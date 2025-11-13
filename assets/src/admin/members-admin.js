/**
 * Members admin page functionality.
 *
 * @package PhotoCompetitionManager
 */

document.addEventListener('DOMContentLoaded', function() {
	// Select all functionality
	const selectAll = document.getElementById('cb-select-all-1');
	if (selectAll) {
		selectAll.addEventListener('change', function() {
			const checkboxes = document.querySelectorAll('input[name="member_ids[]"]');
			checkboxes.forEach(function(checkbox) {
				checkbox.checked = selectAll.checked;
			});
		});
	}

	// Show/hide grade selector based on bulk action
	const bulkAction = document.getElementById('bulk-action-selector-top');
	const gradeSelector = document.getElementById('bulk-grade-selector');
	if (bulkAction && gradeSelector) {
		bulkAction.addEventListener('change', function() {
			if (bulkAction.value === 'bulk_update_grade') {
				gradeSelector.style.display = 'inline-block';
			} else {
				gradeSelector.style.display = 'none';
			}
		});
	}

	// Form validation
	const bulkForm = document.getElementById('bulk-members-form');
	if (bulkForm) {
		bulkForm.addEventListener('submit', function(e) {
			const action = document.getElementById('bulk-action-selector-top').value;
			if (action === '-1') {
				e.preventDefault();
				alert(photoCompMembersAdmin.selectBulkAction);
				return false;
			}
			const checked = document.querySelectorAll('input[name="member_ids[]"]:checked');
			if (checked.length === 0) {
				e.preventDefault();
				alert(photoCompMembersAdmin.selectOneMember);
				return false;
			}
			if (action === 'bulk_update_grade') {
				const grade = document.getElementById('bulk-grade-selector').value;
				if (!grade) {
					e.preventDefault();
					alert(photoCompMembersAdmin.selectGrade);
					return false;
				}
			}
		});
	}

	// Delete member confirmation
	const deleteLinks = document.querySelectorAll('.delete-member-link');
	deleteLinks.forEach(function(link) {
		link.addEventListener('click', function(e) {
			const memberName = link.getAttribute('data-member-name');
			const message = photoCompMembersAdmin.confirmDelete + '\n\n' +
				photoCompMembersAdmin.memberLabel + ' ' + memberName + '\n\n' +
				photoCompMembersAdmin.cannotUndo;
			if (!confirm(message)) {
				e.preventDefault();
				return false;
			}
		});
	});
});
