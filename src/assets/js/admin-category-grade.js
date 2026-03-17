/**
 * Admin category/grade row management and progress meter preview animations.
 *
 * Shared by competitions and settings pages.
 *
 * Expects `photoCompCategoryGrade` to be localized with:
 *   - labelText, slugText, uploadQuotaText, removeText
 *
 * @package PhotoCompetitionManager
 */

/* global photoCompCategoryGrade */

document.addEventListener( 'DOMContentLoaded', function () {
	( function () {
		var i18n = window.photoCompCategoryGrade || {};
		var categoryIndex = document.querySelectorAll( '.category-row' ).length;
		var gradeIndex = document.querySelectorAll( '.grade-row' ).length;

		var addCategoryBtn = document.getElementById( 'add-category' );
		if ( addCategoryBtn ) {
			addCategoryBtn.addEventListener( 'click', function () {
				var container = document.getElementById( 'categories-container' );
				var row = document.createElement( 'div' );
				row.className = 'category-row';
				row.style.cssText =
					'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;';
				row.innerHTML =
					'<p style="margin: 5px 0;">' +
					'<label>' +
					( i18n.labelText || 'Label' ) +
					'</label><br />' +
					'<input type="text" name="categories[' +
					categoryIndex +
					'][label]" class="regular-text" required />' +
					'</p>' +
					'<p style="margin: 5px 0;">' +
					'<label>' +
					( i18n.slugText || 'Slug' ) +
					'</label><br />' +
					'<input type="text" name="categories[' +
					categoryIndex +
					'][slug]" class="regular-text" required />' +
					'</p>' +
					'<p style="margin: 5px 0;">' +
					'<label>' +
					( i18n.uploadQuotaText || 'Upload Quota' ) +
					'</label><br />' +
					'<input type="number" name="categories[' +
					categoryIndex +
					'][quota]" value="1" min="1" max="10" class="small-text" required />' +
					'</p>' +
					'<button type="button" class="button remove-category" style="color: #b32d2e;">' +
					( i18n.removeText || 'Remove' ) +
					'</button>';
				container.appendChild( row );
				categoryIndex++;
			} );
		}

		var addGradeBtn = document.getElementById( 'add-grade' );
		if ( addGradeBtn ) {
			addGradeBtn.addEventListener( 'click', function () {
				var container = document.getElementById( 'grades-container' );
				var row = document.createElement( 'div' );
				row.className = 'grade-row';
				row.style.cssText =
					'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;';
				row.innerHTML =
					'<p style="margin: 5px 0;">' +
					'<label>' +
					( i18n.labelText || 'Label' ) +
					'</label><br />' +
					'<input type="text" name="grades[' +
					gradeIndex +
					'][label]" class="regular-text" required />' +
					'</p>' +
					'<button type="button" class="button remove-grade" style="color: #b32d2e;">' +
					( i18n.removeText || 'Remove' ) +
					'</button>';
				container.appendChild( row );
				gradeIndex++;
			} );
		}

		document.addEventListener( 'click', function ( e ) {
			if ( e.target.classList.contains( 'remove-category' ) ) {
				e.target.closest( '.category-row' ).remove();
			}
			if ( e.target.classList.contains( 'remove-grade' ) ) {
				e.target.closest( '.grade-row' ).remove();
			}
		} );

		// Progress meter preview animations.
		document
			.querySelectorAll( '.progress-meter-card' )
			.forEach( function ( card ) {
				card.addEventListener( 'click', function () {
					document
						.querySelectorAll( '.progress-meter-card' )
						.forEach( function ( c ) {
							c.classList.remove( 'active' );
							c.style.borderColor = '#ddd';
						} );
					card.classList.add( 'active' );
					card.style.borderColor = '#0073aa';
				} );
			} );

		function renderMeterPreview( container, type, progress ) {
			if ( ! container._initialized ) {
				container._initialized = true;
				container.innerHTML = '';

				if ( type === 'bar' ) {
					container.style.display = 'flex';
					container.style.alignItems = 'flex-end';
					var barTrack = document.createElement( 'div' );
					barTrack.style.cssText =
						'width:100%;height:8px;background:rgba(255,255,255,0.2);border-radius:0;';
					var barFill = document.createElement( 'div' );
					barFill.style.cssText =
						'height:100%;background:#0073aa;transition:width 100ms linear;border-radius:0;';
					barFill.className = 'meter-fill';
					barTrack.appendChild( barFill );
					container.appendChild( barTrack );
				} else if ( type === 'line' ) {
					container.style.display = 'flex';
					container.style.alignItems = 'flex-end';
					var lineTrack = document.createElement( 'div' );
					lineTrack.style.cssText =
						'width:100%;height:3px;background:rgba(255,255,255,0.1);';
					var lineFill = document.createElement( 'div' );
					lineFill.style.cssText =
						'height:100%;background:#fff;box-shadow:0 0 8px rgba(255,255,255,0.6);transition:width 100ms linear;';
					lineFill.className = 'meter-fill';
					lineTrack.appendChild( lineFill );
					container.appendChild( lineTrack );
				} else if ( type === 'dots' ) {
					container.style.display = 'flex';
					container.style.alignItems = 'flex-end';
					container.style.justifyContent = 'center';
					container.style.gap = '4px';
					container.style.paddingBottom = '4px';
					for ( var i = 0; i < 15; i++ ) {
						var dot = document.createElement( 'div' );
						dot.style.cssText =
							'width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,0.2);transition:background 0.2s,transform 0.2s;';
						dot.className = 'meter-dot';
						container.appendChild( dot );
					}
				} else if ( type === 'radial' ) {
					container.style.display = 'flex';
					container.style.alignItems = 'center';
					container.style.justifyContent = 'center';
					var svg = document.createElementNS(
						'http://www.w3.org/2000/svg',
						'svg'
					);
					svg.setAttribute( 'width', '40' );
					svg.setAttribute( 'height', '40' );
					svg.setAttribute( 'viewBox', '0 0 40 40' );
					var bgCircle = document.createElementNS(
						'http://www.w3.org/2000/svg',
						'circle'
					);
					bgCircle.setAttribute( 'cx', '20' );
					bgCircle.setAttribute( 'cy', '20' );
					bgCircle.setAttribute( 'r', '16' );
					bgCircle.setAttribute( 'fill', 'none' );
					bgCircle.setAttribute(
						'stroke',
						'rgba(255,255,255,0.2)'
					);
					bgCircle.setAttribute( 'stroke-width', '3' );
					svg.appendChild( bgCircle );
					var circle = document.createElementNS(
						'http://www.w3.org/2000/svg',
						'circle'
					);
					circle.setAttribute( 'cx', '20' );
					circle.setAttribute( 'cy', '20' );
					circle.setAttribute( 'r', '16' );
					circle.setAttribute( 'fill', 'none' );
					circle.setAttribute( 'stroke', '#0073aa' );
					circle.setAttribute( 'stroke-width', '3' );
					circle.setAttribute( 'stroke-linecap', 'round' );
					circle.setAttribute(
						'transform',
						'rotate(-90 20 20)'
					);
					var circumference = 2 * Math.PI * 16;
					circle.setAttribute(
						'stroke-dasharray',
						circumference
					);
					circle.setAttribute(
						'stroke-dashoffset',
						circumference
					);
					circle.className.baseVal = 'meter-ring';
					svg.appendChild( circle );
					container.appendChild( svg );
				}
			}

			if ( type === 'bar' || type === 'line' ) {
				var fill = container.querySelector( '.meter-fill' );
				if ( fill ) {
					fill.style.width = progress * 100 + '%';
				}
			} else if ( type === 'dots' ) {
				var dots = container.querySelectorAll( '.meter-dot' );
				var filledCount = Math.floor( progress * dots.length );
				dots.forEach( function ( dotEl, idx ) {
					if ( idx < filledCount ) {
						dotEl.style.background = '#0073aa';
						dotEl.style.transform = 'scale(1.3)';
					} else if ( idx === filledCount ) {
						dotEl.style.background = 'rgba(0,115,170,0.5)';
						dotEl.style.transform = 'scale(1.1)';
					} else {
						dotEl.style.background =
							'rgba(255,255,255,0.2)';
						dotEl.style.transform = 'scale(1)';
					}
				} );
			} else if ( type === 'radial' ) {
				var ring = container.querySelector( '.meter-ring' );
				if ( ring ) {
					var circ = 2 * Math.PI * 16;
					ring.setAttribute(
						'stroke-dashoffset',
						circ * ( 1 - progress )
					);
				}
			}
		}

		function animatePreviews() {
			var duration = 3000;
			var startTime = Date.now();

			function tick() {
				var elapsed = Date.now() - startTime;
				var progress = ( elapsed % duration ) / duration;

				document
					.querySelectorAll( '.meter-preview' )
					.forEach( function ( preview ) {
						var type = preview.dataset.meterType;
						renderMeterPreview( preview, type, progress );
					} );

				requestAnimationFrame( tick );
			}

			tick();
		}

		animatePreviews();
	} )();
} );
