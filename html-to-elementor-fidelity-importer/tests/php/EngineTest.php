<?php
/**
 * Unit tests for Visual Reconstruction Engine v2 subsystems.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\AnimationEngine;
use HtmlToElementor\Engine\ComponentRecognitionEngine;
use HtmlToElementor\Engine\AlignmentEngine;
use HtmlToElementor\Engine\ConstraintLayoutSolver;
use HtmlToElementor\Engine\Geometry;
use HtmlToElementor\Engine\GeometryComparator;
use HtmlToElementor\Engine\LayeredLayoutSolver;
use HtmlToElementor\Engine\PixelRepairEngine;
use HtmlToElementor\Engine\SemanticComponentGraph;
use HtmlToElementor\Engine\SemanticComponentRecognizer;
use HtmlToElementor\Engine\VisualLeafClassifier;
use HtmlToElementor\Engine\VisualTreeBuilder;
use HtmlToElementor\Engine\WhitespaceAnalyzer;
use HtmlToElementor\Engine\DesignTokenExtractor;
use HtmlToElementor\Engine\LayoutGraphEngine;
use HtmlToElementor\Engine\MediaEngine;
use HtmlToElementor\Engine\NativeWidgetMapper;
use HtmlToElementor\Engine\ResponsiveReconstructionEngine;
use HtmlToElementor\Engine\VisualExtractionEngine;
use HtmlToElementor\Engine\VisualReconstructionOrchestrator;
use HtmlToElementor\Engine\VisualValidationEngine;
use HtmlToElementor\Engine\WrapperEliminationEngine;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{

	public function test_wrapper_elimination_collapses_transparent_div(): void
	{
		$engine = new WrapperEliminationEngine();
		$tree = array(
			'tag' => 'div',
			'cls' => '',
			's' => array('disp' => 'block'),
			'children' => array(
				array(
					'tag' => 'div',
					'cls' => 'card',
					's' => array('disp' => 'block', 'bg' => 'rgb(255,0,0)'),
					'children' => array(
						array('tag' => 'p', 'text' => 'Hi', 'atomic' => true, 's' => array()),
					),
				),
			),
		);
		$result = $engine->collapse($tree);
		$this->assertSame('card', $result['cls']);
		$this->assertSame(1, $engine->eliminated_count());
	}

	public function test_layout_graph_detects_row_and_stack(): void
	{
		$engine = new LayoutGraphEngine();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					's' => array('disp' => 'flex', 'fd' => 'row'),
					'children' => array(
						array('tag' => 'div', 's' => array('w' => 300, 'h' => 200), 'bbox' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 200), 'children' => array()),
						array('tag' => 'div', 's' => array('w' => 300, 'h' => 200), 'bbox' => array('x' => 320, 'y' => 0, 'width' => 300, 'height' => 200), 'children' => array()),
					),
				),
			),
			array(
				'tree' => array(
					'tag' => 'div',
					's' => array('disp' => 'block'),
					'children' => array(
						array('tag' => 'p', 'atomic' => true, 's' => array(), 'children' => array()),
						array('tag' => 'p', 'atomic' => true, 's' => array(), 'children' => array()),
					),
				),
			),
		);
		$out = $engine->build($sections);
		$this->assertSame('row', $out[0]['tree']['layoutType']);
		$this->assertContains($out[1]['tree']['layoutType'] ?? '', array('stack', 'row'));
	}

	public function test_semantic_graph_detects_layered_block_and_horizontal_bar(): void
	{
		$solver = new ConstraintLayoutSolver();
		$graph = new SemanticComponentGraph();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'section',
					's' => array('pos' => 'relative', 'h' => 380),
					'children' => array(
						array('tag' => 'img', 'src' => 'https://example.com/bg.jpg', 'atomic' => true, 's' => array('w' => 1440, 'h' => 380)),
						array('tag' => 'div', 's' => array('pos' => 'absolute'), 'children' => array()),
					),
				),
			),
			array(
				'tree' => array(
					'tag' => 'div',
					's' => array('disp' => 'flex', 'fd' => 'row', 'w' => 1200, 'h' => 64),
					'children' => array(
						array('tag' => 'span', 'text' => 'Brand', 'atomic' => true, 's' => array()),
						array('tag' => 'a', 'text' => 'Home', 'atomic' => true, 's' => array()),
					),
				),
			),
		);
		$sections = $solver->solve($sections);
		$out = $graph->build($sections);
		$this->assertSame('layered_block', $out[0]['tree']['layoutRole']);
		$this->assertSame('horizontal_bar', $out[1]['tree']['layoutRole']);
	}

	public function test_component_recognition_prefers_native_layered_block_over_html_fallback(): void
	{
		$engine = new SemanticComponentRecognizer();
		$layered = array(
			'tag' => 'section',
			'layoutRole' => 'layered_block',
			'layeredLayout' => array('background' => null, 'content' => array(), 'in_flow' => array()),
			'children' => array(array('tag' => 'div', 's' => array('pos' => 'absolute'))),
		);
		$this->assertFalse($engine->container_needs_fallback($layered));
	}

	public function test_visual_leaf_classifier_uses_typography_not_tags(): void
	{
		$classifier = new VisualLeafClassifier();
		$result = $classifier->classify(array(
			'tag' => 'div',
			'text' => 'Big Title',
			'atomic' => true,
			's' => array('fs' => '48px', 'fw' => '800', 'color' => 'rgb(0,0,0)'),
		));
		$this->assertSame('widget', $result['kind'] ?? '');
		$this->assertSame('heading', $result['type'] ?? '');
	}

	public function test_layered_layout_solver_builds_container(): void
	{
		$solver = new LayeredLayoutSolver(new \HtmlToElementor\Elementor\CssMapper());
		$node = array(
			's' => array('h' => 380),
			'layeredLayout' => array(
				'background' => array('src' => 'https://example.com/hero.jpg', 's' => array()),
				'overlay' => null,
				'content' => array(
					array(
						'tag' => 'div',
						'children' => array(
							array('tag' => 'h1', 'text' => 'Hello', 'atomic' => true, 's' => array('fs' => '48px')),
						),
					),
				),
				'in_flow' => array(),
			),
		);
		$converter = new \HtmlToElementor\Elementor\LayoutTreeConverter();
		$container = $solver->to_container(
			$node,
			function (array $n) use ($converter): array {
				$el = $converter->convert_section($n);
				return null !== $el ? array($el) : array();
			},
			static function (string $r): void {
				unset($r);
			}
		);
		$this->assertNotNull($container);
		$this->assertSame('container', $container['elType']);
		$this->assertArrayHasKey('background_image', $container['settings']);
	}

	public function test_constraint_solver_detects_horizontal_stack(): void
	{
		$solver = new ConstraintLayoutSolver();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'cls' => 'row',
					's' => array('disp' => 'flex', 'fd' => 'row'),
					'children' => array(
						array('tag' => 'div', 's' => array('w' => 300, 'h' => 200), 'bbox' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 200), 'children' => array()),
						array('tag' => 'div', 's' => array('w' => 300, 'h' => 200), 'bbox' => array('x' => 320, 'y' => 0, 'width' => 300, 'height' => 200), 'children' => array()),
					),
				),
			),
		);
		$out = $solver->solve($sections);
		$constraint = $out[0]['tree']['layoutConstraint'] ?? array();
		$this->assertSame('row', $constraint['direction'] ?? '');
		$this->assertGreaterThan(0, $constraint['gap'] ?? 0);
	}

	public function test_whitespace_analyzer_measures_gap_from_bbox(): void
	{
		$analyzer = new WhitespaceAnalyzer();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'bbox' => array('x' => 0, 'y' => 0, 'width' => 700, 'height' => 400),
					'children' => array(
						array('tag' => 'p', 'atomic' => true, 'bbox' => array('x' => 0, 'y' => 0, 'width' => 600, 'height' => 40), 's' => array()),
						array('tag' => 'p', 'atomic' => true, 'bbox' => array('x' => 0, 'y' => 64, 'width' => 600, 'height' => 40), 's' => array()),
					),
					'layoutConstraint' => array('direction' => 'column', 'gap' => 24),
				),
			),
		);
		$out = $analyzer->analyze($sections);
		$this->assertGreaterThan(0, $out[0]['tree']['whitespace']['gap'] ?? 0);
		$this->assertSame('constraint', $out[0]['tree']['s']['_gap_source'] ?? '');
		$this->assertSame('24px', $out[0]['tree']['s']['gap'] ?? '');
	}

	public function test_whitespace_analyzer_uses_parent_row_direction(): void
	{
		$analyzer = new WhitespaceAnalyzer();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'bbox' => array('x' => 0, 'y' => 0, 'width' => 700, 'height' => 220),
					's' => array('disp' => 'flex', 'fd' => 'row'),
					'layoutConstraint' => array('direction' => 'row'),
					'children' => array(
						array('tag' => 'div', 'bbox' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 200), 's' => array(), 'children' => array()),
						array('tag' => 'div', 'bbox' => array('x' => 324, 'y' => 0, 'width' => 300, 'height' => 200), 's' => array(), 'children' => array()),
					),
				),
			),
		);
		$out = $analyzer->analyze($sections);
		$this->assertSame('row', $out[0]['tree']['whitespace']['direction'] ?? '');
		$this->assertEqualsWithDelta(24.0, (float) ($out[0]['tree']['whitespace']['gap'] ?? 0), 0.5);
	}

	public function test_alignment_engine_detects_shared_left(): void
	{
		$engine = new AlignmentEngine();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'children' => array(
						array('tag' => 'h2', 'atomic' => true, 'bbox' => array('x' => 48, 'y' => 0, 'width' => 200, 'height' => 32), 's' => array()),
						array('tag' => 'p', 'atomic' => true, 'bbox' => array('x' => 48, 'y' => 48, 'width' => 400, 'height' => 20), 's' => array()),
					),
					'layoutConstraint' => array('direction' => 'column'),
				),
			),
		);
		$out = $engine->apply($sections);
		$this->assertTrue($out[0]['tree']['alignment']['shared_left'] ?? false);
	}

	public function test_visual_tree_builder_promotes_visual_child(): void
	{
		$builder = new VisualTreeBuilder();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					's' => array('disp' => 'block'),
					'bbox' => array('x' => 0, 'y' => 0, 'width' => 100, 'height' => 100),
					'children' => array(
						array(
							'tag' => 'div',
							'cls' => 'card',
							's' => array('bg' => 'rgb(255,0,0)'),
							'bbox' => array('x' => 0, 'y' => 0, 'width' => 100, 'height' => 100),
							'children' => array(),
						),
					),
				),
			),
		);
		$out = $builder->build($sections);
		$this->assertSame('card', $out[0]['tree']['cls'] ?? '');
	}

	public function test_pixel_repair_applies_flex_gap(): void
	{
		$repair = new PixelRepairEngine(2);
		$data = array(
			array(
				'id' => 'c1',
				'elType' => 'container',
				'settings' => array('_css_classes' => 'row', 'flex_direction' => 'row'),
				'elements' => array(
					array('id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(), 'elements' => array()),
					array('id' => 'w2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(), 'elements' => array()),
				),
				'isInner' => false,
			),
		);
		$sections = array(
			array('tree' => array('cls' => 'row', 'layoutConstraint' => array('direction' => 'row', 'gap' => 24), 'alignment' => array('justify' => 'flex-start'))),
		);
		$result = $repair->repair($data, array('sections' => $sections));
		$this->assertTrue($result['changed']);
		$this->assertArrayHasKey('flex_gap', $result['data'][0]['settings']);
	}

	public function test_geometry_horizontal_gap(): void
	{
		$a = array('x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 50.0);
		$b = array('x' => 124.0, 'y' => 0.0, 'width' => 100.0, 'height' => 50.0);
		$this->assertSame(24.0, Geometry::horizontal_gap($a, $b));
	}

	public function test_design_token_extractor_builds_scales(): void
	{
		$extractor = new DesignTokenExtractor();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					's' => array('fs' => '32px', 'br' => 10, 'color' => 'rgb(149, 125, 74)'),
					'children' => array(),
				),
			),
		);
		$tokens = $extractor->extract($sections, array('scale' => array(8, 16, 24), 'base' => 16));
		$this->assertContains('32px', $tokens['typography_scale']);
		$this->assertContains(10.0, $tokens['radius_scale']);
		$this->assertSame(16, $tokens['spacing_base']);
	}

	public function test_media_engine_collects_images_and_backgrounds(): void
	{
		$engine = new MediaEngine();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					's' => array('bgImg' => 'url("https://example.com/bg.jpg")'),
					'children' => array(
						array('tag' => 'img', 'src' => 'https://example.com/photo.webp', 's' => array()),
					),
				),
			),
		);
		$media = $engine->collect($sections);
		$this->assertGreaterThanOrEqual(2, $media['count']);
		$this->assertContains('https://example.com/photo.webp', $media['urls']);
	}

	public function test_native_widget_mapper_coverage_stats(): void
	{
		$mapper = new NativeWidgetMapper();
		$stats = $mapper->coverage_stats(array('heading' => 3, 'text-editor' => 2, 'html' => 1));
		$this->assertSame(83.3, $stats['native_pct']);
		$this->assertSame(16.7, $stats['html_pct']);
	}

	public function test_responsive_engine_normalizes_breakpoints(): void
	{
		$engine = new ResponsiveReconstructionEngine();
		$bps = $engine->normalize_breakpoints(array('desktop' => 1200));
		$this->assertSame(1920, $bps['wide']);
		$this->assertSame(1200, $bps['desktop']);
		$this->assertSame(375, $bps['mobile']);
	}

	public function test_responsive_does_not_blindly_stack_all_rows(): void
	{
		$engine = new ResponsiveReconstructionEngine();
		$nav = array(
			'tree' => array(
				'tag' => 'nav',
				'layoutRole' => 'horizontal_bar',
				'layoutType' => 'row',
				'layoutConstraint' => array('direction' => 'row'),
				's' => array('disp' => 'flex', 'fd' => 'row', 'w' => 1200),
				'children' => array(
					array('tag' => 'a', 'atomic' => true, 'text' => 'Home', 's' => array('w' => 80), 'bbox' => array('x' => 0, 'y' => 0, 'width' => 80, 'height' => 40)),
					array('tag' => 'a', 'atomic' => true, 'text' => 'About', 's' => array('w' => 80), 'bbox' => array('x' => 100, 'y' => 0, 'width' => 80, 'height' => 40)),
				),
			),
		);
		$out = $engine->annotate(array($nav));
		$this->assertFalse((bool) ($out[0]['tree']['responsiveConstraints']['mobile_stack'] ?? false));

		$cards = array(
			'tree' => array(
				'tag' => 'div',
				'layoutType' => 'row',
				'layoutConstraint' => array('direction' => 'row'),
				's' => array('disp' => 'flex', 'fd' => 'row', 'w' => 1200),
				'r' => array(
					'mobile' => array('fd' => 'column', 'disp' => 'flex', 'w' => 375),
				),
				'children' => array(
					array('tag' => 'div', 's' => array('w' => 360, 'bg' => 'rgb(255,255,255)', 'br' => 8), 'bbox' => array('x' => 0, 'y' => 0, 'width' => 360, 'height' => 200), 'children' => array()),
					array('tag' => 'div', 's' => array('w' => 360, 'bg' => 'rgb(255,255,255)', 'br' => 8), 'bbox' => array('x' => 400, 'y' => 0, 'width' => 360, 'height' => 200), 'children' => array()),
					array('tag' => 'div', 's' => array('w' => 360, 'bg' => 'rgb(255,255,255)', 'br' => 8), 'bbox' => array('x' => 800, 'y' => 0, 'width' => 360, 'height' => 200), 'children' => array()),
				),
			),
		);
		$stacked = $engine->annotate(array($cards));
		$this->assertTrue((bool) ($stacked[0]['tree']['responsiveConstraints']['mobile_stack'] ?? false));
		$this->assertTrue((bool) ($stacked[0]['tree']['children'][0]['responsiveConstraints']['full_width_mobile'] ?? false));
	}

	public function test_animation_engine_maps_fade_transition(): void
	{
		$engine = new AnimationEngine();
		$motion = $engine->extract(array('s' => array('transition' => 'opacity 0.3s ease')));
		$this->assertSame('yes', $motion['motion_fx_opacity_effect'] ?? '');
	}

	public function test_visual_validation_repairs_simple_html_heading(): void
	{
		$repair = new PixelRepairEngine(2);
		$data = array(
			array(
				'id' => 'abc1234',
				'elType' => 'container',
				'settings' => array('flex_direction' => 'column'),
				'elements' => array(
					array(
						'id' => 'def5678',
						'elType' => 'widget',
						'widgetType' => 'html',
						'settings' => array('html' => '<h2>Title</h2>'),
						'elements' => array(),
					),
				),
				'isInner' => false,
			),
		);
		$result = $repair->repair($data, array('sections' => array(), 'validation' => array()));
		$widget = $result['data'][0]['elements'][0];
		$this->assertSame('heading', $widget['widgetType']);
		$this->assertTrue($result['changed']);
	}

	public function test_geometry_comparator_produces_rmse_metrics(): void
	{
		$comparator = new GeometryComparator();
		$sections = array(
			array(
				'bbox' => array('x' => 0, 'y' => 0, 'width' => 600, 'height' => 200),
				'tree' => array(
					'tag' => 'div',
					'cls' => 'hero',
					'bbox' => array('x' => 0, 'y' => 0, 'width' => 600, 'height' => 200),
					'layoutConstraint' => array('direction' => 'column', 'gap' => 24),
					'children' => array(
						array(
							'tag' => 'h1',
							'text' => 'Title',
							'atomic' => true,
							'bbox' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 48),
							's' => array('fs' => '48px'),
						),
					),
				),
			),
		);
		$elementor = array(
			array(
				'elType' => 'container',
				'settings' => array('_css_classes' => 'hero', 'flex_direction' => 'column', 'flex_gap' => array('size' => 24)),
				'elements' => array(
					array('elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(), 'elements' => array()),
				),
				'isInner' => false,
			),
		);
		$result = $comparator->compare($sections, $elementor);
		$this->assertArrayHasKey('geometry_similarity', $result);
		$this->assertArrayHasKey('bbox_delta', $result);
		$this->assertArrayHasKey('position_rmse', $result);
		$this->assertGreaterThan(0, $result['geometry_similarity']);
	}

	public function test_visual_validation_prioritizes_geometry_over_widget_coverage(): void
	{
		$engine = new VisualValidationEngine(95);
		// Empty Elementor tree vs a real source section → low geometry.
		$sections = array(
			array(
				'bbox' => array('x' => 0, 'y' => 0, 'width' => 1200, 'height' => 800),
				'tree' => array(
					'tag' => 'section',
					'bbox' => array('x' => 0, 'y' => 0, 'width' => 1200, 'height' => 800),
					'layoutConstraint' => array('direction' => 'row', 'gap' => 40),
					'children' => array(
						array(
							'tag' => 'div',
							'bbox' => array('x' => 0, 'y' => 0, 'width' => 600, 'height' => 800),
							'children' => array(),
						),
						array(
							'tag' => 'div',
							'bbox' => array('x' => 640, 'y' => 0, 'width' => 560, 'height' => 800),
							'children' => array(),
						),
					),
				),
			),
		);
		$score = $engine->score(
			array(),
			array(
				'sections' => $sections,
				'report' => array(
					'native_widgets' => 100,
					'html_widgets' => 0,
				),
			)
		);

		$this->assertSame('geometry_primary', $score['scoring_mode']);
		$this->assertSame(100, $score['widget_coverage']);
		// Perfect widget coverage must not alone push fidelity to the old 90%+ floor.
		$this->assertLessThan(90, $score['fidelity']);
		$this->assertSame(0, $score['matched_frames']);
		$this->assertGreaterThan(0, $score['source_frames']);
	}

	public function test_leaf_classifier_plain_link_is_text_not_button(): void
	{
		$classifier = new VisualLeafClassifier();
		$link = $classifier->classify(array(
			'tag' => 'a',
			'atomic' => true,
			'text' => 'Read more',
			'href' => 'https://example.com/post',
			's' => array('fs' => '16px', 'h' => 24, 'w' => 90),
			'bbox' => array('x' => 0, 'y' => 0, 'width' => 90, 'height' => 24),
		));
		$this->assertNotNull($link);
		$this->assertSame('text-editor', $link['type']);
		$this->assertStringContainsString('https://example.com/post', $link['settings']['editor']);

		$btn = $classifier->classify(array(
			'tag' => 'a',
			'atomic' => true,
			'text' => 'Book now',
			'href' => 'https://example.com/book',
			'cls' => 'btn btn-primary',
			's' => array('bg' => 'rgb(0,0,0)', 'pt' => 12, 'pb' => 12, 'pl' => 20, 'pr' => 20, 'h' => 44, 'w' => 140),
			'bbox' => array('x' => 0, 'y' => 0, 'width' => 140, 'height' => 44),
		));
		$this->assertSame('button', $btn['type']);
	}

	public function test_leaf_classifier_maps_address_from_embed(): void
	{
		$classifier = new VisualLeafClassifier();
		$map = $classifier->classify(array(
			'tag' => 'iframe',
			'atomic' => true,
			'src' => 'https://www.google.com/maps/embed/v1/place?q=Zurich%2C+Switzerland',
			'html' => '<iframe src="https://www.google.com/maps/embed/v1/place?q=Zurich%2C+Switzerland"></iframe>',
			's' => array('h' => 400, 'w' => 600),
		));
		$this->assertSame('google_maps', $map['type']);
		$this->assertSame('Zurich, Switzerland', $map['settings']['address']);
	}

	public function test_layout_graph_emitter_hoists_transparent_wrapper(): void
	{
		$converter = new \HtmlToElementor\Elementor\LayoutTreeConverter();
		$tree = array(
			'tag' => 'section',
			'cls' => 'section',
			's' => array('disp' => 'block'),
			'layoutConstraint' => array('direction' => 'column'),
			'children' => array(
				array(
					'tag' => 'div',
					's' => array('disp' => 'block'),
					'children' => array(
						array('tag' => 'p', 'text' => 'Hello', 'atomic' => true, 's' => array('fs' => '16px')),
					),
				),
			),
		);
		$result = $converter->convert_section($tree);
		$this->assertNotNull($result);
		$this->assertSame('container', $result['elType']);
		$widgets = $this->collect_widgets($result);
		$this->assertContains('text-editor', $widgets);
	}

	/**
	 * @param array<string,mixed> $el Element.
	 * @return array<int,string>
	 */
	private function collect_widgets(array $el): array
	{
		$types = array();
		if ('widget' === ($el['elType'] ?? '')) {
			$types[] = (string) ($el['widgetType'] ?? '');
		}
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			$types = array_merge($types, $this->collect_widgets($child));
		}
		return $types;
	}

	public function test_orchestrator_prepare_enriches_sections(): void
	{
		$layout = array(
			'meta' => array('title' => 'Test'),
			'sections' => array(
				array(
					'tag' => 'div',
					'tree' => array(
						'tag' => 'div',
						'cls' => 'wrapper',
						's' => array('disp' => 'block'),
						'children' => array(
							array('tag' => 'h1', 'text' => 'Hello', 'atomic' => true, 's' => array('fs' => '48px')),
						),
					),
				),
			),
		);
		$orch = new VisualReconstructionOrchestrator();
		$prepared = $orch->prepare(RenderResult::from_array($layout));
		$this->assertArrayHasKey('engines', $prepared);
		$this->assertSame(4, $prepared['engines']['version']);
		$this->assertNotEmpty($prepared['tokens']);
	}

	public function test_visual_extraction_carries_accessibility_and_xpath_metadata(): void
	{
		$engine = new VisualExtractionEngine();
		$layout = array(
			'meta' => array('title' => 'Extraction Meta'),
			'sections' => array(
				array(
					'tree' => array(
						'tag' => 'button',
						'text' => 'Contact',
						'ariaRole' => 'button',
						'ariaLabel' => 'Contact us',
						'xpath' => '/html[1]/body[1]/button[1]',
						'domPath' => 'body > button',
						'states' => array('hover' => true),
						'pseudo' => array('before' => array('content' => '"→"')),
						'children' => array(),
					),
				),
			),
		);
		$out = $engine->enrich(RenderResult::from_array($layout))->to_array();
		$visual = $out['sections'][0]['tree']['visual'] ?? array();
		$this->assertSame('/html[1]/body[1]/button[1]', $visual['xpath'] ?? '');
		$this->assertSame('Contact us', $visual['accessibility']['label'] ?? '');
		$this->assertTrue($visual['states']['hover'] ?? false);
	}

	public function test_whitespace_analyzer_uses_parent_bbox_for_padding(): void
	{
		$analyzer = new WhitespaceAnalyzer();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					'bbox' => array('x' => 100, 'y' => 200, 'width' => 500, 'height' => 300),
					'children' => array(
						array('tag' => 'p', 'atomic' => true, 'bbox' => array('x' => 120, 'y' => 220, 'width' => 300, 'height' => 24), 's' => array()),
						array('tag' => 'p', 'atomic' => true, 'bbox' => array('x' => 120, 'y' => 264, 'width' => 300, 'height' => 24), 's' => array()),
					),
					'layoutConstraint' => array('direction' => 'column'),
				),
			),
		);
		$out = $analyzer->analyze($sections);
		$padding = $out[0]['tree']['whitespace']['padding'] ?? array();
		$this->assertSame(20.0, (float) ($padding['top'] ?? 0));
		$this->assertSame(20.0, (float) ($padding['left'] ?? 0));
	}
}
