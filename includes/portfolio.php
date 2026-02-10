<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BRANDMANAGER – MODUŁ PORTFOLIO (DOSTAWCA)
 *
 * - CPT: bm_portfolio (niepubliczny)
 * - Taxonomy: bm_portfolio_category
 * - Zarządzanie w Moje konto: /moje-konto/portfolio/
 * - Limit darmowy: 3 wpisy (na sztywno + filtr pod przyszłe plany)
 *
 * Pola:
 * - Nazwa wykonanej usługi: post_title
 * - Kategoria: taxonomy bm_portfolio_category
 * - Projekt: zrealizowany / koncepcyjny: meta _bm_portfolio_status
 * - Krótki opis WYSIWYG: post_content
 * - Zdjęcia (max 6): meta _bm_portfolio_gallery (array attachment IDs)
 * - Link do szczegółów/projektu: meta _bm_portfolio_url
 *
 * Shortcody (Elementor-friendly):
 * - [bm_portfolio_list]                      -> lista portfolio w kontekście dostawcy
 * - [bm_portfolio_list user_id="40"]         -> lista portfolio konkretnego usera
 * - [bm_portfolio_list supplier_id="510"]    -> lista portfolio wg posta dostawcy
 * - [bm_portfolio_item id="123"]             -> pojedynczy element portfolio
 * - [bm_portfolio_gallery id="123"]          -> galeria (HTML) dla elementu portfolio
 */

add_action('wp_enqueue_scripts', function () {
    if (!is_account_page()) return;

    wp_enqueue_script(
        'bm-portfolio',
        plugins_url('../assets/js/portfolio.js', __FILE__),
        [],
        '1.0',
        true
    );

    wp_localize_script('bm-portfolio', 'bmPortfolio', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('bm_pf_nonce'),
    ]);
});


/* ----------------------------------------------------------
 * 1. CPT + Taksonomia
 * ---------------------------------------------------------- */

add_action('init', function () {

    $labels = [
        'name'               => 'Portfolio (BM)',
        'singular_name'      => 'Realizacja (BM)',
        'add_new'            => 'Dodaj realizację',
        'add_new_item'       => 'Dodaj realizację',
        'edit_item'          => 'Edytuj realizację',
        'new_item'           => 'Nowa realizacja',
        'view_item'          => 'Zobacz realizację',
        'search_items'       => 'Szukaj realizacji',
        'not_found'          => 'Nie znaleziono realizacji',
        'not_found_in_trash' => 'Brak realizacji w koszu',
        'menu_name'          => 'Portfolio (BM)',
    ];

    register_post_type('bm_portfolio', [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-portfolio',
        'supports'           => ['title', 'editor', 'author'],
        'capability_type'    => 'post',
        'has_archive'        => false,
        'publicly_queryable' => false,
        'show_in_rest'       => false,
    ]);

    register_taxonomy('bm_portfolio_category', ['bm_portfolio'], [
        'label'        => 'Kategorie portfolio',
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'show_in_rest' => false,
        'hierarchical' => true,
        'rewrite'      => false,
    ]);
});

/* ----------------------------------------------------------
 * 2. Helpery
 * ---------------------------------------------------------- */

if (!function_exists('bm_portfolio_limit_for_user')) {
    function bm_portfolio_limit_for_user($user_id): int
    {
        // Na start: 3 (darmowy). Pod przyszłe plany:
        // add_filter('bm_portfolio_limit', fn($limit,$user_id)=>..., 10, 2);
        $limit = 3;
        return (int) apply_filters('bm_portfolio_limit', $limit, (int) $user_id);
    }
}

if (!function_exists('bm_is_supplier_user')) {
    function bm_is_supplier_user($user_id = 0): bool
    {
        $user_id = (int) ($user_id ?: get_current_user_id());
        if (!$user_id) return false;
        $user = get_userdata($user_id);
        if (!$user) return false;
        return in_array('dostawca', (array) $user->roles, true);
    }
}

if (!function_exists('bm_portfolio_count_for_user')) {
    function bm_portfolio_count_for_user($user_id): int
    {
        $q = new WP_Query([
            'post_type'      => 'bm_portfolio',
            'post_status'    => 'publish',
            'author'         => (int) $user_id,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => false,
        ]);
        return (int) $q->found_posts;
    }
}

if (!function_exists('bm_portfolio_get_gallery_ids')) {
    function bm_portfolio_get_gallery_ids($portfolio_id): array
    {
        $ids = get_post_meta((int)$portfolio_id, '_bm_portfolio_gallery', true);
        if (empty($ids)) return [];
        if (is_string($ids)) {
            // legacy safety: "1,2,3"
            $ids = array_filter(array_map('absint', explode(',', $ids)));
        }
        if (!is_array($ids)) return [];
        return array_values(array_filter(array_map('absint', $ids)));
    }
}

if (!function_exists('bm_portfolio_get_status_label')) {
    function bm_portfolio_get_status_label($status): string
    {
        $status = sanitize_key($status);
        if ($status === 'completed') return 'Projekt zrealizowany';
        if ($status === 'concept') return 'Projekt koncepcyjny';
        return '';
    }
}

if (!function_exists('bm_portfolio_get_context_user_id')) {
    function bm_portfolio_get_context_user_id(): int
    {
        // 1) jeśli mamy helper z shortcodes-dostawca.php
        if (function_exists('bm_get_current_supplier_post_id')) {
            $supplier_post_id = (int) bm_get_current_supplier_post_id();
            if ($supplier_post_id) {
                $author = (int) get_post_field('post_author', $supplier_post_id);
                if ($author) return $author;
            }
        }

        // 2) fallback: jeśli jesteśmy na poście dostawca
        $post_id = get_queried_object_id();
        if ($post_id && get_post_type($post_id) === 'dostawca') {
            $author = (int) get_post_field('post_author', $post_id);
            if ($author) return $author;
        }

        // 3) logged supplier
        if (is_user_logged_in() && bm_is_supplier_user()) {
            return (int) get_current_user_id();
        }

        return 0;
    }
}


/* ----------------------------------------------------------
 * 2b. AJAX – sprawdzenie czy istnieje opinia do wybranej współpracy (dla podglądu w formularzu)
 * ---------------------------------------------------------- */
add_action('wp_ajax_bm_pf_check_deal_review', function () {
    if (!is_user_logged_in()) {
        wp_send_json_success(['has_review' => false]);
    }

    if (!check_ajax_referer('bm_pf_check_deal_review', '_ajax_nonce', false)) {
        wp_send_json_success(['has_review' => false]);
    }

    $current_id = get_current_user_id();
    $deal_id    = !empty($_POST['deal_id']) ? absint($_POST['deal_id']) : 0;

    if (!$deal_id || !bm_is_supplier_user($current_id)) {
        wp_send_json_success(['has_review' => false]);
    }

    // Czy istnieje opinia o dostawcy do tej współpracy?
    $existing = get_posts([
        'post_type'      => 'bm_review',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            'relation' => 'AND',
            [ 'key' => '_bm_review_deal_id',   'value' => $deal_id ],
            [ 'key' => '_bm_review_target_id', 'value' => $current_id ],
        ],
    ]);

    wp_send_json_success(['has_review' => !empty($existing)]);
});


