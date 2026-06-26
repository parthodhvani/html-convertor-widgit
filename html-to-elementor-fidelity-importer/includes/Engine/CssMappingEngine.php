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
				$this->mapper->spacing($node, true)
			),
			'text-editor' => array_merge(
				$this->mapper->typography($node),
				$this->mapper->text_color($node, 'text_color'),
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, true)
			),
			'button' => $this->map_button($node),
			'image' => array_merge(
				$this->mapper->alignment($node, 'align'),
				$this->mapper->spacing($node, true),
				$this->mapper->border($node),
				$this->mapper->box_shadow($node)
			),
			default => $this->mapper->spacing($node, true),
		};

		return $this->apply_token_references($settings);
	}

	/**
	 * Map container-level styles.
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
			$this->mapper->spacing($node, !$is_section)
		);

		return $this->apply_token_references($settings);
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
