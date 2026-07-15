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

		// Real image sources only — CSS gradients must not become Image widgets.
		if ('' !== $src || '' !== $bg_url) {
			return $this->result('image', array(
				'image' => array('url' => $src ?: $bg_url, 'id' => ''),
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

		// Embedded media by URL (content signal, not layout heuristic).
		if ($this->is_youtube($html)) {
			return $this->result('video', array('video_type' => 'youtube', 'youtube_url' => $this->extract_src($html)), 88);
		}
		if ($this->is_vimeo($html)) {
			return $this->result('video', array('video_type' => 'vimeo', 'vimeo_url' => $this->extract_src($html)), 88);
		}
		if ($this->is_maps($html)) {
			return $this->result('google_maps', array('address' => '', 'custom_height' => array('unit' => 'px', 'size' => 360)), 85);
		}

		// Buttons / CTAs — including gradient-filled links with icons.
		if (VisualSignals::looks_button($node) || $this->looks_link_button($node, $text)) {
			return $this->result('button', array(
				'text' => $text ?: 'Button',
				'link' => array('url' => (string) ($node['href'] ?? ''), 'is_external' => '', 'nofollow' => ''),
			), 88);
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

		// SVG inline — still a visual asset.
		if (false !== stripos($html, '<svg')) {
			return array('kind' => 'fallback', 'confidence' => 50);
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
	 * Link that should render as a Button (class/padding/href), even when
	 * background-image is a CSS gradient rather than a photo.
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
		if (preg_match('/\b(btn|button|cta|card-link|nav-link)\b/', $cls)) {
			return true;
		}
		// Text links (nav, footer) → native Button widgets for editability.
		if (VisualSignals::has_background($node['s'] ?? array()) && VisualSignals::padding_sum($node['s'] ?? array()) >= 6) {
			return true;
		}
		// Short inline links without block layout.
		$box = \HtmlToElementor\Engine\Geometry::bbox($node);
		$h = $box['height'] ?: (float) ($node['s']['h'] ?? 0);
		return $h > 0 && $h <= 80;
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
}
