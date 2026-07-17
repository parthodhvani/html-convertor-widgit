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
		$elements = $this->apply_page_canvas($elements, $result->page(), $sections);
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
				'page' => $result->page(),
				'title' => $result->title(),
			)
		);

		// Re-apply after validation repairs so Elementor always receives
		// Full Width + 100% on nested levels 2–7 where structure allows.
		$data = $optimizer->ensure_nested_full_widths($validated['data']);

		$report['validation'] = $validated['validation'];
		$report['quality'] = $validated['quality'];

		return array(
			'data' => $data,
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

	/**
	 * Paint the document canvas onto transparent top-level sections.
	 *
	 * Dark themes set colour on body while section backgrounds stay transparent.
	 * Elementor (and the closed-loop preview) default to a white canvas, which
	 * makes light text invisible and tanks screenshot fidelity.
	 *
	 * @param array<int,array<string,mixed>> $elements Generated sections.
	 * @param array<string,mixed>            $page     meta.page from Chromium.
	 * @param array<int,array<string,mixed>> $sections Source sections.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_page_canvas(array $elements, array $page, array $sections): array
	{
		$bg = $this->resolve_page_background($page, $sections);
		$color = trim((string) ($page['color'] ?? ''));
		if ('' === $bg && '' === $color) {
			return $elements;
		}

		foreach ($elements as $i => $el) {
			if (!is_array($el) || 'container' !== ($el['elType'] ?? '')) {
				continue;
			}
			$settings = (array) ($el['settings'] ?? array());
			$has_paint = !empty($settings['background_color'])
				|| !empty($settings['background_image']['url'])
				|| 'gradient' === ($settings['background_background'] ?? '');
			if (!$has_paint && '' !== $bg) {
				$settings['background_background'] = 'classic';
				$settings['background_color'] = $bg;
			}
			if ('' !== $color && empty($settings['text_color'])) {
				$settings['text_color'] = $color;
			}
			$elements[$i]['settings'] = $settings;
		}

		return $elements;
	}

	/**
	 * @param array<string,mixed>            $page     Page styles.
	 * @param array<int,array<string,mixed>> $sections Source sections.
	 */
	private function resolve_page_background(array $page, array $sections): string
	{
		$bg = trim((string) ($page['backgroundColor'] ?? $page['background_color'] ?? ''));
		if ('' !== $bg && !$this->is_transparent_color($bg)) {
			return $bg;
		}

		// Fallback: majority opaque section background (section-alt / footer).
		$counts = array();
		foreach ($sections as $section) {
			$styles = (array) ($section['styles'] ?? array());
			$candidate = (string) ($styles['backgroundColor'] ?? $section['background'] ?? '');
			if ($this->is_transparent_color($candidate)) {
				$tree_bg = (string) (($section['tree']['s']['bg'] ?? ''));
				$candidate = $tree_bg;
			}
			if ($this->is_transparent_color($candidate)) {
				continue;
			}
			$counts[$candidate] = ($counts[$candidate] ?? 0) + 1;
		}
		if (empty($counts)) {
			return '';
		}
		arsort($counts);
		return (string) array_key_first($counts);
	}

	private function is_transparent_color(string $color): bool
	{
		$color = strtolower(trim($color));
		if ('' === $color || 'transparent' === $color || 'none' === $color) {
			return true;
		}
		if (preg_match('/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)/', $color, $m)) {
			$alpha = isset($m[4]) ? (float) $m[4] : 1.0;
			return $alpha <= 0.01;
		}
		return false;
	}
}
