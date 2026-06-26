<?php
/**
 * Converts CSS spacing into Figma-style layout constraints.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Constraint Layout Engine — never recreates CSS literally. Margins become gap,
 * repeated spacing becomes tokens, and padding maps to container padding.
 */
final class ConstraintLayoutEngine implements EngineInterface
{

	/** @var array<string,mixed> */
	private array $tokens = array();

	public function name(): string
	{
		return 'constraint_layout';
	}

	/**
	 * @return array<string,mixed> Spacing tokens detected in the last pass.
	 */
	public function spacing_tokens(): array
	{
		return $this->tokens;
	}

	/**
	 * Apply constraint inference to all section trees.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function apply(array $sections): array
	{
		$this->tokens = array();
		$spacing_values = array();
		$out = array();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->collect_spacing($tree, $spacing_values);
				$this->transform_node($tree);
				$section['tree'] = $tree;
			}
			$out[] = $section;
		}

		$this->tokens = $this->build_spacing_scale($spacing_values);
		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function transform_node(array &$node): void
	{
		$s = &$node['s'];
		if (!is_array($s)) {
			$s = array();
		}

		// Convert child margins into container gap when flex/grid.
		$disp = (string) ($s['disp'] ?? '');
		if (false !== strpos($disp, 'flex') || false !== strpos($disp, 'grid')) {
			$gap = $this->infer_gap_from_children((array) ($node['children'] ?? array()));
			if ($gap > 0 && empty($s['gap'])) {
				$s['gap'] = $gap . 'px';
				$s['_gap_inferred'] = true;
			}
		}

		// Repeated margin-bottom on stacked children → gap.
		if (empty($s['gap']) && $this->has_vertical_stack((array) ($node['children'] ?? array()))) {
			$gap = $this->infer_vertical_gap((array) ($node['children'] ?? array()));
			if ($gap > 0) {
				$s['gap'] = $gap . 'px';
				$s['_gap_inferred'] = true;
				$this->strip_child_margins($node);
			}
		}

		$node['constraints'] = array(
			'padding' => array(
				'top' => (float) ($s['pt'] ?? 0),
				'right' => (float) ($s['pr'] ?? 0),
				'bottom' => (float) ($s['pb'] ?? 0),
				'left' => (float) ($s['pl'] ?? 0),
			),
			'gap' => $this->parse_gap((string) ($s['gap'] ?? '')),
		);

		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->transform_node($child);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function strip_child_margins(array &$node): void
	{
		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			unset($child['s']['mb'], $child['s']['mt']);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $children Children.
	 */
	private function infer_gap_from_children(array $children): float
	{
		$gaps = array();
		for ($i = 1; $i < count($children); ++$i) {
			$prev = $children[$i - 1]['s'] ?? array();
			$mb = (float) ($prev['mb'] ?? 0);
			if ($mb > 0) {
				$gaps[] = $mb;
			}
		}
		if (empty($gaps)) {
			return 0.0;
		}
		sort($gaps);
		return $gaps[(int) floor(count($gaps) / 2)];
	}

	/**
	 * @param array<int,array<string,mixed>> $children Children.
	 */
	private function infer_vertical_gap(array $children): float
	{
		return $this->infer_gap_from_children($children);
	}

	/**
	 * @param array<int,array<string,mixed>> $children Children.
	 */
	private function has_vertical_stack(array $children): bool
	{
		return count($children) >= 2;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 * @param array<float,int>    $values Tally (by ref).
	 */
	private function collect_spacing(array $node, array &$values): void
	{
		$s = $node['s'] ?? array();
		foreach (array('pt', 'pr', 'pb', 'pl', 'mt', 'mr', 'mb', 'ml') as $key) {
			$v = (float) ($s[$key] ?? 0);
			if ($v > 0) {
				$values[(string) $v] = ($values[(string) $v] ?? 0) + 1;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$this->collect_spacing($child, $values);
			}
		}
	}

	/**
	 * @param array<string,int> $values Raw spacing tally.
	 * @return array<string,mixed>
	 */
	private function build_spacing_scale(array $values): array
	{
		if (empty($values)) {
			return array();
		}
		arsort($values);
		$scale = array_slice(array_map('floatval', array_keys($values)), 0, 8);
		sort($scale);
		return array(
			'scale' => $scale,
			'base' => $scale[(int) floor(count($scale) / 2)] ?? 16.0,
		);
	}

	/**
	 * @param string $gap CSS gap value.
	 */
	private function parse_gap(string $gap): float
	{
		if ('' === $gap) {
			return 0.0;
		}
		if (preg_match('/([\d.]+)/', $gap, $m)) {
			return (float) $m[1];
		}
		return 0.0;
	}
}
