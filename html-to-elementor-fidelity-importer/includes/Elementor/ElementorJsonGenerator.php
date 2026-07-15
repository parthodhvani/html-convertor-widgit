<?php
/**
 * Converts a Chromium layout document into valid Elementor _elementor_data.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

use HtmlToElementor\Engine\VisualReconstructionOrchestrator;
use HtmlToElementor\Services\RenderResult;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The Elementor JSON generator.
 *
 * Always runs native-widget reconstruction (Visual Reconstruction Engine):
 * visual tree → layout graph → component recognition → native Elementor
 * containers/widgets, with iterative validation and repair.
 *
 * Legacy "preserve" (raw HTML passthrough) mode has been permanently removed.
 */
final class ElementorJsonGenerator
{

	private LayoutTreeConverter $converter;
	private DesignTokens $tokens;
	private VisualReconstructionOrchestrator $orchestrator;

	/** @var array<string,mixed> */
	private array $last_engines = array();

	public function __construct()
	{
		$this->converter = new LayoutTreeConverter();
		$this->tokens = new DesignTokens();
		$this->orchestrator = new VisualReconstructionOrchestrator();
	}

	/**
	 * Generate Elementor data plus a structured report and design tokens.
	 *
	 * @param RenderResult        $result Layout document.
	 * @param array<string,mixed> $opts   { confidence }. Mode is always native widgets.
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>,tokens:array<string,mixed>,assets:array<string,mixed>,validation?:array<string,mixed>,quality?:array<string,mixed>}
	 */
	public function generate(RenderResult $result, array $opts = array()): array
	{
		// Always native-widget-first; "preserve" mode was intentionally removed.
		return $this->generate_native($result, $opts);
	}

	/**
	 * Native reconstruction via Visual Reconstruction Engine v2.
	 *
	 * @param RenderResult        $result Layout document.
	 * @param array<string,mixed> $opts   Options.
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>,tokens:array<string,mixed>,assets:array<string,mixed>,validation:array<string,mixed>,quality:array<string,mixed>}
	 */
	private function generate_native(RenderResult $result, array $opts): array
	{
		$prepared = $this->orchestrator->prepare($result, $opts);
		$this->last_engines = $prepared['engines'];

		$this->converter->use_engines($prepared['recognition'], $prepared['css']);
		$this->converter->reset_stats();

		$elements = array();
		$sections = $prepared['result']->sections();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$container = $this->converter->convert_section($tree);
				if (null !== $container) {
					$elements[] = $container;
					continue;
				}
			}
			$elements[] = $this->section_html_fallback($section);
		}

		$optimizer = new ContainerTreeOptimizer();
		$elements = $optimizer->optimize($elements);
		$compression = array_merge(
			$optimizer->stats(),
			$optimizer->depth_metrics($elements)
		);

		$stats = $this->converter->stats();
		$tokens = $prepared['tokens'];

		$report = array(
			'mode' => 'widgets',
			'engine_version' => 4,
			'sections' => count($sections),
			'containers' => (int) $stats['containers'],
			'widgets' => (int) $stats['widgets'],
			'native_widgets' => (int) $stats['native_widgets'],
			'html_widgets' => (int) $stats['html_widgets'],
			'widget_breakdown' => $stats['widget_breakdown'],
			'components' => $stats['roles'],
			'engines' => $this->last_engines,
			'max_nesting_depth' => (int) ($compression['max_container_depth'] ?? 0),
			'html_fallback_reasons' => $stats['html_fallback_reasons'] ?? array(),
			'container_compression' => $compression,
		);

		$validated = $this->orchestrator->validate(
			$elements,
			array(
				'report' => $report,
				'sections' => $sections,
				'screenshots' => $result->screenshots(),
				'engines' => $this->last_engines,
				'container_compression' => $compression,
				'wrappers_eliminated' => (int) ($this->last_engines['wrappers_eliminated'] ?? 0),
				'compare' => $opts['compare'] ?? null,
				'threshold' => (int) ($opts['confidence'] ?? 95),
			)
		);

		$report['validation'] = $validated['validation'];
		$report['quality'] = $validated['quality'];

		return array(
			'data' => $validated['data'],
			'report' => $report,
			'tokens' => $tokens,
			'assets' => array_merge($result->assets(), array('media' => $prepared['media'])),
			'validation' => $validated['validation'],
			'quality' => $validated['quality'],
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
