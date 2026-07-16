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
			return ($context['is_first'] ?? false) || $h >= 320 ? 'hero' : 'layered_block';
		}
		if ($signals['is_layered'] && $h >= 120) {
			return 'layered_block';
		}
		// First tall section with large heading → hero even without absolute layers.
		if (($context['is_first'] ?? false) && $h >= 280 && $this->has_large_heading($node)) {
			return 'hero';
		}

		// Horizontal bar (navigation, toolbar) — wide, shallow row at page top.
		$row_layout = 'row' === ($constraint['direction'] ?? '') || 'row' === $layout;
		$wide = $box['width'] >= 600 || (float) ($node['s']['w'] ?? 0) >= 600 || ($context['is_first'] ?? false);
		$shallow = ($h > 0 && $h <= 120) || ($h <= 0 && ($context['is_first'] ?? false));
		if ($row_layout && $wide && $shallow) {
			if ($signals['atomic_child_count'] >= 1 || ($context['is_first'] ?? false)) {
				return ($context['is_first'] ?? false) ? 'header' : 'horizontal_bar';
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

		// Service / pricing cards.
		if (preg_match('/\b(service-card|pricing|price-table|icon-box|feature)\b/', $cls)
			|| (($signals['has_border'] || $signals['has_shadow']) && $this->has_icon_signal($node))) {
			if ($this->has_price_text($node)) {
				return 'pricing';
			}
			if ($this->has_icon_signal($node) || preg_match('/\b(service-card|icon-box|feature)\b/', $cls)) {
				return 'icon_box';
			}
		}

		// Card — bordered/shadow box among equal siblings.
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
		if ($this->looks_cta($node, $signals)) {
			return 'cta_block';
		}

		// Gallery / portfolio / logo cloud / team / stats / timeline (Phase 10).
		if ($this->looks_gallery($node, $constraint, $layout)) {
			return 'gallery';
		}
		if ($this->looks_logo_cloud($node, $constraint, $layout)) {
			return 'logo_cloud';
		}
		if ($this->looks_team($node, $constraint)) {
			return 'team';
		}
		if ($this->looks_stats($node, $constraint, $layout)) {
			return 'statistics';
		}
		if ($this->looks_timeline($node)) {
			return 'timeline';
		}
		if ($this->looks_contact($node, $signals)) {
			return 'contact';
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
			return 'stack';
		}

		return 'section';
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
	 * @param array<string,mixed> $node Node.
	 */
	private function has_large_heading(array $node): bool
	{
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$tag = strtolower((string) ($child['tag'] ?? ''));
			$fs = VisualSignals::font_size_px($child['s'] ?? array());
			if (in_array($tag, array('h1', 'h2'), true) || $fs >= 32) {
				return true;
			}
			if ($this->has_large_heading($child)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $node    Node.
	 * @param array<string,mixed> $signals Signals.
	 */
	private function looks_cta(array $node, array $signals): bool
	{
		$has_heading = $this->has_large_heading($node);
		$has_button = $this->ends_with_button($node) || $this->count_buttons($node) >= 1;
		$box = $signals['bbox'];
		return $has_heading && $has_button && $box['height'] >= 120 && $box['height'] <= 480
			&& ($signals['has_background'] || $signals['is_layered']);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function count_buttons(array $node): int
	{
		$n = 0;
		if (VisualSignals::looks_button($node)) {
			++$n;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$n += $this->count_buttons($child);
			}
		}
		return $n;
	}

	/**
	 * @param array<string,mixed> $node       Node.
	 * @param array<string,mixed> $constraint Constraint.
	 * @param string              $layout     Layout.
	 */
	private function looks_gallery(array $node, array $constraint, string $layout): bool
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 3) {
			return false;
		}
		$row = 'row' === ($constraint['direction'] ?? '') || 'grid' === $layout || 'row' === $layout;
		if (!$row) {
			return false;
		}
		$images = 0;
		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			if (!empty($child['src']) || 'img' === ($child['tag'] ?? '') || null !== VisualSignals::analyze($child)['image_child']) {
				++$images;
			}
		}
		return $images >= 3 && $images >= (int) ceil(count($children) * 0.6);
	}

	/**
	 * @param array<string,mixed> $node       Node.
	 * @param array<string,mixed> $constraint Constraint.
	 * @param string              $layout     Layout.
	 */
	private function looks_logo_cloud(array $node, array $constraint, string $layout): bool
	{
		if (!$this->looks_gallery($node, $constraint, $layout)) {
			return false;
		}
		$children = (array) ($node['children'] ?? array());
		$short = 0;
		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			$h = Geometry::bbox($child)['height'];
			if ($h > 0 && $h <= 80) {
				++$short;
			}
		}
		return $short >= 3;
	}

	/**
	 * @param array<string,mixed> $node       Node.
	 * @param array<string,mixed> $constraint Constraint.
	 */
	private function looks_team(array $node, array $constraint): bool
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 2 || empty($constraint['equal_width'])) {
			return false;
		}
		$with_image_and_name = 0;
		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			$has_img = null !== VisualSignals::analyze($child)['image_child'] || !empty($child['src']);
			$has_text = '' !== trim((string) ($child['text'] ?? ''));
			foreach ((array) ($child['children'] ?? array()) as $gc) {
				if (is_array($gc) && '' !== trim((string) ($gc['text'] ?? ''))) {
					$has_text = true;
				}
			}
			if ($has_img && $has_text) {
				++$with_image_and_name;
			}
		}
		return $with_image_and_name >= 2;
	}

	/**
	 * @param array<string,mixed> $node       Node.
	 * @param array<string,mixed> $constraint Constraint.
	 * @param string              $layout     Layout.
	 */
	private function looks_stats(array $node, array $constraint, string $layout): bool
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 2) {
			return false;
		}
		$row = 'row' === ($constraint['direction'] ?? '') || 'row' === $layout;
		if (!$row) {
			return false;
		}
		$numeric = 0;
		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			$text = trim((string) ($child['text'] ?? ''));
			foreach ((array) ($child['children'] ?? array()) as $gc) {
				if (is_array($gc)) {
					$text .= ' ' . trim((string) ($gc['text'] ?? ''));
				}
			}
			if (preg_match('/\d/', $text) && VisualSignals::font_size_px($child['s'] ?? array()) >= 22) {
				++$numeric;
			}
		}
		return $numeric >= 2;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function looks_timeline(array $node): bool
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 3) {
			return false;
		}
		$dated = 0;
		foreach ($children as $child) {
			if (!is_array($child)) {
				continue;
			}
			$text = (string) ($child['text'] ?? '') . ' ' . (string) ($child['cls'] ?? '');
			if (preg_match('/\b(20\d{2}|19\d{2}|timeline|step-\d)\b/i', $text)) {
				++$dated;
			}
		}
		return $dated >= 3;
	}

	/**
	 * @param array<string,mixed> $node    Node.
	 * @param array<string,mixed> $signals Signals.
	 */
	private function looks_contact(array $node, array $signals): bool
	{
		if ($signals['input_like_children'] >= 2) {
			return true;
		}
		$text = strtolower((string) ($node['text'] ?? '') . ' ' . (string) ($node['cls'] ?? ''));
		return (bool) preg_match('/\b(contact|adresse|address|email|telefon|phone|maps?)\b/', $text)
			&& ($signals['input_like_children'] >= 1 || false !== strpos($text, 'map'));
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
				$child_s = $child['s'] ?? array();
				$is_paint_layer = VisualSignals::has_background($child_s)
					|| !empty($child_s['bgGrad'])
					|| (!empty($child_s['bgImg']) && (bool) preg_match('/gradient\s*\(/i', (string) $child_s['bgImg']));
				// Full-bleed paint layers without text become the background when unset.
				if (!$has_text && $is_paint_layer) {
					$box = Geometry::bbox($child);
					$parent_box = Geometry::bbox($node);
					$covers = $parent_box['width'] > 0
						&& $box['width'] >= $parent_box['width'] * 0.85
						&& $box['height'] >= $parent_box['height'] * 0.85;
					if ($covers && null === $layers['background']) {
						$layers['background'] = $child;
						continue;
					}
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
