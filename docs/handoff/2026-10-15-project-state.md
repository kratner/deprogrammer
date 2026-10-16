# Project state — 2026-10-15 (end of day)

Snapshot of the Deprogrammer Project at the end of the build session that ran 2026-10-12 → 2026-10-15 (updated late on the 15th, after the traffic-funnel work). Start here, then read the private notes (below) before changing anything.

This file is public. Server details, account addresses, the channel lineup with its habit weights, and raw viewing history are deliberately **not** in it — they live in `network/private/` (gitignored; see "Where things are").

---

## 1. What exists

| Piece | State |
|---|---|
| **Deprogramming network** — https://deprogrammer.org/network | **Live.** WordPress plugin `channel-network` **1.5.0** on the curator's WordPress multisite (deprogrammer.org is one site of it). In the main menu as "Deprogramming". |
| **Member sign-in** | **Live since 2026-10-14.** Google (Google Identity Services) + emailed single-use link. Members can follow channels, save videos, and save their page layout. |
| **The Latest + "Edit this page"** | **Live since 2026-10-14** (1.4.0); per-category coverage fix 1.4.1 (2026-10-15). |
| **Support links (Ko-fi)** | **Live** (1.5.0) → https://ko-fi.com/deprogrammer ("Tip", PayPal + Stripe, location confirmed). |
| **Brand kit** — `brand/` | Done. Mirrors the Design System artifact (tokens, components, logos). |
| **Promo video pipeline** — `video/` | **Weekly roundup working** (2026-10-15). First episode uploaded by the curator, **scheduled to go public 2026-10-16**. |
| **YouTube channel** @deprogrammerproject | Cleaned up 2026-10-15: all legacy videos (15) and playlists (32) set to **Private** (archived, not deleted). Public: the roundup + the "Deprogramming Weekly Roundup" playlist. |
| **Old React app** — `src/`, `public/`, root `package.json` | An early iteration of the idea (GitHub Pages). **Noted only — not being developed.** The new version under `network/` is the focus. |

## 2. The page, top to bottom

1. **Top bar** — logo, "Deprogramming", Suggest a channel, **Sign in** (drops down a small panel: Continue with Google, or email a sign-in link, plus an unticked newsletter opt-in), theme toggle. Category chips below (All / My network* / five categories). *My network appears for members who follow at least one channel.
2. **Intro line** + channel count + "updated …" + **Edit this page** button.
3. **The Latest** — sideways strip, uploads from the last 48 h in strict newest-first order (max 3 per channel). With All it shows the newest 40; the page also carries up to 12 per category and each channel's newest, so a category chip or My network never leaves it nearly empty.
4. **Recently watched** — videos the curator actually watched (from the watch-history refresh), large lead card first.
5. **Latest from the network** — uploads ranked by the curator's viewing habits blended with recency (Top picks), or Newest.
6. **Saved for later** — members only, appears once something is saved.
7. **The lineup** — every channel by category, with Follow buttons.
8. **Suggest a channel** — form → admin review queue.
9. *(when a support URL is set)* a **Support on Ko-fi** band above the footer, plus a ♥ Support pill in the top bar, an account-menu item and a footer link.

Sections 3–8 can be reordered/hidden per visitor via **Edit this page** (also: start category, big cards vs compact list). Stored in the browser for everyone, and on the account for members.

## 3. How the ranking works (short)

- Each channel has a **habit weight** (relative 1–100) from the curator's YouTube watch history, refreshed on request (aim: every ~3 weeks; last **weights** refresh 2026-10-13). The **Recently watched** list was refreshed separately on 2026-10-15 (60 videos, Oct 9–15).
- Score = `(w/100 + 0.05)^S / (age_h + 2)^1.3`, S = habit strength setting (default 10 → 1.0). Each channel gets `1 + 4·w/100` slots (max 5) so heavy channels show more videos.
- "The Latest" ignores weights entirely (strict time order).
- Feeds come from the YouTube Data API v3 (uploads playlists), cached, refreshed every ~30 min by a self-triggered signed loopback request (WP-Cron is disabled on the host).

## 4. Member sign-in — how it's put together

