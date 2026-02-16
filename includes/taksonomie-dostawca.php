<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ==========================================================
   TAKSONOMIE DLA DOSTAWCY I OFERTY DOSTAWCY
   (wspólne kategorie / lokalizacje / terminy / budżety)
   ========================================================== */

add_action( 'init', function() {

    $supplier_post_types = [ 'dostawca' ];
    $offer_post_types    = [ 'dostawca_oferta' ];

    // Kategorie usług
    register_taxonomy(
        'dostawca_kategoria',
        $supplier_post_types,
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


    // Doświadczenie w branżach
    register_taxonomy(
        'dostawca_branza',
        $supplier_post_types,
        [
            'labels' => [
                'name'          => 'Doświadczenie w branżach',
                'singular_name' => 'Branża',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_admin_column' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'branza-dostawcy' ],
        ]
    );

    // Lokalizacje
    register_taxonomy(
        'dostawca_lokalizacja',
        $supplier_post_types,
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
        $supplier_post_types,
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
        $supplier_post_types,
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

    // OFERTY: kategorie
    register_taxonomy(
        'oferta_kategoria',
        $offer_post_types,
        [
            'labels' => [
                'name'          => 'Kategorie Ofert',
                'singular_name' => 'Kategoria Oferty',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_admin_column' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'kategoria-oferty' ],
        ]
    );

    // OFERTY: terminy
    register_taxonomy(
        'oferta_termin',
        $offer_post_types,
        [
            'labels' => [
                'name'          => 'Terminy Ofert',
                'singular_name' => 'Termin Oferty',
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_admin_column' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'termin-oferty' ],
        ]
    );

    // OFERTY: budżety/stawki
    register_taxonomy(
        'oferta_budzet',
        $offer_post_types,
        [
            'labels' => [
                'name'          => 'Budżety Ofert',
                'singular_name' => 'Budżet Oferty',
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_admin_column' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'budzet-oferty' ],
        ]
    );

}, 5 );
