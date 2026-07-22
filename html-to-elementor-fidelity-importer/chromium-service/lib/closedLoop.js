'use strict';

/**
 * Closed-loop visual validation against Chromium screenshots.
 *
 * Renders an Elementor preview HTML in headless Chrome, screenshots it,
 * and compares against the original page screenshot using pixel MAE.
 */

const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
const puppeteer = require('puppeteer');
const { compareScreenshots } = require('../compare');
const { chromeLaunchOptions } = require('./chromeLaunch');

/**
 * Screenshot an HTML file at a given viewport width.
 *
 * @param {string} htmlPath Absolute HTML path.
 * @param {string} outPng   Output PNG path.
 * @param {object} opts     { width, height, timeout }.
 * @returns {Promise<string>} PNG path.
 */
async function screenshotHtml(htmlPath, outPng, opts = {}) {
  const width = opts.width || 1440;
  const height = opts.height || 900;
  const timeout = opts.timeout || 60000;

  const browser = await puppeteer.launch(chromeLaunchOptions());

  try {
    const page = await browser.newPage();
    page.setDefaultTimeout(timeout);
    await page.setViewport({ width, height, deviceScaleFactor: 1 });
    await page.goto(pathToFileURL(htmlPath).href, { waitUntil: 'load', timeout });
    try {
      await Promise.race([
        page.evaluate(async () => {
          if (document.fonts && document.fonts.ready) await document.fonts.ready;
        }),
        new Promise((res) => setTimeout(res, 8000)),
      ]);
    } catch (e) {
      // ignore
    }
    fs.mkdirSync(path.dirname(outPng), { recursive: true });
    await page.screenshot({ path: outPng, fullPage: true });
    return outPng;
  } finally {
    await browser.close();
  }
}

/**
 * Run closed-loop compare.
 *
 * @param {object} args
 * @param {string} args.originalPng Original screenshot.
 * @param {string} args.previewHtml Preview HTML path.
 * @param {string} args.outDir      Artifact directory.
 * @param {number} [args.width]
 * @returns {Promise<object>}
 */
async function runClosedLoop(args) {
  const outDir = args.outDir;
  fs.mkdirSync(outDir, { recursive: true });
  const generatedPng = path.join(outDir, 'shot-preview.png');
  await screenshotHtml(args.previewHtml, generatedPng, {
    width: args.width || 1440,
    height: args.height || 900,
  });

  const compare = compareScreenshots(args.originalPng, generatedPng);
  return {
    original: args.originalPng,
    generated: generatedPng,
    compare,
    score: compare.score,
    passed: compare.passed,
    method: compare.method,
  };
}

async function main() {
  const args = { originalPng: '', previewHtml: '', outDir: '', width: 1440, json: false };
  for (let i = 2; i < process.argv.length; i += 1) {
    const a = process.argv[i];
    if (a === '--original' && process.argv[i + 1]) args.originalPng = process.argv[++i];
    else if (a === '--preview' && process.argv[i + 1]) args.previewHtml = process.argv[++i];
    else if (a === '--out-dir' && process.argv[i + 1]) args.outDir = process.argv[++i];
    else if (a === '--width' && process.argv[i + 1]) args.width = parseInt(process.argv[++i], 10);
    else if (a === '--json') args.json = true;
  }
  if (!args.originalPng || !args.previewHtml || !args.outDir) {
    // eslint-disable-next-line no-console
    console.error('Usage: node lib/closedLoop.js --original <png> --preview <html> --out-dir <dir> [--width 1440] [--json]');
    process.exit(2);
  }
  const result = await runClosedLoop(args);
  if (args.json) {
    // eslint-disable-next-line no-console
    console.log(JSON.stringify(result, null, 2));
  } else {
    // eslint-disable-next-line no-console
    console.log(`Closed-loop score: ${result.score}% method=${result.method} passed=${result.passed}`);
  }
  process.exit(result.passed ? 0 : 1);
}

if (require.main === module) {
  main().catch((e) => {
    // eslint-disable-next-line no-console
    console.error(e);
    process.exit(1);
  });
}

module.exports = { screenshotHtml, runClosedLoop };
