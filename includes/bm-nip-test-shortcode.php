<?php
/**
 * BrandManager Portal – TEST ONLY
 * Shortcode: [bm_nip_test]
 *
 * Szybki test pobierania danych firmy po NIP:
 * - CEIDG: dane.biznes.gov.pl (wymaga tokena JWT w stałej BM_CEIDG_TOKEN)
 * - KRS: api-krs.ms.gov.pl (wymaga numeru KRS). Numer KRS pozyskujemy z MF Biała Lista (wl-api.mf.gov.pl).
 *
 * Ten plik:
 * - NIE zapisuje nic do bazy
 * - dodaje shortcode z formularzem testowym
 */

if ( ! defined('ABSPATH') ) { exit; }

add_action('wp_ajax_bm_nip_lookup', 'bm_nip_lookup_ajax');
add_action('wp_ajax_nopriv_bm_nip_lookup', 'bm_nip_lookup_ajax');

function bm_nip_lookup_ajax() {
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if ( ! wp_verify_nonce($nonce, 'bm_nip_test_nonce') ) {
    wp_send_json_error(['message' => 'Błędny token formularza. Odśwież stronę i spróbuj ponownie.']);
  }

  $nip = isset($_POST['nip']) ? preg_replace('/\D+/', '', (string)$_POST['nip']) : '';
  if ( strlen($nip) !== 10 ) {
    wp_send_json_error(['message' => 'Wpisz poprawny NIP (10 cyfr).']);
  }

  $cache_key = 'bm_nip_test_' . $nip;
  $cached = get_transient($cache_key);
  if ( $cached ) {
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
      'start_date'   => '',
      'pkd'          => [],
      'legal_form'   => '',
    ],
  ];

  // 1) CEIDG (token required)
  if ( defined('BM_CEIDG_TOKEN') && BM_CEIDG_TOKEN && BM_CEIDG_TOKEN !== 'WSTAW_TUTAJ_SWÓJ_TOKEN_JWT_Z_DANE.BIZNES.GOV.PL' ) {
    $ceidg = bm_ceidg_fetch_by_nip($nip, BM_CEIDG_TOKEN);
    if ( ! is_wp_error($ceidg) && ! empty($ceidg['ok']) ) {
      $payload['source'] = 'CEIDG';
      $payload['data'] = array_merge($payload['data'], $ceidg['data']);
      $payload['badges'][] = ['type' => 'ok', 'text' => 'Zweryfikowany w CEIDG'];
      if ( ! empty($payload['data']['status']) ) {
        $payload['badges'][] = ['type' => 'info', 'text' => 'Status: ' . $payload['data']['status']];
      }
      if ( ! empty($payload['data']['start_date']) ) {
        $payload['badges'][] = ['type' => 'info', 'text' => 'Start: ' . $payload['data']['start_date']];
      }
      if ( ! empty($payload['data']['pkd']) ) {
        $payload['badges'][] = ['type' => 'info', 'text' => 'PKD: ' . implode(', ', array_slice($payload['data']['pkd'], 0, 3)) . (count($payload['data']['pkd']) > 3 ? '…' : '')];
      }

      set_transient($cache_key, $payload, 12 * HOUR_IN_SECONDS);
      wp_send_json_success($payload);
    }
  }

  // 2) MF VAT Whitelist (works by NIP, helps get KRS)
  $mf = bm_mf_whitelist_fetch_by_nip($nip);
  if ( ! is_wp_error($mf) && ! empty($mf['ok']) ) {
    $payload['source'] = 'MF';
    $payload['data'] = array_merge($payload['data'], $mf['data']);
    $payload['badges'][] = ['type' => 'ok', 'text' => 'Dane z MF (Biała lista VAT)'];

    // If KRS known, try official KRS API for confirmation
    if ( ! empty($payload['data']['krs']) ) {
      $krs = bm_krs_fetch_odpis_aktualny($payload['data']['krs']);
      if ( ! is_wp_error($krs) && ! empty($krs['ok']) ) {
        $payload['source'] = 'KRS';
        $payload['data'] = array_merge($payload['data'], $krs['data']);
        $payload['badges'][] = ['type' => 'ok', 'text' => 'Zweryfikowany w KRS'];
      }
    }

    if ( ! empty($payload['data']['krs']) ) {
      $payload['badges'][] = ['type' => 'info', 'text' => 'KRS: ' . $payload['data']['krs']];
    }
    if ( ! empty($payload['data']['regon']) ) {
      $payload['badges'][] = ['type' => 'info', 'text' => 'REGON: ' . $payload['data']['regon']];
    }

    set_transient($cache_key, $payload, 12 * HOUR_IN_SECONDS);
    wp_send_json_success($payload);
  }

  wp_send_json_error([
    'message' => 'Nie udało się pobrać danych z rejestrów w tym momencie. Uzupełnij dane ręcznie.',
    'hint'    => ( defined('BM_CEIDG_TOKEN') && BM_CEIDG_TOKEN && BM_CEIDG_TOKEN !== 'WSTAW_TUTAJ_SWÓJ_TOKEN_JWT_Z_DANE.BIZNES.GOV.PL' )
      ? 'CEIDG nie zwróciło danych. MF/KRS również nie zwróciły wyniku dla tego NIP.'
      : 'Brak prawdziwego BM_CEIDG_TOKEN (CEIDG wymaga tokena). Wstaw token albo testuj spółki VAT z MF.'
  ]);
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

  if ( is_wp_error($resp) ) return $resp;

  $code = (int) wp_remote_retrieve_response_code($resp);
  if ( $code !== 200 ) {
    return new WP_Error('bm_ceidg_http', 'CEIDG HTTP ' . $code);
  }

  $json = json_decode(wp_remote_retrieve_body($resp), true);
  if ( ! is_array($json) ) {
    return new WP_Error('bm_ceidg_json', 'CEIDG: niepoprawny JSON');
  }

  $firma = null;
  if ( ! empty($json['firmy']) && is_array($json['firmy']) ) {
    $firma = $json['firmy'][0] ?? null;
  } elseif ( isset($json[0]) ) {
    $firma = $json[0];
  }

  if ( ! is_array($firma) ) {
    return ['ok' => false];
  }

  $name   = $firma['nazwa'] ?? $firma['nazwaFirmy'] ?? $firma['firma'] ?? '';
  $regon  = $firma['regon'] ?? '';
  $status = $firma['status'] ?? $firma['statusDzialalnosci'] ?? '';
  $start  = $firma['dataRozpoczecia'] ?? $firma['dataRozpoczeciaDzialalnosci'] ?? '';

  $addr   = $firma['adresDzialalnosci'] ?? $firma['adres'] ?? [];
  $ulica  = $addr['ulica'] ?? '';
  $nr     = $addr['nrDomu'] ?? '';
  $lok    = $addr['nrLokalu'] ?? '';
  $kod    = $addr['kodPocztowy'] ?? '';
  $miasto = $addr['miejscowosc'] ?? '';

  $street = trim($ulica . ' ' . $nr . ($lok ? '/' . $lok : ''));

  $pkd = [];
  if ( ! empty($firma['pkd']) && is_array($firma['pkd']) ) {
    foreach ($firma['pkd'] as $p) {
      $codeP = $p['kod'] ?? $p['pkd'] ?? null;
      if ($codeP) $pkd[] = (string)$codeP;
    }
  } elseif ( ! empty($firma['kodyPKD']) && is_array($firma['kodyPKD']) ) {
    $pkd = array_values(array_filter($firma['kodyPKD']));
  }

  return [
    'ok' => true,
    'data' => [
      'company_name' => (string)$name,
      'regon'        => (string)$regon,
      'status'       => (string)$status,
      'start_date'   => (string)$start,
      'street'       => (string)$street,
      'postal_code'  => (string)$kod,
      'city'         => (string)$miasto,
      'pkd'          => $pkd,
      'legal_form'   => 'JDG (CEIDG)',
    ],
  ];
}

