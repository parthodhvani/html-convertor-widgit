<?php
/**
 * Infers responsive constraints from multi-breakpoint measurements.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Responsive Reconstruction Engine — renders at multiple breakpoints and
 * infers Elementor responsive controls instead of duplicating CSS.
 *
 * Stack-on-mobile is inferred from measured flex-direction / geometry, never
 * applied blindly to every row.
 */
final class ResponsiveReconstructionEngine implements EngineInterface
{

	/** @var array<string,int> */
	public const DEFAULT_BREAKPOINTS = array(
		'wide' => 1920,
		'desktop' => 1440,
		'laptop' => 1280,
		'tablet_landscape' => 1024,
		'tablet' => 768,
		'mobile_landscape' => 480,
		'mobile' => 375,
	);

	public function name(): string
	{
		return 'responsive_reconstruction';
	}

	/**
	 * Merge breakpoint config with defaults.
	 *
	 * @param array<string,int> $breakpoints User breakpoints.
	 * @return array<string,int>
	 */
	public function normalize_breakpoints(array $breakpoints): array
	{
		return array_merge(self::DEFAULT_BREAKPOINTS, $breakpoints);
	}

	/**
	 * Annotate section trees with inferred responsive behaviour.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @param array<string,int>              $breakpoints Breakpoint widths.
	 * @return array<int,array<string,mixed>>
	 */
	public function annotate(array $sections, array $breakpoints = array()): array
	{
		$bps = $this->normalize_breakpoints($breakpoints);
		$out = array();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->annotate_node($tree, $bps);
				$section['tree'] = $tree;
			}
			$section['responsive_constraints'] = $this->section_constraints($section, $bps);
			$out[] = $section;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 * @param array<string,int>   $bps  Breakpoints.
	 */
	private function annotate_node(array &$node, array $bps): void
	{
		$r = $node['r'] ?? array();
		$constraints = array();

		if (!empty($r['tablet'])) {
			$constraints['tablet'] = $this->diff_constraints($node['s'] ?? array(), $r['tablet']);
		}
		if (!empty($r['mobile'])) {
			$constraints['mobile'] = $this->diff_constraints($node['s'] ?? array(), $r['mobile']);
		}
		if (!empty($r['laptop'])) {
			$constraints['laptop'] = $this->diff_constraints($node['s'] ?? array(), $r['laptop']);
		}

		$is_row = $this->is_row_like($node);
		if ($is_row) {
			$constraints['mobile_stack'] = $this->should_stack($node, 'mobile');
			$constraints['tablet_stack'] = $this->should_stack($node, 'tablet');
		}

		if (!empty($constraints)) {
			$node['responsiveConstraints'] = array_merge(
				(array) ($node['responsiveConstraints'] ?? array()),
				$constraints
			);
		}

		// Propagate full-width-on-mobile to children of stacking rows.
		if (!empty($node['responsiveConstraints']['mobile_stack'])) {
			foreach ((array) ($node['children'] ?? array()) as $i => $child) {
				if (!is_array($child)) {
					continue;
				}
				$child['responsiveConstraints'] = array_merge(
					(array) ($child['responsiveConstraints'] ?? array()),
					array('full_width_mobile' => true)
				);
				$node['children'][$i] = $child;
			}
		}

		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->annotate_node($child, $bps);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function is_row_like(array $node): bool
	{
		$layout = (string) ($node['layoutType'] ?? '');
		if ('row' === $layout || 'grid' === $layout) {
			return true;
		}
		$dir = (string) ($node['layoutConstraint']['direction'] ?? '');
		if ('row' === $dir) {
			return true;
		}
		$fd = strtolower((string) ($node['s']['fd'] ?? ''));
		return false !== strpos($fd, 'row') && false === strpos($fd, 'column');
	}

	/**
	 * Decide whether a row should stack at a breakpoint.
	 *
	 * @param array<string,mixed> $node   Node.
	 * @param string              $device tablet|mobile.
	 */
	private function should_stack(array $node, string $device): bool
	{
		$role = strtolower((string) ($node['layoutRole'] ?? ''));
		// Toolbars / navs stay horizontal unless measurements say otherwise.
		if (in_array($role, array('horizontal_bar', 'footer_band'), true)) {
			$r = $node['r'][$device] ?? null;
			if (!is_array($r)) {
				return false;
			}
			$fd = strtolower((string) ($r['fd'] ?? ''));
			return false !== strpos($fd, 'column');
		}

		$r = $node['r'][$device] ?? null;
		if (is_array($r)) {
			$fd = strtolower((string) ($r['fd'] ?? ''));
			if (false !== strpos($fd, 'column')) {
				return true;
			}
			if (false !== strpos($fd, 'row')) {
				return false;
			}

			$disp = strtolower((string) ($r['disp'] ?? ''));
			if ('block' === $disp && 'mobile' === $device) {
				return true;
			}

			if ($this->children_stack_geometrically($node, $device)) {
				return true;
			}

			// Explicit measurement present but still row-like → keep row.
			return false;
		}

		// No breakpoint measurement: only stack equal multi-column card rows on mobile.
		if ('mobile' !== $device) {
			return false;
		}
		return $this->looks_equal_column_row($node);
	}

	/**
	 * @param array<string,mixed> $node   Parent.
	 * @param string              $device Breakpoint.
	 */
	private function children_stack_geometrically(array $node, string $device): bool
	{
		$children = array_values(array_filter(
			(array) ($node['children'] ?? array()),
			static fn($c) => is_array($c)
		));
		if (count($children) < 2) {
			return false;
		}

		$parent_mobile_w = (float) ($node['r'][$device]['w'] ?? $node['s']['w'] ?? 0);
		if ($parent_mobile_w <= 0) {
			return false;
		}

		$full = 0;
		foreach ($children as $child) {
			$cw = (float) ($child['r'][$device]['w'] ?? 0);
			if ($cw <= 0) {
				continue;
			}
			if ($cw / $parent_mobile_w >= 0.85) {
				++$full;
			}
		}

		return $full >= 2 && $full >= (int) ceil(count($children) * 0.6);
	}

	/**
	 * Equal-width multi-column rows (cards/features) typically stack on phones.
	 *
	 * @param array<string,mixed> $node Node.
	 */
	private function looks_equal_column_row(array $node): bool
	{
		$children = array_values(array_filter(
			(array) ($node['children'] ?? array()),
			static fn($c) => is_array($c)
		));
		$n = count($children);
		if ($n < 2 || $n > 4) {
			return false;
		}

		$widths = array();
		foreach ($children as $child) {
			$w = (float) ($child['s']['w'] ?? Geometry::bbox($child)['width']);
			if ($w > 0) {
				$widths[] = $w;
			}
		}
		if (count($widths) < 2) {
			return false;
		}

		$median = Geometry::median($widths);
		if ($median <= 0) {
			return false;
		}
		foreach ($widths as $w) {
			if (abs($w - $median) / $median > 0.2) {
				return false;
			}
		}

		// Require card-like chrome or equal height so nav rows are excluded.
		$cardish = 0;
		foreach ($children as $child) {
			$s = $child['s'] ?? array();
			if (!empty($s['bg']) || !empty($s['bdw']) || !empty($s['sh']) || !empty($s['br'])) {
				++$cardish;
			}
		}
		return $cardish >= max(1, (int) floor($n / 2));
	}

	/**
	 * Compute style differences between desktop and a breakpoint.
	 *
	 * @param array<string,mixed> $desktop Desktop styles.
	 * @param array<string,mixed> $bp      Breakpoint styles.
	 * @return array<string,mixed>
	 */
	private function diff_constraints(array $desktop, array $bp): array
	{
		$diff = array();
		foreach (array('fs', 'ta', 'disp', 'fd', 'w', 'h', 'pt', 'pb', 'pl', 'pr', 'jc', 'ai', 'gap') as $key) {
			$d = $desktop[$key] ?? null;
			$b = $bp[$key] ?? null;
			if (null !== $b && $b !== $d) {
				$diff[$key] = $b;
			}
		}
		return $diff;
	}

	/**
	 * @param array<string,mixed> $section Section.
	 * @param array<string,int>   $bps     Breakpoints.
	 * @return array<string,mixed>
	 */
	private function section_constraints(array $section, array $bps): array
	{
		$responsive = $section['responsive'] ?? array();
		return array(
			'breakpoints' => $bps,
			'has_tablet' => !empty($responsive['tablet']),
			'has_mobile' => !empty($responsive['mobile']),
		);
	}
}
