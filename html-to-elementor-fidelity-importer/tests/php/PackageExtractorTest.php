<?php
/**
 * ZIP package extraction + asset inventory (images for Media Library import).
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Services\PackageExtractor;
use PHPUnit\Framework\TestCase;

final class PackageExtractorTest extends TestCase
{

	private string $tmp = '';

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/h2e-pkg-' . uniqid('', true);
		mkdir($this->tmp, 0777, true);
	}

	protected function tearDown(): void
	{
		$this->rmTree($this->tmp);
	}

	public function test_extract_finds_index_and_inventories_images(): void
	{
		if (!class_exists('\ZipArchive')) {
			$this->markTestSkipped('ZipArchive not available');
		}

		$src = $this->tmp . '/src';
		mkdir($src . '/assets/img', 0777, true);
		file_put_contents($src . '/index.html', '<html><body><img src="assets/img/a.png" /></body></html>');
		file_put_contents($src . '/assets/img/a.png', 'fake');
		file_put_contents($src . '/assets/img/b.jpg', 'fake');
		file_put_contents($src . '/assets/style.css', 'body{}');

		$zip_path = $this->tmp . '/site.zip';
		$zip = new \ZipArchive();
		$this->assertTrue(true === $zip->open($zip_path, \ZipArchive::CREATE));
		$zip->addFile($src . '/index.html', 'index.html');
		$zip->addFile($src . '/assets/img/a.png', 'assets/img/a.png');
		$zip->addFile($src . '/assets/img/b.jpg', 'assets/img/b.jpg');
		$zip->addFile($src . '/assets/style.css', 'assets/style.css');
		$zip->close();

		$dest = $this->tmp . '/out';
		mkdir($dest, 0777, true);
		$extractor = new PackageExtractor();
		$entry = $extractor->extract($zip_path, $dest);
		$this->assertFileExists($entry);
		$this->assertSame('index.html', basename($entry));

		$inv = $extractor->inventory($entry);
		$this->assertTrue($inv['has_local_assets']);
		$this->assertSame(2, $inv['images']);
		$this->assertSame(1, $inv['stylesheets']);
		$this->assertContains('assets/img/a.png', $inv['sample_images']);
	}

	public function test_inventory_html_only_has_no_assets(): void
	{
		$html = $this->tmp . '/lonely.html';
		file_put_contents($html, '<html><body>Hi</body></html>');
		$inv = (new PackageExtractor())->inventory($html);
		$this->assertFalse($inv['has_local_assets']);
		$this->assertSame(0, $inv['images']);
	}

	/**
	 * @param string $dir Directory.
	 */
	private function rmTree(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $file) {
			$path = $file->getPathname();
			$file->isDir() ? rmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
