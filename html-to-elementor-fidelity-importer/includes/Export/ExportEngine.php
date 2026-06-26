<?php
/**
 * Exports an imported page's Elementor data as a portable JSON template.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Export;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Produces an Elementor-compatible template export from a page's stored data.
 */
final class ExportEngine
{

	/**
	 * Export a single page's Elementor data as a template array.
	 *
	 * @param int $post_id Page ID.
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the page has no Elementor data.
	 */
	public function export_page(int $post_id): array
	{
		$raw = get_post_meta($post_id, '_elementor_data', true);
		if (empty($raw)) {
			throw new \RuntimeException('Page has no Elementor data to export.');
		}
		$content = is_string($raw) ? json_decode($raw, true) : $raw;
		if (!is_array($content)) {
			throw new \RuntimeException('Stored Elementor data is invalid.');
		}

		return array(
			'version' => '0.4',
			'title' => get_the_title($post_id),
			'type' => 'page',
			'content' => $content,
			'page_settings' => array(),
			'metadata' => array(
				'exported_by' => 'html-to-elementor-fidelity-importer',
				'exported_at' => gmdate('c'),
				'source_post' => $post_id,
			),
		);
	}

	/**
	 * Export to a JSON file on disk.
	 *
	 * @param int    $post_id  Page ID.
	 * @param string $out_path Destination file path.
	 * @return string The written path.
	 */
	public function export_to_file(int $post_id, string $out_path): string
	{
		$payload = $this->export_page($post_id);
		file_put_contents($out_path, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $out_path;
	}
}
