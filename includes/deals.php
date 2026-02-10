<?php
/**
 * BRANDMANAGER – MODUŁ WSPÓŁPRAC / DEALS
 *
 * - CPT: bm_deal
 * - Endpoint: /moje-konto/deals/
 * - Statusy: pending, accepted, rejected, finished_success, finished_failed
 * - Powiązanie 1:1 z wątkiem wiadomości (bm_thread)
 */

/* ----------------------------------------------------------
 * 1. CPT bm_deal
 * ---------------------------------------------------------- */
add_action('init', function () {
    $labels = [
        'name'               => 'Współprace',
        'singular_name'      => 'Współpraca',
        'add_new'            => 'Dodaj współpracę',
        'add_new_item'       => 'Dodaj współpracę',
        'edit_item'          => 'Edytuj współpracę',
        'new_item'           => 'Nowa współpraca',
        'view_item'          => 'Zobacz współpracę',
        'search_items'       => 'Szukaj współprac',
        'not_found'          => 'Nie znaleziono współprac',
        'not_found_in_trash' => 'Brak współprac w koszu',
        'all_items'          => 'Wszystkie współprace',
    ];

    register_post_type('bm_deal', [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-groups',
        'supports'           => ['title', 'author'],
        'capability_type'    => 'post',
        'has_archive'        => false,
        'publicly_queryable' => false,
        'show_in_rest'       => false,
    ]);
});

/* ----------------------------------------------------------
 * 2. Endpoint w Moje konto + wpis w menu
 * ---------------------------------------------------------- */
add_action('init', function () {
    add_rewrite_endpoint('deals', EP_ROOT | EP_PAGES);
});

add_filter('woocommerce_account_menu_items', function ($items) {
    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) {
        return $items;
    }

    $roles = (array) $user->roles;

    if (!in_array('dostawca', $roles, true) && !in_array('zamawiajacy', $roles, true)) {
        return $items;
    }

    if (!isset($items['deals'])) {
        $items['deals'] = 'Współprace';
    }

    return $items;
});

/* ----------------------------------------------------------
 * 3. Helper – utworzenie nowej współpracy
 * ---------------------------------------------------------- */
if (!function_exists('bm_create_deal')) {
    function bm_create_deal($thread_id, $initiator_id, $other_id, $source_id, $source_type)
    {
        $thread_id    = (int) $thread_id;
        $initiator_id = (int) $initiator_id;
        $other_id     = (int) $other_id;
        $source_id    = (int) $source_id;
        $source_type  = sanitize_key($source_type);

        if (!$thread_id || !$initiator_id || !$other_id || !$source_id || !$source_type) {
            return 0;
        }

        $source_title = get_the_title($source_id);
        $title        = 'Współpraca: ' . ($source_title ?: 'oferta');

        $deal_id = wp_insert_post([
            'post_type'   => 'bm_deal',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => $initiator_id,
        ]);

        if (!$deal_id || is_wp_error($deal_id)) {
            return 0;
        }

        $user_1 = min($initiator_id, $other_id);
        $user_2 = max($initiator_id, $other_id);

        update_post_meta($deal_id, '_bm_thread_id', $thread_id);
        update_post_meta($deal_id, '_bm_user_1', $user_1);
        update_post_meta($deal_id, '_bm_user_2', $user_2);
        update_post_meta($deal_id, '_bm_initiator', $initiator_id);
        update_post_meta($deal_id, '_bm_receiver', $other_id);
        update_post_meta($deal_id, '_bm_source_type', $source_type);
        update_post_meta($deal_id, '_bm_source_id', $source_id);
        update_post_meta($deal_id, '_bm_source_title', $source_title);
        update_post_meta($deal_id, '_bm_status', 'accepted');

        return (int) $deal_id;
    }
}

/* ----------------------------------------------------------
 * 4. Helper – deal po wątku
 * ---------------------------------------------------------- */
if (!function_exists('bm_get_deal_by_thread')) {
    function bm_get_deal_by_thread($thread_id)
    {
        $thread_id = (int) $thread_id;
        if (!$thread_id) {
            return 0;
        }

        $deal = get_posts([
            'post_type'   => 'bm_deal',
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_query'  => [
                [
                    'key'   => '_bm_thread_id',
                    'value' => $thread_id,
                ],
            ],
        ]);

        return !empty($deal) ? (int) $deal[0]->ID : 0;
    }
}

/* ----------------------------------------------------------
 * 5. Helper – druga strona współpracy
 * ---------------------------------------------------------- */
if (!function_exists('bm_deal_other_user')) {
    function bm_deal_other_user($deal_id, $current_user_id)
    {
        $deal_id        = (int) $deal_id;
        $current_user_id = (int) $current_user_id;

        $u1 = (int) get_post_meta($deal_id, '_bm_user_1', true);
        $u2 = (int) get_post_meta($deal_id, '_bm_user_2', true);

        if ($current_user_id === $u1) return $u2;
        if ($current_user_id === $u2) return $u1;

        return 0;
    }
}

/* ----------------------------------------------------------
 * 6. Helper – etykieta statusu
 * ---------------------------------------------------------- */
