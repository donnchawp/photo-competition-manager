/**
 * Slideshow functionality for in-person voting presentations.
 */
(function($) {
	'use strict';

	/**
	 * Slideshow controller class.
	 */
	class SlideshowController {
		constructor(container) {
			this.$container = $(container);
			this.$display = this.$container.find('.slideshow-display');
			this.$controls = this.$container.find('.slideshow-controls');
			this.$image = this.$container.find('.slideshow-current-image');
			this.$imageInfo = this.$container.find('.slideshow-image-info');
			this.$progressBar = this.$container.find('.progress-bar');
			this.$currentCounter = this.$container.find('.current-image');
			this.$statusMessage = this.$container.find('.status-message');

			// Parse data attributes
			this.competitionId = this.$container.data('competition-id');
			this.category = this.$container.data('category');
			this.nonce = this.$container.data('nonce');
			this.ajaxUrl = this.$container.data('ajax-url');
			this.images = this.$container.data('images');

			// State
			this.currentIndex = 0;
			this.isRunning = false;
			this.isPaused = false;
			this.interval = null;
			this.progressInterval = null;
			this.startTime = 0;
			this.pauseTime = 0;

			// Image pre-caching
			this.imageCache = new Map();

			this.bindEvents();
		}

		bindEvents() {
			const self = this;

			// Control buttons
			this.$container.find('.slideshow-start').on('click', () => this.start());
			this.$container.find('.slideshow-pause').on('click', () => this.pause());
			this.$container.find('.slideshow-resume').on('click', () => this.resume());
			this.$container.find('.slideshow-stop').on('click', () => this.stop());
			this.$container.find('.slideshow-exit').on('click', () => this.exitFullscreen());

			// Keyboard controls for fullscreen
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

		start() {
			if (this.images.length === 0) {
				alert('No images available for slideshow.');
				return;
			}

			this.$statusMessage.text('Loading first image...');

			// Pre-load only the first image before starting
			this.preloadImage(this.images[0].url).then(() => {
				this.isRunning = true;
				this.isPaused = false;
				this.currentIndex = 0;

				// Update UI
				this.updateButtonStates();
				this.$display.fadeIn(300);
				this.$statusMessage.text('Slideshow running...');

				// Show first image
				this.showImage(0);
				this.startAutoAdvance();

				// Attempt to enter fullscreen
				this.requestFullscreen();
			}).catch((error) => {
				console.error('Failed to pre-load first image:', error);
				// Start anyway, even if image failed to pre-load
				this.isRunning = true;
				this.isPaused = false;
				this.currentIndex = 0;

				this.updateButtonStates();
				this.$display.fadeIn(300);
				this.$statusMessage.text('Slideshow running...');
				this.showImage(0);
				this.startAutoAdvance();
				this.requestFullscreen();
			});
		}

		pause() {
			if (!this.isRunning || this.isPaused) {
				return;
			}

			this.isPaused = true;
			this.pauseTime = Date.now();
			this.stopAutoAdvance();
			this.updateButtonStates();
			this.$statusMessage.text('Slideshow paused');
		}

		resume() {
			if (!this.isRunning || !this.isPaused) {
				return;
			}

			this.isPaused = false;
			this.startAutoAdvance();
			this.updateButtonStates();
			this.$statusMessage.text('Slideshow running...');
		}

		stop() {
			if (!this.isRunning) {
				return;
			}


			// Stop slideshow via AJAX (voting remains open)
			this.stopSlideshow().then((response) => {
				this.isRunning = false;
				this.isPaused = false;
				this.stopAutoAdvance();

				// Update UI
				this.updateButtonStates();
				this.$display.fadeOut(300);
				this.$statusMessage.text(response.message || 'Slideshow stopped');
				this.$progressBar.css('width', '0%');

				// Exit fullscreen
				this.exitFullscreen();
			}).catch((error) => {
				alert('Failed to stop slideshow: ' + error.message);
			});
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
				this.updateImageInfo(index, image);

				// Start auto-advance timer AFTER image is loaded
				if (shouldAutoAdvance && this.isRunning && !this.isPaused) {
					this.stopAutoAdvance();
					this.startAutoAdvance();
				}

				// Pre-cache next image after current one is displayed
				this.precacheNextImages(index);
			}).catch((error) => {
				console.error('Failed to load image:', error);
				// Still try to display even if load failed
				this.$image.attr('src', image.url);
				this.$image.attr('alt', 'Image #' + image.random_number);
				this.updateImageInfo(index, image);

				// Start auto-advance even if image failed to load
				if (shouldAutoAdvance && this.isRunning && !this.isPaused) {
					this.stopAutoAdvance();
					this.startAutoAdvance();
				}

				// Still try to pre-cache next image
				this.precacheNextImages(index);
			});
		}

		updateImageInfo(index, image) {
			// Update info
			this.$imageInfo.find('.image-number').text('#' + image.random_number);

			// Update counter
			this.$currentCounter.text(index + 1);

			// Reset progress bar
			this.$progressBar.css('width', '0%');
			this.startTime = Date.now();
		}

		nextImage() {
			const nextIndex = this.currentIndex + 1;

			// Check if we've reached the end
			if (nextIndex >= this.images.length) {
				// Slideshow has ended naturally
				this.endSlideshow();
				return;
			}

			// Pass true to restart auto-advance after image loads
			this.showImage(nextIndex, true);
		}

		endSlideshow() {
			if (!this.isRunning) {
				return;
			}

			this.$statusMessage.text('Slideshow complete. Close voting manually when ready.');

			// Stop slideshow via AJAX (voting remains open)
			this.stopSlideshow().then((response) => {
				this.isRunning = false;
				this.isPaused = false;
				this.stopAutoAdvance();

				// Update UI
				this.updateButtonStates();
				this.$display.fadeOut(300);
				this.$statusMessage.text(response.message || 'Slideshow ended');
				this.$progressBar.css('width', '0%');

				// Exit fullscreen
				this.exitFullscreen();
			}).catch((error) => {
				console.error('Failed to stop slideshow:', error);
				// Still end the slideshow even if AJAX fails
				this.isRunning = false;
				this.isPaused = false;
				this.stopAutoAdvance();
				this.updateButtonStates();
				this.$display.fadeOut(300);
				this.exitFullscreen();
			});
		}

		previousImage() {
			const prevIndex = this.currentIndex === 0 ? this.images.length - 1 : this.currentIndex - 1;
			// Pass true to restart auto-advance after image loads
			this.showImage(prevIndex, true);
		}

		startAutoAdvance() {
			this.stopAutoAdvance();

			const intervalSeconds = parseInt(this.$container.find('#slideshow-interval').val(), 10) || 10;
			const intervalMs = intervalSeconds * 1000;

			// Auto-advance timer
			this.interval = setTimeout(() => {
				this.nextImage();
			}, intervalMs);

			// Progress bar animation
			this.startTime = Date.now();
			this.progressInterval = setInterval(() => {
				const elapsed = Date.now() - this.startTime;
				const progress = Math.min((elapsed / intervalMs) * 100, 100);
				this.$progressBar.css('width', progress + '%');
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

		updateButtonStates() {
			this.$container.find('.slideshow-start').prop('disabled', this.isRunning);
			this.$container.find('.slideshow-pause').prop('disabled', !this.isRunning || this.isPaused);
			this.$container.find('.slideshow-resume').prop('disabled', !this.isRunning || !this.isPaused);
			this.$container.find('.slideshow-stop').prop('disabled', !this.isRunning);
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

		precacheNextImages(currentIndex) {
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

	stopSlideshow() {
		return new Promise((resolve, reject) => {
			$.ajax({
				url: this.ajaxUrl,
				type: 'POST',
				data: {
					action: 'photo_comp_slideshow_stop',
					nonce: this.nonce,
					competition_id: this.competitionId,
					category: this.category
				},
				success: function(response) {
					if (response.success) {
						resolve(response.data);
					} else {
						reject(new Error(response.data.message || 'Failed to stop slideshow'));
					}
				},
				error: function(xhr, status, error) {
					reject(new Error('AJAX error: ' + error));
				}
			});
		});
	}
}

	// Initialize slideshow on page load
	$(document).ready(function() {
		$('.photo-comp-slideshow-container').each(function() {
			new SlideshowController(this);
		});
	});

})(jQuery);
