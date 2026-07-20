<?php
/**
 * Uploaded CSS is applied before Elementor; element CSS after.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Frontend\Frontend;
use PHPUnit\Framework\TestCase;

final class SourceCssOrderTest extends TestCase
{

	protected function setUp(): void
	{
		$GLOBALS['h2e_test_is_singular'] = true;
		$GLOBALS['h2e_test_queried_id'] = 42;
		$GLOBALS['h2e_test_enqueued_styles'] = array();
		$GLOBALS['h2e_test_post_meta'] = array(
			42 => array(
				'_h2e_imported' => 1,
				'_h2e_source_links' => array('https://fonts.example/css', 'https://cdn.example/style.css'),
				'_h2e_uploaded_css' => '.hero{color:red}',
				'_h2e_element_css' => '.elementor-element-abc{transform:none}',
				'_h2e_source_css' => ".hero{color:red}\n\n/* h2e element custom css */\n.elementor-element-abc{transform:none}",
			),
		);
	}

	public function test_enqueue_source_styles_registers_uploaded_links(): void
	{
		$frontend = new Frontend();
		$frontend->enqueue_source_styles();
		$this->assertCount(2, $GLOBALS['h2e_test_enqueued_styles']);
		$this->assertSame('https://fonts.example/css', $GLOBALS['h2e_test_enqueued_styles'][0][1]);
		$this->assertSame('https://cdn.example/style.css', $GLOBALS['h2e_test_enqueued_styles'][1][1]);
	}

	public function test_output_source_css_emits_uploaded_only(): void
	{
		$frontend = new Frontend();
		ob_start();
		$frontend->output_source_css();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString('id="h2e-source-css"', $html);
		$this->assertStringContainsString('.hero{color:red}', $html);
		$this->assertStringNotContainsString('elementor-element-abc', $html);
	}

	public function test_output_element_css_emits_generated_rules_late(): void
	{
		$frontend = new Frontend();
		ob_start();
		$frontend->output_element_css();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString('id="h2e-element-css"', $html);
		$this->assertStringContainsString('elementor-element-abc', $html);
		$this->assertStringNotContainsString('.hero{color:red}', $html);
	}
}
