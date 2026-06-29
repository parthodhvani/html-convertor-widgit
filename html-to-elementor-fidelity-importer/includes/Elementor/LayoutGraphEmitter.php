<?php
/**
 * Emits Elementor JSON from a solved layout graph — not DOM recursion.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

use HtmlToElementor\Engine\VisualSignals;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Layout Graph Emitter (v4) — one layout-graph node becomes one Elementor container.
 * Transparent wrappers are hoisted; leaves become native widgets.
 */
final class LayoutGraphEmitter
{

	public function __construct(private LayoutTreeConverter $builder)
	{
	}

	/**
	 * Emit a top-level section container from a solved layout tree.
	 *
	 * @param array<string,mixed> $tree Section root.
	 * @return array<string,mixed>|null
	 */
	public function emit_section(array $tree): ?array
	{
		$role = (string) ($tree['layoutRole'] ?? '');

		if ('layered_block' === $role) {
			$layered = $this->builder->emit_layered_block($tree);
			if (null !== $layered) {
				return $layered;
			}
		}
		if ('horizontal_bar' === $role) {
			$bar = $this->builder->emit_horizontal_bar($tree);
			if (null !== $bar) {
				return $bar;
			}
		}

		if ($this->builder->needs_html_fallback($tree)) {
			return $this->builder->emit_fallback_wrap($tree);
		}

		$elements = $this->emit_children($tree, true, false, (float) ($tree['s']['w'] ?? 0));
		if (empty($elements)) {
			return null;
		}

		if (1 === count($elements) && 'container' === ($elements[0]['elType'] ?? '')) {
			$el = $elements[0];
			if (!($el['isInner'] ?? true)) {
				return $el;
			}
		}

		if (!$this->should_emit_container($tree, true, false)) {
			return 1 === count($elements) ? $elements[0] : null;
		}

		return $this->builder->emit_container($tree, $elements, true, false, 0.0);
	}

	/**
	 * Emit child elements for a layout node.
	 *
	 * @param array<string,mixed> $node         Source node.
	 * @param bool                $is_section   Section root flag.
	 * @param bool                $parent_row   Parent is a row.
	 * @param float               $parent_width Parent width.
	 * @return array<int,array<string,mixed>>
	 */
	private function emit_children(array $node, bool $is_section, bool $parent_row, float $parent_width): array
	{
		if (!empty($node['atomic']) || empty($node['children'])) {
			return $this->builder->emit_leaves($node);
		}

		if ($this->builder->needs_html_fallback($node)) {
			return array($this->builder->emit_html_widget($node));
		}

		$out = array();
		$text = trim((string) ($node['text'] ?? ''));
		if ('' !== $text) {
			$out[] = $this->builder->emit_text_widget($text, $node);
		}

		$child_row = 'row' === $this->builder->flex_direction($node);
		$self_width = (float) ($node['s']['w'] ?? 0);

		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$out = array_merge(
				$out,
				$this->emit_node($child, false, $child_row, $self_width)
			);
		}

		if (empty($out)) {
			if ($this->builder->looks_like_spacer($node)) {
				return array($this->builder->emit_spacer($node));
			}
			if (!empty($node['html'])) {
				return array($this->builder->emit_html_widget($node));
			}
			return array();
		}

		if (!$this->should_emit_container($node, $is_section, $parent_row)) {
			return $out;
		}

		return array($this->builder->emit_container($node, $out, $is_section, $parent_row, $parent_width));
	}

	/**
	 * Emit a single node — container, hoisted children, or leaf.
	 *
	 * @param array<string,mixed> $node         Node.
	 * @param bool                $is_section   Section flag.
	 * @param bool                $parent_row   Parent row.
	 * @param float               $parent_width Parent width.
	 * @return array<int,array<string,mixed>>
	 */
	private function emit_node(array $node, bool $is_section, bool $parent_row, float $parent_width): array
	{
		$role = (string) ($node['layoutRole'] ?? '');

		if ('layered_block' === $role) {
			$layered = $this->builder->emit_layered_block($node);
			return null !== $layered ? array($layered) : $this->emit_children($node, $is_section, $parent_row, $parent_width);
		}

		if (!empty($node['atomic'])) {
			return $this->builder->emit_leaves($node);
		}

		$children = (array) ($node['children'] ?? array());
		if (1 === count($children) && is_array($children[0]) && empty($node['text'])) {
			$signals = VisualSignals::analyze($node);
			if (!$signals['has_background'] && !$signals['has_border'] && !$signals['has_shadow']
				&& !$signals['has_padding'] && '' === $role && empty($node['layoutConstraint'])) {
				return $this->emit_node($children[0], $is_section, $parent_row, $parent_width);
			}
		}

		return $this->emit_children($node, $is_section, $parent_row, $parent_width);
	}

	/**
	 * Whether this node warrants its own Elementor container.
	 *
	 * @param array<string,mixed> $node       Node.
	 * @param bool                $is_section Section root.
	 * @param bool                $parent_row Parent lays out children in a row.
	 */
	private function should_emit_container(array $node, bool $is_section, bool $parent_row): bool
	{
		if ($is_section) {
			return true;
		}

		$role = (string) ($node['layoutRole'] ?? '');
		if (in_array($role, array(
			'layered_block',
			'horizontal_bar',
			'footer_band',
			'row_group',
			'column_group',
			'card',
			'cta_block',
			'form_block',
			'stack',
			'section',
		), true)) {
			return true;
		}

		if (VisualSignals::is_layered($node)) {
			return true;
		}

		$signals = VisualSignals::analyze($node);
		if ($signals['has_background'] || $signals['has_border'] || $signals['has_shadow'] || $signals['has_padding']) {
			return true;
		}

		$children = (array) ($node['children'] ?? array());
		if ($this->all_atomic_leaves($children)) {
			if ($parent_row) {
				return $this->column_stack_in_row($node, $children);
			}

			return false;
		}

		if (!empty($node['layoutConstraint']) && count($children) >= 2) {
			return true;
		}

		return count($children) >= 2;
	}

	/**
	 * Vertical stacks inside a row need a column container; horizontal groups hoist.
	 *
	 * @param array<string,mixed>            $node     Node.
	 * @param array<int,array<string,mixed>> $children Child nodes.
	 */
	private function column_stack_in_row(array $node, array $children): bool
	{
		if (count($children) < 2) {
			return false;
		}

		$constraint = $node['layoutConstraint'] ?? array();
		if (!empty($constraint['direction'])) {
			return 'column' === (string) $constraint['direction'];
		}

		$flex = strtolower((string) ($node['s']['fd'] ?? 'column'));
		if (false !== strpos($flex, 'row')) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $children Child nodes.
	 */
	private function all_atomic_leaves(array $children): bool
	{
		if (empty($children)) {
			return false;
		}

		foreach ($children as $child) {
			if (!is_array($child) || empty($child['atomic'])) {
				return false;
			}
		}

		return true;
	}
}
