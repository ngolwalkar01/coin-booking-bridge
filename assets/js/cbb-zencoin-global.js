( function () {
	'use strict';

	var config = window.cbbZencoinGlobal || {};
	var selectors = Array.isArray( config.selectors ) ? config.selectors : [];
	var rangeSelectors = Array.isArray( config.rangeSelectors ) ? config.rangeSelectors : [];
	var tooltip = 'string' === typeof config.tooltip ? config.tooltip.trim() : '';
	var observer = null;
	var tooltipElement = null;
	var activeTooltipCoin = null;

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

	function getTooltipElement() {
		if ( tooltipElement ) {
			return tooltipElement;
		}

		tooltipElement = document.createElement( 'div' );
		tooltipElement.className = 'cbb-zencoin-tooltip';
		tooltipElement.setAttribute( 'role', 'tooltip' );
		document.body.appendChild( tooltipElement );

		return tooltipElement;
	}

	function positionTooltip( coin, tooltipBox ) {
		var coinRect = coin.getBoundingClientRect();
		var tooltipRect = tooltipBox.getBoundingClientRect();
		var viewportPadding = 16;
		var left = coinRect.left + ( coinRect.width / 2 ) - ( tooltipRect.width / 2 );
		var top = coinRect.bottom + 16;

		left = Math.max( viewportPadding, Math.min( left, window.innerWidth - tooltipRect.width - viewportPadding ) );

		if ( top + tooltipRect.height + viewportPadding > window.innerHeight ) {
			top = Math.max( viewportPadding, coinRect.top - tooltipRect.height - 16 );
			tooltipBox.classList.add( 'is-above' );
		} else {
			tooltipBox.classList.remove( 'is-above' );
		}

		tooltipBox.style.left = left + 'px';
		tooltipBox.style.top = top + 'px';
		tooltipBox.style.setProperty( '--cbb-zencoin-tooltip-arrow-left', ( coinRect.left + ( coinRect.width / 2 ) - left ) + 'px' );
	}

	function showTooltip( coin ) {
		var text = coin.getAttribute( 'data-zencoin-tooltip' );
		var tooltipBox;

		if ( ! text ) {
			return;
		}

		tooltipBox = getTooltipElement();
		activeTooltipCoin = coin;
		tooltipBox.textContent = text;
		tooltipBox.classList.add( 'is-active' );
		positionTooltip( coin, tooltipBox );
	}

	function hideTooltip( coin ) {
		if ( coin && activeTooltipCoin && coin !== activeTooltipCoin ) {
			return;
		}

		if ( tooltipElement ) {
			tooltipElement.classList.remove( 'is-active' );
		}

		activeTooltipCoin = null;
	}

	function bindTooltipEvents() {
		document.addEventListener( 'mouseover', function ( event ) {
			var coin = event.target.closest && event.target.closest( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' );

			if ( coin ) {
				showTooltip( coin );
			}
		} );

		document.addEventListener( 'mouseout', function ( event ) {
			var coin = event.target.closest && event.target.closest( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' );

			if ( coin && ! coin.contains( event.relatedTarget ) ) {
				hideTooltip( coin );
			}
		} );

		document.addEventListener( 'focusin', function ( event ) {
			var coin = event.target.closest && event.target.closest( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' );

			if ( coin ) {
				showTooltip( coin );
			}
		} );

		document.addEventListener( 'focusout', function ( event ) {
			var coin = event.target.closest && event.target.closest( '.zen-coin-global[data-zencoin-tooltip]:not([data-zencoin-tooltip=""])' );

			if ( coin ) {
				hideTooltip( coin );
			}
		} );

		window.addEventListener( 'scroll', function () {
			if ( activeTooltipCoin && tooltipElement && tooltipElement.classList.contains( 'is-active' ) ) {
				positionTooltip( activeTooltipCoin, tooltipElement );
			}
		}, true );

		window.addEventListener( 'resize', function () {
			if ( activeTooltipCoin && tooltipElement && tooltipElement.classList.contains( 'is-active' ) ) {
				positionTooltip( activeTooltipCoin, tooltipElement );
			}
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
