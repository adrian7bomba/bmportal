<?php
/**
 * BrandManager – test form + CEIDG/KRS lookup by NIP
 * Shortcode: [bm_brandmanager_form_test]
 */

add_action('wp_enqueue_scripts', function () {
  // No-op: wszystko inline w shortcode
});

add_action('wp_ajax_bm_lookup_company', 'bm_lookup_company_ajax');
add_action('wp_ajax_nopriv_bm_lookup_company', 'bm_lookup_company_ajax');

function bm_lookup_company_ajax() {
  // Basic security
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'bm_nip_nonce')) {
    wp_send_json_error(['message' => 'Błędny nonce. Odśwież stronę i spróbuj ponownie.']);
  }

  $nip = isset($_POST['nip']) ? preg_replace('/\D+/', '', $_POST['nip']) : '';
  if (strlen($nip) < 10) {
    wp_send_json_error(['message' => 'Wpisz poprawny NIP (10 cyfr).']);
  }

  // Cache
  $cache_key = 'bm_nip_' . $nip;
  $cached = get_transient($cache_key);
  if ($cached) {
    wp_send_json_success($cached);
  }

  $result = [
    'source' => null,          // CEIDG | KRS
    'badges' => [],            // UI badges
    'data'   => [
      'company_name' => '',
      'nip'          => $nip,
      'street'       => '',
      'postal_code'  => '',
      'city'         => '',
      'regon'        => '',
      'krs'          => '',
      'status'       => '',
      'start_date'   => '',
      'pkd'          => [],   // array
      'legal_form'   => '',
    ],
  ];

  // 1) Try CEIDG (requires token)
  $ceidg_token = defined('BM_CEIDG_TOKEN') ? BM_CEIDG_TOKEN : '';
  if (!empty($ceidg_token)) {
    $ceidg = bm_fetch_ceidg_by_nip($nip, $ceidg_token);
    if (!is_wp_error($ceidg) && !empty($ceidg['ok'])) {
      $result['source'] = 'CEIDG';
      $result['data'] = array_merge($result['data'], $ceidg['data']);
      $result['badges'][] = ['type' => 'ok', 'text' => 'Dane z CEIDG'];
      if (!empty($result['data']['status'])) {
        $result['badges'][] = ['type' => 'info', 'text' => 'Status: ' . $result['data']['status']];
      }
      if (!empty($result['data']['start_date'])) {
        $result['badges'][] = ['type' => 'info', 'text' => 'Start: ' . $result['data']['start_date']];
      }
      if (!empty($result['data']['pkd'])) {
        $result['badges'][] = ['type' => 'info', 'text' => 'PKD: ' . implode(', ', array_slice($result['data']['pkd'], 0, 3)) . (count($result['data']['pkd']) > 3 ? '…' : '')];
      }

      set_transient($cache_key, $result, 12 * HOUR_IN_SECONDS);
      wp_send_json_success($result);
    }
  }

  // 2) Try KRS Open API (no token)
  $krs = bm_fetch_krs_by_nip($nip);
  if (!is_wp_error($krs) && !empty($krs['ok'])) {
    $result['source'] = 'KRS';
    $result['data'] = array_merge($result['data'], $krs['data']);
    $result['badges'][] = ['type' => 'ok', 'text' => 'Dane z KRS'];
    if (!empty($result['data']['krs'])) {
      $result['badges'][] = ['type' => 'info', 'text' => 'KRS: ' . $result['data']['krs']];
    }
    if (!empty($result['data']['status'])) {
      $result['badges'][] = ['type' => 'info', 'text' => 'Status: ' . $result['data']['status']];
    }
    if (!empty($result['data']['pkd'])) {
      $result['badges'][] = ['type' => 'info', 'text' => 'PKD: ' . implode(', ', array_slice($result['data']['pkd'], 0, 3)) . (count($result['data']['pkd']) > 3 ? '…' : '')];
    }

    set_transient($cache_key, $result, 12 * HOUR_IN_SECONDS);
    wp_send_json_success($result);
  }

  // Fallback
  wp_send_json_error([
    'message' => 'Nie udało się pobrać danych z rejestrów w tym momencie. Uzupełnij dane ręcznie.',
    'hint'    => empty($ceidg_token) ? 'Brak BM_CEIDG_TOKEN (CEIDG wymaga tokena). KRS też nie zwrócił wyniku dla tego NIP.' : null,
  ]);
}

