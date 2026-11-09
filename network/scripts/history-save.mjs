#!/usr/bin/env node
// FILE: history-save.mjs
// PATH: network/scripts/history-save.mjs
//
// Saves a watch-history snapshot from the automation Chrome into private/reviews/,
// without going through the chrome-devtools MCP `filePath` (which can only write inside
// the Claude session's workspace).
//
// It connects to Chrome's DevTools port, finds the open youtube.com/feed/history tab and:
//   - by default, saves `window.__scrape` if a session already scraped into it;
//   - with --scrape (or when there's no __scrape), runs scripts/history-scrape.js in the tab.
//
// Usage (from network/):
//   node scripts/history-save.mjs                      # save window.__scrape
//   node scripts/history-save.mjs --scrape [--days 7]  # scrape now, then save
//   options: --port 9222  --out private/reviews/<file>.json
//
// Default output: private/reviews/<to>-<days>d.json (overwrites; a full snapshot is a
// superset of a network-only one). Needs Node 22+ (built-in WebSocket).

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const opt = (k, d) => { const i = args.indexOf(`--${k}`); return i >= 0 ? args[i + 1] : d; };
const port = opt('port', '9222');
const days = Number(opt('days', 7));
const forceScrape = args.includes('--scrape');
const die = (m) => { console.error(m); process.exit(1); };

const targets = await fetch(`http://127.0.0.1:${port}/json/list`).then((r) => r.json())
  .catch(() => die(`No Chrome DevTools on port ${port}. Is the automation Chrome running?`));
const tabs = targets.filter((t) => t.type === 'page' && t.url.startsWith('https://www.youtube.com/feed/history'));
if (!tabs.length) die('No youtube.com/feed/history tab open. Open one (signed in as the Keith Ratner channel) and rerun.');

function evaluate(wsUrl, expression) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(wsUrl);
    ws.onopen = () => ws.send(JSON.stringify({ id: 1, method: 'Runtime.evaluate', params: { expression, awaitPromise: true, returnByValue: true, timeout: 300000 } }));
    ws.onmessage = (e) => {
      const m = JSON.parse(e.data);
      if (m.id !== 1) return;
      ws.close();
      if (m.error) return reject(new Error(m.error.message));
      if (m.result.exceptionDetails) return reject(new Error(m.result.exceptionDetails.exception?.description || 'page exception'));
      resolve(m.result.result.value);
    };
    ws.onerror = () => reject(new Error(`WebSocket error on ${wsUrl}`));
  });
}

const signedIn = '(() => !!(window.ytcfg && ytcfg.get("LOGGED_IN")))()';
const existing = 'window.__scrape ? JSON.stringify(window.__scrape) : null';
const scrapeSrc = readFileSync(join(here, 'history-scrape.js'), 'utf8')
  .replace(/^\/\/.*$/gm, '')
  .replace(/const DAYS = \d+;/, `const DAYS = ${days};`);
const runScrape = `(async () => { const r = await (${scrapeSrc.trim()})(); window.__scrape = r; return JSON.stringify(r); })()`;

let json = null, used = null;
for (const t of tabs) {
  if (!(await evaluate(t.webSocketDebuggerUrl, signedIn))) continue;
  if (!forceScrape) json = await evaluate(t.webSocketDebuggerUrl, existing);
  if (!json) { console.log(`Scraping ${days} days of history (this scrolls the tab; ~1 min)…`); json = await evaluate(t.webSocketDebuggerUrl, runScrape); }
  if (json) { used = t; break; }
}
if (!json) die('No signed-in history tab found. Sign in as keith.ratner@gmail.com (Keith Ratner channel) and rerun.');

const snap = JSON.parse(json);
const out = opt('out', join(here, '..', 'private', 'reviews', `${snap.to}-${snap.days}d.json`));
writeFileSync(out, JSON.stringify(snap) + '\n');
console.log(`Saved ${out}`);
console.log(`  ${snap.from} → ${snap.to}, ${snap.totalWatches} watches, ${snap.items?.length ?? 0} items, reachedCutoff ${snap.reachedCutoff}` +
  (snap.unparsedHeaders?.length ? `, UNPARSED: ${snap.unparsedHeaders.join(', ')}` : ''));
if (!snap.reachedCutoff) console.log('  WARNING: window incomplete (did not scroll past the cutoff).');
