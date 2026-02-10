<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BRANDMANAGER – MODUŁ OPINII / REVIEWS
 *
 * - CPT: bm_review
 * - Opinie powiązane z bm_deal + użytkownikami
 * - Endpoint: /moje-konto/opinions/
 */

/* ----------------------------------------------------------
 * 1. CPT bm_review
 * ---------------------------------------------------------- */
add_action('init', function () {

    $labels = [
        'name'               => 'Opinie',
        'singular_name'      => 'Opinia',
        'add_new'            => 'Dodaj opinię',
        'add_new_item'       => 'Dodaj opinię',
        'edit_item'          => 'Edytuj opinię',
        'new_item'           => 'Nowa opinia',
        'view_item'          => 'Zobacz opinię',
        'search_items'       => 'Szukaj opinii',
        'not_found'          => 'Nie znaleziono opinii',
        'not_found_in_trash' => 'Brak opinii w koszu',
        'menu_name'          => 'Opinie',
    ];

    register_post_type('bm_review', [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'capability_type'    => 'post',
        'has_archive'        => false,
        'rewrite'            => false,
        'supports'           => ['title', 'editor'],
    ]);
});

/* ----------------------------------------------------------
 * A. CPT – prośby o opinie spoza portalu: bm_review_request
 * ---------------------------------------------------------- */
add_action('init', function () {

    $labels = [
        'name'          => 'Prośby o opinie',
        'singular_name' => 'Prośba o opinię',
    ];

    register_post_type('bm_review_request', [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,   // jeśli chcesz widzieć w panelu; możesz zmienić na false
        'show_in_menu'       => false,  // nie dodajemy osobnego menu
        'capability_type'    => 'post',
        'has_archive'        => false,
        'rewrite'            => false,
        'supports'           => ['title'],
    ]);
});

/**
 * Helper – sprawdza, czy dany dostawca już prosił ten email o opinię
 * (blokada ponownych próśb)
 */
if (!function_exists('bm_has_review_request_for_email')) {
    function bm_has_review_request_for_email($supplier_user_id, $email)
    {
        $supplier_user_id = (int) $supplier_user_id;
        $email            = sanitize_email($email);

        if (!$supplier_user_id || !$email) {
            return false;
        }

        $existing = get_posts([
            'post_type'   => 'bm_review_request',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                [
                    'key'   => '_bm_rr_supplier_id',
                    'value' => $supplier_user_id,
                ],
                [
                    'key'   => '_bm_rr_email',
                    'value' => $email,
                ],
            ],
        ]);

        return !empty($existing);
    }
}


/* ----------------------------------------------------------
 * 2. Helpery – sprawdzenie / pobieranie opinii
 * ---------------------------------------------------------- */

/**
 * Sprawdza, czy dany użytkownik wystawił już opinię do danej współpracy
 */
if (!function_exists('bm_user_has_review_for_deal')) {
    function bm_user_has_review_for_deal($deal_id, $user_id)
    {
        $deal_id = (int) $deal_id;
        $user_id = (int) $user_id;

        if (!$deal_id || !$user_id) {
            return false;
        }

        $existing = get_posts([
            'post_type'   => 'bm_review',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                [
                    'key'   => '_bm_review_deal_id',
                    'value' => $deal_id,
                ],
                [
                    'key'   => '_bm_review_author_id',
                    'value' => $user_id,
                ],
            ],
        ]);

        return !empty($existing);
    }
}

/**
 * Zwraca średnią ocenę ogólną (rating_overall) dla danego użytkownika
 */
if (!function_exists('bm_get_user_average_rating')) {
    function bm_get_user_average_rating($user_id)
    {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return 0;
        }

        $reviews = get_posts([
            'post_type'   => 'bm_review',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [
                    'key'   => '_bm_review_target_id',
                    'value' => $user_id,
                ],
            ],
        ]);

        if (empty($reviews)) {
            return 0;
        }

        $sum   = 0;
        $count = 0;

        foreach ($reviews as $review_id) {
            $rating = (int) get_post_meta($review_id, '_bm_rating_overall', true);
            if ($rating > 0) {
                $sum   += $rating;
                $count += 1;
            }
        }

        if ($count === 0) {
            return 0;
        }

        return round($sum / $count, 2);
    }
}

/**
 * Liczba opinii dla danego użytkownika
 */
if (!function_exists('bm_get_user_review_count')) {
    function bm_get_user_review_count($user_id)
    {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return 0;
        }

        $reviews = get_posts([
            'post_type'   => 'bm_review',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [
                    'key'   => '_bm_review_target_id',
                    'value' => $user_id,
                ],
            ],
        ]);

        return is_array($reviews) ? count($reviews) : 0;
    }
}

/* ----------------------------------------------------------
 * Helper – szczegółowe statystyki ocen użytkownika
 * Zwraca sumy, ilości i średnie dla pól rating_* oraz procent poleceń
 * ---------------------------------------------------------- */
if ( ! function_exists( 'bm_get_user_detailed_rating_stats' ) ) {
    function bm_get_user_detailed_rating_stats( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) {
            return [];
        }

        $reviews = get_posts( [
            'post_type'   => 'bm_review',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [
                    'key'   => '_bm_review_target_id',
                    'value' => $user_id,
                ],
            ],
        ] );

        if ( empty( $reviews ) ) {
            return [];
        }

        $stats = [];

        // Pola liczbowe 1–5
        $numeric_fields = [
            'overall'       => '_bm_rating_overall',
            'quality'       => '_bm_rating_quality',
            'timeliness'    => '_bm_rating_timeliness',
            'communication' => '_bm_rating_communication',
            'payment'       => '_bm_rating_payment',
        ];

        foreach ( $numeric_fields as $key => $meta_key ) {
            $stats[ $key ] = [
                'sum'   => 0,
                'count' => 0,
                'avg'   => 0,
            ];
        }

        // Pola typu tak/nie
        $stats['recommend'] = [
            'yes'     => 0,
            'total'   => 0,
            'percent' => 0,
        ];
        $stats['cooperation'] = [
            'yes'     => 0,
            'total'   => 0,
            'percent' => 0,
        ];

        foreach ( $reviews as $review_id ) {

            // NUMERYCZNE
            foreach ( $numeric_fields as $key => $meta_key ) {
                $val = (int) get_post_meta( $review_id, $meta_key, true );
                if ( $val > 0 ) {
                    $stats[ $key ]['sum']   += $val;
                    $stats[ $key ]['count'] += 1;
                }
            }

            // TAK / NIE – recommend
            $rec = get_post_meta( $review_id, '_bm_rating_recommend', true );
            if ( $rec !== '' ) {
                $stats['recommend']['total'] += 1;
                if ( $rec === 'yes' ) {
                    $stats['recommend']['yes'] += 1;
                }
            }

            // TAK / NIE – cooperation
            $coop = get_post_meta( $review_id, '_bm_rating_cooperation', true );
            if ( $coop !== '' ) {
                $stats['cooperation']['total'] += 1;
                if ( $coop === 'yes' ) {
                    $stats['cooperation']['yes'] += 1;
                }
            }
        }

        // Wylicz średnie liczbowe
        foreach ( $numeric_fields as $key => $meta_key ) {
            if ( $stats[ $key ]['count'] > 0 ) {
                $stats[ $key ]['avg'] = round(
                    $stats[ $key ]['sum'] / $stats[ $key ]['count'],
                    2
                );
            }
        }

        // Wylicz procenty dla pól tak/nie
        if ( $stats['recommend']['total'] > 0 ) {
            $stats['recommend']['percent'] = round(
                ( $stats['recommend']['yes'] / $stats['recommend']['total'] ) * 100,
                1
            );
        }
        if ( $stats['cooperation']['total'] > 0 ) {
            $stats['cooperation']['percent'] = round(
                ( $stats['cooperation']['yes'] / $stats['cooperation']['total'] ) * 100,
                1
            );
        }

        return $stats;
    }
}


