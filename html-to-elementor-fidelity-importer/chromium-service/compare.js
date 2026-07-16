'use strict';

/**
 * Visual comparison engine for fidelity validation.
 *
 * Decodes PNG pixels (zlib IDAT) and computes a real mean-absolute-error
 * similarity on a downsampled grayscale grid. Falls back to dimension +
 * compressed-byte fingerprint only when decode fails.
 *
 * Usage:
 *   node compare.js --original shot-a.png --generated shot-b.png [--json]
 */

const fs = require('fs');
const zlib = require('zlib');

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
 * Read PNG IHDR metadata.
 *
 * @param {Buffer} buf PNG buffer.
 * @returns {{width:number,height:number,bitDepth:number,colorType:number}|null}
 */
function pngHeader(buf) {
  if (buf.length < 33 || buf.toString('ascii', 1, 4) !== 'PNG') return null;
  return {
    width: buf.readUInt32BE(16),
    height: buf.readUInt32BE(20),
    bitDepth: buf[24],
    colorType: buf[25],
  };
}

/**
 * Collect concatenated IDAT payload from a PNG.
 *
 * @param {Buffer} buf PNG buffer.
 * @returns {Buffer|null}
 */
function extractIdat(buf) {
  let offset = 8;
  const parts = [];
  while (offset + 8 <= buf.length) {
    const len = buf.readUInt32BE(offset);
    const type = buf.toString('ascii', offset + 4, offset + 8);
    const start = offset + 8;
    const end = start + len;
    if (end + 4 > buf.length) break;
    if (type === 'IDAT') parts.push(buf.subarray(start, end));
    if (type === 'IEND') break;
    offset = end + 4;
  }
  if (!parts.length) return null;
  return Buffer.concat(parts);
}

/**
 * Paeth predictor helper.
 *
 * @param {number} a Left.
 * @param {number} b Above.
 * @param {number} c Upper-left.
 * @returns {number}
 */
function paeth(a, b, c) {
  const p = a + b - c;
  const pa = Math.abs(p - a);
  const pb = Math.abs(p - b);
  const pc = Math.abs(p - c);
  if (pa <= pb && pa <= pc) return a;
  if (pb <= pc) return b;
  return c;
}

/**
 * Decode PNG to grayscale samples on a GRID x GRID lattice.
 * Supports 8-bit color types 2 (RGB), 6 (RGBA), 0 (Gray), 4 (Gray+A).
 *
 * @param {Buffer} buf PNG buffer.
 * @param {number} grid Sample grid size.
 * @returns {Uint8Array|null}
 */
function decodeGrayGrid(buf, grid = 32) {
  const header = pngHeader(buf);
  if (!header || header.bitDepth !== 8) return null;
  const { width, height, colorType } = header;
  if (width < 1 || height < 1) return null;

  let channels = 0;
  if (colorType === 0) channels = 1;
  else if (colorType === 2) channels = 3;
  else if (colorType === 4) channels = 2;
  else if (colorType === 6) channels = 4;
  else return null;

  const compressed = extractIdat(buf);
  if (!compressed) return null;

  let raw;
  try {
    raw = zlib.inflateSync(compressed);
  } catch (e) {
    return null;
  }

  const stride = width * channels;
  const expected = height * (1 + stride);
  if (raw.length < expected) return null;

  const rgba = new Uint8Array(width * height);
  let src = 0;
  let prev = new Uint8Array(stride);
  let curr = new Uint8Array(stride);

  for (let y = 0; y < height; y += 1) {
    const filter = raw[src];
    src += 1;
    for (let i = 0; i < stride; i += 1) {
      curr[i] = raw[src + i];
    }
    src += stride;

    for (let i = 0; i < stride; i += 1) {
      const left = i >= channels ? curr[i - channels] : 0;
      const up = prev[i];
      const upLeft = i >= channels ? prev[i - channels] : 0;
      let val = curr[i];
      if (filter === 1) val = (val + left) & 255;
      else if (filter === 2) val = (val + up) & 255;
      else if (filter === 3) val = (val + Math.floor((left + up) / 2)) & 255;
      else if (filter === 4) val = (val + paeth(left, up, upLeft)) & 255;
      else if (filter !== 0) return null;
      curr[i] = val;
    }

    for (let x = 0; x < width; x += 1) {
      const i = x * channels;
      let g;
      if (channels === 1 || channels === 2) {
        g = curr[i];
      } else {
        // Rec. 601 luma.
        g = Math.round(0.299 * curr[i] + 0.587 * curr[i + 1] + 0.114 * curr[i + 2]);
      }
      rgba[y * width + x] = g;
    }

    const tmp = prev;
    prev = curr;
    curr = tmp;
  }

  const samples = new Uint8Array(grid * grid);
  for (let gy = 0; gy < grid; gy += 1) {
    for (let gx = 0; gx < grid; gx += 1) {
      const x = Math.min(width - 1, Math.floor(((gx + 0.5) / grid) * width));
      const y = Math.min(height - 1, Math.floor(((gy + 0.5) / grid) * height));
      samples[gy * grid + gx] = rgba[y * width + x];
    }
  }
  return samples;
}

