'use strict';

/**
 * Classify fidelity mismatches into root-cause categories for ranking.
 */

/**
 * @param {object} row Per-page accuracy row.
 * @returns {Array<{category:string,impact:number,evidence:string}>}
 */
function classifyMismatches(row) {
  const issues = [];
  const v = row.validation || {};
  const pixel = Number(row.pixel_similarity || 0);
  const geo = Number(v.geometry_similarity ?? row.geometry_similarity ?? 0);
  const typo = Number(v.typography_similarity ?? row.typography_similarity ?? 0);
  const spacing = Number(v.spacing_similarity ?? row.spacing_similarity ?? 0);
  const responsive = Number(v.responsive_similarity ?? row.responsive_similarity ?? 0);
  const colour = Number(v.colour ?? row.colour ?? 0);
  const source = Number(v.source_frames || 0);
  const emitted = Number(v.emitted_frames || 0);
  const matched = Number(v.matched_frames || 0);
  const html = Number(row.html_widgets || 0);
  const native = Number(row.native_widgets || 0);
  const totalWidgets = native + html;

  const loss = (score) => Math.max(0, 100 - score);

  // Prefer matched/source — composites absorb children so emitted < source is normal.
  const coverage = source > 0 ? matched / source : 1;
  if (source > 0 && coverage < 0.85) {
    issues.push({
      category: 'missing_emission',
      impact: loss(geo) * 0.45 + (1 - coverage) * 40,
      evidence: `frames emitted=${emitted}/${source} matched=${matched} coverage=${Math.round(coverage * 100)}%`,
    });
  }

  if (spacing < 90) {
    issues.push({
      category: 'spacing_gap',
      impact: loss(spacing) * 0.7,
      evidence: `spacing=${spacing}`,
    });
  }

  if (geo < 90 && emitted >= source * 0.85) {
    issues.push({
      category: 'geometry_position_size',
      impact: loss(geo) * 0.8,
      evidence: `geo=${geo} bbox_delta=${v.bbox_delta || 0}`,
    });
  }

  if (typo < 95) {
    issues.push({
      category: 'typography_mismatch',
      impact: loss(typo) * 0.6,
      evidence: `typography=${typo}`,
    });
  }

  if (colour < 85) {
    issues.push({
      category: 'color_background',
      impact: loss(colour) * 0.55,
      evidence: `colour=${colour}`,
    });
  }

  if (responsive < 85) {
    issues.push({
      category: 'responsive_behavior',
      impact: loss(responsive) * 0.5,
      evidence: `responsive=${responsive}`,
    });
  }

  if (pixel < 85 && geo >= 90) {
    issues.push({
      category: 'pixel_paint_preview',
      impact: loss(pixel) * 0.75,
      evidence: `pixel=${pixel} geo=${geo} (preview/paint gap)`,
    });
  } else if (pixel < 70) {
    issues.push({
      category: 'pixel_overall',
      impact: loss(pixel) * 0.65,
      evidence: `pixel=${pixel}`,
    });
  }

  if (totalWidgets > 0 && html / totalWidgets > 0.15) {
    issues.push({
      category: 'html_fallback_overuse',
      impact: (html / totalWidgets) * 50,
      evidence: `html=${html} native=${native}`,
    });
  }

  const repairs = Array.isArray(v.repairs) ? v.repairs : [];
  const gapRepairs = repairs.filter((r) => String(r).includes('flex_gap')).length;
  if (gapRepairs >= 3 && spacing < 95) {
    issues.push({
      category: 'flex_gap_unstable',
      impact: Math.min(40, gapRepairs * 4),
      evidence: `gap_repairs=${gapRepairs}`,
    });
  }

  if (String(row.error || '')) {
    issues.push({
      category: 'pipeline_error',
      impact: 100,
      evidence: String(row.error).slice(0, 160),
    });
  }

  if (!issues.length && Number(row.composite || 0) < 95) {
    issues.push({
      category: 'residual_fidelity',
      impact: loss(Number(row.composite || 0)),
      evidence: `composite=${row.composite}`,
    });
  }

  return issues.sort((a, b) => b.impact - a.impact);
}

/**
 * Aggregate category impacts across the suite.
 *
 * @param {Array<object>} rows Page rows.
 * @returns {Array<object>}
 */
function rankCategories(rows) {
  const map = new Map();
  for (const row of rows) {
    const issues = row.issues || classifyMismatches(row);
    for (const issue of issues) {
      const cur = map.get(issue.category) || {
        category: issue.category,
        pages: 0,
        total_impact: 0,
        examples: [],
      };
      cur.pages += 1;
      cur.total_impact += issue.impact;
      if (cur.examples.length < 5) {
        cur.examples.push({ id: row.id, evidence: issue.evidence, impact: Math.round(issue.impact * 10) / 10 });
      }
      map.set(issue.category, cur);
    }
  }

  return Array.from(map.values())
    .map((c) => ({
      ...c,
      avg_impact: Math.round((c.total_impact / Math.max(1, c.pages)) * 10) / 10,
      score: Math.round(c.total_impact * 10) / 10,
    }))
    .sort((a, b) => b.score - a.score);
}

/**
 * Weighted composite accuracy for a page.
 *
 * @param {object} metrics Metrics bag.
 * @returns {number}
 */
function compositeScore(metrics) {
  const pixel = Number(metrics.pixel_similarity || 0);
  const geo = Number(metrics.geometry_similarity || 0);
  const typo = Number(metrics.typography_similarity || 0);
  const spacing = Number(metrics.spacing_similarity || 0);
  const responsive = Number(metrics.responsive_similarity || 0);
  const colour = Number(metrics.colour || 0);
  // Pixel + geometry dominate; typography/spacing/responsive/colour support.
  const score = pixel * 0.35
    + geo * 0.25
    + typo * 0.12
    + spacing * 0.12
    + responsive * 0.08
    + colour * 0.08;
  return Math.round(score * 10) / 10;
}

module.exports = { classifyMismatches, rankCategories, compositeScore };
