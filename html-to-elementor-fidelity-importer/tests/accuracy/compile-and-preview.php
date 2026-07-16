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

$preview = (new ElementorPreviewRenderer())->render(
	(array) ($generated['data'] ?? array()),
	array(
		'title' => (string) ($layout['meta']['title'] ?? 'H2E Preview'),
		'width' => 1440,
	)
);

file_put_contents($out_dir . '/elementor.json', wp_json_encode($generated['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($out_dir . '/preview.html', $preview);
file_put_contents($out_dir . '/report.json', wp_json_encode($generated['report'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out_dir . '/validation.json', wp_json_encode($generated['validation'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
);
file_put_contents($out_dir . '/compile-summary.json', wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo wp_json_encode($summary) . PHP_EOL;