/**
 * CEIDG: dane.biznes.gov.pl Hurtownia – firmy?nip=...
 * Requires Bearer token.
 */
function bm_fetch_ceidg_by_nip(string $nip, string $token) {
  $url = 'https://dane.biznes.gov.pl/api/ceidg/v2/firmy?nip=' . rawurlencode($nip);

  $resp = wp_remote_get($url, [
    'timeout' => 8,
    'headers' => [
      'Authorization' => 'Bearer ' . $token,
      'Accept'        => 'application/json',
    ],
  ]);

  if (is_wp_error($resp)) return $resp;

  $code = wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);

  if ($code !== 200) {
    return new WP_Error('ceidg_http', 'CEIDG HTTP ' . $code);
  }

  $json = json_decode($body, true);
  if (!is_array($json)) {
    return new WP_Error('ceidg_json', 'CEIDG: niepoprawny JSON');
  }

  // Dokumentacja zwraca zwykle listę "firmy" – zostawiamy elastycznie.
  $firma = null;
  if (!empty($json['firmy']) && is_array($json['firmy'])) {
    $firma = $json['firmy'][0] ?? null;
  } elseif (!empty($json[0])) {
    $firma = $json[0];
  }

  if (!$firma) {
    return ['ok' => false];
  }

  // Mapowanie (elastyczne – różne wersje pól)
  $name  = $firma['nazwa'] ?? $firma['nazwaFirmy'] ?? $firma['firma'] ?? '';
  $regon = $firma['regon'] ?? '';
  $status = $firma['status'] ?? $firma['statusDzialalnosci'] ?? '';
  $start  = $firma['dataRozpoczecia'] ?? $firma['dataRozpoczeciaDzialalnosci'] ?? '';

  // Adres – składamy z tego co jest
  $ulica = $firma['adresDzialalnosci']['ulica'] ?? $firma['adres']['ulica'] ?? '';
  $nr    = $firma['adresDzialalnosci']['nrDomu'] ?? $firma['adres']['nrDomu'] ?? '';
  $lok   = $firma['adresDzialalnosci']['nrLokalu'] ?? $firma['adres']['nrLokalu'] ?? '';
  $kod   = $firma['adresDzialalnosci']['kodPocztowy'] ?? $firma['adres']['kodPocztowy'] ?? '';
  $miasto= $firma['adresDzialalnosci']['miejscowosc'] ?? $firma['adres']['miejscowosc'] ?? '';

  $street = trim($ulica . ' ' . $nr . ($lok ? '/' . $lok : ''));

  // PKD
  $pkd = [];
  if (!empty($firma['pkd']) && is_array($firma['pkd'])) {
    foreach ($firma['pkd'] as $p) {
      $codeP = $p['kod'] ?? $p['pkd'] ?? null;
      if ($codeP) $pkd[] = $codeP;
    }
  } elseif (!empty($firma['kodyPKD']) && is_array($firma['kodyPKD'])) {
    $pkd = array_values(array_filter($firma['kodyPKD']));
  }

  return [
    'ok' => true,
    'data' => [
      'company_name' => $name,
      'regon'        => $regon,
      'status'       => $status,
      'start_date'   => $start,
      'street'       => $street,
      'postal_code'  => $kod,
      'city'         => $miasto,
      'pkd'          => $pkd,
      'legal_form'   => 'JDG (CEIDG)',
    ]
  ];
}

/**
 * KRS Open API PRS (format by KRS number; by NIP may require a search endpoint).
 * Implemented in a tolerant way:
 * - tries a "search by NIP" endpoint variant
 * - if it returns KRS number, fetches current extract by KRS
 */
