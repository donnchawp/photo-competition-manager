/**
 * Client-side validation for voting forms.
 *
 * Ensures all images have received a vote before allowing form submission.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

(function () {
	'use strict';

	/**
	 * Make a paragraph holding the given text, so a translation can't add markup.
	 *
	 * @param {string} text      The paragraph's text.
	 * @param {string} className The paragraph's class, if any.
	 * @return {HTMLParagraphElement} The paragraph.
	 */
	function paragraph(text, className = '') {
		const p = document.createElement('p');
		p.className = className;
		p.textContent = text;
		return p;
	}

	/**
	 * Initialize voting form validation.
	 */
	function initVotingValidation() {
		const votingForms = document.querySelectorAll('.voting-form');

		if (votingForms.length === 0) {
			return;
		}

		votingForms.forEach((form) => {
			// Track vote changes to provide real-time feedback
			const voteInputs = form.querySelectorAll(
				'input[name^="votes["], select[name^="votes["]'
			);

			voteInputs.forEach((input) => {
				input.addEventListener('change', () => {
					updateVoteCounter(form);
				});
			});

			// Initialize counter
			updateVoteCounter(form);
		});

		// Use event delegation at document level for submit events
		// This ensures we catch the event even if something else is interfering
		document.addEventListener(
			'submit',
			(e) => {
				// Check if this is a voting form
				if (
					e.target &&
					e.target.classList &&
					e.target.classList.contains('voting-form')
				) {
					if (!validateAllImagesVoted(e.target)) {
						e.preventDefault();
						e.stopPropagation();
						e.stopImmediatePropagation();
						showValidationError(e.target);
						return false;
					}
				}
			},
			true
		); // Use capture phase
	}

	/**
	 * Check if all images have received a vote.
	 *
	 * @param {HTMLFormElement} form The voting form.
	 * @return {boolean} True if all images have votes, false otherwise.
	 */
	function validateAllImagesVoted(form) {
		const imageItems = form.querySelectorAll('.voting-image-item');
		let votedCount = 0;

		imageItems.forEach((item) => {
			const imageId = item.getAttribute('data-image-id');
			const radioInputs = item.querySelectorAll(
				`input[type="radio"][name="votes[${imageId}]"]`
			);
			const selectInput = item.querySelector(
				`select[name="votes[${imageId}]"]`
			);

			let hasVote = false;

			// Check radio buttons
			if (radioInputs.length > 0) {
				radioInputs.forEach((radio) => {
					if (radio.checked) {
						hasVote = true;
					}
				});
			}

			// Check dropdown
			if (selectInput && selectInput.value !== '') {
				hasVote = true;
			}

			if (hasVote) {
				votedCount++;
				item.classList.remove('vote-missing');
			} else {
				item.classList.add('vote-missing');
			}
		});

		return votedCount === imageItems.length;
	}

	/**
	 * Update the vote counter display.
	 *
	 * @param {HTMLFormElement} form The voting form.
	 */
	function updateVoteCounter(form) {
		const imageItems = form.querySelectorAll('.voting-image-item');
		let votedCount = 0;

		imageItems.forEach((item) => {
			const imageId = item.getAttribute('data-image-id');
			const radioInputs = item.querySelectorAll(
				`input[type="radio"][name="votes[${imageId}]"]`
			);
			const selectInput = item.querySelector(
				`select[name="votes[${imageId}]"]`
			);

			let hasVote = false;

			// Check radio buttons
			if (radioInputs.length > 0) {
				radioInputs.forEach((radio) => {
					if (radio.checked) {
						hasVote = true;
					}
				});
			}

			// Check dropdown
			if (selectInput && selectInput.value !== '') {
				hasVote = true;
			}

			if (hasVote) {
				votedCount++;
				item.classList.remove('vote-missing');
			} else {
				item.classList.add('vote-missing');
			}
		});

		// Update or create counter
		let counter = form.querySelector('.vote-counter');
		if (!counter) {
			counter = document.createElement('div');
			counter.className = 'vote-counter';
			const submitSection = form.querySelector('.voting-submit');
			if (submitSection) {
				submitSection.insertBefore(counter, submitSection.firstChild);
			}
		}

		const totalImages = imageItems.length;
		const allVoted = votedCount === totalImages;

		counter.replaceChildren(
			allVoted
				? paragraph(
						'✓ ' +
							sprintf(
								/* translators: %d: number of images in the competition */
								_n('All %d image has been voted for.', 'All %d images have been voted for.', totalImages, 'photo-competition-manager'),
								totalImages
							),
						'vote-counter-complete'
					)
				: paragraph(
						sprintf(
							/* translators: 1: number of images voted for, 2: number of images in the competition */
							_n(
								'You have voted for %1$d of %2$d image. Please vote for all images before submitting.',
								'You have voted for %1$d of %2$d images. Please vote for all images before submitting.',
								totalImages,
								'photo-competition-manager'
							),
							votedCount,
							totalImages
						),
						'vote-counter-incomplete'
					)
		);

		// Update submit button state
		const submitButton = form.querySelector('button[type="submit"]');
		if (submitButton) {
			if (allVoted) {
				submitButton.disabled = false;
				submitButton.classList.remove('button-disabled');
			} else {
				submitButton.disabled = true;
				submitButton.classList.add('button-disabled');
			}
		}
	}

	/**
	 * Show validation error message.
	 *
	 * @param {HTMLFormElement} form The voting form.
	 */
	function showValidationError(form) {
		// Remove any existing error
		const existingError = form.querySelector('.voting-validation-error');
		if (existingError) {
			existingError.remove();
		}

		// Get count of missing votes
		const imageItems = form.querySelectorAll('.voting-image-item');
		const missingItems = form.querySelectorAll('.vote-missing');

		// Create error message
		const error = document.createElement('div');
		error.className = 'voting-validation-error error';
		error.style.cssText =
			'background: #f8d7da; border: 1px solid #f5c2c7; color: #842029; padding: 15px 20px; margin: 20px 0; border-radius: 4px; font-size: 16px;';
		const heading = paragraph('');
		heading.style.margin = '0 0 10px 0';
		const strong = document.createElement('strong');
		strong.textContent = '⚠️ ' + __('Please vote for all images before submitting.', 'photo-competition-manager');
		heading.append(strong);
		const detail = paragraph(
			sprintf(
				/* translators: 1: number of images still needing a vote, 2: number of images in the competition */
				_n(
					'You need to vote for %1$d more image out of %2$d total. Images missing votes are highlighted with a red border below.',
					'You need to vote for %1$d more images out of %2$d total. Images missing votes are highlighted with a red border below.',
					missingItems.length,
					'photo-competition-manager'
				),
				missingItems.length,
				imageItems.length
			)
		);
		detail.style.margin = '0';
		error.append(heading, detail);

		// Insert error before submit button or at top of form
		const submitSection = form.querySelector('.voting-submit');
		if (submitSection) {
			submitSection.insertBefore(error, submitSection.firstChild);
		} else {
			// Fallback: insert at top of form
			form.insertBefore(error, form.firstChild);
		}

		// Also show alert for visibility
		alert(
			sprintf(
				/* translators: %d: number of images in the competition */
				_n('Please vote for all %d image before submitting.', 'Please vote for all %d images before submitting.', imageItems.length, 'photo-competition-manager'),
				imageItems.length
			) +
				'\n\n' +
				sprintf(
					/* translators: %d: number of images still needing a vote */
					_n('You still need to vote for %d more image.', 'You still need to vote for %d more images.', missingItems.length, 'photo-competition-manager'),
					missingItems.length
				)
		);

		// Scroll to first missing vote
		const firstMissing = form.querySelector('.vote-missing');
		if (firstMissing) {
			setTimeout(() => {
				firstMissing.scrollIntoView({
					behavior: 'smooth',
					block: 'center',
				});
			}, 100);
		}
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initVotingValidation);
	} else {
		initVotingValidation();
	}
})();
