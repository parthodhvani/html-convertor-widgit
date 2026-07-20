<?php
/**
 * Nested Elementor containers at depths 2–10 get Full Width + 100%.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ContainerTreeOptimizer;
use PHPUnit\Framework\TestCase;

final class NestedContainerFullWidthTest extends TestCase
{

	public function test_depths_2_to_10_get_full_width_100_percent(): void
	{
		$leaf = array(
			'id' => 'h',
			'elType' => 'widget',
			'widgetType' => 'heading',
			'settings' => array('title' => 'Hello'),
			'elements' => array(),
		);

		// Build root → d2 … d10 → widget
		$node = $leaf;
		for ($depth = 10; $depth >= 2; --$depth) {
			$node = array(
				'id' => 'd' . $depth,
				'elType' => 'container',
				'isInner' => true,
				'settings' => array(
					'content_width' => 2 === $depth ? 'boxed' : 'full',
					'flex_direction' => 'column',
					// Simulate a 51% column share that must be forced to 100%.
					'width' => 5 === $depth
						? array('unit' => '%', 'size' => 51)
						: array('unit' => '%', 'size' => 80),
				),
				'elements' => array($node),
			);
		}

		$elements = array(
			array(
				'id' => 'root',
				'elType' => 'container',
				'isInner' => false,
				'settings' => array('content_width' => 'full', 'flex_direction' => 'column'),
				'elements' => array($node),
			),
		);

		$out = (new ContainerTreeOptimizer())->ensure_nested_full_widths($elements);

		$current = $out[0];
		for ($depth = 2; $depth <= 10; ++$depth) {
			$current = $current['elements'][0];
			$this->assertSame('container', $current['elType'], 'depth ' . $depth);
			$this->assertSame('full', $current['settings']['content_width'], 'depth ' . $depth . ' content_width');
			$this->assertSame('%', $current['settings']['width']['unit'], 'depth ' . $depth . ' unit');
			$this->assertSame(100, (int) $current['settings']['width']['size'], 'depth ' . $depth . ' size');
		}
	}

	public function test_partial_percent_shares_are_preserved_in_row_parents(): void
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

		// Row children keep geometry shares so header/nav and splits do not wrap.
		$this->assertSame(51, (int) $cols[0]['settings']['width']['size']);
		$this->assertSame('%', $cols[0]['settings']['width']['unit']);
		$this->assertSame(49, (int) $cols[1]['settings']['width']['size']);
		// Tiny px chrome stays measured.
		$this->assertSame(56, (int) $cols[2]['settings']['width']['size']);
		$this->assertSame('px', $cols[2]['settings']['width']['unit']);
		$this->assertSame('full', $cols[0]['settings']['content_width']);
	}
}
