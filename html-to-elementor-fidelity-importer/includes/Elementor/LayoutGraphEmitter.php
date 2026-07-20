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

		if (in_array($role, array('layered_block', 'hero'), true)) {
			$layered = $this->builder->emit_layered_block($tree);
			if (null !== $layered) {
				return $layered;
			}
		}
		if (in_array($role, array('horizontal_bar', 'header'), true)) {
			$bar = $this->builder->emit_horizontal_bar($tree);
			if (null !== $bar) {
				return $bar;
			}
		}

		$composite = $this->builder->emit_composite_widget($tree);
		if (null !== $composite) {
			// Only collapse the whole section when it is itself a pure pattern
			// (e.g. a FAQ section with no sibling chrome). Mixed sections descend.
			$role = strtolower((string) ($tree['layoutRole'] ?? ''));
			$cls = strtolower((string) ($tree['cls'] ?? ''));
			$pure = in_array($role, array('faq', 'form_block', 'testimonial', 'cta_block', 'cta'), true)
				|| (bool) preg_match('/^(faq|accordion)(-section)?$/i', trim($cls));
			if ($pure) {
				return $this->builder->emit_container($tree, array($composite), true, false, 0.0);
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

		// Prefer a pure composite widget when the node itself is a pattern
		// (e.g. `.faq` wrapper). Mixed sections fall through so headings stay.
		$role = strtolower((string) ($node['layoutRole'] ?? ''));
		$cls = strtolower((string) ($node['cls'] ?? ''));
		$pure_pattern = in_array($role, array('faq', 'form_block', 'social_icons', 'pricing', 'cta_block', 'cta'), true)
			|| (bool) preg_match('/\b(faq|accordion|cta-banner|socials|newsletter-form|price-table|pricing)\b/', $cls);
		if ($pure_pattern) {
			$composite = $this->builder->emit_composite_widget($node);
			if (null !== $composite) {
				// Keep a layout container so CSS gap / flex alignment survive
				// composite collapse (FAQ, CTA, socials).
				if ($this->should_emit_container($node, $is_section, $parent_row)) {
					return array($this->builder->emit_container($node, array($composite), $is_section, $parent_row, $parent_width));
				}
				return array($composite);
			}
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
		$is_grid = false !== strpos((string) ($node['s']['disp'] ?? ''), 'grid');
		// CSS Grid needs percentage children for Elementor free / flex fallback
		// when custom_css display:grid is unavailable. flex_shrink stays 0 so
		// equal card tracks cannot collapse and wrap phone numbers.
		$parent_row_for_children = $child_row || $is_grid;

		// Group consecutive accordion/details children into one widget.
		$grouped = $this->emit_children_with_accordion_groups(
			(array) ($node['children'] ?? array()),
			$is_section,
			$parent_row_for_children,
			$self_width,
			$node
		);
		$out = array_merge($out, $grouped);

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
	 * Emit children, collapsing consecutive details/faq-item nodes into one accordion.
	 *
	 * @param array<int,mixed>    $children     Child nodes.
	 * @param bool                $is_section   Section flag.
	 * @param bool                $child_row    Row layout.
	 * @param float               $self_width   Parent width.
	 * @param array<string,mixed> $parent       Parent node (for class hints).
	 * @return array<int,array<string,mixed>>
	 */
	private function emit_children_with_accordion_groups(
		array $children,
		bool $is_section,
		bool $child_row,
		float $self_width,
		array $parent
	): array {
		$out = array();
		$buffer = array();
		$flush = function () use (&$out, &$buffer, $parent): void {
			if (count($buffer) >= 2) {
				$group = array(
					'tag' => 'div',
					'cls' => trim((string) ($parent['cls'] ?? '') . ' faq'),
					'layoutRole' => 'faq',
					'children' => $buffer,
					's' => array(),
				);
				$composite = $this->builder->emit_composite_widget($group);
				if (null !== $composite) {
					$out[] = $composite;
					$buffer = array();
					return;
				}
			}
			foreach ($buffer as $item) {
				$out = array_merge($out, $this->emit_node($item, false, false, 0.0));
			}
			$buffer = array();
		};

		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			$tag = strtolower((string) ($child['tag'] ?? ''));
			$cls = strtolower((string) ($child['cls'] ?? ''));
			$is_item = 'details' === $tag || (bool) preg_match('/\b(faq-item|accordion-item)\b/', $cls);
			if ($is_item) {
				// Painted FAQ cards still collapse to accordion — chrome moves onto
				// the widget. Only bail for non-FAQ painted siblings mis-tagged.
				$explicit_faq = 'details' === $tag
					|| (bool) preg_match('/\b(faq-item|accordion-item)\b/', $cls);
				if (!$explicit_faq) {
					$signals = VisualSignals::analyze($child);
					if ($signals['has_background'] || $signals['has_border'] || $signals['has_shadow']) {
						$flush();
						$out = array_merge($out, $this->emit_node($child, false, $child_row, $self_width));
						continue;
					}
				}
				$buffer[] = $child;
				continue;
			}
			$flush();
			$out = array_merge($out, $this->emit_node($child, false, $child_row, $self_width));
		}
		$flush();

		return $out;
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

		if (in_array($role, array('layered_block', 'hero'), true)) {
			$layered = $this->builder->emit_layered_block($node);
			return null !== $layered ? array($layered) : $this->emit_children($node, $is_section, $parent_row, $parent_width);
		}
		if (in_array($role, array('horizontal_bar', 'header'), true)) {
			$bar = $this->builder->emit_horizontal_bar($node);
			if (null !== $bar) {
				return array($bar);
			}
		}

		$composite = $this->builder->emit_composite_widget($node);
		if (null !== $composite) {
			// Grid/flex columns need a width-bearing wrapper; otherwise emit the
			// composite alone so padding/background are not applied twice.
			if ($parent_row) {
				$wrap = $this->builder->emit_container($node, array($composite), $is_section, $parent_row, $parent_width);
				$wrap = $this->strip_nested_widget_chrome($wrap);
				return array($wrap);
			}
			return array($composite);
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
			'hero',
			'horizontal_bar',
			'header',
			'footer_band',
			'row_group',
			'column_group',
			'card',
			'cta_block',
			'form_block',
			'faq',
			'testimonial',
			'gallery',
			'logo_cloud',
			'team',
			'statistics',
			'timeline',
			'contact',
			'icon_box',
			'social_icons',
			'pricing',
			'stack',
			'section',
		), true)) {
			// Nested header/nav fragments that are only atomic links must hoist
			// into the parent row (Bootstrap navbar). Keep a box when the node
			// owns CSS gap/paint (Petra .nav-list gap:28px).
			if ($parent_row && in_array($role, array('header', 'horizontal_bar'), true)) {
				$kids = (array) ($node['children'] ?? array());
				if ($this->all_atomic_leaves($kids) && !$this->css_declared_bar_chrome($node)) {
					return false;
				}
			}
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
		// logo-text style stacks (own text + child line) inside a row parent must
		// stay a column box — otherwise "Petra Müller" and the tagline sit in the
		// logo row as siblings of the mark.
		if ($parent_row) {
			$text = trim((string) ($node['text'] ?? ''));
			$pieces = count(array_filter($children, 'is_array')) + ('' !== $text ? 1 : 0);
			$fd = strtolower((string) ($node['s']['fd'] ?? 'column'));
			if ($pieces >= 2 && false === strpos($fd, 'row')) {
				return true;
			}
		}

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

	/**
	 * CSS-declared gap/padding/background on a bar (ignores invented whitespace).
	 *
	 * @param array<string,mixed> $node Node.
	 */
	private function css_declared_bar_chrome(array $node): bool
	{
		$s = $node['s'] ?? array();
		$gap = $s['gap'] ?? 0;
		if (is_string($gap)) {
			$gap = (float) $gap;
		}
		if ((float) $gap > 0) {
			return true;
		}
		// Do not treat WhitespaceAnalyzer-stamped pt/pb as real chrome.
		$bg = strtolower((string) ($s['bg'] ?? ''));
		if ('' !== $bg && 'transparent' !== $bg && false === strpos($bg, 'rgba(0, 0, 0, 0)')) {
			return true;
		}
		return !empty($s['bgImg']);
	}

	/**
	 * Drop box chrome from nested widgets (and duplicate single-child wrappers)
	 * so padding/background applied on the outer container is not painted twice.
	 *
	 * @param array<string,mixed> $element Container wrapping a composite.
	 * @return array<string,mixed>
	 */
	private function strip_nested_widget_chrome(array $element): array
	{
		if ('container' !== ($element['elType'] ?? '')) {
			return $element;
		}

		$kids = array_values((array) ($element['elements'] ?? array()));
		foreach ($kids as $i => $kid) {
			if (!is_array($kid)) {
				continue;
			}
			$el_type = (string) ($kid['elType'] ?? '');
			if ('widget' === $el_type) {
				$kids[$i]['settings'] = $this->without_box_chrome((array) ($kid['settings'] ?? array()));
				continue;
			}
			if ('container' !== $el_type) {
				continue;
			}

			// Paint-chrome composites arrive as container > [chrome…, widget].
			// Strip duplicate padding/margin on that inner wrapper, but keep
			// nested chrome backgrounds (gradients / card icons).
			$kids[$i]['settings'] = $this->without_box_chrome((array) ($kid['settings'] ?? array()), true);
			$nested = array_values((array) ($kid['elements'] ?? array()));
			foreach ($nested as $j => $inner) {
				if (!is_array($inner) || 'widget' !== ($inner['elType'] ?? '')) {
					continue;
				}
				$nested[$j]['settings'] = $this->without_box_chrome((array) ($inner['settings'] ?? array()));
			}
			$kids[$i]['elements'] = $nested;
		}
		$element['elements'] = $kids;

		return $element;
	}

	/**
	 * Remove padding/margin/background/border/shadow keys from settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param bool                $keep_background When true, retain background_* paint.
	 * @return array<string,mixed>
	 */
	private function without_box_chrome(array $settings, bool $keep_background = false): array
	{
		foreach (array_keys($settings) as $key) {
			if (!is_string($key)) {
				continue;
			}
			if (0 === strpos($key, 'padding') || 0 === strpos($key, 'margin')) {
				unset($settings[$key]);
				continue;
			}
			if (!$keep_background && 0 === strpos($key, 'background_')) {
				unset($settings[$key]);
				continue;
			}
			if (0 === strpos($key, 'border_') || 0 === strpos($key, 'box_shadow_')) {
				unset($settings[$key]);
			}
		}

		return $settings;
	}
}
