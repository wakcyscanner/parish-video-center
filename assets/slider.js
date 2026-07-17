/**
 * Slider arrows for .svc-slider: progressive enhancement over a scroll-snap
 * row. Without this script the track is still swipeable/scrollable; the
 * arrows stay hidden (they render with the hidden attribute).
 */
(function () {
	'use strict';

	function setup(slider) {
		var track = slider.querySelector('.svc-slider-track');
		var prev = slider.querySelector('.svc-slider-prev');
		var next = slider.querySelector('.svc-slider-next');
		if (!track || !prev || !next) return;

		function update() {
			var overflow = track.scrollWidth > track.clientWidth + 1;
			prev.hidden = !overflow || track.scrollLeft <= 0;
			next.hidden = !overflow || track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
		}

		function slide(direction) {
			track.scrollBy({ left: direction * track.clientWidth * 0.8, behavior: 'smooth' });
		}

		prev.addEventListener('click', function () { slide(-1); });
		next.addEventListener('click', function () { slide(1); });
		track.addEventListener('scroll', update, { passive: true });
		window.addEventListener('resize', update);
		update();
	}

	function initAll() {
		document.querySelectorAll('.svc-slider').forEach(setup);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
