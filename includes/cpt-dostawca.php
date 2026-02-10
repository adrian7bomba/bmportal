<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ==========================================================
   CPT DOSTAWCA
   + dodatkowy status "rejected"
   + auto-tworzenie wpisu po rejestracji usera z rolą dostawca
   ========================================================== */

add_action('init', function() {

    register_post_type('dostawca', [
        'labels' => [
            'name'          => 'Dostawcy',
            'singular_name' => 'Dostawca',
        ],
        'public'        => true,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'menu_icon'     => 'dashicons-businessman',
        'supports'      => ['title','editor','thumbnail','author'],
        'has_archive'   => true,
        'rewrite'       => ['slug' => 'dostawca', 'with_front' => false],
        'show_in_rest'  => true,
    ]);

    register_post_status('rejected', [
        'label'                     => 'Odrzucone',
        'public'                    => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
    ]);

}, 5);

add_action('user_register', function($user_id){
    $u = get_userdata($user_id);
    if ($u && in_array('dostawca',(array)$u->roles, true)) {
        bm_get_or_create_supplier_post($user_id);
    }
});

/* CPT OFERTY DOSTAWCY */

add_action('init', function(){
    register_post_type('dostawca_oferta', [
        'labels' => [
            'name'          => 'Oferty dostawców',
            'singular_name' => 'Oferta dostawcy',
            'add_new'       => 'Dodaj ofertę',
            'add_new_item'  => 'Dodaj nową ofertę',
            'edit_item'     => 'Edytuj ofertę',
            'new_item'      => 'Nowa oferta',
            'view_item'     => 'Zobacz ofertę',
            'search_items'  => 'Szukaj ofert',
            'not_found'     => 'Nie znaleziono ofert',
        ],
        'public'        => true,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'menu_position' => 6,
        'menu_icon'     => 'dashicons-index-card',
        'supports'      => ['title','editor','thumbnail','author'],
        'show_in_rest'  => true,
        'has_archive'   => true,
        'rewrite'       => ['slug'=>'oferta-dostawcy'],
    ]);
}, 9);

/* Powiązanie istniejących taksonomii z ofertą (gdy są zarejestrowane) */
add_action('init', function(){
    if (taxonomy_exists('dostawca_kategoria'))   register_taxonomy_for_object_type('dostawca_kategoria','dostawca_oferta');
    if (taxonomy_exists('dostawca_lokalizacja')) register_taxonomy_for_object_type('dostawca_lokalizacja','dostawca_oferta');
    if (taxonomy_exists('dostawca_termin'))      register_taxonomy_for_object_type('dostawca_termin','dostawca_oferta');
    if (taxonomy_exists('dostawca_budzet'))      register_taxonomy_for_object_type('dostawca_budzet','dostawca_oferta');
}, 20);
