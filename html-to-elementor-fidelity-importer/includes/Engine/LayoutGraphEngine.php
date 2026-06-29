<?php
/**
 * Builds a semantic layout graph from the visual tree.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Layout Graph Engine — infers sections, rows, columns, stacks, heroes, nav,
 * cards and other visual regions using geometry, spacing and gestalt cues.
 */
final class LayoutGraphEngine implements EngineInterface
{

	/** @var array<string,int> */
	private array $detected = array();

	public function name(): string
	{
		return 'layout_graph';
	}

	/**
	 * @return array<string,int> Component counts from the last build.
	 */
	public function detected_components(): array
	{
		return $this->detected;
	}

	/**
	 * Annotate section trees with semantic layout roles.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function build(array $sections): array
	{
		$this->detected = array();
		$out = array();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->annotate($tree);
				$section['tree'] = $tree;
				$section['layout_graph'] = $this->graph_summary($tree);
			}
			$out[] = $section;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function annotate(array &$node): void
	{
		$role = $this->infer_role($node);
		if ('' !== $role) {
			$node['layoutRole'] = $role;
			$this->detected[$role] = ($this->detected[$role] ?? 0) + 1;
		}

		$layout = $this->infer_layout_type($node);
		if ('' !== $layout) {
			$node['layoutType'] = $layout;
		}

		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->annotate($child);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * Infer a semantic component role from visual + DOM cues.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function infer_role(array $node): string
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$cls = strtolower((string) ($node['cls'] ?? '') . ' ' . (string) ($node['id'] ?? ''));
		$children = (array) ($node['children'] ?? array());
		$layout = (string) ($node['layoutType'] ?? '');

		$geometric = $this->infer_role_from_geometry($node, $children, $layout);
		if ('' !== $geometric) {
			return $geometric;
		}

		if ('nav' === $tag || preg_match('/\b(nav|navbar|menu)\b/', $cls)) {
			return 'navigation';
		}
		if ('footer' === $tag || preg_match('/\bfooter\b/', $cls)) {
			return 'footer';
		}
		if ('header' === $tag) {
			return 'header';
		}
		if (preg_match('/\b(page-hero|hero|banner|masthead|jumbotron)\b/', $cls)) {
			return 'hero';
		}
		if (preg_match('/\b(cta|call-to-action|erstberatung)\b/', $cls)) {
			return 'cta';
		}
		if (preg_match('/\b(card|box|eb-box)\b/', $cls)) {
			return 'card';
		}
		if (preg_match('/\b(form|kontakt-form)\b/', $cls) || 'form' === $tag) {
			return 'form';
		}
		if (preg_match('/\b(gallery|carousel)\b/', $cls)) {
			return 'gallery';
		}
		if (preg_match('/\b(pricing|price-table)\b/', $cls)) {
			return 'pricing';
		}
		if (preg_match('/\b(testimonial|review)\b/', $cls)) {
			return 'testimonial';
		}
		if (preg_match('/\b(faq|accordion)\b/', $cls)) {
			return 'faq';
		}
		if (preg_match('/\b(map|anfahrt|map-placeholder)\b/', $cls)) {
			return 'media_block';
		}
		if (preg_match('/\b(sidebar|aside)\b/', $cls) || 'aside' === $tag) {
			return 'sidebar';
		}
		if (preg_match('/\b(info-block|feature|service)\b/', $cls)) {
			return 'content_group';
		}

		return '';
	}

	/**
	 * @param array<string,mixed>            $node
	 * @param array<int,array<string,mixed>> $children
	 */
	private function infer_role_from_geometry(array $node, array $children, string $layout): string
	{
		$count = count($children);
		$box = Geometry::bbox($node);
		$wide = $box['width'] >= 900;
		$tall = $box['height'] >= 360;

		if ($wide && $tall && $count >= 2 && $this->has_large_heading($node)) {
			return 'hero';
		}
		if (in_array($layout, array('row', 'grid'), true) && $count >= 3 && $this->cards_like_children($children)) {
			return 'feature_grid';
		}
		if ('row' === $layout && $count >= 4 && $this->mostly_images($children)) {
			return 'logo_cloud';
		}
		if ('row' === $layout && $count >= 2 && $this->contains_nav_signals($children)) {
			return 'navigation';
		}
		if (in_array($layout, array('row', 'grid'), true) && $count >= 2 && $this->contains_price_signals($children)) {
			return 'pricing';
		}
		if ('stack' === $layout && $count >= 3 && $this->contains_toggle_signals($children)) {
			return 'faq';
		}
		if ($count >= 3 && $this->contains_stat_signals($children)) {
			return 'statistics';
		}
		return '';
	}

