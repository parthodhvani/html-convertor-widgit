#!/usr/bin/env php
<?php
/**
 * Compile a Chromium layout.json into Elementor JSON + preview HTML + metrics.
 *
 * Usage:
 *   php tests/accuracy/compile-and-preview.php <layout.json> <out-dir>
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/php/');
define('H2E_PLUGIN_DIR', dirname(__DIR__, 2) . '/');

require dirname(__DIR__) . '/php/bootstrap.php';

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Engine\ElementorPreviewRenderer;
use HtmlToElementor\Services\RenderResult;

$layout_path = $argv[1] ?? '';
$out_dir = $argv[2] ?? '';

if ('' === $layout_path || '' === $out_dir || !is_readable($layout_path)) {
	fwrite(STDERR, "Usage: php tests/accuracy/compile-and-preview.php <layout.json> <out-dir>\n");
	exit(2);
}

if (!is_dir($out_dir) && !mkdir($out_dir, 0777, true) && !is_dir($out_dir)) {
	fwrite(STDERR, "Cannot create out dir: {$out_dir}\n");
	exit(1);
}

$layout = json_decode((string) file_get_contents($layout_path), true);
if (!is_array($layout)) {
	fwrite(STDERR, "Invalid layout JSON\n");
	exit(1);
}

$generator = new ElementorJsonGenerator();
$generated = $generator->generate(
	RenderResult::from_array($layout),
	array(
		'confidence' => 95,
		'closed_loop' => false,
	)
);

$elements = (array) ($generated['data'] ?? array());
$custom_css = collect_element_custom_css($elements);
$source_css = (string) ($layout['assets']['combinedCss'] ?? '');
$css_loss = collect_css_loss($elements);

$preview = (new ElementorPreviewRenderer())->render(
	$elements,
	array(
		'title' => (string) ($layout['meta']['title'] ?? 'H2E Preview'),
		'width' => 1440,
		'css' => $source_css . "\n" . $custom_css,
	)
);

file_put_contents($out_dir . '/elementor.json', wp_json_encode($elements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($out_dir . '/preview.html', $preview);
file_put_contents($out_dir . '/report.json', wp_json_encode($generated['report'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out_dir . '/validation.json', wp_json_encode($generated['validation'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out_dir . '/css-loss.json', wp_json_encode($css_loss, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$summary = array(
	'native_widgets' => (int) ($generated['report']['native_widgets'] ?? 0),
	'html_widgets' => (int) ($generated['report']['html_widgets'] ?? 0),
	'geometry_similarity' => (int) ($generated['validation']['geometry_similarity'] ?? 0),
	'typography_similarity' => (int) ($generated['validation']['typography_similarity'] ?? 0),
	'spacing_similarity' => (int) ($generated['validation']['spacing_similarity'] ?? 0),
	'responsive_similarity' => (int) ($generated['validation']['responsive_similarity'] ?? 0),
	'fidelity' => (int) ($generated['validation']['fidelity'] ?? 0),
	'colour' => (int) ($generated['validation']['colour'] ?? 0),
	'source_frames' => (int) ($generated['validation']['source_frames'] ?? 0),
	'emitted_frames' => (int) ($generated['validation']['emitted_frames'] ?? 0),
	'matched_frames' => (int) ($generated['validation']['matched_frames'] ?? 0),
	'repairs' => $generated['validation']['repairs'] ?? array(),
	'components' => $generated['report']['components'] ?? array(),
	'unsupported_css_count' => count($css_loss['unsupported'] ?? array()),
	'custom_css_rules' => count($css_loss['custom_css_elements'] ?? array()),
);
file_put_contents($out_dir . '/compile-summary.json', wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo wp_json_encode($summary) . PHP_EOL;

/**
 * @param array<int,array<string,mixed>> $elements Elements.
 */
function collect_element_custom_css(array $elements): string
{
	$rules = array();
	$walk = static function (array $el) use (&$walk, &$rules): void {
		$id = (string) ($el['id'] ?? '');
		$css = trim((string) (($el['settings']['_h2e_custom_css'] ?? '')), " \t\n\r\0\x0B;");
		if ('' !== $id && '' !== $css) {
			$rules[] = '.elementor-element-' . $id . '{' . $css . '}';
		}
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			if (is_array($child)) {
				$walk($child);
			}
		}
	};
	foreach ($elements as $el) {
		if (is_array($el)) {
			$walk($el);
		}
	}
	return implode("\n", $rules);
}

/**
 * @param array<int,array<string,mixed>> $elements Elements.
 * @return array<string,mixed>
 */
function collect_css_loss(array $elements): array
{
	$unsupported = array();
	$custom = array();
	$walk = static function (array $el) use (&$walk, &$unsupported, &$custom): void {
		$s = (array) ($el['settings'] ?? array());
		foreach ((array) ($s['_h2e_unsupported'] ?? array()) as $prop) {
			$unsupported[(string) $prop] = ($unsupported[(string) $prop] ?? 0) + 1;
		}
		if (!empty($s['_h2e_custom_css'])) {
			$custom[] = array(
				'id' => (string) ($el['id'] ?? ''),
				'type' => (string) ($el['widgetType'] ?? $el['elType'] ?? ''),
				'css' => (string) $s['_h2e_custom_css'],
			);
		}
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			if (is_array($child)) {
				$walk($child);
			}
		}
	};
	foreach ($elements as $el) {
		if (is_array($el)) {
			$walk($el);
		}
	}
	return array(
		'unsupported' => $unsupported,
		'custom_css_elements' => $custom,
	);
}
