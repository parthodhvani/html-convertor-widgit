<?php
/**
 * Value object wrapping the layout document produced by the Chromium service.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Services;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Immutable view over the JSON layout document returned by the Node renderer.
 */
final class RenderResult
{

	/**
	 * @param array<string,mixed> $data Decoded layout document.
	 */
	public function __construct(private array $data)
	{
	}

	/**
	 * Build from a decoded JSON array.
	 *
	 * @param array<string,mixed> $data Decoded layout document.
	 */
	public static function from_array(array $data): self
	{
		return new self($data);
	}

	/**
	 * Raw underlying array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array
	{
		return $this->data;
	}

	/**
	 * Page title detected by Chromium.
	 */
	public function title(): string
	{
		return (string) ($this->data['meta']['title'] ?? '');
	}

	/**
	 * Detected sections.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sections(): array
	{
		return is_array($this->data['sections'] ?? null) ? $this->data['sections'] : array();
	}

	/**
	 * Screenshot paths keyed by device.
	 *
	 * @return array<string,string>
	 */
	public function screenshots(): array
	{
		return is_array($this->data['screenshots'] ?? null) ? $this->data['screenshots'] : array();
	}

	/**
	 * Collected document-level CSS/JS asset references.
	 *
	 * @return array<string,mixed>
	 */
	public function assets(): array
	{
		return is_array($this->data['assets'] ?? null) ? $this->data['assets'] : array();
	}

	/**
	 * Document canvas styles (body/html) captured by Chromium.
	 *
	 * @return array<string,mixed>
	 */
	public function page(): array
	{
		$page = $this->data['meta']['page'] ?? ($this->data['assets']['page'] ?? null);
		return is_array($page) ? $page : array();
	}
}
