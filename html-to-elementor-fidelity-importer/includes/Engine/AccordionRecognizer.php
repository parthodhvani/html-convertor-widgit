<?php
/**
 * Recognises accordion / FAQ / disclosure structures from the visual tree.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Accordion Recognizer — detects repeated title/content disclosure groups
 * (native `<details>`/`<summary>`, Bootstrap `.accordion`, generic `.faq`
 * blocks) and extracts them as `{title, content}` item pairs.
 *
 * The recognised group is reconstructed by the converter as a single native
 * Elementor `accordion` widget, eliminating the wrapper/item divs entirely
 * instead of mirroring the raw DOM.
 */
final class AccordionRecognizer implements EngineInterface
{

	/** Maximum wrapper levels to descend looking for the item group. */
	private const MAX_DESCENT = 3;

	public function name(): string
	{
		return 'accordion_recognizer';
	}

	/**
	 * Detect an accordion/FAQ group on a container node.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @return array{items:array<int,array{title:string,content:string}>}|null
	 *               Null when the node is not an accordion group.
	 */
	public function detect(array $node): ?array
	{
		return $this->detect_node($node, $this->is_hinted($node), 0);
	}

	/**
	 * @param array<string,mixed> $node    Tree node.
	 * @param bool                $hinted  Whether an ancestor hinted FAQ/accordion.
	 * @param int                 $depth   Current descent depth.
	 * @return array{items:array<int,array{title:string,content:string}>}|null
	 */
	private function detect_node(array $node, bool $hinted, int $depth): ?array
	{
		if ($depth > self::MAX_DESCENT) {
			return null;
		}

		$children = array();
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$children[] = $child;
			}
		}

		$hinted = $hinted || $this->is_hinted($node);

		if (count($children) >= 2) {
			// Pattern A: native <details>/<summary> disclosure widgets.
			$details_items = array();
			foreach ($children as $child) {
				if ('details' === strtolower((string) ($child['tag'] ?? ''))) {
					$pair = $this->details_pair($child);
					if (null !== $pair) {
						$details_items[] = $pair;
					}
				}
			}
			if (count($details_items) >= 2) {
				return array('items' => $details_items);
			}

			// Pattern B: hinted block with repeated title/content item pairs.
			if ($hinted) {
				$items = array();
				foreach ($children as $child) {
					if (!empty($child['atomic']) || !empty($child['atomicText'])) {
						continue;
					}
					$pair = $this->item_pair($child);
					if (null !== $pair) {
						$items[] = $pair;
					}
				}
				if (count($items) >= 2) {
					return array('items' => $items);
				}
			}
		}

		// Descend through a single structural wrapper (e.g. `.container`).
		$containers = array();
		foreach ($children as $child) {
			if (empty($child['atomic']) && !empty($child['children'])) {
				$containers[] = $child;
			}
		}
		if (1 === count($containers)) {
			return $this->detect_node($containers[0], $hinted, $depth + 1);
		}

		return null;
	}

	/**
	 * Whether a node's role/classes hint at an accordion or FAQ block.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function is_hinted(array $node): bool
	{
		if ('faq' === strtolower((string) ($node['layoutRole'] ?? ''))) {
			return true;
		}
		$cls = strtolower((string) ($node['cls'] ?? '') . ' ' . (string) ($node['id'] ?? ''));
		return (bool) preg_match('/\b(accordion|faqs?|disclosure)\b/', $cls);
	}

	/**
	 * Build a title/content pair from a `<details>` element.
	 *
	 * @param array<string,mixed> $details Details node.
	 * @return array{title:string,content:string}|null
	 */
	private function details_pair(array $details): ?array
	{
		$title = '';
		$content_parts = array();

		foreach ((array) ($details['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			if ('' === $title && 'summary' === strtolower((string) ($child['tag'] ?? ''))) {
				$title = $this->inner_text($child);
				continue;
			}
			$html = $this->content_html($child);
			if ('' !== $html) {
				$content_parts[] = $html;
			}
		}

		if ('' === $title) {
			$title = trim((string) ($details['text'] ?? ''));
		}

		$content = trim(implode('', $content_parts));
		if ('' === trim(wp_strip_all_tags($content))) {
			$text = trim((string) ($details['text'] ?? ''));
			$content = '' !== $text ? '<p>' . esc_html($text) . '</p>' : '';
		}

		if ('' === $title || '' === trim(wp_strip_all_tags($content))) {
			return null;
		}
		return array('title' => $title, 'content' => $content);
	}

	/**
	 * Build a title/content pair from a generic accordion/FAQ item.
	 *
	 * @param array<string,mixed> $item Item node.
	 * @return array{title:string,content:string}|null
	 */
	private function item_pair(array $item): ?array
	{
		$title_node = $this->find_first($item, function (array $n): bool {
			return $this->is_title_node($n);
		});
		$title = null !== $title_node ? $this->inner_text($title_node) : '';
		if ('' === $title) {
			$title = trim((string) ($item['text'] ?? ''));
		}
		if ('' === $title) {
			return null;
		}

		$content_node = $this->find_first($item, function (array $n) use ($title): bool {
			return $this->is_content_node($n, $title);
		});
		$content = null !== $content_node ? $this->content_html($content_node) : '';

		if ('' === trim(wp_strip_all_tags($content))) {
			$all = trim($this->collect_text($item));
			$rest = trim(str_replace($title, '', $all));
			$content = '' !== $rest ? '<p>' . esc_html($rest) . '</p>' : '';
		}

		if ('' === trim(wp_strip_all_tags($content))) {
			return null;
		}
		return array('title' => $title, 'content' => $content);
	}

	/**
	 * Whether a node is a plausible accordion title (header/summary/button).
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function is_title_node(array $node): bool
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$cls = strtolower((string) ($node['cls'] ?? ''));
		$is_titleish = in_array($tag, array('summary', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'dt'), true)
			|| (bool) preg_match('/\b(title|header|head|question|toggle|trigger|label|faq-q|accordion-button|accordion-header)\b/', $cls);
		return $is_titleish && '' !== $this->inner_text($node);
	}

	/**
	 * Whether a node is a plausible accordion content/body block.
	 *
	 * @param array<string,mixed> $node  Tree node.
	 * @param string              $title Already-extracted title text (to skip).
	 */
	private function is_content_node(array $node, string $title): bool
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$cls = strtolower((string) ($node['cls'] ?? ''));
		$is_contentish = in_array($tag, array('p', 'dd'), true)
			|| (bool) preg_match('/\b(body|content|answer|panel|desc|description|text|faq-a|accordion-body|accordion-collapse)\b/', $cls);
		if (!$is_contentish) {
			return false;
		}
		$text = $this->inner_text($node);
		if ('' === $text) {
			$text = trim(wp_strip_all_tags($this->content_html($node)));
		}
		return '' !== $text && $text !== $title;
	}

	/**
	 * Depth-first search for the first descendant (or self) matching predicate.
	 *
	 * @param array<string,mixed> $node      Tree node.
	 * @param callable            $predicate fn(array $node): bool.
	 * @return array<string,mixed>|null
	 */
	private function find_first(array $node, callable $predicate): ?array
	{
		if ($predicate($node)) {
			return $node;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$found = $this->find_first($child, $predicate);
			if (null !== $found) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Reconstruct an HTML fragment for an accordion content node.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function content_html(array $node): string
	{
		$html = trim((string) ($node['html'] ?? ''));
		if ('' !== $html) {
			return $html;
		}
		$text = trim((string) ($node['text'] ?? ''));
		if ('' !== $text) {
			return '<p>' . esc_html($text) . '</p>';
		}
		$out = '';
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$out .= $this->content_html($child);
			}
		}
		return $out;
	}

	/**
	 * Plain text of a node, falling back to stripped outer HTML.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function inner_text(array $node): string
	{
		$text = trim((string) ($node['text'] ?? ''));
		if ('' !== $text) {
			return $text;
		}
		return trim(wp_strip_all_tags((string) ($node['html'] ?? '')));
	}

	/**
	 * Recursively collect all text within a node subtree.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function collect_text(array $node): string
	{
		$parts = array();
		$text = trim((string) ($node['text'] ?? ''));
		if ('' !== $text) {
			$parts[] = $text;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$sub = $this->collect_text($child);
				if ('' !== $sub) {
					$parts[] = $sub;
				}
			}
		}
		if (empty($parts)) {
			return trim(wp_strip_all_tags((string) ($node['html'] ?? '')));
		}
		return trim(implode(' ', $parts));
	}
}
