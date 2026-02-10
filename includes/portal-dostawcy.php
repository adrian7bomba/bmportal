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
function bm_handle_image_upload($file_key, $max_bytes = 131072){
    if (empty($_FILES[$file_key]) || empty($_FILES[$file_key]['name'])) return 0;
    if (!function_exists('wp_handle_upload')) require_once ABSPATH.'wp-admin/includes/file.php';
    if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH.'wp-admin/includes/image.php';

    $f = $_FILES[$file_key];
    if (!empty($f['size']) && (int)$f['size'] > (int)$max_bytes){
        return new WP_Error('bm_file_too_big', 'Plik jest za duży. Maksymalny rozmiar: 128KB.');
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
        echo '<li class="bm-tax-tree__item">';
        echo '<label class="bm-tax-tree__label">';
        echo '<input class="bm-tax-tree__checkbox" type="checkbox" name="'.esc_attr($name).'[]" value="'.esc_attr($term->term_id).'" '.checked($is_checked,true,false).'> ';
        echo '<span>'.esc_html($term->name).'</span>';
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

            // Profil
            $www          = esc_url_raw(bm_post('bm_company_www'));
            $desc         = wp_kses_post(bm_post('bm_company_description'));
            $size         = sanitize_text_field(bm_post('bm_company_size'));
            $op_name      = sanitize_text_field(bm_post('bm_contact_name'));
            $op_role      = sanitize_text_field(bm_post('bm_contact_role'));
            $op_email     = sanitize_email(bm_post('bm_contact_email'));
            $op_phone     = sanitize_text_field(bm_post('bm_contact_phone'));

            $awarded      = bm_post('bm_awarded') ? 1 : 0;
            $awarded_text = sanitize_textarea_field(bm_post('bm_awarded_text'));

            $spec_ids = array_map('intval', (array)bm_post('bm_specializations', []));
            $spec_ids = array_values(array_filter($spec_ids));
            if (count($spec_ids) > 3){
                $errors[] = 'Możesz wybrać maksymalnie 3 specjalizacje.';
                $spec_ids = array_slice($spec_ids, 0, 3);
            }

            $ind_ids = array_map('intval', (array)bm_post('bm_industries', []));
            $ind_ids = array_values(array_filter($ind_ids));
            if (count($ind_ids) > 3){
                $errors[] = 'Możesz wybrać maksymalnie 3 branże doświadczenia.';
                $ind_ids = array_slice($ind_ids, 0, 3);
            }

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

                bm_set_supplier_field($post_id, 'www_dostawca', $www);
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

                // branże – meta (oddzielnie)
                bm_set_supplier_field($post_id, 'bm_industries', $ind_ids);

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

                // status pending (tylko z frontu)
                if (!current_user_can('manage_options')){
                    wp_update_post(['ID'=>$post_id,'post_status'=>'pending']);
                }

                // mail do superadminów
                $emails = bm_get_superadmin_emails();
                $user_panel_link = admin_url('user-edit.php?user_id='.$user->ID);
                $supplier_post_link = get_edit_post_link($post_id);
                $html = '<div style="font-family:Arial,sans-serif;line-height:1.5">'
                      . '<div style="border:1px solid #e5e5e5;border-radius:10px;padding:16px;max-width:700px">'
                      . '<h2 style="margin:0 0 10px">Wykonawca – dane do akceptacji</h2>'
                      . '<p>Wykonawca <strong>'.esc_html($user->user_email).'</strong> zaktualizował dane firmy.</p>'
                      . '<p><strong>Nazwa firmy:</strong> '.esc_html($company_name).'<br>'
                      . '<strong>NIP:</strong> '.esc_html($nip_raw).'</p>'
                      . '<p style="margin:12px 0">'
                      . '<a href="'.esc_url($user_panel_link).'" style="display:inline-block;padding:10px 14px;border-radius:8px;background:#1B2A4E;color:#fff;text-decoration:none">Profil użytkownika</a> '
                      . ($supplier_post_link ? '<a href="'.esc_url($supplier_post_link).'" style="display:inline-block;padding:10px 14px;border-radius:8px;background:#FFD700;color:#1B2A4E;text-decoration:none;margin-left:8px">Edycja wizytówki</a>' : '')
                      . '</p>'
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
    }
    if (!empty($errors)){
        echo '<div class="bm-alert bm-alert--error"><ul>'; foreach($errors as $e){ echo '<li>'.esc_html($e).'</li>'; } echo '</ul></div>';
    }
    if ($success){
        echo '<div class="bm-alert bm-alert--success">'.esc_html($success).'</div>';
    }
    echo '</div>';

    // wartości do formularza
    $v_company_name = bm_get_supplier_field($post_id,'nazwa_dostawca','');
    $v_street       = bm_get_supplier_field($post_id,'ulica_dostawca','');
    $v_postcode     = bm_get_supplier_field($post_id,'kod_pocztowy_dostawca','');
    $v_city         = bm_get_supplier_field($post_id,'miejscowosc_dostawca','');
    $v_nip          = bm_get_supplier_field($post_id,'nip_dostawca','');
    $v_www          = bm_get_supplier_field($post_id,'www_dostawca','');
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

    echo '<form class="bm-form bm-form--supplier" method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="bm_supplier_step2" value="1">';
    wp_nonce_field('bm_supplier_step2','_bm_nonce');

    echo '<div class="bm-form__section">';
    echo '<h3 class="bm-form__h">Dane firmy</h3>';

    echo '<div class="bm-grid bm-grid--2">';

    echo '<div class="bm-field bm-field--company-name"><label>Nazwa firmy <span class="bm-req">*</span></label><input type="text" name="bm_company_name" value="'.esc_attr($v_company_name).'" required></div>';
    echo '<div class="bm-field bm-field--nip"><label>NIP <span class="bm-req">*</span></label><input type="text" name="bm_company_nip" value="'.esc_attr($v_nip).'" inputmode="numeric" required></div>';
    echo '<div class="bm-field bm-field--street"><label>Ulica i numer <span class="bm-req">*</span></label><input type="text" name="bm_company_street" value="'.esc_attr($v_street).'" required></div>';
    echo '<div class="bm-field bm-field--postcode"><label>Kod pocztowy <span class="bm-req">*</span></label><input type="text" name="bm_company_postcode" value="'.esc_attr($v_postcode).'" required></div>';
    echo '<div class="bm-field bm-field--city"><label>Miejscowość <span class="bm-req">*</span></label><input type="text" name="bm_company_city" value="'.esc_attr($v_city).'" required></div>';

    echo '</div>'; // grid

    echo '<div class="bm-grid bm-grid--2">';
    echo '<div class="bm-field bm-field--logo"><label>Logo firmy</label><input type="file" name="bm_company_logo" accept="image/*"><div class="bm-help">Maksymalny rozmiar pliku: 128KB. Format: JPG/PNG/WEBP.</div></div>';
    echo '<div class="bm-field bm-field--photo"><label>Zdjęcie firmy</label><input type="file" name="bm_company_photo" accept="image/*"><div class="bm-help">Najlepiej wykadruj zdjęcie tak, aby główna treść była na środku. Maksymalny rozmiar: 128KB.</div></div>';
    echo '</div>';

    echo '</div>'; // section

    echo '<div class="bm-form__section">';
    echo '<h3 class="bm-form__h">Profil Wykonawcy</h3>';

    echo '<div class="bm-grid bm-grid--2">';
    echo '<div class="bm-field bm-field--www"><label>Strona WWW</label><input type="url" name="bm_company_www" value="'.esc_attr($v_www).'" placeholder="https://..."></div>';
    echo '<div class="bm-field bm-field--size"><label>Wielkość firmy</label>';
    echo '<select name="bm_company_size">';
    $sizes = ['' => '— wybierz —','do 5 pracowników'=>'do 5 pracowników','5-10 pracowników'=>'5-10 pracowników','10-20 pracowników'=>'10-20 pracowników','ponad 20 pracowników'=>'ponad 20 pracowników'];
    foreach($sizes as $k=>$lbl){ echo '<option value="'.esc_attr($k).'" '.selected($v_size,$k,false).'>'.esc_html($lbl).'</option>'; }
    echo '</select></div>';
    echo '</div>';

    echo '<div class="bm-field bm-field--desc">';
    echo '<label>Opis firmy</label>';
    echo '<div class="bm-premium-note bm-premium-note--green">W wersji darmowej limit opisu to <strong>200 znaków</strong>. W pakiecie Premium: <strong>1500 znaków</strong>.</div>';
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
    echo '<div class="bm-field bm-field--spec"><label>Specjalizacja (max 3)</label>';
    bm_render_tax_tree('dostawca_kategoria','bm_specializations',$v_spec,3);
    echo '</div>';
    echo '<div class="bm-field bm-field--industries"><label>Doświadczenie w branżach (max 3)</label>';
    bm_render_tax_tree('dostawca_kategoria','bm_industries',$v_ind,3);
    echo '<div class="bm-premium-note">W Premium możesz wybrać do <strong>6</strong> branż.</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="bm-field bm-field--awarded">';
    echo '<label><input type="checkbox" name="bm_awarded" value="1" '.checked($v_awarded,1,false).'> Nagradzani w konkursach</label>';
    echo '<div class="bm-field bm-field--awarded-text" style="margin-top:10px;'.($v_awarded? '':'display:none;').'">';
    echo '<label>Opisz nagrody</label><textarea name="bm_awarded_text" rows="4">'.esc_textarea($v_awarded_text).'</textarea></div>';
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
        function enforce(){
          var checked = root.querySelectorAll("input[type=checkbox]:checked");
          if(checked.length>=max){
            cbs.forEach(function(cb){ if(!cb.checked){ cb.disabled=true; cb.parentElement.classList.add("is-disabled"); } });
          }else{
            cbs.forEach(function(cb){ cb.disabled=false; cb.parentElement.classList.remove("is-disabled"); });
          }
        }
        cbs.forEach(function(cb){ cb.addEventListener("change", enforce); });
        enforce();
      }
      document.querySelectorAll(".bm-tax-tree").forEach(initTree);
      var aw = document.querySelector("input[name=bm_awarded]");
      var box = document.querySelector(".bm-field--awarded-text");
      if(aw && box){ aw.addEventListener("change", function(){ box.style.display = this.checked?"block":"none"; }); }
    })();</script>';
});


