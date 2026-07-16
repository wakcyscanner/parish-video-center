/**
 * Click-to-play facade: swap the poster + play button for the Vimeo iframe on demand.
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

	function warmUp() {
		preconnect('https://player.vimeo.com');
		preconnect('https://i.vimeocdn.com');
		preconnect('https://f.vimeocdn.com');
	}

	function loadPlayer(el) {
		var id = el.getAttribute('data-vimeo-id');
		if (!id) return;

		var iframe = document.createElement('iframe');
		iframe.src = 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?autoplay=1&dnt=1';
		iframe.allow = 'autoplay; fullscreen; picture-in-picture';
		iframe.allowFullscreen = true;
		iframe.title = el.getAttribute('data-title') || 'Video player';

		el.classList.add('svc-playing');
		el.innerHTML = '';
		el.appendChild(iframe);
	}

	document.addEventListener('pointerover', function (e) {
		if (e.target.closest && e.target.closest('.svc-player')) warmUp();
	}, { once: true, passive: true });

	document.addEventListener('click', function (e) {
		var player = e.target.closest && e.target.closest('.svc-player');
		if (player && !player.classList.contains('svc-playing')) {
			e.preventDefault();
			warmUp();
			loadPlayer(player);
		}
	});
})();
