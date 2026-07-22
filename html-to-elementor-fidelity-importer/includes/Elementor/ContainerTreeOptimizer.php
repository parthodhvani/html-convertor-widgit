<?php
/**
 * Post-emission container hierarchy optimizer.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Compresses redundant Elementor containers after layout-graph emission.
 *
 * Phase 13 — Widget Optimizer goals:
 * - Merge redundant single-child / identical-signature wrappers
 * - Split oversized flat widget stacks into designer-like groups
 * - Preserve visual styling and layout controls (never change pixels)
 */
final class ContainerTreeOptimizer
{

	private const OVERSIZED_WIDGET_THRESHOLD = 8;

	private int $removed = 0;
	private int $split = 0;
	private int $containers_before = 0;
	private int $containers_after = 0;

	/**
	 * Optimize a top-level Elementor element list.
	 *
	 * @param array<int,array<string,mixed>> $elements Root elements.
	 * @return array<int,array<string,mixed>>
	 */
	public function optimize(array $elements): array
	{
		$this->removed = 0;
		$this->split = 0;
		$this->containers_before = $this->count_containers($elements);

		$optimized = array();
		foreach ($elements as $element) {
			$next = $this->optimize_element($element);
			if (null !== $next) {
				$optimized[] = $next;
			}
		}

		$optimized = $this->ensure_nested_full_widths($optimized);
		$this->containers_after = $this->count_containers($optimized);
		return array_values($optimized);
	}

	/**
	 * Ensure nesting levels 2–10 fill their parent in Elementor (Full Width + 100%).
	 *
	 * Nested containers (including former column shares like 40%/51%) are forced
	 * to width 100% through depth 10 so Elementor structure stays intact. Tiny px
	 * chrome boxes and absolute/fixed layers are left alone.
	 *
	 * @param array<int,array<string,mixed>> $elements Root elements.
	 * @return array<int,array<string,mixed>>
	 */
	public function ensure_nested_full_widths(array $elements): array
	{
		return $this->apply_nested_full_widths($elements, 1, 'column');
	}

	/**
	 * Metrics from the last optimization pass.
	 *
	 * @return array<string,mixed>
	 */
	public function stats(): array
	{
		$before = max(1, $this->containers_before);
		$after = $this->containers_after;

		return array(
			'containers_before' => $this->containers_before,
			'containers_after' => $after,
			'redundant_containers_removed' => $this->removed,
			'oversized_containers_split' => $this->split,
			'compression_ratio' => round(1 - ($after / $before), 3),
			'average_container_depth' => 0.0,
			'max_container_depth' => 0,
		);
	}

	/**
	 * Depth metrics for an element tree.
	 *
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array{average_container_depth:float,max_container_depth:int}
	 */
	public function depth_metrics(array $elements): array
	{
		$depths = array();
		$this->collect_container_depths($elements, 1, $depths);

		if (empty($depths)) {
			return array(
				'average_container_depth' => 0.0,
				'max_container_depth' => 0,
			);
		}

		return array(
			'average_container_depth' => round(array_sum($depths) / count($depths), 2),
			'max_container_depth' => max($depths),
		);
	}

	/**
	 * @param array<string,mixed> $element Element.
	 * @return array<string,mixed>|null
	 */
	private function optimize_element(array $element): ?array
	{
		$children = (array) ($element['elements'] ?? array());
		if (!empty($children)) {
			$optimized_children = array();
			foreach ($children as $child) {
				$next = $this->optimize_element($child);
				if (null !== $next) {
					$optimized_children[] = $next;
				}
			}
			$element['elements'] = $this->merge_adjacent_containers($optimized_children);
		}

		if ('container' !== ($element['elType'] ?? '')) {
			return $element;
		}

		$element = $this->compress_container_chain($element);
		$element = $this->split_oversized_widget_stack($element);
		return $this->drop_noop_flex_gap($element);
	}

