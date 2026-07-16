#!/usr/bin/env php
<?php
/**
 * Accuracy regression benchmark suite (Phase 15).
 *
 * Usage:
 *   php tests/benchmarks/run-regression.php [--json] [--fixture=name]
 *
 * Measures geometry similarity, widget editability, container depth and
 * native/html ratios across synthetic layout patterns.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

require dirname(__DIR__) . '/php/bootstrap.php';

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Services\RenderResult;
use HtmlToElementor\Tests\RegressionFixtures;

/**
 * Thin harness so we can call RegressionFixtures trait methods from CLI.
 */
final class H2ERegressionHarness
{
	use RegressionFixtures;

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array
	{
		return array(
			'bootstrap' => $this->bootstrap_layout(),
			'tailwind' => $this->tailwind_layout(),
			'html5up' => $this->html5up_layout(),
			'nested_flex' => $this->nested_flex_layout(),
			'bootstrapmade' => $this->bootstrapmade_layout(),
			'agency' => $this->agency_layout(),
			'business' => $this->business_layout(),
			'portfolio' => $this->portfolio_layout(),
			'docs' => $this->docs_layout(),
			'complex_grid' => $this->complex_grid_layout(),
		);
	}
}

$as_json = in_array('--json', $argv, true);
$only = null;
foreach ($argv as $arg) {
	if (str_starts_with($arg, '--fixture=')) {
		$only = substr($arg, 10);
	}
}

$harness = new H2ERegressionHarness();
$fixtures = $harness->all();

if (null !== $only) {
	$fixtures = array_filter($fixtures, static fn($k) => $k === $only, ARRAY_FILTER_USE_KEY);
}

$gen = new ElementorJsonGenerator();
$report = array(
	'generated_at' => gmdate('c'),
	'fixtures' => array(),
	'summary' => array(),
);

$total_geo = 0;
$total_native = 0;
$total_html = 0;
$total_depth = 0;
$n = 0;

foreach ($fixtures as $name => $layout) {
	$result = $gen->generate(RenderResult::from_array($layout), array(
		'confidence' => 95,
		'closed_loop' => false,
	));
	$native = (int) ($result['report']['native_widgets'] ?? 0);
	$html = (int) ($result['report']['html_widgets'] ?? 0);
	$geo = (int) ($result['validation']['geometry_similarity'] ?? 0);
	$fidelity = (int) ($result['validation']['fidelity'] ?? 0);
	$depth = (int) ($result['report']['max_nesting_depth'] ?? 0);
	if ($depth <= 0) {
		$depth = (int) ($result['report']['optimizer']['max_container_depth'] ?? 0);
	}
	$editability = ($native + $html) > 0 ? (int) round($native / ($native + $html) * 100) : 0;

	$row = array(
		'fixture' => $name,
		'geometry_similarity' => $geo,
		'fidelity' => $fidelity,
		'native_widgets' => $native,
		'html_widgets' => $html,
		'editability_pct' => $editability,
		'max_container_depth' => $depth,
		'scoring_mode' => $result['validation']['scoring_mode'] ?? '',
		'roles' => $result['report']['components'] ?? array(),
	);
	$report['fixtures'][] = $row;

	$total_geo += $geo;
	$total_native += $native;
	$total_html += $html;
	$total_depth += $depth;
	++$n;
}

$report['summary'] = array(
	'fixtures' => $n,
	'avg_geometry_similarity' => $n ? (int) round($total_geo / $n) : 0,
	'avg_editability_pct' => ($total_native + $total_html) > 0
		? (int) round($total_native / ($total_native + $total_html) * 100)
		: 0,
	'avg_max_container_depth' => $n ? round($total_depth / $n, 2) : 0,
	'total_native_widgets' => $total_native,
	'total_html_widgets' => $total_html,
);

if ($as_json) {
	echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
	exit(0);
}

echo "H2E Regression Benchmark\n";
echo str_repeat('=', 72) . "\n";
foreach ($report['fixtures'] as $row) {
	printf(
		"%-16s geo=%3d fid=%3d edit=%3d%% native=%2d html=%2d depth=%d\n",
		$row['fixture'],
		$row['geometry_similarity'],
		$row['fidelity'],
		$row['editability_pct'],
		$row['native_widgets'],
		$row['html_widgets'],
		$row['max_container_depth']
	);
}
echo str_repeat('-', 72) . "\n";
printf(
	"AVG geometry=%d  editability=%d%%  depth=%.1f  widgets=%d/%d\n",
	$report['summary']['avg_geometry_similarity'],
	$report['summary']['avg_editability_pct'],
	$report['summary']['avg_max_container_depth'],
	$report['summary']['total_native_widgets'],
	$report['summary']['total_html_widgets']
);
