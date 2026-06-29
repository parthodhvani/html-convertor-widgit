<?php
/**
 * Regression suite for Visual Reconstruction Engine v3.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

/**
 * Validates layout fidelity across common website patterns.
 */
final class RegressionSuiteTest extends TestCase
{
	use RegressionFixtures;

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fixture_provider(): array
	{
		$self = new self('provider');
		return array(
			'bootstrap' => array($self->bootstrap_layout()),
			'tailwind' => array($self->tailwind_layout()),
			'html5up' => array($self->html5up_layout()),
			'nested_flex' => array($self->nested_flex_layout()),
			'bootstrapmade' => array($self->bootstrapmade_layout()),
			'agency' => array($self->agency_layout()),
			'business' => array($self->business_layout()),
			'portfolio' => array($self->portfolio_layout()),
			'docs' => array($self->docs_layout()),
			'complex_grid' => array($self->complex_grid_layout()),
		);
	}

	/**
	 * @param array<string,mixed> $layout Layout document.
	 * @dataProvider fixture_provider
	 */
	public function test_fixture_imports_with_geometry_pipeline(array $layout): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));

		$this->assertSame('native', $result['report']['mode']);
		$this->assertSame(3, $result['report']['engine_version']);
		$this->assertNotEmpty($result['data']);
		$this->assertGreaterThan(0, $result['report']['native_widgets']);
		$this->assertArrayHasKey('layout_similarity', $result['validation']);
		$this->assertGreaterThanOrEqual(70, $result['validation']['layout_similarity']);
		$this->assertGreaterThanOrEqual(70, $result['quality']['visual_fidelity_score']);
	}

	/**
	 * @param array<string,mixed> $layout Layout document.
	 * @dataProvider fixture_provider
	 */
	public function test_fixture_uses_constraints_not_raw_margins(array $layout): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));

		$this->assertGreaterThan(0, $result['validation']['constraint_coverage'] ?? 0);
		$this->assertLessThanOrEqual(8, $result['quality']['max_nesting_depth']);
	}

	/**
	 * @param array<string,mixed> $layout Layout document.
	 * @dataProvider fixture_provider
	 */
	public function test_fixture_native_mode_beats_preserve(array $layout): void
	{
		$gen = new ElementorJsonGenerator();
		$doc = RenderResult::from_array($layout);
		$native = $gen->generate($doc, array('mode' => 'native'));
		$preserve = $gen->generate($doc, array('mode' => 'preserve'));

		$this->assertGreaterThan(
			$preserve['validation']['layout_similarity'] ?? 0,
			$native['validation']['layout_similarity'] ?? 0
		);
	}

	public function test_bootstrap_row_has_flex_gap_or_direction(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->bootstrap_layout()), array('mode' => 'native'));

		$features = $result['data'][2];
		$this->assertSame('container', $features['elType']);
		$this->assertContains(
			$features['settings']['flex_direction'] ?? '',
			array('row', 'column')
		);
	}
}
