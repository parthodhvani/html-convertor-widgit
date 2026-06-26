<?php
/**
 * Batch conversion of multiple HTML entries.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Batch;

use HtmlToElementor\Services\ConversionPipeline;
use HtmlToElementor\Services\UploadHandler;
use HtmlToElementor\Support\Logger;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Converts every HTML file found within a directory tree into its own page.
 */
final class BatchConverter
{

	public function __construct(
		private ?ConversionPipeline $pipeline = null,
		private ?UploadHandler $uploads = null
	) {
		$this->pipeline = $pipeline ?? new ConversionPipeline();
		$this->uploads = $uploads ?? new UploadHandler();
	}

	/**
	 * Convert and import every HTML file in a directory.
	 *
	 * @param string              $dir  Directory to scan recursively.
	 * @param array<string,mixed> $args Import args / overrides.
	 * @return array<int,array<string,mixed>> One result entry per file.
	 */
	public function run(string $dir, array $args = array()): array
	{
		$results = array();
		foreach ($this->html_files($dir) as $file) {
			$job = $this->uploads->create_job();
			try {
				$result = $this->pipeline->convert_and_import(
					$file,
					$job['dir'],
					array_merge($args, array('title' => $this->title_for($file, $args)))
				);
				$results[] = array(
					'file' => $file,
					'success' => true,
					'post_id' => $result['post_id'],
					'report' => $result['report'],
				);
			} catch (\Throwable $e) {
				Logger::error('Batch item failed', array('file' => $file, 'error' => $e->getMessage()));
				$results[] = array(
					'file' => $file,
					'success' => false,
					'error' => $e->getMessage(),
				);
			}
		}
		return $results;
	}

	/**
	 * Recursively list HTML files within a directory.
	 *
	 * @param string $dir Directory.
	 * @return array<int,string>
	 */
	private function html_files(string $dir): array
	{
		$out = array();
		if (!is_dir($dir)) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);
		/** @var \SplFileInfo $f */
		foreach ($it as $f) {
			if ($f->isFile() && in_array(strtolower($f->getExtension()), array('html', 'htm'), true)) {
				$out[] = $f->getPathname();
			}
		}
		sort($out);
		return $out;
	}

	/**
	 * Derive a page title from a file path.
	 *
	 * @param string              $file File path.
	 * @param array<string,mixed> $args Args (may contain title_prefix).
	 */
	private function title_for(string $file, array $args): string
	{
		$prefix = (string) ($args['title_prefix'] ?? '');
		$base = pathinfo($file, PATHINFO_FILENAME);
		return trim($prefix . ' ' . ucwords(str_replace(array('-', '_'), ' ', $base)));
	}
}
