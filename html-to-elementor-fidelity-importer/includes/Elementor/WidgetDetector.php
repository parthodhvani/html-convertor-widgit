<?php
/**
 * Conservative widget detection for the optional widget-conversion mode.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Inspects a chunk of HTML and, only when highly confident, proposes an
 * equivalent Elementor widget. Anything ambiguous returns null so the caller
 * keeps the original HTML (fidelity over widget purity).
 */
final class WidgetDetector
{

	/**
	 * @param int $min_confidence Minimum confidence (0-100) required to map.
	 */
	public function __construct(private int $min_confidence = 95)
	{
	}

	/**
	 * Attempt to detect a single widget represented by the given HTML.
	 *
	 * @param string $html Section / block HTML.
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}|null
	 */
	public function detect(string $html): ?array
	{
		$html = trim($html);
		if ('' === $html) {
			return null;
		}

		$dom = $this->load($html);
		if (null === $dom) {
			return null;
		}

		$body = $dom->getElementsByTagName('body')->item(0);
		if (!$body instanceof \DOMElement) {
			return null;
		}

		$elements = $this->significant_children($body);
		if (1 !== count($elements)) {
			// More than one meaningful element: not safe to map to a single widget.
			return null;
		}

		$node = $elements[0];
		$tag = strtolower($node->nodeName);

		$candidate = match (true) {
			in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true) => $this->heading($node, $tag),
			'p' === $tag => $this->text($node),
			'img' === $tag => $this->image($node),
			'button' === $tag => $this->button($node),
			'a' === $tag => $this->link_button($node),
			in_array($tag, array('ul', 'ol'), true) => $this->icon_list($node),
			'video' === $tag => $this->video($node),
			'hr' === $tag => $this->spacer($node),
			default => null,
		};

