/**
 * wallee Express Checkout: asks the add-on's REST endpoint for a session token
 * and hands it to wallee's handler script.
 */
( function () {
	'use strict';

	const config = window.walleeEc;
	if ( ! config || ! config.endpoint ) {
		return;
	}

	const DEBOUNCE_MS = 400;
	const context = config.context;
	const containerId = 'wallee-ec-container-' + context;

	// wallee sessions die 30 minutes after creation. The server says how
	// many seconds are left (expiresIn, by its own clock); the page counts
	// that down from the moment it sent the request and renews 15 minutes
	// before the end, since a wallet flow was observed to take 11 minutes.
	// The browser's clock is never compared with the server's. Without a
	// usable expiresIn it renews 15 minutes after the request. The delay is
	// kept between 1 and 15 minutes, so it can neither loop nor wait too long.
	const MINUTE_MS = 60 * 1000;
	const REFRESH_LEAD_MS = 15 * MINUTE_MS;
	const REFRESH_MAX_MS = 15 * MINUTE_MS;
	const REFRESH_MIN_MS = MINUTE_MS;
	const MAX_QUANTITY = 999;

	// Message types the wallee widget posts to the page around its wallet
	// overlay (docs/reference/spike-findings.md item 2; the handler script
	// reads them from event.data.type and only trusts the widget's origin).
	const OVERLAY_OPEN = 'wallee-express-checkout-overlay-open';
	const OVERLAY_CLOSE = 'wallee-express-checkout-overlay-close';

	let controller = null;
	let sequence = 0;
	let refreshTimer = null;
	let refreshStart = null;
	let refreshAfter = 0;
	let refreshDue = false;
	let overlayOpen = false;
	let refresh = null;

	function findRoot() {
		return document.querySelector( '.wallee-ec[data-context="' + context + '"]' );
	}

	function setHidden( hidden ) {
		const root = findRoot();
		if ( root ) {
			root.hidden = hidden;
		}
	}

	function emptyContainer() {
		const container = document.getElementById( containerId );
		if ( container ) {
			container.textContent = '';
		}
	}

	function debounce( fn, wait ) {
		let timer = null;
		return function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( fn, wait );
		};
	}

	function monotonicNow() {
		return window.performance && typeof window.performance.now === 'function' ? window.performance.now() : NaN;
	}

	/**
	 * A point in time to measure elapsed time from.
	 *
	 * @return {{mono: number, wall: number}} Monotonic and wall-clock readings.
	 */
	function startClock() {
		return { mono: monotonicNow(), wall: Date.now() };
	}

	/**
	 * Milliseconds since a startClock() reading. The monotonic clock
	 * (performance.now) is the measure; the wall clock only counts when it
	 * shows more time passed (some browsers stop the monotonic clock while
	 * the computer sleeps). So a wrong or adjusted browser clock can make a
	 * renewal come earlier, never later.
	 *
	 * @param {{mono: number, wall: number}} start Reading.
	 * @return {number} Elapsed milliseconds, 0 or more.
	 */
	function elapsedSince( start ) {
		const mono = monotonicNow() - start.mono;
		const wall = Date.now() - start.wall;
		return Math.max( 0, isFinite( mono ) ? mono : 0, isFinite( wall ) ? wall : 0 );
	}

	function clearRefresh() {
		window.clearTimeout( refreshTimer );
		refreshTimer = null;
		refreshStart = null;
		refreshAfter = 0;
		refreshDue = false;
	}

	function cancelPending() {
		clearRefresh();
		sequence++;
		if ( controller ) {
			controller.abort();
			controller = null;
		}
	}

	function showError() {
		clearRefresh();
		setHidden( true );
	}

	/**
	 * Clears a widget that no longer matches the page, before the debounce
	 * fires, so nobody can pay for the old content.
	 */
	function invalidate() {
		cancelPending();
		emptyContainer();
	}

	/**
	 * Milliseconds until the session should be renewed.
	 *
	 * @param {*}      expiresIn Seconds the session had left when the server answered, may be missing.
	 * @param {number} elapsed   Milliseconds since the request was sent.
	 * @return {number} Delay.
	 */
	function refreshDelay( expiresIn, elapsed ) {
		const seconds = typeof expiresIn === 'number' ? expiresIn : NaN;
		if ( ! isFinite( seconds ) || seconds < 0 ) {
			return REFRESH_MAX_MS;
		}
		return Math.min( REFRESH_MAX_MS, Math.max( REFRESH_MIN_MS, seconds * 1000 - REFRESH_LEAD_MS - elapsed ) );
	}

	/**
	 * Renews the session unless a wallet overlay is open; then it waits for
	 * the overlay to close, so an open wallet sheet is never removed.
	 */
	function runRefresh() {
		if ( overlayOpen ) {
			refreshDue = true;
			return;
		}
		if ( refresh ) {
			refresh();
		}
	}

	/**
	 * The wallet overlay closed or the customer cancelled the wallet: a
	 * renewal that came due while it was open runs now.
	 */
	function overlayClosed() {
		overlayOpen = false;
		if ( refreshDue ) {
			refreshDue = false;
			runRefresh();
		}
	}

	/**
	 * @param {*}                            expiresIn Seconds left, from the server.
	 * @param {{mono: number, wall: number}} requested Reading taken when the request was sent.
	 */
	function scheduleRefresh( expiresIn, requested ) {
		clearRefresh();
		const delay = refreshDelay( expiresIn, elapsedSince( requested ) );
		refreshStart = startClock();
		refreshAfter = delay;
		refreshTimer = window.setTimeout( runRefresh, delay );
	}

	/**
	 * Reads an overlay message of the wallee widget. Adjust here if wallee
	 * changes the message format.
	 *
	 * @param {MessageEvent} event Message.
	 * @return {?boolean} True for "opened", false for "closed", null for anything else.
	 */
	function overlayChange( event ) {
		if ( ! config.origin || ! event || event.origin !== config.origin ) {
			return null;
		}
		const type = event.data && typeof event.data.type === 'string' ? event.data.type : '';
		if ( type === OVERLAY_OPEN ) {
			return true;
		}
		if ( type === OVERLAY_CLOSE ) {
			return false;
		}
		return null;
	}

	function render( token, expiresIn, requested ) {
		const handler = window.ExpressCheckoutHandler;
		if ( ! handler || typeof handler.load !== 'function' ) {
			showError();
			return;
		}
		emptyContainer();
		setHidden( false );
		const options = {
			maxColumns: Number( config.maxColumns ) || 2,
			onError: function () {
				showError();
			},
			// The customer closed a wallet sheet: keep the buttons, and run a
			// renewal that was postponed while the sheet was open.
			onCancel: function () {
				overlayClosed();
			},
		};
		if ( config.maxRows !== undefined && Number( config.maxRows ) > 0 ) {
			options.maxRows = Number( config.maxRows );
		}
		try {
			Promise.resolve( handler.load( containerId, token, options ) ).catch( showError );
			scheduleRefresh( expiresIn, requested );
		} catch ( e ) {
			showError();
		}
	}

	/**
	 * Requests a session token. A newer call aborts the one in flight and
	 * makes its response stale.
	 *
	 * @param {string} requestContext "cart" or "product".
	 * @param {Object} params         Extra body fields (product context).
	 */
	async function requestSession( requestContext, params ) {
		cancelPending();
		emptyContainer();
		const mine = sequence;
		const requested = startClock();
		controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;

		try {
			const response = await window.fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.restNonce,
					'X-Wallee-Ec-Nonce': config.nonce,
				},
				body: JSON.stringify( Object.assign( { context: requestContext }, params || {} ) ),
				signal: controller ? controller.signal : undefined,
			} );
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			const data = await response.json();
			if ( mine !== sequence ) {
				return;
			}
			if ( data && typeof data.sessionToken === 'string' && data.sessionToken !== '' ) {
				render( data.sessionToken, data.expiresIn, requested );
			} else {
				showError();
			}
		} catch ( e ) {
			if ( mine === sequence && ! ( e && e.name === 'AbortError' ) ) {
				showError();
			}
		}
	}

	/**
	 * Calls fn once the container exists (the block cart renders late).
	 *
	 * @param {Function} fn Callback.
	 */
	function whenRootExists( fn ) {
		if ( findRoot() ) {
			fn();
			return;
		}
		if ( typeof window.MutationObserver !== 'function' ) {
			return;
		}
		const observer = new window.MutationObserver( function () {
			if ( findRoot() ) {
				observer.disconnect();
				fn();
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setTimeout( function () {
			observer.disconnect();
		}, 15000 );
	}

	function cartSignature( store ) {
		const data = store.getCartData();
		const items = ( data.items || [] ).map( function ( item ) {
			return [ item.key, item.quantity ];
		} );
		return JSON.stringify( [ data.totals && data.totals.total_price, items ] );
	}

	function initCart() {
		const request = function () {
			requestSession( 'cart' );
		};
		const schedule = debounce( request, DEBOUNCE_MS );
		const debounced = function () {
			invalidate();
			schedule();
		};
		refresh = request;

		request();

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_cart_totals', debounced );
		}

		if ( window.wp && window.wp.data && document.querySelector( '.wp-block-woocommerce-cart' ) ) {
			try {
				const store = window.wp.data.select( 'wc/store/cart' );
				let last = cartSignature( store );
				window.wp.data.subscribe( function () {
					let current;
					try {
						current = cartSignature( window.wp.data.select( 'wc/store/cart' ) );
					} catch ( e ) {
						return;
					}
					if ( current !== last ) {
						last = current;
						debounced();
					}
				} );
			} catch ( e ) {
				// No cart store: the first request stands.
			}
		}
	}

	function initProduct() {
		const root = findRoot();
		const form = root ? root.closest( 'form.cart' ) : null;
		if ( ! root || ! form ) {
			showError();
			return;
		}

		const type = root.getAttribute( 'data-product-type' );
		const productId = parseInt( root.getAttribute( 'data-product-id' ), 10 ) || 0;
		if ( productId < 1 ) {
			showError();
			return;
		}
		const variable = type === 'variable';
		let selected = null;

		if ( variable ) {
			setHidden( true );
			if ( ! window.jQuery ) {
				return;
			}
		}

		const quantity = function () {
			const input = form.querySelector( 'input.qty' ) || form.querySelector( 'input[name="quantity"]' );
			const value = input ? parseInt( input.value, 10 ) : 1;
			return value > 0 ? value : 1;
		};

		// Attribute choices come from the form: "Any ..." variations report ''.
		const attributesFor = function ( variation ) {
			const attributes = Object.assign( {}, variation.attributes || {} );
			form.querySelectorAll( '[name^="attribute_"]' ).forEach( function ( field ) {
				if ( ( field.type === 'radio' || field.type === 'checkbox' ) && ! field.checked ) {
					return;
				}
				if ( field.value !== '' ) {
					attributes[ field.name ] = field.value;
				}
			} );
			return attributes;
		};

		const request = function () {
			const amount = quantity();
			if ( amount > MAX_QUANTITY ) {
				showError();
				return;
			}
			const params = { productId: productId, quantity: amount };
			if ( variable ) {
				if ( ! selected ) {
					return;
				}
				params.variationId = selected.variationId;
				params.variation = selected.attributes;
			}
			requestSession( 'product', params );
		};
		refresh = request;
		const schedule = debounce( request, DEBOUNCE_MS );
		const debounced = function () {
			if ( variable && ! selected ) {
				return;
			}
			invalidate();
			schedule();
		};

		// The wallet buttons must never submit the add-to-cart form.
		form.addEventListener( 'submit', function ( event ) {
			if ( event.submitter && event.submitter.closest( '.wallee-ec' ) ) {
				event.preventDefault();
			}
		} );

		form.addEventListener( 'change', function ( event ) {
			const target = event.target;
			if ( target && target.matches && target.matches( 'input.qty, input[name="quantity"]' ) ) {
				debounced();
			}
		} );

		if ( variable ) {
			const $form = window.jQuery( form );
			$form.on( 'found_variation', function ( event, variation ) {
				if ( ! variation || ! variation.variation_id ) {
					return;
				}
				selected = { variationId: variation.variation_id, attributes: attributesFor( variation ) };
				request();
			} );
			const clear = function () {
				selected = null;
				cancelPending();
				emptyContainer();
				setHidden( true );
			};
			$form.on( 'reset_data hide_variation', clear );
			// Quantity changes made through jQuery do not reach native listeners.
			$form.on( 'change', 'input.qty, input[name="quantity"]', debounced );
		} else {
			if ( window.jQuery ) {
				// Theme +/- buttons trigger jQuery events that native listeners miss.
				window.jQuery( form ).on( 'change', 'input.qty, input[name="quantity"]', debounced );
			}
			request();
		}
	}

	function init() {
		// Timers are throttled in background tabs: catch up when visible again.
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'visible' && refreshStart && elapsedSince( refreshStart ) >= refreshAfter ) {
				runRefresh();
			}
		} );
		window.addEventListener( 'message', function ( event ) {
			const open = overlayChange( event );
			if ( open === null ) {
				return;
			}
			if ( open ) {
				overlayOpen = true;
			} else {
				overlayClosed();
			}
		} );
		whenRootExists( function () {
			if ( ! window.ExpressCheckoutHandler ) {
				setHidden( true );
				return;
			}
			if ( context === 'product' ) {
				initProduct();
			} else {
				initCart();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
