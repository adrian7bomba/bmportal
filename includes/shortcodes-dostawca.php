<?php
/**
 * Shortcodes dostawcy – scalone i uporządkowane
 * - [dane_dostawcy]
 * - [dostawca_pole]
 * - [dostawca_obrazek]
 * - [oferty_dostawcy]
 * - [wszystkie_oferty_dostawcy]
 */

/* ==========================================================
   1️⃣ Ustal ID dostawcy na podstawie kontekstu
   ========================================================== */

if ( ! function_exists( 'bm_get_current_supplier_post_id' ) ) {
    function bm_get_current_supplier_post_id() {

        global $post;

        // 1) Jeśli mamy globalny post (loop Elementora)
        if ( $post instanceof WP_Post ) {
            $post_id   = $post->ID;
            $post_type = get_post_type( $post_id );
        } else {
            // 2) Fallback – główny obiekt zapytania
            $post_id   = get_queried_object_id();
            $post_type = $post_id ? get_post_type( $post_id ) : '';
        }

        // Jesteśmy na wizytówce dostawcy
        if ( $post_id && $post_type === 'dostawca' ) {
            return (int) $post_id;
        }

        // Jesteśmy na ofercie → powiązany dostawca z ACF
        if ( $post_id && $post_type === 'dostawca_oferta' && function_exists( 'get_field' ) ) {
            $supplier = get_field( 'powiazany_dostawca', $post_id );

            if ( is_object( $supplier ) && isset( $supplier->ID ) ) return (int) $supplier->ID;
            if ( is_array( $supplier ) && isset( $supplier['ID'] ) ) return (int) $supplier['ID'];
            if ( is_numeric( $supplier ) ) return (int) $supplier;
        }

        // Jeśli zalogowany user jest dostawcą – zwróć jego CPT
        if ( is_user_logged_in() && function_exists( 'bm_get_or_create_supplier_post' ) ) {
            $user = wp_get_current_user();
            if ( in_array( 'dostawca', (array) $user->roles, true ) ) {
                return (int) bm_get_or_create_supplier_post( $user->ID );
            }
        }

        return 0;
    }
}

/* ==========================================================
   2️⃣ SHORTCODE: [dostawca_pole]
   ========================================================== */

add_shortcode( 'dostawca_pole', function( $atts ) {

    $atts = shortcode_atts([
        'klucz' => '',
        'sep'   => ', ',
    ], $atts );

    $key = trim( $atts['klucz'] );
    if ( ! $key ) return '';

    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';

    // Taksonomie dostawcy
    $taxonomy_map = [
        'lokalizacja_dostawca' => 'dostawca_lokalizacja',
        'budzet_dostawca'      => 'dostawca_budzet',
        'dostawca_budzety'     => 'dostawca_budzet',
        'termin_dostawca'      => 'dostawca_termin',
        'dostawca_terminy'     => 'dostawca_termin',
    ];

    if ( isset( $taxonomy_map[ $key ] ) ) {
        $terms = wp_get_post_terms( $supplier_id, $taxonomy_map[ $key ] );
        if ( is_wp_error($terms) || empty($terms) ) return '';
        return esc_html( implode( $atts['sep'], wp_list_pluck( $terms, 'name' ) ) );
    }

    // Zwykłe pola ACF
    $value = function_exists('get_field')
        ? get_field( $key, $supplier_id )
        : get_post_meta( $supplier_id, $key, true );

    if ( empty( $value ) ) return '';

    if ( is_array($value) ) {
        if ( isset( $value['url'] ) ) return esc_url( $value['url'] ); // obrazek
        return esc_html( implode( $atts['sep'], array_map( 'sanitize_text_field', $value ) ) );
    }

    return esc_html( $value );
});



/* ==========================================================
   2b️⃣ DEDYKOWANE SHORTCODY PÓL REJESTRACYJNYCH/WIZYTÓWKI
   ========================================================== */