		if (null === $candidate) {
			return null;
		}
		if ($candidate['confidence'] < $this->min_confidence) {
			return null;
		}
		return $candidate;
	}

	/**
	 * Build an Elementor heading widget.
	 *
	 * @param \DOMElement $node Heading element.
	 * @param string      $tag  Tag name (h1..h6).
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}|null
	 */
	private function heading(\DOMElement $node, string $tag): ?array
	{
		$text = trim($node->textContent);
		if ('' === $text) {
			return null;
		}
		// Headings containing nested markup are risky to flatten.
		$confidence = $this->has_complex_children($node) ? 60 : 97;
		return array(
			'type' => 'heading',
			'confidence' => $confidence,
			'settings' => array(
				'title' => $text,
				'header_size' => $tag,
			),
		);
	}

	/**
	 * Build an Elementor text-editor widget.
	 *
	 * @param \DOMElement $node Paragraph element.
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}
	 */
	private function text(\DOMElement $node): array
	{
		$inner = $this->inner_html($node);
		$confidence = $this->has_block_children($node) ? 70 : 96;
		return array(
			'type' => 'text-editor',
			'confidence' => $confidence,
			'settings' => array(
				'editor' => '<p>' . $inner . '</p>',
			),
		);
	}

	/**
	 * Build an Elementor image widget.
	 *
	 * @param \DOMElement $node Image element.
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}|null
	 */
	private function image(\DOMElement $node): ?array
	{
		$src = $node->getAttribute('src');
		if ('' === $src) {
			return null;
		}
		return array(
			'type' => 'image',
			'confidence' => 96,
			'settings' => array(
				'image' => array(
					'url' => $src,
					'id' => '',
				),
				'image_size' => 'full',
				'alt' => $node->getAttribute('alt'),
			),
		);
	}

	/**
	 * Build an Elementor button widget from a <button>.
	 *
	 * @param \DOMElement $node Button element.
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}
	 */
	private function button(\DOMElement $node): array
	{
		return array(
			'type' => 'button',
			'confidence' => 96,
			'settings' => array(
				'text' => trim($node->textContent),
			),
		);
	}

	/**
	 * Build an Elementor button widget from an <a> only when it looks like a button.
	 *
	 * @param \DOMElement $node Anchor element.
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}|null
	 */
	private function link_button(\DOMElement $node): ?array
	{
		$text = trim($node->textContent);
		$class = strtolower($node->getAttribute('class'));
		$role = strtolower($node->getAttribute('role'));
		$looks_button = (false !== strpos($class, 'btn') || false !== strpos($class, 'button') || 'button' === $role);

		if ('' === $text || $this->has_complex_children($node) || !$looks_button) {
			return null;
		}
		return array(
			'type' => 'button',
			'confidence' => 95,
			'settings' => array(
				'text' => $text,
				'link' => array(
					'url' => $node->getAttribute('href'),
					'is_external' => '',
					'nofollow' => '',
				),
			),
		);
	}

	/**
	 * Build an Elementor icon-list widget from a simple list.
	 *
	 * @param \DOMElement $node List element.
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}|null
	 */
	private function icon_list(\DOMElement $node): ?array
	{
		$items = array();
		foreach ($node->childNodes as $child) {
			if (!$child instanceof \DOMElement || 'li' !== strtolower($child->nodeName)) {
				continue;
			}
			if ($this->has_complex_children($child)) {
				return null; // Nested markup inside list items is not safe to flatten.
			}
			$items[] = array(
				'text' => trim($child->textContent),
			);
		}
		if (empty($items)) {
			return null;
		}
		return array(
			'type' => 'icon-list',
			'confidence' => 95,
			'settings' => array(
				'icon_list' => $items,
			),
		);
	}

	/**
	 * Build an Elementor video widget from a simple <video>.
	 *
	 * @param \DOMElement $node Video element.
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}|null
	 */
	private function video(\DOMElement $node): ?array
	{
		$src = $node->getAttribute('src');
		if ('' === $src) {
			$source = $node->getElementsByTagName('source')->item(0);
			$src = $source instanceof \DOMElement ? $source->getAttribute('src') : '';
		}
		if ('' === $src) {
			return null;
		}
		return array(
			'type' => 'video',
			'confidence' => 95,
			'settings' => array(
				'video_type' => 'hosted',
				'hosted_url' => array('url' => $src),
			),
		);
	}

	/**
	 * Build an Elementor spacer widget from an <hr>.
	 *
	 * @param \DOMElement $node HR element.
	 * @return array{type:string,confidence:int,settings:array<string,mixed>}
	 */
	private function spacer(\DOMElement $node): array
	{
		return array(
			'type' => 'spacer',
			'confidence' => 95,
			'settings' => array(
				'space' => array(
					'unit' => 'px',
					'size' => 20,
				),
			),
		);
	}

	/**
	 * Load HTML into a DOMDocument, suppressing libxml warnings.
	 *
	 * @param string $html Markup.
	 * @return \DOMDocument|null
	 */
	private function load(string $html): ?\DOMDocument
	{
		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $loaded ? $dom : null;
	}

	/**
	 * Significant element children (ignoring whitespace text nodes and comments).
	 *
	 * @param \DOMElement $parent Parent element.
	 * @return array<int,\DOMElement>
	 */
	private function significant_children(\DOMElement $parent): array
	{
		$out = array();
		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMText && '' === trim($child->wholeText)) {
				continue;
			}
			if ($child instanceof \DOMComment) {
				continue;
			}
			if ($child instanceof \DOMElement) {
				$out[] = $child;
			} else {
				// Stray text content alongside elements: treat as non-mappable.
				$out[] = new \DOMElement('span');
			}
		}
		return $out;
	}

	/**
	 * Whether the node contains non-inline child elements.
	 *
	 * @param \DOMElement $node Node.
	 */
	private function has_block_children(\DOMElement $node): bool
	{
		$inline = array('a', 'b', 'strong', 'i', 'em', 'span', 'br', 'small', 'u', 'mark', 'code');
		foreach ($node->getElementsByTagName('*') as $el) {
			if (!in_array(strtolower($el->nodeName), $inline, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the node contains any child elements at all.
	 *
	 * @param \DOMElement $node Node.
	 */
	private function has_complex_children(\DOMElement $node): bool
	{
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Serialise the inner HTML of a node.
	 *
	 * @param \DOMElement $node Node.
	 */
	private function inner_html(\DOMElement $node): string
	{
		$html = '';
		foreach ($node->childNodes as $child) {
			$html .= $node->ownerDocument->saveHTML($child);
		}
		return $html;
	}
}
