<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin settings
 * Option: bm_superadmin_notify_emails
 */

add_action( 'admin_init', function () {
    register_setting( 'bm_portal_settings', 'bm_superadmin_notify_emails', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ] );

    add_settings_section(
        'bm_portal_settings_section',
        'Powiadomienia e-mail',
        function () {
            echo '<p>Ustaw adresy e-mail, na które mają trafiać powiadomienia o nowych zgłoszeniach (np. rejestracja Wykonawcy do zatwierdzenia).</p>';
        },
        'bm_portal_settings'
    );

    add_settings_field(
        'bm_superadmin_notify_emails',
        'E-maile superadminów (oddziel przecinkami)',
        function () {
            $value = get_option( 'bm_superadmin_notify_emails', '' );
            echo '<input type="text" class="regular-text" name="bm_superadmin_notify_emails" value="' . esc_attr( $value ) . '" placeholder="np. admin@domena.pl, druga@domena.pl" />';
            echo '<p class="description">Jeśli pole puste, system pobierze e-maile użytkowników z rolą <code>superadmin</code>, a jeśli nie istnieje – z rolą <code>administrator</code>.</p>';
        },
        'bm_portal_settings',
        'bm_portal_settings_section'
    );
} );

add_action( 'admin_menu', function () {
    add_options_page(
        'BrandManager Portal',
        'BrandManager',
        'manage_options',
        'bm_portal_settings',
        function () {
            echo '<div class="wrap"><h1>BrandManager Portal – Ustawienia</h1>';
            echo '<form method="post" action="options.php">';
            settings_fields( 'bm_portal_settings' );
            do_settings_sections( 'bm_portal_settings' );
            submit_button();
            echo '</form></div>';
        }
    );
} );

/**
 * Resolve notification emails
 * @return string[]
 */
function bm_portal_get_superadmin_emails(): array {
    $opt = (string) get_option( 'bm_superadmin_notify_emails', '' );
    $emails = [];

    if ( trim( $opt ) !== '' ) {
        $parts = preg_split( '/[\s,;]+/', $opt );
        foreach ( (array) $parts as $p ) {
            $p = trim( (string) $p );
            if ( $p && is_email( $p ) ) {
                $emails[] = $p;
            }
        }
        $emails = array_values( array_unique( $emails ) );
        if ( ! empty( $emails ) ) {
            return $emails;
        }
    }

    // fallback: users with role superadmin, else administrator
    $users = get_users( [ 'role' => 'superadmin', 'fields' => [ 'user_email' ] ] );
    if ( empty( $users ) ) {
        $users = get_users( [ 'role' => 'administrator', 'fields' => [ 'user_email' ] ] );
    }
    foreach ( (array) $users as $u ) {
        if ( ! empty( $u->user_email ) && is_email( $u->user_email ) ) {
            $emails[] = $u->user_email;
        }
    }

    $emails = array_values( array_unique( $emails ) );
    if ( empty( $emails ) ) {
        $fallback = get_option( 'admin_email' );
        if ( is_email( $fallback ) ) {
            $emails[] = $fallback;
        }
    }

    return $emails;
}