if ( ! function_exists( 'bm_get_supplier_field_value' ) ) {
    function bm_get_supplier_field_value( $supplier_id, $key ) {
        return function_exists('get_field')
            ? get_field( $key, $supplier_id )
            : get_post_meta( $supplier_id, $key, true );
    }
}

$bm_registration_shortcodes = [
    'bm_status_vat'            => 'vat_status_dostawca',
    'bm_nazwa_firmy'           => 'nazwa_dostawca',
    'bm_nip_firmy'             => 'nip_dostawca',
    'bm_regon_firmy'           => 'regon_dostawca',
    'bm_krs_firmy'             => 'krs_dostawca',
    'bm_forma_prawna'          => 'forma_prawna_dostawca',
    'bm_ulica_firmy'           => 'ulica_dostawca',
    'bm_kod_pocztowy_firmy'    => 'kod_pocztowy_dostawca',
    'bm_miejscowosc_firmy'     => 'miejscowosc_dostawca',
    'bm_www_firmy'             => 'www_dostawca',
    'bm_slogan_firmy'          => 'slogan_dostawca',
    'bm_realizujemy_na_cito'   => 'bm_cito_now',
    'bm_opis_firmy'            => 'opis_dostawca',
    'bm_wielkosc_firmy'        => 'wielkosc_firmy_dostawca',
    'bm_opiekun_nazwa'         => 'opiekun_imie_nazwisko',
    'bm_opiekun_stanowisko'    => 'opiekun_stanowisko',
    'bm_opiekun_email'         => 'opiekun_email',
    'bm_opiekun_telefon'       => 'opiekun_telefon',
    'bm_nagradzani_opis'       => 'nagradzani_opis',
];

foreach ( $bm_registration_shortcodes as $shortcode => $meta_key ) {
    add_shortcode( $shortcode, function() use ( $meta_key ) {
        $supplier_id = bm_get_current_supplier_post_id();
        if ( ! $supplier_id ) return '';

        $value = bm_get_supplier_field_value( $supplier_id, $meta_key );
        if ( empty( $value ) ) return '';

        if ( $meta_key === 'opis_dostawca' || $meta_key === 'nagradzani_opis' ) {
            return wp_kses_post( wpautop( (string) $value ) );
        }

        if ( $meta_key === 'bm_cito_now' ) {
            return ((int) $value === 1) ? 'Tak' : 'Nie';
        }

        return esc_html( is_scalar( $value ) ? (string) $value : '' );
    } );
}

add_shortcode( 'bm_specjalizacje', function( $atts ) {
    $atts = shortcode_atts(['sep' => ', '], $atts );
    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';
    $terms = wp_get_post_terms( $supplier_id, 'dostawca_kategoria', ['fields' => 'names'] );
    if ( is_wp_error($terms) || empty($terms) ) return '';
    return esc_html( implode( $atts['sep'], $terms ) );
} );

add_shortcode( 'bm_branze', function( $atts ) {
    $atts = shortcode_atts(['sep' => ', '], $atts );
    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';
    $terms = wp_get_post_terms( $supplier_id, 'dostawca_branza', ['fields' => 'names'] );
    if ( is_wp_error($terms) || empty($terms) ) return '';
    return esc_html( implode( $atts['sep'], $terms ) );
} );

add_shortcode( 'bm_lokalizacje', function( $atts ) {
    $atts = shortcode_atts(['sep' => ', '], $atts );
    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';
    $terms = wp_get_post_terms( $supplier_id, 'dostawca_lokalizacja', ['fields' => 'names'] );
    if ( is_wp_error($terms) || empty($terms) ) return '';
    return esc_html( implode( $atts['sep'], $terms ) );
} );

add_shortcode( 'bm_termin_realizacji', function() {
    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';
    $terms = wp_get_post_terms( $supplier_id, 'dostawca_termin', ['fields' => 'names'] );
    if ( is_wp_error($terms) || empty($terms) ) return '';
    return esc_html( $terms[0] );
} );

