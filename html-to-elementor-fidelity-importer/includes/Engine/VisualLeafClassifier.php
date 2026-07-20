<?php
/**
 * Classifies leaf nodes from visual appearance — not HTML tags.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Visual Leaf Classifier — maps atomic nodes to Elementor widget types using
 * geometry, typography and computed styles.
 */
final class VisualLeafClassifier
{

	/**
	 * @param array<string,mixed> $node Atomic tree node.
	 * @return array{kind:string,type?:string,settings?:array<string,mixed>,confidence:int}|null
	 */
	public function classify(array $node): ?array
	{
		if (empty($node['atomic']) && !empty($node['children'])) {
			return null;
		}

		$signals = VisualSignals::analyze($node);
		$src = (string) ($node['src'] ?? '');
		$text = trim((string) ($node['text'] ?? ''));
		$html = (string) ($node['html'] ?? '');
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$bg_url = $this->extract_bg_url((string) ($node['s']['bgImg'] ?? ''));
		$embed_hay = $src . ' ' . $html;

		// Embedded media by URL before treating src as a raster image.
		if ($this->is_youtube($embed_hay)) {
			return $this->result('video', array(
				'video_type' => 'youtube',
				'youtube_url' => $this->extract_src($html) ?: $src,
			), 88);
		}
		if ($this->is_vimeo($embed_hay)) {
			return $this->result('video', array(
				'video_type' => 'vimeo',
				'vimeo_url' => $this->extract_src($html) ?: $src,
			), 88);
		}
		if ($this->is_maps($embed_hay)) {
			$map_src = $this->extract_src($html) ?: $src;
			return $this->result('google_maps', array(
				'address' => $this->maps_address($map_src, $html),
				'custom_height' => array('unit' => 'px', 'size' => max(200, (int) round((float) ($node['s']['h'] ?? 360)))),
			), 85);
		}

		// Real image sources only — CSS gradients must not become Image widgets.
		// iframe/video/audio src values are not images.
		if (('img' === $tag || 'picture' === $tag || '' !== $bg_url) && ('' !== $src || '' !== $bg_url)) {
			return $this->result('image', array(
				'image' => array('url' => $src ?: $bg_url, 'id' => ''),
				'image_size' => 'full',
				'alt' => (string) ($node['alt'] ?? ''),
			), 90);
		}
		if ('' !== $src && !in_array($tag, array('iframe', 'video', 'audio', 'source', 'embed', 'object'), true)
			&& preg_match('/\.(png|jpe?g|gif|webp|svg|avif)(\?|$)/i', $src)) {
			return $this->result('image', array(
				'image' => array('url' => $src, 'id' => ''),
				'image_size' => 'full',
				'alt' => (string) ($node['alt'] ?? ''),
			), 90);
		}

		// Navigation / icon lists captured as atomic <ul>/<ol> with items[].
		if (in_array($tag, array('ul', 'ol'), true) && !empty($node['items']) && is_array($node['items'])) {
			return $this->icon_list_from_items($node);
		}

		// Logo / rich anchors: reconstruct text (+ optional mark) from outerHTML.
		if ('a' === $tag && '' === $text && '' !== $html) {
			$from_html = trim(wp_strip_all_tags($html));
			if ('' !== $from_html) {
				$text = $from_html;
				$node['text'] = $from_html;
			}
		}

		// Buttons / CTAs — visual chrome or explicit button semantics only.
		if (VisualSignals::looks_button($node) || $this->looks_link_button($node, $text)) {
			$settings = array(
				'text' => $text ?: 'Button',
				'link' => array('url' => (string) ($node['href'] ?? ''), 'is_external' => '', 'nofollow' => ''),
			);
			// Nested FA icons live in outerHTML; innerText never includes icon-font glyphs.
			$settings = array_merge($settings, $this->nested_button_icon($node));
			return $this->result('button', $settings, 88);
		}

		// Plain text links stay editable text (not buttons).
		if ('a' === $tag && '' !== $text && '' !== (string) ($node['href'] ?? '')) {
			$href = esc_url((string) $node['href']);
			$label = esc_html($text);
			return $this->result('text-editor', array(
				'editor' => '<p><a href="' . $href . '">' . $label . '</a></p>',
			), 87);
		}

		// Font Awesome / icon fonts — native Icon widget.
		$icon = $this->maybe_icon($node);
		if (null !== $icon) {
			return $icon;
		}

		// Typography-driven text widgets.
		if (VisualSignals::looks_heading($node)) {
			$level = $this->heading_level($signals['font_size_px']);
			return $this->result('heading', array(
				'title' => $text,
				'header_size' => $level,
			), 92);
		}

		if ('' !== $text) {
			return $this->result('text-editor', array('editor' => '<p>' . esc_html($text) . '</p>'), 85);
		}

		// Inline SVG → native Image widget (data URI), keep link when wrapped in <a>.
		if ('svg' === $tag || false !== stripos($html, '<svg')) {
			$svg = $this->extract_svg_markup($html !== '' ? $html : (string) ($node['outerHTML'] ?? ''));
			if ('' === $svg && 'svg' === $tag) {
				$svg = $html;
			}
			if ('' !== $svg) {
				$settings = array(
					'image' => array('url' => $this->svg_data_uri($svg), 'id' => ''),
					'image_size' => 'full',
					'alt' => (string) ($node['alt'] ?? ''),
				);
				$href = (string) ($node['href'] ?? '');
				if ('' === $href && 'a' === $tag) {
					$href = $this->first_href($html);
				}
				if ('' !== $href) {
					$settings['link_to'] = 'custom';
					$settings['link'] = array('url' => $href);
				}
				return $this->result('image', $settings, 90);
			}
		}

		// Lone form controls → native Form widget (avoid HTML fallback).
		if (VisualSignals::looks_input_like($node) || in_array($tag, array('input', 'textarea', 'select'), true)) {
			$type = strtolower((string) ($node['inputType'] ?? $node['type'] ?? 'text'));
			if (in_array($type, array('submit', 'button', 'image', 'reset'), true)) {
				return $this->result(
					'button',
					array(
						'text' => $text !== '' ? $text : 'Submit',
						'link' => array('url' => ''),
					),
					92
				);
			}
			$field_type = in_array($type, array('email', 'tel', 'url', 'number', 'search', 'password'), true) ? $type : 'text';
			if ('textarea' === $tag) {
				$field_type = 'textarea';
			}
			if ('select' === $tag) {
				$field_type = 'select';
			}
			return $this->result(
				'form',
				array(
					'form_name' => 'Search',
					'form_fields' => array(
						array(
							'field_type' => $field_type,
							'field_label' => (string) ($node['placeholder'] ?? $text),
							'placeholder' => (string) ($node['placeholder'] ?? ''),
							'required' => !empty($node['required']) ? 'true' : '',
						),
					),
					'submit_button_text' => 'Suchen',
				),
				96
			);
		}

		if ($signals['input_like_children'] > 0) {
			return array('kind' => 'fallback', 'confidence' => 45);
		}

		return null;
	}

