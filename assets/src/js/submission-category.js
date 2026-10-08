/**
 * Handle submission category updates with smart quota management.
 *
 * @package PhotoCompetitionManager
 */

import { __, _n, sprintf } from '@wordpress/i18n';

document.addEventListener('DOMContentLoaded', () => {
	const categorySelects = document.querySelectorAll('.submission-category-select');
	const saveButton = document.getElementById('save-category-changes');
	const statusDiv = document.getElementById('category-change-status');

	if (categorySelects.length === 0) {
		return;
	}

	const pendingChanges = new Map(); // Track unsaved changes

	// The button's label as the page printed it, already in the site's language.
	const saveLabel = saveButton.textContent.trim();

	// Get configuration data
	const config = window.photoCompCategoryUpdate || {};
	const categories = config.categories || {};

	/**
	 * Count submissions per category with current selections.
	 */
	function getCategoryCounts() {
		const counts = {};

		// Initialize counts
		Object.keys(categories).forEach((slug) => {
			counts[slug] = 0;
		});

		// Count based on current dropdown values
		categorySelects.forEach((select) => {
			const category = select.value;
			if (category && counts.hasOwnProperty(category)) {
				counts[category]++;
			}
		});

		return counts;
	}

	/**
	 * Validate if current category distribution is valid.
	 */
	function validateQuotas() {
		const counts = getCategoryCounts();
		const errors = [];

		Object.keys(counts).forEach((slug) => {
			const count = counts[slug];
			const quota = categories[slug]?.quota || 1;
			const label = categories[slug]?.label || slug;

			if (count > quota) {
				errors.push(
					sprintf(
						/* translators: 1: category name, 2: entries in the category, 3: the category's quota */
						__('%1$s: %2$d/%3$d (over quota)', 'photo-competition-manager'),
						label,
						count,
						quota
					)
				);
			}
		});

		return errors;
	}

	/**
	 * Auto-swap categories for 2-image case.
	 */
	function autoSwapForTwoImages(changedSelect) {
		if (categorySelects.length !== 2) {
			return false;
		}

		if (Object.keys(categories).length !== 2) {
			return false;
		}

		// Find the other select
		const otherSelect = Array.from(categorySelects).find((s) => s !== changedSelect);
		if (!otherSelect) {
			return false;
		}

		const newCategory = changedSelect.value;
		const otherCategory = otherSelect.value;

		// If both are now in the same category, swap the other one
		if (newCategory === otherCategory) {
			const categoryKeys = Object.keys(categories);
			const targetCategory = categoryKeys.find((key) => key !== newCategory);

			if (targetCategory) {
				otherSelect.value = targetCategory;
				pendingChanges.set(otherSelect.dataset.submissionId, targetCategory);
				return true;
			}
		}

		return false;
	}

	/**
	 * Update status message.
	 */
	function updateStatus() {
		const errors = validateQuotas();

		if (pendingChanges.size === 0) {
			// No changes
			statusDiv.style.display = 'none';
			saveButton.style.display = 'none';
			return;
		}

		saveButton.style.display = 'block';

		if (errors.length > 0) {
			// Show warnings
			statusDiv.className = 'category-change-status warning';
			// Built from text nodes so a category name or a translation can't add markup.
			const heading = document.createElement('strong');
			heading.textContent = __('Warning: Category quotas exceeded', 'photo-competition-manager');
			const list = document.createElement('ul');
			errors.forEach((err) => {
				const item = document.createElement('li');
				item.textContent = err;
				list.append(item);
			});
			const advice = document.createElement('p');
			advice.textContent = __('Please adjust categories before saving.', 'photo-competition-manager');
			statusDiv.replaceChildren(heading, list, advice);
			statusDiv.style.display = 'block';
			saveButton.disabled = true;
		} else {
			// Valid changes
			statusDiv.className = 'category-change-status info';
			statusDiv.textContent = sprintf(
				/* translators: 1: number of category changes, 2: the save button's label */
				_n(
					'%1$d category change pending. Click "%2$s" to apply.',
					'%1$d category changes pending. Click "%2$s" to apply.',
					pendingChanges.size,
					'photo-competition-manager'
				),
				pendingChanges.size,
				saveLabel
			);
			statusDiv.style.display = 'block';
			saveButton.disabled = false;
		}
	}

	/**
	 * Handle category selection change.
	 */
	categorySelects.forEach((select) => {
		select.addEventListener('change', () => {
			const submissionId = select.dataset.submissionId;
			const originalCategory = select.dataset.originalCategory;
			const newCategory = select.value;

			if (newCategory === originalCategory) {
				// Reverted to original
				pendingChanges.delete(submissionId);
			} else {
				// Track pending change
				pendingChanges.set(submissionId, newCategory);
			}

			// Auto-swap for 2-image case
			autoSwapForTwoImages(select);

			// Update UI
			updateStatus();
		});
	});

	/**
	 * Save all category changes.
	 */
	saveButton.addEventListener('click', async () => {
		if (pendingChanges.size === 0) {
			return;
		}

		// Validate first
		const errors = validateQuotas();
		if (errors.length > 0) {
			alert(__('Please fix quota issues before saving.', 'photo-competition-manager'));
			return;
		}

		// Disable button during save
		saveButton.disabled = true;
		saveButton.textContent = __('Saving...', 'photo-competition-manager');

		statusDiv.className = 'category-change-status info';
		statusDiv.textContent = __('Saving changes...', 'photo-competition-manager');
		statusDiv.style.display = 'block';

		const token = config.token || '';
		const apiUrl = config.apiUrl || '';
		const nonce = config.nonce || '';

		// Send every change in one request: the server checks quota against where
		// the entries end up, and applies all of them or none.
		const showError = (message) => {
			// Nothing moved, so the pending changes stay for the member to fix and save again.
			statusDiv.className = 'category-change-status error';
			statusDiv.textContent = sprintf(
				/* translators: %s: why the server refused the changes */
				__('No categories were changed: %s', 'photo-competition-manager'),
				message
			);
		};

		try {
			const response = await fetch(
				`${apiUrl}photo-comp/v1/upload/categories?token=${encodeURIComponent(token)}`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify({
						changes: Object.fromEntries(pendingChanges),
					}),
				}
			);

			const data = await response.json();

			if (response.ok) {
				statusDiv.className = 'category-change-status success';
				statusDiv.textContent =
					'✓ ' +
					sprintf(
						/* translators: %d: number of entries moved to another category */
						_n(
							'Successfully updated %d category assignment.',
							'Successfully updated %d category assignments.',
							pendingChanges.size,
							'photo-competition-manager'
						),
						pendingChanges.size
					);
				setTimeout(() => {
					statusDiv.style.display = 'none';
				}, 5000);

				pendingChanges.forEach((newCategory, submissionId) => {
					const select = document.querySelector(`.submission-category-select[data-submission-id="${submissionId}"]`);
					if (select) {
						select.dataset.originalCategory = newCategory;
					}
				});
				pendingChanges.clear();
				saveButton.style.display = 'none';
			} else {
				showError(data.message || __('Failed', 'photo-competition-manager'));
			}
		} catch (error) {
			// The request may have gone through, so don't claim nothing changed.
			statusDiv.className = 'category-change-status error';
			statusDiv.textContent = __("Couldn't confirm whether the categories changed. Reload the page to check.", 'photo-competition-manager');
		}

		saveButton.textContent = saveLabel;
		saveButton.disabled = false;
	});

	// Initial status check
	updateStatus();
});
