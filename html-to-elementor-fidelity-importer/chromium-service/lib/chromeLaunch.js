'use strict';

/**
 * Shared Puppeteer launch options.
 *
 * Prefers (in order):
 *  1. PUPPETEER_EXECUTABLE_PATH / CHROME_PATH env
 *  2. Common system Chrome/Chromium binaries when present
 *  3. Puppeteer's downloaded Chrome (omit executablePath)
 */

const fs = require('fs');

const SYSTEM_CHROME_CANDIDATES = [
  process.env.PUPPETEER_EXECUTABLE_PATH,
  process.env.CHROME_PATH,
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
  '/snap/bin/chromium',
].filter(Boolean);

/**
 * @returns {string|undefined} Absolute Chrome path, or undefined to use Puppeteer cache.
 */
function resolveChromeExecutable() {
  for (const candidate of SYSTEM_CHROME_CANDIDATES) {
    try {
      if (candidate && fs.existsSync(candidate)) {
        return candidate;
      }
    } catch {
      // ignore
    }
  }
  return undefined;
}

/**
 * @param {object} [extra] Extra puppeteer.launch options.
 * @returns {object}
 */
function chromeLaunchOptions(extra = {}) {
  const executablePath = resolveChromeExecutable();
  const opts = {
    headless: 'new',
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--font-render-hinting=none',
      // Every `file://` document otherwise gets its own unique/opaque
      // origin, so `<link rel="stylesheet" href="assets/css/style.css">`
      // fails CSSOM `sheet.cssRules` access ("Cannot access rules") even
      // though the stylesheet sits right next to the HTML file being
      // imported. Without this flag, every uploaded HTML+CSS bundle (the
      // plugin's primary input) silently loses all `:hover`-rule extraction
      // and any other feature that reads stylesheet rules directly.
      '--allow-file-access-from-files',
    ],
    ...extra,
  };
  if (executablePath) {
    opts.executablePath = executablePath;
  }
  return opts;
}

module.exports = {
  resolveChromeExecutable,
  chromeLaunchOptions,
};
