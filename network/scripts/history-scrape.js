// FILE: history-scrape.js
// PATH: network/scripts/history-scrape.js
//
// Browser snippet, not a Node script. Run the whole function with chrome-devtools
// `evaluate_script` (waitForStableDom: false) in a tab that's on
// https://www.youtube.com/feed/history and signed in to the curator's personal Google account.
// Use an isolatedContext tab, as described in CLAUDE.md under "Weekly review".
// Change DAYS if needed.
//
// It scrolls until it's past the window, then returns per-channel watch counts (`channels`)
// and every watched video in history order, most recent first (`items`: videoId, title,
// channelId, channelName, day). Make sure the "All" chip is selected, not "Music". Save the
// returned JSON as reviews/<to-date>.json and run:
//   node scripts/channels.mjs review reviews/<to-date>.json --describe   (weekly review)
//   node scripts/channels.mjs watched reviews/<to-date>.json             (watched list)
//
// Counts come from the history page, which lists a video at most once per day. The
// channel ID is the first UC… browseId in each lockup, which is the uploader. On a
// collab video, only the first channel counts.

async () => {
  const DAYS = 7;

  const midnight = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const today = midnight(new Date());
  const cutoff = new Date(today); cutoff.setDate(cutoff.getDate() - (DAYS - 1)); // DAYS days including today
  const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  // Section headers: "Today", "Yesterday", a weekday name (this past week), "Oct 5", "Dec 30, 2025".
  const parse = (h) => {
    if (!h) return null;
    if (h === 'Today') return today;
    if (h === 'Yesterday') { const d = new Date(today); d.setDate(d.getDate() - 1); return d; }
    const wd = WEEKDAYS.indexOf(h);
    if (wd >= 0) { const d = new Date(today); let back = (today.getDay() - wd + 7) % 7; if (back < 2) back += 7; d.setDate(d.getDate() - back); return d; }
    let d = new Date(/\d{4}/.test(h) ? h : `${h}, ${today.getFullYear()}`);
    if (isNaN(d)) return null;
    if (d > today) d.setFullYear(d.getFullYear() - 1);
    return midnight(d);
  };
  const header = (sec) => sec.querySelector('#header')?.innerText.trim().split('\n')[0] || '';
  // Only visible sections: switching chips (All/Music) leaves the old ones hidden in the DOM.
  const sections = () => [...document.querySelectorAll('ytd-item-section-renderer')].filter((s) => s.offsetParent !== null);

  // Scroll until the oldest loaded section is before the cutoff, or nothing more loads.
  let last = 0, stall = 0;
  for (let i = 0; i < 120; i++) {
    const s = sections(); const d = parse(header(s[s.length - 1]));
    if (d && d < cutoff) break;
    window.scrollTo(0, document.documentElement.scrollHeight);
    await new Promise((r) => setTimeout(r, 1500));
    const n = sections().length;
    if (n === last) { if (++stall > 4) break; } else stall = 0;
    last = n;
  }

  const agg = new Map();
  const items = [];
  let total = 0, unparsed = new Set();
  for (const sec of sections()) {
    const h = header(sec), d = parse(h);
    if (!d) { unparsed.add(h); continue; }
    if (d < cutoff) continue;
    for (const c of sec.data?.contents || []) {
      const s = JSON.stringify(c);
      const id = (s.match(/"browseId":"(UC[\w-]{22})"/) || [])[1];
      if (!id) continue;
      const canon = (s.match(/"canonicalBaseUrl":"(\/@[^"]+)"/) || [])[1];
      const name = c.lockupViewModel?.metadata?.lockupMetadataViewModel?.metadata?.contentMetadataViewModel
        ?.metadataRows?.[0]?.metadataParts?.[0]?.text?.content?.trim();
      const a = agg.get(id) || { channelId: id, name: name || null, url: null, watches: 0 };
      a.watches++; total++;
      const vid = c.lockupViewModel?.contentId;
      if (/^[\w-]{11}$/.test(vid || '')) {
        items.push({ videoId: vid, title: c.lockupViewModel?.metadata?.lockupMetadataViewModel?.title?.content || '', channelId: id, channelName: name || null, day: d.toLocaleDateString('en-CA') });
      }
      if (!a.name && name) a.name = name;
      if (!a.url && canon) a.url = 'https://www.youtube.com' + decodeURIComponent(canon);
      agg.set(id, a);
    }
  }
  const iso = (d) => d.toLocaleDateString('en-CA');
  const oldest = parse(header(sections().at(-1)));
  return {
    from: iso(cutoff), to: iso(today), days: DAYS,
    reachedCutoff: !!(oldest && oldest < cutoff),
    unparsedHeaders: [...unparsed],
    totalWatches: total,
    channels: [...agg.values()].sort((a, b) => b.watches - a.watches),
    items,
  };
}
