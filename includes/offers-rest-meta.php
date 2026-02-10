<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ==========================================================
   UDOSTĘPNIENIE PÓL ACF OFERTY W REST API (Filter Everything)
   ========================================================== */

add_action('init', function() {

    $fields = [
        'oferta_stawka',
        'oferta_tryb',
        'oferta_miasto',
        'oferta_termin',
        'oferta_kategoria',
        'oferta_budzet',
        // gdy dodasz nowe pola ACF OFERTY → dopisz jedną linijkę tu
    ];

    foreach ($fields as $field) {
        register_post_meta('dostawca_oferta', $field, [
            'show_in_rest' => true,
            'single'       => true,
            'type'         => 'string',
        ]);
    }

}, 20);
