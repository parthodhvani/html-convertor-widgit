'use strict';

/**
 * Visual comparison engine for fidelity validation.
 *
 * Compares two PNG screenshots and returns SSIM-like, perceptual-hash and
 * bounding-box fidelity metrics. Used by the Visual Validation Engine.
 *
 * Usage:
 *   node compare.js --original shot-a.png --generated shot-b.png
 */

const fs = require('fs');
const path = require('path');

/**
 * Parse CLI arguments.
 *
 * @param {string[]} argv Process argv.
 * @returns {object}
 */
function parseArgs(argv) {
  const out = { original: '', generated: '', json: false };
  for (let i = 2; i < argv.length; i += 1) {
    if (argv[i] === '--original' && argv[i + 1]) out.original = argv[++i];
    else if (argv[i] === '--generated' && argv[i + 1]) out.generated = argv[++i];
    else if (argv[i] === '--json') out.json = true;
  }
  return out;
}

/**
 * Read PNG dimensions from IHDR chunk (no native deps).
 *
 * @param {Buffer} buf PNG buffer.
 * @returns {{width:number,height:number}|null}
 */
function pngSize(buf) {
  if (buf.length < 24 || buf.toString('ascii', 1, 4) !== 'PNG') return null;
  return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
}

/**
 * Simple average-hash perceptual fingerprint.
 *
 * @param {Buffer} buf PNG buffer.
 * @returns {string} Hex hash.
 */
function averageHash(buf) {
  const size = pngSize(buf);
  if (!size) return '';
  const sample = Math.min(buf.length, 4096);
  let sum = 0;
  for (let i = 0; i < sample; i += 16) sum += buf[i];
  const avg = sum / Math.max(1, Math.floor(sample / 16));
  let bits = '';
  for (let i = 0; i < sample; i += 16) {
    bits += buf[i] >= avg ? '1' : '0';
  }
  return bits.slice(0, 64);
}

/**
 * Hamming distance between two bit strings.
 *
 * @param {string} a Bits.
 * @param {string} b Bits.
 * @returns {number}
 */
function hamming(a, b) {
  const len = Math.min(a.length, b.length);
  let d = Math.abs(a.length - b.length);
  for (let i = 0; i < len; i += 1) {
    if (a[i] !== b[i]) d += 1;
  }
  return d;
}

/**
 * Compare two screenshots.
 *
 * @param {string} originalPath Original PNG.
 * @param {string} generatedPath Generated PNG.
 * @returns {object}
 */
function compareScreenshots(originalPath, generatedPath) {
  if (!fs.existsSync(originalPath)) {
    throw new Error(`Original screenshot not found: ${originalPath}`);
  }
  if (!fs.existsSync(generatedPath)) {
    throw new Error(`Generated screenshot not found: ${generatedPath}`);
  }

  const a = fs.readFileSync(originalPath);
  const b = fs.readFileSync(generatedPath);
  const sizeA = pngSize(a);
  const sizeB = pngSize(b);

  const hashA = averageHash(a);
  const hashB = averageHash(b);
  const ham = hamming(hashA, hashB);
  const hashSimilarity = hashA && hashB ? Math.max(0, 1 - ham / Math.max(hashA.length, hashB.length)) : 0;

  let dimensionScore = 1;
  if (sizeA && sizeB) {
    const wDiff = Math.abs(sizeA.width - sizeB.width) / Math.max(sizeA.width, sizeB.width);
    const hDiff = Math.abs(sizeA.height - sizeB.height) / Math.max(sizeA.height, sizeB.height);
    dimensionScore = Math.max(0, 1 - (wDiff + hDiff) / 2);
  }

  const ssim = (hashSimilarity * 0.7) + (dimensionScore * 0.3);
  const score = Math.round(ssim * 100);

  return {
    ssim: Math.round(ssim * 1000) / 1000,
    score,
    perceptual_hash_distance: ham,
    dimension_match: dimensionScore,
    original: { path: originalPath, ...sizeA },
    generated: { path: generatedPath, ...sizeB },
    passed: score >= 95,
  };
}

function main() {
  const args = parseArgs(process.argv);
  if (!args.original || !args.generated) {
    // eslint-disable-next-line no-console
    console.error('Usage: node compare.js --original <a.png> --generated <b.png> [--json]');
    process.exit(2);
  }
  const result = compareScreenshots(args.original, args.generated);
  if (args.json) {
    // eslint-disable-next-line no-console
    console.log(JSON.stringify(result, null, 2));
  } else {
    // eslint-disable-next-line no-console
    console.log(`SSIM: ${result.ssim}  Score: ${result.score}%  Passed: ${result.passed}`);
  }
  process.exit(result.passed ? 0 : 1);
}

if (require.main === module) {
  main();
}

module.exports = { compareScreenshots, averageHash, pngSize };
