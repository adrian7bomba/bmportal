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

    // Słowniki do konfiguracji stawek/terminów (taksonomie)
    add_submenu_page('bm-brandmanager', 'Stawki (Budżety)', 'Stawki (Budżety)', 'manage_categories', 'edit-tags.php?taxonomy=dostawca_budzet&post_type=dostawca');
    add_submenu_page('bm-brandmanager', 'Terminy realizacji', 'Terminy realizacji', 'manage_categories', 'edit-tags.php?taxonomy=dostawca_termin&post_type=dostawca');
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
