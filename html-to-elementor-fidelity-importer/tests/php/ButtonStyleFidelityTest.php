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
