<?php
/**
 * Local file:// / package path resolution for media sideload.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ImportEngine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MediaResolveLocalTest extends TestCase
{

	public function test_resolve_file_url_and_relative_under_base(): void
	{
		$tmp = sys_get_temp_dir() . '/h2e-media-' . uniqid('', true);
		mkdir($tmp . '/assets/img', 0777, true);
		$img = $tmp . '/assets/img/hero.png';
		file_put_contents($img, 'x');

		$engine = new ImportEngine();
		$m = new ReflectionMethod(ImportEngine::class, 'resolve_local');
		$m->setAccessible(true);

		$file_url = 'file://' . $img;
		$this->assertSame($img, $m->invoke($engine, $file_url, $tmp));

		$rel = $m->invoke($engine, 'assets/img/hero.png', $tmp);
		$this->assertSame(realpath($img), $rel);

		$this->assertNull($m->invoke($engine, 'assets/img/missing.png', $tmp));

		$this->rmTree($tmp);
	}

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