	/**
	 * Infer structural layout type (row, column, stack, grid).
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function infer_layout_type(array $node): string
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 2) {
			return count($children) >= 1 ? 'stack' : '';
		}

		// Geometry-first inference from bounding boxes.
		$boxes = array_map(array(Geometry::class, 'bbox'), $children);
		$row_votes = 0;
		$col_votes = 0;
		for ($i = 0; $i < count($boxes) - 1; ++$i) {
			if (Geometry::overlaps_y($boxes[$i], $boxes[$i + 1]) && Geometry::horizontal_gap($boxes[$i], $boxes[$i + 1]) >= 0) {
				++$row_votes;
			}
			if (Geometry::vertical_gap($boxes[$i], $boxes[$i + 1]) > 4) {
				++$col_votes;
			}
		}
		if ($row_votes > $col_votes) {
			return 'row';
		}
		if ($col_votes > 0) {
			return 'stack';
		}

		$s = $node['s'] ?? array();
		$disp = (string) ($s['disp'] ?? '');

		if (false !== strpos($disp, 'grid')) {
			$cols = count($children);
			if ($cols >= 2) {
				return 'grid';
			}
		}
		if (false !== strpos($disp, 'flex')) {
			$fd = strtolower((string) ($s['fd'] ?? 'row'));
			if (false !== strpos($fd, 'column')) {
				return 'stack';
			}
			if (count($children) >= 2) {
				return 'row';
			}
		}

		// Infer row from side-by-side children with similar heights.
		if (count($children) >= 2 && $this->children_are_columns($children)) {
			return 'row';
		}

		if (count($children) >= 1) {
			return 'stack';
		}

		return '';
	}

	/**
	 * Whether children appear as horizontal columns (shared Y, distinct X).
	 *
	 * @param array<int,array<string,mixed>> $children Child nodes.
	 */
	private function children_are_columns(array $children): bool
	{
		$widths = array();
		foreach ($children as $child) {
			$w = (float) ($child['s']['w'] ?? 0);
			if ($w > 50) {
				$widths[] = $w;
			}
		}
		if (count($widths) < 2) {
			return false;
		}
		$avg = array_sum($widths) / count($widths);
		foreach ($widths as $w) {
			if (abs($w - $avg) / max(1, $avg) > 0.6) {
				return true;
			}
		}
		return count($widths) >= 2;
	}

	/**
	 * @param array<string,mixed> $node
	 */
	private function has_large_heading(array $node): bool
	{
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$tag = strtolower((string) ($child['tag'] ?? ''));
			if (preg_match('/^h[1-2]$/', $tag)) {
				$fs = (string) ($child['s']['fs'] ?? '');
				if ((float) $fs >= 34) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $children
	 */
	private function cards_like_children(array $children): bool
	{
		$cards = 0;
		foreach ($children as $child) {
			$s = (array) ($child['s'] ?? array());
			if (!empty($s['bg']) || !empty($s['bdw']) || !empty($s['sh']) || !empty($s['br'])) {
				++$cards;
			}
		}
		return $cards >= max(2, (int) floor(count($children) * 0.5));
	}

	/**
	 * @param array<int,array<string,mixed>> $children
	 */
	private function mostly_images(array $children): bool
	{
		$images = 0;
		foreach ($children as $child) {
			$tag = strtolower((string) ($child['tag'] ?? ''));
			if ('img' === $tag || !empty($child['src'])) {
				++$images;
			}
		}
		return $images >= max(2, (int) floor(count($children) * 0.6));
	}

	/**
	 * @param array<int,array<string,mixed>> $children
	 */
	private function contains_nav_signals(array $children): bool
	{
		foreach ($children as $child) {
			$tag = strtolower((string) ($child['tag'] ?? ''));
			if (in_array($tag, array('ul', 'ol', 'nav'), true)) {
				return true;
			}
			$text = strtolower(trim((string) ($child['text'] ?? '')));
			if (preg_match('/\b(home|about|contact|services|blog)\b/', $text)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $children
	 */
	private function contains_price_signals(array $children): bool
	{
		foreach ($children as $child) {
			$text = strtolower((string) ($child['text'] ?? ''));
			if (preg_match('/(\$|€|£)\s?\d+|\/mo|per month|monat/', $text)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $children
	 */
	private function contains_toggle_signals(array $children): bool
	{
		foreach ($children as $child) {
			$text = strtolower((string) ($child['text'] ?? ''));
			$cls = strtolower((string) ($child['cls'] ?? ''));
			if (preg_match('/\?$|faq|question|answer/', $text) || preg_match('/accordion|toggle/', $cls)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $children
	 */
	private function contains_stat_signals(array $children): bool
	{
		$hits = 0;
		foreach ($children as $child) {
			$text = (string) ($child['text'] ?? '');
			if (preg_match('/\d+(\+|%|k|m)?/i', $text)) {
				++$hits;
			}
		}
		return $hits >= 2;
	}

	/**
	 * @param array<string,mixed> $tree Section tree.
	 * @return array<string,mixed>
	 */
	private function graph_summary(array $tree): array
	{
		return array(
			'root_role' => (string) ($tree['layoutRole'] ?? ''),
			'root_layout' => (string) ($tree['layoutType'] ?? ''),
			'components' => $this->detected,
		);
	}
}
