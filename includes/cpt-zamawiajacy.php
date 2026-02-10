<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ==========================================================
   CPT "Zamawiający" – wizytówki zamawiających
   ========================================================== */

add_action('init', function() {

    register_post_type('zamawiajacy', [
        'labels' => [
            'name'               => 'Zamawiający',
            'singular_name'      => 'Zamawiający',
            'add_new'            => 'Dodaj nowego',
            'add_new_item'       => 'Dodaj nowego zamawiającego',
            'edit_item'          => 'Edytuj zamawiającego',
            'new_item'           => 'Nowy zamawiający',
            'view_item'          => 'Zobacz zamawiającego',
            'search_items'       => 'Szukaj zamawiających',
            'not_found'          => 'Nie znaleziono zamawiających',
            'not_found_in_trash' => 'Brak zamawiających w koszu',
            'all_items'          => 'Wszyscy zamawiający'
        ],
        'public'              => true,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-admin-users',
        'query_var'           => true,
        'rewrite'             => ['slug' => 'zamawiajacy'],
        'capability_type'     => 'post',
        'has_archive'         => true,
        'hierarchical'        => false,
        'menu_position'       => 6,
        'show_in_rest'        => true,
        'supports'            => ['title', 'editor', 'thumbnail', 'author'],
    ]);

});

/* Tylko zaakceptowane wizytówki na froncie */

add_action('pre_get_posts', function($query){

    if (is_admin() || ! $query->is_main_query()) {
        return;
    }

    // Archiwum zamawiających
    if ($query->is_post_type_archive('zamawiajacy')) {
        $query->set('post_status', 'publish');
    }

    // Pojedyncza wizytówka
    if ($query->is_singular('zamawiajacy')) {
        $query->set('post_status', 'publish');
    }

});
