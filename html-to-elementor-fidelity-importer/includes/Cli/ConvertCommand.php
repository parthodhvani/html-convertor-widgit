<?php
/**
 * WP-CLI commands for the importer.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Cli;

use HtmlToElementor\Batch\BatchConverter;
use HtmlToElementor\Services\ConversionPipeline;
use HtmlToElementor\Services\UploadHandler;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * `wp h2e ...` commands.
 */
final class ConvertCommand
{

	/**
	 * Convert and import a single HTML file or ZIP package into an Elementor page.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Absolute path to an .html / .htm file or a .zip package.
	 *
	 * [--title=<title>]
	 * : Page title. Defaults to the rendered <title>.
	 *
	 * [--mode=<mode>]
	 * : Conversion mode: "preserve" (default) or "widgets".
	 *
	 * [--status=<status>]
	 * : Post status. Default: draft.
	 *
	 * [--no-import]
	 * : Only print the generated report; do not create a page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp h2e import /var/www/sample.html --title="Landing" --mode=preserve
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import(array $args, array $assoc_args): void
	{
		$path = $args[0] ?? '';
		if ('' === $path || !file_exists($path)) {
			\WP_CLI::error('File not found: ' . $path);
		}

		$uploads = new UploadHandler();
		$job = $uploads->create_job();
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		try {
			if ('zip' === $ext) {
				$stored = $uploads->store(
					array(
						'name' => basename($path),
						'tmp_name' => $path,
						'error' => UPLOAD_ERR_OK,
						'size' => filesize($path),
					),
					$job['dir']
				);
				$entry = $stored['entry'];
			} else {
				$entry = $job['dir'] . '/index.html';
				copy($path, $entry);
			}

			$overrides = array();
			if (isset($assoc_args['mode'])) {
				$overrides['conversion_mode'] = $assoc_args['mode'];
			}

			$pipeline = new ConversionPipeline();

			if (isset($assoc_args['no-import'])) {
				$converted = $pipeline->convert($entry, $job['dir'], $overrides);
				\WP_CLI::log(wp_json_encode($converted['report'], JSON_PRETTY_PRINT));
				\WP_CLI::success('Conversion complete (not imported).');
				return;
			}

			$result = $pipeline->convert_and_import(
				$entry,
				$job['dir'],
				array_merge(
					$overrides,
					array(
						'title' => $assoc_args['title'] ?? '',
						'status' => $assoc_args['status'] ?? 'draft',
					)
				)
			);
			\WP_CLI::log(wp_json_encode($result['report'], JSON_PRETTY_PRINT));
			\WP_CLI::success('Imported as page #' . $result['post_id']);
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
		}
	}

	/**
	 * Batch-convert every HTML file in a directory.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : Directory to scan recursively for HTML files.
	 *
	 * [--mode=<mode>]
	 * : Conversion mode: "preserve" (default) or "widgets".
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function batch(array $args, array $assoc_args): void
	{
		$dir = $args[0] ?? '';
		if ('' === $dir || !is_dir($dir)) {
			\WP_CLI::error('Directory not found: ' . $dir);
		}

		$overrides = array();
		if (isset($assoc_args['mode'])) {
			$overrides['conversion_mode'] = $assoc_args['mode'];
		}

		$results = (new BatchConverter())->run($dir, $overrides);
		foreach ($results as $r) {
			if ($r['success']) {
				\WP_CLI::log(sprintf('OK   %s -> #%d', $r['file'], $r['post_id']));
			} else {
				\WP_CLI::warning(sprintf('FAIL %s : %s', $r['file'], $r['error']));
			}
		}
		\WP_CLI::success(sprintf('Processed %d file(s).', count($results)));
	}
}