/* PANEL AKCEPTACJI DOSTAWCY W PROFILU UŻYTKOWNIKA */

add_action('edit_user_profile', function($user){

    if (!in_array('dostawca', (array)$user->roles, true)) return;

    $post_id = bm_get_or_create_supplier_post($user->ID);
    $status  = get_post_status($post_id) ?: 'draft';

    echo '<h2>Dane Wykonawcy – akceptacja</h2>';

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

    $public_link = get_permalink($post_id);
    $edit_post_link = get_edit_post_link($post_id,'');
    echo '<p class="description">';
    echo 'Aktualny status: <strong>'.esc_html($status).'</strong>. ';
    if ($public_link && $status === 'publish'){
        echo 'Publiczna wizytówka: <a href="'.esc_url($public_link).'" target="_blank">'.esc_html($public_link).'</a><br>';
    }
    if ($edit_post_link){
        echo 'Edycja wpisu "dostawca": <a href="'.esc_url($edit_post_link).'" target="_blank">Otwórz w nowej karcie</a>';
    }
    echo '</p>';

    echo '</td>';
    echo '</tr>';

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
});

/* Zapis decyzji superadmina */
add_action('personal_options_update','bm_save_supplier_admin_decision');
add_action('edit_user_profile_update','bm_save_supplier_admin_decision');

