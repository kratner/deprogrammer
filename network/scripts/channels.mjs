#!/usr/bin/env node
// FILE: channels.mjs
// PATH: network/scripts/channels.mjs
//
// Maintains channels.json, the Channel Network lineup. Run it from the project root.
//
//   node scripts/channels.mjs resolve <link|@handle|UC id|video link>
//   node scripts/channels.mjs add     <link…> --category <key> [--network "<name>"]
//   node scripts/channels.mjs remove  <channelId|name> [--exclude "<reason>"]
//   node scripts/channels.mjs exclude <link…> --reason "<reason>"
//   node scripts/channels.mjs review  reviews/<date>.json [--min 3] [--describe]
//   node scripts/channels.mjs feeds   (checks every listed channel's RSS feed)
//   node scripts/channels.mjs weights reviews/<date>.json --seed   (reset habit weights from a snapshot)
//   node scripts/channels.mjs weights reviews/<date>.json          (weekly update: moving average)
//   node scripts/channels.mjs weights                              (show current weights)
//   node scripts/channels.mjs watched reviews/<date>.json [--days 7] [--max 60]
//        Builds watched.json: the news videos Keith actually watched (lineup channels and
//        their folded-in fan channels only, so music/off-topic never appear), most recent
//        watch first, one entry per video. build-plugin.mjs ships it to the page's
//        "Recently watched" section. watched.json is gitignored (personal viewing data).
//   node scripts/channels.mjs music reviews/<date>.json [--music reviews/<date>-music.json] [--apply]
//        Finds YouTube Music listening among a snapshot's unreviewed channels and, with
//        --apply, adds it to the excluded list so reviews never show it. Music = any of:
//        it appears in a snapshot taken with YouTube history's "Music" filter (--music);
//        its name ends in "- Topic" or "VEVO"; or its Data API topic categories are music
//        with no Politics/Society/News topic. The API key only works from the server, so
//        the lookup runs there over SSH (key piped on stdin, never on a command line).
//
// Habit weight ("affinity"): Keith's watches per week of each channel (fan channels roll up
// to their parent). Seeded from a history snapshot, then updated each weekly review as an
// exponential moving average (60% previous, 40% this snapshot) so it follows changing
// habits without lurching. Manually added channels start at the lineup median.
//
// Accepted links: youtube.com/@handle, /channel/UC…, /c/…, /user/…, watch?v=…,
// youtu.be/…, /shorts/…, a bare @handle, or a bare UC… id.

import { readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { homedir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
// Personal data (the lineup with habit weights and exclusions, watched list, server config)
// lives in network/private/, which is gitignored and never published.
const PRIVATE = join(ROOT, 'private');
const DATA = join(PRIVATE, 'channels.json');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';
const HEADERS = { 'User-Agent': UA, 'Accept-Language': 'en-US,en;q=0.9', Cookie: 'CONSENT=YES+1; SOCS=CAI' };

const load = () => JSON.parse(readFileSync(DATA, 'utf8'));
const save = (d) => writeFileSync(DATA, JSON.stringify(d, null, 2) + '\n');
const today = () => new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD, local time
const feedUrl = (id) => `https://www.youtube.com/feeds/videos.xml?channel_id=${id}`;
const die = (msg) => { console.error('Error: ' + msg); process.exit(1); };

function args(argv) {
  const pos = [], opt = {};
  for (let i = 0; i < argv.length; i++) {
    if (argv[i].startsWith('--')) {
      const k = argv[i].slice(2), next = argv[i + 1];
      if (next === undefined || next.startsWith('--')) opt[k] = true; else { opt[k] = next; i++; }
    } else pos.push(argv[i]);
  }
  return { pos, opt };
}

const decode = (s) => s.replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/&quot;/g, '"')
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/\\u0026/g, '&');

async function get(url) {
  const r = await fetch(url, { headers: HEADERS, redirect: 'follow' });
  if (!r.ok) throw new Error(`${r.status} for ${url}`);
  return r.text();
}

