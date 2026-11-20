# Deprogramming network

The code behind **Deprogramming** (https://deprogrammer.org/network): one page that gathers the news and politics channels the curator actually watches, ranks their newest videos by those viewing habits, shows the videos the curator watched recently (with the full watch history, day by day, at `/network/history/`), and takes channel suggestions from visitors.

## What's here

| Path | What it is |
|---|---|
| `wp-plugin/channel-network/` | WordPress plugin: the page template, YouTube feed fetching (RSS or YouTube Data API, cached, refreshed in the background), visitor suggestions (REST endpoint, spam protection, admin review screen), a hand-picked "Highlighted" section that signed-in members can suggest videos for (curator-approved), GA4 events, WP-CLI commands |
| `scripts/channels.mjs` | Maintains the channel lineup: add/remove/exclude channels, weekly review reports, habit weights, the watched list, the watch-history archive (`history`), a YouTube-Music filter |
| `scripts/history-scrape.js` | Browser snippet that reads the curator's YouTube watch history (run in a signed-in browser session) |
| `scripts/build-plugin.mjs` | Copies the public part of the lineup (names, categories, relative 1-100 weights) into the plugin, and splits the watch-history archive into a small index plus one file per month |
| `dev/` | Local test site in Docker: WordPress multisite + MySQL, with `setup.sh` to rebuild it; `make-history.mjs` writes a synthetic archive (default 20,000 videos) for load-testing the history view |

The lineup, raw watch history, exclusions and deployment notes are personal and are not in this repository (`network/private/` is gitignored). The plugin ships only what the page shows.

## Running it locally

```sh
cd network
node scripts/build-plugin.mjs          # needs private/channels.json
docker compose -f dev/docker-compose.yml up -d
bash dev/setup.sh                      # http://localhost:8088/deprogrammer/network/
```

### The watch history view

`/network/history/` (`includes/class-cn-history.php`, `templates/history.php`, `assets/history.js`) shows every network video in the archive, newest first, grouped by day, with a draggable date scrubber on the right edge (a handle on touch screens). It's built to stay fast as the archive grows by tens of thousands of videos:

- The page inlines only an index (days, per-category counts, channel table: ~16 KB for a year). From it, every day's position is computed before any video data loads, so the scrubber and scrollbar are exact from the first paint.
- Only rows near the viewport exist in the DOM (a few hundred nodes, whatever the archive size).
- Video data comes one month at a time (`data/history/YYYY-MM.json`, ~140 KB raw for a busy month), fetched when a day of that month comes into range. Months passed over during a drag aren't fetched, and new thumbnails wait until the drag or fling stops.
- `?d=YYYY-MM-DD` and `?cat=<category>` link to a date and filter; filters and resizing keep the day at the top in place.

Load test (`node dev/make-history.mjs && node scripts/build-plugin.mjs --history /tmp/history-20k.json`, then the local site with 4× CPU throttling): first paint 260 ms, no long tasks while loading or while scrubbing through a year, every scrubber jump drawn within two frames.

The plugin works on any WordPress 6.4+ site with PHP 8.1+: build it, copy `wp-plugin/channel-network/` into `wp-content/plugins/`, activate it on one site, run `wp channel-network create-page`, and add a YouTube Data API key in its settings (RSS works without one when YouTube's feeds are up).
