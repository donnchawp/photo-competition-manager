/**
 * Handle submission category updates with smart quota management.
 *
 * @package PhotoCompetitionManager
 */

document.addEventListener('DOMContentLoaded', () => {
	const categorySelects = document.querySelectorAll('.submission-category-select');
	const saveButton = document.getElementById('save-category-changes');
	const statusDiv = document.getElementById('category-change-status');

	if (categorySelects.length === 0) {
		return;
	}

	const pendingChanges = new Map(); // Track unsaved changes

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
				errors.push(`${label}: ${count}/${quota} (over quota)`);
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
			statusDiv.innerHTML = `
				<strong>Warning: Category quotas exceeded</strong>
				<ul>
					${errors.map((err) => `<li>${err}</li>`).join('')}
				</ul>
				<p>Please adjust categories before saving.</p>
			`;
			statusDiv.style.display = 'block';
			saveButton.disabled = true;
		} else {
			// Valid changes
			statusDiv.className = 'category-change-status info';
			statusDiv.textContent = `${pendingChanges.size} category change(s) pending. Click "Save Category Changes" to apply.`;
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
			alert('Please fix quota issues before saving.');
			return;
		}

		// Disable button during save
		saveButton.disabled = true;
		saveButton.textContent = 'Saving...';

		statusDiv.className = 'category-change-status info';
		statusDiv.textContent = 'Saving changes...';
		statusDiv.style.display = 'block';

		const token = config.token || '';
		const apiUrl = config.apiUrl || '';
		const nonce = config.nonce || '';

		let successCount = 0;
		let errorCount = 0;
		const errorMessages = [];

		// Process each change
		for (const [submissionId, newCategory] of pendingChanges) {
			try {
				const response = await fetch(
					`${apiUrl}photo-comp/v1/upload/${submissionId}/category?token=${encodeURIComponent(token)}`,
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': nonce,
						},
						body: JSON.stringify({
							category: newCategory,
						}),
					}
				);

				const data = await response.json();

				if (response.ok) {
					successCount++;
					// Update original category
					const select = document.querySelector(`.submission-category-select[data-submission-id="${submissionId}"]`);
					if (select) {
						select.dataset.originalCategory = newCategory;
					}
				} else {
					errorCount++;
					errorMessages.push(`Image ${submissionId}: ${data.message || 'Failed'}`);
				}
			} catch (error) {
				errorCount++;
				errorMessages.push(`Image ${submissionId}: Network error`);
			}
		}

		// Clear pending changes
		pendingChanges.clear();

		// Show results
		if (errorCount === 0) {
			statusDiv.className = 'category-change-status success';
			statusDiv.textContent = `✓ Successfully updated ${successCount} category assignment(s).`;
			setTimeout(() => {
				statusDiv.style.display = 'none';
			}, 5000);
		} else {
			statusDiv.className = 'category-change-status error';
			statusDiv.innerHTML = `
				<strong>Completed with errors:</strong>
				<p>${successCount} succeeded, ${errorCount} failed.</p>
				<ul>
					${errorMessages.map((msg) => `<li>${msg}</li>`).join('')}
				</ul>
			`;
		}

		// Reset button
		saveButton.textContent = 'Save Category Changes';
		saveButton.disabled = false;
		saveButton.style.display = 'none';
	});

	// Initial status check
	updateStatus();
});
