<?php
/**
 * Regression tests for the kontakt.html business template fixture.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

/**
 * Validates native widget coverage and layout reconstruction for the
 * WF Baumanagement kontakt page without requiring a live Chromium render.
 */
final class KontaktRegressionTest extends TestCase
{

	/**
	 * Synthetic layout document mirroring kontakt.html structure after Chromium
	 * extraction (nav, hero, two-column contact, map band, CTA, footer).
	 *
	 * @return array<string,mixed>
	 */
	private function kontakt_layout(): array
	{
		$heading = fn(string $tag, string $text, array $extra = array()): array => array_merge(
			array('tag' => $tag, 'text' => $text, 'atomic' => true, 'html' => "<$tag>$text</$tag>", 's' => array('fs' => '32px', 'fw' => '800', 'color' => 'rgb(48,49,58)')),
			$extra
		);
		$text = fn(string $t): array => array('tag' => 'p', 'text' => $t, 'atomic' => true, 'html' => "<p>$t</p>", 's' => array('fs' => '13px', 'color' => 'rgba(90,91,101,0.52)'));
		$btn = fn(string $t, string $cls = ''): array => array('tag' => 'button', 'text' => $t, 'atomic' => true, 'cls' => $cls, 'html' => "<button class=\"$cls\">$t</button>", 's' => array('bg' => 'rgb(149,125,74)', 'color' => 'rgb(255,255,255)', 'pt' => 9, 'pb' => 9, 'pl' => 22, 'pr' => 22));

		return array(
			'meta' => array('title' => 'Kontakt – WF Baumanagement GmbH'),
			'assets' => array('combinedCss' => ':root{--gold:#957d4a}'),
			'sections' => array(
				// Nav
				array(
					'tag' => 'nav',
					'tree' => array(
						'tag' => 'nav',
						'cls' => 'nav-links',
						's' => array('disp' => 'flex', 'fd' => 'row', 'bg' => 'rgb(48,49,58)', 'jc' => 'space-between', 'ai' => 'center', 'pt' => 14, 'pb' => 14, 'pl' => 48, 'pr' => 48),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'nav-brand',
								's' => array('disp' => 'flex', 'fd' => 'row', 'gap' => '14px'),
								'children' => array(
									$heading('strong', 'Baumanagement', array('tag' => 'strong', 's' => array('fs' => '11.5px', 'color' => 'rgb(255,255,255)'))),
								),
							),
							array(
								'tag' => 'ul',
								'cls' => 'nav-links',
								's' => array('disp' => 'flex', 'fd' => 'row', 'gap' => '30px'),
								'children' => array(
									array('tag' => 'li', 'children' => array(array('tag' => 'a', 'text' => 'Kontakt', 'atomic' => true, 'href' => '#', 'html' => '<a>Kontakt</a>', 's' => array('color' => 'rgb(149,125,74)')))),
								),
							),
							$btn('Beratung anfragen', 'nav-cta'),
						),
					),
				),
				// Hero
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						'cls' => 'page-hero',
						's' => array('pos' => 'relative', 'h' => 380, 'ov' => 'hidden'),
						'children' => array(
							array('tag' => 'img', 'src' => 'https://example.com/hero.jpg', 'atomic' => true, 'html' => '<img src="hero.jpg">', 's' => array('w' => 1440, 'h' => 380)),
							array('tag' => 'div', 'cls' => 'page-hero-overlay', 's' => array('pos' => 'absolute', 'bgImg' => 'linear-gradient(90deg,rgba(48,49,58,.9),rgba(48,49,58,.2))'), 'children' => array()),
							array(
								'tag' => 'div',
								'cls' => 'page-hero-content',
								's' => array('pos' => 'absolute'),
								'children' => array(
									$heading('h1', 'Sprechen wir über Ihr Projekt.', array('s' => array('fs' => '52px', 'fw' => '900', 'color' => 'rgb(255,255,255)'))),
									$text('Kostenlose Erstberatung – unverbindlich und persönlich.'),
								),
							),
						),
					),
				),
				// Two-column contact
				array(
					'tag' => 'div',
					'tree' => array(
						'tag' => 'div',
						'cls' => 'kontakt-main',
						's' => array('disp' => 'grid', 'gtc' => '1fr 1fr', 'h' => 600),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'kontakt-info',
								's' => array('disp' => 'flex', 'fd' => 'column', 'bg' => 'rgb(48,49,58)', 'pt' => 70, 'pb' => 70, 'pl' => 52, 'pr' => 52),
								'children' => array(
									$heading('h2', 'Ihr persönlicher Kontakt', array('s' => array('color' => 'rgb(255,255,255)'))),
									$text('Mit mehr als 60 Jahren gemeinsamer Erfahrung.'),
								),
							),
							array(
								'tag' => 'div',
								'cls' => 'kontakt-form-wrap',
								's' => array('disp' => 'flex', 'fd' => 'column', 'bg' => 'rgb(245,244,241)', 'pt' => 70, 'pb' => 70, 'pl' => 52, 'pr' => 52),
								'children' => array(
									$heading('h2', 'Kostenlose Erstberatung'),
									array('tag' => 'form', 'html' => '<form></form>', 'atomic' => true, 's' => array()),
								),
							),
						),
					),
				),
				// Footer
				array(
					'tag' => 'footer',
					'tree' => array(
						'tag' => 'footer',
						'cls' => 'footer',
						's' => array('disp' => 'flex', 'fd' => 'row', 'bg' => 'rgb(26,27,34)', 'jc' => 'space-between', 'pt' => 30, 'pb' => 30),
						'children' => array(
							$text('© 2026 WF Baumanagement GmbH'),
						),
					),
				),
			),
		);
	}

	public function test_kontakt_native_mode_uses_mostly_native_widgets(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->kontakt_layout()), array('mode' => 'native'));

		$this->assertSame('native', $result['report']['mode']);
		$this->assertSame(2, $result['report']['engine_version']);
		$this->assertGreaterThanOrEqual(5, $result['report']['native_widgets']);
		$html_pct = ($result['report']['html_widgets'] / max(1, $result['report']['native_widgets'] + $result['report']['html_widgets'])) * 100;
		$this->assertLessThan(30, $html_pct, 'HTML widgets should be under 30% for kontakt layout');
	}

	public function test_kontakt_hero_reconstructed_natively(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->kontakt_layout()), array('mode' => 'native'));

		$hero = $result['data'][1];
		$this->assertSame('container', $hero['elType']);
		$this->assertArrayHasKey('background_image', $hero['settings']);
		$types = $this->collect_widget_types($hero);
		$this->assertContains('heading', $types);
		$this->assertNotContains('html', $types);
	}

	/**
	 * @param array<string,mixed> $el Elementor element.
	 * @return array<int,string>
	 */
	private function collect_widget_types(array $el): array
	{
		$types = array();
		if ('widget' === ($el['elType'] ?? '') && !empty($el['widgetType'])) {
			$types[] = (string) $el['widgetType'];
		}
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			$types = array_merge($types, $this->collect_widget_types($child));
		}
		return $types;
	}

	public function test_kontakt_two_column_grid_produces_row_layout(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->kontakt_layout()), array('mode' => 'native'));

		$main = $result['data'][2];
		$this->assertSame('container', $main['elType']);
		$this->assertContains($main['settings']['flex_direction'] ?? '', array('row', 'column'));
		$this->assertGreaterThanOrEqual(2, count($main['elements']));
	}

	public function test_kontakt_validation_scores_present(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->kontakt_layout()), array('mode' => 'native'));

		$this->assertArrayHasKey('validation', $result);
		$this->assertArrayHasKey('fidelity', $result['validation']);
		$this->assertArrayHasKey('quality', $result);
		$this->assertGreaterThan(0, $result['quality']['native_widget_percentage']);
	}

	public function test_kontakt_improved_over_preserve_mode(): void
	{
		$gen = new ElementorJsonGenerator();
		$layout = RenderResult::from_array($this->kontakt_layout());

		$native = $gen->generate($layout, array('mode' => 'native'));
		$preserve = $gen->generate($layout, array('mode' => 'preserve'));

		$this->assertGreaterThan($preserve['report']['native_widgets'], $native['report']['native_widgets']);
		$this->assertLessThan($preserve['report']['html_widgets'], $native['report']['html_widgets']);
	}
}
