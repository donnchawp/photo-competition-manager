/**
 * Drag and drop bulk upload functionality.
 *
 * @package PhotoCompetitionManager
 */

import { __, _n, sprintf } from '@wordpress/i18n';

class DragDropUpload {
	constructor(config) {
		this.token = config.token;
		this.apiUrl = config.apiUrl;
		this.categories = config.categories;
		// A copy, since the uploader counts down each category's remaining quota as images go in.
		this.quotas = Object.fromEntries(
			Object.entries(config.quotas).map(([slug, quota]) => [slug, { ...quota }])
		);
		this.maxFileSize = config.maxFileSize || 10 * 1024 * 1024; // 10MB default
		this.allowedFormats = config.allowedFormats || ['jpg', 'jpeg', 'png'];
		this.selectedFiles = [];

		this.init();
	}

	init() {
		this.dropZone = document.querySelector('.photo-comp-drag-drop-zone');
		this.fileInput = document.querySelector('#batch-file-input');
		this.previewGrid = document.querySelector('.photo-comp-preview-grid');
		this.uploadButton = document.querySelector('.photo-comp-upload-all-btn');
		this.progressSection = document.querySelector('.photo-comp-upload-progress');

		if (!this.dropZone || !this.fileInput || !this.previewGrid) {
			return;
		}

		this.bindEvents();
	}

	bindEvents() {
		// Prevent default drag and drop behavior on the entire document.
		document.addEventListener('dragover', (e) => {
			e.preventDefault();
			e.stopPropagation();
		});

		document.addEventListener('drop', (e) => {
			e.preventDefault();
			e.stopPropagation();
		});

		// Drag and drop events on drop zone.
		this.dropZone.addEventListener('dragover', (e) => this.handleDragOver(e));
		this.dropZone.addEventListener('dragleave', (e) => this.handleDragLeave(e));
		this.dropZone.addEventListener('drop', (e) => this.handleDrop(e));
		this.dropZone.addEventListener('click', () => this.fileInput.click());

		// File input change.
		this.fileInput.addEventListener('change', (e) => this.handleFileSelect(e));

		// Upload all button.
		if (this.uploadButton) {
			this.uploadButton.addEventListener('click', () => this.uploadAll());
		}
	}

	handleDragOver(e) {
		e.preventDefault();
		e.stopPropagation();
		this.dropZone.classList.add('drag-over');
	}

	handleDragLeave(e) {
		e.preventDefault();
		e.stopPropagation();
		this.dropZone.classList.remove('drag-over');
	}

	handleDrop(e) {
		e.preventDefault();
		e.stopPropagation();
		this.dropZone.classList.remove('drag-over');

		const files = Array.from(e.dataTransfer.files);
		this.addFiles(files);
	}

	handleFileSelect(e) {
		const files = Array.from(e.target.files);
		this.addFiles(files);
	}

	addFiles(files) {
		const validFiles = files.filter((file) => this.validateFile(file));

		if (validFiles.length === 0) {
			this.showError(__('No valid image files selected.', 'photo-competition-manager'));
			return;
		}

		// Calculate total available quota across all categories.
		const totalAvailableQuota = this.getTotalAvailableQuota();
		const currentFileCount = this.selectedFiles.length;
		const availableSlots = totalAvailableQuota - currentFileCount;

		if (availableSlots <= 0) {
			this.showError(__('All category quotas are full. Cannot add more files.', 'photo-competition-manager'));
			return;
		}

		// Limit files to available quota slots.
		const filesToAdd = validFiles.slice(0, availableSlots);
		const rejectedCount = validFiles.length - filesToAdd.length;

		if (rejectedCount > 0) {
			const added = sprintf(
				/* translators: %d: number of files added. */
				_n('Only %d file added.', 'Only %d files added.', filesToAdd.length, 'photo-competition-manager'),
				filesToAdd.length
			);
			const rejected = sprintf(
				/* translators: %d: number of files turned away. */
				_n('%d file rejected due to quota limits.', '%d files rejected due to quota limits.', rejectedCount, 'photo-competition-manager'),
				rejectedCount
			);
			this.showError(`${added} ${rejected}`);
		}

		filesToAdd.forEach((file) => {
			const fileId = this.generateFileId();
			const fileData = {
				id: fileId,
				file: file,
				category: '',
				preview: null,
			};

			this.selectedFiles.push(fileData);
			this.createPreview(fileData);
		});

		this.updateUI();
	}

