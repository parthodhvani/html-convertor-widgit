<?php
/**
 * Adjacent containers with distinct bboxes must not be merged.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ContainerTreeOptimizer;
use PHPUnit\Framework\TestCase;

final class AdjacentContainerMergeTest extends TestCase
{
	public function test_distinct_bbox_siblings_stay_separate(): void
	{
		$optimizer = new ContainerTreeOptimizer();
		$mk = static function (float $y, float $h, int $kids): array {
			$elements = array();
			for ($i = 0; $i < $kids; ++$i) {
				$elements[] = array(
					'elType' => 'widget',
					'widgetType' => 'text-editor',
					'settings' => array(),
					'elements' => array(),
				);
			}
			return array(
				'elType' => 'container',
				'isInner' => true,
				'settings' => array(
					'_css_classes' => 'info-block',
					'flex_direction' => 'column',
					'_h2e_bbox' => array('x' => 0, 'y' => $y, 'width' => 600, 'height' => $h),
				),
				'elements' => $elements,
			);
		};

		$parent = array(
			'elType' => 'container',
			'isInner' => false,
			'settings' => array('_css_classes' => 'kontakt-info', 'flex_direction' => 'column'),
			'elements' => array(
				$mk(400, 54, 3),
				$mk(486, 54, 3),
				$mk(637, 96, 2),
			),
		);

		$out = $optimizer->optimize(array($parent));
		$this->assertCount(3, $out[0]['elements']);
		$this->assertSame(54.0, (float) ($out[0]['elements'][0]['settings']['_h2e_bbox']['height'] ?? 0));
		$this->assertSame(54.0, (float) ($out[0]['elements'][1]['settings']['_h2e_bbox']['height'] ?? 0));
		$this->assertSame(96.0, (float) ($out[0]['elements'][2]['settings']['_h2e_bbox']['height'] ?? 0));
	}
}
