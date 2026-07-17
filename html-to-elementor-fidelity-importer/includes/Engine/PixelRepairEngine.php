<?php
/**
 * Iteratively repairs Elementor JSON for pixel-level fidelity.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Pixel Repair Engine — adjusts container width, gap, padding, alignment,
 * typography and responsive values when visual fidelity is below target.
 */
final class PixelRepairEngine implements EngineInterface
{

	private int $max_iterations;

	public function __construct(int $max_iterations = 3)
	{
		$this->max_iterations = $max_iterations;
	}

	public function name(): string
	{
		return 'pixel_repair_engine';
	}

	/**
	 * Run repair iterations until no improvement or max reached.
	 *
	 * @param array<int,array<string,mixed>> $elementor_data Elements.
	 * @param array<string,mixed>            $context        Sections, validation, report.
	 * @return array{data:array<int,array<string,mixed>>,iterations:int,changed:bool,repairs:array<int,string>}
	 */
	public function repair(array $elementor_data, array $context): array
	{
		$changed = false;
		$repairs = array();
		$iteration = 0;
		$sections = $context['sections'] ?? array();
		$comparator = new GeometryComparator();
		$best_geometry = (int) (($context['validation']['geometry_similarity'] ?? 0));
		$threshold = (int) ($context['validation']['threshold'] ?? $context['threshold'] ?? 95);

		while ($iteration < $this->max_iterations) {
			$round_changed = false;

			$elementor_data = $this->apply_layout_constraints($elementor_data, $sections, $round_changed, $repairs);
			$elementor_data = $this->promote_simple_html($elementor_data, $round_changed, $repairs);
			$elementor_data = $this->fix_container_flex($elementor_data, $round_changed, $repairs);
			$elementor_data = $this->strip_container_margins($elementor_data, $round_changed, $repairs);
			$elementor_data = $this->apply_gap_from_source($elementor_data, $sections, $round_changed, $repairs);
			$elementor_data = $this->apply_padding_from_whitespace($elementor_data, $sections, $round_changed, $repairs);

			if (!$round_changed) {
				break;
			}
			$changed = true;
			++$iteration;

			$geo = $comparator->compare($sections, $elementor_data);
			$geometry_score = (int) ($geo['geometry_similarity'] ?? 0);
			if ($geometry_score <= $best_geometry) {
				break;
			}
			$best_geometry = $geometry_score;
			if ($geometry_score >= $threshold) {
				break;
			}
		}

		return array(
			'data' => $elementor_data,
			'iterations' => $iteration,
			'changed' => $changed,
			'repairs' => $repairs,
			'geometry_similarity' => $best_geometry,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param array<int,array<string,mixed>> $sections Source sections.
	 * @param bool                            $changed  Changed flag.
	 * @param array<int,string>               $repairs  Repair log.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_layout_constraints(array $elements, array $sections, bool &$changed, array &$repairs): array
	{
		$constraints = $this->collect_constraints($sections);
		foreach ($elements as $i => $el) {
			if ('container' !== ($el['elType'] ?? '')) {
				continue;
			}
			$cls = (string) ($el['settings']['_css_classes'] ?? '');
			foreach ($constraints as $c) {
				if ('' !== $cls && $cls === ($c['cls'] ?? '')) {
					if (!empty($c['direction']) && ($el['settings']['flex_direction'] ?? '') !== $c['direction']) {
						$elements[$i]['settings']['flex_direction'] = $c['direction'];
						$changed = true;
						$repairs[] = 'flex_direction:' . $cls;
					}
					if (($c['gap'] ?? 0) > 0) {
						$jc = strtolower((string) ($el['settings']['flex_justify_content'] ?? ''));
						$distributed = in_array($jc, array('space-between', 'space-around', 'space-evenly'), true);
						$existing = (float) ($el['settings']['flex_gap']['size'] ?? 0);
						$target = (float) $c['gap'];
						// Never stamp free-space as gap on distributed justify rows.
						if ($distributed && $target > 64) {
							continue;
						}
						if (abs($existing - $target) > 0.5) {
							$elements[$i]['settings']['flex_gap'] = array(
								'column' => (string) $c['gap'],
								'row' => (string) $c['gap'],
								'isLinked' => true,
								'unit' => 'px',
								'size' => $target,
							);
							$changed = true;
							$repairs[] = 'flex_gap:' . $cls;
						}
					}
					if (!empty($c['justify'])) {
						$elements[$i]['settings']['flex_justify_content'] = $c['justify'];
						$changed = true;
					}
					if (!empty($c['align_items'])) {
						$elements[$i]['settings']['flex_align_items'] = $c['align_items'];
						$changed = true;
					}
				}
			}
			$elements[$i]['elements'] = $this->apply_layout_constraints(
				(array) ($el['elements'] ?? array()),
				$sections,
				$changed,
				$repairs
			);
		}
		return $elements;
	}

	/**
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_constraints(array $sections): array
	{
		$out = array();
		foreach ($sections as $section) {
			$this->walk_constraints($section['tree'] ?? null, $out);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed>|null         $node Node.
	 * @param array<int,array<string,mixed>>   $out  Output (by ref).
	 */
	private function walk_constraints($node, array &$out): void
	{
		if (!is_array($node)) {
			return;
		}
		$c = $node['layoutConstraint'] ?? array();
		if (!empty($c)) {
			$out[] = array(
				'cls' => (string) ($node['cls'] ?? ''),
				'direction' => (string) ($c['direction'] ?? ''),
				'gap' => (float) ($c['gap'] ?? 0),
				'justify' => (string) ($node['alignment']['justify'] ?? ''),
				'align_items' => (string) ($node['alignment']['align_items'] ?? ''),
			);
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->walk_constraints($child, $out);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param bool                           $changed  Changed flag.
	 * @param array<int,string>              $repairs  Repair log.
	 * @return array<int,array<string,mixed>>
	 */
	private function promote_simple_html(array $elements, bool &$changed, array &$repairs): array
	{
		foreach ($elements as $i => $el) {
			if ('widget' === ($el['elType'] ?? '') && 'html' === ($el['widgetType'] ?? '')) {
				$html = trim((string) ($el['settings']['html'] ?? ''));
				if (preg_match('/^<h([1-6])[^>]*>(.*)<\/h\1>$/is', $html, $m)) {
					$elements[$i]['widgetType'] = 'heading';
					$elements[$i]['settings']['title'] = wp_strip_all_tags($m[2]);
					$elements[$i]['settings']['header_size'] = 'h' . $m[1];
					unset($elements[$i]['settings']['html']);
					$changed = true;
					$repairs[] = 'promote_heading';
				} elseif (preg_match('/^<p[^>]*>(.*)<\/p>$/is', $html, $m)) {
					$elements[$i]['widgetType'] = 'text-editor';
					$elements[$i]['settings']['editor'] = '<p>' . esc_html(wp_strip_all_tags($m[1])) . '</p>';
					unset($elements[$i]['settings']['html']);
					$changed = true;
					$repairs[] = 'promote_text';
				}
			}
			$elements[$i]['elements'] = $this->promote_simple_html((array) ($el['elements'] ?? array()), $changed, $repairs);
		}
		return $elements;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param bool                           $changed  Changed flag.
	 * @param array<int,string>              $repairs  Repair log.
	 * @return array<int,array<string,mixed>>
	 */
	private function fix_container_flex(array $elements, bool &$changed, array &$repairs): array
	{
		foreach ($elements as $i => $el) {
			if ('container' === ($el['elType'] ?? '') && !empty($el['elements']) && empty($el['settings']['flex_direction'])) {
				$elements[$i]['settings']['flex_direction'] = 'column';
				$changed = true;
				$repairs[] = 'default_flex_column';
			}
			$elements[$i]['elements'] = $this->fix_container_flex((array) ($el['elements'] ?? array()), $changed, $repairs);
		}
		return $elements;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @param bool                           $changed  Changed flag.
	 * @param array<int,string>              $repairs  Repair log.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_gap_from_source(array $elements, array $sections, bool &$changed, array &$repairs): array
	{
		$gaps = array();
		foreach ($sections as $section) {
			$this->walk_whitespace($section['tree'] ?? null, $gaps);
		}
		if (empty($gaps)) {
			return $elements;
		}
		$median_gap = Geometry::median(array_values($gaps));
		if ($median_gap <= 0) {
			return $elements;
		}
		return $this->set_gap_recursive($elements, $median_gap, $changed, $repairs);
	}

	/**
	 * @param array<string,mixed>|null       $node Node.
	 * @param array<string,float>            $gaps Gaps (by ref).
	 */
	private function walk_whitespace($node, array &$gaps): void
	{
		if (!is_array($node)) {
			return;
		}
		$ws = $node['whitespace'] ?? array();
		if (!empty($ws['gap'])) {
			$gaps[(string) ($node['cls'] ?? uniqid('n', true))] = (float) $ws['gap'];
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->walk_whitespace($child, $gaps);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param float                          $gap      Gap px.
	 * @param bool                           $changed  Changed flag.
	 * @param array<int,string>              $repairs  Repair log.
	 * @return array<int,array<string,mixed>>
	 */
	private function set_gap_recursive(array $elements, float $gap, bool &$changed, array &$repairs): array
	{
		foreach ($elements as $i => $el) {
			if ('container' === ($el['elType'] ?? '') && count((array) ($el['elements'] ?? array())) >= 2 && empty($el['settings']['flex_gap'])) {
				$elements[$i]['settings']['flex_gap'] = array(
					'column' => (string) round($gap),
					'row' => (string) round($gap),
					'isLinked' => true,
					'unit' => 'px',
					'size' => round($gap),
				);
				$changed = true;
				$repairs[] = 'inferred_gap';
			}
			$elements[$i]['elements'] = $this->set_gap_recursive((array) ($el['elements'] ?? array()), $gap, $changed, $repairs);
		}
		return $elements;
	}

	/**
	 * Remove margin controls from containers — spacing belongs in gap/padding.
	 *
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param bool                           $changed  Changed flag.
	 * @param array<int,string>              $repairs  Repair log.
	 * @return array<int,array<string,mixed>>
	 */
	private function strip_container_margins(array $elements, bool &$changed, array &$repairs): array
	{
		foreach ($elements as $i => $el) {
			if ('container' === ($el['elType'] ?? '') && isset($el['settings']['margin'])) {
				unset($elements[$i]['settings']['margin']);
				$changed = true;
				$repairs[] = 'strip_margin';
			}
			$elements[$i]['elements'] = $this->strip_container_margins(
				(array) ($el['elements'] ?? array()),
				$changed,
				$repairs
			);
		}
		return $elements;
	}

	/**
	 * Apply measured padding from whitespace analyzer.
	 *
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @param bool                           $changed  Changed flag.
	 * @param array<int,string>              $repairs  Repair log.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_padding_from_whitespace(array $elements, array $sections, bool &$changed, array &$repairs): array
	{
		$padding_map = array();
		foreach ($sections as $section) {
			$this->walk_padding($section['tree'] ?? null, $padding_map);
		}
		return $this->set_padding_recursive($elements, $padding_map, $changed, $repairs);
	}

	/**
	 * @param array<string,mixed>|null            $node Node.
	 * @param array<string,array<string,mixed>>   $map  Padding map (by ref).
	 */
	private function walk_padding($node, array &$map): void
	{
		if (!is_array($node)) {
			return;
		}
		$ws = $node['whitespace'] ?? array();
		if (!empty($ws['padding']) && is_array($ws['padding'])) {
			$cls = (string) ($node['cls'] ?? '');
			if ('' !== $cls) {
				$map[$cls] = $ws['padding'];
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->walk_padding($child, $map);
		}
	}

	/**
	 * @param array<int,array<string,mixed>>    $elements Elements.
	 * @param array<string,array<string,mixed>> $map      Padding map.
	 * @param bool                              $changed  Changed flag.
	 * @param array<int,string>                 $repairs  Repair log.
	 * @return array<int,array<string,mixed>>
	 */
	private function set_padding_recursive(array $elements, array $map, bool &$changed, array &$repairs): array
	{
		foreach ($elements as $i => $el) {
			if ('container' === ($el['elType'] ?? '')) {
				$cls = (string) ($el['settings']['_css_classes'] ?? '');
				if ('' !== $cls && isset($map[$cls]) && empty($el['settings']['padding'])) {
					$p = $map[$cls];
					$elements[$i]['settings']['padding'] = array(
						'unit' => 'px',
						'top' => (string) round((float) ($p['top'] ?? 0)),
						'right' => (string) round((float) ($p['right'] ?? 0)),
						'bottom' => (string) round((float) ($p['bottom'] ?? 0)),
						'left' => (string) round((float) ($p['left'] ?? 0)),
						'isLinked' => false,
					);
					$changed = true;
					$repairs[] = 'padding:' . $cls;
				}
			}
			$elements[$i]['elements'] = $this->set_padding_recursive(
				(array) ($el['elements'] ?? array()),
				$map,
				$changed,
				$repairs
			);
		}
		return $elements;
	}
}
