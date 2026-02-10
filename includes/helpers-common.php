<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wspólne helpery
 */

/**
 * E-maile superadminów / adminów
 */
function bm_get_superadmin_emails(){
    // 1) Jeśli ustawiono ręcznie w opcjach – użyj
    $opt = get_option('bm_superadmin_emails','');
    if (is_string($opt) && trim($opt) !== ''){
        $parts = preg_split('/[\s,;]+/', $opt);
        $parts = array_map('sanitize_email', (array)$parts);
        $parts = array_filter($parts, function($e){ return is_email($e); });
        if (!empty($parts)){
            return array_values(array_unique($parts));
        }
    }

    // 2) Fallback: użytkownicy roli superadmin
    $emails = [];
    $supers = get_users(['role'=>'superadmin','fields'=>['user_email']]);
    foreach($supers as $u){ $emails[] = $u->user_email; }

    // 3) Fallback: administrator
    if (empty($emails)) {
        $admins = get_users(['role'=>'administrator','fields'=>['user_email']]);
        foreach($admins as $a){ $emails[] = $a->user_email; }
    }
    return array_values(array_unique(array_filter($emails)));
}

/**
 * Prosty mail HTML (czytelny, lekki)
 */
function bm_send_html_mail($to, $subject, $html, $plain_fallback = ''){
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $ok = wp_mail($to, $subject, $html, $headers);
    if (!$ok && $plain_fallback){
        wp_mail($to, $subject, $plain_fallback, ['Content-Type: text/plain; charset=UTF-8']);
    }
    return $ok;
}

/**
 * Znajdź lub utwórz post dostawcy (CPT: dostawca) powiązany z userem
 */
function bm_get_or_create_supplier_post($user_id){
    $existing = get_posts([
        'post_type'   => 'dostawca',
        'author'      => $user_id,
        'numberposts' => 1,
        'post_status' => ['draft','pending','publish','rejected'],
        'fields'      => 'ids'
    ]);
    if (!empty($existing)) return (int)$existing[0];

    $u = get_userdata($user_id);
    $post_id = wp_insert_post([
        'post_type'   => 'dostawca',
        'post_status' => 'draft',
        'post_author' => $user_id,
        'post_title'  => $u ? $u->display_name : 'Dostawca #'.$user_id
    ]);

    return (int) $post_id;
}

/**
 * Znajdź lub utwórz post zamawiającego (CPT: zamawiajacy) powiązany z userem
 */
function bm_get_or_create_client_post( $user_id ) {

    $existing = get_posts( [
        'post_type'   => 'zamawiajacy',
        'author'      => $user_id,
        'numberposts' => 1,
        'post_status' => [ 'draft', 'pending', 'publish', 'rejected' ],
        'fields'      => 'ids',
    ] );

    if ( ! empty( $existing ) ) {
        return (int) $existing[0];
    }

    $u       = get_userdata( $user_id );
    $post_id = wp_insert_post( [
        'post_type'   => 'zamawiajacy',
        'post_status' => 'draft',
        'post_author' => $user_id,
        'post_title'  => $u ? $u->user_email : ( 'Zamawiający #' . $user_id ),
    ] );

    return (int) $post_id;
}

/**
 * ACF form head na stronach Moje konto (wspólne dla dostawcy i zamawiającego)
 */
add_action('template_redirect', function(){
    if (function_exists('is_account_page') && is_account_page() && function_exists('acf_form_head')) {
        acf_form_head();
    }
});

/**
 * CPT dostawcy dla zalogowanego usera
 */
function bm_get_supplier_post_for_user($user_id){
    $posts = get_posts([
        'post_type'   => 'dostawca',
        'author'      => $user_id,
        'numberposts' => 1,
        'post_status' => ['publish','pending','draft','rejected'],
        'fields'      => 'ids'
    ]);
    return !empty($posts) ? (int)$posts[0] : 0;
}
