# Handoff prompt

Paste the block below as the first message of a new Claude Code session opened in `~/Projects/deprogrammer` (opening it there loads the gitignored root `CLAUDE.md`, which pulls in the private notes).

---

```
I'm continuing work on the Deprogrammer Project (my personal project, separate from my company work).
Repo: ~/Projects/deprogrammer (github.com/kratner/deprogrammer, public).

Before doing anything:
1. Read docs/handoff/2026-10-18-project-state.md (latest state, open items, deploy/rollback), then
   docs/handoff/2026-10-15-project-state.md (the full picture and working rules it builds on).
2. Read network/private/CLAUDE.md — the canonical private notes (server access, deploy steps,
   data formats, decisions). It's gitignored and never goes into the public repo.
3. Run `git log --oneline -15` and `git status` in the repo, and check the live plugin version
   (deploy notes in the private CLAUDE.md) so you know nothing changed since the snapshot.

Ground rules (details in the 10-15 state doc §7):
- Personal accounts only — never my work Google Workspace domain, work inbox, the gws CLI,
  or the work Google Cloud project. Personal-account browser work goes in an isolated context;
  gcloud uses the `personal` configuration.
- Public repo: scan staged files for server details, keys, personal emails and lineup weights
  before every commit.
- Work on a branch, then merge it yourself and push as kratner over SSH; only ask me if a PR or
  merge raises a real question.
- Test in the local Docker site first. Ask me before flipping live settings or shipping anything
  visitors will notice.

Where we left off: deprogrammer.org/network is live on plugin 1.6.2 (Highlighted section with
member suggestions, mobile top-bar fix, "Suggest anonymously" on the highlight form). Open: decisions d10 (credit suggesters?) and d11 (who can
suggest?) on the Command Center board, a real-Android-phone check of the top bar, a live anonymous
suggestion test, and the next weekly roundup. Nothing is mid-flight.

Next, unless I say otherwise: [pick one — e.g. "make this week's roundup", "check how the first
roundup did", "build the Shorts cut", "review my viewing history",
or describe a new task].
```

---

Update this prompt (and add a new dated state file next to it) at the end of each long session.
