/**
 * Click-to-play facade: swap the poster + play button for the provider's player iframe on demand.
 */
(function () {
	'use strict';

	function preconnect(href) {
		if (document.querySelector('link[rel="preconnect"][href="' + href + '"]')) return;
		var link = document.createElement('link');
		link.rel = 'preconnect';
		link.href = href;
		link.crossOrigin = 'anonymous';
		document.head.appendChild(link);
	}

	function warmUp(el) {
		var src = el.getAttribute('data-embed-src');
		if (!src) return;
		try {
			preconnect(new URL(src).origin);
		} catch (e) {
			return;
		}
		if (el.getAttribute('data-provider') === 'vimeo') {
			preconnect('https://i.vimeocdn.com');
			preconnect('https://f.vimeocdn.com');
		}
	}

	function loadPlayer(el) {
		var src = el.getAttribute('data-embed-src');
		if (!src) return;

		var iframe = document.createElement('iframe');
		iframe.src = src;
		iframe.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
		iframe.allowFullscreen = true;
		iframe.title = el.getAttribute('data-title') || 'Video player';

		el.classList.add('svc-playing');
		el.innerHTML = '';
		el.appendChild(iframe);
	}

	// "Play on arrival" links (?play=1, used in email newsletters): start the
	// watch page's player straight away. Muted, because browsers refuse
	// autoplay with sound when the visitor hasn't touched the page yet. Only
	// while the page is still loading — a script delayed until the first
	// interaction must not restart a video someone is already watching.
	(function () {
		if (!/[?&]play=1(&|$)/.test(window.location.search)) return;
		if (window.performance && performance.now() > 6000) return;
		var frame = document.querySelector('.svc-single .svc-player iframe[data-autoplay-src]');
		if (frame) frame.src = frame.getAttribute('data-autoplay-src');
	})();

	document.addEventListener('pointerover', function (e) {
		var player = e.target.closest && e.target.closest('.svc-player');
		if (player && !player.svcWarmed) {
			player.svcWarmed = true;
			warmUp(player);
		}
	}, { passive: true });

	document.addEventListener('click', function (e) {
		var player = e.target.closest && e.target.closest('.svc-player');
		if (player && !player.classList.contains('svc-playing')) {
			e.preventDefault();
			loadPlayer(player);
		}
	});

	// "Read more" on single video pages: the description past the first line
	// is not in the served HTML at all — fetch it on demand.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.svc-read-more');
		if (!btn || btn.disabled) return;

		var endpoint = btn.getAttribute('data-endpoint');
		var target = document.getElementById(btn.getAttribute('aria-controls'));
		if (!endpoint || !target) return;

		btn.disabled = true;
		fetch(endpoint)
			.then(function (res) {
				if (!res.ok) throw new Error(res.status);
				return res.json();
			})
			.then(function (data) {
				target.innerHTML = data.html;
				btn.setAttribute('aria-expanded', 'true');
				var wrap = btn.closest('.svc-read-more-wrap');
				(wrap || btn).remove();
			})
			.catch(function () {
				btn.disabled = false;
			});
	});
})();
