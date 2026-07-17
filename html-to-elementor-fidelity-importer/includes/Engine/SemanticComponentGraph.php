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
		$cls = strtolower((string) ($node['cls'] ?? '') . ' ' . (string) ($node['id'] ?? ''));
		$tag = strtolower((string) ($node['tag'] ?? ''));

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

		// Footer band — last section OR explicit footer landmark.
		if ('footer' === $tag || preg_match('/\b(site-footer|footer)\b/', $cls)) {
			return 'footer_band';
		}
		if (($context['is_last'] ?? false) && $h >= 40 && $h <= 400) {
			return 'footer_band';
		}

		// Form block — never on landmarks; require form tag/class or many fields.
		if (!in_array($tag, array('header', 'footer', 'nav', 'section', 'main'), true)
			&& ($signals['input_like_children'] >= 2 || 'form' === $tag || preg_match('/\b(form|newsletter-form)\b/', $cls))) {
			return 'form_block';
		}

		// FAQ / accordion — repeated Q/A or class hints.
		if (preg_match('/\b(faq|accordion|disclosure)\b/', $cls) || $this->looks_faq($node, $layout)) {
			return 'faq';
		}

		// Testimonial / review card.
		if (preg_match('/\b(testimonial|review)\b/', $cls) || $this->looks_testimonial($node)) {
			return 'testimonial';
		}

		// Social icon row.
		if (preg_match('/\b(socials?|social-icons)\b/', $cls) || $this->looks_social_row($node, $constraint, $layout)) {
			return 'social_icons';
		}

		// Explicit price-table widgets only — marketing service cards stay as
		// structured `card` containers so IR children remain editable.
		if (preg_match('/\b(pricing|price-table|price-card)\b/', $cls) && $this->has_price_text($node)) {
			return 'pricing';
		}
		if (preg_match('/\b(icon-box|feature-box)\b/', $cls)) {
			return 'icon_box';
		}

		// Card — bordered/shadow box among equal siblings (incl. service-card).
		if (preg_match('/\b(service-card|feature-card)\b/', $cls)
			|| (($signals['has_border'] || $signals['has_shadow']) && $this->has_icon_signal($node))) {
			return 'card';
		}
		if (($signals['has_border'] || $signals['has_shadow']) && ($constraint['equal_width'] ?? false)) {
			return 'card';
		}
		if ($signals['has_border'] && $signals['has_padding'] && $box['width'] > 0 && $box['width'] < 600) {
			return 'card';
		}

		// CTA banner — only explicit CTA surfaces, not every button-ending column.
		if (preg_match('/\b(cta-banner|call-to-action)\b/', $cls)) {
			return 'cta_block';
		}

		// Grid / row of columns.
		if ('grid' === $layout || ('row' === ($constraint['direction'] ?? '') && ($constraint['equal_width'] ?? false))) {
			return 'column_group';
		}
		if ('row' === ($constraint['direction'] ?? '')) {
			return 'row_group';
		}

		// Media block — dominant image area.
		if (null !== $signals['image_child'] && $signals['image_child'] === $node) {
			return 'media_block';
		}

		if ('stack' === $layout || 'column' === ($constraint['direction'] ?? '')) {
			$children = (array) ($node['children'] ?? array());
			// Only annotate real multi-child stacks — not every column-ish node.
			if (count($children) >= 2) {
				return 'stack';
			}
			return '';
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $node   Node.
	 * @param string              $layout Layout type.
	 */
	private function looks_faq(array $node, string $layout): bool
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 2) {
			return false;
		}
		$q = 0;
		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			$cls = strtolower((string) ($child['cls'] ?? ''));
			$text = trim((string) ($child['text'] ?? ''));
			if (preg_match('/\b(faq-item|accordion-item)\b/', $cls) || str_ends_with($text, '?')
				|| preg_match('/\b(faq-q|accordion-header|accordion-button)\b/', $cls)) {
				++$q;
			}
		}
		return $q >= 2 && in_array($layout, array('stack', 'column', ''), true);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function looks_testimonial(array $node): bool
	{
		$has_stars = false;
		$has_quote = false;
		$has_who = false;
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$text = (string) ($child['text'] ?? '');
			$cls = strtolower((string) ($child['cls'] ?? ''));
			if (preg_match('/★|⭐|\bstars?\b/u', $text . ' ' . $cls)) {
				$has_stars = true;
			}
			if (false !== strpos($text, '„') || false !== strpos($text, '"') || 'blockquote' === ($child['tag'] ?? '')) {
				$has_quote = true;
			}
			if (preg_match('/\b(who|author|avatar|name)\b/', $cls)) {
				$has_who = true;
			}
		}
		return ($has_quote && ($has_stars || $has_who));
	}

	/**
	 * @param array<string,mixed> $node       Node.
	 * @param array<string,mixed> $constraint Constraint.
	 * @param string              $layout     Layout.
	 */
	private function looks_social_row(array $node, array $constraint, string $layout): bool
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 2) {
			return false;
		}
		$row = 'row' === ($constraint['direction'] ?? '') || 'row' === $layout
			|| preg_match('/\b(socials?|social-icons)\b/', strtolower((string) ($node['cls'] ?? '')));
		if (!$row && !preg_match('/\b(socials?|social-icons)\b/', strtolower((string) ($node['cls'] ?? '')))) {
			// Only infer from geometry when children are truly icon-only.
			$row = 'row' === ($constraint['direction'] ?? '') || 'row' === $layout;
		}
		if (!$row) {
			return false;
		}
		$icons = 0;
		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			// Service/feature/blog cards contain icons but are not social rows.
			if ($this->has_substantial_copy_for_social($child)) {
				return false;
			}
			$cls = (string) ($child['cls'] ?? '') . ' ' . (string) ($child['html'] ?? '');
			if (preg_match('/\b(fa-(?:solid|regular|brands)|fa[srlb]?)\s+fa-[\w-]+/i', $cls)
				&& '' === trim((string) ($child['text'] ?? ''))) {
				++$icons;
			}
		}
		return $icons >= 2 && $icons === count($children);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function has_substantial_copy_for_social(array $node): bool
	{
		$cls = strtolower((string) ($node['cls'] ?? ''));
		if (preg_match('/\b(card|service|feature|testimonial|blog|price|icon-box)\b/', $cls)) {
			return true;
		}
		$tag = strtolower((string) ($node['tag'] ?? ''));
		if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'), true)
			&& '' !== trim((string) ($node['text'] ?? ''))) {
			return true;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child) && $this->has_substantial_copy_for_social($child)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function looks_pricing_card(array $node): bool
	{
		return $this->has_price_text($node) && ('' !== $this->first_heading_text($node));
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function has_price_text(array $node): bool
	{
		$text = (string) ($node['text'] ?? '');
		$cls = strtolower((string) ($node['cls'] ?? ''));
		if (preg_match('/\bprice\b/', $cls) || preg_match('/(CHF|EUR|USD|\$|€|£)\s*[\d\'.,]+/i', $text)) {
			return true;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child) && $this->has_price_text($child)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function has_icon_signal(array $node): bool
	{
		$cls = (string) ($node['cls'] ?? '') . ' ' . (string) ($node['html'] ?? '');
		if (preg_match('/\b(fa-(?:solid|regular|brands)|fa[srlb]?)\s+fa-[\w-]+/i', $cls) || preg_match('/\b(card-icon|icon)\b/', strtolower($cls))) {
			return true;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child) && $this->has_icon_signal($child)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function first_heading_text(array $node): string
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$text = trim((string) ($node['text'] ?? ''));
		if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true) && '' !== $text) {
			return $text;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$found = $this->first_heading_text($child);
			if ('' !== $found) {
				return $found;
			}
		}
		return '';
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
