<?php
/**
 * Container hierarchy optimization tests.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ContainerTreeOptimizer;
use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Elementor\LayoutTreeConverter;
use HtmlToElementor\Engine\GeometryComparator;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

/**
 * Ensures layout-graph emission produces minimal, editable container trees.
 */
final class ContainerHierarchyTest extends TestCase
{
	use RegressionFixtures;

	public function test_container_optimizer_removes_redundant_single_child_chain(): void
	{
		$elements = array(
			array(
				'id' => 'root',
				'elType' => 'container',
				'isInner' => false,
				'settings' => array('content_width' => 'full', 'flex_direction' => 'column'),
				'elements' => array(
					array(
						'id' => 'mid',
						'elType' => 'container',
						'isInner' => true,
						'settings' => array('flex_direction' => 'column'),
						'elements' => array(
							array(
								'id' => 'leaf',
								'elType' => 'container',
								'isInner' => true,
								'settings' => array('flex_direction' => 'column'),
								'elements' => array(
									array(
										'id' => 'w1',
										'elType' => 'widget',
										'widgetType' => 'heading',
										'settings' => array(),
										'elements' => array(),
									),
									array(
										'id' => 'w2',
										'elType' => 'widget',
										'widgetType' => 'text-editor',
										'settings' => array(),
										'elements' => array(),
									),
								),
							),
						),
					),
				),
			),
		);

		$optimizer = new ContainerTreeOptimizer();
		$optimized = $optimizer->optimize($elements);
		$stats = $optimizer->stats();

		$this->assertSame(1, $this->count_containers($optimized));
		$this->assertGreaterThanOrEqual(2, $stats['redundant_containers_removed']);
		$this->assertGreaterThan(0, $stats['compression_ratio']);
		$this->assertSame(2, count($optimized[0]['elements']));
	}

	public function test_nested_div_wrappers_emit_flat_widget_stack(): void
	{
		$layout = array(
			'meta' => array('title' => 'Nested Wrappers'),
			'sections' => array(
				array(
					'tag' => 'div',
					'tree' => array(
						'tag' => 'div',
						'cls' => 'section',
						's' => array('disp' => 'block'),
						'layoutConstraint' => array('direction' => 'column', 'gap' => 16),
						'children' => array(
							array(
								'tag' => 'div',
								's' => array('disp' => 'block'),
								'children' => array(
									array(
										'tag' => 'div',
										's' => array('disp' => 'block'),
										'children' => array(
											array('tag' => 'h2', 'text' => 'Title', 'atomic' => true, 's' => array('fs' => '32px')),
											array('tag' => 'p', 'text' => 'Body', 'atomic' => true, 's' => array('fs' => '16px')),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));

		$this->assertSame(1, $result['quality']['container_count']);
		$this->assertLessThanOrEqual(2, $result['quality']['max_container_depth']);
		$this->assertCount(2, $result['data'][0]['elements']);
	}

	public function test_row_nav_hoists_atomic_links_without_wrapper_containers(): void
	{
		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($this->bootstrap_layout()), array('mode' => 'native'));

		$navbar = $result['data'][0];
		$this->assertSame('container', $navbar['elType']);
		$this->assertCount(3, $navbar['elements']);
		foreach ($navbar['elements'] as $child) {
			$this->assertSame('widget', $child['elType']);
		}
	}

	public function test_regression_fixtures_respect_max_container_depth(): void
	{
		$gen = new ElementorJsonGenerator();
		foreach (array($this->bootstrap_layout(), $this->tailwind_layout(), $this->nested_flex_layout()) as $layout) {
			$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));
			$this->assertLessThanOrEqual(4, $result['quality']['max_container_depth']);
			$this->assertGreaterThan(0, $result['validation']['geometry_similarity']);
		}
	}

	public function test_compression_does_not_reduce_geometry_similarity(): void
	{
		$layout = array(
			'meta' => array('title' => 'Hero'),
			'sections' => array(
				array(
					'tag' => 'section',
					'bbox' => array('x' => 0, 'y' => 0, 'width' => 600, 'height' => 200),
					'tree' => array(
						'tag' => 'section',
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
							array(
								'tag' => 'p',
								'text' => 'Subtitle',
								'atomic' => true,
								'bbox' => array('x' => 0, 'y' => 72, 'width' => 400, 'height' => 24),
								's' => array('fs' => '16px'),
							),
						),
					),
				),
			),
		);

		$gen = new ElementorJsonGenerator();
		$result = $gen->generate(RenderResult::from_array($layout), array('mode' => 'native'));
		$comparator = new GeometryComparator();
		$score = $comparator->compare(
			$layout['sections'],
			$result['data']
		);

		$this->assertGreaterThan(0, $result['validation']['geometry_similarity']);
		$this->assertGreaterThan(0, $score['geometry_similarity']);
	}

	public function test_layout_graph_emitter_skips_meaningless_single_child_wrapper(): void
	{
		$converter = new LayoutTreeConverter();
		$tree = array(
			'tag' => 'section',
			'cls' => 'section',
			's' => array('disp' => 'block'),
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
		$this->assertSame('widget', $result['elements'][0]['elType'] ?? '');
		$this->assertSame(1, $this->count_containers(array($result)));
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function count_containers(array $elements): int
	{
		$count = 0;
		foreach ($elements as $element) {
			if ('container' === ($element['elType'] ?? '')) {
				++$count;
			}
			$count += $this->count_containers((array) ($element['elements'] ?? array()));
		}

		return $count;
	}
}
