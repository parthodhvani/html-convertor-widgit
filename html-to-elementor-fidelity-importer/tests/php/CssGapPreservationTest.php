<?php
/**
 * Constraint / whitespace gap must follow CSS gap, not space-between free space.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\ConstraintLayoutSolver;
use HtmlToElementor\Engine\WhitespaceAnalyzer;
use PHPUnit\Framework\TestCase;

final class CssGapPreservationTest extends TestCase
{

	public function test_space_between_does_not_become_huge_flex_gap(): void
	{
		$solver = new ConstraintLayoutSolver();
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
					'children' => array(
						array(
							'tag' => 'div',
							'cls' => 'logo',
							's' => array('w' => 180, 'h' => 44),
							'bbox' => array('x' => 0, 'y' => 17, 'width' => 180, 'height' => 44),
							'children' => array(),
						),
						array(
							'tag' => 'nav',
							'cls' => 'nav',
							's' => array('w' => 800, 'h' => 44),
							'bbox' => array('x' => 400, 'y' => 17, 'width' => 800, 'height' => 44),
							'children' => array(),
						),
					),
				),
			),
		);

		$out = $solver->solve($sections);
		$tree = $out[0]['tree'];
		$gap = (float) ($tree['layoutConstraint']['gap'] ?? -1);

		$this->assertSame(0.0, $gap);
		$this->assertSame('css', $tree['layoutConstraint']['gap_source'] ?? '');
		$this->assertArrayNotHasKey('_gap_geometry', $tree['s'] ?? array());
	}

	public function test_css_gap_wins_over_geometry_on_space_between(): void
	{
		$solver = new ConstraintLayoutSolver();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'cls' => 'reviews-header',
					's' => array(
						'disp' => 'flex',
						'fd' => 'row',
						'jc' => 'space-between',
						'gap' => '24px',
						'w' => 1038,
						'h' => 60,
					),
					'children' => array(
						array(
							'tag' => 'div',
							's' => array('w' => 200, 'h' => 48),
							'bbox' => array('x' => 0, 'y' => 0, 'width' => 200, 'height' => 48),
							'children' => array(),
						),
						array(
							'tag' => 'div',
							's' => array('w' => 280, 'h' => 48),
							'bbox' => array('x' => 758, 'y' => 0, 'width' => 280, 'height' => 48),
							'children' => array(),
						),
					),
				),
			),
		);

		$out = $solver->solve($sections);
		$tree = $out[0]['tree'];

		$this->assertSame(24.0, (float) ($tree['layoutConstraint']['gap'] ?? 0));
		$this->assertSame('24px', $tree['s']['gap'] ?? '');
		$this->assertArrayNotHasKey('_gap_geometry', $tree['s'] ?? array());
	}

	public function test_whitespace_analyzer_preserves_css_gap(): void
	{
		$analyzer = new WhitespaceAnalyzer();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'cls' => 'reviews-header',
					's' => array(
						'disp' => 'flex',
						'fd' => 'row',
						'jc' => 'space-between',
						'gap' => '24px',
						'w' => 1038,
						'h' => 60,
					),
					'bbox' => array('x' => 0, 'y' => 0, 'width' => 1038, 'height' => 60),
					'layoutConstraint' => array('direction' => 'row', 'gap' => 24, 'gap_source' => 'css'),
					'children' => array(
						array(
							'tag' => 'div',
							'atomic' => true,
							's' => array(),
							'bbox' => array('x' => 0, 'y' => 0, 'width' => 200, 'height' => 48),
						),
						array(
							'tag' => 'div',
							'atomic' => true,
							's' => array(),
							'bbox' => array('x' => 758, 'y' => 0, 'width' => 280, 'height' => 48),
						),
					),
				),
			),
		);

		$out = $analyzer->analyze($sections);
		$tree = $out[0]['tree'];

		$this->assertSame(24.0, (float) ($tree['whitespace']['gap'] ?? 0));
		$this->assertSame('24px', $tree['s']['gap'] ?? '');
		$this->assertArrayNotHasKey('_gap_whitespace', $tree['s'] ?? array());
	}
}
