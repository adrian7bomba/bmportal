<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ==========================================================
   PORTAL DOSTAWCÓW – WERSJA ACF
   ========================================================== */

/* ENDPOINTY W MOJE KONTO (WooCommerce) */
add_action('init', function(){
    add_rewrite_endpoint('supplier-data',   EP_ROOT|EP_PAGES); // Dane firmy
    add_rewrite_endpoint('supplier-offers', EP_ROOT|EP_PAGES); // Oferty
});

add_filter('woocommerce_account_menu_items', function($items){
    $u = wp_get_current_user();
    if ($u && in_array('dostawca',(array)$u->roles)) {
        $new = [
            'supplier-data'   => 'Dane Wykonawcy',
            'supplier-offers' => 'Oferty',
        ];
        $items = $new + $items;
    }
    return $items;
});

/* ==========================================================
   ZAKŁADKA "DANE WYKONAWCY" – formularz (KROK 2)
   - Rejestracja zostaje prosta (Woo: e-mail + typ konta)
   - Tutaj uzupełniamy dane firmy/profil
   - Zapis jak dotychczas: do CPT "dostawca" (+ ACF jeśli dostępne)
   ========================================================== */

/**
 * Pobierz bezpiecznie wartość z POST
 */
function bm_post($key, $default = ''){
    return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
}

/**
 * Ustaw wartość w meta + (jeśli ACF) w polu o tej samej nazwie
 */
function bm_set_supplier_field($post_id, $field_name, $value){
    update_post_meta($post_id, $field_name, $value);
    if (function_exists('update_field')){
        // update_field przyjmuje nazwę pola (field name) lub key
        @update_field($field_name, $value, $post_id);
    }
}

/**
 * Pobierz wartość z ACF (jeśli istnieje), inaczej z meta
 */
function bm_get_supplier_field($post_id, $field_name, $default = ''){
    if (function_exists('get_field')){
        $v = get_field($field_name, $post_id);
        if ($v !== null && $v !== false && $v !== '') return $v;
    }
    $m = get_post_meta($post_id, $field_name, true);
    return ($m !== '') ? $m : $default;
}

/**
 * Upload obrazka do Media Library i zwróć attachment_id
 */
