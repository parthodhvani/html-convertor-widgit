#!/usr/bin/env node
'use strict';

/**
 * Continuous Accuracy Optimization suite.
 *
 * For every corpus page:
 *   Chromium render → layout + screenshot
 *   → Elementor compile + preview HTML
 *   → Chromium screenshot of preview
 *   → pixel compare + geometry/typography/spacing metrics
 *   → root-cause classification
 *
 * Usage (from plugin root or this directory):
 *   node tests/accuracy/run-suite.js [--limit N] [--only id] [--skip-pixel]
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { classifyMismatches, rankCategories, compositeScore } = require('./lib/classify');
const { compareScreenshots } = require('../../chromium-service/compare');
const { screenshotHtml } = require('../../chromium-service/lib/closedLoop');

const ACCURACY_DIR = __dirname;
const PLUGIN_ROOT = path.resolve(ACCURACY_DIR, '../..');
const CORPUS_DIR = path.join(ACCURACY_DIR, 'corpus');
const OUT_ROOT = path.join(ACCURACY_DIR, 'out');
const CHROMIUM_DIR = path.join(PLUGIN_ROOT, 'chromium-service');

function parseArgs(argv) {
  const out = { limit: 0, only: '', skipPixel: false, widths: [1440], label: 'run' };
  for (let i = 2; i < argv.length; i += 1) {
    const a = argv[i];
    if (a === '--limit' && argv[i + 1]) out.limit = parseInt(argv[++i], 10);
    else if (a === '--only' && argv[i + 1]) out.only = argv[++i];
    else if (a === '--skip-pixel') out.skipPixel = true;
    else if (a === '--label' && argv[i + 1]) out.label = argv[++i];
    else if (a === '--mobile') out.widths = [1440, 768];
  }
  return out;
}

function run(cmd, args, opts = {}) {
  const res = spawnSync(cmd, args, {
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024,
    ...opts,
  });
  return res;
}

function loadManifest() {
  const manifestPath = path.join(CORPUS_DIR, 'manifest.json');
  if (!fs.existsSync(manifestPath)) {
    console.log('Building corpus…');
    const built = run(process.execPath, [path.join(ACCURACY_DIR, 'build-corpus.js')]);
    if (built.status !== 0) {
      throw new Error(built.stderr || built.stdout || 'corpus build failed');
    }
  }
  return JSON.parse(fs.readFileSync(path.join(CORPUS_DIR, 'manifest.json'), 'utf8'));
}

async function processPage(page, args, runDir) {
  const pageDir = path.join(runDir, page.id);
  fs.mkdirSync(pageDir, { recursive: true });
  const htmlPath = path.join(CORPUS_DIR, page.path);
  const layoutPath = path.join(pageDir, 'layout.json');
  const row = {
    id: page.id,
    category: page.category,
    tags: page.tags || [],
    path: page.path,
  };

  if (!fs.existsSync(htmlPath)) {
    row.error = 'missing_html';
    row.composite = 0;
    row.issues = classifyMismatches(row);
    return row;
  }

  // 1) Chromium extract + desktop screenshot
  const extract = run(process.execPath, ['cli.js', '--input', htmlPath, '--out', layoutPath], {
    cwd: CHROMIUM_DIR,
  });
  if (extract.status !== 0 || !fs.existsSync(layoutPath)) {
    row.error = `extract_failed: ${(extract.stderr || extract.stdout || '').slice(0, 240)}`;
    row.composite = 0;
    row.issues = classifyMismatches(row);
    return row;
  }

  // cli writes screenshots next to out — extractor uses dirname(out)
  const shotDesktop = path.join(pageDir, 'shot-desktop.png');
  // extractor may write shot-desktop.png into pageDir already
  if (!fs.existsSync(shotDesktop)) {
    // fallback: search
    const candidates = fs.readdirSync(pageDir).filter((f) => f.startsWith('shot-') && f.endsWith('.png'));
    if (candidates.length) {
      fs.copyFileSync(path.join(pageDir, candidates[0]), shotDesktop);
    }
  }

  // 2) Compile + preview
  const compile = run('php', [path.join(ACCURACY_DIR, 'compile-and-preview.php'), layoutPath, pageDir]);
  if (compile.status !== 0) {
    row.error = `compile_failed: ${(compile.stderr || compile.stdout || '').slice(0, 240)}`;
    row.composite = 0;
    row.issues = classifyMismatches(row);
    return row;
  }

  let summary = {};
  try {
    summary = JSON.parse(fs.readFileSync(path.join(pageDir, 'compile-summary.json'), 'utf8'));
  } catch (e) {
    summary = {};
  }
  let validation = {};
  try {
    validation = JSON.parse(fs.readFileSync(path.join(pageDir, 'validation.json'), 'utf8'));
  } catch (e) {
    validation = {};
  }

  row.native_widgets = summary.native_widgets || 0;
  row.html_widgets = summary.html_widgets || 0;
  row.geometry_similarity = summary.geometry_similarity || validation.geometry_similarity || 0;
  row.typography_similarity = summary.typography_similarity || validation.typography_similarity || 0;
  row.spacing_similarity = summary.spacing_similarity || validation.spacing_similarity || 0;
  row.responsive_similarity = summary.responsive_similarity || validation.responsive_similarity || 0;
  row.colour = summary.colour || validation.colour || 0;
  row.fidelity = summary.fidelity || validation.fidelity || 0;
  row.validation = validation;

  // 3) Pixel compare original vs preview
  const previewHtml = path.join(pageDir, 'preview.html');
  let pixel = 0;
  if (!args.skipPixel && fs.existsSync(shotDesktop) && fs.existsSync(previewHtml)) {
    const previewPng = path.join(pageDir, 'shot-preview.png');
    try {
      await screenshotHtml(previewHtml, previewPng, { width: 1440, height: 900 });
      const cmp = compareScreenshots(shotDesktop, previewPng);
      pixel = Number(cmp.score || 0);
      row.pixel_method = cmp.method;
      row.pixel_compare = cmp;
      fs.writeFileSync(path.join(pageDir, 'pixel.json'), JSON.stringify(cmp, null, 2));
    } catch (e) {
      row.pixel_error = String(e.message || e);
      pixel = 0;
    }
  } else if (args.skipPixel) {
    // Without pixels, weight geometry as proxy.
    pixel = row.geometry_similarity;
  }

  row.pixel_similarity = pixel;
  row.paint_accuracy = Number(summary.colour || row.colour || 0);
  row.css_preservation = Math.max(0, 100 - Math.min(100, (summary.unsupported_css_count || 0) * 2));
  row.composite = compositeScore(row);
  row.issues = classifyMismatches(row);

  // Visual debug package for developers.
  writeDebugOutput(pageDir, row, shotDesktop, path.join(pageDir, 'shot-preview.png'));

  fs.writeFileSync(path.join(pageDir, 'page-result.json'), JSON.stringify(row, null, 2));
  return row;
}

/**
 * Write /debug-output style artifacts beside each page result.
 *
 * @param {string} pageDir Page work dir.
 * @param {object} row Result row.
 * @param {string} originalPng Original screenshot path.
 * @param {string} previewPng Preview screenshot path.
 */