	/**
	 * Split a container with many direct widget children into smaller stacks
	 * grouped by consecutive widget-type runs (designer-like editability).
	 * Layout direction / gap are preserved on the parent; groups inherit direction.
	 *
	 * @param array<string,mixed> $container Container.
	 * @return array<string,mixed>
	 */
	private function split_oversized_widget_stack(array $container): array
	{
		$children = (array) ($container['elements'] ?? array());
		if (count($children) < self::OVERSIZED_WIDGET_THRESHOLD) {
			return $container;
		}

		$all_widgets = true;
		foreach ($children as $child) {
			if ('widget' !== ($child['elType'] ?? '')) {
				$all_widgets = false;
				break;
			}
		}
		if (!$all_widgets) {
			return $container;
		}

		// Do not split rows — equal-width card grids must stay flat.
		$direction = (string) (($container['settings']['flex_direction'] ?? 'column'));
		if ('row' === $direction) {
			return $container;
		}

		$runs = array();
		$current = array($children[0]);
		$current_type = (string) ($children[0]['widgetType'] ?? '');
		for ($i = 1; $i < count($children); ++$i) {
			$type = (string) ($children[$i]['widgetType'] ?? '');
			// Keep heading+text / text+button pairs together (common designer pattern).
			$pair = ('heading' === $current_type && 'text-editor' === $type)
				|| ('text-editor' === $current_type && 'button' === $type)
				|| ('heading' === $current_type && 'button' === $type);
			if ($type !== $current_type && !$pair && count($current) >= 1) {
				if ('heading' === $type && count($current) >= 2) {
					$runs[] = $current;
					$current = array($children[$i]);
					$current_type = $type;
					continue;
				}
			}
			$current[] = $children[$i];
			$current_type = $type;
		}
		$runs[] = $current;

		if (count($runs) < 2) {
			return $container;
		}

		$grouped = array();
		foreach ($runs as $run_index => $run) {
			if (1 === count($run)) {
				$grouped[] = $run[0];
				continue;
			}
			$seed = (string) ($container['id'] ?? 'root') . ':grp:' . $run_index;
			$grouped[] = array(
				'id' => substr(md5($seed), 0, 8),
				'elType' => 'container',
				'isInner' => true,
				'settings' => array(
					'content_width' => 'full',
					'flex_direction' => $direction,
					'_h2e_designer_group' => 1,
				),
				'elements' => $run,
			);
			++$this->split;
		}

		$container['elements'] = $grouped;
		return $container;
	}

	/**
	 * flex_gap with fewer than 2 Elementor children has no flex effect.
	 * When the sole child is a multi-item composite (social-icons, accordion…),
	 * transfer the gap onto that widget so icon/item spacing survives.
	 *
	 * @param array<string,mixed> $container Container.
	 * @return array<string,mixed>
	 */
	private function drop_noop_flex_gap(array $container): array
	{
		$kids = (array) ($container['elements'] ?? array());
		if (count($kids) >= 2) {
			return $container;
		}
		$gap = $container['settings']['flex_gap'] ?? null;
		$size = is_array($gap) ? (float) ($gap['size'] ?? 0) : 0.0;
		if ($size <= 0) {
			return $container;
		}

		if (1 === count($kids) && 'widget' === ($kids[0]['elType'] ?? '')) {
			$wt = (string) ($kids[0]['widgetType'] ?? '');
			if (in_array($wt, array('social-icons', 'icon-list', 'accordion', 'image-carousel', 'form'), true)) {
				$existing = (float) ($kids[0]['settings']['gap']['size']
					?? $kids[0]['settings']['space_between']['size']
					?? 0);
				if ($existing <= 0) {
					$key = 'accordion' === $wt ? 'space_between' : 'gap';
					$kids[0]['settings'][$key] = array(
						'unit' => 'px',
						'size' => $size,
					);
					$container['elements'] = $kids;
				}
				// Keep flex_gap on the wrapper so spacing frames still match
				// the source container gap after composite collapse.
				return $container;
			}
		}

		unset($container['settings']['flex_gap']);
		return $container;
	}

	/**
	 * Repeatedly promote single-child redundant containers.
	 *
	 * @param array<string,mixed> $container Container element.
	 * @return array<string,mixed>
	 */
	private function compress_container_chain(array $container): array
	{
		while (true) {
			$children = (array) ($container['elements'] ?? array());
			if (1 !== count($children)) {
				break;
			}

			$child = $children[0];
			if ('container' !== ($child['elType'] ?? '')) {
				break;
			}

			// Top-level section roots keep their identity. Absorb redundant
			// single-child wrappers underneath instead of promoting the child.
			if (!($container['isInner'] ?? true)) {
				if ($this->is_redundant_container($child) && !$this->has_distinct_geometry($container)) {
					$container = $this->absorb_child($container, $child);
					++$this->removed;
					continue;
				}
				break;
			}

			if (!$this->is_redundant_container($container)) {
				break;
			}

			$container = $this->promote_child($container, $child);
			++$this->removed;
		}

		$children = (array) ($container['elements'] ?? array());
		if (1 === count($children) && 'container' === ($children[0]['elType'] ?? '')) {
			$child = $children[0];
			if (!($container['isInner'] ?? true)) {
				if ($this->layout_signatures_match($container, $child)
					&& $this->is_redundant_container($child)
					&& !$this->has_distinct_geometry($container)) {
					$container = $this->absorb_child($container, $child);
					++$this->removed;
				}
			} elseif ($this->layout_signatures_match($container, $child) && $this->is_redundant_container($container)) {
				$container = $this->promote_child($container, $child);
				++$this->removed;
			}
		}

		return $container;
	}

