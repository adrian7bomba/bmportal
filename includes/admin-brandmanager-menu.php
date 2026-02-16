<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Grupowanie elementów pluginu pod jednym menu "BrandManager" w panelu WP.
 */
add_action('admin_menu', function(){
    add_menu_page(
        'BrandManager',
        'BrandManager',
        'edit_posts',
        'bm-brandmanager',
        'bm_brandmanager_admin_dashboard',
        'dashicons-screenoptions',
        26
    );

    // CPTy są podpinane automatycznie przez show_in_menu => bm-brandmanager,
    // dlatego tutaj dodajemy tylko dodatkowe narzędzia/słowniki.

    // Słowniki DOSTAWCÓW
    add_submenu_page('bm-brandmanager', 'Specjalizacje Dostawców', 'Specjalizacje Dostawców', 'manage_categories', 'edit-tags.php?taxonomy=dostawca_kategoria&post_type=dostawca');
    add_submenu_page('bm-brandmanager', 'Branże Dostawców', 'Branże Dostawców', 'manage_categories', 'edit-tags.php?taxonomy=dostawca_branza&post_type=dostawca');

    // Słowniki OFERT
    add_submenu_page('bm-brandmanager', 'Specjalizacje Ofert', 'Specjalizacje Ofert', 'manage_categories', 'edit-tags.php?taxonomy=oferta_specjalizacja&post_type=dostawca_oferta');
    add_submenu_page('bm-brandmanager', 'Stawki Ofert', 'Stawki Ofert', 'manage_categories', 'edit-tags.php?taxonomy=oferta_budzet&post_type=dostawca_oferta');
    add_submenu_page('bm-brandmanager', 'Terminy Ofert', 'Terminy Ofert', 'manage_categories', 'edit-tags.php?taxonomy=oferta_termin&post_type=dostawca_oferta');
}, 20);

function bm_brandmanager_admin_dashboard(){
    if ( ! current_user_can('edit_posts') ) {
        wp_die('Brak uprawnień.');
    }
    echo '<div class="wrap">';
    echo '<h1>BrandManager</h1>';
    echo '<p>Wszystkie elementy pluginu znajdziesz w menu po lewej stronie, w sekcji <strong>BrandManager</strong>.</p>';
    echo '</div>';
}

add_filter('register_post_type_args', function($args, $post_type){
    $bm_types = [
        'dostawca',
        'dostawca_oferta',
        'zamawiajacy',
        'ogloszenie',
        'bm_deal',
        'bm_review',
        'bm_thread',
        'bm_portfolio',
    ];

    if ( in_array($post_type, $bm_types, true) ) {
        $args['show_in_menu'] = 'bm-brandmanager';
    }

    return $args;
}, 20, 2);
