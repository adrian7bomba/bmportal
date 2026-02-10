<?php
/**
 * Plugin Name: BrandManager Portal
 * Description: Portal dostawców i zamawiających – CPT, ACF, shortcody, panel akceptacji.
 * Author: BDC
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BM_PORTAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'BM_PORTAL_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Autoloadujemy nasze pliki z katalogu includes
 */
add_action( 'plugins_loaded', function() {

    require_once BM_PORTAL_PATH . 'includes/helpers-common.php';
    require_once BM_PORTAL_PATH . 'includes/cpt-dostawca.php';
    require_once BM_PORTAL_PATH . 'includes/cpt-zamawiajacy.php';
    require_once BM_PORTAL_PATH . 'includes/taksonomie-dostawca.php';
    require_once BM_PORTAL_PATH . 'includes/portal-dostawcy.php';
    require_once BM_PORTAL_PATH . 'includes/portal-zamawiajacego.php';
    require_once BM_PORTAL_PATH . 'includes/shortcodes-dostawca.php';
    require_once BM_PORTAL_PATH . 'includes/filters-js.php';
    require_once BM_PORTAL_PATH . 'includes/offers-rest-meta.php';
    require_once BM_PORTAL_PATH . 'includes/portal-ogloszenia.php';
    require_once BM_PORTAL_PATH . 'includes/messages.php';
    require_once BM_PORTAL_PATH . 'includes/messages-buttons.php';
    require_once BM_PORTAL_PATH . 'includes/deals.php';
    require_once BM_PORTAL_PATH . 'includes/reviews.php';
    require_once BM_PORTAL_PATH . 'includes/portfolio.php';
    require_once BM_PORTAL_PATH . 'includes/supplier-posts.php';
    require_once BM_PORTAL_PATH . 'includes/bm-nip-test-shortcode.php';


});

add_action('wp_enqueue_scripts', function(){
    $css_path = BM_PORTAL_PATH . 'assets/css/portal.css';
    $css_ver  = file_exists($css_path) ? filemtime($css_path) : '1.0';

    wp_enqueue_style(
        'bm-portal-css',
        plugins_url('/assets/css/portal.css', __FILE__),
        [],
        $css_ver
    );

    // JS (portfolio: komunikaty + lightbox)
    $js_path = BM_PORTAL_PATH . 'assets/js/portfolio.js';
    $js_ver  = file_exists($js_path) ? filemtime($js_path) : '1.0';

    wp_enqueue_script(
        'bm-portfolio-js',
        plugins_url('/assets/js/portfolio.js', __FILE__),
        [],
        $js_ver,
        true
    );

    // dane dla AJAX (jeśli używasz komunikatu o opinii do współpracy)
    wp_localize_script('bm-portfolio-js', 'bmPortfolio', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('bm_portfolio_nonce'),
    ]);
});

/**
 * ================================================================
 * WYBÓR TYPU KONTA – wersja zgodna z WooCommerce + Elementor
 * ================================================================
 */

add_action( 'woocommerce_register_form', function() {
    ?>

    <p class="form-row form-row-wide">
        <label for="account_type">
            <strong>Wybierz rodzaj konta <span class="required">*</span></strong>
        </label>
        <select name="account_type" id="account_type" required>
            <option value="">-- wybierz --</option>
            <option value="dostawca">Wykonawca</option>
            <option value="zamawiajacy">Klient</option>
        </select>
    </p>

    <div class="bm-account-type-info">
        <div class="bm-info-box">
            <p><strong>WYKONAWCA</strong></p>
            <p>
                Jako wykonawca możesz prezentować wizytówkę swojej firmy, przedstawiać portfolio realizacji
                oraz odpowiadać na zlecenia wystawione przez Klientów.
            </p>
            <hr>
            <p><strong>KLIENT</strong></p>
            <p>
                Jako klient możesz publikować swoje zlecenia oraz odpowiadać na ogłoszenia Wykonawców.
            </p>
        </div>
    </div>

    <style>
        .bm-account-type-info .bm-info-box {
            background:#FFD700;
            padding:20px 24px;
            border-radius:8px;
            margin:10px 0 20px;
            font-size:14px;
            color:#1B2A4E;
            line-height:1.55em;
        }

        .bm-account-type-info .bm-info-box hr {
            border:0;
            border-bottom:1px solid rgba(0,0,0,0.15);
            margin:12px 0;
        }
    </style>

    <?php
});

/**
 * ================================================================
 * REJESTRACJA: wymagaj wyboru typu konta + ustaw rolę po rejestracji
 * - nie wymagamy danych firmy na etapie rejestracji (krok 2 w Moje konto)
 * - brak przekierowań (zostaje standardowe zachowanie Woo)
 * ================================================================
 */

// Walidacja: typ konta jest wymagany
add_filter( 'woocommerce_registration_errors', function( $errors, $username, $email ) {

    if ( empty( $_POST['account_type'] ) ) {
        $errors->add( 'bm_account_type_missing', __( 'Błąd: wybierz rodzaj konta.', 'brandmanager-portal' ) );
    }

    return $errors;
}, 10, 3 );

// Ustaw rolę na podstawie wyboru + utwórz wizytówkę (CPT)
add_action( 'woocommerce_created_customer', function( $customer_id, $new_customer_data = [], $password_generated = false ) {

    $type = isset( $_POST['account_type'] ) ? sanitize_key( wp_unslash( $_POST['account_type'] ) ) : '';
    if ( ! $type || ! $customer_id ) {
        return;
    }

    $user = get_user_by( 'id', $customer_id );
    if ( ! $user ) {
        return;
    }

    // Mapowanie: wartości pozostają bez zmian (dostawca/zamawiajacy)
    if ( $type === 'dostawca' ) {
        if ( get_role( 'dostawca' ) ) {
            $user->set_role( 'dostawca' );
        }
        if ( function_exists( 'bm_get_or_create_supplier_post' ) ) {
            bm_get_or_create_supplier_post( $customer_id );
        }
    } elseif ( $type === 'zamawiajacy' ) {
        if ( get_role( 'zamawiajacy' ) ) {
            $user->set_role( 'zamawiajacy' );
        }
        if ( function_exists( 'bm_get_or_create_client_post' ) ) {
            bm_get_or_create_client_post( $customer_id );
        }
    }

}, 20, 3 );
