#!/usr/bin/env node
'use strict';

/**
 * Long-running HTTP rendering service.
 *
 * Endpoints:
 *   GET  /health           -> { ok: true }
 *   POST /convert          -> body { inputPath, outDir, config } => layout document
 *
 * Optional bearer-token auth via the H2E_SERVICE_TOKEN environment variable.
 * Listens on H2E_PORT (default 8745).
 */

const http = require('http');
const { renderToLayout } = require('./lib/extractor');

const PORT = parseInt(process.env.H2E_PORT || '8745', 10);
const HOST = process.env.H2E_HOST || '127.0.0.1';
const TOKEN = process.env.H2E_SERVICE_TOKEN || '';

/**
 * Read and JSON-parse a request body.
 *
 * @param {http.IncomingMessage} req Request.
 * @returns {Promise<object>}
 */
function readJson(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', (chunk) => {
      data += chunk;
      if (data.length > 25 * 1024 * 1024) {
        reject(new Error('Payload too large'));
        req.destroy();
      }
    });
    req.on('end', () => {
      if (!data) {
        resolve({});
        return;
      }
      try {
        resolve(JSON.parse(data));
      } catch (e) {
        reject(e);
      }
    });
    req.on('error', reject);
  });
}

/**
 * Write a JSON response.
 *
 * @param {http.ServerResponse} res     Response.
 * @param {number}              status  HTTP status.
 * @param {object}              payload Body.
 */
function sendJson(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(body),
  });
  res.end(body);
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    sendJson(res, 200, { ok: true, service: 'h2e-chromium-service' });
    return;
  }

  if (req.method === 'POST' && (req.url === '/convert' || req.url === '/render')) {
    if (TOKEN) {
      const auth = req.headers.authorization || '';
      if (auth !== `Bearer ${TOKEN}`) {
        sendJson(res, 401, { error: 'Unauthorized' });
        return;
      }
    }
    try {
      const body = await readJson(req);
      if (!body.inputPath) {
        sendJson(res, 400, { error: 'inputPath is required' });
        return;
      }
      const outDir = body.outDir || require('os').tmpdir();
      const layout = await renderToLayout(body.inputPath, outDir, body.config || {});
      sendJson(res, 200, layout);
    } catch (e) {
      sendJson(res, 500, { error: e.message });
    }
    return;
  }

  sendJson(res, 404, { error: 'Not found' });
});

server.listen(PORT, HOST, () => {
  // eslint-disable-next-line no-console
  console.log(`h2e-chromium-service listening on http://${HOST}:${PORT}`);
});
