<?php
/**
 * Converts computed CSS into Elementor controls.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

use HtmlToElementor\Elementor\CssMapper;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * CSS Mapping Engine — delegates to {@see CssMapper} and applies design-token
 * awareness so repeated values map to global styles instead of inline CSS.
 */
final class CssMappingEngine implements EngineInterface
{

	private CssMapper $mapper;

	/** @var array<string,mixed> */
	private array $tokens = array();

	public function __construct(?CssMapper $mapper = null)
	{
		$this->mapper = $mapper ?? new CssMapper();
	}

	public function name(): string
	{
		return 'css_mapping';
	}

	/**
	 * @param array<string,mixed> $tokens Design tokens.
	 */
	public function set_tokens(array $tokens): void
	{
		$this->tokens = $tokens;
	}

	/**
	 * Map a node's computed styles to Elementor controls for a widget type.
	 *
	 * @param array<string,mixed> $node       Tree node.
	 * @param string              $widget_type Elementor widget type.
	 * @return array<string,mixed>
	 */
	public function map_widget(array $node, string $widget_type): array
	{
		$settings = match ($widget_type) {
			'heading' => array_merge(
				$this->mapper->typography($node),
				$this->mapper->text_color($node, 'title_color'),
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, true),
				$this->mapper->background($node),
				$this->mapper->border($node),
				$this->mapper->position($node)
			),
			'text-editor' => array_merge(
				$this->mapper->typography($node),
				$this->mapper->text_color($node, 'text_color'),
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, true),
				$this->mapper->background($node),
				$this->mapper->border($node),
				$this->mapper->position($node)
			),
			'button' => $this->map_button($node),
			'image' => array_merge(
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, true),
				$this->mapper->border($node),
				$this->mapper->box_shadow($node),
				$this->mapper->image_media($node),
				$this->mapper->effects($node)
			),
			// Painted composites must retain browser backgrounds (incl. gradients).
			'call-to-action', 'price-table', 'icon-box', 'testimonial' => $this->map_painted_composite($node, $widget_type),
			'accordion', 'form', 'social-icons', 'star-rating', 'icon-list',
			'divider', 'spacer', 'video', 'google_maps', 'icon' => array_merge(
				$this->mapper->spacing($node, true),
				$this->map_icon_paint($node, $widget_type)
			),
			default => array_merge(
				$this->mapper->spacing($node, true),
				$this->mapper->effects($node)
			),
		};

		return $this->apply_token_references($settings);
	}

	/**
	 * Map root paint onto composite widgets Elementor can background-style.
	 *
	 * @param array<string,mixed> $node        Tree node.
	 * @param string              $widget_type Widget type.
	 * @return array<string,mixed>
	 */
	private function map_painted_composite(array $node, string $widget_type): array
	{
		$out = array_merge(
			$this->mapper->spacing($node, true),
			$this->mapper->background($node),
			$this->mapper->border($node),
			$this->mapper->box_shadow($node)
		);

		if ('icon-box' === $widget_type) {
			$out = array_merge($out, $this->map_icon_box_chrome($node));
		}

		// CTA / price-table button chrome: approximate absorbed gradient buttons.
		if (in_array($widget_type, array('call-to-action', 'price-table'), true)) {
			$btn = $this->find_painted_button($node);
			if (null !== $btn) {
				$grad = $this->mapper->parse_gradient((string) ($btn['s']['bgImg'] ?? ''));
				if (null === $grad) {
					$grad = $this->mapper->parse_gradient((string) ($btn['s']['bg'] ?? ''));
				}
				if (null !== $grad) {
					$out['button_background_color'] = $grad['color_a'];
					$out['button_text_color'] = (string) ($btn['s']['color'] ?? $out['button_text_color'] ?? '#ffffff');
				} elseif (!empty($btn['s']['bg']) && false === stripos((string) $btn['s']['bg'], 'gradient')) {
					$out['button_background_color'] = (string) $btn['s']['bg'];
				}
			}
		}

		return $out;
	}

	/**
	 * Icon-box stacked chrome from a descendant gradient badge.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @return array<string,mixed>
	 */
	private function map_icon_box_chrome(array $node): array
	{
		$chrome = $this->find_paint_chrome_node($node);
		if (null === $chrome) {
			$chrome = $node;
		}
		$grad = $this->mapper->parse_gradient((string) ($chrome['s']['bgImg'] ?? ''));
		if (null === $grad) {
			$grad = $this->mapper->parse_gradient((string) ($chrome['s']['bg'] ?? ''));
		}
		if (null === $grad) {
			return array();
		}
		return array(
			'view' => 'stacked',
			'primary_color' => $grad['color_a'],
			'secondary_color' => $grad['color_b'],
		);
	}

	/**
	 * @param array<string,mixed> $node        Tree node.
	 * @param string              $widget_type Widget type.
	 * @return array<string,mixed>
	 */
	private function map_icon_paint(array $node, string $widget_type): array
	{
		if ('icon' !== $widget_type) {
			return array();
		}
		$out = array();
		if (!empty($node['s']['color'])) {
			$out['primary_color'] = (string) $node['s']['color'];
		}
		$grad = $this->mapper->parse_gradient((string) ($node['s']['bgImg'] ?? ''));
		if (null !== $grad) {
			$out['view'] = 'stacked';
			$out['primary_color'] = $grad['color_a'];
			$out['secondary_color'] = $grad['color_b'];
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @return array<string,mixed>|null
	 */
	private function find_painted_button(array $node): ?array
	{
		$found = null;
		$walk = function (array $n) use (&$walk, &$found): void {
			if (null !== $found) {
				return;
			}
			$cls = strtolower((string) ($n['cls'] ?? ''));
			$tag = strtolower((string) ($n['tag'] ?? ''));
			$bg_img = (string) ($n['s']['bgImg'] ?? '');
			$is_btn = 'button' === $tag || 'a' === $tag || (bool) preg_match('/\b(btn|button)\b/', $cls);
			$has_paint = false !== stripos($bg_img, 'gradient') || (!empty($n['s']['bg']) && 'transparent' !== strtolower((string) $n['s']['bg']));
			if ($is_btn && $has_paint) {
				$found = $n;
				return;
			}
			foreach ((array) ($n['children'] ?? array()) as $child) {
				if (is_array($child)) {
					$walk($child);
				}
			}
		};
		$walk($node);
		return $found;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @return array<string,mixed>|null
	 */
	private function find_paint_chrome_node(array $node): ?array
	{
		$found = null;
		$walk = function (array $n, bool $is_root) use (&$walk, &$found): void {
			if (null !== $found) {
				return;
			}
			if (!$is_root && $this->is_paint_chrome_node($n)) {
				$found = $n;
				return;
			}
			foreach ((array) ($n['children'] ?? array()) as $child) {
				if (is_array($child)) {
					$walk($child, false);
				}
			}
		};
		$walk($node, true);
		return $found;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	private function is_paint_chrome_node(array $node): bool
	{
		$s = $node['s'] ?? array();
		$bg_img = (string) ($s['bgImg'] ?? '');
		$bg = (string) ($s['bg'] ?? '');
		$has_grad = false !== stripos($bg_img, 'gradient') || false !== stripos($bg, 'gradient');
		if (!$has_grad && '' === $bg_img && ('' === $bg || 'transparent' === strtolower($bg))) {
			return false;
		}
		$cls = strtolower((string) ($node['cls'] ?? ''));
		if (preg_match('/\b(card-icon|avatar|logo-mark|icon-badge|icon-wrap|media-icon)\b/', $cls)) {
			return true;
		}
		$w = (float) ($s['w'] ?? 0);
		$h = (float) ($s['h'] ?? 0);
		return $has_grad && $w > 0 && $h > 0 && $w <= 96 && $h <= 96;
	}

	/**
	 * Map container-level styles using constraint-based spacing only (no margins).
	 *
	 * @param array<string,mixed> $node       Tree node.
	 * @param bool                $is_section Top-level section flag.
	 * @return array<string,mixed>
	 */
	public function map_container(array $node, bool $is_section = false): array
	{
		$settings = array_merge(
			$this->mapper->flex($node),
			$this->mapper->background($node),
			$this->mapper->border($node),
			$this->mapper->box_shadow($node),
			$this->mapper->sizing($node),
			$this->mapper->effects($node),
			$this->map_constraint_spacing($node, $is_section)
		);

		return $this->apply_token_references($settings);
	}

	/**
	 * Padding and gap from geometry constraints — never margins.
	 *
	 * @param array<string,mixed> $node       Tree node.
	 * @param bool                $is_section Top-level section flag.
	 * @return array<string,mixed>
	 */
	private function map_constraint_spacing(array $node, bool $is_section): array
	{
		$out = array();
		$whitespace = $node['whitespace'] ?? array();
		$constraint = $node['layoutConstraint'] ?? array();

		// Preserve browser padding AND margins. Only prefer gap when CSS gap exists.
		$out = array_merge($out, $this->mapper->spacing($node, true));

		if (!empty($whitespace['padding']) && is_array($whitespace['padding'])) {
			$p = $whitespace['padding'];
			$out['padding'] = array(
				'unit' => 'px',
				'top' => (string) round((float) ($p['top'] ?? 0)),
				'right' => (string) round((float) ($p['right'] ?? 0)),
				'bottom' => (string) round((float) ($p['bottom'] ?? 0)),
				'left' => (string) round((float) ($p['left'] ?? 0)),
				'isLinked' => false,
			);
		}

		$gap = (float) ($constraint['gap'] ?? $whitespace['gap'] ?? 0);
		if ($gap > 0) {
			$g = (string) round($gap);
			$out['flex_gap'] = array(
				'column' => $g,
				'row' => $g,
				'isLinked' => true,
				'unit' => 'px',
				'size' => round($gap),
			);
		}

		return $out;
	}

	/**
	 * Expose the underlying mapper for advanced use.
	 */
	public function mapper(): CssMapper
	{
		return $this->mapper;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @return array<string,mixed>
	 */
	private function map_button(array $node): array
	{
		$style = array_merge(
			$this->mapper->typography($node),
			$this->mapper->text_color($node, 'button_text_color'),
			$this->mapper->alignment($node, 'align'),
			$this->mapper->border($node),
			$this->mapper->box_shadow($node),
			$this->mapper->background($node)
		);
		if (empty($style['background_background']) && empty($style['background_color'])) {
			$bg = (string) ($node['s']['bg'] ?? '');
			if ('' !== $bg && false === stripos($bg, 'gradient')) {
				$style['background_color'] = $bg;
			}
		}
		return $style;
	}

	/**
	 * Replace repeated values with token references where possible.
	 *
	 * @param array<string,mixed> $settings Elementor settings.
	 * @return array<string,mixed>
	 */
	private function apply_token_references(array $settings): array
	{
		if (empty($this->tokens)) {
			return $settings;
		}

		$palette = $this->tokens['palette'] ?? array();
		foreach (array('background_color', 'title_color', 'text_color', 'button_text_color') as $key) {
			if (!empty($settings[$key]) && in_array($settings[$key], $palette, true)) {
				$settings['_h2e_token_color'] = $settings[$key];
			}
		}

		return $settings;
	}
}
