/**
 * Subscriptions Health Check — floating tooltip for the warning icons.
 *
 * Renders a single tooltip element appended to <body> and reuses it across
 * every `.woocommerce-subscriptions-health-check-warning` icon on the
 * Health Check Status tab. Keeping the bubble outside the candidates table
 * wrapper avoids the wrapper's `overflow-x: auto` clipping the tooltip on
 * first-row icons (and similar edges) — the wrapper inherits a non-`visible`
 * `overflow-y` per the CSS spec, so a CSS-only `::after` bubble would clip
 * any time the bubble extended past the wrapper bounds.
 *
 * Positioning is recomputed on each show: the tooltip is placed above the
 * icon, horizontally centred, and clamped to stay inside the viewport.
 * Coordinates are absolute (anchored to the document) so the tooltip stays
 * with the icon during page scroll without listening to scroll events.
 */
( function () {
	'use strict';

	var ICON_SELECTOR    = '.woocommerce-subscriptions-health-check-warning';
	var TOOLTIP_CLASS    = 'woocommerce-subscriptions-health-check-tooltip';
	var TOOLTIP_OFFSET   = 6;
	var VIEWPORT_PADDING = 8;

	var tooltip   = null;
	var activeEl  = null;

	function ensureTooltip() {
		if ( tooltip ) {
			return tooltip;
		}
		tooltip = document.createElement( 'div' );
		tooltip.className = TOOLTIP_CLASS;
		tooltip.setAttribute( 'role', 'tooltip' );
		tooltip.setAttribute( 'aria-hidden', 'true' );
		document.body.appendChild( tooltip );
		return tooltip;
	}

	function show( icon ) {
		var text = icon.getAttribute( 'data-tooltip' ) || '';
		if ( '' === text ) {
			return;
		}

		var el = ensureTooltip();
		el.textContent = text;
		el.classList.add( 'is-visible' );

		// Reset the flip state before measuring so the height we read
		// matches the about-to-be-applied placement.
		el.classList.remove( 'is-below' );

		var iconRect    = icon.getBoundingClientRect();
		var tooltipRect = el.getBoundingClientRect();
		var scrollX     = window.pageXOffset || document.documentElement.scrollLeft;
		var scrollY     = window.pageYOffset || document.documentElement.scrollTop;

		// Default placement: above the icon, horizontally centred on it.
		var top  = iconRect.top + scrollY - tooltipRect.height - TOOLTIP_OFFSET;
		var left = iconRect.left + scrollX + ( iconRect.width / 2 ) - ( tooltipRect.width / 2 );

		// Edge-clamp horizontally so the bubble stays inside the viewport.
		var minLeft = scrollX + VIEWPORT_PADDING;
		var maxLeft = scrollX + document.documentElement.clientWidth - tooltipRect.width - VIEWPORT_PADDING;
		if ( left < minLeft ) {
			left = minLeft;
		}
		if ( left > maxLeft ) {
			left = maxLeft;
		}

		// Flip below the icon if the bubble would land above the
		// visible area. Mirror that on the bubble itself so the CSS
		// arrow rule swaps the triangle to the top edge.
		var minTop = scrollY + VIEWPORT_PADDING;
		if ( top < minTop ) {
			top = iconRect.bottom + scrollY + TOOLTIP_OFFSET;
			el.classList.add( 'is-below' );
		}

		// Anchor the triangle to the icon's centre, not the bubble's
		// centre — when the bubble is edge-clamped horizontally the two
		// don't line up and a centred arrow would float away from the
		// icon. Computing the offset relative to the bubble's left
		// edge keeps the arrow pointing at the icon regardless.
		var iconCenterX  = iconRect.left + scrollX + ( iconRect.width / 2 );
		var arrowOffset  = iconCenterX - left;
		el.style.setProperty( '--arrow-left', arrowOffset + 'px' );

		el.style.top  = top + 'px';
		el.style.left = left + 'px';
		activeEl      = icon;
	}

	function hide( icon ) {
		if ( ! tooltip ) {
			return;
		}
		if ( icon && icon !== activeEl ) {
			return;
		}
		tooltip.classList.remove( 'is-visible' );
		tooltip.style.top  = '';
		tooltip.style.left = '';
		activeEl           = null;
	}

	function handleEnter( event ) {
		var icon = event.target && event.target.closest ? event.target.closest( ICON_SELECTOR ) : null;
		if ( ! icon ) {
			return;
		}
		show( icon );
	}

	function handleLeave( event ) {
		var icon = event.target && event.target.closest ? event.target.closest( ICON_SELECTOR ) : null;
		if ( ! icon ) {
			return;
		}
		hide( icon );
	}

	function init() {
		document.addEventListener( 'mouseover', handleEnter );
		document.addEventListener( 'mouseout', handleLeave );
		document.addEventListener( 'focusin', handleEnter );
		document.addEventListener( 'focusout', handleLeave );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