// Normalize any accepted input to a URL that YouTube serves a page for.
function toUrl(input) {
  const s = input.trim();
  if (/^UC[\w-]{22}$/.test(s)) return `https://www.youtube.com/channel/${s}`;
  if (s.startsWith('@')) return `https://www.youtube.com/${s}`;
  if (/^https?:\/\//.test(s)) return s;
  if (/^(www\.|m\.)?youtube\.com\/|^youtu\.be\//.test(s)) return 'https://' + s;
  die(`don't recognize "${input}" as a channel or video link`);
}

// Resolve input → { name, channelId, url, description }.
async function resolve(input) {
  let url = toUrl(input).replace('://m.youtube.com', '://www.youtube.com');
  if (/youtu\.be\/|\/watch\?|\/shorts\/|\/live\//.test(url)) {
    const html = await get(url);
    const id = (html.match(/"videoDetails":\{[^}]*?"channelId":"(UC[\w-]{22})"/) || html.match(/<meta itemprop="channelId" content="(UC[\w-]{22})"/) || [])[1];
    if (!id) die(`couldn't find the channel for video ${input}`);
    url = `https://www.youtube.com/channel/${id}`;
  } else {
    url = url.replace(/(youtube\.com\/(?:@[^/?#]+|channel\/UC[\w-]{22}|c\/[^/?#]+|user\/[^/?#]+)).*$/, '$1');
  }
  const html = await get(url);
  const channelId = (html.match(/<meta itemprop="identifier" content="(UC[\w-]{22})"/)
    || html.match(/"externalId":"(UC[\w-]{22})"/)
    || html.match(/<link rel="canonical" href="https:\/\/www\.youtube\.com\/channel\/(UC[\w-]{22})"/) || [])[1];
  if (!channelId) die(`no channel found at ${url}`);
  const name = decode((html.match(/<meta property="og:title" content="([^"]*)"/) || [])[1] || '');
  const handle = (html.match(/"vanityChannelUrl":"https?:\/\/www\.youtube\.com\/(@[^"]+)"/) || [])[1];
  const description = decode((html.match(/<meta property="og:description" content="([^"]*)"/) || [])[1] || '');
  return {
    name, channelId,
    url: handle ? `https://www.youtube.com/${decodeURIComponent(handle)}` : `https://www.youtube.com/channel/${channelId}`,
    description,
  };
}

// Every channel ID the lineup already accounts for, including folded-in fan channels.
function knownIds(d) {
  const listed = new Map();
  for (const c of d.channels) {
    listed.set(c.channelId, c);
    for (const f of c.mergedFanChannels || []) listed.set(f.channelId, c);
  }
  return { listed, excluded: new Map(d.excluded.map((e) => [e.channelId, e])) };
}

function medianAffinity(d) {
  const a = d.channels.map((c) => c.affinity ?? 0).sort((x, y) => x - y);
  return a.length ? Math.round(a[Math.floor(a.length / 2)] * 10) / 10 : 1;
}

// Watches per channel in a snapshot, rolled up to listed parents, as a weekly rate.
function weeklyRates(snap, d) {
  const { listed } = knownIds(d);
  const weeks = (snap.days || 7) / 7;
  const rate = new Map();
  for (const c of snap.channels) {
    const p = listed.get(c.channelId);
    if (p) rate.set(p.channelId, (rate.get(p.channelId) || 0) + c.watches / weeks);
  }
  return rate;
}

// Data API channels.list topicCategories, looked up from the server (the key is IP-locked).
function topicCategories(ids) {
  const key = readFileSync(join(homedir(), '.config/channel-network/yt-api-key'), 'utf8').trim();
  const php = `<?php $k = json_decode(${JSON.stringify(JSON.stringify(key))}); $ids = json_decode(${JSON.stringify(JSON.stringify(ids))}, true);
$out = [];
foreach (array_chunk($ids, 50) as $chunk) {
  $j = json_decode((string) file_get_contents("https://www.googleapis.com/youtube/v3/channels?part=topicDetails&id=" . implode(",", $chunk) . "&key=" . $k), true);
  if (isset($j["error"])) { fwrite(STDERR, "API error: " . $j["error"]["message"] . "\n"); exit(1); }
  foreach ($j["items"] ?? [] as $it) $out[$it["id"]] = array_map("basename", $it["topicDetails"]["topicCategories"] ?? []);
}
echo json_encode($out);`;
  // The server's SSH details stay out of the public code: network/private/config.json
  // {"server": {"host": "…", "user": "…", "port": 22, "key": "~/.ssh/…"}}
  const { server } = JSON.parse(readFileSync(join(PRIVATE, 'config.json'), 'utf8'));
  const sshKey = server.key.replace(/^~(?=\/)/, homedir());
  const out = execFileSync('ssh', ['-i', sshKey, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-p', String(server.port), `${server.user}@${server.host}`, 'php'], { input: php, encoding: 'utf8' });
  return JSON.parse(out || '{}');
}

const isMusicTopics = (t) => t.some((x) => /music/i.test(x)) && !t.some((x) => /^(Politics|Society|News)$/i.test(x));

function sortChannels(d) {
  const order = d.categories.map((c) => c.key);
  d.channels.sort((a, b) => order.indexOf(a.category) - order.indexOf(b.category) || a.name.localeCompare(b.name));
}

const cmds = {
  async resolve({ pos }) {
    if (!pos[0]) die('usage: resolve <link>');
    const info = await resolve(pos[0]);
    const { listed, excluded } = knownIds(load());
    info.status = listed.has(info.channelId) ? `already listed (as ${listed.get(info.channelId).name})`
      : excluded.has(info.channelId) ? `excluded: ${excluded.get(info.channelId).reason}` : 'new';
    console.log(JSON.stringify(info, null, 2));
  },

  async add({ pos, opt }) {
    const d = load();
    const keys = d.categories.map((c) => c.key);
    if (!pos.length) die('usage: add <link…> --category <key>');
    if (!keys.includes(opt.category)) die(`--category must be one of: ${keys.join(', ')}`);
    for (const input of pos) {
      const info = await resolve(input);
      const { listed, excluded } = knownIds(d);
      if (listed.has(info.channelId)) { console.log(`skip  ${info.name}: already listed (as ${listed.get(info.channelId).name})`); continue; }
      if (excluded.has(info.channelId)) {
        if (!opt['un-exclude']) { console.log(`skip  ${info.name}: excluded (${excluded.get(info.channelId).reason}); pass --un-exclude to override`); continue; }
        d.excluded = d.excluded.filter((e) => e.channelId !== info.channelId);
      }
      const entry = { name: info.name, url: info.url, channelId: info.channelId, category: opt.category };
      if (typeof opt.network === 'string') entry.network = opt.network;
      entry.affinity = medianAffinity(d);
      entry.added = today(); entry.source = 'manual';
      d.channels.push(entry);
      console.log(`added ${info.name} → ${opt.category}  ${info.url}`);
    }
    sortChannels(d); save(d);
  },

  async remove({ pos, opt }) {
    const d = load();
    const q = pos.join(' ');
    if (!q) die('usage: remove <channelId|name> [--exclude "<reason>"]');
    const c = d.channels.find((x) => x.channelId === q || x.name.toLowerCase() === q.toLowerCase());
    if (!c) die(`"${q}" isn't in the lineup`);
    d.channels = d.channels.filter((x) => x !== c);
    if (opt.exclude) d.excluded.push({ name: c.name, channelId: c.channelId, reason: opt.exclude === true ? "Keith's call" : opt.exclude, date: today() });
    save(d);
    console.log(`removed ${c.name}${opt.exclude ? ' (and excluded)' : ''}`);
  },

  async exclude({ pos, opt }) {
    const d = load();
    if (!pos.length || !opt.reason) die('usage: exclude <link…> --reason "<reason>"');
    for (const input of pos) {
      const info = await resolve(input);
      const { listed, excluded } = knownIds(d);
      if (listed.has(info.channelId)) { console.log(`skip  ${info.name}: it's listed; use remove --exclude`); continue; }
      if (excluded.has(info.channelId)) { console.log(`skip  ${info.name}: already excluded`); continue; }
      d.excluded.push({ name: info.name, channelId: info.channelId, reason: opt.reason, date: today() });
      console.log(`excluded ${info.name}`);
    }
    save(d);
  },

  // Compare a history snapshot (from scripts/history-scrape.js) against the lineup.
  async review({ pos, opt }) {
    if (!pos[0]) die('usage: review reviews/<date>.json [--min 3] [--describe]');
    const snap = JSON.parse(readFileSync(pos[0], 'utf8'));
    const d = load();
    const { listed, excluded } = knownIds(d);
    const min = Number(opt.min || 3);
    const counts = new Map(); // listed channel → watches (fan channels roll up to their parent)
    const fresh = [];
    let excludedWatches = 0;
    for (const c of snap.channels) {
      if (listed.has(c.channelId)) { const p = listed.get(c.channelId); counts.set(p.channelId, (counts.get(p.channelId) || 0) + c.watches); }
      else if (excluded.has(c.channelId)) excludedWatches += c.watches;
      else fresh.push(c);
    }
    const candidates = fresh.filter((c) => c.watches >= min);
    console.log(`# Weekly review: ${snap.from} → ${snap.to}\n`);
    console.log(`${snap.totalWatches} watches across ${snap.channels.length} channels. ${excludedWatches} were from excluded channels (music/off-topic), ${fresh.length} channels are unreviewed.\n`);
    console.log(`## New channels with ${min}+ watches (${candidates.length})\n`);
    for (const c of candidates) {
      let line = `- **${c.name}** (${c.watches}): ${c.url || 'https://www.youtube.com/channel/' + c.channelId}  \`${c.channelId}\``;
      if (opt.describe) {
        try { const info = await resolve(c.channelId); line += `\n  > ${info.description.slice(0, 200).replace(/\s+/g, ' ')}`; } catch { line += '\n  > (description unavailable)'; }
      }
      console.log(line);
    }
    const quiet = d.channels.filter((c) => !counts.has(c.channelId));
    console.log(`\n## Listed channels you watched this period (${d.channels.length - quiet.length}/${d.channels.length})\n`);
    for (const c of [...d.channels].filter((c) => counts.has(c.channelId)).sort((a, b) => counts.get(b.channelId) - counts.get(a.channelId)))
      console.log(`- ${c.name}: ${counts.get(c.channelId)}`);
    console.log(`\n## Listed but not watched this period (${quiet.length})\n`);
    console.log(quiet.map((c) => c.name).join(', ') || '(none)');
    const below = fresh.length - candidates.length;
    if (below) console.log(`\n(${below} other new channels had fewer than ${min} watches; rerun with --min 1 to see them.)`);
  },

  async weights({ pos, opt }) {
    const d = load();
    if (pos[0]) {
      const snap = JSON.parse(readFileSync(pos[0], 'utf8'));
      if (!snap.reachedCutoff) die(`${pos[0]} didn't reach its cutoff; the window is incomplete`);
      const rate = weeklyRates(snap, d);
      for (const c of d.channels) {
        const r = rate.get(c.channelId) || 0;
        const next = opt.seed || c.affinity === undefined ? r : 0.6 * c.affinity + 0.4 * r;
        c.affinity = Math.round(next * 10) / 10;
      }
      // A channel with no watches still belongs to the lineup; keep it visible at a floor.
      for (const c of d.channels) if (c.affinity < 0.3) c.affinity = 0.3;
      d.weightsUpdated = { date: today(), from: pos[0].split('/').pop(), window: `${snap.from} → ${snap.to}`, mode: opt.seed ? 'seed' : 'moving average' };
      save(d);
      console.log(`${opt.seed ? 'Seeded' : 'Updated'} habit weights from ${snap.from} → ${snap.to} (${snap.days} days).\n`);
    }
    const max = Math.max(...d.channels.map((c) => c.affinity ?? 0)) || 1;
    for (const c of [...d.channels].sort((a, b) => (b.affinity ?? 0) - (a.affinity ?? 0))) {
      const w = Math.max(1, Math.round((100 * (c.affinity ?? 0)) / max));
      console.log(`${String(w).padStart(3)}  ${String(c.affinity ?? '—').padStart(5)}/wk  ${c.name}`);
    }
  },

  async watched({ pos, opt }) {
    if (!pos[0]) die('usage: watched reviews/<date>.json [--days 7] [--max 60]');
    const snap = JSON.parse(readFileSync(pos[0], 'utf8'));
    if (!Array.isArray(snap.items)) die(`${pos[0]} has no per-video items; re-pull history with the current scripts/history-scrape.js`);
    const d = load();
    const { listed, excluded } = knownIds(d);
    const days = Number(opt.days || 7), max = Number(opt.max || 60);
    const since = new Date(snap.to + 'T00:00:00'); since.setDate(since.getDate() - (days - 1));
    const sinceIso = since.toLocaleDateString('en-CA');
    const seen = new Set(), items = [];
    let skippedOther = 0;
    for (const it of snap.items) {
      if (it.day < sinceIso || seen.has(it.videoId)) continue;
      const parent = listed.get(it.channelId);
      if (!parent || excluded.has(it.channelId)) { skippedOther++; continue; }
      seen.add(it.videoId);
      items.push({ id: it.videoId, title: it.title, channelId: parent.channelId, uploaderId: it.channelId, uploaderName: it.channelName || parent.name, day: it.day });
      if (items.length >= max) break;
    }
    const out = { updated: today(), from: sinceIso, to: snap.to, source: pos[0].split('/').pop(), items };
    writeFileSync(join(PRIVATE, 'watched.json'), JSON.stringify(out, null, 2) + '\n');
    const per = {};
    for (const it of items) per[it.uploaderName] = (per[it.uploaderName] || 0) + 1;
    console.log(`watched.json: ${items.length} news videos watched ${sinceIso} → ${snap.to} (${skippedOther} watches from non-network channels left out).`);
    console.log('By channel: ' + Object.entries(per).sort((a, b) => b[1] - a[1]).map(([n, c]) => `${n} ${c}`).join(', '));
  },

  async music({ pos, opt }) {
    if (!pos[0]) die('usage: music reviews/<date>.json [--music reviews/<date>-music.json] [--apply]');
    const snap = JSON.parse(readFileSync(pos[0], 'utf8'));
    const d = load();
    const { listed, excluded } = knownIds(d);
    const chipIds = new Set(typeof opt.music === 'string' ? JSON.parse(readFileSync(opt.music, 'utf8')).channels.map((c) => c.channelId) : []);
    const fresh = snap.channels.filter((c) => !listed.has(c.channelId) && !excluded.has(c.channelId));
    const topics = fresh.length ? topicCategories(fresh.map((c) => c.channelId)) : {};
    const music = [], keep = [];
    for (const c of fresh) {
      const t = topics[c.channelId] || [];
      const why = chipIds.has(c.channelId) ? 'YouTube "Music" history filter'
        : /( - Topic|VEVO)$/i.test(c.name || '') ? 'Topic/VEVO channel'
        : isMusicTopics(t) ? `topics: ${t.join(', ')}` : '';
      (why ? music : keep).push({ ...c, why, topics: t });
    }
    console.log(`${fresh.length} unreviewed channels: ${music.length} music (${music.reduce((n, c) => n + c.watches, 0)} plays), ${keep.length} not music.\n`);
    for (const c of music.sort((a, b) => b.watches - a.watches).slice(0, opt.all ? 1e9 : 25)) console.log(`  music  ${String(c.watches).padStart(3)}  ${c.name}  (${c.why})`);
    if (opt.apply) {
      for (const c of music) d.excluded.push({ name: c.name, channelId: c.channelId, reason: 'music (YouTube Music listening)', date: today() });
      save(d);
      console.log(`\nExcluded ${music.length} music channels.`);
    } else console.log('\n(dry run; add --apply to exclude them)');
  },

  async feeds() {
    const d = load();
    const bad = [];
    await Promise.all(d.channels.map(async (c) => {
      try { const r = await fetch(feedUrl(c.channelId), { headers: HEADERS }); if (r.status !== 200) bad.push(`${c.name}: HTTP ${r.status}`); }
      catch (e) { bad.push(`${c.name}: ${e.message}`); }
    }));
    console.log(bad.length ? `${bad.length} feed(s) failing:\n` + bad.join('\n') : `all ${d.channels.length} feeds OK`);
    if (bad.length) process.exitCode = 1;
  },
};

const [cmd, ...rest] = process.argv.slice(2);
if (!cmds[cmd]) die(`commands: ${Object.keys(cmds).join(', ')}`);
await cmds[cmd](args(rest));
