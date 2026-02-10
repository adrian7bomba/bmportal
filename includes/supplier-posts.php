<?php
/**
 * Moduł: Artykuły Dostawców (Premium - w przyszłości)
 *
 * WERSJA: korzysta ze стандартowych WP "Wpisów" (post_type = post),
 * dzięki czemu:
 * - wpisy Dostawców są widoczne w WP -> Wpisy,
 * - działają szablony Elementora dla wpisów / archiwów,
 * - /wpisy/ nie jest zależne od CPT.
 *
 * Każdy artykuł Dostawcy ma meta: _bm_supplier_article = 1
 *
 * Funkcje:
 * - Woo endpoint: /moje-konto/artykuly/
 * - limit: 5/miesiąc/użytkownik
 * - zawsze pending (zatwierdza superadmin)
 * - admin może odrzucić (status: bm_rejected)
 * - powiadomienia email: admin (nowy), autor (zatwierdzony/odrzucony)
 * - archiwum autora: /artykuly-dostawcow/dostawca/{user_nicename}/ (tylko artykuły Dostawcy)
 *
 * Bezpieczeństwo:
 * - tylko zalogowani + rola dostawca
 * - nonce
 * - honeypot
 * - rate limit (transient)
 * - wp_kses_post (treść)
 */

if (!defined('ABSPATH')) { exit; }

const BM_SA_META_KEY = '_bm_supplier_article';
const BM_SA_MONTHLY_LIMIT = 5;

/** -------------------------------------------------------
 * 0) Migracja z poprzedniego CPT (jeśli istnieje w bazie)
 * ------------------------------------------------------ */
