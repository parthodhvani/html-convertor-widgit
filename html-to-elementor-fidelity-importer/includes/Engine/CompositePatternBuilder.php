<?php
/**
 * Builds native Elementor widgets from composite visual patterns.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Composite Pattern Builder — maps FAQ / form / testimonial / icon-box /
 * CTA / social / pricing structures to single native Elementor widgets
 * instead of nested HTML fallbacks or shallow text stacks.
 */
final class CompositePatternBuilder implements EngineInterface
{

	private AccordionRecognizer $accordion;

	public function __construct(?AccordionRecognizer $accordion = null)
	{
		$this->accordion = $accordion ?? new AccordionRecognizer();
	}

	public function name(): string
	{
		return 'composite_pattern_builder';
	}

	/**
	 * Detect a composite pattern and return Elementor widget settings.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	public function build(array $node): ?array
	{
		$role = strtolower((string) ($node['layoutRole'] ?? ''));
		$cls = strtolower((string) ($node['cls'] ?? '') . ' ' . (string) ($node['id'] ?? ''));
		$tag = strtolower((string) ($node['tag'] ?? ''));

		// Atomic forms still map via outerHTML field extraction.
		if (!empty($node['atomic'])) {
			if ('form' === $tag || 'form_block' === $role || preg_match('/\bform\b/', $cls)) {
				return $this->try_form($node, $role !== '' ? $role : 'form_block', $cls);
			}
			return null;
		}

		$accordion = $this->try_accordion($node, $role, $cls);
		if (null !== $accordion) {
			return $accordion;
		}

		$form = $this->try_form($node, $role, $cls);
		if (null !== $form) {
			return $form;
		}

		$testimonial = $this->try_testimonial($node, $role, $cls);
		if (null !== $testimonial) {
			return $testimonial;
		}

		$social = $this->try_social_icons($node, $role, $cls);
		if (null !== $social) {
			return $social;
		}

		$cta = $this->try_cta($node, $role, $cls);
		if (null !== $cta) {
			return $cta;
		}

		$price = $this->try_price_table($node, $role, $cls);
		if (null !== $price) {
			return $price;
		}

		$icon_box = $this->try_icon_box($node, $role, $cls);
		if (null !== $icon_box) {
			return $icon_box;
		}

		$stars = $this->try_star_rating($node, $role, $cls);
		if (null !== $stars) {
			return $stars;
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param string              $role Role.
	 * @param string              $cls  Classes.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	private function try_accordion(array $node, string $role, string $cls): ?array
	{
		if (!in_array($role, array('faq', 'accordion'), true)
			&& !preg_match('/\b(faq|accordion|disclosure)\b/', $cls)) {
			// Still allow AccordionRecognizer geometry/hint detection.
			$detected = $this->accordion->detect($node);
			if (null === $detected || count($detected['items']) < 2) {
				return null;
			}
		} else {
			$detected = $this->accordion->detect($node);
			if (null === $detected || count($detected['items']) < 2) {
				$detected = $this->accordion_from_html($node);
			}
		}

		if (null === $detected || count($detected['items']) < 2) {
			return null;
		}

		// Prefer native accordion even when items are painted cards — Elementor
		// accordion can carry shared chrome, and keeping DOM cards as flex-row
		// FAQ items destroys page geometry (~2× height).
		$tabs = array();
		foreach ($detected['items'] as $item) {
			$tabs[] = array(
				'tab_title' => (string) $item['title'],
				'tab_content' => (string) $item['content'],
			);
		}

		return array(
			'type' => 'accordion',
			'role' => 'faq',
			'settings' => array_merge(
				array('tabs' => $tabs),
				$this->accordion_spacing_settings($node),
				$this->accordion_paint_settings($node)
			),
		);
	}

	/**
	 * Map FAQ flex/CSS gap onto Elementor accordion item spacing.
	 *
	 * @param array<string,mixed> $node FAQ root.
	 * @return array<string,mixed>
	 */
	private function accordion_spacing_settings(array $node): array
	{
		$gap = (float) ($node['layoutConstraint']['gap'] ?? $node['whitespace']['gap'] ?? 0);
		if ($gap <= 0) {
			$raw = $node['s']['gap'] ?? null;
			if (is_numeric($raw)) {
				$gap = (float) $raw;
			} elseif (is_string($raw) && preg_match('/^(-?\d+(?:\.\d+)?)\s*px/i', trim($raw), $m)) {
				$gap = (float) $m[1];
			}
		}
		if ($gap <= 0) {
			return array();
		}
		return array(
			'space_between' => array(
				'unit' => 'px',
				'size' => round($gap),
			),
		);
	}