/* ----------------------------------------------------------
 * Shortcode – szczegółowe statystyki ocen użytkownika
 *
 * [bm_rating_detail field="quality"]
 * [bm_rating_detail field="timeliness"]
 * [bm_rating_detail field="communication"]
 * [bm_rating_detail field="payment"]
 * [bm_rating_detail field="overall"]
 *
 * Domyślnie zwraca średnią (avg).
 *
 * Dla pól tak/nie:
 * [bm_rating_detail field="recommend" what="percent"]
 * [bm_rating_detail field="cooperation" what="percent"]
 *
 * Opcje:
 *  - field: overall|quality|timeliness|communication|payment|recommend|cooperation
 *  - what: avg|count|percent (percent tylko dla recommend/cooperation)
 *  - empty_label: tekst gdy brak opinii
 * ---------------------------------------------------------- */
add_shortcode( 'bm_rating_detail', function ( $atts ) {
    $atts = shortcode_atts( [
        'user_id'     => 0,
        'field'       => 'overall',
        'what'        => 'avg',          // avg|count|percent
        'empty_label' => 'Brak opinii',
    ], $atts, 'bm_rating_detail' );

    $user_id = bm_get_user_id_from_context( (int) $atts['user_id'] );
    if ( ! $user_id ) {
        return esc_html( $atts['empty_label'] );
    }

    $stats = bm_get_user_detailed_rating_stats( $user_id );
    if ( empty( $stats ) ) {
        return esc_html( $atts['empty_label'] );
    }

    $field = sanitize_key( $atts['field'] );
    $what  = sanitize_key( $atts['what'] );

    // Pola numeryczne
    if ( in_array( $field, [ 'overall', 'quality', 'timeliness', 'communication', 'payment' ], true ) ) {
        if ( empty( $stats[ $field ] ) || $stats[ $field ]['count'] === 0 ) {
            return esc_html( $atts['empty_label'] );
        }

        if ( $what === 'count' ) {
            return (string) (int) $stats[ $field ]['count'];
        }

        // domyślnie średnia
        return esc_html( (string) $stats[ $field ]['avg'] );
    }

    // Pola tak/nie
    if ( in_array( $field, [ 'recommend', 'cooperation' ], true ) ) {
        if ( empty( $stats[ $field ] ) || $stats[ $field ]['total'] === 0 ) {
            return esc_html( $atts['empty_label'] );
        }

        if ( $what === 'count' ) {
            // liczba wszystkich opinii, gdzie było zaznaczone tak/nie
            return (string) (int) $stats[ $field ]['total'];
        }

        // domyślnie procent odpowiedzi "yes"
        $percent = $stats[ $field ]['percent']; // np. 80.5
        return esc_html( (string) $percent );
    }

    return '';
} );


/* ----------------------------------------------------------
 * 3. Obsługa wysłania opinii — PRZED OUTPUTEM (template_redirect)
 * ---------------------------------------------------------- */
add_action('template_redirect', function () {

    if (!is_user_logged_in()) {
        return;
    }

    if (empty($_POST['bm_add_review']) || empty($_POST['_bm_review_nonce'])) {
        return;
    }

    // Sprawdzamy nonce
    if (!wp_verify_nonce($_POST['_bm_review_nonce'], 'bm_add_review')) {
        return;
    }

    $current_id = get_current_user_id();
    $deal_id    = isset($_POST['bm_deal_id']) ? (int) $_POST['bm_deal_id'] : 0;

    if (!$deal_id || bm_user_has_review_for_deal($deal_id, $current_id)) {
        return;
    }

    $u1 = (int) get_post_meta($deal_id, '_bm_user_1', true);
    $u2 = (int) get_post_meta($deal_id, '_bm_user_2', true);

    if ($current_id !== $u1 && $current_id !== $u2) {
        return;
    }

    $other_id = ($current_id === $u1) ? $u2 : $u1;

    // Rola oceniającego
    $roles = (array) wp_get_current_user()->roles;
    $review_role =
        in_array('dostawca', $roles, true) ? 'from_supplier' :
        (in_array('zamawiajacy', $roles, true) ? 'from_client' : 'other');

    // Pobieramy wszystkie możliwe pola z formularza
    $rating_overall       = isset($_POST['bm_rating_overall']) ? (int) $_POST['bm_rating_overall'] : 0;
    $rating_quality       = isset($_POST['bm_rating_quality']) ? (int) $_POST['bm_rating_quality'] : 0;
    $rating_timeliness    = isset($_POST['bm_rating_timeliness']) ? (int) $_POST['bm_rating_timeliness'] : 0;
    $rating_communication = isset($_POST['bm_rating_communication']) ? (int) $_POST['bm_rating_communication'] : 0;
    $rating_payment       = isset($_POST['bm_rating_payment']) ? (int) $_POST['bm_rating_payment'] : 0;
    $rating_recommend     = isset($_POST['bm_rating_recommend']) ? sanitize_text_field($_POST['bm_rating_recommend']) : '';
    $rating_cooperation   = isset($_POST['bm_rating_cooperation']) ? sanitize_text_field($_POST['bm_rating_cooperation']) : '';
    $review_text          = isset($_POST['bm_review_text']) ? wp_kses_post($_POST['bm_review_text']) : '';

    if ($rating_overall < 1 || $rating_overall > 5) {
        return;
    }

    $deal_title  = get_the_title($deal_id);
    $other_user  = get_userdata($other_id);
    $other_name  = $other_user ? $other_user->display_name : 'Użytkownik';

    $post_title  = sprintf('Opinia o %s – współpraca "%s"', $other_name, $deal_title);

    $review_id = wp_insert_post([
        'post_type'    => 'bm_review',
        'post_status'  => 'publish',
        'post_title'   => $post_title,
        'post_content' => $review_text,
        'post_author'  => $current_id,
    ]);

    if (!$review_id || is_wp_error($review_id)) {
        return;
    }

    update_post_meta($review_id, '_bm_review_author_id', $current_id);
    update_post_meta($review_id, '_bm_review_target_id', $other_id);
    update_post_meta($review_id, '_bm_review_deal_id', $deal_id);
    update_post_meta($review_id, '_bm_review_role', $review_role);
    update_post_meta($review_id, '_bm_rating_overall', $rating_overall);
    update_post_meta($review_id, '_bm_rating_quality', $rating_quality);
    update_post_meta($review_id, '_bm_rating_timeliness', $rating_timeliness);
    update_post_meta($review_id, '_bm_rating_recommend', $rating_recommend);
    update_post_meta($review_id, '_bm_rating_communication', $rating_communication);
    update_post_meta($review_id, '_bm_rating_payment', $rating_payment);
    update_post_meta($review_id, '_bm_rating_cooperation', $rating_cooperation);
    update_post_meta($review_id, '_bm_review_locked', 1);

    /**
     * Wyślij mail do ocenianego użytkownika o nowej opinii
     * (mechanizm e-maili już masz – tu zostawiamy hook do ewentualnej rozbudowy).
     */
    do_action('bm_new_review_created', $review_id, $deal_id, $current_id, $other_id);

    // Redirect PRG przed wysłaniem jakiegokolwiek outputu
    $redirect = add_query_arg([
        'bm_review_submitted' => '1',
    ], wc_get_account_endpoint_url('opinions'));

    wp_safe_redirect($redirect);
    exit;
});


/* ----------------------------------------------------------
 * 4. Endpoint "opinions" w Moje konto
 * ---------------------------------------------------------- */

/**
 * Rejestrujemy endpoint /moje-konto/opinions/
 */
add_action('init', function () {
    add_rewrite_endpoint('opinions', EP_ROOT | EP_PAGES);
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'opinions';
    return $vars;
});

add_filter('woocommerce_account_menu_items', function ($items) {
    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) {
        return $items;
    }

    $roles = (array) $user->roles;

    // tylko dla dostawca / zamawiajacy
    if (!in_array('dostawca', $roles, true) && !in_array('zamawiajacy', $roles, true)) {
        return $items;
    }

    if (!isset($items['opinions'])) {
        $items['opinions'] = 'Opinie';
    }

    return $items;
});

/* ----------------------------------------------------------
 * 5. Formularz opinii + lista współprac do oceny
 * ---------------------------------------------------------- */