if (!function_exists('bm_deal_status_label')) {
    function bm_deal_status_label($status)
    {
        return [
            'pending'          => 'Oczekuje na akceptację',
            'accepted'         => 'Współpraca w toku',
            'rejected'         => 'Odrzucona',
            'finished_success' => 'Zakończona – udana',
            'finished_failed'  => 'Zakończona – nieudana',
        ][$status] ?? ($status ?: 'brak');
    }
}

/* ----------------------------------------------------------
 * 7. Endpoint deals – lista współprac
 * ---------------------------------------------------------- */
add_action('woocommerce_account_deals_endpoint', function () {

    if (!is_user_logged_in()) {
        echo '<p>Musisz być zalogowany, aby zarządzać współpracami.</p>';
        return;
    }

    $current_id = get_current_user_id();

    /* ----------------------------------------------
     * Obsługa zakończenia współpracy + redirect
     * ---------------------------------------------- */
    if (!empty($_POST['bm_finish_deal']) &&
        !empty($_POST['bm_finish_deal_id']) &&
        !empty($_POST['_bm_finish_deal_nonce'])) {

        $deal_to_finish = (int) $_POST['bm_finish_deal_id'];

        if ($deal_to_finish &&
            wp_verify_nonce($_POST['_bm_finish_deal_nonce'], 'bm_finish_deal_' . $deal_to_finish)) {

            $u1 = (int) get_post_meta($deal_to_finish, '_bm_user_1', true);
            $u2 = (int) get_post_meta($deal_to_finish, '_bm_user_2', true);

            if ($current_id === $u1 || $current_id === $u2) {

                update_post_meta($deal_to_finish, '_bm_status', 'finished_success');

                $redirect = add_query_arg(['deal' => $deal_to_finish], wc_get_account_endpoint_url('opinions'));
                wp_safe_redirect($redirect);
                exit;
            }
        }
    }

    /* ----------------------------------------------
     * Pobranie współprac użytkownika
     * ---------------------------------------------- */
    $deals = get_posts([
        'post_type'   => 'bm_deal',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => [
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
        'orderby' => 'date',
        'order'   => 'DESC',
    ]);

    if (!$deals) {
        echo '<p>Nie masz jeszcze żadnych współprac.</p>';
        return;
    }

    /* ----------------------------------------------
     * Tabela współprac
     * ---------------------------------------------- */
    echo '<h3>Twoje współprace</h3>';

    echo '<table class="bm-deals-table" style="width:100%;border-collapse:collapse;margin-top:10px;">
        <thead>
            <tr>
                <th>Druga strona</th>
                <th>Dotyczy</th>
                <th>Status</th>
                <th>Akcje</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($deals as $deal) {

        $deal_id = $deal->ID;
        $status  = get_post_meta($deal_id, '_bm_status', true) ?: 'accepted';

        $other_id   = bm_deal_other_user($deal_id, $current_id);
        $other_user = $other_id ? get_userdata($other_id) : null;
        $other_name = $other_user->display_name ?? 'Użytkownik';

        $source_id    = (int) get_post_meta($deal_id, '_bm_source_id', true);
        $source_title = get_post_meta($deal_id, '_bm_source_title', true) ?: get_the_title($source_id);
        $source_link  = $source_id ? get_permalink($source_id) : '';

        echo '<tr>';

        echo '<td>' . esc_html($other_name) . '</td>';

        echo '<td>';
        if ($source_link) {
            echo '<a href="' . esc_url($source_link) . '" target="_blank">' . esc_html($source_title) . '</a>';
        } else {
            echo esc_html($source_title);
        }
        echo '</td>';

        echo '<td><span class="bm-deal-status-badge bm-deal-status-' . esc_attr($status) . '">' .
            esc_html(bm_deal_status_label($status)) . '</span></td>';

        /* ----------------------------------------------
         * AKCJE
         * ---------------------------------------------- */
        echo '<td>';

        // 1. Współpraca trwa -> przycisk zakończenia
        if ($status === 'accepted') {

            echo '<form method="post" style="margin:0;">';
            echo '<input type="hidden" name="bm_finish_deal_id" value="' . esc_attr($deal_id) . '">';
            wp_nonce_field('bm_finish_deal_' . $deal_id, '_bm_finish_deal_nonce');
            echo '<button class="button" type="submit" name="bm_finish_deal" value="1">Zakończ współpracę</button>';
            echo '</form>';

        }

        // 2. Współpraca zakończona -> wystaw opinię (jeśli brak)
        elseif ($status === 'finished_success') {

            if (function_exists('bm_user_has_review_for_deal') &&
                !bm_user_has_review_for_deal($deal_id, $current_id)) {

                $review_link = add_query_arg(['deal' => $deal_id], wc_get_account_endpoint_url('opinions'));
                echo '<a class="button" href="' . esc_url($review_link) . '">Wystaw opinię</a>';

            } else {
                echo '<span style="font-size:12px;color:#999;">Opinia została już wystawiona.</span>';
            }

        }

        // 3. Pozostałe statusy
        else {
            echo '<span style="font-size:12px;color:#999;">Brak dostępnych akcji</span>';
        }

        echo '</td>';

        echo '</tr>';
    }

    echo '</tbody></table>';
});
