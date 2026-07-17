<?php
/**
 * AlignmentEngine must not invent space-between over CSS / grids.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\AlignmentEngine;
use HtmlToElementor\Engine\ConstraintLayoutSolver;
use PHPUnit\Framework\TestCase;

final class AlignmentCssPreservationTest extends TestCase
{

	public function test_does_not_overwrite_css_space_between(): void
	{
		$engine = new AlignmentEngine();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'cls' => 'header-inner',
					's' => array(
						'disp' => 'flex',
						'fd' => 'row',
						'jc' => 'space-between',
						'ai' => 'center',
						'w' => 1200,
						'h' => 78,
					),
					'layoutConstraint' => array('direction' => 'row', 'gap' => 0),
					'children' => array(
						array(
							'tag' => 'div',
							's' => array('w' => 180, 'h' => 40),
							'bbox' => array('x' => 0, 'y' => 0, 'width' => 180, 'height' => 40),
							'children' => array(),
						),
						array(
							'tag' => 'div',
							's' => array('w' => 800, 'h' => 40),
							'bbox' => array('x' => 400, 'y' => 0, 'width' => 800, 'height' => 40),
							'children' => array(),
						),
					),
				),
			),
		);

		$out = $engine->apply($sections);
		$tree = $out[0]['tree'];

		$this->assertSame('space-between', $tree['s']['jc'] ?? '');
		$this->assertSame('center', $tree['s']['ai'] ?? '');
		$this->assertSame('css', $tree['alignment']['justify_source'] ?? '');
	}

	public function test_grid_does_not_get_invented_space_between(): void
	{
		$solver = new ConstraintLayoutSolver();
		$engine = new AlignmentEngine();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'cls' => 'grid grid-3',
					's' => array(
						'disp' => 'grid',
						'gap' => '28px',
						'w' => 1152,
						'h' => 400,
					),
					'children' => array(
						array(
							'tag' => 'div',
							'cls' => 'card',
							's' => array('w' => 365, 'h' => 280),
							'bbox' => array('x' => 0, 'y' => 0, 'width' => 365, 'height' => 280),
							'children' => array(),
						),
						array(
							'tag' => 'div',
							'cls' => 'card',
							's' => array('w' => 365, 'h' => 280),
							'bbox' => array('x' => 393, 'y' => 0, 'width' => 365, 'height' => 280),
							'children' => array(),
						),
						array(
							'tag' => 'div',
							'cls' => 'card',
							's' => array('w' => 365, 'h' => 280),
							'bbox' => array('x' => 786, 'y' => 0, 'width' => 365, 'height' => 280),
							'children' => array(),
						),
					),
				),
			),
		);

		$sections = $solver->solve($sections);
		$out = $engine->apply($sections);
		$tree = $out[0]['tree'];

		$this->assertNotSame('space-between', $tree['s']['jc'] ?? 'space-between');
		$this->assertSame('flex-start', $tree['alignment']['justify'] ?? '');
	}
}
