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
 * Visual Reconstruction Orchestrator v4 — geometry-first pipeline with
 * LayoutGraphEmitter and GeometryComparator validation loop.
 */
final class VisualReconstructionOrchestrator
{

	private VisualExtractionEngine $extraction;
	private VisualTreeBuilder $visual_tree;
	private LayoutGraphEngine $layout_graph;
	private ConstraintLayoutSolver $constraint_solver;
	private SemanticComponentGraph $semantic_graph;
	private WhitespaceAnalyzer $whitespace;
	private AlignmentEngine $alignment;
	private WrapperEliminationEngine $wrapper_elimination;
	private SemanticComponentRecognizer $recognition;
	private DesignTokenExtractor $tokens;
	private NativeWidgetMapper $widget_mapper;
	private ResponsiveLayoutEngine $responsive;
	private MediaEngine $media;
	private CssMappingEngine $css;
	private AnimationEngine $animation;
	private VisualValidationEngine $validation;
	private GeometryComparator $geometry;
	private PixelRepairEngine $repair;
	private ImportQualityReport $quality;

	/** @var array<string,mixed> */
	private array $last_metadata = array();

	public function __construct(array $opts = array())
	{
		$threshold = (int) ($opts['confidence'] ?? 95);
		$max_repair = (int) ($opts['max_repair_iterations'] ?? 3);

		$this->extraction = new VisualExtractionEngine();
		$this->visual_tree = new VisualTreeBuilder();
		$this->layout_graph = new LayoutGraphEngine();
		$this->constraint_solver = new ConstraintLayoutSolver();
		$this->semantic_graph = new SemanticComponentGraph();
		$this->whitespace = new WhitespaceAnalyzer();
		$this->alignment = new AlignmentEngine();
		$this->wrapper_elimination = new WrapperEliminationEngine();
		$this->recognition = new SemanticComponentRecognizer(null, $threshold);
		$this->tokens = new DesignTokenExtractor();
		$this->widget_mapper = new NativeWidgetMapper();
		$this->responsive = new ResponsiveLayoutEngine();
		$this->media = new MediaEngine();
		$this->css = new CssMappingEngine();
		$this->animation = new AnimationEngine();
		$this->validation = new VisualValidationEngine($threshold, $max_repair);
		$this->geometry = new GeometryComparator();
		$this->repair = new PixelRepairEngine($max_repair);
		$this->quality = new ImportQualityReport();
	}