function bm_mf_whitelist_fetch_by_nip(string $nip) {
  $date = gmdate('Y-m-d');
  $url = 'https://wl-api.mf.gov.pl/api/search/nip/' . rawurlencode($nip) . '?date=' . rawurlencode($date);

  $resp = wp_remote_get($url, [
    'timeout' => 10,
    'headers' => [
      'Accept' => 'application/json',
    ],
  ]);

  if ( is_wp_error($resp) ) return $resp;

  $code = (int) wp_remote_retrieve_response_code($resp);
  if ( $code !== 200 ) {
    return new WP_Error('bm_mf_http', 'MF HTTP ' . $code);
  }

  $json = json_decode(wp_remote_retrieve_body($resp), true);
  if ( ! is_array($json) ) {
    return new WP_Error('bm_mf_json', 'MF: niepoprawny JSON');
  }

  $subject = $json['result']['subject'] ?? null;
  if ( ! is_array($subject) ) {
    return ['ok' => false];
  }

  $name = $subject['name'] ?? '';
  $regon = $subject['regon'] ?? '';
  $krs = $subject['krs'] ?? '';
  $status = ! empty($subject['statusVat']) ? ('VAT: ' . $subject['statusVat']) : '';

  $address_line = $subject['workingAddress'] ?? $subject['residenceAddress'] ?? '';
  $street = '';
  $postal = '';
  $city = '';

  if ( $address_line ) {
    // naive split: try find postal code in Polish format 00-000
    if ( preg_match('/(\d{2}-\d{3})\s+([^,]+)$/u', $address_line, $m) ) {
      $postal = trim($m[1]);
      $city = trim($m[2]);
      $street = trim(preg_replace('/\s*\d{2}-\d{3}\s+[^,]+$/u', '', $address_line));
      $street = trim($street, " ,\t\n\r\0\x0B");
    } else {
      $street = trim($address_line);
    }
  }

  return [
    'ok' => true,
    'data' => [
      'company_name' => (string)$name,
      'regon'        => (string)$regon,
      'krs'          => (string)$krs,
      'status'       => (string)$status,
      'street'       => (string)$street,
      'postal_code'  => (string)$postal,
      'city'         => (string)$city,
      'legal_form'   => $krs ? 'Podmiot z KRS' : 'Podmiot (MF)',
    ],
  ];
}