	getTotalAvailableQuota() {
		// Calculate remaining quota across all categories, accounting for already-assigned files.
		const assignedCount = {};
		this.selectedFiles.forEach((fileData) => {
			if (fileData.category) {
				assignedCount[fileData.category] = (assignedCount[fileData.category] || 0) + 1;
			}
		});

		let total = 0;
		Object.keys(this.quotas).forEach((categorySlug) => {
			const quota = this.quotas[categorySlug];
			const assigned = assignedCount[categorySlug] || 0;
			const remaining = quota.remaining - assigned;
			total += Math.max(0, remaining);
		});

		return total;
	}

	getEffectiveRemainingQuota(categorySlug) {
		// Get remaining quota for a category, accounting for already-assigned files.
		const quota = this.quotas[categorySlug];
		if (!quota) {
			return 0;
		}

		const assignedCount = this.selectedFiles.filter((f) => f.category === categorySlug).length;
		return Math.max(0, quota.remaining - assignedCount);
	}

	getAvailableCategories() {
		// Get categories that still have quota available (accounting for assigned files).
		return this.categories.filter((cat) => {
			return this.getEffectiveRemainingQuota(cat.slug) > 0;
		});
	}

	getAvailableCategoriesForFile(fileData) {
		// Get categories available for a specific file.
		// Rules:
		// 1. If file is already assigned: show ALL categories (allow swapping)
		// 2. If file is unassigned: only show categories with remaining quota

		if (fileData.category) {
			// File is assigned - allow swapping to ANY category
			return this.categories.slice(); // Return copy of all categories
		} else {
			// File is unassigned - only show categories with quota
			return this.getAvailableCategories();
		}
	}

	validateFile(file) {
		// Check if it's an image.
		if (!file.type.startsWith('image/')) {
			return false;
		}

		// Check file extension.
		const ext = file.name.split('.').pop().toLowerCase();
		if (!this.allowedFormats.includes(ext)) {
			return false;
		}

		// Check file size.
		if (file.size > this.maxFileSize) {
			this.showError(
				sprintf(
					/* translators: 1: file name, 2: maximum file size, such as "5 MB". */
					__('%1$s is too large. Maximum file size is %2$s.', 'photo-competition-manager'),
					file.name,
					this.formatFileSize(this.maxFileSize)
				)
			);
			return false;
		}

		return true;
	}

	createPreview(fileData) {
		const reader = new FileReader();

		reader.onload = (e) => {
			fileData.preview = e.target.result;
			this.renderPreviewItem(fileData);
		};

		reader.readAsDataURL(fileData.file);
	}

	renderPreviewItem(fileData) {
		const item = document.createElement('div');
		item.className = 'photo-comp-preview-item';
		item.dataset.fileId = fileData.id;

		const img = document.createElement('img');
		img.src = fileData.preview;
		img.alt = fileData.file.name;

		const controls = document.createElement('div');
		controls.className = 'photo-comp-preview-controls';

		const categorySelect = this.createCategorySelect(fileData);
		const removeButton = this.createRemoveButton(fileData);
		const fileInfo = document.createElement('div');
		fileInfo.className = 'photo-comp-file-info';
		fileInfo.textContent = this.formatFileSize(fileData.file.size);

		controls.appendChild(categorySelect);
		controls.appendChild(fileInfo);
		controls.appendChild(removeButton);

		item.appendChild(img);
		item.appendChild(controls);

		this.previewGrid.appendChild(item);
	}

