<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce registration extensions
 * - account type selector: dostawca (Wykonawca) / zamawiajacy (Klient)
 * - required company fields for Wykonawca during registration
 * - role assignment + CPT auto create
 * - notify superadmins and set supplier post status to pending
 */

/**
 * UI fields inside Woo registration form
 */
add_action( 'woocommerce_register_form', function () {
    ?>
    <div class="bm-register bm-register--account-type">
        <p class="form-row form-row-wide bm-field bm-field--account-type">
            <label for="bm_account_type"><strong>Wybierz rodzaj konta <span class="required">*</span></strong></label>
            <select name="account_type" id="bm_account_type" required class="woocommerce-Input woocommerce-Input--select input-text">
                <option value="">-- wybierz --</option>
                <option value="dostawca">Wykonawca</option>
                <option value="zamawiajacy">Klient</option>
            </select>
        </p>

        <div class="bm-account-type-info bm-info bm-info--account-type">
            <div class="bm-info__box">
                <p><strong>WYKONAWCA</strong></p>
                <p>Możesz prezentować wizytówkę swojej firmy, portfolio realizacji oraz odpowiadać na zlecenia Klientów.</p>
                <hr>
                <p><strong>KLIENT</strong></p>
                <p>Możesz publikować zlecenia oraz odpowiadać na ogłoszenia Wykonawców.</p>
            </div>
        </div>

        <div id="bm_company_fields" class="bm-company-fields" style="display:none;">
            <h4 class="bm-company-fields__title">Dane firmy (Wykonawca)</h4>

            <p class="form-row form-row-wide bm-field bm-field--company-name">
                <label for="bm_company_name">Nazwa firmy <span class="required">*</span></label>
                <input type="text" name="bm_company_name" id="bm_company_name" class="woocommerce-Input woocommerce-Input--text input-text" value="<?php echo isset($_POST['bm_company_name']) ? esc_attr(wp_unslash($_POST['bm_company_name'])) : ''; ?>" data-bm-required="1" autocomplete="organization" />
            </p>

            <p class="form-row form-row-first bm-field bm-field--company-street">
                <label for="bm_company_street">Ulica i nr <span class="required">*</span></label>
                <input type="text" name="bm_company_street" id="bm_company_street" class="woocommerce-Input woocommerce-Input--text input-text" value="<?php echo isset($_POST['bm_company_street']) ? esc_attr(wp_unslash($_POST['bm_company_street'])) : ''; ?>" data-bm-required="1" autocomplete="address-line1" />
            </p>

            <p class="form-row form-row-last bm-field bm-field--company-postcode">
                <label for="bm_company_postcode">Kod pocztowy <span class="required">*</span></label>
                <input type="text" name="bm_company_postcode" id="bm_company_postcode" class="woocommerce-Input woocommerce-Input--text input-text" placeholder="00-000" value="<?php echo isset($_POST['bm_company_postcode']) ? esc_attr(wp_unslash($_POST['bm_company_postcode'])) : ''; ?>" data-bm-required="1" autocomplete="postal-code" />
            </p>

            <p class="form-row form-row-wide bm-field bm-field--company-city">
                <label for="bm_company_city">Miejscowość <span class="required">*</span></label>
                <input type="text" name="bm_company_city" id="bm_company_city" class="woocommerce-Input woocommerce-Input--text input-text" value="<?php echo isset($_POST['bm_company_city']) ? esc_attr(wp_unslash($_POST['bm_company_city'])) : ''; ?>" data-bm-required="1" autocomplete="address-level2" />
            </p>

            <p class="form-row form-row-wide bm-field bm-field--company-nip">
                <label for="bm_company_nip">NIP firmy <span class="required">*</span></label>
                <input type="text" name="bm_company_nip" id="bm_company_nip" class="woocommerce-Input woocommerce-Input--text input-text" value="<?php echo isset($_POST['bm_company_nip']) ? esc_attr(wp_unslash($_POST['bm_company_nip'])) : ''; ?>" data-bm-required="1" inputmode="numeric" />
            </p>
        </div>

        <script>
        (function(){
            function toggleCompanyFields(){
                var sel = document.getElementById('bm_account_type');
                var box = document.getElementById('bm_company_fields');
                if(!sel || !box) return;
                var isSupplier = sel.value === 'dostawca';
                box.style.display = isSupplier ? '' : 'none';
                var requiredInputs = box.querySelectorAll('[data-bm-required="1"]');
                requiredInputs.forEach(function(el){
                    if (isSupplier) el.setAttribute('required','required');
                    else el.removeAttribute('required');
                });
            }
            function init(){
                var sel = document.getElementById('bm_account_type');
                if(!sel) return;
                sel.addEventListener('change', toggleCompanyFields);
                toggleCompanyFields();
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
            // Elementor/late DOM safety
            setTimeout(init, 250);
            setTimeout(init, 800);
        })();
        </script>

        <style>
            .bm-account-type-info .bm-info__box{background:#FFD700;padding:20px 24px;border-radius:8px;margin:10px 0 20px;font-size:14px;color:#1B2A4E;line-height:1.55em;}
            .bm-account-type-info .bm-info__box hr{border:0;border-bottom:1px solid rgba(0,0,0,0.15);margin:12px 0;}
            .bm-company-fields{background:#fff;padding:14px 14px 4px;border:1px solid #e8e8e8;border-radius:8px;margin:16px 0;}
            .bm-company-fields__title{margin:0 0 10px;font-size:14px;}
        </style>
    </div>
    <?php
} );

/**
 * Validate registration fields
 */
add_action( 'woocommerce_register_post', function ( $username, $email, $validation_errors ) {
    $type = isset( $_POST['account_type'] ) ? sanitize_text_field( wp_unslash( $_POST['account_type'] ) ) : '';

    if ( ! in_array( $type, [ 'dostawca', 'zamawiajacy' ], true ) ) {
        $validation_errors->add( 'bm_account_type', 'Wybierz rodzaj konta.' );
        return $validation_errors;
    }

    if ( $type === 'dostawca' ) {
        $fields = [
            'bm_company_name'     => 'Podaj nazwę firmy.',
            'bm_company_street'   => 'Podaj ulicę i numer.',
            'bm_company_postcode' => 'Podaj kod pocztowy.',
            'bm_company_city'     => 'Podaj miejscowość.',
            'bm_company_nip'      => 'Podaj NIP firmy.',
        ];

        foreach ( $fields as $key => $msg ) {
            $val = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
            if ( $val === '' ) {
                $validation_errors->add( $key, $msg );
            }
        }

        $nip = isset( $_POST['bm_company_nip'] ) ? preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['bm_company_nip'] ) ) : '';
        if ( $nip && strlen( $nip ) !== 10 ) {
            $validation_errors->add( 'bm_company_nip_invalid', 'NIP powinien mieć 10 cyfr.' );
        }

        $postcode = isset( $_POST['bm_company_postcode'] ) ? trim( (string) wp_unslash( $_POST['bm_company_postcode'] ) ) : '';
        if ( $postcode && ! preg_match( '/^\d{2}-\d{3}$/', $postcode ) ) {
            $validation_errors->add( 'bm_company_postcode_invalid', 'Kod pocztowy powinien mieć format 00-000.' );
        }
    }

    return $validation_errors;
}, 10, 3 );

/**
 * Persist registration metadata + set roles
 */
add_action( 'woocommerce_created_customer', function ( $customer_id ) {

    $type = isset( $_POST['account_type'] ) ? sanitize_text_field( wp_unslash( $_POST['account_type'] ) ) : '';
    if ( ! in_array( $type, [ 'dostawca', 'zamawiajacy' ], true ) ) {
        return;
    }

    // Save base meta
    update_user_meta( $customer_id, 'bm_account_type', $type );

    if ( $type === 'dostawca' ) {
        // Company data
        $company_name = isset( $_POST['bm_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bm_company_name'] ) ) : '';
        $street       = isset( $_POST['bm_company_street'] ) ? sanitize_text_field( wp_unslash( $_POST['bm_company_street'] ) ) : '';
        $postcode     = isset( $_POST['bm_company_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['bm_company_postcode'] ) ) : '';
        $city         = isset( $_POST['bm_company_city'] ) ? sanitize_text_field( wp_unslash( $_POST['bm_company_city'] ) ) : '';
        $nip          = isset( $_POST['bm_company_nip'] ) ? preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['bm_company_nip'] ) ) : '';

        update_user_meta( $customer_id, 'bm_company_name', $company_name );
        update_user_meta( $customer_id, 'bm_company_street', $street );
        update_user_meta( $customer_id, 'bm_company_postcode', $postcode );
        update_user_meta( $customer_id, 'bm_company_city', $city );
        update_user_meta( $customer_id, 'bm_company_nip', $nip );

        // Assign role
        wp_update_user( [
            'ID'   => $customer_id,
            'role' => 'dostawca',
        ] );

        // Create supplier CPT and set to pending
        $post_id = function_exists( 'bm_get_or_create_supplier_post' ) ? bm_get_or_create_supplier_post( $customer_id ) : 0;
        if ( $post_id ) {
            // Set title to company name for nicer URLs/admin
            if ( $company_name ) {
                wp_update_post( [
                    'ID'         => $post_id,
                    'post_title' => $company_name,
                ] );
            }
            if ( get_post_status( $post_id ) === 'draft' ) {
                wp_update_post( [ 'ID' => $post_id, 'post_status' => 'pending' ] );
            }
        }

        // Mark pending for frontend message
        update_user_meta( $customer_id, 'bm_supplier_status', 'pending' );

        // Notify superadmins
        $emails = function_exists( 'bm_portal_get_superadmin_emails' ) ? bm_portal_get_superadmin_emails() : [];
        if ( ! empty( $emails ) ) {
            $site    = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
            $subject = sprintf( '[%s] Nowy Wykonawca do zatwierdzenia', $site );

            $edit_link = $post_id ? admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ) : admin_url( 'users.php?user_id=' . (int) $customer_id );

            $body = '<div style="font-family:Arial,sans-serif;max-width:680px;line-height:1.5">'
                  . '<div style="border:1px solid #e5e5e5;border-radius:10px;overflow:hidden">'
                  . '<div style="background:#1B2A4E;color:#fff;padding:14px 16px;font-size:16px;font-weight:700">Nowa rejestracja – Wykonawca</div>'
                  . '<div style="padding:16px;background:#fff">'
                  . '<p><strong>Firma:</strong> ' . esc_html( $company_name ) . '</p>'
                  . '<p><strong>NIP:</strong> ' . esc_html( $nip ) . '</p>'
                  . '<p><strong>Adres:</strong> ' . esc_html( $street . ', ' . $postcode . ' ' . $city ) . '</p>'
                  . '<p><strong>E-mail konta:</strong> ' . esc_html( get_userdata( $customer_id )->user_email ) . '</p>'
                  . '<p style="margin-top:16px"><a href="' . esc_url( $edit_link ) . '" style="display:inline-block;background:#FFD700;color:#1B2A4E;text-decoration:none;padding:10px 14px;border-radius:8px;font-weight:700">Przejdź do weryfikacji</a></p>'
                  . '</div>'
                  . '<div style="padding:12px 16px;background:#fafafa;border-top:1px solid #e5e5e5;color:#666;font-size:12px">Wiadomość wygenerowana automatycznie przez portal BrandManager.</div>'
                  . '</div>'
                  . '</div>';

            wp_mail( $emails, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
        }

    } else {
        // Client = immediate (no approval)
        wp_update_user( [
            'ID'   => $customer_id,
            'role' => 'zamawiajacy',
        ] );

        if ( function_exists( 'bm_get_or_create_client_post' ) ) {
            bm_get_or_create_client_post( $customer_id );
        }
    }
}, 10, 1 );

/**
 * Redirect after registration
 */
add_filter( 'woocommerce_registration_redirect', function ( $redirect_to ) {
    $type = isset( $_POST['account_type'] ) ? sanitize_text_field( wp_unslash( $_POST['account_type'] ) ) : '';
    if ( $type === 'dostawca' ) {
        return wc_get_account_endpoint_url( 'supplier-data' );
    }
    return wc_get_page_permalink( 'myaccount' );
} );

/**
 * Optional: adjust Woo register messages/labels (front only)
 */
add_filter( 'gettext', function ( $translated, $text, $domain ) {
    // Only front, keep safe
    if ( is_admin() ) return $translated;

    // A few key UI replacements
    $replacements = [
        'Dostawca'     => 'Wykonawca',
        'Zamawiający'  => 'Klient',
        'Zamawiajacy'  => 'Klient',
    ];

    if ( isset( $replacements[ $translated ] ) ) {
        return $replacements[ $translated ];
    }
    if ( isset( $replacements[ $text ] ) ) {
        return $replacements[ $text ];
    }

    return $translated;
}, 10, 3 );
