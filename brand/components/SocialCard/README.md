The link-preview image for deprogrammer.org/network: what Facebook, LinkedIn, Bluesky, Threads and X show when someone posts the link (`og:image` / `twitter:image`).

- **Size:** 1200x630 (1.91:1), PNG, sRGB, well under 1 MB. Facebook and LinkedIn show the whole frame; X's large card crops a sliver top and bottom, so nothing important sits within 20px of either edge.
- **Layout:** the `kicker`, a three-line headline in the display face, one sans line and the URL, left-aligned inside 84px margins; the logo's concentric rings on the right, sharing one centre with `dp-mark-color` on cinema-black. The mark never sits on a brand-green fill.
- **No signal-magenta outline.** The outline is for YouTube promo thumbnails; a link card already sits in a frame drawn by each platform.
- **Copy:** "The voices worth your time", from the page's own description. Calm, no counts or dates, so it doesn't go stale.

Render from this folder (headless Chrome, fonts load from Google Fonts):

```
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --hide-scrollbars \
  --force-device-scale-factor=1 --window-size=1200,630 --virtual-time-budget=4000 \
  --screenshot="$PWD/network-card.png" "file://$PWD/network-card.html"
```

After changing the image on the site, ask Facebook to re-read the link in the Sharing Debugger (developers.facebook.com/tools/debug, "Scrape Again"); it caches previews for weeks otherwise.
