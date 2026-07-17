<?php
/**
 * Star-rating recognition must not steal FAQ / multi-child trees.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\CompositePatternBuilder;
use PHPUnit\Framework\TestCase;

final class StarRatingScopeTest extends TestCase
{
	public function test_faq_with_star_glyphs_does_not_become_star_rating(): void
	{
		$builder = new CompositePatternBuilder();
		$node = array(
			'tag' => 'div',
			'cls' => 'faq',
			'layoutRole' => 'faq',
			'text' => 'Was kostet eine Bewertung ★★★★☆?',
			'html' => '<div class="faq"><details><summary>Rating?</summary><p>★★★★☆</p></details></div>',
			'children' => array(
				array(
					'tag' => 'details',
					'cls' => 'faq-item',
					'children' => array(
						array('tag' => 'summary', 'cls' => 'faq-q', 'text' => 'Rating?', 'atomic' => true),
						array('tag' => 'div', 'cls' => 'faq-a', 'text' => '★★★★☆', 'atomic' => true),
					),
				),
				array(
					'tag' => 'details',
					'cls' => 'faq-item',
					'children' => array(
						array('tag' => 'summary', 'cls' => 'faq-q', 'text' => 'Zwei?', 'atomic' => true),
						array('tag' => 'div', 'cls' => 'faq-a', 'text' => 'Antwort', 'atomic' => true),
					),
				),
			),
			's' => array('disp' => 'flex', 'fd' => 'column', 'gap' => '14px'),
		);

		$out = $builder->build($node);
		$this->assertTrue(null === $out || 'star-rating' !== ($out['type'] ?? ''));
	}
}