add_action('woocommerce_account_opinions_endpoint', function () {

    if (!is_user_logged_in()) {
        echo '<p>Musisz być zalogowany, aby wystawiać i przeglądać opinie.</p>';
        return;
    }

    $current_id = get_current_user_id();
    $deal_id    = isset($_GET['deal']) ? (int) $_GET['deal'] : 0;

    // Komunikat po zapisie opinii
    if (isset($_GET['bm_review_submitted']) && $_GET['bm_review_submitted'] === '1') {
        echo '<div class="woocommerce-message" role="alert">Dziękujemy! Twoja opinia została zapisana i nie można jej już edytować.</div>';
    }

    // Jeżeli mamy konkretną współpracę, a user jeszcze nie wystawił opinii – pokaż formularz
    if ($deal_id && !bm_user_has_review_for_deal($deal_id, $current_id)) {

        $user_1 = (int) get_post_meta($deal_id, '_bm_user_1', true);
        $user_2 = (int) get_post_meta($deal_id, '_bm_user_2', true);

        if ($current_id === $user_1 || $current_id === $user_2) {

            $other_id   = ($current_id === $user_1) ? $user_2 : $user_1;
            $other      = get_userdata($other_id);
            $other_name = $other ? $other->display_name : 'Użytkownik';

            $user_roles  = (array) wp_get_current_user()->roles;
            $is_supplier = in_array('dostawca', $user_roles, true);
            $is_client   = in_array('zamawiajacy', $user_roles, true);

            echo '<h3>Wystaw opinię</h3>';
            echo '<p>Wystawiasz opinię dla: <strong>' . esc_html($other_name) . '</strong>.</p>';
            echo '<p style="font-size:12px;color:#555;">Pamiętaj: wystawionej opinii nie można edytować.</p>';

            echo '<form method="post" class="bm-review-form">';

            wp_nonce_field('bm_add_review', '_bm_review_nonce');

            echo '<input type="hidden" name="bm_deal_id" value="' . esc_attr($deal_id) . '" />';
            echo '<input type="hidden" name="bm_add_review" value="1" />';

            // Pola wspólne
            echo '<p><label>Ocena ogólna (1–5) <span class="required">*</span><br />';
            echo '<select name="bm_rating_overall" required>';
            echo '<option value="">-- wybierz --</option>';
            for ($i = 5; $i >= 1; $i--) {
                echo '<option value="' . $i . '">' . $i . '</option>';
            }
            echo '</select></label></p>';

            if ($is_client) {
                // Zamawiający ocenia Dostawcę
                echo '<p><label>Jakość pracy (1–5)<br />';
                echo '<select name="bm_rating_quality">';
                echo '<option value="">-- wybierz --</option>';
                for ($i = 5; $i >= 1; $i--) {
                    echo '<option value="' . $i . '">' . $i . '</option>';
                }
                echo '</select></label></p>';

                echo '<p><label>Terminowość (1–5)<br />';
                echo '<select name="bm_rating_timeliness">';
                echo '<option value="">-- wybierz --</option>';
                for ($i = 5; $i >= 1; $i--) {
                    echo '<option value="' . $i . '">' . $i . '</option>';
                }
                echo '</select></label></p>';

                echo '<p><label>Czy polecił(a)byś tego dostawcę?<br />';
                echo '<select name="bm_rating_recommend">';
                echo '<option value="">-- wybierz --</option>';
                echo '<option value="yes">Tak</option>';
                echo '<option value="no">Nie</option>';
                echo '</select></label></p>';

            } elseif ($is_supplier) {
                // Dostawca ocenia Zamawiającego
                echo '<p><label>Komunikacja (1–5)<br />';
                echo '<select name="bm_rating_communication">';
                echo '<option value="">-- wybierz --</option>';
                for ($i = 5; $i >= 1; $i--) {
                    echo '<option value="' . $i . '">' . $i . '</option>';
                }
                echo '</select></label></p>';

                echo '<p><label>Terminowość płatności (1–5)<br />';
                echo '<select name="bm_rating_payment">';
                echo '<option value="">-- wybierz --</option>';
                for ($i = 5; $i >= 1; $i--) {
                    echo '<option value="' . $i . '">' . $i . '</option>';
                }
                echo '</select></label></p>';

                echo '<p><label>Czy współpraca przebiegała sprawnie?<br />';
                echo '<select name="bm_rating_cooperation">';
                echo '<option value="">-- wybierz --</option>';
                echo '<option value="yes">Tak</option>';
                echo '<option value="no">Nie</option>';
                echo '</select></label></p>';
            }

            echo '<p><label>Treść opinii<br />';
            echo '<textarea name="bm_review_text" rows="5" cols="50" placeholder="Napisz kilka zdań o współpracy (opcjonalnie)"></textarea>';
            echo '</label></p>';

            echo '<p><button type="submit" class="button">Wyślij opinię</button></p>';

            echo '</form>';

            return;
        }
    }

    // Jeśli nie wyświetlamy formularza – pokaż listę współprac do oceny
    echo '<h3>Wystaw opinię</h3>';
    echo '<p>Poniżej znajdziesz zakończone współprace, do których możesz wystawić opinię.</p>';

    $deals = get_posts([
        'post_type'   => 'bm_deal',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => [
            'relation' => 'AND',
            [
                'key'   => '_bm_status',
                'value' => 'finished_success',
            ],
            [
                'relation' => 'OR',
                [
                    'key'   => '_bm_user_1',
                    'value' => $current_id,
                ],
                [
                    'key'   => '_bm_user_2',
                    'value' => $current_id,
                ],
            ],
        ],
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);

    if (empty($deals)) {
        echo '<p>Nie masz jeszcze żadnych zakończonych współprac wymagających opinii.</p>';
        return;
    }

    echo '<table class="shop_table shop_table_responsive bm-deals-to-review">';
    echo '<thead><tr>';
    echo '<th>Współpraca</th>';
    echo '<th>Druga strona</th>';
    echo '<th>Data</th>';
    echo '<th>Akcja</th>';
    echo '</tr></thead><tbody>';

    foreach ($deals as $deal) {
        $deal_id = (int) $deal->ID;

        if (bm_user_has_review_for_deal($deal_id, $current_id)) {
            continue;
        }

        $source_title = get_post_meta($deal_id, '_bm_source_title', true);
        if (!$source_title) {
            $source_title = get_the_title($deal_id);
        }

        $user_1     = (int) get_post_meta($deal_id, '_bm_user_1', true);
        $user_2     = (int) get_post_meta($deal_id, '_bm_user_2', true);
        $other_id   = ($current_id === $user_1) ? $user_2 : $user_1;
        $other      = $other_id ? get_userdata($other_id) : null;
        $other_name = $other ? $other->display_name : 'Użytkownik';

        echo '<tr>';
        echo '<td>' . esc_html($source_title) . '</td>';
        echo '<td>' . esc_html($other_name) . '</td>';
        echo '<td>' . esc_html(get_the_date('', $deal_id)) . '</td>';

        $link = add_query_arg([
            'deal' => $deal_id,
        ], wc_get_account_endpoint_url('opinions'));

        echo '<td><a class="button" href="' . esc_url($link) . '">Wystaw opinię</a></td>';

        echo '</tr>';
    }

    echo '</tbody></table>';
});


/* ----------------------------------------------------------
 * 6. Helper – ID użytkownika z kontekstu (wizytówka, oferta, loop)
 * ---------------------------------------------------------- */
if ( ! function_exists( 'bm_get_user_id_from_context' ) ) {
    /**
     * Ustala ID użytkownika na podstawie aktualnego kontekstu:
     * - jeśli podano $forced_user_id → zwraca to
     * - jeśli jesteśmy na bm_review → bierze target (ocenianego) lub autora
     * - jeśli jesteśmy na poście dostawcy / oferty / zlecenia → bierze post_author
     */
    function bm_get_user_id_from_context( $forced_user_id = 0 ) {
        $forced_user_id = (int) $forced_user_id;
        if ( $forced_user_id > 0 ) {
            return $forced_user_id;
        }

        global $post;
        if ( ! $post ) {
            return 0;
        }

        // Jeśli jesteśmy na poście opinii – próbujemy wziąć ocenianego
        if ( $post->post_type === 'bm_review' ) {
            $target_id = (int) get_post_meta( $post->ID, '_bm_review_target_id', true );
            if ( $target_id ) {
                return $target_id;
            }
            $author_id = (int) get_post_meta( $post->ID, '_bm_review_author_id', true );
            if ( $author_id ) {
                return $author_id;
            }
        }

        // Domyślnie – autor aktualnego posta (wizytówka dostawcy, oferta, itp.)
        $author_id = (int) $post->post_author;
        return $author_id > 0 ? $author_id : 0;
    }
}

/* ----------------------------------------------------------
 * 7. Shortcode – średnia ocena użytkownika
 *    [bm_rating_average] lub [bm_rating_average format="full"]
 * ---------------------------------------------------------- */
add_shortcode( 'bm_rating_average', function ( $atts ) {
    $atts = shortcode_atts( [
        'user_id'     => 0,
        'format'      => 'number',      // number | full
        'empty_label' => 'Brak opinii', // tekst, gdy brak opinii
    ], $atts, 'bm_rating_average' );

    $user_id = bm_get_user_id_from_context( (int) $atts['user_id'] );
    if ( ! $user_id ) {
        return esc_html( $atts['empty_label'] );
    }

    $avg   = bm_get_user_average_rating( $user_id );
    $count = bm_get_user_review_count( $user_id );

    if ( $count === 0 || $avg <= 0 ) {
        return esc_html( $atts['empty_label'] );
    }

    if ( $atts['format'] === 'full' ) {
        // np. "4.8 (12 opinii)"
        return esc_html( sprintf( '%.1f (%d opinii)', $avg, $count ) );
    }

    return esc_html( $avg );
} );


/* ----------------------------------------------------------
 * 8. Shortcode – liczba opinii użytkownika
 *    [bm_rating_count]
 * ---------------------------------------------------------- */
add_shortcode( 'bm_rating_count', function ( $atts ) {
    $atts = shortcode_atts( [
        'user_id' => 0,
    ], $atts, 'bm_rating_count' );

    $user_id = bm_get_user_id_from_context( (int) $atts['user_id'] );
    if ( ! $user_id ) {
        return '';
    }

    $count = bm_get_user_review_count( $user_id );
    if ( $count === 0 ) {
        return '0';
    }

    return (string) (int) $count;
} );


/* ----------------------------------------------------------
 * 9. Shortcode – lista opinii o użytkowniku (każda opinia w nowej linii)
 *
 * [bm_reviews_list]
 * [bm_reviews_list limit="3"]
 *
 * Format jednej linii:
 * rating_overall|review_text|author_name|date
 * ---------------------------------------------------------- */
add_shortcode( 'bm_reviews_list', function ( $atts ) {
    $atts = shortcode_atts( [
        'user_id' => 0,
        'limit'   => 5,
    ], $atts, 'bm_reviews_list' );

    $user_id = bm_get_user_id_from_context( (int) $atts['user_id'] );
    if ( ! $user_id ) {
        return '';
    }

    $limit = (int) $atts['limit'];
    if ( $limit <= 0 ) {
        $limit = -1; // wszystkie
    }

    $reviews = get_posts( [
        'post_type'      => 'bm_review',
        'post_status'    => 'publish',
        'numberposts'    => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => '_bm_review_target_id',
                'value' => $user_id,
            ],
        ],
    ] );

    if ( empty( $reviews ) ) {
        return '';
    }

    $lines = [];

    foreach ( $reviews as $review ) {
        $review_id = (int) $review->ID;

        $rating = (int) get_post_meta( $review_id, '_bm_rating_overall', true );
        $text   = wp_strip_all_tags( $review->post_content );
        // usuwamy znaki, które psułyby format
        $text = str_replace( [ "\r", "\n", '|' ], [ ' ', ' ', '/' ], $text );

        $author_id   = (int) get_post_meta( $review_id, '_bm_review_author_id', true );
        $author_user = $author_id ? get_userdata( $author_id ) : null;
        $author_name = $author_user ? $author_user->display_name : '';

        $date = get_the_date( '', $review_id );

        $parts  = [
            (string) $rating,
            $text,
            $author_name,
            $date,
        ];
        $line   = implode( '|', $parts );
        $lines[] = esc_html( $line );
    }

    // Każda opinia w nowej linii
    return implode( "\n", $lines );
} );


