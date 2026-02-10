<?php
/**
 * BRANDMANAGER – MODUŁ WIADOMOŚCI / CZATU
 *
 * - CPT: bm_thread (wątki)
 * - Wiadomości jako komentarze typu 'bm_message'
 * - Zakładka "Wiadomości" w Moje konto (dostawca + zamawiający)
 * - 1 wątek per: (użytkownik A, użytkownik B, źródło: ogłoszenie/oferta/wizytówka)
 * - Shortcode [bm_message_button]
 */

/* ----------------------------------------------------------
 * 1. CPT bm_thread – wątki rozmów
 * ---------------------------------------------------------- */
add_action('init', function () {
    $labels = [
        'name'               => 'Wątki wiadomości',
        'singular_name'      => 'Wątek wiadomości',
        'add_new'            => 'Dodaj wątek',
        'add_new_item'       => 'Dodaj nowy wątek',
        'edit_item'          => 'Edytuj wątek',
        'new_item'           => 'Nowy wątek',
        'view_item'          => 'Zobacz wątek',
        'search_items'       => 'Szukaj wątków',
        'not_found'          => 'Nie znaleziono wątków',
        'not_found_in_trash' => 'Brak wątków w koszu',
        'all_items'          => 'Wszystkie wątki',
    ];

    register_post_type('bm_thread', [
        'labels'              => $labels,
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-format-chat',
        'supports'            => ['title', 'author'],
        'capability_type'     => 'post',
        'has_archive'         => false,
        'show_in_rest'        => false,
        'publicly_queryable'  => false,
    ]);
});

/* ----------------------------------------------------------
 * 2. Endpoint "messages" + pozycja w menu Moje konto
 * ---------------------------------------------------------- */
add_action('init', function () {
    add_rewrite_endpoint('messages', EP_ROOT | EP_PAGES);
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'messages';
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

    if (!isset($items['messages'])) {
        // wstaw "Wiadomości" na początek
        $items = ['messages' => 'Wiadomości'] + $items;
    } else {
        $label = $items['messages'];
        unset($items['messages']);
        $items = ['messages' => $label] + $items;
    }

    return $items;
});

/* ----------------------------------------------------------
 * 3. Helper: stabilny klucz uczestników (mniejsze_id:większe_id)
 * ---------------------------------------------------------- */
if (!function_exists('bm_thread_participants_key')) {
    function bm_thread_participants_key($user_id_1, $user_id_2)
    {
        $a = (int) $user_id_1;
        $b = (int) $user_id_2;
        return ($a <= $b) ? ($a . ':' . $b) : ($b . ':' . $a);
    }
}

/* ----------------------------------------------------------
 * 4. Helper: znajdź lub utwórz wątek
 * ---------------------------------------------------------- */
