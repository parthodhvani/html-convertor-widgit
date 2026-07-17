<?php
/**
 * PixelRepair must not last-wins stamp flex_gap across generic classes.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\PixelRepairEngine;
use PHPUnit\Framework\TestCase;

final class AmbiguousClassGapStampTest extends TestCase
{
	public function test_ambiguous_container_class_uses_nearest_bbox_gap(): void
	{
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'cls' => 'page',
					's' => array('x' => 0, 'y' => 0, 'w' => 800, 'h' => 600, 'disp' => 'block'),
					'layoutConstraint' => array('direction' => 'column', 'gap' => 0),
					'children' => array(
						array(
							'tag' => 'div',
							'cls' => 'container',
							's' => array('x' => 0, 'y' => 0, 'w' => 700, 'h' => 100, 'disp' => 'flex', 'fd' => 'column', 'gap' => '17px'),
							'layoutConstraint' => array('direction' => 'column', 'gap' => 17, 'gap_source' => 'css'),
							'children' => array(
								array('tag' => 'p', 'cls' => 'a', 's' => array('x' => 0, 'y' => 0, 'w' => 100, 'h' => 20), 'text' => 'A', 'atomic' => true),
								array('tag' => 'p', 'cls' => 'b', 's' => array('x' => 0, 'y' => 37, 'w' => 100, 'h' => 20), 'text' => 'B', 'atomic' => true),
							),
						),
						array(
							'tag' => 'div',
							'cls' => 'container',
							's' => array('x' => 0, 'y' => 200, 'w' => 700, 'h' => 200, 'disp' => 'flex', 'fd' => 'column', 'gap' => '48px'),
							'layoutConstraint' => array('direction' => 'column', 'gap' => 48, 'gap_source' => 'css'),
							'children' => array(
								array('tag' => 'p', 'cls' => 'c', 's' => array('x' => 0, 'y' => 200, 'w' => 100, 'h' => 20), 'text' => 'C', 'atomic' => true),
								array('tag' => 'p', 'cls' => 'd', 's' => array('x' => 0, 'y' => 268, 'w' => 100, 'h' => 20), 'text' => 'D', 'atomic' => true),
							),
						),
					),
				),
			),
		);

		// Emulate converter output: each container already has the correct gap.
		$elements = array(
			array(
				'elType' => 'container',
				'settings' => array(
					'_css_classes' => 'page',
					'flex_direction' => 'column',
					'_h2e_bbox' => array('x' => 0, 'y' => 0, 'width' => 800, 'height' => 600),
				),
				'elements' => array(
					array(
						'elType' => 'container',
						'settings' => array(
							'_css_classes' => 'container',
							'flex_direction' => 'column',
							'flex_gap' => array('size' => 17, 'unit' => 'px', 'column' => '17', 'row' => '17', 'isLinked' => true),
							'_h2e_bbox' => array('x' => 0, 'y' => 0, 'width' => 700, 'height' => 100),
						),
						'elements' => array(
							array('elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array()),
							array('elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array()),
						),
					),
					array(
						'elType' => 'container',
						'settings' => array(
							'_css_classes' => 'container',
							'flex_direction' => 'column',
							'flex_gap' => array('size' => 48, 'unit' => 'px', 'column' => '48', 'row' => '48', 'isLinked' => true),
							'_h2e_bbox' => array('x' => 0, 'y' => 200, 'width' => 700, 'height' => 200),
						),
						'elements' => array(
							array('elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array()),
							array('elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array()),
						),
					),
				),
			),
		);

		// Poison both with last-wins 48 — repair must restore each by nearest bbox.
		$elements[0]['elements'][0]['settings']['flex_gap'] = array(
			'size' => 48, 'unit' => 'px', 'column' => '48', 'row' => '48', 'isLinked' => true,
		);

		$engine = new PixelRepairEngine();
		$out = $engine->repair($elements, array(
			'sections' => $sections,
			'validation' => array('threshold' => 95, 'geometry_similarity' => 50),
			'threshold' => 95,
		));

		$first = $out['data'][0]['elements'][0]['settings']['flex_gap']['size'] ?? null;
		$second = $out['data'][0]['elements'][1]['settings']['flex_gap']['size'] ?? null;

		$this->assertSame(17.0, (float) $first, 'first .container must keep gap 17, not last-wins 48');
		$this->assertSame(48.0, (float) $second, 'second .container must keep gap 48');
	}
}
