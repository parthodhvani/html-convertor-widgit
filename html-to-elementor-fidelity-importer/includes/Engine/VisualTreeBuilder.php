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
				);
			}
			$out[] = $section;
		}

		return $out;
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
		$cls = strtolower((string) ($node['cls'] ?? ''));
		if (preg_match('/\b(hero|nav|card|grid|row|section|header|footer|cta|form)\b/', $cls)) {
			return false;
		}
		$s = $node['s'] ?? array();
		if (!empty($s['bg']) || !empty($s['bgImg']) || !empty($s['bdw'])) {
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

		if ($row_score > $col_score && $row_score >= 1) {
			foreach ($siblings as $i => $sib) {
				$siblings[$i]['visualSiblingLayout'] = 'row';
			}
		} elseif ($col_score >= 1) {
			foreach ($siblings as $i => $sib) {
				$siblings[$i]['visualSiblingLayout'] = 'column';
			}
		}

		return $siblings;
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