/* ----------------------------------------------------------
 * 10. Shortcode – pojedyncze pole z konkretnej opinii
 *
 * [bm_review_field id="123" field="rating_overall"]
 * [bm_review_field id="123" field="text"]
 * [bm_review_field id="123" field="author"]
 * [bm_review_field id="123" field="date"]
 *
 * Jeśli id NIE zostanie podane, a jesteśmy na poście bm_review –
 * użyje bieżącego posta.
 * ---------------------------------------------------------- */
if ( ! function_exists( 'bm_get_review_id_from_context' ) ) {
    function bm_get_review_id_from_context( $forced_id = 0 ) {
        $forced_id = (int) $forced_id;
        if ( $forced_id > 0 ) {
            return $forced_id;
        }

        global $post;
        if ( $post && $post->post_type === 'bm_review' ) {
            return (int) $post->ID;
        }

        return 0;
    }
}

add_shortcode( 'bm_review_field', function ( $atts ) {
    $atts = shortcode_atts( [
        'id'    => 0,
        'field' => '',
    ], $atts, 'bm_review_field' );

    $review_id = bm_get_review_id_from_context( (int) $atts['id'] );
    if ( ! $review_id ) {
        return '';
    }

    $field = sanitize_key( $atts['field'] );
    $value = '';

    switch ( $field ) {
        // Meta ratingi
        case 'rating_overall':
        case 'rating_quality':
        case 'rating_timeliness':
        case 'rating_communication':
        case 'rating_payment':
        case 'rating_recommend':
        case 'rating_cooperation':
            $meta_key = '_bm_' . $field;
            $value    = get_post_meta( $review_id, $meta_key, true );
            break;

        // Treść opinii
        case 'text':
            $content = get_post_field( 'post_content', $review_id );
            $value   = wp_strip_all_tags( $content );
            break;

        // Autor opinii – nazwa
        case 'author':
            $author_id   = (int) get_post_meta( $review_id, '_bm_review_author_id', true );
            $author_user = $author_id ? get_userdata( $author_id ) : null;
            $value       = $author_user ? $author_user->display_name : '';
            break;

        // ID autora opinii
        case 'author_id':
            $value = (int) get_post_meta( $review_id, '_bm_review_author_id', true );
            break;

        // ID ocenianego użytkownika
        case 'target_id':
            $value = (int) get_post_meta( $review_id, '_bm_review_target_id', true );
            break;

        // Data opinii
        case 'date':
            $value = get_the_date( '', $review_id );
            break;

        default:
            $value = '';
            break;
    }

    if ( is_array( $value ) ) {
        $value = implode( ',', $value );
    }

    return esc_html( (string) $value );
} );


/* ----------------------------------------------------------
 * 11. Shortcode aliasy – uproszczone użycie w szablonach
 * ---------------------------------------------------------- */
/**
 * [bm_rating_avg field="rating_quality"]
 *
 * Skrócony alias do szczegółowych statystyk ocen użytkownika.
 * Zwraca:
 *  - dla pól liczbowych (overall, quality, timeliness, communication, payment)
 *    średnią ocenę w skali 1–5
 *  - dla pól tak/nie (recommend, cooperation)
 *    procent odpowiedzi "tak" (0–100)
 *
 * Działa w oparciu o aktualny kontekst (wizytówka dostawcy, oferta, loop)
 * lub na podstawie parametru user_id="123".
 *
 * Przykłady:
 *  [bm_rating_avg field="rating_quality"]
 *  [bm_rating_avg field="rating_recommend"]
 *  [bm_rating_avg user_id="40" field="rating_payment"]
 */
