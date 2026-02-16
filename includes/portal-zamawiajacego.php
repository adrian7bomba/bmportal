<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ==========================================================
   PORTAL KLIENTÓW – WERSJA ACF (bez weryfikacji)
   ========================================================== */

/* Po rejestracji usera z rolą zamawiajacy – twórz wizytówkę */
add_action( 'user_register', function( $user_id ) {
    $u = get_userdata( $user_id );
    if ( $u && in_array( 'zamawiajacy', (array) $u->roles, true ) ) {
        bm_get_or_create_client_post( $user_id );
    }
} );

/* ENDPOINT "client-data" w Moje konto */

add_action( 'init', function() {
    add_rewrite_endpoint( 'client-data', EP_ROOT | EP_PAGES );
} );

add_filter( 'woocommerce_account_menu_items', function( $items ) {
    $u = wp_get_current_user();
    if ( $u && in_array( 'zamawiajacy', (array) $u->roles, true ) ) {
        $new  = [
            'client-data' => 'Dane Klienta',
        ];
        $items = $new + $items;
    }
    return $items;
} );

/* ZAKŁADKA "Dane Klienta" */

add_action( 'woocommerce_account_client-data_endpoint', function() {

    if ( ! is_user_logged_in() ) {
        echo '<p>Musisz być zalogowany.</p>';
        return;
    }

    $user = wp_get_current_user();
    if ( ! in_array( 'zamawiajacy', (array) $user->roles, true ) ) {
        echo '<p>Ta sekcja jest dostępna tylko dla użytkowników z rolą <strong>Klient</strong>.</p>';
        return;
    }

    if ( ! function_exists( 'acf_form' ) ) {
        echo '<p>ACF PRO jest wymagany do edycji danych Klienta.</p>';
        return;
    }

    $post_id = bm_get_or_create_client_post( $user->ID );
    $status  = get_post_status( $post_id );

    echo '<div class="bm-box bm-box--account bm-client-box">';
    echo '<h2 class="bm-account-title">Dane Klienta</h2>';

    if ( 'publish' === $status ) {
        echo '<p>Status: <strong>Aktywne</strong> – Twoja wizytówka jest widoczna publicznie.</p>';
        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            echo '<p>Adres wizytówki: <a href="' . esc_url( $permalink ) . '" target="_blank">'
                 . esc_html( $permalink ) . '</a></p>';
        }
    } else {
        echo '<p>Uzupełnij dane Klienta. Nie wymagają one akceptacji administracji.</p>';
    }

    echo '</div>';

    acf_form( [
        'post_id'          => $post_id,
        'post_title'       => false, // tytuł = imię + nazwisko
        'post_content'     => false,
        'uploader'         => 'wp',
        'return'           => wc_get_account_endpoint_url( 'client-data' ),
        'submit_value'     => 'Zapisz dane',
        'updated_message'  => 'Dane Klienta zapisane.',
        'label_placement'  => 'top',
        'html_before_fields'=> '<div class="bm-form bm-form--client">',
        'html_after_fields'=> '<input type="hidden" name="bm_client_form" value="1" /></div>',
    ] );
} );

/* ZAPIS FORMULARZA – Klient bez weryfikacji */

add_action( 'acf/save_post', function( $post_id ) {

    if ( get_post_type( $post_id ) !== 'zamawiajacy' ) {
        return;
    }
    if ( empty( $_POST['bm_client_form'] ) ) {
        return;
    }

    // tytuł = imię + nazwisko
    if ( function_exists( 'get_field' ) ) {
        $full_name = get_field( 'imie_nazwisko_zamawiajacy', $post_id );
        if ( $full_name ) {
            wp_update_post( [
                'ID'         => $post_id,
                'post_title' => $full_name,
                'post_name'  => sanitize_title( $full_name ),
            ] );
        }
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        // Klient bez potwierdzenia: publikujemy od razu
        wp_update_post( [
            'ID'          => $post_id,
            'post_status' => 'publish',
        ] );
    }

}, 20 );

/* PANEL AKCEPTACJI ZAMAWIAJĄCEGO W PROFILU USERA */

