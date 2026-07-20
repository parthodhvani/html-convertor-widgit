<?php
/**
 * Unit tests for the native Elementor reconstruction engine.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Services\RenderResult;
use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Elementor\CssMapper;
use HtmlToElementor\Elementor\WidgetClassifier;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{

	/**
	 * A small layout document with a computed-style DOM tree (as the Chromium
	 * service produces). Hero section + a flex row of two cards.
	 *
	 * @return array<string,mixed>
	 */
	private function layout(): array
	{
		$heading = function (string $tag, string $text, array $extra = array ()): array {
			return array_merge(
				array('tag' => $tag, 'cls' => '', 'text' => $text, 's' => array('fs' => '32px', 'fw' => '700', 'color' => 'rgb(13,71,161)'), 'atomic' => true, 'html' => "<$tag>$text</$tag>"),
				$extra
			);
		};
		$para = function (string $text): array {
			return array('tag' => 'p', 'cls' => '', 'text' => $text, 's' => array('fs' => '16px'), 'atomic' => true, 'html' => "<p>$text</p>");
		};

		return array(
			'meta' => array('title' => 'Sample'),
			'assets' => array('combinedCss' => 'body{color:#000}'),
			'sections' => array(
				array(
					'tag' => 'header',
					'tree' => array(
						'tag' => 'header',
						'cls' => 'hero',
						'text' => '',
						's' => array('disp' => 'block', 'bg' => 'rgb(13, 71, 161)', 'pt' => 80, 'pb' => 80, 'h' => 400),
						'children' => array(
							$heading('h1', 'Hello World'),
							$para('Tagline goes here'),
						),
					),
				),
				array(
					'tag' => 'div',
					'tree' => array(
						'tag' => 'div',
						'cls' => 'features',
						'text' => '',
						's' => array('disp' => 'flex', 'fd' => 'row', 'gap' => '24px'),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'card',
								'text' => '',
								's' => array('disp' => 'block', 'bg' => 'rgb(255,255,255)', 'br' => 10, 'sh' => 'rgba(0, 0, 0, 0.08) 0px 6px 18px 0px'),
								'children' => array($heading('h3', 'Fast'), $para('Speedy.')),
							),
							array(
								'tag' => 'div',
								'cls' => 'card',
								'text' => '',
								's' => array('disp' => 'block', 'bg' => 'rgb(255,255,255)', 'br' => 10),
								'children' => array($heading('h3', 'Reliable'), $para('Solid.')),
							),
						),
					),
				),
			),
		);
	}

	public function test_native_mode_emits_nested_containers_and_widgets(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->layout()), array('mode' => 'native'));

		$this->assertSame('widgets', $result['report']['mode']);
		$this->assertCount(2, $result['data']); // two top-level sections.

		// No HTML widgets for this clean markup.
		$this->assertSame(0, $result['report']['html_widgets']);
		$this->assertGreaterThanOrEqual(4, $result['report']['native_widgets']);

		// Hero -> container with heading + text widgets.
		$hero = $result['data'][0];
		$this->assertSame('container', $hero['elType']);
		$this->assertSame('rgb(13, 71, 161)', $hero['settings']['background_color']);
		$types = array_column($hero['elements'], 'widgetType');
		$this->assertContains('heading', $types);
		$this->assertContains('text-editor', $types);
	}

	public function test_flex_row_becomes_row_container_with_nested_card_containers(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->layout()), array('mode' => 'native'));

		$features = $result['data'][1];
		$this->assertSame('row', $features['settings']['flex_direction']);
		$this->assertCount(2, $features['elements']); // two card containers.
		foreach ($features['elements'] as $card) {
			$this->assertSame('container', $card['elType']);
			$this->assertTrue($card['isInner']);
			$this->assertSame('10', (string) $card['settings']['border_radius']['top']);
		}
	}

	public function test_widget_breakdown_and_components_recorded(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->layout()), array('mode' => 'native'));
		$this->assertSame(3, $result['report']['widget_breakdown']['heading'] ?? 0);
		$this->assertArrayHasKey('card', $result['report']['components']);
	}

	public function test_css_mapper_typography_and_shadow(): void
	{
		$mapper = new CssMapper();
		$node = array('s' => array('ff' => '"Montserrat", sans-serif', 'fs' => '48px', 'fw' => 'bold', 'sh' => 'rgba(0, 0, 0, 0.2) 0px 4px 12px 0px'));

		$typo = $mapper->typography($node);
		$this->assertSame('custom', $typo['typography_typography']);
		$this->assertSame('Montserrat', $typo['typography_font_family']);
		$this->assertSame(48.0, $typo['typography_font_size']['size']);
		$this->assertSame('700', $typo['typography_font_weight']);

		$shadow = $mapper->box_shadow($node);
		$this->assertSame('yes', $shadow['box_shadow_box_shadow_type']);
		$this->assertSame(4.0, $shadow['box_shadow_box_shadow']['vertical']);
		$this->assertSame(12.0, $shadow['box_shadow_box_shadow']['blur']);
	}

	public function test_css_mapper_gradient_and_asymmetric_border(): void
	{
		$mapper = new CssMapper();
		$gradient = $mapper->background(array(
			's' => array(
				'bgGrad' => true,
				'bgImg' => 'linear-gradient(135deg, rgb(26, 58, 74), rgb(13, 31, 40))',
			),
		));
		$this->assertSame('gradient', $gradient['background_background']);
		$this->assertSame('rgb(26, 58, 74)', $gradient['background_color']);
		$this->assertSame('rgb(13, 31, 40)', $gradient['background_color_b']);
		$this->assertSame('linear', $gradient['background_gradient_type']);
		$this->assertSame(135.0, $gradient['background_gradient_angle']['size']);

		$border = $mapper->border(array(
			's' => array(
				'bd' => array('t' => 1, 'r' => 4, 'b' => 1, 'l' => 1),
				'bds' => 'solid',
				'bdc' => 'rgb(204, 68, 85)',
				'brad' => array('tl' => 4, 'tr' => 20, 'br' => 4, 'bl' => 20),
			),
		));
		$this->assertSame('1', (string) $border['border_width']['top']);
		$this->assertSame('4', (string) $border['border_width']['right']);
		$this->assertFalse($border['border_width']['isLinked']);
		$this->assertSame('20', (string) $border['border_radius']['right']);
		$this->assertSame('4', (string) $border['border_radius']['top']);
	}

	public function test_css_mapper_image_media_emits_custom_css(): void
	{
		$mapper = new CssMapper();
		$media = $mapper->image_media(array(
			's' => array('of' => 'cover', 'ar' => '5 / 3'),
		));
		$this->assertSame('cover', $media['_h2e_object_fit']);
		$this->assertStringContainsString('object-fit:cover', $media['_h2e_custom_css']);
		$this->assertStringContainsString('aspect-ratio:5 / 3', $media['_h2e_custom_css']);
		$this->assertContains('object-fit', $media['_h2e_unsupported']);
	}

	public function test_classifier_fallback_and_components(): void
	{
		$classifier = new WidgetClassifier();
		// Inline SVG becomes a native Image widget (data URI), not an HTML widget.
		$svg = $classifier->classify(array('tag' => 'svg', 'html' => '<svg viewBox="0 0 10 10"><rect/></svg>'));
		$this->assertSame('widget', $svg['kind']);
		$this->assertSame('image', $svg['type']);
		// Forms / canvas / tables fall back to HTML (last resort).
		$this->assertSame('fallback', $classifier->classify(array('tag' => 'form', 'html' => '<form></form>'))['kind']);
		$this->assertSame('fallback', $classifier->classify(array('tag' => 'canvas', 'html' => '<canvas></canvas>'))['kind']);

		$heading = $classifier->classify(array('tag' => 'h2', 'text' => 'Title', 'html' => '<h2>Title</h2>'));
		$this->assertSame('heading', $heading['type']);

		// Absolute children are reconstructed via Elementor position controls.
		$layered = array(
			'tag' => 'section',
			'cls' => 'page-hero',
			'children' => array(array('tag' => 'div', 's' => array('pos' => 'absolute'))),
		);
		$this->assertFalse($classifier->container_needs_fallback($layered));

		// v3 recognition engine reconstructs layered blocks natively.
		$recognition = new \HtmlToElementor\Engine\SemanticComponentRecognizer();
		$layered['layoutRole'] = 'layered_block';
		$layered['layeredLayout'] = array('background' => null, 'content' => array(), 'in_flow' => array());
		$this->assertFalse($recognition->container_needs_fallback($layered));
	}

	public function test_css_mapper_combine_preserves_custom_css_bags(): void
	{
		$mapper = new CssMapper();
		$combined = $mapper->combine(
			array(
				'_h2e_custom_css' => 'background-image:linear-gradient(red,blue)',
				'_h2e_unsupported' => array('multi-layer-gradient'),
				'background_background' => 'gradient',
			),
			array(
				'_h2e_custom_css' => 'overflow:hidden',
				'_h2e_unsupported' => array('overflow'),
			)
		);
		$this->assertSame('gradient', $combined['background_background']);
		$this->assertStringContainsString('background-image:linear-gradient(red,blue)', $combined['_h2e_custom_css']);
		$this->assertStringContainsString('overflow:hidden', $combined['_h2e_custom_css']);
		$this->assertContains('multi-layer-gradient', $combined['_h2e_unsupported']);
		$this->assertContains('overflow', $combined['_h2e_unsupported']);
	}

	public function test_css_mapper_elliptical_border_radius_emits_raw_css(): void
	{
		$mapper = new CssMapper();
		$border = $mapper->border(array(
			's' => array(
				'brRaw' => '40% 60% 42% 58% / 55% 45%',
				'brad' => array('tl' => 40, 'tr' => 60, 'br' => 42, 'bl' => 58),
			),
		));
		// Percent/elliptical radius must not leave a competing px Elementor control.
		$this->assertArrayNotHasKey('border_radius', $border);
		$this->assertStringContainsString('border-radius:40% 60% 42% 58% / 55% 45%', $border['_h2e_custom_css']);
		$this->assertContains('elliptical-border-radius', $border['_h2e_unsupported']);
	}

	public function test_card_link_classifies_as_text_not_button(): void
	{
		$clf = new \HtmlToElementor\Engine\VisualLeafClassifier();
		$result = $clf->classify(array(
			'tag' => 'a',
			'cls' => 'card-link',
			'text' => 'Mehr erfahren',
			'href' => 'angebot.html',
			'html' => '<a href="angebot.html" class="card-link">Mehr erfahren <i class="fa-solid fa-arrow-right"></i></a>',
			's' => array(
				'color' => 'rgb(201, 162, 39)',
				'disp' => 'inline-flex',
				'w' => 118,
				'h' => 24,
				'fw' => '600',
				'fs' => '14.4px',
			),
		));
		$this->assertSame('text-editor', $result['type'] ?? null);
		$this->assertStringContainsString('Mehr erfahren', (string) ($result['settings']['editor'] ?? ''));
	}
}
