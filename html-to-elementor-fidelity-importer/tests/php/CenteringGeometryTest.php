<?php
/**
 * Centered max-width / margin:auto must not invent huge L/R Elementor spacing.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\CssMapper;
use HtmlToElementor\Engine\CssMappingEngine;
use HtmlToElementor\Engine\WhitespaceAnalyzer;
use PHPUnit\Framework\TestCase;

final class CenteringGeometryTest extends TestCase
{
	public function test_whitespace_does_not_treat_justify_center_gutter_as_padding(): void
	{
		$analyzer = new WhitespaceAnalyzer();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'cls' => 'breadcrumb',
					'bbox' => array('x' => 144, 'y' => 189, 'width' => 1152, 'height' => 22),
					's' => array('disp' => 'flex', 'fd' => 'row', 'jc' => 'center', 'pl' => 0, 'pr' => 0),
					'children' => array(
						array(
							'tag' => 'a',
							'atomic' => true,
							'bbox' => array('x' => 663, 'y' => 189, 'width' => 38, 'height' => 22),
							's' => array(),
						),
						array(
							'tag' => 'span',
							'atomic' => true,
							'bbox' => array('x' => 709, 'y' => 189, 'width' => 5, 'height' => 22),
							's' => array(),
						),
						array(
							'tag' => 'span',
							'atomic' => true,
							'bbox' => array('x' => 722, 'y' => 189, 'width' => 55, 'height' => 22),
							's' => array(),
						),
					),
					'layoutConstraint' => array('direction' => 'row'),
				),
			),
		);

		$out = $analyzer->analyze($sections);
		$padding = $out[0]['tree']['whitespace']['padding'] ?? array();
		$this->assertLessThan(40.0, (float) ($padding['left'] ?? 0));
		$this->assertLessThan(40.0, (float) ($padding['right'] ?? 0));
	}

	public function test_css_mapper_drops_resolved_auto_margins_with_max_width(): void
	{
		$mapper = new CssMapper();
		$node = array(
			's' => array(
				'maxW' => '1200px',
				'w' => 1200,
				'ml' => 120,
				'mr' => 120,
				'mt' => 0,
				'mb' => 0,
				'pl' => 24,
				'pr' => 24,
				'pt' => 0,
				'pb' => 0,
			),
			'bbox' => array('x' => 120, 'y' => 0, 'width' => 1200, 'height' => 80),
		);

		$spacing = $mapper->spacing($node, true);
		$this->assertSame(24.0, (float) ($spacing['padding']['left'] ?? 0));
		$this->assertSame(24.0, (float) ($spacing['padding']['right'] ?? 0));
		$this->assertTrue(
			empty($spacing['margin'])
			|| (
				(float) ($spacing['margin']['left'] ?? 0) < 1
				&& (float) ($spacing['margin']['right'] ?? 0) < 1
			)
		);

		$sizing = $mapper->sizing($node);
		$this->assertSame('center', $sizing['align_self'] ?? null);
		$this->assertSame(1200.0, (float) ($sizing['max_width']['size'] ?? 0));
	}

	public function test_map_container_does_not_emit_centering_padding(): void
	{
		$engine = new CssMappingEngine(new CssMapper());
		$node = array(
			's' => array(
				'maxW' => '1200px',
				'w' => 1200,
				'ml' => 120,
				'mr' => 120,
				'pl' => 24,
				'pr' => 24,
				'pt' => 0,
				'pb' => 0,
				'disp' => 'block',
			),
			'bbox' => array('x' => 120, 'y' => 0, 'width' => 1200, 'height' => 200),
			'whitespace' => array(
				'gap' => 0,
				'padding' => array('top' => 0, 'right' => 519, 'bottom' => 0, 'left' => 519),
			),
			'layoutConstraint' => array(),
			'children' => array(),
		);

		$settings = $engine->map_container($node, false);
		$this->assertLessThan(40.0, (float) ($settings['padding']['left'] ?? 0));
		$this->assertLessThan(40.0, (float) ($settings['padding']['right'] ?? 0));
		$this->assertTrue(
			empty($settings['margin'])
			|| (
				(float) ($settings['margin']['left'] ?? 0) < 1
				&& (float) ($settings['margin']['right'] ?? 0) < 1
			)
		);
		$this->assertSame(1200.0, (float) ($settings['max_width']['size'] ?? 0));
	}
}