function bm_handle_image_upload($file_key, $max_bytes = 262144){
    if (empty($_FILES[$file_key]) || empty($_FILES[$file_key]['name'])) return 0;
    if (!function_exists('wp_handle_upload')) require_once ABSPATH.'wp-admin/includes/file.php';
    if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH.'wp-admin/includes/image.php';

    $f = $_FILES[$file_key];
    if (!empty($f['size']) && (int)$f['size'] > (int)$max_bytes){
        return new WP_Error('bm_file_too_big', 'Plik jest za duży. Maksymalny rozmiar: 256KB.');
    }

    $allowed = ['image/jpeg','image/png','image/webp'];
    if (!empty($f['type']) && !in_array($f['type'], $allowed, true)){
        return new WP_Error('bm_bad_type', 'Dozwolone formaty: JPG, PNG, WEBP.');
    }

    $upload = wp_handle_upload($f, ['test_form' => false]);
    if (!empty($upload['error'])){
        return new WP_Error('bm_upload_error', $upload['error']);
    }
    $file_path = $upload['file'];
    $file_url  = $upload['url'];
    $file_type = wp_check_filetype(basename($file_path), null);

    $attachment_id = wp_insert_attachment([
        'post_mime_type' => $file_type['type'],
        'post_title'     => sanitize_file_name(basename($file_path)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ], $file_path);

    if (is_wp_error($attachment_id) || !$attachment_id){
        return new WP_Error('bm_attach_error', 'Nie udało się zapisać pliku w mediach.');
    }

    $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
    wp_update_attachment_metadata($attachment_id, $attach_data);

    return (int)$attachment_id;
}

/**
 * Czy użytkownik ma aktywne Premium (hook pod dalszą integrację Woo)
 */
function bm_user_has_premium($user_id){
    $has_premium = false;
    $user = get_userdata((int) $user_id);

    if ($user && in_array('premium', (array) $user->roles, true)){
        $has_premium = true;
    }

    // Premium po zakupie produktu WooCommerce (ID: 1268)
    if ( ! $has_premium && function_exists('wc_get_orders') ) {
        $orders = wc_get_orders([
            'customer_id' => (int) $user_id,
            'status'      => ['wc-processing', 'wc-completed'],
            'limit'       => -1,
            'return'      => 'objects',
        ]);

        foreach ((array) $orders as $order){
            foreach ($order->get_items() as $item){
                if ((int) $item->get_product_id() === 1268){
                    $has_premium = true;
                    break 2;
                }
            }
        }
    }

    return (bool) apply_filters('bm_user_has_premium', $has_premium, (int) $user_id);
}

function bm_get_account_plan_data($user_id){
    $is_premium = bm_user_has_premium((int) $user_id);

    if ($is_premium){
        return [
            'is_premium' => true,
            'label'      => 'Premium',
            'message'    => 'Korzystasz z konta Premium. Poznaj swoje przewagi,',
            'link_text'  => 'zobacz',
            'link_url'   => 'https://brandmanager.cfolks.pl/premium/',
        ];
    }

    return [
        'is_premium' => false,
        'label'      => 'Standard',
        'message'    => 'Korzystasz z konta Standard. Dowiedz się co oferuje Premium i',
        'link_text'  => 'ulepsz',
        'link_url'   => 'https://brandmanager.cfolks.pl/premium/',
    ];
}

add_action('woocommerce_account_content', function(){
    if (!is_user_logged_in()) return;
    $user = wp_get_current_user();
    if (!$user || !in_array('dostawca', (array) $user->roles, true)) return;

    $plan = bm_get_account_plan_data($user->ID);
    echo '<div class="bm-account-plan-note">'
       . esc_html($plan['message'])
       . ' <a href="'.esc_url($plan['link_url']).'" class="bm-premium-link" target="_blank" rel="noopener">'
       . esc_html($plan['link_text'])
       . '</a> -></div>';
}, 1);

/**
 * Render drzewka taksonomii z checkboxami (hierarchicznie)
 */
function bm_render_tax_tree($taxonomy, $name, $selected_ids = [], $max = 3){
    $selected_ids = array_map('intval', (array)$selected_ids);

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'parent'     => 0,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms) || empty($terms)){
        echo '<p class="bm-help">Brak kategorii do wyboru.</p>';
        return;
    }

    echo '<div class="bm-tax-tree" data-max="'.esc_attr((int)$max).'" data-name="'.esc_attr($name).'">';
    echo '<ul class="bm-tax-tree__list">';

    $walk = function($term) use (&$walk, $taxonomy, $name, $selected_ids){
        $children = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'parent'     => (int)$term->term_id,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        $is_checked = in_array((int)$term->term_id, $selected_ids, true);
        $is_parent_only = !is_wp_error($children) && !empty($children);
        echo '<li class="bm-tax-tree__item">';
        echo '<label class="bm-tax-tree__label">';
        if ($is_parent_only){
            echo '<input class="bm-tax-tree__checkbox" type="checkbox" disabled aria-disabled="true"> ';
            echo '<span class="bm-tax-tree__parent">'.esc_html($term->name).'</span>';
        } else {
            echo '<input class="bm-tax-tree__checkbox" type="checkbox" name="'.esc_attr($name).'[]" value="'.esc_attr($term->term_id).'" '.checked($is_checked,true,false).'> ';
            echo '<span>'.esc_html($term->name).'</span>';
        }
        echo '</label>';
        if (!is_wp_error($children) && !empty($children)){
            echo '<ul class="bm-tax-tree__children">';
            foreach($children as $ch){ $walk($ch); }
            echo '</ul>';
        }
        echo '</li>';
    };

    foreach($terms as $t){ $walk($t); }
    echo '</ul>';
    echo '<div class="bm-tax-tree__note">Maksymalnie: <strong>'.esc_html((int)$max).'</strong>.</div>';
    echo '</div>';
}
add_action('woocommerce_account_supplier-data_endpoint', function(){

    if (!is_user_logged_in()){ echo '<p>Musisz być zalogowany.</p>'; return; }

    $user = wp_get_current_user();
    if (!in_array('dostawca',(array)$user->roles)){
        echo '<p>Ta sekcja jest tylko dla <strong>Wykonawców</strong>.</p>'; 
        return;
    }

    $post_id = bm_get_or_create_supplier_post($user->ID);
    $status  = get_post_status($post_id);

    $errors = [];
    $success = '';

    // SZYBKA AKTUALIZACJA: tylko flaga CITO (bez wysyłki do akceptacji)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bm_supplier_cito_only'])){
        if (!isset($_POST['_bm_cito_nonce']) || !wp_verify_nonce($_POST['_bm_cito_nonce'], 'bm_supplier_cito_only')){
            $errors[] = 'Błąd zabezpieczeń formularza CITO. Odśwież stronę i spróbuj ponownie.';
        } else {
            $cito_now = bm_post('bm_cito_now') ? 1 : 0;
            bm_set_supplier_field($post_id, 'bm_cito_now', $cito_now);
            $success = $cito_now
                ? 'Status „Realizujemy na CITO” został włączony.'
                : 'Status „Realizujemy na CITO” został wyłączony.';
        }
    }

    // ZAPIS FORMULARZA (KROK 2)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bm_supplier_step2']) ){
        if (!isset($_POST['_bm_nonce']) || !wp_verify_nonce($_POST['_bm_nonce'], 'bm_supplier_step2')){
            $errors[] = 'Błąd zabezpieczeń formularza. Odśwież stronę i spróbuj ponownie.';
        } else {
            // Wymagane dane firmy
            $company_name = sanitize_text_field(bm_post('bm_company_name'));
            $street       = sanitize_text_field(bm_post('bm_company_street'));
            $postcode     = sanitize_text_field(bm_post('bm_company_postcode'));
            $city         = sanitize_text_field(bm_post('bm_company_city'));
            $nip_raw      = preg_replace('/\D+/', '', (string)bm_post('bm_company_nip'));

            if ($company_name === '') $errors[] = 'Podaj nazwę firmy.';
            if ($street === '') $errors[] = 'Podaj ulicę i numer.';
            if ($postcode === '') $errors[] = 'Podaj kod pocztowy.';
            if ($city === '') $errors[] = 'Podaj miejscowość.';
            if ($nip_raw === '' || strlen($nip_raw) !== 10) $errors[] = 'Podaj poprawny NIP (10 cyfr).';
            if (!bm_post('bm_confirm_company_data')) $errors[] = 'Potwierdź poprawność danych firmy przed wysłaniem.';

            // Profil
            $www          = esc_url_raw(bm_post('bm_company_www'));
            $slogan       = sanitize_text_field(bm_post('bm_company_slogan'));
            $desc         = wp_kses_post(bm_post('bm_company_description'));
            $size         = sanitize_text_field(bm_post('bm_company_size'));
            $op_name      = sanitize_text_field(bm_post('bm_contact_name'));
            $op_role      = sanitize_text_field(bm_post('bm_contact_role'));
            $op_email     = sanitize_email(bm_post('bm_contact_email'));
            $op_phone     = sanitize_text_field(bm_post('bm_contact_phone'));

            if (mb_strlen($slogan) > 45){
                $errors[] = 'Slogan firmy może mieć maksymalnie 45 znaków.';
                $slogan = mb_substr($slogan, 0, 45);
            }

            $awarded      = bm_post('bm_awarded') ? 1 : 0;
            $awarded_text = sanitize_textarea_field(bm_post('bm_awarded_text'));

            $max_taxonomy_items = bm_user_has_premium($user->ID) ? 6 : 3;

            $spec_ids = array_map('intval', (array)bm_post('bm_specializations', []));
            $spec_ids = array_values(array_filter($spec_ids));
            if (count($spec_ids) > $max_taxonomy_items){
                $errors[] = sprintf('Możesz wybrać maksymalnie %d specjalizacje.', $max_taxonomy_items);
                $spec_ids = array_slice($spec_ids, 0, $max_taxonomy_items);
            }

            $ind_ids = array_map('intval', (array)bm_post('bm_industries', []));
            $ind_ids = array_values(array_filter($ind_ids));
            if (count($ind_ids) > $max_taxonomy_items){
                $errors[] = sprintf('Możesz wybrać maksymalnie %d branż doświadczenia.', $max_taxonomy_items);
                $ind_ids = array_slice($ind_ids, 0, $max_taxonomy_items);
            }

            $location_ids = array_map('intval', (array) bm_post('bm_locations', []));
            $location_ids = array_values(array_filter($location_ids));
            if (count($location_ids) > 3){
                $errors[] = 'Możesz wybrać maksymalnie 3 lokalizacje.';
                $location_ids = array_slice($location_ids, 0, 3);
            }

            $term_id = (int) bm_post('bm_term', 0);
            $budget_id = (int) bm_post('bm_budget', 0);

            // Uploady (logo + zdjęcie firmy)
            $logo_upload = bm_handle_image_upload('bm_company_logo');
            if (is_wp_error($logo_upload)) $errors[] = $logo_upload->get_error_message();

            $photo_upload = bm_handle_image_upload('bm_company_photo');
            if (is_wp_error($photo_upload)) $errors[] = $photo_upload->get_error_message();

            if (empty($errors)){
                // zapis pól (ACF/meta)
                bm_set_supplier_field($post_id, 'nazwa_dostawca', $company_name);
                bm_set_supplier_field($post_id, 'ulica_dostawca', $street);
                bm_set_supplier_field($post_id, 'kod_pocztowy_dostawca', $postcode);
                bm_set_supplier_field($post_id, 'miejscowosc_dostawca', $city);
                bm_set_supplier_field($post_id, 'nip_dostawca', $nip_raw);
                bm_set_supplier_field($post_id, 'regon_dostawca', sanitize_text_field(bm_post('bm_company_regon')));
                bm_set_supplier_field($post_id, 'krs_dostawca', sanitize_text_field(bm_post('bm_company_krs')));
                bm_set_supplier_field($post_id, 'vat_status_dostawca', sanitize_text_field(bm_post('bm_company_vat_status')));
                bm_set_supplier_field($post_id, 'forma_prawna_dostawca', sanitize_text_field(bm_post('bm_company_legal_form')));

                bm_set_supplier_field($post_id, 'www_dostawca', $www);
                bm_set_supplier_field($post_id, 'slogan_dostawca', $slogan);
                bm_set_supplier_field($post_id, 'opis_dostawca', $desc);
                bm_set_supplier_field($post_id, 'wielkosc_firmy_dostawca', $size);

                bm_set_supplier_field($post_id, 'opiekun_imie_nazwisko', $op_name);
                bm_set_supplier_field($post_id, 'opiekun_stanowisko', $op_role);
                bm_set_supplier_field($post_id, 'opiekun_email', $op_email);
                bm_set_supplier_field($post_id, 'opiekun_telefon', $op_phone);

                bm_set_supplier_field($post_id, 'nagradzani_konkursy', $awarded);
                bm_set_supplier_field($post_id, 'nagradzani_opis', $awarded_text);

                // termy (specjalizacje) – zapisujemy meta + (opcjonalnie) przypisujemy do posta
                bm_set_supplier_field($post_id, 'bm_specializations', $spec_ids);
                wp_set_object_terms($post_id, $spec_ids, 'dostawca_kategoria', false);

                // branże – meta + termy
                bm_set_supplier_field($post_id, 'bm_industries', $ind_ids);
                wp_set_object_terms($post_id, $ind_ids, 'dostawca_branza', false);

                // lokalizacje/termin/budżet
                bm_set_supplier_field($post_id, 'bm_locations', $location_ids);
                wp_set_object_terms($post_id, $location_ids, 'dostawca_lokalizacja', false);

                bm_set_supplier_field($post_id, 'bm_term', $term_id);
                wp_set_object_terms($post_id, $term_id ? [$term_id] : [], 'dostawca_termin', false);

                bm_set_supplier_field($post_id, 'bm_budget', $budget_id);
                wp_set_object_terms($post_id, $budget_id ? [$budget_id] : [], 'dostawca_budzet', false);

                if (is_int($logo_upload) && $logo_upload > 0){
                    bm_set_supplier_field($post_id, 'logo_dostawca', $logo_upload);
                }
                if (is_int($photo_upload) && $photo_upload > 0){
                    bm_set_supplier_field($post_id, 'zdjecie_firmy', $photo_upload);
                }

                // synchronizacja tytułu
                wp_update_post([
                    'ID'         => $post_id,
                    'post_title' => $company_name,
                    'post_name'  => sanitize_title($company_name),
                ]);

                // czytelna nazwa użytkownika (zamiast samego prefiksu z e-maila)
                wp_update_user([
                    'ID'           => $user->ID,
                    'display_name' => $company_name,
                    'nickname'     => $company_name,
                ]);

                // status pending (tylko z frontu)
                if (!current_user_can('manage_options')){
                    wp_update_post(['ID'=>$post_id,'post_status'=>'pending']);
                }

                // mail do superadminów
                $emails = bm_get_superadmin_emails();
                $user_panel_link = admin_url('user-edit.php?user_id='.$user->ID);
                $supplier_post_link = get_edit_post_link($post_id);
                $specializations_names = wp_get_post_terms($post_id, 'dostawca_kategoria', ['fields' => 'names']);
                $industries_names = wp_get_post_terms($post_id, 'dostawca_branza', ['fields' => 'names']);
                $locations_names = wp_get_post_terms($post_id, 'dostawca_lokalizacja', ['fields' => 'names']);
                $terms_names = wp_get_post_terms($post_id, 'dostawca_termin', ['fields' => 'names']);
                $budgets_names = wp_get_post_terms($post_id, 'dostawca_budzet', ['fields' => 'names']);

                $html = '<div style="font-family:Arial,sans-serif;line-height:1.5">'
                      . '<div style="border:1px solid #e5e5e5;border-radius:10px;padding:16px;max-width:700px">'
                      . '<h2 style="margin:0 0 10px">Wykonawca – dane do akceptacji</h2>'
                      . '<p>Wykonawca <strong>'.esc_html($user->user_email).'</strong> zaktualizował dane firmy.</p>'
                      . '<p><strong>Nazwa firmy:</strong> '.esc_html($company_name).'<br>'
                      . '<strong>NIP:</strong> '.esc_html($nip_raw).'<br>'
                      . '<strong>Adres:</strong> '.esc_html($street.', '.$postcode.' '.$city).'<br>'
                      . '<strong>Status VAT:</strong> '.esc_html(sanitize_text_field(bm_post('bm_company_vat_status'))).'</p>'
                      . '<p><strong>Realizujemy na CITO:</strong> '.esc_html((int) bm_get_supplier_field($post_id, 'bm_cito_now', 0) === 1 ? 'Tak' : 'Nie').'</p>'
                      . '<p><strong>Opis firmy:</strong><br>'.wp_kses_post(wpautop($desc)).'</p>'
                      . '<p><strong>Branże:</strong> '.esc_html(!empty($industries_names) ? implode(', ', $industries_names) : '—').'<br>'
                      . '<strong>Specjalizacje:</strong> '.esc_html(!empty($specializations_names) ? implode(', ', $specializations_names) : '—').'<br>'
                      . '<strong>Lokalizacje:</strong> '.esc_html(!empty($locations_names) ? implode(', ', $locations_names) : '—').'<br>'
                      . '<strong>Termin realizacji:</strong> '.esc_html(!empty($terms_names) ? implode(', ', $terms_names) : '—').'<br>'
                      . '<strong>Budżet:</strong> '.esc_html(!empty($budgets_names) ? implode(', ', $budgets_names) : '—').'<br>'
                      . '<strong>Opiekun klienta:</strong> '.esc_html(trim($op_name.' / '.$op_role.' / '.$op_email.' / '.$op_phone, ' /')).'</p>'
                      . '<p><strong>Profil użytkownika:</strong> '.esc_html($user_panel_link).'</p>'
                      . ($supplier_post_link ? '<p><strong>Link do edycji wizytówki:</strong> '.esc_html($supplier_post_link).'</p>' : '')
                      . '<hr style="border:none;border-top:1px solid #eee;margin:16px 0">'
                      . '<p style="color:#666;font-size:12px;margin:0">BrandManager – powiadomienie systemowe.</p>'
                      . '</div></div>';

                if (!empty($emails)){
                    bm_send_html_mail($emails, 'Wykonawca: dane do akceptacji', $html, 'Wykonawca '.$user->user_email.' zaktualizował dane.');
                }

                $success = 'Dane zapisane. Twoja wizytówka oczekuje na akceptację SUPERADMINA.';
                // odśwież status
                $status = get_post_status($post_id);
            }
        }
    }

    // Komunikat o statusie
    echo '<div class="bm-box bm-box--account">';
    echo '<h2 class="bm-account-title">Dane Wykonawcy</h2>';

    if ($status === 'draft'){
        echo '<p>Status: <strong>Robocze</strong> – uzupełnij dane i wyślij do akceptacji.</p>';
    } elseif ($status === 'pending'){
        echo '<p>Status: <strong>Oczekujące</strong> – SUPERADMIN weryfikuje Twoje dane.</p>';
    } elseif ($status === 'publish'){
        echo '<p>Status: <strong>Zaakceptowane</strong> – Twoja wizytówka jest widoczna publicznie.</p>';
        $permalink = get_permalink($post_id);
        if ($permalink){
            echo '<p>Adres wizytówki: <a href="'.esc_url($permalink).'" target="_blank">'.esc_html($permalink).'</a></p>';
        }
    } elseif ($status === 'rejected'){
        echo '<p>Status: <strong>Do poprawy</strong> – popraw dane i wyślij ponownie do akceptacji.</p>';
        $reject_reason = get_user_meta($user->ID, 'bm_supplier_reject_reason', true);
        if ($reject_reason){
            echo '<div class="bm-alert bm-alert--error bm-reject-reason"><strong>Powód odrzucenia:</strong><br>'.wp_kses_post(nl2br(esc_html($reject_reason))).'</div>';
        }
    }
    if (!empty($errors)){
        echo '<div class="bm-alert bm-alert--error"><ul>'; foreach($errors as $e){ echo '<li>'.esc_html($e).'</li>'; } echo '</ul></div>';
    }
    if ($success){
        echo '<div class="bm-alert bm-alert--success">'.esc_html($success).'</div>';
    }
    echo '</div>';

    // wartości do formularza
    $is_premium = bm_user_has_premium($user->ID);
    $max_taxonomy_items = $is_premium ? 6 : 3;
    $upgrade_link = '<a href="https://brandmanager.cfolks.pl/premium/" class="bm-premium-link" target="_blank" rel="noopener">Ulepsz</a> ->';

    $premium_desc_note = $is_premium
        ? 'Korzystasz z Premium, możesz dodać 1500 znaków opisu. W koncie Standard to tylko 200 znaków.'
        : 'W wersji darmowej limit opisu to 200 znaków. W pakiecie Premium: 1500 znaków. '.$upgrade_link;

    $premium_spec_note = $is_premium
        ? 'Korzystasz z Premium, możesz dodać aż o 3 specjalizacje więcej niż konto standardowe.'
        : 'W Premium możesz wybrać do 6 specjalizacji. '.$upgrade_link;

    $premium_ind_note = $is_premium
        ? 'Korzystasz z Premium, możesz dodać aż o 3 branże więcej niż konto standardowe.'
        : 'W Premium możesz wybrać do 6 branż. '.$upgrade_link;

    $v_company_name = bm_get_supplier_field($post_id,'nazwa_dostawca','');
    $v_street       = bm_get_supplier_field($post_id,'ulica_dostawca','');
    $v_postcode     = bm_get_supplier_field($post_id,'kod_pocztowy_dostawca','');
    $v_city         = bm_get_supplier_field($post_id,'miejscowosc_dostawca','');
    $v_nip          = bm_get_supplier_field($post_id,'nip_dostawca','');
    $v_www          = bm_get_supplier_field($post_id,'www_dostawca','');
    $v_slogan       = bm_get_supplier_field($post_id,'slogan_dostawca','');
    $v_desc         = bm_get_supplier_field($post_id,'opis_dostawca','');
    $v_size         = bm_get_supplier_field($post_id,'wielkosc_firmy_dostawca','');
    $v_op_name      = bm_get_supplier_field($post_id,'opiekun_imie_nazwisko','');
    $v_op_role      = bm_get_supplier_field($post_id,'opiekun_stanowisko','');
    $v_op_email     = bm_get_supplier_field($post_id,'opiekun_email','');
    $v_op_phone     = bm_get_supplier_field($post_id,'opiekun_telefon','');
    $v_awarded      = (int) bm_get_supplier_field($post_id,'nagradzani_konkursy',0);
    $v_awarded_text = bm_get_supplier_field($post_id,'nagradzani_opis','');
    $v_spec         = bm_get_supplier_field($post_id,'bm_specializations',[]);
    $v_ind          = bm_get_supplier_field($post_id,'bm_industries',[]);
    $v_locations    = bm_get_supplier_field($post_id,'bm_locations',[]);
    $v_term         = (int) bm_get_supplier_field($post_id,'bm_term',0);
    $v_budget       = (int) bm_get_supplier_field($post_id,'bm_budget',0);
    $v_regon        = bm_get_supplier_field($post_id,'regon_dostawca','');
    $v_krs          = bm_get_supplier_field($post_id,'krs_dostawca','');
    $v_vat_status   = bm_get_supplier_field($post_id,'vat_status_dostawca','');
    $v_legal_form   = bm_get_supplier_field($post_id,'forma_prawna_dostawca','');
    $v_cito_now     = (int) bm_get_supplier_field($post_id,'bm_cito_now',0);

    echo '<form class="bm-form bm-form--supplier-cito" method="post">';
    echo '<input type="hidden" name="bm_supplier_cito_only" value="1">';
    wp_nonce_field('bm_supplier_cito_only','_bm_cito_nonce');
    echo '<div class="bm-field bm-field--cito">';
    echo '<label><input type="checkbox" name="bm_cito_now" value="1" '.checked($v_cito_now,1,false).'> <strong>Realizujemy na CITO</strong></label>';
    echo '<div class="bm-help">Zaznaczając ten box jesteś widoczny w wynikach „na już”. Tę opcję możesz włączać/wyłączać niezależnie – bez wysyłki danych do ponownej akceptacji.</div>';
    echo '</div>';
    echo '<div class="bm-form__actions"><button type="submit" class="button bm-btn-submit">Zapisz status CITO</button></div>';
    echo '</form>';

    echo '<form class="bm-form bm-form--supplier" method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="bm_supplier_step2" value="1">';
    wp_nonce_field('bm_supplier_step2','_bm_nonce');

    echo '<div class="bm-form__section">';
    echo '<h3 class="bm-form__h">Dane firmy</h3>';

    echo '<div class="bm-grid bm-grid--2">';

    echo '<div class="bm-field bm-field--company-name"><label>Nazwa firmy <span class="bm-req">*</span></label><input type="text" name="bm_company_name" value="'.esc_attr($v_company_name).'" required></div>';
    echo '<div class="bm-field bm-field--nip"><label>NIP <span class="bm-req">*</span></label><div class="bm-nip-inline"><input type="text" name="bm_company_nip" value="'.esc_attr($v_nip).'" inputmode="numeric" required><button type="button" class="button bm-btn-nip-fetch" data-nonce="'.esc_attr(wp_create_nonce('bm_company_lookup_nonce')).'">Pobierz dane</button></div><div class="bm-help bm-help--nip-status"></div></div>';
    echo '<div class="bm-field bm-field--street"><label>Ulica i numer <span class="bm-req">*</span></label><input type="text" name="bm_company_street" value="'.esc_attr($v_street).'" required></div>';
    echo '<div class="bm-field bm-field--postcode"><label>Kod pocztowy <span class="bm-req">*</span></label><input type="text" name="bm_company_postcode" value="'.esc_attr($v_postcode).'" required></div>';
    echo '<div class="bm-field bm-field--city"><label>Miejscowość <span class="bm-req">*</span></label><input type="text" name="bm_company_city" value="'.esc_attr($v_city).'" required></div>';
    echo '<div class="bm-field bm-field--vat"><label>Status VAT</label><input type="text" name="bm_company_vat_status" value="'.esc_attr($v_vat_status).'" readonly></div>';
    echo '<div class="bm-field bm-field--regon"><label>REGON</label><input type="text" name="bm_company_regon" value="'.esc_attr($v_regon).'" readonly></div>';
    echo '<div class="bm-field bm-field--krs"><label>KRS</label><input type="text" name="bm_company_krs" value="'.esc_attr($v_krs).'" readonly></div>';
    echo '<div class="bm-field bm-field--legal"><label>Forma prawna</label><input type="text" name="bm_company_legal_form" value="'.esc_attr($v_legal_form).'" readonly></div>';

    echo '</div>'; // grid

    echo '<div class="bm-grid bm-grid--2">';
    echo '<div class="bm-field bm-field--logo"><label>Logo firmy</label><input type="file" name="bm_company_logo" accept="image/*" data-max-kb="256"><div class="bm-help">Maksymalny rozmiar pliku: 256KB. Format: JPG/PNG/WEBP.</div><div class="bm-help bm-help--file-error" aria-live="polite"></div></div>';
    echo '<div class="bm-field bm-field--photo"><label>Zdjęcie firmy</label><input type="file" name="bm_company_photo" accept="image/*" data-max-kb="256"><div class="bm-help">Najlepiej wykadruj zdjęcie tak, aby główna treść była na środku. Maksymalny rozmiar: 256KB. Twój obrazek będzie wykadrowany do koła.</div><div class="bm-photo-preview"><span>Podgląd zdjęcia w kole</span></div><div class="bm-help bm-help--file-error" aria-live="polite"></div></div>';
    echo '</div>';

    echo '</div>'; // section

    echo '<div class="bm-form__section">';
    echo '<h3 class="bm-form__h">Profil Wykonawcy</h3>';

    echo '<div class="bm-grid bm-grid--2">';
    echo '<div class="bm-field bm-field--www"><label>Strona WWW</label><input type="url" name="bm_company_www" value="'.esc_attr($v_www).'" placeholder="https://..."></div>';
    echo '<div class="bm-field bm-field--slogan"><label>Slogan firmy (max 45 znaków)</label><input type="text" name="bm_company_slogan" value="'.esc_attr($v_slogan).'" maxlength="45"></div>';
    echo '<div class="bm-field bm-field--size"><label>Wielkość firmy</label>';
    echo '<select name="bm_company_size">';
    $sizes = ['' => '— wybierz —','do 5 pracowników'=>'do 5 pracowników','5-10 pracowników'=>'5-10 pracowników','10-20 pracowników'=>'10-20 pracowników','ponad 20 pracowników'=>'ponad 20 pracowników'];
    foreach($sizes as $k=>$lbl){ echo '<option value="'.esc_attr($k).'" '.selected($v_size,$k,false).'>'.esc_html($lbl).'</option>'; }
    echo '</select></div>';
    echo '</div>';

    echo '<div class="bm-field bm-field--desc">';
    echo '<label>Opis firmy</label>';
    echo '<div class="bm-premium-note bm-premium-note--green">'.wp_kses_post($premium_desc_note).'</div>';
    wp_editor($v_desc, 'bm_company_description', [
        'textarea_name' => 'bm_company_description',
        'media_buttons' => false,
        'teeny'         => true,
        'textarea_rows' => 8,
    ]);
    echo '<div class="bm-help bm-help--tip">';
    echo '<strong>Co warto zawrzeć w opisie firmy?</strong><br>';
    echo '• Twarde dane: ile lat na rynku, wielkość zespołu.<br>';
    echo '• Specjalizacja: w czym jesteście najlepsi.<br>';
    echo '• Zaplecze: park maszynowy/studio/podwykonawcy.<br>';
    echo '• Skala: korporacje vs MŚP.<br>';
    echo '• Technologia: narzędzia (np. Jira, Asana, Adobe CC, SEMrush).';
    echo '</div>';
    echo '</div>';

    echo '<div class="bm-grid bm-grid--2">';
    echo '<div class="bm-field bm-field--contact-name"><label>Imię i nazwisko opiekuna klienta</label><input type="text" name="bm_contact_name" value="'.esc_attr($v_op_name).'"></div>';
    echo '<div class="bm-field bm-field--contact-role"><label>Stanowisko opiekuna klienta</label><input type="text" name="bm_contact_role" value="'.esc_attr($v_op_role).'"></div>';
    echo '<div class="bm-field bm-field--contact-email"><label>Adres e-mail</label><input type="email" name="bm_contact_email" value="'.esc_attr($v_op_email).'"></div>';
    echo '<div class="bm-field bm-field--contact-phone"><label>Telefon kontaktowy</label><input type="text" name="bm_contact_phone" value="'.esc_attr($v_op_phone).'"></div>';
    echo '</div>';

    echo '<div class="bm-grid bm-grid--2">';
    echo '<div class="bm-field bm-field--spec"><label>Specjalizacja (max '.(int)$max_taxonomy_items.')</label>';
    bm_render_tax_tree('dostawca_kategoria','bm_specializations',$v_spec,$max_taxonomy_items);
    echo '<div class="bm-premium-note">'.wp_kses_post($premium_spec_note).'</div>';
    echo '</div>';
    echo '<div class="bm-field bm-field--industries"><label>Doświadczenie w branżach (max '.(int)$max_taxonomy_items.')</label>';
    bm_render_tax_tree('dostawca_branza','bm_industries',$v_ind,$max_taxonomy_items);
    echo '<div class="bm-premium-note">'.wp_kses_post($premium_ind_note).'</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="bm-grid bm-grid--2">';
    echo '<div class="bm-field bm-field--locations"><label>Lokalizacje (max 3)</label>';
    bm_render_tax_tree('dostawca_lokalizacja','bm_locations',$v_locations,3);
    echo '</div>';

    echo '<div class="bm-field bm-field--term"><label>Terminy realizacji (jedno do wyboru)</label>';
    $term_options = get_terms(['taxonomy' => 'dostawca_termin', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    echo '<select name="bm_term">';
    echo '<option value="0">— wybierz —</option>';
    if (!is_wp_error($term_options)){
        foreach($term_options as $term_opt){
            echo '<option value="'.esc_attr((int)$term_opt->term_id).'" '.selected($v_term, (int)$term_opt->term_id, false).'>'.esc_html($term_opt->name).'</option>';
        }
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';

    echo '<div class="bm-grid bm-grid--2">';
    echo '<div class="bm-field bm-field--budget"><label>Budżety (jedno do wyboru)</label>';
    $budget_options = get_terms(['taxonomy' => 'dostawca_budzet', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    echo '<select name="bm_budget">';
    echo '<option value="0">— wybierz —</option>';
    if (!is_wp_error($budget_options)){
        foreach($budget_options as $budget_opt){
            echo '<option value="'.esc_attr((int)$budget_opt->term_id).'" '.selected($v_budget, (int)$budget_opt->term_id, false).'>'.esc_html($budget_opt->name).'</option>';
        }
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';

    echo '<div class="bm-field bm-field--awarded">';
    echo '<label><input type="checkbox" name="bm_awarded" value="1" '.checked($v_awarded,1,false).'> Nagradzani w konkursach</label>';
    echo '<div class="bm-field bm-field--awarded-text" style="margin-top:10px;'.($v_awarded? '':'display:none;').'">';
    echo '<label>Opisz nagrody</label>';
    wp_editor($v_awarded_text, 'bm_awarded_text_editor', [
        'textarea_name' => 'bm_awarded_text',
        'media_buttons' => false,
        'teeny'         => true,
        'textarea_rows' => 5,
    ]);
    echo '</div>';
    echo '</div>';

    $confirm_checked = (!empty($_POST['bm_confirm_company_data']) || $_SERVER['REQUEST_METHOD'] !== 'POST') ? 1 : 0;
    echo '<div class="bm-field bm-field--confirm">';
    echo '<label><input type="checkbox" name="bm_confirm_company_data" value="1" '.checked($confirm_checked,1,false).' required> Potwierdzam poprawność danych firmy i NIP.</label>';
    echo '</div>';

    echo '</div>'; // section

    echo '<div class="bm-form__actions">';
    echo '<button type="submit" class="button button-primary bm-btn-submit">Zapisz dane i wyślij do akceptacji</button>';
    echo '</div>';

    echo '</form>';

    // JS: blokada max zaznaczeń + toggle nagród
    echo '<script>(function(){
      function initTree(root){
        var max = parseInt(root.getAttribute("data-max"),10)||3;
        var cbs = root.querySelectorAll("input[type=checkbox]");

        cbs.forEach(function(cb){
          if(cb.disabled && !cb.name){
            cb.dataset.permanentDisabled = "1";
          }
        });

        function enforce(){
          var checked = root.querySelectorAll("input[type=checkbox]:checked");
          if(checked.length>=max){
            cbs.forEach(function(cb){
              if(cb.dataset.permanentDisabled === "1") return;
              if(!cb.checked){
                cb.disabled=true;
                cb.parentElement.classList.add("is-disabled");
              }
            });
          }else{
            cbs.forEach(function(cb){
              if(cb.dataset.permanentDisabled === "1") return;
              cb.disabled=false;
              cb.parentElement.classList.remove("is-disabled");
            });
          }
        }
        cbs.forEach(function(cb){
          if(cb.dataset.permanentDisabled === "1") return;
          cb.addEventListener("change", enforce);
        });
        enforce();
      }
      document.querySelectorAll(".bm-tax-tree").forEach(initTree);
      var aw = document.querySelector("input[name=bm_awarded]");
      var box = document.querySelector(".bm-field--awarded-text");
      if(aw && box){ aw.addEventListener("change", function(){ box.style.display = this.checked?"block":"none"; }); }

      var form = document.querySelector(".bm-form--supplier");
      var nipButton = document.querySelector(".bm-btn-nip-fetch");
      if(form && nipButton){
        var nipInput = form.querySelector("input[name=bm_company_nip]");
        var statusEl = form.querySelector(".bm-help--nip-status");
        var companyInput = form.querySelector("input[name=bm_company_name]");
        var streetInput = form.querySelector("input[name=bm_company_street]");
        var postcodeInput = form.querySelector("input[name=bm_company_postcode]");
        var cityInput = form.querySelector("input[name=bm_company_city]");
        var regonInput = form.querySelector("input[name=bm_company_regon]");
        var krsInput = form.querySelector("input[name=bm_company_krs]");
        var vatInput = form.querySelector("input[name=bm_company_vat_status]");
        var legalInput = form.querySelector("input[name=bm_company_legal_form]");
        var photoInput = form.querySelector("input[name=bm_company_photo]");
        var photoPreview = form.querySelector(".bm-photo-preview");
        var ajaxUrl = "' . esc_js(admin_url('admin-ajax.php')) . '";

        if(photoInput && photoPreview){
          photoInput.addEventListener("change", function(){
            var file = this.files && this.files[0] ? this.files[0] : null;
            if(!file) return;
            if(file.size > 262144){
              var err = this.parentElement.querySelector(".bm-help--file-error");
              if(err){ err.textContent = "Plik jest za duży. Maksymalny rozmiar: 256KB."; }
              this.value = "";
              photoPreview.classList.remove("is-filled");
              photoPreview.style.backgroundImage = "none";
              return;
            }
            var err = this.parentElement.querySelector(".bm-help--file-error");
            if(err){ err.textContent = ""; }
            var reader = new FileReader();
            reader.onload = function(e){
              photoPreview.style.backgroundImage = "url(" + e.target.result + ")";
              photoPreview.classList.add("is-filled");
            };
            reader.readAsDataURL(file);
          });
        }

        var logoInput = form.querySelector("input[name=bm_company_logo]");
        if(logoInput){
          logoInput.addEventListener("change", function(){
            var file = this.files && this.files[0] ? this.files[0] : null;
            if(!file) return;
            var err = this.parentElement.querySelector(".bm-help--file-error");
            if(file.size > 262144){
              if(err){ err.textContent = "Plik jest za duży. Maksymalny rozmiar: 256KB."; }
              this.value = "";
              return;
            }
            if(err){ err.textContent = ""; }
          });
        }

        nipButton.addEventListener("click", function(){
          var nip = (nipInput && nipInput.value ? nipInput.value : "").replace(/\D+/g, "");
          if(nip.length !== 10){
            if(statusEl){ statusEl.textContent = "Wpisz poprawny NIP (10 cyfr)."; }
            return;
          }

          if(statusEl){ statusEl.textContent = "Pobieram dane z rejestru..."; }
          nipButton.disabled = true;

          var fd = new FormData();
          fd.append("action", "bm_company_lookup");
          fd.append("nonce", nipButton.getAttribute("data-nonce") || "");
          fd.append("nip", nip);

          fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function(resp){ return resp.json(); })
            .then(function(json){
              if(!json || !json.success){
                var msg = (json && json.data && json.data.message) ? json.data.message : "Nie udało się pobrać danych. Uzupełnij ręcznie.";
                if(statusEl){ statusEl.textContent = msg; }
                return;
              }

              var data = (json.data && json.data.data) ? json.data.data : {};
              if(companyInput && data.company_name && !companyInput.value){ companyInput.value = data.company_name; }
              if(streetInput && data.street && !streetInput.value){ streetInput.value = data.street; }
              if(postcodeInput && data.postal_code && !postcodeInput.value){ postcodeInput.value = data.postal_code; }
              if(cityInput && data.city && !cityInput.value){ cityInput.value = data.city; }
              if(regonInput){ regonInput.value = data.regon || ""; }
              if(krsInput){ krsInput.value = data.krs || ""; }
              if(vatInput){ vatInput.value = data.status_vat || ""; }
              if(legalInput){ legalInput.value = data.legal_form || ""; }
              if(statusEl){ statusEl.textContent = "Dane pobrane. Sprawdź i potwierdź przed zapisaniem."; }
            })
            .catch(function(){
              if(statusEl){ statusEl.textContent = "Błąd połączenia. Uzupełnij dane ręcznie."; }
            })
            .finally(function(){
              nipButton.disabled = false;
            });
        });
      }
    })();</script>';
});


/* PANEL AKCEPTACJI DOSTAWCY W PROFILU UŻYTKOWNIKA */

add_action('edit_user_profile', function($user){

    if (!in_array('dostawca', (array)$user->roles, true)) return;

    $post_id = bm_get_or_create_supplier_post($user->ID);
    $status  = get_post_status($post_id) ?: 'draft';

    echo '<h2 style="margin:0;padding:12px 14px;background:#282a36;color:#fff;border-radius:8px;">Dane Wykonawcy – akceptacja</h2>';
    echo '<p style="margin:10px 0 14px;padding:10px 12px;border:1px solid #47c0c7;border-radius:8px;background:#f4fdfe;">Sekcja weryfikacji znajduje się na górze profilu. Poniżej widzisz status i komplet danych do decyzji.</p>';

    echo '<table class="form-table"><tbody>';

    // Status + select
    echo '<tr>';
    echo '<th><label for="bm_supplier_status">Status wizytówki</label></th>';
    echo '<td>';
    echo '<select name="bm_supplier_status" id="bm_supplier_status">';
    echo '<option value="draft" '.selected($status,'draft',false).'>Robocze</option>';
    echo '<option value="pending" '.selected($status,'pending',false).'>Oczekujące</option>';
    echo '<option value="publish" '.selected($status,'publish',false).'>Zaakceptowane</option>';
    echo '<option value="rejected" '.selected($status,'rejected',false).'>Odrzucone</option>';
    echo '</select>';
    $reject_reason_admin = get_user_meta($user->ID, 'bm_supplier_reject_reason', true);
    echo '<p style="margin-top:8px"><label for="bm_supplier_reject_reason"><strong>Powód odrzucenia (opcjonalnie)</strong></label><br><textarea name="bm_supplier_reject_reason" id="bm_supplier_reject_reason" rows="4" style="width:100%;max-width:640px">'.esc_textarea($reject_reason_admin).'</textarea></p>';

    $public_link = get_permalink($post_id);
    echo '<p class="description">';
    echo 'Aktualny status: <strong>'.esc_html($status).'</strong>. ';
    if ($public_link && $status === 'publish'){
        echo 'Publiczna wizytówka: <a href="'.esc_url($public_link).'" target="_blank">'.esc_html($public_link).'</a><br>';
    }
    echo '</p>';

    echo '</td>';
    echo '</tr>';

    echo '<tr><th>Dane do akceptacji</th><td>';
    echo '<div style="border:1px solid #EE2356;border-radius:10px;padding:12px;background:#fff7f9">';
    echo '<p><strong>Firma:</strong> '.esc_html((string) bm_get_supplier_field($post_id,'nazwa_dostawca','—')).'</p>';
    echo '<p><strong>Slogan:</strong> '.esc_html((string) bm_get_supplier_field($post_id,'slogan_dostawca','—')).'</p>';
    echo '<p><strong>Realizujemy na CITO:</strong> '.esc_html((int) bm_get_supplier_field($post_id,'bm_cito_now',0) === 1 ? 'Tak' : 'Nie').'</p>';
    echo '<p><strong>Adres:</strong> '.esc_html((string) bm_get_supplier_field($post_id,'ulica_dostawca','')).', '.esc_html((string) bm_get_supplier_field($post_id,'kod_pocztowy_dostawca','')).' '.esc_html((string) bm_get_supplier_field($post_id,'miejscowosc_dostawca','')).'</p>';
    echo '<p><strong>Opis firmy:</strong><br>'.wp_kses_post(wpautop((string) bm_get_supplier_field($post_id,'opis_dostawca',''))).'</p>';
    $admin_spec = wp_get_post_terms($post_id, 'dostawca_kategoria', ['fields' => 'names']);
    $admin_ind = wp_get_post_terms($post_id, 'dostawca_branza', ['fields' => 'names']);
    $admin_locations = wp_get_post_terms($post_id, 'dostawca_lokalizacja', ['fields' => 'names']);
    $admin_term = wp_get_post_terms($post_id, 'dostawca_termin', ['fields' => 'names']);
    $admin_budget = wp_get_post_terms($post_id, 'dostawca_budzet', ['fields' => 'names']);
    echo '<p><strong>Branże:</strong> '.esc_html(!empty($admin_ind) ? implode(', ', $admin_ind) : '—').'</p>';
    echo '<p><strong>Specjalizacje:</strong> '.esc_html(!empty($admin_spec) ? implode(', ', $admin_spec) : '—').'</p>';
    echo '<p><strong>Lokalizacje:</strong> '.esc_html(!empty($admin_locations) ? implode(', ', $admin_locations) : '—').'</p>';
    echo '<p><strong>Termin realizacji:</strong> '.esc_html(!empty($admin_term) ? implode(', ', $admin_term) : '—').'</p>';
    echo '<p><strong>Budżet:</strong> '.esc_html(!empty($admin_budget) ? implode(', ', $admin_budget) : '—').'</p>';
    echo '<p><strong>Opiekun:</strong> '.esc_html((string) bm_get_supplier_field($post_id,'opiekun_imie_nazwisko','—')).' / '.esc_html((string) bm_get_supplier_field($post_id,'opiekun_stanowisko','—')).' / '.esc_html((string) bm_get_supplier_field($post_id,'opiekun_email','—')).' / '.esc_html((string) bm_get_supplier_field($post_id,'opiekun_telefon','—')).'</p>';
    echo '</div>';
    echo '</td></tr>';

    // Podgląd: logo + zdjęcie firmy (jeśli istnieją)
    $logo_id  = 0;
    $photo_id = 0;
    if (function_exists('get_field')){
        $logo_id  = (int) get_field('logo_dostawca', $post_id);
        $photo_id = (int) get_field('zdjecie_firmy', $post_id);
    }
    if (!$logo_id){  $logo_id  = (int) get_post_meta($post_id,'logo_dostawca', true); }
    if (!$photo_id){ $photo_id = (int) get_post_meta($post_id,'zdjecie_firmy', true); }

    if ($logo_id || $photo_id){
        echo '<tr><th>Podgląd</th><td style="display:flex;gap:16px;align-items:flex-start;">';
        if ($logo_id){
            echo '<div><strong>Logo</strong><br>'.wp_get_attachment_image($logo_id, [96,96], false, ['style'=>'border:1px solid #ddd;border-radius:10px;padding:6px;background:#fff;max-width:96px;height:auto;']).'</div>';
        }
        if ($photo_id){
            echo '<div><strong>Zdjęcie firmy</strong><br>'.wp_get_attachment_image($photo_id, [160,120], false, ['style'=>'border:1px solid #ddd;border-radius:10px;padding:6px;background:#fff;max-width:160px;height:auto;']).'</div>';
        }
        echo '</td></tr>';
    }

    // ZMIANY WZGLĘDEM OSTATNIO ZAAKCEPTOWANYCH DANYCH
    if (function_exists('get_fields')){
        $current_fields = get_fields($post_id);
        if (!is_array($current_fields)) $current_fields = [];

        $prev_json = get_post_meta($post_id,'bm_last_accepted_snapshot',true);
        $prev_fields = $prev_json ? json_decode($prev_json, true) : [];

        echo '<tr><th>Zmiany w danych</th><td>';

        if (empty($prev_fields)){
            echo '<p><em>Brak wcześniej zaakceptowanych danych – to może być pierwsza akceptacja.</em></p>';
            if (!empty($current_fields)){
                echo '<p><strong>Aktualnie zapisane pola ACF:</strong></p><ul>';
                foreach($current_fields as $key => $val){
                    $label = $key;
                    if (function_exists('acf_get_field')){
                        $field_obj = acf_get_field($key);
                        if ($field_obj && !is_wp_error($field_obj) && !empty($field_obj['label'])){
                            $label = $field_obj['label'].' ('.$key.')';
                        }
                    }
                    $val_str = is_scalar($val) ? (string)$val : '[złożona wartość]';
                    echo '<li><strong>'.esc_html($label).':</strong> '.esc_html($val_str).'</li>';
                }
                echo '</ul>';
            } else {
                echo '<p><em>Brak zapisanych pól ACF dla tego dostawcy.</em></p>';
            }
        } else {
            echo '<p><strong>Różnice względem ostatnio zaakceptowanych danych:</strong></p>';
            $has_changes = false;
            echo '<ul>';
            foreach($current_fields as $key => $val){
                $new_val = $val;
                $old_val = array_key_exists($key, $prev_fields) ? $prev_fields[$key] : null;

                $new_str = is_scalar($new_val) ? (string)$new_val : '[złożona wartość]';
                $old_str = is_scalar($old_val) ? (string)$old_val : ( ($old_val === null) ? '[brak]' : '[złożona wartość]' );

                if ($old_val === null && $new_val === null) continue;
                if ($old_str === $new_str) continue;

                $has_changes = true;

                $label = $key;
                if (function_exists('acf_get_field')){
                    $field_obj = acf_get_field($key);
                    if ($field_obj && !is_wp_error($field_obj) && !empty($field_obj['label'])){
                        $label = $field_obj['label'].' ('.$key.')';
                    }
                }

                echo '<li><strong>'.esc_html($label).'</strong><br>
                        Poprzednio: <code>'.esc_html($old_str).'</code><br>
                        Teraz: <code>'.esc_html($new_str).'</code>
                      </li>';
            }
            echo '</ul>';

            if (!$has_changes){
                echo '<p><em>Brak zmian względem ostatnio zaakceptowanej wersji.</em></p>';
            }
        }

        echo '<p class="description">Lista oparta o wszystkie pola ACF przypisane do CPT <code>dostawca</code>.</p>';

        echo '</td></tr>';
    }

    echo '</tbody></table>';
}, 1);

/* Zapis decyzji superadmina */
add_action('personal_options_update','bm_save_supplier_admin_decision');
add_action('edit_user_profile_update','bm_save_supplier_admin_decision');

function bm_save_supplier_admin_decision($user_id){
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['bm_supplier_status'])) return;

    $user_id = (int)$user_id;
    $post_id = bm_get_or_create_supplier_post($user_id);

