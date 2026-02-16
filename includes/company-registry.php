<?php
if ( ! defined('ABSPATH') ) { exit; }

add_action('wp_ajax_bm_company_lookup', 'bm_company_lookup_ajax');
add_action('wp_ajax_nopriv_bm_company_lookup', 'bm_company_lookup_ajax');

function bm_company_lookup_ajax() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) $_POST['nonce']) : '';
    if ( ! wp_verify_nonce($nonce, 'bm_company_lookup_nonce') ) {
        wp_send_json_error(['message' => 'Błędny token formularza. Odśwież stronę i spróbuj ponownie.']);
    }

    $nip = isset($_POST['nip']) ? preg_replace('/\D+/', '', (string) $_POST['nip']) : '';
    if (strlen($nip) !== 10) {
        wp_send_json_error(['message' => 'Wpisz poprawny NIP (10 cyfr).']);
    }

    $cache_key = 'bm_company_lookup_' . $nip;
    $cached = get_transient($cache_key);
    if ($cached) {
        wp_send_json_success($cached);
    }

    $payload = [
        'source' => null,
        'badges' => [],
        'data'   => [
            'company_name' => '',
            'nip'          => $nip,
            'street'       => '',
            'postal_code'  => '',
            'city'         => '',
            'regon'        => '',
            'krs'          => '',
            'status'       => '',
            'status_vat'   => '',
            'start_date'   => '',
            'pkd'          => [],
            'legal_form'   => '',
        ],
    ];

    if (defined('BM_CEIDG_TOKEN') && BM_CEIDG_TOKEN && BM_CEIDG_TOKEN !== 'WSTAW_TUTAJ_SWÓJ_TOKEN_JWT_Z_DANE.BIZNES.GOV.PL') {
        $ceidg = bm_ceidg_fetch_by_nip($nip, BM_CEIDG_TOKEN);
        if ( ! is_wp_error($ceidg) && ! empty($ceidg['ok']) ) {
            $payload['source'] = 'CEIDG';
            $payload['data'] = array_merge($payload['data'], $ceidg['data']);
            $payload['badges'][] = ['type' => 'ok', 'text' => 'Dane z CEIDG'];
        }
    }

    $mf = bm_mf_whitelist_fetch_by_nip($nip);
    if ( ! is_wp_error($mf) && ! empty($mf['ok']) ) {
        $payload['source'] = 'MF';
        $payload['data'] = array_merge($payload['data'], $mf['data']);
        $payload['badges'][] = ['type' => 'ok', 'text' => 'Dane z MF (Biała lista VAT)'];
    }

    if ( ! empty($payload['data']['krs']) ) {
        $krs = bm_krs_fetch_odpis_aktualny($payload['data']['krs']);
        if ( ! is_wp_error($krs) && ! empty($krs['ok']) ) {
            $payload['source'] = 'KRS';
            $payload['data'] = array_merge($payload['data'], $krs['data']);
            $payload['badges'][] = ['type' => 'ok', 'text' => 'Zweryfikowano w KRS'];
        }
    }

    if (!empty($payload['data']['status'])) {
        $payload['badges'][] = ['type' => 'info', 'text' => 'Status: ' . $payload['data']['status']];
    }
    if (!empty($payload['data']['status_vat'])) {
        $payload['badges'][] = ['type' => 'info', 'text' => 'Status VAT: ' . $payload['data']['status_vat']];
    }
    if (!empty($payload['data']['krs'])) {
        $payload['badges'][] = ['type' => 'info', 'text' => 'KRS: ' . $payload['data']['krs']];
    }
    if (!empty($payload['data']['regon'])) {
        $payload['badges'][] = ['type' => 'info', 'text' => 'REGON: ' . $payload['data']['regon']];
    }

    if (empty($payload['source'])) {
        wp_send_json_error(['message' => 'Nie udało się pobrać danych z rejestrów. Uzupełnij dane ręcznie.']);
    }

    set_transient($cache_key, $payload, 12 * HOUR_IN_SECONDS);
    wp_send_json_success($payload);
}