add_action( 'edit_user_profile', function( $user ) {

    if ( ! in_array( 'zamawiajacy', (array) $user->roles, true ) ) {
        return;
    }

    $post_id = bm_get_or_create_client_post( $user->ID );
    $status  = get_post_status( $post_id ) ?: 'draft';

    echo '<h2>Dane Klienta – ustawienia (opcjonalne)</h2>';

    echo '<table class="form-table"><tbody>';

    echo '<tr>';
    echo '<th><label for="bm_client_status">Status wizytówki</label></th>';
    echo '<td>';
    echo '<select name="bm_client_status" id="bm_client_status">';
    echo '<option value="draft" '    . selected( $status, 'draft', false )    . '>Robocze</option>';
    echo '<option value="pending" '  . selected( $status, 'pending', false )  . '>Oczekujące</option>';
    echo '<option value="publish" '  . selected( $status, 'publish', false )  . '>Zaakceptowane</option>';
    echo '<option value="rejected" ' . selected( $status, 'rejected', false ) . '>Odrzucone</option>';
    echo '</select>';

    $public_link   = get_permalink( $post_id );
    $edit_post_url = get_edit_post_link( $post_id, '' );

    echo '<p class="description">';
    echo 'Aktualny status: <strong>' . esc_html( $status ) . '</strong>.<br>';
    if ( $public_link && 'publish' === $status ) {
        echo 'Publiczna wizytówka: <a href="' . esc_url( $public_link ) . '" target="_blank">'
             . esc_html( $public_link ) . '</a><br>';
    }
    if ( $edit_post_url ) {
        echo 'Edycja wpisu "zamawiający": <a href="' . esc_url( $edit_post_url ) . '" target="_blank">Otwórz w nowej karcie</a>';
    }
    echo '</p>';

    echo '</td></tr>';

    if ( function_exists( 'get_fields' ) ) {

        $current_fields = get_fields( $post_id );
        if ( ! is_array( $current_fields ) ) {
            $current_fields = [];
        }

        $prev_json   = get_post_meta( $post_id, 'bm_client_last_accepted_snapshot', true );
        $prev_fields = $prev_json ? json_decode( $prev_json, true ) : [];

        echo '<tr><th>Zmiany w danych</th><td>';

        if ( empty( $prev_fields ) ) {

            echo '<p><em>Brak wcześniej zaakceptowanych danych – to może być pierwsza akceptacja.</em></p>';

            if ( ! empty( $current_fields ) ) {
                echo '<p><strong>Aktualnie zapisane pola ACF:</strong></p><ul>';
                foreach ( $current_fields as $key => $val ) {

                    $label = $key;
                    if ( function_exists( 'acf_get_field' ) ) {
                        $field_obj = acf_get_field( $key );
                        if ( $field_obj && ! is_wp_error( $field_obj ) && ! empty( $field_obj['label'] ) ) {
                            $label = $field_obj['label'] . ' (' . $key . ')';
                        }
                    }

                    $val_str = is_scalar( $val ) ? (string) $val : '[złożona wartość]';
                    echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $val_str ) . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p><em>Brak zapisanych pól ACF dla tego zamawiającego.</em></p>';
            }

        } else {

            echo '<p><strong>Różnice względem ostatnio zaakceptowanych danych:</strong></p>';
            $has_changes = false;
            echo '<ul>';

            foreach ( $current_fields as $key => $val ) {

                $new_val = $val;
                $old_val = array_key_exists( $key, $prev_fields ) ? $prev_fields[ $key ] : null;

                $new_str = is_scalar( $new_val ) ? (string) $new_val : '[złożona wartość]';
                $old_str = is_scalar( $old_val ) ? (string) $old_val :
                           ( ( null === $old_val ) ? '[brak]' : '[złożona wartość]' );

                if ( $old_val === null && $new_val === null ) {
                    continue;
                }
                if ( $old_str === $new_str ) {
                    continue;
                }

                $has_changes = true;

                $label = $key;
                if ( function_exists( 'acf_get_field' ) ) {
                    $field_obj = acf_get_field( $key );
                    if ( $field_obj && ! is_wp_error( $field_obj ) && ! empty( $field_obj['label'] ) ) {
                        $label = $field_obj['label'] . ' (' . $key . ')';
                    }
                }

                echo '<li><strong>' . esc_html( $label ) . '</strong><br>';
                echo 'Poprzednio: <code>' . esc_html( $old_str ) . '</code><br>';
                echo 'Teraz: <code>' . esc_html( $new_str ) . '</code></li>';
            }

            echo '</ul>';

            if ( ! $has_changes ) {
                echo '<p><em>Brak zmian względem ostatnio zaakceptowanej wersji.</em></p>';
            }
        }

        echo '<p class="description">Lista oparta o wszystkie pola ACF przypisane do CPT <code>zamawiajacy</code>.</p>';

        echo '</td></tr>';
    }

    echo '</tbody></table>';
} );

function bm_save_client_admin_decision( $user_id ) {

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_POST['bm_client_status'] ) ) {
        return;
    }

    $user_id = (int) $user_id;
    $post_id = bm_get_or_create_client_post( $user_id );

    $new_status = sanitize_text_field( $_POST['bm_client_status'] );
    $allowed    = [ 'draft', 'pending', 'publish', 'rejected' ];

    if ( ! in_array( $new_status, $allowed, true ) ) {
        return;
    }

    $old_status = get_post_status( $post_id );
    if ( $old_status === $new_status ) {
        return;
    }

    wp_update_post( [
        'ID'          => $post_id,
        'post_status' => $new_status,
    ] );

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return;
    }

    if ( 'publish' === $new_status ) {

        if ( function_exists( 'get_fields' ) ) {
            $fields = get_fields( $post_id );
            if ( is_array( $fields ) ) {
                update_post_meta(
                    $post_id,
                    'bm_client_last_accepted_snapshot',
                    wp_json_encode( $fields )
                );
            }
        }

        wp_mail(
            $user->user_email,
            'Twoje dane zostały zaakceptowane',
            "Twoje dane zostały zaakceptowane przez administrację.\nTwoja wizytówka jest już widoczna publicznie.",
            [ 'Content-Type' => 'text/plain; charset=UTF-8' ]
        );

    } elseif ( 'rejected' === $new_status ) {

        wp_mail(
            $user->user_email,
            'Twoje dane wymagają poprawek',
            "Twoje dane zostały odrzucone.\nZaloguj się do panelu, popraw dane i wyślij je ponownie do akceptacji.",
            [ 'Content-Type' => 'text/plain; charset=UTF-8' ]
        );
    }
}

add_action( 'personal_options_update', 'bm_save_client_admin_decision' );
add_action( 'edit_user_profile_update', 'bm_save_client_admin_decision' );
