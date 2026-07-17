<?php
/**
 * CssMapper gradient / background unit tests.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\CssMapper;
use PHPUnit\Framework\TestCase;

final class CssMapperGradientTest extends TestCase
{

	public function test_linear_gradient_maps_to_elementor_gradient_controls(): void
	{
		$mapper = new CssMapper();
		$node = array(
			's' => array(
				'bgImg' => 'linear-gradient(135deg, rgb(13, 59, 102) 0%, rgb(26, 90, 148) 55%, rgb(142, 125, 190) 100%)',
			),
		);

		$bg = $mapper->background($node);

		$this->assertSame('gradient', $bg['background_background']);
		$this->assertSame('linear', $bg['background_gradient_type']);
		$this->assertSame(135.0, (float) $bg['background_gradient_angle']['size']);
		$this->assertSame('rgb(13, 59, 102)', $bg['background_color']);
		$this->assertSame('rgb(142, 125, 190)', $bg['background_color_b']);
	}

	public function test_multi_layer_hero_uses_last_linear_as_base(): void
	{
		$mapper = new CssMapper();
		$node = array(
			's' => array(
				'bgImg' => 'radial-gradient(1200px 600px at 10% 10%, rgba(142, 125, 190, 0.35), rgba(0, 0, 0, 0) 60%), '
					. 'radial-gradient(900px 500px at 90% 20%, rgba(201, 162, 39, 0.2), rgba(0, 0, 0, 0) 60%), '
					. 'linear-gradient(135deg, rgb(13, 59, 102) 0%, rgb(20, 64, 110) 60%, rgb(28, 42, 84) 100%)',
			),
		);

		$bg = $mapper->background($node);

		$this->assertSame('gradient', $bg['background_background']);
		$this->assertSame('linear', $bg['background_gradient_type']);
		$this->assertSame('rgb(13, 59, 102)', $bg['background_color']);
		$this->assertSame('rgb(28, 42, 84)', $bg['background_color_b']);
	}

	public function test_url_background_still_classic(): void
	{
		$mapper = new CssMapper();
		$node = array(
			's' => array(
				'bgImg' => 'url("https://example.com/hero.jpg")',
				'bgSize' => 'cover',
			),
		);

		$bg = $mapper->background($node);

		$this->assertSame('classic', $bg['background_background']);
		$this->assertSame('https://example.com/hero.jpg', $bg['background_image']['url']);
		$this->assertArrayNotHasKey('background_color_b', $bg);
	}

	public function test_parse_gradient_public_helper(): void
	{
		$mapper = new CssMapper();
		$parsed = $mapper->parse_gradient('linear-gradient(to right, #C9A227, #e6c15a)');

		$this->assertNotNull($parsed);
		$this->assertSame('linear', $parsed['type']);
		$this->assertSame(90.0, $parsed['angle']);
		$this->assertSame('#C9A227', $parsed['color_a']);
		$this->assertSame('#e6c15a', $parsed['color_b']);
	}

	public function test_max_width_maps_to_elementor_sizing(): void
	{
		$mapper = new CssMapper();
		$node = array(
			's' => array(
				'maxW' => '1200px',
				'minH' => '0px',
				'minW' => '0px',
				'maxH' => 'none',
			),
		);

		$sizing = $mapper->sizing($node);

		$this->assertSame(1200.0, (float) $sizing['max_width']['size']);
		$this->assertSame('px', $sizing['max_width']['unit']);
		$this->assertSame(100.0, (float) $sizing['width']['size']);
		$this->assertSame('%', $sizing['width']['unit']);
		$this->assertSame('center', $sizing['align_self']);
		$this->assertArrayNotHasKey('min_width', $sizing);
		$this->assertArrayNotHasKey('max_height', $sizing);
	}
}