$new_status = sanitize_text_field($_POST['bm_supplier_status']);
    $reject_reason = isset($_POST['bm_supplier_reject_reason']) ? sanitize_textarea_field(wp_unslash($_POST['bm_supplier_reject_reason'])) : '';
    $allowed    = ['draft','pending','publish','rejected'];
    if (!in_array($new_status, $allowed, true)) return;

    $old_status = get_post_status($post_id);
if ($old_status === $new_status && $new_status !== 'rejected') return;

    wp_update_post([
        'ID'          => $post_id,
        'post_status' => $new_status,
    ]);

    $user = get_userdata($user_id);
    if (!$user) return;

    // Jeśli akceptacja → snapshot i mail
if ($new_status === 'publish'){
        update_user_meta($user_id, 'bm_supplier_reject_reason', '');

        if (function_exists('get_fields')){
            $fields = get_fields($post_id);
            if (is_array($fields)){
                update_post_meta($post_id,'bm_last_accepted_snapshot', wp_json_encode($fields));
            }
        }

        $html = '<div style="font-family:Arial,sans-serif;line-height:1.5">'
              . '<div style="border:1px solid #e5e5e5;border-radius:10px;padding:16px;max-width:700px">'
              . '<h2 style="margin:0 0 10px">Twoje dane zostały zaakceptowane</h2>'
              . '<p>Twoje dane Wykonawcy zostały zaakceptowane przez administrację.</p>'
              . '<p>Twoja wizytówka jest już widoczna publicznie.</p>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:16px 0">'
              . '<p style="color:#666;font-size:12px;margin:0">BrandManager – wiadomość automatyczna. Prosimy nie odpowiadać na tę wiadomość.</p>'
              . '</div></div>';
        bm_send_html_mail(
            $user->user_email,
            'Twoje dane zostały zaakceptowane',
            $html,
            "Twoje dane Wykonawcy zostały zaakceptowane. Twoja wizytówka jest już widoczna publicznie."
        );

    } elseif ($new_status === 'rejected'){
        update_user_meta($user_id, 'bm_supplier_reject_reason', $reject_reason);

        $html = '<div style="font-family:Arial,sans-serif;line-height:1.5">'
              . '<div style="border:1px solid #e5e5e5;border-radius:10px;padding:16px;max-width:700px">'
              . '<h2 style="margin:0 0 10px">Twoje dane wymagają poprawek</h2>'
              . '<p>Twoje dane Wykonawcy zostały odrzucone.</p>'
              . '<p>Zaloguj się do panelu, popraw dane i wyślij je ponownie do akceptacji.</p>'
              . (!empty($reject_reason) ? '<p><strong>Powód odrzucenia:</strong><br>' . nl2br(esc_html($reject_reason)) . '</p>' : '')
              . '<hr style="border:none;border-top:1px solid #eee;margin:16px 0">'
              . '<p style="color:#666;font-size:12px;margin:0">BrandManager – wiadomość automatyczna. Prosimy nie odpowiadać na tę wiadomość.</p>'
              . '</div></div>';
        bm_send_html_mail(
            $user->user_email,
            'Twoje dane wymagają poprawek',
            $html,
            "Twoje dane Wykonawcy zostały odrzucone. Zaloguj się do panelu, popraw dane i wyślij je ponownie do akceptacji."
        );
    }
}

