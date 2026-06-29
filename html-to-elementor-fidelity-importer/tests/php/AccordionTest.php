<?php
/**
 * Tests for native accordion / FAQ reconstruction.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Engine\AccordionRecognizer;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the AccordionRecognizer and the converter produce a single native
 * Elementor `accordion` widget (instead of HTML / generic containers) for
 * disclosure and FAQ markup.
 */
final class AccordionTest extends TestCase
{

	/**
	 * A `<details>`/`<summary>` group as captured by the Chromium segmenter.
	 *
	 * @return array<string,mixed>
	 */
	private function details_node(string $q, string $a): array
	{
		return array(
			'tag' => 'details',
			'cls' => '',
			's' => array('disp' => 'block'),
			'children' => array(
				array('tag' => 'summary', 'text' => $q, 'atomicText' => true, 'html' => "<summary>$q</summary>", 'children' => array(), 's' => array()),
				array('tag' => 'p', 'text' => $a, 'atomic' => true, 'html' => "<p>$a</p>", 's' => array()),
			),
		);
	}

	public function test_recognizer_detects_details_group(): void
	{
		$recognizer = new AccordionRecognizer();
		$node = array(
			'tag' => 'section',
			'cls' => 'faq',
			'children' => array(
				array('tag' => 'h2', 'text' => 'FAQ', 'atomic' => true, 'html' => '<h2>FAQ</h2>', 's' => array()),
				$this->details_node('What is it?', 'A visual importer.'),
				$this->details_node('Is it editable?', 'Yes, fully native.'),
			),
		);

		$detected = $recognizer->detect($node);
		$this->assertNotNull($detected);
		$this->assertCount(2, $detected['items']);
		$this->assertSame('What is it?', $detected['items'][0]['title']);
		$this->assertSame('<p>A visual importer.</p>', $detected['items'][0]['content']);
	}

	public function test_recognizer_detects_hinted_div_faq_with_descent(): void
	{
		$recognizer = new AccordionRecognizer();
		$item = function (string $q, string $a): array {
			return array(
				'tag' => 'div',
				'cls' => 'faq-item',
				'children' => array(
					array('tag' => 'h3', 'text' => $q, 'atomic' => true, 'html' => "<h3>$q</h3>", 's' => array()),
					array('tag' => 'p', 'text' => $a, 'atomic' => true, 'html' => "<p>$a</p>", 's' => array()),
				),
			);
		};
		// FAQ hint on the outer section; items live inside an inner wrapper.
		$node = array(
			'tag' => 'section',
			'cls' => 'faq-section',
			'layoutRole' => 'faq',
			'children' => array(
				array(
					'tag' => 'div',
					'cls' => 'container',
					'children' => array(
						array('tag' => 'h2', 'text' => 'Questions', 'atomic' => true, 'html' => '<h2>Questions</h2>', 's' => array()),
						$item('Q1', 'Answer one.'),
						$item('Q2', 'Answer two.'),
						$item('Q3', 'Answer three.'),
					),
				),
			),
		);

		$detected = $recognizer->detect($node);
		$this->assertNotNull($detected);
		$this->assertCount(3, $detected['items']);
		$this->assertSame('Q2', $detected['items'][1]['title']);
		$this->assertSame('<p>Answer two.</p>', $detected['items'][1]['content']);
	}

	public function test_recognizer_ignores_unhinted_card_grid(): void
	{
		$recognizer = new AccordionRecognizer();
		$card = function (string $h, string $p): array {
			return array(
				'tag' => 'div',
				'cls' => 'card',
				'children' => array(
					array('tag' => 'h3', 'text' => $h, 'atomic' => true, 'html' => "<h3>$h</h3>", 's' => array()),
					array('tag' => 'p', 'text' => $p, 'atomic' => true, 'html' => "<p>$p</p>", 's' => array()),
				),
			);
		};
		$node = array(
			'tag' => 'div',
			'cls' => 'features',
			'children' => array($card('Fast', 'Speedy.'), $card('Reliable', 'Solid.')),
		);

		$this->assertNull($recognizer->detect($node), 'Feature card grids must not be treated as accordions.');
	}

	public function test_generator_emits_single_native_accordion_widget(): void
	{
		$layout = array(
			'meta' => array('title' => 'FAQ Page'),
			'assets' => array('combinedCss' => 'body{color:#000}'),
			'sections' => array(
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						'cls' => 'faq',
						's' => array('disp' => 'block', 'pt' => 60, 'pb' => 60),
						'children' => array(
							array('tag' => 'h2', 'text' => 'Frequently Asked Questions', 'atomic' => true, 'html' => '<h2>Frequently Asked Questions</h2>', 's' => array('fs' => '32px')),
							$this->details_node('What does it do?', 'It rebuilds pages as native Elementor.'),
							$this->details_node('Is it responsive?', 'Yes, across all breakpoints.'),
							$this->details_node('Can I edit it?', 'Every widget stays editable.'),
						),
					),
				),
			),
		);

		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));

		$this->assertCount(1, $result['data']);
		$section = $result['data'][0];
		$this->assertSame('container', $section['elType']);

		$widget_types = array_column($section['elements'], 'widgetType');
		$this->assertContains('accordion', $widget_types);
		$this->assertNotContains('html', $widget_types);

		$accordion = null;
		foreach ($section['elements'] as $el) {
			if (($el['widgetType'] ?? '') === 'accordion') {
				$accordion = $el;
				break;
			}
		}
		$this->assertNotNull($accordion);
		$this->assertCount(3, $accordion['settings']['tabs']);
		$this->assertSame('What does it do?', $accordion['settings']['tabs'][0]['tab_title']);
		$this->assertNotEmpty($accordion['settings']['tabs'][0]['_id']);

		$this->assertSame(0, $result['report']['html_widgets']);
		$this->assertSame(1, $result['report']['widget_breakdown']['accordion'] ?? 0);
	}
}
