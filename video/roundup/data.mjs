// FILE: data.mjs
// PATH: video/roundup/data.mjs
//
// Builds the data for one weekly roundup video:
//   node roundup/data.mjs [--end YYYY-MM-DD] [--days 7]
// → video/out/roundup-<end>/data.json
//
// Sources:
//   - the live site's feed cache (every lineup channel's recent uploads), read over SSH with
//     the server details in network/private/config.json (never committed);
//   - network/private/watched.json (what the curator watched; already public on the page).
// Only titles, channel names and counts are used: no creator footage or thumbnails.

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { homedir } from 'node:os';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const PRIVATE = join(ROOT, 'network', 'private');

const args = Object.fromEntries(process.argv.slice(2).reduce((a, v, i, all) => (v.startsWith('--') ? [...a, [v.slice(2), all[i + 1]]] : a), []));
const days = Number(args.days || 7);
const end = args.end ? new Date(args.end + 'T23:59:59') : new Date();
const start = new Date(end); start.setDate(start.getDate() - days + 1); start.setHours(0, 0, 0, 0);
const iso = (d) => d.toLocaleDateString('en-CA');

// ---- 1. The week's uploads from the live cache ----
const php = `
$cache = CN_Feeds::cache(); $out = [];
foreach ( CN_Lineup::channels() as $ch ) {
	$v = $cache['channels'][ $ch['channelId'] ]['videos'] ?? [];
	$out[] = [ 'name' => $ch['name'], 'channelId' => $ch['channelId'], 'category' => $ch['category'], 'network' => $ch['network'] ?? '',
		'videos' => array_values( array_map( fn( $x ) => [ 'id' => $x['id'], 'title' => $x['title'], 'published' => $x['published'], 'short' => $x['short'] ?? null ], $v ) ) ];
}
echo json_encode( [ 'channels' => $out, 'categories' => CN_Lineup::category_labels(), 'refreshed' => (int) $cache['refreshed_at'] ] );`;
const { server } = JSON.parse(readFileSync(join(PRIVATE, 'config.json'), 'utf8'));
const key = server.key.replace(/^~(?=\/)/, homedir());
const raw = execFileSync('ssh', ['-i', key, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-p', String(server.port), `${server.user}@${server.host}`,
	'cd ~/public_html && wp eval-file - --url=https://deprogrammer.org/ 2>/dev/null'], { input: '<?php ' + php, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
const live = JSON.parse(raw);

const from = start.getTime() / 1000, to = end.getTime() / 1000;
const uploads = [];
for (const ch of live.channels) {
	for (const v of ch.videos) {
		if (v.published < from || v.published > to || v.short === true) continue;
		uploads.push({ ...v, channel: ch.name, channelId: ch.channelId, category: ch.category });
	}
}

// ---- 2. Topics: phrases that many different channels used in their titles ----
const STOP = new Set(('a an the and or but if of to in on at by for from with without about as is are was were be been being it its this that these those he she they them his her their we our you your i me my ' +
	'not no so just now new live news breaking update latest full video watch must here there what why how who when where which will would can could should may might ' +
	'after before over under into out up down off again more most very really even still all any some every one two three first last ' +
	'gets get got goes go going says said say tells told calls call called makes make made takes take took gives give shows show reveals reveal ' +
	'has have had do does did done than then also back right left big huge total major massive insane wild shocking stunning epic ' +
	'him hers himself herself themselves us ours who whom whose ' +
	'today tonight week day days year years time times moment people thing things way ep episode part pt show podcast clip clips w s t ll re ve d m'
).split(/\s+/));
// Words that are in nearly every title on this network; as topics they say nothing.
const GENERIC = new Set(['trump', 'trump\'s', 'maga', 'gop', 'republicans', 'republican', 'democrats', 'democrat', 'president', 'white', 'house', 'admin', 'administration']);
const words = (t) => t.replace(/[‘’]/g, "'").replace(/[^\p{L}\p{N}'\s-]/gu, ' ').split(/\s+/).map((w) => w.replace(/^['-]+|['-]+$/g, '').replace(/'s$/i, '')).filter(Boolean);
const keep = (w) => w.length > 2 && !STOP.has(w.toLowerCase()) && !/^\d+$/.test(w);

const phrases = new Map(); // phrase(lower) → {label, videos:Set, channels:Set}
const add = (key, label, u) => {
	const p = phrases.get(key) || { label, videos: new Set(), channels: new Set(), samples: [] };
	if (!p.videos.has(u.id)) { p.videos.add(u.id); p.channels.add(u.channel); p.samples.push(u); }
	phrases.set(key, p);
};
for (const u of uploads) {
	const w = words(u.title);
	for (let i = 0; i < w.length; i++) {
		const a = w[i];
		if (keep(a) && !GENERIC.has(a.toLowerCase())) add(a.toLowerCase(), a, u);
		const b = w[i + 1];
		if (b && keep(a) && keep(b) && !(GENERIC.has(a.toLowerCase()) && GENERIC.has(b.toLowerCase()))) add((a + ' ' + b).toLowerCase(), a + ' ' + b, u);
	}
}
const titleCase = (s) => s.toLowerCase().replace(/(^|\s|-)(\p{L})/gu, (m, p, c) => p + c.toUpperCase());
const scored = [...phrases.values()]
	.filter((p) => p.channels.size >= 3 && p.videos.size >= 4)
	.map((p) => ({ ...p, score: p.channels.size * 2 + p.videos.size + (p.label.includes(' ') ? 4 : 0) }))
	.sort((a, b) => b.score - a.score);
// A two-word phrase beats its single words when it covers most of their videos
// ("Press Secretary" over "Press").
const pairs = scored.filter((p) => p.label.includes(' '));
const ordered = scored.map((p) => {
	if (p.label.includes(' ')) return p;
	const better = pairs.find((b) => b.label.toLowerCase().split(' ').includes(p.label.toLowerCase()) && b.videos.size >= p.videos.size * 0.5);
	return better || p;
}).filter((p, i, all) => all.indexOf(p) === i);
const topics = [];
for (const p of ordered) {
	// Skip a phrase that's mostly the same videos as one already chosen ("Ken" vs "Ken Paxton").
	if (topics.some((t) => [...p.videos].filter((v) => t._videos.has(v)).length / p.videos.size > 0.6)) continue;
	const seen = new Set();
	const samples = p.samples.sort((a, b) => b.published - a.published).filter((s) => (seen.has(s.channel) ? false : seen.add(s.channel))).slice(0, 3);
	topics.push({ topic: titleCase(p.label), videos: p.videos.size, channels: p.channels.size, samples: samples.map((s) => ({ title: s.title, channel: s.channel })), _videos: p.videos });
	if (topics.length >= 5) break;
}

// ---- 3. What the curator watched most ----
// (No "most active channels": the site caches only each channel's newest 15 uploads, so busy
// channels all cap at 15 and the ranking would be wrong. Totals are lower bounds for the
// same reason, so the video shows them rounded down: "400+".)

let curator = [];
const wPath = join(PRIVATE, 'watched.json');
if (existsSync(wPath)) {
	const w = JSON.parse(readFileSync(wPath, 'utf8'));
	const names = Object.fromEntries(live.channels.map((c) => [c.channelId, c.name]));
	const per = {};
	for (const it of w.items) if (it.day >= iso(start) && it.day <= iso(end)) { const n = names[it.channelId] || it.uploaderName; per[n] = (per[n] || 0) + 1; }
	curator = Object.entries(per).sort((a, b) => b[1] - a[1]).slice(0, 5).map(([channel, watched]) => ({ channel, watched }));
}

const fmt = (d, o) => d.toLocaleDateString('en-US', o);
const range = start.getMonth() === end.getMonth()
	? `${fmt(start, { month: 'long' })} ${start.getDate()}–${end.getDate()}, ${end.getFullYear()}`
	: `${fmt(start, { month: 'short', day: 'numeric' })} – ${fmt(end, { month: 'short', day: 'numeric' })}, ${end.getFullYear()}`;
const slug = `roundup-${iso(end)}`;
const data = {
	slug, from: iso(start), to: iso(end), range,
	stats: { uploads: uploads.length, uploadsShown: uploads.length >= 100 ? `${Math.floor(uploads.length / 50) * 50}+` : String(uploads.length), channels: new Set(uploads.map((u) => u.channel)).size, lineup: live.channels.length },
	topics: topics.map(({ _videos, ...t }) => t),
	curator,
	link: `https://deprogrammer.org/network/?utm_source=youtube&utm_medium=video&utm_campaign=${slug}`,
	linkShown: 'deprogrammer.org/network',
};
const outDir = join(HERE, '..', 'out', slug);
mkdirSync(outDir, { recursive: true });
writeFileSync(join(outDir, 'data.json'), JSON.stringify(data, null, 2) + '\n');
console.log(`${slug}: ${data.stats.uploads} uploads from ${data.stats.channels}/${data.stats.lineup} channels (${data.from} → ${data.to})`);
console.log('Topics: ' + data.topics.map((t) => `${t.topic} (${t.videos} videos, ${t.channels} channels)`).join(' · '));
console.log('Curator watched most: ' + curator.map((m) => `${m.channel} ${m.watched}`).join(', '));
console.log('→ ' + join('video', 'out', slug, 'data.json'));
