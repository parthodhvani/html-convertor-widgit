<?php
/**
 * Builds Elementor widget element arrays.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Factory for individual Elementor widget nodes.
 */
final class WidgetFactory
{

	/**
	 * Build a raw HTML widget that preserves the original markup verbatim.
	 *
	 * @param string $html Original section HTML.
	 * @return array<string,mixed>
	 */
	public function html(string $html): array
	{
		return $this->widget('html', array('html' => $html));
	}

	/**
	 * Build a widget of an arbitrary type with the given settings.
	 *
	 * @param string              $type     Elementor widget type.
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,mixed>
	 */
	public function widget(string $type, array $settings): array
	{
		return array(
			'id' => ElementId::generate(),
			'elType' => 'widget',
			'widgetType' => $type,
			'settings' => $settings,
			'elements' => array(),
		);
	}
}
