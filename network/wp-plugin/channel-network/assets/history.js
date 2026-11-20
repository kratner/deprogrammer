/* FILE: history.js
   PATH: network/wp-plugin/channel-network/assets/history.js

   The full watch history (templates/history.php). Built to stay fast at tens of thousands
   of videos:
   - Layout comes from the inlined index alone (videos per day per category), so every day's
     position and the page height are known before any video data loads, and the scrubber
     maps straight onto them.
   - Only rows within about a screen of the viewport exist in the DOM (a few dozen cards at
     any time). Rows are absolutely positioned and keyed, so scrolling adds and removes
     rows without touching the others.
   - Video data comes in one month file at a time (data/history/YYYY-MM.json), fetched when
     a day of that month comes within range; the template preloads the first one.
   - While the scrubber is dragged or the page is flung, new rows get their thumbnails only
     once scrolling settles, so a fast drag through a year doesn't request a year of images.
   - Filters, the date in the URL (?d=YYYY-MM-DD, ?cat=…), resizing and the scrubber all go
     through one anchor: the day at the top of the screen stays at the top.
   Also: light/dark toggle and the in-page player (same behaviour as network.js). */
(function () {
	'use strict';

	function track(name, params) {
		try { if (typeof window.gtag === 'function') window.gtag('event', name, params || {}); } catch (e) { /* never break the page */ }
	}

	/* ---- Light/dark toggle (as on the network page) ---- */
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
			try { localStorage.setItem('cn-theme', next); } catch (e) { /* private mode */ }
			label();
			track('theme_toggle', { theme: next, location: 'history' });
		});
		if (media && media.addEventListener) media.addEventListener('change', label);
	}

	/* ---- Player (delegated, so it works for rows rendered later) ---- */
	var dlg = document.querySelector('.cn-player');
	if (dlg && typeof dlg.showModal === 'function') {
		var pframe = dlg.querySelector('.cn-player-frame');
		var ptitle = dlg.querySelector('.cn-player-title');
		var yt = dlg.querySelector('.cn-player-yt');
		var opener = null, openedAt = 0, openInfo = null;
		var info = function (a) {
			var card = a.closest('.cn-card');
			return {
				video_id: a.getAttribute('data-video'),
				video_title: (a.getAttribute('data-title') || '').slice(0, 100),
				channel_name: card ? (card.querySelector('.cn-card-channel') || {}).textContent || '' : '',
				category: card ? card.getAttribute('data-cat') : '',
				section: 'history'
			};
		};
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('a[data-video]');
			if (!a) return;
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { track('open_on_youtube', Object.assign(info(a), { method: 'new_tab' })); return; }
			if (e.defaultPrevented || e.button !== 0) return;
			var id = a.getAttribute('data-video');
			if (!/^[\w-]{11}$/.test(id)) return;
			e.preventDefault();
			opener = a;
			var iframe = document.createElement('iframe');
			iframe.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1';
			iframe.title = a.getAttribute('data-title') || 'YouTube video';
			iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
			pframe.replaceChildren(iframe);
			ptitle.textContent = a.getAttribute('data-title') || '';
			yt.href = 'https://www.youtube.com/watch?v=' + id;
			dlg.showModal();
			openInfo = info(a); openedAt = Date.now();
			track('video_play', openInfo);
		});
		yt.addEventListener('click', function () { track('open_on_youtube', Object.assign({}, openInfo || {}, { method: 'player_link' })); });
		dlg.querySelector('.cn-player-close').addEventListener('click', function () { dlg.close(); });
		dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
		dlg.addEventListener('close', function () {
			if (openInfo) track('video_close', Object.assign({}, openInfo, { seconds_open: Math.round((Date.now() - openedAt) / 1000) }));
			openInfo = null;
			pframe.replaceChildren();
			if (opener && opener.isConnected) opener.focus();
		});
	}

	/* ---- History ---- */
	var dataEl = document.getElementById('cn-history-data');
	var list = document.getElementById('cn-hist');
	if (!dataEl || !list) return;
	var D;
	try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }
	if (!D || !D.days || !D.days.length) return;

	document.documentElement.style.scrollBehavior = 'auto'; // the scrubber jumps; never animate
	if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; // ?d= restores instead

	var cats = D.cats, ch = D.ch, up = D.up, months = D.months;
	var catKeys = cats.map(function (c) { return c[0]; });
	var topBar = document.querySelector('.cn-top');
	var scrub = document.querySelector('.cn-scrub');
	var endNote = document.querySelector('.cn-hist-end');
	var mqPhone = window.matchMedia('(max-width: 599px)');
	var touchOnly = window.matchMedia('(hover: none)').matches;

	var ci = -1;            // current category index (-1 = all)
	var L = null;           // layout
	var nodes = new Map();  // key -> element currently in the list
	var monthData = {};     // 'YYYY-MM' -> { 'YYYY-MM-DD': [[id, title, upIdx], …] }
	var monthState = {};    // 'YYYY-MM' -> 'loading' | 'done' | 'error'
	var monthTries = {};
	var dayCache = {};      // 'YYYY-MM-DD' -> filtered items for the current filter
	var listTop = 0, topH = 0;
	var fast = false, dragging = false;

	/* Formatting */
	var pad = function (n) { return (n < 10 ? '0' : '') + n; };
	var toDate = function (s) { return new Date(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)); };
	var iso = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
	var todayD = toDate(D.today || iso(new Date()));
	var yesterday = iso(new Date(todayD.getFullYear(), todayD.getMonth(), todayD.getDate() - 1));
	var fmtW = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
	var fmtWY = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
	var fmtS = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' });
	var fmtSY = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
	var fmtM = new Intl.DateTimeFormat('en-US', { month: 'short' });
	var thisYear = todayD.getFullYear();
	function dayTitle(s) {
		if (s === D.today) return 'Today';
		if (s === yesterday) return 'Yesterday';
		var d = toDate(s);
		return (d.getFullYear() === thisYear ? fmtW : fmtWY).format(d);
	}
	function dayShort(s) { var d = toDate(s); return (d.getFullYear() === thisYear ? fmtS : fmtSY).format(d); }
	var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };
	var plural = function (n) { return n.toLocaleString('en-US') + (n === 1 ? ' video' : ' videos'); };

	function count(day) { return ci < 0 ? day[1] : day[2][ci]; }

	/* ---- Layout: positions of every day and row, from counts alone ---- */
	function measure() {
		topH = topBar ? topBar.offsetHeight : 0;
		document.documentElement.style.setProperty('--cn-top-h', topH + 'px');
		listTop = list.getBoundingClientRect().top + window.scrollY;
	}

	function layout() {
		var W = list.clientWidth || 300, vw = window.innerWidth;
		var phone = mqPhone.matches;
		var gx = phone ? 0 : Math.round(Math.min(24, Math.max(16, vw * 0.018)));
		var gy = phone ? 14 : Math.round(Math.min(28, Math.max(20, vw * 0.022)));
		var cols = phone ? 1 : Math.max(1, Math.floor((W + gx) / (250 + gx)));
		var cardW = phone ? W : (W - gx * (cols - 1)) / cols;
		var cardH = phone ? Math.max(84, Math.round(Math.min(W * 0.44, 172) * 9 / 16)) : Math.round(cardW * 9 / 16) + 12 + 68;
		var headH = phone ? 52 : 62;
		var rowH = cardH + gy;
		var secs = [], top = 0;
		for (var i = 0; i < D.days.length; i++) {
			var n = count(D.days[i]);
			if (!n) continue;
			var rows = Math.ceil(n / cols);
			var h = headH + rows * rowH;
			secs.push({ day: D.days[i][0], n: n, rows: rows, top: top, h: h });
			top += h;
		}
		L = { cols: cols, cardH: cardH, headH: headH, rowH: rowH, secs: secs, total: top, phone: phone };
		list.style.height = top + 'px';
		list.style.setProperty('--cols', cols);
		list.style.setProperty('--gx', gx + 'px');
		list.style.setProperty('--card-h', cardH + 'px');
	}

	/* Section containing list offset o (binary search; secs are in order). */
	function secIndexAt(o) {
		var s = L.secs, lo = 0, hi = s.length - 1;
		if (!s.length) return -1;
		while (lo < hi) {
			var mid = (lo + hi + 1) >> 1;
			if (s[mid].top <= o) lo = mid; else hi = mid - 1;
		}
		return lo;
	}
	/* List offset at the top edge of the visible area (just under the sticky bar). */
	function topOffset() { return window.scrollY + topH - listTop; }
	function scrollToOffset(o) { window.scrollTo(0, Math.max(0, Math.round(listTop - topH + o))); }

	/* Anchor = the day at the top of the screen and how far into it we are. */
	function anchor() {
		if (!L || !L.secs.length) return null;
		var o = topOffset();
		if (o <= 0) return { day: null };
		var i = secIndexAt(o + 2), s = L.secs[i]; // +2: landing on a day's top edge counts as that day
		return { day: s.day, frac: Math.max(0, Math.min(1, (o - s.top) / s.h)) };
	}
	function restore(a) {
		if (!a || !a.day) return;
		// Same day, or the nearest older day the current filter still shows.
		var s = null;
		for (var i = 0; i < L.secs.length; i++) { if (L.secs[i].day <= a.day) { s = L.secs[i]; break; } }
		if (!s) s = L.secs[L.secs.length - 1];
		if (!s) return;
		scrollToOffset(s.top + (s.day === a.day ? a.frac * s.h : 0));
	}

	/* ---- Data ---- */
	function loadMonth(m) {
		// Months only passed over during a drag or fling aren't fetched; where it stops is.
		if (dragging || fast) return;
		if (monthState[m] === 'loading' || monthState[m] === 'done' || !months[m]) return;
		monthState[m] = 'loading';
		fetch(months[m], { credentials: 'same-origin' })
			.then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
			.then(function (j) {
				monthData[m] = j.d || {};
				monthState[m] = 'done';
				schedule();
			})
			.catch(function () {
				monthState[m] = 'error';
				monthTries[m] = (monthTries[m] || 0) + 1;
				if (monthTries[m] < 4) setTimeout(function () { monthState[m] = undefined; schedule(); }, 1500 * monthTries[m]);
			});
	}
	function dayItems(day) {
		if (dayCache[day]) return dayCache[day];
		var m = monthData[day.slice(0, 7)];
		if (!m) return null;
		var all = m[day] || [];
		var out = ci < 0 ? all : all.filter(function (it) { var u = up[it[2]]; return u && ch[u[2]] && ch[u[2]][1] === ci; });
		dayCache[day] = out;
		return out;
	}

	/* ---- Rendering ---- */
	function card(it) {
		var u = up[it[2]] || ['', '', 0, '', '?', 0];
		var c = ch[u[2]] || ['', 0, ''];
		var id = esc(it[0]), t = esc(it[1]);
		var img = 'https://i.ytimg.com/vi/' + id + '/mqdefault.jpg';
		var chUrl = u[0] === c[0] ? c[2] : 'https://www.youtube.com/channel/' + u[0];
		var watch = 'https://www.youtube.com/watch?v=' + id;
		var avatar = u[3]
			? '<img class="cn-avatar" src="' + esc(u[3]) + '" alt="" width="36" height="36" loading="lazy" decoding="async" referrerpolicy="no-referrer">'
			: '<span class="cn-avatar cn-mono" style="--h:' + (+u[5] || 0) + '" aria-hidden="true">' + esc(u[4]) + '</span>';
		return '<li class="cn-card" data-cat="' + esc(catKeys[c[1]] || '') + '">'
			+ '<a class="cn-thumb" href="' + watch + '" data-video="' + id + '" data-title="' + t + '" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">'
			+ '<img ' + (fast ? 'data-src' : 'src') + '="' + img + '" alt="" width="320" height="180" loading="lazy" decoding="async">'
			+ '<span class="cn-tag">' + esc((cats[c[1]] || ['', ''])[1]) + '</span><span class="cn-play" aria-hidden="true"></span></a>'
			+ '<div class="cn-card-body">' + avatar + '<div class="cn-card-text">'
			+ '<h3 class="cn-card-title"><a href="' + watch + '" data-video="' + id + '" data-title="' + t + '" target="_blank" rel="noopener">' + t + '</a></h3>'
			+ '<p class="cn-card-meta"><a class="cn-card-channel" href="' + esc(chUrl) + '" target="_blank" rel="noopener">' + esc(u[1]) + '</a></p>'
			+ '</div></div></li>';
	}
	var skel = '<li class="cn-card cn-skel" aria-hidden="true"><span class="cn-thumb"></span><div class="cn-card-body"><div class="cn-card-text"><span class="cn-skel-line"></span><span class="cn-skel-line is-short"></span></div></div></li>';

	function makeHead(s) {
		var el = document.createElement('div');
		el.className = 'cn-hist-day';
		el.style.top = s.top + 'px';
		el.style.height = L.headH + 'px';
		el.innerHTML = '<h2>' + esc(dayTitle(s.day)) + '</h2><span class="cn-hist-count">' + plural(s.n) + '</span>';
		return el;
	}
	function makeRow(s, r, items) {
		var el = document.createElement('ol');
		el.className = 'cn-hist-row';
		el.style.top = (s.top + L.headH + r * L.rowH) + 'px';
		var html = '';
		if (items) {
			for (var k = r * L.cols; k < Math.min(items.length, (r + 1) * L.cols); k++) html += card(items[k]);
		} else {
			for (var j = r * L.cols; j < Math.min(s.n, (r + 1) * L.cols); j++) html += skel;
			el.setAttribute('aria-busy', 'true');
		}
		el.innerHTML = html;
		return el;
	}

	function render() {
		if (!L) return;
		var o = topOffset() - topH; // list offset at the top of the window
		var vh = window.innerHeight;
		var from = o - vh * 0.75, to = o + vh * 1.75;
		var want = new Map();
		var i = Math.max(0, secIndexAt(Math.max(0, from)));
		for (; i < L.secs.length; i++) {
			var s = L.secs[i];
			if (s.top > to) {
				// Prefetch the month of the next screens' worth of days.
				if (s.top < to + vh * 2) loadMonth(s.day.slice(0, 7));
				else break;
				continue;
			}
			if (s.top + s.h < from) continue;
			if (s.top + L.headH > from) want.set('h' + s.day, function (s) { return function () { return makeHead(s); }; }(s));
			var r0 = Math.max(0, Math.floor((from - s.top - L.headH) / L.rowH));
			var r1 = Math.min(s.rows - 1, Math.floor((to - s.top - L.headH) / L.rowH));
			var items = dayItems(s.day);
			if (!items) loadMonth(s.day.slice(0, 7));
			for (var r = r0; r <= r1; r++) {
				want.set((items ? 'r' : 'p') + s.day + ':' + r, (function (s, r, items) { return function () { return makeRow(s, r, items); }; })(s, r, items));
			}
		}
		nodes.forEach(function (el, key) { if (!want.has(key)) { el.remove(); nodes.delete(key); } });
		var frag = null;
		want.forEach(function (make, key) {
			if (nodes.has(key)) return;
			var el = make();
			nodes.set(key, el);
			(frag || (frag = document.createDocumentFragment())).appendChild(el);
		});
		if (frag) list.appendChild(frag);
	}

	function hydrate() {
		list.querySelectorAll('img[data-src]').forEach(function (img) { img.src = img.getAttribute('data-src'); img.removeAttribute('data-src'); });
	}

	function reset() {
		nodes.forEach(function (el) { el.remove(); });
		nodes.clear();
		dayCache = {};
	}

	/* ---- Scrubber ---- */
	var track_ = scrub && scrub.querySelector('.cn-scrub-track');
	var thumb = scrub && scrub.querySelector('.cn-scrub-thumb');
	var bubble = scrub && scrub.querySelector('.cn-scrub-bubble');
	var labels = scrub && scrub.querySelector('.cn-scrub-labels');
	var hover = scrub && scrub.querySelector('.cn-scrub-hover');
	var trackH = 0, trackTop = 0;

	function maxO() { return Math.max(1, L.total - (window.innerHeight - topH) + 40); }
	function pAt() { return Math.min(1, Math.max(0, topOffset() / maxO())); }
	function secAtP(p) { var i = secIndexAt(p * maxO()); return i < 0 ? null : L.secs[i]; }

	function buildLabels() {
		if (!scrub) return;
		scrub.hidden = !L.secs.length || L.total < window.innerHeight * 1.5;
		trackH = track_.clientHeight;
		var mo = maxO();
		var monthsSeen = {}, years = {};
		L.secs.forEach(function (s) { monthsSeen[s.day.slice(0, 7)] = 1; years[s.day.slice(0, 4)] = 1; });
		var nMonths = Object.keys(monthsSeen).length;
		var marks = []; // {y, text|null}
		var last = {};
		L.secs.forEach(function (s, idx) {
			var y = Math.min(1, s.top / mo) * trackH;
			var m = s.day.slice(0, 7), yr = s.day.slice(0, 4);
			if (nMonths > 14) {
				// Years as labels, months as dots.
				if (last.y !== yr) marks.push({ y: y, text: yr });
				else if (last.m !== m) marks.push({ y: y, text: null });
			} else if (nMonths >= 3) {
				if (last.m !== m) {
					var d = toDate(s.day);
					marks.push({ y: y, text: fmtM.format(d) + (last.y !== yr && (idx === 0 || +yr !== thisYear) ? ' ' + yr : '') });
				}
			} else {
				marks.push({ y: y, text: dayShort(s.day) });
			}
			last.m = m; last.y = yr;
		});
		var html = '', lastY = -99, lastDot = -99;
		marks.forEach(function (mk) {
			if (mk.text && mk.y - lastY >= 22) {
				html += '<span style="top:' + mk.y.toFixed(1) + 'px">' + esc(mk.text) + '</span>';
				lastY = mk.y; lastDot = mk.y;
			} else if (mk.y - lastDot >= 6) {
				html += '<i style="top:' + mk.y.toFixed(1) + 'px"></i>';
				lastDot = mk.y;
			}
		});
		labels.innerHTML = html;
	}

	function updateThumb() {
		if (!scrub || scrub.hidden) return;
		var p = pAt();
		thumb.style.transform = 'translateY(' + (p * trackH).toFixed(1) + 'px)';
		var o = Math.max(0, topOffset());
		var s = L.secs[Math.max(0, secIndexAt(o + 2))];
		if (s) {
			bubble.textContent = dayShort(s.day);
			thumb.setAttribute('aria-valuenow', Math.round(p * 100));
			thumb.setAttribute('aria-valuetext', dayTitle(s.day));
		}
	}

	var pendingP = null;
	function jumpTo(p) {
		pendingP = p;
		if (!jumpRaf) jumpRaf = requestAnimationFrame(function () {
			jumpRaf = 0;
			if (pendingP === null) return;
			scrollToOffset(pendingP * maxO());
			pendingP = null;
		});
	}
	var jumpRaf = 0;

	if (scrub) {
		var pY = function (e) { return Math.min(1, Math.max(0, (e.clientY - trackTop) / trackH)); };
		var startDrag = function (e) {
			if (e.button) return;
			if (touchOnly && e.target !== thumb && !thumb.contains(e.target)) return;
			dragging = true; fast = true;
			trackTop = track_.getBoundingClientRect().top;
			try { track_.setPointerCapture(e.pointerId); } catch (x) { /* older browsers */ }
			scrub.classList.add('is-drag');
			if (e.target !== thumb) jumpTo(pY(e));
			e.preventDefault();
		};
		track_.addEventListener('pointerdown', startDrag);
		track_.addEventListener('pointermove', function (e) {
			if (dragging) { jumpTo(pY(e)); return; }
			if (e.pointerType !== 'mouse') return;
			trackTop = track_.getBoundingClientRect().top;
			var p = pY(e), s = secAtP(p);
			hover.style.transform = 'translateY(' + (p * trackH).toFixed(1) + 'px)';
			hover.firstChild.textContent = s ? dayShort(s.day) : '';
			scrub.classList.add('is-hover');
		});
		track_.addEventListener('pointerleave', function () { scrub.classList.remove('is-hover'); });
		var endDrag = function () {
			if (!dragging) return;
			dragging = false;
			scrub.classList.remove('is-drag');
			settle();
			track('history_scrub', { to: (anchor() || {}).day || 'top', filter: ci < 0 ? 'all' : catKeys[ci] });
		};
		track_.addEventListener('pointerup', endDrag);
		track_.addEventListener('pointercancel', endDrag);
		track_.addEventListener('lostpointercapture', endDrag);

		thumb.addEventListener('keydown', function (e) {
			var o = Math.max(0, topOffset()), i = Math.max(0, secIndexAt(o + 2)), s = L.secs;
			var monthOf = function (k) { return s[k].day.slice(0, 7); };
			var j = null;
			if (e.key === 'ArrowDown') j = Math.min(s.length - 1, i + 1);
			else if (e.key === 'ArrowUp') j = (o - s[i].top > 4) ? i : Math.max(0, i - 1);
			else if (e.key === 'PageDown') { j = i; while (j < s.length - 1 && monthOf(j) === monthOf(i)) j++; }
			else if (e.key === 'PageUp') {
				// Start of this month, or of the newer month when already at its start.
				var f = i;
				while (f > 0 && monthOf(f - 1) === monthOf(i)) f--;
				if (o - s[f].top > 4) j = f;
				else if (f === 0) j = 0;
				else { j = f - 1; while (j > 0 && monthOf(j - 1) === monthOf(f - 1)) j--; }
			}
			else if (e.key === 'Home') j = 0;
			else if (e.key === 'End') j = s.length - 1;
			if (j === null) return;
			e.preventDefault();
			scrollToOffset(s[j].top);
			scrub.classList.add('is-key');
		});
		thumb.addEventListener('blur', function () { scrub.classList.remove('is-key'); });
	}

	/* ---- Scroll loop ---- */
	var raf = 0, lastY = window.scrollY, lastT = 0, idleT = 0, urlT = 0, activeT = 0;
	function frame(t) {
		raf = 0;
		var y = window.scrollY, dt = Math.max(1, (t || performance.now()) - lastT);
		if (!dragging) fast = Math.abs(y - lastY) / dt > 3;
		lastY = y; lastT = t || performance.now();
		render();
		updateThumb();
	}
	function schedule() { if (!raf) raf = requestAnimationFrame(frame); }
	function settle() { fast = false; hydrate(); render(); }
	function writeUrl() {
		var a = anchor();
		var u = new URL(location.href);
		if (a && a.day) u.searchParams.set('d', a.day); else u.searchParams.delete('d');
		if (ci >= 0) u.searchParams.set('cat', catKeys[ci]); else u.searchParams.delete('cat');
		if (u.href !== location.href) history.replaceState(null, '', u.href);
	}
	window.addEventListener('scroll', function () {
		schedule();
		clearTimeout(idleT); idleT = setTimeout(function () { if (!dragging) settle(); }, 140);
		clearTimeout(urlT); urlT = setTimeout(writeUrl, 500);
		if (scrub) {
			scrub.classList.add('is-active');
			clearTimeout(activeT); activeT = setTimeout(function () { scrub.classList.remove('is-active'); }, 1500);
		}
	}, { passive: true });

	/* ---- Filters ---- */
	var chips = Array.prototype.slice.call(document.querySelectorAll('.cn-chip[data-filter]'));
	function setFilter(key, keepPlace) {
		var idx = key === 'all' ? -1 : catKeys.indexOf(key);
		if (idx === ci) return;
		var a = keepPlace ? anchor() : null;
		ci = idx;
		chips.forEach(function (c) { c.setAttribute('aria-pressed', String(c.getAttribute('data-filter') === (ci < 0 ? 'all' : catKeys[ci]))); });
		reset();
		layout();
		buildLabels();
		if (a) restore(a);
		render();
		updateThumb();
		writeUrl();
	}
	chips.forEach(function (c) {
		c.addEventListener('click', function () {
			setFilter(c.getAttribute('data-filter'), true);
			track('filter_category', { category: c.getAttribute('data-filter'), location: 'history' });
		});
	});

	/* ---- Resize: keep the day at the top in place ---- */
	var lastW = 0, resizeT = 0;
	function relayout() {
		var a = anchor();
		measure();
		reset();
		layout();
		buildLabels();
		if (a) restore(a);
		render();
		updateThumb();
	}
	if (window.ResizeObserver) {
		new ResizeObserver(function () {
			var w = list.clientWidth;
			if (w === lastW) return;
			lastW = w;
			clearTimeout(resizeT); resizeT = setTimeout(relayout, 60);
		}).observe(list);
	}
	window.addEventListener('resize', function () {
		// Height-only changes (mobile URL bar) move the scrubber, not the layout.
		clearTimeout(resizeT); resizeT = setTimeout(function () {
			if (list.clientWidth !== lastW) return; // ResizeObserver handles width
			measure(); buildLabels(); updateThumb(); render();
		}, 60);
	});

	/* ---- Start ---- */
	var params = new URLSearchParams(location.search);
	var startCat = params.get('cat');
	if (startCat && catKeys.indexOf(startCat) >= 0 && chips.some(function (c) { return c.getAttribute('data-filter') === startCat; })) {
		ci = catKeys.indexOf(startCat);
		chips.forEach(function (c) { c.setAttribute('aria-pressed', String(c.getAttribute('data-filter') === startCat)); });
	}
	measure();
	lastW = list.clientWidth;
	layout();
	buildLabels();
	var startDay = params.get('d');
	if (startDay && /^\d{4}-\d{2}-\d{2}$/.test(startDay)) restore({ day: startDay, frac: 0 });
	render();
	updateThumb();
	if (endNote) endNote.hidden = false;
	// Web fonts can change the bar's height after first layout.
	if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { var a = anchor(); measure(); buildLabels(); if (a && startDay) restore(a); updateThumb(); render(); });
})();
