/**
 * Voting Controls QR code rendering.
 */
(function(window, document) {
	'use strict';

	function renderQRCode(container) {
		if (!container) {
			return;
		}

		var url = container.getAttribute('data-voting-url');
		if (!url) {
			return;
		}

		var canvasWrapper = container.querySelector('.club-compete-qr-canvas');
		if (!canvasWrapper) {
			return;
		}

		// Clear existing content before rendering a new code.
		canvasWrapper.innerHTML = '';

		try {
			var correctLevel = window.QRCode && window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : undefined;
			var qr = new QRCode(canvasWrapper, {
				text: url,
				width: 560,
				height: 560,
				colorDark: '#000000',
				colorLight: '#ffffff',
				correctLevel: correctLevel,
			});

			// Enhance accessibility for generated <img> fallback.
			var img = canvasWrapper.querySelector('img');
			if (img) {
				img.setAttribute('alt', 'QR code linking to the voting page');
				img.setAttribute('aria-hidden', 'true');
			}
			var canvas = canvasWrapper.querySelector('canvas');
			if (canvas) {
				canvas.setAttribute('role', 'img');
				canvas.setAttribute('aria-label', 'QR code linking to the voting page');
			}

			return qr;
		} catch (error) {
			// eslint-disable-next-line no-console
			console.error('Unable to render QR code for voting page', error);
		}
	}

	function init() {
		if (typeof window.QRCode === 'undefined') {
			return;
		}

		var cards = document.querySelectorAll('.club-compete-qr-card');
		if (!cards.length) {
			return;
		}

		cards.forEach(renderQRCode);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