if (!function_exists('bm_get_or_create_thread')) {
    /**
     * @return array [ 'thread_id' => int, 'is_new' => bool ]
     */
    function bm_get_or_create_thread($current_user_id, $other_user_id, $source_type, $source_id)
    {
        $current_user_id = (int) $current_user_id;
        $other_user_id   = (int) $other_user_id;
        $source_id       = (int) $source_id;
        $source_type     = sanitize_key($source_type);

        if (!$current_user_id || !$other_user_id || !$source_id || !$source_type) {
            return ['thread_id' => 0, 'is_new' => false];
        }

        $key = bm_thread_participants_key($current_user_id, $other_user_id);

        // Szukamy otwartego wątku dla pary + źródła
        $existing = get_posts([
            'post_type'   => 'bm_thread',
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_query'  => [
                'relation' => 'AND',
                [
                    'key'   => '_bm_thread_participants_key',
                    'value' => $key,
                ],
                [
                    'key'   => '_bm_thread_source_type',
                    'value' => $source_type,
                ],
                [
                    'key'   => '_bm_thread_source_id',
                    'value' => $source_id,
                ],
                [
                    'key'     => '_bm_thread_status',
                    'value'   => 'closed',
                    'compare' => '!=',
                ],
            ],
        ]);

        if (!empty($existing)) {
            return [
                'thread_id' => (int) $existing[0]->ID,
                'is_new'    => false,
            ];
        }

        $source_title = get_the_title($source_id);
        $title        = 'Wiadomości: ' . ($source_title ? $source_title : 'konwersacja');

        $thread_id = wp_insert_post([
            'post_type'   => 'bm_thread',
            'post_status' => 'publish',
            'post_author' => $current_user_id,
            'post_title'  => $title,
        ]);

        if (!$thread_id || is_wp_error($thread_id)) {
            return ['thread_id' => 0, 'is_new' => false];
        }

        $user_1 = min($current_user_id, $other_user_id);
        $user_2 = max($current_user_id, $other_user_id);

        update_post_meta($thread_id, '_bm_thread_participants_key', $key);
        update_post_meta($thread_id, '_bm_thread_user_1', $user_1);
        update_post_meta($thread_id, '_bm_thread_user_2', $user_2);
        update_post_meta($thread_id, '_bm_thread_status', 'open');
        update_post_meta($thread_id, '_bm_thread_source_type', $source_type);
        update_post_meta($thread_id, '_bm_thread_source_id', $source_id);
        update_post_meta($thread_id, '_bm_thread_source_title', $source_title);

        return [
            'thread_id' => (int) $thread_id,
            'is_new'    => true,
        ];
    }
}

/* ----------------------------------------------------------
 * 5. Helper: czy user widzi wątek
 * ---------------------------------------------------------- */
if (!function_exists('bm_user_can_view_thread')) {
    function bm_user_can_view_thread($thread_id, $user_id)
    {
        $user_id   = (int) $user_id;
        $thread_id = (int) $thread_id;

        if (!$thread_id || !$user_id) {
            return false;
        }

        $user_1 = (int) get_post_meta($thread_id, '_bm_thread_user_1', true);
        $user_2 = (int) get_post_meta($thread_id, '_bm_thread_user_2', true);

        return ($user_id === $user_1 || $user_id === $user_2);
    }
}

/* ----------------------------------------------------------
 * 6. Helper: drugi uczestnik wątku
 * ---------------------------------------------------------- */
if (!function_exists('bm_get_other_thread_user')) {
    function bm_get_other_thread_user($thread_id, $current_user_id)
    {
        $current_user_id = (int) $current_user_id;
        $user_1          = (int) get_post_meta($thread_id, '_bm_thread_user_1', true);
        $user_2          = (int) get_post_meta($thread_id, '_bm_thread_user_2', true);

        if ($current_user_id === $user_1) {
            return $user_2;
        }
        if ($current_user_id === $user_2) {
            return $user_1;
        }
        return 0;
    }
}

/* ----------------------------------------------------------
 * 7. Shortcode [bm_message_button]
 *    – link do zakładki "Wiadomości" z parametrami start_thread
 * ---------------------------------------------------------- */
add_shortcode('bm_message_button', function ($atts) {

    global $post;

    if (!$post instanceof WP_Post) {
        return '';
    }

    $atts = shortcode_atts([
        'label' => 'Napisz wiadomość',
        'class' => 'button',
    ], $atts);

    // Niezalogowany → link do logowania
    if (!is_user_logged_in()) {
        $login_url = wp_login_url(get_permalink($post->ID));
        return '<a href="' . esc_url($login_url) . '" class="' . esc_attr($atts['class']) . '">Zaloguj się, aby wysłać wiadomość</a>';
    }

    if (!function_exists('wc_get_account_endpoint_url')) {
        return '';
    }

    $current_user = wp_get_current_user();
    $current_id   = (int) $current_user->ID;
    $to_user_id   = (int) $post->post_author;

    if (!$to_user_id || $to_user_id === $current_id) {
        // nie piszemy do siebie
        return '';
    }

    $source_type = get_post_type($post->ID);
    $source_id   = (int) $post->ID;

    $base = wc_get_account_endpoint_url('messages');

    $url = add_query_arg([
        'start_thread' => 1,
        'to_user'      => $to_user_id,
        'source_type'  => $source_type,
        'source_id'    => $source_id,
    ], $base);

    return '<a href="' . esc_url($url) . '" class="' . esc_attr($atts['class']) . '">' . esc_html($atts['label']) . '</a>';
});