- Code: `network/wp-plugin/channel-network/includes/class-cn-members.php`; front end in `assets/network.js` (members block + layout block).
- **Google:** ID token → verified server-side via Google's tokeninfo (audience, issuer, expiry, verified email). Client ID defaults to the one Site Kit's "Sign in with Google" already configured; Site Kit's own sign-in script is **blocked on the network page** (both initialised Google's library; last one wins).
- **Email link:** 64-hex token, only its SHA-256 stored (transient), single use, 20 min. Sent as `Deprogramming <no-reply@deprogrammer.org>` with a matching envelope sender — WordPress's multisite default sender (the network's main domain) fails SPF/DKIM for this site. SPF + DMARC (`p=none`) records were added 2026-10-14; Gmail reports DKIM/SPF/DMARC all **pass**.
- **Accounts:** subscribers on deprogrammer.org only; registration stays closed network-wide. **Staff accounts (anyone who can edit posts, or a super admin) are refused** — the curator's own admin Google account therefore can't be used to test member sign-in.
- **Member data (user meta):** `cn_google_sub`, `cn_follows`, `cn_saved` (≤200), `cn_layout`. Self-service **Delete my account** removes all four and the site membership (the WordPress user is deleted only if it belongs to no other site of the network). WordPress privacy exporter/eraser registered.
- **REST** (`/wp-json/channel-network/v1/`): `auth/google`, `auth/email`, `auth/logout`, `me`, `follow`, `save`, `layout`, `account/delete`, plus `suggest` and `refresh`.
- **Newsletter:** opt-in calls Jetpack Subscriptions (WordPress.com sends the confirmation). Jetpack is connected and the Subscriptions module is on.
- Gotcha fixed: the REST nonce returned after Google sign-in must be minted against the *new* session cookie, or the first follow/save 403s.

## 4b. Traffic funnel (started 2026-10-15)

**Funnel:** promo videos on YouTube @deprogrammerproject (later Bluesky/Threads/X, Facebook/Instagram, Reddit) → tracked link to deprogrammer.org/network → plays → sign-in / newsletter → Ko-fi support.

**Decisions:** first piece = weekly roundup; **no voice** (text and captions; the curator adds a music bed in YouTube Studio); the curator uploads (no upload OAuth); support via **Ko-fi**, labelled "support", not "donation".

**Weekly roundup — how to make one** (details in `video/README.md`):
1. `cd video && node roundup/data.mjs` → `out/roundup-<date>/data.json` (week's uploads from the live cache; topics used by 3+ channels with quoted headlines; the curator's most-watched channels; tracked link `utm_campaign=roundup-<date>`).
2. Review/edit `data.json` (rename a vague topic — e.g. "Press" → "Press Secretary" — drop a weak one, or set `thumbHeadline` / `youtubeTitle`).
3. `node render.mjs roundup --stills-only` to check the frames, then `node render.mjs roundup` (~70 s video, ~1 min render): `roundup.mp4`, `thumbnail.png` (magenta outline), `thumbnail-plain.png` (control), `youtube.txt`.
4. Curator uploads in Studio: paste `youtube.txt`, add an Audio Library music bed, add to the **Deprogramming Weekly Roundup** playlist, end screen = **Video: Most recent upload** + **Subscribe**, both from **15 s before the end** (the end card is always the last 15 s; elements on the right half).
5. Data caveat: the site caches each channel's newest 15 uploads, so totals are lower bounds (shown as "400+") and there's no "most active channel" ranking.

**Measure:** GA4 — sessions by `utm_campaign` → `video_play` → `sign_up`/`login` → `support_click`. YouTube Studio for views/CTR; the outlined vs plain thumbnail test comes later.

## 5. Verified live (2026-10-14)

- Google sign-in end to end with a real non-staff Google account (joined, saved a video; account then removed).
- Email-link member flow (server-minted token for a throwaway example.com address): sign-in, follow, save, delete account — then cleaned up.
- Staff refusal (admin Google account) — correct message, panel stays open to show it.
- Privacy policy rewritten and set as the site's privacy page: https://deprogrammer.org/privacy-policy/ (covers accounts, Google sign-in, sign-in emails, newsletter, YouTube player, analytics, page layout).
- Google OAuth app: In production / External; authorized origin `https://deprogrammer.org`; branding has homepage + privacy-policy links.
- 2026-10-15: plugin 1.4.1 (The Latest per-category) and 1.5.0 (support links hidden) deployed and checked; roundup MP4 checked (1920×1080 H.264, 69 s); end screen checked in Studio (0:54–1:09).
- Analytics: GA4 events (`video_play`, `video_close`, `open_on_youtube`, `channel_click`, `filter_category`, `sort_change`, `show_more`, `theme_toggle`, `suggest_channel`, `login_prompt`, `login_hint`, `sign_up`/`login` {method}, `follow_channel`, `save_video`, `layout_edit_open`, `layout_change`, `layout_reset`, `support_click` {location}, …).

## 6. Open items

