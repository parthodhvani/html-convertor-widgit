<?php
/**
 * Plugin uninstall handler.
 *
 * @package HtmlToElementor
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('h2e_settings');

// Remove the private working directory.
$uploads = wp_upload_dir();
$dir = trailingslashit($uploads['basedir']) . 'h2e-imports';

if (is_dir($dir)) {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($it as $file) {
		if ($file->isDir()) {
			@rmdir($file->getPathname()); // phpcs:ignore
		} else {
			@unlink($file->getPathname()); // phpcs:ignore
		}
	}
	@rmdir($dir); // phpcs:ignore
}
