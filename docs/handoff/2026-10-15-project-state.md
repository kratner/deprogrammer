# Project state — 2026-10-15

Snapshot of the Deprogrammer Project at the end of the build session that ran 2026-10-12 → 2026-10-15. Start here, then read the private notes (below) before changing anything.

This file is public. Server details, account addresses, the channel lineup with its habit weights, and raw viewing history are deliberately **not** in it — they live in `network/private/` (gitignored; see "Where things are").

---

## 1. What exists

| Piece | State |
|---|---|
| **Deprogramming network** — https://deprogrammer.org/network | **Live.** WordPress plugin `channel-network` **1.4.0** on the curator's WordPress multisite (deprogrammer.org is one site of it). In the main menu as "Deprogramming". |
| **Member sign-in** | **Live since 2026-10-14.** Google (Google Identity Services) + emailed single-use link. Members can follow channels, save videos, and save their page layout. |
| **The Latest + "Edit this page"** | **Live since 2026-10-14** (1.4.0). |
| **Brand kit** — `brand/` | Done. Mirrors the Design System artifact (tokens, components, logos). |
| **Promo video pipeline** for YouTube @deprogrammerproject | **Not started.** Brand kit is the prerequisite (done). |
| **Old React app** — `src/`, `public/`, root `package.json` | An early iteration of the idea (GitHub Pages). **Noted only — not being developed.** The new version under `network/` is the focus. |

## 2. The page, top to bottom

1. **Top bar** — logo, "Deprogramming", Suggest a channel, **Sign in** (drops down a small panel: Continue with Google, or email a sign-in link, plus an unticked newsletter opt-in), theme toggle. Category chips below (All / My network* / five categories). *My network appears for members who follow at least one channel.
2. **Intro line** + channel count + "updated …" + **Edit this page** button.
3. **The Latest** — sideways strip, every channel's uploads from the last 48 h in strict newest-first order (max 3 per channel, 40 total).
4. **Recently watched** — videos the curator actually watched (from the watch-history refresh), large lead card first.
5. **Latest from the network** — uploads ranked by the curator's viewing habits blended with recency (Top picks), or Newest.
6. **Saved for later** — members only, appears once something is saved.
7. **The lineup** — every channel by category, with Follow buttons.
8. **Suggest a channel** — form → admin review queue.

Sections 3–8 can be reordered/hidden per visitor via **Edit this page** (also: start category, big cards vs compact list). Stored in the browser for everyone, and on the account for members.

## 3. How the ranking works (short)

- Each channel has a **habit weight** (relative 1–100) from the curator's YouTube watch history, refreshed on request (aim: every ~3 weeks; last refresh 2026-10-13).
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

## 5. Verified live (2026-10-14)

- Google sign-in end to end with a real non-staff Google account (joined, saved a video; account then removed).
- Email-link member flow (server-minted token for a throwaway example.com address): sign-in, follow, save, delete account — then cleaned up.
- Staff refusal (admin Google account) — correct message, panel stays open to show it.
- Privacy policy rewritten and set as the site's privacy page: https://deprogrammer.org/privacy-policy/ (covers accounts, Google sign-in, sign-in emails, newsletter, YouTube player, analytics, page layout).
- Google OAuth app: In production / External; authorized origin `https://deprogrammer.org`; branding has homepage + privacy-policy links.
- Analytics: GA4 events (`video_play`, `video_close`, `open_on_youtube`, `channel_click`, `filter_category`, `sort_change`, `show_more`, `theme_toggle`, `suggest_channel`, `login_prompt`, `login_hint`, `sign_up`/`login` {method}, `follow_channel`, `save_video`, `layout_edit_open`, `layout_change`, `layout_reset`, …).

## 6. Open items

**Network page**
- [ ] **Newsletter opt-in** — not yet seen working live; check the next time a member ticks the box (Jetpack confirmation email should arrive).
- [ ] **Members kept out of wp-admin** — works locally; can't be checked with scripts live because the host shows a "Checking your browser" bot wall on /wp-admin. Check once in a real browser with a member account.
- [ ] **Weekly / 3-weekly history refresh** — on request ("review my history"); last 2026-10-13. Procedure in the private notes.
- [ ] **12 undecided news channels** waiting for a yes/no (listed in the private notes).
- [ ] Optional: a variety rule for Top picks; the field guide section is **on hold** (setting off).
- [ ] Later: tighten DMARC from `p=none` to `quarantine` with a report address.
- [ ] More sign-in providers were **declined for now** (Google + email only).

**Promo videos (not started)**
- [ ] `video/` pipeline: HTML/GSAP scenes from the brand kit → frames → ffmpeg MP4 + thumbnail PNG → YouTube upload (OAuth for @deprogrammerproject). Formats: Shorts, weekly roundup, trailer. Clips policy: own graphics + permitted clips only.
- [ ] Experiment: coloured outline on promo thumbnails (Secular Talk's green outline as the reference) — outline colour to confirm with the curator.

**Other sites on the same WordPress network (outside this project, noted only)**
- Other sites' WordPress mail still goes out from the network's main domain without DKIM (WP Mail SMTP is installed but inactive).
- The curator's personal site: cookie banner, a PayPal debug setting, MonsterInsights still active.
- optionexplicit.live: unused AffiliateX / Amazon Auto Links plugins.

## 7. Working rules (agreed with the curator)

- **Personal accounts only** for this project — never the curator's work Google Workspace domain, work inbox, work CLI tools, or work Google Cloud project. Personal-account browser work happens in an isolated browser context.
- **Public repo:** never commit server host/user/port/IP, API keys, personal email addresses, or the lineup's habit weights / raw history. Scan staged files before every public commit (`grep` list in the private notes).
- **Branches & merges:** work on a branch, merge it yourself (`git merge --no-ff`, push over SSH as **kratner**), and only ask the curator when a pull request or merge raises a real question. (`gh` on this Mac is logged in to a different account — use git over SSH / compare URLs.)
- **Deploys:** test in the local Docker site first; back up before risky changes; turning on features visitors will see (or flipping live settings) still gets an explicit "go" from the curator.
- Commit messages end with the Co-Authored-By line when Claude commits.

## 8. Where things are

| Path | What |
|---|---|
| `network/wp-plugin/channel-network/` | The plugin (PHP classes in `includes/`, `templates/network.php` + `card.php`, `assets/network.{js,css}`, `assets/logo/`) |
| `network/scripts/` | `channels.mjs` (lineup CLI: resolve/add/remove/exclude/review/weights/watched/music/feeds), `build-plugin.mjs`, `history-scrape.js` |
| `network/dev/` | Docker WordPress multisite for local testing (`http://localhost:8088/deprogrammer/network/`), fixtures, dev-only sign-in shortcuts |
| `network/private/` | **Gitignored, its own local git repo.** Canonical project notes `CLAUDE.md` (server access, deploy steps, data formats, decisions log), `channels.json` (lineup + weights + exclusions), `config.json`, reviews, backups, policy draft |
| `brand/` | Brand kit (tokens, components, logos) |
| `docs/handoff/` | This snapshot and the handoff prompt |
| root `CLAUDE.md` | Gitignored; imports `@network/private/CLAUDE.md` |
