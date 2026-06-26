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
use HtmlToElementor\Engine\ConstraintLayoutEngine;
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

	public function test_layout_graph_detects_hero_and_navigation(): void
	{
		$engine = new LayoutGraphEngine();
		$sections = array(
			array('tree' => array('tag' => 'section', 'cls' => 'page-hero', 's' => array(), 'children' => array())),
			array('tree' => array('tag' => 'nav', 'cls' => 'nav-links', 's' => array(), 'children' => array())),
		);
		$out = $engine->build($sections);
		$this->assertSame('hero', $out[0]['tree']['layoutRole']);
		$this->assertSame('navigation', $out[1]['tree']['layoutRole']);
	}

	public function test_component_recognition_prefers_native_hero_over_html_fallback(): void
	{
		$engine = new ComponentRecognitionEngine();
		$hero = array(
			'tag' => 'section',
			'cls' => 'page-hero',
			'layoutRole' => 'hero',
			'children' => array(array('tag' => 'div', 's' => array('pos' => 'absolute'))),
		);
		$this->assertFalse($engine->container_needs_fallback($hero));
	}

	public function test_constraint_layout_infers_gap_from_child_margins(): void
	{
		$engine = new ConstraintLayoutEngine();
		$sections = array(
			array(
				'tree' => array(
					'tag' => 'div',
					's' => array('disp' => 'flex', 'fd' => 'column'),
					'children' => array(
						array('tag' => 'p', 's' => array('mb' => 24), 'atomic' => true),
						array('tag' => 'p', 's' => array('mb' => 24), 'atomic' => true),
					),
				),
			),
		);
		$out = $engine->apply($sections);
		$this->assertStringContainsString('24', (string) ($out[0]['tree']['s']['gap'] ?? ''));
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

	public function test_animation_engine_maps_fade_transition(): void
	{
		$engine = new AnimationEngine();
		$motion = $engine->extract(array('s' => array('transition' => 'opacity 0.3s ease')));
		$this->assertSame('yes', $motion['motion_fx_opacity_effect'] ?? '');
	}

	public function test_visual_validation_repairs_simple_html_heading(): void
	{
		$validator = new VisualValidationEngine(95, 2);
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
		$result = $validator->validate_and_repair($data, array('report' => array('native_widgets' => 0, 'html_widgets' => 1), 'sections' => array()));
		$widget = $result['data'][0]['elements'][0];
		$this->assertSame('heading', $widget['widgetType']);
		$this->assertTrue($result['repaired']);
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
		$this->assertSame(2, $prepared['engines']['version']);
		$this->assertNotEmpty($prepared['tokens']);
	}
}
