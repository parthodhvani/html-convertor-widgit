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
			'heading' => $this->mapper->combine(
				$this->mapper->typography($node),
				$this->mapper->text_color($node, 'title_color'),
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, true),
				$this->mapper->background($node),
				$this->mapper->border($node),
				$this->mapper->position($node)
			),
			'text-editor' => $this->mapper->combine(
				$this->mapper->typography($node),
				$this->mapper->text_color($node, 'text_color'),
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, true),
				$this->mapper->background($node),
				$this->mapper->border($node),
				$this->mapper->position($node)
			),
			'button' => $this->map_button($node),
			'image' => $this->mapper->combine(
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, true),
				$this->mapper->border($node),
				$this->mapper->box_shadow($node),
				$this->mapper->image_media($node),
				$this->mapper->effects($node)
			),
			// Painted composites must retain browser backgrounds (incl. gradients).
			'call-to-action', 'price-table', 'icon-box', 'testimonial' => $this->map_painted_composite($node, $widget_type),
			'accordion' => $this->mapper->combine(
				$this->mapper->spacing($node, true),
				$this->mapper->background($node),
				$this->mapper->border($node),
				$this->mapper->box_shadow($node)
			),
			'form', 'social-icons', 'star-rating', 'icon-list',
			'divider', 'spacer', 'video', 'google_maps', 'icon' => $this->mapper->combine(
				$this->mapper->spacing($node, true),
				$this->map_icon_paint($node, $widget_type)
			),
			default => $this->mapper->combine(
				$this->mapper->spacing($node, true),
				$this->mapper->effects($node)
			),
		};

		if (in_array($widget_type, array('text-editor', 'heading'), true)) {
			$settings = $this->mapper->combine($settings, $this->single_line_text_guard($node));
		}

		return $this->apply_token_references($settings);
	}

	/**
	 * Keep short contact lines (phones, emails) from wrapping mid-token in Elementor.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @return array<string,mixed>
	 */
	private function single_line_text_guard(array $node): array
	{
		$text = trim(preg_replace('/\s+/', ' ', (string) ($node['text'] ?? '')) ?? '');
		if ('' === $text || strlen($text) > 48) {
			return array();
		}
		// Only contact tokens (phones / emails / short locale lines). Do NOT
		// nowrap ordinary nav labels — that forces header flex rows to wrap and
		// doubles header height.
		$looks_contact = (bool) preg_match(
			'/^(\+\d[\d\s\/-]{6,}|\S+@\S+\.\S+|\d{4,}\s+\w+)/u',
			$text
		);
		if (!$looks_contact) {
			return array();
		}

		return array(
			'custom_css' => 'selector, selector * { white-space: nowrap !important; }',
		);
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
		$out = $this->mapper->combine(
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
		$settings = $this->mapper->combine(
			$this->mapper->flex($node),
			$this->mapper->background($node),
			$this->mapper->border($node),
			$this->mapper->box_shadow($node),
			$this->mapper->sizing($node),
			$this->mapper->effects($node),
			$this->mapper->text_color($node, 'text_color'),
			$this->map_constraint_spacing($node, $is_section)
		);

		return $this->apply_token_references($settings);
	}

	/**
	 * Padding and gap from geometry constraints — never invent centering gutters.
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
		$s = $node['s'] ?? array();

		// Prefer browser CSS padding/margins (auto-margins already normalized).
		$out = array_merge($out, $this->mapper->spacing($node, true));

		// Geometry padding may fill missing sides only — never overwrite CSS and
		// never apply centering free-space as Elementor padding.
		if (!empty($whitespace['padding']) && is_array($whitespace['padding'])) {
			$p = $whitespace['padding'];
			$geo = array(
				'top' => round((float) ($p['top'] ?? 0)),
				'right' => round((float) ($p['right'] ?? 0)),
				'bottom' => round((float) ($p['bottom'] ?? 0)),
				'left' => round((float) ($p['left'] ?? 0)),
			);

			$css = array(
				'top' => (float) ($s['pt'] ?? 0),
				'right' => (float) ($s['pr'] ?? 0),
				'bottom' => (float) ($s['pb'] ?? 0),
				'left' => (float) ($s['pl'] ?? 0),
			);

			$merged = array(
				'top' => $css['top'] > 0 ? $css['top'] : $geo['top'],
				'right' => $css['right'] > 0 ? $css['right'] : $geo['right'],
				'bottom' => $css['bottom'] > 0 ? $css['bottom'] : $geo['bottom'],
				'left' => $css['left'] > 0 ? $css['left'] : $geo['left'],
			);

			// Drop residual centering gutters that slipped through.
			if ($merged['left'] > 48 && $merged['right'] > 48
				&& abs($merged['left'] - $merged['right']) <= max(12.0, 0.2 * max($merged['left'], $merged['right']))
				&& $css['left'] <= 48 && $css['right'] <= 48
			) {
				$merged['left'] = $css['left'];
				$merged['right'] = $css['right'];
			}

			// Drop one-sided stretch gutters (short logo in wide grid cell).
			if ($merged['right'] > 48 && $merged['right'] > $merged['left'] + 24 && $css['right'] <= 8) {
				$merged['right'] = $css['right'];
			}
			if ($merged['left'] > 48 && $merged['left'] > $merged['right'] + 24 && $css['left'] <= 8) {
				$merged['left'] = $css['left'];
			}
			if ($merged['bottom'] > 48 && $css['bottom'] <= 8) {
				$merged['bottom'] = $css['bottom'];
			}

			if ($merged['top'] > 0 || $merged['right'] > 0 || $merged['bottom'] > 0 || $merged['left'] > 0) {
				$out['padding'] = array(
					'unit' => 'px',
					'top' => (string) $merged['top'],
					'right' => (string) $merged['right'],
					'bottom' => (string) $merged['bottom'],
					'left' => (string) $merged['left'],
					'isLinked' => false,
				);
			}
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
		$style = $this->mapper->combine(
			$this->mapper->typography($node),
			$this->mapper->text_color($node, 'button_text_color'),
			$this->mapper->alignment($node, 'align'),
			$this->mapper->border($node),
			$this->mapper->background($node),
			$this->mapper->position($node)
		);

		// Elementor Button uses text_padding (not padding) and button_box_shadow
		// (not box_shadow) — see includes/widgets/traits/button-trait.php.
		$spacing = $this->mapper->spacing($node, false);
		if (!empty($spacing['padding']) && is_array($spacing['padding'])) {
			$style['text_padding'] = $spacing['padding'];
		}
		foreach ($spacing as $key => $value) {
			if (is_string($key) && preg_match('/^padding_(tablet|mobile|laptop|widescreen)$/', $key, $m)) {
				$style['text_padding_' . $m[1]] = $value;
			}
		}

		$shadow = $this->mapper->box_shadow($node);
		if (!empty($shadow['box_shadow_box_shadow_type'])) {
			$style['button_box_shadow_box_shadow_type'] = $shadow['box_shadow_box_shadow_type'];
		}
		if (!empty($shadow['box_shadow_box_shadow'])) {
			$style['button_box_shadow_box_shadow'] = $shadow['box_shadow_box_shadow'];
		}

		if (empty($style['background_background']) && empty($style['background_color'])) {
			$bg = (string) ($node['s']['bg'] ?? '');
			if ('' !== $bg && false === stripos($bg, 'gradient') && !$this->is_transparent_color($bg)) {
				$style['background_background'] = 'classic';
				$style['background_color'] = $bg;
			} elseif (!empty($style['border_border'])) {
				// Outline / ghost buttons: source has no fill. Explicit transparent
				// prevents Elementor's default accent background from painting over.
				$style['background_background'] = 'classic';
				$style['background_color'] = 'rgba(0,0,0,0)';
			}
		}

		// Source .btn { gap: 10px } → Elementor icon_indent.
		$gap = $this->css_gap_px($node);
		if ($gap > 0) {
			$style['icon_indent'] = array(
				'unit' => 'px',
				'size' => $gap,
			);
		}

		return $style;
	}

	/**
	 * @param string $color Colour string.
	 */
	private function is_transparent_color(string $color): bool
	{
		$c = strtolower(preg_replace('/\s+/', '', $color) ?? '');
		return '' === $c || 'transparent' === $c || 'rgba(0,0,0,0)' === $c;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	private function css_gap_px(array $node): float
	{
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
