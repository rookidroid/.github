/**
 * YouTube Product Video — Gallery integration
 *
 * Pauses the YouTube iframe whenever the WooCommerce flexslider navigates
 * away from the video slide, preventing audio continuing in the background.
 */
( function ( $ ) {
    'use strict';

    $( document ).on( 'ready', init );
    $( document ).on( 'wc-product-gallery-after-init', init );

    function init() {
        var $gallery = $( '.woocommerce-product-gallery' );

        if ( ! $gallery.length ) {
            return;
        }

        // Flexslider fires 'before' before each slide change
        $gallery.on( 'before.owl.carousel after.owl.carousel beforeChange', pauseVideo );

        // WooCommerce uses flexslider — hook its callback
        var slider = $gallery.data( 'flexslider' );
        if ( slider && slider.vars ) {
            var origBefore = slider.vars.before || function () {};
            slider.vars.before = function ( s ) {
                pauseVideo();
                origBefore( s );
            };
        }

        // Fallback: pause whenever the active slide changes (MutationObserver)
        if ( window.MutationObserver ) {
            new MutationObserver( pauseVideo ).observe( $gallery[0], {
                attributes: true,
                subtree: true,
                attributeFilter: [ 'class' ],
            } );
        }
    }

    function pauseVideo() {
        $( '.ypv-gallery-slide:not(.flex-active-slide) iframe' ).each( function () {
            // Post a pause command to the YouTube iframe via postMessage
            this.contentWindow.postMessage(
                JSON.stringify( { event: 'command', func: 'pauseVideo', args: [] } ),
                'https://www.youtube-nocookie.com'
            );
        } );
    }
}( jQuery ) );