	createCategorySelect(fileData) {
		const container = document.createElement('div');
		container.className = 'photo-comp-category-select-container';

		// Get available categories for this specific file (includes its current category even if exhausted).
		const availableCategories = this.getAvailableCategoriesForFile(fileData);

		// If only one category is available, auto-select it and show as text.
		if (availableCategories.length === 1) {
			const cat = availableCategories[0];

			// Auto-assign the category.
			fileData.category = cat.slug;

			// Display as text instead of dropdown.
			container.appendChild(this.createCategoryLabel(cat));

			// Trigger update since we auto-assigned.
			this.updateUploadButton();
			this.updateQuotaWarning();

			return container;
		}

		// Multiple categories available - show dropdown.
		const select = document.createElement('select');
		select.className = 'photo-comp-category-select';
		select.dataset.fileId = fileData.id;

		this.fillCategoryOptions(select, availableCategories);

		select.addEventListener('change', (e) => {
			fileData.category = e.target.value;
			this.updateUploadButton();
			this.updateQuotaWarning();
			this.refreshCategorySelects();
		});

		container.appendChild(select);
		return container;
	}

	/**
	 * Build the text shown in place of the dropdown when only one category fits.
	 *
	 * The label is added as text, so a category label with HTML in it shows as written.
	 *
	 * @param {Object} cat The category.
	 * @return {HTMLElement} The label.
	 */
	createCategoryLabel(cat) {
		const categoryLabel = document.createElement('div');
		categoryLabel.className = 'photo-comp-category-label';

		const heading = document.createElement('strong');
		heading.textContent = __('Category:', 'photo-competition-manager');
		categoryLabel.append(heading, ` ${cat.label}`);

		const remaining = this.remainingText(cat);
		if (remaining) {
			const small = document.createElement('small');
			small.textContent = remaining;
			categoryLabel.append(' ', small);
		}

		return categoryLabel;
	}

	/**
	 * Replace a dropdown's options with a prompt and the given categories.
	 *
	 * @param {HTMLSelectElement} select     The dropdown.
	 * @param {Object[]}          categories The categories to offer.
	 */
	fillCategoryOptions(select, categories) {
		select.replaceChildren();

		const defaultOption = document.createElement('option');
		defaultOption.value = '';
		defaultOption.textContent = __('-- Select Category --', 'photo-competition-manager');
		select.appendChild(defaultOption);

		categories.forEach((cat) => {
			const option = document.createElement('option');
			option.value = cat.slug;
			const remaining = this.remainingText(cat);
			option.textContent = remaining ? `${cat.label} ${remaining}` : cat.label;
			select.appendChild(option);
		});
	}

	/**
	 * How many more images a category takes, such as "(2 remaining)".
	 *
	 * Left out for a category that takes only one image.
	 *
	 * @param {Object} cat The category.
	 * @return {string} The text, or an empty string.
	 */
	remainingText(cat) {
		if (this.quotas[cat.slug].quota <= 1) {
			return '';
		}

		const remaining = this.getEffectiveRemainingQuota(cat.slug);
		return sprintf(
			/* translators: %d: number of images the category still takes. */
			_n('(%d remaining)', '(%d remaining)', remaining, 'photo-competition-manager'),
			remaining
		);
	}

	refreshCategorySelects() {
		// Refresh all category selects/labels to reflect current quota availability.
		this.selectedFiles.forEach((fileData) => {
			const item = this.previewGrid.querySelector(`[data-file-id="${fileData.id}"]`);
			if (!item) {
				return;
			}

			const controlsDiv = item.querySelector('.photo-comp-preview-controls');
			if (!controlsDiv) {
				return;
			}

			// Get the current category select container.
			const existingContainer = controlsDiv.querySelector('.photo-comp-category-select-container');
			if (!existingContainer) {
				return;
			}

			// Get available categories for this specific file (includes current category even if exhausted).
			const availableCategories = this.getAvailableCategoriesForFile(fileData);

			if (availableCategories.length === 0) {
				return;
			}

			// A dropdown that still has a choice to offer keeps its place; its options are refreshed below.
			const existingSelect = existingContainer.querySelector('select');
			if (existingSelect && availableCategories.length > 1) {
				return;
			}

			// Otherwise rebuild it: a label when one category fits (auto-assigned), a dropdown when several do.
			existingContainer.replaceWith(this.createCategorySelect(fileData));
		});

		// Fill the dropdowns last, so their counts include every file given its only category above.
		this.selectedFiles.forEach((fileData) => {
			const select = this.previewGrid.querySelector(`select[data-file-id="${fileData.id}"]`);
			const availableCategories = this.getAvailableCategoriesForFile(fileData);
			if (!select || availableCategories.length < 2) {
				return;
			}

			this.fillCategoryOptions(select, availableCategories);
			select.value = fileData.category;
		});

		this.updateUploadButton();
	}

