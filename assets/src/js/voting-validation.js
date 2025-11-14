/**
 * Client-side validation for voting forms.
 *
 * Ensures all images have received a vote before allowing form submission.
 */
(function () {
	'use strict';

	/**
	 * Initialize voting form validation.
	 */
	function initVotingValidation() {
		const votingForms = document.querySelectorAll('.voting-form');

		if (votingForms.length === 0) {
			// eslint-disable-next-line no-console
			console.log('Photo Competition: No voting forms found on page');
			return;
		}

		// eslint-disable-next-line no-console
		console.log(
			`Photo Competition: Initializing validation for ${votingForms.length} voting form(s)`
		);

		votingForms.forEach((form) => {
			// Track vote changes to provide real-time feedback
			const voteInputs = form.querySelectorAll(
				'input[name^="votes["], select[name^="votes["]'
			);

			// eslint-disable-next-line no-console
			console.log(
				`Photo Competition: Found ${voteInputs.length} vote inputs in form`
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
					// eslint-disable-next-line no-console
					console.log(
						'Photo Competition: Form submit triggered (via delegation)'
					);

					if (!validateAllImagesVoted(e.target)) {
						// eslint-disable-next-line no-console
						console.log(
							'Photo Competition: Validation failed, preventing submit'
						);
						e.preventDefault();
						e.stopPropagation();
						e.stopImmediatePropagation();
						showValidationError(e.target);
						return false;
					} else {
						// eslint-disable-next-line no-console
						console.log(
							'Photo Competition: Validation passed, allowing submit'
						);
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

		counter.innerHTML = allVoted
			? `<p class="vote-counter-complete">✓ All ${totalImages} images have been voted for.</p>`
			: `<p class="vote-counter-incomplete">You have voted for ${votedCount} of ${totalImages} images. Please vote for all images before submitting.</p>`;

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
		// eslint-disable-next-line no-console
		console.log('Photo Competition: Showing validation error');

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
		error.innerHTML = `<p style="margin: 0 0 10px 0;"><strong>⚠️ Please vote for all images before submitting.</strong></p><p style="margin: 0;">You need to vote for ${missingItems.length} more image(s) out of ${imageItems.length} total. Images missing votes are highlighted with a red border below.</p>`;

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
			`Please vote for all ${imageItems.length} images before submitting.\n\nYou still need to vote for ${missingItems.length} more image(s).`
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
