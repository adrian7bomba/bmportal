<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ==========================================================
   TAKSONOMIE DLA DOSTAWCY I OFERTY DOSTAWCY
   (wspólne kategorie / lokalizacje / terminy / budżety)
   ========================================================== */

add_action( 'init', function() {

    $post_types = [ 'dostawca', 'dostawca_oferta' ];

    // Kategorie usług
    register_taxonomy(
        'dostawca_kategoria',
        $post_types,
        [
            'labels' => [
                'name'          => 'Kategorie usług',
                'singular_name' => 'Kategoria usług',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_admin_column' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'kategoria-dostawcy' ],
        ]
    );

    // Lokalizacje
    register_taxonomy(
        'dostawca_lokalizacja',
        $post_types,
        [
            'labels' => [
                'name'          => 'Lokalizacje',
                'singular_name' => 'Lokalizacja',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_admin_column' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'lokalizacja-dostawcy' ],
        ]
    );

    // Terminy (czas realizacji)
    register_taxonomy(
        'dostawca_termin',
        $post_types,
        [
            'labels' => [
                'name'          => 'Terminy realizacji',
                'singular_name' => 'Termin realizacji',
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_admin_column' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'termin-dostawcy' ],
        ]
    );

    // Budżety
    register_taxonomy(
        'dostawca_budzet',
        $post_types,
        [
            'labels' => [
                'name'          => 'Budżety',
                'singular_name' => 'Budżet',
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_admin_column' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'budzet-dostawcy' ],
        ]
    );

}, 5 );
