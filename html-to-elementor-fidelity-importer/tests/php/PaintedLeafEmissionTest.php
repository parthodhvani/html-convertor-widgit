<?php
/**
 * Painted leaves without text must still emit Elementor paint.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\LayoutTreeConverter;
use HtmlToElementor\Engine\CssMappingEngine;
use HtmlToElementor\Engine\SemanticComponentRecognizer;
use PHPUnit\Framework\TestCase;

final class PaintedLeafEmissionTest extends TestCase
{

	public function test_empty_gradient_thumb_emits_container(): void
	{
		$converter = new LayoutTreeConverter();
		$converter->use_engines(new SemanticComponentRecognizer(), new CssMappingEngine());

		$node = array(
			'tag' => 'div',
			'cls' => 'blog-thumb',
			'atomic' => true,
			'text' => '',
			'html' => '',
			'children' => array(),
			's' => array(
				'w' => 360,
				'h' => 220,
				'bgImg' => 'linear-gradient(135deg, rgb(13, 59, 102), rgb(142, 125, 190))',
				'disp' => 'block',
			),
		);

		$els = $converter->emit_leaves($node);
		$this->assertNotEmpty($els);
		$this->assertSame('container', $els[0]['elType'] ?? '');
		$this->assertSame('gradient', $els[0]['settings']['background_background'] ?? '');
	}

	public function test_heading_keeps_gradient_background(): void
	{
		$engine = new CssMappingEngine();
		$node = array(
			'cls' => 'logo-mark',
			's' => array(
				'bgImg' => 'linear-gradient(135deg, rgb(13, 59, 102), rgb(142, 125, 190))',
				'color' => 'rgb(255,255,255)',
			),
		);

		$settings = $engine->map_widget($node, 'heading');
		$this->assertSame('gradient', $settings['background_background'] ?? '');
	}
}
