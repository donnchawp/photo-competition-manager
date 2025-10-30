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

			this.bindEvents();
		}

		getDisplayDuration() {
			// Read from settings input, default to 10 seconds
			const seconds = parseInt($('#slideshow-duration-setting').val(), 10) || 10;
			return seconds * 1000;
		}

		bindEvents() {
			const self = this;

			// Start slideshow buttons
			$(document).on('click', '.photo-competition-manager-start-slideshow', function() {
				const $btn = $(this);
				const competitionId = $btn.data('competition-id');
				const competitionSlug = $btn.data('competition-slug');
				const category = $btn.data('category');
				const categoryLabel = $btn.data('category-label');

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
						if (self.isPaused) {
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
			this.$progressBar.css('width', '0%');
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
				this.$progressBar.css('width', '0%');
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
				this.$progressBar.css('width', '0%');
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

			// Auto-advance timer
			this.interval = setTimeout(function() {
				self.nextImage();
			}, duration);

			// Progress bar animation
			this.startTime = Date.now();
			this.progressInterval = setInterval(function() {
				const elapsed = Date.now() - self.startTime;
				const progress = Math.min((elapsed / duration) * 100, 100);
				self.$progressBar.css('width', progress + '%');
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
			if (document.exitFullscreen) {
				document.exitFullscreen();
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
