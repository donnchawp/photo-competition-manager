/**
 * Admin slideshow functionality for Voting Controls page.
 */
(function($) {
	'use strict';

	/**
	 * Admin slideshow controller.
	 */
	class AdminSlideshow {
		constructor() {
			this.$display = $('#photo-comp-slideshow-modal');
			this.$image = this.$display.find('.slideshow-current-image');
			this.$imageInfo = this.$display.find('.slideshow-image-info');
			this.$progressBar = this.$display.find('.progress-bar');
			this.$pauseBtn = this.$display.find('.slideshow-pause');
			this.$resumeBtn = this.$display.find('.slideshow-resume');
			this.$stopBtn = this.$display.find('.slideshow-stop');
			this.$exitBtn = this.$display.find('.slideshow-exit');

			// State
			this.images = [];
			this.currentIndex = 0;
			this.isRunning = false;
			this.isPaused = false;
			this.interval = null;
			this.progressInterval = null;
			this.startTime = 0;

			// Image pre-caching
			this.imageCache = new Map();
			this.meterType = $('#slideshow-meter-type').val() || 'bar';

			this.bindEvents();
			this.initQuickActions();
		}

		getDisplayDuration() {
			// If override was set by the start handler, use it.
			if (this.overrideDuration !== undefined && this.overrideDuration !== null) {
				const duration = this.overrideDuration;
				this.overrideDuration = null;
				return duration;
			}

			// Read from the active step's input, or completion panel.
			const $activeStep = $('.photo-comp-step.step-active');
			let duration = 10;

			if ($activeStep.length) {
				const $input = $activeStep.find('.photo-comp-step-duration');
				if ($input.length) {
					duration = parseInt($input.val(), 10) || 0;
				}
			} else {
				const $focusedInput = $(document.activeElement).closest('.complete-category-item, .complete-slideshow-section').find('.photo-comp-step-duration');
				if ($focusedInput.length) {
					duration = parseInt($focusedInput.val(), 10) || 0;
				}
			}

			return duration * 1000;
		}

		createMeterRenderer(type) {
			const $progress = this.$display.find('.slideshow-progress');
			const $progressBar = this.$progressBar;

			if (type === 'line') {
				$progress.addClass('meter-line');
				return {
					update(progress) { $progressBar.css('width', progress + '%'); },
					reset() { $progressBar.css('width', '0%'); },
					destroy() { $progress.removeClass('meter-line'); }
				};
			}

			if (type === 'dots') {
				$progress.addClass('meter-dots');
				for (let i = 0; i < 20; i++) {
					$progress.append('<div class="meter-dot"></div>');
				}
				const $dots = $progress.find('.meter-dot');
				return {
					update(progress) {
						const filledCount = Math.floor(progress / 100 * $dots.length);
						$dots.each(function(i) {
							const $dot = $(this);
							$dot.removeClass('filled filling');
							if (i < filledCount) {
								$dot.addClass('filled');
							} else if (i === filledCount) {
								$dot.addClass('filling');
							}
						});
					},
					reset() { $dots.removeClass('filled filling'); },
					destroy() { $dots.remove(); $progress.removeClass('meter-dots'); }
				};
			}

			if (type === 'radial') {
				$progress.addClass('meter-radial');
				const circumference = 2 * Math.PI * 16;
				const svg = '<svg width="44" height="44" viewBox="0 0 44 44">' +
					'<circle cx="22" cy="22" r="16" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="3"/>' +
					'<circle class="meter-ring" cx="22" cy="22" r="16" fill="none" stroke="#0073aa" stroke-width="3" stroke-linecap="round" transform="rotate(-90 22 22)" stroke-dasharray="' + circumference + '" stroke-dashoffset="' + circumference + '"/>' +
					'</svg>';
				$progress.append(svg);
				const $ring = $progress.find('.meter-ring');
				return {
					update(progress) { $ring.attr('stroke-dashoffset', circumference * (1 - progress / 100)); },
					reset() { $ring.attr('stroke-dashoffset', circumference); },
					destroy() { $progress.find('svg').remove(); $progress.removeClass('meter-radial'); }
				};
			}

			// Default: bar
			return {
				update(progress) { $progressBar.css('width', progress + '%'); },
				reset() { $progressBar.css('width', '0%'); },
				destroy() {}
			};
		}

		bindEvents() {
			const self = this;

			// Continue button - advances workflow step via AJAX
			$(document).on('click', '.photo-comp-continue-step', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const competitionId = $btn.data('competition-id');
				const categorySlug = $btn.data('category');
				const nextStep = $btn.data('next-step');

				$btn.prop('disabled', true).text('Saving...');

				$.ajax({
					url: photoCompetitionManagerSlideshow.ajaxUrl,
					type: 'POST',
					data: {
						action: 'photo_comp_advance_voting_step',
						_wpnonce: photoCompetitionManagerSlideshow.stepNonce,
						competition_id: competitionId,
						category_slug: categorySlug,
						step: nextStep
					},
					success: function(response) {
						if (response.success) {
							window.location.reload();
						} else {
							alert(response.data.message || 'Could not save progress — try again.');
							$btn.prop('disabled', false).html('Continue &rarr;');
						}
					},
					error: function() {
						alert('Could not save progress — try again.');
						$btn.prop('disabled', false).html('Continue &rarr;');
					}
				});
			});

			// Start slideshow buttons
			$(document).on('click', '.photo-competition-manager-start-slideshow', function() {
				const $btn = $(this);
				const competitionId = $btn.data('competition-id');
				const competitionSlug = $btn.data('competition-slug');
				const category = $btn.data('category');
				const categoryLabel = $btn.data('category-label');

				// Before starting the slideshow, find and set the duration from the triggering step
				const $step = $(this).closest('.photo-comp-step, .complete-category-item, .complete-slideshow-section');
				const $durationInput = $step.find('.photo-comp-step-duration');
				if ($durationInput.length) {
					self.overrideDuration = parseInt($durationInput.val(), 10) * 1000 || 0;
				}

				self.loadSlideshow(competitionId, competitionSlug, category, categoryLabel);
			});

			// Control buttons
			this.$pauseBtn.on('click', () => this.pause());
			this.$resumeBtn.on('click', () => this.resume());
			this.$stopBtn.on('click', () => this.stop());
			this.$exitBtn.on('click', () => this.stop());

			// Keyboard controls
			$(document).on('keydown.slideshow', function(e) {
				if (!self.isRunning) {
					return;
				}

				switch(e.key) {
					case 'Escape':
						self.stop();
						break;
					case ' ':
					case 'Spacebar':
						e.preventDefault();
						// If duration is 0 (manual mode), space advances to next image
						if (self.getDisplayDuration() === 0) {
							self.nextImage();
						} else if (self.isPaused) {
							self.resume();
						} else {
							self.pause();
						}
						break;
					case 'ArrowRight':
						e.preventDefault();
						self.nextImage();
						break;
					case 'ArrowLeft':
						e.preventDefault();
						self.previousImage();
						break;
				}
			});
		}

		/**
		 * Initialize quick actions panel functionality.
		 */
		initQuickActions() {
			const self = this;

			// Quick actions toggle
			$(document).on('click', '.quick-actions-toggle', function() {
				const $toggle = $(this);
				const $content = $('#quick-actions-content');
				const isExpanded = $toggle.attr('aria-expanded') === 'true';

				$toggle.attr('aria-expanded', !isExpanded);
				$content.slideToggle(200);

				// Store preference in localStorage
				localStorage.setItem('photoCompQuickActionsExpanded', !isExpanded ? '1' : '0');
			});

			// Restore quick actions state from localStorage
			const savedState = localStorage.getItem('photoCompQuickActionsExpanded');
			if (savedState === '1') {
				$('.quick-actions-toggle').attr('aria-expanded', 'true');
				$('#quick-actions-content').show();
			}

			// QR code toggle button
			$(document).on('click', '.quick-action-qr', function() {
				const $panel = $('#qr-code-panel');
				$panel.slideToggle(200);

				// Generate QR code if not already done
				if (!$panel.data('qr-generated')) {
					self.generateQuickActionsQR();
					$panel.data('qr-generated', true);
				}
			});

			// Copy URL button
			$(document).on('click', '.copy-url-btn', function() {
				const url = $(this).data('url');
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(url).then(function() {
						// Show brief success feedback
						const $btn = $('.copy-url-btn');
						const originalText = $btn.html();
						$btn.html('<span class="dashicons dashicons-yes"></span> Copied!');
						setTimeout(function() {
							$btn.html(originalText);
						}, 2000);
					});
				}
			});
		}

		/**
		 * Generate QR code for quick actions panel.
		 */
		generateQuickActionsQR() {
			const $container = $('.qr-code-container');
			const votingUrl = $container.data('voting-url');

			if (!votingUrl || typeof QRCode === 'undefined') {
				return;
			}

			const $canvas = $container.find('.qr-code-canvas');
			$canvas.empty();

			new QRCode($canvas[0], {
				text: votingUrl,
				width: 560,
				height: 560,
				colorDark: '#000000',
				colorLight: '#ffffff',
				correctLevel: QRCode.CorrectLevel.M
			});
		}

		loadSlideshow(competitionId, competitionSlug, category, categoryLabel) {
			const self = this;

			// Load images via AJAX
			$.ajax({
				url: photoCompetitionManagerSlideshow.ajaxUrl,
				type: 'POST',
				data: {
					action: 'photo_comp_get_slideshow_images',
					nonce: photoCompetitionManagerSlideshow.nonce,
					competition_id: competitionId,
					competition_slug: competitionSlug,
					category: category
				},
				success: function(response) {
					if (response.success && response.data.images && response.data.images.length > 0) {
						self.images = response.data.images;
						self.start();
					} else {
						alert('No images found for this category.');
					}
				},
				error: function() {
					alert('Failed to load slideshow images.');
				}
			});
		}

		start() {
			const self = this;

			// Pre-load the first image before starting
			this.preloadImage(this.images[0].url).then(() => {
				this.isRunning = true;
				this.isPaused = false;
				this.currentIndex = 0;

				// Show display first
				this.$display.css('display', 'flex');

				// Show first image (pass true to start timer)
				this.meterRenderer = this.createMeterRenderer(this.meterType);
				this.showImage(0, true);

				// Attempt fullscreen after display is visible
				setTimeout(function() {
					self.requestFullscreen();
				}, 100);
			}).catch((error) => {
				console.error('Failed to load first image:', error);
				// Start anyway
				this.isRunning = true;
				this.isPaused = false;
				this.currentIndex = 0;
				this.$display.css('display', 'flex');
				this.meterRenderer = this.createMeterRenderer(this.meterType);
				this.showImage(0, true);
				setTimeout(function() {
					self.requestFullscreen();
				}, 100);
			});
		}

		pause() {
			if (!this.isRunning || this.isPaused) {
				return;
			}

			this.isPaused = true;
			this.stopAutoAdvance();
			this.$pauseBtn.hide();
			this.$resumeBtn.show();
		}

		resume() {
			if (!this.isRunning || !this.isPaused) {
				return;
			}

			this.isPaused = false;
			this.startAutoAdvance();
			this.$resumeBtn.hide();
			this.$pauseBtn.show();
		}

		stop() {
			this.isRunning = false;
			this.isPaused = false;
			this.stopAutoAdvance();

			// Hide display
			this.$display.fadeOut(300);
			if (this.meterRenderer) {
				this.meterRenderer.reset();
				this.meterRenderer.destroy();
			}
			this.$pauseBtn.show();
			this.$resumeBtn.hide();

			// Exit fullscreen
			this.exitFullscreen();
		}

		showImage(index, shouldAutoAdvance = false) {
			if (index < 0 || index >= this.images.length) {
				return;
			}

			this.currentIndex = index;
			const image = this.images[index];

			// Always wait for image to load before displaying and starting timer
			this.preloadImage(image.url).then(() => {
				// Image is loaded, now display it
				this.$image.attr('src', image.url);
				this.$image.attr('alt', 'Image #' + image.random_number);

				// Update info
				this.$imageInfo.find('.image-number').text('#' + image.random_number);

				// Reset progress bar
				if (this.meterRenderer) this.meterRenderer.reset();
				this.startTime = Date.now();

				// Start auto-advance timer AFTER image is loaded
				if (shouldAutoAdvance && this.isRunning && !this.isPaused) {
					this.stopAutoAdvance();
					this.startAutoAdvance();
				}

				// Pre-cache next image after current one is displayed
				this.precacheNextImage(index);
			}).catch((error) => {
				console.error('Failed to load image:', error);
				// Still try to display even if load failed
				this.$image.attr('src', image.url);
				this.$image.attr('alt', 'Image #' + image.random_number);
				this.$imageInfo.find('.image-number').text('#' + image.random_number);
				if (this.meterRenderer) this.meterRenderer.reset();
				this.startTime = Date.now();

				// Start auto-advance even if image failed to load
				if (shouldAutoAdvance && this.isRunning && !this.isPaused) {
					this.stopAutoAdvance();
					this.startAutoAdvance();
				}

				// Still try to pre-cache next image
				this.precacheNextImage(index);
			});
		}

		nextImage() {
			const nextIndex = this.currentIndex + 1;

			// Check if we've reached the end
			if (nextIndex >= this.images.length) {
				// Slideshow has ended
				this.stop();
				return;
			}

			// Pass true to restart auto-advance after image loads
			this.showImage(nextIndex, true);
		}

		previousImage() {
			const prevIndex = this.currentIndex === 0 ? 0 : this.currentIndex - 1;
			// Pass true to restart auto-advance after image loads
			this.showImage(prevIndex, true);
		}

		startAutoAdvance() {
			this.stopAutoAdvance();

			const self = this;
			const duration = this.getDisplayDuration();

			// If duration is 0, manual mode - no auto-advance
			if (duration === 0) {
				// Hide progress bar in manual mode
				if (this.meterRenderer) this.meterRenderer.reset();
				return;
			}

			// Auto-advance timer
			this.interval = setTimeout(function() {
				self.nextImage();
			}, duration);

			// Progress bar animation
			this.startTime = Date.now();
			this.progressInterval = setInterval(function() {
				const elapsed = Date.now() - self.startTime;
				const progress = Math.min((elapsed / duration) * 100, 100);
				if (self.meterRenderer) self.meterRenderer.update(progress);
			}, 100);
		}

		stopAutoAdvance() {
			if (this.interval) {
				clearTimeout(this.interval);
				this.interval = null;
			}

			if (this.progressInterval) {
				clearInterval(this.progressInterval);
				this.progressInterval = null;
			}
		}

		preloadImage(url) {
			return new Promise((resolve, reject) => {
				// Check if already cached
				if (this.imageCache.has(url)) {
					const cachedImg = this.imageCache.get(url);
					if (cachedImg.complete) {
						// Already loaded, resolve immediately
						resolve(cachedImg);
						return;
					} else {
						// Still loading, wait for it to complete
						cachedImg.addEventListener('load', () => resolve(cachedImg));
						cachedImg.addEventListener('error', () => reject(new Error('Failed to load image: ' + url)));
						return;
					}
				}

				// Create new image and cache it
				const img = new Image();
				this.imageCache.set(url, img);

				img.onload = () => {
					resolve(img);
				};

				img.onerror = () => {
					reject(new Error('Failed to load image: ' + url));
				};

				img.src = url;
			});
		}

		precacheNextImage(currentIndex) {
			// Pre-cache only the next image
			const nextIndex = currentIndex + 1;
			if (nextIndex < this.images.length) {
				const nextUrl = this.images[nextIndex].url;
				// Only pre-load if not already cached
				if (!this.imageCache.has(nextUrl) || !this.imageCache.get(nextUrl).complete) {
					this.preloadImage(nextUrl).catch(error => {
						console.error('Failed to pre-cache next image:', error);
					});
				}
			}
		}

		requestFullscreen() {
			const elem = this.$display[0];

			if (elem.requestFullscreen) {
				elem.requestFullscreen();
			} else if (elem.webkitRequestFullscreen) {
				elem.webkitRequestFullscreen();
			} else if (elem.mozRequestFullScreen) {
				elem.mozRequestFullScreen();
			} else if (elem.msRequestFullscreen) {
				elem.msRequestFullscreen();
			}
		}

		exitFullscreen() {
			// Check if we're actually in fullscreen before trying to exit
			const isFullscreen = document.fullscreenElement ||
				document.webkitFullscreenElement ||
				document.mozFullScreenElement ||
				document.msFullscreenElement;

			if (!isFullscreen) {
				return; // Already exited, nothing to do
			}

			if (document.exitFullscreen) {
				document.exitFullscreen().catch(() => {
					// Ignore errors - user may have already exited
				});
			} else if (document.webkitExitFullscreen) {
				document.webkitExitFullscreen();
			} else if (document.mozCancelFullScreen) {
				document.mozCancelFullScreen();
			} else if (document.msExitFullscreen) {
				document.msExitFullscreen();
			}
		}
	}

	// Initialize on page load
	$(document).ready(function() {
		new AdminSlideshow();
	});

})(jQuery);
