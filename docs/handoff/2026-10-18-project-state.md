# Project state — 2026-10-18 (evening)

Handoff snapshot of the Deprogrammer Project after the 2026-10-17/18 work: highlighted videos (1.6.0), the mobile top-bar fix (1.6.1) and anonymous highlight suggestions (1.6.2). It builds on [2026-10-15-project-state.md](2026-10-15-project-state.md). Everything that file describes (sign-in, The Latest, Edit this page, Ko-fi, the roundup pipeline, the ranking) is unchanged unless this file says otherwise, so read both.

This file is public. Server host, user, port, home-directory paths and exact deploy commands are **not** in it. They're in the private notes `network/private/CLAUDE.md` and the private companion `network/private/HANDOFF-2026-10-18-ops.md`.

---

## 1. Live right now

Checked 2026-10-18 at about 19:05 EDT (read-only: WP-CLI on the server plus an anonymous fetch of the page):

| Item | State |
|---|---|
| Plugin `channel-network` | **1.6.2 active** on deprogrammer.org only (`wp plugin list`; the page loads `network.css` and `network.js` with `?ver=1.6.2`) |
| Page | https://deprogrammer.org/network/ returns 200 |
| Repo `master` | `5f8dd86` "Merge feature/anonymous-suggest", pushed to github.com/kratner/deprogrammer; local and origin match |
| Feeds | Last deploy refresh 54/54 channels via the Data API |
| DB | `cn_db_version` 3 (the `anonymous` column exists) |

## 2. What shipped on 2026-10-17 and 2026-10-18

| Version | What | Commit | Deployed |
|---|---|---|---|
| 1.6.0 | **Highlighted** section at the top of the page (curator-picked videos) + members can suggest highlights | `e4f0f16`, merged `7d2c4fe` | 2026-10-17 21:33 EDT |
| 1.6.1 | **Mobile top bar fit**: "Deprogramming" no longer slides under the buttons on phones | `b933432` (branch `fix/mobile-header`) | 2026-10-18 17:13 EDT |
| 1.6.2 | **"Suggest anonymously"** checkbox on the highlight suggestion form | `444e7b4` (branch `feature/anonymous-suggest`, on top of 1.6.1) | 2026-10-18 17:13 EDT |

1.6.1 and 1.6.2 went out together as merge `5f8dd86`, after the curator said "deploy both".

### 2a. Mobile top bar fix (1.6.1)

**Problem (reported from the curator's Android phone):** the "Deprogramming" title was cut off in portrait and landscape.
- The title was a fixed 21px with no wrapping, and the action buttons couldn't shrink.
- Below about 400px wide the title slid under the buttons: a 4px overlap at 390px, 33px at 360px.
- At 600–770px (phones in landscape) the page used the desktop wordmark plus "Suggest a channel", overlapping by 57px at 640px.
- "Sign in" and "Suggest a channel" also wrapped onto two lines.

**Fix:** one block at the end of `network/wp-plugin/channel-network/assets/network.css`:
- **Phones:** title `clamp(14px, calc(12vw - 30px), 21px)`, an ellipsis as the last resort, action buttons fixed width (`flex: none`) and on one line (`nowrap`), rows 58px → 52px.
  - The clamp is tuned for **Noto Serif**, which Android substitutes for the title's Iowan/Georgia stack and is about 6% wider.
- **600–899px:** the round logo mark instead of the wordmark, and no green dot.
- **600–699px:** the Support pill collapses to its heart icon.
- **Under 360px:** 14px title. It only ellipsizes at 320px.
- **Short landscape (`max-height: 500px`):** the top bar is no longer sticky, because it took 25–30% of the screen height.

**Tested:**
- **Locally:** 320–1280px with both the Mac fonts and Noto Serif injected.
  - The full title shows from 360px up and ellipsizes below.
  - The title is clear of the buttons from 340px up, no button wraps, phone tap targets are 40px, and there's no horizontal scroll.
  - Screenshots: 390 dark, 740×360 dark, 360 light.
- **Live:** an anonymous visitor at 360–1280px with the Mac fonts and Noto Serif: the title fits, no buttons wrap, no horizontal scroll.
- **Not done:** a check on a real Android phone. Fonts were injected in desktop Chrome.
- **Local testing gotcha:** set `support_url` and `members_enabled` in `cn_settings`, which `dev/setup.sh` doesn't do. Otherwise the local header has fewer buttons than the live one and the test proves nothing.

### 2b. Anonymous highlight suggestions (1.6.2)

**Form:** signed-in members see a **"Suggest anonymously"** checkbox, **off by default**, so being named is the default and anonymity is the member's choice. A hint under it says the curator won't see who sent the suggestion and the member won't be credited.

**Stored for an anonymous suggestion** (table row, `anonymous = 1`):
- the video link, its key (`v:<id>`), its title, and the member's optional note;
- the review status and timestamps.

**Not stored for an anonymous suggestion:**
- **member ID** (`user_id` = 0);
- **IP hash** (empty);
- **browser user-agent** (empty).

Akismet is still called for spam checking, but without the member's email. A named suggestion stores all three, as before.

**Curator's queue:** wp-admin → Highlights shows anonymous rows as **"Anonymous (member's choice)"**. `wp channel-network highlights suggestions` has an `anonymous` column.

