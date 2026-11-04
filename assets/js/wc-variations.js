/**
 * EAN & ISBN Barcodes – show the barcode of the selected variation.
 * Variations without their own GTIN show the product's barcode (like WooCommerce does with the SKU).
 */
( function ( $ ) {
	'use strict';

	function show( id ) {
		$( '[data-eanbc-wc]' ).each( function () {
			var items = $( this ).children( '[data-eanbc-variation]' );
			var match = items.filter( '[data-eanbc-variation="' + id + '"]' );
			if ( ! match.length ) {
				match = items.filter( '[data-eanbc-variation="0"]' );
			}
			items.prop( 'hidden', true );
			match.prop( 'hidden', false );
		} );
	}

	$( function () {
		$( document.body )
			.on( 'found_variation', 'form.variations_form', function ( event, variation ) {
				show( variation && variation.variation_id ? variation.variation_id : 0 );
			} )
			.on( 'reset_data hide_variation', 'form.variations_form', function () {
				show( 0 );
			} );
	} );
}( jQuery ) );
