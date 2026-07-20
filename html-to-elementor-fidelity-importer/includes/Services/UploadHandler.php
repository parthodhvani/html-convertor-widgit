<?php
/**
 * Handles validation and storage of uploaded HTML / ZIP packages.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Services;

use HtmlToElementor\Support\Logger;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Moves uploads into a per-job working directory and normalises the entry HTML file.
 */
final class UploadHandler
{

	private const ALLOWED_HTML = array('html', 'htm');
	private const ALLOWED_PKG = array('zip');

	/**
	 * Create a unique working directory for a conversion job.
	 *
	 * @return array{id:string,dir:string}
	 */
	public function create_job(): array
	{
		$id = gmdate('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 8);
		$dir = trailingslashit(Logger::work_dir()) . $id;
		wp_mkdir_p($dir);
		return array(
			'id' => $id,
			'dir' => $dir,
		);
	}

	/**
	 * Persist an uploaded file (from $_FILES) into the job directory.
	 *
	 * @param array{name:string,tmp_name:string,error:int,size:int} $file Single $_FILES entry.
	 * @param string                                                $dir  Job directory.
	 * @return array{type:string,path:string,entry:string,package:array<string,mixed>}
	 *
	 * @throws \RuntimeException When the file is invalid.
	 */
	public function store(array $file, string $dir): array
	{
		if (!isset($file['error']) || UPLOAD_ERR_OK !== (int) $file['error']) {
			throw new \RuntimeException('Upload failed with error code ' . ($file['error'] ?? 'unknown'));
		}

		$name = sanitize_file_name((string) $file['name']);
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

		if (in_array($ext, self::ALLOWED_HTML, true)) {
			$dest = trailingslashit($dir) . 'index.html';
			$this->move((string) $file['tmp_name'], $dest);
			$inventory = (new PackageExtractor())->inventory($dest);
			$inventory['source'] = 'html';
			$inventory['warning'] = $inventory['has_local_assets']
				? ''
				: 'HTML-only upload: relative images (assets/img/…) will not import. Prefer a ZIP that includes the assets folder.';
			return array(
				'type' => 'html',
				'path' => $dir,
				'entry' => $dest,
				'package' => $inventory,
			);
		}

		if (in_array($ext, self::ALLOWED_PKG, true)) {
			$zip_path = trailingslashit($dir) . 'package.zip';
			$this->move((string) $file['tmp_name'], $zip_path);
			$extractor = new PackageExtractor();
			$entry = $extractor->extract($zip_path, $dir);
			$inventory = $extractor->inventory($entry);
			$inventory['source'] = 'zip';
			$inventory['warning'] = $inventory['has_local_assets']
				? ''
				: 'ZIP extracted, but no local images/CSS/JS were found next to the HTML. Check that assets/ is inside the archive.';
			return array(
				'type' => 'package',
				'path' => $dir,
				'entry' => $entry,
				'package' => $inventory,
			);
		}

		throw new \RuntimeException('Unsupported file type: .' . $ext . ' — upload a .zip (recommended) or .html file.');
	}

	/**
	 * Store raw HTML pasted into the admin UI.
	 *
	 * @param string $html Raw HTML markup.
	 * @param string $dir  Job directory.
	 * @return array{type:string,path:string,entry:string,package:array<string,mixed>}
	 */
	public function store_raw_html(string $html, string $dir): array
	{
		$dest = trailingslashit($dir) . 'index.html';
		file_put_contents($dest, $html); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$inventory = (new PackageExtractor())->inventory($dest);
		$inventory['source'] = 'paste';
		$inventory['warning'] = 'Pasted HTML has no asset files. Relative images will not show — upload a ZIP with HTML + assets/ instead.';
		return array(
			'type' => 'html',
			'path' => $dir,
			'entry' => $dest,
			'package' => $inventory,
		);
	}

	/**
	 * Move an uploaded temp file, falling back to a copy when rename is not allowed.
	 *
	 * @param string $from Source temp path.
	 * @param string $to   Destination path.
	 *
	 * @throws \RuntimeException On failure.
	 */
	private function move(string $from, string $to): void
	{
		$ok = false;
		if (is_uploaded_file($from)) {
			$ok = move_uploaded_file($from, $to);
		}
		if (!$ok) {
			$ok = @copy($from, $to); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if (!$ok) {
			throw new \RuntimeException('Could not store uploaded file.');
		}
	}
}
