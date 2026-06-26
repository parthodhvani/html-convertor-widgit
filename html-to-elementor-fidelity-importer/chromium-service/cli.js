#!/usr/bin/env node
'use strict';

/**
 * Standalone CLI for the Chromium rendering service.
 *
 * Usage:
 *   node cli.js --input page.html --out layout.json [--config config.json]
 *
 * Writes the layout document to --out and prints a short summary to stdout.
 */

const fs = require('fs');
const path = require('path');
const { renderToLayout } = require('./lib/extractor');

/**
 * Parse `--key value` and `--flag` style arguments.
 *
 * @param {string[]} argv Process args (without node/script).
 * @returns {Object<string,string|boolean>}
 */
function parseArgs(argv) {
  const args = {};
  for (let i = 0; i < argv.length; i += 1) {
    const token = argv[i];
    if (token.startsWith('--')) {
      const key = token.slice(2);
      const next = argv[i + 1];
      if (next && !next.startsWith('--')) {
        args[key] = next;
        i += 1;
      } else {
        args[key] = true;
      }
    }
  }
  return args;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const input = args.input || args.i;
  const out = args.out || args.o;

  if (!input || !out) {
    process.stderr.write('Usage: node cli.js --input <file.html> --out <layout.json> [--config <config.json>]\n');
    process.exit(2);
    return;
  }

  let config = {};
  if (args.config && fs.existsSync(String(args.config))) {
    try {
      config = JSON.parse(fs.readFileSync(String(args.config), 'utf8'));
    } catch (e) {
      process.stderr.write(`Invalid config JSON: ${e.message}\n`);
      process.exit(2);
      return;
    }
  }

  const outDir = path.dirname(path.resolve(String(out)));

  try {
    const layout = await renderToLayout(path.resolve(String(input)), outDir, config);
    fs.writeFileSync(String(out), JSON.stringify(layout, null, 2));
    process.stdout.write(
      `OK: ${layout.sections.length} section(s), title="${layout.meta.title}" -> ${out}\n`
    );
    process.exit(0);
  } catch (e) {
    process.stderr.write(`Render failed: ${e.stack || e.message}\n`);
    process.exit(1);
  }
}

main();
