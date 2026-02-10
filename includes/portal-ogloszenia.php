<?php
/**
 * PORTAL – OGŁOSZENIA ZAMAWIAJĄCYCH
 *
 * - CPT: ogloszenie
 * - Zakładka "Moje ogłoszenia" w WooCommerce → Moje konto (rola: zamawiajacy)
 * - Limit 3 ogłoszeń w planie darmowym
 * - Formularz ACF na froncie (Moje konto) z Twoimi polami:
 *   tytul_ogloszenie, termin_ogloszenie, tresc_ogloszenie,
 *   budzet_ogloszenie, realizcja_ogloszenie, obszar_ogloszenie
 *
 * Docelowo: każdy post `ogloszenie` możesz zmapować na szablon Single w Elementorze.
 */

/**
 * 1. Rejestracja CPT "ogloszenie"
 */
add_action( 'init', function () {

    $labels = [
        'name'               => 'Ogłoszenia',
        'singular_name'      => 'Ogłoszenie',
        'add_new'            => 'Dodaj ogłoszenie',
        'add_new_item'       => 'Dodaj nowe ogłoszenie',
        'edit_item'          => 'Edytuj ogłoszenie',
        'new_item'           => 'Nowe ogłoszenie',
        'view_item'          => 'Zobacz ogłoszenie',
        'search_items'       => 'Szukaj ogłoszeń',
        'not_found'          => 'Nie znaleziono ogłoszeń',
        'not_found_in_trash' => 'Brak ogłoszeń w koszu',
        'all_items'          => 'Wszystkie ogłoszenia',
    ];

    register_post_type( 'ogloszenie', [
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-megaphone',
        'query_var'          => true,
        'rewrite'            => [ 'slug' => 'ogloszenie', 'with_front' => false ],
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 7,
        'show_in_rest'       => true,
        'supports'           => [ 'title', 'editor', 'thumbnail', 'author' ],
    ] );

}, 9 );

/**
 * 2. Endpoint "client-ads" w Moje Konto (WooCommerce)
 *    → zakładka "Moje ogłoszenia" dla roli "zamawiajacy"
 */
add_action( 'init', function () {
    if ( function_exists( 'add_rewrite_endpoint' ) ) {
        add_rewrite_endpoint( 'client-ads', EP_ROOT | EP_PAGES );
    }
} );

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
    $u = wp_get_current_user();

    if ( $u && in_array( 'zamawiajacy', (array) $u->roles, true ) ) {
        $new  = [
            'client-ads' => 'Moje ogłoszenia',
        ];
        $items = $new + $items;
    }

    return $items;
} );

/**
 * Helper – pobierz lub policz ogłoszenia bieżącego zamawiającego
 */
function bm_get_client_ads_for_user( $user_id ) {
    $ads = get_posts( [
        'post_type'   => 'ogloszenie',
        'post_status' => [ 'publish', 'draft', 'pending' ],
        'numberposts' => - 1,
        'author'      => $user_id,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ] );

    return is_array( $ads ) ? $ads : [];
}

/**
 * 3. ZAKŁADKA "Moje ogłoszenia" – logika frontu (lista / nowy / edycja)
 */
