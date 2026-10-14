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
			sortable: el.hasAttribute('data-sortable'), hideWhenEmpty: el.hasAttribute('data-hide-empty')
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
			section: feed && feed.hasAttribute('data-sortable') ? 'latest' : 'watched',
			position: visible.indexOf(card) + 1,
			is_lead: card.classList.contains('is-lead')
		};
	}

	var chips = Array.prototype.slice.call(document.querySelectorAll('.cn-chip'));
	var groups = Array.prototype.slice.call(document.querySelectorAll('.cn-lineup-group'));
	var current = 'all';

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
	function render() {
		var leadGiven = false;
		feeds.forEach(function (f) {
			if (!f.grid) return;
			var matching = f.cards.filter(function (c) { return current === 'all' || c.getAttribute('data-cat') === current; });
			var lead = !leadGiven && matching.length >= 3;
			f.cards.forEach(function (c) { c.hidden = true; c.classList.remove('is-lead'); });
			matching.forEach(function (c, i) { c.hidden = i >= f.shown; });
			if (lead) { matching[0].classList.add('is-lead'); upgradeLead(matching[0]); leadGiven = true; }
			if (f.more) f.more.hidden = matching.length <= f.shown;
			if (f.empty) f.empty.hidden = matching.length > 0;
			if (f.hideWhenEmpty) f.el.hidden = matching.length === 0;
		});
		groups.forEach(function (g) { g.hidden = current !== 'all' && g.getAttribute('data-cat') !== current; });
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
		var valid = chips.some(function (ch) { return ch.getAttribute('data-filter') === cat; });
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
})();
