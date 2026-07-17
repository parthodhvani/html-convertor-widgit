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
 * Merges single-child wrappers, identical parent/child stacks, and adjacent
 * containers that share the same layout signature. Visual styling is preserved.
 */
final class ContainerTreeOptimizer
{

	private int $removed = 0;
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
		$this->containers_before = $this->count_containers($elements);

		$optimized = array();
		foreach ($elements as $element) {
			$next = $this->optimize_element($element);
			if (null !== $next) {
				$optimized[] = $next;
			}
		}

		$this->containers_after = $this->count_containers($optimized);
		return array_values($optimized);
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

		return $this->compress_container_chain($element);
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
			if ('container' !== ($child['elType'] ?? '') || !$this->is_redundant_container($container)) {
				break;
			}

			$container = $this->promote_child($container, $child);
			++$this->removed;
		}

		$children = (array) ($container['elements'] ?? array());
		if (1 === count($children) && 'container' === ($children[0]['elType'] ?? '')) {
			$child = $children[0];
			if ($this->layout_signatures_match($container, $child) && $this->is_redundant_container($container)) {
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
					|| !$this->layout_signatures_match($current, $candidate)) {
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

		if ($this->has_visual_styling($container)) {
			return false;
		}

		if ($this->has_layout_controls($container)) {
			return false;
		}

		if ($this->has_responsive_overrides($container)) {
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

		return false;
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
			return ((float) ($value['size'] ?? 0)) > 0;
		}

		return is_numeric($value) && (float) $value > 0;
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