	/**
	 * Link that should render as a Button — requires button chrome, not mere height.
	 *
	 * @param array<string,mixed> $node Node.
	 * @param string              $text Resolved text.
	 */
	private function looks_link_button(array $node, string $text): bool
	{
		if ('' === $text) {
			return false;
		}
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$cls = strtolower((string) ($node['cls'] ?? ''));
		if ('button' === $tag) {
			return true;
		}
		if ('a' !== $tag || (string) ($node['href'] ?? '') === '') {
			return false;
		}
		// Block card anchors are containers, not buttons.
		if (preg_match('/\b(blog-card|card|post|article)\b/', $cls) && !preg_match('/\b(btn|button|card-link)\b/', $cls)) {
			return false;
		}
		// Explicit CTA / button classes.
		if (preg_match('/\b(btn|button|cta|card-link)\b/', $cls)) {
			return true;
		}
		// Visual button chrome: filled background + padding + compact control height.
		$s = $node['s'] ?? array();
		$box = Geometry::bbox($node);
		$h = $box['height'] ?: (float) ($s['h'] ?? 0);
		$w = $box['width'] ?: (float) ($s['w'] ?? 0);
		$has_chrome = VisualSignals::has_background($s) && VisualSignals::padding_sum($s) >= 6;
		return $has_chrome && $h >= 28 && $h <= 72 && $w >= 60 && $w <= 480;
	}