add_shortcode( 'bm_budzet', function() {
    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';
    $terms = wp_get_post_terms( $supplier_id, 'dostawca_budzet', ['fields' => 'names'] );
    if ( is_wp_error($terms) || empty($terms) ) return '';
    return esc_html( $terms[0] );
} );

add_shortcode( 'bm_konto_typ', function() {
    if ( ! is_user_logged_in() || ! function_exists('bm_get_account_plan_data') ) return '';
    $plan = bm_get_account_plan_data( get_current_user_id() );
    return esc_html( $plan['label'] );
} );

add_shortcode( 'bm_dane_wykonawcy_status', function() {
    if ( ! function_exists('bm_get_or_create_supplier_post') ) return '';
    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';
    $status = get_post_status($supplier_id);
    $map = [
        'draft'    => 'Robocze',
        'pending'  => 'Oczekujące',
        'publish'  => 'Zaakceptowane',
        'rejected' => 'Do poprawy',
    ];
    return esc_html( $map[$status] ?? $status );
} );

add_shortcode( 'bm_dane_wykonawcy_adres', function() {
    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';
    $street = get_post_meta($supplier_id, 'ulica_dostawca', true);
    $code   = get_post_meta($supplier_id, 'kod_pocztowy_dostawca', true);
    $city   = get_post_meta($supplier_id, 'miejscowosc_dostawca', true);
    $addr = trim($street.' '.$code.' '.$city);
    return esc_html($addr);
} );

add_shortcode( 'bm_sekcja_dane_wykonawcy', function() {
    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';
    $status = do_shortcode('[bm_dane_wykonawcy_status]');
    $addr = do_shortcode('[bm_dane_wykonawcy_adres]');
    $plan = do_shortcode('[bm_konto_typ]');
    return '<div class="bm-box bm-box--account"><h3 class="bm-account-title">Dane Wykonawcy</h3><p><strong>Plan:</strong> '.esc_html($plan).'</p><p><strong>Status:</strong> '.esc_html($status).'</p><p><strong>Adres:</strong> '.esc_html($addr).'</p></div>';
} );


add_shortcode( 'bm_logo_firmy', function() {
    return do_shortcode('[dostawca_obrazek klucz="logo_dostawca" size="medium"]');
} );

add_shortcode( 'bm_zdjecie_firmy', function() {
    return do_shortcode('[dostawca_obrazek klucz="zdjecie_firmy" size="medium"]');
} );

/* ==========================================================
   3️⃣ SHORTCODE: [dostawca_obrazek]
   ========================================================== */

add_shortcode( 'dostawca_obrazek', function( $atts ) {

    $atts = shortcode_atts([
        'klucz' => '',
        'size'  => 'medium',
        'class' => '',
    ], $atts );

    if ( empty( $atts['klucz'] ) ) return '';

    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';

    $field = function_exists('get_field')
        ? get_field( $atts['klucz'], $supplier_id )
        : get_post_meta( $supplier_id, $atts['klucz'], true );

    if ( empty( $field ) ) return '';

    // ACF Image (tablica)
    if ( is_array( $field ) && isset( $field['ID'] ) ) {
        return wp_get_attachment_image( $field['ID'], $atts['size'], false, [
            'class' => 'dostawca-obrazek ' . esc_attr( $atts['class'] ),
        ]);
    }

    // ID załącznika
    if ( is_numeric( $field ) ) {
        return wp_get_attachment_image( (int)$field, $atts['size'], false, [
            'class' => 'dostawca-obrazek ' . esc_attr( $atts['class'] ),
        ]);
    }

    // surowy URL
    if ( is_string( $field ) ) {
        return '<img src="'.esc_url($field).'" class="dostawca-obrazek '.esc_attr($atts['class']).'">';
    }

    return '';
});

/* ==========================================================
   4️⃣ SHORTCODE: [dane_dostawcy]
   ========================================================== */

