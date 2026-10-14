# Deprogramming network

The code behind **Deprogramming** (https://deprogrammer.org/network): one page that gathers the news and politics channels the curator actually watches, ranks their newest videos by those viewing habits, shows the videos the curator watched recently, and takes channel suggestions from visitors.

## What's here

| Path | What it is |
|---|---|
| `wp-plugin/channel-network/` | WordPress plugin: the page template, YouTube feed fetching (RSS or YouTube Data API, cached, refreshed in the background), visitor suggestions (REST endpoint, spam protection, admin review screen), GA4 events, WP-CLI commands |
| `scripts/channels.mjs` | Maintains the channel lineup: add/remove/exclude channels, weekly review reports, habit weights, the watched list, a YouTube-Music filter |
| `scripts/history-scrape.js` | Browser snippet that reads the curator's YouTube watch history (run in a signed-in browser session) |
| `scripts/build-plugin.mjs` | Copies the public part of the lineup (names, categories, relative 1-100 weights) into the plugin |
| `dev/` | Local test site in Docker: WordPress multisite + MySQL, with `setup.sh` to rebuild it |

The lineup, raw watch history, exclusions and deployment notes are personal and are not in this repository (`network/private/` is gitignored). The plugin ships only what the page shows.

## Running it locally

```sh
cd network
node scripts/build-plugin.mjs          # needs private/channels.json
docker compose -f dev/docker-compose.yml up -d
bash dev/setup.sh                      # http://localhost:8088/deprogrammer/network/
```

The plugin works on any WordPress 6.4+ site with PHP 8.1+: build it, copy `wp-plugin/channel-network/` into `wp-content/plugins/`, activate it on one site, run `wp channel-network create-page`, and add a YouTube Data API key in its settings (RSS works without one when YouTube's feeds are up).
