<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* Wygląd filtrów (Filter Everything) – rozwijanie sekcji */

add_action('wp_footer', function(){
    ?>
    <script>
    function initFilterToggles(){
        document.querySelectorAll('.wpc-filter-header').forEach(function(header){
            if (!header.dataset.listenerAttached) {
                header.addEventListener('click', function(){
                    header.parentElement.classList.toggle('active');
                });
                header.dataset.listenerAttached = "true";
            }
        });
    }

    document.addEventListener('DOMContentLoaded', initFilterToggles);
    document.addEventListener('wpcAjaxFiltersUpdated', initFilterToggles);
    </script>
    <?php
});

/* Ukryj powiazany_dostawca na froncie Moje konto */

add_action('wp_head', function(){
    if (function_exists('is_account_page') && is_account_page()) {
        echo '<style>
            .acf-field[data-name="powiazany_dostawca"] {
                display:none !important;
            }
        </style>';
    }
});
