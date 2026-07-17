<?php
/**
 * Inline SVG leaves must map to native Image widgets, not HTML fallback.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\VisualLeafClassifier;
use PHPUnit\Framework\TestCase;

final class SvgNativeImageTest extends TestCase
{
	public function test_inline_svg_becomes_image_widget(): void
	{
		$classifier = new VisualLeafClassifier();
		$node = array(
			'tag' => 'svg',
			'atomic' => true,
			'html' => '<svg width="28" height="22" viewBox="0 0 110 88" fill="none"><line x1="8" y1="14" x2="8" y2="74" stroke="#957d4a"/></svg>',
			's' => array('w' => 28, 'h' => 22),
			'children' => array(),
		);
		$out = $classifier->classify($node);
		$this->assertNotNull($out);
		$this->assertSame('widget', $out['kind'] ?? '');
		$this->assertSame('image', $out['type'] ?? '');
		$url = (string) ($out['settings']['image']['url'] ?? '');
		$this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
	}

	public function test_anchor_wrapped_svg_keeps_link(): void
	{
		$classifier = new VisualLeafClassifier();
		$node = array(
			'tag' => 'a',
			'atomic' => true,
			'href' => 'home.html',
			'html' => '<a href="home.html"><svg width="50" height="40" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg></a>',
			's' => array('w' => 50, 'h' => 40),
			'children' => array(),
		);
		$out = $classifier->classify($node);
		$this->assertSame('image', $out['type'] ?? '');
		$this->assertSame('custom', $out['settings']['link_to'] ?? '');
		$this->assertSame('home.html', $out['settings']['link']['url'] ?? '');
	}
}
