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

		$this->assertSame('native', $result['report']['mode']);
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

		// Layered (absolute) children force an HTML fallback for the legacy classifier.
		$layered = array(
			'tag' => 'section',
			'cls' => 'page-hero',
			'children' => array(array('tag' => 'div', 's' => array('pos' => 'absolute'))),
		);
		$this->assertTrue($classifier->container_needs_fallback($layered));

		// v2 recognition engine reconstructs heroes natively.
		$recognition = new \HtmlToElementor\Engine\ComponentRecognitionEngine();
		$layered['layoutRole'] = 'hero';
		$this->assertFalse($recognition->container_needs_fallback($layered));
	}
}
