/**
 * Tests for the drag-and-drop uploader on the upload page.
 */

/**
 * A stand-in for XMLHttpRequest that records each request and lets the test answer it.
 */
class FakeXhr {
	constructor() {
		this.listeners = {};
		this.upload = {
			listeners: {},
			addEventListener( type, listener ) {
				this.listeners[ type ] = listener;
			},
		};
		FakeXhr.requests.push( this );
	}

	addEventListener( type, listener ) {
		this.listeners[ type ] = listener;
	}

	open( method, url ) {
		this.url = url;
	}

	setRequestHeader() {}

	send( body ) {
		this.body = body;
	}

	respond( status, data ) {
		this.respondWithText( status, JSON.stringify( data ) );
	}

	respondWithText( status, text ) {
		this.status = status;
		this.responseText = text;
		this.listeners.load();
	}

	fail() {
		this.listeners.error();
	}

	sendProgress( loaded, total ) {
		this.upload.listeners.progress( { lengthComputable: true, loaded, total } );
	}
}

const uploaded = { results: { file_0: { success: true, image_id: 1 } }, success_count: 1, error_count: 0, total: 1 };

function renderPage() {
	document.body.innerHTML = `
		<div class="photo-comp-drag-drop-zone"></div>
		<input type="file" id="batch-file-input" multiple />
		<div class="photo-comp-preview-grid"></div>
		<button type="button" class="photo-comp-upload-all-btn">Upload All</button>
		<div class="photo-comp-upload-progress"></div>`;
}

window.photoCompUpload = {
	token: 'abc',
	apiUrl: 'https://example.com/wp-json/',
	nonce: 'n',
	categories: [ { slug: 'colour', label: 'Colour', quota: 3 } ],
	quotas: { colour: { current: 0, quota: 3, remaining: 3 } },
	maxFileSize: 5 * 1024 * 1024,
	allowedFormats: [ 'jpg', 'jpeg' ],
};

// The script starts an uploader on each DOMContentLoaded, so it's loaded once and each test fires the event.
require( '../../assets/src/js/drag-drop-upload' );

async function selectFiles( names ) {
	const input = document.querySelector( '#batch-file-input' );
	const files = names.map( ( name ) => new File( [ 'jpeg' ], name, { type: 'image/jpeg' } ) );
	Object.defineProperty( input, 'files', { value: files, configurable: true } );
	input.dispatchEvent( new Event( 'change' ) );

	// Each preview is read in the background, and the button waits until each one's category is assigned.
	const button = document.querySelector( '.photo-comp-upload-all-btn' );
	for ( let i = 0; i < 50 && button.disabled; i++ ) {
		await settle();
	}
	expect( button.disabled ).toBe( false );
}

/**
 * Let the uploader move on to its next request.
 */
