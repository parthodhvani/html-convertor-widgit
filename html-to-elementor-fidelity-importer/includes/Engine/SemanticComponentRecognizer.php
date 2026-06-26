<?php
/**
 * Geometry-aware semantic component recognition.
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
 * Semantic Component Recognizer — classifies blocks using geometry, typography,
 * spacing, visual appearance and context. Never relies on HTML tags alone.
 */
final class SemanticComponentRecognizer implements EngineInterface
{

	private ComponentRecognitionEngine $inner;
	private int $threshold;

	/** @var array<string,int> */
	private array $confidence_sum = array();
	private int $confidence_count = 0;

	/** @var array<int,array{reason:string,role:string,confidence:int}> */
	private array $fallback_reasons = array();

	public function __construct(?WidgetClassifier $classifier = null, int $threshold = 95)
	{
		$this->inner = new ComponentRecognitionEngine($classifier, $threshold);
		$this->threshold = $threshold;
	}

	public function name(): string
	{
		return 'semantic_component_recognizer';
	}

	/**
	 * @param int $threshold Confidence threshold 0–100.
	 */
	public function set_threshold(int $threshold): void
	{
		$this->threshold = max(0, min(100, $threshold));
		$this->inner->set_threshold($this->threshold);
	}

	/**
	 * @return float Average confidence from last classification batch.
	 */
	public function average_confidence(): float
	{
		if ($this->confidence_count <= 0) {
			return 0.0;
		}
		return array_sum($this->confidence_sum) / $this->confidence_count;
	}

	/**
	 * @return array<int,array{reason:string,role:string,confidence:int}>
	 */
	public function fallback_reasons(): array
	{
		return $this->fallback_reasons;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @return array{kind:string,confidence:int,type?:string,settings?:array<string,mixed>,role?:string}
	 */
	public function classify(array $node): array
	{
		$result = $this->inner->classify($node);
		$geometry_boost = $this->geometry_confidence($node);
		$result['confidence'] = min(100, (int) ($result['confidence'] ?? 50) + $geometry_boost);
		$result['role'] = (string) ($result['role'] ?? $node['layoutRole'] ?? '');

		$this->confidence_sum[] = (float) $result['confidence'];
		++$this->confidence_count;

		if ('fallback' === ($result['kind'] ?? '') && ($result['confidence'] ?? 0) < $this->threshold) {
			$this->fallback_reasons[] = array(
				'reason' => $this->fallback_reason($node),
				'role' => (string) ($result['role'] ?? ''),
				'confidence' => (int) ($result['confidence'] ?? 0),
			);
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	public function container_needs_fallback(array $node): bool
	{
		if (!$this->inner->container_needs_fallback($node)) {
			return false;
		}
		$result = $this->inner->classify($node);
		$confidence = min(100, (int) ($result['confidence'] ?? 50) + $this->geometry_confidence($node));
		if ($confidence >= $this->threshold) {
			return false;
		}
		$this->fallback_reasons[] = array(
			'reason' => $this->fallback_reason($node),
			'role' => (string) ($node['layoutRole'] ?? ''),
			'confidence' => $confidence,
		);
		return true;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	private function geometry_confidence(array $node): int
	{
		$boost = 0;
		$box = Geometry::bbox($node);
		if ($box['width'] > 0 && $box['height'] > 0) {
			$boost += 5;
		}
		if (!empty($node['layoutConstraint'])) {
			$boost += 8;
		}
		if (!empty($node['alignment'])) {
			$boost += 5;
		}
		if (!empty($node['layoutRole'])) {
			$boost += 10;
		}
		$s = $node['s'] ?? array();
		if (!empty($s['fs']) && !empty($s['color'])) {
			$boost += 3;
		}
		return $boost;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	private function fallback_reason(array $node): string
	{
		$tag = strtolower((string) ($node['tag'] ?? ''));
		if ('form' === $tag) {
			return 'complex_form_fields';
		}
		if (preg_match('/swiper|slick|carousel/i', (string) ($node['cls'] ?? ''))) {
			return 'third_party_slider';
		}
		if ($this->has_absolute_children($node)) {
			return 'layered_absolute_layout';
		}
		return 'low_confidence_no_native_mapping';
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	private function has_absolute_children(array $node): bool
	{
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$pos = strtolower((string) ($child['s']['pos'] ?? ''));
			if (in_array($pos, array('absolute', 'fixed'), true)) {
				return true;
			}
		}
		return false;
	}
}
