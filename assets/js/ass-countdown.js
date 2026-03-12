/**
 * Live countdown for reservation dates.
 * Targets elements with data-ass-countdown-target (ISO 8601 timestamp).
 */
(function() {
	'use strict';

	function formatCountdown(el, targetDate) {
		var now = new Date();
		var diff = targetDate.getTime() - now.getTime();

		if (diff <= 0) {
			return null;
		}

		var prefix = el.getAttribute('data-ass-countdown-prefix') || '';
		var suffixH = el.getAttribute('data-ass-countdown-suffix-hours') || 'h';
		var suffixM = el.getAttribute('data-ass-countdown-suffix-minutes') || 'min';
		var suffixS = el.getAttribute('data-ass-countdown-suffix-seconds') || 's';

		var totalSeconds = Math.floor(diff / 1000);
		var hours = Math.floor(totalSeconds / 3600);
		var minutes = Math.floor((totalSeconds % 3600) / 60);
		var seconds = totalSeconds % 60;

		var parts = [];
		if (hours > 0) {
			parts.push(hours + suffixH);
		}
		if (minutes > 0 || hours > 0) {
			parts.push(minutes + suffixM);
		}
		parts.push(seconds + suffixS);

		var text = parts.join(' ');
		return prefix ? prefix + ' ' + text : text;
	}

	function tick() {
		var elements = document.querySelectorAll('[data-ass-countdown-target]');
		elements.forEach(function(el) {
			var targetStr = el.getAttribute('data-ass-countdown-target');
			var countdownEl = el.querySelector('.ass-countdown');

			if (!targetStr || !countdownEl) return;

			var targetDate = new Date(targetStr);
			if (isNaN(targetDate.getTime())) return;

			var text = formatCountdown(el, targetDate);
			if (text === null) {
				el.style.display = 'none';
				return;
			}
			countdownEl.textContent = text;
		});
	}

	function init() {
		tick();
		setInterval(tick, 1000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Re-run tick after WooCommerce replaces checkout fragments via AJAX
	if (typeof jQuery !== 'undefined') {
		jQuery(document.body).on('updated_checkout', tick);
	}
})();
