<?php
/**
 * Button style control-id fidelity tests (Elementor button-trait.php).
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\CssMappingEngine;
use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

final class ButtonStyleFidelityTest extends TestCase
{

	public function test_map_button_uses_elementor_text_padding_and_button_box_shadow(): void
	{
		$engine = new CssMappingEngine();
		$node = array(
			'tag' => 'a',
			'cls' => 'btn btn-gold',
			'text' => 'Book now',
			'href' => '#',
			's' => array(
				'bgImg' => 'linear-gradient(135deg, rgb(201, 162, 39) 0%, rgb(230, 193, 90) 100%)',
				'color' => 'rgb(42, 31, 0)',
				'br' => 999,
				'brad' => array('tl' => 999, 'tr' => 999, 'br' => 999, 'bl' => 999),
				'sh' => 'rgba(201, 162, 39, 0.28) 0px 10px 30px 0px',
				'pt' => 14,
				'pr' => 28,
				'pb' => 14,
				'pl' => 28,
				'gap' => '10px',
				'fs' => '16px',
				'fw' => '600',
			),
		);

		$settings = $engine->map_widget($node, 'button');

		$this->assertArrayHasKey('text_padding', $settings);
		$this->assertSame('14', (string) $settings['text_padding']['top']);
		$this->assertSame('28', (string) $settings['text_padding']['right']);
		$this->assertArrayNotHasKey('padding', $settings);

		$this->assertSame('yes', $settings['button_box_shadow_box_shadow_type'] ?? null);
		$this->assertSame(10.0, $settings['button_box_shadow_box_shadow']['vertical'] ?? null);
		$this->assertSame(30.0, $settings['button_box_shadow_box_shadow']['blur'] ?? null);
		$this->assertArrayNotHasKey('box_shadow_box_shadow_type', $settings);

		$this->assertSame('gradient', $settings['background_background'] ?? null);
		$this->assertSame('rgb(201, 162, 39)', $settings['background_color'] ?? null);
		$this->assertSame('rgb(230, 193, 90)', $settings['background_color_b'] ?? null);
		$this->assertSame(999.0, (float) ($settings['border_radius']['top'] ?? 0));
		$this->assertSame(10.0, (float) ($settings['icon_indent']['size'] ?? 0));
	}

	public function test_map_button_preserves_explicit_min_width(): void
	{
		$engine = new CssMappingEngine();
		$node = array(
			'tag' => 'a',
			'cls' => 'btn btn-cta',
			'text' => 'Subscribe',
			'href' => '#',
			's' => array(
				'bg' => 'rgb(20, 120, 200)',
				'color' => 'rgb(255, 255, 255)',
				'minW' => '220px',
				'pt' => 14,
				'pr' => 28,
				'pb' => 14,
				'pl' => 28,
			),
		);

		$settings = $engine->map_widget($node, 'button');

		$this->assertSame(220.0, (float) ($settings['min_width']['size'] ?? 0));
	}

	public function test_map_button_preserves_second_box_shadow_layer_via_custom_css(): void
	{
		$engine = new CssMappingEngine();
		$node = array(
			'tag' => 'a',
			'cls' => 'btn btn-glow',
			'text' => 'Get started',
			'href' => '#',
			's' => array(
				'bg' => 'rgb(90, 60, 200)',
				'color' => 'rgb(255, 255, 255)',
				// Tight dark contact shadow + soft colored glow.
				'sh' => 'rgba(0, 0, 0, 0.25) 0px 2px 4px 0px, rgba(90, 60, 200, 0.45) 0px 12px 24px 0px',
				'pt' => 14,
				'pr' => 28,
				'pb' => 14,
				'pl' => 28,
			),
		);

		$settings = $engine->map_widget($node, 'button');

		// Native control still holds the first layer for editability.
		$this->assertSame('yes', $settings['button_box_shadow_box_shadow_type'] ?? null);
		$this->assertSame(4.0, $settings['button_box_shadow_box_shadow']['blur'] ?? null);
		// Full multi-layer value carried through so the glow isn't dropped.
		$custom_css = (string) ($settings['_h2e_custom_css'] ?? '');
		$this->assertStringContainsString('box-shadow:', $custom_css);
		$this->assertStringContainsString('rgba(90, 60, 200, 0.45)', $custom_css);
		$this->assertStringContainsString('!important', $custom_css);
	}

	public function test_map_button_translates_captured_hover_style_to_elementor_hover_controls(): void
	{
		$engine = new CssMappingEngine();
		$node = array(
			'tag' => 'a',
			'cls' => 'btn btn-gold',
			'text' => 'Book now',
			'href' => '#',
			's' => array(
				'bg' => 'rgb(201, 162, 39)',
				'color' => 'rgb(42, 31, 0)',
				'pt' => 14,
				'pr' => 28,
				'pb' => 14,
				'pl' => 28,
			),
			// Captured by chromium-service/lib/segmenter.js `hoverStyleFor()`.
			'hover' => array(
				'bg' => 'rgb(230, 193, 90)',
				'color' => 'rgb(255, 255, 255)',
				'bdc' => 'rgb(10, 10, 10)',
				'sh' => 'rgba(0, 0, 0, 0.3) 0px 6px 16px 0px',
			),
		);

		$settings = $engine->map_widget($node, 'button');

		$this->assertSame('rgb(255, 255, 255)', $settings['hover_color'] ?? null);
		$this->assertSame('classic', $settings['button_background_hover_background'] ?? null);
		$this->assertSame('rgb(230, 193, 90)', $settings['button_background_hover_color'] ?? null);
		$this->assertSame('rgb(10, 10, 10)', $settings['button_hover_border_color'] ?? null);
		$this->assertSame('yes', $settings['button_hover_box_shadow_box_shadow_type'] ?? null);
		$this->assertSame(16.0, $settings['button_hover_box_shadow_box_shadow']['blur'] ?? null);
	}

	public function test_outline_button_forces_transparent_fill(): void
	{
		$engine = new CssMappingEngine();
		$node = array(
			'tag' => 'a',
			'cls' => 'btn btn-outline',
			'text' => 'Angebot entdecken',
			'href' => '#',
			's' => array(
				'color' => 'rgb(244, 236, 214)',
				'br' => 999,
				'bdw' => 1.5,
				'bdc' => 'rgba(255, 255, 255, 0.35)',
				'bds' => 'solid',
				'pt' => 14,
				'pr' => 28,
				'pb' => 14,
				'pl' => 28,
			),
		);

		$settings = $engine->map_widget($node, 'button');
		$this->assertSame('solid', $settings['border_border'] ?? null);
		$this->assertSame('rgba(255, 255, 255, 0.35)', $settings['border_color'] ?? null);
		$this->assertSame('classic', $settings['background_background'] ?? null);
		$this->assertSame('rgba(0,0,0,0)', $settings['background_color'] ?? null);
		$this->assertSame('rgb(244, 236, 214)', $settings['button_text_color'] ?? null);
	}

	public function test_generator_emits_button_control_ids_end_to_end(): void
	{
		$layout = array(
			'meta' => array('title' => 'Buttons'),
			'assets' => array('combinedCss' => ''),
			'sections' => array(
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						's' => array('disp' => 'block'),
						'children' => array(
							array(
								'tag' => 'a',
								'cls' => 'btn btn-gold',
								'text' => 'Termin buchen',
								'href' => 'buchen.html',
								'atomic' => true,
								'html' => '<a class="btn btn-gold" href="buchen.html">Termin buchen <i class="fa-solid fa-arrow-right"></i></a>',
								's' => array(
									'bgImg' => 'linear-gradient(135deg, rgb(201, 162, 39) 0%, rgb(230, 193, 90) 100%)',
									'color' => 'rgb(42, 31, 0)',
									'br' => 999,
									'sh' => 'rgba(201, 162, 39, 0.28) 0px 10px 30px 0px',
									'pt' => 14,
									'pr' => 28,
									'pb' => 14,
									'pl' => 28,
									'gap' => 10,
									'h' => 48,
									'w' => 200,
									'bg' => 'rgb(201, 162, 39)',
								),
							),
						),
					),
				),
			),
		);

		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));
		$button = $this->find_button($result['data']);
		$this->assertNotNull($button);
		$s = $button['settings'];

		$this->assertArrayHasKey('text_padding', $s);
		$this->assertArrayNotHasKey('padding', $s);
		$this->assertSame('yes', $s['button_box_shadow_box_shadow_type'] ?? null);
		$this->assertArrayNotHasKey('box_shadow_box_shadow_type', $s);
		$this->assertSame('gradient', $s['background_background'] ?? null);
		$this->assertNotEmpty($s['selected_icon']['value'] ?? null);
		$this->assertSame('right', $s['icon_align'] ?? null);
	}

	public function test_bootstrap_icon_button_keeps_its_icon(): void
	{
		$layout = array(
			'meta' => array('title' => 'Buttons'),
			'assets' => array('combinedCss' => ''),
			'sections' => array(
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						's' => array('disp' => 'block'),
						'children' => array(
							array(
								'tag' => 'a',
								'cls' => 'btn btn-primary',
								'text' => 'Weiter',
								'href' => '#',
								'atomic' => true,
								// Bootstrap Icons — no `fa-` class anywhere.
								'html' => '<a class="btn btn-primary" href="#">Weiter <i class="bi bi-arrow-right"></i></a>',
								's' => array(
									'bg' => 'rgb(13, 110, 253)',
									'color' => 'rgb(255, 255, 255)',
									'pt' => 10,
									'pr' => 20,
									'pb' => 10,
									'pl' => 20,
									'h' => 44,
									'w' => 140,
								),
							),
						),
					),
				),
			),
		);

		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));
		$button = $this->find_button($result['data']);
		$this->assertNotNull($button);
		$s = $button['settings'];

		$this->assertSame('bi bi-arrow-right', $s['selected_icon']['value'] ?? null);
		$this->assertSame('right', $s['icon_align'] ?? null);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<string,mixed>|null
	 */
	private function find_button(array $elements): ?array
	{
		foreach ($elements as $el) {
			if (($el['elType'] ?? '') === 'widget' && ($el['widgetType'] ?? '') === 'button') {
				return $el;
			}
			$nested = $this->find_button((array) ($el['elements'] ?? array()));
			if (null !== $nested) {
				return $nested;
			}
		}
		return null;
	}
}
