<?php
/**
 * Converts a Chromium layout document into valid Elementor _elementor_data.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

use HtmlToElementor\Services\RenderResult;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The Elementor JSON generator.
 *
 * Default ("native") mode rebuilds each section as nested Elementor containers
 * and native widgets via {@see LayoutTreeConverter}, mapping computed CSS to
 * Elementor controls and using HTML widgets only as a last resort.
 *
 * Legacy ("preserve") mode wraps each section's original HTML in a single HTML
 * widget for maximum raw fidelity.
 */
final class ElementorJsonGenerator
{

	private LayoutTreeConverter $converter;
	private DesignTokens $tokens;

	public function __construct()
	{
		$this->converter = new LayoutTreeConverter();
		$this->tokens = new DesignTokens();
	}

	/**
	 * Generate Elementor data plus a structured report and design tokens.
	 *
	 * @param RenderResult        $result Layout document.
	 * @param array<string,mixed> $opts   { mode: "native"|"preserve" }.
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>,tokens:array<string,mixed>,assets:array<string,mixed>}
	 */
	public function generate(RenderResult $result, array $opts = array()): array
	{
		$mode = (string) ($opts['mode'] ?? 'native');

		if ('preserve' === $mode) {
			return $this->generate_preserve($result);
		}

		return $this->generate_native($result);
	}

	/**
	 * Native reconstruction: nested containers + native widgets.
	 *
	 * @param RenderResult $result Layout document.
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>,tokens:array<string,mixed>,assets:array<string,mixed>}
	 */
	private function generate_native(RenderResult $result): array
	{
		$this->converter->reset_stats();
		$elements = array();
		$sections = $result->sections();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$container = $this->converter->convert_section($tree);
				if (null !== $container) {
					$elements[] = $container;
					continue;
				}
			}
			// Fallback: whole section as HTML when no usable tree was produced.
			$elements[] = $this->section_html_fallback($section);
		}

		$stats = $this->converter->stats();
		$tokens = $this->tokens->extract($sections);

		$report = array(
			'mode' => 'native',
			'sections' => count($sections),
			'containers' => (int) $stats['containers'],
			'widgets' => (int) $stats['widgets'],
			'native_widgets' => (int) $stats['native_widgets'],
			'html_widgets' => (int) $stats['html_widgets'],
			'widget_breakdown' => $stats['widget_breakdown'],
			'components' => $stats['roles'],
		);

		return array(
			'data' => $elements,
			'report' => $report,
			'tokens' => $tokens,
			'assets' => $result->assets(),
		);
	}

	/**
	 * Legacy preservation mode.
	 *
	 * @param RenderResult $result Layout document.
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>,tokens:array<string,mixed>,assets:array<string,mixed>}
	 */
	private function generate_preserve(RenderResult $result): array
	{
		$elements = array();
		$sections = $result->sections();

		foreach ($sections as $section) {
			$elements[] = $this->section_html_fallback($section);
		}

		$report = array(
			'mode' => 'preserve',
			'sections' => count($sections),
			'containers' => count($elements),
			'widgets' => count($elements),
			'native_widgets' => 0,
			'html_widgets' => count($elements),
			'widget_breakdown' => array('html' => count($elements)),
			'components' => array(),
		);

		return array(
			'data' => $elements,
			'report' => $report,
			'tokens' => $this->tokens->extract($sections),
			'assets' => $result->assets(),
		);
	}

	/**
	 * Build a container holding the section's original HTML in one HTML widget.
	 *
	 * @param array<string,mixed> $section Section data.
	 * @return array<string,mixed>
	 */
	private function section_html_fallback(array $section): array
	{
		$html = (string) ($section['html'] ?? '');
		return array(
			'id' => ElementId::generate(),
			'elType' => 'container',
			'settings' => array('content_width' => 'full', 'flex_direction' => 'column'),
			'elements' => array(
				array(
					'id' => ElementId::generate(),
					'elType' => 'widget',
					'widgetType' => 'html',
					'settings' => array('html' => $html),
					'elements' => array(),
				),
			),
			'isInner' => false,
		);
	}
}