/* ----------------------------------------------------------
 * 8. Endpoint "messages" – logika + widok
 * ---------------------------------------------------------- */
add_action('woocommerce_account_messages_endpoint', function () {

    if (!is_user_logged_in()) {
        echo '<p>Musisz być zalogowany, aby korzystać z wiadomości.</p>';
        return;
    }

    $current_user = wp_get_current_user();
    $current_id   = (int) $current_user->ID;

    $active_thread_id = 0;
    $prefill_message  = '';

    /* 8.1. Zamknięcie wątku ------------------------------------- */
    if (isset($_GET['bm_close_thread'], $_GET['_bm_nonce'])) {
        $thread_id = (int) $_GET['bm_close_thread'];

        if ($thread_id && bm_user_can_view_thread($thread_id, $current_id)) {
            if (wp_verify_nonce($_GET['_bm_nonce'], 'bm_close_thread_' . $thread_id)) {
                update_post_meta($thread_id, '_bm_thread_status', 'closed');
                echo '<div class="woocommerce-message">Wątek został zamknięty.</div>';
            }
        }
    }

    /* 8.2. Akceptacja oferty przez Zamawiającego ----------------
     *  - tylko dla post_type = ogloszenie
     *  - tylko autor ogłoszenia
     *  - tylko jedna akceptacja na ogłoszenie
     */
    if (isset($_GET['bm_accept_offer'], $_GET['_bm_acc_nonce'])) {

        $thread_id = (int) $_GET['bm_accept_offer'];

        if ($thread_id && bm_user_can_view_thread($thread_id, $current_id)) {

            if (wp_verify_nonce($_GET['_bm_acc_nonce'], 'bm_accept_offer_' . $thread_id)) {

                $source_id   = (int) get_post_meta($thread_id, '_bm_thread_source_id', true);
                $source_type = get_post_meta($thread_id, '_bm_thread_source_type', true);

                $source_post = $source_id ? get_post($source_id) : null;

                // tylko dla ogłoszeń i tylko autor ogłoszenia
                if ($source_post && get_post_type($source_post) === 'ogloszenie' && (int) $source_post->post_author === $current_id) {

                    // sprawdzamy, czy dla tego ogłoszenia nie ma już zaakceptowanej współpracy
                    $existing_for_source = get_posts([
                        'post_type'   => 'bm_deal',
                        'post_status' => 'publish',
                        'numberposts' => 1,
                        'meta_query'  => [
                            [
                                'key'   => '_bm_source_id',
                                'value' => $source_id,
                            ],
                            [
                                'key'     => '_bm_status',
                                'value'   => ['accepted', 'finished_success', 'finished_failed'],
                                'compare' => 'IN',
                            ],
                        ],
                    ]);

                    if (!empty($existing_for_source)) {
                        echo '<div class="woocommerce-error">Dla tego ogłoszenia została już zaakceptowana jedna oferta.</div>';
                    } else {

                        // druga strona wątku = Dostawca, którego wybieramy
                        $other_id = bm_get_other_thread_user($thread_id, $current_id);

                        if ($other_id && function_exists('bm_create_deal')) {

                            $deal_id = bm_create_deal($thread_id, $current_id, $other_id, $source_id, $source_type);

                            if ($deal_id) {

                                $source_title = get_the_title($source_id);

                                // MAIL: do wybranego Dostawcy
                                $chosen_user = get_userdata($other_id);
                                if ($chosen_user && $chosen_user->user_email) {

                                    $messages_url = function_exists('wc_get_account_endpoint_url')
                                        ? wc_get_account_endpoint_url('messages')
                                        : home_url('/moje-konto/');

                                    $subject = 'Twoja oferta została zaakceptowana. Rozpoczyna się współpraca.';
                                    $msg  = "Cześć,\n\n";
                                    $msg .= "Twoja oferta w sprawie: \"" . $source_title . "\" została zaakceptowana przez zamawiającego.\n";
                                    $msg .= "Rozpoczyna się współpraca.\n\n";
                                    $msg .= "Zaloguj się i przejdź do zakładki „Wiadomości” lub „Współprace”, aby zobaczyć szczegóły:\n";
                                    $msg .= $messages_url . "\n\n";
                                    $msg .= "Pozdrawiamy,\nZespół BrandManager";

                                    wp_mail(
                                        $chosen_user->user_email,
                                        $subject,
                                        $msg,
                                        ['Content-Type: text/plain; charset=UTF-8']
                                    );
                                }

                                // MAIL: do pozostałych Dostawców, którzy pisali w sprawie tego samego ogłoszenia
                                $all_threads = get_posts([
                                    'post_type'   => 'bm_thread',
                                    'post_status' => 'publish',
                                    'numberposts' => -1,
                                    'meta_query'  => [
                                        [
                                            'key'   => '_bm_thread_source_type',
                                            'value' => $source_type,
                                        ],
                                        [
                                            'key'   => '_bm_thread_source_id',
                                            'value' => $source_id,
                                        ],
                                    ],
                                ]);

                                if (!empty($all_threads)) {

                                    foreach ($all_threads as $thr) {

                                        $thr_id = (int) $thr->ID;
                                        if ($thr_id === $thread_id) {
                                            continue; // pomijamy wybrany wątek
                                        }

                                        // z perspektywy Zamawiającego druga strona to Dostawca
                                        $other_provider_id = bm_get_other_thread_user($thr_id, $current_id);
                                        if (!$other_provider_id || $other_provider_id === $other_id) {
                                            continue;
                                        }

                                        $prov_user = get_userdata($other_provider_id);
                                        if ($prov_user && $prov_user->user_email) {

                                            $messages_url = function_exists('wc_get_account_endpoint_url')
                                                ? wc_get_account_endpoint_url('messages')
                                                : home_url('/moje-konto/');

                                            $subject = 'Twoja oferta nie została wybrana';
                                            $msg  = "Cześć,\n\n";
                                            $msg .= "Zamawiający wybrał innego wykonawcę dla ogłoszenia: \"" . $source_title . "\".\n";
                                            $msg .= "Dziękujemy za Twoją ofertę.\n\n";
                                            $msg .= "Możesz sprawdzić swoje rozmowy w zakładce „Wiadomości” po zalogowaniu:\n";
                                            $msg .= $messages_url . "\n\n";
                                            $msg .= "Pozdrawiamy,\nZespół BrandManager";

                                            wp_mail(
                                                $prov_user->user_email,
                                                $subject,
                                                $msg,
                                                ['Content-Type: text/plain; charset=UTF-8']
                                            );
                                        }
                                    }
                                }

                                echo '<div class="woocommerce-message">Twoja oferta została zaakceptowana. Rozpoczyna się współpraca.</div>';
                            }
                        }
                    }
                }
            }
        }
    }

    /* 8.3. Wysłanie nowej wiadomości ---------------------------- */
    if (!empty($_POST['bm_send_message']) && !empty($_POST['bm_thread_id'])) {

        $thread_id = (int) $_POST['bm_thread_id'];

        if ($thread_id && bm_user_can_view_thread($thread_id, $current_id)) {

            if (isset($_POST['_bm_nonce']) && wp_verify_nonce($_POST['_bm_nonce'], 'bm_send_message_' . $thread_id)) {

                $thread_status = get_post_meta($thread_id, '_bm_thread_status', true);
                if ($thread_status !== 'closed') {

                    $content_raw = isset($_POST['bm_message_content']) ? wp_unslash($_POST['bm_message_content']) : '';
                    $content     = trim(wp_strip_all_tags($content_raw));

                    if (strlen($content) > 0) {

                        $comment_id = wp_insert_comment([
                            'comment_post_ID'      => $thread_id,
                            'comment_author'       => $current_user->display_name,
                            'comment_author_email' => $current_user->user_email,
                            'comment_content'      => $content,
                            'user_id'              => $current_id,
                            'comment_approved'     => 1,
                            'comment_type'         => 'bm_message',
                        ]);

                        if ($comment_id && !is_wp_error($comment_id)) {

                            // aktualizacja daty modyfikacji wątku
                            wp_update_post([
                                'ID'            => $thread_id,
                                'post_modified' => current_time('mysql'),
                            ]);

                            // MAIL do drugiego uczestnika – dopiero po wysłaniu wiadomości
                            $other_id = bm_get_other_thread_user($thread_id, $current_id);
                            if ($other_id) {
                                $other = get_userdata($other_id);
                                if ($other && $other->user_email) {

                                    $messages_url = function_exists('wc_get_account_endpoint_url')
                                        ? wc_get_account_endpoint_url('messages')
                                        : home_url('/moje-konto/');

                                    $subject = 'Nowa wiadomość w BrandManager';
                                    $msg     = "Cześć,\n\nMasz nową wiadomość od: " . $current_user->user_email . ".\n\n";
                                    $msg    .= "Zaloguj się i przejdź do zakładki „Wiadomości”, aby odpowiedzieć:\n";
                                    $msg    .= $messages_url . "\n\n";
                                    $msg    .= "Pozdrawiamy,\nZespół BrandManager";

                                    wp_mail(
                                        $other->user_email,
                                        $subject,
                                        $msg,
                                        ['Content-Type: text/plain; charset=UTF-8']
                                    );
                                }
                            }

                            echo '<div class="woocommerce-message">Wiadomość została wysłana.</div>';
                        }
                    }
                } else {
                    echo '<div class="woocommerce-error">Ten wątek jest zamknięty. Nie można wysyłać nowych wiadomości.</div>';
                }
            }
        }
    }

    /* 8.4. start_thread – kliknięcie przycisku na ogłoszeniu/ofercie */
    if (
        !empty($_GET['start_thread']) &&
        !empty($_GET['to_user']) &&
        !empty($_GET['source_type']) &&
        !empty($_GET['source_id'])
    ) {
        $to_user     = (int) $_GET['to_user'];
        $source_id   = (int) $_GET['source_id'];
        $source_type = sanitize_key($_GET['source_type']);

        if ($to_user && $to_user !== $current_id && $source_id && $source_type) {

            $result = bm_get_or_create_thread($current_id, $to_user, $source_type, $source_id);

            if (!empty($result['thread_id'])) {
                $active_thread_id = (int) $result['thread_id'];

                // Jeśli świeżo utworzony wątek → pre-fill treści w textarea (NIE wysyłamy jeszcze wiadomości)
                if (!empty($result['is_new'])) {
                    $source_title    = get_the_title($source_id);
                    $prefill_message = 'Cześć, piszę w sprawie: "' . $source_title . '" ';
                }
            }
        }
    }

    /* 8.5. Wybranie konkretnego wątku z listy ------------------ */
    if (empty($active_thread_id) && !empty($_GET['thread_id'])) {
        $maybe_thread = (int) $_GET['thread_id'];
        if ($maybe_thread && bm_user_can_view_thread($maybe_thread, $current_id)) {
            $active_thread_id = $maybe_thread;
        }
    }

    /* 8.6. Pobranie wszystkich wątków użytkownika -------------- */
    $threads = get_posts([
        'post_type'   => 'bm_thread',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => [
            'relation' => 'OR',
            [
                'key'   => '_bm_thread_user_1',
                'value' => $current_id,
            ],
            [
                'key'   => '_bm_thread_user_2',
                'value' => $current_id,
            ],
        ],
        'orderby'     => 'modified',
        'order'       => 'DESC',
    ]);

    if (empty($threads)) {
        echo '<p>Nie masz jeszcze żadnych wiadomości.</p>';
        return;
    }

    if (!$active_thread_id && !empty($threads)) {
        $active_thread_id = (int) $threads[0]->ID;
    }

    /* 8.7. Styl + widok dwukolumnowy --------------------------- */
    ?>
    <style>
        .bm-messages-wrapper{
            display:flex;
            gap:20px;
            align-items:stretch;
        }
        .bm-messages-threads{
            width:30%;
            max-width:360px;
            border:1px solid #e5e5e5;
            border-radius:8px;
            background:#fff;
            overflow:hidden;
        }
        .bm-messages-threads h3{
            margin:0;
            padding:12px 16px;
            border-bottom:1px solid #e5e5e5;
            font-size:15px;
            font-weight:600;
        }
        .bm-thread-list{
            list-style:none;
            margin:0;
            padding:0;
        }
        .bm-thread-list li{
            border-bottom:1px solid #f2f2f2;
        }
        .bm-thread-list a{
            display:block;
            padding:10px 14px;
            text-decoration:none;
            color:#333;
        }
        .bm-thread-list a.active{
            background:#f5f5f5;
            font-weight:600;
        }
        .bm-thread-meta{
            font-size:11px;
            color:#777;
        }
        .bm-thread-status-badge{
            display:inline-block;
            padding:2px 6px;
            font-size:10px;
            border-radius:10px;
            background:#e5f5ff;
            color:#005a9e;
            margin-left:4px;
        }
        .bm-thread-status-badge.closed{
            background:#f5e5e5;
            color:#a30000;
        }
        .bm-messages-thread-view{
            flex:1;
            border:1px solid #e5e5e5;
            border-radius:8px;
            background:#fff;
            padding:12px 16px;
        }
        .bm-messages-thread-header{
            border-bottom:1px solid #eee;
            padding-bottom:8px;
            margin-bottom:12px;
        }
        .bm-messages-thread-header h3{
            margin:0;
            font-size:16px;
            font-weight:600;
        }
        .bm-messages-thread-header .bm-thread-source{
            font-size:12px;
            color:#777;
        }
        .bm-message-list{
            max-height:400px;
            overflow-y:auto;
            padding-right:6px;
            margin-bottom:12px;
        }
        .bm-message-item{
            margin-bottom:10px;
        }
        .bm-message-item.me .bm-message-bubble{
            background:#e6f7ff;
            margin-left:auto;
        }
        .bm-message-bubble{
            display:inline-block;
            padding:8px 10px;
            border-radius:12px;
            background:#f5f5f5;
            max-width:80%;
        }
        .bm-message-meta{
            font-size:10px;
            color:#999;
        }
        .bm-message-form textarea{
            width:100%;
            min-height:80px;
        }
        .bm-message-form button{
            margin-top:6px;
        }
        .bm-thread-closed-info{
            padding:8px 10px;
            background:#fff7e6;
            border-radius:6px;
            border:1px solid #ffe0b2;
            margin-bottom:10px;
            font-size:12px;
        }
        .bm-thread-info-active-deal{
            padding:8px 10px;
            background:#e6fffb;
            border-radius:6px;
            border:1px solid #87e8de;
            margin-top:10px;
            font-size:12px;
            color:#0050b3;
        }
    </style>
    <?php

    echo '<div class="bm-messages-wrapper">';

    /* LISTA WĄTKÓW – lewa kolumna ----------------------------- */
    echo '<div class="bm-messages-threads">';
    echo '<h3>Wiadomości</h3>';
    echo '<ul class="bm-thread-list">';

    foreach ($threads as $thread) {

        $thread_id     = (int) $thread->ID;
        $thread_status = get_post_meta($thread_id, '_bm_thread_status', true);
        if (!$thread_status) {
            $thread_status = 'open';
        }

        $source_title = get_post_meta($thread_id, '_bm_thread_source_title', true);
        $other_id     = bm_get_other_thread_user($thread_id, $current_id);
        $other        = $other_id ? get_userdata($other_id) : null;
        $label_other  = $other ? $other->display_name : 'Użytkownik';

        // ostatnia wiadomość (snippet)
        $last_comment = get_comments([
            'post_id' => $thread_id,
            'number'  => 1,
            'status'  => 'approve',
            'type'    => 'bm_message',
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
        ]);

        $last_snippet = '';
        if (!empty($last_comment)) {
            $c            = $last_comment[0];
            $last_snippet = wp_trim_words($c->comment_content, 5, '…');
        }

        $url = add_query_arg('thread_id', $thread_id, wc_get_account_endpoint_url('messages'));

        $classes = [];
        if ($thread_id === $active_thread_id) {
            $classes[] = 'active';
        }

        echo '<li>';
        echo '<a href="' . esc_url($url) . '" class="' . esc_attr(implode(' ', $classes)) . '">';
        echo '<strong>' . esc_html($label_other) . '</strong>';

        if ($thread_status === 'closed') {
            echo '<span class="bm-thread-status-badge closed">zamknięty</span>';
        } else {
            echo '<span class="bm-thread-status-badge">aktywny</span>';
        }

        if ($source_title) {
            echo '<div class="bm-thread-meta">' . esc_html($source_title) . '</div>';
        }

        if ($last_snippet) {
            echo '<div class="bm-thread-meta">' . esc_html($last_snippet) . '</div>';
        }

        echo '</a>';
        echo '</li>';
    }

    echo '</ul>';
    echo '</div>'; // .bm-messages-threads

    /* SZCZEGÓŁY WĄTKU – prawa kolumna -------------------------- */
    echo '<div class="bm-messages-thread-view">';

    if (!$active_thread_id) {
        echo '<p>Wybierz wątek z listy po lewej, aby zobaczyć wiadomości.</p>';
        echo '</div></div>';
        return;
    }

    $thread_id     = $active_thread_id;
    $thread_status = get_post_meta($thread_id, '_bm_thread_status', true);
    $source_type   = get_post_meta($thread_id, '_bm_thread_source_type', true);
    $source_id     = (int) get_post_meta($thread_id, '_bm_thread_source_id', true);
    $source_title  = get_post_meta($thread_id, '_bm_thread_source_title', true);
    $source_link   = $source_id ? get_permalink($source_id) : '';

    $other_id    = bm_get_other_thread_user($thread_id, $current_id);
    $other       = $other_id ? get_userdata($other_id) : null;
    $other_label = $other ? $other->display_name : 'Użytkownik';

    // Czy dla tego wątku istnieje już powiązana współpraca (deal)?
    $has_active_deal = false;
    if (function_exists('bm_get_deal_by_thread')) {
        $deal_for_thread = bm_get_deal_by_thread($thread_id);
        if ($deal_for_thread) {
            $has_active_deal = true;
        }
    }

    // Czy bieżący user może akceptować ofertę w tym wątku?
    $can_accept_offer = false;
    if (
        $thread_status !== 'closed' &&
        $source_id &&
        $source_type === 'ogloszenie' &&
        function_exists('bm_create_deal')
    ) {
        $source_post = get_post($source_id);
        if ($source_post && (int) $source_post->post_author === $current_id) {

            // sprawdzamy, czy już jest zaakceptowana współpraca dla tego ogłoszenia
            $existing_for_source = get_posts([
                'post_type'   => 'bm_deal',
                'post_status' => 'publish',
                'numberposts' => 1,
                'meta_query'  => [
                    [
                        'key'   => '_bm_source_id',
                        'value' => $source_id,
                    ],
                    [
                        'key'     => '_bm_status',
                        'value'   => ['accepted', 'finished_success', 'finished_failed'],
                        'compare' => 'IN',
                    ],
                ],
            ]);

            if (empty($existing_for_source)) {
                $can_accept_offer = true;
            }
        }
    }

    echo '<div class="bm-messages-thread-header">';
    echo '<h3>Rozmowa z: ' . esc_html($other_label) . '</h3>';

    if ($source_title) {
        echo '<div class="bm-thread-source">';
        echo 'Dotyczy: ';
        if ($source_link) {
            echo '<a href="' . esc_url($source_link) . '" target="_blank">' . esc_html($source_title) . '</a>';
        } else {
            echo esc_html($source_title);
        }
        echo '</div>';
    }

    // Pasek akcji (akceptacja oferty + zamknięcie wątku)
    if ($thread_status !== 'closed') {

        echo '<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';

        // przycisk "Akceptuję ofertę" tylko dla Zamawiającego (autora ogłoszenia) i tylko gdy nie ma jeszcze zaakceptowanej oferty
        if ($can_accept_offer) {
            $accept_url = add_query_arg(
                [
                    'bm_accept_offer' => $thread_id,
                    '_bm_acc_nonce'   => wp_create_nonce('bm_accept_offer_' . $thread_id),
                ],
                wc_get_account_endpoint_url('messages')
            );

            echo '<a href="' . esc_url($accept_url) . '" class="button" style="background:#52c41a;border-color:#52c41a;color:#fff;">Akceptuję ofertę</a>';
            echo '<span style="font-size:11px;color:#555;max-width:320px;">UWAGA: możesz zaakceptować jedynie jedną ofertę. Wszystkie pozostałe zostaną automatycznie odrzucone.</span>';
        }

        // przycisk "Zamknij wątek"
        $close_url = add_query_arg(
            [
                'bm_close_thread' => $thread_id,
                '_bm_nonce'       => wp_create_nonce('bm_close_thread_' . $thread_id),
            ],
            wc_get_account_endpoint_url('messages')
        );

        echo '<a href="' . esc_url($close_url) . '" class="button" style="background:#f5222d;border-color:#cf1322;color:#fff;" onclick="return confirm(\'Czy na pewno zamknąć ten wątek?\');">Zamknij wątek</a>';

        echo '</div>';
    } else {
        echo '<div style="margin-top:6px;font-size:12px;color:#999;">Ten wątek jest zamknięty.</div>';
    }

    // Informacja o rozpoczętej współpracy – dla obu stron, jeśli istnieje bm_deal
    if ($has_active_deal) {
        echo '<div class="bm-thread-info-active-deal">Współpraca została już rozpoczęta.</div>';
    }

    echo '</div>'; // .bm-messages-thread-header

    // Lista wiadomości
    $messages = get_comments([
        'post_id' => $thread_id,
        'status'  => 'approve',
        'type'    => 'bm_message',
        'orderby' => 'comment_date_gmt',
        'order'   => 'ASC',
        'number'  => 200,
    ]);

    echo '<div class="bm-message-list">';

    if (empty($messages)) {
        echo '<p>Brak wiadomości w tym wątku.</p>';
    } else {
        foreach ($messages as $msg) {
            $is_me        = ((int) $msg->user_id === $current_id);
            $cls          = $is_me ? 'bm-message-item me' : 'bm-message-item';
            $date         = mysql2date('d.m.Y H:i', $msg->comment_date);
            $author_label = $is_me ? 'Ty' : ($msg->comment_author ?: 'Użytkownik');

            echo '<div class="' . esc_attr($cls) . '">';
            echo '<div class="bm-message-bubble">' . esc_html($msg->comment_content) . '</div>';
            echo '<div class="bm-message-meta">' . esc_html($author_label) . ' • ' . esc_html($date) . '</div>';
            echo '</div>';
        }
    }

    echo '</div>'; // .bm-message-list

    if ($thread_status === 'closed') {
        echo '<div class="bm-thread-closed-info">';
        echo 'Ten wątek został zamknięty. Nie można wysyłać nowych wiadomości.';
        echo '</div>';
    }

    // Formularz – tylko gdy wątek otwarty
    if ($thread_status !== 'closed') {
        ?>
        <form method="post" class="bm-message-form">
            <p><label for="bm_message_content">Twoja wiadomość:</label></p>
            <p>
                <textarea name="bm_message_content" id="bm_message_content"><?php echo esc_textarea($prefill_message); ?></textarea>
            </p>
            <p>
                <input type="hidden" name="bm_thread_id" value="<?php echo esc_attr($thread_id); ?>" />
                <input type="hidden" name="bm_send_message" value="1" />
                <?php wp_nonce_field('bm_send_message_' . $thread_id, '_bm_nonce'); ?>
                <button type="submit" class="button button-primary">Wyślij</button>
            </p>
        </form>
        <?php
    }

    echo '</div>';  // .bm-messages-thread-view
    echo '</div>';  // .bm-messages-wrapper
});
