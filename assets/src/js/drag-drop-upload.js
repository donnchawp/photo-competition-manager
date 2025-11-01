/**
 * Drag and drop bulk upload functionality.
 *
 * @package PhotoCompetitionManager
 */

class DragDropUpload {
	constructor(config) {
		this.token = config.token;
		this.apiUrl = config.apiUrl;
		this.categories = config.categories;
		this.quotas = config.quotas;
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
			this.showError('No valid image files selected.');
			return;
		}

		// Calculate total available quota across all categories.
		const totalAvailableQuota = this.getTotalAvailableQuota();
		const currentFileCount = this.selectedFiles.length;
		const availableSlots = totalAvailableQuota - currentFileCount;

		if (availableSlots <= 0) {
			this.showError('All category quotas are full. Cannot add more files.');
			return;
		}

		// Limit files to available quota slots.
		const filesToAdd = validFiles.slice(0, availableSlots);
		const rejectedCount = validFiles.length - filesToAdd.length;

		if (rejectedCount > 0) {
			this.showError(
				`Only ${filesToAdd.length} file(s) added. ${rejectedCount} file(s) rejected due to quota limits.`
			);
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
		// Always include the file's current category (if assigned) even if exhausted,
		// so users can change their selection.
		const availableCategories = this.getAvailableCategories();

		// If this file has a category assigned, make sure it's included.
		if (fileData.category) {
			const hasCurrentCategory = availableCategories.some((cat) => cat.slug === fileData.category);
			if (!hasCurrentCategory) {
				const currentCategory = this.categories.find((cat) => cat.slug === fileData.category);
				if (currentCategory) {
					availableCategories.push(currentCategory);
				}
			}
		}

		return availableCategories;
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
				`${file.name} is too large. Maximum file size is ${this.formatFileSize(this.maxFileSize)}.`
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
			const effectiveRemaining = this.getEffectiveRemainingQuota(cat.slug);

			// Auto-assign the category.
			fileData.category = cat.slug;

			// Display as text instead of dropdown.
			const categoryLabel = document.createElement('div');
			categoryLabel.className = 'photo-comp-category-label';

			// Only show remaining count if quota is more than 1.
			const quota = this.quotas[cat.slug];
			const remainingText = quota.quota > 1 ? ` <small>(${effectiveRemaining} remaining)</small>` : '';
			categoryLabel.innerHTML = `<strong>Category:</strong> ${cat.label}${remainingText}`;
			container.appendChild(categoryLabel);

			// Trigger update since we auto-assigned.
			this.updateUploadButton();
			this.updateQuotaWarning();

			return container;
		}

		// Multiple categories available - show dropdown.
		const select = document.createElement('select');
		select.className = 'photo-comp-category-select';
		select.dataset.fileId = fileData.id;

		const defaultOption = document.createElement('option');
		defaultOption.value = '';
		defaultOption.textContent = '-- Select Category --';
		select.appendChild(defaultOption);

		availableCategories.forEach((cat) => {
			const effectiveRemaining = this.getEffectiveRemainingQuota(cat.slug);
			const option = document.createElement('option');
			option.value = cat.slug;
			// Only show remaining count if quota is more than 1.
			const quota = this.quotas[cat.slug];
			const remainingText = quota.quota > 1 ? ` (${effectiveRemaining} remaining)` : '';
			option.textContent = `${cat.label}${remainingText}`;
			select.appendChild(option);
		});

		select.addEventListener('change', (e) => {
			fileData.category = e.target.value;
			this.updateUploadButton();
			this.updateQuotaWarning();
			this.refreshCategorySelects();
		});

		container.appendChild(select);
		return container;
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