add_shortcode( 'dane_dostawcy', function( $atts ) {

    $atts = shortcode_atts([
        'pole'  => '',
        'typ'   => '',
        'class' => '',
        'style' => '',
    ], $atts );

    if ( empty( $atts['pole'] ) ) return '';

    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';

    $value = function_exists('get_field') ? get_field( $atts['pole'], $supplier_id ) : '';

    if ( empty( $value ) ) return '';

    // obrazek
    if ( $atts['typ'] === 'image' ) {

        $url = '';

        if ( is_array($value) && isset($value['url']) ) $url = $value['url'];
        elseif ( is_numeric($value) ) $url = wp_get_attachment_url( $value );
        elseif ( is_string($value) ) $url = $value;

        if ( ! $url ) return '';

        $style = $atts['style'] ?: 'height:80px;width:auto;';
        return '<img src="'.esc_url($url).'" class="'.esc_attr($atts['class']).'" style="'.esc_attr($style).'">';
    }

    // tekst
    if ( is_scalar($value) ) return esc_html($value);

    return '';
});

/* ==========================================================
   5️⃣ SHORTCODE: [oferty_dostawcy]
   ========================================================== */

add_shortcode( 'oferty_dostawcy', function( $atts ) {

    $atts = shortcode_atts([
        'limit' => 3,
        'class' => '',
    ], $atts );

    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '';

    $current_offer = is_singular('dostawca_oferta') ? get_the_ID() : 0;

    $q = new WP_Query([
        'post_type'      => 'dostawca_oferta',
        'posts_per_page' => (int) $atts['limit'],
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'     => 'powiazany_dostawca',
                'value'   => $supplier_id,
                'compare' => '=',
            ]
        ],
        'post__not_in' => $current_offer ? [ $current_offer ] : [],
    ]);

    if ( ! $q->have_posts() ) return '';

    ob_start();
    echo '<div class="lista-ofert-dostawcy '.esc_attr($atts['class']).'">';

    while ( $q->have_posts() ) {
        $q->the_post();

        echo '<div class="oferta-box">';
        if ( $thumb = get_the_post_thumbnail_url( get_the_ID(), 'medium' ) ) {
            echo '<div class="oferta-thumb"><img src="'.esc_url($thumb).'"></div>';
        }
        echo '<h4>'.esc_html(get_the_title()).'</h4>';
        echo '<a href="'.esc_url(get_permalink()).'" class="button">Zobacz ofertę</a>';
        echo '</div>';
    }

    echo '</div>';
    wp_reset_postdata();
    return ob_get_clean();
});

/* ==========================================================
   6️⃣ SHORTCODE: [wszystkie_oferty_dostawcy] – Loop Template Elementor
   ========================================================== */

add_shortcode( 'wszystkie_oferty_dostawcy', function( $atts ) {

    $atts = shortcode_atts([
        'limit' => -1,
    ], $atts );

    $supplier_id = bm_get_current_supplier_post_id();
    if ( ! $supplier_id ) return '<p>Brak ofert.</p>';

    $user_id = (int) get_post_field('post_author', $supplier_id);
    if ( ! $user_id ) return '<p>Brak ofert.</p>';

    $q = new WP_Query([
        'post_type'      => 'dostawca_oferta',
        'post_status'    => 'publish',
        'author'         => $user_id,
        'posts_per_page' => (int) $atts['limit'],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    if ( ! $q->have_posts() ) return '<p>Brak ofert.</p>';

    $loop_template_id = 520;

    if ( class_exists('\Elementor\Core\Files\CSS\Post') ) {
        $css_file = new \Elementor\Core\Files\CSS\Post( $loop_template_id );
        $css_file->enqueue();
    }

    if ( ! class_exists('\Elementor\Plugin') ) {
        return '<p>Elementor nieaktywny.</p>';
    }

    ob_start();
    echo '<div class="oferty-grid-elementor">';

    while ( $q->have_posts() ) {
        $q->the_post();
        echo \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $loop_template_id );
    }

    echo '</div>';
    wp_reset_postdata();
    return ob_get_clean();
});