function writeDebugOutput(pageDir, row, originalPng, previewPng) {
  const debugDir = path.join(pageDir, 'debug-output');
  fs.mkdirSync(debugDir, { recursive: true });
  try {
    if (fs.existsSync(originalPng)) fs.copyFileSync(originalPng, path.join(debugDir, 'original.png'));
    if (fs.existsSync(previewPng)) fs.copyFileSync(previewPng, path.join(debugDir, 'elementor-render.png'));
  } catch (e) {
    // ignore copy errors
  }

  const v = row.validation || {};
  const layoutDiff = {
    problem: (row.issues && row.issues[0] && row.issues[0].category) || 'none',
    evidence: (row.issues && row.issues[0] && row.issues[0].evidence) || '',
    original: {
      source_frames: v.source_frames || 0,
      geometry_similarity: row.geometry_similarity,
      spacing_similarity: row.spacing_similarity,
    },
    generated: {
      emitted_frames: v.emitted_frames || 0,
      matched_frames: v.matched_frames || 0,
      bbox_delta: v.bbox_delta || 0,
      position_rmse: v.position_rmse || 0,
      size_rmse: v.size_rmse || 0,
    },
    cause: rankCause(row),
    expected_gain_hint: (row.issues && row.issues[0] && row.issues[0].impact)
      ? `~${Math.min(3, Math.round((row.issues[0].impact / 40) * 10) / 10)}% if resolved`
      : '',
  };
  fs.writeFileSync(path.join(debugDir, 'layout-diff.json'), JSON.stringify(layoutDiff, null, 2));

  const cssLossPath = path.join(pageDir, 'css-loss.json');
  if (fs.existsSync(cssLossPath)) {
    fs.copyFileSync(cssLossPath, path.join(debugDir, 'css-loss.json'));
  } else {
    fs.writeFileSync(path.join(debugDir, 'css-loss.json'), JSON.stringify({ unsupported: {}, note: 'no css-loss emitted' }, null, 2));
  }

  fs.writeFileSync(path.join(debugDir, 'typography-diff.json'), JSON.stringify({
    typography_similarity: row.typography_similarity,
    note: 'Measured via GeometryComparator typography channel',
  }, null, 2));

  fs.writeFileSync(path.join(debugDir, 'paint-diff.json'), JSON.stringify({
    pixel_similarity: row.pixel_similarity,
    colour: row.colour,
    paint_accuracy: row.paint_accuracy,
    pixel_method: row.pixel_method || null,
  }, null, 2));

  // difference.png is approximate: reuse compare metadata (no pixel buffer export yet).
  fs.writeFileSync(path.join(debugDir, 'difference.json'), JSON.stringify(row.pixel_compare || {}, null, 2));
}

