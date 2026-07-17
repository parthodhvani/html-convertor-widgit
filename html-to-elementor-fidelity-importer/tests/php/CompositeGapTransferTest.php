<?php
/**
 * Composite wrappers must retain CSS/geometry gap after collapse.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ContainerTreeOptimizer;
use PHPUnit\Framework\TestCase;

final class CompositeGapTransferTest extends TestCase
{
	public function test_social_icons_wrapper_keeps_flex_gap(): void
	{
		$optimizer = new ContainerTreeOptimizer();
		$elements = array(
			array(
				'elType' => 'container',
				'settings' => array(
					'_css_classes' => 'socials',
					'flex_gap' => array(
						'size' => 10,
						'unit' => 'px',
						'column' => '10',
						'row' => '10',
						'isLinked' => true,
					),
				),
				'elements' => array(
					array(
						'elType' => 'widget',
						'widgetType' => 'social-icons',
						'settings' => array(
							'_css_classes' => 'socials',
							'social_icon_list' => array(),
						),
						'elements' => array(),
					),
				),
			),
		);

		$out = $optimizer->optimize($elements);
		$this->assertSame(10.0, (float) ($out[0]['settings']['flex_gap']['size'] ?? 0));
		$this->assertSame(10.0, (float) ($out[0]['elements'][0]['settings']['gap']['size'] ?? 0));
	}

	public function test_form_wrapper_keeps_flex_gap(): void
	{
		$optimizer = new ContainerTreeOptimizer();
		$elements = array(
			array(
				'elType' => 'container',
				'settings' => array(
					'_css_classes' => 'kontakt-form-wrap',
					'flex_gap' => array(
						'size' => 12,
						'unit' => 'px',
						'column' => '12',
						'row' => '12',
						'isLinked' => true,
					),
				),
				'elements' => array(
					array(
						'elType' => 'widget',
						'widgetType' => 'form',
						'settings' => array('_css_classes' => 'kontakt-form-wrap'),
						'elements' => array(),
					),
				),
			),
		);

		$out = $optimizer->optimize($elements);
		$this->assertSame(12.0, (float) ($out[0]['settings']['flex_gap']['size'] ?? 0));
	}
}