add_action( 'woocommerce_account_client-ads_endpoint', function () {

    if ( ! is_user_logged_in() ) {
        echo '<p>Musisz być zalogowany.</p>';

        return;
    }

    $user = wp_get_current_user();
    if ( ! in_array( 'zamawiajacy', (array) $user->roles, true ) ) {
        echo '<p>Ta sekcja jest dostępna tylko dla użytkowników z rolą <strong>zamawiający</strong>.</p>';

        return;
    }

    if ( ! function_exists( 'acf_form' ) ) {
        echo '<p>ACF PRO jest wymagany do zarządzania ogłoszeniami.</p>';

        return;
    }

    // LIMIT – 3 ogłoszenia w planie darmowym
    $ad_limit = 3;

    $ads        = bm_get_client_ads_for_user( $user->ID );
    $ads_count  = count( $ads );
    $mode       = 'list';
    $edit_id    = 0;

    if ( isset( $_GET['new_ad'] ) ) {
        $mode = 'new';
    } elseif ( isset( $_GET['edit_ad'] ) ) {
        $mode    = 'edit';
        $edit_id = (int) $_GET['edit_ad'];
    }

    // Nagłówek info o limicie
    echo '<div style="background:#fff;padding:16px;border-radius:8px;border:1px solid #e8e8e8;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">Twoje ogłoszenia</h2>';
    echo '<p>W planie darmowym możesz dodać maksymalnie <strong>' . $ad_limit . '</strong> ogłoszenia. 
          Obecnie masz: <strong>' . $ads_count . '</strong>.</p>';
    echo '</div>';

    /**
     * TRYB: NOWE OGŁOSZENIE
     */
    if ( 'new' === $mode ) {

        if ( $ads_count >= $ad_limit ) {
            echo '<div style="background:#fffbe6;padding:12px;border-radius:6px;border:1px solid #faad14;margin-bottom:24px;">
                    Osiągnąłeś limit ' . $ad_limit . ' ogłoszeń w planie darmowym.
                  </div>';
            echo '<p><a class="button" href="' . esc_url( wc_get_account_endpoint_url( 'client-ads' ) ) . '">Wróć do listy ogłoszeń</a></p>';

            return;
        }

        echo '<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e8e8e8;margin-bottom:24px;">';
        echo '<h3 style="margin-top:0;">Dodaj nowe ogłoszenie</h3>';

        /**
         * ACF FORM – nowe ogłoszenie
         * Uwaga:
         * - Tytuł posta = "Tytuł ogłoszenia" (tytul_ogloszenie)
         *   Możemy też zostawić standardowy tytuł WP i tylko w Elementorze wyświetlać pole ACF.
         *   Na razie robimy: post_title = standardowy tytuł WordPress (użytkownik wpisze go w polu Title),
         *   a ACF używamy do reszty pól (masz je już w grupie dla `ogloszenie`).
         */
        acf_form( [
            'post_id'          => 'new_post',
            'new_post'         => [
                'post_type'   => 'ogloszenie',
                'post_status' => 'publish',
                'post_author' => $user->ID,
            ],
            'post_title'       => true,   // jeśli chcesz, żeby tytuł WP był wpisywany w formularzu
            'post_content'     => false,  // treść ogłoszenia w ACF: tresc_ogloszenie
            'uploader'         => 'wp',
            'return'           => wc_get_account_endpoint_url( 'client-ads' ),
            'submit_value'     => 'Zapisz ogłoszenie',
            'updated_message'  => 'Ogłoszenie zostało zapisane.',
            'label_placement'  => 'top',
            'html_after_fields'=> '<input type="hidden" name="bm_client_ad_form" value="1" />',
        ] );

        echo '<p><a class="button" href="' . esc_url( wc_get_account_endpoint_url( 'client-ads' ) ) . '" style="margin-top:10px;">Anuluj</a></p>';
        echo '</div>';

        return;
    }

    /**
     * TRYB: EDYCJA OGŁOSZENIA
     */
    if ( 'edit' === $mode && $edit_id ) {

        $ad = get_post( $edit_id );
        if ( ! $ad || $ad->post_type !== 'ogloszenie' || (int) $ad->post_author !== $user->ID ) {
            echo '<div style="background:#fff1f0;padding:12px;border-radius:6px;border:1px solid #f5222d;margin-bottom:12px;">
                    Nie możesz edytować tego ogłoszenia.
                  </div>';
            echo '<p><a class="button" href="' . esc_url( wc_get_account_endpoint_url( 'client-ads' ) ) . '">Wróć do listy ogłoszeń</a></p>';

            return;
        }

        echo '<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e8e8e8;margin-bottom:24px;">';
        echo '<h3 style="margin-top:0;">Edytuj ogłoszenie: ' . esc_html( get_the_title( $ad ) ) . '</h3>';

        $delete_url = wp_nonce_url(
            add_query_arg( 'delete_client_ad', $edit_id, wc_get_account_endpoint_url( 'client-ads' ) ),
            'delete_client_ad_' . $edit_id
        );

        acf_form( [
            'post_id'          => $edit_id,
            'post_title'       => true,
            'post_content'     => false,
            'uploader'         => 'wp',
            'return'           => wc_get_account_endpoint_url( 'client-ads' ),
            'submit_value'     => 'Zapisz zmiany',
            'updated_message'  => 'Ogłoszenie zostało zaktualizowane.',
            'label_placement'  => 'top',
            'html_after_fields'=> '<input type="hidden" name="bm_client_ad_form" value="1" />',
        ] );

        echo '<p style="margin-top:20px;">
                <a href="' . esc_url( $delete_url ) . '"
                   class="button"
                   style="background:#c62828;color:#fff;border-color:#b71c1c;"
                   onclick="return confirm(\'Czy na pewno usunąć to ogłoszenie?\');">
                    Usuń ogłoszenie
                </a>
                <a class="button" href="' . esc_url( wc_get_account_endpoint_url( 'client-ads' ) ) . '" style="margin-left:8px;">
                    Wróć do listy ogłoszeń
                </a>
              </p>';

        echo '</div>';

        return;
    }

    /**
     * TRYB: LISTA OGŁOSZEŃ
     */
    echo '<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e8e8e8;">';
    echo '<h3 style="margin-top:0;">Lista Twoich ogłoszeń</h3>';

    if ( $ads_count < $ad_limit ) {
        $new_url = add_query_arg( 'new_ad', 1, wc_get_account_endpoint_url( 'client-ads' ) );
        echo '<p><a class="button button-primary" href="' . esc_url( $new_url ) . '">Dodaj nowe ogłoszenie</a></p>';
    } else {
        echo '<p><strong>Masz już maksymalną liczbę ogłoszeń w planie darmowym.</strong></p>';
    }

    if ( empty( $ads ) ) {
        echo '<p>Nie masz jeszcze żadnych ogłoszeń.</p>';
    } else {
        echo '<ul style="list-style:none;padding-left:0;margin-top:20px;">';
        foreach ( $ads as $ad ) {
            $edit_link = add_query_arg( 'edit_ad', $ad->ID, wc_get_account_endpoint_url( 'client-ads' ) );
            $view_link = get_permalink( $ad->ID );

            echo '<li style="border-top:1px solid #e8e8e8;padding:12px 0;">';
            echo '<strong>' . esc_html( get_the_title( $ad ) ) . '</strong>';
            echo '<div style="margin-top:6px;">';
            echo '<a class="button" href="' . esc_url( $edit_link ) . '">Edytuj</a> ';
            if ( $view_link ) {
                echo '<a class="button" href="' . esc_url( $view_link ) . '" target="_blank">Zobacz</a>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    echo '</div>';
} );

/**
 * 4. Usuwanie ogłoszenia (GET + nonce) – analogicznie jak oferty dostawcy
 */
add_action( 'init', function () {

    if ( ! is_user_logged_in() ) {
        return;
    }

    if ( isset( $_GET['delete_client_ad'], $_GET['_wpnonce'] ) ) {

        $ad_id = (int) $_GET['delete_client_ad'];
        if ( ! $ad_id ) {
            return;
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_client_ad_' . $ad_id ) ) {
            return;
        }

        $ad = get_post( $ad_id );
        if ( ! $ad || $ad->post_type !== 'ogloszenie' ) {
            return;
        }

        if ( (int) $ad->post_author !== get_current_user_id() ) {
            return;
        }

        wp_delete_post( $ad_id, true );

        wp_safe_redirect( wc_get_account_endpoint_url( 'client-ads' ) );
        exit;
    }
} );
