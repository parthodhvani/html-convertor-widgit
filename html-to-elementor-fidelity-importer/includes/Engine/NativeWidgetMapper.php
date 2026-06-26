<?php
/**
 * Maps recognised components to native Elementor widget types.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Native Widget Mapper — prefers Elementor containers and native widgets.
 * HTML widget is only emitted when no native representation exists.
 */
final class NativeWidgetMapper implements EngineInterface
{

	/** @var array<string,string> */
	private const WIDGET_MAP = array(
		'heading' => 'heading',
		'text' => 'text-editor',
		'text-editor' => 'text-editor',
		'image' => 'image',
		'button' => 'button',
		'icon' => 'icon',
		'icon-box' => 'icon-box',
		'counter' => 'counter',
		'divider' => 'divider',
		'spacer' => 'spacer',
		'accordion' => 'accordion',
		'tabs' => 'tabs',
		'toggle' => 'toggle',
		'progress' => 'progress',
		'video' => 'video',
		'google_maps' => 'google_maps',
		'gallery' => 'gallery',
		'carousel' => 'image-carousel',
		'slides' => 'slides',
		'social-icons' => 'social-icons',
		'form' => 'form',
		'nav-menu' => 'nav-menu',
		'search' => 'search',
		'price-table' => 'price-table',
		'testimonial' => 'testimonial',
		'cta' => 'call-to-action',
		'star-rating' => 'star-rating',
	);

	public function name(): string
	{
		return 'native_widget_mapper';
	}

	/**
	 * Resolve the Elementor widget type for a classification result.
	 *
	 * @param array<string,mixed> $classification From ComponentRecognitionEngine.
	 * @return string Elementor widget type or empty for containers/patterns.
	 */
	public function widget_type(array $classification): string
	{
		if ('widget' === ($classification['kind'] ?? '')) {
			return (string) ($classification['type'] ?? '');
		}
		if ('pattern' === ($classification['kind'] ?? '')) {
			return ''; // Patterns are handled by specialised reconstructors.
		}
		return 'html';
	}

	/**
	 * Whether the classification should produce an HTML widget.
	 *
	 * @param array<string,mixed> $classification Classification result.
	 * @param int                 $threshold    Confidence threshold.
	 */
	public function should_fallback_html(array $classification, int $threshold = 95): bool
	{
		if ('fallback' === ($classification['kind'] ?? '')) {
			return ($classification['confidence'] ?? 0) < $threshold;
		}
		return false;
	}

	/**
	 * @return array<string,string> Supported native widget types.
	 */
	public function supported_widgets(): array
	{
		return self::WIDGET_MAP;
	}

	/**
	 * Coverage statistics for a widget breakdown.
	 *
	 * @param array<string,int> $breakdown Widget type counts.
	 * @return array{native_pct:float,html_pct:float,native_count:int,html_count:int,total:int}
	 */
	public function coverage_stats(array $breakdown): array
	{
		$html = (int) ($breakdown['html'] ?? 0);
		$total = max(1, array_sum($breakdown));
		$native = $total - $html;
		return array(
			'native_count' => $native,
			'html_count' => $html,
			'total' => $total,
			'native_pct' => round($native / $total * 100, 1),
			'html_pct' => round($html / $total * 100, 1),
		);
	}
}
