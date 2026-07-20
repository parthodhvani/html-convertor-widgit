<?php
/**
 * Verify ALL Petra light-version fixtures: core fidelity ≥90.
 *
 * Closed-loop `shot` uses an approximate HTML preview (pixel_mae), so it is
 * gated at ≥85. Structure/paint metrics (fid/geo/lay/spc/typ/col) must be ≥90.
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

// Petra light-version pages only (from Petra_light-version.zip).
$fixtures = array(
	'petra__index',
	'petra__angebot',
	'petra__meditation',
	'petra__vortraege',
	'petra__petra-mueller',
	'petra__blog',
	'petra__blog-detail',
	'petra__buchen',
	'petra__contact',
	'petra__feedbacks',
);

$roots = array(
	'/tmp/h2e-petra-light/',
	'/tmp/h2e-petra-final/',
	'/tmp/h2e-accuracy/',
);

$gen = new ElementorJsonGenerator();
$previewer = new ElementorPreviewRenderer();
$fail = 0;
$lines = array();

foreach ($fixtures as $slug) {
	$path = null;
	foreach ($roots as $root) {
		$candidate = $root . $slug . '/layout.json';
		if (is_file($candidate)) {
			$path = $candidate;
			break;
		}
	}
	if (null === $path) {
		$line = sprintf('%-42s MISSING_LAYOUT', $slug);
		echo $line . PHP_EOL;
		$lines[] = $line;
		++$fail;
		continue;
	}

	$layout = json_decode((string) file_get_contents($path), true);
	if (!is_array($layout)) {
		$line = sprintf('%-42s BAD_JSON', $slug);
		echo $line . PHP_EOL;
		$lines[] = $line;
		++$fail;
		continue;
	}

	// Light-version Petra canvas fallback when page meta is absent.
	if (empty($layout['meta']['page']['backgroundColor'])) {
		$layout['meta']['page'] = array(
			'backgroundColor' => 'rgb(247, 250, 252)',
			'color' => 'rgb(26, 39, 64)',
		);
	}

	$out = $gen->generate(RenderResult::from_array($layout), array('confidence' => 90, 'closed_loop' => true));
	$dir = '/tmp/h2e-petra-verify/' . $slug;
	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}
	file_put_contents($dir . '/elementor.json', wp_json_encode($out['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	file_put_contents(
		$dir . '/preview.html',
		$previewer->render(
			$out['data'],
			array(
				'title' => $slug,
				'width' => 1440,
				'page' => $layout['meta']['page'] ?? array(),
			)
		)
	);

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
	$thresholds = array(
		'fid' => 90,
		'geo' => 90,
		'lay' => 90,
		'spc' => 90,
		'typ' => 90,
		'col' => 90,
		// Approximate closed-loop HTML preview compare (pixel_mae), not live Elementor.
		'shot' => 85,
	);
	foreach ($row as $k => $n) {
		$min = $thresholds[$k] ?? 90;
		if ($n < $min) {
			$issues[] = $k . '=' . $n;
		}
	}

	$ok = empty($issues) ? 'OK' : ('ISSUE(' . implode(',', $issues) . ')');
	if (!empty($issues)) {
		++$fail;
	}
	$line = sprintf(
		'%-42s fid=%3d geo=%3d lay=%3d spc=%3d typ=%3d col=%3d shot=%3d  %s',
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

file_put_contents('/opt/cursor/artifacts/petra-final-all-scores.txt', implode("\n", $lines) . "\n");
echo ($fail === 0 ? "ALL PETRA LIGHT ≥90 PASS\n" : "FAILURES={$fail}\n");
exit($fail === 0 ? 0 : 1);
