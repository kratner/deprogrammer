/* FILE: network.js
   PATH: network/wp-plugin/channel-network/assets/network.js
   Progressive enhancement for the Deprogramming page. Without it, every video card shows
   and links straight to YouTube. With it: category filters (remembered in ?cat=), paging,
   live relative times, an in-page player, and an inline suggestion form.
   Analytics: track() sends GA4 events through the site's existing Google tag (Site Kit
   and MonsterInsights both load gtag, so events reach both properties). If gtag isn't on
   the page, or consent mode blocks it, nothing is sent. Event names and parameters are
   listed in CLAUDE.md under "Analytics". No email or free text is ever sent. */
(function () {
	'use strict';

	/* Each .cn-feed is one stream: "Recently watched" (what Keith actually watched) and
	   "Latest from the network" (new uploads, data-sortable). Category chips filter both;
	   the first feed with at least 3 matching videos gets the large lead card. */
	var feeds = Array.prototype.slice.call(document.querySelectorAll('.cn-feed')).map(function (el) {
		var grid = el.querySelector('.cn-grid');
		var cards = grid ? Array.prototype.slice.call(grid.querySelectorAll('.cn-card')) : [];
		var size = grid ? parseInt(grid.getAttribute('data-page-size'), 10) || 24 : 24;
		return {
			el: el, grid: grid, cards: cards, topOrder: cards.slice(), pageSize: size, shown: size,
			more: el.querySelector('.cn-more-row'), empty: el.querySelector('.cn-filter-empty'),
			sortable: el.hasAttribute('data-sortable'), hideWhenEmpty: el.hasAttribute('data-hide-empty'),
			noLead: el.hasAttribute('data-no-lead')
		};
	});
	/* ---- Analytics ---- */
	function track(name, params) {
		try { if (typeof window.gtag === 'function') window.gtag('event', name, params || {}); } catch (e) { /* never break the page */ }
	}
	/* Details of the card a link belongs to, for event parameters. */
	function cardInfo(el) {
		var card = el.closest ? el.closest('.cn-card') : null;
		if (!card) return {};
		var feed = card.closest('.cn-feed');
		var visible = feed ? Array.prototype.slice.call(feed.querySelectorAll('.cn-card')).filter(function (c) { return !c.hidden; }) : [];
		var link = card.querySelector('[data-video]');
		var ch = card.querySelector('.cn-card-channel');
		return {
			video_id: link ? link.getAttribute('data-video') : '',
			video_title: link ? (link.getAttribute('data-title') || '').slice(0, 100) : '',
			channel_name: ch ? ch.textContent.trim() : '',
			category: card.getAttribute('data-cat') || '',
			section: card.closest('.cn-highlights') ? 'highlights' : !feed ? '' : feed.getAttribute('data-section') === 'latest' ? 'the_latest' : feed.hasAttribute('data-sortable') ? 'latest' : 'watched',
			position: feed ? visible.indexOf(card) + 1 : Array.prototype.indexOf.call(document.querySelectorAll('.cn-highlights .cn-card'), card) + 1,
			is_lead: card.classList.contains('is-lead')
		};
	}

	var chips = Array.prototype.slice.call(document.querySelectorAll('.cn-chip'));
	var groups = Array.prototype.slice.call(document.querySelectorAll('.cn-lineup-group'));
	var current = 'all';

	/* ---- Highlighted: the feature shows large, so use the 1280px thumbnail when it exists ---- */
	var hlImg = document.querySelector('.cn-hl-feature img[data-hi]');
	if (hlImg) upgradeLead(hlImg.closest('.cn-hl-feature'));

	/* ---- Light/dark toggle ----
	   The page follows the system setting until the visitor picks one; the choice is kept
	   in localStorage and applied by an inline script in <head> before first paint. The
	   button is hidden in the HTML so it only appears when this script can run it. */
	var themeBtn = document.querySelector('.cn-theme');
	if (themeBtn) {
		var root = document.documentElement;
		var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
		var effective = function () {
			var t = root.getAttribute('data-theme');
			return t === 'light' || t === 'dark' ? t : (media && media.matches ? 'dark' : 'light');
		};
		var label = function () {
			var dark = effective() === 'dark';
			themeBtn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
			themeBtn.title = dark ? 'Light mode' : 'Dark mode';
		};
		themeBtn.hidden = false;
		label();
		themeBtn.addEventListener('click', function () {
			var next = effective() === 'dark' ? 'light' : 'dark';
			root.setAttribute('data-theme', next);
			try { localStorage.setItem('cn-theme', next); } catch (e) { /* private mode: works for this visit */ }
			label();
			track('theme_toggle', { theme: next });
		});
		if (media && media.addEventListener) media.addEventListener('change', label);
	}

	/* ---- Filtering + paging ---- */
	/* "mine" = channels the signed-in member follows (set by the members block below). */
	var follows = [];
	function matches(c) {
		if (current === 'all') return true;
		if (current === 'mine') return follows.indexOf(c.getAttribute('data-ch')) !== -1;
		return c.getAttribute('data-cat') === current;
	}
	function render() {
		var leadGiven = false;
		feeds.forEach(function (f) {
			if (!f.grid) return;
			var matching = f.cards.filter(matches);
			var lead = !f.noLead && !leadGiven && matching.length >= 3 && !document.documentElement.classList.contains('cn-compact');
			f.cards.forEach(function (c) { c.hidden = true; c.classList.remove('is-lead'); });
			matching.forEach(function (c, i) { c.hidden = i >= f.shown; });
			if (lead) { matching[0].classList.add('is-lead'); upgradeLead(matching[0]); leadGiven = true; }
			if (f.more) f.more.hidden = matching.length <= f.shown;
			if (f.empty) f.empty.hidden = matching.length > 0;
			if (f.hideWhenEmpty) f.el.hidden = matching.length === 0;
		});
		groups.forEach(function (g) { g.hidden = current !== 'all' && current !== 'mine' && g.getAttribute('data-cat') !== current; });
		chips.forEach(function (ch) { ch.setAttribute('aria-pressed', String(ch.getAttribute('data-filter') === current)); });
	}

	/* The lead shows large, so swap in YouTube's 1280px thumbnail when the video has one
	   (missing ones come back as a 120px placeholder, which we ignore). */
	function upgradeLead(card) {
		var img = card.querySelector('.cn-thumb img');
		var link = card.querySelector('[data-video]');
		if (!img || !link || img.getAttribute('data-hi-checked')) return;
		img.setAttribute('data-hi-checked', '1');
		var id = link.getAttribute('data-video');
		if (!/^[\w-]{11}$/.test(id)) return;
		var hi = new Image();
		hi.onload = function () { if (hi.naturalWidth > 200) { img.removeAttribute('srcset'); img.src = hi.src; } };
		hi.src = 'https://i.ytimg.com/vi/' + id + '/maxresdefault.jpg';
	}

	function setParam(key, value, isDefault) {
		try {
			var u = new URL(window.location.href);
			if (isDefault) u.searchParams.delete(key); else u.searchParams.set(key, value);
			history.replaceState(null, '', u);
		} catch (e) { /* old browser: everything still works, just not remembered in the URL */ }
	}

	function setFilter(cat, push) {
		var valid = chips.some(function (ch) { return ch.getAttribute('data-filter') === cat && !ch.hidden; });
		current = valid ? cat : 'all';
		feeds.forEach(function (f) { f.shown = f.pageSize; });
		render();
		if (push) setParam('cat', current, current === 'all');
	}

	chips.forEach(function (ch) {
		ch.addEventListener('click', function () {
			setFilter(ch.getAttribute('data-filter'), true);
			track('filter_category', { category: current });
		});
	});
	feeds.forEach(function (f) {
		if (f.more) f.more.querySelector('button').addEventListener('click', function () {
			f.shown += f.pageSize; render();
			track('show_more', { section: f.sortable ? 'latest' : 'watched', shown: f.shown, category: current });
		});
	});

	/* ---- Sort (uploads feed only): "Top picks" = server order (habit-weighted), "Newest" ---- */
	var sortFeed = feeds.filter(function (f) { return f.sortable && f.grid; })[0];
	var sortWrap = document.querySelector('.cn-sort');
	var sortBtns = sortWrap ? Array.prototype.slice.call(sortWrap.querySelectorAll('.cn-sort-btn')) : [];
	function setSort(mode, push) {
		if (!sortFeed) return;
		mode = mode === 'newest' ? 'newest' : 'top';
		sortFeed.cards = mode === 'newest'
			? sortFeed.topOrder.slice().sort(function (a, b) { return (+b.getAttribute('data-ts')) - (+a.getAttribute('data-ts')); })
			: sortFeed.topOrder.slice();
		sortFeed.cards.forEach(function (c) { sortFeed.grid.appendChild(c); });
		sortBtns.forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-sort') === mode)); });
		sortFeed.shown = sortFeed.pageSize;
		render();
		if (push) setParam('sort', mode, mode === 'top');
	}
	if (sortFeed && sortWrap) {
		sortWrap.hidden = false;
		sortBtns.forEach(function (b) { b.addEventListener('click', function () {
			setSort(b.getAttribute('data-sort'), true);
			track('sort_change', { sort: b.getAttribute('data-sort') });
		}); });
	}
	try {
		var params = new URL(window.location.href).searchParams;
		if (params.get('sort') === 'newest') setSort('newest', false);
		setFilter(params.get('cat') || 'all', false);
	} catch (e) { setFilter('all', false); }

	/* ---- Relative times ---- */
	function ago(iso) {
		var s = (Date.now() - new Date(iso).getTime()) / 1000;
		if (!isFinite(s)) return null;
		if (s < 60) return 'just now';
		if (s < 3600) return Math.floor(s / 60) + 'm ago';
		if (s < 86400) return Math.floor(s / 3600) + 'h ago';
		if (s < 604800) return Math.floor(s / 86400) + 'd ago';
		return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
	}
	function refreshTimes() {
		document.querySelectorAll('time[data-ago]').forEach(function (t) {
			var txt = ago(t.getAttribute('datetime'));
			if (txt) t.textContent = txt;
			t.title = new Date(t.getAttribute('datetime')).toLocaleString();
		});
	}
	refreshTimes();
	setInterval(refreshTimes, 60000);

	/* ---- Player ---- */
	var dlg = document.querySelector('.cn-player');
	if (dlg && typeof dlg.showModal === 'function') {
		var frame = dlg.querySelector('.cn-player-frame');
		var title = dlg.querySelector('.cn-player-title');
		var yt = dlg.querySelector('.cn-player-yt');
		var opener = null;

		var openedAt = 0, openInfo = null;
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('a[data-video]');
			if (a && (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey)) {
				track('open_on_youtube', Object.assign(cardInfo(a), { method: 'new_tab' }));
				return;
			}
			if (!a || e.defaultPrevented || e.button !== 0) return;
			var id = a.getAttribute('data-video');
			if (!/^[\w-]{11}$/.test(id)) return;
			e.preventDefault();
			opener = a;
			var iframe = document.createElement('iframe');
			iframe.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1';
			iframe.title = a.getAttribute('data-title') || 'YouTube video';
			iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
			frame.replaceChildren(iframe);
			title.textContent = a.getAttribute('data-title') || '';
			yt.href = 'https://www.youtube.com/watch?v=' + id;
			dlg.showModal();
			openInfo = cardInfo(a); openedAt = Date.now();
			track('video_play', openInfo);
		});
		document.addEventListener('auxclick', function (e) {
			var a = e.button === 1 && e.target.closest && e.target.closest('a[data-video]');
			if (a) track('open_on_youtube', Object.assign(cardInfo(a), { method: 'middle_click' }));
		});
		yt.addEventListener('click', function () { track('open_on_youtube', Object.assign({}, openInfo || {}, { method: 'player_link' })); });
		function close() { dlg.close(); }
		dlg.querySelector('.cn-player-close').addEventListener('click', close);
		dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
		dlg.addEventListener('close', function () {
			if (openInfo) track('video_close', Object.assign({}, openInfo, { seconds_open: Math.round((Date.now() - openedAt) / 1000) }));
			openInfo = null;
			frame.replaceChildren(); // stops playback
			if (opener) opener.focus();
		});
	}

	/* ---- Channel link clicks (video cards and the lineup) ---- */
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a.cn-card-channel, a.cn-channel');
		if (!a) return;
		var group = a.closest('.cn-lineup-group');
		var info = a.classList.contains('cn-card-channel') ? cardInfo(a) : {};
		track('channel_click', {
			channel_name: a.classList.contains('cn-channel') ? (a.querySelector('.cn-channel-name') || a).textContent.trim() : info.channel_name,
			category: group ? group.getAttribute('data-cat') : info.category,
			location: group ? 'lineup' : 'card'
		});
	});

	/* ---- Support links (Ko-fi) ---- */
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[data-support]');
		if (a) track('support_click', { location: a.getAttribute('data-support') });
	});

	/* ---- Suggestion form ---- */
	var form = document.querySelector('.cn-form');
	if (form && window.fetch && window.CN) {
		var status = form.querySelector('.cn-form-status');
		var button = form.querySelector('button[type="submit"]');
		var channel = form.querySelector('#cn-channel');

		function show(msg, ok) {
			status.textContent = msg;
			status.className = 'cn-form-status ' + (ok ? 'is-ok' : 'is-error');
		}
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
			if (!channel.value.trim()) {
				channel.setAttribute('aria-invalid', 'true');
				channel.focus();
				show('Please enter a YouTube channel link or @handle.', false);
				return;
			}
			button.disabled = true;
			show('Sending…', true);
			fetch(window.CN.suggestUrl, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
				.then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Something went wrong. Please try again.' }; }); })
				.then(function (j) {
					track('suggest_channel', { result: j.status || (j.ok ? 'queued' : 'error'), category: (form.querySelector('[name="category"]') || {}).value || '' });
					show(j.message || (j.ok ? 'Thanks!' : 'Something went wrong. Please try again.'), !!j.ok);
					if (j.ok) {
						var token = form.querySelector('[name="token"]').value;
						form.reset();
						form.querySelector('[name="token"]').value = token; // reset() keeps hidden values, but be explicit
					} else if (j.field) {
						var f = form.querySelector('[name="' + j.field + '"]');
						if (f) { f.setAttribute('aria-invalid', 'true'); f.focus(); }
					}
				})
				.catch(function () { track('suggest_channel', { result: 'network_error' }); show('Couldn’t reach the server. Please try again.', false); })
				.then(function () { button.disabled = false; });
		});
	}

	/* ---- Members: sign-in, follow channels, save videos ----
	   Only present when the admin has turned sign-in on (CN.members). Google sign-in uses
	   Google Identity Services; the email option mails a single-use link. All writes go
	   to /wp-json/channel-network/v1/ with the REST nonce WordPress issues per session. */
	var CN = window.CN || {};
	/* Shared with the layout block below: members save their layout to their account. */
	var layoutHooks = { remote: null, onSignedIn: null };
	if (CN.members && window.fetch) {
		var me = CN.me || { loggedIn: false };
		var nonce = CN.nonce || '';
		var signinBtn = document.querySelector('.cn-signin');
		var account = document.querySelector('.cn-account');
		var accountBtn = account && account.querySelector('.cn-account-btn');
		var menu = account && account.querySelector('.cn-account-menu');
		var mineChip = document.querySelector('.cn-chip-mine');
		var savedSec = document.getElementById('cn-saved');
		var savedList = savedSec && savedSec.querySelector('.cn-saved-list');
		var auth = document.querySelector('.cn-auth');
		var gsiDrawn = false;

		var api = function (path, body) {
			var headers = { 'Content-Type': 'application/json' };
			if (nonce) headers['X-WP-Nonce'] = nonce;
			return fetch(CN.rest + path, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body || {}) })
				.then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
		};
		/* Short status message at the bottom of the screen; optional action button. */
		var toast = function (msg, action) {
			var t = document.querySelector('.cn-toast');
			if (!t) { t = document.createElement('div'); t.className = 'cn-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
			t.textContent = msg;
			if (action) {
				var b = document.createElement('button');
				b.type = 'button'; b.className = 'cn-toast-act'; b.textContent = action.label;
				b.addEventListener('click', function (e) { e.stopPropagation(); t.classList.remove('is-on'); action.run(); });
				t.appendChild(b);
			}
			t.classList.add('is-on');
			clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('is-on'); }, action ? 6000 : 3200);
		};
		var savedIds = function () { return (me.saved || []).map(function (s) { return s.id; }); };

		function paint() {
			var on = !!me.loggedIn;
			if (signinBtn) signinBtn.hidden = on;
			if (account) {
				account.hidden = !on;
				if (on) {
					var img = accountBtn.querySelector('img');
					if (me.avatar) img.src = me.avatar;
					account.querySelector('.cn-account-name').textContent = me.name || '';
					var del = menu.querySelector('[data-act="delete"]');
					if (del) del.hidden = !me.isMember;
				}
			}
			follows = on ? (me.follows || []) : [];
			document.querySelectorAll('.cn-follow').forEach(function (b) {
				b.setAttribute('aria-pressed', String(follows.indexOf(b.getAttribute('data-channel-id')) !== -1));
			});
			var ids = on ? savedIds() : [];
			document.querySelectorAll('.cn-save').forEach(function (b) {
				var s = ids.indexOf(b.getAttribute('data-video')) !== -1;
				b.setAttribute('aria-pressed', String(s));
				b.setAttribute('aria-label', s ? 'Remove from saved' : 'Save for later');
			});
			if (mineChip) mineChip.hidden = !on || follows.length === 0;
			if (current === 'mine' && (!on || follows.length === 0)) current = 'all';
			paintSaved();
			render();
		}

		function paintSaved() {
			if (!savedSec) return;
			var list = me.loggedIn ? (me.saved || []) : [];
			savedSec.hidden = list.length === 0;
			savedList.textContent = '';
			list.forEach(function (s) {
				var li = document.createElement('li');
				li.className = 'cn-saved-item';
				var a = document.createElement('a');
				a.className = 'cn-saved-link';
				a.href = 'https://www.youtube.com/watch?v=' + encodeURIComponent(s.id);
				a.target = '_blank'; a.rel = 'noopener';
				a.setAttribute('data-video', s.id);
				a.setAttribute('data-title', s.title || '');
				var img = document.createElement('img');
				img.src = 'https://i.ytimg.com/vi/' + encodeURIComponent(s.id) + '/mqdefault.jpg';
				img.alt = ''; img.loading = 'lazy'; img.width = 160; img.height = 90;
				var txt = document.createElement('span');
				txt.className = 'cn-saved-text';
				var tt = document.createElement('strong'); tt.textContent = s.title || 'Untitled';
				var cc = document.createElement('span'); cc.textContent = s.channel || '';
				txt.appendChild(tt); txt.appendChild(cc);
				a.appendChild(img); a.appendChild(txt);
				var rm = document.createElement('button');
				rm.type = 'button'; rm.className = 'cn-saved-rm'; rm.textContent = '×';
				rm.setAttribute('aria-label', 'Remove ' + (s.title || 'video') + ' from saved');
				rm.addEventListener('click', function () { toggleSave(s.id, false, s); });
				li.appendChild(a); li.appendChild(rm);
				savedList.appendChild(li);
			});
		}

		/* Sign-in is a small panel that drops down from the top-bar button: no page dimming,
		   closes on outside click or Escape. */
		function openAuth(reason) {
			if (!auth || !auth.hidden) return;
			auth.hidden = false;
			signinBtn.setAttribute('aria-expanded', 'true');
			track('login_prompt', { reason: reason || 'button' });
			drawGsi();
		}
		function closeAuth() {
			if (!auth || auth.hidden) return;
			auth.hidden = true;
			signinBtn.setAttribute('aria-expanded', 'false');
		}

		function signedIn(j, method) {
			me = j.me; nonce = j.nonce || nonce;
			track(j.status === 'signed_up' ? 'sign_up' : 'login', { method: method });
			closeAuth(); paint();
			if (layoutHooks.onSignedIn) layoutHooks.onSignedIn(me);
			toast(j.status === 'signed_up' ? 'Welcome! Follow channels and save videos as you go.' : 'Signed in.');
		}

		function drawGsi() {
			var slot = document.getElementById('cn-gsi');
			if (gsiDrawn || !slot || !CN.clientId) { if (slot && !CN.clientId) slot.hidden = true; return; }
			var g = window.google && window.google.accounts && window.google.accounts.id;
			if (!g) { setTimeout(drawGsi, 300); return; }
			gsiDrawn = true;
			g.initialize({
				client_id: CN.clientId,
				callback: function (resp) {
					var nl = document.getElementById('cn-auth-newsletter');
					api('auth/google', { credential: resp.credential, newsletter: nl && nl.checked ? 1 : 0 }).then(function (j) {
						if (j.ok) signedIn(j, 'google');
						else authStatus(j.message || 'Google sign-in failed. Please try again.', false);
					}).catch(function () { authStatus('Couldn’t reach the server. Please try again.', false); });
				}
			});
			var dark = document.documentElement.getAttribute('data-theme') === 'dark' ||
				(!document.documentElement.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
			g.renderButton(slot, { theme: dark ? 'filled_black' : 'outline', size: 'large', text: 'continue_with', shape: 'pill', width: Math.min(320, slot.clientWidth || 320) });
		}

		var authForm = auth && auth.querySelector('.cn-auth-form');
		var authStatusEl = auth && auth.querySelector('.cn-auth-status');
		function authStatus(msg, ok) {
			if (!authStatusEl) return;
			authStatusEl.textContent = msg;
			authStatusEl.className = 'cn-auth-status ' + (ok ? 'is-ok' : 'is-error');
		}
		if (authForm) authForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var email = authForm.querySelector('[name="email"]');
			if (!email.value.trim() || !email.checkValidity()) {
				email.setAttribute('aria-invalid', 'true'); email.focus();
				authStatus('Please enter a valid email address.', false); return;
			}
			email.removeAttribute('aria-invalid');
			var btn = authForm.querySelector('button[type="submit"]');
			btn.disabled = true; authStatus('Sending…', true);
			api('auth/email', {
				email: email.value.trim(),
				website: authForm.querySelector('[name="website"]').value,
				newsletter: authForm.querySelector('[name="newsletter"]').checked ? 1 : 0
			}).then(function (j) {
				track('login_link_requested', { result: j.status || (j.ok ? 'sent' : 'error') });
				authStatus(j.message || (j.ok ? 'Check your inbox.' : 'Something went wrong. Please try again.'), !!j.ok);
			}).catch(function () { authStatus('Couldn’t reach the server. Please try again.', false); })
				.then(function () { btn.disabled = false; });
		});
		if (auth) {
			auth.querySelector('.cn-auth-close').addEventListener('click', function () { closeAuth(); signinBtn.focus(); });
			document.addEventListener('click', function (e) { if (!auth.hidden && !auth.parentNode.contains(e.target)) closeAuth(); });
			document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !auth.hidden) { closeAuth(); signinBtn.focus(); } });
		}
		if (signinBtn) signinBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (auth.hidden) openAuth('button'); else closeAuth();
		});

		/* Account menu */
		if (accountBtn) {
			var setMenu = function (open) { menu.hidden = !open; accountBtn.setAttribute('aria-expanded', String(open)); };
			accountBtn.addEventListener('click', function (e) { e.stopPropagation(); setMenu(menu.hidden); });
			document.addEventListener('click', function (e) { if (!account.contains(e.target)) setMenu(false); });
			document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
			menu.addEventListener('click', function (e) {
				var b = e.target.closest('[data-act]');
				if (!b) return;
				setMenu(false);
				var act = b.getAttribute('data-act');
				if (act === 'mine') {
					if (!follows.length) { toast('Follow a few channels in the lineup below first.'); document.getElementById('cn-lineup').scrollIntoView({ behavior: 'smooth' }); return; }
					setFilter('mine', true); window.scrollTo({ top: 0, behavior: 'smooth' });
				} else if (act === 'saved') {
					if (savedSec && !savedSec.hidden) savedSec.scrollIntoView({ behavior: 'smooth' });
					else toast('Nothing saved yet. Use the bookmark on any video.');
				} else if (act === 'logout') {
					api('auth/logout').then(function () { track('logout'); window.location.reload(); });
				} else if (act === 'delete') {
					if (!window.confirm('Delete your Deprogramming account? Your follows and saved videos are removed for good.')) return;
					api('account/delete').then(function (j) {
						if (j.ok) { track('account_delete'); window.location.reload(); } else toast(j.message || 'Could not delete the account.');
					});
				}
			});
		}

		/* Follow + save (signed-out clicks open the sign-in dialog) */
		function toggleSave(id, save, info) {
			api('save', { videoId: id, save: save ? 1 : 0, title: info.title || '', channel: info.channel || '', channelId: info.channelId || '' }).then(function (j) {
				if (!j.ok) { toast(j.message || 'Could not update saved videos.'); return; }
				me.saved = j.saved; paint();
				track(save ? 'save_video' : 'unsave_video', { video_id: id, channel_name: info.channel || '' });
				toast(save ? 'Saved for later.' : 'Removed from saved.');
			});
		}
		document.addEventListener('click', function (e) {
			var sv = e.target.closest('.cn-save');
			var fl = e.target.closest('.cn-follow');
			if (!sv && !fl) return;
			e.preventDefault(); e.stopPropagation();
			if (!me.loggedIn) {
				track('login_hint', { reason: sv ? 'save' : 'follow' });
				toast(sv ? 'Sign in to save videos for later.' : 'Sign in to follow channels.', {
					label: 'Sign in',
					run: function () { window.scrollTo({ top: 0, behavior: 'smooth' }); openAuth(sv ? 'save' : 'follow'); }
				});
				return;
			}
			if (sv) {
				toggleSave(sv.getAttribute('data-video'), sv.getAttribute('aria-pressed') !== 'true', {
					title: sv.getAttribute('data-title'), channel: sv.getAttribute('data-channel'), channelId: sv.getAttribute('data-channel-id')
				});
			} else {
				var id = fl.getAttribute('data-channel-id');
				var on = fl.getAttribute('aria-pressed') !== 'true';
				fl.setAttribute('aria-pressed', String(on)); // optimistic
				api('follow', { channelId: id, follow: on ? 1 : 0 }).then(function (j) {
					if (!j.ok) { toast(j.message || 'Could not update follows.'); paint(); return; }
					me.follows = j.follows; paint();
					var tile = fl.closest('li');
					var nm = tile && tile.querySelector('.cn-channel-name');
					track(on ? 'follow_channel' : 'unfollow_channel', { channel_id: id, channel_name: nm ? nm.textContent.trim() : '' });
				});
			}
		}, true);

		/* Notices after following an emailed sign-in link (?signin=…) */
		try {
			var u = new URL(window.location.href);
			var si = u.searchParams.get('signin');
			if (si) {
				var notes = {
					welcome: 'Welcome! You’re signed in. Follow channels and save videos as you go.',
					ok: 'You’re signed in.',
					expired: 'That sign-in link expired or was already used. Request a new one.',
					failed: 'Sign-in failed. Please try again.',
					staff: 'That account is one of this site’s admin or editor accounts, so it can’t be a member account. Staff sign in through the regular WordPress login.'
				};
				if (notes[si]) toast(notes[si]);
				if (si === 'welcome' || si === 'ok') track(si === 'welcome' ? 'sign_up' : 'login', { method: 'email' });
				if (si === 'expired' || si === 'failed') openAuth('link_' + si);
				u.searchParams.delete('signin');
				history.replaceState(null, '', u);
			}
		} catch (e) { /* ignore */ }

		/* ---- Suggest a highlight (members only; Keith approves each one in wp-admin) ---- */
		var hlBtn = document.querySelector('.cn-hl-suggest-btn');
		var hlForm = document.getElementById('cn-hl-form');
		if (hlBtn && hlForm) {
			hlBtn.hidden = false;
			var hlStatus = hlForm.querySelector('.cn-form-status');
			var hlUrl = hlForm.querySelector('#cn-hl-url');
			var hlSubmit = hlForm.querySelector('button[type="submit"]');
			var hlShow = function (msg, ok) { hlStatus.textContent = msg; hlStatus.className = 'cn-form-status ' + (ok ? 'is-ok' : 'is-error'); };
			var hlToggle = function (open) {
				hlForm.hidden = !open; hlBtn.setAttribute('aria-expanded', String(open));
				if (open) { hlShow('', true); hlUrl.focus(); } else { hlBtn.focus(); }
			};
			hlBtn.addEventListener('click', function () {
				if (!me.loggedIn) {
					track('login_hint', { reason: 'highlight' });
					toast('Sign in to suggest a video for the highlights.', {
						label: 'Sign in',
						run: function () { window.scrollTo({ top: 0, behavior: 'smooth' }); openAuth('highlight'); }
					});
					return;
				}
				hlToggle(hlForm.hidden);
			});
			hlForm.querySelector('.cn-hl-cancel').addEventListener('click', function () { hlToggle(false); });
			hlForm.addEventListener('submit', function (e) {
				e.preventDefault();
				hlUrl.removeAttribute('aria-invalid');
				if (!hlUrl.value.trim()) { hlUrl.setAttribute('aria-invalid', 'true'); hlUrl.focus(); hlShow('Please paste a link to a YouTube video.', false); return; }
				hlSubmit.disabled = true;
				hlShow('Sending…', true);
				api('highlight/suggest', {
					url: hlUrl.value.trim(),
					reason: hlForm.querySelector('#cn-hl-reason').value,
					website: hlForm.querySelector('#cn-hl-website').value
				}).then(function (j) {
					track('suggest_highlight', { result: j.status || (j.ok ? 'queued' : 'error') });
					hlShow(j.message || (j.ok ? 'Thanks!' : 'Something went wrong. Please try again.'), !!j.ok);
					if (j.ok) hlForm.reset();
					else if (j.field === 'url') { hlUrl.setAttribute('aria-invalid', 'true'); hlUrl.focus(); }
				}).catch(function () {
					track('suggest_highlight', { result: 'network_error' });
					hlShow('Couldn’t reach the server. Please try again.', false);
				}).then(function () { hlSubmit.disabled = false; });
			});
		}

		layoutHooks.remote = function (layout) {
			if (!me.loggedIn) return;
			api('layout', layout ? { layout: layout } : { reset: 1 });
		};
		layoutHooks.isMember = function () { return !!me.loggedIn; };
		paint();
		/* A ?cat=mine URL from a previous visit applies once follows are known. */
		try { if (new URL(window.location.href).searchParams.get('cat') === 'mine' && follows.length) setFilter('mine', false); } catch (e) { /* ignore */ }
	}

	/* ---- "Edit this page": section order, hidden sections, start category, density ----
	   Visitors' layouts live in this browser (localStorage); members' are saved to their
	   account too, so the page looks the same on every device they sign in on. */
	var mainEl = document.getElementById('cn-main');
	var editBtn = document.querySelector('.cn-edit-btn');
	var editSec = document.getElementById('cn-edit');
	if (mainEl && editBtn && editSec) {
		var LKEY = 'cn-layout-v1';
		var list = editSec.querySelector('.cn-edit-list');
		var catSel = editSec.querySelector('#cn-edit-cat');
		var note = editSec.querySelector('.cn-edit-note');
		var DEFAULT_ORDER = Array.prototype.map.call(list.children, function (li) { return li.getAttribute('data-key'); });
		var saveTimer = null;

		var readLocal = function () { try { return JSON.parse(window.localStorage.getItem(LKEY)); } catch (e) { return null; } };
		var writeLocal = function (l) {
			try { if (l) window.localStorage.setItem(LKEY, JSON.stringify(l)); else window.localStorage.removeItem(LKEY); } catch (e) { /* private mode: layout lasts for this visit */ }
		};
		function normalize(l) {
			l = l && typeof l === 'object' ? l : {};
			var order = (l.order || []).filter(function (k) { return DEFAULT_ORDER.indexOf(k) !== -1; });
			DEFAULT_ORDER.forEach(function (k, i) { if (order.indexOf(k) === -1) order.splice(Math.min(i, order.length), 0, k); });
			return {
				order: order,
				hidden: (l.hidden || []).filter(function (k) { return DEFAULT_ORDER.indexOf(k) !== -1; }),
				cat: typeof l.cat === 'string' ? l.cat : 'all',
				density: l.density === 'compact' ? 'compact' : 'cards'
			};
		}
		var member = CN.me && CN.me.loggedIn;
		var layout = normalize(member && CN.me.layout ? CN.me.layout : readLocal());
		var isDefault = function (l) {
			return l.order.join() === DEFAULT_ORDER.join() && !l.hidden.length && l.cat === 'all' && l.density === 'cards';
		};

		function apply() {
			layout.order.forEach(function (k) {
				var sec = mainEl.querySelector(':scope > [data-section="' + k + '"]');
				if (sec) mainEl.appendChild(sec);
			});
			Array.prototype.forEach.call(mainEl.querySelectorAll(':scope > [data-section]'), function (sec) {
				sec.classList.toggle('cn-user-hidden', layout.hidden.indexOf(sec.getAttribute('data-section')) !== -1);
			});
			document.documentElement.classList.toggle('cn-compact', layout.density === 'compact');
			// panel controls
			layout.order.forEach(function (k) { var li = list.querySelector('[data-key="' + k + '"]'); if (li) list.appendChild(li); });
			Array.prototype.forEach.call(list.querySelectorAll('[data-show]'), function (cb) { cb.checked = layout.hidden.indexOf(cb.getAttribute('data-show')) === -1; });
			var mineOpt = catSel.querySelector('[data-members-only]');
			if (mineOpt) mineOpt.disabled = !(layoutHooks.isMember && layoutHooks.isMember());
			catSel.value = layout.cat;
			if (catSel.value !== layout.cat) catSel.value = 'all';
			Array.prototype.forEach.call(editSec.querySelectorAll('[name="cn-density"]'), function (r) { r.checked = r.value === layout.density; });
		}
		function save(change, detail) {
			var stored = isDefault(layout) ? null : layout;
			writeLocal(stored);
			if (layoutHooks.remote && layoutHooks.isMember && layoutHooks.isMember()) {
				clearTimeout(saveTimer);
				saveTimer = setTimeout(function () { layoutHooks.remote(stored); }, 600);
			}
			if (change) track('layout_change', Object.assign({ change: change }, detail || {}));
		}
		function setNote() {
			var signedIn = layoutHooks.isMember && layoutHooks.isMember();
			note.textContent = signedIn
				? 'Changes save to your account automatically.'
				: 'Changes save in this browser automatically.' + (CN.members ? ' Sign in to keep your layout on every device.' : '');
		}

		editBtn.addEventListener('click', function () {
			var open = editSec.hidden;
			editSec.hidden = !open;
			editBtn.setAttribute('aria-expanded', String(open));
			if (open) { setNote(); apply(); editSec.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); track('layout_edit_open'); }
		});
		editSec.querySelector('.cn-edit-done').addEventListener('click', function () {
			editSec.hidden = true; editBtn.setAttribute('aria-expanded', 'false'); editBtn.focus();
		});
		editSec.querySelector('.cn-edit-reset').addEventListener('click', function () {
			layout = normalize(null); apply(); save(); setFilter('all', true); track('layout_reset');
		});
		list.addEventListener('change', function (e) {
			var k = e.target.getAttribute('data-show');
			if (!k) return;
			layout.hidden = layout.hidden.filter(function (h) { return h !== k; });
			if (!e.target.checked) layout.hidden.push(k);
			apply(); save(e.target.checked ? 'show' : 'hide', { section_key: k });
		});
		function readOrder() { layout.order = Array.prototype.map.call(list.children, function (li) { return li.getAttribute('data-key'); }); }
		list.addEventListener('click', function (e) {
			var b = e.target.closest('[data-move]');
			if (!b) return;
			var li = b.closest('li');
			var dir = +b.getAttribute('data-move');
			var sib = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
			if (!sib) return;
			list.insertBefore(li, dir < 0 ? sib : sib.nextElementSibling);
			readOrder(); apply(); save('order', { section_key: li.getAttribute('data-key') });
			b.focus();
		});
		// Drag and drop (mouse); the arrow buttons cover touch and keyboard.
		var dragged = null;
		list.addEventListener('dragstart', function (e) {
			dragged = e.target.closest('li'); if (!dragged) return;
			dragged.classList.add('is-dragging');
			try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragged.getAttribute('data-key')); } catch (err) { /* old browsers */ }
		});
		list.addEventListener('dragover', function (e) {
			if (!dragged) return;
			e.preventDefault();
			var over = e.target.closest('li');
			if (!over || over === dragged) return;
			var r = over.getBoundingClientRect();
			list.insertBefore(dragged, e.clientY > r.top + r.height / 2 ? over.nextElementSibling : over);
		});
		list.addEventListener('dragend', function () {
			if (!dragged) return;
			dragged.classList.remove('is-dragging');
			var k = dragged.getAttribute('data-key'); dragged = null;
			readOrder(); apply(); save('order', { section_key: k });
		});
		catSel.addEventListener('change', function () {
			layout.cat = catSel.value; save('start_category', { category: layout.cat }); setFilter(layout.cat, true);
		});
		editSec.addEventListener('change', function (e) {
			if (e.target.name !== 'cn-density') return;
			layout.density = e.target.value; apply(); render(); save('density', { density: layout.density });
		});

		// After a sign-in without reload: an account layout wins; otherwise this browser's moves up.
		layoutHooks.onSignedIn = function (m) {
			if (m.layout) { layout = normalize(m.layout); apply(); }
			else if (!isDefault(layout) && layoutHooks.remote) layoutHooks.remote(layout);
			setNote();
		};

		apply();
		// Start category, unless the URL already picked one.
		try {
			if (!new URL(window.location.href).searchParams.get('cat') && layout.cat !== 'all') setFilter(layout.cat, false);
		} catch (e) { /* ignore */ }
	}
})();
