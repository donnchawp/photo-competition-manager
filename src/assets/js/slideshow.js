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

			this.$statusMessage.text('Starting slideshow...');

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

		showImage(index) {
			if (index < 0 || index >= this.images.length) {
				return;
			}

			this.currentIndex = index;
			const image = this.images[index];

			// Update image
			this.$image.attr('src', image.url);
			this.$image.attr('alt', 'Image #' + image.random_number);

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

			this.showImage(nextIndex);

			if (this.isRunning && !this.isPaused) {
				this.stopAutoAdvance();
				this.startAutoAdvance();
			}
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
			this.showImage(prevIndex);

			if (this.isRunning && !this.isPaused) {
				this.stopAutoAdvance();
				this.startAutoAdvance();
			}
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
