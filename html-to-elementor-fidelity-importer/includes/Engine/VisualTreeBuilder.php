<?php
/**
 * Builds a visual tree from bounding boxes — not DOM hierarchy.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Visual Tree Builder — groups nodes by visual proximity, alignment and shared
 * backgrounds. The output tree represents rendered layout, not HTML structure.
 */
final class VisualTreeBuilder implements EngineInterface
{

	private int $restructured = 0;

	public function name(): string
	{
		return 'visual_tree_builder';
	}

	/**
	 * @return int Nodes restructured in the last pass.
	 */
	public function restructured_count(): int
	{
		return $this->restructured;
	}

	/**
	 * Rebuild section trees from visual geometry.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function build(array $sections): array
	{
		$this->restructured = 0;

		// Phase 8 — Chromium visual segmentation: merge sections that share a
		// visualGroup id before DOM-driven restructuring. Visual grouping wins.
		$sections = $this->merge_visual_sections($sections);

		$out = array();
		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->ensure_bbox($tree);
				$tree = $this->restructure($tree);
				$section['tree'] = $tree;
				$section['visual_tree'] = array(
					'root_bbox' => Geometry::bbox($tree),
					'restructured' => $this->restructured,
					'visual_group' => (string) ($section['visualGroup'] ?? ''),
				);
			}
			$out[] = $section;
		}

		return $out;
	}

	/**
	 * Merge consecutive Chromium sections that share a visualGroup id into one
	 * section whose tree children are the former section roots (column stack).
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	private function merge_visual_sections(array $sections): array
	{
		if (count($sections) < 2) {
			return $sections;
		}

		$merged = array();
		$i = 0;
		$count = count($sections);
		while ($i < $count) {
			$current = $sections[$i];
			$gid = trim((string) ($current['visualGroup'] ?? ''));
			if ('' === $gid) {
				$merged[] = $current;
				++$i;
				continue;
			}

			$group = array($current);
			$j = $i + 1;
			while ($j < $count) {
				$next_gid = trim((string) ($sections[$j]['visualGroup'] ?? ''));
				if ($next_gid !== $gid) {
					break;
				}
				$group[] = $sections[$j];
				++$j;
			}

			if (count($group) === 1) {
				$merged[] = $current;
				++$i;
				continue;
			}

			$merged[] = $this->compose_visual_section($group, $gid);
			$this->restructured += count($group) - 1;
			$i = $j;
		}

		// Re-index.
		foreach ($merged as $idx => $section) {
			$merged[$idx]['index'] = $idx;
		}

		return $merged;
	}

	/**
	 * @param array<int,array<string,mixed>> $group Sections in one visual group.
	 * @param string                         $gid   Group id.
	 * @return array<string,mixed>
	 */
	private function compose_visual_section(array $group, string $gid): array
	{
		$primary = $group[0];
		foreach ($group as $section) {
			if (!empty($section['visualSection'])) {
				$primary = $section;
				break;
			}
		}

		$children = array();
		$min_x = PHP_FLOAT_MAX;
		$min_y = PHP_FLOAT_MAX;
		$max_x = 0.0;
		$max_y = 0.0;
		foreach ($group as $section) {
			$tree = $section['tree'] ?? null;
			if (!is_array($tree)) {
				continue;
			}
			$box = Geometry::bbox($tree);
			$min_x = min($min_x, $box['x']);
			$min_y = min($min_y, $box['y']);
			$max_x = max($max_x, $box['x'] + $box['width']);
			$max_y = max($max_y, $box['y'] + $box['height']);
			$tree['visualGroupMember'] = $gid;
			$children[] = $tree;
		}

		$width = max(0.0, $max_x - $min_x);
		$height = max(0.0, $max_y - $min_y);
		$primary['tree'] = array(
			'tag' => 'div',
			'cls' => 'h2e-visual-section',
			'visualGroup' => 'column',
			'visualSectionRoot' => true,
			's' => array(
				'disp' => 'flex',
				'fd' => 'column',
				'w' => $width,
				'h' => $height,
			),
			'bbox' => array(
				'x' => $min_x === PHP_FLOAT_MAX ? 0.0 : $min_x,
				'y' => $min_y === PHP_FLOAT_MAX ? 0.0 : $min_y,
				'width' => $width,
				'height' => $height,
			),
			'children' => $children,
		);
		$primary['visualGroup'] = $gid;
		$primary['visualSection'] = true;
		$primary['bbox'] = $primary['tree']['bbox'];

		return $primary;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function ensure_bbox(array &$node): void
	{
		if (empty($node['bbox'])) {
			$node['bbox'] = Geometry::bbox($node);
		}
		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->ensure_bbox($child);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * Restructure node children based on visual layout.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @return array<string,mixed>
	 */
	private function restructure(array $node): array
	{
		$children = array();
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$children[] = $this->restructure($child);
		}

		// Promote single visual child through transparent wrappers.
		if ($this->is_visual_pass_through($node) && 1 === count($children)) {
			++$this->restructured;
			$promoted = $children[0];
			if (empty($promoted['cls']) && !empty($node['cls'])) {
				$promoted['cls'] = $node['cls'];
			}
			$promoted['visualPromoted'] = true;
			return $promoted;
		}

		// Infer visual grouping for siblings.
		if (count($children) >= 2) {
			$children = $this->group_siblings($children);
		}

		$node['children'] = $children;
		$node['visualGroup'] = $this->infer_visual_group($children);

		return $node;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function is_visual_pass_through(array $node): bool
	{
		if (!empty($node['atomic'])) {
			return false;
		}
		if (VisualSignals::is_layered($node)) {
			return false;
		}
		$s = $node['s'] ?? array();
		if (!empty($s['bg']) || !empty($s['bgImg']) || !empty($s['bdw']) || !empty($s['sh']) || !empty($s['filter']) || !empty($s['tf'])) {
			return false;
		}
		$pseudo = (array) ($node['pseudo'] ?? array());
		if (!empty($pseudo['before']) || !empty($pseudo['after'])) {
			return false;
		}
		$pad = (float) ($s['pt'] ?? 0) + (float) ($s['pb'] ?? 0) + (float) ($s['pl'] ?? 0) + (float) ($s['pr'] ?? 0);
		return $pad <= 0 && '' === trim((string) ($node['text'] ?? ''));
	}

	/**
	 * Group siblings that share a visual row or column.
	 *
	 * @param array<int,array<string,mixed>> $siblings Sibling nodes.
	 * @return array<int,array<string,mixed>>
	 */
	private function group_siblings(array $siblings): array
	{
		if (count($siblings) < 2) {
			return $siblings;
		}

		$boxes = array_map(array(Geometry::class, 'bbox'), $siblings);
		$row_score = 0;
		$col_score = 0;

		for ($i = 0; $i < count($boxes) - 1; ++$i) {
			if (Geometry::overlaps_y($boxes[$i], $boxes[$i + 1]) && Geometry::horizontal_gap($boxes[$i], $boxes[$i + 1]) > 0) {
				++$row_score;
			}
			if (Geometry::overlaps_x($boxes[$i], $boxes[$i + 1]) && Geometry::vertical_gap($boxes[$i], $boxes[$i + 1]) > 0) {
				++$col_score;
			}
		}

		$shared_bg = $this->shared_background($siblings);
		$shared_typography = $this->shared_typography($siblings);
		$similar_height = $this->shared_size_axis($boxes, 'height');
		$similar_width = $this->shared_size_axis($boxes, 'width');

		if (($row_score > $col_score && $row_score >= 1) || ($similar_height && $shared_bg)) {
			usort($siblings, function (array $a, array $b): int {
				return Geometry::bbox($a)['x'] <=> Geometry::bbox($b)['x'];
			});
			foreach ($siblings as $i => $sib) {
				$siblings[$i]['visualSiblingLayout'] = 'row';
			}
		} elseif ($col_score >= 1 || ($similar_width && $shared_typography)) {
			usort($siblings, function (array $a, array $b): int {
				return Geometry::bbox($a)['y'] <=> Geometry::bbox($b)['y'];
			});
			foreach ($siblings as $i => $sib) {
				$siblings[$i]['visualSiblingLayout'] = 'column';
			}
		}

		return $siblings;
	}

	/**
	 * @param array<int,array<string,mixed>> $siblings
	 */
	private function shared_background(array $siblings): bool
	{
		$colors = array();
		foreach ($siblings as $sibling) {
			$bg = strtolower(trim((string) ($sibling['s']['bg'] ?? '')));
			if ('' !== $bg) {
				$colors[] = $bg;
			}
		}
		return count($colors) >= 2 && count(array_unique($colors)) <= 2;
	}

	/**
	 * @param array<int,array<string,mixed>> $siblings
	 */
	private function shared_typography(array $siblings): bool
	{
		$fonts = array();
		foreach ($siblings as $sibling) {
			$fs = strtolower(trim((string) ($sibling['s']['fs'] ?? '')));
			if ('' !== $fs) {
				$fonts[] = $fs;
			}
		}
		return count($fonts) >= 2 && count(array_unique($fonts)) <= 2;
	}

	/**
	 * @param array<int,array{x:float,y:float,width:float,height:float}> $boxes
	 */
	private function shared_size_axis(array $boxes, string $axis): bool
	{
		$vals = array();
		foreach ($boxes as $box) {
			$vals[] = (float) ($box[$axis] ?? 0);
		}
		return Geometry::aligned($vals, 10.0);
	}

	/**
	 * @param array<int,array<string,mixed>> $children Child nodes.
	 * @return string
	 */
	private function infer_visual_group(array $children): string
	{
		if (count($children) < 2) {
			return 'single';
		}
		$layouts = array_column($children, 'visualSiblingLayout');
		if (in_array('row', $layouts, true)) {
			return 'row';
		}
		if (in_array('column', $layouts, true)) {
			return 'column';
		}
		return 'group';
	}
}