	updateQuotaWarning() {
		// Remove existing warning.
		const existingWarning = document.querySelector('.photo-comp-quota-warning');
		if (existingWarning) {
			existingWarning.remove();
		}

		if (!this.validateQuotas()) {
			const warning = document.createElement('div');
			warning.className = 'photo-comp-quota-warning';
			warning.textContent = __(
				'Warning: You have assigned more images to a category than your remaining quota allows. Please adjust your selections.',
				'photo-competition-manager'
			);
			this.previewGrid.parentNode.insertBefore(warning, this.previewGrid);
		}
	}

	createRemoveButton(fileData) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'photo-comp-remove-btn';
		button.textContent = __('Remove', 'photo-competition-manager');
		button.setAttribute(
			'aria-label',
			sprintf(
				/* translators: %s: file name. */
				__('Remove %s', 'photo-competition-manager'),
				fileData.file.name
			)
		);

		button.addEventListener('click', () => {
			this.removeFile(fileData.id);
		});

		return button;
	}

	removeFile(fileId) {
		this.selectedFiles = this.selectedFiles.filter((f) => f.id !== fileId);

		const item = this.previewGrid.querySelector(`[data-file-id="${fileId}"]`);
		if (item) {
			item.remove();
		}

		this.updateUI();
		this.refreshCategorySelects();
	}

	updateUI() {
		if (this.selectedFiles.length === 0) {
			this.dropZone.classList.remove('has-files');
			this.previewGrid.style.display = 'none';
			if (this.uploadButton) {
				this.uploadButton.style.display = 'none';
			}
		} else {
			this.dropZone.classList.add('has-files');
			this.previewGrid.style.display = 'grid';
			if (this.uploadButton) {
				this.uploadButton.style.display = 'block';
			}
		}

		this.updateUploadButton();
	}

	updateUploadButton() {
		if (!this.uploadButton) {
			return;
		}

		const allCategoriesAssigned = this.selectedFiles.every((f) => f.category !== '');
		const quotaValid = this.validateQuotas();

		if (allCategoriesAssigned && this.selectedFiles.length > 0 && quotaValid) {
			this.uploadButton.disabled = false;
		} else {
			this.uploadButton.disabled = true;
		}
	}

	validateQuotas() {
		// Count files per category.
		const categoryCount = {};
		this.selectedFiles.forEach((fileData) => {
			if (fileData.category) {
				categoryCount[fileData.category] = (categoryCount[fileData.category] || 0) + 1;
			}
		});

		// Check if any category exceeds quota.
		for (const [category, count] of Object.entries(categoryCount)) {
			const quota = this.quotas[category];
			if (!quota || count > quota.remaining) {
				return false;
			}
		}

		return true;
	}

	async uploadAll() {
		if (this.selectedFiles.length === 0) {
			return;
		}

		// Disable upload button.
		this.uploadButton.disabled = true;
		this.uploadButton.textContent = __('Uploading...', 'photo-competition-manager');

		// Show progress section with progress bar.
		this.progressSection.style.display = 'block';
		const status = document.createElement('p');
		status.textContent = sprintf(
			/* translators: %d: number of images being uploaded. */
			_n('Uploading %d image...', 'Uploading %d images...', this.selectedFiles.length, 'photo-competition-manager'),
			this.selectedFiles.length
		);

		const barContainer = document.createElement('div');
		barContainer.className = 'photo-comp-progress-bar-container';
		const bar = document.createElement('div');
		bar.className = 'photo-comp-progress-bar';
		bar.id = 'upload-progress-bar';
		const barText = document.createElement('div');
		barText.className = 'photo-comp-progress-text';
		barText.id = 'upload-progress-text';
		barText.textContent = '0%';
		barContainer.append(bar, barText);

		this.progressSection.replaceChildren(status, barContainer);

		// One image per request, so no request is bigger than PHP's post_max_size allows.
		const files = this.selectedFiles.slice();
		const results = [];
		const entered = [];

		for (const [index, fileData] of files.entries()) {
			const result = await this.uploadOne(fileData, (sent) => {
				this.updateProgressBar(Math.round(((index + sent) / files.length) * 100));
			});

			// The link or session was refused, so the rest would be too: say why once and stop.
			if (result.refused) {
				results.push({ success: false, error: result.error });
				break;
			}

			if (result.success) {
				entered.push(fileData);
				results.push(result);
			} else {
				results.push({
					success: false,
					error: sprintf(
						/* translators: 1: file name, 2: why the upload failed. */
						__('%1$s: %2$s', 'photo-competition-manager'),
						fileData.file.name,
						result.error
					),
				});
			}

			this.updateProgressBar(Math.round(((index + 1) / files.length) * 100));
		}

		// The images that went in are entries now, so they leave the selection and use up their quota.
		// Upload All can then only send the images that didn't go in.
		entered.forEach((fileData) => {
			this.quotas[fileData.category].remaining -= 1;
			this.removeFile(fileData.id);
		});
		this.uploadButton.textContent = __('Upload All', 'photo-competition-manager');
		this.updateUI();

		this.showResults(results, files.length);
	}

	/**
	 * Upload one image to the batch endpoint.
	 *
	 * A 401, 403 or 404 refuses the whole upload (a bad link, an expired
	 * session, a missing competition), so the result says it's refused.
	 *
	 * @param {Object}   fileData   The selected file and its category.
	 * @param {Function} onProgress Called with the fraction of this image sent so far, 0 to 1.
	 * @return {Promise<Object>} Resolves to { success: true } or { success: false, error, refused }.
	 */
	uploadOne(fileData, onProgress) {
		const formData = new FormData();
		formData.append('file_0', fileData.file);
		formData.append('assignments[file_0]', fileData.category);

		return new Promise((resolve) => {
			const xhr = new XMLHttpRequest();

			xhr.upload.addEventListener('progress', (event) => {
				if (event.lengthComputable && event.total > 0) {
					onProgress(event.loaded / event.total);
				}
			});

			xhr.addEventListener('load', () => {
				let data = null;
				try {
					data = JSON.parse(xhr.responseText);
				} catch (error) {
					// Not JSON: a web server in front of WordPress answered, such as nginx refusing a big body.
				}

				if (xhr.status >= 200 && xhr.status < 300 && data && data.results && data.results.file_0) {
					resolve(data.results.file_0);
					return;
				}

				let error = (data && data.message) || __('Upload failed. Please try again.', 'photo-competition-manager');
				if (xhr.status === 413 && !(data && data.message)) {
					error = __('That image is too big. Check the size limit under the upload form.', 'photo-competition-manager');
				}

				resolve({ success: false, error, refused: [401, 403, 404].includes(xhr.status) });
			});

			xhr.addEventListener('error', () => {
				resolve({
					success: false,
					error: __('Network error. Please check your connection and try again.', 'photo-competition-manager'),
				});
			});

			xhr.addEventListener('abort', () => {
				resolve({ success: false, error: __('Upload cancelled.', 'photo-competition-manager') });
			});

			xhr.open(
				'POST',
				`${this.apiUrl}photo-comp/v1/upload/batch?token=${encodeURIComponent(this.token)}`
			);
			xhr.setRequestHeader('X-WP-Nonce', window.photoCompUpload?.nonce || '');
			xhr.send(formData);
		});
	}

	updateProgressBar(percent) {
		const progressBar = document.getElementById('upload-progress-bar');
		const progressText = document.getElementById('upload-progress-text');

		if (progressBar) {
			progressBar.style.width = `${percent}%`;
		}

		if (progressText) {
			progressText.textContent = `${percent}%`;
		}
	}

	/**
	 * Show how each upload went.
	 *
	 * When every image went in, the page reloads to show the new entries. When
	 * some failed, the failures stay listed with a button to reload, so the
	 * member can see which images to fix first.
	 *
	 * @param {Object[]} results One { success, error } per image sent, or one error for a refused upload.
	 * @param {number}   total   How many images were selected.
	 */
	showResults(results, total) {
		const failures = results.filter((result) => !result.success);
		const successCount = results.length - failures.length;
		const failedCount = total - successCount;

		let message = sprintf(
			/* translators: %d: number of images uploaded. */
			_n('Successfully uploaded %d image.', 'Successfully uploaded %d images.', successCount, 'photo-competition-manager'),
			successCount
		);
		if (failedCount > 0) {
			message += ' ' + sprintf(
				/* translators: %d: number of images that failed to upload. */
				_n('%d upload failed.', '%d uploads failed.', failedCount, 'photo-competition-manager'),
				failedCount
			);
		}

		const summary = document.createElement('p');
		summary.className = 'success';
		summary.textContent = message;
		this.progressSection.replaceChildren(summary);

		if (failures.length > 0) {
			const errorList = document.createElement('ul');
			errorList.className = 'photo-comp-error-list';

			failures.forEach((result) => {
				const li = document.createElement('li');
				li.textContent = result.error;
				errorList.appendChild(li);
			});

			this.progressSection.appendChild(errorList);
		}

		if (successCount === 0) {
			return;
		}

		// Everything went in: reload to show the new entries once the message has been seen.
		if (failedCount === 0) {
			setTimeout(() => window.location.reload(), 2000);
			return;
		}

		// Some failed: keep the list on screen and let the member reload when they've read it.
		const refreshButton = document.createElement('button');
		refreshButton.type = 'button';
		refreshButton.className = 'photo-comp-refresh-btn';
		refreshButton.textContent = __('Show my entries', 'photo-competition-manager');
		refreshButton.addEventListener('click', () => window.location.reload());
		this.progressSection.appendChild(refreshButton);
	}

	showError(message) {
		const errorDiv = document.createElement('div');
		errorDiv.className = 'photo-comp-error-message';
		errorDiv.textContent = message;

		// Added below whatever is there, so a batch's failure list stays on screen.
		if (this.progressSection) {
			this.progressSection.appendChild(errorDiv);
			this.progressSection.style.display = 'block';
		}

		setTimeout(() => {
			errorDiv.remove();
		}, 5000);
	}

	generateFileId() {
		return `file_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
	}

	formatFileSize(bytes) {
		const sizes = [
			__('Bytes', 'photo-competition-manager'),
			__('KB', 'photo-competition-manager'),
			__('MB', 'photo-competition-manager'),
			__('GB', 'photo-competition-manager'),
		];
		const k = 1024;
		const i = bytes === 0 ? 0 : Math.floor(Math.log(bytes) / Math.log(k));
		const size = Math.round(bytes / Math.pow(k, i) * 100) / 100;
		return sprintf(
			/* translators: 1: file size, such as "2.5", 2: unit, such as "MB". */
			__('%1$s %2$s', 'photo-competition-manager'),
			this.formatNumber(size),
			sizes[i]
		);
	}

	/**
	 * Format a number the way the site's language writes it, such as "2,5" on a German site.
	 *
	 * The site's language comes from the page's lang attribute, which WordPress sets.
	 *
	 * @param {number} number The number.
	 * @return {string} The formatted number.
	 */
	formatNumber(number) {
		try {
			return new Intl.NumberFormat(document.documentElement.lang || undefined).format(number);
		} catch (e) {
			// A theme can print a lang attribute Intl doesn't accept.
			return String(number);
		}
	}
}

// Initialize when DOM is ready.
if (typeof window.photoCompUpload !== 'undefined') {
	document.addEventListener('DOMContentLoaded', () => {
		new DragDropUpload(window.photoCompUpload);
	});
} else {
	// Log warning if configuration data is missing.
	console.warn('Photo Competition Manager: Upload configuration data (photoCompUpload) not found. Drag-and-drop upload will not be available.');
}