**Hourly spam limit:**
- Each member gets **5 suggestions an hour**, and each IP **10 an hour**. The 6th member suggestion within the hour gets "Please try again in an hour" (HTTP 429).
- Anonymous suggestions still count toward both. The counter is a WordPress transient that **expires after an hour** and **isn't linked to the suggestion row**.
- The transient key is an HMAC of the member/IP using the site's auth salt, so the key can't be read back as a member ID or IP address.

**Side effect:** "you already suggested that" de-duplication can't work for anonymous rows, because there's no member ID to match on.

**Interaction with the open credit question (d10):** anonymous suggestions are **never credited**, whatever the curator decides.

**Tested:**
- **Locally**, as a signed-in subscriber: the checkbox is unticked by default; named and anonymous submissions through the real form; the stored rows, admin queue and CLI output were checked; PHP lint (8.2) and a JS syntax check pass. The test user and rows were deleted afterwards.
- **Live:** the checkbox is in the page markup. **Not done:** an end-to-end anonymous submission by a real member account on the live site.

### 2c. Highlighted videos (1.6.0), for context

- **How it works:**
  - The curator's picks are stored in order. Up to 30 are kept and the first 9 shown: the first as a large feature, the rest as cards.
  - Any YouTube link form is accepted (`watch?v=`, `youtu.be`, `/live/`, `/shorts/`, `/embed/`, with or without `?si=`/`&t=`).
  - It's the first section on the page, hidden when the list is empty. Category filters and compact mode don't affect it, and it can be moved or hidden in **Edit this page**.
- **Managed in** wp-admin → Highlights or `wp channel-network highlights [list|add|remove|up|down|suggestions]`.
- **First highlight:** `fttOhpURQGg` ("Hasanabi Live Stream"), added 2026-10-17.
- **Suggestions are members-only as built.** Whether to open them to everyone is decision d11 (§3).

## 3. Open items

**Waiting on the curator (decisions on the Command Center board)**
- [ ] **d10: credit approved highlight suggesters?** Options: "No credit" (the current behaviour) or "Show their first name". No recommendation was given. Anonymous suggestions are never credited either way.
- [ ] **d11: who can suggest highlights?** Options: "Members only (as built)" (recommended: less spam) or "Everyone".

**Payments**
- [ ] **Stripe payouts paused**, unresolved since 2026-10-16. Payments are still accepted and tips accrue in the Stripe balance; PayPal tips are unaffected. The plan was to recheck after about 2 days, which is **now (Oct 18)**: Stripe → Settings → Account status, and if payouts are still paused, contact Stripe support. Board project `per-stripe` (owner: the curator).

**Checks not done yet**
- [ ] Look at the top bar on a **real Android phone**, in portrait and landscape.
- [ ] One **live anonymous suggestion** from a throwaway member account. Confirm the row has no member ID, IP hash or user-agent and the queue label is right, then delete the row and the account.

