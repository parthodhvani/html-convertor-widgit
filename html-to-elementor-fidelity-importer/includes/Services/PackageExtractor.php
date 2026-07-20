<?php
/**
 * Extracts ZIP packages / full website exports and locates the entry HTML file.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Services;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Safely unzips a package and finds the most likely entry point.
 */
final class PackageExtractor
{

	/**
	 * Extract a ZIP archive into the destination directory.
	 *
	 * @param string $zip_path Absolute path to the .zip file.
	 * @param string $dest_dir Destination directory.
	 * @return string Absolute path to the detected entry HTML file.
	 *
	 * @throws \RuntimeException When extraction fails or no HTML is found.
	 */
	public function extract(string $zip_path, string $dest_dir): string
	{
		$target = trailingslashit($dest_dir) . 'package';
		wp_mkdir_p($target);

		if (class_exists('\ZipArchive')) {
			$this->extract_with_ziparchive($zip_path, $target);
		} else {
			$this->extract_with_wp($zip_path, $target);
		}

		$entry = $this->find_entry($target);
		if (null === $entry) {
			throw new \RuntimeException('No HTML entry file found inside the package.');
		}
		return $entry;
	}

	/**
	 * Inventory local assets next to an extracted entry HTML file.
	 *
	 * Used by the admin UI / report so users know whether images will import.
	 *
	 * @param string $entry Absolute path to entry HTML.
	 * @return array{
	 *   entry:string,
	 *   entry_name:string,
	 *   has_local_assets:bool,
	 *   images:int,
	 *   stylesheets:int,
	 *   scripts:int,
	 *   other_files:int,
	 *   sample_images:array<int,string>
	 * }
	 */
	public function inventory(string $entry): array
	{
		$root = dirname($entry);
		$images = 0;
		$stylesheets = 0;
		$scripts = 0;
		$other = 0;
		$sample = array();

		if (!is_dir($root)) {
			return array(
				'entry' => $entry,
				'entry_name' => basename($entry),
				'has_local_assets' => false,
				'images' => 0,
				'stylesheets' => 0,
				'scripts' => 0,
				'other_files' => 0,
				'sample_images' => array(),
			);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
		);
		/** @var \SplFileInfo $file */
		foreach ($iterator as $file) {
			if (!$file->isFile()) {
				continue;
			}
			$path = $file->getPathname();
			if ($path === $entry) {
				continue;
			}
			$ext = strtolower($file->getExtension());
			$rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
			if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico', 'bmp'), true)) {
				++$images;
				if (count($sample) < 8) {
					$sample[] = $rel;
				}
			} elseif (in_array($ext, array('css'), true)) {
				++$stylesheets;
			} elseif (in_array($ext, array('js', 'mjs'), true)) {
				++$scripts;
			} elseif (!in_array($ext, array('html', 'htm'), true)) {
				++$other;
			}
		}

		return array(
			'entry' => $entry,
			'entry_name' => basename($entry),
			'has_local_assets' => ($images + $stylesheets + $scripts) > 0,
			'images' => $images,
			'stylesheets' => $stylesheets,
			'scripts' => $scripts,
			'other_files' => $other,
			'sample_images' => $sample,
		);
	}

	/**
	 * Extract using the native ZipArchive, guarding against path traversal (zip-slip).
	 *
	 * @param string $zip_path Source archive.
	 * @param string $target   Destination directory.
	 *
	 * @throws \RuntimeException On error.
	 */
	private function extract_with_ziparchive(string $zip_path, string $target): void
	{
		$zip = new \ZipArchive();
		if (true !== $zip->open($zip_path)) {
			throw new \RuntimeException('Could not open ZIP archive.');
		}

		$real_target = realpath($target);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (false === $name) {
				continue;
			}
			$dest = $this->safe_path($real_target, $name);
			if (null === $dest) {
				continue; // Skip entries that would escape the target dir.
			}
			if (str_ends_with($name, '/')) {
				wp_mkdir_p($dest);
				continue;
			}
			wp_mkdir_p(dirname($dest));
			$stream = $zip->getStream($name);
			if (false === $stream) {
				continue;
			}
			file_put_contents($dest, $stream); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if (is_resource($stream)) {
				fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$zip->close();
	}

	/**
	 * Fallback extraction using WordPress' bundled unzip_file().
	 *
	 * @param string $zip_path Source archive.
	 * @param string $target   Destination directory.
	 *
	 * @throws \RuntimeException On error.
	 */
	private function extract_with_wp(string $zip_path, string $target): void
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$result = unzip_file($zip_path, $target);
		if (is_wp_error($result)) {
			throw new \RuntimeException('ZIP extraction failed: ' . $result->get_error_message());
		}
	}

	/**
	 * Resolve a zip member to an absolute path that must stay within $base.
	 *
	 * @param string|false $base Resolved base directory.
	 * @param string       $name Archive member name.
	 * @return string|null Safe absolute path or null when traversal is detected.
	 */
	private function safe_path($base, string $name): ?string
	{
		if (false === $base) {
			return null;
		}
		$name = str_replace('\\', '/', $name);
		$name = ltrim($name, '/');
		$path = $base . DIRECTORY_SEPARATOR . $name;

		// Normalise without requiring the path to exist yet.
		$parts = array();
		foreach (explode('/', $name) as $segment) {
			if ('' === $segment || '.' === $segment) {
				continue;
			}
			if ('..' === $segment) {
				array_pop($parts);
				continue;
			}
			$parts[] = $segment;
		}
		$normalized = $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);

		if (0 !== strpos($normalized, $base)) {
			return null;
		}
		return $normalized;
	}

	/**
	 * Find the best entry HTML file: prefer index.html at the shallowest depth.
	 *
	 * @param string $dir Directory to scan.
	 * @return string|null Absolute path or null.
	 */
	private function find_entry(string $dir): ?string
	{
		$candidates = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);
		/** @var \SplFileInfo $file */
		foreach ($iterator as $file) {
			if (!$file->isFile()) {
				continue;
			}
			$ext = strtolower($file->getExtension());
			if (in_array($ext, array('html', 'htm'), true)) {
				$candidates[] = $file->getPathname();
			}
		}
		if (empty($candidates)) {
			return null;
		}

		usort(
			$candidates,
			static function (string $a, string $b): int {
				$a_index = ('index.html' === strtolower(basename($a))) ? 0 : 1;
				$b_index = ('index.html' === strtolower(basename($b))) ? 0 : 1;
				if ($a_index !== $b_index) {
					return $a_index <=> $b_index;
				}
				return substr_count($a, DIRECTORY_SEPARATOR) <=> substr_count($b, DIRECTORY_SEPARATOR);
			}
		);

		return $candidates[0];
	}
}