function bm_save_supplier_admin_decision($user_id){
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['bm_supplier_status'])) return;

    $user_id = (int)$user_id;
    $post_id = bm_get_or_create_supplier_post($user_id);

    $new_status = sanitize_text_field($_POST['bm_supplier_status']);
    $allowed    = ['draft','pending','publish','rejected'];
    if (!in_array($new_status, $allowed, true)) return;

    $old_status = get_post_status($post_id);
    if ($old_status === $new_status) return;

    wp_update_post([
        'ID'          => $post_id,
        'post_status' => $new_status,
    ]);

    $user = get_userdata($user_id);
    if (!$user) return;

    // Jeśli akceptacja → snapshot i mail
    if ($new_status === 'publish'){

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
              . '<p style="color:#666;font-size:12px;margin:0">BrandManager – wiadomość automatyczna.</p>'
              . '</div></div>';
        bm_send_html_mail(
            $user->user_email,
            'Twoje dane zostały zaakceptowane',
            $html,
            "Twoje dane Wykonawcy zostały zaakceptowane. Twoja wizytówka jest już widoczna publicznie."
        );

    } elseif ($new_status === 'rejected'){

        $html = '<div style="font-family:Arial,sans-serif;line-height:1.5">'
              . '<div style="border:1px solid #e5e5e5;border-radius:10px;padding:16px;max-width:700px">'
              . '<h2 style="margin:0 0 10px">Twoje dane wymagają poprawek</h2>'
              . '<p>Twoje dane Wykonawcy zostały odrzucone.</p>'
              . '<p>Zaloguj się do panelu, popraw dane i wyślij je ponownie do akceptacji.</p>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:16px 0">'
              . '<p style="color:#666;font-size:12px;margin:0">BrandManager – wiadomość automatyczna.</p>'
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

    echo '<div style="background:#fff;padding:16px;border-radius:8px;border:1px solid #e8e8e8;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">Twoje oferty</h2>';
    echo '<p>W planie darmowym możesz dodać maksymalnie <strong>'.$offer_limit.'</strong> ofert(y). 
          Obecnie masz: <strong>'.$offers_count.'</strong>.</p>';
    echo '<p><button type="button" class="button" style="margin-top:8px;">Zwiększ limit (wkrótce)</button></p>';
    echo '</div>';

    if ($mode === 'new'){

        if ($offers_count >= $offer_limit){
            echo '<div style="background:#fffbe6;padding:12px;border-radius:6px;border:1px solid #faad14;margin-bottom:24px;">
                    Osiągnąłeś limit '.$offer_limit.' ofert w planie darmowym.
                  </div>';
            echo '<p><a class="button" href="'.esc_url(wc_get_account_endpoint_url('supplier-offers')).'">Wróć do listy ofert</a></p>';
            return;
        }

        echo '<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e8e8e8;margin-bottom:24px;">';
        echo '<h3 style="margin-top:0;">Dodaj nową ofertę</h3>';

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
            'html_after_fields'=> '<input type="hidden" name="bm_offer_form" value="1" />',
        ]);

        echo '<p><a class="button" href="'.esc_url(wc_get_account_endpoint_url('supplier-offers')).'" style="margin-top:10px;">Anuluj</a></p>';
        echo '</div>';

        return;
    }

    if ($mode === 'edit' && $edit_id){

        $offer = get_post($edit_id);
        if (!$offer || $offer->post_type !== 'dostawca_oferta' || (int)$offer->post_author !== $user->ID){
            echo '<div style="background:#fff1f0;padding:12px;border-radius:6px;border:1px solid #f5222d;margin-bottom:12px;">
                    Nie możesz edytować tej oferty.
                  </div>';
            echo '<p><a class="button" href="'.esc_url(wc_get_account_endpoint_url('supplier-offers')).'">Wróć do listy ofert</a></p>';
            return;
        }

        echo '<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e8e8e8;margin-bottom:24px;">';
        echo '<h3 style="margin-top:0;">Edytuj ofertę: '.esc_html(get_the_title($offer)).'</h3>';

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
            'html_after_fields'=> '<input type="hidden" name="bm_offer_form" value="1" />',
        ]);

        echo '<p style="margin-top:20px;">
                <a href="'.esc_url($delete_url).'"
                   class="button"
                   style="background:#c62828;color:#fff;border-color:#b71c1c;"
                   onclick="return confirm(\'Czy na pewno usunąć tę ofertę?\');">
                    Usuń ofertę
                </a>
                <a class="button" href="'.esc_url(wc_get_account_endpoint_url('supplier-offers')).'" style="margin-left:8px;">
                    Wróć do listy ofert
                </a>
              </p>';

        echo '</div>';

        return;
    }

    echo '<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e8e8e8;">';
    echo '<h3 style="margin-top:0;">Lista Twoich ofert</h3>';

    if ($offers_count < $offer_limit){
        $new_url = add_query_arg('new_offer', 1, wc_get_account_endpoint_url('supplier-offers'));
        echo '<p><a class="button button-primary" href="'.esc_url($new_url).'">Dodaj nową ofertę</a></p>';
    } else {
        echo '<p><strong>Masz już maksymalną liczbę ofert w planie darmowym.</strong></p>';
    }

    if (empty($offers)){
        echo '<p>Nie masz jeszcze żadnych ofert.</p>';
    } else {
        echo '<ul style="list-style:none;padding-left:0;margin-top:20px;">';
        foreach($offers as $offer){
            $edit_link = add_query_arg('edit_offer', $offer->ID, wc_get_account_endpoint_url('supplier-offers'));
            $view_link = get_permalink($offer->ID);

            echo '<li style="border-top:1px solid #e8e8e8;padding:12px 0;">';
            echo '<strong>'.esc_html(get_the_title($offer)).'</strong>';
            echo '<div style="margin-top:6px;">';
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
});