add_shortcode( 'bm_rating_avg', function( $atts ) {
    $atts = shortcode_atts( [
        'user_id'     => 0,
        'field'       => 'rating_overall',
        'empty_label' => 'Brak opinii',
    ], $atts, 'bm_rating_avg' );

    // Ustal użytkownika z kontekstu lub z parametru
    $user_id = bm_get_user_id_from_context( (int) $atts['user_id'] );
    if ( ! $user_id ) {
        return esc_html( $atts['empty_label'] );
    }

    // Jeśli helper statystyk nie istnieje – bezpieczny fallback
    if ( ! function_exists( 'bm_get_user_detailed_rating_stats' ) ) {
        return esc_html( $atts['empty_label'] );
    }

    // Normalizacja nazwy pola – akceptujemy np. "rating_quality" albo "quality"
    $field = sanitize_key( $atts['field'] );
    if ( strpos( $field, 'rating_' ) === 0 ) {
        $field = substr( $field, 7 ); // utnij prefiks "rating_"
    }

    $stats = bm_get_user_detailed_rating_stats( $user_id );
    if ( empty( $stats[ $field ] ) ) {
        return esc_html( $atts['empty_label'] );
    }

    // Pola liczbowe 1–5
    if ( in_array( $field, [ 'overall', 'quality', 'timeliness', 'communication', 'payment' ], true ) ) {
        if ( empty( $stats[ $field ]['count'] ) || $stats[ $field ]['avg'] <= 0 ) {
            return esc_html( $atts['empty_label'] );
        }
        // Średnia np. 4.5
        return esc_html( (string) $stats[ $field ]['avg'] );
    }

    // Pola tak/nie – recommend / cooperation
    if ( in_array( $field, [ 'recommend', 'cooperation' ], true ) ) {
        if ( empty( $stats[ $field ]['total'] ) ) {
            return esc_html( $atts['empty_label'] );
        }
        // Procent odpowiedzi "tak", np. 80.5
        return esc_html( (string) $stats[ $field ]['percent'] );
    }

    // Nieznane pole – zwracamy etykietę "brak opinii"
    return esc_html( $atts['empty_label'] );
} );


/* ----------------------------------------------------------
 * Shortcode – podsumowanie ocen użytkownika na kartach/wizytówkach
 *
 * [bm_rating_summary]
 * [bm_rating_summary user_id="40"]
 *
 * Zwraca:
 * - gdy są opinie:   "X.X (N opinii)" + druga linia "Y% poleceń"
 * - gdy brak opinii: "Brak opinii"
 *
 * Działa w oparciu o:
 *  - post_author (wizytówka, oferta, loop dostawców)
 *  - lub parametr user_id
 * ---------------------------------------------------------- */
add_shortcode( 'bm_rating_summary', function( $atts ) {

    $atts = shortcode_atts( [
        'user_id'     => 0,
        'empty_label' => 'Brak opinii',
    ], $atts, 'bm_rating_summary' );

    // Ustal użytkownika z kontekstu lub z parametru
    if ( ! function_exists( 'bm_get_user_id_from_context' ) ) {
        return esc_html( $atts['empty_label'] );
    }

    $user_id = bm_get_user_id_from_context( (int) $atts['user_id'] );
    if ( ! $user_id ) {
        return esc_html( $atts['empty_label'] );
    }

    // Musimy mieć helper ze szczegółowymi statystykami
    if ( ! function_exists( 'bm_get_user_detailed_rating_stats' ) ) {
        return esc_html( $atts['empty_label'] );
    }

    $stats = bm_get_user_detailed_rating_stats( $user_id );
    if ( empty( $stats ) || empty( $stats['overall'] ) || empty( $stats['overall']['count'] ) || $stats['overall']['avg'] <= 0 ) {
        // Brak jakichkolwiek ocen
        return esc_html( $atts['empty_label'] );
    }

    // Średnia ogólna
    $avg   = (float) $stats['overall']['avg'];
    $count = (int) $stats['overall']['count'];

    // 1. linia: "5.0 (1 opinii)"
    $line1 = sprintf( '%.1f (%d opinii)', $avg, $count );

    // 2. linia: "100% poleceń" – tylko jeśli mamy dane o poleceniach
    $line2 = '';
    if ( ! empty( $stats['recommend'] ) && ! empty( $stats['recommend']['total'] ) ) {
        // procent "yes"
        $percent = (float) $stats['recommend']['percent']; // np. 100.0, 80.5
        // Zaokrąglamy do 0 miejsc – 100, 81, 65 itd.
        $percent_rounded = round( $percent );

        $line2 = sprintf( '%d%% poleceń', $percent_rounded );
    }

    // Składamy HTML – z klasami, żeby można było łatwo ostylować
    $html  = '<span class="bm-rating-summary-average">' . esc_html( $line1 ) . '</span>';

    if ( $line2 !== '' ) {
        $html .= '<br /><span class="bm-rating-summary-recommend">' . esc_html( $line2 ) . '</span>';
    }

    return $html;
} );


/* ----------------------------------------------------------
 * SHORTCODE: Stylowalna lista wszystkich opinii o dostawcy
 * [bm_reviews_full]
 * ---------------------------------------------------------- */

add_shortcode('bm_reviews_full', function () {

    global $post;

    // 1) Ustalenie ID użytkownika, którego dotyczy wizytówka
    if (!$post) {
        return '<p>Brak opinii.</p>';
    }

    $author_id = $post->post_author;

    if (!$author_id) {
        return '<p>Brak opinii.</p>';
    }

    // 2) Pobranie wszystkich opinii o tym użytkowniku
    $reviews = get_posts([
        'post_type'   => 'bm_review',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => [
            [
                'key'   => '_bm_review_target_id',
                'value' => $author_id
            ]
        ],
        'orderby'     => 'date',
        'order'       => 'DESC'
    ]);

    // 3) Jeśli brak opinii – JEDEN komunikat
    if (empty($reviews)) {
        return '<p class="bm-no-reviews">Brak opinii.</p>';
    }

    // 4) Generowanie HTML listy opinii
    $html = '<div class="bm-reviews-wrapper">';

    foreach ($reviews as $review) {

        $rid   = $review->ID;
        $date  = get_the_date('Y-m-d', $rid);
        $text  = wpautop(get_post_field('post_content', $rid));

        // Pobranie meta
        $rating_overall       = get_post_meta($rid, '_bm_rating_overall', true);
        $rating_quality       = get_post_meta($rid, '_bm_rating_quality', true);
        $rating_timeliness    = get_post_meta($rid, '_bm_rating_timeliness', true);
        $rating_communication = get_post_meta($rid, '_bm_rating_communication', true);
        $rating_payment       = get_post_meta($rid, '_bm_rating_payment', true);
        $rating_recommend     = get_post_meta($rid, '_bm_rating_recommend', true);
        $rating_cooperation   = get_post_meta($rid, '_bm_rating_cooperation', true);

        // Autor opinii
        $author_id_review = get_post_meta($rid, '_bm_review_author_id', true);
        $author_data      = get_userdata($author_id_review);
        $author_name      = $author_data ? $author_data->display_name : 'Użytkownik';

        // 5) Budujemy kartę opinii
        $html .= '<div class="bm-review-card">';

        $html .= '<div class="bm-review-head">';
        $html .= '<strong class="bm-review-author">' . esc_html($author_name) . '</strong>';
        $html .= '<span class="bm-review-date">' . esc_html($date) . '</span>';
        $html .= '<span class="bm-review-rating">Ocena ogólna: <strong>' . esc_html($rating_overall) . '/5</strong></span>';
        $html .= '</div>';

        $html .= '<div class="bm-review-details">';

        if ($rating_quality !== '') {
            $html .= '<div><strong>Jakość: </strong>' . esc_html($rating_quality) . '/5</div>';
        }
        if ($rating_timeliness !== '') {
            $html .= '<div><strong>Terminowość: </strong>' . esc_html($rating_timeliness) . '/5</div>';
        }
        if ($rating_communication !== '') {
            $html .= '<div><strong>Komunikacja: </strong>' . esc_html($rating_communication) . '/5</div>';
        }
        if ($rating_payment !== '') {
            $html .= '<div><strong>Płatność: </strong>' . esc_html($rating_payment) . '/5</div>';
        }
        if ($rating_recommend !== '') {
            $html .= '<div><strong>Polecam: </strong>' . ($rating_recommend === 'yes' ? 'Tak' : 'Nie') . '</div>';
        }
        if ($rating_cooperation !== '') {
            $html .= '<div><strong>Współpraca: </strong>' . ($rating_cooperation === 'yes' ? 'Tak' : 'Nie') . '</div>';
        }

        $html .= '</div>'; // .bm-review-details

        if ($text !== '') {
            $html .= '<div class="bm-review-text"><strong>Opinia:</strong>' . $text . '</div>';
        }

        $html .= '</div>'; // .bm-review-card
    }

    $html .= '</div>'; // .bm-reviews-wrapper

    return $html;
});


