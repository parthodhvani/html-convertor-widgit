<?php
/**
 * Collects and normalises media assets from the visual tree.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Media Engine — discovers images, SVG, GIF, WebP and background images for
 * import into the WordPress Media Library.
 */
final class MediaEngine implements EngineInterface
{

	/** @var array<string,array<string,mixed>> */
	private array $assets = array();

	public function name(): string
	{
		return 'media';
	}

	/**
	 * @return array<string,array<string,mixed>> Discovered assets from last scan.
	 */
	public function assets(): array
	{
		return $this->assets;
	}

	/**
	 * Scan sections for media URLs.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @param array<string,mixed>            $document_assets Document-level assets.
	 * @return array<string,array<string,mixed>>
	 */
	public function collect(array $sections, array $document_assets = array()): array
	{
		$this->assets = array();

		foreach ($sections as $section) {
			$this->walk($section['tree'] ?? null);
		}

		// Background images from computed styles.
		foreach ($sections as $section) {
			$this->collect_backgrounds($section['tree'] ?? null);
		}

		return array(
			'urls' => array_keys($this->assets),
			'items' => array_values($this->assets),
			'document' => $document_assets,
			'count' => count($this->assets),
		);
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function walk($node): void
	{
		if (!is_array($node)) {
			return;
		}

		$tag = strtolower((string) ($node['tag'] ?? ''));
		if ('img' === $tag) {
			$src = (string) ($node['src'] ?? '');
			if ($src) {
				$this->register($src, 'image', $node);
			}
		}
		if ('svg' === $tag && !empty($node['html'])) {
			$this->register('data:image/svg+xml;inline', 'svg', $node);
		}
		if (!empty($node['lazy']) || !empty($node['loading'])) {
			$src = (string) ($node['dataSrc'] ?? $node['src'] ?? '');
			if ($src) {
				$this->register($src, 'lazy_image', $node);
			}
		}

		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->walk($child);
		}
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function collect_backgrounds($node): void
	{
		if (!is_array($node)) {
			return;
		}
		$s = $node['s'] ?? array();
		$bg = (string) ($s['bgImg'] ?? '');
		if ($bg && preg_match('/url\(["\']?([^"\')]+)["\']?\)/', $bg, $m)) {
			$this->register($m[1], 'background', $node);
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->collect_backgrounds($child);
		}
	}

	/**
	 * @param string              $url  Asset URL.
	 * @param string              $type Asset type.
	 * @param array<string,mixed> $node Source node.
	 */
	private function register(string $url, string $type, array $node): void
	{
		if (isset($this->assets[$url])) {
			return;
		}
		$this->assets[$url] = array(
			'url' => $url,
			'type' => $type,
			'format' => $this->detect_format($url),
			'alt' => (string) ($node['alt'] ?? ''),
			'node_tag' => (string) ($node['tag'] ?? ''),
		);
	}

	/**
	 * @param string $url Asset URL.
	 */
	private function detect_format(string $url): string
	{
		if (str_starts_with($url, 'data:image/svg')) {
			return 'svg';
		}
		if (preg_match('/\.(webp|gif|png|jpe?g|svg)(\?|$)/i', $url, $m)) {
			return strtolower($m[1]);
		}
		return 'unknown';
	}
}
