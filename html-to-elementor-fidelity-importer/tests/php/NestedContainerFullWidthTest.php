<?php
/**
 * Nested Elementor containers at depths 2–4 get Full Width + 100% when safe.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ContainerTreeOptimizer;
use PHPUnit\Framework\TestCase;

final class NestedContainerFullWidthTest extends TestCase
{

	public function test_depths_2_to_4_get_full_width_100_percent(): void
	{
		$elements = array(
			array(
				'id' => 'root',
				'elType' => 'container',
				'isInner' => false,
				'settings' => array('content_width' => 'full', 'flex_direction' => 'column'),
				'elements' => array(
					array(
						'id' => 'd2',
						'elType' => 'container',
						'isInner' => true,
						'settings' => array('content_width' => 'boxed', 'flex_direction' => 'column'),
						'elements' => array(
							array(
								'id' => 'd3',
								'elType' => 'container',
								'isInner' => true,
								'settings' => array('flex_direction' => 'row', 'flex_grow' => 1),
								'elements' => array(
									array(
										'id' => 'd4',
										'elType' => 'container',
										'isInner' => true,
										'settings' => array('flex_direction' => 'column'),
										'elements' => array(
											array(
												'id' => 'h',
												'elType' => 'widget',
												'widgetType' => 'heading',
												'settings' => array('title' => 'Hello'),
												'elements' => array(),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		$out = (new ContainerTreeOptimizer())->ensure_nested_full_widths($elements);

		$d2 = $out[0]['elements'][0];
		$d3 = $d2['elements'][0];
		$d4 = $d3['elements'][0];

		foreach (array($d2, $d3, $d4) as $node) {
			$this->assertSame('full', $node['settings']['content_width']);
			$this->assertSame('%', $node['settings']['width']['unit']);
			$this->assertSame(100, (int) $node['settings']['width']['size']);
		}
	}

	public function test_row_column_shares_and_px_chrome_are_preserved(): void
	{
		$elements = array(
			array(
				'id' => 'root',
				'elType' => 'container',
				'isInner' => false,
				'settings' => array('content_width' => 'full', 'flex_direction' => 'column'),
				'elements' => array(
					array(
						'id' => 'row',
						'elType' => 'container',
						'isInner' => true,
						'settings' => array(
							'content_width' => 'full',
							'flex_direction' => 'row',
							'width' => array('unit' => '%', 'size' => 100),
						),
						'elements' => array(
							array(
								'id' => 'col-a',
								'elType' => 'container',
								'isInner' => true,
								'settings' => array(
									'content_width' => 'full',
									'flex_direction' => 'column',
									'width' => array('unit' => '%', 'size' => 51),
									'flex_shrink' => 0,
								),
								'elements' => array(),
							),
							array(
								'id' => 'col-b',
								'elType' => 'container',
								'isInner' => true,
								'settings' => array(
									'content_width' => 'full',
									'flex_direction' => 'column',
									'width' => array('unit' => '%', 'size' => 49),
									'flex_shrink' => 0,
								),
								'elements' => array(),
							),
							array(
								'id' => 'icon',
								'elType' => 'container',
								'isInner' => true,
								'settings' => array(
									'content_width' => 'full',
									'width' => array('unit' => 'px', 'size' => 56),
									'flex_grow' => 0,
									'flex_shrink' => 0,
								),
								'elements' => array(),
							),
						),
					),
				),
			),
		);

		$out = (new ContainerTreeOptimizer())->ensure_nested_full_widths($elements);
		$row = $out[0]['elements'][0];
		$cols = $row['elements'];

		$this->assertSame(51, (int) $cols[0]['settings']['width']['size']);
		$this->assertSame('%', $cols[0]['settings']['width']['unit']);
		$this->assertSame(49, (int) $cols[1]['settings']['width']['size']);
		$this->assertSame(56, (int) $cols[2]['settings']['width']['size']);
		$this->assertSame('px', $cols[2]['settings']['width']['unit']);
		// content_width still forced full on all.
		$this->assertSame('full', $cols[0]['settings']['content_width']);
	}
}
