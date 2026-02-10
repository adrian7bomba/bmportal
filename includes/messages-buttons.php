<?php
/**
 * Przyciski wiadomości i współpracy + styl
 * - [bm_message_button label="..."]
 * - [bm_deal_button label="..."]
 */

/**
 * Globalny styl przycisku portalu
 */
add_action( 'wp_head', function () {
    ?>
    <style>
        .bm-portal-btn{
            display:inline-block;
            padding:16px 40px;
            border-radius:16px;
            background:#FFD700;
            color:#1B2A4E;
            text-decoration:none;
            font-weight:600;
            border:none;
            cursor:pointer;
        }
        .bm-portal-btn:hover{
            background:#1B2A4E;
            color:#ffffff;
        }
    </style>
    <?php
} );

/**
 * 1) [bm_message_button]
 *    – otwiera zakładkę "Wiadomości" z rozpoczętym wątkiem
 */
add_shortcode( 'bm_message_button', function ( $atts ) {

    $atts = shortcode_atts( [
        'label' => 'Napisz wiadomość',
    ], $atts );

    // URL logowania (niezalogowany)
    $login_url = home_url( '/moje-konto/' );

    global $post;
    if ( ! $post instanceof WP_Post ) {
        return '';
    }

    // Niezalogowany – pokaż przycisk do logowania
    if ( ! is_user_logged_in() ) {
        return sprintf(
            '<a href="%s" class="bm-portal-btn">%s</a>',
            esc_url( $login_url ),
            esc_html( 'Zaloguj się, aby wysłać wiadomość' )
        );
    }

    if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
        return ''; // WooCommerce nieaktywne
    }

    $current_user = wp_get_current_user();
    $current_id   = (int) $current_user->ID;
    $to_user_id   = (int) $post->post_author;

    // nie piszemy do siebie
    if ( ! $to_user_id || $to_user_id === $current_id ) {
        return '';
    }

    $source_type = get_post_type( $post->ID );
    $source_id   = (int) $post->ID;

    $base = wc_get_account_endpoint_url( 'messages' );

    $url = add_query_arg( [
        'start_thread' => 1,
        'to_user'      => $to_user_id,
        'source_type'  => $source_type,
        'source_id'    => $source_id,
    ], $base );

    return sprintf(
        '<a href="%s" class="bm-portal-btn">%s</a>',
        esc_url( $url ),
        esc_html( $atts['label'] )
    );
} );

/**
 * 2) [bm_deal_button]
 *    – otwiera zakładkę "Współprace" z propozycją współpracy
 */
add_shortcode( 'bm_deal_button', function ( $atts ) {

    $atts = shortcode_atts( [
        'label' => 'Zaproponuj współpracę',
    ], $atts );

    $login_url = home_url( '/moje-konto/' );

    global $post;
    if ( ! $post instanceof WP_Post ) {
        return '';
    }

    // Niezalogowany – przycisk do logowania
    if ( ! is_user_logged_in() ) {
        return sprintf(
            '<a href="%s" class="bm-portal-btn">%s</a>',
            esc_url( $login_url ),
            esc_html( 'Zaloguj się, aby zaproponować współpracę' )
        );
    }

    if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
        return '';
    }

    $current_user = wp_get_current_user();
    $current_id   = (int) $current_user->ID;
    $to_user_id   = (int) $post->post_author;

    if ( ! $to_user_id || $to_user_id === $current_id ) {
        return '';
    }

    $source_type = get_post_type( $post->ID );
    $source_id   = (int) $post->ID;

    $base = wc_get_account_endpoint_url( 'deals' ); // endpoint z pliku deals.php

    $url = add_query_arg( [
        'start_deal'  => 1,
        'to_user'     => $to_user_id,
        'source_type' => $source_type,
        'source_id'   => $source_id,
    ], $base );

    return sprintf(
        '<a href="%s" class="bm-portal-btn">%s</a>',
        esc_url( $url ),
        esc_html( $atts['label'] )
    );
} );