function bm_krs_fetch_odpis_aktualny(string $krs) {
  $krs = preg_replace('/\D+/', '', $krs);
  if ( strlen($krs) < 5 ) return ['ok' => false];

  // Official API base (documented in OpenAPI KRS)
  $url = 'https://api-krs.ms.gov.pl/api/krs/OdpisAktualny/' . rawurlencode($krs) . '?rejestr=P&format=json';

  $resp = wp_remote_get($url, [
    'timeout' => 12,
    'headers' => [ 'Accept' => 'application/json' ],
  ]);

  if ( is_wp_error($resp) ) return $resp;
  if ( (int) wp_remote_retrieve_response_code($resp) !== 200 ) {
    return ['ok' => false];
  }

  $json = json_decode(wp_remote_retrieve_body($resp), true);
  if ( ! is_array($json) ) return ['ok' => false];

  // Map: keep it defensive – we only need a few fields
  $name = $json['odpis']['dane']['dzial1']['danePodmiotu']['nazwa'] ?? $json['nazwa'] ?? '';
  $regon = $json['odpis']['dane']['dzial1']['danePodmiotu']['regon'] ?? '';

  $addr = $json['odpis']['dane']['dzial1']['siedzibaIAdres']['adres'] ?? [];
  $street = '';
  $postal = '';
  $city   = '';
  if ( is_array($addr) ) {
    $street = trim(($addr['ulica'] ?? '') . ' ' . ($addr['nrDomu'] ?? '') . (($addr['nrLokalu'] ?? '') ? '/' . $addr['nrLokalu'] : ''));
    $postal = $addr['kodPocztowy'] ?? '';
    $city   = $addr['miejscowosc'] ?? '';
  }

  return [
    'ok' => true,
    'data' => [
      'company_name' => (string)$name,
      'regon'        => (string)$regon,
      'street'       => $street ?: '',
      'postal_code'  => (string)$postal,
      'city'         => (string)$city,
      'krs'          => (string)$krs,
      'legal_form'   => 'Spółka (KRS)',
    ],
  ];
}

