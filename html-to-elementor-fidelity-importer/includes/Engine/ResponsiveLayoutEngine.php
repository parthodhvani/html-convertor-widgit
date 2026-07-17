<?php
/**
 * Responsive layout reconstruction from multi-breakpoint geometry.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Responsive Layout Engine — infers responsive constraints from breakpoint
 * measurements and generates Elementor responsive control hints.
 */
final class ResponsiveLayoutEngine implements EngineInterface
{

	private ResponsiveReconstructionEngine $inner;

	public function __construct()
	{
		$this->inner = new ResponsiveReconstructionEngine();
	}

	public function name(): string
	{
		return 'responsive_layout_engine';
	}

	/**
	 * @param array<string,int> $breakpoints Breakpoint widths.
	 * @return array<string,int>
	 */
	public function normalize_breakpoints(array $breakpoints): array
	{
		return $this->inner->normalize_breakpoints($breakpoints);
	}

	/**
	 * Annotate sections with responsive layout constraints.
	 *
	 * @param array<int,array<string,mixed>> $sections    Sections.
	 * @param array<string,int>              $breakpoints Breakpoints.
	 * @return array<int,array<string,mixed>>
	 */
	public function apply(array $sections, array $breakpoints = array()): array
	{
		$sections = $this->inner->annotate($sections, $breakpoints);
		$out = array();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->infer_stack_rules($tree);
				$section['tree'] = $tree;
			}
			$out[] = $section;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function infer_stack_rules(array &$node): void
	{
		$rc = $node['responsiveConstraints'] ?? array();
		$layout = array();

		if (!empty($rc['mobile_stack'])) {
			$layout['mobile'] = array(
				'flex_direction' => 'column',
				'width' => array('unit' => '%', 'size' => 100),
			);
		} elseif (!empty($rc['mobile']['fd'])) {
			$fd = strtolower((string) $rc['mobile']['fd']);
			$layout['mobile'] = array(
				'flex_direction' => (false !== strpos($fd, 'column')) ? 'column' : 'row',
			);
		}

		if (!empty($rc['tablet_stack'])) {
			$layout['tablet'] = array(
				'flex_direction' => 'column',
			);
		} elseif (!empty($rc['tablet']['fd'])) {
			$fd = strtolower((string) $rc['tablet']['fd']);
			$layout['tablet'] = array(
				'flex_direction' => (false !== strpos($fd, 'column')) ? 'column' : 'row',
			);
		}

		if (!empty($layout)) {
			$node['responsiveLayout'] = $layout;
		}

		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->infer_stack_rules($child);
			$node['children'][$i] = $child;
		}
	}
}
