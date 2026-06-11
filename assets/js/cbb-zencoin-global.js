( function () {
	'use strict';

	var config = window.cbbZencoinGlobal || {};
	var selectors = Array.isArray( config.selectors ) ? config.selectors : [];
	var rangeSelectors = Array.isArray( config.rangeSelectors ) ? config.rangeSelectors : [];
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
			updateTooltipAlignment( coin );
			coin.removeAttribute( 'title' );
			label += '. ' + tooltip;
		}

		coin.setAttribute( 'aria-label', label );

		if ( ! coin.hasAttribute( 'tabindex' ) ) {
			coin.setAttribute( 'tabindex', '0' );
		}
	}

	function createRing() {
		var ring = document.createElement( 'span' );

		ring.className = 'zen-coin-global__ring';

		return ring;
	}

	function createValue( value ) {
		var text = document.createElement( 'span' );

		text.className = 'zen-coin-global__value';
		text.textContent = value;

		return text;
	}

	function createCoin( value ) {
		var coin = document.createElement( 'span' );

		coin.className = 'zen-coin-global zen-coin-global--replaced';
		coin.setAttribute( 'data-cbb-zencoin', '1' );
		coin.setAttribute( 'data-zencoin-value', value );

		coin.appendChild( createRing() );
		coin.appendChild( createValue( value ) );
		applyTooltip( coin, value );

		return coin;
	}

	function getDirectText( element ) {
		var text = '';

		element.childNodes.forEach( function ( node ) {
			if ( node.nodeType === 3 ) {
				text += node.textContent;
			}
		} );

		return text.replace( /\s+/g, ' ' ).trim();
	}

	function getWrapperCoinValue( element ) {
		var valueElement = element.querySelector( ':scope > [data-cbb-zencoin-value], :scope > [data-zencoin-value], :scope > .zen-coin-global, :scope > span, :scope > strong, :scope > b' );

		return valueElement ? getCoinValue( valueElement ) : getCoinValue( element ).replace( getDirectText( element ), '' ).trim();
	}

	function getNumericToken( text ) {
		var matches = String( text || '' ).match( /[-+]?\d+(?:[.,]\d+)?/g );

		return matches ? matches[ matches.length - 1 ] : '';
	}

	function getWrapperParts( element ) {
		var children = Array.from( element.children );
		var labelParts = [];
		var value = '';
		var valueElement = null;
		var directText = getDirectText( element );

		if ( directText ) {
			labelParts.push( directText );
		}

		children.forEach( function ( child ) {
			var childValue = '';

			if ( valueElement ) {
				return;
			}

			if ( child.matches( '[data-cbb-zencoin-value], [data-zencoin-value], .zen-coin-global' ) ) {
				valueElement = child;
				value = getCoinValue( child );
				return;
			}

			childValue = getNumericToken( child.textContent );

			if ( childValue ) {
				valueElement = child;
				value = childValue;
			}
		} );

		if ( ! value ) {
			value = getNumericToken( element.textContent );
		}

		children.forEach( function ( child ) {
			var childText = child.textContent.replace( /\s+/g, ' ' ).trim();

			if ( child === valueElement || ! childText ) {
				return;
			}

			labelParts.push( childText );
		} );

		return {
			label: labelParts.join( ' ' ).replace( /\s+/g, ' ' ).trim(),
			value: value,
		};
	}

	function replaceCoinWrapper( element ) {
		var parts;
		var labelElement;

		if ( element.dataset && element.dataset.cbbZencoinEnhanced ) {
			return;
		}

		parts = getWrapperParts( element );

		if ( ! parts.value ) {
			return;
		}

		element.textContent = '';

		if ( parts.label ) {
			labelElement = document.createElement( 'span' );
			labelElement.className = 'zen-coin-global-label';
			labelElement.textContent = parts.label;
			element.appendChild( labelElement );
		}

		element.appendChild( createCoin( parts.value ) );

		if ( element.dataset ) {
			element.dataset.cbbZencoinEnhanced = '1';
		}
	}

	function isLabelValueCoinWrapper( element ) {
		return element.classList && (
			element.classList.contains( 'zbp-coins' ) ||
			element.classList.contains( 'zbp-join-zencoins' )
		);
	}

	function enhanceExistingCoin( coin ) {
		var value = getCoinValue( coin );
		var hasStructure = coin.querySelector( ':scope > .zen-coin-global__ring' ) && coin.querySelector( ':scope > .zen-coin-global__value' );

		if ( coin.dataset && coin.dataset.cbbZencoinEnhanced && hasStructure ) {
			return;
		}

		coin.textContent = '';
		coin.appendChild( createRing() );
		coin.appendChild( createValue( value ) );
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

		if ( isLabelValueCoinWrapper( element ) ) {
			replaceCoinWrapper( element );
			return;
		}

		value = getCoinValue( element );

		if ( ! value ) {
			return;
		}

		element.replaceWith( createCoin( value ) );
	}

	function markCoinRange( element ) {
		if ( ! element.dataset ) {
			return;
		}

		element.dataset.cbbZencoinRange = '1';
	}

	function updateTooltipAlignment( coin ) {
		var tooltipWidth = 263;
		var viewportPadding = 16;
		var rect;
		var center;

		if ( ! coin || ! coin.getBoundingClientRect || ! coin.hasAttribute( 'data-zencoin-tooltip' ) ) {
			return;
		}

		rect = coin.getBoundingClientRect();
		center = rect.left + ( rect.width / 2 );

		if ( center - ( tooltipWidth / 2 ) < viewportPadding ) {
			coin.setAttribute( 'data-zencoin-tooltip-align', 'left' );
			return;
		}

		if ( center + ( tooltipWidth / 2 ) > window.innerWidth - viewportPadding ) {
			coin.setAttribute( 'data-zencoin-tooltip-align', 'right' );
			return;
		}

		coin.setAttribute( 'data-zencoin-tooltip-align', 'center' );
	}

	function scan( root ) {
		var scope = root && root.querySelectorAll ? root : document;
		var rootElement = scope.nodeType === 1 ? scope : null;

		rangeSelectors.forEach( function ( selector ) {
			if ( rootElement && rootElement.matches( selector ) ) {
				markCoinRange( rootElement );
			}

			scope.querySelectorAll( selector ).forEach( markCoinRange );
		} );

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

		scope.querySelectorAll( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' ).forEach( updateTooltipAlignment );
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

	function bindTooltipEvents() {
		document.addEventListener( 'mouseover', function ( event ) {
			var coin = event.target.closest && event.target.closest( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' );

			if ( coin ) {
				updateTooltipAlignment( coin );
			}
		} );

		document.addEventListener( 'focusin', function ( event ) {
			var coin = event.target.closest && event.target.closest( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' );

			if ( coin ) {
				updateTooltipAlignment( coin );
			}
		} );

		window.addEventListener( 'scroll', function () {
			document.querySelectorAll( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' ).forEach( updateTooltipAlignment );
		}, true );

		window.addEventListener( 'resize', function () {
			document.querySelectorAll( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' ).forEach( updateTooltipAlignment );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			scan( document );
			startObserver();
			bindTooltipEvents();
		} );
	} else {
		scan( document );
		startObserver();
		bindTooltipEvents();
	}
}() );