	/**
	 * @param array<string,mixed> $node FAQ root.
	 */
	private function accordion_items_are_painted(array $node): bool
	{
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$tag = strtolower((string) ($child['tag'] ?? ''));
			$cls = strtolower((string) ($child['cls'] ?? ''));
			$is_item = 'details' === $tag || (bool) preg_match('/\b(faq-item|accordion-item)\b/', $cls);
			if (!$is_item) {
				continue;
			}
			$signals = VisualSignals::analyze($child);
			if ($signals['has_background'] || $signals['has_border'] || $signals['has_shadow']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Carry painted FAQ item chrome + title/content/icon colours onto accordion.
	 *
	 * Elementor Accordion style controls (verified against elementor accordion.php):
	 * - title_color, content_color, icon_color, icon_align, selected_icon
	 * - border_color / border_width (widget-level); border_radius + box_shadow via
	 *   CssMapper Group_Control keys (preview + Advanced/custom CSS consumers)
	 *
	 * @param array<string,mixed> $node FAQ root.
	 * @return array<string,mixed>
	 */
	private function accordion_paint_settings(array $node): array
	{
		$mapper = new \HtmlToElementor\Elementor\CssMapper();
		$item = $this->first_faq_item($node);
		if (null === $item) {
			return array();
		}

		$out = array_merge(
			$mapper->background($item),
			$mapper->border($item),
			$mapper->box_shadow($item)
		);

		$title_node = $this->find_descendant_by_class($item, '/\b(faq-q|accordion-button|accordion-header)\b/')
			?? $this->find_descendant_by_tag($item, array('summary', 'button', 'h3', 'h4'));
		if (null !== $title_node) {
			$out = array_merge($out, $mapper->text_color($title_node, 'title_color'));
		}

		$content_node = $this->find_descendant_by_class($item, '/\b(faq-a|accordion-body|accordion-collapse)\b/');
		$content_p = null !== $content_node
			? ($this->find_descendant_by_tag($content_node, array('p')) ?? $content_node)
			: $this->find_descendant_by_tag($item, array('p'));
		if (null !== $content_p) {
			$out = array_merge($out, $mapper->text_color($content_p, 'content_color'));
		}

		$plus = $this->find_descendant_by_class($item, '/\bplus\b/');
		$plus_in_html = false;
		if (null === $plus && null !== $title_node) {
			$html = (string) ($title_node['html'] ?? '');
			$plus_in_html = (bool) preg_match('/class=["\'][^"\']*\bplus\b/', $html);
		}
		if (null !== $plus || $plus_in_html) {
			if (null !== $plus) {
				$out = array_merge($out, $mapper->text_color($plus, 'icon_color'));
			} elseif (!empty($out['title_color'])) {
				// Atomic faq-q leaves keep .plus only in outerHTML; colour matches title.
				$out['icon_color'] = $out['title_color'];
			}
			// Source FAQs place the "+" badge after the question text.
			$out['icon_align'] = 'right';
			if (empty($out['selected_icon'])) {
				$out['selected_icon'] = array(
					'value' => 'fas fa-plus',
					'library' => 'fa-solid',
				);
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node FAQ root.
	 * @return array<string,mixed>|null
	 */
	private function first_faq_item(array $node): ?array
	{
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$tag = strtolower((string) ($child['tag'] ?? ''));
			$cls = strtolower((string) ($child['cls'] ?? ''));
			if ('details' === $tag || (bool) preg_match('/\b(faq-item|accordion-item)\b/', $cls)) {
				return $child;
			}
			// Items may sit one wrapper deep (e.g. .faq > .container > .faq-item).
			$nested = $this->first_faq_item($child);
			if (null !== $nested) {
				return $nested;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $node Root.
	 * @param string              $pattern Class regex.
	 * @return array<string,mixed>|null
	 */
	private function find_descendant_by_class(array $node, string $pattern): ?array
	{
		$cls = strtolower((string) ($node['cls'] ?? ''));
		if ('' !== $cls && (bool) preg_match($pattern, $cls)) {
			return $node;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$found = $this->find_descendant_by_class($child, $pattern);
			if (null !== $found) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $node Root.
	 * @param array<int,string>   $tags Tag names.
	 * @return array<string,mixed>|null
	 */
	private function find_descendant_by_tag(array $node, array $tags): ?array
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		if (in_array($tag, $tags, true)) {
			return $node;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$found = $this->find_descendant_by_tag($child, $tags);
			if (null !== $found) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Fallback accordion extraction when collapsed panels left only outerHTML.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return array{items:array<int,array{title:string,content:string}>}|null
	 */
	private function accordion_from_html(array $node): ?array
	{
		$html = (string) ($node['html'] ?? '');
		if ('' === $html) {
			$html = $this->collect_descendant_html($node);
		}
		if ('' === $html) {
			return null;
		}

		$items = array();
		if (preg_match_all(
			'/<button[^>]*class=["\'][^"\']*faq-q[^"\']*["\'][^>]*>(.*?)<\/button>.*?<div[^>]*class=["\'][^"\']*faq-a[^"\']*["\'][^>]*>(.*?)<\/div>/is',
			$html,
			$matches,
			PREG_SET_ORDER
		)) {
			foreach ($matches as $m) {
				$title = trim(wp_strip_all_tags($m[1]));
				$content = trim($m[2]);
				if ('' !== $title && '' !== trim(wp_strip_all_tags($content))) {
					$items[] = array('title' => $title, 'content' => $content);
				}
			}
		}

		if (count($items) < 2 && preg_match_all(
			'/<details\b[^>]*>.*?<summary\b[^>]*>(.*?)<\/summary>(.*?)<\/details>/is',
			$html,
			$matches,
			PREG_SET_ORDER
		)) {
			foreach ($matches as $m) {
				$title = trim(wp_strip_all_tags($m[1]));
				$content = trim($m[2]);
				if ('' !== $title && '' !== trim(wp_strip_all_tags($content))) {
					$items[] = array('title' => $title, 'content' => $content);
				}
			}
		}

		return count($items) >= 2 ? array('items' => $items) : null;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param string              $role Role.
	 * @param string              $cls  Classes.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	private function try_form(array $node, string $role, string $cls): ?array
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		// Never collapse landmark sections into a Form widget.
		if (in_array($tag, array('header', 'footer', 'nav', 'section', 'main', 'aside'), true)) {
			return null;
		}
		if (preg_match('/\b(site-footer|site-header|footer-grid|footer-bottom)\b/', $cls)) {
			return null;
		}

		$is_form = 'form' === $tag || 'form_block' === $role
			|| (bool) preg_match('/\b(form|newsletter-form)\b/', $cls);
		if (!$is_form) {
			return null;
		}

		$fields = $this->extract_form_fields($node);
		if (count($fields) < 1) {
			$html = (string) ($node['html'] ?? '');
			$fields = $this->fields_from_html($html);
		}
		if (count($fields) < 1) {
			return null;
		}

		$button = $this->find_submit_label($node) ?: 'Senden';

		return array(
			'type' => 'form',
			'role' => 'form_block',
			'settings' => array(
				'form_name' => 'Form',
				'form_fields' => $fields,
				'submit_actions' => array('email'),
				'button_text' => $button,
			),
		);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_form_fields(array $node): array
	{
		$fields = array();
		$this->walk_fields($node, $fields);
		return $fields;
	}

	/**
	 * @param array<string,mixed>            $node   Node.
	 * @param array<int,array<string,mixed>> $fields Accumulator.
	 */
	private function walk_fields(array $node, array &$fields): void
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		if (in_array($tag, array('input', 'textarea', 'select'), true)) {
			$type = strtolower((string) ($node['inputType'] ?? $node['type'] ?? 'text'));
			if (in_array($type, array('submit', 'button', 'hidden', 'reset', 'image'), true)) {
				return;
			}
			$label = trim((string) ($node['ariaLabel'] ?? $node['placeholder'] ?? $node['name'] ?? $node['text'] ?? ''));
			if ('' === $label) {
				$label = 'Field ' . (count($fields) + 1);
			}
			$field_type = 'textarea' === $tag ? 'textarea' : ('select' === $tag ? 'select' : ('email' === $type ? 'email' : ('tel' === $type ? 'tel' : 'text')));
			$fields[] = array(
				'field_type' => $field_type,
				'field_label' => $label,
				'placeholder' => (string) ($node['placeholder'] ?? ''),
				'required' => !empty($node['required']) ? 'true' : '',
			);
			return;
		}

		// Geometry input-like leaves without tag metadata.
		if (!empty($node['atomic']) && VisualSignals::looks_input_like($node)) {
			$fields[] = array(
				'field_type' => 'text',
				'field_label' => trim((string) ($node['ariaLabel'] ?? $node['placeholder'] ?? 'Field')),
				'placeholder' => (string) ($node['placeholder'] ?? ''),
				'required' => '',
			);
		}

		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$this->walk_fields($child, $fields);
			}
		}
	}

	/**
	 * @param string $html HTML.
	 * @return array<int,array<string,mixed>>
	 */
	private function fields_from_html(string $html): array
	{
		$fields = array();
		if (preg_match_all('/<(input|textarea|select)\b([^>]*)>/i', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$tag = strtolower($m[1]);
				$attrs = $m[2];
				$type = 'text';
				if (preg_match('/\btype=["\']([^"\']+)/i', $attrs, $tm)) {
					$type = strtolower($tm[1]);
				}
				if (in_array($type, array('submit', 'button', 'hidden', 'reset', 'image'), true)) {
					continue;
				}
				$label = '';
				if (preg_match('/\bplaceholder=["\']([^"\']+)/i', $attrs, $pm)) {
					$label = $pm[1];
				} elseif (preg_match('/\bname=["\']([^"\']+)/i', $attrs, $nm)) {
					$label = $nm[1];
				} elseif (preg_match('/\baria-label=["\']([^"\']+)/i', $attrs, $am)) {
					$label = $am[1];
				}
				if ('' === $label) {
					$label = 'Field ' . (count($fields) + 1);
				}
				$field_type = 'textarea' === $tag ? 'textarea' : ('select' === $tag ? 'select' : ('email' === $type ? 'email' : ('tel' === $type ? 'tel' : 'text')));
				$fields[] = array(
					'field_type' => $field_type,
					'field_label' => $label,
					'placeholder' => $label,
					'required' => false !== stripos($attrs, 'required') ? 'true' : '',
				);
			}
		}
		return $fields;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function find_submit_label(array $node): string
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$type = strtolower((string) ($node['inputType'] ?? $node['type'] ?? ''));
		if (('button' === $tag || 'submit' === $type) && '' !== trim((string) ($node['text'] ?? ''))) {
			return trim((string) $node['text']);
		}
		if (VisualSignals::looks_button($node)) {
			return trim((string) ($node['text'] ?? ''));
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$label = $this->find_submit_label($child);
			if ('' !== $label) {
				return $label;
			}
		}
		$html = (string) ($node['html'] ?? '');
		if (preg_match('/<(button|input)[^>]*(type=["\']submit["\'])?[^>]*>([^<]*)/i', $html, $m)) {
			$text = trim(wp_strip_all_tags($m[3] ?? ''));
			if ('' !== $text) {
				return $text;
			}
		}
		if (preg_match('/<button[^>]*>(.*?)<\/button>/is', $html, $m)) {
			return trim(wp_strip_all_tags($m[1]));
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param string              $role Role.
	 * @param string              $cls  Classes.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	private function try_testimonial(array $node, string $role, string $cls): ?array
	{
		$hinted = 'testimonial' === $role || (bool) preg_match('/\b(testimonial|review|quote)\b/', $cls);
		if (!$hinted) {
			return null;
		}

		$content = $this->find_quote_text($node);
		$name = $this->find_name($node);
		$job = $this->find_job($node);

		if ('' === $content) {
			return null;
		}

		$settings = array(
			'testimonial_content' => $content,
			'testimonial_name' => $name ?: 'Client',
			'testimonial_job' => $job,
		);

		return array(
			'type' => 'testimonial',
			'role' => 'testimonial',
			'settings' => array_merge($settings, $this->testimonial_paint_settings($node)),
		);
	}

	/**
	 * Map quote / name / job colours onto Elementor Testimonial style controls.
	 *
	 * Controls (elementor testimonial.php): content_content_color, name_text_color,
	 * job_text_color — plus shared border/shadow/background via style_for_widget.
	 *
	 * @param array<string,mixed> $node Testimonial root.
	 * @return array<string,mixed>
	 */
	private function testimonial_paint_settings(array $node): array
	{
		$mapper = new \HtmlToElementor\Elementor\CssMapper();
		$out = array();

		$quote = $this->find_descendant_by_tag($node, array('p', 'blockquote'));
		if (null !== $quote) {
			$out = array_merge($out, $mapper->text_color($quote, 'content_content_color'));
		}

		$name_node = $this->find_descendant_by_tag($node, array('strong', 'b'));
		if (null !== $name_node) {
			$out = array_merge($out, $mapper->text_color($name_node, 'name_text_color'));
		}

		$job_node = null;
		$this->walk_text($node, function (array $n) use (&$job_node): void {
			if (null !== $job_node) {
				return;
			}
			$tag = strtolower((string) ($n['tag'] ?? ''));
			$text = trim((string) ($n['text'] ?? ''));
			if ('span' === $tag && '' !== $text && strlen($text) < 40 && !preg_match('/★|⭐/', $text)) {
				$job_node = $n;
			}
		});
		if (null !== $job_node) {
			$out = array_merge($out, $mapper->text_color($job_node, 'job_text_color'));
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function find_quote_text(array $node): string
	{
		$best = '';
		$this->walk_text($node, function (array $n) use (&$best): void {
			$text = trim((string) ($n['text'] ?? ''));
			if ('' === $text) {
				return;
			}
			$tag = strtolower((string) ($n['tag'] ?? ''));
			$cls = strtolower((string) ($n['cls'] ?? ''));
			$is_quote = 'blockquote' === $tag || false !== strpos($text, '„') || false !== strpos($text, '"')
				|| preg_match('/\b(quote|content)\b/', $cls);
			if ($is_quote || (strlen($text) > strlen($best) && strlen($text) > 40 && !preg_match('/★|⭐/', $text))) {
				if (strlen($text) > strlen($best)) {
					$best = $text;
				}
			}
		});
		return $best;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function find_name(array $node): string
	{
		$name = '';
		$this->walk_text($node, function (array $n) use (&$name): void {
			$tag = strtolower((string) ($n['tag'] ?? ''));
			if (in_array($tag, array('strong', 'b'), true) && '' !== trim((string) ($n['text'] ?? ''))) {
				$name = trim((string) $n['text']);
			}
		});
		return $name;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function find_job(array $node): string
	{
		$job = '';
		$this->walk_text($node, function (array $n) use (&$job): void {
			$tag = strtolower((string) ($n['tag'] ?? ''));
			$text = trim((string) ($n['text'] ?? ''));
			if ('span' === $tag && '' !== $text && strlen($text) < 40 && !preg_match('/★|⭐/', $text)) {
				$job = $text;
			}
		});
		return $job;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param string              $role Role.
	 * @param string              $cls  Classes.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	private function try_social_icons(array $node, string $role, string $cls): ?array
	{
		$hinted = 'social_icons' === $role || (bool) preg_match('/\b(socials?|social-icons|social-links)\b/', $cls);
		$icons = $this->collect_brand_icons($node);
		if (count($icons) < 2) {
			return null;
		}
		// Require an explicit social role/class, or a pure icon-only link row
		// (no headings/paragraphs). Prevents service-card grids from matching.
		if (!$hinted && !$this->is_icon_only_row($node)) {
			return null;
		}

		$list = array();
		foreach ($icons as $icon) {
			$list[] = array(
				'social_icon' => array(
					'value' => $icon['value'],
					'library' => $icon['library'],
				),
				'link' => array('url' => $icon['url'], 'is_external' => 'on', 'nofollow' => ''),
			);
		}

		$settings = array('social_icon_list' => $list);
		$gap = $this->css_gap_px($node);
		if ($gap > 0) {
			$settings['gap'] = array(
				'unit' => 'px',
				'size' => $gap,
			);
		}
		// Keep icons on one row in Elementor (editor uses .elementor-grid which
		// otherwise stacks when the parent column is narrow).
		$settings['shape'] = 'rounded';
		$settings['custom_css'] = 'selector .elementor-social-icons-wrapper, selector .elementor-grid {'
			. ' display:flex !important; flex-direction:row !important; flex-wrap:nowrap !important;'
			. ' grid-template-columns:none !important; gap:' . ($gap > 0 ? (int) round($gap) : 10) . 'px; }';

		return array(
			'type' => 'social-icons',
			'role' => 'social_icons',
			'settings' => $settings,
		);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function css_gap_px(array $node): float
	{
		$gap = (float) ($node['layoutConstraint']['gap'] ?? $node['whitespace']['gap'] ?? 0);
		if ($gap > 0) {
			return round($gap);
		}
		$raw = $node['s']['gap'] ?? null;
		if (is_numeric($raw)) {
			return (float) $raw;
		}
		if (is_string($raw) && preg_match('/^(-?\d+(?:\.\d+)?)\s*px/i', trim($raw), $m)) {
			return (float) $m[1];
		}
		return 0.0;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function is_icon_only_row(array $node): bool
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 2) {
			return false;
		}
		foreach ($children as $child) {
			if (!is_array($child)) {
				return false;
			}
			// Any nested heading / paragraph / priced content means this is not a social row.
			if ($this->has_substantial_copy($child)) {
				return false;
			}
			$text = trim((string) ($child['text'] ?? ''));
			if ('' !== $text) {
				return false;
			}
			$html = (string) ($child['html'] ?? '') . ' ' . (string) ($child['cls'] ?? '');
			if (!preg_match('/\b(fa-(?:solid|regular|brands)|fa[srlb]?)\s+fa-[\w-]+/i', $html)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function has_substantial_copy(array $node): bool
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote'), true)) {
			return '' !== trim((string) ($node['text'] ?? ''));
		}
		$cls = strtolower((string) ($node['cls'] ?? ''));
		if (preg_match('/\b(card|service|feature|testimonial|blog|price)\b/', $cls)) {
			return true;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child) && $this->has_substantial_copy($child)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @return array<int,array{value:string,library:string,url:string}>
	 */
	private function collect_brand_icons(array $node): array
	{
		$out = array();
		$cls = (string) ($node['cls'] ?? '');
		$html = (string) ($node['html'] ?? '');
		$combined = $cls . ' ' . $html;
		if (preg_match_all('/\b(fa-(?:solid|regular|brands)|fa[srlb]?)\s+(fa-[\w-]+)/i', $combined, $m, PREG_SET_ORDER)) {
			foreach ($m as $match) {
				$prefix = strtolower($match[1]);
				$name = strtolower($match[2]);
				$library = 'fa-brands';
				if ('fas' === $prefix || 'fa' === $prefix || 'fa-solid' === $prefix) {
					$library = 'fa-solid';
				} elseif ('far' === $prefix || 'fa-regular' === $prefix) {
					$library = 'fa-regular';
				} elseif ('fab' === $prefix || 'fa-brands' === $prefix) {
					$library = 'fa-brands';
				}
				$out[] = array(
					'value' => $prefix . ' ' . $name,
					'library' => $library,
					'url' => (string) ($node['href'] ?? '#'),
				);
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$out = array_merge($out, $this->collect_brand_icons($child));
			}
		}
		// Deduplicate by icon value.
		$seen = array();
		$unique = array();
		foreach ($out as $icon) {
			$key = $icon['value'];
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$unique[] = $icon;
		}
		return $unique;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param string              $role Role.
	 * @param string              $cls  Classes.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	private function try_cta(array $node, string $role, string $cls): ?array
	{
		// Only explicit CTA banners — never general content columns that end with a button.
		if (!preg_match('/\b(cta-banner|call-to-action)\b/', $cls) && 'cta' !== $role) {
			return null;
		}

		$title = '';
		$description = '';
		$button = '';
		$link = '';

		$this->walk_text($node, function (array $n) use (&$title, &$description, &$button, &$link): void {
			$tag = strtolower((string) ($n['tag'] ?? ''));
			$text = trim((string) ($n['text'] ?? ''));
			if ('' === $text) {
				return;
			}
			if (in_array($tag, array('h1', 'h2', 'h3'), true) && '' === $title) {
				$title = $text;
				return;
			}
			if (VisualSignals::looks_heading($n) && '' === $title) {
				$title = $text;
				return;
			}
			$cls_n = strtolower((string) ($n['cls'] ?? ''));
			if (VisualSignals::looks_button($n) || preg_match('/\b(btn|button)\b/', $cls_n) || 'button' === $tag) {
				if ('' === $button) {
					$button = $text;
					$link = (string) ($n['href'] ?? '');
				}
				return;
			}
			if ('a' === $tag && preg_match('/\b(btn|button|cta)\b/', $cls_n) && '' === $button) {
				$button = $text;
				$link = (string) ($n['href'] ?? '');
				return;
			}
			if (('p' === $tag || !VisualSignals::looks_heading($n)) && strlen($text) > 20 && '' === $description) {
				$description = $text;
			}
		});

		if ('' === $title || '' === $button) {
			return null;
		}

		$settings = array(
			'title' => $title,
			'description' => $description,
			'button' => $button,
			'link' => array('url' => $link, 'is_external' => '', 'nofollow' => ''),
		);

		return array(
			'type' => 'call-to-action',
			'role' => 'cta',
			'settings' => array_merge($settings, $this->cta_paint_settings($node)),
		);
	}

	/**
	 * Map CTA title/description/button colours onto Elementor Call to Action controls.
	 *
	 * Controls: title_color, description_color, button_text_color,
	 * button_background_color (Pro CTA). Border/shadow/bg still come from
	 * style_for_widget / map_painted_composite on the root.
	 *
	 * @param array<string,mixed> $node CTA root.
	 * @return array<string,mixed>
	 */
	private function cta_paint_settings(array $node): array
	{
		$mapper = new \HtmlToElementor\Elementor\CssMapper();
		$out = array();

		$title_node = $this->find_descendant_by_tag($node, array('h1', 'h2', 'h3'));
		if (null === $title_node) {
			$this->walk_text($node, function (array $n) use (&$title_node): void {
				if (null === $title_node && VisualSignals::looks_heading($n)) {
					$title_node = $n;
				}
			});
		}
		if (null !== $title_node) {
			$out = array_merge($out, $mapper->text_color($title_node, 'title_color'));
		}

		$desc_node = $this->find_descendant_by_tag($node, array('p'));
		if (null !== $desc_node) {
			$out = array_merge($out, $mapper->text_color($desc_node, 'description_color'));
		}

		$btn_node = null;
		$this->walk_text($node, function (array $n) use (&$btn_node): void {
			if (null !== $btn_node) {
				return;
			}
			$cls = strtolower((string) ($n['cls'] ?? ''));
			$tag = strtolower((string) ($n['tag'] ?? ''));
			if (VisualSignals::looks_button($n) || preg_match('/\b(btn|button)\b/', $cls) || 'button' === $tag) {
				$btn_node = $n;
			}
		});
		if (null !== $btn_node) {
			$out = array_merge($out, $mapper->text_color($btn_node, 'button_text_color'));
			$bg = (string) ($btn_node['s']['bg'] ?? '');
			if ('' !== $bg && false === stripos($bg, 'gradient') && 'transparent' !== strtolower($bg)) {
				$out['button_background_color'] = $bg;
			} else {
				$grad = $mapper->parse_gradient((string) ($btn_node['s']['bgImg'] ?? $btn_node['s']['bg'] ?? ''));
				if (null !== $grad) {
					$out['button_background_color'] = $grad['color_a'];
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param string              $role Role.
	 * @param string              $cls  Classes.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	private function try_price_table(array $node, string $role, string $cls): ?array
	{
		// Only explicit pricing blocks — marketing service cards keep a structured
		// container + heading/text/button tree so Chromium IR children survive.
		$hinted = in_array($role, array('pricing', 'price_table'), true)
			|| (bool) preg_match('/\b(pricing|price-table|price-card)\b/', $cls);
		if (!$hinted) {
			return null;
		}
		$price = $this->extract_price($node);
		if (null === $price) {
			return null;
		}

		$title = $this->first_heading($node);
		$desc = $this->first_paragraph($node);
		$button = $this->first_button($node);
		$features = $this->extract_feature_list($node);

		if ('' === $title) {
			return null;
		}

		// Feature-rich pricing cards emit as editable widget stacks (heading +
		// icon-list + button) instead of a single price-table that drops
		// geometry frames. Keep price-table for compact price/CTA cards.
		if (count($features) >= 2) {
			return null;
		}

		return array(
			'type' => 'price-table',
			'role' => 'pricing',
			'settings' => array(
				'heading' => $title,
				'sub_heading' => $desc,
				'price' => $price['amount'],
				'currency_symbol' => $price['currency'],
				'period' => $price['period'] !== '' ? $price['period'] : 'mo',
				'button_text' => $button['text'] ?: 'Buchen',
				'link' => array('url' => $button['url'], 'is_external' => '', 'nofollow' => ''),
				'features_list' => $features,
			),
		);
	}

	/**
	 * Collect list / checklist feature lines for price-table widgets.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_feature_list(array $node): array
	{
		$features = array();
		$this->collect_list_items($node, $features);
		return $features;
	}

	/**
	 * @param array<string,mixed>              $node     Node.
	 * @param array<int,array<string,mixed>>   $features Collector.
	 */
	private function collect_list_items(array $node, array &$features): void
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		if ('li' === $tag) {
			$text = trim((string) ($node['text'] ?? ''));
			if ('' === $text) {
				foreach ((array) ($node['children'] ?? array()) as $child) {
					if (is_array($child)) {
						$text = trim($text . ' ' . (string) ($child['text'] ?? ''));
					}
				}
				$text = trim($text);
			}
			if ('' !== $text) {
				$features[] = array(
					'_id' => substr(md5('feat:' . $text . ':' . count($features)), 0, 7),
					'item_text' => $text,
					'item_icon' => array(
						'value' => 'fas fa-check',
						'library' => 'fa-solid',
					),
				);
			}
			return;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$this->collect_list_items($child, $features);
			}
		}
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param string              $role Role.
	 * @param string              $cls  Classes.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	private function try_icon_box(array $node, string $role, string $cls): ?array
	{
		// Explicit icon-box / feature widgets only — not broad service-card trees.
		$hinted = in_array($role, array('icon_box', 'feature'), true)
			|| (bool) preg_match('/\b(icon-box|feature-box|feature-card)\b/', $cls);
		if (!$hinted) {
			return null;
		}

		// Prefer price-table when a price is present.
		if (null !== $this->extract_price($node)) {
			return null;
		}

		$icon = $this->first_icon($node);
		$title = $this->first_heading($node);
		$desc = $this->first_paragraph($node);
		$button = $this->first_button($node);

		if ('' === $title) {
			return null;
		}

		$settings = array(
			'title_text' => $title,
			'description_text' => $desc,
			'link' => array('url' => $button['url'], 'is_external' => '', 'nofollow' => ''),
		);
		if (null !== $icon) {
			$settings['selected_icon'] = $icon;
		}

		return array(
			'type' => 'icon-box',
			'role' => 'feature',
			'settings' => array_merge($settings, $this->icon_box_paint_settings($node)),
		);
	}

	/**
	 * Map icon-box title/description colours onto Elementor Icon Box controls.
	 *
	 * @param array<string,mixed> $node Icon-box root.
	 * @return array<string,mixed>
	 */
	private function icon_box_paint_settings(array $node): array
	{
		$mapper = new \HtmlToElementor\Elementor\CssMapper();
		$out = array();

		$title_node = $this->find_descendant_by_tag($node, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'));
		if (null !== $title_node) {
			$out = array_merge($out, $mapper->text_color($title_node, 'title_color'));
		}

		$desc_node = $this->find_descendant_by_tag($node, array('p'));
		if (null !== $desc_node) {
			$out = array_merge($out, $mapper->text_color($desc_node, 'description_color'));
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param string              $role Role.
	 * @param string              $cls  Classes.
	 * @return array{type:string,settings:array<string,mixed>,role:string}|null
	 */
	private function try_star_rating(array $node, string $role, string $cls): ?array
	{
		// Never collapse FAQ / form / card trees into a star widget because
		// body copy mentioned "rating" or included a ★ glyph.
		if (in_array($role, array('faq', 'accordion', 'form_block', 'testimonial', 'card', 'cta_block', 'footer_band', 'social_icons'), true)) {
			return null;
		}
		if (preg_match('/\b(faq|accordion|disclosure|testimonial|form)\b/', $cls)) {
			return null;
		}
		if (count((array) ($node['children'] ?? array())) >= 2) {
			return null;
		}

		$text = trim((string) ($node['text'] ?? ''));
		$html = (string) ($node['html'] ?? '');
		$combined = $text . ' ' . $html . ' ' . $cls;
		if (!preg_match('/[★⭐]|stars?|rating/i', $combined) && 'star_rating' !== $role) {
			return null;
		}
		$rating = 5.0;
		if (preg_match('/([0-5](?:\.\d)?)\s*[★⭐]/', $combined, $m)) {
			$rating = (float) $m[1];
		} elseif (preg_match('/(★{1,5}|⭐{1,5})/u', $combined, $m)) {
			$rating = (float) mb_strlen($m[1]);
		} elseif (!preg_match('/\bstars?\b/i', $cls) && 'star_rating' !== $role) {
			return null;
		}

		return array(
			'type' => 'star-rating',
			'role' => 'star_rating',
			'settings' => array(
				'rating_scale' => 5,
				'rating' => $rating,
			),
		);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @return array{amount:string,currency:string,period:string}|null
	 */
	private function extract_price(array $node): ?array
	{
		$found = null;
		$this->walk_text($node, function (array $n) use (&$found): void {
			if (null !== $found) {
				return;
			}
			$text = trim((string) ($n['text'] ?? ''));
			$cls = strtolower((string) ($n['cls'] ?? ''));
			if ('' === $text) {
				return;
			}
			if (!preg_match('/\bprice\b/', $cls) && !preg_match('/(CHF|EUR|USD|\$|€|£)\s*[\d\'.,]+/i', $text)) {
				return;
			}
			$currency = 'CHF';
			$amount = '';
			$period = '';
			if (preg_match('/(CHF|EUR|USD|\$|€|£)\s*([\d\'.,]+)/i', $text, $m)) {
				$currency = strtoupper($m[1]);
				if ('$' === $currency) {
					$currency = 'USD';
				} elseif ('€' === $currency) {
					$currency = 'EUR';
				} elseif ('£' === $currency) {
					$currency = 'GBP';
				}
				$amount = str_replace("'", '', $m[2]);
			}
			if (preg_match('/[·•\-–]\s*(.+)$/u', $text, $pm)) {
				$period = trim($pm[1]);
			} elseif (preg_match('/\/\s*(Session|Mo|Monat|month|hr|Min\.?)/i', $text, $pm)) {
				$period = trim($pm[0], '/ ');
			}
			if ('' !== $amount) {
				$found = array('amount' => $amount, 'currency' => $currency, 'period' => $period);
			}
		});
		return $found;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function first_heading(array $node): string
	{
		$found = '';
		$this->walk_text($node, function (array $n) use (&$found): void {
			if ('' !== $found) {
				return;
			}
			$tag = strtolower((string) ($n['tag'] ?? ''));
			$text = trim((string) ($n['text'] ?? ''));
			if ('' === $text) {
				return;
			}
			if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true) || VisualSignals::looks_heading($n)) {
				$found = $text;
			}
		});
		return $found;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function first_paragraph(array $node): string
	{
		$found = '';
		$this->walk_text($node, function (array $n) use (&$found): void {
			if ('' !== $found) {
				return;
			}
			$tag = strtolower((string) ($n['tag'] ?? ''));
			$text = trim((string) ($n['text'] ?? ''));
			if (('p' === $tag || (!VisualSignals::looks_heading($n) && !VisualSignals::looks_button($n)))
				&& strlen($text) > 20
				&& !preg_match('/(CHF|EUR|\$|€)\s*[\d\'.,]+/i', $text)) {
				$found = $text;
			}
		});
		return $found;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @return array{text:string,url:string}
	 */
	private function first_button(array $node): array
	{
		$found = array('text' => '', 'url' => '');
		$this->walk_text($node, function (array $n) use (&$found): void {
			if ('' !== $found['text']) {
				return;
			}
			if (VisualSignals::looks_button($n) || 'a' === strtolower((string) ($n['tag'] ?? ''))) {
				$text = trim((string) ($n['text'] ?? ''));
				if ('' !== $text) {
					$found = array('text' => $text, 'url' => (string) ($n['href'] ?? ''));
				}
			}
		});
		return $found;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @return array{value:string,library:string}|null
	 */
	private function first_icon(array $node): ?array
	{
		$cls = (string) ($node['cls'] ?? '');
		$html = (string) ($node['html'] ?? '');
		if (preg_match('/\b(fa-(?:solid|regular|brands)|fa[srlb]?)\s+(fa-[\w-]+)/i', $cls . ' ' . $html, $m)) {
			$prefix = strtolower($m[1]);
			$library = 'fa-solid';
			if ('fab' === $prefix || 'fa-brands' === $prefix) {
				$library = 'fa-brands';
			} elseif ('far' === $prefix || 'fa-regular' === $prefix) {
				$library = 'fa-regular';
			}
			return array('value' => $prefix . ' ' . strtolower($m[2]), 'library' => $library);
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$icon = $this->first_icon($child);
			if (null !== $icon) {
				return $icon;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $node     Node.
	 * @param callable            $callback fn(array $n): void.
	 */
	private function walk_text(array $node, callable $callback): void
	{
		$callback($node);
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$this->walk_text($child, $callback);
			}
		}
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function collect_descendant_html(array $node): string
	{
		$html = (string) ($node['html'] ?? '');
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$html .= $this->collect_descendant_html($child);
			}
		}
		return $html;
	}
}
