# Promo videos

Promotional videos for YouTube [@deprogrammerproject](https://www.youtube.com/@deprogrammerproject) that send viewers to [deprogrammer.org/network](https://deprogrammer.org/network). Built from data and the brand kit (`../brand/`): our own graphics only, with channel names and video titles quoted as text, and no creator footage or thumbnails.

## Weekly roundup

```sh
cd video
npm install                          # once (Playwright; uses the Chromium Playwright downloads)
node roundup/data.mjs                # → out/roundup-<date>/data.json  (needs network/private/)
#   optional: edit data.json — rename a topic, drop a weak one, set "thumbHeadline" or "youtubeTitle"
node render.mjs roundup --stills-only   # quick check: stills/ + thumbnails + youtube.txt
node render.mjs roundup              # full render: roundup.mp4 (~70 s, ~1 min to render)
```

`out/roundup-<date>/` then holds:

| File | Use |
|---|---|
| `roundup.mp4` | Upload. 1920×1080 H.264 with a silent track: add a music bed in YouTube Studio's Audio Library. |
| `thumbnail.png` | Custom thumbnail, with the signature magenta outline (brand experiment). |
| `thumbnail-plain.png` | Same without the outline, the control for a later click-through test. |
| `youtube.txt` | Title, description (with the tracked `utm_campaign=roundup-<date>` link) and tags. |
| `stills/` | One frame per scene for checking before upload. |

**Scenes:** title card → by the numbers → up to 5 topics (phrases used by 3+ channels, with up to 3 quoted headlines each) → the curator's most-watched channels → a 15 s end screen with space on the right for YouTube's end-screen video and subscribe elements.

**How it renders:** every motion in `roundup/roundup.html` is a CSS animation with an absolute delay. `render.mjs` seeks all animations to each frame's time (`window.__seek`), screenshots it in headless Chromium and pipes the frames to ffmpeg, so the output is exact and repeatable. Preview in a browser with `roundup.html?play` after setting `window.ROUNDUP` (or serve the folder and pass `?data=`).

**Data caveat:** the site caches each channel's newest 15 uploads, so weekly totals are lower bounds (the video shows "400+") and there's no "most active channel" ranking.
