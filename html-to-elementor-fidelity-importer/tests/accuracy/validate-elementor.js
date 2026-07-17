#!/usr/bin/env node
'use strict';

/**
 * Validate harness Elementor JSON for import-shaped structure (no WP required).
 * Also copies Chromium source screenshots beside a cycle report for visual review.
 *
 * Usage (from plugin root):
 *   node tests/accuracy/validate-elementor.js
 *   node tests/accuracy/validate-elementor.js --fixture kontakt.html
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const PLUGIN_ROOT = path.resolve(__dirname, '../..');
const OUT_DIR = path.join('/tmp', 'h2e-accuracy');
const ARTIFACTS = path.join('/opt/cursor/artifacts', 'fidelity-cycles');
const HARNESS = path.join(PLUGIN_ROOT, 'tests/harness.php');

function parseArgs(argv) {
  const out = { fixtures: [] };
  for (let i = 2; i < argv.length; i += 1) {
    if (argv[i] === '--fixture' && argv[i + 1]) out.fixtures.push(argv[++i]);
  }
  return out;
}

function loadSummary() {
  const p = path.join(OUT_DIR, 'summary.json');
  if (!fs.existsSync(p)) return null;
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

function validateElement(el, pathLabel, errors) {
  if (!el || typeof el !== 'object') {
    errors.push(`${pathLabel}: not an object`);
    return;
  }
  if (!el.id) errors.push(`${pathLabel}: missing id`);
  if (!el.elType) errors.push(`${pathLabel}: missing elType`);
  if (el.elType === 'widget') {
    if (!el.widgetType) errors.push(`${pathLabel}: widget missing widgetType`);
    if (el.widgetType === 'html') {
      errors.push(`${pathLabel}: unexpected html widget (prefer native)`);
    }
  }
  if (el.elType === 'container') {
    if (!el.settings || typeof el.settings !== 'object') {
      errors.push(`${pathLabel}: container missing settings`);
    }
  }
  (el.elements || []).forEach((child, i) => validateElement(child, `${pathLabel}/elements[${i}]`, errors));
}

function extractElementorData(stdout) {
  const marker = '=== Elementor data';
  const idx = stdout.indexOf(marker);
  if (idx < 0) return null;
  const brace = stdout.indexOf('[', idx);
  if (brace < 0) return null;
  try {
    return JSON.parse(stdout.slice(brace));
  } catch (e) {
    return null;
  }
}

function main() {
  const args = parseArgs(process.argv);
  const summary = loadSummary();
  if (!summary) {
    console.error('Run tests/accuracy/run-suite.js first to produce /tmp/h2e-accuracy/summary.json');
    process.exit(2);
  }

  fs.mkdirSync(ARTIFACTS, { recursive: true });
  const report = {
    generatedAt: new Date().toISOString(),
    averages: summary.averages,
    under95: [],
    importValidation: [],
    visuals: [],
  };

  const results = summary.results.filter((r) => r.ok);
  for (const r of results) {
    const under = [];
    for (const key of ['geometry', 'spacing', 'fidelity', 'responsive']) {
      if (typeof r[key] === 'number' && r[key] < 95) under.push(`${key}=${r[key]}`);
    }
    if (under.length) report.under95.push({ fixture: r.fixture, metrics: under });

    const slug = r.fixture.replace(/[\\/]/g, '__').replace(/\.html?$/i, '');
    const layoutPath = path.join(OUT_DIR, slug, 'layout.json');
    if (!fs.existsSync(layoutPath)) continue;

    const harness = spawnSync('php', [HARNESS, layoutPath], {
      encoding: 'utf8',
      cwd: PLUGIN_ROOT,
      maxBuffer: 32 * 1024 * 1024,
    });
    const data = extractElementorData(harness.stdout || '');
    const errors = [];
    if (!Array.isArray(data) || !data.length) {
      errors.push('no Elementor data array');
    } else {
      data.forEach((el, i) => validateElement(el, `root[${i}]`, errors));
    }
    report.importValidation.push({
      fixture: r.fixture,
      ok: errors.length === 0,
      errors: errors.slice(0, 12),
      sections: Array.isArray(data) ? data.length : 0,
      html_widgets: r.html_widgets,
    });

    // Visual: copy Chromium desktop screenshot for side-by-side review.
    const shot = path.join(OUT_DIR, slug, 'shot-desktop.png');
    if (fs.existsSync(shot)) {
      const dest = path.join(ARTIFACTS, `${slug}-source-desktop.png`);
      fs.copyFileSync(shot, dest);
      report.visuals.push({ fixture: r.fixture, sourceDesktop: dest });
    }
  }

  const outPath = path.join(ARTIFACTS, 'cycle-report.json');
  fs.writeFileSync(outPath, JSON.stringify(report, null, 2));
  console.log('Elementor import-shape validation');
  console.log('Under 95%:', report.under95.length ? JSON.stringify(report.under95, null, 2) : 'none');
  const failed = report.importValidation.filter((v) => !v.ok);
  console.log(`Import validation: ${report.importValidation.length - failed.length}/${report.importValidation.length} ok`);
  if (failed.length) {
    failed.forEach((f) => console.log(' FAIL', f.fixture, f.errors.join('; ')));
  }
  console.log('Wrote', outPath);
  process.exit(failed.length ? 1 : 0);
}

main();