function bm_ceidg_fetch_by_nip(string $nip, string $token) {
    $url = 'https://dane.biznes.gov.pl/api/ceidg/v2/firmy?nip=' . rawurlencode($nip);
    $resp = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ]);

    if (is_wp_error($resp)) return $resp;
    if ((int) wp_remote_retrieve_response_code($resp) !== 200) return ['ok' => false];

    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($json)) return ['ok' => false];

    $firma = null;
    if (!empty($json['firmy']) && is_array($json['firmy'])) {
        $firma = $json['firmy'][0] ?? null;
    } elseif (isset($json[0])) {
        $firma = $json[0];
    }
    if (!is_array($firma)) return ['ok' => false];

    $addr = $firma['adresDzialalnosci'] ?? $firma['adres'] ?? [];
    $street = trim(($addr['ulica'] ?? '') . ' ' . ($addr['nrDomu'] ?? '') . (($addr['nrLokalu'] ?? '') ? '/' . $addr['nrLokalu'] : ''));

    return [
        'ok' => true,
        'data' => [
            'company_name' => (string) ($firma['nazwa'] ?? $firma['nazwaFirmy'] ?? $firma['firma'] ?? ''),
            'regon'        => (string) ($firma['regon'] ?? ''),
            'status'       => (string) ($firma['status'] ?? $firma['statusDzialalnosci'] ?? ''),
            'start_date'   => (string) ($firma['dataRozpoczecia'] ?? $firma['dataRozpoczeciaDzialalnosci'] ?? ''),
            'street'       => $street,
            'postal_code'  => (string) ($addr['kodPocztowy'] ?? ''),
            'city'         => (string) ($addr['miejscowosc'] ?? ''),
            'legal_form'   => 'JDG (CEIDG)',
        ],
    ];
}

function bm_mf_whitelist_fetch_by_nip(string $nip) {
    $date = gmdate('Y-m-d');
    $url = 'https://wl-api.mf.gov.pl/api/search/nip/' . rawurlencode($nip) . '?date=' . rawurlencode($date);
    $resp = wp_remote_get($url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($resp)) return $resp;
    if ((int) wp_remote_retrieve_response_code($resp) !== 200) return ['ok' => false];

    $json = json_decode(wp_remote_retrieve_body($resp), true);
    $subject = $json['result']['subject'] ?? null;
    if (!is_array($subject)) return ['ok' => false];

    $address_line = (string) ($subject['workingAddress'] ?? $subject['residenceAddress'] ?? '');
    $street = '';
    $postal = '';
    $city   = '';
    if ($address_line && preg_match('/(\d{2}-\d{3})\s+([^,]+)$/u', $address_line, $m)) {
        $postal = trim($m[1]);
        $city = trim($m[2]);
        $street = trim(preg_replace('/\s*\d{2}-\d{3}\s+[^,]+$/u', '', $address_line), " ,\t\n\r\0\x0B");
    } else {
        $street = $address_line;
    }

    return [
        'ok' => true,
        'data' => [
            'company_name' => (string) ($subject['name'] ?? ''),
            'regon'        => (string) ($subject['regon'] ?? ''),
            'krs'          => (string) ($subject['krs'] ?? ''),
            'status_vat'   => (string) ($subject['statusVat'] ?? ''),
            'street'       => $street,
            'postal_code'  => $postal,
            'city'         => $city,
            'legal_form'   => !empty($subject['krs']) ? 'Podmiot z KRS' : 'Podmiot (MF)',
        ],
    ];
}

function bm_krs_fetch_odpis_aktualny(string $krs) {
    $krs = preg_replace('/\D+/', '', $krs);
    if (strlen($krs) < 5) return ['ok' => false];

    $url = 'https://api-krs.ms.gov.pl/api/krs/OdpisAktualny/' . rawurlencode($krs) . '?rejestr=P&format=json';
    $resp = wp_remote_get($url, ['timeout' => 12, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($resp)) return $resp;
    if ((int) wp_remote_retrieve_response_code($resp) !== 200) return ['ok' => false];

    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($json)) return ['ok' => false];

    $addr = $json['odpis']['dane']['dzial1']['siedzibaIAdres']['adres'] ?? [];
    $street = trim(($addr['ulica'] ?? '') . ' ' . ($addr['nrDomu'] ?? '') . (($addr['nrLokalu'] ?? '') ? '/' . $addr['nrLokalu'] : ''));

    return [
        'ok' => true,
        'data' => [
            'company_name' => (string) ($json['odpis']['dane']['dzial1']['danePodmiotu']['nazwa'] ?? $json['nazwa'] ?? ''),
            'regon'        => (string) ($json['odpis']['dane']['dzial1']['danePodmiotu']['regon'] ?? ''),
            'street'       => $street,
            'postal_code'  => (string) ($addr['kodPocztowy'] ?? ''),
            'city'         => (string) ($addr['miejscowosc'] ?? ''),
            'krs'          => (string) $krs,
            'legal_form'   => 'Spółka (KRS)',
        ],
    ];
}