/**
 * Mean absolute error similarity in [0,1] for two gray grids.
 *
 * @param {Uint8Array} a Grid A.
 * @param {Uint8Array} b Grid B.
 * @returns {number}
 */
function maeSimilarity(a, b) {
  const n = Math.min(a.length, b.length);
  if (!n) return 0;
  let sum = 0;
  for (let i = 0; i < n; i += 1) sum += Math.abs(a[i] - b[i]);
  return Math.max(0, 1 - sum / (n * 255));
}

/**
 * Fallback fingerprint over compressed bytes (not perceptual).
 *
 * @param {Buffer} buf PNG buffer.
 * @returns {string}
 */
function averageHash(buf) {
  const size = pngHeader(buf);
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
 * Compare two screenshots with decoded-pixel MAE when possible.
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
  const sizeA = pngHeader(a);
  const sizeB = pngHeader(b);

  let dimensionScore = 1;
  if (sizeA && sizeB) {
    const wDiff = Math.abs(sizeA.width - sizeB.width) / Math.max(sizeA.width, sizeB.width);
    const hDiff = Math.abs(sizeA.height - sizeB.height) / Math.max(sizeA.height, sizeB.height);
    dimensionScore = Math.max(0, 1 - (wDiff + hDiff) / 2);
  }

  const gridA = decodeGrayGrid(a, 32);
  const gridB = decodeGrayGrid(b, 32);
  let method = 'pixel_mae';
  let pixelSimilarity = 0;

  if (gridA && gridB) {
    pixelSimilarity = maeSimilarity(gridA, gridB);
  } else {
    method = 'compressed_byte_hash_fallback';
    const hashA = averageHash(a);
    const hashB = averageHash(b);
    const ham = hamming(hashA, hashB);
    pixelSimilarity = hashA && hashB ? Math.max(0, 1 - ham / Math.max(hashA.length, hashB.length)) : 0;
  }

  const similarity = (pixelSimilarity * 0.85) + (dimensionScore * 0.15);
  const score = Math.round(similarity * 100);

  return {
    // Keep legacy keys for PHP consumers; values are now pixel-MAE based when decode works.
    ssim: Math.round(similarity * 1000) / 1000,
    score,
    pixel_similarity: Math.round(pixelSimilarity * 1000) / 1000,
    dimension_match: Math.round(dimensionScore * 1000) / 1000,
    method,
    original: { path: originalPath, width: sizeA ? sizeA.width : 0, height: sizeA ? sizeA.height : 0 },
    generated: { path: generatedPath, width: sizeB ? sizeB.width : 0, height: sizeB ? sizeB.height : 0 },
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
    console.log(`Similarity: ${result.ssim}  Score: ${result.score}%  Method: ${result.method}  Passed: ${result.passed}`);
  }
  process.exit(result.passed ? 0 : 1);
}

if (require.main === module) {
  main();
}

module.exports = { compareScreenshots, averageHash, pngSize: (buf) => {
  const h = pngHeader(buf);
  return h ? { width: h.width, height: h.height } : null;
}, decodeGrayGrid, maeSimilarity };