	/**
	 * Merge consecutive sibling containers that share an identical layout signature.
	 *
	 * @param array<int,array<string,mixed>> $children Child elements.
	 * @return array<int,array<string,mixed>>
	 */
	private function merge_adjacent_containers(array $children): array
	{
		if (count($children) < 2) {
			return $children;
		}

		$merged = array();
		$index = 0;
		$count = count($children);

		while ($index < $count) {
			$current = $children[$index];
			if ('container' !== ($current['elType'] ?? '') || !$this->is_redundant_container($current)) {
				$merged[] = $current;
				++$index;
				continue;
			}

			$group = array($current);
			$next = $index + 1;
			while ($next < $count) {
				$candidate = $children[$next];
				if ('container' !== ($candidate['elType'] ?? '')
					|| !$this->is_redundant_container($candidate)
					|| !$this->layout_signatures_match($current, $candidate)
					|| $this->has_distinct_sibling_geometry($current, $candidate)) {
					break;
				}
				$group[] = $candidate;
				++$next;
			}

			if (count($group) > 1) {
				$combined_children = array();
				foreach ($group as $container) {
					$combined_children = array_merge(
						$combined_children,
						(array) ($container['elements'] ?? array())
					);
				}
				$merged[] = array_merge(
					$current,
					array('elements' => $combined_children)
				);
				$this->removed += count($group) - 1;
				$index = $next;
				continue;
			}

			$merged[] = $current;
			++$index;
		}

		return $merged;
	}

	/**
	 * @param array<string,mixed> $parent Parent container.
	 * @param array<string,mixed> $child  Child container.
	 * @return array<string,mixed>
	 */
	private function promote_child(array $parent, array $child): array
	{
		$child_settings = (array) ($child['settings'] ?? array());
		$parent_settings = (array) ($parent['settings'] ?? array());

		$merged_settings = array_merge($parent_settings, $child_settings);
		$merged_settings = $this->merge_identity($parent_settings, $child_settings, $merged_settings);

		$child['settings'] = $merged_settings;
		$child['isInner'] = $parent['isInner'] ?? ($child['isInner'] ?? true);

		return $child;
	}

	/**
	 * Fold a redundant inner wrapper into its parent, keeping the parent frame.
	 *
	 * @param array<string,mixed> $parent Parent container.
	 * @param array<string,mixed> $child  Child container.
	 * @return array<string,mixed>
	 */
	private function absorb_child(array $parent, array $child): array
	{
		$parent_settings = (array) ($parent['settings'] ?? array());
		$child_settings = (array) ($child['settings'] ?? array());

		// Parent identity/geometry wins; fill missing layout from the child.
		$merged = array_merge($child_settings, $parent_settings);
		$merged = $this->merge_identity($parent_settings, $child_settings, $merged);
		// Restore parent bbox / classes preference after merge_identity concatenation.
		if (!empty($parent_settings['_h2e_bbox'])) {
			$merged['_h2e_bbox'] = $parent_settings['_h2e_bbox'];
		}
		if (!empty($parent_settings['_css_classes'])) {
			$merged['_css_classes'] = $parent_settings['_css_classes'];
		}

		$parent['settings'] = $merged;
		$parent['elements'] = (array) ($child['elements'] ?? array());

		return $parent;
	}

	/**
	 * @param array<string,mixed> $parent   Parent settings.
	 * @param array<string,mixed> $child    Child settings.
	 * @param array<string,mixed> $settings Merged settings.
	 * @return array<string,mixed>
	 */
	private function merge_identity(array $parent, array $child, array $settings): array
	{
		$parent_classes = trim((string) ($parent['_css_classes'] ?? ''));
		$child_classes = trim((string) ($child['_css_classes'] ?? ''));
		$classes = trim($parent_classes . ' ' . $child_classes);
		if ('' !== $classes) {
			$settings['_css_classes'] = preg_replace('/\s+/', ' ', $classes) ?? $classes;
		}

		if (empty($settings['_element_id']) && !empty($parent['_element_id'])) {
			$settings['_element_id'] = $parent['_element_id'];
		}

		return $settings;
	}