function settle() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'drag-and-drop upload', () => {
	beforeEach( () => {
		FakeXhr.requests = [];
		window.XMLHttpRequest = FakeXhr;
		renderPage();
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	} );

	it( 'sends each image in a request of its own', async () => {
		await selectFiles( [ 'one.jpg', 'two.jpg' ] );

		document.querySelector( '.photo-comp-upload-all-btn' ).click();
		await settle();

		expect( FakeXhr.requests ).toHaveLength( 1 );
		const first = FakeXhr.requests[ 0 ];
		expect( first.url ).toBe( 'https://example.com/wp-json/photo-comp/v1/upload/batch?token=abc' );
		expect( first.body.get( 'file_0' ).name ).toBe( 'one.jpg' );
		expect( first.body.get( 'assignments[file_0]' ) ).toBe( 'colour' );
		expect( first.body.get( 'file_1' ) ).toBeNull();

		first.respond( 200, uploaded );
		await settle();

		expect( FakeXhr.requests ).toHaveLength( 2 );
		expect( FakeXhr.requests[ 1 ].body.get( 'file_0' ).name ).toBe( 'two.jpg' );
	} );

	it( 'shows the successes and failures of all the requests together', async () => {
		await selectFiles( [ 'one.jpg', 'two.jpg', 'three.jpg' ] );

		document.querySelector( '.photo-comp-upload-all-btn' ).click();
		await settle();
		FakeXhr.requests[ 0 ].respond( 200, uploaded );
		await settle();
		FakeXhr.requests[ 1 ].respond( 413, { code: 'file_too_large', message: 'That image is too big. Check the size limit under the upload form.' } );
		await settle();
		FakeXhr.requests[ 2 ].respond( 200, { results: { file_0: { success: true, image_id: 3 } }, success_count: 1, error_count: 0, total: 1 } );
		await settle();

		const progress = document.querySelector( '.photo-comp-upload-progress' );
		expect( progress.querySelector( '.success' ).textContent ).toBe( 'Successfully uploaded 2 image(s). 1 upload(s) failed.' );
		expect( Array.from( progress.querySelectorAll( '.photo-comp-error-list li' ), ( li ) => li.textContent ) ).toEqual( [
			'two.jpg: That image is too big. Check the size limit under the upload form.',
		] );
	} );

	it( 'moves the progress bar while an image is being sent', async () => {
		await selectFiles( [ 'one.jpg', 'two.jpg' ] );

		document.querySelector( '.photo-comp-upload-all-btn' ).click();
		await settle();
		FakeXhr.requests[ 0 ].sendProgress( 50, 100 );

		expect( document.querySelector( '#upload-progress-text' ).textContent ).toBe( '25%' );

		FakeXhr.requests[ 0 ].respond( 200, uploaded );
		await settle();
		FakeXhr.requests[ 1 ].sendProgress( 50, 100 );

		expect( document.querySelector( '#upload-progress-text' ).textContent ).toBe( '75%' );
	} );

	it( 'carries on to the next image after a network error', async () => {
		await selectFiles( [ 'one.jpg', 'two.jpg' ] );

		document.querySelector( '.photo-comp-upload-all-btn' ).click();
		await settle();
		FakeXhr.requests[ 0 ].fail();
		await settle();

		expect( FakeXhr.requests ).toHaveLength( 2 );
		FakeXhr.requests[ 1 ].respond( 200, uploaded );
		await settle();

		const progress = document.querySelector( '.photo-comp-upload-progress' );
		expect( progress.querySelector( '.success' ).textContent ).toBe( 'Successfully uploaded 1 image(s). 1 upload(s) failed.' );
		expect( Array.from( progress.querySelectorAll( '.photo-comp-error-list li' ), ( li ) => li.textContent ) ).toEqual( [
			'one.jpg: Network error. Please check your connection and try again.',
		] );
	} );

	it( 'stops and says why once when the upload link is refused', async () => {
		await selectFiles( [ 'one.jpg', 'two.jpg' ] );

		document.querySelector( '.photo-comp-upload-all-btn' ).click();
		await settle();
		FakeXhr.requests[ 0 ].respond( 401, { code: 'invalid_token', message: 'Invalid or expired upload token.' } );
		await settle();

		expect( FakeXhr.requests ).toHaveLength( 1 );
		const progress = document.querySelector( '.photo-comp-upload-progress' );
		expect( progress.querySelector( '.success' ).textContent ).toBe( 'Successfully uploaded 0 image(s). 2 upload(s) failed.' );
		expect( Array.from( progress.querySelectorAll( '.photo-comp-error-list li' ), ( li ) => li.textContent ) ).toEqual( [
			'Invalid or expired upload token.',
		] );
	} );

	it( 'says the image is too big when the web server refuses it before WordPress', async () => {
		await selectFiles( [ 'one.jpg' ] );

		document.querySelector( '.photo-comp-upload-all-btn' ).click();
		await settle();
		FakeXhr.requests[ 0 ].respondWithText( 413, '<html><body>413 Request Entity Too Large</body></html>' );
		await settle();

		const progress = document.querySelector( '.photo-comp-upload-progress' );
		expect( Array.from( progress.querySelectorAll( '.photo-comp-error-list li' ), ( li ) => li.textContent ) ).toEqual( [
			'one.jpg: That image is too big. Check the size limit under the upload form.',
		] );
	} );
} );