/* ----------------------------------------------------------
 * Shortcode: [bm_reviews_detailed]
 * Szczegółowe opinie na wizytówce dostawcy
 * - automatycznie bierze ID użytkownika z autora aktualnego posta
 * - pokazuje TYLKO opinie wystawione temu użytkownikowi (target_id)
 * - dla opinii z portalu (powiązanych z bm_deal) dodaje nagłówek
 *   "Opinia z portalu BrandManager ★"
 * ---------------------------------------------------------- */
/* ----------------------------------------------------------
 * Shortcode: [bm_reviews_detailed]
 * Szczegółowe opinie na wizytówce dostawcy
 * ---------------------------------------------------------- */
add_shortcode('bm_reviews_detailed', function ($atts) {
    $atts = shortcode_atts([
        'user_id' => 0,   // można podać ręcznie, ale na wizytówce nie trzeba
        'limit'   => -1,  // -1 = wszystkie
    ], $atts, 'bm_reviews_detailed');

    // 1. Ustalamy użytkownika (dostawcę) – domyślnie autor aktualnego posta
    $user_id = (int) $atts['user_id'];
    if (!$user_id) {
        global $post;
        if ($post) {
            $user_id = (int) $post->post_author;
        }
    }

    if (!$user_id) {
        return '<p>Brak opinii.</p>';
    }

    // 2. Pobieramy wszystkie opinie, w których ten użytkownik jest ocenianym (target_id)
    $numberposts = (int) $atts['limit'] > 0 ? (int) $atts['limit'] : -1;

    $reviews = get_posts([
        'post_type'   => 'bm_review',
        'post_status' => 'publish',
        'numberposts' => $numberposts,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'meta_query'  => [
            [
                'key'   => '_bm_review_target_id',
                'value' => $user_id,
            ],
        ],
    ]);

    if (empty($reviews)) {
        return '<p>Brak opinii.</p>';
    }

    $out = '<div class="bm-reviews-detailed">';

    foreach ($reviews as $review) {
        $review_id = (int) $review->ID;

        // Źródło opinii
        $deal_id      = (int) get_post_meta($review_id, '_bm_review_deal_id', true);
        $source       = get_post_meta($review_id, '_bm_review_source', true); // 'external' dla opinii spoza portalu
        $is_portal    = $deal_id > 0;
        $is_external  = ($source === 'external');

        // Autor / nazwa wystawiającego
        if ($is_external) {
            $author_name = trim((string) get_post_meta($review_id, '_bm_external_name', true));
            if ($author_name === '') {
                $author_name = 'Użytkownik';
            }
        } else {
            $author_id   = (int) get_post_meta($review_id, '_bm_review_author_id', true);
            $author_user = $author_id ? get_userdata($author_id) : null;
            $author_name = $author_user ? $author_user->display_name : 'Użytkownik';
        }

        $date            = get_the_date('Y-m-d', $review_id);
        $rating_overall  = (int) get_post_meta($review_id, '_bm_rating_overall', true);
        $rating_quality  = (int) get_post_meta($review_id, '_bm_rating_quality', true);
        $rating_time     = (int) get_post_meta($review_id, '_bm_rating_timeliness', true);
        $rating_recommend = get_post_meta($review_id, '_bm_rating_recommend', true);
        $text            = get_post_field('post_content', $review_id);
        $text            = trim($text);

        $out .= '<div class="bm-review-card">';

        // Nagłówek źródła opinii
        if ($is_portal) {
            $out .= '<div class="bm-review-label bm-review-label-portal">Opinia z portalu BrandManager &#9733;</div>';
        } elseif ($is_external) {
            $out .= '<div class="bm-review-label bm-review-label-external">Opinia spoza portalu BrandManager – niezweryfikowana</div>';
        }

        // Główka: Od + data + ocena ogólna
        $out .= '<div class="bm-review-head">';
        $out .= '<div class="bm-review-from"><strong>Od:</strong> ' . esc_html($author_name);
        if ($date) {
            $out .= ' <span class="bm-review-date">' . esc_html($date) . '</span>';
        }
        $out .= '</div>';

        if ($rating_overall > 0) {
            $out .= '<div class="bm-review-overall">Ocena ogólna: <strong>' . esc_html($rating_overall) . '/5</strong></div>';
        }
        $out .= '</div>'; // .bm-review-head

        // Szczegółowe pola – TYLKO dla dostawcy: jakość, terminowość, polecam
        $out .= '<div class="bm-review-details">';

        if ($rating_quality > 0) {
            $out .= '<div class="bm-review-row bm-review-quality"><strong>Jakość:</strong> ' . esc_html($rating_quality) . '/5</div>';
        }

        if ($rating_time > 0) {
            $out .= '<div class="bm-review-row bm-review-time"><strong>Terminowość:</strong> ' . esc_html($rating_time) . '/5</div>';
        }

        if ($rating_recommend !== '') {
            $label = ($rating_recommend === 'yes') ? 'Tak' : 'Nie';
            $out .= '<div class="bm-review-row bm-review-recommend"><strong>Polecam:</strong> ' . esc_html($label) . '</div>';
        }

        $out .= '</div>'; // .bm-review-details

        // Treść opinii
        if ($text !== '') {
            $out .= '<div class="bm-review-text"><strong>Opinia:</strong> ' . wp_kses_post(wpautop($text)) . '</div>';
        }

        $out .= '</div>'; // .bm-review-card
    }

    $out .= '</div>'; // .bm-reviews-detailed

    return $out;
});


/* ----------------------------------------------------------
 * B. Sekcja w "Moje konto → Opinie" – prośba o opinię spoza portalu
 * ---------------------------------------------------------- */
function bm_render_external_review_request_section()
{
    if (!is_user_logged_in()) {
        return;
    }

    $current_user = wp_get_current_user();
    $roles        = (array) $current_user->roles;

    // Tylko dostawca może prosić o opinię
    if (!in_array('dostawca', $roles, true)) {
        return;
    }

    $supplier_id = (int) $current_user->ID;

    // Obsługa wysłania formularza prośby o opinię
    if (!empty($_POST['bm_ext_request_submit']) && !empty($_POST['_bm_ext_request_nonce'])) {

        if (!wp_verify_nonce(sanitize_text_field($_POST['_bm_ext_request_nonce']), 'bm_ext_request')) {
            echo '<div class="woocommerce-error" role="alert">Nieprawidłowe dane formularza. Spróbuj ponownie.</div>';
        } else {

            $company_name = isset($_POST['bm_ext_company']) ? sanitize_text_field($_POST['bm_ext_company']) : '';
            $project_name = isset($_POST['bm_ext_project']) ? sanitize_text_field($_POST['bm_ext_project']) : '';
            $project_desc = isset($_POST['bm_ext_desc']) ? wp_kses_post($_POST['bm_ext_desc']) : '';
            $client_email = isset($_POST['bm_ext_email']) ? sanitize_email($_POST['bm_ext_email']) : '';

            if (!$company_name || !$project_name || !$client_email) {
                echo '<div class="woocommerce-error" role="alert">Uzupełnij wymagane pola: nazwa firmy, nazwa projektu, email klienta.</div>';
            } elseif (!is_email($client_email)) {
                echo '<div class="woocommerce-error" role="alert">Podany adres email jest nieprawidłowy.</div>';
            } elseif (bm_has_review_request_for_email($supplier_id, $client_email)) {
                echo '<div class="woocommerce-error" role="alert">Dla tego adresu email już wysłano prośbę o opinię.</div>';
            } else {

                // Tworzymy prośbę
                $title = sprintf('Prośba o opinię: %s – %s', $company_name, $project_name);

                $request_id = wp_insert_post([
                    'post_type'   => 'bm_review_request',
                    'post_status' => 'publish',
                    'post_title'  => $title,
                ]);

                if (!$request_id || is_wp_error($request_id)) {
                    echo '<div class="woocommerce-error" role="alert">Wystąpił błąd podczas zapisu prośby o opinię.</div>';
                } else {

                    $token   = wp_generate_password(32, false);
                    $created = current_time('timestamp');

                    update_post_meta($request_id, '_bm_rr_supplier_id', $supplier_id);
                    update_post_meta($request_id, '_bm_rr_email', $client_email);
                    update_post_meta($request_id, '_bm_rr_company', $company_name);
                    update_post_meta($request_id, '_bm_rr_project', $project_name);
                    update_post_meta($request_id, '_bm_rr_desc', $project_desc);
                    update_post_meta($request_id, '_bm_rr_token', $token);
                    update_post_meta($request_id, '_bm_rr_created', $created);
                    update_post_meta($request_id, '_bm_rr_used', 0);

                    // Link do wystawienia opinii – używamy home_url(), żeby działało po przeniesieniu domeny
                    $review_url = add_query_arg(
                        'token',
                        rawurlencode($token),
                        home_url('/opinia/')
                    );

                    // Mail do klienta
                    $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
                    $subject  = sprintf('Prośba o opinię – %s', $company_name);

                    $message  = "Dzień dobry,\n\n";
                    $message .= sprintf("Firma %s prosi o wystawienie opinii w portalu BrandManager.\n\n", $company_name);
                    if ($project_name) {
                        $message .= "Nazwa projektu: " . $project_name . "\n";
                    }
                    if ($project_desc) {
                        $message .= "Opis projektu:\n" . wp_strip_all_tags($project_desc) . "\n\n";
                    }
                    $message .= "Aby wystawić opinię, kliknij w poniższy link (ważny 48 godzin):\n";
                    $message .= $review_url . "\n\n";
                    $message .= "Pozdrawiamy,\nZespół " . $blogname . "\n";
                    $message .= home_url('/') . "\n";

                    wp_mail($client_email, $subject, $message);

                    echo '<div class="woocommerce-message" role="alert">Prośba o opinię została wysłana.</div>';
                }
            }
        }
    }

    // Formularz "Poproś o opinię"
    echo '<hr />';
    echo '<h3>Poproś o opinię spoza portalu</h3>';
    echo '<p>Wyślij prośbę o opinię do swojego klienta. Otrzyma on link do formularza i będzie mógł wystawić opinię bez logowania.</p>';

    echo '<form method="post" class="bm-ext-review-request-form">';
    wp_nonce_field('bm_ext_request', '_bm_ext_request_nonce');

    echo '<p><label>Nazwa Twojej firmy <span class="required">*</span><br />';
    echo '<input type="text" name="bm_ext_company" required /></label></p>';

    echo '<p><label>Nazwa projektu <span class="required">*</span><br />';
    echo '<input type="text" name="bm_ext_project" required /></label></p>';

    echo '<p><label>Krótki opis projektu (opcjonalnie)<br />';
    echo '<textarea name="bm_ext_desc" rows="3"></textarea></label></p>';

    echo '<p><label>Adres email klienta <span class="required">*</span><br />';
    echo '<input type="email" name="bm_ext_email" required /></label></p>';

    echo '<p><button type="submit" class="button" name="bm_ext_request_submit" value="1">Wyślij prośbę o opinię</button></p>';

    echo '</form>';
}
add_action('woocommerce_account_opinions_endpoint', 'bm_render_external_review_request_section', 20);

