<?php
/**
 * Dark themes need body canvas paint + CSS space-between / intrinsic icons.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Engine\ElementorPreviewRenderer;
use HtmlToElementor\Engine\LayeredLayoutSolver;
use HtmlToElementor\Elementor\CssMapper;
use HtmlToElementor\Services\RenderResult;
use PHPUnit\Framework\TestCase;

final class DarkThemeCanvasTest extends TestCase
{

	public function test_transparent_sections_inherit_page_background(): void
	{
		$layout = array(
			'meta' => array(
				'title' => 'Dark',
				'width' => 1440,
				'height' => 800,
				'page' => array(
					'backgroundColor' => 'rgb(5, 7, 15)',
					'color' => 'rgb(230, 236, 245)',
				),
			),
			'sections' => array(
				array(
					'styles' => array('backgroundColor' => 'rgba(0, 0, 0, 0)'),
					'tree' => array(
						'tag' => 'section',
						'cls' => 'section',
						's' => array(
							'disp' => 'block',
							'w' => 1440,
							'h' => 400,
							'bg' => 'rgba(0, 0, 0, 0)',
							'color' => 'rgb(230, 236, 245)',
							'pt' => 96,
							'pb' => 96,
						),
						'children' => array(
							array(
								'tag' => 'h2',
								'text' => 'Beratung',
								'atomic' => true,
								's' => array(
									'w' => 400,
									'h' => 48,
									'fs' => '32px',
									'ff' => 'Playfair Display',
									'color' => 'rgb(244, 236, 214)',
								),
							),
						),
					),
				),
			),
		);

		$out = (new ElementorJsonGenerator())->generate(
			RenderResult::from_array($layout),
			array('confidence' => 90, 'closed_loop' => false)
		);

		$bg = (string) ($out['data'][0]['settings']['background_color'] ?? '');
		$this->assertSame('rgb(5, 7, 15)', $bg);

		$html = (new ElementorPreviewRenderer())->render(
			$out['data'],
			array(
				'title' => 'Dark',
				'width' => 1440,
				'page' => $layout['meta']['page'],
			)
		);
		$this->assertStringContainsString('background:rgb(5, 7, 15)', $html);
	}

	public function test_css_space_between_not_overridden_by_constraint(): void
	{
		$layout = array(
			'meta' => array('title' => 'Header', 'width' => 1440, 'height' => 80),
			'sections' => array(
				array(
					'tree' => array(
						'tag' => 'header',
						'cls' => 'site-header',
						's' => array('disp' => 'block', 'w' => 1440, 'h' => 79, 'bg' => 'rgb(5, 7, 15)'),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'header-inner',
								's' => array(
									'disp' => 'flex',
									'fd' => 'row',
									'jc' => 'space-between',
									'ai' => 'center',
									'fw_wrap' => 'nowrap',
									'w' => 1200,
									'h' => 78,
									'pl' => 24,
									'pr' => 24,
								),
								'children' => array(
									array(
										'tag' => 'a',
										'text' => 'Logo',
										'atomic' => true,
										's' => array('w' => 180, 'h' => 40, 'fs' => '16px'),
									),
									array(
										'tag' => 'nav',
										'cls' => 'nav',
										's' => array('disp' => 'flex', 'fd' => 'row', 'gap' => '32px', 'w' => 700, 'h' => 40),
										'children' => array(
											array(
												'tag' => 'a',
												'text' => 'Home',
												'atomic' => true,
												's' => array('w' => 50, 'h' => 26, 'fs' => '16px'),
											),
											array(
												'tag' => 'a',
												'text' => 'Buchen',
												'atomic' => true,
												's' => array('w' => 120, 'h' => 40, 'fs' => '16px', 'bg' => 'rgb(201, 162, 39)'),
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

		$out = (new ElementorJsonGenerator())->generate(
			RenderResult::from_array($layout),
			array('confidence' => 90, 'closed_loop' => false)
		);

		$find = null;
		$walk = static function (array $n) use (&$walk, &$find): void {
			$cls = (string) ($n['settings']['_css_classes'] ?? '');
			if (str_contains($cls, 'header-inner')) {
				$find = $n;
				return;
			}
			foreach ((array) ($n['elements'] ?? array()) as $child) {
				if (is_array($child)) {
					$walk($child);
				}
			}
		};
		$walk($out['data'][0]);
		$this->assertNotNull($find);
		$this->assertSame('space-between', $find['settings']['flex_justify_content'] ?? null);
	}

	public function test_card_icon_keeps_measured_px_size(): void
	{
		$layout = array(
			'meta' => array('title' => 'Icon', 'width' => 1440, 'height' => 400),
			'sections' => array(
				array(
					'tree' => array(
						'tag' => 'section',
						'cls' => 'section',
						's' => array('disp' => 'block', 'w' => 1440, 'h' => 400, 'bg' => 'rgb(5, 7, 15)'),
						'children' => array(
							array(
								'tag' => 'div',
								'cls' => 'card',
								's' => array(
									'disp' => 'flex',
									'fd' => 'column',
									'w' => 360,
									'h' => 300,
									'bg' => 'rgb(13, 20, 36)',
								),
								'children' => array(
									array(
										'tag' => 'div',
										'cls' => 'card-icon',
										's' => array(
											'disp' => 'grid',
											'gtc' => '56px',
											'gtr' => '56px',
											'w' => 56,
											'h' => 56,
											'bgImg' => 'linear-gradient(135deg, rgb(5, 7, 15), rgb(142, 125, 190))',
										),
										'children' => array(
											array(
												'tag' => 'i',
												'cls' => 'fa-solid fa-moon',
												'atomic' => true,
												's' => array('w' => 17, 'h' => 22, 'color' => 'rgb(255,255,255)'),
											),
										),
									),
									array(
										'tag' => 'h3',
										'text' => 'Astrologie',
										'atomic' => true,
										's' => array('w' => 200, 'h' => 30, 'fs' => '22px', 'color' => 'rgb(244, 236, 214)'),
									),
								),
							),
						),
					),
				),
			),
		);

		$out = (new ElementorJsonGenerator())->generate(
			RenderResult::from_array($layout),
			array('confidence' => 90, 'closed_loop' => false)
		);

		$icon = null;
		$walk = static function (array $n) use (&$walk, &$icon): void {
			$cls = (string) ($n['settings']['_css_classes'] ?? '');
			if (str_contains($cls, 'card-icon')) {
				$icon = $n;
				return;
			}
			foreach ((array) ($n['elements'] ?? array()) as $child) {
				if (is_array($child)) {
					$walk($child);
				}
			}
		};
		$walk($out['data'][0]);
		$this->assertNotNull($icon);
		$this->assertSame('px', $icon['settings']['width']['unit'] ?? null);
		$this->assertSame(56.0, (float) ($icon['settings']['width']['size'] ?? 0));
		$this->assertSame(56.0, (float) ($icon['settings']['min_height']['size'] ?? 0));
	}

	public function test_framed_media_not_promoted_to_background(): void
	{
		$solver = new LayeredLayoutSolver(new CssMapper());
		$node = array(
			'tag' => 'div',
			'cls' => 'hero-visual',
			's' => array('disp' => 'grid', 'w' => 508, 'h' => 550, 'pos' => 'relative'),
			'layeredLayout' => array(
				'background' => array(
					'tag' => 'div',
					'cls' => 'founder-frame',
					'src' => '',
					's' => array(
						'w' => 440,
						'h' => 550,
						'brad' => array('tl' => 40, 'tr' => 60, 'br' => 42, 'bl' => 58),
						'bg' => 'rgba(255,255,255,0.08)',
					),
					'children' => array(
						array(
							'tag' => 'img',
							'src' => 'file:///tmp/portrait.jpg',
							's' => array('w' => 440, 'h' => 550),
						),
					),
				),
				'content' => array(
					array(
						'tag' => 'div',
						'cls' => 'hero-badge top',
						'text' => 'Termine verfügbar',
						's' => array(
							'pos' => 'absolute',
							'inset' => array('top' => '44px', 'left' => '-20px', 'right' => 'auto', 'bottom' => 'auto'),
							'w' => 182,
							'h' => 52,
						),
					),
				),
				'in_flow' => array(),
				'overlay' => null,
			),
		);

		$container = $solver->to_container(
			$node,
			static function (array $child): array {
				return array(
					array(
						'id' => 'x',
						'elType' => 'widget',
						'widgetType' => 'html',
						'settings' => array('html' => (string) ($child['cls'] ?? 'node')),
						'elements' => array(),
					),
				);
			},
			static function (): void {
			}
		);

		$this->assertNotNull($container);
		$bg = (string) ($container['settings']['background_image']['url'] ?? '');
		$this->assertSame('', $bg, 'founder-frame must stay nested, not become background_image');
		$this->assertNotEmpty($container['elements']);
	}

	public function test_absolute_inset_maps_to_offsets(): void
	{
		$mapper = new CssMapper();
		$settings = $mapper->position(array(
			's' => array(
				'pos' => 'absolute',
				'inset' => array(
					'top' => '44px',
					'left' => '-20px',
					'right' => '345px',
					'bottom' => '453px',
				),
			),
		));
		$this->assertSame('absolute', $settings['position'] ?? null);
		$this->assertSame(-20.0, (float) ($settings['left']['size'] ?? 0));
		$this->assertSame(44.0, (float) ($settings['top']['size'] ?? 0));
		// Full four-side computed insets must not over-constrain Elementor.
		$this->assertArrayNotHasKey('right', $settings);
		$this->assertArrayNotHasKey('bottom', $settings);
	}

	public function test_painted_hero_keeps_light_text_on_light_page_canvas(): void
	{
		$layout = array(
			'meta' => array(
				'title' => 'Light Petra',
				'width' => 1440,
				'height' => 900,
				'page' => array(
					'backgroundColor' => 'rgb(247, 250, 252)',
					'color' => 'rgb(26, 39, 64)',
				),
			),
			'sections' => array(
				array(
					'styles' => array('backgroundColor' => 'rgba(0, 0, 0, 0)'),
					'tree' => array(
						'tag' => 'section',
						'cls' => 'hero',
						's' => array(
							'disp' => 'block',
							'w' => 1440,
							'h' => 800,
							'color' => 'rgb(255, 255, 255)',
							'bgImg' => 'linear-gradient(rgb(5,7,15),rgb(11,16,36)), url("https://example.com/hero.jpg")',
							'bgGrad' => true,
							'bgSize' => 'cover',
							'bgPos' => 'center center',
							'bgRepeat' => 'no-repeat',
						),
						'children' => array(
							array(
								'tag' => 'h1',
								'text' => 'Astrologie Schweiz',
								'atomic' => true,
								's' => array(
									'w' => 600,
									'h' => 80,
									'fs' => '40px',
									'color' => 'rgb(255, 255, 255)',
								),
							),
						),
					),
				),
			),
		);

		$out = (new ElementorJsonGenerator())->generate(
			RenderResult::from_array($layout),
			array('confidence' => 90, 'closed_loop' => false)
		);

		$hero = $out['data'][0]['settings'] ?? array();
		$this->assertSame('rgb(255, 255, 255)', $hero['text_color'] ?? null);
		$this->assertNotSame('rgb(26, 39, 64)', $hero['text_color'] ?? null);
	}
}
