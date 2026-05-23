( function () {
	'use strict';

	var config = window.cbbZencoinGlobal || {};
	var selectors = Array.isArray( config.selectors ) ? config.selectors : [];
	var tooltip = 'string' === typeof config.tooltip ? config.tooltip.trim() : '';
	var observer = null;

	function getCoinValue( element ) {
		var valueElement;
		var value = '';

		if ( element.dataset ) {
			value = element.dataset.cbbZencoinValue || element.dataset.zencoinValue || '';
		}

		if ( ! value ) {
			valueElement = element.querySelector( '.zen-coin-global__value, .zen-what-zencoins-coin__value, .zen-zencoins-badge__value, .pfc__zencoin-badge-value, .zen-coin-value' );
			value = valueElement ? valueElement.textContent : element.textContent;
		}

		return value.replace( /\s+/g, ' ' ).trim();
	}

	function applyTooltip( coin, value ) {
		var label = value ? value + ' Zencoins' : 'Zencoins';

		if ( tooltip ) {
			coin.setAttribute( 'data-zencoin-tooltip', tooltip );
			coin.setAttribute( 'title', tooltip );
			label += '. ' + tooltip;
		}

		coin.setAttribute( 'aria-label', label );

		if ( ! coin.hasAttribute( 'tabindex' ) ) {
			coin.setAttribute( 'tabindex', '0' );
		}
	}

	function createCoin( value ) {
		var coin = document.createElement( 'span' );
		var ring = document.createElement( 'span' );
		var text = document.createElement( 'span' );

		coin.className = 'zen-coin-global zen-coin-global--replaced';
		coin.setAttribute( 'data-cbb-zencoin', '1' );
		coin.setAttribute( 'data-zencoin-value', value );

		ring.className = 'zen-coin-global__ring';
		text.className = 'zen-coin-global__value';
		text.textContent = value;

		coin.appendChild( ring );
		coin.appendChild( text );
		applyTooltip( coin, value );

		return coin;
	}

	function enhanceExistingCoin( coin ) {
		var value = getCoinValue( coin );

		if ( coin.dataset && coin.dataset.cbbZencoinEnhanced ) {
			return;
		}

		applyTooltip( coin, value );

		if ( coin.dataset ) {
			coin.dataset.cbbZencoin = '1';
			coin.dataset.cbbZencoinEnhanced = '1';
			coin.dataset.zencoinValue = value;
		}
	}

	function replaceCoinReference( element ) {
		var value;

		if ( element.closest( '.zen-coin-global' ) || ( element.dataset && element.dataset.cbbZencoinEnhanced ) ) {
			return;
		}

		value = getCoinValue( element );

		if ( ! value ) {
			return;
		}

		element.replaceWith( createCoin( value ) );
	}

	function scan( root ) {
		var scope = root && root.querySelectorAll ? root : document;
		var rootElement = scope.nodeType === 1 ? scope : null;

		scope.querySelectorAll( '.zen-coin-global' ).forEach( enhanceExistingCoin );

		if ( rootElement && rootElement.matches( '.zen-coin-global' ) ) {
			enhanceExistingCoin( rootElement );
		}

		selectors.forEach( function ( selector ) {
			if ( rootElement && rootElement.matches( selector ) ) {
				replaceCoinReference( rootElement );
			}

			scope.querySelectorAll( selector ).forEach( replaceCoinReference );
		} );
	}

	function startObserver() {
		if ( observer || ! window.MutationObserver ) {
			return;
		}

		observer = new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType === 1 ) {
						scan( node );
					}
				} );
			} );
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			scan( document );
			startObserver();
		} );
	} else {
		scan( document );
		startObserver();
	}
}() );
