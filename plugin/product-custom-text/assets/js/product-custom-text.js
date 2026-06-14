/**
 * Product Custom Text for WooCommerce – Front-end JS
 * Live character counter with visual feedback.
 */
( function ( $ ) {
    'use strict';

    $( function () {
        var $input   = $( '#pct_custom_text' );
        var $counter = $( '#pct-counter' );
        var $used    = $( '#pct-chars-used' );

        if ( ! $input.length ) {
            return;
        }

        var maxLen = parseInt( $input.attr( 'maxlength' ), 10 ) || 200;

        function updateCounter() {
            var len = $input.val().length;
            $used.text( len );

            $counter
                .removeClass( 'pct-limit-near pct-limit-reached' );

            if ( len >= maxLen ) {
                $counter.addClass( 'pct-limit-reached' );
            } else if ( len >= Math.floor( maxLen * 0.85 ) ) {
                $counter.addClass( 'pct-limit-near' );
            }
        }

        // Initialize & bind
        updateCounter();
        $input.on( 'input', updateCounter );

        // Validate before add-to-cart if required
        $( 'form.cart' ).on( 'submit', function ( e ) {
            var isRequired = $input.prop( 'required' );
            if ( isRequired && $input.val().trim() === '' ) {
                e.preventDefault();
                $input.addClass( 'pct-error' );
                $input.focus();

                // WooCommerce notices container
                var $notices = $( '.woocommerce-notices-wrapper' );
                if ( $notices.length ) {
                    $notices.html(
                        '<ul class="woocommerce-error" role="alert">' +
                        '<li>' + pct_params.required_msg + '</li>' +
                        '</ul>'
                    );
                    $( 'html, body' ).animate( { scrollTop: $notices.offset().top - 80 }, 300 );
                }
            }
        } );

        $input.on( 'input', function () {
            $( this ).removeClass( 'pct-error' );
        } );
    } );
} )( jQuery );
