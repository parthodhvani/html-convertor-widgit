<?php
/**
 * Verify Petra fixtures: scores + header structure (nav gaps / groups).
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('H2E_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
require __DIR__ . '/bootstrap.php';

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Engine\ElementorPreviewRenderer;
use HtmlToElementor\Services\RenderResult;

$fixtures = array(
	'petra__index',
	'petra__angebot-conversion-ready',
	'petra__contact',
	'petra__blog',
	'petra__blog-detail',
	'petra__buchen',
	'petra__vortraege',
	'petra__feedbacks',
);

$gen = new ElementorJsonGenerator();
$previewer = new ElementorPreviewRenderer();
$fail = 0;
$lines = array();

foreach ($fixtures as $slug) {
	$path = '/tmp/h2e-accuracy/' . $slug . '/layout.json';
	$layout = json_decode((string) file_get_contents($path), true);
	$out = $gen->generate(RenderResult::from_array($layout), array('confidence' => 90, 'closed_loop' => true));
	$dir = '/tmp/h2e-petra-verify/' . $slug;
	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}
	file_put_contents($dir . '/elementor.json', wp_json_encode($out['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	file_put_contents($dir . '/preview.html', $previewer->render($out['data'], array('title' => $slug, 'width' => 1440)));

	$v = $out['validation'];
	$row = array(
		'fid' => (int) ($v['fidelity'] ?? 0),
		'geo' => (int) ($v['geometry_similarity'] ?? 0),
		'lay' => (int) ($v['layout_similarity'] ?? 0),
		'spc' => (int) ($v['spacing_similarity'] ?? 0),
		'typ' => (int) ($v['typography_similarity'] ?? 0),
		'col' => (int) ($v['colour'] ?? 0),
		'shot' => (int) ($v['screenshot'] ?? 0),
	);

	$issues = array();
	foreach ($row as $k => $n) {
		if ($n < 90) {
			$issues[] = $k . '=' . $n;
		}
	}

	$header = $out['data'][0] ?? array();
	$has_nav_gap = false;
	$find_gap = static function (array $el) use (&$find_gap, &$has_nav_gap): void {
		$g = (float) ($el['settings']['flex_gap']['size'] ?? -1);
		if (abs($g - 28.0) < 0.5 || abs($g - 32.0) < 0.5) {
			$has_nav_gap = true;
		}
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			if (is_array($child)) {
				$find_gap($child);
			}
		}
	};
	$find_gap($header);
	if (!$has_nav_gap) {
		$issues[] = 'missing_nav_gap';
	}

	// First header row should group logo/nav rather than dump every link as a sibling.
	$inner = null;
	$find_row = static function (array $el) use (&$find_row, &$inner): void {
		if (null === $inner && ($el['settings']['flex_direction'] ?? '') === 'row') {
			$inner = $el;
			return;
		}
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			if (is_array($child)) {
				$find_row($child);
			}
		}
	};
	$find_row($header);
	if (is_array($inner)) {
		$widgets = 0;
		$containers = 0;
		foreach ((array) ($inner['elements'] ?? array()) as $child) {
			if (($child['elType'] ?? '') === 'widget') {
				++$widgets;
			}
			if (($child['elType'] ?? '') === 'container') {
				++$containers;
			}
		}
		if ($widgets >= 5) {
			$issues[] = 'flat_header_widgets=' . $widgets;
		}
		if ($containers < 2) {
			$issues[] = 'header_groups=' . $containers;
		}
	}

	$ok = empty($issues) ? 'OK' : ('ISSUE(' . implode(',', $issues) . ')');
	if (!empty($issues)) {
		++$fail;
	}
	$line = sprintf(
		'%-36s fid=%3d geo=%3d lay=%3d spc=%3d typ=%3d col=%3d shot=%3d  %s',
		$slug,
		$row['fid'],
		$row['geo'],
		$row['lay'],
		$row['spc'],
		$row['typ'],
		$row['col'],
		$row['shot'],
		$ok
	);
	echo $line . PHP_EOL;
	$lines[] = $line;
}

file_put_contents('/opt/cursor/artifacts/petra-layout-verify.txt', implode("\n", $lines) . "\n");
echo ($fail === 0 ? "ALL STRUCT+SCORE PASS\n" : "FAILURES={$fail}\n");
exit($fail === 0 ? 0 : 1);