add_shortcode('bm_nip_test', function() {
  $nonce = wp_create_nonce('bm_nip_test_nonce');

  ob_start();
  ?>
  <style>
    .bmNipWrap{max-width:1000px;margin:0 auto;padding:18px;border-radius:16px;background:#fff;box-shadow:0 12px 35px rgba(0,0,0,.06);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial}
    .bmNipTitle{font-size:18px;font-weight:700;color:#111827;margin:0 0 14px}
    .bmGrid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .bmField label{display:block;font-size:12px;font-weight:700;color:#374151;margin:0 0 6px}
    .bmField input[type="text"],.bmField input[type="url"],.bmField input[type="email"],.bmField input[type="tel"],.bmField select,.bmField textarea{width:100%;padding:12px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:14px;outline:none}
    .bmField input:focus,.bmField select:focus,.bmField textarea:focus{border-color:#84cc16;box-shadow:0 0 0 3px rgba(132,204,22,.18)}
    .bmInline{display:flex;gap:10px;align-items:center}
    .bmBtn{white-space:nowrap;border:0;border-radius:10px;padding:12px 14px;font-weight:700;cursor:pointer;background:#111827;color:#fff}
    .bmBtn:hover{opacity:.9}
    .bmMuted{font-size:12px;color:#6b7280;margin-top:6px}
    .bmBadges{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0}
    .bmBadge{font-size:12px;font-weight:700;padding:7px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#f9fafb;color:#111827}
    .bmBadge.ok{background:#dcfce7;border-color:#86efac}
    .bmBadge.info{background:#eef2ff;border-color:#c7d2fe}
    .bmBadge.warn{background:#fef9c3;border-color:#fde047}
    .bmSection{margin-top:18px}
    .bmSection h4{font-size:15px;margin:0 0 12px;color:#111827}
    .bmGreen{margin:10px 0;background:#84cc16;color:#0f172a;padding:10px 12px;border-radius:10px;font-weight:700;font-size:12px}

    /* Checkbox columns – poprawione */
    .bmChecksGrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .bmCheckWrap{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff}
    .bmCheckHead{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .bmCheckHead b{font-size:13px;color:#111827}
    .bmCheckHead span{font-size:12px;color:#6b7280}
    .bmCheckList{max-height:360px;overflow:auto;padding-right:6px}
    .bmCheckItem{display:flex;align-items:flex-start;gap:10px;padding:6px 6px;border-radius:10px}
    .bmCheckItem:hover{background:#f8fafc}
    .bmCheckItem input{margin-top:2px}
    .bmCheckItem .txt{font-size:13px;color:#111827;line-height:1.25}
    .bmCheckGroup{margin-top:10px;padding-top:10px;border-top:1px dashed #e5e7eb}
    .bmCheckGroup .gtitle{font-size:12px;font-weight:800;color:#374151;margin:0 0 6px}

    .bmSubmit{margin-top:18px;display:flex;justify-content:flex-start}
    .bmSubmit button{background:#a00055;color:#fff;border:0;border-radius:12px;padding:14px 18px;font-weight:800;cursor:pointer}
    .bmSubmit button:hover{background:#820046}

    @media(max-width:820px){.bmGrid2{grid-template-columns:1fr}.bmChecksGrid{grid-template-columns:1fr}}
  </style>

  <div class="bmNipWrap" id="bm-nip-test-root">
    <div class="bmNipTitle">Formularz testowy – pobieranie danych po NIP</div>

    <div class="bmBadges" id="bm-nip-badges" style="display:none"></div>

    <div class="bmGrid2">
      <div class="bmField">
        <label>Nazwa firmy <span style="color:#dc2626">*</span></label>
        <input type="text" id="bm_company_name" placeholder="Nazwa firmy">
      </div>

      <div class="bmField">
        <label>NIP <span style="color:#dc2626">*</span></label>
        <div class="bmInline">
          <input type="text" id="bm_nip" placeholder="Wpisz NIP">
          <button class="bmBtn" type="button" id="bm_fetch">Zaczytaj</button>
        </div>
        <div class="bmMuted" id="bm_status"></div>
      </div>

      <div class="bmField">
        <label>Ulica i numer <span style="color:#dc2626">*</span></label>
        <input type="text" id="bm_street">
      </div>

      <div class="bmField">
        <label>Kod pocztowy <span style="color:#dc2626">*</span></label>
        <input type="text" id="bm_postal">
      </div>

      <div class="bmField">
        <label>Miejscowość <span style="color:#dc2626">*</span></label>
        <input type="text" id="bm_city">
      </div>

      <div class="bmField">
        <label>REGON (z rejestru)</label>
        <input type="text" id="bm_regon" readonly>
        <div class="bmMuted">Uzupełnia się automatycznie po zaczytaniu danych.</div>
      </div>

      <div class="bmField">
        <label>Logo firmy</label>
        <input type="file" id="bm_logo" accept=".jpg,.jpeg,.png,.webp">
        <div class="bmMuted">Maksymalny rozmiar pliku: 128KB. Format: JPG/PNG/WEBP.</div>
      </div>

      <div class="bmField">
        <label>Zdjęcie firmy</label>
        <input type="file" id="bm_photo" accept=".jpg,.jpeg,.png,.webp">
        <div class="bmMuted">Najlepiej wykadruj zdjęcie tak, aby główna treść była na środku. Maksymalny rozmiar: 128KB.</div>
      </div>
    </div>

    <div class="bmSection">
      <h4>Profil Wykonawcy</h4>

      <div class="bmGrid2">
        <div class="bmField">
          <label>Strona WWW</label>
          <input type="url" id="bm_www" placeholder="https://…">
        </div>

        <div class="bmField">
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

      <div class="bmField" style="margin-top:14px">
        <label>Opis firmy</label>
        <div class="bmGreen">W wersji darmowej limit opisu to 200 znaków. W pakiecie Premium: 1500 znaków.</div>
        <textarea id="bm_desc" rows="6"></textarea>
      </div>

      <div class="bmGrid2" style="margin-top:14px">
        <div class="bmField"><label>Imię i nazwisko opiekuna klienta</label><input type="text" id="bm_contact_name"></div>
        <div class="bmField"><label>Stanowisko opiekuna klienta</label><input type="text" id="bm_contact_role"></div>
        <div class="bmField"><label>Adres e-mail</label><input type="email" id="bm_email"></div>
        <div class="bmField"><label>Telefon kontaktowy</label><input type="tel" id="bm_phone"></div>
      </div>

      <div class="bmChecksGrid" style="margin-top:14px">
        <div class="bmCheckWrap" data-limit="3">
          <div class="bmCheckHead"><b>Specjalizacja (max 3)</b><span class="bmCounter">0/3</span></div>
          <div class="bmCheckList">
            <div class="bmCheckGroup"><div class="gtitle">Druk & POS</div>
              <?php foreach ([
                'Drukarnia Cyfrowa i Offsetowa',
                'Drukarnia Wielkoformatowa (Outdoor)',
                'Gadżety Reklamowe i Merch',
                'Materiały POS i Ekspozytory',
                'Opakowania i Etykiety',
              ] as $opt): ?>
              <label class="bmCheckItem"><input type="checkbox" class="bmLimit3" name="spec[]" value="<?php echo esc_attr($opt); ?>"><span class="txt"><?php echo esc_html($opt); ?></span></label>
              <?php endforeach; ?>
            </div>

            <div class="bmCheckGroup"><div class="gtitle">Kreacja & Komunikacja</div>
              <?php foreach ([
                'Agencje Reklamowe / 360',
                'Branding & Identyfikacja',
                'Digital & UI Design',
                'Motion Design & Animacja',
                'Packaging & Product Design',
              ] as $opt): ?>
              <label class="bmCheckItem"><input type="checkbox" class="bmLimit3" name="spec[]" value="<?php echo esc_attr($opt); ?>"><span class="txt"><?php echo esc_html($opt); ?></span></label>
              <?php endforeach; ?>
            </div>

            <div class="bmCheckGroup"><div class="gtitle">Marketing / Tech</div>
              <?php foreach ([
                'Marketing','PR','Prawo','Social Media','Strony internetowe','TECH / AI','Automatyzacja','E-mail Marketing','Płatne Kampanie PPC','SEO & Content Marketing','Aplikacje mobilne i webowe','E-commerce','Landing Pages & Microsites'
              ] as $opt): ?>
              <label class="bmCheckItem"><input type="checkbox" class="bmLimit3" name="spec[]" value="<?php echo esc_attr($opt); ?>"><span class="txt"><?php echo esc_html($opt); ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="bmMuted" style="margin-top:8px">Maksymalnie: 3.</div>
        </div>

        <div class="bmCheckWrap" data-limit="3">
          <div class="bmCheckHead"><b>Doświadczenie w branżach (max 3)</b><span class="bmCounter">0/3</span></div>
          <div class="bmCheckList">
            <?php foreach ([
              'Drukarnia Cyfrowa i Offsetowa','Drukarnia Wielkoformatowa (Outdoor)','Gadżety Reklamowe i Merch','Materiały POS i Ekspozytory','Opakowania i Etykiety',
              'Agencje Reklamowe / 360','Branding & Identyfikacja','Digital & UI Design','Motion Design & Animacja','Packaging & Product Design',
              'Marketing','PR','Prawo','Social Media','Strony internetowe','TECH / AI','Automatyzacja','E-mail Marketing','Płatne Kampanie PPC','SEO & Content Marketing','Aplikacje mobilne i webowe','E-commerce','Landing Pages & Microsites'
            ] as $opt): ?>
              <label class="bmCheckItem"><input type="checkbox" class="bmLimit3" name="exp[]" value="<?php echo esc_attr($opt); ?>"><span class="txt"><?php echo esc_html($opt); ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="bmGreen" style="margin-top:10px">W Premium możesz wybrać do 6 branż.</div>
        </div>
      </div>

      <div class="bmField" style="margin-top:14px">
        <label><input type="checkbox" id="bm_awards"> Nagradzani w konkursach</label>
      </div>

      <div class="bmSubmit">
        <button type="button" disabled title="Test UI – zapis dodamy po weryfikacji">Zapisz dane i wyślij do akceptacji</button>
      </div>

      <div class="bmMuted" style="margin-top:10px"><b>Test:</b> wpisz NIP i kliknij „Zaczytaj”. Jeśli rejestry nie odpowiedzą, możesz wpisać dane ręcznie.</div>
    </div>
  </div>

  <script>
  (function(){
    const root = document.getElementById('bm-nip-test-root');
    if(!root) return;

    const ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
    const nonce   = <?php echo json_encode($nonce); ?>;

    const nipInput = root.querySelector('#bm_nip');
    const btn = root.querySelector('#bm_fetch');
    const status = root.querySelector('#bm_status');
    const badges = root.querySelector('#bm-nip-badges');

    const fields = {
      company_name: root.querySelector('#bm_company_name'),
      street: root.querySelector('#bm_street'),
      postal: root.querySelector('#bm_postal'),
      city: root.querySelector('#bm_city'),
      regon: root.querySelector('#bm_regon')
    };

    function setBadges(list){
      badges.innerHTML = '';
      (list||[]).forEach(b => {
        const el = document.createElement('span');
        el.className = 'bmBadge ' + (b.type || 'info');
        el.textContent = b.text || '';
        badges.appendChild(el);
      });
      badges.style.display = (list && list.length) ? 'flex' : 'none';
    }

    btn.addEventListener('click', async () => {
      const nip = (nipInput.value || '').replace(/\D+/g,'');
      if(nip.length !== 10){
        status.textContent = 'Wpisz poprawny NIP (10 cyfr).';
        setBadges([{type:'warn', text:'Wpisz poprawny NIP'}]);
        return;
      }

      status.textContent = 'Pobieram dane…';
      setBadges([{type:'info', text:'Pobieranie danych…'}]);

      const fd = new FormData();
      fd.append('action', 'bm_nip_lookup');
      fd.append('nonce', nonce);
      fd.append('nip', nip);

      try{
        const res = await fetch(ajaxUrl, {method:'POST', body: fd, credentials:'same-origin'});
        const json = await res.json();

        if(!json || !json.success){
          const msg = (json && json.data && json.data.message) ? json.data.message : 'Nie udało się pobrać danych – uzupełnij ręcznie.';
          status.textContent = msg;
          setBadges([{type:'warn', text:'Brak danych z rejestrów'},{type:'info', text:'Uzupełnij ręcznie'}]);
          return;
        }

        const payload = json.data || {};
        const data = payload.data || {};

        status.textContent = 'Dane pobrane ✔ Możesz je poprawić ręcznie, jeśli trzeba.';
        setBadges(payload.badges || [{type:'ok', text:'Dane pobrane'}]);

        fields.company_name.value = data.company_name || '';
        fields.street.value = data.street || '';
        fields.postal.value = data.postal_code || '';
        fields.city.value = data.city || '';
        fields.regon.value = data.regon || '';

        // Dorzuć część informacji jako badge
        const extra = [];
        if(payload.source) extra.push({type:'ok', text:'Źródło: ' + payload.source});
        if(data.legal_form) extra.push({type:'info', text:data.legal_form});
        if(data.krs) extra.push({type:'info', text:'KRS: ' + data.krs});
        if(data.status) extra.push({type:'info', text:'Status: ' + data.status});
        if(data.start_date) extra.push({type:'info', text:'Start: ' + data.start_date});
        if(data.pkd && data.pkd.length) extra.push({type:'info', text:'PKD: ' + data.pkd.slice(0,3).join(', ') + (data.pkd.length>3 ? '…' : '')});
        if(extra.length) setBadges((payload.badges||[]).concat(extra));

      }catch(e){
        status.textContent = 'Błąd połączenia – uzupełnij ręcznie.';
        setBadges([{type:'warn', text:'Błąd połączenia'},{type:'info', text:'Uzupełnij ręcznie'}]);
      }
    });

    // Limit 3 checkboxes per box
    root.querySelectorAll('.bmCheckWrap').forEach(box => {
      const limit = parseInt(box.getAttribute('data-limit')||'3',10);
      const counter = box.querySelector('.bmCounter');
      const cbs = Array.from(box.querySelectorAll('input.bmLimit3'));

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
