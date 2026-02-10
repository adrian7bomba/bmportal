<?php
if (!defined('ABSPATH')) { exit; }

// Kontekst: jesteśmy w pętli WP_Query, więc get_the_ID() to portfolio
$portfolio_id = isset($portfolio_id) ? (int) $portfolio_id : (int) get_the_ID();
if (!$portfolio_id) { return; }

$title   = get_the_title($portfolio_id);
$content = apply_filters('the_content', get_post_field('post_content', $portfolio_id));

// Opis realizacji: skrót 300 znaków + rozwiń/zwiń
$desc_plain = trim(wp_strip_all_tags($content));
$desc_is_long = (function_exists('mb_strlen') ? mb_strlen($desc_plain) : strlen($desc_plain)) > 300;
$desc_short = $desc_is_long ? wp_html_excerpt($desc_plain, 300, '…') : $desc_plain;


$status  = get_post_meta($portfolio_id, '_bm_portfolio_status', true);
$url     = get_post_meta($portfolio_id, '_bm_portfolio_url', true);
$origin  = get_post_meta($portfolio_id, '_bm_portfolio_origin', true); // portal|external|none

// Status label
$status_label = '';
if ($status === 'completed') {
    $status_label = 'Zrealizowany u klienta';
} elseif ($status === 'concept') {
    $status_label = 'Koncepcyjny';
}

// Kategorie
$cats_text = '';
$terms = get_the_terms($portfolio_id, 'bm_portfolio_category');
if (!is_wp_error($terms) && !empty($terms)) {
    $names = wp_list_pluck($terms, 'name');
    $cats_text = implode(', ', array_map('sanitize_text_field', $names));
}

// Opinia / review
$review_id = (int) get_post_meta($portfolio_id, '_bm_portfolio_review_id', true);
$review_post = $review_id ? get_post($review_id) : null;
$has_review  = ($review_post && $review_post->post_type === 'bm_review' && $review_post->post_status !== 'trash');

$review_text = '';
if ($has_review) {
    $review_text = trim(wp_strip_all_tags(apply_filters('the_content', $review_post->post_content)));
}

// Badge opinii (3 warianty)
$badge_text  = 'Brak opinii';
$badge_class = 'bm-portfolio-badge-muted';
if ($has_review) {
    if ($origin === 'external') {
        $badge_text  = 'Opinia spoza portalu BrandManager';
        $badge_class = 'bm-portfolio-badge-muted';
    } else {
        // domyślnie portal
        $badge_text  = 'Opinia z portalu BrandManager ★';
        $badge_class = 'bm-portfolio-badge-portal';
    }
}

// Oceny (z opinii)
$rating_overall = $has_review ? (float) get_post_meta($review_id, '_bm_rating_overall', true) : 0;
$rating_quality = $has_review ? (int) get_post_meta($review_id, '_bm_rating_quality', true) : 0;
$rating_time    = $has_review ? (int) get_post_meta($review_id, '_bm_rating_timeliness', true) : 0;
$rating_reco    = $has_review ? (string) get_post_meta($review_id, '_bm_rating_recommend', true) : '';

$reco_label = '';
if ($rating_reco === 'yes') { $reco_label = 'Tak'; }
elseif ($rating_reco === 'no') { $reco_label = 'Nie'; }

?>
<div class="bm-portfolio-item">
  <div class="bm-portfolio-col bm-portfolio-left">

    <?php if (!empty($title)): ?>
      <div class="bm-portfolio-title"><?php echo esc_html($title); ?></div>
    <?php endif; ?>

    <div class="bm-portfolio-meta">

      <?php if ($status_label): ?>
        <div class="bm-portfolio-status-line <?php echo ($status === 'completed') ? 'bm-portfolio-status-line--completed' : ''; ?>">
          <span class="bm-portfolio-meta-label">Status:</span>
          <span class="bm-portfolio-status-value"><?php echo esc_html($status_label); ?></span>
        </div>
      <?php endif; ?>

      <?php if ($cats_text): ?>
        <div class="bm-portfolio-meta-row bm-portfolio-cats">
          <span class="bm-portfolio-meta-label">Kategoria:</span>
          <span class="bm-portfolio-meta-value"><?php echo esc_html($cats_text); ?></span>
        </div>
      <?php endif; ?>

      <div class="bm-portfolio-meta-row bm-portfolio-review-badge-row">
        <span class="bm-portfolio-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
      </div>

      <?php if ($has_review && $rating_overall > 0): ?>
        <div class="bm-portfolio-meta-row bm-portfolio-rating-row">
          <span class="bm-portfolio-meta-label">Ocena:</span>
          <span class="bm-portfolio-meta-value"><?php echo esc_html(number_format_i18n($rating_overall, 1)); ?>/5</span>
        </div>

        <div class="bm-portfolio-review-details-title">Szczegóły opinii</div>

        <div class="bm-portfolio-review-details">
          <?php if ($rating_quality > 0): ?>
            <div class="bm-portfolio-review-detail">Jakość: <?php echo esc_html($rating_quality); ?>/5</div>
          <?php endif; ?>
          <?php if ($rating_time > 0): ?>
            <div class="bm-portfolio-review-detail">Terminowość: <?php echo esc_html($rating_time); ?>/5</div>
          <?php endif; ?>
          <?php if ($reco_label !== ''): ?>
            <div class="bm-portfolio-review-detail">Polecam: <?php echo esc_html($reco_label); ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($has_review && $review_text !== ''): ?>
        <div class="bm-portfolio-review-text"><?php echo esc_html($review_text); ?></div>
      <?php endif; ?>

    </div>

  </div>

  <div class="bm-portfolio-col bm-portfolio-right">
    <?php if (!empty($content)): ?>
      <div class="bm-portfolio-desc" data-collapsible="1">
        <?php if ($desc_is_long): ?>
          <div class="bm-portfolio-desc-short"><?php echo esc_html($desc_short); ?></div>
          <div class="bm-portfolio-desc-full" style="display:none;"><?php echo $content; ?></div>
          <button type="button" class="bm-portfolio-desc-toggle" aria-expanded="false">Rozwiń</button>
        <?php else: ?>
          <div class="bm-portfolio-desc-full"><?php echo $content; ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="bm-portfolio-gallery">
      <?php echo do_shortcode('[bm_portfolio_gallery id="' . (int) $portfolio_id . '" lightbox="1"]'); ?>
    </div>

    <?php if (!empty($url)): ?>
      <div class="bm-portfolio-cta">
        <a class="bm-portfolio-button" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
          Zobacz szczegóły
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>
