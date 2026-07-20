<?php
/**
 * CSS Grid maps to Elementor custom CSS, not fake flex space-between.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\CssMapper;
use PHPUnit\Framework\TestCase;

final class CssGridMappingTest extends TestCase
{

	public function test_multi_column_grid_emits_custom_css(): void
	{
		$mapper = new CssMapper();
		$node = array(
			'cls' => 'grid grid-3',
			's' => array(
				'disp' => 'grid',
				'gap' => '28px',
				'gtc' => '365.328px 365.328px 365.344px',
			),
		);

		$flex = $mapper->flex($node);

		$this->assertSame('grid', $flex['_h2e_display'] ?? '');
		$this->assertStringContainsString('display: grid', (string) ($flex['custom_css'] ?? ''));
		$this->assertStringContainsString('repeat(3, minmax(0, 1fr))', (string) ($flex['custom_css'] ?? ''));
		$this->assertSame(28.0, (float) ($flex['flex_gap']['size'] ?? 0));
	}

	public function test_single_track_grid_falls_back_to_flex_mapping(): void
	{
		$mapper = new CssMapper();
		$node = array(
			'cls' => 'card-icon',
			's' => array(
				'disp' => 'grid',
				'ai' => 'center',
				'gtc' => '56px',
			),
		);

		$flex = $mapper->flex($node);

		$this->assertArrayNotHasKey('custom_css', $flex);
		$this->assertArrayNotHasKey('_h2e_display', $flex);
		$this->assertSame('center', $flex['flex_align_items'] ?? '');
	}

	public function test_asymmetric_grid_preserves_track_ratios(): void
	{
		$mapper = new CssMapper();
		$node = array(
			'cls' => 'hero-grid',
			's' => array(
				'disp' => 'grid',
				'gap' => '60px',
				'ai' => 'center',
				'gtc' => '584.078px 507.906px',
			),
		);

		$flex = $mapper->flex($node);
		$css = (string) ($flex['custom_css'] ?? '');

		$this->assertSame('grid', $flex['_h2e_display'] ?? '');
		$this->assertStringContainsString('584fr 508fr', $css);
		$this->assertStringNotContainsString('repeat(2', $css);
	}
}
