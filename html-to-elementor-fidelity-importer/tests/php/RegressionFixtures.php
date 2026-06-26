<?php
/**
 * Shared synthetic layout fixtures for regression testing.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

/**
 * Provides layout documents mirroring common website patterns after Chromium extraction.
 */
trait RegressionFixtures
{

	/**
	 * @return array<string,mixed>
	 */
	protected function bootstrap_layout(): array
	{
		return array(
			'meta' => array('title' => 'Bootstrap Landing'),
			'sections' => array(
				array(
					'tag' => 'nav',
					'tree' => $this->row_node('navbar', array(
						$this->text_node('Brand', 120, 40, 0, 0),
						$this->row_node('nav-links', array(
							$this->text_node('Home', 60, 30, 200, 5),
							$this->text_node('About', 60, 30, 280, 5),
						), 400, 40, 500, 0),
					), 1200, 56, 0, 0, array('bg' => 'rgb(33,37,41)')),
				),
				array(
					'tag' => 'section',
					'tree' => $this->stack_node('hero', array(
						$this->heading_node('Bootstrap Site', 600, 48, 300, 120),
						$this->text_node('Built with Bootstrap 5', 400, 24, 400, 200),
						$this->button_node('Get Started', 160, 44, 520, 260),
					), 1200, 400, 0, 56, array('bg' => 'rgb(13,110,253)', 'ta' => 'center')),
				),
				array(
					'tag' => 'section',
					'tree' => $this->row_node('row features', array(
						$this->card_node('Feature 1', 360, 200, 0, 0),
						$this->card_node('Feature 2', 360, 200, 384, 0),
						$this->card_node('Feature 3', 360, 200, 768, 0),
					), 1200, 220, 0, 480),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function tailwind_layout(): array
	{
		return array(
			'meta' => array('title' => 'Tailwind SaaS'),
			'sections' => array(
				array(
					'tag' => 'header',
					'tree' => $this->row_node('flex justify-between', array(
						$this->heading_node('SaaSify', 120, 32, 48, 20),
						$this->button_node('Sign up', 100, 40, 1050, 16),
					), 1200, 72, 0, 0),
				),
				array(
					'tag' => 'section',
					'tree' => $this->stack_node('text-center py-20', array(
						$this->heading_node('Ship faster', 500, 56, 350, 80),
						$this->text_node('Tailwind-powered landing page', 400, 20, 400, 160),
					), 1200, 280, 0, 72),
				),
				array(
					'tag' => 'section',
					'tree' => $this->row_node('grid grid-cols-2', array(
						$this->stack_node('pricing-card', array(
							$this->heading_node('Pro', 80, 28, 200, 40),
							$this->text_node('$29/mo', 100, 20, 200, 80),
						), 560, 180, 40, 400),
						$this->stack_node('pricing-card', array(
							$this->heading_node('Team', 80, 28, 760, 40),
							$this->text_node('$99/mo', 100, 20, 760, 80),
						), 560, 180, 600, 400),
					), 1200, 220, 0, 380),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function html5up_layout(): array
	{
		return array(
			'meta' => array('title' => 'HTML5 UP'),
			'sections' => array(
				array(
					'tag' => 'section',
					'tree' => $this->stack_node('banner', array(
						$this->heading_node('Spectral', 400, 64, 400, 200),
						$this->text_node('A free responsive site template', 360, 18, 420, 280),
					), 1200, 500, 0, 0, array('bg' => 'rgb(33,40,48)')),
				),
				array(
					'tag' => 'section',
					'tree' => $this->row_node('spotlights', array(
						$this->stack_node('spotlight', array(
							$this->heading_node('Sed magna', 300, 28, 80, 40),
							$this->text_node('Lorem ipsum dolor sit amet.', 300, 16, 80, 80),
						), 580, 160, 20, 540),
						$this->stack_node('spotlight', array(
							$this->heading_node('Quis rhoncus', 300, 28, 700, 40),
							$this->text_node('Praesent ac sem eget.', 300, 16, 700, 80),
						), 580, 160, 600, 540),
					), 1200, 200, 0, 520),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function nested_flex_layout(): array
	{
		return array(
			'meta' => array('title' => 'Nested Flex'),
			'sections' => array(
				array(
					'tag' => 'div',
					'tree' => $this->row_node('complex-grid', array(
						$this->stack_node('col-a', array(
							$this->heading_node('Column A', 280, 24, 20, 20),
							$this->text_node('Nested content', 280, 16, 20, 56),
						), 320, 200, 0, 0),
						$this->stack_node('col-b', array(
							$this->row_node('inner-row', array(
								$this->card_node('Card X', 140, 100, 0, 0),
								$this->card_node('Card Y', 140, 100, 156, 0),
							), 300, 120, 340, 0),
							$this->text_node('Below row', 300, 16, 340, 140),
						), 340, 200, 340, 0),
					), 700, 220, 0, 0),
				),
			),
		);
	}

	/**
	 * @param string                       $cls      CSS class.
	 * @param array<int,array<string,mixed>> $children Children.
	 * @param float                        $w        Width.
	 * @param float                        $h        Height.
	 * @param float                        $x        X.
	 * @param float                        $y        Y.
	 * @param array<string,mixed>          $extra    Extra styles.
	 * @return array<string,mixed>
	 */
	private function row_node(string $cls, array $children, float $w, float $h, float $x, float $y, array $extra = array()): array
	{
		return array_merge(
			array(
				'tag' => 'div',
				'cls' => $cls,
				's' => array_merge(array('disp' => 'flex', 'fd' => 'row', 'w' => $w, 'h' => $h), $extra),
				'bbox' => array('x' => $x, 'y' => $y, 'width' => $w, 'height' => $h),
				'children' => $children,
			)
		);
	}

	/**
	 * @param string                       $cls      CSS class.
	 * @param array<int,array<string,mixed>> $children Children.
	 * @param float                        $w        Width.
	 * @param float                        $h        Height.
	 * @param float                        $x        X.
	 * @param float                        $y        Y.
	 * @param array<string,mixed>          $extra    Extra styles.
	 * @return array<string,mixed>
	 */
	private function stack_node(string $cls, array $children, float $w, float $h, float $x, float $y, array $extra = array()): array
	{
		return array(
			'tag' => 'div',
			'cls' => $cls,
			's' => array_merge(array('disp' => 'flex', 'fd' => 'column', 'w' => $w, 'h' => $h), $extra),
			'bbox' => array('x' => $x, 'y' => $y, 'width' => $w, 'height' => $h),
			'children' => $children,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function heading_node(string $text, float $w, float $h, float $x, float $y): array
	{
		return array(
			'tag' => 'h2',
			'text' => $text,
			'atomic' => true,
			'html' => "<h2>$text</h2>",
			's' => array('fs' => '28px', 'fw' => '700', 'w' => $w, 'h' => $h),
			'bbox' => array('x' => $x, 'y' => $y, 'width' => $w, 'height' => $h),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function text_node(string $text, float $w, float $h, float $x, float $y): array
	{
		return array(
			'tag' => 'p',
			'text' => $text,
			'atomic' => true,
			'html' => "<p>$text</p>",
			's' => array('fs' => '16px', 'w' => $w, 'h' => $h),
			'bbox' => array('x' => $x, 'y' => $y, 'width' => $w, 'height' => $h),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function button_node(string $text, float $w, float $h, float $x, float $y): array
	{
		return array(
			'tag' => 'button',
			'text' => $text,
			'atomic' => true,
			'html' => "<button>$text</button>",
			's' => array('fs' => '14px', 'w' => $w, 'h' => $h, 'bg' => 'rgb(13,110,253)'),
			'bbox' => array('x' => $x, 'y' => $y, 'width' => $w, 'height' => $h),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function card_node(string $title, float $w, float $h, float $x, float $y): array
	{
		return $this->stack_node(
			'card',
			array(
				$this->heading_node($title, $w - 40, 28, $x + 20, $y + 20),
				$this->text_node('Card body text', $w - 40, 16, $x + 20, $y + 56),
			),
			$w,
			$h,
			$x,
			$y,
			array('bg' => 'rgb(255,255,255)', 'br' => 8, 'sh' => '0 2px 8px rgba(0,0,0,.1)')
		);
	}
}