/**
 * @param {object} row Page row.
 * @returns {string}
 */
function rankCause(row) {
  const issue = (row.issues && row.issues[0]) || null;
  if (!issue) return 'within tolerance';
  const map = {
    missing_emission: 'Emitter dropped or absorbed frames before Elementor JSON',
    spacing_gap: 'Margin/gap model diverged from browser spacing (often margin→gap wipe)',
    geometry_position_size: 'Container width/position/size mismatch vs Chromium bboxes',
    color_background: 'Background/gradient/paint not mapped or not in preview CSS',
    pixel_paint_preview: 'Preview oracle / paint CSS gap (check custom CSS injection)',
    pixel_overall: 'Large pixel delta — inspect absolute layers and backgrounds',
    flex_gap_unstable: 'Repair loop rewriting flex_gap without convergence',
    typography_mismatch: 'Font metrics / wrapping / line-height drift',
    responsive_behavior: 'Breakpoint stack/width inference incorrect',
  };
  return map[issue.category] || issue.evidence || issue.category;
}

async function main() {
  const args = parseArgs(process.argv);
  const manifest = loadManifest();
  let pages = manifest.pages || [];
  if (args.only) {
    pages = pages.filter((p) => p.id === args.only || (p.tags || []).includes(args.only));
  }
  if (args.limit > 0) {
    pages = pages.slice(0, args.limit);
  }

  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const runDir = path.join(OUT_ROOT, `${args.label}-${stamp}`);
  fs.mkdirSync(runDir, { recursive: true });

  console.log(`Accuracy suite: ${pages.length} pages → ${runDir}`);
  const rows = [];
  for (let i = 0; i < pages.length; i += 1) {
    const page = pages[i];
    process.stdout.write(`[${i + 1}/${pages.length}] ${page.id} … `);
    const started = Date.now();
    const row = await processPage(page, args, runDir);
    rows.push(row);
    console.log(`composite=${row.composite} pixel=${row.pixel_similarity || 0} geo=${row.geometry_similarity || 0} (${Date.now() - started}ms)`);
  }

  const categories = rankCategories(rows);
  const avg = (key) => {
    if (!rows.length) return 0;
    return Math.round((rows.reduce((s, r) => s + Number(r[key] || 0), 0) / rows.length) * 10) / 10;
  };

  const report = {
    generated_at: new Date().toISOString(),
    label: args.label,
    run_dir: runDir,
    pages: rows.length,
    summary: {
      avg_composite: avg('composite'),
      avg_pixel: avg('pixel_similarity'),
      avg_geometry: avg('geometry_similarity'),
      avg_typography: avg('typography_similarity'),
      avg_spacing: avg('spacing_similarity'),
      avg_responsive: avg('responsive_similarity'),
      avg_colour: avg('colour'),
      avg_paint_accuracy: avg('paint_accuracy'),
      avg_css_preservation: avg('css_preservation'),
      native_widget_pct: (() => {
        const n = rows.reduce((s, r) => s + (r.native_widgets || 0), 0);
        const h = rows.reduce((s, r) => s + (r.html_widgets || 0), 0);
        return (n + h) > 0 ? Math.round((n / (n + h)) * 1000) / 10 : 0;
      })(),
      total_native_widgets: rows.reduce((s, r) => s + (r.native_widgets || 0), 0),
      total_html_widgets: rows.reduce((s, r) => s + (r.html_widgets || 0), 0),
      errors: rows.filter((r) => r.error).length,
    },
    top_categories: categories.slice(0, 12),
    pages: rows.map((r) => ({
      id: r.id,
      category: r.category,
      composite: r.composite,
      pixel: r.pixel_similarity,
      geometry: r.geometry_similarity,
      typography: r.typography_similarity,
      spacing: r.spacing_similarity,
      responsive: r.responsive_similarity,
      colour: r.colour,
      native: r.native_widgets,
      html: r.html_widgets,
      top_issue: (r.issues && r.issues[0] && r.issues[0].category) || null,
      error: r.error || null,
    })),
  };

  const reportPath = path.join(runDir, 'report.json');
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
  fs.writeFileSync(path.join(OUT_ROOT, 'latest.json'), JSON.stringify(report, null, 2));

  // Human summary
  let text = 'H2E Continuous Accuracy Report\n';
  text += '='.repeat(72) + '\n';
  text += `pages=${rows.length}  avg_composite=${report.summary.avg_composite}%  avg_pixel=${report.summary.avg_pixel}%  avg_geo=${report.summary.avg_geometry}%\n`;
  text += `typography=${report.summary.avg_typography}% spacing=${report.summary.avg_spacing}% responsive=${report.summary.avg_responsive}% colour=${report.summary.avg_colour}%\n`;
  text += `widgets native=${report.summary.total_native_widgets} html=${report.summary.total_html_widgets} errors=${report.summary.errors}\n\n`;
  text += 'Top root-cause categories (by total impact)\n';
  text += '-'.repeat(72) + '\n';
  for (const c of report.top_categories) {
    text += `${c.category.padEnd(28)} score=${String(c.score).padStart(7)} pages=${String(c.pages).padStart(3)} avg=${c.avg_impact}\n`;
    for (const ex of c.examples.slice(0, 2)) {
      text += `    · ${ex.id}: ${ex.evidence}\n`;
    }
  }
  text += '\nLowest pages\n' + '-'.repeat(72) + '\n';
  const lowest = [...report.pages].sort((a, b) => a.composite - b.composite).slice(0, 10);
  for (const p of lowest) {
    text += `${p.id.padEnd(28)} composite=${p.composite} pixel=${p.pixel} geo=${p.geometry} issue=${p.top_issue || '-'}\n`;
  }
  fs.writeFileSync(path.join(runDir, 'report.txt'), text);
  fs.writeFileSync(path.join(OUT_ROOT, 'latest.txt'), text);
  console.log('\n' + text);
  console.log(`Wrote ${reportPath}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
