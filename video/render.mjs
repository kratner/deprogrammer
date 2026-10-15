// FILE: render.mjs
// PATH: video/render.mjs
//
// Renders a composition to MP4 plus a thumbnail and upload text:
//   node render.mjs roundup [--out out/roundup-YYYY-MM-DD] [--fps 30] [--stills-only]
// Reads <out>/data.json (made by roundup/data.mjs; edit it first to rename topics etc.).
// Writes into <out>:
//   roundup.mp4          1920x1080 H.264, silent AAC track (add music in YouTube Studio)
//   thumbnail.png        1280x720, magenta outline (thumbnail-plain.png: no outline, the control)
//   stills/*.png         one frame from the middle of each scene, for checking before upload
//   youtube.txt          title, description (with the tracked link), tags
//
// Frames come from Playwright's Chromium: the page exposes __seek(seconds), and every
// motion is a CSS animation, so each frame is exact. ffmpeg reads JPEG frames from stdin.

import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const argv = process.argv.slice(2);
const comp = argv[0] || 'roundup';
const opt = (k, d) => { const i = argv.indexOf('--' + k); return i === -1 ? d : (argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : true); };
const fps = Number(opt('fps', 30));
const latest = () => readdirSync(join(HERE, 'out')).filter((d) => d.startsWith(comp + '-')).sort().pop();
const outDir = resolve(HERE, opt('out', join('out', latest() || '')));
const data = JSON.parse(readFileSync(join(outDir, 'data.json'), 'utf8'));

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
page.on('pageerror', (e) => { console.error('page error:', e.message); process.exitCode = 1; });
await page.addInitScript((d) => { window.ROUNDUP = d; }, data);
await page.goto(pathToFileURL(join(HERE, comp, comp + '.html')).href);
await page.waitForFunction(() => window.__ready === true, null, { timeout: 60000 });
const duration = await page.evaluate(() => window.__duration);
const scenes = await page.evaluate(() => [...document.querySelectorAll('.scene')].map((s) => ({ cls: s.className.replace('scene ', ''), at: parseFloat(s.style.getPropertyValue('--at')), dur: parseFloat(s.style.getPropertyValue('--dur')) })));
console.log(`${comp}: ${duration.toFixed(1)}s, ${scenes.length} scenes, ${Math.round(duration * fps)} frames @ ${fps}fps`);

// Stills: the moment each scene is fully on screen (late in the scene, before it fades).
mkdirSync(join(outDir, 'stills'), { recursive: true });
for (const [i, s] of scenes.entries()) {
	await page.evaluate((t) => window.__seek(t), s.at + s.dur * 0.85);
	await page.screenshot({ path: join(outDir, 'stills', `${String(i + 1).padStart(2, '0')}-${s.cls}.png`) });
}

// Thumbnail (and the no-outline control for the click-through test).
const top = data.topics.slice(0, 2).map((t) => t.topic);
const thumbHeadline = data.thumbHeadline || `What ${data.stats.channels} channels covered this week`;
const tp = await browser.newPage({ viewport: { width: 1280, height: 720 } });
for (const [file, outline] of [['thumbnail.png', '#ff2e93'], ['thumbnail-plain.png', 'none']]) {
	await tp.addInitScript((t) => { window.THUMB = t; }, { headline: thumbHeadline, kicker: `Weekly roundup · ${data.range}`, outline });
	await tp.goto(pathToFileURL(join(HERE, comp, 'thumbnail.html')).href);
	await tp.waitForFunction(() => window.__ready === true);
	await tp.screenshot({ path: join(outDir, file) });
}

// Upload text.
const title = data.youtubeTitle || `This week on the network: ${top.join(', ')} | Deprogramming roundup ${data.range}`.slice(0, 100);
const desc = [
	`What ${data.stats.channels} independent news & politics channels covered ${data.range}, in one minute. Every channel, every new video, on one page:`,
	data.link,
	'',
	'This week:',
	...data.topics.map((t) => `• ${t.topic}: ${t.videos} videos from ${t.channels} channels`),
	'',
	'Deprogramming gathers the independent voices, legal analysts and newsrooms worth your time. Sign in (free) to follow channels and save videos for later.',
	'',
	'Video titles quoted are from the channels named on screen; all graphics are our own.',
].join('\n');
const tags = ['news roundup', 'politics', 'independent media', ...top, 'Deprogramming', 'Deprogrammer Project'].join(', ');
writeFileSync(join(outDir, 'youtube.txt'), `TITLE\n${title}\n\nDESCRIPTION\n${desc}\n\nTAGS\n${tags}\n\nTHUMBNAIL\nthumbnail.png (outlined). thumbnail-plain.png is the no-outline control for a later A/B test.\n`);

if (!opt('stills-only', false)) {
	const mp4 = join(outDir, comp + '.mp4');
	const ff = spawn('ffmpeg', ['-y', '-loglevel', 'error',
		'-f', 'image2pipe', '-framerate', String(fps), '-c:v', 'mjpeg', '-i', '-',
		'-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo',
		'-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p', '-r', String(fps),
		'-c:a', 'aac', '-b:a', '128k', '-shortest', '-movflags', '+faststart', mp4], { stdio: ['pipe', 'inherit', 'inherit'] });
	const done = new Promise((res, rej) => ff.on('close', (c) => (c === 0 ? res() : rej(new Error('ffmpeg exited ' + c)))));
	const frames = Math.round(duration * fps);
	const t0 = Date.now();
	for (let f = 0; f < frames; f++) {
		await page.evaluate((t) => window.__seek(t), f / fps);
		const buf = await page.screenshot({ type: 'jpeg', quality: 95 });
		if (!ff.stdin.write(buf)) await new Promise((r) => ff.stdin.once('drain', r));
		if (f % (fps * 10) === 0) process.stdout.write(`  frame ${f}/${frames} (${Math.round((Date.now() - t0) / 1000)}s)\n`);
	}
	ff.stdin.end();
	await done;
	console.log(`→ ${mp4} (${(statSync(mp4).size / 1e6).toFixed(1)} MB)`);
}
await browser.close();
console.log(`→ ${outDir}/{stills/, thumbnail.png, thumbnail-plain.png, youtube.txt}`);