function bm_fetch_krs_by_nip(string $nip) {
  // Variant A (search) – depending on PRS implementation:
  $search_urls = [
    // Spotykane warianty (PRS zmienia ścieżki; dlatego lista):
    'https://prs.ms.gov.pl/krs/openApi/api/krs/search?nip=' . rawurlencode($nip) . '&format=json',
    'https://prs.ms.gov.pl/krs/openApi/api/krs/podmioty?nip=' . rawurlencode($nip) . '&format=json',
    'https://prs.ms.gov.pl/krs/openApi/api/krs/Podmioty?nip=' . rawurlencode($nip) . '&format=json',
  ];

  $foundKrs = null;
  foreach ($search_urls as $u) {
    $resp = wp_remote_get($u, ['timeout' => 8, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($resp)) continue;
    if (wp_remote_retrieve_response_code($resp) !== 200) continue;
    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($json)) continue;

    // Spróbuj wydłubać numer KRS z różnych struktur
    $foundKrs = $json['krs'] ?? $json['numerKrs'] ?? $json['number'] ?? null;
    if (!$foundKrs && !empty($json['items'][0])) {
      $foundKrs = $json['items'][0]['krs'] ?? $json['items'][0]['numerKrs'] ?? null;
    }
    if (!$foundKrs && !empty($json[0])) {
      $foundKrs = $json[0]['krs'] ?? $json[0]['numerKrs'] ?? null;
    }
    if ($foundKrs) break;
  }

  if (!$foundKrs) {
    return ['ok' => false];
  }

  // Variant B: Fetch current extract by KRS (typowo działa)
  $krs = preg_replace('/\D+/', '', (string)$foundKrs);
  $extract_urls = [
    // Najczęściej spotykany wzorzec:
    'https://prs.ms.gov.pl/krs/openApi/api/krs/OdpisAktualny/' . rawurlencode($krs) . '?rejestr=P&format=json',
    'https://prs.ms.gov.pl/krs/openApi/api/krs/odpisaktualny/' . rawurlencode($krs) . '?rejestr=P&format=json',
  ];

  $dataJson = null;
  foreach ($extract_urls as $u) {
    $resp = wp_remote_get($u, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($resp)) continue;
    if (wp_remote_retrieve_response_code($resp) !== 200) continue;
    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (is_array($json)) { $dataJson = $json; break; }
  }

  if (!$dataJson) {
    return ['ok' => false];
  }

  // Mapowanie: w PRS dane są głębokie – bierzemy podstawowe rzeczy.
  $name = $dataJson['odpis']['dane']['dzial1']['danePodmiotu']['nazwa'] ?? $dataJson['nazwa'] ?? '';
  $regon = $dataJson['odpis']['dane']['dzial1']['danePodmiotu']['regon'] ?? '';
  $status = $dataJson['odpis']['informacjeODpisie']['stan'] ?? $dataJson['status'] ?? 'Aktywny';

  $addr = $dataJson['odpis']['dane']['dzial1']['siedzibaIAdres']['adres'] ?? [];
  $street = trim(($addr['ulica'] ?? '') . ' ' . ($addr['nrDomu'] ?? '') . (($addr['nrLokalu'] ?? '') ? '/' . $addr['nrLokalu'] : ''));
  $postal = $addr['kodPocztowy'] ?? '';
  $city   = $addr['miejscowosc'] ?? ($dataJson['odpis']['dane']['dzial1']['siedzibaIAdres']['siedziba']['miejscowosc'] ?? '');

  $pkd = [];
  $pkdNode = $dataJson['odpis']['dane']['dzial3']['przedmiotDzialalnosci']['pkd'] ?? null;
  if (is_array($pkdNode)) {
    foreach ($pkdNode as $p) {
      $codeP = $p['kod'] ?? null;
      if ($codeP) $pkd[] = $codeP;
    }
  }

  return [
    'ok' => true,
    'data' => [
      'company_name' => $name,
      'regon'        => $regon,
      'krs'          => $krs,
      'status'       => $status,
      'street'       => $street,
      'postal_code'  => $postal,
      'city'         => $city,
      'pkd'          => $pkd,
      'legal_form'   => 'Spółka (KRS)',
    ]
  ];
}

