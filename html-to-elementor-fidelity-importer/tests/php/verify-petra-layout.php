<?php
/**
 * Verify ALL Petra fixtures: scores ≥90 for geo/lay/spc/typ/col/shot/fid.
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
	'petra__index-dark',
	'petra__angebot',
	'petra__angebot-conversion-ready',
	'petra__contact',
	'petra__blog',
	'petra__blog-detail',
	'petra__buchen',
	'petra__vortraege',
	'petra__feedbacks',
	'petra__petra-mueller',
);

$roots = array(
	'/tmp/h2e-petra-final/',
	'/tmp/h2e-accuracy/',
	'/tmp/h2e-petra-dark/',
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
		// index-dark may live as bare layout under h2e-petra-dark
		if ('petra__index-dark' === $slug && is_file($root . 'layout.json') && str_contains($root, 'petra-dark')) {
			$path = $root . 'layout.json';
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

	// Ensure dark canvas meta for closed-loop preview when absent.
	if (empty($layout['meta']['page']['backgroundColor'])) {
		$layout['meta']['page'] = array(
			'backgroundColor' => 'rgb(5, 7, 15)',
			'color' => 'rgb(230, 236, 245)',
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
	foreach ($row as $k => $n) {
		if ($n < 90) {
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
echo ($fail === 0 ? "ALL PETRA ≥90 PASS\n" : "FAILURES={$fail}\n");
exit($fail === 0 ? 0 : 1);
