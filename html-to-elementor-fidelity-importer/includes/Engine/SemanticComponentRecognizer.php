<?php
/**
 * Geometry-aware semantic component recognition.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Semantic Component Recognizer — classifies leaves via VisualLeafClassifier and
 * containers via geometry-derived layout roles. No HTML class or tag heuristics.
 */
final class SemanticComponentRecognizer implements EngineInterface
{

	private VisualLeafClassifier $leaf;
	private CompilerConfidence $confidence_engine;
	private int $threshold;

	/** @var array<string,int> */
	private array $confidence_sum = array();
	private int $confidence_count = 0;

	/** @var array<int,array{reason:string,role:string,confidence:int}> */
	private array $fallback_reasons = array();

	/** @var array<int,string> */
	private const NATIVE_CONTAINER_ROLES = array(
		'layered_block',
		'hero',
		'horizontal_bar',
		'header',
		'footer_band',
		'row_group',
		'column_group',
		'cta_block',
		'stack',
		'section',
		'card',
		'media_block',
		'form_block',
		'faq',
		'testimonial',
		'icon_box',
		'social_icons',
		'pricing',
		'gallery',
		'logo_cloud',
		'team',
		'statistics',
		'timeline',
		'contact',
	);

	public function __construct(?VisualLeafClassifier $leaf = null, int $threshold = 95)
	{
		$this->leaf = $leaf ?? new VisualLeafClassifier();
		$this->confidence_engine = new CompilerConfidence();
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
		$role = (string) ($node['layoutRole'] ?? '');
		$leaf = $this->leaf->classify($node);

		if (null !== $leaf) {
			$confidence = min(100, (int) ($leaf['confidence'] ?? 50) + $this->geometry_boost($node));
			$this->record_confidence($confidence);

			if ('fallback' === ($leaf['kind'] ?? '')) {
				if ($confidence < $this->threshold) {
					$this->record_fallback($node, $role, $confidence);
				}
				return array(
					'kind' => 'fallback',
					'confidence' => $confidence,
					'role' => $role,
				);
			}

			if ('widget' === ($leaf['kind'] ?? '')) {
				$result = array(
					'kind' => 'widget',
					'type' => (string) ($leaf['type'] ?? ''),
					'settings' => $leaf['settings'] ?? array(),
					'confidence' => $confidence,
					'role' => $role,
				);
				// Phase 14 — gate low-confidence composite/interactive widgets.
				$gated = $this->confidence_engine->gate($result, $node);
				if ('fallback' === ($gated['kind'] ?? '')) {
					$this->record_fallback($node, $role, (int) ($gated['confidence'] ?? $confidence));
					return array(
						'kind' => 'fallback',
						'confidence' => (int) ($gated['confidence'] ?? $confidence),
						'role' => $role,
						'fallback_reason' => (string) ($gated['fallback_reason'] ?? 'low_confidence'),
					);
				}
				return array_merge($result, array(
					'confidence' => (int) ($gated['confidence'] ?? $confidence),
					'confidence_reasons' => $gated['confidence_reasons'] ?? array(),
				));
			}
		}

		$confidence = min(100, $this->container_confidence($node, $role));
		$this->record_confidence($confidence);

		return array(
			'kind' => 'container',
			'confidence' => $confidence,
			'role' => $role,
		);
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	public function container_needs_fallback(array $node): bool
	{
		$role = (string) ($node['layoutRole'] ?? '');

		if (in_array($role, self::NATIVE_CONTAINER_ROLES, true)) {
			return false;
		}

		// Forms are reconstructed as native Elementor Form widgets via
		// CompositePatternBuilder — do not force HTML fallback.
		if ('form_block' === $role) {
			return false;
		}

		if (VisualSignals::is_layered($node)) {
			if ('layered_block' === $role || $this->layered_confidence($node) >= $this->threshold) {
				return false;
			}
			$this->record_fallback($node, $role, $this->layered_confidence($node));
			return true;
		}

		if ($this->looks_carousel($node)) {
			$this->record_fallback($node, $role, 30);
			return true;
		}

		if ('row' === ($node['layoutType'] ?? '') && count((array) ($node['children'] ?? array())) >= 2) {
			return false;
		}
		if ('grid' === ($node['layoutType'] ?? '') && count((array) ($node['children'] ?? array())) >= 2) {
			return false;
		}

		$result = $this->classify($node);
		if ('fallback' === ($result['kind'] ?? '') && ($result['confidence'] ?? 0) < $this->threshold) {
			return true;
		}

		// Input clusters without a form_block role still prefer native form mapping.
		if ($this->has_unmappable_inputs($node)) {
			return false;
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	private function geometry_boost(array $node): int
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
	 * @param string              $role   Layout role.
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
		if (count((array) ($node['children'] ?? array())) >= 2) {
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
	 */
	private function layered_confidence(array $node): int
	{
		$signals = VisualSignals::analyze($node);
		$score = 70;
		if ($signals['is_layered']) {
			$score += 10;
		}
		if (null !== $signals['image_child']) {
			$score += 10;
		}
		if (!empty($node['layeredLayout'])) {
			$score += 10;
		}
		return min(100, $score);
	}

	/**
	 * Detect carousel-like geometry: overflow hidden + many equal-width siblings.
	 *
	 * @param array<string,mixed> $node Tree node.
	 */
	private function looks_carousel(array $node): bool
	{
		$s = $node['s'] ?? array();
		$ov = strtolower((string) ($s['ov'] ?? ''));
		if (false === strpos($ov, 'hidden')) {
			return false;
		}
		$children = (array) ($node['children'] ?? array());
		if (count($children) < 4) {
			return false;
		}
		$widths = array();
		foreach ($children as $child) {
			$w = Geometry::bbox($child)['width'];
			if ($w > 40) {
				$widths[] = $w;
			}
		}
		if (count($widths) < 4) {
			return false;
		}
		$avg = array_sum($widths) / count($widths);
		$similar = 0;
		foreach ($widths as $w) {
			if (abs($w - $avg) / max(1, $avg) <= 0.15) {
				++$similar;
			}
		}
		return $similar >= 4;
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	private function has_unmappable_inputs(array $node): bool
	{
		return VisualSignals::count_input_like((array) ($node['children'] ?? array())) >= 3;
	}

	/**
	 * @param array<string,mixed> $node       Tree node.
	 * @param string              $role       Layout role.
	 * @param int                 $confidence Confidence score.
	 */
	private function record_fallback(array $node, string $role, int $confidence): void
	{
		$this->fallback_reasons[] = array(
			'reason' => $this->fallback_reason($node, $role),
			'role' => $role,
			'confidence' => $confidence,
		);
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @param string              $role Layout role.
	 */
	private function fallback_reason(array $node, string $role): string
	{
		if ('form_block' === $role || $this->has_unmappable_inputs($node)) {
			return 'complex_form_fields';
		}
		if ($this->looks_carousel($node)) {
			return 'third_party_slider';
		}
		if (VisualSignals::is_layered($node) && 'layered_block' !== $role) {
			return 'layered_absolute_layout';
		}
		return 'low_confidence_no_native_mapping';
	}

	private function record_confidence(int $confidence): void
	{
		$this->confidence_sum[] = (float) $confidence;
		++$this->confidence_count;
	}
}
