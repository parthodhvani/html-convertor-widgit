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

		// Infer stack-on-mobile for row layouts.
		$layout = (string) ($node['layoutType'] ?? '');
		if ('row' === $layout || 'grid' === $layout) {
			$constraints['mobile_stack'] = true;
		}

		if (!empty($constraints)) {
			$node['responsiveConstraints'] = $constraints;
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
	 * Compute style differences between desktop and a breakpoint.
	 *
	 * @param array<string,mixed> $desktop Desktop styles.
	 * @param array<string,mixed> $bp      Breakpoint styles.
	 * @return array<string,mixed>
	 */
	private function diff_constraints(array $desktop, array $bp): array
	{
		$diff = array();
		foreach (array('fs', 'ta', 'disp', 'fd', 'w', 'h', 'pt', 'pb', 'pl', 'pr') as $key) {
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