**Network page**
- [ ] **Newsletter opt-in** — not yet seen working live; check the next time a member ticks the box (Jetpack confirmation email should arrive).
- [ ] **Members kept out of wp-admin** — works locally; can't be checked with scripts live because the host shows a "Checking your browser" bot wall on /wp-admin. Check once in a real browser with a member account.
- [ ] **Weights / lineup review** — on request ("review my history"); last 2026-10-13. Procedure in the private notes. Candidates seen 2026-10-15 (3+ watches, not in the lineup): The Lincoln Project, CBS News, Dr. Jason Johnson, Couch Politics, U.S. Democratic Socialists, UNFTR Media.
- [ ] **12 undecided news channels** waiting for a yes/no (listed in the private notes).
- [ ] Optional: a variety rule for Top picks; the field guide section is **on hold** (setting off).
- [ ] Later: tighten DMARC from `p=none` to `quarantine` with a report address.
- [ ] More sign-in providers were **declined for now** (Google + email only).

**Traffic funnel / promo videos**
- [ ] **After 2026-10-16:** check the first roundup's views/CTR in Studio and its `utm_campaign=roundup-2026-10-15` sessions in GA4.
- [ ] **Next roundup** each week (steps above).
- [ ] **Shorts** cut from the roundup data (9:16, 15–45 s) — not built yet.
- [ ] **Other platforms:** vertical/square cuts for Bluesky/Threads/X and Facebook/Instagram; drafted Reddit posts (the curator posts).
- [ ] **GA4 funnel exploration** (visits → plays → sign-ups → support clicks) — not built yet.
- [ ] **Channel trailer** (30–60 s) — later.
- [ ] Thumbnail outline A/B (magenta vs plain) once there are a few episodes.
- [ ] Idea kept from the archived legacy videos: an on-brand media-literacy Shorts series (misleading-information signs, rhetoric analysis, critical internet use).
- Clips policy: own graphics + permitted clips only; no creator footage or thumbnails.

**Other sites on the same WordPress network (outside this project, noted only)**
- Other sites' WordPress mail still goes out from the network's main domain without DKIM (WP Mail SMTP is installed but inactive).
- The curator's personal site: cookie banner, a PayPal debug setting, MonsterInsights still active.
- optionexplicit.live: unused AffiliateX / Amazon Auto Links plugins.

## 7. Working rules (agreed with the curator)

- **Personal accounts only** for this project — never the curator's work Google Workspace domain, work inbox, work CLI tools, or work Google Cloud project. Personal-account browser work happens in an isolated browser context.
- **Public repo:** never commit server host/user/port/IP, API keys, personal email addresses, or the lineup's habit weights / raw history. Scan staged files before every public commit (`grep` list in the private notes).
- **Branches & merges:** work on a branch, merge it yourself (`git merge --no-ff`, push over SSH as **kratner**), and only ask the curator when a pull request or merge raises a real question. (`gh` on this Mac is logged in to a different account — use git over SSH / compare URLs.)
- **Deploys:** test in the local Docker site first; back up before risky changes; turning on features visitors will see (or flipping live settings) still gets an explicit "go" from the curator.
- **Browser:** the automation Chrome (debug port 9222) must be running; if the curator closes it, relaunch it the way `~/.claude/bin/chrome-devtools-mcp-wrapper.sh` does, and he signs in again (YouTube/Studio in an isolated context).
- Commit messages end with the Co-Authored-By line when Claude commits.

## 8. Where things are

| Path | What |
|---|---|
| `network/wp-plugin/channel-network/` | The plugin (PHP classes in `includes/`, `templates/network.php` + `card.php`, `assets/network.{js,css}`, `assets/logo/`) |
| `network/scripts/` | `channels.mjs` (lineup CLI: resolve/add/remove/exclude/review/weights/watched/music/feeds), `build-plugin.mjs`, `history-scrape.js` |
| `network/dev/` | Docker WordPress multisite for local testing (`http://localhost:8088/deprogrammer/network/`), fixtures, dev-only sign-in shortcuts |
| `network/private/` | **Gitignored, its own local git repo.** Canonical project notes `CLAUDE.md` (server access, deploy steps, data formats, decisions log), `channels.json` (lineup + weights + exclusions), `config.json`, reviews, backups, policy draft |
| `brand/` | Brand kit (tokens, components, logos) |
| `video/` | Promo video pipeline: `roundup/data.mjs`, `roundup/roundup.html`, `roundup/thumbnail.html`, `render.mjs`, README. Renders go to gitignored `video/out/` |
| `docs/handoff/` | This snapshot and the handoff prompt |
| root `CLAUDE.md` | Gitignored; imports `@network/private/CLAUDE.md` |
