<?php
/**
 * Prevent Font Awesome / btn classes on Elementor wrappers (2× icons / double chrome).
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

final class WrapperClassSanitizationTest extends TestCase
{

	public function test_icon_widget_does_not_keep_fa_classes_on_wrapper(): void
	{
		$layout = array(
			'meta' => array('title' => 'Icons'),
			'assets' => array('combinedCss' => ''),
			'sections' => array(
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						's' => array('disp' => 'block'),
						'children' => array(
							array(
								'tag' => 'i',
								'cls' => 'fa-solid fa-award',
								'atomic' => true,
								'html' => '<i class="fa-solid fa-award"></i>',
								's' => array('color' => 'rgb(255,255,255)', 'w' => 24, 'h' => 24),
							),
						),
					),
				),
			),
		);

		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));
		$icon = $this->find_widget($result['data'], 'icon');
		$this->assertNotNull($icon);
		$classes = (string) ($icon['settings']['_css_classes'] ?? '');
		$this->assertStringNotContainsString('fa-solid', $classes);
		$this->assertStringNotContainsString('fa-award', $classes);
		$this->assertNotEmpty($icon['settings']['selected_icon']['value'] ?? null);
	}

	public function test_button_widget_does_not_keep_btn_classes_on_wrapper(): void
	{
		$layout = array(
			'meta' => array('title' => 'Buttons'),
			'assets' => array('combinedCss' => '.btn-gold{box-shadow:0 10px 30px rgba(201,162,39,.28)}'),
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
								),
							),
						),
					),
				),
			),
		);

		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));
		$button = $this->find_widget($result['data'], 'button');
		$this->assertNotNull($button);
		$s = $button['settings'];
		$classes = (string) ($s['_css_classes'] ?? '');
		$this->assertStringNotContainsString('btn', $classes);
		$this->assertStringNotContainsString('btn-gold', $classes);
		$this->assertStringNotContainsString('fa-solid', $classes);
		$this->assertStringNotContainsString('fa-arrow-right', $classes);

		// Paint must live on Elementor controls only (no source-class double chrome).
		$this->assertSame('gradient', $s['background_background'] ?? null);
		$this->assertSame('yes', $s['button_box_shadow_box_shadow_type'] ?? null);
		$this->assertArrayHasKey('text_padding', $s);
		$this->assertSame('right', $s['icon_align'] ?? null);
		$this->assertStringContainsString('fa-arrow-right', (string) ($s['selected_icon']['value'] ?? ''));
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<string,mixed>|null
	 */
	private function find_widget(array $elements, string $type): ?array
	{
		foreach ($elements as $el) {
			if (($el['elType'] ?? '') === 'widget' && ($el['widgetType'] ?? '') === $type) {
				return $el;
			}
			$nested = $this->find_widget((array) ($el['elements'] ?? array()), $type);
			if (null !== $nested) {
				return $nested;
			}
		}
		return null;
	}
}