/* ----------------------------------------------------------
 * 3. Endpoint "portfolio" w Moje konto (dla dostawcy)
 * ---------------------------------------------------------- */

add_action('init', function () {
    add_rewrite_endpoint('portfolio', EP_ROOT | EP_PAGES);
});

add_filter('woocommerce_account_menu_items', function ($items) {

    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) return $items;

    if (!in_array('dostawca', (array)$user->roles, true)) {
        return $items;
    }

    if (!isset($items['portfolio'])) {
        $items['portfolio'] = 'Portfolio';
    }

    return $items;
}, 50);

add_action('woocommerce_account_portfolio_endpoint', function () {

    if (!is_user_logged_in()) {
        echo '<p>Musisz być zalogowany.</p>';
        return;
    }

    $current_id = get_current_user_id();

    if (!bm_is_supplier_user($current_id)) {
        echo '<p>Ta sekcja jest dostępna tylko dla konta Dostawcy.</p>';
        return;
    }

    // komunikaty po PRG
    if (!empty($_GET['bm_portfolio_msg'])) {
        $msg = sanitize_text_field($_GET['bm_portfolio_msg']);
        if ($msg === 'saved') {
            echo '<div class="woocommerce-message" role="alert">Portfolio zostało zapisane.</div>';
        } elseif ($msg === 'deleted') {
            echo '<div class="woocommerce-message" role="alert">Realizacja została usunięta.</div>';
        } elseif ($msg === 'limit') {
            echo '<div class="woocommerce-error" role="alert">Osiągnąłeś limit realizacji w darmowym planie.</div>';
        } elseif ($msg === 'error') {
            echo '<div class="woocommerce-error" role="alert">Nie udało się zapisać zmian. Spróbuj ponownie.</div>';
        }
    }

    // id edycji (opcjonalnie)
    $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;

    // Pobierz istniejący wpis do edycji
    $edit_post = null;
    if ($edit_id) {
        $p = get_post($edit_id);
        if ($p && $p->post_type === 'bm_portfolio' && (int)$p->post_author === (int)$current_id) {
            $edit_post = $p;
        }
    }

    $limit = bm_portfolio_limit_for_user($current_id);
    $count = bm_portfolio_count_for_user($current_id);

    $can_add_more = ($count < $limit) || $edit_post; // przy edycji limit nie blokuje

    echo '<div class="bm-account-portfolio">';

    echo '<h3>Portfolio</h3>';
    echo '<p>Dodawaj swoje realizacje i pokazuj je na wizytówce. W darmowym planie możesz dodać maksymalnie <strong>' . esc_html($limit) . '</strong> realizacje.</p>';

    // Formularz
    if ($can_add_more) {

        $val_title   = $edit_post ? $edit_post->post_title : '';
        $val_desc    = $edit_post ? $edit_post->post_content : '';
        $val_status  = $edit_post ? get_post_meta($edit_post->ID, '_bm_portfolio_status', true) : 'completed';
        $val_url     = $edit_post ? get_post_meta($edit_post->ID, '_bm_portfolio_url', true) : '';
        $val_gallery = $edit_post ? bm_portfolio_get_gallery_ids($edit_post->ID) : [];
        $val_origin    = $edit_post ? get_post_meta($edit_post->ID, '_bm_portfolio_origin', true) : 'portal';
        if (!in_array($val_origin, ['portal','external','none'], true)) { $val_origin = 'portal'; }
        $val_deal_id   = $edit_post ? (int) get_post_meta($edit_post->ID, '_bm_portfolio_deal_id', true) : 0;
        $val_review_id = $edit_post ? (int) get_post_meta($edit_post->ID, '_bm_portfolio_review_id', true) : 0;
        $val_terms = $edit_post ? wp_get_post_terms($edit_post->ID, 'bm_portfolio_category', ['fields' => 'ids']) : [];

        echo '<div class="bm-portfolio-box bm-portfolio-box--form">';
        echo '<h4>' . ($edit_post ? 'Edytuj realizację' : 'Dodaj realizację') . '</h4>';

        echo '<form method="post" enctype="multipart/form-data" class="bm-portfolio-form">';
        wp_nonce_field('bm_portfolio_save', '_bm_portfolio_nonce');

        if ($edit_post) {
            echo '<input type="hidden" name="bm_portfolio_id" value="' . esc_attr($edit_post->ID) . '">';
        }

        echo '<p><label>Nazwa wykonanej usługi <span class="required">*</span><br>';
        echo '<input type="text" name="bm_portfolio_title" required value="' . esc_attr($val_title) . '"></label></p>';

        // Kategoria (select)
        $cats = get_terms('bm_portfolio_category', [
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        echo '<p><label>Kategoria<br>';
        echo '<select name="bm_portfolio_category">';
        echo '<option value="">-- wybierz --</option>';
        if (!is_wp_error($cats) && !empty($cats)) {
            foreach ($cats as $cat) {
                $selected = (!empty($val_terms) && in_array((int) $cat->term_id, array_map('intval', (array) $val_terms), true)) ? 'selected' : '';
                echo '<option value="' . esc_attr($cat->term_id) . '" ' . $selected . '>' . esc_html($cat->name) . '</option>';
            }
        }
        echo '</select></label></p>';
        // Opcjonalnie: dodaj nową kategorię (utworzy się przy zapisie)
        echo '<p class="bm-portfolio-newcat"><label>Dodaj nową kategorię (opcjonalnie)<br>';
        echo '<input type="text" name="bm_portfolio_new_category" placeholder="np. Social media" value=""></label></p>';

        // Źródło opinii do realizacji (portal / poza portalem / brak opinii)
        echo '<hr>';
        echo '<h4>Opinia do tej realizacji</h4>';
        echo '<p>';
        echo '<label class="bm-origin-option"><input type="radio" name="bm_portfolio_origin" value="portal" ' . checked($val_origin, 'portal', false) . '> Realizacja w portalu</label> ';
        echo '<label class="bm-origin-option" style="margin-left:12px;"><input type="radio" name="bm_portfolio_origin" value="external" ' . checked($val_origin, 'external', false) . '> Realizacja poza portalem</label> ';
        echo '<label class="bm-origin-option" style="margin-left:12px;"><input type="radio" name="bm_portfolio_origin" value="none" ' . checked($val_origin, 'none', false) . '> Nie dodawaj opinii</label>';
        echo '</p>';

        // Sekcja: portal (wybór współpracy) – bez pól zewnętrznych
        echo '<div class="bm-portfolio-origin bm-portfolio-origin-portal" style="margin-top:10px;">';
        echo '<div class="bm-portfolio-deal-hint">Wybierz zakończoną współpracę. Jeśli opinia została już wystawiona do tej współpracy, zostanie automatycznie pobrana.</div>';

        // Lista zakończonych współprac dostawcy
        $finished_deals = get_posts([
            'post_type'   => 'bm_deal',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [
                'relation' => 'AND',
                [ 'key' => '_bm_status', 'value' => 'finished_success' ],
                [
                    'relation' => 'OR',
                    [ 'key' => '_bm_user_1', 'value' => $current_id ],
                    [ 'key' => '_bm_user_2', 'value' => $current_id ],
                ],
            ],
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);

        echo '<p><label>Wybierz współpracę<br>';
        echo '<select name="bm_portfolio_deal_id">';
        echo '<option value="">-- wybierz --</option>';
        if (!empty($finished_deals)) {
            foreach ($finished_deals as $d) {
                $did = (int) $d->ID;
                $label = get_post_meta($did, '_bm_source_title', true);
                if (!$label) $label = get_the_title($did);
                echo '<option value="' . esc_attr($did) . '" ' . selected($val_deal_id, $did, false) . '>' . esc_html($label) . '</option>';
            }
        }
        echo '</select></label></p>';

        echo '<div class="bm-portfolio-deal-review-hint" style="margin-top:8px;"></div>';

        if ($val_deal_id) {
            // Sprawdzamy czy do tej współpracy istnieje już opinia o dostawcy
            $existing_reviews = get_posts([
                'post_type'   => 'bm_review',
                'post_status' => 'publish',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_query'  => [
                    'relation' => 'AND',
                    [ 'key' => '_bm_review_deal_id',   'value' => $val_deal_id ],
                    [ 'key' => '_bm_review_target_id', 'value' => $current_id ],
                ],
            ]);
            if (!empty($existing_reviews)) {
                $rid = (int) $existing_reviews[0];
                echo '<div class="woocommerce-message" style="margin-top:8px;">Masz już opinię do tej współpracy — zostanie pobrana automatycznie po zapisaniu realizacji.</div>';
            } else {
                echo '<div style="margin-top:8px;font-size:12px;color:#777;">Brak opinii do tej współpracy — możesz poprosić o opinię w zakładce Opinie lub później.</div>';
            }
        }


        // Hint po wyborze współpracy (od razu, bez zapisu) – AJAX
        $bm_pf_ajax_url   = admin_url('admin-ajax.php');
        $bm_pf_ajax_nonce = wp_create_nonce('bm_pf_check_deal_review');

        echo '<script>(function(){' .
             'var wrap=document.querySelector(".bm-portfolio-form"); if(!wrap) return;' .
             'var sel=wrap.querySelector("select[name=\\"bm_portfolio_deal_id\\"]");' .
             'var hint=wrap.querySelector(".bm-portfolio-deal-review-hint");' .
             'if(!sel||!hint) return;' .
             'var ajaxUrl=' . wp_json_encode($bm_pf_ajax_url) . ';' .
             'var nonce=' . wp_json_encode($bm_pf_ajax_nonce) . ';' .
             'function show(html){ hint.innerHTML = html || ""; }' .
             'function msgHas(){ show("<div class=\\"woocommerce-message\\">Masz już opinię do tej współpracy — zostanie pobrana automatycznie po zapisaniu realizacji.</div>"); }' .
             'function msgNone(){ show("<div style=\\"margin-top:8px;font-size:12px;color:#777;\\">Brak opinii do tej współpracy — możesz poprosić o opinię w zakładce Opinie lub później.</div>"); }' .
             'function check(){ var dealId = sel.value || ""; if(!dealId){ show(""); return; }' .
             'var data = new URLSearchParams(); data.append("action","bm_pf_check_deal_review"); data.append("deal_id",dealId); data.append("_ajax_nonce", nonce);' .
             'fetch(ajaxUrl, {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"}, body:data.toString()})' .
             '.then(function(r){return r.json();})' .
             '.then(function(res){ if(res && res.success && res.data && res.data.has_review){ msgHas(); } else { msgNone(); } })' .
             '.catch(function(){ msgNone(); });' .
             '}' .
             'sel.addEventListener("change", check);' .
             'if(sel.value){ check(); }' .
             '})();</script>';

        echo '</div>'; // portal section

        // Sekcja: poza portalem (prośba mailowa)
        echo '<div class="bm-portfolio-origin bm-portfolio-origin-external" style="margin-top:10px;">';
        echo '<div class="bm-portfolio-deal-hint">Realizacja poza portalem: po dodaniu portfolio zostanie wysłana prośba o opinię do klienta. Otrzyma link do formularza i wystawi opinię bez logowania.</div>';
        echo '<p><label>Nazwa Twojej firmy <span class="required">*</span><br><input type="text" name="bm_pf_ext_company" value=""></label></p>';
        echo '<p><label>Nazwa projektu <span class="required">*</span><br><input type="text" name="bm_pf_ext_project" value=""></label></p>';
        echo '<p><label>Krótki opis projektu (opcjonalnie)<br><textarea name="bm_pf_ext_desc" rows="3"></textarea></label></p>';
        echo '<p><label>Adres email klienta <span class="required">*</span><br><input type="email" name="bm_pf_ext_email" value=""></label></p>';
        echo '<p class="bm-portfolio-ext-note" style="font-size:12px;color:#555;margin-top:8px;">Kliknięcie <strong>Dodaj do portfolio</strong> zapisze realizację i wyśle prośbę o opinię do klienta.</p>';
        echo '</div>'; // external section

        // Prosty toggle sekcji (bez konfliktów)
        // - portal: pokazuje wybór współpracy
        // - external: pokazuje prośbę mailową
        // - none: ukrywa obie sekcje
        echo '<script>(function(){var wrap=document.querySelector(".bm-portfolio-form"); if(!wrap) return; function t(){var v=(wrap.querySelector("input[name=\"bm_portfolio_origin\"]:checked")||{}).value||"portal"; var p=wrap.querySelector(".bm-portfolio-origin-portal"); var e=wrap.querySelector(".bm-portfolio-origin-external"); if(p) p.style.display=(v==="portal")?"block":"none"; if(e) e.style.display=(v==="external")?"block":"none"; if(v==="none"){ if(p) p.style.display="none"; if(e) e.style.display="none"; }} wrap.addEventListener("change", function(ev){ if(ev.target && ev.target.name==="bm_portfolio_origin") t(); }); t();})();</script>';

        echo '<p><label>Rodzaj projektu<br>';
        echo '<select name="bm_portfolio_status">';
        echo '<option value="completed" ' . selected($val_status, 'completed', false) . '>Projekt zrealizowany</option>';
        echo '<option value="concept" ' . selected($val_status, 'concept', false) . '>Projekt koncepcyjny</option>';
        echo '</select></label></p>';

        echo '<p><label>Krótki opis<br>';
        // wp_editor is heavy but acceptable in account; provide textarea fallback if needed
        if (function_exists('wp_editor')) {
            ob_start();
            wp_editor($val_desc, 'bm_portfolio_desc', [
                'textarea_name' => 'bm_portfolio_desc',
                'media_buttons' => false,
                'textarea_rows' => 6,
                'teeny'         => true,
                'quicktags'     => false,
            ]);
            echo ob_get_clean();
        } else {
            echo '<textarea name="bm_portfolio_desc" rows="6">' . esc_textarea($val_desc) . '</textarea>';
        }
        echo '</label></p>';

        echo '<p><label>Link do szczegółów / projektu (URL)<br>';
        echo '<input type="url" name="bm_portfolio_url" placeholder="https://..." value="' . esc_attr($val_url) . '"></label></p>';

        // Zdjęcia
        echo '<p><label>Zdjęcia (max 6)<br>';
        echo '<input type="file" name="bm_portfolio_images[]" accept="image/*" multiple></label><br>';
        echo '<span style="font-size:12px;color:#666;">Jeśli edytujesz realizację i nie wybierzesz nowych zdjęć – obecna galeria zostanie bez zmian.</span></p>';

        // Podgląd obecnych zdjęć przy edycji
        if (!empty($val_gallery)) {
            echo '<div class="bm-portfolio-gallery-preview">';
            foreach (array_slice($val_gallery, 0, 6) as $att_id) {
                $img = wp_get_attachment_image($att_id, 'thumbnail', false, ['class' => 'bm-portfolio-thumb']);
                if ($img) echo $img;
            }
            echo '</div>';
        }

        echo '<p><button type="submit" class="button" name="bm_portfolio_save" value="1">' . ($edit_post ? 'Zapisz zmiany' : 'Dodaj do portfolio') . '</button></p>';

        if ($edit_post) {
            $back = wc_get_account_endpoint_url('portfolio');
            echo '<p><a class="button" href="' . esc_url($back) . '">Anuluj edycję</a></p>';
        }

        echo '</form>';
        echo '</div>';
    } else {
        echo '<div class="woocommerce-error" role="alert">Osiągnąłeś limit realizacji w darmowym planie.</div>';
    }

    // Lista realizacji
    $items = get_posts([
        'post_type'      => 'bm_portfolio',
        'post_status'    => 'publish',
        'author'         => (int) $current_id,
        'numberposts'    => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    echo '<div class="bm-portfolio-box bm-portfolio-box--list">';
    echo '<h4>Twoje realizacje</h4>';

    if (empty($items)) {
        echo '<p>Nie masz jeszcze żadnych realizacji w portfolio.</p>';
    } else {

        echo '<table class="shop_table shop_table_responsive bm-portfolio-table">';
        echo '<thead><tr><th>Nazwa</th><th>Rodzaj</th><th>Kategoria</th><th>Data</th><th>Akcje</th></tr></thead><tbody>';

        foreach ($items as $it) {
            $pid = (int) $it->ID;
            $status = get_post_meta($pid, '_bm_portfolio_status', true);
            $terms  = wp_get_post_terms($pid, 'bm_portfolio_category', ['fields' => 'names']);
            $cat    = !is_wp_error($terms) && !empty($terms) ? implode(', ', $terms) : '';

            $edit_link = add_query_arg(['edit' => $pid], wc_get_account_endpoint_url('portfolio'));

            $delete_nonce = wp_create_nonce('bm_portfolio_delete_' . $pid);
            $delete_link  = add_query_arg([
                'bm_portfolio_delete' => $pid,
                '_bm_pdnonce'         => $delete_nonce,
            ], wc_get_account_endpoint_url('portfolio'));

            echo '<tr>';
            echo '<td>' . esc_html($it->post_title) . '</td>';
            echo '<td>' . esc_html(bm_portfolio_get_status_label($status)) . '</td>';
            echo '<td>' . esc_html($cat) . '</td>';
            echo '<td>' . esc_html(get_the_date('', $pid)) . '</td>';
            echo '<td>';
            echo '<a class="button" href="' . esc_url($edit_link) . '">Edytuj</a> ';
            echo '<a class="button" href="' . esc_url($delete_link) . '" onclick="return confirm(\'Usunąć tę realizację?\');">Usuń</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>'; // list box
    echo '</div>'; // wrapper
});

/* ----------------------------------------------------------
 * 4. Obsługa zapisu / usuwania (PRG) – przed outputem
 * ---------------------------------------------------------- */

add_action('template_redirect', function () {

    if (!is_user_logged_in()) return;

    // usuwanie
    if (!empty($_GET['bm_portfolio_delete']) && !empty($_GET['_bm_pdnonce']) && function_exists('wc_get_account_endpoint_url')) {

        $pid = absint($_GET['bm_portfolio_delete']);
        $nonce = sanitize_text_field($_GET['_bm_pdnonce']);

        if ($pid && wp_verify_nonce($nonce, 'bm_portfolio_delete_' . $pid)) {

            $post = get_post($pid);
            $uid  = get_current_user_id();

            if ($post && $post->post_type === 'bm_portfolio' && (int)$post->post_author === (int)$uid && bm_is_supplier_user($uid)) {
                wp_trash_post($pid);

                $redir = add_query_arg(['bm_portfolio_msg' => 'deleted'], wc_get_account_endpoint_url('portfolio'));
                wp_safe_redirect($redir);
                exit;
            }
        }
    }

    // zapis
    if (empty($_POST['bm_portfolio_save']) || empty($_POST['_bm_portfolio_nonce'])) return;

    if (!wp_verify_nonce(sanitize_text_field($_POST['_bm_portfolio_nonce']), 'bm_portfolio_save')) return;

    $uid = get_current_user_id();
    if (!bm_is_supplier_user($uid)) return;

    $pid = !empty($_POST['bm_portfolio_id']) ? absint($_POST['bm_portfolio_id']) : 0;

    // limit tylko przy dodawaniu nowego
    if (!$pid) {
        $limit = bm_portfolio_limit_for_user($uid);
        $count = bm_portfolio_count_for_user($uid);
        if ($count >= $limit) {
            $redir = add_query_arg(['bm_portfolio_msg' => 'limit'], wc_get_account_endpoint_url('portfolio'));
            wp_safe_redirect($redir);
            exit;
        }
    } else {
        $p = get_post($pid);
        if (!$p || $p->post_type !== 'bm_portfolio' || (int)$p->post_author !== (int)$uid) {
            $pid = 0;
        }
    }

    $title  = isset($_POST['bm_portfolio_title']) ? sanitize_text_field($_POST['bm_portfolio_title']) : '';
    $desc   = isset($_POST['bm_portfolio_desc']) ? wp_kses_post($_POST['bm_portfolio_desc']) : '';
    $status = isset($_POST['bm_portfolio_status']) ? sanitize_key($_POST['bm_portfolio_status']) : 'completed';
    $url    = isset($_POST['bm_portfolio_url']) ? esc_url_raw($_POST['bm_portfolio_url']) : '';
    $cat_id = !empty($_POST['bm_portfolio_category']) ? absint($_POST['bm_portfolio_category']) : 0;
    // nowa kategoria (opcjonalnie)
    $new_cat_name = !empty($_POST['bm_portfolio_new_category']) ? sanitize_text_field($_POST['bm_portfolio_new_category']) : '';
    if ($new_cat_name) {
        $maybe = term_exists($new_cat_name, 'bm_portfolio_category');
        if (is_array($maybe) && !empty($maybe['term_id'])) {
            $cat_id = (int) $maybe['term_id'];
        } elseif (!$maybe) {
            $created = wp_insert_term($new_cat_name, 'bm_portfolio_category');
            if (!is_wp_error($created) && !empty($created['term_id'])) {
                $cat_id = (int) $created['term_id'];
            }
        }
    }

    // opinia: źródło + powiązanie
    $origin = !empty($_POST['bm_portfolio_origin']) ? sanitize_key($_POST['bm_portfolio_origin']) : 'portal';
    if (!in_array($origin, ['portal','external','none'], true)) { $origin = 'portal'; }
    $deal_id = ($origin === 'portal' && !empty($_POST['bm_portfolio_deal_id'])) ? absint($_POST['bm_portfolio_deal_id']) : 0;

    // dane do prośby o opinię spoza portalu (wyślij mail)
    $send_external_request = ($origin === 'external');
    $ext_company = $send_external_request && !empty($_POST['bm_pf_ext_company']) ? sanitize_text_field($_POST['bm_pf_ext_company']) : '';
    $ext_project = $send_external_request && !empty($_POST['bm_pf_ext_project']) ? sanitize_text_field($_POST['bm_pf_ext_project']) : '';
    $ext_desc    = $send_external_request && !empty($_POST['bm_pf_ext_desc']) ? sanitize_textarea_field($_POST['bm_pf_ext_desc']) : '';
    $ext_email   = $send_external_request && !empty($_POST['bm_pf_ext_email']) ? sanitize_email($_POST['bm_pf_ext_email']) : '';

    if (!$title) {
        $redir = add_query_arg(['bm_portfolio_msg' => 'error'], wc_get_account_endpoint_url('portfolio'));
        wp_safe_redirect($redir);
        exit;
    }

    if (!in_array($status, ['completed', 'concept'], true)) {
        $status = 'completed';
    }

    $postarr = [
        'post_type'    => 'bm_portfolio',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $desc,
        'post_author'  => $uid,
    ];

    if ($pid) {
        $postarr['ID'] = $pid;
        $new_id = wp_update_post($postarr, true);
    } else {
        $new_id = wp_insert_post($postarr, true);
    }

    if (!$new_id || is_wp_error($new_id)) {
        $redir = add_query_arg(['bm_portfolio_msg' => 'error'], wc_get_account_endpoint_url('portfolio'));
        wp_safe_redirect($redir);
        exit;
    }

    update_post_meta($new_id, '_bm_portfolio_status', $status);
    update_post_meta($new_id, '_bm_portfolio_url', $url);
    // zapisz powiązanie opinii (portal / poza portalem)
    update_post_meta($new_id, '_bm_portfolio_origin', $origin);
    update_post_meta($new_id, '_bm_portfolio_deal_id', $deal_id);

    // Jeśli wybrano realizację w portalu – spróbuj automatycznie podpiąć istniejącą opinię do tej współpracy
    if ($origin === 'portal' && $deal_id) {
        $existing_reviews = get_posts([
            'post_type'   => 'bm_review',
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                [ 'key' => '_bm_review_deal_id',   'value' => $deal_id ],
                [ 'key' => '_bm_review_target_id', 'value' => $uid ],
            ],
        ]);
        if (!empty($existing_reviews)) {
            $rid = (int) $existing_reviews[0];
            update_post_meta($new_id, '_bm_portfolio_review_id', $rid);
            // dwukierunkowe powiązanie (bez konfliktów)
            if (!get_post_meta($rid, '_bm_review_portfolio_id', true)) {
                update_post_meta($rid, '_bm_review_portfolio_id', $new_id);
            }
        } else {
            // brak opinii do tej współpracy – czyścimy powiązanie, jeśli było
            update_post_meta($new_id, '_bm_portfolio_review_id', 0);
        }
    }

    // Jeśli wybrano realizację poza portalem i kliknięto 'Wyślij prośbę o opinię' – utwórz request + wyślij mail
    if ($send_external_request) {
        if (!$ext_company || !$ext_project || !$ext_email) {
            // brak wymaganych danych – nic nie wysyłamy
        } elseif (function_exists('bm_has_review_request_for_email') && bm_has_review_request_for_email($uid, $ext_email)) {
            // mail był już proszony – blokada
        } else {
            $token = wp_generate_password(32, false, false);
            $request_id = wp_insert_post([
                'post_type'   => 'bm_review_request',
                'post_status' => 'publish',
                'post_title'  => 'Prośba o opinię – ' . $ext_project,
                'post_author' => $uid,
            ], true);
            if ($request_id && !is_wp_error($request_id)) {
                update_post_meta($request_id, '_bm_rr_supplier_id', $uid);
                update_post_meta($request_id, '_bm_rr_email', $ext_email);
                update_post_meta($request_id, '_bm_rr_company', $ext_company);
                update_post_meta($request_id, '_bm_rr_project', $ext_project);
                update_post_meta($request_id, '_bm_rr_desc', $ext_desc);
                update_post_meta($request_id, '_bm_rr_token', $token);
                update_post_meta($request_id, '_bm_rr_used', 0);
                update_post_meta($request_id, '_bm_rr_created', time());
                update_post_meta($request_id, '_bm_rr_portfolio_id', $new_id);
                update_post_meta($request_id, '_bm_rr_deal_id', 0);

                // link do formularza (działa po przeniesieniu domeny – token jest w DB)
                $link = add_query_arg(['token' => $token], home_url('/opinia/'));
                $subject = 'Prośba o opinię – ' . $ext_company;
                $message = "To wiadomość z portalu BrandManager.\n\n";
                $message .= $ext_company . " prosi o wystawienie opinii do projektu: " . $ext_project . "\n\n";
                if ($ext_desc) { $message .= "Opis projektu: " . $ext_desc . "\n\n"; }
                $message .= "Kliknij w link, aby wystawić opinię (ważny 48h):\n" . $link . "\n\n";
                $message .= "Jeśli nie chcesz wystawiać opinii, zignoruj tę wiadomość.";
                wp_mail($ext_email, $subject, $message);
            }
        }
    }

    // ustaw kategorię
    if ($cat_id) {
        wp_set_post_terms($new_id, [$cat_id], 'bm_portfolio_category', false);
    } else {
        wp_set_post_terms($new_id, [], 'bm_portfolio_category', false);
    }

    // zdjęcia – jeśli użytkownik coś dodał, podmieniamy galerię (max 6)
    if (!empty($_FILES['bm_portfolio_images']) && !empty($_FILES['bm_portfolio_images']['name']) && is_array($_FILES['bm_portfolio_images']['name'])) {

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $files = $_FILES['bm_portfolio_images'];

        $gallery_ids = [];

        // znormalizuj tablicę plików
        $count_files = min(count($files['name']), 6);

        for ($i = 0; $i < $count_files; $i++) {

            if (empty($files['name'][$i])) continue;

            $file = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];

            // media_handle_sideload expects $_FILES-like single file in a global
            $overrides = ['test_form' => false];

            $tmp = wp_handle_upload($file, $overrides);
            if (isset($tmp['error']) || empty($tmp['file'])) {
                continue;
            }

            $attachment = [
                'post_mime_type' => $tmp['type'],
                'post_title'     => sanitize_file_name($file['name']),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];

            $attach_id = wp_insert_attachment($attachment, $tmp['file'], $new_id);
            if (is_wp_error($attach_id) || !$attach_id) {
                continue;
            }

            $attach_data = wp_generate_attachment_metadata($attach_id, $tmp['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);

            $gallery_ids[] = (int) $attach_id;
        }

        if (!empty($gallery_ids)) {
            update_post_meta($new_id, '_bm_portfolio_gallery', $gallery_ids);
        }
    }

    $redir = add_query_arg(['bm_portfolio_msg' => 'saved'], wc_get_account_endpoint_url('portfolio'));
    wp_safe_redirect($redir);
    exit;
});

/* ----------------------------------------------------------
 * 5. Shortcody pod Elementor (wizytówka / loop / oferta)
 * ---------------------------------------------------------- */

if (!function_exists('bm_portfolio_query_ids')) {
    function bm_portfolio_query_ids($user_id, $limit = 0): array
    {
        $args = [
            'post_type'      => 'bm_portfolio',
            'post_status'    => 'publish',
            'author'         => (int)$user_id,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];
        if ($limit) $args['posts_per_page'] = (int)$limit;
        else $args['posts_per_page'] = -1;

        $q = new WP_Query($args);
        return !empty($q->posts) ? array_map('intval', $q->posts) : [];
    }
}

add_shortcode('bm_portfolio_list', function ($atts) {

    $atts = shortcode_atts([
        'user_id'      => 0,
        'supplier_id'  => 0,
        'limit'        => 0,
        'empty'        => '', // np. "Brak realizacji"
        'class'        => '',
    ], $atts, 'bm_portfolio_list');

    $user_id = absint($atts['user_id']);

    if (!$user_id && !empty($atts['supplier_id'])) {
        $supplier_id = absint($atts['supplier_id']);
        if ($supplier_id && get_post_type($supplier_id) === 'dostawca') {
            $user_id = (int) get_post_field('post_author', $supplier_id);
        }
    }

    if (!$user_id) {
        $user_id = bm_portfolio_get_context_user_id();
    }

    if (!$user_id) return '';

    $ids = bm_portfolio_query_ids($user_id, (int)$atts['limit']);

    if (empty($ids)) {
        return $atts['empty'] ? esc_html($atts['empty']) : '';
    }

    $out = '<div class="bm-portfolio-list ' . esc_attr($atts['class']) . '">';

    foreach ($ids as $pid) {
        $out .= bm_portfolio_render_item_html($pid);
    }

    $out .= '</div>';

    return $out;
});

if (!function_exists('bm_portfolio_render_item_html')) {
    function bm_portfolio_render_item_html($portfolio_id): string
    {
        $p = get_post((int)$portfolio_id);
        if (!$p || $p->post_type !== 'bm_portfolio' || $p->post_status !== 'publish') {
            return '';
        }

        $status = get_post_meta($p->ID, '_bm_portfolio_status', true);
        $status_label = bm_portfolio_get_status_label($status);

        $terms = wp_get_post_terms($p->ID, 'bm_portfolio_category', ['fields' => 'names']);
        $cat = (!is_wp_error($terms) && !empty($terms)) ? implode(', ', $terms) : '';

        $url = get_post_meta($p->ID, '_bm_portfolio_url', true);
        $gallery_ids = bm_portfolio_get_gallery_ids($p->ID);
        $first_img = !empty($gallery_ids) ? $gallery_ids[0] : 0;

        $thumb_html = '';
        if ($first_img) {
            $thumb_html = wp_get_attachment_image($first_img, 'medium', false, ['class' => 'bm-portfolio-thumb']);
        }

        $out  = '<article class="bm-portfolio-item" data-portfolio-id="' . esc_attr($p->ID) . '">';
        if ($status_label) {
            $out .= '<div class="bm-portfolio-badge bm-portfolio-badge--' . esc_attr(sanitize_key($status)) . '">' . esc_html($status_label) . '</div>';
        }
        if ($thumb_html) {
            $out .= '<div class="bm-portfolio-thumbwrap">' . $thumb_html . '</div>';
        }
        $out .= '<h4 class="bm-portfolio-title">' . esc_html($p->post_title) . '</h4>';

        if ($cat) {
            $out .= '<div class="bm-portfolio-category">' . esc_html($cat) . '</div>';
        }

        if (!empty($p->post_content)) {
            $out .= '<div class="bm-portfolio-desc">' . wpautop(wp_kses_post($p->post_content)) . '</div>';
        }

        if ($url) {
            $out .= '<div class="bm-portfolio-actions"><a class="bm-portfolio-button" href="' . esc_url($url) . '" target="_blank" rel="noopener">Zobacz szczegóły</a></div>';
        }

        $out .= '</article>';

        return $out;
    }
}

add_shortcode('bm_portfolio_item', function ($atts) {
    $atts = shortcode_atts([
        'id' => 0,
    ], $atts, 'bm_portfolio_item');

    $id = absint($atts['id']);
    if (!$id) return '';
    return bm_portfolio_render_item_html($id);
});


// Shortcode: ocena dla konkretnego portfolio
add_shortcode('bm_portfolio_rating', function($atts){
    $atts = shortcode_atts([
        'id'     => 0,
        'format' => 'short',
        'empty'  => '',
    ], $atts, 'bm_portfolio_rating');

    $pid = absint($atts['id']);
    if (!$pid) return '';

    $rid = (int) get_post_meta($pid, '_bm_portfolio_review_id', true);
    if (!$rid) {
        $q = new WP_Query([
            'post_type'      => 'bm_review',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_bm_review_portfolio_id',
                    'value'   => $pid,
                    'compare' => '=',
                ],
            ],
        ]);
        if (!empty($q->posts[0])) $rid = (int) $q->posts[0];
        wp_reset_postdata();
    }

    if (!$rid) return (string) $atts['empty'];

    $val = get_post_meta($rid, '_bm_rating_overall', true);
    $val = is_numeric($val) ? (float) $val : 0.0;
    if ($val <= 0) return (string) $atts['empty'];

    $out = number_format_i18n($val, 1) . '/5';
    if ($atts['format'] === 'stars') {
        $stars = max(0, min(5, (int) round($val)));
        $out = str_repeat('★', $stars) . str_repeat('☆', 5-$stars) . ' ' . $out;
    }
    return esc_html($out);
});

// Shortcode: procent poleceń dla konkretnego portfolio (TAK/NIE -> 100%/0%)
add_shortcode('bm_portfolio_recommend_percent', function($atts){
    $atts = shortcode_atts([
        'id'    => 0,
        'empty' => '',
    ], $atts, 'bm_portfolio_recommend_percent');

    $pid = absint($atts['id']);
    if (!$pid) return '';

    $rid = (int) get_post_meta($pid, '_bm_portfolio_review_id', true);
    if (!$rid) {
        $q = new WP_Query([
            'post_type'      => 'bm_review',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_bm_review_portfolio_id',
                    'value'   => $pid,
                    'compare' => '=',
                ],
            ],
        ]);
        if (!empty($q->posts[0])) $rid = (int) $q->posts[0];
        wp_reset_postdata();
    }

    if (!$rid) return (string) $atts['empty'];

    $rec = get_post_meta($rid, '_bm_rating_recommend', true); // yes|no
    if ($rec === '') return (string) $atts['empty'];

    $pct = ($rec === 'yes') ? 100 : 0;
    return esc_html($pct . '%');
});


add_shortcode('bm_portfolio_gallery', function ($atts) {

    $atts = shortcode_atts([
        'id'       => 0,
        'size'     => 'medium_large',
        'class'    => '',
        'columns'  => '3',
        'lightbox' => '1',
    ], $atts, 'bm_portfolio_gallery');

    $id = absint($atts['id']);
    if (!$id) return '';

    $ids = bm_portfolio_get_gallery_ids($id);
    if (empty($ids)) return '';

    $out  = '<div class="bm-portfolio-gallery ' . esc_attr($atts['class']) . '" data-portfolio-id="' . (int)$id . '" data-lightbox="' . esc_attr($atts['lightbox']) . '">';

    foreach (array_slice($ids, 0, 6) as $att_id) {
        $att_id = (int) $att_id;
        $thumb  = wp_get_attachment_image($att_id, $atts['size'], false, ['class' => 'bm-portfolio-gallery-img']);
        if (!$thumb) continue;

        $full = wp_get_attachment_url($att_id);
        if (!$full) continue;

        $out .= '<a class="bm-portfolio-gallery-link" href="' . esc_url($full) . '">' . $thumb . '</a>';
    }

    $out .= '</div>';

    return $out;
});



/* ----------------------------------------------------------
 * 6. Flush rewrite rules (bezpiecznie)
 * ---------------------------------------------------------- */

register_activation_hook(__FILE__, function () {
    // UWAGA: activation hook w pliku include nie zadziała, ale zostawiamy dla czytelności.
});

// rzeczywisty flush przy aktywacji pluginu
add_action('init', function () {
    // nic – rewrite jest rejestrowany wyżej
}, 99);


/* ==========================================================
 * PORTFOLIO – SHORTCODY POLOWE + AUTO-KONTEKST
 * ========================================================== */

/**
 * Rozpoznaje ID dostawcy (user_id) w zależności od kontekstu:
 * - single dostawca (post_type=dostawca): author posta to user_id dostawcy
 * - oferta dostawcy / ogłoszenie (post_author): autor posta to user_id dostawcy
 * - single portfolio (bm_portfolio): autor portfolio to user_id dostawcy
 */
if (!function_exists('bm_get_context_supplier_user_id')) {
    function bm_get_context_supplier_user_id() {
        $post_id = get_the_ID();
        if (!$post_id) return 0;

        $post_type = get_post_type($post_id);

        // Wizytówka dostawcy
        if ($post_type === 'dostawca') {
            return (int) get_post_field('post_author', $post_id);
        }

        // Pojedyncze portfolio
        if ($post_type === 'bm_portfolio') {
            return (int) get_post_field('post_author', $post_id);
        }

        // Oferty / ogłoszenia itp. – zakładamy, że autor posta = dostawca (tak jak mówiłeś)
        // Jeśli masz konkretny post_type ofert, można tu zawęzić.
        $author = (int) get_post_field('post_author', $post_id);
        return $author > 0 ? $author : 0;
    }
}

/**
 * Zwraca ID portfolio w kontekście:
 * - jeśli jesteśmy na single bm_portfolio -> to ID jest kontekstem
 * - jeśli jesteśmy w loopie portfolio -> to też działa (get_the_ID())
 * - jeśli w innym miejscu: można podać atrybut id=""
 */
if (!function_exists('bm_get_context_portfolio_id')) {
    function bm_get_context_portfolio_id($forced_id = 0) {
        $forced_id = (int) $forced_id;
        if ($forced_id) return $forced_id;

        $post_id = get_the_ID();
        if ($post_id && get_post_type($post_id) === 'bm_portfolio') {
            return (int) $post_id;
        }

        return 0;
    }
}

/**
 * Helper: tekst statusu projektu
 */
if (!function_exists('bm_portfolio_status_label')) {
    function bm_portfolio_status_label($status) {
        $status = sanitize_key($status);
        if ($status === 'realized') return 'Zrealizowany';
        if ($status === 'concept')  return 'Koncepcyjny';
        return '';
    }
}

/**
 * [bm_portfolio_field field="title|description|status|status_label|url|categories|category|gallery_count" id="123" sep=", "]
 * - field="categories" zwraca listę kategorii portfolio (dla konkretnego portfolio)
 * - field="category" zwraca pierwszą kategorię
 */
add_shortcode('bm_portfolio_field', function($atts) {
    $atts = shortcode_atts([
        'field' => '',
        'id'    => '',
        'sep'   => ', ',
    ], $atts, 'bm_portfolio_field');

    $field = sanitize_key($atts['field']);
    $id    = bm_get_context_portfolio_id($atts['id']);
    $sep   = (string) $atts['sep'];

    if (!$field) return '';

    // jeśli pole dotyczy konkretnego portfolio, a nie mamy ID – zwracamy pusty string
    $needs_portfolio = in_array($field, ['title','description','status','status_label','url','categories','category','gallery_count'], true);
    if ($needs_portfolio && !$id) {
        // Jeśli shortcode użyty na wizytówce dostawcy / w loopie dostawcy, a nie w kontekście pojedynczej realizacji,
        // zwracamy listę wartości z wszystkich realizacji tego dostawcy (sep = separator, np. "<br>").
        $ctx_user = function_exists('bm_portfolio_get_context_user_id') ? (int) bm_portfolio_get_context_user_id() : 0;
        if (!$ctx_user) return '';

        $ids = get_posts([
            'post_type'      => 'bm_portfolio',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'author'         => $ctx_user,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        if (empty($ids)) return '';

        // Mapujemy wartości dla danego pola po wszystkich realizacjach
        $vals = [];
        foreach ($ids as $pid) {
            $pid = (int) $pid;
            switch ($field) {
                case 'title':
                    $vals[] = get_the_title($pid);
                    break;
                case 'status':
                    $vals[] = (string) get_post_meta($pid, '_bm_portfolio_status', true);
                    break;
                case 'status_label':
                    $vals[] = bm_portfolio_status_label(get_post_meta($pid, '_bm_portfolio_status', true));
                    break;
                case 'url':
                    $u = trim((string) get_post_meta($pid, '_bm_portfolio_url', true));
                    if ($u) $vals[] = $u;
                    break;
                case 'category':
                case 'categories':
                    $terms = get_the_terms($pid, 'bm_portfolio_category');
                    if (!empty($terms) && !is_wp_error($terms)) {
                        $names = array_map(function($t){ return $t->name; }, $terms);
                        $vals[] = ($field === 'category') ? ($names[0] ?? '') : implode($sep, $names);
                    }
                    break;
            }
        }

        $vals = array_values(array_filter(array_map('trim', $vals)));
        if (empty($vals)) return '';

        // Uwaga: sep może zawierać <br>, dlatego używamy wp_kses_post
        return wp_kses_post(implode($sep, array_map('esc_html', $vals)));
    }

    switch ($field) {
        case 'title':
            return esc_html(get_the_title($id));

        case 'description':
            // WYSIWYG = post_content
            $content = get_post_field('post_content', $id);
            return apply_filters('the_content', $content);

        case 'status':
            return esc_html(get_post_meta($id, '_bm_portfolio_status', true));

        case 'status_label':
            return esc_html(bm_portfolio_status_label(get_post_meta($id, '_bm_portfolio_status', true)));

        case 'url':
            $url = trim((string) get_post_meta($id, '_bm_portfolio_url', true));
            return $url ? esc_url($url) : '';

        case 'categories':
        case 'category':
            $terms = get_the_terms($id, 'bm_portfolio_category');
            if (empty($terms) || is_wp_error($terms)) return '';
            $names = array_map(function($t){ return $t->name; }, $terms);
            if ($field === 'category') return esc_html($names[0] ?? '');
            return esc_html(implode($sep, $names));

        case 'gallery_count':
            $ids = get_post_meta($id, '_bm_portfolio_gallery', true);
            if (!is_array($ids)) $ids = [];
            return (string) count($ids);
    }

    return '';
});

/**
 * Galeria jako HTML (do wstawienia w Elementorze w HTML widget)
 * [bm_portfolio_gallery id="123" columns="3" size="medium"]
 */


/* (zostawione miejsce po zduplikowanym shortcode bm_portfolio_gallery – usunięto duplikat, aby lightbox działał) */



/**
 * AUTO KATEGORIE DOSTAWCY – “tak jak w ofercie dostawcy”
 *
 * Ten shortcode zwróci listę kategorii z portfolio DOSTAWCY,
 * automatycznie rozpoznając dostawcę z kontekstu strony:
 * - wizytówka dostawcy
 * - oferta dostawcy
 * - dowolny wpis autora (post_author)
 *
 * [bm_portfolio_categories sep=", "]
 */
add_shortcode('bm_portfolio_categories', function($atts) {
    $atts = shortcode_atts([
        'sep' => ', ',
    ], $atts, 'bm_portfolio_categories');

    $sep = (string) $atts['sep'];

    $supplier_user_id = bm_get_context_supplier_user_id();
    if (!$supplier_user_id) return '';

    // Pobieramy portfolio dostawcy
    $items = get_posts([
        'post_type'      => 'bm_portfolio',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'fields'         => 'ids',
        'author'         => $supplier_user_id,
    ]);

    if (empty($items)) return '';

    $term_names = [];
    foreach ($items as $pid) {
        $terms = get_the_terms($pid, 'bm_portfolio_category');
        if (empty($terms) || is_wp_error($terms)) continue;
        foreach ($terms as $t) {
            $term_names[$t->term_id] = $t->name; // unikalnie
        }
    }

    if (empty($term_names)) return '';

    return esc_html(implode($sep, array_values($term_names)));
});

// Elementor Loop Grid: portfolio dla aktualnego dostawcy (autor dostawcy)
add_action('elementor/query/bm_portfolio_supplier', function( $query ) {

    // upewniamy się, że jesteśmy na single dostawcy
    $supplier_post_id = get_queried_object_id();
    if (!$supplier_post_id) {
        return;
    }

    // autor wpisu "dostawca" = user_id dostawcy
    $supplier_user_id = (int) get_post_field('post_author', $supplier_post_id);
    if (!$supplier_user_id) {
        return;
    }

    $query->set('post_type', 'bm_portfolio');
    $query->set('post_status', 'publish');
    $query->set('author', $supplier_user_id);
    $query->set('posts_per_page', 50);
    $query->set('orderby', 'date');
    $query->set('order', 'DESC');
});


/**
 * Shortcode: [bm_portfolio_loop]
 * - Wyświetla realizacje (bm_portfolio) autora aktualnego dostawcy (post_type=dostawca)
 * - Renderuje każdą realizację osobno przez template (łatwe stylowanie)
 *
 * Atrybuty:
 * - limit (domyślnie 12)
 * - order (DESC/ASC)
 * - orderby (date/title)
 */
add_shortcode('bm_portfolio_loop', function ($atts) {

    $atts = shortcode_atts([
        'limit'   => 12,
        'order'   => 'DESC',
        'orderby' => 'date',
        'empty'   => 'Brak realizacji.',
    ], $atts, 'bm_portfolio_loop');

    $queried_id = get_queried_object_id();
    if (!$queried_id) {
        return '';
    }

    // Zakładamy: jesteśmy na single dostawcy (post_type=dostawca)
    $post_type = get_post_type($queried_id);
    if ($post_type !== 'dostawca') {
        return '';
    }

    $supplier_user_id = (int) get_post_field('post_author', $queried_id);
    if (!$supplier_user_id) {
        return '';
    }

    $q = new WP_Query([
        'post_type'      => 'bm_portfolio',
        'post_status'    => 'publish',
        'author'         => $supplier_user_id,
        'posts_per_page' => (int) $atts['limit'],
        'orderby'        => sanitize_key($atts['orderby']),
        'order'          => strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC',
        'no_found_rows'  => true,
    ]);

    if (!$q->have_posts()) {
        return '<div class="bm-portfolio-empty">' . esc_html($atts['empty']) . '</div>';
    }

    // Szukamy template: najpierw child theme, potem wtyczka
    $theme_template = trailingslashit(get_stylesheet_directory()) . 'brandmanager/portfolio/loop-item.php';
    $plugin_template = trailingslashit(plugin_dir_path(__FILE__)) . '../templates/portfolio/loop-item.php';

    $template_path = file_exists($theme_template) ? $theme_template : $plugin_template;

    ob_start();
    echo '<div class="bm-portfolio-loop">';

    while ($q->have_posts()) {
        $q->the_post();

        $portfolio_id = get_the_ID();

        // Udostępniamy zmienną dla template:
        // $portfolio_id (ID aktualnego portfolio)
        // Możesz czytać meta/shortcody w template.
        include $template_path;
    }

    echo '</div>';
    wp_reset_postdata();

    return ob_get_clean();
});