/* USUWANIE OFERTY + ZAKŁADKA "OFERTY" – lista + formularze */

add_action('init', function() {

    if (!is_user_logged_in()) return;

    if ( isset($_GET['delete_supplier_offer']) && isset($_GET['_wpnonce']) ) {

        $offer_id = (int) $_GET['delete_supplier_offer'];
        if (!$offer_id) return;

        if ( !wp_verify_nonce($_GET['_wpnonce'], 'delete_supplier_offer_'.$offer_id) ) return;

        $offer = get_post($offer_id);
        if (!$offer) return;

        if ((int)$offer->post_author !== get_current_user_id()) return;

        wp_delete_post($offer_id, true);

        wp_safe_redirect( wc_get_account_endpoint_url('supplier-offers') );
        exit;
    }
});

add_action('woocommerce_account_supplier-offers_endpoint', function(){

    if (!is_user_logged_in()){ echo '<p>Musisz być zalogowany.</p>'; return; }
    $user = wp_get_current_user();
    if (!in_array('dostawca',(array)$user->roles)){ echo '<p>Ta sekcja jest tylko dla Dostawców.</p>'; return; }

    if (!function_exists('acf_form')){
        echo '<p>ACF PRO jest wymagany do zarządzania ofertami.</p>';
        return;
    }

    $supplier_post_id = bm_get_or_create_supplier_post($user->ID);
    $status = get_post_status($supplier_post_id);

    if ($status !== 'publish'){
        echo '<div style="background:#fffbe6;padding:16px;border-radius:6px;border:1px solid #faad14;margin:16px 0;">
                Uzupełnij i zatwierdź <strong>Dane firmy</strong>, aby móc dodawać oferty.
              </div>';
        return;
    }

    $offer_limit = 3;
    $is_premium = bm_user_has_premium($user->ID);
    $offer_limit_effective = $is_premium ? 6 : $offer_limit;
    $offer_cat_limit = 3;
    $offer_upgrade_link = '<a href="https://brandmanager.cfolks.pl/premium/" class="bm-premium-link" target="_blank" rel="noopener">Ulepsz</a> ->';

    $offer_limit_note = $is_premium
        ? 'Korzystasz z Premium, możesz dodać aż o 3 oferty więcej niż konto standardowe.'
        : 'W planie darmowym możesz dodać maksymalnie <strong>3</strong> oferty. '.$offer_upgrade_link;

    $offers = get_posts([
        'post_type'   => 'dostawca_oferta',
        'post_status' => ['publish','draft','pending'],
        'numberposts' => -1,
        'author'      => $user->ID,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);
    $offers_count = count($offers);

    $mode      = 'list';
    $edit_id   = 0;
    if (isset($_GET['new_offer'])) {
        $mode = 'new';
    } elseif (isset($_GET['edit_offer'])) {
        $mode    = 'edit';
        $edit_id = (int) $_GET['edit_offer'];
    }

    echo '<div class="bm-box bm-box--account">';
    echo '<h2 class="bm-account-title">Twoje oferty</h2>';
    echo '<div class="bm-premium-note">'.wp_kses_post($offer_limit_note).'</div>';
    echo '<p>Obecnie masz: <strong>'.$offers_count.'</strong> / <strong>'.$offer_limit_effective.'</strong>.</p>';
    echo '<p><a href="https://brandmanager.cfolks.pl/premium/" class="button bm-btn-submit">Zwiększ limit</a></p>';
    echo '</div>';

    if ($mode === 'new'){

        if ($offers_count >= $offer_limit_effective){
            echo '<div class="bm-alert bm-alert--error">Osiągnąłeś limit '.$offer_limit_effective.' ofert dla aktualnego planu.</div>';
            echo '<p><a class="button" href="'.esc_url(wc_get_account_endpoint_url('supplier-offers')).'">Wróć do listy ofert</a></p>';
            return;
        }

        echo '<div class="bm-form bm-form--supplier bm-form--offer">';
        echo '<h3 class="bm-form__h">Dodaj nową ofertę</h3>';
        echo '<div class="bm-field bm-field--offer-cats"><label>Kategoria oferty (max '.(int)$offer_cat_limit.')</label>';
        bm_render_tax_tree('oferta_kategoria','bm_offer_categories',[],$offer_cat_limit);
        echo '</div>';
        echo '<div class="bm-field bm-field--offer-spec"><label>Specjalizacja oferty (max '.(int)$offer_cat_limit.')</label>';
        bm_render_tax_tree('oferta_specjalizacja','bm_offer_specializations',[],$offer_cat_limit);
        echo '</div>';
        echo '<div class="bm-field bm-field--offer-term"><label>Termin realizacji</label>';
        $offer_term_options = get_terms(['taxonomy' => 'oferta_termin', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        echo '<select name="bm_offer_term"><option value="0">— wybierz —</option>';
        if (!is_wp_error($offer_term_options)){ foreach($offer_term_options as $term_opt){ echo '<option value="'.esc_attr((int)$term_opt->term_id).'">'.esc_html($term_opt->name).'</option>'; } }
        echo '</select></div>';
        echo '<div class="bm-field bm-field--offer-budget"><label>Stawka / Budżet</label>';
        $offer_budget_options = get_terms(['taxonomy' => 'oferta_budzet', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        echo '<select name="bm_offer_budget"><option value="0">— wybierz —</option>';
        if (!is_wp_error($offer_budget_options)){ foreach($offer_budget_options as $budget_opt){ echo '<option value="'.esc_attr((int)$budget_opt->term_id).'">'.esc_html($budget_opt->name).'</option>'; } }
        echo '</select></div>';
        echo '<div class="bm-field bm-field--offer-image-note"><div class="bm-help">Obrazek oferty: maks. 256KB. Po wyborze zobaczysz podgląd kołowy.</div><div class="bm-photo-preview bm-offer-photo-preview"><span>Podgląd obrazka w kole</span></div><div class="bm-help bm-help--file-error" aria-live="polite"></div></div>';
        echo '</div>';

        acf_form([
            'post_id'          => 'new_post',
            'new_post'         => [
                'post_type'   => 'dostawca_oferta',
                'post_status' => 'publish',
                'post_author' => $user->ID,
            ],
            'post_title'       => true,
            'post_content'     => false,
            'uploader'         => 'wp',
            'return'           => wc_get_account_endpoint_url('supplier-offers'),
            'submit_value'     => 'Zapisz ofertę',
            'updated_message'  => 'Oferta została zapisana.',
            'label_placement'  => 'top',
            'html_before_fields'=> '<div class="bm-offer-form-acf">',
            'html_after_fields'=> '<input type="hidden" name="bm_offer_form" value="1" /></div>',
        ]);

        echo '<p><a class="button" href="'.esc_url(wc_get_account_endpoint_url('supplier-offers')).'">Anuluj</a></p>';
        echo '</div>';

        return;
    }

    if ($mode === 'edit' && $edit_id){

        $offer = get_post($edit_id);
        if (!$offer || $offer->post_type !== 'dostawca_oferta' || (int)$offer->post_author !== $user->ID){
            echo '<div class="bm-alert bm-alert--error">Nie możesz edytować tej oferty.</div>';
            echo '<p><a class="button" href="'.esc_url(wc_get_account_endpoint_url('supplier-offers')).'">Wróć do listy ofert</a></p>';
            return;
        }

        echo '<div class="bm-form bm-form--supplier bm-form--offer">';
        echo '<h3 class="bm-form__h">Edytuj ofertę: '.esc_html(get_the_title($offer)).'</h3>';
        $offer_selected_cats = wp_get_post_terms($offer->ID, 'oferta_kategoria', ['fields' => 'ids']);
        if (is_wp_error($offer_selected_cats)) { $offer_selected_cats = []; }
        $offer_selected_specs = wp_get_post_terms($offer->ID, 'oferta_specjalizacja', ['fields' => 'ids']);
        if (is_wp_error($offer_selected_specs)) { $offer_selected_specs = []; }
        echo '<div class="bm-field bm-field--offer-cats"><label>Kategoria oferty (max '.(int)$offer_cat_limit.')</label>';
        bm_render_tax_tree('oferta_kategoria','bm_offer_categories',$offer_selected_cats,$offer_cat_limit);
        echo '</div>';
        echo '<div class="bm-field bm-field--offer-spec"><label>Specjalizacja oferty (max '.(int)$offer_cat_limit.')</label>';
        bm_render_tax_tree('oferta_specjalizacja','bm_offer_specializations',$offer_selected_specs,$offer_cat_limit);
        echo '</div>';
        $offer_selected_term = wp_get_post_terms($offer->ID, 'oferta_termin', ['fields' => 'ids']);
        if (is_wp_error($offer_selected_term) || empty($offer_selected_term)) { $offer_selected_term = [0]; }
        $offer_selected_budget = wp_get_post_terms($offer->ID, 'oferta_budzet', ['fields' => 'ids']);
        if (is_wp_error($offer_selected_budget) || empty($offer_selected_budget)) { $offer_selected_budget = [0]; }
        echo '<div class="bm-field bm-field--offer-term"><label>Termin realizacji</label>';
        $offer_term_options = get_terms(['taxonomy' => 'oferta_termin', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        echo '<select name="bm_offer_term"><option value="0">— wybierz —</option>';
        if (!is_wp_error($offer_term_options)){ foreach($offer_term_options as $term_opt){ echo '<option value="'.esc_attr((int)$term_opt->term_id).'" '.selected((int)$offer_selected_term[0], (int)$term_opt->term_id, false).'>'.esc_html($term_opt->name).'</option>'; } }
        echo '</select></div>';
        echo '<div class="bm-field bm-field--offer-budget"><label>Stawka / Budżet</label>';
        $offer_budget_options = get_terms(['taxonomy' => 'oferta_budzet', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        echo '<select name="bm_offer_budget"><option value="0">— wybierz —</option>';
        if (!is_wp_error($offer_budget_options)){ foreach($offer_budget_options as $budget_opt){ echo '<option value="'.esc_attr((int)$budget_opt->term_id).'" '.selected((int)$offer_selected_budget[0], (int)$budget_opt->term_id, false).'>'.esc_html($budget_opt->name).'</option>'; } }
        echo '</select></div>';
        echo '<div class="bm-field bm-field--offer-image-note"><div class="bm-help">Obrazek oferty: maks. 256KB. Po wyborze zobaczysz podgląd kołowy.</div><div class="bm-photo-preview bm-offer-photo-preview"><span>Podgląd obrazka w kole</span></div><div class="bm-help bm-help--file-error" aria-live="polite"></div></div>';
        echo '</div>';

        $delete_url = wp_nonce_url(
            add_query_arg('delete_supplier_offer', $edit_id, wc_get_account_endpoint_url('supplier-offers')),
            'delete_supplier_offer_'.$edit_id
        );

        acf_form([
            'post_id'          => $edit_id,
            'post_title'       => true,
            'post_content'     => false,
            'uploader'         => 'wp',
            'return'           => wc_get_account_endpoint_url('supplier-offers'),
            'submit_value'     => 'Zapisz zmiany',
            'updated_message'  => 'Oferta została zaktualizowana.',
            'label_placement'  => 'top',
            'html_before_fields'=> '<div class="bm-offer-form-acf">',
            'html_after_fields'=> '<input type="hidden" name="bm_offer_form" value="1" /></div>',
        ]);

        echo '<p>
                <a href="'.esc_url($delete_url).'"
                   class="button"
                   onclick="return confirm(\'Czy na pewno usunąć tę ofertę?\');">
                    Usuń ofertę
                </a>
                <a class="button" href="'.esc_url(wc_get_account_endpoint_url('supplier-offers')).'">
                    Wróć do listy ofert
                </a>
              </p>';

        echo '</div>';

        return;
    }

    echo '<div class="bm-box bm-box--account bm-offers-list">';
    echo '<h3 class="bm-form__h">Lista Twoich ofert</h3>';

    if ($offers_count < $offer_limit_effective){
        $new_url = add_query_arg('new_offer', 1, wc_get_account_endpoint_url('supplier-offers'));
        echo '<p><a class="button button-primary" href="'.esc_url($new_url).'">Dodaj nową ofertę</a></p>';
    } else {
        echo '<div class="bm-premium-note">Masz już maksymalną liczbę ofert dla aktualnego planu. '.wp_kses_post($offer_upgrade_link).'</div>';
    }

    if (empty($offers)){
        echo '<p>Nie masz jeszcze żadnych ofert.</p>';
    } else {
        echo '<ul class="bm-offers-listing">';
        foreach($offers as $offer){
            $edit_link = add_query_arg('edit_offer', $offer->ID, wc_get_account_endpoint_url('supplier-offers'));
            $view_link = get_permalink($offer->ID);

            echo '<li class="bm-offers-listing__item">';
            echo '<strong>'.esc_html(get_the_title($offer)).'</strong>';
            echo '<div class="bm-offers-listing__actions">';
            echo '<a class="button" href="'.esc_url($edit_link).'">Edytuj</a> ';
            if ($view_link){
                echo '<a class="button" href="'.esc_url($view_link).'" target="_blank">Zobacz</a>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    echo '</div>';

    echo '<script>(function(){
      var offerWrap = document.querySelector(".bm-form--offer");
      if(!offerWrap) return;

      function bindOfferLimit(fieldClass, max){
        var root = offerWrap.querySelector(fieldClass + " .bm-tax-tree");
        if(!root) return;
        var boxes = root.querySelectorAll("input[type=checkbox]");
        function enforce(){
          var checked = root.querySelectorAll("input[type=checkbox]:checked").length;
          boxes.forEach(function(cb){
            if(!cb.checked){
              cb.disabled = checked >= max;
              cb.parentElement.classList.toggle("is-disabled", checked >= max);
            }
          });
        }
        boxes.forEach(function(cb){ cb.addEventListener("change", enforce); });
        enforce();
      }

      bindOfferLimit(".bm-field--offer-cats", 3);
      bindOfferLimit(".bm-field--offer-spec", 3);

      var preview = offerWrap.querySelector(".bm-offer-photo-preview");
      var fileErr = offerWrap.querySelector(".bm-field--offer-image-note .bm-help--file-error");
      var imageInput = offerWrap.querySelector("input[type=file][name^=\"acf[field_\"]");

      if(imageInput){
        imageInput.addEventListener("change", function(){
          var f = this.files && this.files[0] ? this.files[0] : null;
          if(!f) return;

          if(f.size > 262144){
            if(fileErr){ fileErr.textContent = "Plik jest za duży. Maksymalny rozmiar: 256KB."; }
            this.value = "";
            if(preview){
              preview.style.backgroundImage = "none";
              preview.classList.remove("is-filled");
            }
            return;
          }

          if(fileErr){ fileErr.textContent = ""; }
          if(preview){
            var reader = new FileReader();
            reader.onload = function(e){
              preview.style.backgroundImage = "url(" + e.target.result + ")";
              preview.classList.add("is-filled");
            };
            reader.readAsDataURL(f);
          }
        });
      }
    })();</script>';
});

add_action('acf/save_post', function($post_id){
    if (empty($_POST['bm_offer_form'])) return;
    if (get_post_type($post_id) !== 'dostawca_oferta') return;
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('dostawca', (array) $user->roles, true)) return;

    // Oferta zawsze przypisana do aktualnego wykonawcy
    $supplier_post_id = bm_get_or_create_supplier_post($user->ID);
    update_post_meta($post_id, 'powiazany_dostawca', (int) $supplier_post_id);
    wp_update_post(['ID' => $post_id, 'post_author' => $user->ID]);

    // Kategorie oferty z taką samą logiką limitu jak w danych wykonawcy
    $max_taxonomy_items = 3;
    $offer_cats = isset($_POST['bm_offer_categories']) ? array_map('intval', (array) wp_unslash($_POST['bm_offer_categories'])) : [];
    $offer_cats = array_values(array_filter($offer_cats));
    if (count($offer_cats) > $max_taxonomy_items) {
        $offer_cats = array_slice($offer_cats, 0, $max_taxonomy_items);
    }
    if (!empty($offer_cats)){
        wp_set_object_terms($post_id, $offer_cats, 'oferta_kategoria', false);
    } else {
        wp_set_object_terms($post_id, [], 'oferta_kategoria', false);
    }

    $offer_specs = isset($_POST['bm_offer_specializations']) ? array_map('intval', (array) wp_unslash($_POST['bm_offer_specializations'])) : [];
    $offer_specs = array_values(array_filter($offer_specs));
    if (count($offer_specs) > $max_taxonomy_items) {
        $offer_specs = array_slice($offer_specs, 0, $max_taxonomy_items);
    }
    wp_set_object_terms($post_id, $offer_specs, 'oferta_specjalizacja', false);

    $offer_term = isset($_POST['bm_offer_term']) ? (int) wp_unslash($_POST['bm_offer_term']) : 0;
    wp_set_object_terms($post_id, $offer_term ? [$offer_term] : [], 'oferta_termin', false);

    $offer_budget = isset($_POST['bm_offer_budget']) ? (int) wp_unslash($_POST['bm_offer_budget']) : 0;
    wp_set_object_terms($post_id, $offer_budget ? [$offer_budget] : [], 'oferta_budzet', false);
}, 30);
