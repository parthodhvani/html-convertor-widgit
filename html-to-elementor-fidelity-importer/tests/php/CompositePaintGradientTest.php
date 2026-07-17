<?php
/**
 * Composite widgets must retain browser gradient paint.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\CssMappingEngine;
use HtmlToElementor\Elementor\CssMapper;
use HtmlToElementor\Elementor\LayoutTreeConverter;
use PHPUnit\Framework\TestCase;

final class CompositePaintGradientTest extends TestCase
{

	public function test_cta_maps_root_gradient_background(): void
	{
		$engine = new CssMappingEngine(new CssMapper());
		$node = array(
			'cls' => 'cta-banner',
			's' => array(
				'bgImg' => 'linear-gradient(135deg, rgb(13, 59, 102), rgb(28, 42, 84))',
			),
		);

		$settings = $engine->map_widget($node, 'call-to-action');

		$this->assertSame('gradient', $settings['background_background']);
		$this->assertSame('rgb(13, 59, 102)', $settings['background_color']);
		$this->assertSame('rgb(28, 42, 84)', $settings['background_color_b']);
	}

	public function test_composite_emits_card_icon_chrome_container(): void
	{
		$converter = new LayoutTreeConverter();
		$node = array(
			'tag' => 'div',
			'cls' => 'service-card',
			's' => array(
				'w' => 320,
				'h' => 280,
				'bg' => 'rgb(255, 255, 255)',
			),
			'children' => array(
				array(
					'tag' => 'div',
					'cls' => 'card-icon',
					's' => array(
						'w' => 56,
						'h' => 56,
						'bgImg' => 'linear-gradient(135deg, rgb(13, 59, 102), rgb(142, 125, 190))',
					),
					'children' => array(),
					'atomic' => true,
				),
				array(
					'tag' => 'h3',
					'text' => 'Coaching',
					's' => array('w' => 200, 'h' => 32, 'fs' => 22, 'fw' => 700),
					'atomic' => true,
				),
				array(
					'tag' => 'p',
					'cls' => 'price',
					'text' => '€120 / Session',
					's' => array('w' => 200, 'h' => 24, 'fs' => 18),
					'atomic' => true,
				),
				array(
					'tag' => 'a',
					'cls' => 'btn',
					'text' => 'Buchen',
					'href' => '#',
					's' => array('w' => 120, 'h' => 40),
					'atomic' => true,
				),
			),
		);

		$el = $converter->emit_composite_widget($node);
		$this->assertNotNull($el);
		$this->assertSame('container', $el['elType']);

		$grads = 0;
		$walk = function (array $e) use (&$walk, &$grads): void {
			$s = $e['settings'] ?? array();
			if (($s['background_background'] ?? '') === 'gradient') {
				++$grads;
			}
			foreach ((array) ($e['elements'] ?? array()) as $child) {
				if (is_array($child)) {
					$walk($child);
				}
			}
		};
		$walk($el);

		$this->assertGreaterThanOrEqual(1, $grads, 'card-icon gradient must be emitted');
	}
}
