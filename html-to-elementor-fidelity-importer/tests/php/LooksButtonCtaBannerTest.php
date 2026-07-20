<?php
/**
 * VisualSignals button detection must not treat CTA banners as buttons.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Tests;

use HtmlToElementor\Engine\VisualSignals;
use PHPUnit\Framework\TestCase;

final class LooksButtonCtaBannerTest extends TestCase
{

	public function test_cta_banner_is_not_a_button(): void
	{
		$node = array(
			'tag' => 'div',
			'cls' => 'cta-banner reveal',
			'text' => 'Lernen wir uns unverbindlich kennen',
			's' => array(
				'bg' => 'rgb(5,7,15)',
				'pt' => 64,
				'pb' => 64,
				'pl' => 64,
				'pr' => 64,
				'h' => 400,
				'w' => 1152,
			),
			'bbox' => array('width' => 1152, 'height' => 400),
		);
		$this->assertFalse(VisualSignals::looks_button($node));
	}

	public function test_standalone_cta_class_still_matches(): void
	{
		$node = array(
			'tag' => 'a',
			'cls' => 'cta',
			'text' => 'View work',
			's' => array('pt' => 14, 'pb' => 14, 'pl' => 28, 'pr' => 28, 'h' => 48, 'w' => 160),
			'bbox' => array('width' => 160, 'height' => 48),
		);
		$this->assertTrue(VisualSignals::looks_button($node));
	}

	public function test_btn_gold_matches(): void
	{
		$node = array(
			'tag' => 'a',
			'cls' => 'btn btn-gold',
			'text' => 'Termin buchen',
			's' => array(),
			'bbox' => array('width' => 180, 'height' => 48),
		);
		$this->assertTrue(VisualSignals::looks_button($node));
	}

	public function test_hero_cta_wrapper_is_not_a_button(): void
	{
		$node = array(
			'tag' => 'div',
			'cls' => 'hero-cta',
			'text' => 'Kostenloses Kennenlerngespräch',
			's' => array('h' => 52, 'w' => 500, 'pt' => 0, 'pb' => 0),
			'bbox' => array('width' => 500, 'height' => 52),
		);
		$this->assertFalse(VisualSignals::looks_button($node));
	}
}