**Carried over from 2026-10-15** (details in that file, §6)
- [ ] Newsletter opt-in not yet seen working live.
- [ ] Members kept out of wp-admin: check in a real browser (the host's bot wall blocks scripts).
- [ ] Weights and lineup review, on request (last done 2026-10-13); 12 undecided news channels.
- [ ] Traffic funnel:
  - check the first roundup's YouTube views/CTR and its `utm_campaign=roundup-2026-10-15` sessions in GA4 (no record that this has been done);
  - make the **next weekly roundup** (only `roundup-2026-10-15` exists in `video/out/`);
  - Shorts cut, other platforms, GA4 funnel exploration, channel trailer.
- [ ] Later: DMARC `p=none` → `quarantine`.

**Housekeeping**
- [ ] The local branches `fix/mobile-header` and `feature/anonymous-suggest` are merged into `master` and were never pushed. Delete them with `git branch -d`.

## 4. Deploy and roll back (outline)

Exact commands with the server details are in `network/private/HANDOFF-2026-10-18-ops.md` and the private notes' **Deploying** section.

**Deploy (needs the curator's go-ahead for anything visitors will see):**
1. Test on the local Docker site (`network/dev/`, `http://localhost:8088/deprogrammer/network/`).
2. Merge to `master` and push as kratner.
3. **Back up the live plugin folder** to a dated `.tgz` in the hosting account's home directory.
4. `node network/scripts/build-plugin.mjs`, then rsync `network/wp-plugin/channel-network/` to the server's `wp-content/plugins/channel-network/`.
5. `wp plugin list` to confirm the version, `wp channel-network refresh`, then load the page once as an anonymous visitor. The first load runs any DB migration.

**Backups on the server** (hosting account home directory; each archive unpacks to `channel-network/`):

| Archive | Contains | Made before |
|---|---|---|
| `cn-plugin-pre-1.6.2-backup-202610181713.tgz` | **1.6.0** | the 1.6.1 + 1.6.2 deploy (2026-10-18) |
| `cn-plugin-pre-1.6.0-backup-202610172133.tgz` | 1.5.0 | the 1.6.0 deploy (2026-10-17) |
| `cn-plugin-1.2.0-backup-202610141001.tgz` | 1.2.0 | the sign-in deploy (2026-10-14) |

**Roll back 1.6.2 → 1.6.0:**
1. Unpack `cn-plugin-pre-1.6.2-backup-202610181713.tgz` over `wp-content/plugins/` (replacing `channel-network/`).
2. `wp plugin list` should show 1.6.0. Then refresh and check the page.
- On its first page load 1.6.0 re-runs its installer, which sets `cn_db_version` back to 2. That's harmless: `dbDelta` never drops columns, so the `anonymous` column stays (default 0) and new rows insert fine. Redeploying 1.6.2 later sets it back to 3. Anonymous rows already stored keep `user_id` 0 and would show in the queue as having no member.
- Rolling back **only the header fix** means reverting `b933432`'s CSS block and redeploying. There's no separate 1.6.1 archive.

## 5. Where things are

| Path | What |
|---|---|
| `~/Projects/deprogrammer` | Repo (github.com/kratner/deprogrammer, public; branch `master`) |
| `network/wp-plugin/channel-network/` | The plugin. New since 10-15: `includes/class-cn-highlights.php` (highlights + suggestion endpoint + rate limit), the highlight rows in `includes/class-cn-suggestions.php` (DB v3), the top-bar block at the end of `assets/network.css` |
| `network/dev/` | Local Docker WordPress site (seeds the first highlight) |
| `network/private/CLAUDE.md` | **Canonical private notes** (gitignored, its own git repo). Sections "Highlighted videos", "Mobile top bar fit", "Anonymous highlight suggestions" |
| `network/private/HANDOFF-2026-10-18-ops.md` | Private companion to this file: exact deploy and rollback commands |
| `docs/handoff/` | This file, the 10-15 snapshot, `HANDOFF-PROMPT.md` |
| `video/` | Roundup pipeline; renders in gitignored `video/out/` |
| Command Center board | Decisions d10/d11, project `per-stripe`, and the Deprogrammer project cards |

## 6. Recommended next steps (in order)

1. **Stripe payouts:** the curator rechecks Account status today and contacts Stripe support if payouts are still paused.
2. **Answer d10 and d11** on the board. If d10 is "Show their first name", that's a small plugin change: the name is shown for approved non-anonymous suggestions only.
3. **Two live checks:** the top bar on a real Android phone, and one throwaway-member anonymous suggestion (then clean up).
4. **Traffic funnel:** read the first roundup's numbers, then make the next weekly roundup (`video/README.md`).
5. **Housekeeping:** delete the two merged local branches; carry the remaining 10-15 open items.

The working rules haven't changed (10-15 file, §7): personal accounts only; scan staged files before public commits; branch, merge yourself, push as kratner; local Docker test first; get the curator's go-ahead before visitor-visible changes.
