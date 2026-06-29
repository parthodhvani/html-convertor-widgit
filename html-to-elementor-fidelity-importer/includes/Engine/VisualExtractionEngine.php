<?php
/**
 * Normalises Chromium visual extraction output into a canonical visual tree.
 *
 * DOM nodes become supporting metadata; bounding boxes, computed styles and
 * visual relationships are the primary source of truth.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

use HtmlToElementor\Services\RenderResult;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Chromium Visual Extraction Engine — enriches layout sections with visual
 * metadata (bbox, stacking, transforms, accessibility) before reconstruction.
 */
final class VisualExtractionEngine implements EngineInterface
{

	public function name(): string
	{
		return 'visual_extraction';
	}

	/**
	 * Enrich every section tree with visual metadata.
	 *
	 * @param RenderResult $result Chromium layout document.
	 * @return RenderResult Enriched copy.
	 */
	public function enrich(RenderResult $result): RenderResult
	{
		$data = $result->to_array();
		$sections = array();

		foreach ($result->sections() as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$section_origin = $this->section_origin($section);
				$this->enrich_node($tree, null, 0, $section_origin);
				$section['tree'] = $tree;
				$section['visual'] = $this->section_visual($section);
			}
			$sections[] = $section;
		}

		$data['sections'] = $sections;
		$data['visual_tree'] = true;

		return RenderResult::from_array($data);
	}

	/**
	 * Recursively attach visual metadata to a tree node.
	 *
	 * @param array<string,mixed>      $node           Node (by ref).
	 * @param array<string,mixed>|null $parent         Parent node.
	 * @param int                      $depth          Tree depth.
	 * @param array{x:float,y:float}   $section_origin Section-local origin.
	 */
	private function enrich_node(array &$node, ?array $parent, int $depth, array $section_origin): void
	{
		$s = $node['s'] ?? array();
		$bbox = $this->normalize_bbox($node, $section_origin);

		$node['bbox'] = $bbox;
		$node['visual'] = array(
			'bbox' => $bbox,
			'depth' => $depth,
			'stacking' => $this->stacking_context($s),
			'visible' => !$this->is_hidden($s),
			'role' => $node['role'] ?? $node['ariaRole'] ?? '',
			'dom_path' => $node['domPath'] ?? '',
			'xpath' => $node['xpath'] ?? '',
		);

		if (null !== $parent) {
			$node['visual']['parent_tag'] = (string) ($parent['tag'] ?? '');
			$node['visual']['parent_cls'] = (string) ($parent['cls'] ?? '');
		}

		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->enrich_node($child, $node, $depth + 1, $section_origin);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * Section-local coordinate origin for bbox normalization.
	 *
	 * @param array<string,mixed> $section Section.
	 * @return array{x:float,y:float}
	 */
	private function section_origin(array $section): array
	{
		$bbox = $section['bbox'] ?? array();
		return array(
			'x' => (float) ($bbox['x'] ?? 0),
			'y' => (float) ($bbox['y'] ?? 0),
		);
	}

	/**
	 * Normalize a node bbox to section-local coordinates.
	 *
	 * @param array<string,mixed>    $node           Node.
	 * @param array{x:float,y:float} $section_origin Origin.
	 * @return array{x:float,y:float,width:float,height:float}
	 */
	private function normalize_bbox(array $node, array $section_origin): array
	{
		$raw = $node['bbox'] ?? $this->bbox_from_styles($node['s'] ?? array());
		return array(
			'x' => max(0.0, (float) ($raw['x'] ?? 0) - $section_origin['x']),
			'y' => max(0.0, (float) ($raw['y'] ?? 0) - $section_origin['y']),
			'width' => (float) ($raw['width'] ?? 0),
			'height' => (float) ($raw['height'] ?? 0),
		);
	}

	/**
	 * Derive a bounding box from computed width/height when explicit bbox missing.
	 *
	 * @param array<string,mixed> $s Style set.
	 * @return array<string,float>
	 */
	private function bbox_from_styles(array $s): array
	{
		return array(
			'x' => 0.0,
			'y' => 0.0,
			'width' => (float) ($s['w'] ?? 0),
			'height' => (float) ($s['h'] ?? 0),
		);
	}

	/**
	 * @param array<string,mixed> $s Style set.
	 */
	private function stacking_context(array $s): array
	{
		$ctx = array();
		if (!empty($s['z'])) {
			$ctx['z_index'] = $s['z'];
		}
		if (!empty($s['pos']) && 'static' !== $s['pos']) {
			$ctx['position'] = $s['pos'];
		}
		if (!empty($s['op']) && (float) $s['op'] < 1) {
			$ctx['opacity'] = (float) $s['op'];
		}
		if (!empty($s['tf'])) {
			$ctx['transform'] = $s['tf'];
		}
		return $ctx;
	}

	/**
	 * @param array<string,mixed> $s Style set.
	 */
	private function is_hidden(array $s): bool
	{
		$disp = (string) ($s['disp'] ?? '');
		if ('none' === $disp) {
			return true;
		}
		if (!empty($s['vis']) && 'hidden' === $s['vis']) {
			return true;
		}
		$w = (float) ($s['w'] ?? 0);
		$h = (float) ($s['h'] ?? 0);
		return $w <= 0 && $h <= 0 && !empty($s['disp']) && 'inline' !== $disp;
	}

	/**
	 * Section-level visual summary.
	 *
	 * @param array<string,mixed> $section Section.
	 * @return array<string,mixed>
	 */
	private function section_visual(array $section): array
	{
		return array(
			'bbox' => $section['bbox'] ?? array(),
			'semantic' => (bool) ($section['semantic'] ?? false),
			'tag' => (string) ($section['tag'] ?? ''),
		);
	}
}
