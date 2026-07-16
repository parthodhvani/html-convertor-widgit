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
				$this->mapper->spacing($node, false),
				$this->mapper->border($node),
				$this->mapper->background($node),
				$this->mapper->box_shadow($node)
			),
			'text-editor' => array_merge(
				$this->mapper->typography($node),
				$this->mapper->text_color($node, 'text_color'),
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, false),
				$this->mapper->border($node),
				$this->mapper->background($node),
				$this->mapper->box_shadow($node)
			),
			'button' => $this->map_button($node),
			'image' => array_merge(
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, false),
				$this->mapper->border($node),
				$this->mapper->box_shadow($node),
				$this->mapper->image_media($node),
				$this->mapper->effects($node)
			),
			default => array_merge(
				$this->mapper->spacing($node, false),
				$this->mapper->effects($node)
			),
		};

		return $this->apply_token_references($settings);
	}

	/**
	 * Map container-level styles: flex/paint + padding/gap + preserved margins.
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
	 * Padding, gap, and (when still present) margins from browser IR.
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

		// Prefer CSS padding; allow whitespace to fill missing sides only.
		$spacing = $this->mapper->spacing($node, true);
		$out = array_merge($out, $spacing);

		// Residual bbox padding is only a fallback when Chromium never emitted
		// padding keys. Cap each side so under-filled rows cannot invent 1000px+.
		$s = $node['s'] ?? array();
		$has_computed_pad = array_key_exists('pt', $s) || array_key_exists('pr', $s)
			|| array_key_exists('pb', $s) || array_key_exists('pl', $s);
		if (!$has_computed_pad && !empty($whitespace['padding']) && is_array($whitespace['padding']) && empty($out['padding'])) {
			$p = $whitespace['padding'];
			$sides = array(
				'top' => (float) ($p['top'] ?? 0),
				'right' => (float) ($p['right'] ?? 0),
				'bottom' => (float) ($p['bottom'] ?? 0),
				'left' => (float) ($p['left'] ?? 0),
			);
			$max_side = max($sides);
			if ($max_side > 0 && $max_side <= 160) {
				$out['padding'] = array(
					'unit' => 'px',
					'top' => (string) round($sides['top']),
					'right' => (string) round($sides['right']),
					'bottom' => (string) round($sides['bottom']),
					'left' => (string) round($sides['left']),
					'isLinked' => false,
				);
			}
		}

		// Only emit flex_gap when constraint/CSS gap exists — not margin-invented gaps.
		$gap = (float) ($constraint['gap'] ?? 0);
		if ($gap <= 0 && !empty($node['s']['gap']) && empty($node['whitespace']['gap_from_margins'])) {
			$gap = (float) preg_replace('/[^\d.]/', '', (string) $node['s']['gap']);
		}
		if ($gap > 0 && empty($constraint['gap_from_margins']) && empty($whitespace['gap_from_margins'])) {
			$g = (string) round($gap);
			$row_gap = $gap;
			$col_gap = $gap;
			if (!empty($node['s']['rgap'])) {
				$row_gap = (float) preg_replace('/[^\d.]/', '', (string) $node['s']['rgap']);
			}
			if (!empty($node['s']['cgap'])) {
				$col_gap = (float) preg_replace('/[^\d.]/', '', (string) $node['s']['cgap']);
			}
			$out['flex_gap'] = array(
				'column' => (string) round($col_gap),
				'row' => (string) round($row_gap),
				'isLinked' => abs($row_gap - $col_gap) < 0.5,
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
			$this->mapper->box_shadow($node)
		);
		$bg = (string) ($node['s']['bg'] ?? '');
		if ('' !== $bg) {
			$style['background_color'] = $bg;
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