add_action('init', function () {
    if (get_option('bm_sa_migrated_to_posts') === '1') return;

    // Jeśli ktoś ma w bazie stare wpisy CPT bm_supplier_article – konwertujemy je do 'post'
    $old = get_posts([
        'post_type'      => 'bm_supplier_article',
        'post_status'    => ['publish','pending','draft','bm_rejected','private'],
        'numberposts'    => 200,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    if (!empty($old)) {
        foreach ($old as $pid) {
            // Zachowaj kategorie (jeśli były przypięte do category)
            $terms = wp_get_post_terms($pid, 'category', ['fields'=>'ids']);
            wp_update_post([
                'ID'        => (int)$pid,
                'post_type' => 'post',
            ]);
            update_post_meta((int)$pid, BM_SA_META_KEY, '1');
            if (!is_wp_error($terms) && !empty($terms)) {
                wp_set_post_terms((int)$pid, $terms, 'category', false);
            }
        }
    }

    update_option('bm_sa_migrated_to_posts', '1', false);
}, 20);

/** -------------------------------------------------------
 * 1) Status "Odrzucony" (dla wpisów Dostawców)
 * ------------------------------------------------------ */
add_action('init', function () {
    register_post_status('bm_rejected', [
        'label'                     => 'Odrzucony',
        'public'                    => true,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
    ]);
});

/** Dodaj status do dropdown w edycji posta */
add_action('admin_footer-post.php', function () {
    global $post;
    if (!$post || $post->post_type !== 'post') return;
    // tylko dla postów dostawców
    if (get_post_meta($post->ID, BM_SA_META_KEY, true) !== '1') return;
    ?>
    <script>
    (function(){
      var sel = document.querySelector('#post_status');
      if(!sel) return;
      var exists = false;
      for (var i=0;i<sel.options.length;i++){ if(sel.options[i].value==='bm_rejected'){ exists=true; break; } }
      if(!exists){
        var opt = document.createElement('option');
        opt.value = 'bm_rejected';
        opt.text = 'Odrzucony';
        sel.appendChild(opt);
      }
    })();
    </script>
    <?php
});

/** -------------------------------------------------------
 * 2) Endpoint "artykuly" w Moje konto
 * ------------------------------------------------------ */
add_action('init', function () {
    add_rewrite_endpoint('artykuly', EP_ROOT | EP_PAGES);
});

/** Menu: dodaj "Artykuły" + badge CSS (bez HTML w etykiecie) */
add_filter('woocommerce_account_menu_items', function ($items) {
    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) return $items;

    if (!in_array('dostawca', (array)$user->roles, true)) return $items;

    $new = [];
    $inserted = false;

    foreach ($items as $key => $label) {
        $new[$key] = $label;
        if ($key === 'portfolio' && !$inserted) {
            $new['artykuly'] = 'Artykuły'; // badge robimy w CSS
            $inserted = true;
        }
    }
    if (!$inserted) $new['artykuly'] = 'Artykuły';

    return $new;
});

/** Dodaj klasę do elementu menu, żeby CSS mógł dorysować badge */
add_filter('woocommerce_account_menu_item_classes', function($classes, $endpoint){
    if ($endpoint === 'artykuly') $classes[] = 'bm-menu-premium';
    return $classes;
}, 10, 2);

/** -------------------------------------------------------
 * 3) Render zakładki "Artykuły" (lista + formularz)
 * ------------------------------------------------------ */
add_action('woocommerce_account_artykuly_endpoint', function () {
    if (!is_user_logged_in()) {
        echo '<div class="woocommerce-info">Zaloguj się, aby korzystać z tej funkcji.</div>';
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('dostawca', (array)$user->roles, true)) {
        echo '<div class="woocommerce-error">Ta sekcja jest dostępna tylko dla kont Dostawcy.</div>';
        return;
    }

    // INFO (na razie do testów)
    echo '<div class="bm-premium-note">TYLKO PREMIUM: Ta funkcja będzie dostępna w pakiecie Premium. Na potrzeby testów jest chwilowo odblokowana.</div>';

    // komunikaty
    if (!empty($_GET['bm_msg'])) {
        $msg = sanitize_text_field(wp_unslash($_GET['bm_msg']));
        if ($msg === 'saved') {
            echo '<div class="woocommerce-message">Artykuł został zapisany i czeka na zatwierdzenie.</div>';
        } elseif ($msg === 'updated') {
            echo '<div class="woocommerce-message">Zmiany zapisane. Artykuł ponownie czeka na zatwierdzenie.</div>';
        } elseif ($msg === 'limit') {
            echo '<div class="woocommerce-error">Osiągnięto limit 5 artykułów w tym miesiącu.</div>';
        } elseif ($msg === 'forbidden') {
            echo '<div class="woocommerce-error">Brak uprawnień do tej operacji.</div>';
        }
    }

    // akcje
    $action  = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
    $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

    if ($action === 'add' || ($action === 'edit' && $post_id)) {
        bm_sa_render_form((int)$user->ID, $post_id);
        return;
    }

    // Lista
    $add_url = add_query_arg(['action'=>'add'], wc_get_account_endpoint_url('artykuly'));
    echo '<div class="bm-articles-head">';
    echo '<a class="bm-btn bm-btn-yellow" href="' . esc_url($add_url) . '">Dodaj artykuł</a>';
    echo '</div>';

    bm_sa_render_list((int)$user->ID);
});

/** -------------------------------------------------------
 * 4) Obsługa zapisu (add/edit)
 * ------------------------------------------------------ */
add_action('init', function(){
    if (!is_user_logged_in()) return;
    if (empty($_POST['bm_sa_submit'])) return;

    $user = wp_get_current_user();
    if (!in_array('dostawca', (array)$user->roles, true)) return;

    // nonce
    if (empty($_POST['bm_sa_nonce']) || !wp_verify_nonce($_POST['bm_sa_nonce'], 'bm_sa_save')) {
        bm_sa_safe_redirect(['bm_msg'=>'forbidden']);
        exit;
    }

    // honeypot (bot)
    if (!empty($_POST['bm_sa_hp'])) {
        bm_sa_safe_redirect(['bm_msg'=>'forbidden']);
        exit;
    }

    // rate limit (min 45s)
    $rate_key = 'bm_sa_rate_' . (int)$user->ID;
    if (get_transient($rate_key)) {
        bm_sa_safe_redirect(['bm_msg'=>'forbidden']);
        exit;
    }
    set_transient($rate_key, 1, 45);

    $edit_id = isset($_POST['bm_sa_post_id']) ? (int)$_POST['bm_sa_post_id'] : 0;

    // uprawnienia edycji
    if ($edit_id) {
        $p = get_post($edit_id);
        if (!$p || (int)$p->post_author !== (int)$user->ID || get_post_meta($edit_id, BM_SA_META_KEY, true) !== '1') {
            bm_sa_safe_redirect(['bm_msg'=>'forbidden']);
            exit;
        }
    } else {
        // limit tylko dla nowych
        if (!bm_sa_can_add_more((int)$user->ID)) {
            bm_sa_safe_redirect(['bm_msg'=>'limit']);
            exit;
        }
    }

    $title   = isset($_POST['bm_sa_title']) ? sanitize_text_field(wp_unslash($_POST['bm_sa_title'])) : '';
    $excerpt = isset($_POST['bm_sa_excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['bm_sa_excerpt'])) : '';
    $content = isset($_POST['bm_sa_content']) ? wp_kses_post(wp_unslash($_POST['bm_sa_content'])) : '';

    $cat_id  = isset($_POST['bm_sa_category']) ? (int)$_POST['bm_sa_category'] : 0; 

    // zajawka wymagana
    if (trim($excerpt) === '') {
        // bez redirect: pokaż błąd na tej samej stronie
        add_filter('woocommerce_add_error', '__return_true'); // no-op
        wc_add_notice('Zajawka jest wymagana.', 'error');
        // pokaż form ponownie
        bm_sa_render_form((int)$user->ID, $edit_id);
        exit;
    } 

    $postarr = [
        'post_type'    => 'post',
        'post_status'  => 'pending',
        'post_author'  => (int)$user->ID,
        'post_title'   => $title,
        'post_excerpt' => $excerpt,
        'post_content' => $content,
    ];

    if ($edit_id) {
        $postarr['ID'] = $edit_id;
        $pid = wp_update_post($postarr, true);
    } else {
        $pid = wp_insert_post($postarr, true);
    }

    if (is_wp_error($pid) || !$pid) {
        wc_add_notice('Wystąpił błąd podczas zapisu artykułu.', 'error');
        bm_sa_render_form((int)$user->ID, $edit_id);
        exit;
    }

    update_post_meta((int)$pid, BM_SA_META_KEY, '1');

    if ($cat_id) {
        wp_set_post_terms((int)$pid, [$cat_id], 'category', false);
    }

    // mail do admina przy nowym (lub przy ponownym wysłaniu)
    bm_sa_mail_admin_new_pending((int)$pid);

    bm_sa_safe_redirect(['bm_msg' => $edit_id ? 'updated' : 'saved']);
    exit;
});

/** Safe redirect: jeśli headers sent, to nie rób redirect (unikamy warningów) */
function bm_sa_safe_redirect(array $args){
    $url = add_query_arg($args, wc_get_account_endpoint_url('artykuly'));
    if (!headers_sent()) {
        wp_safe_redirect($url);
        return;
    }
    // fallback: pokaż link
    echo '<div class="woocommerce-message">Zapisano. <a href="' . esc_url($url) . '">Wróć do listy</a></div>';
}

/** limit 5/miesiąc */
function bm_sa_can_add_more(int $user_id): bool {
    $start = gmdate('Y-m-01 00:00:00');
    $end   = gmdate('Y-m-t 23:59:59');

    $q = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => ['publish','pending','draft','bm_rejected','private'],
        'author'         => $user_id,
        'date_query'     => [
            [
                'after'     => $start,
                'before'    => $end,
                'inclusive' => true,
                'column'    => 'post_date_gmt',
            ]
        ],
        'meta_key'       => BM_SA_META_KEY,
        'meta_value'     => '1',
        'fields'         => 'ids',
        'posts_per_page' => 1,
        'no_found_rows'  => false,
    ]);

    return (int)$q->found_posts < BM_SA_MONTHLY_LIMIT;
}

/** lista */
function bm_sa_render_list(int $user_id){
    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => ['publish','pending','draft','bm_rejected'],
        'author'         => $user_id,
        'meta_key'       => BM_SA_META_KEY,
        'meta_value'     => '1',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'numberposts'    => 50,
    ]);

    echo '<table class="shop_table shop_table_responsive bm-articles-table">';
    echo '<thead><tr><th>Tytuł</th><th>Status</th><th>Data</th><th>Akcje</th></tr></thead><tbody>';

    if (!$posts) {
        echo '<tr><td colspan="4">Brak artykułów.</td></tr>';
    } else {
        foreach ($posts as $p) {
            $status = bm_sa_human_status($p->post_status);
            $edit_url = add_query_arg(['action'=>'edit','post_id'=>$p->ID], wc_get_account_endpoint_url('artykuly'));
            echo '<tr>';
            echo '<td data-title="Tytuł">' . esc_html($p->post_title ?: '(bez tytułu)') . '</td>';
            echo '<td data-title="Status"><span class="bm-status bm-status-' . esc_attr($p->post_status) . '">' . esc_html($status) . '</span></td>';
            echo '<td data-title="Data">' . esc_html(get_the_date('Y-m-d', $p)) . '</td>';
            echo '<td data-title="Akcje"><a class="bm-link" href="' . esc_url($edit_url) . '">Edytuj</a></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';

    // link do archiwum autora (dla tych wpisów)
    $u = get_user_by('id', $user_id);
    if ($u) {
        echo '<p style="margin-top:10px;">';
        echo '<a class="bm-link" href="' . esc_url(bm_sa_author_archive_url($u)) . '">Zobacz archiwum moich artykułów</a>';
        echo '</p>';
    }
}

function bm_sa_human_status(string $status): string {
    return match ($status) {
        'publish'    => 'Opublikowany',
        'pending'    => 'Czeka na zatwierdzenie',
        'draft'      => 'Szkic',
        'bm_rejected'=> 'Odrzucony',
        default      => $status,
    };
}

/** formularz */
function bm_sa_render_form(int $user_id, int $edit_id = 0){
    $is_edit = $edit_id > 0;
    $title = $excerpt = $content = '';
    $cat_id = 0;

    if (function_exists('wp_enqueue_editor')) wp_enqueue_editor();

    if ($is_edit) {
        $p = get_post($edit_id);
        if (!$p || (int)$p->post_author !== $user_id || get_post_meta($edit_id, BM_SA_META_KEY, true) !== '1') {
            echo '<div class="woocommerce-error">Nie znaleziono artykułu lub brak uprawnień.</div>';
            return;
        }
        $title = $p->post_title;
        $excerpt = $p->post_excerpt;
        $content = $p->post_content;

        $terms = wp_get_post_terms($edit_id, 'category', ['fields'=>'ids']);
        if (!is_wp_error($terms) && !empty($terms)) $cat_id = (int)$terms[0];
    }

    $back_url = wc_get_account_endpoint_url('artykuly');
    echo '<p><a class="bm-link" href="' . esc_url($back_url) . '">← Wróć do listy</a></p>';

    echo '<form method="post" class="bm-article-form">';
    wp_nonce_field('bm_sa_save', 'bm_sa_nonce');
    echo '<input type="hidden" name="bm_sa_submit" value="1">';
    echo '<input type="hidden" name="bm_sa_post_id" value="' . esc_attr($edit_id) . '">';

    echo '<p><label>Tytuł</label><input type="text" name="bm_sa_title" value="' . esc_attr($title) . '" required></p>';

    // Excerpt required (bez "opcjonalnie")
    echo '<p><label>Zajawka</label><textarea name="bm_sa_excerpt" rows="4" required>' . esc_textarea($excerpt) . '</textarea></p>';

    // WYSIWYG
    echo '<p><label>Treść</label></p>';
    ob_start();
    wp_editor($content, 'bm_sa_content_editor', [
        'textarea_name' => 'bm_sa_content',
        'media_buttons' => false,
        'teeny'         => true,
        'quicktags'     => true,
        'textarea_rows' => 12,
    ]);
    echo ob_get_clean();

    // Kategorie (globalne WP)
    $cats = get_terms([
        'taxonomy'   => 'category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    echo '<p><label>Kategoria</label>';
    echo '<select name="bm_sa_category">';
    echo '<option value="">— wybierz —</option>';
    if (!is_wp_error($cats)) {
        foreach ($cats as $c) {
            echo '<option value="' . (int)$c->term_id . '" ' . selected($cat_id, (int)$c->term_id, false) . '>' . esc_html($c->name) . '</option>';
        }
    }
    echo '</select></p>'; 

    // honeypot
    echo '<input type="text" name="bm_sa_hp" value="" style="display:none !important" tabindex="-1" autocomplete="off">';

    echo '<p><button type="submit" class="bm-btn bm-btn-yellow">' . ($is_edit ? 'Zapisz zmiany' : 'Wyślij do zatwierdzenia') . '</button></p>';
    echo '</form>';
}

/** -------------------------------------------------------
 * 5) Powiadomienia email
 * ------------------------------------------------------ */
function bm_sa_mail_admin_new_pending(int $post_id): void {
    $p = get_post($post_id);
    if (!$p) return;
    if ($p->post_status !== 'pending') return;
    if (get_post_meta($post_id, BM_SA_META_KEY, true) !== '1') return;

    $admin_email = get_option('admin_email');
    if (!$admin_email) return;

    $site = wp_parse_url(home_url(), PHP_URL_HOST);
    $subject = sprintf('[%s] Nowy artykuł do zatwierdzenia', $site);

    $edit_link = admin_url('post.php?post=' . (int)$post_id . '&action=edit');
    $author = get_user_by('id', (int)$p->post_author);
    $author_name = $author ? ($author->display_name ?: $author->user_login) : '—';

    $body = "Nowy artykuł czeka na zatwierdzenie.\n\nTytuł: {$p->post_title}\nAutor: {$author_name}\n\nEdytuj/Zatwierdź: {$edit_link}";
    wp_mail($admin_email, $subject, $body);
}

add_action('transition_post_status', function($new, $old, $post){
    if (!$post || $post->post_type !== 'post') return;
    if (get_post_meta($post->ID, BM_SA_META_KEY, true) !== '1') return;
    if ($old === $new) return;

    if (!in_array($new, ['publish','bm_rejected'], true)) return;

    $author = get_user_by('id', (int)$post->post_author);
    if (!$author || empty($author->user_email)) return;

    $site = wp_parse_url(home_url(), PHP_URL_HOST);
    $subject = ($new === 'publish')
        ? sprintf('[%s] Twój artykuł został zatwierdzony', $site)
        : sprintf('[%s] Twój artykuł został odrzucony', $site);

    $line = ($new === 'publish')
        ? "Administrator zatwierdził Twój artykuł i jest on już opublikowany."
        : "Administrator odrzucił Twój artykuł. Popraw go i wyślij ponownie do zatwierdzenia.";

    $account = wc_get_account_endpoint_url('artykuly');
    $body = $line . "\n\nTytuł: " . $post->post_title . "\nPanel: " . $account;

    wp_mail($author->user_email, $subject, $body);
}, 10, 3);

/** -------------------------------------------------------
 * 6) Archiwum autora: /artykuly-dostawcow/dostawca/{nicename}/
 * (jako author archive + filtr meta)
 * ------------------------------------------------------ */
add_action('init', function(){
    add_rewrite_rule(
        '^artykuly-dostawcow/dostawca/([^/]+)/?$',
        'index.php?author_name=$matches[1]&bm_supplier_articles=1',
        'top'
    );
});

add_filter('query_vars', function($vars){
    $vars[] = 'bm_supplier_articles';
    return $vars;
});

add_action('pre_get_posts', function($q){
    if (is_admin() || !$q->is_main_query()) return;

    // tylko na naszym author-archive z query var
    if ($q->is_author() && (int)get_query_var('bm_supplier_articles') === 1) {
        $q->set('post_type', 'post');
        $q->set('meta_query', [
            [
                'key'   => BM_SA_META_KEY,
                'value' => '1',
            ]
        ]);
    }
}, 20);

function bm_sa_author_archive_url($user): string {
    if (is_numeric($user)) $user = get_user_by('id', (int)$user);
    if (!$user) return home_url('/');
    return home_url('/artykuly-dostawcow/dostawca/' . $user->user_nicename . '/');
}

/** -------------------------------------------------------
 * 7) Shortcody autora (logo/nazwa firmy) do Elementora
 * ------------------------------------------------------ */
function bm_sa_get_supplier_data(int $author_id): array {
    $supplier_post_id = function_exists('bm_get_or_create_supplier_post') ? (int) bm_get_or_create_supplier_post($author_id) : 0;
    $company = '';
    $logo_url = '';

    if ($supplier_post_id) {
        if (function_exists('get_field')) {
            foreach (['nazwa_firmy','company_name','firma','nazwa'] as $k) {
                $v = get_field($k, $supplier_post_id);
                if (is_string($v) && trim($v) !== '') { $company = trim($v); break; }
            }
            foreach (['logo_dostawca','logo','logotyp','logo_firmy','company_logo'] as $k) {
                $img = get_field($k, $supplier_post_id);
                if (is_array($img) && !empty($img['url'])) { $logo_url = $img['url']; break; }
                if (is_numeric($img)) { $u = wp_get_attachment_image_url((int)$img, 'thumbnail'); if ($u) { $logo_url = $u; break; } }
                if (is_string($img) && filter_var($img, FILTER_VALIDATE_URL)) { $logo_url = $img; break; }
            }
        }
        if ($company === '') $company = get_the_title($supplier_post_id);
        if ($logo_url === '') {
            $thumb = get_the_post_thumbnail_url($supplier_post_id, 'thumbnail');
            if ($thumb) $logo_url = $thumb;
        }
    }

    if ($company === '') {
        $u = get_user_by('id', $author_id);
        $company = $u ? ($u->display_name ?: $u->user_login) : '';
    }
    if ($logo_url === '') {
        $logo_url = get_avatar_url($author_id, ['size'=>96]);
    }

    return ['company'=>$company, 'logo'=>$logo_url];
}

add_shortcode('bm_supplier_author_name', function(){
    $author_id = get_the_author_meta('ID');
    if (!$author_id) return '';
    $d = bm_sa_get_supplier_data((int)$author_id);
    return esc_html($d['company']);
});

add_shortcode('bm_supplier_author_logo', function($atts){
    $atts = shortcode_atts(['size'=>64], $atts, 'bm_supplier_author_logo');
    $author_id = get_the_author_meta('ID');
    if (!$author_id) return '';
    $d = bm_sa_get_supplier_data((int)$author_id);
    $size = max(16, (int)$atts['size']);

    // Preferuj logo z danych firmy (ACF: logo_dostawca). Fallback: avatar WP.
    $url = isset($d['logo']) ? trim((string)$d['logo']) : '';
    if ($url === '') {
        return get_avatar($author_id, $size);
    }

    return '<img class="bm-supplier-logo" src="' . esc_url($url) . '" width="' . $size . '" height="' . $size . '" alt="">';
});

add_shortcode('bm_supplier_author_box', function(){
    $author_id = get_the_author_meta('ID');
    if (!$author_id) return '';
    $d = bm_sa_get_supplier_data((int)$author_id);
    $url = bm_sa_author_archive_url((int)$author_id);

    return '<div class="bm-supplier-author-box">'
        . '<a href="' . esc_url($url) . '" class="bm-supplier-author-link">'
        . '<img class="bm-supplier-logo" src="' . esc_url($d['logo']) . '" width="44" height="44" alt="">'
        . '<span class="bm-supplier-name">' . esc_html($d['company']) . '</span>'
        . '</a></div>';
});


// --- Supplier Author: profile URL + header box ---

if (!function_exists('bm_get_supplier_post_id_by_user')) {
    // Jeśli masz już tę funkcję w pliku, usuń ten blok.
    function bm_get_supplier_post_id_by_user($user_id) {
        $ids = get_posts([
            'post_type'   => 'dostawca',
            'author'      => (int) $user_id,
            'numberposts' => 1,
            'post_status' => ['publish','pending','draft','private'],
            'fields'      => 'ids'
        ]);
        return !empty($ids) ? (int) $ids[0] : 0;
    }
}

/**
 * Zwraca ID usera autora bieżącego wpisu (działa w loopie i na single).
 */
function bm_get_current_post_author_id() {
    $post_id = get_the_ID();
    if (!$post_id) return 0;
    return (int) get_post_field('post_author', $post_id);
}

/**
 * Shortcode: [bm_supplier_author_name]
 * Nazwa firmy = tytuł posta CPT "dostawca" autora wpisu.
 */
add_shortcode('bm_supplier_author_name', function () {
    $author_id = bm_get_current_post_author_id();
    if (!$author_id) return '';

    $supplier_post_id = bm_get_supplier_post_id_by_user($author_id);
    if ($supplier_post_id) {
        return esc_html(get_the_title($supplier_post_id));
    }

    $u = get_user_by('id', $author_id);
    return $u ? esc_html($u->display_name) : '';
});

/**
 * Shortcode: [bm_supplier_author_profile_url]
 * URL do wizytówki dostawcy (permalink CPT "dostawca").
 */
add_shortcode('bm_supplier_author_profile_url', function () {
    $author_id = bm_get_current_post_author_id();
    if (!$author_id) return '';

    $supplier_post_id = bm_get_supplier_post_id_by_user($author_id);
    if (!$supplier_post_id) return '';

    $url = get_permalink($supplier_post_id);
    return $url ? esc_url($url) : '';
});

/**
 * Shortcode: [bm_supplier_author_profile_link label="Zobacz wizytówkę"]
 */
add_shortcode('bm_supplier_author_profile_link', function ($atts) {
    $atts = shortcode_atts([
        'label' => 'Zobacz wizytówkę',
        'class' => 'bm-supplier-author-link'
    ], $atts, 'bm_supplier_author_profile_link');

    $url = do_shortcode('[bm_supplier_author_profile_url]');
    if (!$url) return '';

    return '<a class="'.esc_attr($atts['class']).'" href="'.esc_url($url).'">'.esc_html($atts['label']).'</a>';
});

/**
 * Shortcode: [bm_supplier_author_header logo_size="56" show_rating="1"]
 * Gotowy blok: logo + firma + link + [bm_rating_summary]
 */
add_shortcode('bm_supplier_author_header', function ($atts) {

    $atts = shortcode_atts([
        'logo_size'   => 56,
        'show_rating' => 1,
        'link_label'  => 'Zobacz wizytówkę'
    ], $atts, 'bm_supplier_author_header');

    $logo_size = max(24, (int) $atts['logo_size']);

    $logo = do_shortcode('[bm_supplier_author_logo size="'.$logo_size.'"]');
    $name = do_shortcode('[bm_supplier_author_name]');
    $link = do_shortcode('[bm_supplier_author_profile_link label="'.esc_attr($atts['link_label']).'"]');

    $rating = '';
    if ((int)$atts['show_rating'] === 1) {
        $rating = do_shortcode('[bm_rating_summary]');
    }

    if (!$name) return '';

    return '
    <div class="bm-supplier-author-header">
        <div class="bm-supplier-author-header__left">
            <div class="bm-supplier-author-header__logo">'.$logo.'</div>
        </div>
        <div class="bm-supplier-author-header__right">
            <div class="bm-supplier-author-header__name">'.$name.'</div>
            <div class="bm-supplier-author-header__meta">'.$link.'</div>
            '.($rating ? '<div class="bm-supplier-author-header__rating">'.$rating.'</div>' : '').'
        </div>
    </div>';
});