	/**
	 * Best-effort address / query extraction from a Google Maps embed URL.
	 *
	 * @param string $src  iframe src.
	 * @param string $html Outer HTML fallback.
	 */
	private function maps_address(string $src, string $html = ''): string
	{
		$hay = $src !== '' ? $src : $html;
		if (preg_match('/[?&]q=([^&]+)/i', $hay, $m)) {
			return rawurldecode(str_replace('+', ' ', $m[1]));
		}
		if (preg_match('/[?&]query=([^&]+)/i', $hay, $m)) {
			return rawurldecode(str_replace('+', ' ', $m[1]));
		}
		if (preg_match('/!2s([^!]+)/', $hay, $m)) {
			return rawurldecode(str_replace('+', ' ', $m[1]));
		}
		if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $hay, $m)) {
			return $m[1] . ', ' . $m[2];
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $node Node with items[].
	 * @return array{kind:string,type:string,settings:array<string,mixed>,confidence:int}
	 */
	private function icon_list_from_items(array $node): array
	{
		$items = array();
		foreach ((array) ($node['items'] ?? array()) as $label) {
			$label = trim((string) $label);
			if ('' === $label) {
				continue;
			}
			$items[] = array(
				'text' => $label,
				'selected_icon' => array('value' => '', 'library' => ''),
			);
		}
		return $this->result('icon-list', array(
			'icon_list' => $items,
			'space_between' => array('unit' => 'px', 'size' => 8),
		), 86);
	}

	/**
	 * Extract a nested Font Awesome <i> from a button/link's outerHTML and map
	 * it onto Elementor Button selected_icon + icon_align.
	 *
	 * Elementor Button (button-trait.php): selected_icon (ICONS), icon_align
	 * (left/right via selectors_dictionary → row / row-reverse).
	 *
	 * @param array<string,mixed> $node Button node.
	 * @return array<string,mixed>
	 */
	private function nested_button_icon(array $node): array
	{
		$html = (string) ($node['html'] ?? '');
		if ('' === $html) {
			$html = (string) ($node['outerHTML'] ?? '');
		}
		if ('' === $html || !preg_match('/<i\b[^>]*\bclass\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
			return array();
		}

		$icon_cls = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
		if ('' === $icon_cls || !preg_match('/\bfa-[\w-]+/', $icon_cls)) {
			return array();
		}

		$library = 'fa-solid';
		$value = $icon_cls;
		if (preg_match('/\b(fa-(?:solid|regular|brands)|fa[srlb]?)\s+(fa-[\w-]+)/i', $icon_cls, $im)) {
			$prefix = strtolower($im[1]);
			$name = strtolower($im[2]);
			$value = $prefix . ' ' . $name;
			if ('fab' === $prefix || 'fa-brands' === $prefix) {
				$library = 'fa-brands';
			} elseif ('far' === $prefix || 'fa-regular' === $prefix) {
				$library = 'fa-regular';
			} else {
				$library = 'fa-solid';
				// Normalise fa4-style "fas fa-arrow-right" etc.
				if (in_array($prefix, array('fas', 'far', 'fab', 'fal'), true)) {
					$value = $prefix . ' ' . $name;
				} elseif (str_starts_with($prefix, 'fa-')) {
					$value = $prefix . ' ' . $name;
				}
			}
		} elseif (preg_match('/\b(fa-[\w-]+)\b/', $icon_cls, $im)) {
			$value = 'fas ' . strtolower($im[1]);
		}

		// Icon before visible text → leading (left); otherwise trailing (right).
		$icon_pos = (int) strpos($html, $m[0]);
		$text_before = trim(wp_strip_all_tags(substr($html, 0, $icon_pos)));
		$align = '' === $text_before ? 'left' : 'right';

		return array(
			'selected_icon' => array(
				'value' => $value,
				'library' => $library,
			),
			'icon_align' => $align,
		);
	}

	/**
	 * Map Font Awesome / icon-font leaves to the Icon widget.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return array{kind:string,type:string,settings:array<string,mixed>,confidence:int}|null
	 */
	private function maybe_icon(array $node): ?array
	{
		$cls = (string) ($node['cls'] ?? '');
		$html = (string) ($node['html'] ?? '');
		$combined = $cls . ' ' . $html;
		if (!preg_match('/\b(fa-(?:solid|regular|brands)|fa[srlb]?)\s+(fa-[\w-]+)/i', $combined, $m)
			&& !preg_match('/\bfa-[\w-]+/', $cls)) {
			$tag = strtolower((string) ($node['tag'] ?? ''));
			if (!in_array($tag, array('i', 'span'), true) || !preg_match('/\bfa(?:s|r|b|l)?\b|\bfa-[\w-]+/', $cls)) {
				return null;
			}
		}

		$value = trim(preg_replace('/\s+/', ' ', $cls));
		if ('' === $value && preg_match('/\b(fa-(?:solid|regular|brands)|fa[srlb]?)\s+(fa-[\w-]+)/i', $combined, $m)) {
			$value = strtolower($m[1] . ' ' . $m[2]);
		}
		if ('' === $value) {
			return null;
		}

		$library = 'fa-solid';
		if (preg_match('/\bfab\b|\bfa-brands\b/', $value)) {
			$library = 'fa-brands';
		} elseif (preg_match('/\bfar\b|\bfa-regular\b/', $value)) {
			$library = 'fa-regular';
		}

		return $this->result('icon', array(
			'selected_icon' => array('value' => $value, 'library' => $library),
		), 90);
	}

	/**
	 * @param string              $type     Widget type.
	 * @param array<string,mixed> $settings Settings.
	 * @param int                 $confidence Confidence.
	 * @return array{kind:string,type:string,settings:array<string,mixed>,confidence:int}
	 */
	private function result(string $type, array $settings, int $confidence): array
	{
		return array(
			'kind' => 'widget',
			'type' => $type,
			'settings' => $settings,
			'confidence' => $confidence,
		);
	}

	/**
	 * @param float $fs Font size px.
	 */
	private function heading_level(float $fs): string
	{
		if ($fs >= 40) {
			return 'h1';
		}
		if ($fs >= 32) {
			return 'h2';
		}
		if ($fs >= 26) {
			return 'h3';
		}
		if ($fs >= 22) {
			return 'h4';
		}
		return 'h5';
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function inner_html(array $node): string
	{
		$html = (string) ($node['html'] ?? '');
		if ('' !== $html && preg_match('/^<[^>]+>(.*)<\/[^>]+>\s*$/s', trim($html), $m)) {
			return trim($m[1]);
		}
		return trim((string) ($node['text'] ?? ''));
	}

	/**
	 * @param string $bgImg CSS background-image.
	 */
	private function extract_bg_url(string $bgImg): string
	{
		// Ignore CSS gradients — only real url(...) backgrounds are images.
		if (preg_match('/gradient\s*\(/i', $bgImg)) {
			return '';
		}
		if (preg_match('/url\(["\']?([^"\')]+)["\']?\)/', $bgImg, $m)) {
			$url = trim($m[1]);
			if ('' === $url || 0 === stripos($url, 'data:image/svg')) {
				// Allow data-uri images; reject empty.
			}
			if ('' !== $url) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * @param string $html HTML.
	 */
	private function extract_src(string $html): string
	{
		if (preg_match('/src=["\']([^"\']+)/i', $html, $m)) {
			return $m[1];
		}
		return '';
	}

	private function is_youtube(string $html): bool
	{
		return (bool) preg_match('#(youtube\.com|youtu\.be)#i', $html);
	}

	private function is_vimeo(string $html): bool
	{
		return (bool) preg_match('#vimeo\.com#i', $html);
	}

	private function is_maps(string $html): bool
	{
		return (bool) preg_match('#(google\.[a-z.]+/maps|maps\.google)#i', $html);
	}

	/**
	 * @param string $html HTML possibly containing an SVG.
	 */
	private function extract_svg_markup(string $html): string
	{
		if (preg_match('/<svg\b.*?<\/svg>/is', $html, $m)) {
			return $m[0];
		}
		return '';
	}

	/**
	 * @param string $svg SVG markup.
	 */
	private function svg_data_uri(string $svg): string
	{
		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	/**
	 * @param string $html Anchor HTML.
	 */
	private function first_href(string $html): string
	{
		if (preg_match('/href=["\']([^"\']*)["\']/i', $html, $m)) {
			return $m[1];
		}
		return '';
	}
}