	/**
	 * Preprocess a Chromium layout document through the v3 pipeline.
	 *
	 * @param RenderResult        $result Chromium output.
	 * @param array<string,mixed> $opts   { confidence, breakpoints, max_repair_iterations }.
	 * @return array{result:RenderResult,tokens:array<string,mixed>,media:array<string,mixed>,engines:array<string,mixed>,recognition:SemanticComponentRecognizer,css:CssMappingEngine,widget_mapper:NativeWidgetMapper}
	 */
	public function prepare(RenderResult $result, array $opts = array()): array
	{
		if (isset($opts['confidence'])) {
			$this->recognition->set_threshold((int) $opts['confidence']);
		}

		$enriched = $this->extraction->enrich($result);
		$data = $enriched->to_array();
		$sections = $data['sections'] ?? array();

		// v3 geometry-first pipeline order.
		$sections = $this->visual_tree->build($sections);
		$sections = $this->layout_graph->build($sections);
		$sections = $this->constraint_solver->solve($sections);
		$sections = $this->semantic_graph->build($sections);
		$sections = $this->whitespace->analyze($sections);
		$sections = $this->alignment->apply($sections);
		$sections = $this->wrapper_elimination->process_sections($sections);
		$sections = $this->responsive->apply(
			$sections,
			(array) ($opts['breakpoints'] ?? $data['breakpoints'] ?? array())
		);
		$sections = $this->animation->annotate_sections($sections);

		$spacing_tokens = $this->constraint_solver->spacing_tokens();
		if (empty($spacing_tokens)) {
			$gaps = $this->whitespace->measured_gaps();
			if (!empty($gaps)) {
				ksort($gaps);
				$spacing_tokens = array('scale' => array_keys($gaps), 'base' => Geometry::median(array_map('floatval', array_keys($gaps))));
			}
		}

		$design_tokens = $this->tokens->extract($sections, $spacing_tokens);
		$this->css->set_tokens($design_tokens);
		$media = $this->media->collect($sections, $enriched->assets());

		$data['sections'] = $sections;
		$this->last_metadata = $this->engine_metadata();
		$data['engines'] = $this->last_metadata;

		return array(
			'result' => RenderResult::from_array($data),
			'tokens' => $design_tokens,
			'media' => $media,
			'engines' => $this->last_metadata,
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
	 * @return array{data:array<int,array<string,mixed>>,validation:array<string,mixed>,quality:array<string,mixed>,repaired:bool}
	 */
	public function validate(array $elementor_data, array $context): array
	{
		$validation = $this->validation->score($elementor_data, $context);

		$repair_result = $this->repair->repair($elementor_data, array_merge($context, array(
			'validation' => $validation,
		)));

		if ($repair_result['changed']) {
			$elementor_data = $repair_result['data'];
			$validation = $this->validation->score($elementor_data, $context);
			$validation['iterations'] = $repair_result['iterations'];
			$validation['repairs'] = $repair_result['repairs'];
			if (isset($repair_result['geometry_similarity'])) {
				$validation['geometry_similarity'] = $repair_result['geometry_similarity'];
			}
		}

		$validation['threshold'] = (int) ($context['threshold'] ?? 95);
		$validation['passed'] = ($validation['fidelity'] ?? 0) >= $validation['threshold'];

		$quality = $this->quality->build(
			$context['report'] ?? array(),
			$validation,
			array(
				'missing_assets' => $context['missing_assets'] ?? array(),
				'unsupported_css' => $context['unsupported_css'] ?? array(),
				'engines' => $context['engines'] ?? $this->last_metadata,
				'wrappers_eliminated' => (int) (($context['engines'] ?? array())['wrappers_eliminated'] ?? 0),
				'visual_restructured' => (int) (($context['engines'] ?? array())['visual_restructured'] ?? 0),
				'fallback_reasons' => $this->recognition->fallback_reasons(),
				'average_confidence' => $this->recognition->average_confidence(),
				'elementor_data' => $elementor_data,
				'import_duration_ms' => (int) ($context['import_duration_ms'] ?? 0),
				'container_compression' => $context['container_compression'] ?? array(),
			)
		);

		return array(
			'data' => $elementor_data,
			'validation' => $validation,
			'quality' => $quality,
			'repaired' => $repair_result['changed'],
		);
	}

	public function recognition(): SemanticComponentRecognizer
	{
		return $this->recognition;
	}

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
			'version' => 4,
			'pipeline' => array(
				$this->extraction->name(),
				$this->visual_tree->name(),
				$this->layout_graph->name(),
				$this->constraint_solver->name(),
				$this->semantic_graph->name(),
				$this->whitespace->name(),
				$this->alignment->name(),
				$this->wrapper_elimination->name(),
				$this->recognition->name(),
				$this->tokens->name(),
				$this->responsive->name(),
				'layout_graph_emitter',
				'container_tree_optimizer',
				$this->geometry->name(),
				$this->media->name(),
				$this->css->name(),
				$this->animation->name(),
				$this->validation->name(),
				$this->repair->name(),
			),
			'wrappers_eliminated' => $this->wrapper_elimination->eliminated_count(),
			'visual_restructured' => $this->visual_tree->restructured_count(),
			'layout_components' => array_merge(
				$this->layout_graph->detected_components(),
				$this->semantic_graph->detected_components()
			),
			'measured_gaps' => $this->whitespace->measured_gaps(),
		);
	}
}
