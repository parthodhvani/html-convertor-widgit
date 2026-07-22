<?php
/**
 * Removes meaningless wrapper divs that exist only for DOM structure.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Wrapper Elimination Engine — collapses transparent single-child wrappers so
 * the layout graph reflects visual groups, not raw DOM hierarchy.
 */
final class WrapperEliminationEngine implements EngineInterface
{

	private int $eliminated = 0;

	public function name(): string
	{
		return 'wrapper_elimination';
	}

	/**
	 * @return int Wrappers removed during the last pass.
	 */
	public function eliminated_count(): int
	{
		return $this->eliminated;
	}

	/**
	 * Process all section trees in-place.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function process_sections(array $sections): array
	{
		$this->eliminated = 0;
		$out = array();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$section['tree'] = $this->collapse($tree);
			}
			$out[] = $section;
		}

		return $out;
	}

	/**
	 * Collapse a single tree node recursively.
	 *
	 * @param array<string,mixed> $node Tree root.
	 * @return array<string,mixed>
	 */
	public function collapse(array $node): array
	{
		$children = (array) ($node['children'] ?? array());
		$collapsed = array();

		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			$collapsed[] = $this->collapse($child);
		}

		$node['children'] = $collapsed;

		if ($this->is_meaningless_wrapper($node) && 1 === count($collapsed)) {
			++$this->eliminated;
			$child = $collapsed[0];
			// Preserve original identity on the promoted child.
			if (empty($child['id']) && !empty($node['id'])) {
				$child['id'] = $node['id'];
			}
			if (empty($child['cls']) && !empty($node['cls'])) {
				$child['cls'] = $node['cls'];
			}
			return $child;
		}

		return $node;
	}

	/**
	 * Whether a container is a transparent pass-through wrapper.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function is_meaningless_wrapper(array $node): bool
	{
		if (!empty($node['atomic'])) {
			return false;
		}
		$role = (string) ($node['layoutRole'] ?? '');
		if (in_array($role, array(
			'layered_block',
			'horizontal_bar',
			'footer_band',
			'card',
			'form_block',
			'column_group',
			'faq',
			'testimonial',
			'icon_box',
			'social_icons',
			'pricing',
			'cta_block',
		), true)) {
			return false;
		}
		if (VisualSignals::is_layered($node)) {
			return false;
		}
		$s = $node['s'] ?? array();
		$has_visual = !empty($s['bg']) || !empty($s['bgImg']) || !empty($s['bdw']) || !empty($s['sh'])
			|| VisualSignals::has_clip_shape($s);
		if ($has_visual) {
			return false;
		}
		$pt = (float) ($s['pt'] ?? 0) + (float) ($s['pb'] ?? 0) + (float) ($s['pl'] ?? 0) + (float) ($s['pr'] ?? 0);
		if ($pt > 0) {
			return false;
		}
		// A `max-width` (or `width` + `margin:0 auto`) wrapper is providing a
		// deliberate "boxed content inside a full-bleed section" width
		// constraint even though it paints nothing — collapsing it would
		// destroy that relationship (the child would inherit the section's
		// full width instead of staying centered/measured).
		if (VisualSignals::has_width_constraint($s)) {
			return false;
		}
		$text = trim((string) ($node['text'] ?? ''));
		if ('' !== $text) {
			return false;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$pos = strtolower((string) ($child['s']['pos'] ?? ''));
			if (in_array($pos, array('absolute', 'fixed', 'sticky'), true)) {
				return false;
			}
		}
		return true;
	}
}
