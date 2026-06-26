<?php
/**
 * Converts CSS animations and transitions into Elementor Motion Effects.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Animation Engine — maps fade, slide, scale, rotate, opacity and transform
 * animations to Elementor motion-effect controls when supported.
 */
final class AnimationEngine implements EngineInterface
{

	public function name(): string
	{
		return 'animation';
	}

	/**
	 * Extract motion effects from a node's computed styles.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @return array<string,mixed>
	 */
	public function extract(array $node): array
	{
		$s = $node['s'] ?? array();
		$effects = array();

		$transition = (string) ($s['transition'] ?? '');
		$animation = (string) ($s['animation'] ?? '');

		if ($transition && 'none' !== $transition) {
			$parsed = $this->parse_transition($transition);
			if (!empty($parsed)) {
				$effects['transition'] = $parsed;
			}
		}

		if ($animation && 'none' !== $animation) {
			$parsed = $this->parse_animation($animation);
			if (!empty($parsed)) {
				$effects['animation'] = $parsed;
			}
		}

		// Hover states captured by Chromium.
		$hover = $node['pseudo'] ?? $node['states'] ?? array();
		if (!empty($hover['hover'])) {
			$effects['hover'] = $this->hover_motion($hover['hover']);
		}

		return $this->to_elementor_motion($effects);
	}

	/**
	 * Annotate all nodes in section trees.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function annotate_sections(array $sections): array
	{
		$out = array();
		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->annotate_node($tree);
				$section['tree'] = $tree;
			}
			$out[] = $section;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function annotate_node(array &$node): void
	{
		$motion = $this->extract($node);
		if (!empty($motion)) {
			$node['motionEffects'] = $motion;
		}
		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->annotate_node($child);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param string $transition CSS transition value.
	 * @return array<string,mixed>
	 */
	private function parse_transition(string $transition): array
	{
		$out = array();
		if (preg_match('/([\d.]+)s/', $transition, $m)) {
			$out['duration'] = (float) $m[1];
		}
		if (false !== stripos($transition, 'opacity')) {
			$out['type'] = 'fade';
		}
		if (false !== stripos($transition, 'transform')) {
			$out['type'] = 'transform';
		}
		if (false !== stripos($transition, 'color')) {
			$out['type'] = 'color';
		}
		return $out;
	}

	/**
	 * @param string $animation CSS animation value.
	 * @return array<string,mixed>
	 */
	private function parse_animation(string $animation): array
	{
		$out = array();
		if (preg_match('/([\d.]+)s/', $animation, $m)) {
			$out['duration'] = (float) $m[1];
		}
		if (preg_match('/(fade|slide|scale|rotate|bounce|pulse)/i', $animation, $m)) {
			$out['type'] = strtolower($m[1]);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $hover Hover styles.
	 * @return array<string,mixed>
	 */
	private function hover_motion(array $hover): array
	{
		$out = array();
		if (!empty($hover['opacity'])) {
			$out['fade'] = true;
		}
		if (!empty($hover['transform'])) {
			$out['transform'] = (string) $hover['transform'];
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $effects Parsed effects.
	 * @return array<string,mixed>
	 */
	private function to_elementor_motion(array $effects): array
	{
		if (empty($effects)) {
			return array();
		}

		$elementor = array();
		$type = (string) ($effects['transition']['type'] ?? $effects['animation']['type'] ?? '');

		if ('fade' === $type) {
			$elementor['motion_fx_opacity_effect'] = 'yes';
		}
		if (in_array($type, array('slide', 'transform'), true)) {
			$elementor['motion_fx_translateY_effect'] = 'yes';
		}
		if ('scale' === $type) {
			$elementor['motion_fx_scale_effect'] = 'yes';
		}

		$duration = (float) ($effects['transition']['duration'] ?? $effects['animation']['duration'] ?? 0);
		if ($duration > 0) {
			$elementor['_h2e_motion_duration'] = $duration;
		}

		return $elementor;
	}
}