	/**
	 * @param array<string,mixed> $container Container element.
	 */
	private function is_redundant_container(array $container): bool
	{
		if ('container' !== ($container['elType'] ?? '')) {
			return false;
		}

		// Never dissolve a top-level section root — geometry pairing and
		// full-bleed section sizing depend on preserving that frame.
		if (!($container['isInner'] ?? true)) {
			return false;
		}

		if ($this->has_visual_styling($container)) {
			return false;
		}

		if ($this->has_layout_controls($container)) {
			return false;
		}

		if ($this->has_responsive_overrides($container)) {
			return false;
		}

		if ($this->has_distinct_geometry($container)) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $a First container.
	 * @param array<string,mixed> $b Second container.
	 */
	private function layout_signatures_match(array $a, array $b): bool
	{
		return $this->layout_signature($a) === $this->layout_signature($b);
	}

	/**
	 * @param array<string,mixed> $container Container element.
	 */
	private function layout_signature(array $container): string
	{
		$settings = (array) ($container['settings'] ?? array());
		$keys = array(
			'flex_direction',
			'flex_justify_content',
			'flex_align_items',
			'content_width',
			'width',
			'min_height',
			'flex_gap',
		);

		$parts = array();
		foreach ($keys as $key) {
			if (!array_key_exists($key, $settings)) {
				continue;
			}
			$parts[] = $key . '=' . json_encode($settings[$key], JSON_THROW_ON_ERROR);
		}

		return implode('|', $parts);
	}

	/**
	 * @param array<string,mixed> $container Container element.
	 */
	private function has_visual_styling(array $container): bool
	{
		$settings = (array) ($container['settings'] ?? array());

		foreach (array('background_color', 'background_image', 'background_background', 'background_color_b', 'border_border', 'box_shadow_box_shadow_type') as $key) {
			if (!empty($settings[$key])) {
				return true;
			}
		}

		foreach (array('padding', 'padding_top', 'padding_bottom', 'padding_left', 'padding_right') as $key) {
			if ($this->positive_size($settings[$key] ?? null)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $container Container element.
	 */
	private function has_layout_controls(array $container): bool
	{
		$settings = (array) ($container['settings'] ?? array());

		if ($this->positive_size($settings['flex_gap'] ?? null)) {
			return true;
		}

		if (!empty($settings['position']) && 'default' !== (string) $settings['position']) {
			return true;
		}

		$width = $settings['width'] ?? null;
		if (is_array($width) && ($width['size'] ?? 0) > 0) {
			return true;
		}

		if ($this->positive_size($settings['max_width'] ?? null)
			|| $this->positive_size($settings['min_width'] ?? null)
			|| $this->positive_size($settings['min_height'] ?? null)
			|| $this->positive_size($settings['max_height'] ?? null)) {
			return true;
		}

		if (!empty($settings['custom_css']) || 'grid' === ($settings['_h2e_display'] ?? '')) {
			return true;
		}

		return false;
	}

	/**
	 * Keep wrappers whose measured box differs from their only child.
	 *
	 * @param array<string,mixed> $container Container element.
	 */
	private function has_distinct_geometry(array $container): bool
	{
		$kids = (array) ($container['elements'] ?? array());
		if (1 !== count($kids)) {
			return false;
		}
		$parent_box = $container['settings']['_h2e_bbox'] ?? null;
		$child_box = $kids[0]['settings']['_h2e_bbox'] ?? null;
		if (!is_array($parent_box) || !is_array($child_box)) {
			return false;
		}
		$dw = abs((float) ($parent_box['width'] ?? 0) - (float) ($child_box['width'] ?? 0));
		$dh = abs((float) ($parent_box['height'] ?? 0) - (float) ($child_box['height'] ?? 0));
		return $dw > 8.0 || $dh > 8.0;
	}

	/**
	 * Sibling containers with different measured boxes are separate layout
	 * frames (e.g. stacked info-blocks) — never flatten them together.
	 *
	 * @param array<string,mixed> $a First container.
	 * @param array<string,mixed> $b Second container.
	 */
	private function has_distinct_sibling_geometry(array $a, array $b): bool
	{
		$ba = $a['settings']['_h2e_bbox'] ?? null;
		$bb = $b['settings']['_h2e_bbox'] ?? null;
		if (!is_array($ba) || !is_array($bb)) {
			return false;
		}
		$dy = abs((float) ($ba['y'] ?? 0) - (float) ($bb['y'] ?? 0));
		$dx = abs((float) ($ba['x'] ?? 0) - (float) ($bb['x'] ?? 0));
		$dh = abs((float) ($ba['height'] ?? 0) - (float) ($bb['height'] ?? 0));
		$dw = abs((float) ($ba['width'] ?? 0) - (float) ($bb['width'] ?? 0));
		return $dy > 8.0 || $dx > 8.0 || $dh > 8.0 || $dw > 24.0;
	}

	/**
	 * @param array<string,mixed> $container Container element.
	 */
	private function has_responsive_overrides(array $container): bool
	{
		$settings = (array) ($container['settings'] ?? array());
		foreach (array_keys($settings) as $key) {
			if (preg_match('/_(tablet|mobile)$/', (string) $key)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $value Size control value.
	 */
	private function positive_size(mixed $value): bool
	{
		if (is_array($value)) {
			if (((float) ($value['size'] ?? 0)) > 0) {
				return true;
			}
			// Elementor padding/margin/gap use top/right/bottom/left or column/row.
			foreach (array('top', 'right', 'bottom', 'left', 'column', 'row') as $side) {
				if (isset($value[$side]) && is_numeric($value[$side]) && (float) $value[$side] > 0) {
					return true;
				}
			}
			return false;
		}

		return is_numeric($value) && (float) $value > 0;
	}

	/**
	 * Walk containers and set content_width=full + width=100% at depths 2–10.
	 *
	 * @param array<int,array<string,mixed>> $elements         Elements.
	 * @param int                            $depth            Container depth (1 = section root).
	 * @param string                         $parent_direction Parent flex direction.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_nested_full_widths(array $elements, int $depth, string $parent_direction): array
	{
		foreach ($elements as $i => $element) {
			if (!is_array($element)) {
				continue;
			}

			$is_container = 'container' === ($element['elType'] ?? '');
			if ($is_container) {
				$settings = (array) ($element['settings'] ?? array());
				$settings['content_width'] = 'full';

				if ($depth >= 2 && $depth <= 10 && 'row' !== $parent_direction
					&& $this->should_force_full_percent_width($settings)) {
					$settings['width'] = array(
						'unit' => '%',
						'size' => 100,
					);
				}

				$element['settings'] = $settings;
				$child_direction = strtolower(trim((string) ($settings['flex_direction'] ?? 'column')));
				if ('' === $child_direction) {
					$child_direction = 'column';
				}
				$element['elements'] = $this->apply_nested_full_widths(
					(array) ($element['elements'] ?? array()),
					$depth + 1,
					$child_direction
				);
				$elements[$i] = $element;
				continue;
			}

			if (!empty($element['elements']) && is_array($element['elements'])) {
				$element['elements'] = $this->apply_nested_full_widths(
					$element['elements'],
					$depth,
					$parent_direction
				);
				$elements[$i] = $element;
			}
		}

		return $elements;
	}

	/**
	 * Whether this nested container should be forced to width:100%.
	 *
	 * Applies only to containers stacked inside a column-direction parent —
	 * Elementor nested containers otherwise default to width:100% there, so a
	 * stray measured percentage (e.g. 80% left over from an ancestor row) must
	 * be normalized back to full width. Row-direction parents are handled by
	 * the caller (their children keep the measured percentage share that
	 * lays them out side-by-side). Absolute/fixed layers and tiny intrinsic
	 * px chrome are also skipped.
	 *
	 * @param array<string,mixed> $settings Container settings.
	 */
	private function should_force_full_percent_width(array $settings): bool
	{
		$position = strtolower(trim((string) ($settings['position'] ?? '')));
		if (in_array($position, array('absolute', 'fixed'), true)) {
			return false;
		}

		$width = $settings['width'] ?? null;
		if (is_array($width)) {
			$unit = strtolower((string) ($width['unit'] ?? '%'));
			$size = (float) ($width['size'] ?? 0);
			// Keep intrinsic painted chrome (icons, marks, avatars).
			if ('px' === $unit && $size > 0 && $size <= 160) {
				return false;
			}
			// Already full width — nothing to do.
			if ('%' === $unit && 100.0 === $size) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function count_containers(array $elements): int
	{
		$count = 0;
		foreach ($elements as $element) {
			if ('container' === ($element['elType'] ?? '')) {
				++$count;
			}
			$count += $this->count_containers((array) ($element['elements'] ?? array()));
		}

		return $count;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param int                            $depth    Current depth.
	 * @param array<int,int>                 $depths   Collector.
	 */
	private function collect_container_depths(array $elements, int $depth, array &$depths): void
	{
		foreach ($elements as $element) {
			if ('container' !== ($element['elType'] ?? '')) {
				continue;
			}

			$depths[] = $depth;
			$this->collect_container_depths((array) ($element['elements'] ?? array()), $depth + 1, $depths);
		}
	}
}
