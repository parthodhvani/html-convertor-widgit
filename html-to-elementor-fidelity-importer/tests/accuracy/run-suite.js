#!/usr/bin/env node
'use strict';

/**
 * Accuracy benchmark suite for the Browser Rendering → Elementor compiler.
 *
 * Pipeline per fixture:
 *   HTML → Chromium render/extract → layout.json
 *        → PHP harness (Elementor JSON)
 *        → paint / geometry / spacing / typography / widget metrics
 *
 * This does NOT fake scores. Geometry and paint come from real Chromium
 * extraction vs emitted Elementor settings. Pixel screenshot compare against a
 * live Elementor render requires WordPress and is reported as "n/a" here.
 *
 * Usage (from plugin root):
 *   node tests/accuracy/run-suite.js
 *   node tests/accuracy/run-suite.js --fixture petra/angebot-conversion-ready.html
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const PLUGIN_ROOT = path.resolve(__dirname, '../..');
const FIXTURES_DIR = path.join(PLUGIN_ROOT, 'tests/fixtures');
const CHROMIUM_DIR = path.join(PLUGIN_ROOT, 'chromium-service');
const OUT_DIR = path.join('/tmp', 'h2e-accuracy');
const HARNESS = path.join(PLUGIN_ROOT, 'tests/harness.php');

const DEFAULT_FIXTURES = [
  'sample.html',
  'faq.html',
  'kontakt.html',
  'petra/index.html',
  'petra/angebot-conversion-ready.html',
  'petra/contact.html',
  'petra/blog.html',
  'petra/blog-detail.html',
  'petra/buchen.html',
  'petra/vortraege.html',
  'petra/feedbacks.html',
  'bootstrap-grid.html',
  'dark-saas.html',
  'portfolio-absolute.html',
  'ecommerce-grid.html',
  'dashboard-analytics.html',
  'agency-tailwind.html',
  'blog-magazine.html',
];

function parseArgs(argv) {
  const out = { fixtures: [], json: false };
  for (let i = 2; i < argv.length; i += 1) {
    if (argv[i] === '--fixture' && argv[i + 1]) out.fixtures.push(argv[++i]);
    else if (argv[i] === '--json') out.json = true;
  }
  if (!out.fixtures.length) out.fixtures = DEFAULT_FIXTURES;
  return out;
}

function run(cmd, args, opts = {}) {
  const res = spawnSync(cmd, args, {
    encoding: 'utf8',
    cwd: opts.cwd || PLUGIN_ROOT,
    maxBuffer: 32 * 1024 * 1024,
  });
  return res;
}

function extractReport(harnessStdout) {
  const marker = '=== Conversion report ===';
  const idx = harnessStdout.indexOf(marker);
  if (idx < 0) return null;
  let rest = harnessStdout.slice(idx + marker.length).trim();
  const endMarker = '=== Elementor data';
  const end = rest.indexOf(endMarker);
  if (end >= 0) rest = rest.slice(0, end).trim();
  try {
    return JSON.parse(rest);
  } catch (e) {
    return null;
  }
}

function countPaint(node, acc) {
  if (!node || typeof node !== 'object') return;
  const s = node.s || {};
  const bg = String(s.bg || '');
  const bgImg = String(s.bgImg || '');
  const hasGrad = /gradient/i.test(bgImg) || /gradient/i.test(bg);
  const hasSolid = bg && bg !== 'transparent' && !/rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/i.test(bg);
  const hasImg = /url\(/i.test(bgImg);
  if (hasGrad || hasSolid || hasImg) {
    acc.painted += 1;
    if (hasGrad) acc.gradients += 1;
  }
  (node.children || []).forEach((c) => countPaint(c, acc));
}

function countEmittedPaint(elements, acc) {
  (elements || []).forEach((el) => {
    if (!el || typeof el !== 'object') return;
    const settings = el.settings || {};
    const type = String(settings.background_background || '');
    const has =
      type ||
      settings.background_color ||
      (settings.background_image && settings.background_image.url) ||
      settings.background_overlay_background;
    if (has) acc.bgs += 1;
    if (type === 'gradient' || settings.background_overlay_background === 'gradient') {
      acc.grads += 1;
    }
    countEmittedPaint(el.elements || [], acc);
  });
}

function loadElementorData(harnessStdout) {
  const marker = '=== Elementor data';
  const idx = harnessStdout.indexOf(marker);
  if (idx < 0) return [];
  const brace = harnessStdout.indexOf('[', idx);
  if (brace < 0) return [];
  try {
    return JSON.parse(harnessStdout.slice(brace));
  } catch (e) {
    // Truncated? try balanced parse
    return [];
  }
}

function scoreFixture(name) {
  const input = path.join(FIXTURES_DIR, name);
  if (!fs.existsSync(input)) {
    return { fixture: name, error: 'missing fixture', ok: false };
  }

  const slug = name.replace(/[\\/]/g, '__').replace(/\.html?$/i, '');
  const dir = path.join(OUT_DIR, slug);
  fs.mkdirSync(dir, { recursive: true });
  const layoutPath = path.join(dir, 'layout.json');

  const render = run('node', ['cli.js', '--input', input, '--out', layoutPath], {
    cwd: CHROMIUM_DIR,
  });
  if (render.status !== 0 || !fs.existsSync(layoutPath)) {
    return {
      fixture: name,
      ok: false,
      error: 'chromium render failed',
      stderr: (render.stderr || render.stdout || '').slice(0, 500),
    };
  }

  const harness = run('php', [HARNESS, layoutPath, 'widgets']);
  const report = extractReport(harness.stdout || '');
  if (!report) {
    return {
      fixture: name,
      ok: false,
      error: 'harness failed',
      stderr: (harness.stderr || harness.stdout || '').slice(0, 500),
    };
  }

  const layout = JSON.parse(fs.readFileSync(layoutPath, 'utf8'));
  const paintSrc = { painted: 0, gradients: 0 };
  (layout.sections || []).forEach((sec) => countPaint(sec.tree, paintSrc));

  const elements = loadElementorData(harness.stdout || '');
  const paintOut = { bgs: 0, grads: 0 };
  countEmittedPaint(elements, paintOut);

  const validation = report.validation || {};
  const native = report.native_widgets || 0;
  const html = report.html_widgets || 0;
  const total = Math.max(1, native + html);

  const gradientPreserve =
    paintSrc.gradients > 0
      ? Math.min(100, Math.round((paintOut.grads / paintSrc.gradients) * 100))
      : 100;

  return {
    fixture: name,
    ok: true,
    fidelity: validation.fidelity ?? null,
    geometry: validation.geometry_similarity ?? null,
    layout: validation.layout_similarity ?? null,
    spacing: validation.spacing_similarity ?? null,
    typography: validation.typography_similarity ?? null,
    colour: validation.colour ?? null,
    responsive: validation.responsive_similarity ?? null,
    widget_coverage: Math.round((native / total) * 100),
    html_widgets: html,
    native_widgets: native,
    source_gradients: paintSrc.gradients,
    emitted_gradients: paintOut.grads,
    gradient_preserve_pct: gradientPreserve,
    pixel_ssim: 'n/a (requires WP+Elementor re-render)',
  };
}

function main() {
  const args = parseArgs(process.argv);
  fs.mkdirSync(OUT_DIR, { recursive: true });

  console.log('H2E Accuracy Suite');
  console.log('Plugin:', PLUGIN_ROOT);
  console.log('Fixtures:', args.fixtures.length);
  console.log('---');

  const results = args.fixtures.map(scoreFixture);
  const ok = results.filter((r) => r.ok);
  const avg = (key) => {
    const vals = ok.map((r) => r[key]).filter((v) => typeof v === 'number');
    if (!vals.length) return null;
    return Math.round(vals.reduce((a, b) => a + b, 0) / vals.length);
  };

  for (const r of results) {
    if (!r.ok) {
      console.log(`FAIL  ${r.fixture}: ${r.error}`);
      continue;
    }
    console.log(
      [
        r.fixture.padEnd(42),
        `fid=${String(r.fidelity).padStart(3)}`,
        `geo=${String(r.geometry).padStart(3)}`,
        `lay=${String(r.layout).padStart(3)}`,
        `spc=${String(r.spacing).padStart(3)}`,
        `typ=${String(r.typography).padStart(3)}`,
        `col=${String(r.colour).padStart(3)}`,
        `grad=${String(r.gradient_preserve_pct).padStart(3)}%`,
        `cov=${String(r.widget_coverage).padStart(3)}%`,
        `html=${r.html_widgets}`,
      ].join('  ')
    );
  }

  const summary = {
    fixtures: results.length,
    passed: ok.length,
    failed: results.length - ok.length,
    averages: {
      fidelity: avg('fidelity'),
      geometry: avg('geometry'),
      layout: avg('layout'),
      spacing: avg('spacing'),
      typography: avg('typography'),
      colour: avg('colour'),
      gradient_preserve_pct: avg('gradient_preserve_pct'),
      widget_coverage: avg('widget_coverage'),
    },
    results,
  };

  const summaryPath = path.join(OUT_DIR, 'summary.json');
  fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2));
  console.log('---');
  console.log('Averages:', JSON.stringify(summary.averages));
  console.log('Wrote', summaryPath);

  if (args.json) {
    process.stdout.write(JSON.stringify(summary, null, 2) + '\n');
  }

  process.exit(summary.failed ? 1 : 0);
}

main();
