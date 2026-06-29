<?php
/**
 * Geometry-based semantic component graph.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Semantic Component Graph — infers visual roles from geometry, typography,
 * children and layout constraints. No HTML class names or tag names.
 */
final class SemanticComponentGraph implements EngineInterface
{

	/** @var array<string,int> */
	private array $detected = array();

	public function name(): string
	{
		return 'semantic_component_graph';
	}

	/**
	 * @return array<string,int>
	 */
	public function detected_components(): array
	{
		return $this->detected;
	}

	/**
	 * Annotate visual roles on section trees.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function build(array $sections): array
	{
		$this->detected = array();
		$out = array();
		$section_count = count($sections);

		foreach ($sections as $i => $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->annotate($tree, array(
					'index' => $i,
					'total' => $section_count,
					'is_first' => 0 === $i,
					'is_last' => ($i === $section_count - 1),
				));
				$section['tree'] = $tree;
			}
			$out[] = $section;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node    Node (by ref).
	 * @param array<string,mixed> $context Section context.
	 */
	private function annotate(array &$node, array $context): void
	{
		$signals = VisualSignals::analyze($node);
		$constraint = $node['layoutConstraint'] ?? array();
		$layout = (string) ($node['layoutType'] ?? '');

		$role = $this->infer_role($node, $signals, $constraint, $layout, $context);
		if ('' !== $role) {
			$node['layoutRole'] = $role;
			$node['semanticConfidence'] = $this->role_confidence($role, $signals, $constraint);
			$this->detected[$role] = ($this->detected[$role] ?? 0) + 1;
		}

		if ($signals['is_layered']) {
			$node['layeredLayout'] = $this->describe_layers($node);
		}

		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->annotate($child, $context);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param array<string,mixed> $node       Node.
	 * @param array<string,mixed> $signals    Visual signals.
	 * @param array<string,mixed> $constraint Layout constraint.
	 * @param string              $layout     Layout type.
	 * @param array<string,mixed> $context    Context.
	 */
	private function infer_role(array $node, array $signals, array $constraint, string $layout, array $context): string
	{
		$box = $signals['bbox'];
		$h = $box['height'];

		// Layered full-bleed block (hero, banner) — geometry only.
		if ($signals['is_layered'] && $h >= 180 && null !== $signals['image_child']) {
			return 'layered_block';
		}
		if ($signals['is_layered'] && $h >= 120) {
			return 'layered_block';
		}

		// Horizontal bar (navigation, toolbar) — wide, shallow row at page top.
		$row_layout = 'row' === ($constraint['direction'] ?? '') || 'row' === $layout;
		$wide = $box['width'] >= 600 || (float) ($node['s']['w'] ?? 0) >= 600 || ($context['is_first'] ?? false);
		$shallow = ($h > 0 && $h <= 120) || ($h <= 0 && ($context['is_first'] ?? false));
		if ($row_layout && $wide && $shallow) {
			if ($signals['atomic_child_count'] >= 1 || ($context['is_first'] ?? false)) {
				return 'horizontal_bar';
			}
		}

		// Footer band — last section, full width, moderate height.
		if (($context['is_last'] ?? false) && $h >= 40 && $h <= 250) {
			return 'footer_band';
		}

		// Form block — multiple input-like descendants.
		if ($signals['input_like_children'] >= 3) {
			return 'form_block';
		}

		// Card — bordered/shadow box among equal siblings.
		if (($signals['has_border'] || $signals['has_shadow']) && ($constraint['equal_width'] ?? false)) {
			return 'card';
		}
		if ($signals['has_border'] && $signals['has_padding'] && $box['width'] > 0 && $box['width'] < 600) {
			return 'card';
		}

		// Grid / row of columns.
		if ('grid' === $layout || ('row' === ($constraint['direction'] ?? '') && ($constraint['equal_width'] ?? false))) {
			return 'column_group';
		}
		if ('row' === ($constraint['direction'] ?? '')) {
			return 'row_group';
		}

		// CTA — vertical stack ending with button-like leaf.
		if ($this->ends_with_button($node)) {
			return 'cta_block';
		}

		// Media block — dominant image area.
		if (null !== $signals['image_child'] && $signals['image_child'] === $node) {
			return 'media_block';
		}

		if ('stack' === $layout || 'column' === ($constraint['direction'] ?? '')) {
			return 'stack';
		}

		return 'section';
	}

	/**
	 * @param string              $role       Role.
	 * @param array<string,mixed> $signals    Signals.
	 * @param array<string,mixed> $constraint Constraint.
	 */
	private function role_confidence(string $role, array $signals, array $constraint): int
	{
		$base = 65;
		if (!empty($constraint)) {
			$base += 10;
		}
		if ($signals['bbox']['width'] > 0) {
			$base += 5;
		}
		if ('layered_block' === $role && $signals['is_layered']) {
			$base += 15;
		}
		if ('form_block' === $role && $signals['input_like_children'] >= 4) {
			$base += 15;
		}
		return min(100, $base);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function ends_with_button(array $node): bool
	{
		$children = (array) ($node['children'] ?? array());
		if (empty($children)) {
			return false;
		}
		$last = $children[count($children) - 1];
		return VisualSignals::looks_button($last);
	}

	/**
	 * Describe layered children for the layout solver.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return array<string,mixed>
	 */
	private function describe_layers(array $node): array
	{
		$layers = array(
			'background' => null,
			'overlay' => null,
			'content' => array(),
			'in_flow' => array(),
		);

		foreach ((array) ($node['children'] ?? array()) as $child) {
			$pos = strtolower((string) ($child['s']['pos'] ?? ''));
			$signals = VisualSignals::analyze($child);

			if (null !== $signals['image_child'] || (!empty($child['src']) && 'absolute' !== $pos)) {
				if (null === $layers['background']) {
					$layers['background'] = $child;
					continue;
				}
			}

			if (in_array($pos, array('absolute', 'fixed'), true)) {
				$has_text = '' !== trim((string) ($child['text'] ?? '')) || !empty($child['children']);
				if (!$has_text && VisualSignals::has_background($child['s'] ?? array())) {
					$layers['overlay'] = $child;
					continue;
				}
				if ($has_text || !empty($child['children'])) {
					$layers['content'][] = $child;
					continue;
				}
				$layers['overlay'] = $layers['overlay'] ?? $child;
				continue;
			}

			$layers['in_flow'][] = $child;
		}

		return $layers;
	}
}