			// If only one category available now, switch to label.
			if (availableCategories.length === 1) {
				const cat = availableCategories[0];
				const effectiveRemaining = this.getEffectiveRemainingQuota(cat.slug);

				// Auto-assign if not already assigned.
				if (fileData.category === '') {
					fileData.category = cat.slug;
				}

				// Replace with label.
				const categoryLabel = document.createElement('div');
				categoryLabel.className = 'photo-comp-category-label';
				const quota = this.quotas[cat.slug];
				const remainingText = quota.quota > 1 ? ` <small>(${effectiveRemaining} remaining)</small>` : '';
				categoryLabel.innerHTML = `<strong>Category:</strong> ${cat.label}${remainingText}`;

				const newContainer = document.createElement('div');
				newContainer.className = 'photo-comp-category-select-container';
				newContainer.appendChild(categoryLabel);

				existingContainer.replaceWith(newContainer);
			} else if (availableCategories.length > 1) {
				// Multiple categories - ensure dropdown is up to date.
				const existingSelect = existingContainer.querySelector('select');

				// If it's currently a label, recreate as dropdown.
				if (!existingSelect) {
					const newContainer = this.createCategorySelect(fileData);
					existingContainer.replaceWith(newContainer);
					// Set the value if there was a previous assignment.
					if (fileData.category) {
						const newSelect = newContainer.querySelector('select');
						if (newSelect) {
							newSelect.value = fileData.category;
						}
					}
				} else {
					// Update existing dropdown options.
					const currentValue = fileData.category;
					existingSelect.innerHTML = '';

					const defaultOption = document.createElement('option');
					defaultOption.value = '';
					defaultOption.textContent = '-- Select Category --';
					existingSelect.appendChild(defaultOption);

					availableCategories.forEach((cat) => {
						const effectiveRemaining = this.getEffectiveRemainingQuota(cat.slug);
						const option = document.createElement('option');
						option.value = cat.slug;
						const quota = this.quotas[cat.slug];
						const remainingText = quota.quota > 1 ? ` (${effectiveRemaining} remaining)` : '';
						option.textContent = `${cat.label}${remainingText}`;
						existingSelect.appendChild(option);
					});

					existingSelect.value = currentValue;
				}
			}
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
			warning.textContent = 'Warning: You have assigned more images to a category than your remaining quota allows. Please adjust your selections.';
			this.previewGrid.parentNode.insertBefore(warning, this.previewGrid);
		}
	}

	createRemoveButton(fileData) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'photo-comp-remove-btn';
		button.textContent = 'Remove';
		button.setAttribute('aria-label', `Remove ${fileData.file.name}`);

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
		this.uploadButton.textContent = 'Uploading...';

		// Show progress section.
		this.progressSection.style.display = 'block';
		this.progressSection.innerHTML = '<p>Uploading images...</p>';

		try {
			const formData = new FormData();
			const assignments = {};

			this.selectedFiles.forEach((fileData, index) => {
				const fileKey = `file_${index}`;
				formData.append(fileKey, fileData.file);
				assignments[fileKey] = fileData.category;
			});

			// Send assignments as individual form fields instead of JSON.
			Object.keys(assignments).forEach((key) => {
				formData.append(`assignments[${key}]`, assignments[key]);
			});

			const response = await fetch(
				`${this.apiUrl}photo-comp/v1/upload/batch?token=${encodeURIComponent(this.token)}`,
				{
					method: 'POST',
					body: formData,
					headers: {
						'X-WP-Nonce': window.photoCompUpload?.nonce || '',
					},
				}
			);

			const data = await response.json();

			if (response.ok) {
				this.handleUploadSuccess(data);
			} else {
				this.handleUploadError(data);
			}
		} catch (error) {
			this.showError('Upload failed. Please try again.');
			console.error('Upload error:', error);
		} finally {
			this.uploadButton.disabled = false;
			this.uploadButton.textContent = 'Upload All';
		}
	}

	handleUploadSuccess(data) {
		const successCount = data.success_count || 0;
		const errorCount = data.error_count || 0;

		let message = `Successfully uploaded ${successCount} image(s).`;
		if (errorCount > 0) {
			message += ` ${errorCount} upload(s) failed.`;
		}

		this.progressSection.innerHTML = `<p class="success">${message}</p>`;

		// Show individual results.
		if (data.results && errorCount > 0) {
			const errorList = document.createElement('ul');
			errorList.className = 'photo-comp-error-list';

			Object.keys(data.results).forEach((fileKey) => {
				const result = data.results[fileKey];
				if (!result.success) {
					const li = document.createElement('li');
					li.textContent = result.error;
					errorList.appendChild(li);
				}
			});

			if (errorList.children.length > 0) {
				this.progressSection.appendChild(errorList);
			}
		}

		// Clear successful uploads.
		if (successCount > 0) {
			setTimeout(() => {
				// Reload page to show updated submissions.
				window.location.reload();
			}, 2000);
		}
	}

	handleUploadError(data) {
		const message = data.message || 'Upload failed. Please try again.';
		this.showError(message);
	}

	showError(message) {
		const errorDiv = document.createElement('div');
		errorDiv.className = 'photo-comp-error-message';
		errorDiv.textContent = message;

		if (this.progressSection) {
			this.progressSection.innerHTML = '';
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
		if (bytes === 0) return '0 Bytes';
		const k = 1024;
		const sizes = ['Bytes', 'KB', 'MB', 'GB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
	}
}

// Initialize when DOM is ready.
if (typeof window.photoCompUpload !== 'undefined') {
	document.addEventListener('DOMContentLoaded', () => {
		new DragDropUpload(window.photoCompUpload);
	});
}
