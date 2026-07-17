<?php
/**
 * Top-level section roots must not be dissolved into inner content boxes.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ContainerTreeOptimizer;
use PHPUnit\Framework\TestCase;

final class SectionRootPreservationTest extends TestCase
{

	public function test_padded_section_not_replaced_by_narrower_child(): void
	{
		$elements = array(
			array(
				'id' => 'section',
				'elType' => 'container',
				'isInner' => false,
				'settings' => array(
					'content_width' => 'full',
					'flex_direction' => 'column',
					'_css_classes' => 'section',
					'_h2e_bbox' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 900),
					'padding' => array(
						'unit' => 'px',
						'top' => '96',
						'right' => '0',
						'bottom' => '96',
						'left' => '0',
						'isLinked' => false,
					),
				),
				'elements' => array(
					array(
						'id' => 'grid',
						'elType' => 'container',
						'isInner' => true,
						'settings' => array(
							'flex_direction' => 'row',
							'_css_classes' => 'container grid grid-3',
							'_h2e_bbox' => array('x' => 144, 'y' => 96, 'width' => 1152, 'height' => 714),
							'flex_gap' => array('unit' => 'px', 'size' => 28, 'column' => '28', 'row' => '28', 'isLinked' => true),
						),
						'elements' => array(
							array(
								'id' => 'w1',
								'elType' => 'widget',
								'widgetType' => 'heading',
								'settings' => array(),
								'elements' => array(),
							),
						),
					),
				),
			),
		);

		$optimizer = new ContainerTreeOptimizer();
		$optimized = $optimizer->optimize($elements);

		$this->assertCount(1, $optimized);
		$this->assertSame('section', $optimized[0]['settings']['_css_classes'] ?? '');
		$this->assertSame(1440.0, (float) ($optimized[0]['settings']['_h2e_bbox']['width'] ?? 0));
		$this->assertNotEmpty($optimized[0]['elements']);
		$this->assertSame('container', $optimized[0]['elements'][0]['elType'] ?? '');
	}

	public function test_padding_dimension_controls_count_as_visual_styling(): void
	{
		$elements = array(
			array(
				'id' => 'outer',
				'elType' => 'container',
				'isInner' => true,
				'settings' => array(
					'flex_direction' => 'column',
					'padding' => array(
						'unit' => 'px',
						'top' => '40',
						'right' => '40',
						'bottom' => '40',
						'left' => '40',
						'isLinked' => true,
					),
				),
				'elements' => array(
					array(
						'id' => 'inner',
						'elType' => 'container',
						'isInner' => true,
						'settings' => array('flex_direction' => 'column'),
						'elements' => array(
							array(
								'id' => 'w1',
								'elType' => 'widget',
								'widgetType' => 'text-editor',
								'settings' => array(),
								'elements' => array(),
							),
						),
					),
				),
			),
		);

		$optimizer = new ContainerTreeOptimizer();
		$optimized = $optimizer->optimize($elements);

		$this->assertSame('40', (string) ($optimized[0]['settings']['padding']['top'] ?? ''));
		$this->assertSame(2, $this->count_containers($optimized));
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function count_containers(array $elements): int
	{
		$count = 0;
		foreach ($elements as $el) {
			if ('container' === ($el['elType'] ?? '')) {
				++$count;
			}
			$count += $this->count_containers((array) ($el['elements'] ?? array()));
		}
		return $count;
	}
}
