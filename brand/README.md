The Deprogrammer Project runs **Deprogramming** (deprogrammer.org/network), a single page that gathers the independent commentators, legal analysts and newsrooms Keith actually watches. This kit governs that page and everything that promotes it: YouTube thumbnails, Shorts, the weekly roundup and the channel trailer posted to **@deprogrammerproject** (https://www.youtube.com/@deprogrammerproject).

## Content fundamentals

- **Voice: calm, specific, unhyped.** The network's own thumbnails shout (ALL CAPS, arrows, "BREAKING"). Ours don't: the promise is a clear head, so the copy is the steady voice in a loud feed. Say what happened, who said it, where to watch.
  - Yes: "Three courts ruled on it this week. Here's who explained it best."
  - No: "🚨 Trump DESTROYED in court!!!"
- **Person:** "we" for the network, "you" for the viewer. Never speak for a creator; quote them or name them.
- **Casing:** sentence case everywhere except `thumb-headline`, `title-card` and `kicker`, which are uppercase by design.
- **No emoji** in titles, captions or thumbnails.
- **Always credit:** every clip, title or screenshot names its channel on screen (`LowerThird`) and in the description.
- **Call to action, one per video:** "Watch the whole network at deprogrammer.org/network". The URL is always written out in full, never as a QR code alone.

## Clips policy (decided 2026-10-14)

Promos are built from **our own motion graphics** (titles, channel names and avatars, screen captures of the Deprogramming page) plus **real footage only from creators who have given permission**. No downloading other channels' videos (YouTube terms) and no unlicensed excerpts (Content ID claims and copyright strikes land on @deprogrammerproject). Keep each permission (who, what, date) with the video's project files.

## Visual foundations

- **Colour.** The logo's greens carry the brand: `brand-green` is the accent on the web page; `brand-green-deep` and `brand-green-bright` are the two stops of the logo gradient for large shapes on video. `parchment` (the warm neutral in the mark) is the quiet second colour on dark video grounds. `signal-magenta` exists for one job only, the thumbnail outline (below).
- **Video is dark.** Every video frame and thumbnail sits on `cinema-black`, with `caption-ink` type, `parchment` kickers and `brand-green-bright` rules and bars. Never put brand-green-bright text on a light ground (2.4:1).
- **Type.** `display` (Big Shoulders Display, Google Fonts) is for video and thumbnails only: tall, condensed, cinematic. `sans` (Inter) for the web page, captions and lower thirds. `serif` only for the "Deprogramming" page name beside the logo.
- **Shape.** Rounded corners on the web (`radius-md` thumbnails, `radius-pill` chips). On video, the recurring motif is the logo's circle: discs, arcs and concentric rings (the way out of the rabbit hole), never icons or arrows.
- **Motion.** Slow, deliberate: 400-700ms eases (`cubic-bezier(.2,.7,.2,1)`), text revealed by line, one camera move per scene (a slow push-in on stills). No shakes, whooshes or flashing. Respect a 2-second hold on every title so it can be read.
- **Footage treatment.** Permitted clips play inside a `radius-md` frame on cinema-black with a `LowerThird`; never full-bleed without the credit.

## The thumbnail outline (experiment)

Keith noticed that Secular Talk's thumbnails carry a bright green outline, and that it makes them easy to spot in a crowded feed, including on our own page. Our promo thumbnails get a signature outline too:

- **Colour:** `signal-magenta` (proposed). It can't be green: that is Secular Talk's mark, and our accent is already green. Magenta is the one hue almost no political thumbnail uses (they live in red, blue, yellow and green), so it reads as ours at a glance. Alternatives to test are in the Thumbnail preview.
- **Size:** `thumb-outline` (14px at 1280x720), inset, square corners on the image (YouTube rounds the corners itself).
- **Every** promo thumbnail gets it, including Shorts covers. Network videos on our page are never outlined.
- **Measure it:** after 10 uploads, compare click-through rate in YouTube Studio against the first uploads without it.

## Video formats

| Format | Size | Length | Structure |
|---|---|---|---|
| Shorts | `short-width` x `short-height` (1080x1920) | 30-58s | Hook title (2s) → 3-5 beats with captions → end card with the URL |
| Weekly roundup | `video-width` (1920x1080) | 1-3 min | `TitleCard` → featured highlights (one per channel, `LowerThird` each) → `EndScreen` (20s) |
| Channel trailer | 1920x1080 | 30-45s | What the network is → how it's curated → `EndScreen` |

- Keep all text inside `safe-margin`; on Shorts also keep clear of the bottom 420px and right 140px (YouTube's UI).
- Captions burned in on Shorts (most watch muted); roundup and trailer also get an uploaded caption file.
- End screens run the last 20 seconds (YouTube's maximum) with the subscribe and video elements placed in `EndScreen`'s marked zones.

## Iconography

The Deprogrammer Project uses its logo, not an icon set. Use `dp-mark-color` (or `dp-mark-white` on footage) as the only symbol; no emoji, no stock icons. UI glyphs on the web page (theme toggle, play button) are simple drawn shapes in the current text colour.