/* ----------------------------------------------------------
 * C. Shortcode [bm_external_review_form] – formularz opinii spoza portalu
 *     Użycie: w treści strony "Opinia" wstaw [bm_external_review_form]
 *     URL w mailu: https://twojadomena/opinia/?token=XYZ
 * ---------------------------------------------------------- */
add_shortcode('bm_external_review_form', function () {

    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

    if (!$token) {
        return '<p>Nieprawidłowy link do opinii.</p>';
    }

    // Szukamy prośby o opinię po tokenie
    $requests = get_posts([
        'post_type'   => 'bm_review_request',
        'post_status' => 'any',
        'numberposts' => 1,
        'meta_query'  => [
            [
                'key'   => '_bm_rr_token',
                'value' => $token,
            ],
        ],
    ]);

    if (empty($requests)) {
        return '<p>Link do opinii jest nieprawidłowy lub wygasł.</p>';
    }

    $request    = $requests[0];
    $request_id = (int) $request->ID;

    $supplier_id = (int) get_post_meta($request_id, '_bm_rr_supplier_id', true);
    $client_email = get_post_meta($request_id, '_bm_rr_email', true);
    $company_name = get_post_meta($request_id, '_bm_rr_company', true);
    $project_name = get_post_meta($request_id, '_bm_rr_project', true);
    $project_desc = get_post_meta($request_id, '_bm_rr_desc', true);
    $created      = (int) get_post_meta($request_id, '_bm_rr_created', true);
    $used         = (int) get_post_meta($request_id, '_bm_rr_used', true);

    // Sprawdzamy ważność tokenu (48h) i czy nie został wykorzystany
    $now      = current_time('timestamp');
    $lifetime = 48 * HOUR_IN_SECONDS;

    if ($used || ($created && ($created + $lifetime) < $now)) {
        return '<p>Link do wystawienia opinii wygasł lub został już wykorzystany.</p>';
    }

    $output = '';

    // Obsługa wysłania formularza opinii
    if (!empty($_POST['bm_ext_review_submit']) && !empty($_POST['_bm_ext_review_nonce'])) {

        if (!wp_verify_nonce(sanitize_text_field($_POST['_bm_ext_review_nonce']), 'bm_ext_review_' . $token)) {
            $output .= '<div class="bm-ext-error">Nieprawidłowe dane formularza. Spróbuj ponownie.</div>';
        } else {

            $reviewer_name  = isset($_POST['bm_ext_reviewer_name']) ? sanitize_text_field($_POST['bm_ext_reviewer_name']) : '';
            $reviewer_email = isset($_POST['bm_ext_reviewer_email']) ? sanitize_email($_POST['bm_ext_reviewer_email']) : '';

            $rating_overall       = isset($_POST['bm_rating_overall']) ? (int) $_POST['bm_rating_overall'] : 0;
            $rating_quality       = isset($_POST['bm_rating_quality']) ? (int) $_POST['bm_rating_quality'] : 0;
            $rating_timeliness    = isset($_POST['bm_rating_timeliness']) ? (int) $_POST['bm_rating_timeliness'] : 0;
            $rating_recommend     = isset($_POST['bm_rating_recommend']) ? sanitize_text_field($_POST['bm_rating_recommend']) : '';
            $rating_communication = isset($_POST['bm_rating_communication']) ? (int) $_POST['bm_rating_communication'] : 0;
            $rating_payment       = isset($_POST['bm_rating_payment']) ? (int) $_POST['bm_rating_payment'] : 0;
            $rating_cooperation   = isset($_POST['bm_rating_cooperation']) ? sanitize_text_field($_POST['bm_rating_cooperation']) : '';
            $review_text          = isset($_POST['bm_review_text']) ? wp_kses_post($_POST['bm_review_text']) : '';

            if ($rating_overall < 1 || $rating_overall > 5) {
                $output .= '<div class="bm-ext-error">Podaj ogólną ocenę w skali 1–5.</div>';
            } else {

                // Tworzymy opinię – autor 0 (gość), oceniany = dostawca (supplier_id)
                $supplier_user = $supplier_id ? get_userdata($supplier_id) : null;
                $supplier_name = $supplier_user ? $supplier_user->display_name : 'Dostawca';

                $post_title = sprintf('Opinia spoza portalu – %s (%s)', $company_name, $project_name);

                $review_id = wp_insert_post([
                    'post_type'    => 'bm_review',
                    'post_status'  => 'publish',
                    'post_title'   => $post_title,
                    'post_content' => $review_text,
                    'post_author'  => 0, // gość
                ]);

                if (!$review_id || is_wp_error($review_id)) {
                    $output .= '<div class="bm-ext-error">Wystąpił błąd podczas zapisu opinii. Spróbuj ponownie później.</div>';
                } else {

                    update_post_meta($review_id, '_bm_review_author_id', 0);
                    update_post_meta($review_id, '_bm_review_target_id', $supplier_id);
                    update_post_meta($review_id, '_bm_review_deal_id', 0);
                    update_post_meta($review_id, '_bm_review_role', 'external');
                    update_post_meta($review_id, '_bm_rating_overall', $rating_overall);
                    update_post_meta($review_id, '_bm_rating_quality', $rating_quality);
                    update_post_meta($review_id, '_bm_rating_timeliness', $rating_timeliness);
                    update_post_meta($review_id, '_bm_rating_recommend', $rating_recommend);
                    update_post_meta($review_id, '_bm_rating_communication', $rating_communication);
                    update_post_meta($review_id, '_bm_rating_payment', $rating_payment);
                    update_post_meta($review_id, '_bm_rating_cooperation', $rating_cooperation);
                    update_post_meta($review_id, '_bm_review_locked', 1);
                    update_post_meta($review_id, '_bm_review_source', 'external');
                    update_post_meta($review_id, '_bm_external_name', $reviewer_name);
                    update_post_meta($review_id, '_bm_external_email', $reviewer_email);
                    // Powiązanie z portfolio (jeśli prośba wyszła z realizacji)
                    $portfolio_id = (int) get_post_meta($request_id, '_bm_rr_portfolio_id', true);
                    if ($portfolio_id) {
                        update_post_meta($review_id, '_bm_review_portfolio_id', $portfolio_id);
                        // dwukierunkowo – pozwala używać shortcodów w portfolio
                        update_post_meta($portfolio_id, '_bm_portfolio_review_id', $review_id);
                    }

                    // Oznaczamy prośbę jako wykorzystaną
                    update_post_meta($request_id, '_bm_rr_used', 1);

                    $output .= '<div class="bm-ext-success">Dziękujemy! Twoja opinia została zapisana.</div>';

                    return $output;
                }
            }
        }
    }

    // Formularz – tylko jeśli jeszcze nie wysłano poprawnej opinii
    $output .= '<div class="bm-ext-review-form-wrapper">';
    $output .= '<h2>Wystaw opinię dla firmy ' . esc_html($company_name) . '</h2>';

    if ($project_name) {
        $output .= '<p><strong>Projekt:</strong> ' . esc_html($project_name) . '</p>';
    }
    if ($project_desc) {
        $output .= '<p>' . wp_kses_post(wpautop($project_desc)) . '</p>';
    }

    $output .= '<form method="post" class="bm-ext-review-form">';
    $output .= wp_nonce_field('bm_ext_review_' . $token, '_bm_ext_review_nonce', true, false);

    $output .= '<p><label>Od kogo opinia (imię i nazwisko)<br />';
    $output .= '<input type="text" name="bm_ext_reviewer_name" /></label></p>';

    $output .= '<p><label>Twój adres email (opcjonalnie)<br />';
    $output .= '<input type="email" name="bm_ext_reviewer_email" value="' . esc_attr($client_email) . '" /></label></p>';

    // Ocena ogólna
    $output .= '<p><label>Ocena ogólna (1–5) <span class="required">*</span><br />';
    $output .= '<select name="bm_rating_overall" required>';
    $output .= '<option value="">-- wybierz --</option>';
    for ($i = 5; $i >= 1; $i--) {
        $output .= '<option value="' . $i . '">' . $i . '</option>';
    }
    $output .= '</select></label></p>';

    // Pola jak w portalu – możesz je w razie czego uprościć
    $output .= '<p><label>Jakość pracy (1–5)<br />';
    $output .= '<select name="bm_rating_quality">';
    $output .= '<option value="">-- wybierz --</option>';
    for ($i = 5; $i >= 1; $i--) {
        $output .= '<option value="' . $i . '">' . $i . '</option>';
    }
    $output .= '</select></label></p>';

    $output .= '<p><label>Terminowość (1–5)<br />';
    $output .= '<select name="bm_rating_timeliness">';
    $output .= '<option value="">-- wybierz --</option>';
    for ($i = 5; $i >= 1; $i--) {
        $output .= '<option value="' . $i . '">' . $i . '</option>';
    }
    $output .= '</select></label></p>';

    $output .= '<p><label>Czy polecił(a)byś tego dostawcę?<br />';
    $output .= '<select name="bm_rating_recommend">';
    $output .= '<option value="">-- wybierz --</option>';
    $output .= '<option value="yes">Tak</option>';
    $output .= '<option value="no">Nie</option>';
    $output .= '</select></label></p>';


    $output .= '<p><label>Treść opinii<br />';
    $output .= '<textarea name="bm_review_text" rows="5" cols="50"></textarea>';
    $output .= '</label></p>';

    $output .= '<p><button type="submit" class="button" name="bm_ext_review_submit" value="1">Wyślij opinię</button></p>';

    $output .= '</form>';
    $output .= '</div>';

    return $output;
});


