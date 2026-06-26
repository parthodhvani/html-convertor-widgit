<?php
/**
 * Coordinates all Visual Reconstruction Engine subsystems.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

use HtmlToElementor\Services\RenderResult;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Visual Reconstruction Orchestrator — the main entry point for the v2 pipeline:
 *
 *   Rendered Page → Visual Tree → Semantic Layout Graph → Component Recognition
 *   → Elementor Reconstruction → Pixel Validation → Automatic Repair
 */
final class VisualReconstructionOrchestrator
{

	private VisualExtractionEngine $extraction;
	private WrapperEliminationEngine $wrapper_elimination;
	private LayoutGraphEngine $layout_graph;
	private ConstraintLayoutEngine $constraints;
	private DesignTokenExtractor $tokens;
	private ComponentRecognitionEngine $recognition;
	private NativeWidgetMapper $widget_mapper;
	private ResponsiveReconstructionEngine $responsive;
	private MediaEngine $media;
	private CssMappingEngine $css;
	private AnimationEngine $animation;
	private VisualValidationEngine $validation;
	private ImportQualityReport $quality;

	public function __construct(array $opts = array())
	{
		$threshold = (int) ($opts['confidence'] ?? 95);
		$this->extraction = new VisualExtractionEngine();
		$this->wrapper_elimination = new WrapperEliminationEngine();
		$this->layout_graph = new LayoutGraphEngine();
		$this->constraints = new ConstraintLayoutEngine();
		$this->tokens = new DesignTokenExtractor();
		$this->recognition = new ComponentRecognitionEngine(null, $threshold);
		$this->widget_mapper = new NativeWidgetMapper();
		$this->responsive = new ResponsiveReconstructionEngine();
		$this->media = new MediaEngine();
		$this->css = new CssMappingEngine();
		$this->animation = new AnimationEngine();
		$this->validation = new VisualValidationEngine($threshold);
		$this->quality = new ImportQualityReport();
	}

	/**
	 * Preprocess a Chromium layout document through all extraction engines.
	 *
	 * @param RenderResult        $result Chromium output.
	 * @param array<string,mixed> $opts   { confidence, breakpoints }.
	 * @return array{result:RenderResult,tokens:array<string,mixed>,media:array<string,mixed>,engines:array<string,mixed>}
	 */
	public function prepare(RenderResult $result, array $opts = array()): array
	{
		if (isset($opts['confidence'])) {
			$this->recognition->set_threshold((int) $opts['confidence']);
		}

		$enriched = $this->extraction->enrich($result);
		$data = $enriched->to_array();
		$sections = $data['sections'] ?? array();

		$sections = $this->wrapper_elimination->process_sections($sections);
		$sections = $this->layout_graph->build($sections);
		$sections = $this->constraints->apply($sections);
		$sections = $this->responsive->annotate(
			$sections,
			(array) ($opts['breakpoints'] ?? $data['breakpoints'] ?? array())
		);
		$sections = $this->animation->annotate_sections($sections);

		$spacing_tokens = $this->constraints->spacing_tokens();
		$design_tokens = $this->tokens->extract($sections, $spacing_tokens);
		$this->css->set_tokens($design_tokens);
		$media = $this->media->collect($sections, $enriched->assets());

		$data['sections'] = $sections;
		$data['engines'] = $this->engine_metadata();

		return array(
			'result' => RenderResult::from_array($data),
			'tokens' => $design_tokens,
			'media' => $media,
			'engines' => $data['engines'],
			'recognition' => $this->recognition,
			'css' => $this->css,
			'widget_mapper' => $this->widget_mapper,
		);
	}

	/**
	 * Validate and repair generated Elementor data.
	 *
	 * @param array<int,array<string,mixed>> $elementor_data Generated elements.
	 * @param array<string,mixed>            $context        Pipeline context.
	 * @return array{data:array<int,array<string,mixed>>,validation:array<string,mixed>,quality:array<string,mixed>}
	 */
	public function validate(array $elementor_data, array $context): array
	{
		$result = $this->validation->validate_and_repair($elementor_data, $context);
		$quality = $this->quality->build(
			$context['report'] ?? array(),
			$result['validation'],
			array(
				'missing_assets' => $context['missing_assets'] ?? array(),
				'unsupported_css' => $context['unsupported_css'] ?? array(),
				'engines' => $context['engines'] ?? array(),
				'wrappers_eliminated' => $context['wrappers_eliminated'] ?? 0,
			)
		);

		return array(
			'data' => $result['data'],
			'validation' => $result['validation'],
			'quality' => $quality,
			'repaired' => $result['repaired'],
		);
	}

	/**
	 * Expose the component recognition engine for the tree converter.
	 */
	public function recognition(): ComponentRecognitionEngine
	{
		return $this->recognition;
	}

	/**
	 * Expose the CSS mapping engine for the tree converter.
	 */
	public function css(): CssMappingEngine
	{
		return $this->css;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function engine_metadata(): array
	{
		return array(
			'version' => 2,
			'pipeline' => array(
				$this->extraction->name(),
				$this->wrapper_elimination->name(),
				$this->layout_graph->name(),
				$this->constraints->name(),
				$this->tokens->name(),
				$this->recognition->name(),
				$this->widget_mapper->name(),
				$this->responsive->name(),
				$this->media->name(),
				$this->css->name(),
				$this->animation->name(),
				$this->validation->name(),
			),
			'wrappers_eliminated' => $this->wrapper_elimination->eliminated_count(),
			'layout_components' => $this->layout_graph->detected_components(),
		);
	}
}
