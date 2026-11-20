#!/usr/bin/env node
// FILE: make-history.mjs
// PATH: network/dev/make-history.mjs
//
// Writes a synthetic watch-history archive for load-testing the /history view locally:
// real lineup channels, made-up titles and video ids (their thumbnails 404, which is fine).
//   node dev/make-history.mjs [--videos 20000] [--days 365] [--out /tmp/history-20k.json]
//   node scripts/build-plugin.mjs --history /tmp/history-20k.json
// Then rebuild with the real archive (no --history) before deploying.

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const argv = process.argv.slice(2);
const opt = (k, d) => { const i = argv.indexOf(`--${k}`); return i >= 0 ? argv[i + 1] : d; };
const total = Number(opt('videos', 20000)), days = Number(opt('days', 365));
const out = opt('out', '/tmp/history-20k.json');

const lineup = JSON.parse(readFileSync(join(ROOT, 'private', 'channels.json'), 'utf8')).channels;
let seed = 42;
const rnd = () => ((seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648);
const pick = (a) => a[Math.floor(rnd() * a.length)];
const ID = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
const words = 'Trump Senate court ruling BREAKING judge shocking moment Congress vote collapse exposed live reaction DOJ election poll Fox host melts down fired subpoena hearing scandal'.split(' ');
const today = new Date();
const items = [];
for (let i = 0; i < total; i++) {
  const d = new Date(today); d.setDate(d.getDate() - Math.floor((i / total) * days));
  const c = pick(lineup);
  const title = Array.from({ length: 6 + Math.floor(rnd() * 8) }, () => pick(words)).join(' ');
  items.push({ id: Array.from({ length: 11 }, () => pick(ID)).join(''), title, channelId: c.channelId, uploaderId: c.channelId, uploaderName: c.name, day: d.toLocaleDateString('en-CA') });
}
writeFileSync(out, JSON.stringify({ updated: today.toLocaleDateString('en-CA'), items }));
console.log(`wrote ${items.length} synthetic videos over ${days} days → ${out}`);
