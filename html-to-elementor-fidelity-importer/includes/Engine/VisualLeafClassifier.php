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

		// Media.
		if ('' !== $src || !empty($node['s']['bgImg'])) {
			return $this->result('image', array(
				'image' => array('url' => $src ?: $this->extract_bg_url((string) ($node['s']['bgImg'] ?? '')), 'id' => ''),
				'image_size' => 'full',
				'alt' => (string) ($node['alt'] ?? ''),
			), 90);
		}

		// Embedded media by URL (content signal, not layout heuristic).
		$html = (string) ($node['html'] ?? '');
		if ($this->is_youtube($html)) {
			return $this->result('video', array('video_type' => 'youtube', 'youtube_url' => $this->extract_src($html)), 88);
		}
		if ($this->is_vimeo($html)) {
			return $this->result('video', array('video_type' => 'vimeo', 'vimeo_url' => $this->extract_src($html)), 88);
		}
		if ($this->is_maps($html)) {
			return $this->result('google_maps', array('address' => '', 'custom_height' => array('unit' => 'px', 'size' => 360)), 85);
		}

		// Interactive.
		if (VisualSignals::looks_button($node)) {
			return $this->result('button', array(
				'text' => $text ?: 'Button',
				'link' => array('url' => (string) ($node['href'] ?? ''), 'is_external' => '', 'nofollow' => ''),
			), 88);
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
			$inner = $this->inner_html($node);
			return $this->result('text-editor', array('editor' => '<p>' . esc_html($text) . '</p>'), 85);
		}

		// SVG inline — still a visual asset.
		if (false !== stripos($html, '<svg')) {
			return array('kind' => 'fallback', 'confidence' => 50);
		}

		// Form controls without native Elementor mapping.
		if (VisualSignals::looks_input_like($node) || $signals['input_like_children'] > 0) {
			return array('kind' => 'fallback', 'confidence' => 45);
		}

		return null;
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
		if (preg_match('/url\(["\']?([^"\')]+)["\']?\)/', $bgImg, $m)) {
			return $m[1];
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
