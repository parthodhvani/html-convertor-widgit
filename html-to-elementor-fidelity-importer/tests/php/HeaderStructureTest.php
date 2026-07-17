<?php
/**
 * Headers must keep logo / nav / CTA structure and gaps — not a flat atom row.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

final class HeaderStructureTest extends TestCase
{

	public function test_header_preserves_nav_gap_and_groups(): void
	{
		$layout = array(
			'meta' => array('title' => 'Header', 'width' => 1440, 'height' => 80),
			'sections' => array(
				array(
					'tree' => array(
						'tag' => 'header',
						'cls' => 'site-header',
						's' => array('disp' => 'block', 'w' => 1440, 'h' => 79),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'container header-inner',
								'layoutRole' => 'header',
								's' => array(
									'disp' => 'flex',
									'fd' => 'row',
									'jc' => 'space-between',
									'ai' => 'center',
									'w' => 1200,
									'h' => 78,
								),
								'children' => array(
									array(
										'tag' => 'a',
										'cls' => 'logo',
										'layoutRole' => 'header',
										's' => array('disp' => 'flex', 'fd' => 'row', 'gap' => '12px', 'w' => 184, 'h' => 49),
										'children' => array(
											array(
												'tag' => 'span',
												'cls' => 'logo-mark',
												'text' => 'PM',
												'atomic' => true,
												's' => array('w' => 44, 'h' => 44, 'fs' => '16px'),
											),
											array(
												'tag' => 'span',
												'cls' => 'logo-text',
												'text' => 'Petra Müller',
												'atomic' => true,
												's' => array('w' => 128, 'h' => 49, 'fs' => '16px'),
											),
										),
									),
									array(
										'tag' => 'nav',
										'cls' => 'nav',
										'layoutRole' => 'header',
										's' => array('disp' => 'flex', 'fd' => 'row', 'gap' => '32px', 'w' => 789, 'h' => 53),
										'children' => array(
											array(
												'tag' => 'ul',
												'cls' => 'nav-list',
												's' => array('disp' => 'flex', 'fd' => 'row', 'gap' => '28px', 'w' => 591, 'h' => 26),
												'children' => array(
													array(
														'tag' => 'a',
														'text' => 'Home',
														'atomic' => true,
														's' => array('w' => 43, 'h' => 26, 'fs' => '16px'),
													),
													array(
														'tag' => 'a',
														'text' => 'Kontakt',
														'atomic' => true,
														's' => array('w' => 56, 'h' => 26, 'fs' => '16px'),
													),
												),
											),
											array(
												'tag' => 'a',
												'cls' => 'btn btn-gold',
												'text' => 'Termin buchen',
												'atomic' => true,
												's' => array('w' => 166, 'h' => 53, 'fs' => '15px', 'bg' => 'rgb(201,162,39)'),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		$generated = (new ElementorJsonGenerator())->generate(
			RenderResult::from_array($layout),
			array('confidence' => 90, 'closed_loop' => false)
		);
		$header = $generated['data'][0] ?? array();
		$this->assertSame('container', $header['elType'] ?? '');

		$json = wp_json_encode($header);
		$this->assertStringContainsString('Home', $json);
		$this->assertStringContainsString('Termin buchen', $json);

		// Must not flatten logo+nav+links into one gapless row of 4+ text widgets.
		$inner = $this->first_row_container($header);
		$this->assertNotNull($inner);
		$gap = (float) ($inner['settings']['flex_gap']['size'] ?? 0);
		$kids = (array) ($inner['elements'] ?? array());
		$widget_kids = 0;
		$container_kids = 0;
		foreach ($kids as $kid) {
			if (($kid['elType'] ?? '') === 'widget') {
				++$widget_kids;
			}
			if (($kid['elType'] ?? '') === 'container') {
				++$container_kids;
			}
		}
		$this->assertGreaterThanOrEqual(2, $container_kids, 'logo and nav should remain containers');
		$this->assertLessThan(4, $widget_kids, 'nav links must not sit as bare siblings of the logo');

		$nav_gap_found = $this->find_gap_near($header, 28) || $this->find_gap_near($header, 32);
		$this->assertTrue($nav_gap_found, 'nav/nav-list gap must survive');
		$this->assertTrue($gap >= 0);
	}

	/**
	 * @param array<string,mixed> $el Element.
	 * @return array<string,mixed>|null
	 */
	private function first_row_container(array $el): ?array
	{
		if (($el['settings']['flex_direction'] ?? '') === 'row') {
			return $el;
		}
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$found = $this->first_row_container($child);
			if (null !== $found) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $el Element.
	 */
	private function find_gap_near(array $el, float $target): bool
	{
		$gap = (float) ($el['settings']['flex_gap']['size'] ?? -1);
		if (abs($gap - $target) <= 1.0) {
			return true;
		}
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			if (is_array($child) && $this->find_gap_near($child, $target)) {
				return true;
			}
		}
		return false;
	}
}