add_shortcode('bm_brandmanager_form_test', function () {
  $nonce = wp_create_nonce('bm_nip_nonce');
  ob_start(); ?>

  <style>
    /* Wrapper */
    .bm-wrap{max-width:1000px;margin:0 auto;padding:18px;border-radius:16px;background:#fff;box-shadow:0 12px 35px rgba(0,0,0,.06);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial}
    .bm-title{font-size:18px;font-weight:700;color:#111827;margin:0 0 14px}
    .bm-section{margin-top:18px}
    .bm-section h4{font-size:15px;margin:0 0 12px;color:#111827}
    .bm-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .bm-field label{display:block;font-size:12px;font-weight:700;color:#374151;margin:0 0 6px}
    .bm-field input[type="text"],.bm-field input[type="url"],.bm-field input[type="email"],.bm-field input[type="tel"],.bm-field select,.bm-field textarea{
      width:100%;padding:12px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:14px;outline:none
    }
    .bm-field input:focus,.bm-field select:focus,.bm-field textarea:focus{border-color:#84cc16;box-shadow:0 0 0 3px rgba(132,204,22,.18)}
    .bm-inline{display:flex;gap:10px;align-items:center}
    .bm-inline .bm-btn{white-space:nowrap;border:0;border-radius:10px;padding:12px 14px;font-weight:700;cursor:pointer;background:#111827;color:#fff}
    .bm-inline .bm-btn:hover{opacity:.9}
    .bm-muted{font-size:12px;color:#6b7280;margin-top:6px}
    .bm-badges{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0}
    .bm-badge{font-size:12px;font-weight:700;padding:7px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#f9fafb;color:#111827}
    .bm-badge.ok{background:#dcfce7;border-color:#86efac}
    .bm-badge.info{background:#eef2ff;border-color:#c7d2fe}
    .bm-badge.warn{background:#fef9c3;border-color:#fde047}

    /* Upload */
    .bm-file input[type="file"]{padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;width:100%}
    .bm-file .bm-muted{margin-top:6px}

    /* Green info bars */
    .bm-greenbar{margin:10px 0;background:#84cc16;color:#0f172a;padding:10px 12px;border-radius:10px;font-weight:700;font-size:12px}

    /* Checkbox columns – FIX (ładnie jak na screenie) */
    .bm-checkwrap{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff}
    .bm-checkhead{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .bm-checkhead b{font-size:13px;color:#111827}
    .bm-checkhead span{font-size:12px;color:#6b7280}
    .bm-checklist{max-height:360px;overflow:auto;padding-right:6px}
    .bm-checkitem{display:flex;align-items:flex-start;gap:10px;padding:6px 6px;border-radius:10px}
    .bm-checkitem:hover{background:#f8fafc}
    .bm-checkitem input{margin-top:2px}
    .bm-checkitem .txt{font-size:13px;color:#111827;line-height:1.25}
    .bm-checkgroup{margin-top:10px;padding-top:10px;border-top:1px dashed #e5e7eb}
    .bm-checkgroup .gtitle{font-size:12px;font-weight:800;color:#374151;margin:0 0 6px}

    /* Submit */
    .bm-submit{margin-top:18px;display:flex;justify-content:flex-start}
    .bm-submit button{background:#a00055;color:#fff;border:0;border-radius:12px;padding:14px 18px;font-weight:800;cursor:pointer}
    .bm-submit button:hover{background:#820046}

    /* Responsive */
    @media(max-width:820px){.bm-grid2{grid-template-columns:1fr}}
  </style>

  <div class="bm-wrap" id="bm-form-root">
    <div class="bm-title">Dane firmy</div>

    <div class="bm-field">
      <div class="bm-badges" id="bm-badges" style="display:none"></div>
    </div>

    <div class="bm-grid2">
      <div class="bm-field">
        <label>Nazwa firmy <span style="color:#dc2626">*</span></label>
        <input type="text" id="bm_company_name" placeholder="Nazwa firmy">
      </div>

      <div class="bm-field">
        <label>NIP <span style="color:#dc2626">*</span></label>
        <div class="bm-inline">
          <input type="text" id="bm_nip" placeholder="Wpisz NIP">
          <button class="bm-btn" type="button" id="bm_fetch_nip">Zaczytaj</button>
        </div>
        <div class="bm-muted" id="bm_nip_status"></div>
      </div>

      <div class="bm-field">
        <label>Ulica i numer <span style="color:#dc2626">*</span></label>
        <input type="text" id="bm_street" placeholder="">
      </div>

      <div class="bm-field">
        <label>Kod pocztowy <span style="color:#dc2626">*</span></label>
        <input type="text" id="bm_postal" placeholder="">
      </div>

      <div class="bm-field">
        <label>Miejscowość <span style="color:#dc2626">*</span></label>
        <input type="text" id="bm_city" placeholder="">
      </div>

      <div class="bm-field">
        <label>REGON (z rejestru)</label>
        <input type="text" id="bm_regon" placeholder="" readonly>
        <div class="bm-muted">Pole uzupełniane automatycznie po zaczytaniu danych.</div>
      </div>

      <div class="bm-field bm-file">
        <label>Logo firmy</label>
        <input type="file" id="bm_logo" accept=".jpg,.jpeg,.png,.webp">
        <div class="bm-muted">Maksymalny rozmiar pliku: 128KB. Format: JPG/PNG/WEBP.</div>
      </div>

      <div class="bm-field bm-file">
        <label>Zdjęcie firmy</label>
        <input type="file" id="bm_photo" accept=".jpg,.jpeg,.png,.webp">
        <div class="bm-muted">Najlepiej wykadruj zdjęcie tak, aby główna treść była na środku. Maksymalny rozmiar: 128KB.</div>
      </div>
    </div>

    <div class="bm-section">
      <h4>Profil Wykonawcy</h4>

      <div class="bm-grid2">
        <div class="bm-field">
          <label>Strona WWW</label>
          <input type="url" id="bm_www" placeholder="https://…">
        </div>

        <div class="bm-field">
          <label>Wielkość firmy</label>
          <select id="bm_size">
            <option value="">— wybierz —</option>
            <option value="do5">do 5 pracowników</option>
            <option value="5-10">5–10 pracowników</option>
            <option value="10-20">10–20 pracowników</option>
            <option value="20plus">ponad 20 pracowników</option>
          </select>
        </div>
      </div>

      <div class="bm-field" style="margin-top:14px">
        <label>Opis firmy</label>
        <div class="bm-greenbar">W wersji darmowej limit opisu to 200 znaków. W pakiecie Premium: 1500 znaków.</div>
        <textarea id="bm_desc" rows="6" placeholder=""></textarea>
        <div class="bm-muted">
          <b>Co warto zawrzeć w opisie firmy?</b><br>
          • Twarde dane: ile lat na rynku, wielkość zespołu.<br>
          • Specjalizacja: w czym jesteście najlepsi.<br>
          • Zaplecze: park maszynowy/studio/podwykonawcy.<br>
          • Skala: korporacje vs MŚP.<br>
          • Technologia: narzędzia (np. Jira, Asana, Adobe CC, SEMrush).
        </div>
      </div>

      <div class="bm-grid2" style="margin-top:14px">
        <div class="bm-field">
          <label>Imię i nazwisko opiekuna klienta</label>
          <input type="text" id="bm_contact_name">
        </div>
        <div class="bm-field">
          <label>Stanowisko opiekuna klienta</label>
          <input type="text" id="bm_contact_role">
        </div>
        <div class="bm-field">
          <label>Adres e-mail</label>
          <input type="email" id="bm_email">
        </div>
        <div class="bm-field">
          <label>Telefon kontaktowy</label>
          <input type="tel" id="bm_phone">
        </div>
      </div>

      <div class="bm-grid2" style="margin-top:14px">
        <!-- Specjalizacja -->
        <div class="bm-checkwrap" data-limit="3">
          <div class="bm-checkhead">
            <b>Specjalizacja (max 3)</b>
            <span class="bm-counter">0/3</span>
          </div>
          <div class="bm-checklist">
            <div class="bm-checkgroup">
              <div class="gtitle">Druk & POS</div>
              <?php foreach ([
                'Drukarnia Cyfrowa i Offsetowa',
                'Drukarnia Wielkoformatowa (Outdoor)',
                'Gadżety Reklamowe i Merch',
                'Materiały POS i Ekspozytory',
                'Opakowania i Etykiety',
              ] as $opt): ?>
                <label class="bm-checkitem"><input type="checkbox" class="bm-limit-3" name="spec[]" value="<?php echo esc_attr($opt); ?>"><span class="txt"><?php echo esc_html($opt); ?></span></label>
              <?php endforeach; ?>
            </div>

            <div class="bm-checkgroup">
              <div class="gtitle">Kreacja & Komunikacja</div>
              <?php foreach ([
                'Agencje Reklamowe / 360',
                'Branding & Identyfikacja',
                'Digital & UI Design',
                'Motion Design & Animacja',
                'Packaging & Product Design',
              ] as $opt): ?>
                <label class="bm-checkitem"><input type="checkbox" class="bm-limit-3" name="spec[]" value="<?php echo esc_attr($opt); ?>"><span class="txt"><?php echo esc_html($opt); ?></span></label>
              <?php endforeach; ?>
            </div>

            <div class="bm-checkgroup">
              <div class="gtitle">Marketing / Tech</div>
              <?php foreach ([
                'Marketing',
                'PR',
                'Prawo',
                'Social Media',
                'Strony internetowe',
                'TECH / AI',
                'Automatyzacja',
                'E-mail Marketing',
                'Płatne Kampanie PPC',
                'SEO & Content Marketing',
                'Aplikacje mobilne i webowe',
                'E-commerce',
                'Landing Pages & Microsites',
              ] as $opt): ?>
                <label class="bm-checkitem"><input type="checkbox" class="bm-limit-3" name="spec[]" value="<?php echo esc_attr($opt); ?>"><span class="txt"><?php echo esc_html($opt); ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="bm-muted" style="margin-top:8px">Maksymalnie: 3.</div>
        </div>

        <!-- Doświadczenie -->
        <div class="bm-checkwrap" data-limit="3">
          <div class="bm-checkhead">
            <b>Doświadczenie w branżach (max 3)</b>
            <span class="bm-counter">0/3</span>
          </div>
          <div class="bm-checklist">
            <?php
              $same = [
                'Drukarnia Cyfrowa i Offsetowa',
                'Drukarnia Wielkoformatowa (Outdoor)',
                'Gadżety Reklamowe i Merch',
                'Materiały POS i Ekspozytory',
                'Opakowania i Etykiety',
                'Agencje Reklamowe / 360',
                'Branding & Identyfikacja',
                'Digital & UI Design',
                'Motion Design & Animacja',
                'Packaging & Product Design',
                'Marketing',
                'PR',
                'Prawo',
                'Social Media',
                'Strony internetowe',
                'TECH / AI',
                'Automatyzacja',
                'E-mail Marketing',
                'Płatne Kampanie PPC',
                'SEO & Content Marketing',
                'Aplikacje mobilne i webowe',
                'E-commerce',
                'Landing Pages & Microsites',
              ];
              foreach ($same as $opt):
            ?>
              <label class="bm-checkitem"><input type="checkbox" class="bm-limit-3" name="exp[]" value="<?php echo esc_attr($opt); ?>"><span class="txt"><?php echo esc_html($opt); ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="bm-greenbar" style="margin-top:10px">W Premium możesz wybrać do 6 branż.</div>
        </div>
      </div>

      <div class="bm-field" style="margin-top:14px">
        <label><input type="checkbox" id="bm_awards"> Nagradzani w konkursach</label>
      </div>

      <div class="bm-submit">
        <button type="button" id="bm_submit_fake">Zapisz dane i wyślij do akceptacji</button>
      </div>

      <div class="bm-muted" style="margin-top:10px">
        <b>Uwaga testowa:</b> ten shortcode to formularz testowy UI + pobranie danych. Zapis do bazy/CPT dodamy po Twoim teście pobierania.
      </div>
    </div>
  </div>

  <script>
  (function(){
    const root = document.getElementById('bm-form-root');
    if(!root) return;

    const ajaxUrl = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
    const nonce = "<?php echo esc_js($nonce); ?>";

    const nipInput = root.querySelector('#bm_nip');
    const fetchBtn  = root.querySelector('#bm_fetch_nip');
    const statusEl  = root.querySelector('#bm_nip_status');
    const badgesBox = root.querySelector('#bm-badges');

    const fields = {
      company_name: root.querySelector('#bm_company_name'),
      street:       root.querySelector('#bm_street'),
      postal_code:  root.querySelector('#bm_postal'),
      city:         root.querySelector('#bm_city'),
      regon:        root.querySelector('#bm_regon'),
    };

    function setBadges(badges){
      badgesBox.innerHTML = '';
      (badges||[]).forEach(b => {
        const el = document.createElement('span');
        el.className = 'bm-badge ' + (b.type || 'info');
        el.textContent = b.text || '';
        badgesBox.appendChild(el);
      });
      badgesBox.style.display = (badges && badges.length) ? 'flex' : 'none';
    }

    fetchBtn.addEventListener('click', async () => {
      const nip = (nipInput.value || '').replace(/\D+/g,'');
      if(nip.length < 10){
        statusEl.textContent = 'Wpisz poprawny NIP (10 cyfr).';
        setBadges([{type:'warn', text:'Wpisz poprawny NIP'}]);
        return;
      }

      statusEl.textContent = 'Pobieram dane z rejestrów…';
      setBadges([{type:'info', text:'Pobieranie danych…'}]);

      const fd = new FormData();
      fd.append('action', 'bm_lookup_company');
      fd.append('nonce', nonce);
      fd.append('nip', nip);

      try{
        const res = await fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' });
        const json = await res.json();

        if(!json || !json.success){
          const msg = (json && json.data && json.data.message) ? json.data.message : 'Nie udało się pobrać danych – uzupełnij ręcznie.';
          statusEl.textContent = msg;
          setBadges([{type:'warn', text:'Nie udało się pobrać danych'}, {type:'info', text:'Uzupełnij ręcznie'}]);
          return;
        }

        const payload = json.data || {};
        const data = payload.data || {};
        statusEl.textContent = 'Dane pobrane ✔ Możesz poprawić ręcznie, jeśli trzeba.';
        setBadges(payload.badges || [{type:'ok', text:'Dane pobrane'}]);

        if(fields.company_name) fields.company_name.value = data.company_name || '';
        if(fields.street)       fields.street.value = data.street || '';
        if(fields.postal_code)  fields.postal_code.value = data.postal_code || '';
        if(fields.city)         fields.city.value = data.city || '';
        if(fields.regon)        fields.regon.value = data.regon || '';

        // Dopisz dodatkowe informacje w badge (np. KRS, forma, źródło) jeśli są
        const extra = [];
        if(payload.source) extra.push({type:'ok', text:'Źródło: ' + payload.source});
        if(data.legal_form) extra.push({type:'info', text:data.legal_form});
        if(data.krs) extra.push({type:'info', text:'KRS: ' + data.krs});
        if(data.start_date) extra.push({type:'info', text:'Start: ' + data.start_date});
        if(data.status) extra.push({type:'info', text:'Status: ' + data.status});
        if(data.pkd && data.pkd.length) extra.push({type:'info', text:'PKD: ' + data.pkd.slice(0,3).join(', ') + (data.pkd.length>3?'…':'')});

        if(extra.length){
          setBadges((payload.badges || []).concat(extra));
        }

      } catch(e){
        statusEl.textContent = 'Błąd połączenia – uzupełnij ręcznie.';
        setBadges([{type:'warn', text:'Błąd połączenia'}, {type:'info', text:'Uzupełnij ręcznie'}]);
      }
    });

    // Limit 3 checkboxes per box (Spec + Exp)
    root.querySelectorAll('.bm-checkwrap').forEach(box => {
      const limit = parseInt(box.getAttribute('data-limit') || '3', 10);
      const counter = box.querySelector('.bm-counter');
      const cbs = Array.from(box.querySelectorAll('input.bm-limit-3'));

      const update = () => {
        const checked = cbs.filter(x => x.checked);
        const reached = checked.length >= limit;
        cbs.forEach(cb => { if(!cb.checked) cb.disabled = reached; });
        if(counter) counter.textContent = checked.length + '/' + limit;
      };

      cbs.forEach(cb => cb.addEventListener('change', update));
      update();
    });

  })();
  </script>

  <?php
  return ob_get_clean();
});
