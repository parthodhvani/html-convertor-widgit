<?php
/**
 * Defaults that keep arbitrary (non-fixture) HTML convertible.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Support\Settings;
use PHPUnit\Framework\TestCase;

final class ArbitraryHtmlDefaultsTest extends TestCase
{

	public function test_default_wait_until_is_load_not_networkidle(): void
	{
		$defaults = Settings::defaults();
		$this->assertSame('load', $defaults['wait_until']);
	}
}
