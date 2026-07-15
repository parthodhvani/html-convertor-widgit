<?php
/**
 * Confidence-based component recognition engine.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

use HtmlToElementor\Elementor\WidgetClassifier;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Component Recognition Engine — classifies blocks using visual appearance,
 * geometry, typography, layout graph context and neighbour relationships.
 * HTML widgets are only recommended when confidence is below threshold.
 */
final class ComponentRecognitionEngine implements EngineInterface
{

	private WidgetClassifier $classifier;
	private int $threshold;

	public function __construct(?WidgetClassifier $classifier = null, int $threshold = 95)
	{
		$this->classifier = $classifier ?? new WidgetClassifier();
		$this->threshold = $threshold;
	}

	public function name(): string
	{
		return 'component_recognition';
	}

	/**
	 * @param int $threshold Minimum confidence (0–100) for native widgets.
	 */
	public function set_threshold(int $threshold): void
	{
		$this->threshold = max(0, min(100, $threshold));
	}

	/**
	 * Classify a node with a confidence score.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @return array{kind:string,confidence:int,type?:string,settings?:array<string,mixed>,role?:string}
	 */
	public function classify(array $node): array
	{
		$role = (string) ($node['layoutRole'] ?? $this->classifier->role($node));
		$base = $this->classifier->classify($node);

		if (null === $base) {
			return array(
				'kind' => 'container',
				'confidence' => $this->container_confidence($node, $role),
				'role' => $role,
			);
		}

		if ('fallback' === $base['kind']) {
			$confidence = $this->fallback_confidence($node, $role);
			if ($confidence >= $this->threshold) {
				// High-confidence fallback candidates may still map natively.
				$native = $this->try_native_override($node, $role);
				if (null !== $native) {
					return $native;
				}
			}
			return array(
				'kind' => 'fallback',
				'confidence' => $confidence,
				'role' => $role,
			);
		}

		return array(
			'kind' => 'widget',
			'type' => (string) ($base['type'] ?? ''),
			'settings' => $base['settings'] ?? array(),
			'confidence' => $this->widget_confidence($node, (string) ($base['type'] ?? ''), $role),
			'role' => $role,
		);
	}

	/**
	 * Whether a container should use HTML fallback.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	public function container_needs_fallback(array $node): bool
	{
		$role = (string) ($node['layoutRole'] ?? '');

		// Layered blocks, horizontal bars and structural groups are reconstructed natively.
		if (in_array($role, array('layered_block', 'horizontal_bar', 'footer_band', 'cta_block', 'row_group', 'column_group'), true)) {
			return false;
		}

		// Grid rows/columns with 2+ children should stay native.
		$layout = (string) ($node['layoutType'] ?? '');
		if (in_array($layout, array('row', 'grid'), true) && count((array) ($node['children'] ?? array())) >= 2) {
			return false;
		}

		$result = $this->classify($node);
		if ('fallback' === $result['kind'] && ($result['confidence'] ?? 0) < $this->threshold) {
			return true;
		}

		return $this->classifier->container_needs_fallback($node) && 'layered_block' !== $role;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @param string              $role Semantic role.
	 */
	private function widget_confidence(array $node, string $type, string $role): int
	{
		$score = 70;
		$tag = strtolower((string) ($node['tag'] ?? ''));

		if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true) && 'heading' === $type) {
			$score += 25;
		}
		if (in_array($tag, array('p', 'blockquote'), true) && 'text-editor' === $type) {
			$score += 20;
		}
		if ('img' === $tag && 'image' === $type) {
			$score += 25;
		}
		if ('button' === $tag && 'button' === $type) {
			$score += 25;
		}
		if ('' !== $role) {
			$score += 5;
		}
		if (!empty($node['text']) || !empty($node['html'])) {
			$score += 5;
		}
		$s = $node['s'] ?? array();
		if (!empty($s['fs']) || !empty($s['color'])) {
			$score += 5;
		}

		return min(100, $score);
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @param string              $role Semantic role.
	 */
	private function container_confidence(array $node, string $role): int
	{
		$score = 60;
		if ('' !== $role) {
			$score += 15;
		}
		if (!empty($node['layoutType'])) {
			$score += 10;
		}
		$children = (array) ($node['children'] ?? array());
		if (count($children) >= 2) {
			$score += 10;
		}
		$s = $node['s'] ?? array();
		if (!empty($s['bg']) || !empty($s['bgImg'])) {
			$score += 5;
		}
		return min(100, $score);
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @param string              $role Semantic role.
	 */
	private function fallback_confidence(array $node, string $role): int
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$score = 40;

		if ('form' === $tag) {
			$score = 55; // Forms are hard — lower confidence for native.
		}
		if ('layered_block' === $role) {
			$score = 85;
		}
		if ('horizontal_bar' === $role) {
			$score = 80;
		}
		if ($this->looks_carousel_geometry($node)) {
			$score = 30;
		}

		return min(100, $score);
	}

	/**
	 * Attempt to override a fallback with a native mapping.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @param string              $role Semantic role.
	 * @return array{kind:string,confidence:int,type?:string,settings?:array<string,mixed>,role?:string}|null
	 */
	private function try_native_override(array $node, string $role): ?array
	{
		if ('layered_block' === $role) {
			return array('kind' => 'pattern', 'type' => 'layered_block', 'confidence' => 90, 'role' => 'layered_block');
		}
		if ('horizontal_bar' === $role) {
			return array('kind' => 'pattern', 'type' => 'horizontal_bar', 'confidence' => 85, 'role' => 'horizontal_bar');
		}
		if ('form_block' === $role) {
			return array('kind' => 'pattern', 'type' => 'form_block', 'confidence' => 70, 'role' => 'form_block');
		}
		return null;
	}

	/**
	 * Detect carousel-like geometry without class-name heuristics.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function looks_carousel_geometry(array $node): bool
	{
		$s = $node['s'] ?? array();
		$ov = strtolower((string) ($s['ov'] ?? ''));
		if (false === strpos($ov, 'hidden')) {
			return false;
		}
		return count((array) ($node['children'] ?? array())) >= 4;
	}
}