/* ----------------------------------------------------------
 * Dodatkowy widok w "Moje konto → Opinie":
 * - Twoje wystawione opinie
 * - Opinie o Tobie
 * Nie nadpisuje istniejącego widoku – działa jako drugi callback.
 * ---------------------------------------------------------- */

if ( ! function_exists( 'bm_render_account_reviews_overview' ) ) {

    function bm_render_account_reviews_overview() {

        if ( ! is_user_logged_in() ) {
            return;
        }

        $current_id = get_current_user_id();

        // Opinie WYSTAWIONE przez aktualnego użytkownika
        $written_reviews = get_posts( [
            'post_type'   => 'bm_review',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [
                [
                    'key'   => '_bm_review_author_id',
                    'value' => $current_id,
                ],
            ],
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );

        // Opinie O aktualnym użytkowniku
        $received_reviews = get_posts( [
            'post_type'   => 'bm_review',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [
                [
                    'key'   => '_bm_review_target_id',
                    'value' => $current_id,
                ],
            ],
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );

        echo '<hr />';

        // ------------------------------
        // Twoje wystawione opinie
        // ------------------------------
        echo '<h3>Twoje wystawione opinie</h3>';

        if ( empty( $written_reviews ) ) {
            echo '<p>Nie wystawiłeś jeszcze żadnych opinii.</p>';
        } else {

            echo '<table class="shop_table shop_table_responsive bm-account-reviews-written">';
            echo '<thead><tr>';
            echo '<th>O kim</th>';
            echo '<th>Ocena ogólna</th>';
            echo '<th>Data</th>';
            echo '<th>Współpraca</th>';
            echo '</tr></thead><tbody>';

            foreach ( $written_reviews as $review ) {

                $review_id   = (int) $review->ID;
                $target_id   = (int) get_post_meta( $review_id, '_bm_review_target_id', true );
                $target_user = $target_id ? get_userdata( $target_id ) : null;
                $target_name = $target_user ? $target_user->display_name : 'Użytkownik';

                $rating      = (int) get_post_meta( $review_id, '_bm_rating_overall', true );
                $deal_id     = (int) get_post_meta( $review_id, '_bm_review_deal_id', true );
                $deal_title  = $deal_id ? get_the_title( $deal_id ) : '';

                echo '<tr>';
                echo '<td>' . esc_html( $target_name ) . '</td>';
                echo '<td>' . ( $rating ? esc_html( $rating . '/5' ) : '-' ) . '</td>';
                echo '<td>' . esc_html( get_the_date( '', $review_id ) ) . '</td>';
                echo '<td>' . esc_html( $deal_title ) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<hr />';

        // ------------------------------
        // Opinie o Tobie
        // ------------------------------
        echo '<h3>Opinie o Tobie</h3>';

        if ( empty( $received_reviews ) ) {
            echo '<p>Nie masz jeszcze żadnych opinii.</p>';
        } else {

            echo '<table class="shop_table shop_table_responsive bm-account-reviews-received">';
            echo '<thead><tr>';
            echo '<th>Autor</th>';
            echo '<th>Ocena ogólna</th>';
            echo '<th>Data</th>';
            echo '<th>Współpraca</th>';
            echo '</tr></thead><tbody>';

            foreach ( $received_reviews as $review ) {

                $review_id   = (int) $review->ID;
                $author_id   = (int) get_post_meta( $review_id, '_bm_review_author_id', true );
                $author_user = $author_id ? get_userdata( $author_id ) : null;
                $author_name = $author_user ? $author_user->display_name : 'Użytkownik';

                $rating      = (int) get_post_meta( $review_id, '_bm_rating_overall', true );
                $deal_id     = (int) get_post_meta( $review_id, '_bm_review_deal_id', true );
                $deal_title  = $deal_id ? get_the_title( $deal_id ) : '';

                echo '<tr>';
                echo '<td>' . esc_html( $author_name ) . '</td>';
                echo '<td>' . ( $rating ? esc_html( $rating . '/5' ) : '-' ) . '</td>';
                echo '<td>' . esc_html( get_the_date( '', $review_id ) ) . '</td>';
                echo '<td>' . esc_html( $deal_title ) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }
}

// Podpinamy dodatkowy widok do endpointu "opinions" – po obecnej zawartości
add_action( 'woocommerce_account_opinions_endpoint', 'bm_render_account_reviews_overview', 30 );