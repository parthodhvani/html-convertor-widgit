<?php
/**
 * Regression tests for Petra Müller marketing-page widget mapping.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

/**
 * Ensures FAQ, service cards, testimonials, forms, CTAs and social icons from
 * the Petra Müller HTML design system convert to native Elementor widgets at
 * high coverage (≥95% native).
 */
final class PetraRegressionTest extends TestCase
{

	/**
	 * @return array<string,mixed>
	 */
	private function petra_layout(): array
	{
		$heading = static function (string $tag, string $text, array $s = array()): array {
			return array(
				'tag' => $tag,
				'text' => $text,
				'atomic' => true,
				'html' => "<$tag>$text</$tag>",
				's' => array_merge(array('fs' => '32px', 'fw' => '600', 'color' => 'rgb(13,59,102)'), $s),
			);
		};
		$text = static function (string $t, array $extra = array()): array {
			return array_merge(array(
				'tag' => 'p',
				'text' => $t,
				'atomic' => true,
				'html' => "<p>$t</p>",
				's' => array('fs' => '16px', 'color' => 'rgb(90,106,130)'),
			), $extra);
		};
		$btn = static function (string $t, string $href = 'buchen.html'): array {
			return array(
				'tag' => 'a',
				'text' => $t,
				'href' => $href,
				'atomic' => true,
				'cls' => 'btn btn-gold',
				'html' => "<a class=\"btn btn-gold\" href=\"$href\">$t <i class=\"fa-solid fa-arrow-right\"></i></a>",
				's' => array('bg' => 'rgb(201,162,39)', 'color' => 'rgb(42,31,0)', 'pt' => 14, 'pb' => 14, 'pl' => 28, 'pr' => 28, 'h' => 48, 'w' => 180),
			);
		};

		$service = static function (string $icon, string $title, string $desc, string $price) use ($heading, $text, $btn): array {
			return array(
				'tag' => 'div',
				'cls' => 'card service-card',
				'layoutRole' => 'card',
				's' => array('bg' => 'rgb(255,255,255)', 'bdw' => '1px', 'br' => 18, 'sh' => '0 4px 14px rgba(13,59,102,0.06)', 'pt' => 34, 'pb' => 34, 'pl' => 34, 'pr' => 34, 'w' => 360),
				'children' => array(
					array(
						'tag' => 'div',
						'cls' => 'card-icon',
						'html' => '<div class="card-icon"><i class="fa-solid ' . $icon . '"></i></div>',
						'children' => array(
							array('tag' => 'i', 'cls' => 'fa-solid ' . $icon, 'atomic' => true, 'html' => '<i class="fa-solid ' . $icon . '"></i>', 's' => array('color' => 'rgb(255,255,255)')),
						),
						's' => array('bg' => 'rgb(13,59,102)', 'w' => 56, 'h' => 56),
					),
					$heading('h3', $title, array('fs' => '22px')),
					$text($desc),
					array('tag' => 'span', 'cls' => 'price', 'text' => $price, 'atomic' => true, 'html' => '<span class="price">' . $price . '</span>', 's' => array('fs' => '20px', 'fw' => '600')),
					$btn('Buchen'),
				),
			);
		};

		$faq_item = static function (string $q, string $a): array {
			return array(
				'tag' => 'div',
				'cls' => 'faq-item',
				's' => array(
					'bg' => 'rgb(13,20,36)',
					'bdw' => '1px',
					'bdc' => 'rgba(255,255,255,0.08)',
					'bds' => 'solid',
					'br' => 18,
					'sh' => '0 4px 14px rgba(0,0,0,0.35)',
				),
				'children' => array(
					array(
						'tag' => 'button',
						'cls' => 'faq-q',
						'text' => $q,
						'atomic' => true,
						'html' => '<button class="faq-q">' . $q . '<span class="plus">+</span></button>',
						's' => array('fw' => '600', 'color' => 'rgb(244,236,214)'),
						'children' => array(
							array(
								'tag' => 'span',
								'cls' => 'plus',
								'text' => '+',
								'atomic' => true,
								'html' => '<span class="plus">+</span>',
								's' => array('color' => 'rgb(244,236,214)', 'bg' => 'rgba(255,255,255,0.06)'),
							),
						),
					),
					array(
						'tag' => 'div',
						'cls' => 'faq-a',
						'html' => '<div class="faq-a"><p>' . $a . '</p></div>',
						'children' => array(
							array(
								'tag' => 'p',
								'text' => $a,
								'atomic' => true,
								'html' => '<p>' . $a . '</p>',
								's' => array('fs' => '15px', 'color' => 'rgb(163,176,199)'),
							),
						),
						's' => array(),
					),
				),
			);
		};

		$testimonial = static function (string $quote, string $name, string $place): array {
			return array(
				'tag' => 'div',
				'cls' => 'testimonial',
				'layoutRole' => 'testimonial',
				's' => array('bg' => 'rgb(255,255,255)', 'bdw' => '1px', 'br' => 18, 'pt' => 32, 'pb' => 32, 'pl' => 32, 'pr' => 32, 'w' => 360),
				'children' => array(
					array('tag' => 'div', 'cls' => 'stars', 'text' => '★★★★★', 'atomic' => true, 'html' => '<div class="stars">★★★★★</div>', 's' => array('color' => 'rgb(201,162,39)')),
					array('tag' => 'p', 'text' => $quote, 'atomic' => true, 'html' => '<p>' . $quote . '</p>', 's' => array('fs' => '16px')),
					array(
						'tag' => 'div',
						'cls' => 'who',
						'children' => array(
							array('tag' => 'div', 'cls' => 'avatar', 'text' => substr($name, 0, 2), 'atomic' => true, 'html' => '<div class="avatar">' . substr($name, 0, 2) . '</div>', 's' => array()),
							array('tag' => 'strong', 'text' => $name, 'atomic' => true, 'html' => '<strong>' . $name . '</strong>', 's' => array()),
							array('tag' => 'span', 'text' => $place, 'atomic' => true, 'html' => '<span>' . $place . '</span>', 's' => array('fs' => '13px')),
						),
						's' => array(),
					),
				),
			);
		};

		return array(
			'meta' => array('title' => 'Angebot – Petra Müller'),
			'assets' => array('combinedCss' => ':root{--primary:#0D3B66}'),
			'sections' => array(
				// Nav
				array(
					'tag' => 'header',
					'tree' => array(
						'tag' => 'header',
						'cls' => 'site-header',
						's' => array('disp' => 'flex', 'fd' => 'row', 'w' => 1200, 'h' => 78, 'ai' => 'center', 'jc' => 'space-between', 'bg' => 'rgb(255,255,255)'),
						'children' => array(
							array('tag' => 'a', 'cls' => 'logo', 'text' => 'Petra Müller', 'atomic' => true, 'href' => 'index.html', 'html' => '<a class="logo">Petra Müller</a>', 's' => array('fs' => '18px', 'fw' => '700')),
							array('tag' => 'a', 'text' => 'Home', 'atomic' => true, 'href' => 'index.html', 'html' => '<a>Home</a>', 's' => array('fs' => '15px')),
							array('tag' => 'a', 'text' => 'Angebot', 'atomic' => true, 'href' => 'angebot.html', 'html' => '<a>Angebot</a>', 's' => array('fs' => '15px')),
							$btn('Termin buchen'),
						),
					),
				),
				// Service grid
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						'cls' => 'section',
						's' => array('disp' => 'block', 'pt' => 96, 'pb' => 96),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'grid grid-3',
								'layoutType' => 'grid',
								's' => array('disp' => 'grid', 'gtc' => '1fr 1fr 1fr', 'gap' => '28px'),
								'children' => array(
									$service('fa-moon', 'Radix-Analyse', 'Deine persönliche Geburtshoroskop-Deutung.', 'CHF 220 · 90 Min.'),
									$service('fa-compass', 'Jahreshoroskop', 'Ein Blick auf das kommende Jahr.', 'CHF 190 · 75 Min.'),
									$service('fa-heart-pulse', 'Beziehungshoroskop', 'Synastrie für Paare.', 'CHF 280 · 120 Min.'),
								),
							),
						),
					),
				),
				// Testimonials
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						'cls' => 'section section-alt',
						's' => array('disp' => 'block', 'bg' => 'rgb(238,243,248)', 'pt' => 96, 'pb' => 96),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'grid grid-3',
								'layoutType' => 'grid',
								's' => array('disp' => 'grid'),
								'children' => array(
									$testimonial('„Fundierte Analyse, klare Sprache."', 'Anna B.', 'Zug'),
									$testimonial('„Petras ruhige Art hat mir sehr geholfen."', 'Michael K.', 'Baar'),
									$testimonial('„Jede Sitzung bringt neue Erkenntnisse."', 'Julia S.', 'Zürich'),
								),
							),
						),
					),
				),
				// FAQ
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						'cls' => 'section',
						's' => array('disp' => 'block', 'pt' => 96, 'pb' => 96),
						'children' => array(
							$heading('h2', 'Häufig gestellte Fragen'),
							array(
								'tag' => 'div',
								'cls' => 'faq',
								'layoutRole' => 'faq',
								'html' => '',
								'children' => array(
									$faq_item('Wie läuft eine astrologische Beratung ab?', 'Nach einem kurzen Vorgespräch analysiere ich dein Horoskop.'),
									$faq_item('Welche Daten brauchst du von mir?', 'Geburtsdatum, Uhrzeit und Geburtsort.'),
									$faq_item('Sind auch Online-Termine möglich?', 'Ja, vor Ort in Zug oder via Zoom.'),
									$faq_item('Wie viele Sitzungen brauche ich?', 'Oft reicht eine Sitzung; Coaching braucht 3–6.'),
								),
								's' => array(),
							),
						),
					),
				),
				// CTA
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						'cls' => 'section',
						's' => array('disp' => 'block'),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'cta-banner',
								'layoutRole' => 'cta_block',
								's' => array('bg' => 'rgb(13,59,102)', 'br' => 28, 'pt' => 64, 'pb' => 64, 'pl' => 64, 'pr' => 64),
								'children' => array(
									$heading('h2', 'Nicht sicher, welches Angebot passt?', array('color' => 'rgb(255,255,255)')),
									$text('Kontaktiere mich – wir finden gemeinsam das Passende für dich.'),
									$btn('Jetzt Kontakt aufnehmen', 'contact.html'),
								),
							),
						),
					),
				),
				// Contact form
				array(
					'tag' => 'section',
					'tree' => array(
						'tag' => 'section',
						'cls' => 'section',
						's' => array('disp' => 'block'),
						'children' => array(
							array(
								'tag' => 'form',
								'cls' => 'form',
								'layoutRole' => 'form_block',
								'html' => '<form class="form"><input type="text" placeholder="Name" required /><input type="email" placeholder="E-Mail" required /><textarea placeholder="Nachricht"></textarea><button type="submit">Nachricht senden</button></form>',
								'children' => array(
									array('tag' => 'input', 'inputType' => 'text', 'placeholder' => 'Name', 'required' => true, 'atomic' => true, 'html' => '<input type="text" placeholder="Name" required />', 's' => array('bdw' => '1px', 'h' => 48, 'w' => 400)),
									array('tag' => 'input', 'inputType' => 'email', 'placeholder' => 'E-Mail', 'required' => true, 'atomic' => true, 'html' => '<input type="email" placeholder="E-Mail" required />', 's' => array('bdw' => '1px', 'h' => 48, 'w' => 400)),
									array('tag' => 'textarea', 'placeholder' => 'Nachricht', 'atomic' => true, 'html' => '<textarea placeholder="Nachricht"></textarea>', 's' => array('bdw' => '1px', 'h' => 140, 'w' => 400)),
									array('tag' => 'button', 'text' => 'Nachricht senden', 'atomic' => true, 'html' => '<button>Nachricht senden</button>', 's' => array('bg' => 'rgb(201,162,39)', 'pt' => 14, 'pb' => 14, 'pl' => 28, 'pr' => 28, 'h' => 48, 'w' => 200)),
								),
								's' => array(),
							),
						),
					),
				),
				// Footer with socials
				array(
					'tag' => 'footer',
					'tree' => array(
						'tag' => 'footer',
						'cls' => 'site-footer',
						's' => array('disp' => 'flex', 'fd' => 'row', 'bg' => 'rgb(16,42,67)', 'pt' => 80, 'pb' => 30, 'w' => 1200, 'h' => 220),
						'children' => array(
							$text('Astrologische Beratung in Zug.'),
							array(
								'tag' => 'div',
								'cls' => 'socials',
								'layoutRole' => 'social_icons',
								'html' => '<div class="socials"><a href="#"><i class="fa-brands fa-instagram"></i></a><a href="#"><i class="fa-brands fa-facebook-f"></i></a><a href="#"><i class="fa-brands fa-linkedin-in"></i></a></div>',
								'children' => array(
									array('tag' => 'a', 'href' => '#', 'html' => '<a href="#"><i class="fa-brands fa-instagram"></i></a>', 'cls' => '', 'atomic' => true, 's' => array('w' => 40, 'h' => 40)),
									array('tag' => 'a', 'href' => '#', 'html' => '<a href="#"><i class="fa-brands fa-facebook-f"></i></a>', 'cls' => '', 'atomic' => true, 's' => array('w' => 40, 'h' => 40)),
									array('tag' => 'a', 'href' => '#', 'html' => '<a href="#"><i class="fa-brands fa-linkedin-in"></i></a>', 'cls' => '', 'atomic' => true, 's' => array('w' => 40, 'h' => 40)),
								),
								's' => array('disp' => 'flex', 'fd' => 'row'),
							),
						),
					),
				),
			),
		);
	}

	public function test_petra_native_coverage_at_least_95_percent(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->petra_layout()), array('mode' => 'native'));

		$native = (int) ($result['report']['native_widgets'] ?? 0);
		$html = (int) ($result['report']['html_widgets'] ?? 0);
		$total = max(1, $native + $html);
		$native_pct = ($native / $total) * 100;

		$this->assertGreaterThanOrEqual(95, $native_pct, 'Native widget coverage should be ≥95% for Petra layout');
		$this->assertSame(0, $html, 'Petra marketing patterns should not need HTML fallbacks');
	}

	public function test_petra_emits_accordion_for_faq(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->petra_layout()), array('mode' => 'native'));
		$types = $this->all_widget_types($result['data']);

		$this->assertContains('accordion', $types);
		$this->assertGreaterThanOrEqual(1, $result['report']['widget_breakdown']['accordion'] ?? 0);
	}

	public function test_petra_accordion_carries_faq_item_chrome_and_colours(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->petra_layout()), array('mode' => 'native'));
		$accordion = $this->find_widget($result['data'], 'accordion');
		$this->assertNotNull($accordion);
		$s = $accordion['settings'];

		$this->assertNotEmpty($s['background_color'] ?? $s['background_background'] ?? null);
		$this->assertArrayHasKey('border_radius', $s);
		$this->assertSame('yes', $s['box_shadow_box_shadow_type'] ?? null);
		$this->assertSame('rgb(244,236,214)', $s['title_color'] ?? null);
		$this->assertSame('rgb(163,176,199)', $s['content_color'] ?? null);
		$this->assertSame('rgb(244,236,214)', $s['icon_color'] ?? null);
		$this->assertSame('right', $s['icon_align'] ?? null);
		$this->assertSame('fas fa-plus', $s['selected_icon']['value'] ?? null);
	}

	public function test_petra_buttons_keep_trailing_fa_icons(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->petra_layout()), array('mode' => 'native'));
		$buttons = $this->find_all_widgets($result['data'], 'button');
		$this->assertNotEmpty($buttons);

		$with_icon = array_filter($buttons, static function (array $el): bool {
			return !empty($el['settings']['selected_icon']['value']);
		});
		$this->assertNotEmpty($with_icon, 'At least one button should carry a nested FA icon');
		$sample = array_values($with_icon)[0];
		$this->assertStringContainsString('fa-arrow-right', (string) $sample['settings']['selected_icon']['value']);
		$this->assertSame('right', $sample['settings']['icon_align'] ?? null);
	}

	public function test_petra_cta_carries_title_and_button_colours(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->petra_layout()), array('mode' => 'native'));
		$cta = $this->find_widget($result['data'], 'call-to-action');
		$this->assertNotNull($cta);
		$s = $cta['settings'];
		$this->assertSame('rgb(255,255,255)', $s['title_color'] ?? null);
		$this->assertNotEmpty($s['button_text_color'] ?? null);
	}

	public function test_petra_service_cards_emit_structured_native_widgets(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->petra_layout()), array('mode' => 'native'));
		$types = $this->all_widget_types($result['data']);

		$this->assertContains('heading', $types);
		$this->assertContains('button', $types);
		// Structured cards preserve editable heading/text/button children instead of
		// collapsing into a monolithic price-table that drops Chromium IR leaves.
		$this->assertGreaterThanOrEqual(3, $result['report']['widget_breakdown']['heading'] ?? 0);
	}

	public function test_petra_emits_testimonial_widgets(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->petra_layout()), array('mode' => 'native'));
		$types = $this->all_widget_types($result['data']);

		$this->assertContains('testimonial', $types);
		$this->assertGreaterThanOrEqual(3, $result['report']['widget_breakdown']['testimonial'] ?? 0);
	}

	public function test_petra_emits_form_and_cta_and_social(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->petra_layout()), array('mode' => 'native'));
		$types = $this->all_widget_types($result['data']);

		$this->assertContains('form', $types);
		$this->assertContains('call-to-action', $types);
		$this->assertContains('social-icons', $types);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<int,string>
	 */
	private function all_widget_types(array $elements): array
	{
		$types = array();
		foreach ($elements as $el) {
			if ('widget' === ($el['elType'] ?? '') && !empty($el['widgetType'])) {
				$types[] = (string) $el['widgetType'];
			}
			foreach ((array) ($el['elements'] ?? array()) as $child) {
				$types = array_merge($types, $this->all_widget_types(array($child)));
			}
		}
		return $types;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<string,mixed>|null
	 */
	private function find_widget(array $elements, string $type): ?array
	{
		$all = $this->find_all_widgets($elements, $type);
		return $all[0] ?? null;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<int,array<string,mixed>>
	 */
	private function find_all_widgets(array $elements, string $type): array
	{
		$found = array();
		foreach ($elements as $el) {
			if (!is_array($el)) {
				continue;
			}
			if ('widget' === ($el['elType'] ?? '') && $type === ($el['widgetType'] ?? '')) {
				$found[] = $el;
			}
			foreach ((array) ($el['elements'] ?? array()) as $child) {
				$found = array_merge($found, $this->find_all_widgets(array($child), $type));
			}
		}
		return $found;
	}
}
