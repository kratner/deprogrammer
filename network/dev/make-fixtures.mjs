#!/usr/bin/env node
// FILE: make-fixtures.mjs
// PATH: network/dev/make-fixtures.mjs
//
// Dev only. Builds dev/fixtures/<channelId>.xml in YouTube's Atom feed format from each
// channel's public /videos page, so the local WordPress can be tested with real titles and
// thumbnails while YouTube's RSS endpoint is down. Publish times here are only approximate,
// and often all equal (the page text rarely parses), which distorts ordering. Prefer real
// timestamps: fetch via the Data API on the server (the key only works from its IP); see
// CLAUDE.md "Local testing". One request per channel, a second apart.
//   node dev/make-fixtures.mjs

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'dev/fixtures');
mkdirSync(OUT, { recursive: true });
const { channels } = JSON.parse(readFileSync(join(ROOT, 'private', 'channels.json'), 'utf8'));
const H = { 'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36', 'Accept-Language': 'en-US,en;q=0.9', Cookie: 'CONSENT=YES+1; SOCS=CAI' };
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const UNIT = { second: 1, minute: 60, hour: 3600, day: 86400, week: 604800, month: 2592000, year: 31536000 };
const ago = (t) => { const m = /(\d+)\s+(second|minute|hour|day|week|month|year)/.exec(t || ''); return m ? +m[1] * UNIT[m[2]] : 0; };

let ok = 0;
for (const c of channels) {
  try {
    const html = await (await fetch(`https://www.youtube.com/channel/${c.channelId}/videos`, { headers: H })).text();
    const json = /var ytInitialData = (\{.*?\});<\/script>/s.exec(html)?.[1];
    if (!json) throw new Error('no ytInitialData');
    const vids = [];
    (function walk(o) {
      if (!o || typeof o !== 'object' || vids.length >= 15) return;
      if (o.videoRenderer?.videoId) {
        const v = o.videoRenderer;
        vids.push({ id: v.videoId, title: v.title?.runs?.map((r) => r.text).join('') ?? '', ago: v.publishedTimeText?.simpleText ?? '' });
        return;
      }
      if (o.lockupViewModel?.contentId && o.lockupViewModel.contentType === 'LOCKUP_CONTENT_TYPE_VIDEO') {
        const l = o.lockupViewModel;
        const meta = l.metadata?.lockupMetadataViewModel;
        const parts = (meta?.metadata?.contentMetadataViewModel?.metadataRows ?? []).flatMap((r) => r.metadataParts ?? []).map((p) => p.text?.content ?? '');
        vids.push({ id: l.contentId, title: meta?.title?.content ?? '', ago: parts.find((p) => /ago/.test(p)) ?? '' });
        return;
      }
      for (const k in o) walk(o[k]);
    })(JSON.parse(json));
    const now = Date.now();
    const entries = vids.map((v) => `  <entry>\n    <id>yt:video:${v.id}</id>\n    <yt:videoId>${v.id}</yt:videoId>\n    <yt:channelId>${c.channelId}</yt:channelId>\n    <title>${esc(v.title)}</title>\n    <link rel="alternate" href="https://www.youtube.com/watch?v=${v.id}"/>\n    <published>${new Date(now - ago(v.ago) * 1000).toISOString()}</published>\n  </entry>`).join('\n');
    writeFileSync(join(OUT, `${c.channelId}.xml`), `<?xml version="1.0" encoding="UTF-8"?>\n<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">\n  <title>${esc(c.name)}</title>\n${entries}\n</feed>\n`);
    ok++;
    console.log(`ok   ${c.name}: ${vids.length}`);
  } catch (e) {
    console.log(`fail ${c.name}: ${e.message}`);
  }
  await new Promise((r) => setTimeout(r, 1000));
}
console.log(`${ok}/${channels.length} fixtures written`);
