<?php
/**
 * Compiler confidence scoring for widget / layout decisions.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Compiler Confidence System — every classification decision carries a score
 * and reason list. Low-confidence native widgets fall back to HTML.
 */
final class CompilerConfidence implements EngineInterface
{

	public const DEFAULT_THRESHOLD = 80;

	public function name(): string
	{
		return 'compiler_confidence';
	}

	/**
	 * Score a classification decision.
	 *
	 * @param array<string,mixed> $node   Source node.
	 * @param string              $type   Proposed widget type.
	 * @param array<int,string>   $reasons Positive evidence.
	 * @param array<int,string>   $risks   Negative evidence.
	 * @return array{confidence:int,reasons:array<int,string>,risks:array<int,string>,prefer_html:bool}
	 */
	public function score(array $node, string $type, array $reasons = array(), array $risks = array()): array
	{
		$confidence = 60;
		foreach ($reasons as $reason) {
			$confidence += $this->reason_weight($reason);
		}
		foreach ($risks as $risk) {
			$confidence -= $this->risk_weight($risk);
		}

		// Geometry / style boosts.
		$s = $node['s'] ?? array();
		if ('button' === $type) {
			if (VisualSignals::has_background($s)) {
				$confidence += 8;
				$reasons[] = 'background';
			}
			if (VisualSignals::padding_sum($s) >= 6) {
				$confidence += 6;
				$reasons[] = 'padding';
			}
			if (!empty($s['br']) || !empty($s['brad'])) {
				$confidence += 4;
				$reasons[] = 'border_radius';
			}
			if (!empty($node['href']) || 'button' === ($node['tag'] ?? '')) {
				$confidence += 5;
				$reasons[] = 'interactive';
			}
		}
		if ('heading' === $type) {
			if (VisualSignals::font_size_px($s) >= 22) {
				$confidence += 10;
				$reasons[] = 'font_size';
			}
			if ((int) preg_replace('/\D/', '', (string) ($s['fw'] ?? '400')) >= 600) {
				$confidence += 6;
				$reasons[] = 'font_weight';
			}
		}
		if ('image' === $type && (!empty($node['src']) || !empty($s['bgImg']))) {
			$confidence += 15;
			$reasons[] = 'image_source';
		}
		if (!empty($node['typography']['textWidth'])) {
			$confidence += 3;
			$reasons[] = 'text_metrics';
		}

		$confidence = max(0, min(100, $confidence));
		$threshold = (int) ($node['confidenceThreshold'] ?? self::DEFAULT_THRESHOLD);

		return array(
			'confidence' => $confidence,
			'reasons' => array_values(array_unique($reasons)),
			'risks' => array_values(array_unique($risks)),
			'prefer_html' => $confidence < $threshold && !in_array($type, array('heading', 'text-editor', 'image'), true),
		);
	}

	/**
	 * Apply confidence gating to a classification result.
	 *
	 * @param array<string,mixed> $classified Classification.
	 * @param array<string,mixed> $node       Node.
	 * @return array<string,mixed>
	 */
	public function gate(array $classified, array $node): array
	{
		if (('widget' !== ($classified['kind'] ?? '')) || empty($classified['type'])) {
			return $classified;
		}
		$existing = (int) ($classified['confidence'] ?? 0);
		$scored = $this->score(
			$node,
			(string) $classified['type'],
			(array) ($classified['reasons'] ?? array()),
			(array) ($classified['risks'] ?? array())
		);
		$confidence = max($existing, $scored['confidence']);
		$classified['confidence'] = $confidence;
		$classified['confidence_reasons'] = $scored['reasons'];
		$classified['confidence_risks'] = $scored['risks'];
		if ($scored['prefer_html'] && $confidence < self::DEFAULT_THRESHOLD) {
			$classified['kind'] = 'fallback';
			$classified['fallback_reason'] = 'low_confidence:' . $classified['type'] . ':' . $confidence;
			unset($classified['type'], $classified['settings']);
		}
		return $classified;
	}

	private function reason_weight(string $reason): int
	{
		$map = array(
			'background' => 8,
			'padding' => 6,
			'border_radius' => 4,
			'interactive' => 5,
			'font_size' => 10,
			'font_weight' => 6,
			'image_source' => 15,
			'text_metrics' => 3,
			'aria_role' => 5,
			'geometry' => 7,
		);
		return $map[$reason] ?? 3;
	}

	private function risk_weight(string $risk): int
	{
		$map = array(
			'ambiguous_link' => 12,
			'layered_children' => 15,
			'slider' => 20,
			'empty_text' => 10,
			'complex_html' => 14,
		);
		return $map[$risk] ?? 5;
	}
}
