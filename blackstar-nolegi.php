<?php
/**
 * Plugin Name: Black Star Noleggi
 * Description: Gestione noleggi audio/video/luci per Black Star Service SRL (bozze, correlati, clonazione).
 * Version: 2.0.1
 * Author: Codex + Black Star
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) exit;

define('BSN_VERSION', '2.0.1');
define('BSN_PATH', plugin_dir_path(__FILE__));
define('BSN_URL', plugin_dir_url(__FILE__));

require_once BSN_PATH . 'includes/dimension-calculator.php';
require_once BSN_PATH . 'includes/quantity-discounts.php';

function bsn_get_public_product_meta_defaults() {
    return [
        'gallery_urls'           => '',
        'video_urls'             => '',
        'specifiche_tecniche'    => '',
        // Input editoriale per clienti, OpenClaw/Cerebro e futuro configuratore eventi.
        'utilizzo_consigliato'   => '',
        'faq'                    => '',
        'consigliati_commerciali'=> '',
        'correlati_commerciali'  => '',
        'badge_trasporto'        => '',
        'override_disponibilita' => '',
        'tariffa_label'          => '',
        'dimension_calculator_enabled' => '',
        'dimension_calculation_type'   => 'standard',
        'dimension_module_width_cm'    => '',
        'dimension_module_height_cm'   => '',
        'dimension_presets'            => '',
        'dimension_customer_note'      => '',
        'sottotitolo_catalogo'   => '',
        'alias_ricerca'          => '',
        'larghezza_cm'           => '',
        'altezza_cm'             => '',
        'profondita_cm'          => '',
        'peso_kg'                => '',
        'veicolo_minimo'         => '',
        'sede_operativa'         => 'Brescia',
        'disclaimer_preventivo'  => '',
        'consigliati_ids'        => '', // JSON array di ID prodotti bs_prodotto
        'downloads'              => '', // JSON array di {titolo, url, tipo}
    ];
}

function bsn_get_public_product_meta($post_id) {
    $defaults = bsn_get_public_product_meta_defaults();
    $meta = [];

    foreach ($defaults as $key => $default) {
        $value = get_post_meta($post_id, '_bsn_' . $key, true);
        $meta[$key] = is_string($value) ? $value : $default;
    }

    return $meta;
}

function bsn_get_public_product_tariff_label($post_id) {
    $meta = bsn_get_public_product_meta($post_id);
    $label = trim((string) ($meta['tariffa_label'] ?? ''));

    return $label !== '' ? $label : 'Tariffa 1 giorno';
}

function bsn_get_categoria_prodotto_image_url($term_id, $size = 'large') {
    $image_id = absint(get_term_meta($term_id, '_bsn_categoria_immagine_id', true));
    if (!$image_id) {
        return '';
    }

    $image_url = wp_get_attachment_image_url($image_id, $size);
    return $image_url ? $image_url : '';
}

function bsn_get_categoria_prodotto_meta($term_id) {
    $term_id = absint($term_id);
    $image_id = absint(get_term_meta($term_id, '_bsn_categoria_immagine_id', true));

    return [
        'image_id'   => $image_id,
        'image_url'  => $image_id ? bsn_get_categoria_prodotto_image_url($term_id, 'large') : '',
        'sottotitolo'=> (string) get_term_meta($term_id, '_bsn_categoria_sottotitolo', true),
        'ordine'     => (int) get_term_meta($term_id, '_bsn_categoria_ordine', true),
    ];
}

function bsn_sort_categoria_prodotto_terms_by_order($terms) {
    if (empty($terms) || is_wp_error($terms)) {
        return [];
    }

    usort($terms, function ($a, $b) {
        $a_order = isset($a->term_id) ? (int) get_term_meta($a->term_id, '_bsn_categoria_ordine', true) : 0;
        $b_order = isset($b->term_id) ? (int) get_term_meta($b->term_id, '_bsn_categoria_ordine', true) : 0;

        if ($a_order > 0 && $b_order > 0 && $a_order !== $b_order) {
            return $a_order <=> $b_order;
        }

        if ($a_order > 0 && $b_order <= 0) {
            return -1;
        }

        if ($a_order <= 0 && $b_order > 0) {
            return 1;
        }

        return strcasecmp($a->name ?? '', $b->name ?? '');
    });

    return $terms;
}

function bsn_validate_public_rental_dates($data_ritiro, $data_riconsegna) {
    $data_ritiro = sanitize_text_field((string) $data_ritiro);
    $data_riconsegna = sanitize_text_field((string) $data_riconsegna);

    if ($data_ritiro === '' && $data_riconsegna === '') {
        return [
            'valid' => false,
            'empty' => true,
            'message' => '',
            'data_ritiro' => '',
            'data_riconsegna' => '',
        ];
    }

    if ($data_ritiro === '' || $data_riconsegna === '') {
        return [
            'valid' => false,
            'empty' => false,
            'message' => 'Per verificare disponibilità e prezzi devi inserire sia data ritiro sia data riconsegna.',
            'data_ritiro' => $data_ritiro,
            'data_riconsegna' => $data_riconsegna,
        ];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ritiro) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_riconsegna)) {
        return [
            'valid' => false,
            'empty' => false,
            'message' => 'Il formato delle date non è valido.',
            'data_ritiro' => $data_ritiro,
            'data_riconsegna' => $data_riconsegna,
        ];
    }

    if (function_exists('bsn_is_invalid_date_range') && bsn_is_invalid_date_range($data_ritiro, $data_riconsegna)) {
        return [
            'valid' => false,
            'empty' => false,
            'message' => 'La data di riconsegna non può essere precedente alla data di ritiro.',
            'data_ritiro' => $data_ritiro,
            'data_riconsegna' => $data_riconsegna,
        ];
    }

    return [
        'valid' => true,
        'empty' => false,
        'message' => '',
        'data_ritiro' => $data_ritiro,
        'data_riconsegna' => $data_riconsegna,
    ];
}

function bsn_get_current_public_rental_dates() {
    $query_ritiro = isset($_GET['data_ritiro']) ? sanitize_text_field(wp_unslash($_GET['data_ritiro'])) : '';
    $query_riconsegna = isset($_GET['data_riconsegna']) ? sanitize_text_field(wp_unslash($_GET['data_riconsegna'])) : '';
    $query_validation = bsn_validate_public_rental_dates($query_ritiro, $query_riconsegna);

    if (!empty($query_validation['valid'])) {
        return $query_validation;
    }

    $cart = function_exists('bsn_get_quote_cart') ? bsn_get_quote_cart() : ['dates' => []];
    $cart_ritiro = (string) ($cart['dates']['data_ritiro'] ?? '');
    $cart_riconsegna = (string) ($cart['dates']['data_riconsegna'] ?? '');
    $cart_validation = bsn_validate_public_rental_dates($cart_ritiro, $cart_riconsegna);

    if (!empty($cart_validation['valid'])) {
        return $cart_validation;
    }

    return $query_validation;
}

function bsn_set_public_rental_dates($data_ritiro, $data_riconsegna) {
    $validation = bsn_validate_public_rental_dates($data_ritiro, $data_riconsegna);
    if (empty($validation['valid']) || !function_exists('bsn_get_quote_cart') || !function_exists('bsn_set_quote_cart')) {
        return false;
    }

    $cart = bsn_get_quote_cart();
    $cart['dates'] = [
        'data_ritiro' => $validation['data_ritiro'],
        'data_riconsegna' => $validation['data_riconsegna'],
    ];

    return bsn_set_quote_cart($cart);
}

function bsn_clear_public_rental_dates() {
    if (!function_exists('bsn_get_quote_cart') || !function_exists('bsn_set_quote_cart')) {
        return false;
    }

    $cart = bsn_get_quote_cart();
    $cart['dates'] = [
        'data_ritiro' => '',
        'data_riconsegna' => '',
    ];

    return bsn_set_quote_cart($cart);
}

function bsn_append_public_rental_dates_to_url($url, $dates = null) {
    if (!is_array($dates)) {
        $dates = bsn_get_current_public_rental_dates();
    }

    if (empty($dates['valid'])) {
        return $url;
    }

    return add_query_arg(
        [
            'data_ritiro' => $dates['data_ritiro'],
            'data_riconsegna' => $dates['data_riconsegna'],
        ],
        $url
    );
}

function bsn_render_public_rental_dates_bar($args = []) {
    $args = wp_parse_args($args, [
        'compact' => false,
    ]);
    $dates = bsn_get_current_public_rental_dates();
    $current_url = remove_query_arg(['data_ritiro', 'data_riconsegna', 'bsn_clear_period']);
    $clear_url = add_query_arg('bsn_clear_period', '1', $current_url);
    $ritiro = (string) ($dates['data_ritiro'] ?? '');
    $riconsegna = (string) ($dates['data_riconsegna'] ?? '');
    $periodo = '';

    if (!empty($dates['valid'])) {
        $periodo = bsn_format_date_it($ritiro) . ' - ' . bsn_format_date_it($riconsegna);
    }

    ob_start();
    ?>
    <section class="<?php echo esc_attr('bsn-public-card bsn-rental-dates-bar' . (!empty($args['compact']) ? ' bsn-rental-dates-bar-compact' : '')); ?>">
        <div class="bsn-rental-dates-copy">
            <p class="bsn-rental-dates-eyebrow">Periodo noleggio</p>
            <h2 class="bsn-rental-dates-title">Verifica disponibilità per il tuo evento</h2>
            <p class="bsn-rental-dates-text">Inserisci data ritiro e data riconsegna: aggiorneremo disponibilità e prezzi dei prodotti in base al periodo scelto.</p>
            <?php if ($periodo !== '') : ?>
                <p class="bsn-rental-dates-selected">Periodo selezionato: <strong><?php echo esc_html($periodo); ?></strong></p>
            <?php endif; ?>
            <?php if (!empty($dates['message'])) : ?>
                <p class="bsn-rental-dates-error"><?php echo esc_html($dates['message']); ?></p>
            <?php endif; ?>
        </div>
        <form class="bsn-rental-dates-form" id="bsn-rental-dates-form" method="get" action="<?php echo esc_url($current_url); ?>">
            <label class="bsn-rental-date-field">
                <span>Data ritiro</span>
                <div class="bsn-public-date-shell" data-bsn-rental-date-shell="1">
                    <div class="bsn-public-date-display" data-bsn-rental-date-display="1">gg-mm-aaaa</div>
                    <input type="date" class="bsn-public-date-native" name="data_ritiro" value="<?php echo esc_attr($ritiro); ?>" pattern="\d{4}-\d{2}-\d{2}">
                </div>
            </label>
            <label class="bsn-rental-date-field">
                <span>Data riconsegna</span>
                <div class="bsn-public-date-shell" data-bsn-rental-date-shell="1">
                    <div class="bsn-public-date-display" data-bsn-rental-date-display="1">gg-mm-aaaa</div>
                    <input type="date" class="bsn-public-date-native" name="data_riconsegna" value="<?php echo esc_attr($riconsegna); ?>" pattern="\d{4}-\d{2}-\d{2}">
                </div>
            </label>
            <div class="bsn-rental-dates-actions">
                <button type="submit" class="bsn-public-btn">Verifica disponibilità</button>
                <?php if ($periodo !== '') : ?>
                    <a class="bsn-public-btn bsn-public-btn-ghost bsn-rental-dates-edit" href="#bsn-rental-dates-form">Modifica date</a>
                    <a class="bsn-public-btn bsn-public-btn-ghost" href="<?php echo esc_url($clear_url); ?>">Cancella periodo</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
    <script>
    (function() {
        var form = document.getElementById('bsn-rental-dates-form');
        if (!form) {
            return;
        }

        function formatDateForDisplay(value) {
            var parts;
            if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(String(value))) {
                return 'gg-mm-aaaa';
            }
            parts = String(value).split('-');
            return parts[2] + '-' + parts[1] + '-' + parts[0];
        }

        form.querySelectorAll('[data-bsn-rental-date-shell="1"]').forEach(function(shell) {
            var input = shell.querySelector('input[type="date"]');
            var display = shell.querySelector('[data-bsn-rental-date-display="1"]');
            if (!input || !display) {
                return;
            }

            function syncDisplay() {
                var formatted = formatDateForDisplay(input.value);
                display.textContent = formatted;
                display.classList.toggle('is-placeholder', formatted === 'gg-mm-aaaa');
            }

            shell.addEventListener('click', function(event) {
                if (event.target === input) {
                    return;
                }
                event.preventDefault();
                if (typeof input.showPicker === 'function') {
                    try {
                        input.showPicker();
                        return;
                    } catch (error) {
                    }
                }
                input.focus();
            });

            input.addEventListener('change', syncDisplay);
            input.addEventListener('input', syncDisplay);
            syncDisplay();
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

function bsn_capture_public_rental_dates_from_request() {
    if (is_admin() || !(is_post_type_archive('bs_prodotto') || is_tax('bs_categoria_prodotto') || is_singular('bs_prodotto'))) {
        return;
    }

    if (isset($_GET['bsn_clear_period']) && (string) wp_unslash($_GET['bsn_clear_period']) === '1') {
        bsn_clear_public_rental_dates();
        wp_safe_redirect(remove_query_arg(['data_ritiro', 'data_riconsegna', 'bsn_clear_period']));
        exit;
    }

    if (!isset($_GET['data_ritiro']) && !isset($_GET['data_riconsegna'])) {
        return;
    }

    $data_ritiro = isset($_GET['data_ritiro']) ? sanitize_text_field(wp_unslash($_GET['data_ritiro'])) : '';
    $data_riconsegna = isset($_GET['data_riconsegna']) ? sanitize_text_field(wp_unslash($_GET['data_riconsegna'])) : '';
    bsn_set_public_rental_dates($data_ritiro, $data_riconsegna);
}
add_action('template_redirect', 'bsn_capture_public_rental_dates_from_request', 1);

function bsn_get_public_product_select_options_html($selected_id = 0) {
    $selected_id = absint($selected_id);
    $posts = get_posts([
        'post_type'      => 'bs_prodotto',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $html = '<option value="">-- Nessun prodotto pubblico associato --</option>';
    foreach ($posts as $post) {
        $label = $post->post_title !== '' ? $post->post_title : ('Prodotto #' . $post->ID);
        if ($post->post_status !== 'publish') {
            $label .= ' (' . $post->post_status . ')';
        }
        $html .= sprintf(
            '<option value="%d"%s>%s</option>',
            (int) $post->ID,
            selected($selected_id, (int) $post->ID, false),
            esc_html($label)
        );
    }

    return $html;
}

function bsn_get_public_products_for_linking() {
    $posts = get_posts([
        'post_type'      => 'bs_prodotto',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $items = [];
    foreach ($posts as $post) {
        $meta = bsn_get_public_product_meta($post->ID);
        $items[] = [
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'match_keys' => bsn_get_public_product_match_keys($post),
            'sede_operativa' => (string) ($meta['sede_operativa'] ?? ''),
            'veicolo_minimo' => (string) ($meta['veicolo_minimo'] ?? ''),
            'larghezza_cm' => (string) ($meta['larghezza_cm'] ?? ''),
            'altezza_cm' => (string) ($meta['altezza_cm'] ?? ''),
            'profondita_cm' => (string) ($meta['profondita_cm'] ?? ''),
            'peso_kg' => (string) ($meta['peso_kg'] ?? ''),
            'dimension_calculator_enabled' => ! empty( $meta['dimension_calculator_enabled'] ) ? 1 : 0,
            'dimension_calculation_type' => (string) ( $meta['dimension_calculation_type'] ?? 'standard' ),
            ];
    }

    return $items;
}

function bsn_get_articolo_inventory_mode_options() {
    return [
        'seriale' => 'Seriale / univoco',
        'quantita' => 'A quantita',
    ];
}

function bsn_get_articolo_stato_utilizzabilita_options() {
    return [
        'disponibile' => 'Disponibile',
        'non_noleggiabile' => 'Non noleggiabile',
        'manutenzione' => 'In manutenzione',
    ];
}

function bsn_get_articolo_veicolo_minimo_options() {
    return [
        '' => 'Non specificato',
        'citycar' => 'Citycar',
        'berlina' => 'Berlina',
        'station_wagon' => 'Station wagon',
        'suv' => 'SUV',
        'furgone_piccolo' => 'Furgone piccolo',
        'furgone_medio' => 'Furgone medio',
        'furgone_grande' => 'Furgone grande',
        'camion' => 'Camion',
    ];
}

function bsn_get_public_service_mode_options() {
    return array(
        'pickup' => 'Ritiro in sede',
        'delivery_only' => 'Solo consegna',
        'delivery_install' => 'Trasporto con montaggio',
        'delivery_install_tech' => 'Trasporto con montaggio e gestione tecnica',
    );
}

function bsn_get_public_service_stairs_options() {
    return array(
        'none' => 'Nessuna',
        '1_floor' => '1 piano',
        'multi_floor' => 'Piu piani',
    );
}

function bsn_get_public_service_walk_distance_options() {
    return array(
        'short' => 'Breve',
        'medium' => 'Media',
        'long' => 'Lunga',
    );
}

function bsn_get_public_service_pickup_context() {
    return array(
        'location_name' => 'Black Star Service SRL',
        'address' => 'Via Cerca 28, Brescia (zona Sant\'Eufemia)',
        'maps_url' => 'https://www.google.com/maps/search/?api=1&query=Via+Cerca+28+Brescia',
        'hours' => array(
            'Lun-Ven 09:30-12:30 / 14:30-17:30',
            'Sab 09:30-12:30',
        ),
    );
}

function bsn_get_public_service_pricing_config() {
    return array(
        'delivery_base' => 40.0,
        'delivery_km_rate' => 1.20,
        'install_base' => 100.0,
        'tech_half_day' => 180.0,
        'tech_full_day' => 250.0,
        'tech_transfer_km_rate' => 1.20,
        'tech_extra_hour' => 18.0,
        'tech_night_hour' => 25.0,
        'tech_half_day_hours' => 6.0,
    );
}

function bsn_get_internal_service_article_definitions() {
    return array(
        'SERV-USCITA' => array(
            'nome' => 'Servizio uscita consegna',
            'prezzo' => 40.0,
        ),
        'SERV-KM-CONSEGNA' => array(
            'nome' => 'Componente km consegna',
            'prezzo' => 0.0,
        ),
        'SERV-RITIRO' => array(
            'nome' => 'Servizio ritiro finale',
            'prezzo' => 40.0,
        ),
        'SERV-KM-AR' => array(
            'nome' => 'Componente km ritiro / ritorno',
            'prezzo' => 0.0,
        ),
        'SERV-MONTAGGIO-1TEC' => array(
            'nome' => 'Montaggio standard 1 tecnico',
            'prezzo' => 100.0,
        ),
        'SERV-SMONTAGGIO' => array(
            'nome' => 'Smontaggio standard',
            'prezzo' => 100.0,
        ),
        'SERV-TECNICO-MEZZA' => array(
            'nome' => 'Tecnico mezza giornata',
            'prezzo' => 180.0,
        ),
        'SERV-TECNICO-GIORNATA' => array(
            'nome' => 'Tecnico giornata intera',
            'prezzo' => 250.0,
        ),
        'SERV-EXTRA-ORA' => array(
            'nome' => 'Extra ora tecnico',
            'prezzo' => 18.0,
        ),
        'SERV-NOTTE' => array(
            'nome' => 'Maggiorazione notturna tecnico',
            'prezzo' => 25.0,
        ),
        'SERV-TRASFERTA' => array(
            'nome' => 'Costo chilometrico',
            'prezzo' => 0.0,
        ),
    );
}

function bsn_is_internal_service_article_code( $code ) {
    $code = strtoupper( trim( (string) $code ) );
    return $code !== '' && strpos( $code, 'SERV-' ) === 0;
}

function bsn_get_internal_service_article_display_label( $code, $fallback = '' ) {
    $code = strtoupper( trim( (string) $code ) );
    $definitions = bsn_get_internal_service_article_definitions();

    if ( $code !== '' && isset( $definitions[ $code ]['nome'] ) ) {
        return (string) $definitions[ $code ]['nome'];
    }

    $fallback = trim( (string) $fallback );
    if ( $fallback !== '' ) {
        return $fallback;
    }

    return $code !== '' ? $code : 'Servizio interno';
}

function bsn_get_empty_quote_cart_service() {
    return array(
        'mode' => 'pickup',
        'delivery_return_choice' => '',
        'dismantling_choice' => '',
        'delivery_return_requested' => 0,
        'dismantling_requested' => 0,
        'ack_delivery_only_terms' => 0,
        'location_address'  => '',
        'location_lat'      => '',
        'location_lng'      => '',
        'location_place_id' => '',
        'distance_km'       => '',
        'distance_provider' => '',
        'onsite_contact_name' => '',
        'onsite_contact_phone' => '',
        'logistics_ztl' => 0,
        'logistics_stairs' => 'none',
        'logistics_lift' => 0,
        'logistics_tight_access' => 0,
        'logistics_walk_distance' => 'short',
        'notes' => '',
        'event_date' => '',
        'event_start_date' => '',
        'event_end_date' => '',
        'event_days' => '',
        'tech_day_type' => '',
        'event_start_time' => '',
        'event_end_time' => '',
        'estimated_total' => '',
        'quote_needs_review' => 0,
        'label' => '',
        'distance_status' => 'not_required',
        'distance_message' => '',
        'messages' => array(),
        'errors' => array(),
    );
}

function bsn_normalize_quote_cart_service( $service ) {
    $normalized = bsn_get_empty_quote_cart_service();
    if ( ! is_array( $service ) ) {
        return $normalized;
    }

    $mode = sanitize_key( (string) ( $service['mode'] ?? 'pickup' ) );
    if ( ! array_key_exists( $mode, bsn_get_public_service_mode_options() ) ) {
        $mode = 'pickup';
    }

    $normalized['mode'] = $mode;
    $delivery_return_choice = sanitize_key( (string) ( $service['delivery_return_choice'] ?? '' ) );
    if ( ! in_array( $delivery_return_choice, array( 'yes', 'no' ), true ) ) {
        $delivery_return_choice = '';
    }
    $normalized['delivery_return_choice'] = $delivery_return_choice;

    $dismantling_choice = sanitize_key( (string) ( $service['dismantling_choice'] ?? '' ) );
    if ( ! in_array( $dismantling_choice, array( 'yes', 'no' ), true ) ) {
        $dismantling_choice = '';
    }
    $normalized['dismantling_choice'] = $dismantling_choice;

    $normalized['delivery_return_requested'] = ! empty( $service['delivery_return_requested'] ) ? 1 : 0;
    $normalized['dismantling_requested'] = ! empty( $service['dismantling_requested'] ) ? 1 : 0;
    $normalized['ack_delivery_only_terms'] = ! empty( $service['ack_delivery_only_terms'] ) ? 1 : 0;
    $normalized['location_address']  = sanitize_textarea_field( (string) ( $service['location_address'] ?? '' ) );
    $normalized['location_lat']      = is_numeric( $service['location_lat'] ?? null ) ? (string) (float) $service['location_lat'] : '';
    $normalized['location_lng']      = is_numeric( $service['location_lng'] ?? null ) ? (string) (float) $service['location_lng'] : '';
    $normalized['location_place_id'] = sanitize_text_field( (string) ( $service['location_place_id'] ?? '' ) );
    $normalized['distance_km']       = is_numeric( $service['distance_km'] ?? null ) ? (string) round( (float) $service['distance_km'], 2 ) : '';
    $normalized['distance_provider'] = sanitize_text_field( (string) ( $service['distance_provider'] ?? '' ) );
    $normalized['onsite_contact_name'] = sanitize_text_field( (string) ( $service['onsite_contact_name'] ?? '' ) );
    $normalized['onsite_contact_phone'] = sanitize_text_field( (string) ( $service['onsite_contact_phone'] ?? '' ) );
    $normalized['logistics_ztl'] = ! empty( $service['logistics_ztl'] ) ? 1 : 0;
    $normalized['logistics_stairs'] = sanitize_key( (string) ( $service['logistics_stairs'] ?? 'none' ) );
    if ( ! array_key_exists( $normalized['logistics_stairs'], bsn_get_public_service_stairs_options() ) ) {
        $normalized['logistics_stairs'] = 'none';
    }
    $normalized['logistics_lift'] = ! empty( $service['logistics_lift'] ) ? 1 : 0;
    $normalized['logistics_tight_access'] = ! empty( $service['logistics_tight_access'] ) ? 1 : 0;
    $normalized['logistics_walk_distance'] = sanitize_key( (string) ( $service['logistics_walk_distance'] ?? 'short' ) );
    if ( ! array_key_exists( $normalized['logistics_walk_distance'], bsn_get_public_service_walk_distance_options() ) ) {
        $normalized['logistics_walk_distance'] = 'short';
    }
    $normalized['notes'] = sanitize_textarea_field( (string) ( $service['notes'] ?? '' ) );
    $normalized['event_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $service['event_date'] ?? '' ) )
        ? (string) $service['event_date']
        : '';
    $normalized['event_start_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $service['event_start_date'] ?? '' ) )
        ? (string) $service['event_start_date']
        : '';
    $normalized['event_end_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $service['event_end_date'] ?? '' ) )
        ? (string) $service['event_end_date']
        : '';
    $normalized['event_days'] = is_numeric( $service['event_days'] ?? null ) ? (string) max( 1, intval( $service['event_days'] ) ) : '';
    $normalized['tech_day_type'] = sanitize_key( (string) ( $service['tech_day_type'] ?? '' ) );
    $normalized['event_start_time'] = preg_match( '/^\d{2}:\d{2}$/', (string) ( $service['event_start_time'] ?? '' ) )
        ? (string) $service['event_start_time']
        : '';
    $normalized['event_end_time'] = preg_match( '/^\d{2}:\d{2}$/', (string) ( $service['event_end_time'] ?? '' ) )
        ? (string) $service['event_end_time']
        : '';
    $normalized['estimated_total'] = is_numeric( $service['estimated_total'] ?? null ) ? (string) round( (float) $service['estimated_total'], 2 ) : '';
    $normalized['quote_needs_review'] = ! empty( $service['quote_needs_review'] ) ? 1 : 0;
    $normalized['label'] = sanitize_text_field( (string) ( $service['label'] ?? '' ) );
    $normalized['distance_status'] = sanitize_key( (string) ( $service['distance_status'] ?? 'not_required' ) );
    if ( ! in_array( $normalized['distance_status'], array( 'not_required', 'ok', 'failed' ), true ) ) {
        $normalized['distance_status'] = 'not_required';
    }
    $normalized['distance_message'] = sanitize_text_field( (string) ( $service['distance_message'] ?? '' ) );
    $normalized['messages'] = array_values(
        array_filter(
            array_map(
                function ( $message ) {
                    return sanitize_text_field( (string) $message );
                },
                (array) ( $service['messages'] ?? array() )
            )
        )
    );
    $normalized['errors'] = array_values(
        array_filter(
            array_map(
                function ( $message ) {
                    return sanitize_text_field( (string) $message );
                },
                (array) ( $service['errors'] ?? array() )
            )
        )
    );

    return $normalized;
}

function bsn_remote_json_get( $url ) {
    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 8,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'BlackstarNoleggi/1.0 (+https://www.blackstarservice.it)',
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error( 'bsn_remote_http', 'Provider distanza non disponibile.' );
    }

    $body = wp_remote_retrieve_body( $response );
    $decoded = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'bsn_remote_json', 'Risposta distanza non valida.' );
    }

    return $decoded;
}

// ============================================================
// GOOGLE MAPS — Helper chiavi e funzioni di geocoding/distanza
// ============================================================

function bsn_get_google_maps_frontend_key() {
    return trim( (string) get_option( 'bsn_google_maps_frontend_key', '' ) );
}

function bsn_get_google_maps_backend_key() {
    return trim( (string) get_option( 'bsn_google_maps_backend_key', '' ) );
}

// Calcola la distanza stradale usando Google Routes API (lato server)
function bsn_calculate_distance_google_routes( $origin_lat, $origin_lng, $dest_lat, $dest_lng, $api_key ) {
    $body = wp_json_encode( array(
        'origin'      => array( 'location' => array( 'latLng' => array( 'latitude' => $origin_lat, 'longitude' => $origin_lng ) ) ),
        'destination' => array( 'location' => array( 'latLng' => array( 'latitude' => $dest_lat,   'longitude' => $dest_lng   ) ) ),
        'travelMode'  => 'DRIVE',
    ) );

    $response = wp_remote_post(
        'https://routes.googleapis.com/directions/v2:computeRoutes',
        array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'X-Goog-Api-Key'    => $api_key,
                'X-Goog-FieldMask'  => 'routes.distanceMeters',
            ),
            'body' => $body,
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error( 'bsn_google_routes_http', 'Google Routes API non disponibile (HTTP ' . $code . ').' );
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    $meters = isset( $data['routes'][0]['distanceMeters'] ) ? (float) $data['routes'][0]['distanceMeters'] : 0.0;
    if ( $meters <= 0 ) {
        return new WP_Error( 'bsn_google_routes_empty', 'Distanza Google non disponibile.' );
    }

    return array( 'distance_km' => $meters / 1000 );
}

// Geocoding con Google Geocoding API (lato server)
function bsn_geocode_address_google( $address, $api_key ) {
    $url = add_query_arg( array(
        'address' => rawurlencode( $address ),
        'key'     => $api_key,
    ), 'https://maps.googleapis.com/maps/api/geocode/json' );

    $response = wp_remote_get( $url, array( 'timeout' => 8 ) );
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error( 'bsn_google_geocode_http', 'Google Geocoding API non disponibile.' );
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $data['results'][0]['geometry']['location'] ) ) {
        return new WP_Error( 'bsn_google_geocode_empty', 'Indirizzo non trovato via Google.' );
    }

    $loc = $data['results'][0]['geometry']['location'];
    return array(
        'lat'      => round( (float) $loc['lat'], 6 ),
        'lng'      => round( (float) $loc['lng'], 6 ),
        'provider' => 'google',
    );
}

function bsn_geocode_public_service_address( $address ) {
    $address = trim( (string) $address );
    if ( $address === '' ) {
        return new WP_Error( 'bsn_service_address_empty', 'Indirizzo mancante.' );
    }

    $cache_key = 'bsn_geo2_' . md5( strtolower( $address ) );
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) && isset( $cached['lat'], $cached['lng'] ) ) {
        return $cached;
    }

    // Prova prima con Google Geocoding API se la chiave backend è configurata
    $backend_key = bsn_get_google_maps_backend_key();
    if ( $backend_key !== '' ) {
        $google_result = bsn_geocode_address_google( $address, $backend_key );
        if ( ! is_wp_error( $google_result ) ) {
            set_transient( $cache_key, $google_result, DAY_IN_SECONDS );
            return $google_result;
        }
        // Fallback a Nominatim se Google fallisce
    }

    // Fallback: Nominatim (OpenStreetMap, gratuito)
    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode( $address );
    $data = bsn_remote_json_get( $url );
    if ( is_wp_error( $data ) ) {
        return $data;
    }

    if ( empty( $data[0]['lat'] ) || empty( $data[0]['lon'] ) ) {
        return new WP_Error( 'bsn_service_geocode_empty', 'Indirizzo non geocodificato.' );
    }

    $result = array(
        'lat'      => round( (float) $data[0]['lat'], 6 ),
        'lng'      => round( (float) $data[0]['lon'], 6 ),
        'provider' => 'nominatim',
    );
    set_transient( $cache_key, $result, DAY_IN_SECONDS );

    return $result;
}

function bsn_calculate_public_service_distance( $address, $dest_lat = '', $dest_lng = '', $place_id = '' ) {
    // Coordinate fisse sede Black Star Service — Via Cerca 28, Brescia (zona Sant'Eufemia)
    $origin_lat = 45.51303326496157;
    $origin_lng = 10.27310251455083;
    $origin_key = round( $origin_lat, 5 ) . ',' . round( $origin_lng, 5 );
    $pickup = bsn_get_public_service_pickup_context();

    // Se il frontend ha già fornito le coordinate (da autocomplete), saltiamo il geocoding
    $geocoded = null;
    if ( is_numeric( $dest_lat ) && is_numeric( $dest_lng ) && (float) $dest_lat !== 0.0 && (float) $dest_lng !== 0.0 ) {
        $geocoded = array(
            'lat'      => (float) $dest_lat,
            'lng'      => (float) $dest_lng,
            'provider' => 'autocomplete',
        );
    } else {
        $geocoded = bsn_geocode_public_service_address( $address );
        if ( is_wp_error( $geocoded ) ) {
            return $geocoded;
        }
    }

    $cache_key = 'bsn_dist2_' . md5( $origin_key . '|' . round( (float) $geocoded['lat'], 5 ) . '|' . round( (float) $geocoded['lng'], 5 ) );
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) && isset( $cached['distance_km'] ) ) {
        return $cached;
    }

    // Tentativo con Google Routes API (se configurata)
    $backend_key = bsn_get_google_maps_backend_key();
    if ( $backend_key !== '' ) {
        $routes_result = bsn_calculate_distance_google_routes( $origin_lat, $origin_lng, (float) $geocoded['lat'], (float) $geocoded['lng'], $backend_key );
        if ( ! is_wp_error( $routes_result ) && isset( $routes_result['distance_km'] ) ) {
            $result = array(
                'distance_km'  => round( (float) $routes_result['distance_km'], 1 ),
                'lat'          => (float) $geocoded['lat'],
                'lng'          => (float) $geocoded['lng'],
                'provider'     => 'google',
                'origin_label' => $pickup['location_name'],
            );
            set_transient( $cache_key, $result, DAY_IN_SECONDS );
            return $result;
        }
        // Fallback automatico a OSRM se Google fallisce
    }

    // Fallback: OSRM (gratuito, open source)
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%s,%s;%s,%s?overview=false',
        rawurlencode( (string) $origin_lng ),
        rawurlencode( (string) $origin_lat ),
        rawurlencode( (string) $geocoded['lng'] ),
        rawurlencode( (string) $geocoded['lat'] )
    );
    $data = bsn_remote_json_get( $url );
    if ( is_wp_error( $data ) ) {
        return $data;
    }

    $meters = isset( $data['routes'][0]['distance'] ) ? (float) $data['routes'][0]['distance'] : 0.0;
    if ( $meters <= 0 ) {
        return new WP_Error( 'bsn_service_distance_empty', 'Distanza non disponibile.' );
    }

    $result = array(
        'distance_km'  => round( $meters / 1000, 1 ),
        'lat'          => (float) $geocoded['lat'],
        'lng'          => (float) $geocoded['lng'],
        'provider'     => 'nominatim+osrm',
        'origin_label' => $pickup['location_name'],
    );
    set_transient( $cache_key, $result, DAY_IN_SECONDS );

    return $result;
}

function bsn_combine_service_event_datetime( $date, $time ) {
    $date = trim( (string) $date );
    $time = trim( (string) $time );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
        return '';
    }

    return $date . ' ' . $time . ':00';
}

function bsn_get_service_event_day_count( $start_date, $end_date ) {
    $start_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $start_date ) ? (string) $start_date : '';
    $end_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $end_date ) ? (string) $end_date : '';
    if ( $start_date === '' || $end_date === '' ) {
        return 0;
    }

    $start_ts = strtotime( $start_date . ' 00:00:00' );
    $end_ts = strtotime( $end_date . ' 00:00:00' );
    if ( ! $start_ts || ! $end_ts || $end_ts < $start_ts ) {
        return 0;
    }

    return max( 1, (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1 );
}

function bsn_get_service_tech_day_type( $start_time, $end_time ) {
    $start_time = preg_match( '/^\d{2}:\d{2}$/', (string) $start_time ) ? (string) $start_time : '';
    $end_time = preg_match( '/^\d{2}:\d{2}$/', (string) $end_time ) ? (string) $end_time : '';
    if ( $start_time === '' || $end_time === '' || $start_time >= $end_time ) {
        return '';
    }

    $start_ts = strtotime( '2000-01-01 ' . $start_time . ':00' );
    $end_ts = strtotime( '2000-01-01 ' . $end_time . ':00' );
    if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
        return '';
    }

    $hours = round( ( $end_ts - $start_ts ) / HOUR_IN_SECONDS, 2 );
    return ( $hours > 0 && $hours <= bsn_get_public_service_pricing_config()['tech_half_day_hours'] ) ? 'half_day' : 'full_day';
}

function bsn_prepare_quote_cart_service( $service, $cart_dates = array() ) {
    $service = bsn_normalize_quote_cart_service( $service );
    $pricing = bsn_get_public_service_pricing_config();
    $modes = bsn_get_public_service_mode_options();
    $mode = $service['mode'];
    $messages = array();
    $errors = array();
    $estimated_total = null;
    $distance_km = null;
    $distance_provider = '';
    $distance_status = 'not_required';
    $distance_message = '';
    $quote_needs_review = ( $mode === 'pickup' ) ? 0 : 1;

    $service['label'] = $modes[ $mode ] ?? 'Modalita servizio';
    $service['event_start_date'] = '';
    $service['event_end_date'] = '';
    $service['event_days'] = '';
    $service['tech_day_type'] = '';

    if ( $mode === 'pickup' ) {
        $service['estimated_total'] = '0.00';
        $service['quote_needs_review'] = 0;
        $service['distance_status'] = 'not_required';
        $service['distance_message'] = '';
        $service['messages'] = array();
        $service['errors'] = array();
        return $service;
    }

    if ( $mode === 'delivery_only' ) {
        $choice = sanitize_key( (string) ( $service['delivery_return_choice'] ?? '' ) );
        if ( ! in_array( $choice, array( 'yes', 'no' ), true ) ) {
            $errors[] = 'Seleziona se desideri solo la consegna oppure consegna + ritiro finale.';
        } else {
            $service['delivery_return_requested'] = ( $choice === 'yes' ) ? 1 : 0;
        }
    }

    if ( $mode === 'delivery_install' ) {
        $choice = sanitize_key( (string) ( $service['dismantling_choice'] ?? '' ) );
        if ( ! in_array( $choice, array( 'yes', 'no' ), true ) ) {
            $errors[] = 'Seleziona se desideri solo montaggio oppure montaggio + smontaggio e ritiro.';
        } else {
            $service['dismantling_requested'] = ( $choice === 'yes' ) ? 1 : 0;
        }
    }

    if ( $service['location_address'] === '' ) {
        $errors[] = 'Inserisci l\'indirizzo della location per il servizio selezionato.';
    }

    if ( $service['onsite_contact_name'] === '' ) {
        $errors[] = 'Inserisci il referente in loco.';
    }

    if ( $service['onsite_contact_phone'] === '' ) {
        $errors[] = 'Inserisci il telefono del referente in loco.';
    }

    if ( $mode === 'delivery_only' && ! $service['ack_delivery_only_terms'] ) {
        $errors[] = 'Devi confermare di aver compreso le condizioni della sola consegna.';
    }

    if ( in_array( $mode, array( 'delivery_install', 'delivery_install_tech' ), true ) && $service['notes'] === '' ) {
        $errors[] = 'Inserisci le note logistiche per il servizio selezionato.';
    }

    if ( $mode === 'delivery_install_tech' ) {
        $event_start_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $cart_dates['data_ritiro'] ?? '' ) )
            ? (string) $cart_dates['data_ritiro']
            : '';
        $event_end_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $cart_dates['data_riconsegna'] ?? '' ) )
            ? (string) $cart_dates['data_riconsegna']
            : '';
        $service['event_date'] = $event_start_date;
        $service['event_start_date'] = $event_start_date;
        $service['event_end_date'] = $event_end_date;

        if ( $event_start_date === '' || $event_end_date === '' ) {
            $errors[] = 'Seleziona prima le date del noleggio per calcolare la gestione tecnica.';
        }
        if ( $service['event_start_time'] === '' || $service['event_end_time'] === '' ) {
            $errors[] = 'Inserisci sia l\'orario di inizio sia l\'orario di fine evento.';
        } elseif ( $service['event_start_time'] >= $service['event_end_time'] ) {
            $errors[] = 'L\'orario di fine evento deve essere successivo all\'orario di inizio.';
        }

        $event_days = bsn_get_service_event_day_count( $event_start_date, $event_end_date );
        if ( $event_days > 0 ) {
            $service['event_days'] = (string) $event_days;
        }

        $tech_day_type = bsn_get_service_tech_day_type( $service['event_start_time'], $service['event_end_time'] );
        if ( $tech_day_type !== '' ) {
            $service['tech_day_type'] = $tech_day_type;
        }
    }

    if ( $service['location_address'] !== '' ) {
        // Passa lat/lng e place_id dal frontend (autocomplete) per evitare geocoding ridondante
        $distance = bsn_calculate_public_service_distance(
            $service['location_address'],
            $service['location_lat']      ?? '',
            $service['location_lng']      ?? '',
            $service['location_place_id'] ?? ''
        );
        if ( is_wp_error( $distance ) ) {
            $distance_status = 'failed';
            $distance_message = 'Costo trasporto da definire dopo verifica indirizzo.';
            $messages[] = $distance_message;
        } else {
            $distance_km = round( (float) ( $distance['distance_km'] ?? 0 ), 1 );
            $distance_provider = (string) ( $distance['provider'] ?? '' );
            $distance_status = 'ok';
            $service['location_lat'] = (string) ( $distance['lat'] ?? '' );
            $service['location_lng'] = (string) ( $distance['lng'] ?? '' );
        }
    }

    if ( $distance_km !== null ) {
        if ( $mode === 'delivery_only' ) {
            $trip_total = $pricing['delivery_base'] + ( $distance_km * $pricing['delivery_km_rate'] );
            $estimated_total = $service['delivery_return_requested'] ? ( $trip_total * 2 ) : $trip_total;
        } elseif ( $mode === 'delivery_install' ) {
            $trip_total = $pricing['install_base'] + ( $distance_km * $pricing['delivery_km_rate'] );
            $estimated_total = $service['dismantling_requested'] ? ( $trip_total * 2 ) : $trip_total;
        } elseif ( $mode === 'delivery_install_tech' ) {
            $event_days = max( 1, intval( $service['event_days'] ?: 1 ) );
            $tech_day_type = (string) ( $service['tech_day_type'] ?? '' );
            $tech_base = ( $tech_day_type === 'half_day' ) ? $pricing['tech_half_day'] : $pricing['tech_full_day'];
            $estimated_total = ( $tech_base * $event_days ) + ( $distance_km * $pricing['tech_transfer_km_rate'] );
        }
    }

    if ( $mode === 'delivery_install_tech' ) {
        $messages[] = 'Il numero reale di tecnici verra sempre definito dallo staff.';
        if ( ! empty( $service['event_days'] ) ) {
            $messages[] = 'Giorni evento considerati: ' . intval( $service['event_days'] ) . '.';
        }
        if ( ! empty( $service['tech_day_type'] ) ) {
            $messages[] = 'Tipologia base calcolata: ' . ( $service['tech_day_type'] === 'half_day' ? 'mezza giornata' : 'giornata intera' ) . '.';
        }
        $messages[] = 'Pre-stima calcolata su 1 tecnico minimo.';
    }
    if ( $mode !== 'pickup' ) {
        $messages[] = 'Il costo dei servizi logistici e tecnici e indicativo e soggetto a conferma da parte dello staff Black Star Service.';
    }

    $service['distance_km'] = $distance_km !== null ? (string) $distance_km : '';
    $service['distance_provider'] = $distance_provider;
    $service['estimated_total'] = $estimated_total !== null ? (string) round( $estimated_total, 2 ) : '';
    $service['quote_needs_review'] = $quote_needs_review;
    $service['distance_status'] = $distance_status;
    $service['distance_message'] = $distance_message;
    $service['messages'] = array_values( array_unique( array_filter( $messages ) ) );
    $service['errors'] = array_values( array_unique( array_filter( $errors ) ) );

    return $service;
}

function bsn_normalize_articolo_inventory_mode($value, $qty_disponibile = 1) {
    $value = sanitize_key((string) $value);
    $valid = array_keys(bsn_get_articolo_inventory_mode_options());
    if (in_array($value, $valid, true)) {
        return $value;
    }

    return intval($qty_disponibile) > 1 ? 'quantita' : 'seriale';
}

function bsn_normalize_articolo_stato_utilizzabilita($value) {
    $value = sanitize_key((string) $value);
    $valid = array_keys(bsn_get_articolo_stato_utilizzabilita_options());
    if (in_array($value, $valid, true)) {
        return $value;
    }

    return 'disponibile';
}

function bsn_sanitize_articolo_public_product_id($value) {
    $product_id = absint($value);
    if ($product_id < 1) {
        return 0;
    }

    $post = get_post($product_id);
    if (!$post || $post->post_type !== 'bs_prodotto') {
        return 0;
    }

    return $product_id;
}

function bsn_normalize_public_group_key($value) {
    $value = wp_strip_all_tags((string) $value);
    $value = preg_replace('/#.*$/', '', $value);
    $value = remove_accents($value);
    $value = strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]+/', '', $value);
    return trim((string) $value);
}

function bsn_get_articolo_public_group_key($articolo) {
    $codice = '';
    $nome = '';

    if (is_array($articolo)) {
        $codice = (string) ($articolo['codice'] ?? '');
        $nome = (string) ($articolo['nome'] ?? '');
    } elseif (is_object($articolo)) {
        $codice = (string) ($articolo->codice ?? '');
        $nome = (string) ($articolo->nome ?? '');
    }

    $primary = bsn_normalize_public_group_key($codice);
    if ($primary !== '') {
        return $primary;
    }

    return bsn_normalize_public_group_key($nome);
}

function bsn_get_public_product_match_keys($product) {
    $post = null;
    if ($product instanceof WP_Post) {
        $post = $product;
    } elseif (is_numeric($product)) {
        $post = get_post(absint($product));
    }

    if (!$post || $post->post_type !== 'bs_prodotto') {
        return [];
    }

    $keys = [];
    $meta = bsn_get_public_product_meta($post->ID);
    $title_key = bsn_normalize_public_group_key($post->post_title);
    $slug_key = bsn_normalize_public_group_key($post->post_name);
    $subtitle_key = bsn_normalize_public_group_key($meta['sottotitolo_catalogo'] ?? '');
    $alias_parts = preg_split('/[\r\n,]+/', (string) ($meta['alias_ricerca'] ?? ''));

    if ($title_key !== '') {
        $keys[] = $title_key;
    }
    if ($slug_key !== '' && !in_array($slug_key, $keys, true)) {
        $keys[] = $slug_key;
    }
    if ($subtitle_key !== '' && !in_array($subtitle_key, $keys, true)) {
        $keys[] = $subtitle_key;
    }
    if (is_array($alias_parts)) {
        foreach ($alias_parts as $alias_part) {
            $alias_key = bsn_normalize_public_group_key($alias_part);
            if ($alias_key !== '' && !in_array($alias_key, $keys, true)) {
                $keys[] = $alias_key;
            }
        }
    }

    return $keys;
}

function bsn_find_suggested_public_product_for_articolo($articolo, $products = null) {
    $group_key = bsn_get_articolo_public_group_key($articolo);
    if ($group_key === '') {
        return null;
    }

    if ($products === null) {
        $products = get_posts([
            'post_type'      => 'bs_prodotto',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
    }

    $matches = [];
    foreach ((array) $products as $product) {
        if (!$product instanceof WP_Post || $product->post_type !== 'bs_prodotto') {
            continue;
        }
        $keys = bsn_get_public_product_match_keys($product);
        if (in_array($group_key, $keys, true)) {
            $matches[] = $product;
        }
    }

    if (count($matches) !== 1) {
        return null;
    }

    return $matches[0];
}

function bsn_enrich_articoli_rows($rows) {
    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $product_ids = [];
    $needs_suggested_lookup = false;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $product_id = isset($row['prodotto_pubblico_id']) ? absint($row['prodotto_pubblico_id']) : 0;
        if ($product_id > 0) {
            $product_ids[] = $product_id;
        } else {
            $needs_suggested_lookup = true;
        }
    }

    $product_ids = array_values(array_unique(array_map('absint', $product_ids)));
    $all_public_products = [];

    if ($needs_suggested_lookup) {
        $all_public_products = get_posts([
            'post_type'              => 'bs_prodotto',
            'posts_per_page'         => -1,
            'post_status'            => ['publish', 'draft', 'pending', 'private'],
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);
    } elseif (!empty($product_ids)) {
        $all_public_products = get_posts([
            'post_type'              => 'bs_prodotto',
            'post__in'               => $product_ids,
            'posts_per_page'         => count($product_ids),
            'post_status'            => ['publish', 'draft', 'pending', 'private'],
            'orderby'                => 'post__in',
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);
    }

    $product_titles = [];
    $product_meta_map = [];
    foreach ($all_public_products as $post) {
        $product_titles[(int) $post->ID] = $post->post_title;
        $product_meta_map[(int) $post->ID] = bsn_get_public_product_meta($post->ID);
    }

    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }

        $qty = isset($row['qty_disponibile']) ? intval($row['qty_disponibile']) : 1;
        $row['inventory_mode'] = bsn_normalize_articolo_inventory_mode($row['inventory_mode'] ?? '', $qty);
        $row['stato_utilizzabilita'] = bsn_normalize_articolo_stato_utilizzabilita($row['stato_utilizzabilita'] ?? '');
        $row['min_qty'] = max(1, intval($row['min_qty'] ?? 1));
        $row['qty_discount_enabled'] = ! empty( $row['qty_discount_enabled'] ) ? 1 : 0;
        $row['qty_discount_start_qty'] = isset( $row['qty_discount_start_qty'] ) && $row['qty_discount_start_qty'] !== null ? max( 0, intval( $row['qty_discount_start_qty'] ) ) : '';
        $row['qty_discount_max_qty'] = isset( $row['qty_discount_max_qty'] ) && $row['qty_discount_max_qty'] !== null ? max( 0, intval( $row['qty_discount_max_qty'] ) ) : '';
        $row['qty_discount_max_pct'] = isset( $row['qty_discount_max_pct'] ) ? max( 0, min( 100, (float) $row['qty_discount_max_pct'] ) ) : 0;

        $product_id = isset($row['prodotto_pubblico_id']) ? absint($row['prodotto_pubblico_id']) : 0;
        $row['prodotto_pubblico_id'] = $product_id;
        $row['prodotto_pubblico_titolo'] = $product_id > 0 && isset($product_titles[$product_id]) ? $product_titles[$product_id] : '';
        $product_meta = $product_id > 0 && isset($product_meta_map[$product_id]) ? $product_meta_map[$product_id] : [];
        $row['prodotto_pubblico_sede_operativa'] = (string) ($product_meta['sede_operativa'] ?? '');
        $row['prodotto_pubblico_veicolo_minimo'] = (string) ($product_meta['veicolo_minimo'] ?? '');
        $row['prodotto_pubblico_larghezza_cm'] = (string) ($product_meta['larghezza_cm'] ?? '');
        $row['prodotto_pubblico_altezza_cm'] = (string) ($product_meta['altezza_cm'] ?? '');
        $row['prodotto_pubblico_profondita_cm'] = (string) ($product_meta['profondita_cm'] ?? '');
        $row['prodotto_pubblico_peso_kg'] = (string) ($product_meta['peso_kg'] ?? '');
        $row['prodotto_pubblico_dimension_calculator_enabled'] = ! empty( $product_meta['dimension_calculator_enabled'] ) ? 1 : 0;
        $row['prodotto_pubblico_dimension_calculation_type'] = (string) ( $product_meta['dimension_calculation_type'] ?? 'standard' );
        $row['prodotto_pubblico_suggerito_id'] = 0;
        $row['prodotto_pubblico_suggerito_titolo'] = '';

        if ($product_id < 1) {
            $suggested_product = bsn_find_suggested_public_product_for_articolo($row, $all_public_products);
            if ($suggested_product instanceof WP_Post) {
                $row['prodotto_pubblico_suggerito_id'] = (int) $suggested_product->ID;
                $row['prodotto_pubblico_suggerito_titolo'] = (string) $suggested_product->post_title;
            }
        }
    }
    unset($row);

    return $rows;
}

function bsn_find_public_product_ids_by_search( $search, $limit = 40 ) {
    $search = trim( (string) $search );
    if ( $search === '' ) {
        return [];
    }

    $products = get_posts( [
        'post_type'      => 'bs_prodotto',
        'posts_per_page' => max( 1, min( 100, intval( $limit ) ) ),
        'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
        's'              => $search,
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    if ( empty( $products ) || is_wp_error( $products ) ) {
        return [];
    }

    return array_values( array_unique( array_map( 'absint', (array) $products ) ) );
}

function bsn_get_blocking_rental_statuses() {
    return [ 'bozza', 'attivo', 'ritardo' ];
}

function bsn_get_frontend_open_request_statuses() {
    return array( 'preventivo', 'bozza', 'attivo', 'ritardo' );
}

function bsn_get_customer_frontend_open_request_count( $customer_id, $exclude_noleggio_id = '' ) {
    global $wpdb;

    $customer_id = absint( $customer_id );
    if ( $customer_id < 1 ) {
        return 0;
    }

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $statuses = bsn_get_frontend_open_request_statuses();
    $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
    $sql = "SELECT COUNT(*) FROM $table_noleggi WHERE cliente_id = %d AND origine = %s AND stato IN ($placeholders)";
    $params = array_merge( array( $customer_id, 'frontend' ), $statuses );

    if ( $exclude_noleggio_id !== '' ) {
        $sql .= ' AND id <> %s';
        $params[] = sanitize_text_field( (string) $exclude_noleggio_id );
    }

    return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
}

function bsn_get_articolo_customer_discount_percent( $articolo, $categoria_cliente = 'standard' ) {
    $categoria_cliente = sanitize_key( (string) $categoria_cliente );

    if ( ! is_array( $articolo ) ) {
        $articolo = is_object( $articolo ) ? (array) $articolo : [];
    }

    switch ( $categoria_cliente ) {
        case 'fidato':
            return (float) ( $articolo['sconto_fidato'] ?? 0 );
        case 'premium':
            return (float) ( $articolo['sconto_premium'] ?? 0 );
        case 'service':
            return (float) ( $articolo['sconto_service'] ?? 0 );
        case 'collaboratori':
            return (float) ( $articolo['sconto_collaboratori'] ?? 0 );
        default:
            return (float) ( $articolo['sconto_standard'] ?? 0 );
    }
}

function bsn_get_public_product_pricing_candidates( $product_id, $categoria_cliente = 'standard' ) {
    $articoli = bsn_get_public_product_articles( $product_id );
    if ( empty( $articoli ) ) {
        return [];
    }

    $categoria_cliente = sanitize_key( (string) $categoria_cliente );
    $candidates = [];

    foreach ( $articoli as $articolo ) {
        if ( ! bsn_is_article_publicly_rentable( $articolo ) ) {
            continue;
        }

        $prezzo = (float) ( $articolo['prezzo_giorno'] ?? 0 );
        if ( $prezzo <= 0 ) {
            continue;
        }

        $sconto = bsn_get_articolo_customer_discount_percent( $articolo, $categoria_cliente );
        $prezzo_netto = $prezzo * ( 1 - ( $sconto / 100 ) );

        $candidates[] = [
            'articolo' => $articolo,
            'articolo_id' => (int) ( $articolo['id'] ?? 0 ),
            'label' => trim( ( ! empty( $articolo['codice'] ) ? '[' . $articolo['codice'] . '] ' : '' ) . ( $articolo['nome'] ?? '' ) ),
            'prezzo_standard' => $prezzo,
            'prezzo_netto' => $prezzo_netto,
            'sconto' => $sconto,
            'noleggio_scalare' => ! empty( $articolo['noleggio_scalare'] ),
        ];
    }

    usort(
        $candidates,
        function ( $a, $b ) {
            $cmp = $a['prezzo_netto'] <=> $b['prezzo_netto'];
            if ( 0 !== $cmp ) {
                return $cmp;
            }

            return (int) ( $a['articolo_id'] ?? 0 ) <=> (int) ( $b['articolo_id'] ?? 0 );
        }
    );

    return $candidates;
}

function bsn_get_public_product_quote_reference_article( $product_id, $categoria_cliente = 'standard' ) {
    $candidates = bsn_get_public_product_pricing_candidates( $product_id, $categoria_cliente );
    if ( empty( $candidates ) ) {
        return null;
    }

    return $candidates[0];
}

function bsn_get_public_product_articles($product_id) {
    global $wpdb;

    $product_id = absint($product_id);
    if ($product_id < 1) {
        return [];
    }

    $table_articoli = $wpdb->prefix . 'bs_articoli';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_articoli WHERE prodotto_pubblico_id = %d ORDER BY nome ASC, id ASC",
            $product_id
        ),
        ARRAY_A
    );

    return bsn_enrich_articoli_rows($rows);
}

function bsn_get_public_product_open_warning_tickets($product_id) {
    global $wpdb;

    $articoli = bsn_get_public_product_articles($product_id);
    if (empty($articoli)) {
        return [];
    }

    $article_ids = array_values(array_unique(array_filter(array_map(function($articolo) {
        return intval($articolo['id'] ?? 0);
    }, $articoli))));

    if (empty($article_ids)) {
        return [];
    }

    $table_ticket = $wpdb->prefix . 'bs_ticket';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_ticket));
    if ($table_exists !== $table_ticket) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($article_ids), '%d'));
    $sql = "SELECT id, articolo_id, qty, tipo, note, stato
            FROM $table_ticket
            WHERE stato = 'aperto'
              AND tipo = 'problematico_utilizzabile'
              AND articolo_id IN ($placeholders)
            ORDER BY creato_il DESC, id DESC";

    return $wpdb->get_results($wpdb->prepare($sql, $article_ids), ARRAY_A);
}

function bsn_get_public_product_warning_messages($product_id) {
    $articoli = bsn_get_public_product_articles($product_id);
    if (empty($articoli)) {
        return [];
    }

    $article_map = [];
    foreach ($articoli as $articolo) {
        $articolo_id = intval($articolo['id'] ?? 0);
        if ($articolo_id < 1) {
            continue;
        }

        $label = '';
        if (!empty($articolo['codice'])) {
            $label .= '[' . $articolo['codice'] . '] ';
        }
        $label .= !empty($articolo['nome']) ? $articolo['nome'] : ('Articolo #' . $articolo_id);
        $article_map[$articolo_id] = $label;
    }

    $messages = [];
    $warning_tickets = bsn_get_public_product_open_warning_tickets($product_id);
    foreach ($warning_tickets as $ticket) {
        $articolo_id = intval($ticket['articolo_id'] ?? 0);
        $label = $article_map[$articolo_id] ?? ('Articolo #' . $articolo_id);
        $note = trim((string) ($ticket['note'] ?? ''));
        $qty = max(1, intval($ticket['qty'] ?? 1));

        if ($note !== '') {
            $messages[] = $qty . 'x ' . $label . ': ' . $note;
        } else {
            $messages[] = $qty . 'x ' . $label . ': presente ticket aperto ma articolo ancora utilizzabile.';
        }
    }

    return array_values(array_unique($messages));
}

function bsn_is_article_publicly_rentable($articolo) {
    $stato = '';
    if (is_array($articolo)) {
        $stato = (string) ($articolo['stato_utilizzabilita'] ?? '');
    } elseif (is_object($articolo)) {
        $stato = (string) ($articolo->stato_utilizzabilita ?? '');
    }

    $stato = bsn_normalize_articolo_stato_utilizzabilita($stato);
    return !in_array($stato, [ 'non_noleggiabile', 'manutenzione' ], true);
}

function bsn_get_overlapping_rental_usage_map($article_ids, $data_ritiro = '', $data_riconsegna = '', $exclude_noleggio_id = '') {
    global $wpdb;

    $article_ids = array_values(array_unique(array_filter(array_map('intval', (array) $article_ids))));
    if (empty($article_ids)) {
        return [ 'qty_map' => [], 'noleggio_ids' => [] ];
    }

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $data_ritiro_full = bsn_normalize_date_input($data_ritiro, false);
    $data_riconsegna_full = bsn_normalize_date_input($data_riconsegna, true);

    $statuses = bsn_get_blocking_rental_statuses();
    $status_sql = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";

    if ($data_ritiro_full && $data_riconsegna_full) {
        if (!empty($exclude_noleggio_id)) {
            $sql = "SELECT id, articoli
                    FROM $table_noleggi
                    WHERE id <> %s
                      AND COALESCE(data_ritiro, data_inizio) <= %s
                      AND COALESCE(data_riconsegna, data_fine) >= %s
                      AND stato IN ($status_sql)";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $exclude_noleggio_id, $data_riconsegna_full, $data_ritiro_full));
        } else {
            $sql = "SELECT id, articoli
                    FROM $table_noleggi
                    WHERE COALESCE(data_ritiro, data_inizio) <= %s
                      AND COALESCE(data_riconsegna, data_fine) >= %s
                      AND stato IN ($status_sql)";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $data_riconsegna_full, $data_ritiro_full));
        }
    } else {
        if (!empty($exclude_noleggio_id)) {
            $sql = "SELECT id, articoli FROM $table_noleggi WHERE id <> %s AND stato IN ($status_sql)";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $exclude_noleggio_id));
        } else {
            $sql = "SELECT id, articoli FROM $table_noleggi WHERE stato IN ($status_sql)";
            $rows = $wpdb->get_results($sql);
        }
    }

    $qty_map = array_fill_keys($article_ids, 0);
    $noleggio_ids = [];

    foreach ((array) $rows as $row) {
        $noleggio_ids[] = $row->id;
        $articoli = json_decode((string) $row->articoli, true);
        if (!is_array($articoli)) {
            continue;
        }

        foreach ($articoli as $articolo) {
            $articolo_id = isset($articolo['id']) ? intval($articolo['id']) : 0;
            $qty = isset($articolo['qty']) ? intval($articolo['qty']) : 0;
            if ($articolo_id > 0 && $qty > 0 && isset($qty_map[$articolo_id])) {
                $qty_map[$articolo_id] += $qty;
            }
        }
    }

    if (!empty($noleggio_ids)) {
        $kit_usage_map = bsn_get_kit_component_usage_map($noleggio_ids);
        foreach ($kit_usage_map as $articolo_id => $qty) {
            $articolo_id = intval($articolo_id);
            if (isset($qty_map[$articolo_id])) {
                $qty_map[$articolo_id] += intval($qty);
            }
        }
    }

    return [
        'qty_map' => $qty_map,
        'noleggio_ids' => $noleggio_ids,
    ];
}

function bsn_get_effective_article_available_qty($articolo, $periodo = []) {
    $base_qty = 0;
    $articolo_id = 0;

    if (is_array($articolo)) {
        $base_qty = intval($articolo['qty_disponibile'] ?? 0);
        $articolo_id = intval($articolo['id'] ?? 0);
    } elseif (is_object($articolo)) {
        $base_qty = intval($articolo->qty_disponibile ?? 0);
        $articolo_id = intval($articolo->id ?? 0);
    }

    if ($articolo_id < 1 || $base_qty < 1 || !bsn_is_article_publicly_rentable($articolo)) {
        return 0;
    }

    $data_ritiro = (string) ($periodo['data_ritiro'] ?? '');
    $data_riconsegna = (string) ($periodo['data_riconsegna'] ?? '');
    $exclude_noleggio_id = (string) ($periodo['exclude_noleggio_id'] ?? '');

    if ($data_ritiro === '' || $data_riconsegna === '') {
        return $base_qty;
    }

    $usage = bsn_get_overlapping_rental_usage_map([ $articolo_id ], $data_ritiro, $data_riconsegna, $exclude_noleggio_id);
    $used_qty = intval($usage['qty_map'][$articolo_id] ?? 0);

    return max(0, $base_qty - $used_qty);
}

function bsn_get_public_product_availability($product_id, $data_ritiro = '', $data_riconsegna = '', $cliente_id = null) {
    $product_id = absint($product_id);
    if ($product_id < 1) {
        return [
            'product_id' => 0,
            'badge' => 'Non disponibile',
            'badge_marketing' => 'Non disponibile',
            'is_available' => false,
            'available_units' => 0,
            'total_units' => 0,
            'available_seriale' => 0,
            'available_quantita' => 0,
            'article_count' => 0,
        ];
    }

    $articoli = bsn_get_public_product_articles($product_id);
    $meta = bsn_get_public_product_meta($product_id);
    $periodo = [
        'data_ritiro' => $data_ritiro,
        'data_riconsegna' => $data_riconsegna,
    ];

    $available_units = 0;
    $total_units = 0;
    $available_seriale = 0;
    $available_quantita = 0;
    $rentable_article_count = 0;
    $warning_messages = bsn_get_public_product_warning_messages($product_id);
    $has_warning = !empty($warning_messages);

    foreach ($articoli as $articolo) {
        if (!bsn_is_article_publicly_rentable($articolo)) {
            continue;
        }

        $rentable_article_count++;
        $inventory_mode = bsn_normalize_articolo_inventory_mode($articolo['inventory_mode'] ?? '', intval($articolo['qty_disponibile'] ?? 1));
        $base_qty = max(0, intval($articolo['qty_disponibile'] ?? 0));
        $effective_qty = bsn_get_effective_article_available_qty($articolo, $periodo);

        if ($inventory_mode === 'quantita') {
            $total_units += $base_qty;
            $available_units += $effective_qty;
            $available_quantita += $effective_qty;
        } else {
            $serial_total = max(1, $base_qty > 0 ? 1 : 0);
            $serial_available = $effective_qty > 0 ? 1 : 0;
            $total_units += $serial_total;
            $available_units += $serial_available;
            $available_seriale += $serial_available;
        }
    }

    $is_available = $available_units > 0;
    if (!$is_available) {
        $badge = 'Non disponibile';
    } elseif ($has_warning) {
        $badge = 'Disponibile con warning';
    } elseif ($total_units > 0 && $available_units < $total_units) {
        $badge = 'Disponibilita limitata';
    } else {
        $badge = 'Disponibile';
    }

    $badge_marketing = trim((string) ($meta['override_disponibilita'] ?? ''));
    if ($badge_marketing === '') {
        $badge_marketing = $badge;
    }

    return [
        'product_id' => $product_id,
        'badge' => $badge,
        'badge_marketing' => $badge_marketing,
        'is_available' => $is_available,
        'available_units' => $available_units,
        'total_units' => $total_units,
        'available_seriale' => $available_seriale,
        'available_quantita' => $available_quantita,
        'article_count' => count($articoli),
        'rentable_article_count' => $rentable_article_count,
        'has_warning' => $has_warning,
        'warning_count' => count($warning_messages),
        'warning_messages' => $warning_messages,
        'periodo' => [
            'data_ritiro' => $data_ritiro,
            'data_riconsegna' => $data_riconsegna,
        ],
    ];
}

function bsn_register_public_catalog_types() {
    $product_labels = [
        'name'                  => 'Prodotti pubblici',
        'singular_name'         => 'Prodotto pubblico',
        'menu_name'             => 'Prodotti pubblici',
        'name_admin_bar'        => 'Prodotto pubblico',
        'add_new'               => 'Aggiungi nuovo',
        'add_new_item'          => 'Aggiungi prodotto pubblico',
        'edit_item'             => 'Modifica prodotto pubblico',
        'new_item'              => 'Nuovo prodotto pubblico',
        'view_item'             => 'Vedi prodotto pubblico',
        'search_items'          => 'Cerca prodotti pubblici',
        'not_found'             => 'Nessun prodotto pubblico trovato',
        'not_found_in_trash'    => 'Nessun prodotto pubblico nel cestino',
        'all_items'             => 'Tutti i prodotti pubblici',
        'archives'              => 'Archivio prodotti pubblici',
        'attributes'            => 'Attributi prodotto pubblico',
        'insert_into_item'      => 'Inserisci nel prodotto pubblico',
        'uploaded_to_this_item' => 'Caricato in questo prodotto pubblico',
    ];

    $product_caps = [
        'edit_post'              => 'bsn_manage_noleggi',
        'read_post'              => 'bsn_manage_noleggi',
        'delete_post'            => 'bsn_manage_noleggi',
        'edit_posts'             => 'bsn_manage_noleggi',
        'edit_others_posts'      => 'bsn_manage_noleggi',
        'publish_posts'          => 'bsn_manage_noleggi',
        'read_private_posts'     => 'bsn_manage_noleggi',
        'delete_posts'           => 'bsn_manage_noleggi',
        'delete_private_posts'   => 'bsn_manage_noleggi',
        'delete_published_posts' => 'bsn_manage_noleggi',
        'delete_others_posts'    => 'bsn_manage_noleggi',
        'edit_private_posts'     => 'bsn_manage_noleggi',
        'edit_published_posts'   => 'bsn_manage_noleggi',
        'create_posts'           => 'bsn_manage_noleggi',
    ];

    register_post_type('bs_prodotto', [
        'labels'             => $product_labels,
        'public'             => true,
        'show_ui'            => true,
        'show_in_menu'       => 'bs-noleggi',
        'show_in_rest'       => true,
        'has_archive'        => true,
        'rewrite'            => ['slug' => 'catalogo-noleggio', 'with_front' => false],
        'menu_position'      => 25,
        'supports'           => ['title', 'editor', 'excerpt', 'thumbnail', 'page-attributes'],
        'capabilities'       => $product_caps,
        'map_meta_cap'       => false,
        'publicly_queryable' => true,
        'exclude_from_search'=> false,
    ]);

    $taxonomy_labels = [
        'name'              => 'Categorie prodotto',
        'singular_name'     => 'Categoria prodotto',
        'search_items'      => 'Cerca categorie prodotto',
        'all_items'         => 'Tutte le categorie prodotto',
        'parent_item'       => 'Categoria genitore',
        'parent_item_colon' => 'Categoria genitore:',
        'edit_item'         => 'Modifica categoria prodotto',
        'update_item'       => 'Aggiorna categoria prodotto',
        'add_new_item'      => 'Aggiungi categoria prodotto',
        'new_item_name'     => 'Nuova categoria prodotto',
        'menu_name'         => 'Categorie prodotto',
    ];

    register_taxonomy('bs_categoria_prodotto', ['bs_prodotto'], [
        'labels'            => $taxonomy_labels,
        'public'            => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_admin_column' => true,
        'hierarchical'      => true,
        'rewrite'           => ['slug' => 'categoria-noleggio', 'with_front' => false],
        'capabilities'      => [
            'manage_terms' => 'bsn_manage_noleggi',
            'edit_terms'   => 'bsn_manage_noleggi',
            'delete_terms' => 'bsn_manage_noleggi',
            'assign_terms' => 'bsn_manage_noleggi',
        ],
    ]);
}
add_action('init', 'bsn_register_public_catalog_types', 5);

function bsn_activate_public_catalog_types() {
    bsn_register_public_catalog_types();
    bsn_ensure_default_public_product_categories();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'bsn_activate_public_catalog_types');

function bsn_ensure_default_public_product_categories() {
    if (!taxonomy_exists('bs_categoria_prodotto')) {
        return;
    }

    $default_terms = [
        'audio'                => 'Audio',
        'video'                => 'Video',
        'ledwall'              => 'LEDWALL',
        'luci'                 => 'Luci',
        'strutture'            => 'Strutture',
        'effetti-speciali'     => 'Effetti speciali',
        'dj-strumenti-musicali'=> 'DJ & Strumenti musicali',
        'accessori'            => 'Accessori',
        'arredamento'          => 'Arredamento',
    ];

    foreach ($default_terms as $slug => $name) {
        if (term_exists($slug, 'bs_categoria_prodotto')) {
            continue;
        }

        wp_insert_term($name, 'bs_categoria_prodotto', ['slug' => $slug]);
    }
}
add_action('init', 'bsn_ensure_default_public_product_categories', 20);

function bsn_cleanup_legacy_public_product_categories() {
    if (!taxonomy_exists('bs_categoria_prodotto')) {
        return;
    }

    $dj_root = get_term_by('slug', 'dj-strumenti-musicali', 'bs_categoria_prodotto');
    if (!$dj_root || is_wp_error($dj_root)) {
        return;
    }

    $legacy_root = get_term_by('slug', 'strumenti-musicali', 'bs_categoria_prodotto');
    if ($legacy_root && !is_wp_error($legacy_root) && intval($legacy_root->parent) === 0 && intval($legacy_root->count) === 0) {
        $children = get_terms([
            'taxonomy'   => 'bs_categoria_prodotto',
            'hide_empty' => false,
            'parent'     => intval($legacy_root->term_id),
            'number'     => 1,
            'fields'     => 'ids',
        ]);

        if (!is_wp_error($children) && empty($children)) {
            wp_delete_term(intval($legacy_root->term_id), 'bs_categoria_prodotto');
        }
    }

    $child_terms = get_terms([
        'taxonomy'   => 'bs_categoria_prodotto',
        'hide_empty' => false,
        'parent'     => intval($dj_root->term_id),
        'name'       => 'Strumenti musicali',
        'number'     => 1,
    ]);

    if (is_wp_error($child_terms) || empty($child_terms)) {
        return;
    }

    $child = $child_terms[0];
    if ($child->slug !== 'strumenti-musicali' && !term_exists('strumenti-musicali', 'bs_categoria_prodotto')) {
        wp_update_term(intval($child->term_id), 'bs_categoria_prodotto', ['slug' => 'strumenti-musicali']);
    }
}
add_action('init', 'bsn_cleanup_legacy_public_product_categories', 21);

function bsn_sanitize_categoria_prodotto_image_id_meta($value) {
    return absint($value);
}

function bsn_sanitize_categoria_prodotto_text_meta($value) {
    return sanitize_text_field((string) $value);
}

function bsn_sanitize_categoria_prodotto_order_meta($value) {
    return intval($value);
}

function bsn_register_categoria_prodotto_term_meta() {
    register_term_meta(
        'bs_categoria_prodotto',
        '_bsn_categoria_immagine_id',
        [
            'type'              => 'integer',
            'single'            => true,
            'sanitize_callback' => 'bsn_sanitize_categoria_prodotto_image_id_meta',
            'default'           => 0,
            'show_in_rest'      => true,
        ]
    );

    register_term_meta(
        'bs_categoria_prodotto',
        '_bsn_categoria_sottotitolo',
        [
            'type'              => 'string',
            'single'            => true,
            'sanitize_callback' => 'bsn_sanitize_categoria_prodotto_text_meta',
            'default'           => '',
            'show_in_rest'      => true,
        ]
    );

    register_term_meta(
        'bs_categoria_prodotto',
        '_bsn_categoria_ordine',
        [
            'type'              => 'integer',
            'single'            => true,
            'sanitize_callback' => 'bsn_sanitize_categoria_prodotto_order_meta',
            'default'           => 0,
            'show_in_rest'      => true,
        ]
    );
}
add_action('init', 'bsn_register_categoria_prodotto_term_meta', 6);

function bsn_user_can_manage_categoria_prodotto_terms() {
    return current_user_can('bsn_manage_noleggi') || current_user_can('manage_options');
}

function bsn_render_categoria_prodotto_image_field($image_id = 0) {
    $image_id = absint($image_id);
    $preview = $image_id ? wp_get_attachment_image($image_id, [120, 120], false, ['class' => 'bsn-categoria-image-preview-img']) : '';
    ?>
    <div class="bsn-categoria-image-field">
        <input type="hidden" class="bsn-categoria-image-id" name="bsn_categoria_immagine_id" value="<?php echo esc_attr($image_id); ?>">
        <div class="bsn-categoria-image-preview<?php echo $preview ? ' has-image' : ''; ?>">
            <?php echo $preview ? $preview : '<span>Nessuna immagine selezionata</span>'; ?>
        </div>
        <p>
            <button type="button" class="button bsn-categoria-image-select">Seleziona immagine</button>
            <button type="button" class="button bsn-categoria-image-remove"<?php echo $image_id ? '' : ' style="display:none;"'; ?>>Rimuovi immagine</button>
        </p>
    </div>
    <?php
}

function bsn_add_categoria_prodotto_fields() {
    if (!bsn_user_can_manage_categoria_prodotto_terms()) {
        return;
    }
    wp_nonce_field('bsn_save_categoria_prodotto_meta', 'bsn_categoria_prodotto_meta_nonce');
    ?>
    <div class="form-field term-bsn-categoria-image-wrap">
        <label>Immagine categoria</label>
        <?php bsn_render_categoria_prodotto_image_field(); ?>
        <p>Immagine di riferimento per template, card e griglie categoria.</p>
    </div>
    <div class="form-field term-bsn-categoria-sottotitolo-wrap">
        <label for="bsn_categoria_sottotitolo">Sottotitolo</label>
        <input type="text" id="bsn_categoria_sottotitolo" name="bsn_categoria_sottotitolo" value="">
        <p>Testo breve opzionale per le future pagine categoria.</p>
    </div>
    <div class="form-field term-bsn-categoria-ordine-wrap">
        <label for="bsn_categoria_ordine">Ordine</label>
        <input type="number" id="bsn_categoria_ordine" name="bsn_categoria_ordine" value="" step="1">
        <p>Numero opzionale per ordinamenti personalizzati. Usa 0 o vuoto per mantenere il fallback alfabetico.</p>
    </div>
    <?php
}
add_action('bs_categoria_prodotto_add_form_fields', 'bsn_add_categoria_prodotto_fields');

function bsn_edit_categoria_prodotto_fields($term) {
    if (!bsn_user_can_manage_categoria_prodotto_terms()) {
        return;
    }

    $term_id = isset($term->term_id) ? absint($term->term_id) : 0;
    $meta = bsn_get_categoria_prodotto_meta($term_id);
    wp_nonce_field('bsn_save_categoria_prodotto_meta', 'bsn_categoria_prodotto_meta_nonce');
    ?>
    <tr class="form-field term-bsn-categoria-image-wrap">
        <th scope="row"><label>Immagine categoria</label></th>
        <td>
            <?php bsn_render_categoria_prodotto_image_field($meta['image_id']); ?>
            <p class="description">Immagine di riferimento per template, card e griglie categoria.</p>
        </td>
    </tr>
    <tr class="form-field term-bsn-categoria-sottotitolo-wrap">
        <th scope="row"><label for="bsn_categoria_sottotitolo">Sottotitolo</label></th>
        <td>
            <input type="text" id="bsn_categoria_sottotitolo" name="bsn_categoria_sottotitolo" value="<?php echo esc_attr($meta['sottotitolo']); ?>" class="regular-text">
            <p class="description">Testo breve opzionale per le future pagine categoria.</p>
        </td>
    </tr>
    <tr class="form-field term-bsn-categoria-ordine-wrap">
        <th scope="row"><label for="bsn_categoria_ordine">Ordine</label></th>
        <td>
            <input type="number" id="bsn_categoria_ordine" name="bsn_categoria_ordine" value="<?php echo esc_attr($meta['ordine']); ?>" step="1" class="small-text">
            <p class="description">Numero opzionale per ordinamenti personalizzati. I valori 1, 2, 3... vengono mostrati prima; 0 o vuoto restano in ordine alfabetico.</p>
        </td>
    </tr>
    <?php
}
add_action('bs_categoria_prodotto_edit_form_fields', 'bsn_edit_categoria_prodotto_fields');

function bsn_save_categoria_prodotto_meta($term_id) {
    $term_id = absint($term_id);
    if (!$term_id || !bsn_user_can_manage_categoria_prodotto_terms()) {
        return;
    }

    $nonce = isset($_POST['bsn_categoria_prodotto_meta_nonce']) && is_scalar($_POST['bsn_categoria_prodotto_meta_nonce'])
        ? sanitize_text_field(wp_unslash($_POST['bsn_categoria_prodotto_meta_nonce']))
        : '';

    if (!$nonce || !wp_verify_nonce($nonce, 'bsn_save_categoria_prodotto_meta')) {
        return;
    }

    $image_id = isset($_POST['bsn_categoria_immagine_id']) && is_scalar($_POST['bsn_categoria_immagine_id'])
        ? absint(wp_unslash($_POST['bsn_categoria_immagine_id']))
        : 0;
    if ($image_id) {
        update_term_meta($term_id, '_bsn_categoria_immagine_id', $image_id);
    } else {
        delete_term_meta($term_id, '_bsn_categoria_immagine_id');
    }

    $sottotitolo = isset($_POST['bsn_categoria_sottotitolo']) && is_scalar($_POST['bsn_categoria_sottotitolo'])
        ? sanitize_text_field(wp_unslash($_POST['bsn_categoria_sottotitolo']))
        : '';
    if ($sottotitolo !== '') {
        update_term_meta($term_id, '_bsn_categoria_sottotitolo', $sottotitolo);
    } else {
        delete_term_meta($term_id, '_bsn_categoria_sottotitolo');
    }

    $ordine_raw = isset($_POST['bsn_categoria_ordine']) && is_scalar($_POST['bsn_categoria_ordine'])
        ? wp_unslash($_POST['bsn_categoria_ordine'])
        : '';
    if ($ordine_raw !== '') {
        update_term_meta($term_id, '_bsn_categoria_ordine', intval($ordine_raw));
    } else {
        delete_term_meta($term_id, '_bsn_categoria_ordine');
    }
}
add_action('created_bs_categoria_prodotto', 'bsn_save_categoria_prodotto_meta');
add_action('edited_bs_categoria_prodotto', 'bsn_save_categoria_prodotto_meta');

function bsn_enqueue_categoria_prodotto_admin_assets($hook) {
    if (!in_array($hook, ['edit-tags.php', 'term.php'], true)) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || $screen->taxonomy !== 'bs_categoria_prodotto') {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script(
        'bsn-admin-categoria-prodotto',
        BSN_URL . 'assets/js/admin-categoria-prodotto.js',
        ['jquery'],
        BSN_VERSION,
        true
    );
    wp_localize_script(
        'bsn-admin-categoria-prodotto',
        'bsnCategoriaProdottoMedia',
        [
            'title'      => 'Seleziona immagine categoria',
            'buttonText' => 'Usa questa immagine',
            'emptyText'  => 'Nessuna immagine selezionata',
        ]
    );

    wp_register_style('bsn-admin-categoria-prodotto', false, [], BSN_VERSION);
    wp_enqueue_style('bsn-admin-categoria-prodotto');
    wp_add_inline_style(
        'bsn-admin-categoria-prodotto',
        '.bsn-categoria-image-preview{width:120px;min-height:80px;border:1px solid #c3c4c7;background:#fff;display:flex;align-items:center;justify-content:center;color:#646970;text-align:center;padding:8px;box-sizing:border-box}.bsn-categoria-image-preview.has-image{padding:0}.bsn-categoria-image-preview img{display:block;width:120px;height:120px;object-fit:cover}.column-bsn_categoria_immagine{width:80px}.bsn-categoria-thumbnail{width:50px;height:50px;object-fit:cover;border-radius:4px;background:#f0f0f1;display:block}'
    );
}
add_action('admin_enqueue_scripts', 'bsn_enqueue_categoria_prodotto_admin_assets');

function bsn_categoria_prodotto_admin_columns($columns) {
    $new_columns = [];
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'cb') {
            $new_columns['bsn_categoria_immagine'] = 'Immagine';
        }
    }

    if (!isset($new_columns['bsn_categoria_immagine'])) {
        $new_columns['bsn_categoria_immagine'] = 'Immagine';
    }

    return $new_columns;
}
add_filter('manage_edit-bs_categoria_prodotto_columns', 'bsn_categoria_prodotto_admin_columns');

function bsn_categoria_prodotto_admin_column_content($content, $column_name, $term_id) {
    if ($column_name !== 'bsn_categoria_immagine') {
        return $content;
    }

    $image_id = absint(get_term_meta($term_id, '_bsn_categoria_immagine_id', true));
    if (!$image_id) {
        return '&mdash;';
    }

    $image = wp_get_attachment_image($image_id, [50, 50], false, ['class' => 'bsn-categoria-thumbnail']);
    return $image ? $image : '&mdash;';
}
add_filter('manage_bs_categoria_prodotto_custom_column', 'bsn_categoria_prodotto_admin_column_content', 10, 3);

function bsn_add_public_product_meta_boxes() {
    add_meta_box(
        'bsn-public-product-details',
        'Dettagli prodotto pubblico',
        'bsn_render_public_product_meta_box',
        'bs_prodotto',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes_bs_prodotto', 'bsn_add_public_product_meta_boxes');

function bsn_render_public_product_meta_box($post) {
    $meta = bsn_get_public_product_meta($post->ID);
    wp_nonce_field('bsn_save_public_product_meta', 'bsn_public_product_meta_nonce');

    // Dati prodotti per il selettore consigliati (esclude il prodotto corrente)
    $all_products = get_posts( [
        'post_type'      => 'bs_prodotto',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'exclude'        => [ $post->ID ],
        'fields'         => 'all',
    ] );
    $prodotti_data = array_map( function( $p ) {
        return [ 'id' => $p->ID, 'title' => $p->post_title ];
    }, $all_products );
    ?>
    <script>window.bsnProdottiDisponibili = <?php echo wp_json_encode( $prodotti_data ); ?>;</script>
    <p>Questi campi definiscono il layer commerciale del prodotto pubblico. Le logiche di disponibilita, pricing e noleggio resteranno agganciate agli articoli interni.</p>
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><label for="bsn-badge-trasporto">Badge trasporto/ritiro</label></th>
                <td>
                    <input type="text" id="bsn-badge-trasporto" name="bsn_public_product[badge_trasporto]" class="regular-text" value="<?php echo esc_attr($meta['badge_trasporto']); ?>">
                    <p class="description">Esempio: Ritiro in sede, Trasporto consigliato, Montaggio consigliato.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-override-disponibilita">Override disponibilita marketing</label></th>
                <td>
                    <input type="text" id="bsn-override-disponibilita" name="bsn_public_product[override_disponibilita]" class="regular-text" value="<?php echo esc_attr($meta['override_disponibilita']); ?>">
                    <p class="description">Testo opzionale che in futuro puo sostituire il badge automatico di disponibilita pubblica.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-tariffa-label">Etichetta tariffa</label></th>
                <td>
                    <input type="text" id="bsn-tariffa-label" name="bsn_public_product[tariffa_label]" class="regular-text" value="<?php echo esc_attr($meta['tariffa_label']); ?>">
                    <p class="description">Lascia vuoto per "Tariffa 1 giorno". Esempi: al metro quadro, al litro, a consumo.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Calcolatrice dimensionale</th>
                <td>
                    <input type="hidden" name="bsn_public_product[dimension_calculator_enabled]" value="0">
                    <label>
                        <input type="checkbox" name="bsn_public_product[dimension_calculator_enabled]" value="1" <?php checked( ! empty( $meta['dimension_calculator_enabled'] ) ); ?>>
                        Abilita calcolatrice dimensionale opzionale
                    </label>
                    <p class="description">Il campo quantita resta sempre disponibile. La calcolatrice aiuta solo a compilare la quantita.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-dimension-calculation-type">Tipo calcolo dimensionale</label></th>
                <td>
                    <select id="bsn-dimension-calculation-type" name="bsn_public_product[dimension_calculation_type]" class="regular-text">
                        <?php foreach ( bsn_get_dimension_calculation_type_options() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['dimension_calculation_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Usa "Dimensionale modulare" per LED wall, palchi, pedane o pannelli; "Dimensione libera a mq" per moquette, erba sintetica o teli.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Modulo base</th>
                <td>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <div>
                            <label for="bsn-dimension-module-width-cm">Larghezza modulo (cm)</label><br>
                            <input type="number" step="0.01" min="0" id="bsn-dimension-module-width-cm" name="bsn_public_product[dimension_module_width_cm]" class="small-text" value="<?php echo esc_attr( $meta['dimension_module_width_cm'] ); ?>">
                        </div>
                        <div>
                            <label for="bsn-dimension-module-height-cm">Altezza/profondita modulo (cm)</label><br>
                            <input type="number" step="0.01" min="0" id="bsn-dimension-module-height-cm" name="bsn_public_product[dimension_module_height_cm]" class="small-text" value="<?php echo esc_attr( $meta['dimension_module_height_cm'] ); ?>">
                        </div>
                    </div>
                    <p class="description">Esempio LED wall 50x50: inserire 50 e 50. Esempio pedana 200x100: inserire 200 e 100.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-dimension-presets">Preset dimensioni consigliate</label></th>
                <td>
                    <textarea id="bsn-dimension-presets" name="bsn_public_product[dimension_presets]" class="large-text" rows="4"><?php echo esc_textarea( $meta['dimension_presets'] ); ?></textarea>
                    <p class="description">Formato semplice JSON o una riga per preset. Esempio JSON: [{"label":"4,5x2,5","width_m":4.5,"height_m":2.5,"note":"16:9 circa"}]</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-dimension-customer-note">Nota cliente dimensionale</label></th>
                <td>
                    <textarea id="bsn-dimension-customer-note" name="bsn_public_product[dimension_customer_note]" class="large-text" rows="2"><?php echo esc_textarea( $meta['dimension_customer_note'] ); ?></textarea>
                    <p class="description">Nota breve opzionale mostrabile vicino alla calcolatrice.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-sede-operativa">Sede operativa</label></th>
                <td>
                    <input type="text" id="bsn-sede-operativa" name="bsn_public_product[sede_operativa]" class="regular-text" value="<?php echo esc_attr($meta['sede_operativa']); ?>">
                    <p class="description">Testo da mostrare in evidenza nella scheda prodotto pubblica, ad esempio: Brescia.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-sottotitolo-catalogo">Sottotitolo / descrizione breve SEO</label></th>
                <td>
                    <input type="text" id="bsn-sottotitolo-catalogo" name="bsn_public_product[sottotitolo_catalogo]" class="regular-text" value="<?php echo esc_attr($meta['sottotitolo_catalogo']); ?>">
                    <p class="description">Esempio: Traliccio in alluminio 29x29, compatibile Unirig. Va tenuto leggibile, non come elenco caotico di keyword.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-alias-ricerca">Sinonimi / nomi alternativi</label></th>
                <td>
                    <textarea id="bsn-alias-ricerca" name="bsn_public_product[alias_ricerca]" class="large-text" rows="3"><?php echo esc_textarea($meta['alias_ricerca']); ?></textarea>
                    <p class="description">Una parola o variante per riga, oppure separate da virgola. Esempio: traliccio, truss, americana, Unirig, 29x29.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-utilizzo-consigliato">Utilizzo consigliato</label></th>
                <td>
                    <textarea id="bsn-utilizzo-consigliato" name="bsn_public_product[utilizzo_consigliato]" class="large-text" rows="5"><?php echo esc_textarea($meta['utilizzo_consigliato']); ?></textarea>
                    <p class="description">Risponde alla domanda: in che contesto si usa questo articolo? Serve ai clienti, a OpenClaw/Cerebro e al futuro configuratore eventi. Non usare per prezzi, disponibilita, condizioni commerciali o promesse operative.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-gallery-urls">Galleria immagini</label></th>
                <td>
                    <textarea id="bsn-gallery-urls" name="bsn_public_product[gallery_urls]" class="large-text" rows="4"><?php echo esc_textarea($meta['gallery_urls']); ?></textarea>
                    <p style="margin-top:6px;">
                        <button type="button" class="button" id="bsn-gallery-select-media">Seleziona dalla libreria media</button>
                    </p>
                    <p class="description">Una URL per riga. Il pulsante consente la selezione multipla dalla Media Library. La prima immagine sara usata come principale nel catalogo.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-video-urls">Video URL multipli</label></th>
                <td>
                    <textarea id="bsn-video-urls" name="bsn_public_product[video_urls]" class="large-text" rows="4"><?php echo esc_textarea($meta['video_urls']); ?></textarea>
                    <p class="description">Una URL per riga, ad esempio YouTube o Vimeo.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-specifiche-tecniche">Specifiche tecniche</label></th>
                <td>
                    <textarea id="bsn-specifiche-tecniche" name="bsn_public_product[specifiche_tecniche]" class="large-text" rows="6"><?php echo esc_textarea($meta['specifiche_tecniche']); ?></textarea>
                    <p class="description">Testo libero o elenco tecnico, da usare nella futura tab dedicata.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-faq">FAQ</label></th>
                <td>
                    <textarea id="bsn-faq" name="bsn_public_product[faq]" class="large-text" rows="5"><?php echo esc_textarea($meta['faq']); ?></textarea>
                    <p class="description">Formato libero, una FAQ per paragrafo.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Prodotti consigliati commerciali</label></th>
                <td>
                    <input type="hidden" name="bsn_public_product[consigliati_ids]" id="bsn-consigliati-ids-input" value="<?php echo esc_attr($meta['consigliati_ids']); ?>">
                    <div id="bsn-consigliati-pills" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; min-height:28px;"></div>
                    <div style="position:relative; max-width:400px;">
                        <input type="text" id="bsn-consigliati-search" placeholder="Cerca prodotto per nome..." class="regular-text" autocomplete="off" style="width:100%;">
                        <div id="bsn-consigliati-results" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ccc; border-top:none; border-radius:0 0 4px 4px; max-height:220px; overflow-y:auto; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>
                    </div>
                    <p class="description">Prodotti pubblici da proporre insieme a questo articolo. Esempio: americana/truss → elevatori, basi; mixer → stagebox, cavi.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-correlati-commerciali">Correlati commerciali</label></th>
                <td>
                    <textarea id="bsn-correlati-commerciali" name="bsn_public_product[correlati_commerciali]" class="large-text" rows="4"><?php echo esc_textarea($meta['correlati_commerciali']); ?></textarea>
                    <p class="description">Separati dai correlati operativi interni gia presenti sul gestionale.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Download</th>
                <td>
                    <input type="hidden" name="bsn_public_product[downloads]" id="bsn-downloads-input" value="<?php echo esc_attr($meta['downloads']); ?>">
                    <div id="bsn-downloads-list" style="margin-bottom:8px;"></div>
                    <button type="button" class="button" id="bsn-downloads-add">+ Aggiungi download</button>
                    <p class="description">Documenti o file da mostrare nella scheda prodotto pubblica. Esempio: manuali PDF, schede tecniche, schede sicurezza, software.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Dati logistici canonici</th>
                <td>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <div style="flex:1 1 140px;">
                            <label for="bsn-prodotto-larghezza">Larghezza (cm)</label>
                            <input type="number" step="0.01" min="0" id="bsn-prodotto-larghezza" name="bsn_public_product[larghezza_cm]" class="small-text" value="<?php echo esc_attr($meta['larghezza_cm']); ?>">
                        </div>
                        <div style="flex:1 1 140px;">
                            <label for="bsn-prodotto-altezza">Altezza (cm)</label>
                            <input type="number" step="0.01" min="0" id="bsn-prodotto-altezza" name="bsn_public_product[altezza_cm]" class="small-text" value="<?php echo esc_attr($meta['altezza_cm']); ?>">
                        </div>
                        <div style="flex:1 1 140px;">
                            <label for="bsn-prodotto-profondita">Profondita (cm)</label>
                            <input type="number" step="0.01" min="0" id="bsn-prodotto-profondita" name="bsn_public_product[profondita_cm]" class="small-text" value="<?php echo esc_attr($meta['profondita_cm']); ?>">
                        </div>
                        <div style="flex:1 1 140px;">
                            <label for="bsn-prodotto-peso">Peso (kg)</label>
                            <input type="number" step="0.01" min="0" id="bsn-prodotto-peso" name="bsn_public_product[peso_kg]" class="small-text" value="<?php echo esc_attr($meta['peso_kg']); ?>">
                        </div>
                    </div>
                    <div style="margin-top:10px; max-width:280px;">
                        <label for="bsn-prodotto-veicolo">Veicolo minimo consigliato</label>
                        <select id="bsn-prodotto-veicolo" name="bsn_public_product[veicolo_minimo]" class="regular-text">
                            <?php foreach ( bsn_get_articolo_veicolo_minimo_options() as $value => $label ) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($meta['veicolo_minimo'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="description">Questi dati descrivono il prodotto pubblico in modo univoco e possono essere riusati dagli articoli collegati.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsn-disclaimer-preventivo">Disclaimer preventivo</label></th>
                <td>
                    <textarea id="bsn-disclaimer-preventivo" name="bsn_public_product[disclaimer_preventivo]" class="large-text" rows="4"><?php echo esc_textarea($meta['disclaimer_preventivo']); ?></textarea>
                    <p class="description">Testo commerciale o logistico mostrabile nella futura scheda prodotto o nel carrello preventivo.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Preview disponibilita</th>
                <td>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:10px;">
                        <div>
                            <label for="bsn-public-preview-data-ritiro">Data ritiro</label><br>
                            <input type="date" id="bsn-public-preview-data-ritiro" style="min-width:160px;">
                        </div>
                        <div>
                            <label for="bsn-public-preview-data-riconsegna">Data riconsegna</label><br>
                            <input type="date" id="bsn-public-preview-data-riconsegna" style="min-width:160px;">
                        </div>
                        <div>
                            <button type="button" class="button" id="bsn-public-preview-check">Verifica disponibilita</button>
                            <button type="button" class="button button-secondary" id="bsn-public-preview-reset">Reset</button>
                        </div>
                    </div>
                    <div id="bsn-public-preview-result">
                        <?php echo bsn_render_public_product_availability_html( $post->ID ); ?>
                    </div>
                    <p class="description">Questa preview usa il motore reale di disponibilita pubblica. Se imposti le date, la verifica considera noleggi bloccanti e periodo logistico.</p>
                    <script>
                    (function(){
                        var root = <?php echo wp_json_encode( esc_url_raw( rest_url( 'bsn/v1/' ) ) ); ?>;
                        var productId = <?php echo (int) $post->ID; ?>;
                        var $check = document.getElementById('bsn-public-preview-check');
                        var $reset = document.getElementById('bsn-public-preview-reset');
                        var $ritiro = document.getElementById('bsn-public-preview-data-ritiro');
                        var $riconsegna = document.getElementById('bsn-public-preview-data-riconsegna');
                        var $result = document.getElementById('bsn-public-preview-result');

                        if (!$check || !$reset || !$ritiro || !$riconsegna || !$result || !root || !productId) {
                            return;
                        }

                        function getTheme(badge) {
                            if (badge === 'Disponibile') {
                                return { bg: '#eef9f0', border: '#9fd5ab', text: '#1f6b33' };
                            }
                            if (badge === 'Disponibilita limitata') {
                                return { bg: '#fff7e8', border: '#f2c980', text: '#8a5a00' };
                            }
                            return { bg: '#fff0f0', border: '#e2aaaa', text: '#8f1f1f' };
                        }

                        function renderAvailability(data, hasDates) {
                            var badge = data.badge_marketing || data.badge || 'Non disponibile';
                            var actualBadge = data.badge || 'Non disponibile';
                            var availableUnits = parseInt(data.available_units || 0, 10);
                            var totalUnits = parseInt(data.total_units || 0, 10);
                            var theme = getTheme(actualBadge);
                            var html = '';
                            html += '<div class="bsn-public-availability" style="margin:0 0 16px; padding:12px 14px; border-radius:10px; background:' + theme.bg + '; border:1px solid ' + theme.border + ';">';
                            html += '<div style="font-size:0.95em; font-weight:700; margin-bottom:6px;">Disponibilita</div>';
                            html += '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">';
                            html += '<span style="display:inline-block; padding:4px 10px; border-radius:999px; background:#fff; border:1px solid ' + theme.border + '; color:' + theme.text + '; font-weight:700;">' + badge + '</span>';
                            if (totalUnits > 0) {
                                html += '<span style="font-size:0.95em; color:#445064;">' + availableUnits + ' / ' + totalUnits + ' unita disponibili</span>';
                            }
                            html += '<span style="font-size:0.92em; color:#5d697a;">' + (hasDates ? ('Periodo verificato: ' + ($ritiro.value || '-') + ' - ' + ($riconsegna.value || '-')) : 'Disponibilita attuale senza filtro date.') + '</span>';
                            html += '</div></div>';
                            $result.innerHTML = html;
                        }

                        $check.addEventListener('click', function() {
                            var params = new URLSearchParams({ product_id: String(productId) });
                            if ($ritiro.value) params.set('data_ritiro', $ritiro.value);
                            if ($riconsegna.value) params.set('data_riconsegna', $riconsegna.value);

                            $check.disabled = true;
                            $check.textContent = 'Verifica in corso...';

                            fetch(root + 'public-products/availability?' + params.toString(), { credentials: 'same-origin' })
                                .then(function(res){ return res.json(); })
                                .then(function(data){ renderAvailability(data, !!($ritiro.value && $riconsegna.value)); })
                                .catch(function(){
                                    $result.innerHTML = '<div style="padding:12px 14px; border-radius:10px; background:#fff0f0; border:1px solid #e2aaaa; color:#8f1f1f;">Errore nel recupero della disponibilita.</div>';
                                })
                                .finally(function(){
                                    $check.disabled = false;
                                    $check.textContent = 'Verifica disponibilita';
                                });
                        });

                        $reset.addEventListener('click', function() {
                            $ritiro.value = '';
                            $riconsegna.value = '';
                            $check.click();
                        });
                    })();
                    </script>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
}

function bsn_save_public_product_meta($post_id) {
    if (!isset($_POST['bsn_public_product_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bsn_public_product_meta_nonce'])), 'bsn_save_public_product_meta')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('bsn_manage_noleggi')) {
        return;
    }

    if (!isset($_POST['post_type']) || $_POST['post_type'] !== 'bs_prodotto') {
        return;
    }

    $raw = isset($_POST['bsn_public_product']) ? wp_unslash($_POST['bsn_public_product']) : [];
    if (!is_array($raw)) {
        $raw = [];
    }

    $defaults = bsn_get_public_product_meta_defaults();
    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }

        $value = $raw[$key];
        switch ($key) {
            case 'dimension_calculator_enabled':
                $value = ! empty( $value ) ? '1' : '0';
                break;
            case 'dimension_calculation_type':
                $value = bsn_validate_dimension_calculation_type( $value );
                break;
            case 'dimension_module_width_cm':
            case 'dimension_module_height_cm':
                $value = $value === '' ? '' : (string) max( 0, floatval( $value ) );
                break;
            case 'dimension_presets':
            case 'dimension_customer_note':
                $value = sanitize_textarea_field( (string) $value );
                break;
            case 'larghezza_cm':
            case 'altezza_cm':
            case 'profondita_cm':
            case 'peso_kg':
                $value = $value === '' ? '' : (string) floatval($value);
                break;
            case 'veicolo_minimo':
                $normalized = sanitize_key((string) $value);
                $value = array_key_exists($normalized, bsn_get_articolo_veicolo_minimo_options()) ? $normalized : '';
                break;
            case 'consigliati_ids':
                $arr = json_decode($value, true);
                if (!is_array($arr)) $arr = [];
                $arr = array_values(array_filter(array_map('absint', $arr)));
                $value = wp_json_encode($arr);
                break;
            case 'downloads':
                $arr = json_decode($value, true);
                if (!is_array($arr)) $arr = [];
                $clean = [];
                foreach ($arr as $item) {
                    if (!is_array($item)) continue;
                    $dl_url = esc_url_raw(trim((string)($item['url'] ?? '')));
                    if ($dl_url === '') continue;
                    $clean[] = [
                        'titolo' => sanitize_text_field((string)($item['titolo'] ?? '')),
                        'url'    => $dl_url,
                        'tipo'   => sanitize_key((string)($item['tipo'] ?? '')),
                    ];
                }
                $value = wp_json_encode($clean);
                break;
            case 'utilizzo_consigliato':
                $value = is_scalar($value) ? trim((string) wp_kses_post($value)) : '';
                break;
            default:
                $value = sanitize_textarea_field($value);
                break;
        }
        update_post_meta($post_id, '_bsn_' . $key, $value);
    }
}
add_action('save_post_bs_prodotto', 'bsn_save_public_product_meta');

function bsn_render_public_product_location_notice( $content ) {
    if ( is_admin() || ! is_singular( 'bs_prodotto' ) || ! in_the_loop() || ! is_main_query() ) {
        return $content;
    }

    if ( bsn_locate_public_catalog_template( 'single-bs_prodotto.php' ) ) {
        return $content;
    }

    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return $content;
    }

    $meta = bsn_get_public_product_meta( $post_id );
    $sede = trim( (string) ( $meta['sede_operativa'] ?? '' ) );
    $sottotitolo = trim( (string) ( $meta['sottotitolo_catalogo'] ?? '' ) );
    $alias_raw = trim( (string) ( $meta['alias_ricerca'] ?? '' ) );
    $alias_items = preg_split( '/[\r\n,]+/', $alias_raw );
    $alias_items = array_values( array_filter( array_map( 'trim', is_array( $alias_items ) ? $alias_items : [] ) ) );
    $data_ritiro = isset( $_GET['data_ritiro'] ) ? sanitize_text_field( wp_unslash( $_GET['data_ritiro'] ) ) : '';
    $data_riconsegna = isset( $_GET['data_riconsegna'] ) ? sanitize_text_field( wp_unslash( $_GET['data_riconsegna'] ) ) : '';

    if ( $sede === '' && $sottotitolo === '' && empty( $alias_items ) ) {
        return bsn_render_public_product_availability_html( $post_id, $data_ritiro, $data_riconsegna, [
            'title' => 'Disponibilita del prodotto',
        ] ) . $content;
    }

    $parts = [];
    $parts[] = bsn_render_public_product_availability_html( $post_id, $data_ritiro, $data_riconsegna, [
        'title' => 'Disponibilita del prodotto',
    ] );
    if ( $sede !== '' ) {
        $parts[] = '<div class="bsn-prodotto-sede" style="margin:0 0 12px; padding:12px 14px; border-radius:10px; background:#f3f6fa; border:1px solid #d7dee8;">'
            . '<strong>Questo articolo si trova presso:</strong> ' . esc_html( $sede )
            . '</div>';
    }

    if ( $sottotitolo !== '' || ! empty( $alias_items ) ) {
        $semantic = '<div class="bsn-prodotto-semantica" style="margin:0 0 16px; padding:12px 14px; border-radius:10px; background:#fff; border:1px solid #e2e8f0;">';
        if ( $sottotitolo !== '' ) {
            $semantic .= '<div style="font-size:1.02em; font-weight:600; margin-bottom:' . ( ! empty( $alias_items ) ? '8px' : '0' ) . ';">' . esc_html( $sottotitolo ) . '</div>';
        }
        if ( ! empty( $alias_items ) ) {
            $semantic .= '<div style="font-size:0.95em; color:#516070;"><strong>Sinonimi e ricerche correlate:</strong> ' . esc_html( implode( ' • ', $alias_items ) ) . '</div>';
        }
        $semantic .= '</div>';
        $parts[] = $semantic;
    }

    return implode( '', $parts ) . $content;
}
add_filter( 'the_content', 'bsn_render_public_product_location_notice', 20 );

add_action('rest_api_init', function () {
    register_rest_route('bsn/v1', '/public-products/availability', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_public_product_availability',
        'permission_callback' => '__return_true',
        'args'                => [
            'product_id' => [ 'required' => true, 'type' => 'integer' ],
            'data_ritiro' => [ 'required' => false, 'type' => 'string' ],
            'data_riconsegna' => [ 'required' => false, 'type' => 'string' ],
        ],
    ]);

    register_rest_route('bsn/v1', '/public-products/quote-preview', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_public_product_quote_preview',
        'permission_callback' => '__return_true',
        'args'                => [
            'product_id' => [ 'required' => true, 'type' => 'integer' ],
            'qty' => [ 'required' => false, 'type' => 'integer' ],
            'data_ritiro' => [ 'required' => false, 'type' => 'string' ],
            'data_riconsegna' => [ 'required' => false, 'type' => 'string' ],
        ],
    ]);

    register_rest_route('bsn/v1', '/cerebro/catalog/search', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_cerebro_catalog_search',
        'permission_callback' => '__return_true',
        'args'                => [
            'q'        => [ 'required' => false, 'type' => 'string' ],
            'category' => [ 'required' => false, 'type' => 'string' ],
            'limit'    => [ 'required' => false, 'type' => 'integer' ],
        ],
    ]);

    register_rest_route('bsn/v1', '/cerebro/availability', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_cerebro_availability',
        'permission_callback' => '__return_true',
        'args'                => [
            'product_id'      => [ 'required' => true, 'type' => 'integer' ],
            'data_ritiro'     => [ 'required' => false, 'type' => 'string' ],
            'data_riconsegna' => [ 'required' => false, 'type' => 'string' ],
        ],
    ]);

    register_rest_route('bsn/v1', '/cerebro/quote-preview', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_cerebro_quote_preview',
        'permission_callback' => '__return_true',
        'args'                => [
            'product_id'      => [ 'required' => true, 'type' => 'integer' ],
            'qty'             => [ 'required' => false, 'type' => 'integer' ],
            'data_ritiro'     => [ 'required' => false, 'type' => 'string' ],
            'data_riconsegna' => [ 'required' => false, 'type' => 'string' ],
        ],
    ]);

    register_rest_route('bsn/v1', '/cerebro/preventivi', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_cerebro_preventivi',
        'permission_callback' => 'bsn_check_admin',
    ]);
});

function bsn_api_public_product_availability( WP_REST_Request $request ) {
    $product_id = absint( $request->get_param( 'product_id' ) );
    if ( $product_id < 1 ) {
        return new WP_Error( 'bsn_public_product_missing', 'Prodotto pubblico non valido.', [ 'status' => 400 ] );
    }

    $data_ritiro = sanitize_text_field( (string) $request->get_param( 'data_ritiro' ) );
    $data_riconsegna = sanitize_text_field( (string) $request->get_param( 'data_riconsegna' ) );
    $availability = bsn_get_public_product_availability( $product_id, $data_ritiro, $data_riconsegna );
    $cart = bsn_get_quote_cart();
    $cart_reserved_qty = bsn_get_quote_cart_reserved_qty_for_product( $product_id, $cart );
    $available_units_raw = intval( $availability['available_units'] ?? 0 );

    $availability['available_units_raw'] = $available_units_raw;
    $availability['cart_reserved_qty'] = $cart_reserved_qty;
    $availability['available_units'] = max( 0, $available_units_raw - $cart_reserved_qty );

    return rest_ensure_response( $availability );
}

function bsn_api_public_product_quote_preview( WP_REST_Request $request ) {
    $product_id = absint( $request->get_param( 'product_id' ) );
    if ( $product_id < 1 ) {
        return new WP_Error( 'bsn_public_product_missing', 'Prodotto pubblico non valido.', [ 'status' => 400 ] );
    }

    $qty = max( 1, intval( $request->get_param( 'qty' ) ?: 1 ) );
    $dimension_context = bsn_get_public_product_dimension_request_context( $product_id, $request, $qty );
    if ( ! empty( $dimension_context['errors'] ) ) {
        return rest_ensure_response( [
            'success' => false,
            'message' => implode( ' ', $dimension_context['errors'] ),
            'dimension_errors' => $dimension_context['errors'],
        ] );
    }

    $qty = max( 1, intval( $dimension_context['qty'] ?? $qty ) );
    $data_ritiro = sanitize_text_field( (string) $request->get_param( 'data_ritiro' ) );
    $data_riconsegna = sanitize_text_field( (string) $request->get_param( 'data_riconsegna' ) );
    $categoria_cliente = function_exists( 'bsn_get_current_public_customer_category' ) ? bsn_get_current_public_customer_category() : 'standard';
    $preview = bsn_get_public_product_quote_preview( $product_id, $qty, $data_ritiro, $data_riconsegna, $categoria_cliente );
    if ( is_array( $preview ) && ! empty( $dimension_context['dimension_data'] ) ) {
        $preview['dimension'] = $dimension_context['dimension_data'];
        $preview['dimension_warnings'] = $dimension_context['warnings'];
    }

    return rest_ensure_response( $preview );
}

function bsn_cerebro_format_public_product( WP_Post $product ) {
    $meta = bsn_get_public_product_meta( $product->ID );
    $utilizzo_consigliato = trim( (string) ( $meta['utilizzo_consigliato'] ?? '' ) );
    $terms = get_the_terms( $product->ID, 'bs_categoria_prodotto' );
    $categories = [];

    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        foreach ( $terms as $term ) {
            $categories[] = [
                'id'   => intval( $term->term_id ),
                'slug' => $term->slug,
                'name' => $term->name,
            ];
        }
    }

    return [
        'product_id' => intval( $product->ID ),
        'title'      => get_the_title( $product ),
        'slug'       => $product->post_name,
        'link'       => get_permalink( $product ),
        'categories' => $categories,
        'excerpt'    => wp_strip_all_tags( get_the_excerpt( $product ) ),
        'utilizzo_consigliato' => wp_strip_all_tags( $utilizzo_consigliato ),
    ];
}

function bsn_api_cerebro_catalog_search( WP_REST_Request $request ) {
    $q = sanitize_text_field( (string) $request->get_param( 'q' ) );
    $category = sanitize_title( (string) $request->get_param( 'category' ) );
    $limit = min( 20, max( 1, intval( $request->get_param( 'limit' ) ?: 10 ) ) );

    $args = [
        'post_type'           => 'bs_prodotto',
        'post_status'         => 'publish',
        'posts_per_page'      => $limit,
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
    ];

    if ( $q !== '' ) {
        $args['s'] = $q;
    }

    if ( $category !== '' ) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'bs_categoria_prodotto',
                'field'    => 'slug',
                'terms'    => $category,
            ],
        ];
    }

    $query = new WP_Query( $args );
    $products = [];

    foreach ( $query->posts as $product ) {
        if ( $product instanceof WP_Post ) {
            $products[] = bsn_cerebro_format_public_product( $product );
        }
    }

    if ( $q !== '' && count( $products ) < $limit ) {
        $seen_ids = array_map(
            static function ( $item ) {
                return intval( $item['product_id'] ?? 0 );
            },
            $products
        );
        $meta_args = $args;
        unset( $meta_args['s'] );
        $meta_args['posts_per_page'] = $limit;
        $meta_args['meta_query'] = [
            [
                'key'     => '_bsn_utilizzo_consigliato',
                'value'   => $q,
                'compare' => 'LIKE',
            ],
        ];
        $meta_query = new WP_Query( $meta_args );
        foreach ( $meta_query->posts as $product ) {
            if ( ! ( $product instanceof WP_Post ) || in_array( intval( $product->ID ), $seen_ids, true ) ) {
                continue;
            }
            $products[] = bsn_cerebro_format_public_product( $product );
            $seen_ids[] = intval( $product->ID );
            if ( count( $products ) >= $limit ) {
                break;
            }
        }
    }

    return rest_ensure_response( [
        'success'  => true,
        'query'    => $q,
        'category' => $category,
        'limit'    => $limit,
        'products' => $products,
    ] );
}

function bsn_api_cerebro_availability( WP_REST_Request $request ) {
    return bsn_api_public_product_availability( $request );
}

function bsn_api_cerebro_quote_preview( WP_REST_Request $request ) {
    return bsn_api_public_product_quote_preview( $request );
}

function bsn_api_cerebro_preventivi( WP_REST_Request $request ) {
    global $wpdb;

    $cliente_id = absint( $request->get_param( 'cliente_id' ) );
    $dates = $request->get_param( 'dates' );
    $items = $request->get_param( 'items' );

    if ( $cliente_id < 1 ) {
        return new WP_Error( 'bsn_cerebro_cliente_mancante', 'cliente_id esistente richiesto.', [ 'status' => 400 ] );
    }

    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $cliente = $wpdb->get_row(
        $wpdb->prepare( "SELECT id, categoria_cliente FROM $table_clienti WHERE id = %d LIMIT 1", $cliente_id ),
        ARRAY_A
    );
    if ( ! $cliente ) {
        return new WP_Error( 'bsn_cerebro_cliente_non_trovato', 'Cliente non trovato.', [ 'status' => 404 ] );
    }

    if ( ! is_array( $dates ) ) {
        $dates = [];
    }

    $data_ritiro = sanitize_text_field( (string) ( $dates['data_ritiro'] ?? $request->get_param( 'data_ritiro' ) ) );
    $data_riconsegna = sanitize_text_field( (string) ( $dates['data_riconsegna'] ?? $request->get_param( 'data_riconsegna' ) ) );
    if ( $data_ritiro === '' || $data_riconsegna === '' || bsn_is_invalid_date_range( $data_ritiro, $data_riconsegna ) ) {
        return new WP_Error( 'bsn_cerebro_date_non_valide', 'Date ritiro/riconsegna mancanti o non valide.', [ 'status' => 400 ] );
    }

    if ( ! is_array( $items ) || empty( $items ) ) {
        return new WP_Error( 'bsn_cerebro_items_mancanti', 'Inserisci almeno un prodotto.', [ 'status' => 400 ] );
    }

    $categoria_cliente = sanitize_key( (string) ( $cliente['categoria_cliente'] ?? 'standard' ) );
    $articoli = [];
    $verified = [];

    foreach ( $items as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }

        $product_id = absint( $item['product_id'] ?? 0 );
        $qty = max( 1, intval( $item['qty'] ?? 1 ) );
        if ( $product_id < 1 ) {
            continue;
        }

        $product = get_post( $product_id );
        if ( ! $product || $product->post_type !== 'bs_prodotto' || $product->post_status !== 'publish' ) {
            return new WP_Error( 'bsn_cerebro_product_invalid', 'Prodotto pubblico non valido: ' . $product_id, [ 'status' => 400 ] );
        }

        $availability = bsn_get_public_product_availability( $product_id, $data_ritiro, $data_riconsegna );
        if ( intval( $availability['available_units'] ?? 0 ) < $qty ) {
            return new WP_Error(
                'bsn_cerebro_disponibilita_insufficiente',
                'Disponibilita insufficiente per ' . get_the_title( $product ) . '.',
                [ 'status' => 409, 'availability' => $availability ]
            );
        }

        $reference = bsn_get_public_product_quote_reference_article( $product_id, $categoria_cliente );
        if ( ! is_array( $reference ) || empty( $reference['articolo_id'] ) ) {
            return new WP_Error( 'bsn_cerebro_pricing_missing', 'Nessun articolo/prezzo collegato per ' . get_the_title( $product ) . '.', [ 'status' => 400 ] );
        }

        $quote_preview = bsn_get_public_product_quote_preview( $product_id, $qty, $data_ritiro, $data_riconsegna, $categoria_cliente );
        if ( empty( $quote_preview['success'] ) ) {
            return new WP_Error( 'bsn_cerebro_quote_preview_failed', 'Preview prezzo non disponibile per ' . get_the_title( $product ) . '.', [ 'status' => 400, 'preview' => $quote_preview ] );
        }

        $articoli[] = [
            'id'                       => intval( $reference['articolo_id'] ),
            'qty'                      => $qty,
            'prezzo'                   => null,
            'public_product_id'        => $product_id,
            'public_product_title'     => get_the_title( $product ),
            'public_product_permalink' => get_permalink( $product ),
            'public_request_origin'    => 'cerebro',
        ];

        $verified[] = [
            'product_id'   => $product_id,
            'title'        => get_the_title( $product ),
            'qty'          => $qty,
            'availability' => $availability,
            'quote'        => $quote_preview,
        ];
    }

    if ( empty( $articoli ) ) {
        return new WP_Error( 'bsn_cerebro_items_non_validi', 'Nessun prodotto valido nel payload.', [ 'status' => 400 ] );
    }

    $summary = sanitize_textarea_field( (string) $request->get_param( 'cerebro_summary' ) );
    $risk_flags = $request->get_param( 'risk_flags' );
    $risk_flags = is_array( $risk_flags ) ? array_values( array_filter( array_map( 'sanitize_text_field', $risk_flags ) ) ) : [];
    $note_parts = [ 'Richiesta creata da Cerebro.' ];
    if ( $summary !== '' ) {
        $note_parts[] = 'Riepilogo: ' . $summary;
    }
    if ( ! empty( $risk_flags ) ) {
        $note_parts[] = 'Flag: ' . implode( ', ', $risk_flags );
    }

    $noleggio_request = new WP_REST_Request( 'POST', '/bsn/v1/noleggi' );
    $noleggio_request->set_body_params( [
        'cliente_id'       => $cliente_id,
        'data_inizio'      => $data_ritiro,
        'data_fine'        => $data_riconsegna,
        'data_ritiro'      => $data_ritiro,
        'data_riconsegna'  => $data_riconsegna,
        'stato'            => 'preventivo',
        'origine'          => 'cerebro',
        'articoli'         => wp_json_encode( $articoli ),
        'note'             => implode( "\n", $note_parts ),
        'sconto_globale'   => 0,
    ] );

    $created = bsn_api_noleggi_post( $noleggio_request );
    if ( is_wp_error( $created ) ) {
        return $created;
    }

    $response = rest_ensure_response( $created );
    $data = $response->get_data();
    $data['origine'] = 'cerebro';
    $data['stato'] = 'preventivo';
    $data['verified_items'] = $verified;
    $response->set_data( $data );

    return $response;
}

function bsn_get_public_product_availability_theme( $badge ) {
    $badge = (string) $badge;
    switch ( $badge ) {
        case 'Disponibile':
            return [
                'bg' => '#eef9f0',
                'border' => '#9fd5ab',
                'text' => '#1f6b33',
            ];
        case 'Disponibile con warning':
            return [
                'bg' => '#fff8e8',
                'border' => '#f0c36d',
                'text' => '#8a5a00',
            ];
        case 'Disponibilita limitata':
            return [
                'bg' => '#fff7e8',
                'border' => '#f2c980',
                'text' => '#8a5a00',
            ];
        default:
            return [
                'bg' => '#fff0f0',
                'border' => '#e2aaaa',
                'text' => '#8f1f1f',
            ];
    }
}

function bsn_render_public_product_availability_html( $product_id, $data_ritiro = '', $data_riconsegna = '', $args = [] ) {
    $defaults = [
        'show_counts'  => true,
        'title'        => 'Disponibilita',
        'compact'      => false,
        'show_context' => null,
    ];
    $args = wp_parse_args( $args, $defaults );

    $availability     = bsn_get_public_product_availability( $product_id, $data_ritiro, $data_riconsegna );
    $badge_marketing  = (string) ( $availability['badge_marketing'] ?? $availability['badge'] ?? 'Non disponibile' );
    $actual_badge     = (string) ( $availability['badge'] ?? 'Non disponibile' );
    $available_units  = intval( $availability['available_units'] ?? 0 );
    $total_units      = intval( $availability['total_units'] ?? 0 );
    $show_counts      = ! empty( $args['show_counts'] );
    $compact          = ! empty( $args['compact'] );
    $title            = (string) $args['title'];
    $show_context     = is_null( $args['show_context'] ) ? ! $compact : ! empty( $args['show_context'] );
    $warning_messages = isset( $availability['warning_messages'] ) && is_array( $availability['warning_messages'] ) ? $availability['warning_messages'] : [];

    // === Stato (classe CSS) usata dal restyle pubblico (Phase 3) ===
    // - bsn-stato-disponibile     : verde   (units == total)
    // - bsn-stato-limitato        : arancio (0 < units < total, oppure badge "limitata" / "con warning")
    // - bsn-stato-non-disponibile : rosso   (units <= 0)
    if ( $available_units <= 0 ) {
        $stato_class = 'bsn-stato-non-disponibile';
    } elseif (
        ( $total_units > 0 && $available_units < $total_units )
        || in_array( $actual_badge, [ 'Disponibilita limitata', 'Disponibile con warning' ], true )
    ) {
        $stato_class = 'bsn-stato-limitato';
    } else {
        $stato_class = 'bsn-stato-disponibile';
    }

    // === Markup ===
    // Compact (card catalogo / tassonomia / cella carrello): emette solo .bsn-disponibilita-box
    //   con badge + unit-count. Il CSS pubblico colora tutto via :has() sullo stato.
    // Full (scheda singola, admin preview, content-filter legacy): wrapper .bsn-public-availability
    //   con titolo, contesto data, hint e warning list.
    $box  = '<div class="bsn-disponibilita-box">';
    $box .= '<span class="' . esc_attr( $stato_class ) . '">' . esc_html( $badge_marketing ) . '</span>';
    if ( $show_counts && $total_units > 0 ) {
        $box .= '<span class="bsn-unit-count"><b>' . esc_html( $available_units ) . '</b> / ' . esc_html( $total_units ) . ' unita</span>';
    }
    $box .= '</div>';

    if ( $compact ) {
        // Variante compatta: niente wrapper, niente contesto, niente hint.
        // Le card del catalogo gia avvolgono in <div class="bsn-card-availability">.
        return $box;
    }

    // Variante completa
    $html = '<div class="bsn-public-availability">';
    if ( $title !== '' ) {
        $html .= '<div class="bsn-public-availability-title">' . esc_html( $title ) . '</div>';
    }
    $html .= $box;

    if ( $show_context ) {
        $html .= '<div class="bsn-public-availability-context">';
        if ( $data_ritiro !== '' && $data_riconsegna !== '' ) {
            $html .= 'Periodo verificato: ' . esc_html( bsn_format_date_it( $data_ritiro ) ) . ' &ndash; ' . esc_html( bsn_format_date_it( $data_riconsegna ) );
        } elseif ( $data_ritiro !== '' || $data_riconsegna !== '' ) {
            $html .= 'Verifica parziale date impostata.';
        } else {
            $html .= 'Disponibilita attuale senza filtro date.';
        }
        $html .= '</div>';
    }

    if ( $show_counts && $available_units > 0 ) {
        $html .= '<div class="bsn-public-availability-hint">Per quantita superiori a quelle mostrate contattaci: possiamo verificare disponibilita aggiuntiva o soluzioni alternative.</div>';
    }

    if ( ! empty( $warning_messages ) ) {
        $html .= '<div class="bsn-public-warning-box"><strong>Avvisi sul materiale selezionabile</strong><ul>';
        foreach ( $warning_messages as $warning_message ) {
            $html .= '<li>' . esc_html( $warning_message ) . '</li>';
        }
        $html .= '</ul></div>';
    }

    $html .= '</div>';

    return $html;
}

function bsn_get_public_product_gallery_urls( $product_id ) {
    $urls = [];
    $thumbnail = get_the_post_thumbnail_url( $product_id, 'large' );
    if ( $thumbnail ) {
        $urls[] = esc_url_raw( $thumbnail );
    }

    $meta = bsn_get_public_product_meta( $product_id );
    $raw = preg_split( '/[\r\n,]+/', (string) ( $meta['gallery_urls'] ?? '' ) );
    foreach ( (array) $raw as $item ) {
        $url = esc_url_raw( trim( (string) $item ) );
        if ( $url !== '' && ! in_array( $url, $urls, true ) ) {
            $urls[] = $url;
        }
    }

    return array_values( array_filter( $urls ) );
}

function bsn_get_consigliati_products( $product_id ) {
    $meta = bsn_get_public_product_meta( $product_id );
    if ( $meta['consigliati_ids'] === '' ) {
        return [];
    }
    $decoded = json_decode( $meta['consigliati_ids'], true );
    if ( ! is_array( $decoded ) ) {
        return [];
    }
    $ids = array_values( array_filter( array_map( 'absint', $decoded ) ) );
    if ( empty( $ids ) ) {
        return [];
    }
    return get_posts( [
        'post_type'      => 'bs_prodotto',
        'post_status'    => 'publish',
        'post__in'       => $ids,
        'orderby'        => 'post__in',
        'posts_per_page' => count( $ids ),
    ] );
}

function bsn_get_product_downloads( $product_id ) {
    $meta = bsn_get_public_product_meta( $product_id );
    if ( $meta['downloads'] === '' ) {
        return [];
    }
    $decoded = json_decode( $meta['downloads'], true );
    if ( ! is_array( $decoded ) ) {
        return [];
    }
    return array_values( array_filter( $decoded, function ( $item ) {
        return is_array( $item ) && ! empty( $item['url'] );
    } ) );
}

function bsn_get_public_product_video_urls( $product_id ) {
    $meta = bsn_get_public_product_meta( $product_id );
    $raw = preg_split( '/[\r\n,]+/', (string) ( $meta['video_urls'] ?? '' ) );
    $urls = [];
    foreach ( (array) $raw as $item ) {
        $url = esc_url_raw( trim( (string) $item ) );
        if ( $url !== '' ) {
            $urls[] = $url;
        }
    }

    return array_values( array_unique( $urls ) );
}

function bsn_get_public_product_video_embeds( $product_id ) {
    $embeds = [];
    foreach ( bsn_get_public_product_video_urls( $product_id ) as $video_url ) {
        $embed_html = wp_oembed_get( $video_url, [ 'width' => 920 ] );
        $embeds[] = [
            'url'  => $video_url,
            'html' => $embed_html ? $embed_html : '',
        ];
    }

    return $embeds;
}

function bsn_get_public_product_dimension_request_context( $product_id, $request, $fallback_qty = 1 ) {
    $product_id = absint( $product_id );
    $qty = max( 1, (int) ceil( (float) $fallback_qty ) );
    $context = array(
        'qty' => $qty,
        'dimension_data' => array(),
        'errors' => array(),
        'warnings' => array(),
    );

    if ( $product_id < 1 || ! function_exists( 'bsn_get_dimension_config' ) || ! $request instanceof WP_REST_Request ) {
        return $context;
    }

    $config = bsn_get_dimension_config( $product_id );
    $width = function_exists( 'bsn_dimension_to_float' )
        ? bsn_dimension_to_float( $request->get_param( 'dimension_width_m' ) )
        : (float) $request->get_param( 'dimension_width_m' );
    $height = function_exists( 'bsn_dimension_to_float' )
        ? bsn_dimension_to_float( $request->get_param( 'dimension_height_m' ) )
        : (float) $request->get_param( 'dimension_height_m' );
    $selected_preset_label = sanitize_text_field( (string) $request->get_param( 'selected_preset_label' ) );
    $dimension_note = sanitize_textarea_field( (string) $request->get_param( 'dimension_note' ) );
    $has_dimension_input = $width > 0 || $height > 0;

    if ( empty( $config['enabled'] ) ) {
        if ( $dimension_note !== '' ) {
            $context['dimension_data']['dimension_note'] = $dimension_note;
        }
        return $context;
    }

    if ( $has_dimension_input ) {
        $calc = bsn_calculate_dimension_quantity(
            $product_id,
            array(
                'width_m' => $width,
                'height_m' => $height,
            )
        );

        if ( empty( $calc['success'] ) ) {
            $context['errors'] = isset( $calc['errors'] ) && is_array( $calc['errors'] ) ? $calc['errors'] : array( 'Misure non valide.' );
            return $context;
        }

        $qty = max( 1, (int) ceil( (float) ( $calc['qty_for_cart'] ?? $qty ) ) );
        $context['qty'] = $qty;
        $context['warnings'] = isset( $calc['warnings'] ) && is_array( $calc['warnings'] ) ? $calc['warnings'] : array();
        $context['dimension_data'] = array(
            'calculation_type' => sanitize_key( (string) ( $calc['calculation_type'] ?? '' ) ),
            'requested_width_m' => round( (float) ( $calc['requested_width_m'] ?? $width ), 4 ),
            'requested_height_m' => round( (float) ( $calc['requested_height_m'] ?? $height ), 4 ),
            'technical_width_m' => round( (float) ( $calc['technical_width_m'] ?? $calc['suggested_width_m'] ?? $width ), 4 ),
            'technical_height_m' => round( (float) ( $calc['technical_height_m'] ?? $calc['suggested_height_m'] ?? $height ), 4 ),
            'modules_x' => max( 0, intval( $calc['modules_x'] ?? 0 ) ),
            'modules_y' => max( 0, intval( $calc['modules_y'] ?? 0 ) ),
            'modules_total' => max( 0, intval( $calc['modules_total'] ?? 0 ) ),
            'area_mq' => round( (float) ( $calc['area_mq'] ?? 0 ), 4 ),
            'dimension_summary' => sanitize_text_field( (string) ( $calc['summary'] ?? '' ) ),
            'selected_preset_label' => $selected_preset_label,
            'dimension_note' => $dimension_note,
        );
        return $context;
    }

    if ( $dimension_note !== '' ) {
        $context['dimension_data'] = array(
            'calculation_type' => 'direct_quantity',
            'dimension_note' => $dimension_note,
        );
    }

    return $context;
}

function bsn_get_public_product_quantity_discount_price_range( $product_id, $categoria_cliente = 'standard' ) {
    if ( ! function_exists( 'bsn_get_quantity_discount_price_range' ) ) {
        return null;
    }

    $candidates = bsn_get_public_product_pricing_candidates( $product_id, $categoria_cliente );
    if ( empty( $candidates ) ) {
        return null;
    }

    $row = $candidates[0];
    $range = bsn_get_quantity_discount_price_range( $row['articolo'] ?? ( $row['articolo_id'] ?? 0 ), $row['prezzo_netto'] ?? 0 );
    if ( empty( $range['enabled'] ) || (float) ( $range['min_price'] ?? 0 ) >= (float) ( $range['max_price'] ?? 0 ) ) {
        return null;
    }

    return $range;
}

function bsn_get_public_product_price_from( $product_id, $categoria_cliente = 'standard' ) {
    $candidates = bsn_get_public_product_pricing_candidates( $product_id, $categoria_cliente );
    if ( empty( $candidates ) ) {
        return null;
    }

    return (float) $candidates[0]['prezzo_netto'];
}

function bsn_get_public_product_quote_preview( $product_id, $qty = 1, $data_ritiro = '', $data_riconsegna = '', $categoria_cliente = 'standard' ) {
    $product_id = absint( $product_id );
    $qty = max( 1, intval( $qty ) );
    $categoria_cliente = sanitize_key( (string) $categoria_cliente );

    $availability = bsn_get_public_product_availability( $product_id, $data_ritiro, $data_riconsegna );
    $articoli = bsn_get_public_product_articles( $product_id );
    $min_qty = 1;
    $candidate_rows = bsn_get_public_product_pricing_candidates( $product_id, $categoria_cliente );

    foreach ( $articoli as $articolo ) {
        if ( ! bsn_is_article_publicly_rentable( $articolo ) ) {
            continue;
        }

        $min_qty = max( $min_qty, intval( $articolo['min_qty'] ?? 1 ) );
    }

    if ( empty( $candidate_rows ) ) {
        return [
            'success' => false,
            'message' => 'Nessun articolo disponibile per il calcolo del prezzo.',
            'availability' => $availability,
            'min_qty' => $min_qty,
        ];
    }

    $row = $candidate_rows[0];

    $giorni = 1;
    if ( $data_ritiro !== '' && $data_riconsegna !== '' ) {
        $ts_ritiro = strtotime( $data_ritiro );
        $ts_riconsegna = strtotime( $data_riconsegna );
        if ( $ts_ritiro && $ts_riconsegna && $ts_riconsegna >= $ts_ritiro ) {
            $giorni = max( 1, (int) ceil( ( $ts_riconsegna - $ts_ritiro ) / DAY_IN_SECONDS ) );
        }
    }

    $fattore = $row['noleggio_scalare'] ? sqrt( $giorni ) : 1;
    $unit_price_before_qty_discount = (float) $row['prezzo_netto'];
    $qty_discount = function_exists( 'bsn_calculate_linear_quantity_discount' )
        ? bsn_calculate_linear_quantity_discount(
            $unit_price_before_qty_discount,
            $qty,
            function_exists( 'bsn_get_quantity_discount_config' ) ? bsn_get_quantity_discount_config( $row['articolo'] ?? ( $row['articolo_id'] ?? 0 ) ) : array()
        )
        : array(
            'unit_price' => $unit_price_before_qty_discount,
            'discount_pct' => 0,
            'line_discount_amount' => 0,
            'applied' => false,
        );
    $unit_price_after_qty_discount = (float) ( $qty_discount['unit_price'] ?? $unit_price_before_qty_discount );
    $totale_stimato = $unit_price_after_qty_discount * $qty * $fattore;
    $totale_prima_sconto_quantita = $unit_price_before_qty_discount * $qty * $fattore;
    $totale_lineare_stimato = $unit_price_after_qty_discount * $qty * $giorni;
    $totale_lordo_categoria = $row['prezzo_standard'] * $qty * $fattore;
    $risparmio_scalare = max( 0, $totale_lineare_stimato - $totale_stimato );
    $risparmio_categoria = max( 0, $totale_lordo_categoria - $totale_prima_sconto_quantita );
    $risparmio_quantita = max( 0, $totale_prima_sconto_quantita - $totale_stimato );

    return [
        'success' => true,
        'product_id' => $product_id,
        'qty' => $qty,
        'giorni' => $giorni,
        'min_qty' => $min_qty,
        'categoria_cliente' => $categoria_cliente,
        'articolo_riferimento' => $row['label'],
        'prezzo_standard' => $row['prezzo_standard'],
        'prezzo_netto' => $unit_price_after_qty_discount,
        'unit_price_before_qty_discount' => $unit_price_before_qty_discount,
        'unit_price_after_qty_discount' => $unit_price_after_qty_discount,
        'qty_discount_applied_pct' => round( (float) ( $qty_discount['discount_pct'] ?? 0 ), 4 ),
        'qty_discount_applied' => ! empty( $qty_discount['applied'] ),
        'sconto_percentuale' => $row['sconto'],
        'noleggio_scalare' => $row['noleggio_scalare'],
        'fattore' => $fattore,
        'totale_stimato' => $totale_stimato,
        'totale_lineare_stimato' => $totale_lineare_stimato,
        'risparmio_scalare' => $risparmio_scalare,
        'risparmio_categoria' => $risparmio_categoria,
        'risparmio_quantita' => $risparmio_quantita,
        'availability' => $availability,
        'message' => $qty < $min_qty ? 'La quantita selezionata e inferiore al minimo noleggiabile.' : '',
    ];
}

function bsn_quote_cart_boot_session() {
    if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
        return true;
    }

    if ( headers_sent() || defined( 'WP_CLI' ) ) {
        return false;
    }

    if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_NONE ) {
        @session_start();
    }

    return function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE;
}
add_action( 'init', 'bsn_quote_cart_boot_session', 1 );

function bsn_get_quote_cart_session_key() {
    return 'bsn_quote_cart';
}

function bsn_get_empty_quote_cart() {
    return [
        'dates' => [
            'data_ritiro' => '',
            'data_riconsegna' => '',
        ],
        'items' => [],
        'service' => bsn_get_empty_quote_cart_service(),
        'editing' => [
            'noleggio_id' => '',
            'customer_note' => '',
        ],
    ];
}

function bsn_normalize_quote_cart_dimension_data( $item ) {
    $item = is_array( $item ) ? $item : array();
    $data = array();

    $calculation_type = sanitize_key( (string) ( $item['calculation_type'] ?? '' ) );
    if ( $calculation_type !== '' ) {
        $data['calculation_type'] = $calculation_type;
    }

    foreach ( array( 'requested_width_m', 'requested_height_m', 'technical_width_m', 'technical_height_m', 'area_mq' ) as $key ) {
        $value = function_exists( 'bsn_dimension_to_float' ) ? bsn_dimension_to_float( $item[ $key ] ?? 0 ) : (float) ( $item[ $key ] ?? 0 );
        if ( $value > 0 ) {
            $data[ $key ] = round( $value, 4 );
        }
    }

    foreach ( array( 'modules_x', 'modules_y', 'modules_total' ) as $key ) {
        $value = max( 0, intval( $item[ $key ] ?? 0 ) );
        if ( $value > 0 ) {
            $data[ $key ] = $value;
        }
    }

    foreach ( array( 'dimension_summary', 'selected_preset_label' ) as $key ) {
        $value = sanitize_text_field( (string) ( $item[ $key ] ?? '' ) );
        if ( $value !== '' ) {
            $data[ $key ] = $value;
        }
    }

    $dimension_note = sanitize_textarea_field( (string) ( $item['dimension_note'] ?? '' ) );
    if ( $dimension_note !== '' ) {
        $data['dimension_note'] = $dimension_note;
    }

    if ( count( $data ) === 1 && isset( $data['calculation_type'] ) && $data['calculation_type'] === 'direct_quantity' ) {
        return array();
    }

    return $data;
}

function bsn_build_quote_cart_line_key( $product_id, $dimension_data = array() ) {
    $product_id = absint( $product_id );
    $dimension_data = bsn_normalize_quote_cart_dimension_data( $dimension_data );
    if ( empty( $dimension_data ) ) {
        return 'p' . $product_id;
    }

    return 'p' . $product_id . '-' . substr( md5( wp_json_encode( $dimension_data ) ), 0, 12 );
}

function bsn_normalize_quote_cart( $cart ) {
    $normalized = bsn_get_empty_quote_cart();

    if ( ! is_array( $cart ) ) {
        return $normalized;
    }

    $dates = isset( $cart['dates'] ) && is_array( $cart['dates'] ) ? $cart['dates'] : [];
    $normalized['dates']['data_ritiro'] = sanitize_text_field( (string) ( $dates['data_ritiro'] ?? '' ) );
    $normalized['dates']['data_riconsegna'] = sanitize_text_field( (string) ( $dates['data_riconsegna'] ?? '' ) );

    $normalized['service'] = bsn_normalize_quote_cart_service( $cart['service'] ?? array() );

    $editing = isset( $cart['editing'] ) && is_array( $cart['editing'] ) ? $cart['editing'] : [];
    $normalized['editing']['noleggio_id'] = sanitize_text_field( (string) ( $editing['noleggio_id'] ?? '' ) );
    $normalized['editing']['customer_note'] = sanitize_textarea_field( (string) ( $editing['customer_note'] ?? '' ) );

    $items = isset( $cart['items'] ) && is_array( $cart['items'] ) ? $cart['items'] : [];
    $by_line = [];
    foreach ( $items as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }
        $product_id = absint( $item['product_id'] ?? 0 );
        $qty = max( 1, intval( $item['qty'] ?? 1 ) );
        if ( $product_id < 1 ) {
            continue;
        }
        $dimension_data = bsn_normalize_quote_cart_dimension_data( $item );
        $line_key = bsn_build_quote_cart_line_key( $product_id, $dimension_data );

        if ( ! isset( $by_line[ $line_key ] ) ) {
            $by_line[ $line_key ] = array_merge(
                [
                    'line_key' => $line_key,
                    'product_id' => $product_id,
                    'qty' => $qty,
                ],
                $dimension_data
            );
        } else {
            $by_line[ $line_key ]['qty'] += $qty;
        }
    }

    $normalized['items'] = array_values( $by_line );
    return $normalized;
}

function bsn_quote_cart_get_item_index( $cart, $product_id, $line_key = '' ) {
    $product_id = absint( $product_id );
    $line_key = sanitize_text_field( (string) $line_key );
    $items = isset( $cart['items'] ) && is_array( $cart['items'] ) ? $cart['items'] : [];

    if ( $line_key !== '' ) {
        foreach ( $items as $index => $item ) {
            if ( sanitize_text_field( (string) ( $item['line_key'] ?? '' ) ) === $line_key ) {
                return $index;
            }
        }
    }

    foreach ( $items as $index => $item ) {
        if ( absint( $item['product_id'] ?? 0 ) === $product_id ) {
            return $index;
        }
    }
    return -1;
}

function bsn_get_quote_cart_reserved_qty_for_product_except_line( $product_id, $cart, $line_key = '' ) {
    $product_id = absint( $product_id );
    $line_key = sanitize_text_field( (string) $line_key );
    $items = isset( $cart['items'] ) && is_array( $cart['items'] ) ? $cart['items'] : array();
    $reserved_qty = 0;

    foreach ( $items as $item ) {
        if ( absint( $item['product_id'] ?? 0 ) !== $product_id ) {
            continue;
        }
        if ( $line_key !== '' && sanitize_text_field( (string) ( $item['line_key'] ?? '' ) ) === $line_key ) {
            continue;
        }
        $reserved_qty += max( 1, intval( $item['qty'] ?? 1 ) );
    }

    return max( 0, $reserved_qty );
}

function bsn_get_quote_cart() {
    if ( ! bsn_quote_cart_boot_session() ) {
        return bsn_get_empty_quote_cart();
    }

    $key = bsn_get_quote_cart_session_key();
    $cart = isset( $_SESSION[ $key ] ) ? $_SESSION[ $key ] : [];
    return bsn_normalize_quote_cart( $cart );
}

function bsn_get_quote_cart_reserved_qty_for_product( $product_id, $cart = null ) {
    $product_id = absint( $product_id );
    if ( $product_id < 1 ) {
        return 0;
    }

    if ( ! is_array( $cart ) ) {
        $cart = bsn_get_quote_cart();
    }

    $items = isset( $cart['items'] ) && is_array( $cart['items'] ) ? $cart['items'] : array();
    $reserved_qty = 0;
    foreach ( $items as $item ) {
        if ( absint( $item['product_id'] ?? 0 ) !== $product_id ) {
            continue;
        }
        $reserved_qty += max( 1, intval( $item['qty'] ?? 1 ) );
    }

    return max( 0, $reserved_qty );
}

function bsn_set_quote_cart( $cart ) {
    if ( ! bsn_quote_cart_boot_session() ) {
        return false;
    }

    $_SESSION[ bsn_get_quote_cart_session_key() ] = bsn_normalize_quote_cart( $cart );
    return true;
}

function bsn_clear_quote_cart() {
    if ( ! bsn_quote_cart_boot_session() ) {
        return false;
    }

    unset( $_SESSION[ bsn_get_quote_cart_session_key() ] );
    return true;
}

function bsn_get_quote_cart_page_url() {
    $page_id = get_option( 'bsn_quote_cart_page_id' );
    if ( $page_id ) {
        $url = get_permalink( $page_id );
        if ( $url ) {
            return $url;
        }
    }

    $page = get_page_by_path( 'carrello-noleggio' );
    if ( $page ) {
        update_option( 'bsn_quote_cart_page_id', $page->ID );
        $url = get_permalink( $page->ID );
        if ( $url ) {
            return $url;
        }
    }

    return home_url( '/carrello-noleggio/' );
}

function bsn_get_quote_submitted_page_url( $noleggio_id = '' ) {
    $page_id = get_option( 'bsn_quote_submitted_page_id' );
    $url = $page_id ? get_permalink( $page_id ) : '';

    if ( ! $url ) {
        $page = get_page_by_path( 'preventivo-inviato' );
        if ( $page ) {
            update_option( 'bsn_quote_submitted_page_id', $page->ID );
            $url = get_permalink( $page->ID );
        }
    }

    if ( ! $url ) {
        $url = home_url( '/preventivo-inviato/' );
    }

    $noleggio_id = sanitize_text_field( (string) $noleggio_id );
    if ( $noleggio_id !== '' ) {
        $url = add_query_arg( 'id', rawurlencode( $noleggio_id ), $url );
    }

    return $url;
}

function bsn_generate_noleggio_id() {
    global $wpdb;

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $anno = date( 'Y' );
    $ultimo = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(id, '/', -1) AS UNSIGNED))
             FROM $table_noleggi
             WHERE id LIKE %s",
            $anno . '/%'
        )
    );

    $progressivo = str_pad( ( intval( $ultimo ) + 1 ), 3, '0', STR_PAD_LEFT );
    return $anno . '/' . $progressivo;
}

function bsn_get_quote_cart_customer_context() {
    $context = [
        'is_logged_in' => is_user_logged_in(),
        'customer' => null,
        'login_url' => function_exists( 'bsn_get_login_page_url' ) ? bsn_get_login_page_url() : wp_login_url(),
        'register_url' => function_exists( 'bsn_get_register_page_url' ) ? bsn_get_register_page_url() : wp_registration_url(),
        'account_url' => function_exists( 'bsn_get_account_page_url' ) ? bsn_get_account_page_url() : home_url( '/' ),
        'submitted_url' => bsn_get_quote_submitted_page_url(),
    ];

    if ( ! $context['is_logged_in'] ) {
        return $context;
    }

    $user = wp_get_current_user();
    $cliente = bsn_ensure_customer_for_wp_user( $user->ID );
    if ( ! $cliente ) {
        return $context;
    }

    $context['customer'] = [
        'user_id' => (int) $user->ID,
        'customer_id' => (int) ( $cliente['id'] ?? 0 ),
        'display_name' => (string) ( $user->display_name ?: $user->user_login ),
        'email' => (string) ( $cliente['email'] ?? $user->user_email ),
        'telefono' => (string) ( $cliente['telefono'] ?? '' ),
        'cf_piva' => (string) ( $cliente['cf_piva'] ?? '' ),
        'categoria' => (string) ( $cliente['categoria_cliente'] ?? 'standard' ),
        'categoria_label' => function_exists( 'bsn_get_public_customer_category_label' )
            ? bsn_get_public_customer_category_label( (string) ( $cliente['categoria_cliente'] ?? 'standard' ) )
            : 'Guest / standard',
    ];

    return $context;
}

function bsn_build_quote_request_note( $note, $mode = 'create', $previous_note = '' ) {
    $note = trim( sanitize_textarea_field( (string) $note ) );
    $request_markers = array(
        'Richiesta preventivo inviata dal catalogo pubblico.',
        'Richiesta preventivo aggiornata dal catalogo pubblico.',
    );
    $mode = sanitize_key( (string) $mode );
    $request_prefix = ( $mode === 'update' )
        ? 'Richiesta preventivo aggiornata dal catalogo pubblico.'
        : 'Richiesta preventivo inviata dal catalogo pubblico.';

    $parts = array();
    $system_lines = array( $request_prefix );
    $previous_sections = bsn_parse_noleggio_note_sections( $previous_note );

    if ( $previous_sections['system_note'] !== '' ) {
        $previous_lines = preg_split( '/\r\n|\r|\n/', $previous_sections['system_note'] );
        foreach ( (array) $previous_lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' || in_array( $line, $request_markers, true ) ) {
                continue;
            }
            $system_lines[] = $line;
        }
    }

    $system_lines = array_values( array_unique( array_filter( $system_lines ) ) );
    if ( ! empty( $system_lines ) ) {
        $parts[] = implode( "\n", $system_lines );
    }

    if ( $note !== '' ) {
        $parts[] = 'Note cliente: ' . $note;
    }

    return implode( "\n\n", $parts );
}

function bsn_parse_noleggio_note_sections( $note ) {
    $note = trim( (string) $note );
    if ( $note === '' ) {
        return array(
            'system_note' => '',
            'customer_note' => '',
        );
    }

    $lines = preg_split( '/\r\n|\r|\n/', $note );
    $system_lines = array();
    $customer_lines = array();

    foreach ( (array) $lines as $line ) {
        $line = trim( (string) $line );
        if ( $line === '' ) {
            continue;
        }

        if ( stripos( $line, 'Note cliente:' ) === 0 ) {
            $customer_value = trim( substr( $line, strlen( 'Note cliente:' ) ) );
            if ( $customer_value !== '' ) {
                $customer_lines[] = $customer_value;
            }
            continue;
        }

        $system_lines[] = $line;
    }

    return array(
        'system_note' => implode( "\n", $system_lines ),
        'customer_note' => implode( "\n", $customer_lines ),
    );
}

function bsn_get_public_noleggio_status_label( $status ) {
    $status = sanitize_key( (string) $status );

    switch ( $status ) {
        case 'preventivo':
            return 'In revisione';
        case 'bozza':
            return 'Confermato';
        case 'attivo':
            return 'In corso';
        case 'chiuso':
            return 'Concluso';
        case 'ritardo':
            return 'In ritardo';
        default:
            return ucfirst( $status !== '' ? $status : 'Sconosciuto' );
    }
}

function bsn_delete_noleggio_by_id( $id ) {
    global $wpdb;

    $id = sanitize_text_field( (string) $id );
    if ( $id === '' ) {
        return new WP_Error( 'bsn_noleggio_id_mancante', 'ID noleggio mancante.' );
    }

    $table = $wpdb->prefix . 'bs_noleggi';
    $table_noleggio_kits = $wpdb->prefix . 'bsn_noleggio_kits';
    $table_noleggio_kit_componenti = $wpdb->prefix . 'bsn_noleggio_kit_componenti';
    $kit_rows = $wpdb->get_results(
        $wpdb->prepare( "SELECT id FROM $table_noleggio_kits WHERE noleggio_id = %s", $id ),
        ARRAY_A
    );

    if ( ! empty( $kit_rows ) ) {
        $kit_ids = array_map(
            function( $row ) {
                return intval( $row['id'] );
            },
            $kit_rows
        );
        $placeholders = implode( ',', array_fill( 0, count( $kit_ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table_noleggio_kit_componenti WHERE noleggio_kit_id IN ($placeholders)", $kit_ids ) );
        $wpdb->delete( $table_noleggio_kits, array( 'noleggio_id' => $id ), array( '%s' ) );
    }

    $deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%s' ) );
    if ( $deleted === false ) {
        return new WP_Error( 'bsn_delete_noleggio_error', 'Errore nella cancellazione noleggio.' );
    }

    return true;
}

function bsn_get_customer_owned_noleggio( $user_id, $noleggio_id ) {
    global $wpdb;

    $user_id = absint( $user_id );
    $noleggio_id = sanitize_text_field( (string) $noleggio_id );
    if ( $user_id < 1 || $noleggio_id === '' ) {
        return null;
    }

    $cliente = bsn_ensure_customer_for_wp_user( $user_id );
    if ( ! $cliente || empty( $cliente['id'] ) ) {
        return null;
    }

    $table = $wpdb->prefix . 'bs_noleggi';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %s AND cliente_id = %d LIMIT 1",
            $noleggio_id,
            (int) $cliente['id']
        ),
        ARRAY_A
    );

    return $row ?: null;
}

function bsn_get_quote_cart_editing_context( $cart ) {
    $cart = bsn_normalize_quote_cart( $cart );
    $editing = isset( $cart['editing'] ) && is_array( $cart['editing'] ) ? $cart['editing'] : array();

    return array(
        'noleggio_id' => sanitize_text_field( (string) ( $editing['noleggio_id'] ?? '' ) ),
        'customer_note' => sanitize_textarea_field( (string) ( $editing['customer_note'] ?? '' ) ),
    );
}

function bsn_build_quote_cart_from_noleggio( $noleggio ) {
    if ( ! is_array( $noleggio ) || empty( $noleggio['id'] ) ) {
        return new WP_Error( 'bsn_quote_cart_load_invalid', 'Richiesta non valida per la modifica dal sito.' );
    }

    $items_map = array();
    $snapshot_items = bsn_get_noleggio_snapshot_json( $noleggio, 'snapshot_articoli_json' );

    if ( is_array( $snapshot_items ) && ! empty( $snapshot_items ) ) {
        foreach ( $snapshot_items as $snapshot_item ) {
            if ( ! is_array( $snapshot_item ) ) {
                continue;
            }

            $product_id = absint( $snapshot_item['product_id'] ?? 0 );
            $qty = max( 1, intval( $snapshot_item['qty'] ?? 1 ) );
            if ( $product_id < 1 ) {
                continue;
            }

            $dimension_data = bsn_normalize_quote_cart_dimension_data( $snapshot_item );
            $line_key = bsn_build_quote_cart_line_key( $product_id, $dimension_data );

            if ( ! isset( $items_map[ $line_key ] ) ) {
                $items_map[ $line_key ] = array_merge(
                    array(
                        'line_key' => $line_key,
                        'product_id' => $product_id,
                        'qty' => 0,
                    ),
                    $dimension_data
                );
            }

            $items_map[ $line_key ]['qty'] += $qty;
        }
    }

    if ( empty( $items_map ) ) {
        $articoli = json_decode( (string) ( $noleggio['articoli'] ?? '' ), true );
        if ( is_array( $articoli ) ) {
            foreach ( $articoli as $articolo ) {
                if ( ! is_array( $articolo ) ) {
                    continue;
                }

                $product_id = absint( $articolo['public_product_id'] ?? 0 );
                $qty = max( 1, intval( $articolo['qty'] ?? 1 ) );
                if ( $product_id < 1 ) {
                    continue;
                }

                $dimension_data = bsn_normalize_quote_cart_dimension_data( $articolo );
                $line_key = bsn_build_quote_cart_line_key( $product_id, $dimension_data );

                if ( ! isset( $items_map[ $line_key ] ) ) {
                    $items_map[ $line_key ] = array_merge(
                        array(
                            'line_key' => $line_key,
                            'product_id' => $product_id,
                            'qty' => 0,
                        ),
                        $dimension_data
                    );
                }

                $items_map[ $line_key ]['qty'] += $qty;
            }
        }
    }

    if ( empty( $items_map ) ) {
        return new WP_Error( 'bsn_quote_cart_load_products', 'Questa richiesta non ha ancora prodotti pubblici ricaricabili nel carrello.' );
    }

    $note_sections = bsn_parse_noleggio_note_sections( (string) ( $noleggio['note'] ?? '' ) );
    $service_snapshot = bsn_get_noleggio_service_snapshot( $noleggio );
    $data_ritiro_raw = ! empty( $noleggio['data_ritiro'] ) ? $noleggio['data_ritiro'] : ( $noleggio['data_inizio'] ?? '' );
    $data_riconsegna_raw = ! empty( $noleggio['data_riconsegna'] ) ? $noleggio['data_riconsegna'] : ( $noleggio['data_fine'] ?? '' );
    $data_ritiro = $data_ritiro_raw ? mysql2date( 'Y-m-d', $data_ritiro_raw ) : '';
    $data_riconsegna = $data_riconsegna_raw ? mysql2date( 'Y-m-d', $data_riconsegna_raw ) : '';

    return bsn_normalize_quote_cart(
        array(
            'dates' => array(
                'data_ritiro' => $data_ritiro,
                'data_riconsegna' => $data_riconsegna,
            ),
            'items' => array_values( $items_map ),
            'service' => bsn_map_service_snapshot_to_quote_cart_service( $service_snapshot ),
            'editing' => array(
                'noleggio_id' => sanitize_text_field( (string) $noleggio['id'] ),
                'customer_note' => $note_sections['customer_note'],
            ),
        )
    );
}

function bsn_allocate_quote_cart_line_amounts( $total, $allocations ) {
    $total = round( (float) $total, 2 );
    $normalized_allocations = array_values(
        array_filter(
            (array) $allocations,
            function ( $allocation ) {
                return is_array( $allocation ) && max( 0, intval( $allocation['qty'] ?? 0 ) ) > 0;
            }
        )
    );

    if ( empty( $normalized_allocations ) ) {
        return array();
    }

    $total_qty = array_sum(
        array_map(
            function ( $allocation ) {
                return max( 0, intval( $allocation['qty'] ?? 0 ) );
            },
            $normalized_allocations
        )
    );

    if ( $total_qty < 1 ) {
        return array_fill( 0, count( $normalized_allocations ), 0.0 );
    }

    $distributed = array();
    $allocated = 0.0;
    $last_index = count( $normalized_allocations ) - 1;

    foreach ( $normalized_allocations as $index => $allocation ) {
        $qty = max( 1, intval( $allocation['qty'] ?? 1 ) );
        if ( $index === $last_index ) {
            $portion = round( $total - $allocated, 2 );
        } else {
            $portion = round( $total * ( $qty / $total_qty ), 2 );
            $allocated += $portion;
        }

        $distributed[] = $portion;
    }

    return $distributed;
}

function bsn_build_frontend_quote_internal_lines( $cart_state, $categoria_cliente, $data_ritiro, $data_riconsegna ) {
    $categoria_cliente = sanitize_key( (string) $categoria_cliente );
    $periodo = array(
        'data_ritiro' => sanitize_text_field( (string) $data_ritiro ),
        'data_riconsegna' => sanitize_text_field( (string) $data_riconsegna ),
    );
    $articoli_request = array();
    $snapshot_articoli = array();

    foreach ( (array) ( $cart_state['items'] ?? array() ) as $line ) {
        $product_id = absint( $line['product_id'] ?? 0 );
        $qty = max( 1, intval( $line['qty'] ?? 1 ) );
        if ( $product_id < 1 || $qty < 1 ) {
            continue;
        }

        $dimension_snapshot = bsn_normalize_quote_cart_dimension_data( $line );
        $pricing_snapshot = array(
            'qty_discount_applied_pct' => round( (float) ( $line['qty_discount_applied_pct'] ?? 0 ), 4 ),
            'unit_price_before_qty_discount' => round( (float) ( $line['unit_price_before_qty_discount'] ?? ( $line['unit_price'] ?? 0 ) ), 2 ),
            'unit_price_after_qty_discount' => round( (float) ( $line['unit_price_after_qty_discount'] ?? ( $line['unit_price'] ?? 0 ) ), 2 ),
        );

        $articoli = bsn_get_public_product_articles( $product_id );
        if ( empty( $articoli ) ) {
            return new WP_Error(
                'bsn_quote_submit_reference',
                'Impossibile trovare articoli interni collegati al prodotto "' . ( $line['title'] ?? ( 'Prodotto #' . $product_id ) ) . '".'
            );
        }

        $gallery = bsn_get_public_product_gallery_urls( $product_id );
        $public_title = (string) ( $line['title'] ?? get_the_title( $product_id ) );
        $public_permalink = (string) ( $line['permalink'] ?? get_permalink( $product_id ) );
        $public_image_url = (string) ( $line['image_url'] ?? ( $gallery[0] ?? '' ) );
        $reference = bsn_get_public_product_quote_reference_article( $product_id, $categoria_cliente );
        $fallback_price = round( (float) ( $reference['prezzo_standard'] ?? 0 ), 2 );

        $allocations = array();
        $serial_candidates = array();
        $quantity_candidates = array();

        foreach ( $articoli as $articolo ) {
            if ( ! bsn_is_article_publicly_rentable( $articolo ) ) {
                continue;
            }

            $effective_qty = bsn_get_effective_article_available_qty( $articolo, $periodo );
            if ( $effective_qty < 1 ) {
                continue;
            }

            $inventory_mode = bsn_normalize_articolo_inventory_mode(
                $articolo['inventory_mode'] ?? '',
                intval( $articolo['qty_disponibile'] ?? 1 )
            );

            if ( $inventory_mode === 'quantita' ) {
                $quantity_candidates[] = array(
                    'articolo' => $articolo,
                    'qty' => $effective_qty,
                );
            } else {
                $serial_candidates[] = array(
                    'articolo' => $articolo,
                    'qty' => 1,
                );
            }
        }

        $available_units = count( $serial_candidates );
        foreach ( $quantity_candidates as $candidate ) {
            $available_units += max( 0, intval( $candidate['qty'] ?? 0 ) );
        }

        if ( $available_units < $qty ) {
            return new WP_Error(
                'bsn_quote_submit_availability',
                'La disponibilita del prodotto "' . $public_title . '" e cambiata: aggiorna il carrello o contattaci per trovare una soluzione.'
            );
        }

        $remaining = $qty;

        foreach ( $serial_candidates as $candidate ) {
            if ( $remaining < 1 ) {
                break;
            }

            $allocations[] = array(
                'articolo' => $candidate['articolo'],
                'qty' => 1,
            );
            $remaining--;
        }

        foreach ( $quantity_candidates as $candidate ) {
            if ( $remaining < 1 ) {
                break;
            }

            $take = min( $remaining, max( 0, intval( $candidate['qty'] ?? 0 ) ) );
            if ( $take < 1 ) {
                continue;
            }

            $allocations[] = array(
                'articolo' => $candidate['articolo'],
                'qty' => $take,
            );
            $remaining -= $take;
        }

        if ( $remaining > 0 ) {
            return new WP_Error(
                'bsn_quote_submit_allocation',
                'Non e stato possibile assegnare internamente tutte le unita richieste per "' . $public_title . '".'
            );
        }

        $line_totals = bsn_allocate_quote_cart_line_amounts( (float) ( $line['line_total'] ?? 0 ), $allocations );
        foreach ( $allocations as $index => $allocation ) {
            $articolo = $allocation['articolo'];
            $articolo_id = absint( $articolo['id'] ?? 0 );
            if ( $articolo_id < 1 ) {
                continue;
            }

            $articolo_price = round( (float) ( $articolo['prezzo_giorno'] ?? 0 ), 2 );
            $articoli_request[] = array(
                'id' => $articolo_id,
                'qty' => max( 1, intval( $allocation['qty'] ?? 1 ) ),
                'prezzo' => $articolo_price > 0 ? $articolo_price : $fallback_price,
                'public_product_id' => $product_id,
                'public_product_title' => $public_title,
                'public_product_permalink' => $public_permalink,
                'public_product_image_url' => $public_image_url,
                'public_product_code' => '',
                'public_pricing_category' => $categoria_cliente,
                'public_unit_price_net' => round( (float) ( $line['unit_price'] ?? 0 ), 2 ),
                'public_line_total' => round( (float) ( $line_totals[ $index ] ?? 0 ), 2 ),
                'public_min_qty' => max( 1, intval( $line['min_qty'] ?? 1 ) ),
                'public_request_origin' => 'catalogo_frontend',
            ) + $dimension_snapshot + $pricing_snapshot;
        }

        $snapshot_articoli[] = array(
            'product_id' => $product_id,
            'title' => $public_title,
            'permalink' => $public_permalink,
            'image_url' => $public_image_url,
            'qty' => $qty,
            'min_qty' => max( 1, intval( $line['min_qty'] ?? 1 ) ),
            'base_price' => round( (float) ( $line['base_price'] ?? 0 ), 2 ),
            'unit_price' => round( (float) ( $line['unit_price'] ?? 0 ), 2 ),
            'line_total' => round( (float) ( $line['line_total'] ?? 0 ), 2 ),
            'giorni' => max( 1, intval( $line['giorni'] ?? ( $cart_state['giorni_noleggio'] ?? 1 ) ) ),
        ) + $dimension_snapshot + $pricing_snapshot;
    }

    return array(
        'articoli_request' => $articoli_request,
        'snapshot_articoli' => $snapshot_articoli,
    );
}

function bsn_ensure_internal_service_articles() {
    global $wpdb;

    $definitions = bsn_get_internal_service_article_definitions();
    $codes = array_keys( $definitions );
    if ( empty( $codes ) ) {
        return array();
    }

    $table_articoli = $wpdb->prefix . 'bs_articoli';
    $placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
    $existing_rows = $wpdb->get_results(
        $wpdb->prepare( "SELECT id, codice FROM $table_articoli WHERE codice IN ($placeholders)", $codes ),
        ARRAY_A
    );

    $map = array();
    foreach ( (array) $existing_rows as $row ) {
        $map[ (string) $row['codice'] ] = (int) $row['id'];
    }

    foreach ( $definitions as $code => $definition ) {
        if ( ! empty( $map[ $code ] ) ) {
            continue;
        }

        $insert = array(
            'nome' => (string) $definition['nome'],
            'codice' => $code,
            'prezzo_giorno' => round( (float) ( $definition['prezzo'] ?? 0 ), 2 ),
            'valore_bene' => 0,
            'sconto_standard' => 0,
            'sconto_fidato' => 0,
            'sconto_premium' => 0,
            'sconto_service' => 0,
            'sconto_collaboratori' => 0,
            'noleggio_scalare' => 0,
            'ubicazione' => 'Servizio interno',
            'qty_disponibile' => 999999,
            'inventory_mode' => 'quantita',
            'prodotto_pubblico_id' => null,
            'min_qty' => 1,
            'stato_utilizzabilita' => 'disponibile',
            'note_logistiche' => 'Articolo interno di servizio. Non esporre nel catalogo pubblico.',
            'note' => 'Generato automaticamente per righe economiche servizi.',
            'data_creazione' => current_time( 'mysql' ),
        );

        $wpdb->insert( $table_articoli, $insert );
        if ( ! empty( $wpdb->insert_id ) ) {
            $map[ $code ] = (int) $wpdb->insert_id;
        }
    }

    return $map;
}

function bsn_build_frontend_service_snapshot( $service, $cart_dates = array() ) {
    $service = bsn_prepare_quote_cart_service( $service, $cart_dates );
    $pickup = bsn_get_public_service_pickup_context();
    $label_map = bsn_get_public_service_mode_options();
    $service_label = $label_map[ $service['mode'] ] ?? 'Modalita servizio';
    $event_start = bsn_combine_service_event_datetime(
        $service['event_start_date'] ?: $service['event_date'],
        $service['event_start_time']
    );
    $event_end = bsn_combine_service_event_datetime(
        $service['event_end_date'] ?: $service['event_date'],
        $service['event_end_time']
    );

    return array(
        'service_mode' => $service['mode'],
        'service_label' => $service_label,
        'service_estimated_total' => $service['estimated_total'] !== '' ? round( (float) $service['estimated_total'], 2 ) : null,
        'service_quote_needs_review' => ! empty( $service['quote_needs_review'] ) ? 1 : 0,
        'service_distance_km' => $service['distance_km'] !== '' ? round( (float) $service['distance_km'], 1 ) : null,
        'service_distance_provider' => (string) $service['distance_provider'],
        'service_location_address'  => (string) $service['location_address'],
        'service_location_lat'      => $service['location_lat'] !== '' ? (float) $service['location_lat'] : null,
        'service_location_lng'      => $service['location_lng'] !== '' ? (float) $service['location_lng'] : null,
        'service_location_place_id' => (string) $service['location_place_id'],
        'service_delivery_return_choice' => (string) $service['delivery_return_choice'],
        'service_dismantling_choice' => (string) $service['dismantling_choice'],
        'service_delivery_return_requested' => ! empty( $service['delivery_return_requested'] ) ? 1 : 0,
        'service_dismantling_requested' => ! empty( $service['dismantling_requested'] ) ? 1 : 0,
        'service_ack_delivery_only_terms' => ! empty( $service['ack_delivery_only_terms'] ) ? 1 : 0,
        'service_onsite_contact_name' => (string) $service['onsite_contact_name'],
        'service_onsite_contact_phone' => (string) $service['onsite_contact_phone'],
        'service_logistics_ztl' => ! empty( $service['logistics_ztl'] ) ? 1 : 0,
        'service_logistics_stairs' => (string) $service['logistics_stairs'],
        'service_logistics_lift' => ! empty( $service['logistics_lift'] ) ? 1 : 0,
        'service_logistics_tight_access' => ! empty( $service['logistics_tight_access'] ) ? 1 : 0,
        'service_logistics_walk_distance' => (string) $service['logistics_walk_distance'],
        'service_notes' => (string) $service['notes'],
        'service_event_start_date' => (string) $service['event_start_date'],
        'service_event_end_date' => (string) $service['event_end_date'],
        'service_event_days' => $service['event_days'] !== '' ? intval( $service['event_days'] ) : null,
        'service_tech_day_type' => (string) $service['tech_day_type'],
        'service_event_start' => $event_start,
        'service_event_end' => $event_end,
        'service_distance_status' => (string) $service['distance_status'],
        'service_distance_message' => (string) $service['distance_message'],
        'service_messages' => array_values( (array) $service['messages'] ),
        'pickup_location_name' => $pickup['location_name'],
        'pickup_address' => $pickup['address'],
        'pickup_maps_url' => $pickup['maps_url'],
    );
}

function bsn_build_frontend_service_request_lines( $service_snapshot ) {
    $service_snapshot = is_array( $service_snapshot ) ? $service_snapshot : array();
    $mode = sanitize_key( (string) ( $service_snapshot['service_mode'] ?? 'pickup' ) );
    $estimated_total = $service_snapshot['service_estimated_total'] ?? null;
    $pricing = bsn_get_public_service_pricing_config();
    $delivery_km_rate = (float) ( $pricing['delivery_km_rate'] ?? 1.20 );
    if ( $mode === 'pickup' || $estimated_total === null ) {
        return array();
    }

    $service_article_map = bsn_ensure_internal_service_articles();
    $lines = array();
    $push_line = function( $code, $price, $label, $qty = 1 ) use ( &$lines, $service_article_map, $mode ) {
        $price = round( (float) $price, 2 );
        $article_id = (int) ( $service_article_map[ $code ] ?? 0 );
        $qty = max( 1, intval( $qty ) );
        if ( $article_id < 1 || $price <= 0 ) {
            return;
        }

        $lines[] = array(
            'id' => $article_id,
            'qty' => $qty,
            'prezzo' => $price,
            'service_internal' => 1,
            'service_mode' => $mode,
            'service_component_code' => $code,
            'service_component_label' => $label,
        );
    };

    $distance_component = round( (float) ( $service_snapshot['service_distance_km'] ?? 0 ), 1 );

    if ( $mode === 'delivery_only' ) {
        $push_line( 'SERV-USCITA', 40.0, 'Solo consegna - uscita' );
        $push_line( 'SERV-KM-CONSEGNA', $distance_component * $delivery_km_rate, 'Solo consegna - km' );
        if ( ! empty( $service_snapshot['service_delivery_return_requested'] ) ) {
            $push_line( 'SERV-RITIRO', 40.0, 'Ritiro finale' );
            $push_line( 'SERV-KM-AR', $distance_component * $delivery_km_rate, 'Ritiro finale - km' );
        }
    } elseif ( $mode === 'delivery_install' ) {
        $push_line( 'SERV-MONTAGGIO-1TEC', 100.0, 'Trasporto con montaggio' );
        $push_line( 'SERV-KM-CONSEGNA', $distance_component * $delivery_km_rate, 'Trasporto con montaggio - km' );
        if ( ! empty( $service_snapshot['service_dismantling_requested'] ) ) {
            $push_line( 'SERV-SMONTAGGIO', 100.0, 'Smontaggio e ritiro' );
            $push_line( 'SERV-KM-AR', $distance_component * $delivery_km_rate, 'Smontaggio e ritiro - km' );
        }
    } elseif ( $mode === 'delivery_install_tech' ) {
        $tech_day_type = sanitize_key( (string) ( $service_snapshot['service_tech_day_type'] ?? '' ) );
        $is_half_day = $tech_day_type === 'half_day';
        $event_days = max( 1, intval( $service_snapshot['service_event_days'] ?? 1 ) );

        $push_line(
            $is_half_day ? 'SERV-TECNICO-MEZZA' : 'SERV-TECNICO-GIORNATA',
            $is_half_day ? 180.0 : 250.0,
            $is_half_day ? 'Tecnico mezza giornata' : 'Tecnico giornata intera',
            $event_days
        );
        $push_line( 'SERV-TRASFERTA', $distance_component * 1.20, 'Costo chilometrico' );
    }

    return $lines;
}

function bsn_map_service_snapshot_to_quote_cart_service( $snapshot ) {
    if ( ! is_array( $snapshot ) ) {
        return bsn_get_empty_quote_cart_service();
    }

    return bsn_normalize_quote_cart_service(
        array(
            'mode' => $snapshot['service_mode'] ?? 'pickup',
            'delivery_return_choice' => (string) ( $snapshot['service_delivery_return_choice'] ?? ( ! empty( $snapshot['service_delivery_return_requested'] ) ? 'yes' : 'no' ) ),
            'dismantling_choice' => (string) ( $snapshot['service_dismantling_choice'] ?? ( ! empty( $snapshot['service_dismantling_requested'] ) ? 'yes' : 'no' ) ),
            'delivery_return_requested' => ! empty( $snapshot['service_delivery_return_requested'] ) ? 1 : 0,
            'dismantling_requested' => ! empty( $snapshot['service_dismantling_requested'] ) ? 1 : 0,
            'ack_delivery_only_terms' => ! empty( $snapshot['service_ack_delivery_only_terms'] ) ? 1 : 0,
            'location_address' => (string) ( $snapshot['service_location_address'] ?? '' ),
            'location_lat' => $snapshot['service_location_lat'] ?? '',
            'location_lng' => $snapshot['service_location_lng'] ?? '',
            'distance_km' => $snapshot['service_distance_km'] ?? '',
            'distance_provider' => (string) ( $snapshot['service_distance_provider'] ?? '' ),
            'onsite_contact_name' => (string) ( $snapshot['service_onsite_contact_name'] ?? '' ),
            'onsite_contact_phone' => (string) ( $snapshot['service_onsite_contact_phone'] ?? '' ),
            'logistics_ztl' => ! empty( $snapshot['service_logistics_ztl'] ) ? 1 : 0,
            'logistics_stairs' => (string) ( $snapshot['service_logistics_stairs'] ?? 'none' ),
            'logistics_lift' => ! empty( $snapshot['service_logistics_lift'] ) ? 1 : 0,
            'logistics_tight_access' => ! empty( $snapshot['service_logistics_tight_access'] ) ? 1 : 0,
            'logistics_walk_distance' => (string) ( $snapshot['service_logistics_walk_distance'] ?? 'short' ),
            'notes' => (string) ( $snapshot['service_notes'] ?? '' ),
            'event_date' => ! empty( $snapshot['service_event_start'] ) ? substr( (string) $snapshot['service_event_start'], 0, 10 ) : '',
            'event_start_date' => (string) ( $snapshot['service_event_start_date'] ?? '' ),
            'event_end_date' => (string) ( $snapshot['service_event_end_date'] ?? '' ),
            'event_days' => $snapshot['service_event_days'] ?? '',
            'tech_day_type' => (string) ( $snapshot['service_tech_day_type'] ?? '' ),
            'event_start_time' => ! empty( $snapshot['service_event_start'] ) ? substr( (string) $snapshot['service_event_start'], 11, 5 ) : '',
            'event_end_time' => ! empty( $snapshot['service_event_end'] ) ? substr( (string) $snapshot['service_event_end'], 11, 5 ) : '',
            'estimated_total' => $snapshot['service_estimated_total'] ?? '',
            'quote_needs_review' => ! empty( $snapshot['service_quote_needs_review'] ) ? 1 : 0,
            'label' => (string) ( $snapshot['service_label'] ?? '' ),
            'distance_status' => (string) ( $snapshot['service_distance_status'] ?? 'not_required' ),
            'distance_message' => (string) ( $snapshot['service_distance_message'] ?? '' ),
            'messages' => (array) ( $snapshot['service_messages'] ?? array() ),
        )
    );
}

function bsn_get_noleggio_service_snapshot( $noleggio ) {
    return bsn_get_noleggio_snapshot_json( $noleggio, 'snapshot_servizi_json' );
}

function bsn_get_public_service_yes_no_label( $value ) {
    return ! empty( $value ) ? 'Si' : 'No';
}

function bsn_get_noleggio_service_summary_data( $service_snapshot ) {
    if ( ! is_array( $service_snapshot ) || empty( $service_snapshot ) ) {
        return array();
    }

    $mode = sanitize_key( (string) ( $service_snapshot['service_mode'] ?? '' ) );
    $has_meaningful_data = $mode !== '';

    if ( ! $has_meaningful_data ) {
        $meaningful_keys = array(
            'service_label',
            'service_location_address',
            'service_event_start',
            'service_event_end',
            'pickup_location_name',
            'pickup_address',
        );

        foreach ( $meaningful_keys as $meaningful_key ) {
            if ( ! empty( $service_snapshot[ $meaningful_key ] ) ) {
                $has_meaningful_data = true;
                break;
            }
        }
    }

    if ( ! $has_meaningful_data ) {
        return array();
    }

    if ( $mode === '' ) {
        $mode = 'pickup';
    }

    $mode_labels = bsn_get_public_service_mode_options();
    $stairs_labels = bsn_get_public_service_stairs_options();
    $walk_distance_labels = bsn_get_public_service_walk_distance_options();

    $label = trim( (string) ( $service_snapshot['service_label'] ?? '' ) );
    if ( $label === '' ) {
        $label = $mode_labels[ $mode ] ?? 'Modalita servizio';
    }

    $estimated_total = null;
    if ( isset( $service_snapshot['service_estimated_total'] ) && $service_snapshot['service_estimated_total'] !== '' && $service_snapshot['service_estimated_total'] !== null ) {
        $estimated_total = round( (float) $service_snapshot['service_estimated_total'], 2 );
    }

    $distance_km = null;
    if ( isset( $service_snapshot['service_distance_km'] ) && $service_snapshot['service_distance_km'] !== '' && $service_snapshot['service_distance_km'] !== null ) {
        $distance_km = round( (float) $service_snapshot['service_distance_km'], 1 );
    }

    $event_start = (string) ( $service_snapshot['service_event_start'] ?? '' );
    $event_end = (string) ( $service_snapshot['service_event_end'] ?? '' );
    $event_start_date = (string) ( $service_snapshot['service_event_start_date'] ?? '' );
    $event_end_date = (string) ( $service_snapshot['service_event_end_date'] ?? '' );
    if ( $event_start_date === '' && $event_start !== '' ) {
        $event_start_date = substr( $event_start, 0, 10 );
    }
    if ( $event_end_date === '' && $event_end !== '' ) {
        $event_end_date = substr( $event_end, 0, 10 );
    }

    $event_start_date_label = $event_start_date !== '' ? bsn_format_date_it( $event_start_date ) : '';
    $event_end_date_label = $event_end_date !== '' ? bsn_format_date_it( $event_end_date ) : '';
    $event_start_time = $event_start !== '' ? substr( $event_start, 11, 5 ) : '';
    $event_end_time = $event_end !== '' ? substr( $event_end, 11, 5 ) : '';
    $event_hours_label = '';
    if ( $event_start_time !== '' && $event_end_time !== '' ) {
        $event_hours_label = $event_start_time . ' - ' . $event_end_time;
    }

    $event_days = isset( $service_snapshot['service_event_days'] ) && $service_snapshot['service_event_days'] !== null && $service_snapshot['service_event_days'] !== ''
        ? max( 1, intval( $service_snapshot['service_event_days'] ) )
        : 0;
    if ( $event_days < 1 && $event_start_date !== '' && $event_end_date !== '' ) {
        $event_days = bsn_get_service_event_day_count( $event_start_date, $event_end_date );
    }

    $tech_day_type = sanitize_key( (string) ( $service_snapshot['service_tech_day_type'] ?? '' ) );
    $tech_day_type_label = '';
    if ( $tech_day_type === 'half_day' ) {
        $tech_day_type_label = 'Mezza giornata';
    } elseif ( $tech_day_type === 'full_day' ) {
        $tech_day_type_label = 'Giornata intera';
    }

    $messages = array_values(
        array_unique(
            array_filter(
                array_map(
                    function ( $message ) {
                        return sanitize_text_field( (string) $message );
                    },
                    array_merge(
                        (array) ( $service_snapshot['service_messages'] ?? array() ),
                        array( (string) ( $service_snapshot['service_distance_message'] ?? '' ) )
                    )
                )
            )
        )
    );

    $lines = array();
    $lines[] = array(
        'label' => 'Modalita scelta',
        'value' => $label,
    );

    if ( $mode === 'pickup' ) {
        $pickup_name = trim( (string) ( $service_snapshot['pickup_location_name'] ?? '' ) );
        $pickup_address = trim( (string) ( $service_snapshot['pickup_address'] ?? '' ) );
        $pickup_label = trim( $pickup_name . ( $pickup_name !== '' && $pickup_address !== '' ? ' - ' : '' ) . $pickup_address );
        $pickup_hours = array_values( array_filter( (array) ( bsn_get_public_service_pickup_context()['hours'] ?? array() ) ) );

        if ( $pickup_label !== '' ) {
            $lines[] = array(
                'label' => 'Sede ritiro',
                'value' => $pickup_label,
            );
        }

        if ( ! empty( $pickup_hours ) ) {
            $lines[] = array(
                'label' => 'Orari ritiro / riconsegna',
                'value' => implode( "\n", $pickup_hours ),
            );
        }

        if ( ! empty( $service_snapshot['pickup_maps_url'] ) ) {
            $lines[] = array(
                'label' => 'Google Maps',
                'value' => (string) $service_snapshot['pickup_maps_url'],
                'type'  => 'url',
            );
        }

        $lines[] = array(
            'label' => 'Ritiro e riconsegna',
            'value' => 'Coincidono con le date selezionate nel noleggio',
        );
    } else {
        $location_address = trim( (string) ( $service_snapshot['service_location_address'] ?? '' ) );
        if ( $location_address !== '' ) {
            $lines[] = array(
                'label' => 'Indirizzo location',
                'value' => $location_address,
            );
        }

        $onsite_contact_name = trim( (string) ( $service_snapshot['service_onsite_contact_name'] ?? '' ) );
        if ( $onsite_contact_name !== '' ) {
            $lines[] = array(
                'label' => 'Referente in loco',
                'value' => $onsite_contact_name,
            );
        }

        $onsite_contact_phone = trim( (string) ( $service_snapshot['service_onsite_contact_phone'] ?? '' ) );
        if ( $onsite_contact_phone !== '' ) {
            $lines[] = array(
                'label' => 'Telefono referente',
                'value' => $onsite_contact_phone,
            );
        }

        if ( $distance_km !== null ) {
            $lines[] = array(
                'label' => 'Distanza / costo chilometrico',
                'value' => number_format_i18n( $distance_km, 1 ) . ' km',
            );
        }

        if ( $mode === 'delivery_only' ) {
            $lines[] = array(
                'label' => 'Formula servizio',
                'value' => ! empty( $service_snapshot['service_delivery_return_requested'] )
                    ? 'Consegna + ritiro finale'
                    : 'Solo consegna',
            );
        } elseif ( $mode === 'delivery_install' ) {
            $lines[] = array(
                'label' => 'Formula servizio',
                'value' => ! empty( $service_snapshot['service_dismantling_requested'] )
                    ? 'Montaggio + smontaggio + ritiro finale'
                    : 'Trasporto con montaggio',
            );
        } elseif ( $mode === 'delivery_install_tech' ) {
            if ( $event_start_date_label !== '' ) {
                $lines[] = array(
                    'label' => 'Data inizio evento',
                    'value' => $event_start_date_label,
                );
            }
            if ( $event_end_date_label !== '' ) {
                $lines[] = array(
                    'label' => 'Data fine evento',
                    'value' => $event_end_date_label,
                );
            }
            if ( $event_hours_label !== '' ) {
                $lines[] = array(
                    'label' => 'Orari evento',
                    'value' => $event_hours_label,
                );
            }
            if ( $event_days > 0 ) {
                $lines[] = array(
                    'label' => 'Giorni evento considerati',
                    'value' => (string) $event_days,
                );
            }
            if ( $tech_day_type_label !== '' ) {
                $lines[] = array(
                    'label' => 'Tipologia calcolata',
                    'value' => $tech_day_type_label,
                );
            }
            $lines[] = array(
                'label' => 'Pre-stima tecnica',
                'value' => 'Calcolata su 1 tecnico minimo',
            );
        }

        $lines[] = array(
            'label' => 'ZTL',
            'value' => bsn_get_public_service_yes_no_label( $service_snapshot['service_logistics_ztl'] ?? 0 ),
        );
        $lines[] = array(
            'label' => 'Scale',
            'value' => $stairs_labels[ (string) ( $service_snapshot['service_logistics_stairs'] ?? 'none' ) ] ?? 'N/D',
        );
        $lines[] = array(
            'label' => 'Ascensore / montacarichi',
            'value' => bsn_get_public_service_yes_no_label( $service_snapshot['service_logistics_lift'] ?? 0 ),
        );
        $lines[] = array(
            'label' => 'Accesso difficile',
            'value' => bsn_get_public_service_yes_no_label( $service_snapshot['service_logistics_tight_access'] ?? 0 ),
        );
        $lines[] = array(
            'label' => 'Distanza scarico > utilizzo',
            'value' => $walk_distance_labels[ (string) ( $service_snapshot['service_logistics_walk_distance'] ?? 'short' ) ] ?? 'N/D',
        );

        $notes = trim( (string) ( $service_snapshot['service_notes'] ?? '' ) );
        if ( $notes !== '' ) {
            $lines[] = array(
                'label' => 'Note servizio',
                'value' => $notes,
            );
        }
    }

    $lines[] = array(
        'label' => 'Stima servizi',
        'value' => $estimated_total !== null
            ? 'EUR ' . number_format_i18n( $estimated_total, 2 )
            : 'Costo da definire dopo verifica staff',
    );

    $lines[] = array(
        'label' => 'Revisione staff',
        'value' => ! empty( $service_snapshot['service_quote_needs_review'] )
            ? 'Obbligatoria prima della conferma'
            : 'Non necessaria',
    );

    $disclaimer = $mode !== 'pickup'
        ? 'Il costo dei servizi logistici e tecnici e indicativo e soggetto a conferma da parte dello staff Black Star Service.'
        : '';

    if ( $disclaimer !== '' ) {
        $messages = array_values(
            array_filter(
                $messages,
                function ( $message ) use ( $disclaimer ) {
                    return trim( (string) $message ) !== trim( (string) $disclaimer );
                }
            )
        );
    }

    return array(
        'mode' => $mode,
        'label' => $label,
        'estimated_total' => $estimated_total,
        'needs_review' => ! empty( $service_snapshot['service_quote_needs_review'] ),
        'messages' => $messages,
        'lines' => $lines,
        'disclaimer' => $disclaimer,
    );
}

function bsn_render_noleggio_service_summary_html( $service_snapshot, $args = array() ) {
    $summary = bsn_get_noleggio_service_summary_data( $service_snapshot );
    if ( empty( $summary ) ) {
        return '';
    }

    $args = wp_parse_args(
        $args,
        array(
            'context' => 'document',
            'title' => 'Servizio logistico / tecnico',
        )
    );

    $context = sanitize_key( (string) $args['context'] );
    $title = sanitize_text_field( (string) $args['title'] );

    $wrapper_style = 'margin-bottom:15px; border:1px solid #000; padding:10px; background:#fafafa;';
    $title_style = 'margin:0 0 8px 0; font-size:12px; background:#000; color:#fff; padding:3px 8px; margin:-10px -10px 8px -10px;';
    $table_style = 'width:100%; font-size:10px; line-height:1.4; border-collapse:collapse;';
    $cell_label_style = 'width:35%; padding:3px 0; vertical-align:top;';
    $cell_value_style = 'padding:3px 0; vertical-align:top;';
    $note_style = 'margin-top:8px; font-size:9px; line-height:1.4; color:#444;';

    if ( $context === 'email' ) {
        $wrapper_style = 'margin:18px 0; border:1px solid #d9d9d9; padding:14px; background:#fafafa;';
        $title_style = 'margin:0 0 10px 0; font-size:15px; color:#111;';
        $table_style = 'width:100%; font-size:13px; line-height:1.5; border-collapse:collapse;';
        $cell_label_style = 'width:34%; padding:4px 0; vertical-align:top;';
        $cell_value_style = 'padding:4px 0; vertical-align:top;';
        $note_style = 'margin-top:10px; font-size:12px; line-height:1.5; color:#444;';
    } elseif ( $context === 'account' ) {
        $wrapper_style = 'margin:14px 0; border:1px solid #e1e4e8; padding:12px; background:#fff;';
        $title_style = 'margin:0 0 8px 0; font-size:14px; color:#111;';
        $table_style = 'width:100%; font-size:13px; line-height:1.5; border-collapse:collapse;';
        $cell_label_style = 'width:32%; padding:4px 0; vertical-align:top;';
        $cell_value_style = 'padding:4px 0; vertical-align:top;';
        $note_style = 'margin-top:8px; font-size:12px; line-height:1.5; color:#555;';
    } elseif ( $context === 'admin' ) {
        $wrapper_style = 'margin:10px 0; border:1px solid #ccd0d4; padding:10px; background:#fff;';
        $title_style = 'margin:0 0 8px 0; font-size:13px; color:#111;';
        $table_style = 'width:100%; font-size:12px; line-height:1.45; border-collapse:collapse;';
        $cell_label_style = 'width:30%; padding:3px 0; vertical-align:top;';
        $cell_value_style = 'padding:3px 0; vertical-align:top;';
        $note_style = 'margin-top:8px; font-size:12px; line-height:1.45; color:#555;';
    }

    $html = '<div class="bsn-service-summary bsn-service-summary-' . esc_attr( $context ) . '" style="' . esc_attr( $wrapper_style ) . '">';
    $html .= '<h3 style="' . esc_attr( $title_style ) . '">' . esc_html( $title ) . '</h3>';
    $html .= '<table style="' . esc_attr( $table_style ) . '">';

    foreach ( $summary['lines'] as $line ) {
        $value = (string) ( $line['value'] ?? '' );
        if ( $value === '' ) {
            continue;
        }

        $value_html = nl2br( esc_html( $value ) );
        if ( isset( $line['type'] ) && $line['type'] === 'url' ) {
            $value_html = '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
        }

        $html .= '<tr>';
        $html .= '<td style="' . esc_attr( $cell_label_style ) . '"><strong>' . esc_html( (string) ( $line['label'] ?? '' ) ) . ':</strong></td>';
        $html .= '<td style="' . esc_attr( $cell_value_style ) . '">' . $value_html . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table>';

    if ( ! empty( $summary['messages'] ) ) {
        $html .= '<div style="' . esc_attr( $note_style ) . '"><strong>Note servizio</strong><ul style="margin:6px 0 0 18px; padding:0;">';
        foreach ( $summary['messages'] as $message ) {
            $html .= '<li>' . esc_html( $message ) . '</li>';
        }
        $html .= '</ul></div>';
    }

    if ( ! empty( $summary['disclaimer'] ) ) {
        $html .= '<div style="' . esc_attr( $note_style ) . '"><strong>Disclaimer:</strong> ' . esc_html( $summary['disclaimer'] ) . '</div>';
    }

    $html .= '</div>';

    return $html;
}

function bsn_create_preventivo_from_quote_cart( $user_id, $payload ) {
    global $wpdb;

    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return new WP_Error( 'bsn_quote_submit_user', 'Utente non valido per l\'invio del preventivo.' );
    }

    bsn_ensure_noleggi_columns();

    $customer_context = bsn_get_quote_cart_customer_context();
    $cliente = $customer_context['customer'] ?? null;
    if ( ! $cliente || empty( $cliente['customer_id'] ) ) {
        return new WP_Error( 'bsn_quote_submit_customer', 'Account cliente non collegato correttamente. Accedi di nuovo e riprova.' );
    }

    $cart_state = isset( $payload['cart_state'] ) && is_array( $payload['cart_state'] )
        ? $payload['cart_state']
        : bsn_quote_cart_reprice( bsn_get_quote_cart() );

    if ( empty( $cart_state['item_count'] ) || empty( $cart_state['has_dates'] ) || ! empty( $cart_state['invalid_dates'] ) ) {
        return new WP_Error( 'bsn_quote_submit_cart', 'Il carrello non e pronto per l\'invio del preventivo.' );
    }

    $line_errors = [];
    foreach ( (array) ( $cart_state['items'] ?? [] ) as $line ) {
        if ( ! empty( $line['errors'] ) && is_array( $line['errors'] ) ) {
            foreach ( $line['errors'] as $error_message ) {
                $line_errors[] = (string) $error_message;
            }
        }
    }

    if ( ! empty( $cart_state['errors'] ) || ! empty( $line_errors ) ) {
        $all_errors = array_values( array_unique( array_merge( (array) ( $cart_state['errors'] ?? [] ), $line_errors ) ) );
        return new WP_Error(
            'bsn_quote_submit_validation',
            'Correggi prima il carrello: ' . implode( ' | ', $all_errors )
        );
    }

    $categoria_cliente = sanitize_key( (string) ( $cart_state['categoria_cliente'] ?? 'standard' ) );
    $data_ritiro = sanitize_text_field( (string) ( $cart_state['dates']['data_ritiro'] ?? '' ) );
    $data_riconsegna = sanitize_text_field( (string) ( $cart_state['dates']['data_riconsegna'] ?? '' ) );

    $editing = isset( $cart_state['editing'] ) && is_array( $cart_state['editing'] ) ? $cart_state['editing'] : array();
    $editing_noleggio_id = sanitize_text_field( (string) ( $editing['noleggio_id'] ?? '' ) );
    $editing_customer_note = sanitize_textarea_field( (string) ( $editing['customer_note'] ?? '' ) );
    $editing_noleggio = null;
    if ( $editing_noleggio_id !== '' ) {
        $editing_noleggio = bsn_get_customer_owned_noleggio( $user_id, $editing_noleggio_id );
        if ( ! $editing_noleggio ) {
            return new WP_Error(
                'bsn_quote_submit_edit_missing',
                'La richiesta che stavi modificando non e piu disponibile per questo account.'
            );
        }

        if ( sanitize_key( (string) ( $editing_noleggio['stato'] ?? '' ) ) !== 'preventivo' ) {
            return new WP_Error(
                'bsn_quote_submit_edit_locked',
                'Nel frattempo la richiesta ' . $editing_noleggio_id . ' e gia stata confermata. Per modifiche contattaci direttamente.'
            );
        }
    }

    $open_request_count = bsn_get_customer_frontend_open_request_count(
        (int) $cliente['customer_id'],
        $editing_noleggio_id
    );
    if ( $editing_noleggio_id === '' && $open_request_count >= 3 ) {
        return new WP_Error(
            'bsn_quote_submit_limit',
            'Hai gia 3 richieste di preventivo in lavorazione. Per crearne una nuova, attendi l\'elaborazione di una richiesta esistente oppure contatta Black Star Service.'
        );
    }

    $internal_lines = bsn_build_frontend_quote_internal_lines( $cart_state, $categoria_cliente, $data_ritiro, $data_riconsegna );
    if ( is_wp_error( $internal_lines ) ) {
        return $internal_lines;
    }

    $articoli_request = (array) ( $internal_lines['articoli_request'] ?? array() );
    $snapshot_articoli = (array) ( $internal_lines['snapshot_articoli'] ?? array() );
    $service_snapshot = bsn_build_frontend_service_snapshot( $cart_state['service'] ?? array(), $cart_state['dates'] ?? array() );
    $service_request_lines = bsn_build_frontend_service_request_lines( $service_snapshot );
    if ( ! empty( $service_request_lines ) ) {
        $articoli_request = array_merge( $articoli_request, $service_request_lines );
    }

    if ( empty( $articoli_request ) ) {
        return new WP_Error( 'bsn_quote_submit_items', 'Non ci sono prodotti validi da inviare nel preventivo.' );
    }

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';

    $snapshot_cliente = [
        'user_id' => $user_id,
        'customer_id' => (int) $cliente['customer_id'],
        'nome' => (string) ( $cliente['display_name'] ?? '' ),
        'display_name' => (string) ( $cliente['display_name'] ?? '' ),
        'email' => (string) ( $cliente['email'] ?? '' ),
        'telefono' => (string) ( $cliente['telefono'] ?? '' ),
        'cf_piva' => (string) ( $cliente['cf_piva'] ?? '' ),
        'categoria' => (string) ( $cliente['categoria'] ?? 'standard' ),
        'categoria_label' => (string) ( $cliente['categoria_label'] ?? 'Guest / standard' ),
    ];

    $snapshot_prezzi = [
        'pricing_source' => 'frontend_snapshot',
        'categoria_cliente' => $categoria_cliente,
        'categoria_label' => function_exists( 'bsn_get_public_customer_category_label' )
            ? bsn_get_public_customer_category_label( $categoria_cliente )
            : 'Guest / standard',
        'giorni_noleggio' => max( 1, intval( $cart_state['giorni_noleggio'] ?? 1 ) ),
        'total_qty' => max( 1, intval( $cart_state['total_qty'] ?? 0 ) ),
        'totale_prodotti_stimato' => round( (float) ( $cart_state['totale_prodotti_stimato'] ?? 0 ), 2 ),
        'service_estimated_total' => isset( $cart_state['service_estimated_total'] ) && $cart_state['service_estimated_total'] !== null
            ? round( (float) $cart_state['service_estimated_total'], 2 )
            : null,
        'totale_stimato' => round( (float) ( $cart_state['totale_stimato'] ?? 0 ), 2 ),
        'risparmio_scalare_totale' => round( (float) ( $cart_state['risparmio_scalare_totale'] ?? 0 ), 2 ),
        'risparmio_categoria_totale' => round( (float) ( $cart_state['risparmio_categoria_totale'] ?? 0 ), 2 ),
        'risparmio_totale' => round( (float) ( $cart_state['risparmio_totale'] ?? 0 ), 2 ),
        'currency' => 'EUR',
        'pricing_note' => 'Prezzi IVA esclusa salvo diversa indicazione. Eventuali imposte, servizi extra e adeguamenti saranno verificati dall\'operatore.',
        'service_pricing_note' => 'Il costo dei servizi logistici e tecnici e indicativo e soggetto a conferma da parte dello staff Black Star Service.',
    ];

    $snapshot_prezzi['frontend_initial_totale_stimato'] = $snapshot_prezzi['totale_stimato'];

    $pickup_context = bsn_get_public_service_pickup_context();
    $destination_snapshot = array(
        'service_location_address' => (string) ( $service_snapshot['service_location_address'] ?? '' ),
        'service_location_lat' => $service_snapshot['service_location_lat'] ?? null,
        'service_location_lng' => $service_snapshot['service_location_lng'] ?? null,
        'pickup_location_name' => $pickup_context['location_name'],
        'pickup_address' => $pickup_context['address'],
        'pickup_maps_url' => $pickup_context['maps_url'],
    );

    $is_update = is_array( $editing_noleggio );
    $noleggio_id = $is_update ? (string) $editing_noleggio['id'] : bsn_generate_noleggio_id();
    $note_for_save = bsn_build_quote_request_note(
        $payload['note'] ?? $editing_customer_note,
        $is_update ? 'update' : 'create',
        $is_update ? (string) ( $editing_noleggio['note'] ?? '' ) : ''
    );

    $data = bsn_filter_noleggi_table_data( [
        'cliente_id' => (int) $cliente['customer_id'],
        'data_inizio' => bsn_normalize_date_input( $data_ritiro, false ),
        'data_fine' => bsn_normalize_date_input( $data_riconsegna, true ),
        'data_ritiro' => bsn_normalize_date_input( $data_ritiro, false ),
        'data_riconsegna' => bsn_normalize_date_input( $data_riconsegna, true ),
        'stato' => 'preventivo',
        'articoli' => wp_json_encode( $articoli_request ),
        'totale_calcolato' => round( (float) ( $cart_state['totale_stimato'] ?? 0 ), 2 ),
        'sconto_globale' => 0,
        'note' => $note_for_save,
        'operatore_richiesta' => 'frontend',
        'metodo_pagamento' => '',
        'luogo_destinazione' => ( $service_snapshot['service_mode'] ?? 'pickup' ) === 'pickup'
            ? $pickup_context['address']
            : (string) ( $service_snapshot['service_location_address'] ?? '' ),
        'trasporto_mezzo' => (string) ( $service_snapshot['service_label'] ?? '' ),
        'cauzione' => '',
        'causale_trasporto' => ! empty( $service_snapshot['service_quote_needs_review'] )
            ? 'Servizio da verificare da staff'
            : 'Ritiro in sede',
        'km_distanza' => $service_snapshot['service_distance_km'] ?? null,
        'lat_destinazione' => $service_snapshot['service_location_lat'] ?? null,
        'lng_destinazione' => $service_snapshot['service_location_lng'] ?? null,
        'origine' => 'frontend',
        'snapshot_cliente_json' => wp_json_encode( $snapshot_cliente ),
        'snapshot_articoli_json' => wp_json_encode( $snapshot_articoli ),
        'snapshot_prezzi_json' => wp_json_encode( $snapshot_prezzi ),
        'snapshot_servizi_json' => wp_json_encode( $service_snapshot ),
        'snapshot_destinazione_json' => wp_json_encode( $destination_snapshot ),
    ] );

    if ( $is_update ) {
        $result = $wpdb->update( $table_noleggi, $data, array( 'id' => $noleggio_id ), null, array( '%s' ) );
        if ( $result === false ) {
            return new WP_Error( 'bsn_quote_submit_update', 'Errore nell\'aggiornamento del preventivo.' );
        }
    } else {
        $data['id'] = $noleggio_id;
        $data['data_richiesta'] = current_time( 'mysql' );
        $result = $wpdb->insert( $table_noleggi, $data );
        if ( ! $result ) {
            return new WP_Error( 'bsn_quote_submit_insert', 'Errore nel salvataggio del preventivo.' );
        }
    }

    $noleggio = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_noleggi WHERE id = %s", $noleggio_id ),
        ARRAY_A
    );

    $pdf_info = bsn_generate_preventivo_pdf( $noleggio_id, true );
    if ( is_wp_error( $pdf_info ) ) {
        error_log( 'BSN frontend quote PDF error ' . $noleggio_id . ': ' . $pdf_info->get_error_message() );
        $pdf_info = false;
    }

    bsn_invia_email_preventivo( $noleggio ?: $data, $snapshot_cliente, $pdf_info );

    return [
        'id' => $noleggio_id,
        'noleggio' => $noleggio ?: $data,
        'redirect_url' => bsn_get_quote_submitted_page_url( $noleggio_id ),
        'updated' => $is_update,
    ];
}

function bsn_get_public_product_min_qty( $product_id ) {
    $min_qty = 1;
    $articoli = bsn_get_public_product_articles( $product_id );
    foreach ( $articoli as $articolo ) {
        $min_qty = max( $min_qty, intval( $articolo['min_qty'] ?? 1 ) );
    }
    return max( 1, $min_qty );
}

function bsn_quote_cart_dates_are_set( $cart ) {
    $dates = isset( $cart['dates'] ) && is_array( $cart['dates'] ) ? $cart['dates'] : [];
    return ! empty( $dates['data_ritiro'] ) && ! empty( $dates['data_riconsegna'] );
}

function bsn_quote_cart_reprice( $cart ) {
    $cart = bsn_normalize_quote_cart( $cart );
    $categoria_cliente = function_exists( 'bsn_get_current_public_customer_category' ) ? bsn_get_current_public_customer_category() : 'standard';
    $customer_context = bsn_get_quote_cart_customer_context();
    $editing = bsn_get_quote_cart_editing_context( $cart );
    $service = bsn_prepare_quote_cart_service( $cart['service'] ?? array(), $cart['dates'] ?? array() );
    $dates = $cart['dates'];
    $data_ritiro = sanitize_text_field( (string) ( $dates['data_ritiro'] ?? '' ) );
    $data_riconsegna = sanitize_text_field( (string) ( $dates['data_riconsegna'] ?? '' ) );
    $has_dates = ( $data_ritiro !== '' && $data_riconsegna !== '' );
    $invalid_dates = $has_dates ? bsn_is_invalid_date_range( $data_ritiro, $data_riconsegna ) : false;

    $items_out = [];
    $cart_errors = [];
    $totale_stimato = 0.0;
    $total_qty = 0;
    $line_count = 0;
    $risparmio_scalare_totale = 0.0;
    $risparmio_categoria_totale = 0.0;
    $risparmio_quantita_totale = 0.0;
    $giorni_noleggio = 0;

    if ( $has_dates && ! $invalid_dates ) {
        $ts_ritiro = strtotime( $data_ritiro );
        $ts_riconsegna = strtotime( $data_riconsegna );
        if ( $ts_ritiro && $ts_riconsegna && $ts_riconsegna >= $ts_ritiro ) {
            $giorni_noleggio = max( 1, (int) ceil( ( $ts_riconsegna - $ts_ritiro ) / DAY_IN_SECONDS ) );
        }
    }

    foreach ( $cart['items'] as $item ) {
        $product_id = absint( $item['product_id'] ?? 0 );
        $qty = max( 1, intval( $item['qty'] ?? 1 ) );
        $dimension_data = bsn_normalize_quote_cart_dimension_data( $item );
        $line_key = bsn_build_quote_cart_line_key( $product_id, $dimension_data );
        $product = $product_id > 0 ? get_post( $product_id ) : null;

        $line = [
            'line_key' => $line_key,
            'product_id' => $product_id,
            'qty' => $qty,
            'title' => $product && $product->post_type === 'bs_prodotto' ? get_the_title( $product ) : 'Prodotto non disponibile',
            'permalink' => $product && $product->post_type === 'bs_prodotto' ? get_permalink( $product ) : '',
            'image_url' => '',
            'min_qty' => 1,
            'base_price' => null,
            'line_total' => null,
            'unit_price' => null,
            'line_savings' => 0.0,
            'availability_badge' => '',
            'available_units' => 0,
            'total_units' => 0,
            'warning_messages' => [],
            'errors' => [],
        ];
        $line = array_merge( $line, $dimension_data );

        if ( ! $product || $product->post_type !== 'bs_prodotto' ) {
            $line['errors'][] = 'Prodotto pubblico non piu disponibile.';
            $items_out[] = $line;
            $cart_errors[] = 'Uno dei prodotti nel carrello non e piu disponibile.';
            continue;
        }

        $total_qty += $qty;
        $gallery = bsn_get_public_product_gallery_urls( $product_id );
        $line['image_url'] = ! empty( $gallery[0] ) ? $gallery[0] : '';
        $line['min_qty'] = bsn_get_public_product_min_qty( $product_id );

        if ( $qty < $line['min_qty'] ) {
            $line['errors'][] = 'Quantita minima richiesta: ' . $line['min_qty'] . '.';
        }

        if ( ! $has_dates ) {
            $line['errors'][] = 'Imposta data ritiro e data riconsegna per calcolare il preventivo.';
            $items_out[] = $line;
            continue;
        }

        if ( $invalid_dates ) {
            $line['errors'][] = 'Le date del carrello non sono valide.';
            $items_out[] = $line;
            continue;
        }

        $availability = bsn_get_public_product_availability( $product_id, $data_ritiro, $data_riconsegna );
        $line['availability_badge'] = (string) ( $availability['badge_marketing'] ?? $availability['badge'] ?? '' );
        $line['available_units'] = intval( $availability['available_units'] ?? 0 );
        $line['total_units'] = intval( $availability['total_units'] ?? 0 );
        $line['warning_messages'] = isset( $availability['warning_messages'] ) && is_array( $availability['warning_messages'] ) ? $availability['warning_messages'] : [];
        $line['cart_reserved_qty'] = bsn_get_quote_cart_reserved_qty_for_product( $product_id, $cart );
        $reserved_other_lines = bsn_get_quote_cart_reserved_qty_for_product_except_line( $product_id, $cart, $line_key );
        $line['available_units_net'] = max( 0, $line['available_units'] - $reserved_other_lines );

        if ( $line['available_units'] < ( $reserved_other_lines + $qty ) ) {
            if ( $reserved_other_lines > 0 ) {
                $line['errors'][] = 'Hai gia nel preventivo ' . $line['cart_reserved_qty'] . ' di questo prodotto. Disponibilita residua per queste date: ' . $line['available_units_net'] . '.';
            } else {
                $line['errors'][] = 'Disponibilita insufficiente per queste date. Disponibili: ' . $line['available_units'] . '.';
            }
        }

        $preview = bsn_get_public_product_quote_preview( $product_id, $qty, $data_ritiro, $data_riconsegna, $categoria_cliente );
        if ( empty( $preview['success'] ) ) {
            $line['errors'][] = (string) ( $preview['message'] ?? 'Impossibile calcolare la stima.' );
            $items_out[] = $line;
            continue;
        }

        $line['base_price'] = round( (float) ( $preview['prezzo_standard'] ?? 0 ), 2 );
        $line['unit_price'] = round( (float) ( $preview['prezzo_netto'] ?? 0 ), 2 );
        $line['unit_price_before_qty_discount'] = round( (float) ( $preview['unit_price_before_qty_discount'] ?? $line['unit_price'] ), 2 );
        $line['unit_price_after_qty_discount'] = round( (float) ( $preview['unit_price_after_qty_discount'] ?? $line['unit_price'] ), 2 );
        $line['qty_discount_applied_pct'] = round( (float) ( $preview['qty_discount_applied_pct'] ?? 0 ), 4 );
        $line['line_total'] = round( (float) ( $preview['totale_stimato'] ?? 0 ), 2 );
        $line['giorni'] = intval( $preview['giorni'] ?? 1 );
        $line['noleggio_scalare'] = ! empty( $preview['noleggio_scalare'] );
        $line['risparmio_scalare'] = round( (float) ( $preview['risparmio_scalare'] ?? 0 ), 2 );
        $line['risparmio_categoria'] = round( (float) ( $preview['risparmio_categoria'] ?? 0 ), 2 );
        $line['risparmio_quantita'] = round( (float) ( $preview['risparmio_quantita'] ?? 0 ), 2 );
        $line['line_savings'] = round( $line['risparmio_scalare'] + $line['risparmio_categoria'] + $line['risparmio_quantita'], 2 );

        $items_out[] = $line;
        $line_count++;
        $totale_stimato += (float) $line['line_total'];
        $risparmio_scalare_totale += (float) $line['risparmio_scalare'];
        $risparmio_categoria_totale += (float) $line['risparmio_categoria'];
        $risparmio_quantita_totale += (float) $line['risparmio_quantita'];
    }

    $totale_prodotti_stimato = round( $totale_stimato, 2 );
    $service_estimated_total = $service['estimated_total'] !== '' ? round( (float) $service['estimated_total'], 2 ) : null;
    if ( $service_estimated_total !== null ) {
        $totale_stimato += $service_estimated_total;
    }

    if ( $invalid_dates ) {
        $cart_errors[] = 'Le date globali del carrello non sono valide.';
    } elseif ( ! $has_dates && ! empty( $cart['items'] ) ) {
        $cart_errors[] = 'Imposta le date globali del carrello per ricalcolare il preventivo.';
    }

    $submit_blockers = [];
    $editing_context = array(
        'noleggio_id' => $editing['noleggio_id'],
        'customer_note' => $editing['customer_note'],
        'is_locked' => false,
        'message' => '',
    );
    $open_request_limit = 3;
    $open_request_count = 0;

    if ( $editing_context['noleggio_id'] !== '' ) {
        if ( empty( $customer_context['is_logged_in'] ) ) {
            $editing_context['is_locked'] = true;
            $editing_context['message'] = 'Accedi di nuovo per continuare a modificare la richiesta ' . $editing_context['noleggio_id'] . '.';
        } else {
            $editing_noleggio = bsn_get_customer_owned_noleggio( get_current_user_id(), $editing_context['noleggio_id'] );
            if ( ! $editing_noleggio ) {
                $editing_context['is_locked'] = true;
                $editing_context['message'] = 'La richiesta ' . $editing_context['noleggio_id'] . ' non e piu disponibile per questo account.';
            } elseif ( sanitize_key( (string) ( $editing_noleggio['stato'] ?? '' ) ) !== 'preventivo' ) {
                $editing_context['is_locked'] = true;
                $editing_context['message'] = 'Nel frattempo la richiesta ' . $editing_context['noleggio_id'] . ' e gia stata confermata. Per modifiche contattaci direttamente.';
            } else {
                $editing_context['message'] = 'Stai modificando la richiesta ' . $editing_context['noleggio_id'] . '. Il prossimo invio aggiornera quella esistente.';
            }
        }
    }

    if ( ! $customer_context['is_logged_in'] ) {
        $submit_blockers[] = 'Per inviare la richiesta devi accedere o registrarti.';
    } elseif ( empty( $customer_context['customer'] ) ) {
        $submit_blockers[] = 'Il tuo account non risulta ancora collegato correttamente al profilo cliente.';
    } else {
        $open_request_count = bsn_get_customer_frontend_open_request_count(
            (int) ( $customer_context['customer']['customer_id'] ?? 0 ),
            $editing_context['noleggio_id']
        );
        if ( $editing_context['noleggio_id'] === '' && $open_request_count >= $open_request_limit ) {
            $submit_blockers[] = 'Hai gia 3 richieste di preventivo in lavorazione. Per crearne una nuova, attendi l\'elaborazione di una richiesta esistente oppure contatta Black Star Service.';
        }
    }

    if ( empty( $items_out ) ) {
        $submit_blockers[] = 'Il carrello e vuoto.';
    }

    if ( ! $has_dates || $invalid_dates ) {
        $submit_blockers[] = 'Imposta un periodo valido di ritiro e riconsegna prima di inviare il preventivo.';
    }

    if ( ! empty( $cart_errors ) ) {
        $submit_blockers = array_merge( $submit_blockers, $cart_errors );
    }

    if ( ! empty( $service['errors'] ) ) {
        $submit_blockers = array_merge( $submit_blockers, $service['errors'] );
    }

    foreach ( $items_out as $line ) {
        if ( ! empty( $line['errors'] ) ) {
            $submit_blockers[] = 'Almeno un prodotto del carrello richiede correzioni prima dell\'invio.';
            break;
        }
    }

    if ( $editing_context['is_locked'] && $editing_context['message'] !== '' ) {
        $submit_blockers[] = $editing_context['message'];
    } elseif ( $editing_context['noleggio_id'] !== '' ) {
        foreach ( $items_out as $line ) {
            if ( ! empty( $line['errors'] ) ) {
                $submit_blockers[] = 'La disponibilita attuale e cambiata rispetto alla richiesta originale: aggiorna il carrello o contattaci per trovare una soluzione.';
                break;
            }
        }
    }

    $submit_blockers = array_values( array_unique( array_filter( $submit_blockers ) ) );
    $can_submit = empty( $submit_blockers ) && $line_count === count( $items_out );

    return [
        'dates' => [
            'data_ritiro' => $data_ritiro,
            'data_riconsegna' => $data_riconsegna,
        ],
        'has_dates' => $has_dates,
        'invalid_dates' => $invalid_dates,
        'item_count' => count( $items_out ),
        'total_qty' => $total_qty,
        'giorni_noleggio' => $giorni_noleggio,
        'priced_item_count' => $line_count,
        'categoria_cliente' => $categoria_cliente,
        'items' => $items_out,
        'totale_stimato' => round( $totale_stimato, 2 ),
        'totale_prodotti_stimato' => $totale_prodotti_stimato,
        'service_estimated_total' => $service_estimated_total,
        'risparmio_scalare_totale' => round( $risparmio_scalare_totale, 2 ),
        'risparmio_categoria_totale' => round( $risparmio_categoria_totale, 2 ),
        'risparmio_quantita_totale' => round( $risparmio_quantita_totale, 2 ),
        'risparmio_totale' => round( $risparmio_scalare_totale + $risparmio_categoria_totale + $risparmio_quantita_totale, 2 ),
        'currency' => 'EUR',
        'is_logged_in' => ! empty( $customer_context['is_logged_in'] ),
        'customer' => $customer_context['customer'],
        'service' => $service,
        'editing' => $editing_context,
        'login_url' => $customer_context['login_url'],
        'register_url' => $customer_context['register_url'],
        'account_url' => $customer_context['account_url'],
        'submitted_url' => $customer_context['submitted_url'],
        'open_request_count' => $open_request_count,
        'open_request_limit' => $open_request_limit,
        'can_submit' => $can_submit,
        'submit_blockers' => $submit_blockers,
        'errors' => array_values( array_unique( array_filter( $cart_errors ) ) ),
        'cart_url' => bsn_get_quote_cart_page_url(),
    ];
}

function bsn_api_quote_cart_get( WP_REST_Request $request ) {
    return rest_ensure_response(
        array_merge(
            [ 'success' => true ],
            bsn_quote_cart_reprice( bsn_get_quote_cart() )
        )
    );
}

function bsn_get_quote_cart_service_from_request( WP_REST_Request $request ) {
    return array(
        'mode' => $request->get_param( 'service_mode' ),
        'delivery_return_choice' => $request->get_param( 'service_delivery_return_choice' ),
        'dismantling_choice' => $request->get_param( 'service_dismantling_choice' ),
        'delivery_return_requested' => $request->get_param( 'service_delivery_return_requested' ),
        'dismantling_requested' => $request->get_param( 'service_dismantling_requested' ),
        'ack_delivery_only_terms' => $request->get_param( 'service_ack_delivery_only_terms' ),
        'location_address'  => $request->get_param( 'service_location_address' ),
        'location_lat'      => $request->get_param( 'service_location_lat' ),
        'location_lng'      => $request->get_param( 'service_location_lng' ),
        'location_place_id' => $request->get_param( 'service_location_place_id' ),
        'onsite_contact_name' => $request->get_param( 'service_onsite_contact_name' ),
        'onsite_contact_phone' => $request->get_param( 'service_onsite_contact_phone' ),
        'logistics_ztl' => $request->get_param( 'service_logistics_ztl' ),
        'logistics_stairs' => $request->get_param( 'service_logistics_stairs' ),
        'logistics_lift' => $request->get_param( 'service_logistics_lift' ),
        'logistics_tight_access' => $request->get_param( 'service_logistics_tight_access' ),
        'logistics_walk_distance' => $request->get_param( 'service_logistics_walk_distance' ),
        'notes' => $request->get_param( 'service_notes' ),
        'event_date' => $request->get_param( 'service_event_date' ),
        'event_start_time' => $request->get_param( 'service_event_start_time' ),
        'event_end_time' => $request->get_param( 'service_event_end_time' ),
    );
}

function bsn_api_quote_cart_add( WP_REST_Request $request ) {
    $product_id = absint( $request->get_param( 'product_id' ) );
    $qty = max( 1, intval( $request->get_param( 'qty' ) ) );
    $force_dates = ! empty( $request->get_param( 'force_dates' ) );
    $product = $product_id > 0 ? get_post( $product_id ) : null;

    if ( ! $product || $product->post_type !== 'bs_prodotto' ) {
        return new WP_Error( 'bsn_quote_cart_product', 'Prodotto pubblico non valido.', [ 'status' => 400 ] );
    }

    $cart = bsn_get_quote_cart();
    $data_ritiro = sanitize_text_field( (string) $request->get_param( 'data_ritiro' ) );
    $data_riconsegna = sanitize_text_field( (string) $request->get_param( 'data_riconsegna' ) );

    if ( $data_ritiro === '' && ! empty( $cart['dates']['data_ritiro'] ) ) {
        $data_ritiro = (string) $cart['dates']['data_ritiro'];
    }
    if ( $data_riconsegna === '' && ! empty( $cart['dates']['data_riconsegna'] ) ) {
        $data_riconsegna = (string) $cart['dates']['data_riconsegna'];
    }

    if ( $data_ritiro === '' || $data_riconsegna === '' ) {
        return new WP_Error( 'bsn_quote_cart_dates', 'Seleziona data ritiro e data riconsegna prima di aggiungere il prodotto.', [ 'status' => 400 ] );
    }

    if ( bsn_is_invalid_date_range( $data_ritiro, $data_riconsegna ) ) {
        return new WP_Error( 'bsn_quote_cart_dates_invalid', 'La data di riconsegna deve essere uguale o successiva alla data di ritiro.', [ 'status' => 400 ] );
    }

    $cart_has_dates = bsn_quote_cart_dates_are_set( $cart );
    $dates_differ = $cart_has_dates && (
        (string) $cart['dates']['data_ritiro'] !== $data_ritiro ||
        (string) $cart['dates']['data_riconsegna'] !== $data_riconsegna
    );

    if ( $dates_differ && ! $force_dates ) {
        return rest_ensure_response( [
            'success' => false,
            'code' => 'dates_conflict',
            'message' => 'Il carrello usa gia un altro periodo. Vuoi aggiornare tutto il carrello a queste nuove date?',
            'cart' => bsn_quote_cart_reprice( $cart ),
            'cart_dates' => $cart['dates'],
            'requested_dates' => [
                'data_ritiro' => $data_ritiro,
                'data_riconsegna' => $data_riconsegna,
            ],
        ] );
    }

    $cart['dates'] = [
        'data_ritiro' => $data_ritiro,
        'data_riconsegna' => $data_riconsegna,
    ];

    $min_qty = bsn_get_public_product_min_qty( $product_id );
    $dimension_context = bsn_get_public_product_dimension_request_context( $product_id, $request, $qty );
    if ( ! empty( $dimension_context['errors'] ) ) {
        return new WP_Error( 'bsn_quote_cart_dimension', implode( ' ', $dimension_context['errors'] ), [ 'status' => 400 ] );
    }

    $qty = max( 1, intval( $dimension_context['qty'] ?? $qty ) );
    $dimension_data = bsn_normalize_quote_cart_dimension_data( $dimension_context['dimension_data'] ?? array() );
    $line_key = bsn_build_quote_cart_line_key( $product_id, $dimension_data );

    if ( $qty < $min_qty ) {
        return new WP_Error( 'bsn_quote_cart_min_qty', 'Quantita minima richiesta: ' . $min_qty . '.', [ 'status' => 400 ] );
    }

    $index = bsn_quote_cart_get_item_index( $cart, $product_id, $line_key );
    $current_line_qty = $index >= 0 ? max( 1, intval( $cart['items'][ $index ]['qty'] ?? 1 ) ) : 0;
    $new_qty = $qty + $current_line_qty;
    $reserved_other_lines = bsn_get_quote_cart_reserved_qty_for_product_except_line( $product_id, $cart, $line_key );
    $new_product_qty = $reserved_other_lines + $new_qty;
    if ( $index >= 0 ) {
        $cart['items'][ $index ] = array_merge(
            $cart['items'][ $index ],
            $dimension_data,
            array(
                'line_key' => $line_key,
                'product_id' => $product_id,
            )
        );
    }

    $availability = bsn_get_public_product_availability( $product_id, $data_ritiro, $data_riconsegna );
    if ( intval( $availability['available_units'] ?? 0 ) < $new_product_qty ) {
        $residual_qty = max( 0, intval( $availability['available_units'] ?? 0 ) - $reserved_other_lines );
        $error_message = $reserved_other_lines > 0 || $current_line_qty > 0
            ? 'Hai gia nel preventivo ' . ( $reserved_other_lines + $current_line_qty ) . ' di questo prodotto. Disponibilita residua per queste date: ' . $residual_qty . '.'
            : 'Disponibilita insufficiente per queste date. Disponibili: ' . intval( $availability['available_units'] ?? 0 ) . '.';
        return new WP_Error(
            'bsn_quote_cart_availability',
            $error_message,
            [ 'status' => 400 ]
        );
    }

    if ( $index >= 0 ) {
        $cart['items'][ $index ]['qty'] = $new_qty;
    } else {
        $cart['items'][] = array_merge(
            [
                'line_key' => $line_key,
                'product_id' => $product_id,
                'qty' => $qty,
            ],
            $dimension_data
        );
    }

    bsn_set_quote_cart( $cart );

    return rest_ensure_response( [
        'success' => true,
        'message' => 'Prodotto aggiunto al preventivo.',
        'cart' => bsn_quote_cart_reprice( $cart ),
    ] );
}

function bsn_api_quote_cart_update_item( WP_REST_Request $request ) {
    $product_id = absint( $request->get_param( 'product_id' ) );
    $line_key = sanitize_text_field( (string) $request->get_param( 'line_key' ) );
    $qty = intval( $request->get_param( 'qty' ) );
    $cart = bsn_get_quote_cart();
    $index = bsn_quote_cart_get_item_index( $cart, $product_id, $line_key );

    if ( $index < 0 ) {
        return new WP_Error( 'bsn_quote_cart_missing_item', 'Prodotto non presente nel carrello.', [ 'status' => 404 ] );
    }

    if ( $product_id < 1 ) {
        $product_id = absint( $cart['items'][ $index ]['product_id'] ?? 0 );
    }

    if ( $qty < 1 ) {
        array_splice( $cart['items'], $index, 1 );
        bsn_set_quote_cart( $cart );
        return rest_ensure_response( [
            'success' => true,
            'message' => 'Prodotto rimosso dal preventivo.',
            'cart' => bsn_quote_cart_reprice( $cart ),
        ] );
    }

    $min_qty = bsn_get_public_product_min_qty( $product_id );
    if ( $qty < $min_qty ) {
        return new WP_Error( 'bsn_quote_cart_min_qty', 'Quantita minima richiesta: ' . $min_qty . '.', [ 'status' => 400 ] );
    }

    if ( bsn_quote_cart_dates_are_set( $cart ) && ! bsn_is_invalid_date_range( $cart['dates']['data_ritiro'], $cart['dates']['data_riconsegna'] ) ) {
        $availability = bsn_get_public_product_availability( $product_id, $cart['dates']['data_ritiro'], $cart['dates']['data_riconsegna'] );
        $line_key = $line_key !== '' ? $line_key : sanitize_text_field( (string) ( $cart['items'][ $index ]['line_key'] ?? '' ) );
        $reserved_other_lines = bsn_get_quote_cart_reserved_qty_for_product_except_line( $product_id, $cart, $line_key );
        if ( intval( $availability['available_units'] ?? 0 ) < ( $reserved_other_lines + $qty ) ) {
            $current_cart_qty = bsn_get_quote_cart_reserved_qty_for_product( $product_id, $cart );
            $residual_qty = max( 0, intval( $availability['available_units'] ?? 0 ) - $reserved_other_lines );
            $error_message = $current_cart_qty > 0
                ? 'Hai gia nel preventivo ' . $current_cart_qty . ' di questo prodotto. Disponibilita residua per queste date: ' . $residual_qty . '.'
                : 'Disponibilita insufficiente per queste date. Disponibili: ' . intval( $availability['available_units'] ?? 0 ) . '.';
            return new WP_Error(
                'bsn_quote_cart_availability',
                $error_message,
                [ 'status' => 400 ]
            );
        }
    }

    $cart['items'][ $index ]['qty'] = $qty;
    bsn_set_quote_cart( $cart );

    return rest_ensure_response( [
        'success' => true,
        'message' => 'Quantita aggiornata.',
        'cart' => bsn_quote_cart_reprice( $cart ),
    ] );
}

function bsn_api_quote_cart_remove_item( WP_REST_Request $request ) {
    $product_id = absint( $request->get_param( 'product_id' ) );
    $line_key = sanitize_text_field( (string) $request->get_param( 'line_key' ) );
    $cart = bsn_get_quote_cart();
    $index = bsn_quote_cart_get_item_index( $cart, $product_id, $line_key );

    if ( $index < 0 ) {
        return new WP_Error( 'bsn_quote_cart_missing_item', 'Prodotto non presente nel carrello.', [ 'status' => 404 ] );
    }

    array_splice( $cart['items'], $index, 1 );
    bsn_set_quote_cart( $cart );

    return rest_ensure_response( [
        'success' => true,
        'message' => 'Prodotto rimosso dal preventivo.',
        'cart' => bsn_quote_cart_reprice( $cart ),
    ] );
}

function bsn_api_quote_cart_set_dates( WP_REST_Request $request ) {
    $data_ritiro = sanitize_text_field( (string) $request->get_param( 'data_ritiro' ) );
    $data_riconsegna = sanitize_text_field( (string) $request->get_param( 'data_riconsegna' ) );

    if ( $data_ritiro === '' || $data_riconsegna === '' ) {
        return new WP_Error( 'bsn_quote_cart_dates', 'Inserisci sia la data di ritiro sia la data di riconsegna.', [ 'status' => 400 ] );
    }

    if ( bsn_is_invalid_date_range( $data_ritiro, $data_riconsegna ) ) {
        return new WP_Error( 'bsn_quote_cart_dates_invalid', 'La data di riconsegna deve essere uguale o successiva alla data di ritiro.', [ 'status' => 400 ] );
    }

    $cart = bsn_get_quote_cart();
    $cart['dates'] = [
        'data_ritiro' => $data_ritiro,
        'data_riconsegna' => $data_riconsegna,
    ];
    bsn_set_quote_cart( $cart );

    return rest_ensure_response( [
        'success' => true,
        'message' => 'Date globali aggiornate.',
        'cart' => bsn_quote_cart_reprice( $cart ),
    ] );
}

function bsn_api_quote_cart_clear( WP_REST_Request $request ) {
    bsn_clear_quote_cart();
    return rest_ensure_response( [
        'success' => true,
        'message' => 'Carrello svuotato.',
        'cart' => bsn_quote_cart_reprice( bsn_get_quote_cart() ),
    ] );
}

function bsn_api_quote_cart_set_service( WP_REST_Request $request ) {
    $cart = bsn_get_quote_cart();
    $cart['service'] = bsn_get_quote_cart_service_from_request( $request );
    bsn_set_quote_cart( $cart );

    return rest_ensure_response( array(
        'success' => true,
        'message' => 'Servizi aggiornati.',
        'cart' => bsn_quote_cart_reprice( $cart ),
    ) );
}

function bsn_quote_cart_submit_permission_callback() {
    if ( is_user_logged_in() ) {
        return true;
    }

    return new WP_Error( 'bsn_quote_submit_auth', 'Devi accedere prima di inviare il preventivo.', [ 'status' => 401 ] );
}

function bsn_api_quote_cart_submit( WP_REST_Request $request ) {
    $cart = bsn_get_quote_cart();
    $service_payload = bsn_get_quote_cart_service_from_request( $request );
    if ( ! empty( $service_payload['mode'] ) ) {
        $cart['service'] = $service_payload;
        bsn_set_quote_cart( $cart );
        $cart = bsn_get_quote_cart();
    }

    $cart_state = bsn_quote_cart_reprice( $cart );
    $submission = bsn_create_preventivo_from_quote_cart(
        get_current_user_id(),
        [
            'note' => $request->get_param( 'note' ),
            'cart_state' => $cart_state,
        ]
    );

    if ( is_wp_error( $submission ) ) {
        return $submission;
    }

    bsn_clear_quote_cart();

    return rest_ensure_response( [
        'success' => true,
        'message' => ! empty( $submission['updated'] )
            ? 'Richiesta aggiornata correttamente.'
            : 'Richiesta di preventivo inviata correttamente.',
        'id' => $submission['id'],
        'updated' => ! empty( $submission['updated'] ),
        'redirect_url' => $submission['redirect_url'],
        'cart' => bsn_quote_cart_reprice( bsn_get_quote_cart() ),
    ] );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'bsn/v1', '/quote-cart', [
        'methods' => 'GET',
        'callback' => 'bsn_api_quote_cart_get',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'bsn/v1', '/quote-cart/add', [
        'methods' => 'POST',
        'callback' => 'bsn_api_quote_cart_add',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'bsn/v1', '/quote-cart/update-item', [
        'methods' => 'POST',
        'callback' => 'bsn_api_quote_cart_update_item',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'bsn/v1', '/quote-cart/remove-item', [
        'methods' => 'POST',
        'callback' => 'bsn_api_quote_cart_remove_item',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'bsn/v1', '/quote-cart/dates', [
        'methods' => 'POST',
        'callback' => 'bsn_api_quote_cart_set_dates',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'bsn/v1', '/quote-cart/service', [
        'methods' => 'POST',
        'callback' => 'bsn_api_quote_cart_set_service',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'bsn/v1', '/quote-cart/clear', [
        'methods' => 'POST',
        'callback' => 'bsn_api_quote_cart_clear',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'bsn/v1', '/quote-cart/submit', [
        'methods' => 'POST',
        'callback' => 'bsn_api_quote_cart_submit',
        'permission_callback' => 'bsn_quote_cart_submit_permission_callback',
    ] );
} );

function bsn_locate_public_catalog_template( $filename ) {
    $filename = ltrim( (string) $filename, '/\\' );
    if ( $filename === '' ) {
        return '';
    }

    $theme_override = trailingslashit( get_stylesheet_directory() ) . 'blackstar-noleggi/' . $filename;
    if ( file_exists( $theme_override ) ) {
        return $theme_override;
    }

    $plugin_template = BSN_PATH . 'templates/' . $filename;
    if ( file_exists( $plugin_template ) ) {
        return $plugin_template;
    }

    return '';
}

function bsn_public_catalog_template_include( $template ) {
    if ( is_singular( 'bs_prodotto' ) ) {
        $located = bsn_locate_public_catalog_template( 'single-bs_prodotto.php' );
        return $located ?: $template;
    }

    if ( is_post_type_archive( 'bs_prodotto' ) ) {
        $located = bsn_locate_public_catalog_template( 'archive-bs_prodotto.php' );
        return $located ?: $template;
    }

    if ( is_tax( 'bs_categoria_prodotto' ) ) {
        $located = bsn_locate_public_catalog_template( 'taxonomy-bs_categoria_prodotto.php' );
        return $located ?: $template;
    }

    return $template;
}
add_filter( 'template_include', 'bsn_public_catalog_template_include', 99 );

function bsn_public_catalog_main_query( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if ( $query->is_tax( 'bs_categoria_prodotto' ) || $query->is_post_type_archive( 'bs_prodotto' ) ) {
        $per_page = (int) apply_filters( 'bsn_public_catalog_posts_per_page', 15 );
        $query->set( 'post_type', 'bs_prodotto' );
        $query->set( 'post_status', 'publish' );
        $query->set( 'posts_per_page', max( 1, $per_page ) );
        $query->set( 'ignore_sticky_posts', true );
    }
}
add_action( 'pre_get_posts', 'bsn_public_catalog_main_query', 20 );

function bsn_add_public_product_columns($columns) {
    $new_columns = [];
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'title') {
            $new_columns['bsn_badge_trasporto'] = 'Badge trasporto';
            $new_columns['bsn_override_disponibilita'] = 'Override disponibilita';
            $new_columns['bsn_disponibilita_attuale'] = 'Disponibilita attuale';
        }
    }

    return $new_columns;
}
add_filter('manage_bs_prodotto_posts_columns', 'bsn_add_public_product_columns');

function bsn_render_public_product_columns($column, $post_id) {
    if ($column === 'bsn_badge_trasporto') {
        $value = get_post_meta($post_id, '_bsn_badge_trasporto', true);
        echo $value !== '' ? esc_html($value) : '&mdash;';
        return;
    }

    if ($column === 'bsn_override_disponibilita') {
        $value = get_post_meta($post_id, '_bsn_override_disponibilita', true);
        echo $value !== '' ? esc_html($value) : '&mdash;';
        return;
    }

    if ($column === 'bsn_disponibilita_attuale') {
        $availability = bsn_get_public_product_availability($post_id);
        $label = (string) ($availability['badge_marketing'] ?? '');
        $available_units = intval($availability['available_units'] ?? 0);
        $total_units = intval($availability['total_units'] ?? 0);

        if ($label === '') {
            $label = 'Non disponibile';
        }

        echo esc_html($label);
        if ($total_units > 0) {
            echo '<br><small>' . esc_html($available_units . ' / ' . $total_units . ' unita') . '</small>';
        }
    }
}
add_action('manage_bs_prodotto_posts_custom_column', 'bsn_render_public_product_columns', 10, 2);

/**
 * Attivazione: crea / aggiorna tabelle BS Noleggi
 */
function bsn_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Clienti
    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $sql_clienti = "CREATE TABLE $table_clienti (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        -- Anagrafica
        nome varchar(150) NOT NULL,              -- Nome / ragione sociale
        cognome varchar(100),                    -- Cognome (se serve separato)
        cf_piva varchar(32) NOT NULL,            -- CF o P.IVA
        telefono varchar(30) NOT NULL,
        indirizzo varchar(255),
        cap varchar(10),
        citta varchar(100),
        email varchar(150) NOT NULL,

        -- Categoria cliente (per scontistica)
        categoria_cliente enum('standard','fidato','premium','service','collaboratori') DEFAULT 'standard',

        -- Regime fiscale
        regime_percentuale decimal(5,2) DEFAULT 22.00,  -- es. 22, 10, 0
        regime_note varchar(150),                       -- es. \"esente art. 10\"

        -- Documenti identità
        doc_fronte varchar(255),
        doc_retro varchar(255),
        tipo_documento varchar(50),
        numero_documento varchar(50),

        -- Note interne cliente
        note text,

        data_creazione datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    // Articoli
    $table_articoli = $wpdb->prefix . 'bs_articoli';
    $sql_articoli = "CREATE TABLE $table_articoli (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        nome varchar(150) NOT NULL,
        codice varchar(50) UNIQUE,
        prezzo_giorno decimal(10,2) NOT NULL DEFAULT 0,
        valore_bene  decimal(10,2) NOT NULL DEFAULT 0,
        -- Sconti per categoria cliente
        sconto_standard decimal(5,2) DEFAULT 0,
        sconto_fidato decimal(5,2) DEFAULT 0,
        sconto_premium decimal(5,2) DEFAULT 0,
        sconto_service decimal(5,2) DEFAULT 0,
        sconto_collaboratori decimal(5,2) DEFAULT 0,
        qty_discount_enabled tinyint(1) NOT NULL DEFAULT 0,
        qty_discount_start_qty int NULL,
        qty_discount_max_qty int NULL,
        qty_discount_max_pct decimal(5,2) DEFAULT 0,
        -- Flag scalare
        noleggio_scalare tinyint(1) NOT NULL DEFAULT 0,
        ubicazione varchar(100),
        external_product_url varchar(255),
        external_product_slug varchar(200),
        external_product_name varchar(255),
        external_image_url varchar(255),
        external_last_sync datetime,
        qty_disponibile int DEFAULT 1,
        inventory_mode varchar(20) NOT NULL DEFAULT 'seriale',
        prodotto_pubblico_id bigint(20) unsigned NULL,
        min_qty int NOT NULL DEFAULT 1,
        larghezza_cm decimal(10,2) NULL,
        altezza_cm decimal(10,2) NULL,
        profondita_cm decimal(10,2) NULL,
        peso_kg decimal(10,2) NULL,
        veicolo_minimo varchar(50) NULL,
        stato_utilizzabilita varchar(50) NOT NULL DEFAULT 'disponibile',
        note_logistiche text,
        correlati longtext,
        note text,
        data_creazione datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    // Noleggi
    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $sql_noleggi = "CREATE TABLE $table_noleggi (
        id varchar(20) NOT NULL,
        cliente_id mediumint(9),
        data_richiesta datetime,
        data_inizio datetime,
        data_fine datetime,
        data_ritiro datetime,
        data_riconsegna datetime,
        stato enum('preventivo','bozza','attivo','chiuso','ritardo') DEFAULT 'bozza',
        metodo_pagamento varchar(255),
        operatore_richiesta varchar(50),
        operatore_verifica varchar(50),
        articoli longtext,
        rientro_report longtext,
        rientro_data datetime,
        totale_calcolato decimal(10,2),
        sconto_globale decimal(10,2) DEFAULT 0,
        note text,
        luogo_destinazione varchar(255),
        trasporto_mezzo varchar(255),
        cauzione varchar(255),
        causale_trasporto varchar(255),
        km_distanza decimal(10,2) NULL,
        lat_destinazione decimal(10,6) NULL,
        lng_destinazione decimal(10,6) NULL,
        snapshot_servizi_json longtext,
        snapshot_destinazione_json longtext,
        consenso_privacy tinyint(1) DEFAULT 0,
        consenso_condizioni tinyint(1) DEFAULT 0,
        firma_url varchar(255),
        firma_base64 longtext,
        firma_data datetime,
        op_preparazione_documento varchar(50),
        op_preparazione_materiale varchar(50),
        op_consegna_materiale varchar(50),
        op_rientro_materiale varchar(50),
        PRIMARY KEY (id)
    ) $charset;";

    // Ticket
    $table_ticket = $wpdb->prefix . 'bs_ticket';
    $sql_ticket = "CREATE TABLE $table_ticket (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        noleggio_id varchar(20) DEFAULT NULL,
        articolo_id mediumint(9) NOT NULL,
        qty int NOT NULL DEFAULT 1,
        tipo enum('mancante','non_rientrato','quantita_inferiore','danneggiato','problematico_utilizzabile') NOT NULL,
        note text,
        foto longtext,
        stato enum('aperto','chiuso') DEFAULT 'aperto',
        operatore varchar(50),
        origine varchar(20) DEFAULT 'manuale',
        creato_il datetime DEFAULT CURRENT_TIMESTAMP,
        chiuso_il datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY noleggio_id (noleggio_id),
        KEY articolo_id (articolo_id),
        KEY stato (stato)
    ) $charset;";

    // Profili sconti
    $table_profili_sconti = $wpdb->prefix . 'bs_profili_sconti';
    $sql_profili_sconti = "CREATE TABLE $table_profili_sconti (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        nome varchar(100) NOT NULL UNIQUE,
        sconto_standard decimal(5,2) DEFAULT 0,
        sconto_fidato decimal(5,2) DEFAULT 0,
        sconto_premium decimal(5,2) DEFAULT 0,
        sconto_service decimal(5,2) DEFAULT 0,
        sconto_collaboratori decimal(5,2) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

// KIT (bundle)
    $table_kits = $wpdb->prefix . 'bsn_kits';
    $sql_kits = "CREATE TABLE $table_kits (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        nome_kit varchar(150) NOT NULL,
        articolo_kit_id mediumint(9) NOT NULL,
        note text,
        data_creazione datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY articolo_kit_id (articolo_kit_id)
    ) $charset;";

    $table_kit_componenti = $wpdb->prefix . 'bsn_kit_componenti';
    $sql_kit_componenti = "CREATE TABLE $table_kit_componenti (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        kit_id mediumint(9) NOT NULL,
        articolo_id mediumint(9) NOT NULL,
        ruolo varchar(50) NOT NULL,
        qty int NOT NULL DEFAULT 1,
        is_default tinyint(1) NOT NULL DEFAULT 1,
        is_selectable tinyint(1) NOT NULL DEFAULT 1,
        gruppo_equivalenza varchar(80) NOT NULL,
        PRIMARY KEY (id),
        KEY kit_id (kit_id),
        KEY articolo_id (articolo_id),
        KEY gruppo_equivalenza (gruppo_equivalenza)
    ) $charset;";

    $table_noleggio_kits = $wpdb->prefix . 'bsn_noleggio_kits';
    $sql_noleggio_kits = "CREATE TABLE $table_noleggio_kits (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        noleggio_id varchar(20) NOT NULL,
        kit_id mediumint(9) NOT NULL,
        kit_label varchar(150),
        PRIMARY KEY (id),
        KEY noleggio_id (noleggio_id),
        KEY kit_id (kit_id)
    ) $charset;";

    $table_noleggio_kit_componenti = $wpdb->prefix . 'bsn_noleggio_kit_componenti';
    $sql_noleggio_kit_componenti = "CREATE TABLE $table_noleggio_kit_componenti (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        noleggio_kit_id bigint(20) unsigned NOT NULL,
        ruolo varchar(50) NOT NULL,
        gruppo_equivalenza varchar(80) NOT NULL,
        articolo_id_scelto mediumint(9) NOT NULL,
        qty int NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY noleggio_kit_id (noleggio_kit_id),
        KEY articolo_id_scelto (articolo_id_scelto),
        KEY gruppo_equivalenza (gruppo_equivalenza)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_clienti);
    dbDelta($sql_articoli);
    dbDelta($sql_noleggi);
    dbDelta($sql_ticket);
    dbDelta($sql_profili_sconti);
    dbDelta($sql_kits);
    dbDelta($sql_kit_componenti);
    dbDelta($sql_noleggio_kits);
    dbDelta($sql_noleggio_kit_componenti);
}

add_action('init', function() {
    global $wpdb;
    $table_profili_sconti = $wpdb->prefix . 'bs_profili_sconti';
    $exists = $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $table_profili_sconti) );
    if ( $exists !== $table_profili_sconti ) {
        bsn_install_tables();
    }
    bsn_ensure_noleggi_columns();
    bsn_ensure_articoli_columns();
    bsn_ensure_ticket_table();
    bsn_ensure_preventivo_page();
    bsn_ensure_quote_cart_page();
    bsn_ensure_quote_submitted_page();
});

// Attiva creazione tabelle all'attivazione plugin
register_activation_hook(__FILE__, 'bsn_install_tables');
register_activation_hook(__FILE__, 'bsn_ensure_preventivo_page');
register_activation_hook(__FILE__, 'bsn_ensure_quote_cart_page');
register_activation_hook(__FILE__, 'bsn_ensure_quote_submitted_page');


// === STEP A2: Ruolo e capability per l'app noleggi ===
function bsn_add_roles_and_caps() {
    $cap = 'bsn_manage_noleggi';

    // Aggiunge la capability ad administrator
    if ( $admin = get_role( 'administrator' ) ) {
        $admin->add_cap( $cap );
    }

    // Aggiunge la capability anche all'editor (se usi gli editor sull'app)
    if ( $editor = get_role( 'editor' ) ) {
        $editor->add_cap( $cap );
    }

    // Crea ruolo dedicato Operatore Noleggi, se non esiste
    if ( ! get_role( 'bsn_operatore' ) ) {
        add_role(
            'bsn_operatore',
            'Operatore Noleggi',
            array(
                'read'             => true,
                $cap               => true,
                // niente manage_options, niente edit_posts, ecc.
            )
        );
    }
}
register_activation_hook( __FILE__, 'bsn_add_roles_and_caps' );

function bsn_ensure_ticket_table() {
    global $wpdb;
    $table_ticket = $wpdb->prefix . 'bs_ticket';

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_ticket ) );
    if ( $exists === $table_ticket ) {
        $col_origine = $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM $table_ticket LIKE %s", 'origine' )
        );
        if ( ! $col_origine ) {
            $wpdb->query( "ALTER TABLE $table_ticket ADD origine varchar(20) DEFAULT 'manuale'" );
        }
        $col_foto = $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM $table_ticket LIKE %s", 'foto' )
        );
        if ( ! $col_foto ) {
            $wpdb->query( "ALTER TABLE $table_ticket ADD foto longtext" );
        }
        return;
    }

    $charset = $wpdb->get_charset_collate();
    $sql_ticket = "CREATE TABLE $table_ticket (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        noleggio_id varchar(20) DEFAULT NULL,
        articolo_id mediumint(9) NOT NULL,
        qty int NOT NULL DEFAULT 1,
        tipo enum('mancante','non_rientrato','quantita_inferiore','danneggiato','problematico_utilizzabile') NOT NULL,
        note text,
        foto longtext,
        stato enum('aperto','chiuso') DEFAULT 'aperto',
        operatore varchar(50),
        origine varchar(20) DEFAULT 'manuale',
        creato_il datetime DEFAULT CURRENT_TIMESTAMP,
        chiuso_il datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY noleggio_id (noleggio_id),
        KEY articolo_id (articolo_id),
        KEY stato (stato)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_ticket );
}

function bsn_ensure_noleggi_columns() {
    global $wpdb;
    $table_noleggi = $wpdb->prefix . 'bs_noleggi';

    $col_stato = $wpdb->get_row( "SHOW COLUMNS FROM $table_noleggi LIKE 'stato'" );
    if ( $col_stato && isset( $col_stato->Type ) && strpos( $col_stato->Type, 'preventivo' ) === false ) {
        $wpdb->query( "ALTER TABLE $table_noleggi MODIFY stato enum('preventivo','bozza','attivo','chiuso','ritardo') DEFAULT 'bozza'" );
    }

    $col_report = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'rientro_report' )
    );
    if ( ! $col_report ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD rientro_report longtext" );
    }

    $col_data = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'rientro_data' )
    );
    if ( ! $col_data ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD rientro_data datetime" );
    }

    $col_consenso_privacy = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'consenso_privacy' )
    );
    if ( ! $col_consenso_privacy ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD consenso_privacy tinyint(1) DEFAULT 0" );
    }

    $col_consenso_condizioni = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'consenso_condizioni' )
    );
    if ( ! $col_consenso_condizioni ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD consenso_condizioni tinyint(1) DEFAULT 0" );
    }

    $col_firma_url = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'firma_url' )
    );
    if ( ! $col_firma_url ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD firma_url varchar(255)" );
    }

    $col_firma_base64 = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'firma_base64' )
    );
    if ( ! $col_firma_base64 ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD firma_base64 longtext" );
    }

    $col_firma_data = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'firma_data' )
    );
    if ( ! $col_firma_data ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD firma_data datetime" );
    }

    $col_op_doc = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'op_preparazione_documento' )
    );
    if ( ! $col_op_doc ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD op_preparazione_documento varchar(50)" );
    }

    $col_op_mat = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'op_preparazione_materiale' )
    );
    if ( ! $col_op_mat ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD op_preparazione_materiale varchar(50)" );
    }

    $col_op_consegna = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'op_consegna_materiale' )
    );
    if ( ! $col_op_consegna ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD op_consegna_materiale varchar(50)" );
    }

    $col_op_rientro = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'op_rientro_materiale' )
    );
    if ( ! $col_op_rientro ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD op_rientro_materiale varchar(50)" );
    }

    $col_metodo_pagamento = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'metodo_pagamento' )
    );
    if ( ! $col_metodo_pagamento ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD metodo_pagamento varchar(255)" );
    }

    $col_data_ritiro = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'data_ritiro' )
    );
    if ( ! $col_data_ritiro ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD data_ritiro datetime NULL AFTER data_fine" );
    }

    $col_data_riconsegna = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'data_riconsegna' )
    );
    if ( ! $col_data_riconsegna ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD data_riconsegna datetime NULL AFTER data_ritiro" );
    }

    $col_origine = $wpdb->get_var(
        $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", 'origine' )
    );
    if ( ! $col_origine ) {
        $wpdb->query( "ALTER TABLE $table_noleggi ADD origine varchar(20) DEFAULT 'manuale'" );
    }

    $snapshot_columns = [
        'snapshot_cliente_json' => 'longtext',
        'snapshot_articoli_json' => 'longtext',
        'snapshot_prezzi_json' => 'longtext',
        'snapshot_servizi_json' => 'longtext',
        'snapshot_destinazione_json' => 'longtext',
    ];

    foreach ( $snapshot_columns as $column => $sql_type ) {
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", $column )
        );
        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE $table_noleggi ADD $column $sql_type" );
        }
    }

    $extra_columns = array(
        'km_distanza'                  => 'decimal(10,2) NULL',
        'lat_destinazione'             => 'decimal(10,6) NULL',
        'lng_destinazione'             => 'decimal(10,6) NULL',
        // Incaricato al ritiro: cliente diverso dall'intestatario che ritira fisicamente il materiale
        'incaricato_ritiro_cliente_id' => 'bigint(20) unsigned NULL',
    );

    foreach ( $extra_columns as $column => $sql_type ) {
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM $table_noleggi LIKE %s", $column )
        );
        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE $table_noleggi ADD $column $sql_type" );
        }
    }
}

// ============================================================
// INCARICATO AL RITIRO — Helper
// ============================================================

// Restituisce i dati del cliente incaricato al ritiro, o null se coincide con l'intestatario
function bsn_get_incaricato_ritiro_data( $noleggio ) {
    global $wpdb;

    $id = isset( $noleggio['incaricato_ritiro_cliente_id'] )
        ? absint( $noleggio['incaricato_ritiro_cliente_id'] )
        : 0;

    if ( $id < 1 ) {
        return null;
    }

    // Verifica che l'incaricato non sia lo stesso del cliente intestatario
    if ( $id === absint( $noleggio['cliente_id'] ?? 0 ) ) {
        return null;
    }

    $table = $wpdb->prefix . 'bs_clienti';
    $row   = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table WHERE id = %d LIMIT 1", $id ),
        ARRAY_A
    );

    return $row ?: null;
}

function bsn_noleggi_table_has_column( $column ) {
    global $wpdb;

    static $cache = [];
    $column = sanitize_key( (string) $column );
    if ( $column === '' ) {
        return false;
    }

    if ( array_key_exists( $column, $cache ) ) {
        return $cache[ $column ];
    }

    $table = $wpdb->prefix . 'bs_noleggi';
    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) );
    $cache[ $column ] = ! empty( $exists );

    return $cache[ $column ];
}

function bsn_filter_noleggi_table_data( $data ) {
    if ( ! is_array( $data ) || empty( $data ) ) {
        return [];
    }

    $allowed = [];
    foreach ( array_keys( $data ) as $column ) {
        if ( bsn_noleggi_table_has_column( $column ) ) {
            $allowed[ $column ] = true;
        }
    }

    return array_intersect_key( $data, $allowed );
}

function bsn_get_noleggio_field( $noleggio, $field ) {
    if ( is_array( $noleggio ) ) {
        return isset( $noleggio[ $field ] ) ? $noleggio[ $field ] : null;
    }
    if ( is_object( $noleggio ) ) {
        return isset( $noleggio->{$field} ) ? $noleggio->{$field} : null;
    }
    return null;
}

function bsn_get_noleggio_snapshot_json( $noleggio, $field ) {
    $raw = bsn_get_noleggio_field( $noleggio, $field );
    if ( empty( $raw ) || ! is_string( $raw ) ) {
        return null;
    }

    $decoded = json_decode( $raw, true );
    return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $decoded : null;
}

function bsn_is_frontend_quote_noleggio( $noleggio ) {
    return sanitize_key( (string) bsn_get_noleggio_field( $noleggio, 'origine' ) ) === 'frontend';
}

function bsn_get_noleggio_origine_label( $origine ) {
    $labels = array(
        'frontend' => 'Sito pubblico',
        'cerebro'  => 'Cerebro',
    );

    $key = sanitize_key( (string) $origine );
    return $labels[ $key ] ?? 'Gestionale interno';
}

function bsn_get_noleggio_pricing_source( $noleggio ) {
    if ( ! bsn_is_frontend_quote_noleggio( $noleggio ) ) {
        return 'admin_current';
    }

    $snapshot_prezzi = bsn_get_noleggio_snapshot_json( $noleggio, 'snapshot_prezzi_json' );
    $pricing_source = sanitize_key(
        (string) (
            is_array( $snapshot_prezzi )
                ? ( $snapshot_prezzi['pricing_source'] ?? '' )
                : ''
        )
    );

    if ( $pricing_source === 'admin_current' ) {
        return 'admin_current';
    }
    if ( $pricing_source === 'frontend_snapshot' ) {
        return 'frontend_snapshot';
    }

    $totale_snapshot = is_array( $snapshot_prezzi ) && isset( $snapshot_prezzi['totale_stimato'] )
        ? round( (float) $snapshot_prezzi['totale_stimato'], 2 )
        : null;
    $totale_corrente = round( (float) bsn_get_noleggio_field( $noleggio, 'totale_calcolato' ), 2 );
    $sconto_globale = round( (float) bsn_get_noleggio_field( $noleggio, 'sconto_globale' ), 2 );

    if ( $totale_snapshot !== null && ( abs( $totale_corrente - $totale_snapshot ) > 0.01 || abs( $sconto_globale ) > 0.01 ) ) {
        return 'admin_current';
    }

    return 'frontend_snapshot';
}

function bsn_should_use_frontend_snapshot_pricing( $noleggio ) {
    return bsn_get_noleggio_pricing_source( $noleggio ) === 'frontend_snapshot';
}

function bsn_get_frontend_quote_snapshot_after_admin_update( $noleggio, $totale_calcolato, $sconto_globale = 0.0 ) {
    $snapshot_prezzi = bsn_get_noleggio_snapshot_json( $noleggio, 'snapshot_prezzi_json' );
    if ( ! is_array( $snapshot_prezzi ) ) {
        $snapshot_prezzi = array();
    }

    if ( ! array_key_exists( 'frontend_initial_totale_stimato', $snapshot_prezzi ) && array_key_exists( 'totale_stimato', $snapshot_prezzi ) ) {
        $snapshot_prezzi['frontend_initial_totale_stimato'] = round( (float) $snapshot_prezzi['totale_stimato'], 2 );
    }

    if ( ! array_key_exists( 'frontend_initial_pricing_note', $snapshot_prezzi ) && ! empty( $snapshot_prezzi['pricing_note'] ) ) {
        $snapshot_prezzi['frontend_initial_pricing_note'] = (string) $snapshot_prezzi['pricing_note'];
    }

    $snapshot_prezzi['pricing_source'] = 'admin_current';
    $snapshot_prezzi['admin_last_totale_calcolato'] = round( (float) $totale_calcolato, 2 );
    $snapshot_prezzi['admin_last_sconto_globale'] = round( (float) $sconto_globale, 2 );
    $snapshot_prezzi['admin_last_updated_at'] = current_time( 'mysql' );
    $snapshot_prezzi['admin_last_updated_by'] = get_current_user_id();
    $snapshot_prezzi['pricing_note'] = 'Importi aggiornati dal gestionale Black Star Service.';

    return $snapshot_prezzi;
}

function bsn_normalize_article_row_array( $article_row ) {
    if ( is_array( $article_row ) ) {
        return $article_row;
    }

    if ( is_object( $article_row ) ) {
        return get_object_vars( $article_row );
    }

    return array();
}

function bsn_get_article_quantity_discount_config_for_line( $article_row ) {
    $article_row = bsn_normalize_article_row_array( $article_row );

    if (
        function_exists( 'bsn_get_quantity_discount_config_from_row' )
        && (
            array_key_exists( 'qty_discount_enabled', $article_row )
            || array_key_exists( 'qty_discount_start_qty', $article_row )
            || array_key_exists( 'qty_discount_max_qty', $article_row )
            || array_key_exists( 'qty_discount_max_pct', $article_row )
        )
    ) {
        return bsn_get_quantity_discount_config_from_row( $article_row );
    }

    if ( function_exists( 'bsn_get_quantity_discount_config' ) && ! empty( $article_row['id'] ) ) {
        return bsn_get_quantity_discount_config( (int) $article_row['id'] );
    }

    return function_exists( 'bsn_normalize_quantity_discount_config' )
        ? bsn_normalize_quantity_discount_config( array() )
        : array( 'enabled' => false );
}

function bsn_calculate_noleggio_article_line_price( $art, $article_row, $categoria_cliente, $giorni, $use_snapshot = true ) {
    $art = is_array( $art ) ? $art : array();
    $article_row = bsn_normalize_article_row_array( $article_row );
    $qty = max( 1, intval( $art['qty'] ?? 1 ) );
    $giorni = max( 1, intval( $giorni ) );

    $prezzo_custom = array_key_exists( 'prezzo', $art ) && $art['prezzo'] !== null && $art['prezzo'] !== ''
        ? (float) $art['prezzo']
        : null;
    $prezzo_base = (float) ( $article_row['prezzo_giorno'] ?? 0 );
    $prezzo = $prezzo_custom !== null ? $prezzo_custom : $prezzo_base;
    $noleggio_scalare = ! empty( $article_row['noleggio_scalare'] );
    $fattore = $noleggio_scalare ? sqrt( $giorni ) : 1;

    $has_snapshot_unit_price = $use_snapshot
        && array_key_exists( 'public_unit_price_net', $art )
        && $art['public_unit_price_net'] !== null
        && $art['public_unit_price_net'] !== '';
    $has_snapshot_line_total = $use_snapshot
        && array_key_exists( 'public_line_total', $art )
        && $art['public_line_total'] !== null
        && $art['public_line_total'] !== '';

    if ( $has_snapshot_unit_price || $has_snapshot_line_total ) {
        $unit_after_qty_discount = $has_snapshot_unit_price
            ? round( (float) $art['public_unit_price_net'], 4 )
            : ( $qty > 0 ? round( (float) $art['public_line_total'] / $qty, 4 ) : 0.0 );
        $unit_before_qty_discount = array_key_exists( 'unit_price_before_qty_discount', $art ) && $art['unit_price_before_qty_discount'] !== ''
            ? round( (float) $art['unit_price_before_qty_discount'], 4 )
            : $unit_after_qty_discount;
        $qty_discount_pct = array_key_exists( 'qty_discount_applied_pct', $art ) && $art['qty_discount_applied_pct'] !== ''
            ? max( 0.0, min( 100.0, (float) $art['qty_discount_applied_pct'] ) )
            : ( $unit_before_qty_discount > 0 ? max( 0.0, min( 100.0, ( 1 - ( $unit_after_qty_discount / $unit_before_qty_discount ) ) * 100 ) ) : 0.0 );
        $category_discount_pct = $prezzo > 0 && $unit_before_qty_discount >= 0 && $unit_before_qty_discount <= $prezzo
            ? max( 0.0, min( 100.0, ( 1 - ( $unit_before_qty_discount / $prezzo ) ) * 100 ) )
            : 0.0;
        $line_total = $has_snapshot_line_total
            ? round( (float) $art['public_line_total'], 2 )
            : round( $unit_after_qty_discount * $qty * $fattore, 2 );

        return array(
            'prezzo' => $prezzo,
            'qty' => $qty,
            'fattore' => $fattore,
            'noleggio_scalare' => $noleggio_scalare ? 1 : 0,
            'category_discount_pct' => round( $category_discount_pct, 4 ),
            'qty_discount_pct' => round( $qty_discount_pct, 4 ),
            'unit_price_before_qty_discount' => round( $unit_before_qty_discount, 4 ),
            'unit_price_after_qty_discount' => round( $unit_after_qty_discount, 4 ),
            'line_total' => $line_total,
            'used_snapshot' => true,
        );
    }

    $category_discount_pct = function_exists( 'bsn_get_articolo_customer_discount_percent' )
        ? bsn_get_articolo_customer_discount_percent( $article_row, $categoria_cliente )
        : 0.0;
    $category_discount_pct = max( 0.0, min( 100.0, (float) $category_discount_pct ) );
    $unit_before_qty_discount = $prezzo * ( 1 - ( $category_discount_pct / 100 ) );
    $qty_discount = function_exists( 'bsn_calculate_linear_quantity_discount' )
        ? bsn_calculate_linear_quantity_discount(
            $unit_before_qty_discount,
            $qty,
            bsn_get_article_quantity_discount_config_for_line( $article_row )
        )
        : array(
            'unit_price' => $unit_before_qty_discount,
            'discount_pct' => 0.0,
        );
    $unit_after_qty_discount = (float) ( $qty_discount['unit_price'] ?? $unit_before_qty_discount );
    $qty_discount_pct = (float) ( $qty_discount['discount_pct'] ?? 0 );

    return array(
        'prezzo' => $prezzo,
        'qty' => $qty,
        'fattore' => $fattore,
        'noleggio_scalare' => $noleggio_scalare ? 1 : 0,
        'category_discount_pct' => round( $category_discount_pct, 4 ),
        'qty_discount_pct' => round( $qty_discount_pct, 4 ),
        'unit_price_before_qty_discount' => round( $unit_before_qty_discount, 4 ),
        'unit_price_after_qty_discount' => round( $unit_after_qty_discount, 4 ),
        'line_total' => round( $unit_after_qty_discount * $qty * $fattore, 2 ),
        'used_snapshot' => false,
    );
}

function bsn_get_document_public_product_data( $art, $articolo_db ) {
    $product_id = 0;
    if ( is_array( $art ) && ! empty( $art['public_product_id'] ) ) {
        $product_id = absint( $art['public_product_id'] );
    }
    if ( $product_id < 1 && is_array( $articolo_db ) && ! empty( $articolo_db['prodotto_pubblico_id'] ) ) {
        $product_id = absint( $articolo_db['prodotto_pubblico_id'] );
    }

    $data = array(
        'id'        => 0,
        'title'     => '',
        'permalink' => '',
        'image_url' => '',
    );

    if ( $product_id < 1 ) {
        return $data;
    }

    $post = get_post( $product_id );
    if ( ! $post || $post->post_type !== 'bs_prodotto' ) {
        return $data;
    }

    $data['id'] = $product_id;
    $data['title'] = trim( (string) get_the_title( $post ) );
    $data['permalink'] = (string) get_permalink( $post );

    if ( function_exists( 'bsn_get_public_product_gallery_urls' ) ) {
        $gallery_urls = bsn_get_public_product_gallery_urls( $product_id );
        if ( ! empty( $gallery_urls[0] ) ) {
            $data['image_url'] = (string) $gallery_urls[0];
        }
    }

    if ( $data['image_url'] === '' ) {
        $thumbnail = get_the_post_thumbnail_url( $product_id, 'large' );
        if ( $thumbnail ) {
            $data['image_url'] = (string) $thumbnail;
        }
    }

    return $data;
}

function bsn_prepare_noleggio_document_item_row( $art, $articolo_db, $categoria, $giorni, $fattore_giorni_scalare, $use_frontend_snapshot = false ) {
    if ( ! is_array( $art ) ) {
        return null;
    }

    if ( ! is_array( $articolo_db ) ) {
        $articolo_db = array();
    }

    $qty = max( 1, intval( $art['qty'] ?? 1 ) );
    $articolo_id = isset( $art['id'] ) ? (int) $art['id'] : 0;
    $product_id = isset( $art['public_product_id'] ) ? (int) $art['public_product_id'] : 0;
    $service_component_code = trim( (string) ( $art['service_component_code'] ?? '' ) );
    if ( $service_component_code === '' && ! empty( $articolo_db['codice'] ) ) {
        $service_component_code = trim( (string) $articolo_db['codice'] );
    }

    $is_service_internal = ! empty( $art['service_internal'] ) || bsn_is_internal_service_article_code( $service_component_code );
    $service_component_label = trim( (string) ( $art['service_component_label'] ?? '' ) );
    if ( $is_service_internal ) {
        $service_component_label = bsn_get_internal_service_article_display_label(
            $service_component_code,
            $service_component_label !== '' ? $service_component_label : (string) ( $articolo_db['nome'] ?? '' )
        );
    }

    $prezzo_custom = array_key_exists( 'prezzo', $art ) && $art['prezzo'] !== null && $art['prezzo'] !== ''
        ? (float) $art['prezzo']
        : null;

    $prezzo_base = (float) ( $articolo_db['prezzo_giorno'] ?? 0 );
    $prezzo_standard = $prezzo_custom !== null ? $prezzo_custom : $prezzo_base;
    $valore_bene = (float) ( $articolo_db['valore_bene'] ?? 0 );
    $noleggio_scalare = (int) ( $articolo_db['noleggio_scalare'] ?? 0 );

    $public_title = trim( (string) ( $art['public_product_title'] ?? '' ) );
    $public_code = trim( (string) ( $art['public_product_code'] ?? '' ) );
    $public_permalink = trim( (string) ( $art['public_product_permalink'] ?? '' ) );
    $public_image_url = trim( (string) ( $art['public_product_image_url'] ?? '' ) );
    $dimension_summary = trim( (string) ( $art['dimension_summary'] ?? '' ) );
    $selected_preset_label = trim( (string) ( $art['selected_preset_label'] ?? '' ) );
    $dimension_note = trim( (string) ( $art['dimension_note'] ?? '' ) );
    $modules_x = max( 0, intval( $art['modules_x'] ?? 0 ) );
    $modules_y = max( 0, intval( $art['modules_y'] ?? 0 ) );
    $qty_discount_applied_pct = max( 0.0, (float) ( $art['qty_discount_applied_pct'] ?? 0 ) );
    $product_meta_lines = array();
    if ( $dimension_summary !== '' ) {
        $product_meta_lines[] = $dimension_summary;
    }
    if ( $modules_x > 0 && $modules_y > 0 ) {
        $product_meta_lines[] = $modules_x . 'x' . $modules_y . ' moduli';
    }
    if ( $selected_preset_label !== '' ) {
        $product_meta_lines[] = 'Preset: ' . $selected_preset_label;
    }
    if ( $dimension_note !== '' ) {
        $product_meta_lines[] = 'Nota: ' . $dimension_note;
    }
    if ( $qty_discount_applied_pct > 0.0001 ) {
        $product_meta_lines[] = 'Sconto quantita: -' . number_format( $qty_discount_applied_pct, 2, ',', '.' ) . '%';
    }
    $linked_public_product = $is_service_internal
        ? array( 'id' => 0, 'title' => '', 'permalink' => '', 'image_url' => '' )
        : bsn_get_document_public_product_data( $art, $articolo_db );
    if ( ! empty( $linked_public_product['id'] ) ) {
        $product_id = (int) $linked_public_product['id'];
    }

    $codice = $is_service_internal
        ? ( $service_component_code !== '' ? $service_component_code : 'SERV' )
        : ( $public_code !== '' ? $public_code : (string) ( $articolo_db['codice'] ?? '' ) );
    if ( $codice === '' && $articolo_id > 0 ) {
        $codice = 'ART-' . $articolo_id;
    }

    $nome_prodotto = $is_service_internal ? $service_component_label : (string) ( $linked_public_product['title'] ?? '' );
    if ( $nome_prodotto === '' ) {
        $nome_prodotto = $public_title;
    }
    if ( $nome_prodotto === '' ) {
        $nome_prodotto = trim(
            (string) (
                $articolo_db['nome']
                ?? $articolo_db['external_product_name']
                ?? ( $product_id > 0 ? 'Prodotto #' . $product_id : '' )
            )
        );
    }

    if ( $nome_prodotto === '' && empty( $articolo_db ) ) {
        return null;
    }

    $external_url = $is_service_internal
        ? ''
        : ( (string) ( $linked_public_product['permalink'] ?? '' ) !== '' ? (string) $linked_public_product['permalink'] : $public_permalink );
    $external_image = $is_service_internal
        ? ''
        : ( (string) ( $linked_public_product['image_url'] ?? '' ) !== '' ? (string) $linked_public_product['image_url'] : $public_image_url );

    $correlati_testo = '-';
    if ( ! $is_service_internal && ! empty( $articolo_db['correlati'] ) ) {
        $correlati = json_decode( $articolo_db['correlati'], true );
        if ( is_array( $correlati ) && ! empty( $correlati ) ) {
            $corr_labels = array_map(
                function ( $corr ) use ( $qty ) {
                    $qty_corr = max( 1, (int) ( $corr['qty'] ?? 1 ) ) * $qty;
                    return $qty_corr . 'x ' . ( $corr['nome'] ?? '' );
                },
                $correlati
            );
            $correlati_testo = implode( ', ', array_filter( $corr_labels ) );
        }
    }

    if ( empty( $articolo_db ) ) {
        return null;
    }

    $line_price = bsn_calculate_noleggio_article_line_price( $art, $articolo_db, $categoria, $giorni, $use_frontend_snapshot );
    $prezzo = (float) ( $line_price['prezzo'] ?? $prezzo_standard );
    $sconto = (float) ( $line_price['category_discount_pct'] ?? 0 );
    $qty_discount_applied_pct = max( $qty_discount_applied_pct, (float) ( $line_price['qty_discount_pct'] ?? 0 ) );
    $subtotale_riga = (float) ( $line_price['line_total'] ?? 0 );
    $noleggio_scalare = ! empty( $line_price['noleggio_scalare'] ) ? 1 : 0;

    if ( $qty_discount_applied_pct > 0.0001 ) {
        $product_meta_lines[] = 'Sconto quantita: -' . number_format( $qty_discount_applied_pct, 2, ',', '.' ) . '%';
    }

    if ( $noleggio_scalare === 1 ) {
        $fattore_display = '√' . $giorni . ' (' . number_format( $fattore_giorni_scalare, 3, ',', '.' ) . ')';
    } elseif ( $giorni > 1 ) {
        $fattore_display = 'Lineare';
    } else {
        $fattore_display = '1 giorno';
    }

    $sconto_display = '-' . number_format( $sconto, 2, ',', '.' ) . '%';
    if ( $qty_discount_applied_pct > 0.0001 ) {
        $sconto_display = 'Cat -' . number_format( $sconto, 2, ',', '.' ) . '%<br>Qty -' . number_format( $qty_discount_applied_pct, 2, ',', '.' ) . '%';
    }

/*
    $has_snapshot_unit_price = $use_frontend_snapshot
        && array_key_exists( 'public_unit_price_net', $art )
        && $art['public_unit_price_net'] !== null
        && $art['public_unit_price_net'] !== '';

    $has_snapshot_line_total = $use_frontend_snapshot
        && array_key_exists( 'public_line_total', $art )
        && $art['public_line_total'] !== null
        && $art['public_line_total'] !== '';

    if ( $has_snapshot_unit_price ) {
        $unit_price_net = round( (float) $art['public_unit_price_net'], 2 );
        $subtotale_riga = $has_snapshot_line_total
            ? round( (float) $art['public_line_total'], 2 )
            : round( $unit_price_net * $qty, 2 );

        $prezzo = $prezzo_standard > 0 ? $prezzo_standard : $unit_price_net;
        if ( $prezzo_standard > 0 && $unit_price_net >= 0 && $unit_price_net <= $prezzo_standard ) {
            $sconto = round( max( 0, min( 100, ( 1 - ( $unit_price_net / $prezzo_standard ) ) * 100 ) ), 2 );
        } else {
            $sconto = 0.0;
        }

        $lineare_atteso = round( $unit_price_net * $qty, 2 );
        if ( $giorni > 1 && ( $noleggio_scalare === 1 || abs( $subtotale_riga - $lineare_atteso ) > 0.01 ) ) {
            $fattore_display = '√' . $giorni;
        } elseif ( $giorni > 1 ) {
            $fattore_display = 'Lineare';
        } else {
            $fattore_display = '1 giorno';
        }
    } else {
        if ( empty( $articolo_db ) ) {
            return null;
        }

        $prezzo = $prezzo_standard;
        $sconto = bsn_get_articolo_customer_discount_percent( $articolo_db, $categoria );
        $prezzo_netto = $prezzo * ( 1 - ( $sconto / 100 ) );

        if ( $noleggio_scalare === 1 ) {
            $fattore_display = '√' . $giorni;
            $subtotale_riga = $prezzo_netto * $qty * $fattore_giorni_scalare;
        } else {
            $fattore_display = 'Lineare';
            $subtotale_riga = $prezzo_netto * $qty;
        }
    }

*/

    if ( $is_service_internal ) {
        $fattore_display = 'Servizio';
        $sconto = 0.0;
        $sconto_display = '-0,00%';
        $correlati_testo = '-';
        $valore_bene = 0.0;
    }

    return array(
        'codice' => $codice !== '' ? $codice : 'N/D',
        'nome_prodotto' => $nome_prodotto !== '' ? $nome_prodotto : 'Articolo #' . max( 1, $articolo_id ),
        'external_url' => $external_url,
        'external_image' => $external_image,
        'correlati_testo' => $correlati_testo !== '' ? $correlati_testo : '-',
        'prezzo' => round( (float) $prezzo, 2 ),
        'qty' => $qty,
        'fattore_display' => $fattore_display,
        'sconto' => round( (float) $sconto, 2 ),
        'sconto_display' => $sconto_display,
        'subtotale_riga' => round( (float) $subtotale_riga, 2 ),
        'valore_bene' => round( (float) $valore_bene, 2 ),
        'is_service_internal' => $is_service_internal ? 1 : 0,
        'product_meta_lines' => array_values( array_unique( array_filter( $product_meta_lines ) ) ),
    );
}

function bsn_get_noleggio_document_totals_context( $noleggio, $subtotale, $regime ) {
    $subtotale = round( (float) $subtotale, 2 );
    $totale_finale = round( (float) bsn_get_noleggio_field( $noleggio, 'totale_calcolato' ), 2 );
    $is_frontend_quote = bsn_is_frontend_quote_noleggio( $noleggio );
    $snapshot_prezzi = bsn_get_noleggio_snapshot_json( $noleggio, 'snapshot_prezzi_json' );
    $pricing_source = bsn_get_noleggio_pricing_source( $noleggio );

    if ( $is_frontend_quote && $pricing_source === 'frontend_snapshot' ) {
        if ( is_array( $snapshot_prezzi ) && isset( $snapshot_prezzi['totale_stimato'] ) ) {
            $totale_finale = round( (float) $snapshot_prezzi['totale_stimato'], 2 );
        }

        return array(
            'subtotale' => $subtotale,
            'subtotale_label' => 'Totale prodotti + IVA esclusa',
            'importo_regime' => 0.0,
            'show_regime' => false,
            'totale_noleggio' => $subtotale,
            'totale_label' => 'Totale stimato + IVA',
            'totale_finale' => $totale_finale,
            'sconto_globale' => round( $totale_finale - $subtotale, 2 ),
            'pricing_note' => is_array( $snapshot_prezzi ) && ! empty( $snapshot_prezzi['pricing_note'] )
                ? (string) $snapshot_prezzi['pricing_note']
                : 'Prezzi IVA esclusa salvo diversa indicazione. Eventuali imposte, servizi extra e adeguamenti saranno verificati dall\'operatore.',
        );
    }

    $sconto_globale = round( (float) bsn_get_noleggio_field( $noleggio, 'sconto_globale' ), 2 );
    $totale_noleggio = round( $totale_finale - $sconto_globale, 2 );
    $importo_regime = round( $totale_noleggio - $subtotale, 2 );
    $show_regime = abs( $importo_regime ) > 0.01 || abs( (float) $regime ) > 0.01;

    return array(
        'subtotale' => $subtotale,
        'subtotale_label' => 'Subtotale imponibile',
        'importo_regime' => $importo_regime,
        'show_regime' => $show_regime,
        'totale_noleggio' => $totale_noleggio,
        'totale_label' => $is_frontend_quote ? 'Totale aggiornato + IVA' : 'Totale ivato',
        'totale_finale' => $totale_finale,
        'sconto_globale' => $sconto_globale,
        'pricing_note' => $is_frontend_quote ? 'Prezzi IVA esclusa salvo diversa indicazione. Importi aggiornati dal gestionale Black Star Service.' : 'Prezzi IVA esclusa salvo diversa indicazione.',
    );
}

function bsn_extract_iso_date( $value ) {
    $value = is_string( $value ) ? trim( $value ) : '';
    if ( $value === '' ) {
        return '';
    }
    $timestamp = strtotime( $value );
    if ( ! $timestamp ) {
        return '';
    }
    return date( 'Y-m-d', $timestamp );
}

function bsn_format_date_it( $value ) {
    $iso = bsn_extract_iso_date( $value );
    if ( $iso === '' ) {
        return '';
    }
    return date( 'd-m-Y', strtotime( $iso ) );
}

function bsn_normalize_date_input( $value, $end_of_day = false ) {
    $iso = bsn_extract_iso_date( $value );
    if ( $iso === '' ) {
        return null;
    }
    return $iso . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' );
}

function bsn_is_invalid_date_range( $start, $end ) {
    $start_iso = bsn_extract_iso_date( $start );
    $end_iso = bsn_extract_iso_date( $end );

    if ( $start_iso === '' || $end_iso === '' ) {
        return false;
    }

    return strtotime( $start_iso . ' 00:00:00' ) > strtotime( $end_iso . ' 00:00:00' );
}

function bsn_get_periodo_utilizzo_noleggio( $noleggio ) {
    $data_inizio = bsn_extract_iso_date( bsn_get_noleggio_field( $noleggio, 'data_inizio' ) );
    $data_fine   = bsn_extract_iso_date( bsn_get_noleggio_field( $noleggio, 'data_fine' ) );

    $giorni = 1;
    if ( $data_inizio !== '' && $data_fine !== '' ) {
        $ts_inizio = strtotime( $data_inizio . ' 00:00:00' );
        $ts_fine   = strtotime( $data_fine . ' 00:00:00' );
        if ( $ts_inizio && $ts_fine && $ts_fine > $ts_inizio ) {
            $giorni = max( 1, (int) ceil( ( $ts_fine - $ts_inizio ) / 86400 ) );
        }
    }

    return [
        'inizio'     => $data_inizio,
        'fine'       => $data_fine,
        'inizio_it'  => bsn_format_date_it( $data_inizio ),
        'fine_it'    => bsn_format_date_it( $data_fine ),
        'giorni'     => $giorni,
    ];
}

function bsn_get_periodo_logistico_noleggio( $noleggio ) {
    $data_ritiro = bsn_extract_iso_date( bsn_get_noleggio_field( $noleggio, 'data_ritiro' ) );
    $data_riconsegna = bsn_extract_iso_date( bsn_get_noleggio_field( $noleggio, 'data_riconsegna' ) );
    $data_inizio = bsn_extract_iso_date( bsn_get_noleggio_field( $noleggio, 'data_inizio' ) );
    $data_fine   = bsn_extract_iso_date( bsn_get_noleggio_field( $noleggio, 'data_fine' ) );

    $ritiro_effettivo = $data_ritiro !== '' ? $data_ritiro : $data_inizio;
    $riconsegna_effettiva = $data_riconsegna !== '' ? $data_riconsegna : $data_fine;

    return [
        'ritiro'                  => $data_ritiro,
        'riconsegna'              => $data_riconsegna,
        'ritiro_effettivo'        => $ritiro_effettivo,
        'riconsegna_effettiva'    => $riconsegna_effettiva,
        'ritiro_it'               => bsn_format_date_it( $data_ritiro ),
        'riconsegna_it'           => bsn_format_date_it( $data_riconsegna ),
        'ritiro_effettivo_it'     => bsn_format_date_it( $ritiro_effettivo ),
        'riconsegna_effettiva_it' => bsn_format_date_it( $riconsegna_effettiva ),
    ];
}

function bsn_ensure_articoli_columns() {
    global $wpdb;
    $table_articoli = $wpdb->prefix . 'bs_articoli';

    $columns = [
        'external_product_url'  => "ALTER TABLE $table_articoli ADD external_product_url varchar(255)",
        'external_product_slug' => "ALTER TABLE $table_articoli ADD external_product_slug varchar(200)",
        'external_product_name' => "ALTER TABLE $table_articoli ADD external_product_name varchar(255)",
        'external_image_url'    => "ALTER TABLE $table_articoli ADD external_image_url varchar(255)",
        'external_last_sync'    => "ALTER TABLE $table_articoli ADD external_last_sync datetime",
        'inventory_mode'        => "ALTER TABLE $table_articoli ADD inventory_mode varchar(20) NOT NULL DEFAULT 'seriale'",
        'prodotto_pubblico_id'  => "ALTER TABLE $table_articoli ADD prodotto_pubblico_id bigint(20) unsigned NULL",
        'min_qty'               => "ALTER TABLE $table_articoli ADD min_qty int NOT NULL DEFAULT 1",
        'larghezza_cm'          => "ALTER TABLE $table_articoli ADD larghezza_cm decimal(10,2) NULL",
        'altezza_cm'            => "ALTER TABLE $table_articoli ADD altezza_cm decimal(10,2) NULL",
        'profondita_cm'         => "ALTER TABLE $table_articoli ADD profondita_cm decimal(10,2) NULL",
        'peso_kg'               => "ALTER TABLE $table_articoli ADD peso_kg decimal(10,2) NULL",
        'veicolo_minimo'        => "ALTER TABLE $table_articoli ADD veicolo_minimo varchar(50) NULL",
        'stato_utilizzabilita'  => "ALTER TABLE $table_articoli ADD stato_utilizzabilita varchar(50) NOT NULL DEFAULT 'disponibile'",
        'note_logistiche'       => "ALTER TABLE $table_articoli ADD note_logistiche text",
        'qty_discount_enabled'  => "ALTER TABLE $table_articoli ADD qty_discount_enabled tinyint(1) NOT NULL DEFAULT 0",
        'qty_discount_start_qty'=> "ALTER TABLE $table_articoli ADD qty_discount_start_qty int NULL",
        'qty_discount_max_qty'  => "ALTER TABLE $table_articoli ADD qty_discount_max_qty int NULL",
        'qty_discount_max_pct'  => "ALTER TABLE $table_articoli ADD qty_discount_max_pct decimal(5,2) DEFAULT 0",
        'qr_stampato'           => "ALTER TABLE $table_articoli ADD qr_stampato tinyint(1) NOT NULL DEFAULT 0",
        'qr_stampato_at'        => "ALTER TABLE $table_articoli ADD qr_stampato_at datetime NULL",
        'qr_stampato_by'        => "ALTER TABLE $table_articoli ADD qr_stampato_by bigint(20) unsigned NULL",
        'data_modifica'         => "ALTER TABLE $table_articoli ADD data_modifica datetime NULL",
    ];

    foreach ( $columns as $column => $sql ) {
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM $table_articoli LIKE %s", $column )
        );
        if ( ! $exists ) {
            $wpdb->query( $sql );
        }
    }
}

function bsn_ensure_preventivo_page() {
    $page_id = get_option( 'bsn_preventivo_page_id' );
    if ( $page_id && get_post( $page_id ) ) {
        return;
    }

    $page = get_page_by_path( 'preventivo' );
    if ( $page ) {
        update_option( 'bsn_preventivo_page_id', $page->ID );
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $page_id = wp_insert_post(
        [
            'post_title'   => 'Preventivo',
            'post_name'    => 'preventivo',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[blackstar_preventivo]',
        ]
    );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        update_option( 'bsn_preventivo_page_id', $page_id );
    }
}

function bsn_get_preventivo_page_url() {
    $page_id = get_option( 'bsn_preventivo_page_id' );
    if ( $page_id ) {
        $url = get_permalink( $page_id );
        if ( $url ) {
            return $url;
        }
    }

    $page = get_page_by_path( 'preventivo' );
    if ( $page ) {
        update_option( 'bsn_preventivo_page_id', $page->ID );
        $url = get_permalink( $page->ID );
        if ( $url ) {
            return $url;
        }
    }

    return home_url( '/preventivo/' );
}


/**
 * Menu admin base
 */
add_action('admin_menu', 'bsn_admin_menu');
function bsn_admin_menu() {
    add_menu_page(
        'Black Star Noleggi',
        'BS Noleggi',
        'bsn_manage_noleggi',
        'bs-noleggi',
        'bsn_admin_page'
    );
    add_submenu_page(
        'bs-noleggi',
        'Categorie prodotto',
        'Categorie prodotto',
        'bsn_manage_noleggi',
        'edit-tags.php?taxonomy=bs_categoria_prodotto&post_type=bs_prodotto'
    );
    add_submenu_page(
        'bs-noleggi',
        'Impostazioni BS Noleggi',
        'Impostazioni',
        'manage_options',
        'bs-noleggi-settings',
        'bsn_render_settings_page'
    );
}

// Salva le impostazioni della pagina Google Maps
add_action( 'admin_init', 'bsn_register_settings' );
function bsn_register_settings() {
    register_setting( 'bsn_settings_group', 'bsn_google_maps_frontend_key', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ) );
    register_setting( 'bsn_settings_group', 'bsn_google_maps_backend_key', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ) );
}

// Pagina impostazioni — Google Maps API keys
function bsn_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $saved = isset( $_GET['settings-updated'] ) && $_GET['settings-updated'];
    $frontend_key = esc_attr( get_option( 'bsn_google_maps_frontend_key', '' ) );
    $backend_key  = esc_attr( get_option( 'bsn_google_maps_backend_key', '' ) );
    ?>
    <div class="wrap">
        <h1>Impostazioni Black Star Noleggi</h1>
        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>Impostazioni salvate.</p></div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'bsn_settings_group' ); ?>

            <h2>Google Maps API</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="bsn_google_maps_frontend_key">Chiave Frontend (Places Autocomplete)</label></th>
                    <td>
                        <input type="text" id="bsn_google_maps_frontend_key" name="bsn_google_maps_frontend_key"
                               value="<?php echo $frontend_key; ?>" class="regular-text" autocomplete="off">
                        <p class="description">
                            Usata nel browser del cliente per i suggerimenti indirizzo.<br>
                            <strong>Limitala per dominio</strong> in Google Cloud Console → API &amp; Services → Credentials → Restrizioni applicazione: Siti web HTTP referrer.<br>
                            Abilita: <em>Maps JavaScript API</em> e <em>Places API</em>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bsn_google_maps_backend_key">Chiave Backend (Geocoding + Routes)</label></th>
                    <td>
                        <input type="text" id="bsn_google_maps_backend_key" name="bsn_google_maps_backend_key"
                               value="<?php echo $backend_key; ?>" class="regular-text" autocomplete="off">
                        <p class="description">
                            Usata <strong>solo lato server</strong> per calcolare i km stradali reali e per il geocoding di fallback.<br>
                            <strong>Limitala per IP server</strong> in Google Cloud Console → Restrizioni applicazione: Indirizzi IP.<br>
                            Abilita: <em>Geocoding API</em> e <em>Routes API</em>.<br>
                            Se vuoi usare la stessa chiave per entrambi, incollala in entrambi i campi (meno sicuro).
                        </p>
                    </td>
                </tr>
            </table>

            <h2>Sede di partenza</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Indirizzo origine</th>
                    <td>
                        <p><strong>Black Star Service SRL</strong><br>
                        Via Cerca 28, 25135 Brescia BS (zona Sant'Eufemia)<br>
                        Coordinate: 45.51303326496157, 10.27310251455083</p>
                        <p class="description">Le coordinate di origine sono fisse nel codice e non modificabili da qui.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Salva impostazioni' ); ?>
        </form>
    </div>
    <?php
}

add_action('admin_menu', function() {
    // Pagina nascosta, raggiungibile solo via URL (non appare nel menu)
    add_submenu_page(
        null, // niente voce di menu
        'Finalizza noleggio',
        'Finalizza noleggio',
        'bsn_manage_noleggi', // ORA GLI OPERATORI POSSONO ACCEDERE
        'bsn-finalizza-noleggio',
        'bsn_render_finalizza_noleggio_page'
    );

        // Pagina nascosta: Rientro noleggio (come Finalizza, ma per chiusura)
    add_submenu_page(
        null,
        'Rientro noleggio',
        'Rientro noleggio',
        'bsn_manage_noleggi',
        'bsn-rientro-noleggio',
        'bsn_render_rientro_noleggio_page'
    );

});


function bsn_render_finalizza_noleggio_page( $args = [] ) {
    global $wpdb;

    $args = wp_parse_args(
        $args,
        [
            'back_url' => admin_url( 'admin.php?page=blackstar-noleggi' ),
            'render_document_only' => false,
            'noleggio_id' => '',
        ]
    );

    // ===== RECUPERA ID NOLEGGIO =====
    $render_document_only = ! empty( $args['render_document_only'] );
    $noleggio_id = ! empty( $args['noleggio_id'] )
        ? sanitize_text_field( $args['noleggio_id'] )
        : ( isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '' );
    
    if (empty($noleggio_id)) {
        echo '<div class="wrap"><h1>Errore</h1><p>ID noleggio mancante.</p></div>';
        return;
    }
    
    // ===== RECUPERA DATI NOLEGGIO DAL DB =====
    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $noleggio = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_noleggi WHERE id = %s",
        $noleggio_id
    ), ARRAY_A);
    
    if (!$noleggio) {
        echo '<div class="wrap"><h1>Errore</h1><p>Noleggio non trovato.</p></div>';
        return;
    }
    
    // ===== VERIFICA STATO (solo bozze possono essere finalizzate) =====
    if ($noleggio['stato'] !== 'bozza' && ! $render_document_only) {
        echo '<div class="wrap"><h1>Attenzione</h1><p>Questo noleggio è già in stato: <strong>' . esc_html($noleggio['stato']) . '</strong>. Solo le bozze possono essere finalizzate.</p>';
        echo '<p><a href="admin.php?page=blackstar-noleggi" class="button">← Torna ai noleggi</a></p></div>';
        return;
    }
    
    // ===== RECUPERA DATI CLIENTE =====
    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $cliente = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_clienti WHERE id = %d",
        $noleggio['cliente_id']
    ), ARRAY_A);

    if (!$cliente) {
        echo '<div class="wrap"><h1>Errore</h1><p>Cliente non trovato.</p></div>';
        return;
    }

    // ===== RECUPERA INCARICATO AL RITIRO (se diverso dal cliente) =====
    $incaricato = bsn_get_incaricato_ritiro_data( $noleggio );

    // ===== DECODIFICA ARTICOLI JSON =====
    $articoli = json_decode($noleggio['articoli'], true);
    if (!is_array($articoli)) {
        $articoli = [];
    }
    
    // ===== PREPARA DATE E CALCOLO GIORNI (IGNORE ORARIO, COME NEL FORM) =====
    // Prendiamo solo la parte data (YYYY-MM-DD), così siamo coerenti anche dopo gli update
    $periodo_utilizzo = bsn_get_periodo_utilizzo_noleggio( $noleggio );
    $periodo_logistico = bsn_get_periodo_logistico_noleggio( $noleggio );
    $service_snapshot = bsn_get_noleggio_service_snapshot( $noleggio );

    $data_inizio_formattata = $periodo_utilizzo['inizio_it'];
    $data_fine_formattata   = $periodo_utilizzo['fine_it'];

    $ts_inizio = $periodo_utilizzo['inizio'] ? strtotime($periodo_utilizzo['inizio'] . ' 00:00:00') : false;
    $ts_fine   = $periodo_utilizzo['fine'] ? strtotime($periodo_utilizzo['fine'] . ' 00:00:00') : false;

    // stessa logica del form noleggio: differenza in giorni interi, minimo 1
    $giorni = 1;
    if ($ts_inizio && $ts_fine && $ts_fine > $ts_inizio) {
        $giorni = max(1, (int)ceil(($ts_fine - $ts_inizio) / 86400));
    }

    $fattore_giorni_scalare = sqrt($giorni);
    
    // ===== RECUPERA TUTTI GLI ARTICOLI DAL DB =====
    $table_articoli = $wpdb->prefix . 'bs_articoli';
    $ids_articoli = array_map(function($a) { return (int)$a['id']; }, $articoli);
    $ids_articoli = array_values(array_unique($ids_articoli));
    
    $mappa_articoli = [];
    if (!empty($ids_articoli)) {
        $placeholders = implode(',', array_fill(0, count($ids_articoli), '%d'));
        $sql_art = "SELECT * FROM $table_articoli WHERE id IN ($placeholders)";
        $rows_art = $wpdb->get_results($wpdb->prepare($sql_art, $ids_articoli), ARRAY_A);
        
        foreach ($rows_art as $row) {
            $mappa_articoli[(int)$row['id']] = $row;
        }
    }
    
    // ===== CALCOLO TOTALI (come in JS) =====
    $categoria = $cliente['categoria_cliente'] ?? 'standard';
    $regime = (float)($cliente['regime_percentuale'] ?? 22.00);
    $subtotale = 0.0;
    $use_frontend_snapshot = bsn_should_use_frontend_snapshot_pricing( $noleggio );
    
    $consenso_privacy = ! empty( $noleggio['consenso_privacy'] );
    $consenso_condizioni = ! empty( $noleggio['consenso_condizioni'] );
    $firma_base64_db = $noleggio['firma_base64'] ?? '';
    $firma_url_db = $noleggio['firma_url'] ?? '';
    $firma_src = '';

    if ( ! empty( $firma_base64_db ) ) {
        if ( strpos( $firma_base64_db, 'data:image' ) === 0 ) {
            $firma_src = $firma_base64_db;
        } else {
            $firma_src = 'data:image/png;base64,' . $firma_base64_db;
        }
    } elseif ( ! empty( $firma_url_db ) ) {
        $firma_src = $firma_url_db;
    }

    $firma_data_value = 'N/D';
    if ( ! empty( $noleggio['firma_data'] ) ) {
        $firma_timestamp = strtotime( $noleggio['firma_data'] );
        if ( $firma_timestamp ) {
            $firma_data_value = date_i18n( 'd-m-Y H:i', $firma_timestamp );
        } else {
            $firma_data_value = $noleggio['firma_data'];
        }
    }

    $op_documento = $noleggio['op_preparazione_documento'] ?? '';
    $op_materiale = $noleggio['op_preparazione_materiale'] ?? '';
    $op_consegna = $noleggio['op_consegna_materiale'] ?? '';
    $op_rientro = $noleggio['op_rientro_materiale'] ?? '';

    ?>

<?php if ( $render_document_only ) : ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page { margin: 14mm 12mm; }
                body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }
            </style>
        </head>
        <body>
    <?php endif; ?>

    <?php if ( ! $render_document_only ) : ?>
    <div class="wrap bsn-finalizza-wrap">
        
        <!-- ===== HEADER AZIONI ===== -->
        <div class="bsn-finalizza-header" style="background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #ccc;">
            <a href="<?php echo esc_url( $args['back_url'] ); ?>" class="button">← Torna ai noleggi</a>
            <button id="bsn-salva-finalizza" class="button button-primary" style="float: right; margin-left: 10px;">💾 Salva e Attiva Noleggio</button>
            <button id="bsn-stampa-documento" class="button" style="float: right;">🖨️ Stampa/Scarica PDF</button>
            <div style="clear: both;"></div>
        </div>
    <?php endif; ?>

        <!-- ===== DOCUMENTO PRINCIPALE ===== -->
        <div id="bsn-documento-finalizzazione" style="background: #fff; padding: 30px; max-width: 21cm; margin: 0 auto; border: 1px solid #ddd;">
            
            <!-- ===== LOGO E INTESTAZIONE (STESSA RIGA) ===== -->
            <div class="bsn-doc-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; padding-bottom: 10px;">
                <div style="flex: 0 0 auto;">
                    <img src="http://www.blackstarservice.it/wp-content/uploads/2021/04/logo-1500px.jpg" 
                         alt="Black Star Service" 
                         style="max-width: 280px; height: auto;">
                </div>
                <div style="flex: 1; text-align: right; font-size: 10px; line-height: 1.4; padding-left: 20px;">
                    <strong style="font-size: 11px;">BLACK STAR SERVICE S.R.L.</strong><br>
                    Sede Legale: Via Repubblica Argentina, 54 - 25124 Brescia<br>
                    Sede Operativa: Via Cerca, 28 - 25135 Brescia<br>
                    Partita IVA: 04130270988
                </div>
            </div>
            
            <!-- ===== TIPOLOGIA DOCUMENTO ===== -->
            <div style="text-align: center; font-size: 9px; font-style: italic; margin-bottom: 10px;">
                Documento di responsabilità e di trasporto ai sensi<br>
                (D.P.R. 472 del 14-08-1996 - D.P.R. 696 del 21-12-1996)
            </div>
            
            <!-- ===== SEPARATORE ===== -->
            <div style="border-bottom: 2px solid #000; margin-bottom: 8px;"></div>
            
            <!-- ===== NUMERO NOLEGGIO ===== -->
            <div style="text-align: right; margin-bottom: 15px;">
                <strong style="font-size: 15px;">NOLEGGIO N° <?php echo esc_html($noleggio_id); ?></strong><br>
                <span style="font-size: 10px;">Data richiesta: <?php echo date('d-m-Y', strtotime($noleggio['data_richiesta'])); ?></span>
            </div>
            
            <!-- ===== DATI CLIENTE E NOLEGGIO (DUE COLONNE) ===== -->
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                
                <!-- COLONNA SINISTRA: DATI CLIENTE / INTESTATARIO -->
                <div style="flex: 1; border: 1px solid #000; padding: 10px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin: -10px -10px 8px -10px;">CLIENTE / INTESTATARIO</h3>
                    <table style="width: 100%; font-size: 10px; line-height: 1.3;">
                        <tr>
                            <td style="width: 35%; padding: 2px 0;"><strong>Nome/Rag. Soc.:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($cliente['nome']); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>CF/P.IVA:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($cliente['cf_piva'] ?? 'N/D'); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Telefono:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($cliente['telefono'] ?? 'N/D'); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Email:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($cliente['email'] ?? 'N/D'); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Documento:</strong></td>
                            <td style="padding: 2px 0;">
                                <?php 
                                $doc_tipo = !empty($cliente['tipo_documento']) ? esc_html($cliente['tipo_documento']) : 'N/D';
                                $doc_numero = !empty($cliente['numero_documento']) ? ' - ' . esc_html($cliente['numero_documento']) : '';
                                echo $doc_tipo . $doc_numero;
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Categoria:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html(strtoupper($cliente['categoria_cliente'] ?? 'Standard')); ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- COLONNA DESTRA: DATI NOLEGGIO -->
                <div style="flex: 1; border: 1px solid #000; padding: 10px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin: -10px -10px 8px -10px;">DATI NOLEGGIO</h3>
                    <table style="width: 100%; font-size: 10px; line-height: 1.3;">
                        <tr>
                            <td style="width: 35%; padding: 2px 0;"><strong>Periodo di utilizzo:</strong></td>
                            <td style="padding: 2px 0;">dal <strong><?php echo esc_html( $data_inizio_formattata ); ?></strong> al <strong><?php echo esc_html( $data_fine_formattata ); ?></strong> (<?php echo intval( $giorni ); ?> giorni)</td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Periodo di ritiro/riconsegna:</strong></td>
                            <td style="padding: 2px 0;">dal <strong><?php echo esc_html( $periodo_logistico['ritiro_effettivo_it'] ); ?></strong> al <strong><?php echo esc_html( $periodo_logistico['riconsegna_effettiva_it'] ); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Luogo Dest.:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($noleggio['luogo_destinazione'] ?? 'N/D'); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Trasporto:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($noleggio['trasporto_mezzo'] ?? 'N/D'); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Cauzione:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($noleggio['cauzione'] ?? 'N/D'); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Metodo pagamento:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($noleggio['metodo_pagamento'] ?? 'N/D'); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Causale:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html($noleggio['causale_trasporto'] ?? 'N/D'); ?></td>
                        </tr>
                        <?php if (!empty($noleggio['note'])): ?>
                        <tr>
                            <td style="padding: 2px 0; vertical-align: top;"><strong>Note:</strong></td>
                            <td style="padding: 2px 0;"><?php echo nl2br(esc_html($noleggio['note'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                
            </div>

            <?php echo bsn_render_pdf_incaricato_block( $incaricato ); ?>
            <?php echo bsn_render_pdf_incaricato_disclaimer( $incaricato ); ?>

            <?php echo bsn_render_noleggio_service_summary_html( $service_snapshot, array( 'context' => 'document', 'title' => 'SERVIZIO LOGISTICO / TECNICO' ) ); ?>

            <!-- ===== TABELLA ARTICOLI COMPLETA ===== -->
            <div class="bsn-doc-section" style="margin-bottom: 15px;">
                <h3 style="font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin-bottom: 8px;">ARTICOLI NOLEGGIATI</h3>
                
                <table style="width: 100%; border-collapse: collapse; font-size: 9px;">
                    <thead>
                        <tr style="background: #f0f0f0;">
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 8%;">Img</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 8%;">Codice</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 22%;">Prodotto</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 15%;">Correlati</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: right; width: 7%;">Prezzo</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 5%;">Q.tà</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 8%;">Fattore</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 7%;">Sconto</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: right; width: 8%;">Subtot.</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: right; width: 8%;">Val. Bene</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($articoli as $art) {
                            $articolo_id = isset($art['id']) ? (int)$art['id'] : 0;
                            $articolo_db = isset($mappa_articoli[$articolo_id]) ? $mappa_articoli[$articolo_id] : array();
                            $row_doc = bsn_prepare_noleggio_document_item_row(
                                $art,
                                $articolo_db,
                                $categoria,
                                $giorni,
                                $fattore_giorni_scalare,
                                $use_frontend_snapshot
                            );

                            if ( ! $row_doc ) {
                                continue;
                            }
                            
                            // Dati articolo
                            $codice = $row_doc['codice'];
                            $nome_prodotto = $row_doc['nome_prodotto'];
                            $external_url = $row_doc['external_url'];
                            $external_image = $row_doc['external_image'];
                            $product_meta_lines = $row_doc['product_meta_lines'];
                            $correlati_testo = $row_doc['correlati_testo'];
                            $prezzo = $row_doc['prezzo'];
                            $qty = $row_doc['qty'];
                            $fattore_display = $row_doc['fattore_display'];
                            
                            // Usa prezzo custom se presente
                            $sconto = $row_doc['sconto'];
                            $sconto_display = $row_doc['sconto_display'] ?? ( '-' . number_format( $sconto, 0 ) . '%' );
                            
                            // Sconto categoria
                            $subtotale_riga = $row_doc['subtotale_riga'];
                            
                            $valore_bene = $row_doc['valore_bene'];
                            
                            $subtotale += $subtotale_riga;
                            
                            ?>
                            <tr>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align:center;">
                                    <?php if ( ! empty( $external_image ) ) : ?>
                                        <?php if ( ! empty( $external_url ) ) : ?>
                                            <a href="<?php echo esc_url( $external_url ); ?>" target="_blank" rel="noopener noreferrer">
                                                <img src="<?php echo esc_url( $external_image ); ?>" alt="" style="width:50px; height:auto;">
                                            </a>
                                        <?php else : ?>
                                            <img src="<?php echo esc_url( $external_image ); ?>" alt="" style="width:50px; height:auto;">
                                        <?php endif; ?>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="border: 1px solid #ccc; padding: 3px;"><?php echo esc_html($codice); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px;">
                                    <?php
                                    if ( ! empty( $external_url ) ) :
                                    ?>
                                        <a href="<?php echo esc_url( $external_url ); ?>" target="_blank" rel="noopener noreferrer" style="color:#000; text-decoration:underline;">
                                            <?php echo esc_html( $nome_prodotto ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $nome_prodotto ); ?>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $product_meta_lines ) ) : ?>
                                        <div style="margin-top:2px; font-size:8px; line-height:1.25; color:#555;">
                                            <?php echo esc_html( implode( ' | ', $product_meta_lines ) ); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="border: 1px solid #ccc; padding: 3px; font-size: 8px;"><?php echo esc_html($correlati_testo); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: right;">€ <?php echo number_format($prezzo, 2, ',', '.'); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: center;"><?php echo $qty; ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: center; font-size: 8px;"><?php echo $fattore_display; ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: center;"><?php echo wp_kses_post( $sconto_display ); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: right;">€ <?php echo number_format($subtotale_riga, 2, ',', '.'); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: right; color: #666;">€ <?php echo number_format($valore_bene, 2, ',', '.'); ?></td>
                            </tr>
                            <?php
                        }
                        
                        // ===== CALCOLO TOTALI FINALI =====
                        $totals_context = bsn_get_noleggio_document_totals_context( $noleggio, $subtotale, $regime );
                        $importo_regime = $totals_context['importo_regime'];

                        // Totale finale (già calcolato e salvato)
                        $totale_noleggio = $totals_context['totale_noleggio'];

                        // Calcola lo sconto globale (differenza tra totale_noleggio con regime e totale_finale)
                        $totale_finale = $totals_context['totale_finale'];
                        $sconto_globale = $totals_context['sconto_globale'];
                        $pricing_note = $totals_context['pricing_note'];

                        // Lo sconto globale non lo ricalcoliamo, è già incluso in totale_calcolato
                        // Se vuoi mostrarlo separatamente, devi aggiungerlo come campo nel DB

                        ?>
                    </tbody>
                </table>
                
                <!-- ===== TOTALI (COME ANTEPRIMA) ===== -->
                <div style="margin-top: 10px; text-align: right; font-size: 11px; line-height: 1.6;">
                    <div><strong><?php echo esc_html( $totals_context['subtotale_label'] ); ?>:</strong> € <?php echo number_format($subtotale, 2, ',', '.'); ?></div>
                    <?php if ( ! empty( $totals_context['show_regime'] ) ) : ?>
                    <div><strong>IVA/Regime (<?php echo number_format($regime, 2, ',', '.'); ?>%):</strong> € <?php echo number_format($importo_regime, 2, ',', '.'); ?></div>
                    <?php endif; ?>
                    <div><strong><?php echo esc_html( $totals_context['totale_label'] ); ?>:</strong> € <?php echo number_format($totale_noleggio, 2, ',', '.'); ?></div>
                    <?php if (abs($sconto_globale) > 0.01): // Mostra sconto solo se significativo ?>
                    <div><strong>Sconto globale:</strong> € <?php echo number_format($sconto_globale, 2, ',', '.'); ?></div>
                    <?php endif; ?>
                    <div style="font-size: 14px; background: #f9f9f9; padding: 8px; display: inline-block; border: 2px solid #000; margin-top: 5px;">
                        <strong>TOTALE FINALE:</strong> € <?php echo number_format($totale_finale, 2, ',', '.'); ?>
                    </div>
                    <?php if ( ! empty( $pricing_note ) ) : ?>
                    <div style="max-width: 420px; margin-left: auto; margin-top: 8px; font-size: 9px; line-height: 1.4; color: #555;">
                        <?php echo esc_html( $pricing_note ); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php echo bsn_render_noleggio_kits_block( $noleggio_id ); ?>

            <!-- ===== CONSENSI E RESPONSABILITÀ ===== -->
            <div class="bsn-doc-section" style="margin-bottom: 15px; border: 2px solid #000; padding: 12px; background: #fffbf0;">
                <h3 style="margin-top: 0; font-size: 12px; margin-bottom: 8px;">CONSENSI E RESPONSABILITÀ</h3>
                
                <?php if ( $render_document_only ) : ?>
                    <div style="margin-bottom: 10px; font-size: 11px;">
                        <?php echo $consenso_privacy ? '[X]' : '[ ]'; ?>
                        Dichiaro di aver letto e accettato l'
                        <strong>Informativa Privacy</strong>
                    </div>
                    <div style="margin-bottom: 10px; font-size: 11px;">
                        <?php echo $consenso_condizioni ? '[X]' : '[ ]'; ?>
                        Dichiaro di aver preso visione delle
                        <strong>Modalità di Noleggio e Responsabilità</strong>
                    </div>
                <?php else : ?>
                    <div style="margin-bottom: 10px;">
                        <label style="display: block; font-size: 11px; cursor: pointer;">
                            <input type="checkbox" id="bsn-consenso-privacy" style="margin-right: 8px; transform: scale(1.3);">
                            Dichiaro di aver letto e accettato l'
                            <a href="https://www.blackstarservice.it/privacy/" target="_blank" style="color: #0073aa; text-decoration: underline;">
                                <strong>Informativa Privacy</strong>
                            </a>
                        </label>
                    </div>
                    
                    <div style="margin-bottom: 10px;">
                        <label style="display: block; font-size: 11px; cursor: pointer;">
                            <input type="checkbox" id="bsn-consenso-condizioni" style="margin-right: 8px; transform: scale(1.3);">
                            Dichiaro di aver preso visione delle
                            <a href="https://www.blackstarservice.it/modalita-noleggio/" target="_blank" style="color: #0073aa; text-decoration: underline;">
                                <strong>Modalità di Noleggio e Responsabilità</strong>
                            </a>
                        </label>
                    </div>
                <?php endif; ?>
            </div>
            
                        <!-- ===== FIRMA CLIENTE ===== -->
            <div class="bsn-doc-section" style="margin-bottom: 15px; border: 2px solid #000; padding: 12px;">
                <h3 style="margin-top: 0; font-size: 12px; margin-bottom: 8px;">FIRMA DEL CLIENTE PER ACCETTAZIONE</h3>
                
                <div style="margin-bottom: 8px;">
                    <label style="font-size: 10px; display: block; margin-bottom: 3px;">
                        <strong>Data e ora firma:</strong>
                    </label>
                    <?php
                        // Timestamp firma allineato +1 ora rispetto a current_time
                        $firma_ts = current_time( 'timestamp' ) + HOUR_IN_SECONDS;
                    ?>
                    <?php if ( $render_document_only ) : ?>
                        <div style="font-size: 10px; padding: 4px 0;">
                            <?php echo esc_html( $firma_data_value ); ?>
                        </div>
                    <?php else : ?>
                        <input type="text" id="bsn-data-firma"
                               value="<?php echo date_i18n( 'd-m-Y H:i', $firma_ts ); ?>"
                               readonly 
                               style="width: 180px; padding: 4px; background: #f9f9f9; border: 1px solid #ccc; font-size: 10px;">
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 10px;">
                    <label style="font-size: 10px; display: block; margin-bottom: 5px;">
                        <strong>Firma (utilizzare touchscreen o mouse):</strong>
                    </label>
                    <?php if ( $render_document_only ) : ?>
                        <div style="border: 2px solid #000; background: #fff; display: inline-block; width: 500px; height: 150px; text-align: center;">
                            <?php if ( ! empty( $firma_src ) ) : ?>
                                <img src="<?php echo esc_attr( $firma_src ); ?>" alt="Firma cliente" style="max-width: 500px; max-height: 150px;">
                            <?php else : ?>
                                <div style="font-size: 10px; color: #666; line-height: 150px;">Firma non disponibile</div>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <div style="border: 2px solid #000; background: #fff; display: inline-block;">
                            <canvas id="bsn-canvas-firma" width="500" height="150" style="display: block; cursor: crosshair;"></canvas>
                        </div>
                        <br>
                        <button type="button" id="bsn-cancella-firma" class="button" style="margin-top: 5px; font-size: 11px;">🗑️ Cancella Firma</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ===== OPERATORI ===== -->
            <div class="bsn-doc-section" style="margin-bottom: 15px; border: 1px solid #000; padding: 12px; background: #f9f9f9;">
                <h3 style="margin-top: 0; font-size: 12px; margin-bottom: 8px;">OPERATORI RESPONSABILI</h3>
                
                <?php if ( $render_document_only ) : ?>
                    <table style="width: 100%; font-size: 10px;">
                        <tr>
                            <td style="width: 38%; padding: 3px 0;"><strong>Op. Prep. Documento:</strong></td>
                            <td style="padding: 3px 0;"><?php echo esc_html( $op_documento !== '' ? $op_documento : '-' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 3px 0;"><strong>Op. Prep. Materiale:</strong></td>
                            <td style="padding: 3px 0;"><?php echo esc_html( $op_materiale !== '' ? $op_materiale : '-' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 3px 0;"><strong>Op. Consegna Materiale:</strong></td>
                            <td style="padding: 3px 0;"><?php echo esc_html( $op_consegna !== '' ? $op_consegna : '-' ); ?></td>
                        </tr>
                        <?php if ( $op_rientro !== '' ) : ?>
                            <tr>
                                <td style="padding: 3px 0;"><strong>Op. Rientro Materiale:</strong></td>
                                <td style="padding: 3px 0;"><?php echo esc_html( $op_rientro ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                <?php else : ?>
                    <table style="width: 100%; font-size: 10px;">
                        <tr>
                            <td style="width: 38%; padding: 3px 0;"><strong>Op. Prep. Documento:</strong></td>
                            <td style="padding: 3px 0;">
                                <input type="text" id="bsn-op-documento"
                                       value="<?php echo esc_attr( $op_documento ); ?>"
                                       style="width: 100%; padding: 4px; border: 1px solid #ccc; font-size: 10px;"
                                       placeholder="Nome operatore">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 3px 0;"><strong>Op. Prep. Materiale:</strong></td>
                            <td style="padding: 3px 0;">
                                <input type="text" id="bsn-op-materiale"
                                       value="<?php echo esc_attr( $op_materiale ); ?>"
                                       style="width: 100%; padding: 4px; border: 1px solid #ccc; font-size: 10px;"
                                       placeholder="Nome operatore">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 3px 0;"><strong>Op. Consegna Materiale:</strong></td>
                            <td style="padding: 3px 0;">
                                <input type="text" id="bsn-op-consegna"
                                       value="<?php echo esc_attr( $op_consegna ); ?>"
                                       style="width: 100%; padding: 4px; border: 1px solid #ccc; font-size: 10px;"
                                       placeholder="Nome operatore">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 3px 0;"><strong>Op. Rientro Materiale:</strong></td>
                            <td style="padding: 3px 0;">
                                <input type="text" id="bsn-op-rientro"
                                       value="<?php echo esc_attr( $op_rientro ); ?>"
                                       style="width: 100%; padding: 4px; border: 1px solid #ccc; font-size: 10px;"
                                       placeholder="Compilabile dopo">
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>
            </div>
            
        </div><!-- fine documento -->

    <?php if ( ! $render_document_only ) : ?>
        <!-- ===== FOOTER AZIONI RIPETUTO ===== -->
        <div class="bsn-finalizza-footer" style="background: #fff; padding: 15px; margin-top: 20px; border: 1px solid #ccc; text-align: center;">
            <button id="bsn-salva-finalizza-2" class="button button-primary button-hero">💾 Salva e Attiva Noleggio</button>
            <button id="bsn-stampa-documento-2" class="button button-hero" style="margin-left: 10px;">🖨️ Stampa/Scarica PDF</button>
        </div>
        
    </div><!-- fine wrap -->
    <?php endif; ?>

    <?php if ( $render_document_only ) : ?>
        </body>
        </html>
    <?php endif; ?>
    
    <?php if ( ! $render_document_only ) : ?>
    <!-- SCRIPT INLINE PER FIRMA E VALIDAZIONE -->
    <script>
    jQuery(document).ready(function($) {
        
        // ===== INIZIALIZZA CANVAS FIRMA =====
        var canvas = document.getElementById('bsn-canvas-firma');
        var ctx = canvas.getContext('2d');
        var isDrawing = false;
        var lastX = 0;
        var lastY = 0;
        
        // Stile pennello
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        
        // Event listeners per disegno
        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseout', stopDrawing);
        
        // Touch events per tablet
        canvas.addEventListener('touchstart', handleTouchStart);
        canvas.addEventListener('touchmove', handleTouchMove);
        canvas.addEventListener('touchend', stopDrawing);
        
        function startDrawing(e) {
            isDrawing = true;
            var rect = canvas.getBoundingClientRect();
            lastX = e.clientX - rect.left;
            lastY = e.clientY - rect.top;
        }
        
        function draw(e) {
            if (!isDrawing) return;
            
            var rect = canvas.getBoundingClientRect();
            var currentX = e.clientX - rect.left;
            var currentY = e.clientY - rect.top;
            
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(currentX, currentY);
            ctx.stroke();
            
            lastX = currentX;
            lastY = currentY;
        }
        
        function stopDrawing() {
            isDrawing = false;
        }
        
        function handleTouchStart(e) {
            e.preventDefault();
            var touch = e.touches[0];
            var rect = canvas.getBoundingClientRect();
            lastX = touch.clientX - rect.left;
            lastY = touch.clientY - rect.top;
            isDrawing = true;
        }
        
        function handleTouchMove(e) {
            e.preventDefault();
            if (!isDrawing) return;
            
            var touch = e.touches[0];
            var rect = canvas.getBoundingClientRect();
            var currentX = touch.clientX - rect.left;
            var currentY = touch.clientY - rect.top;
            
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(currentX, currentY);
            ctx.stroke();
            
            lastX = currentX;
            lastY = currentY;
        }
        
        // ===== PULSANTE CANCELLA FIRMA =====
        $('#bsn-cancella-firma').on('click', function() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        });
        
        // ===== VALIDAZIONE E SALVATAGGIO =====
        function validaESalva() {
            // Verifica consensi
            if (!$('#bsn-consenso-privacy').is(':checked')) {
                alert('❌ Devi accettare l\'Informativa Privacy per continuare.');
                return false;
            }
            
            if (!$('#bsn-consenso-condizioni').is(':checked')) {
                alert('❌ Devi accettare le Modalità di Noleggio per continuare.');
                return false;
            }
            
            // Verifica firma (canvas non vuoto)
            var canvasData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var firmaPresente = false;
            for (var i = 0; i < canvasData.data.length; i += 4) {
                if (canvasData.data[i+3] !== 0) {
                    firmaPresente = true;
                    break;
                }
            }
            
            if (!firmaPresente) {
                alert('❌ Devi apporre la firma per continuare.');
                return false;
            }
            
            // Verifica operatori (almeno documento e materiale)
            if (!$('#bsn-op-documento').val().trim()) {
                alert('❌ Inserisci l\'Operatore Preparazione Documento.');
                $('#bsn-op-documento').focus();
                return false;
            }
            
            if (!$('#bsn-op-materiale').val().trim()) {
                alert('❌ Inserisci l\'Operatore Preparazione Materiale.');
                $('#bsn-op-materiale').focus();
                return false;
            }
            
                        // TODO: Qui andrà la chiamata AJAX per salvare
            var payload = {
                id_noleggio: '<?php echo esc_js($noleggio_id); ?>',
                consenso_privacy: 1,
                consenso_condizioni: 1,
                firma_data: $('#bsn-data-firma').val(),
                firma_base64: canvas.toDataURL('image/png'),
                op_documento: $('#bsn-op-documento').val(),
                op_materiale: $('#bsn-op-materiale').val(),
                op_consegna: $('#bsn-op-consegna').val(),
                op_rientro: $('#bsn-op-rientro').val()
            };

            $.ajax({
                url: BSN_API.root + 'noleggi/finalizza',
                method: 'POST',
                data: payload,
                beforeSend: function(xhr) {
                    if (BSN_API && BSN_API.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
                    }
                    $('#bsn-salva-finalizza, #bsn-salva-finalizza-2')
                        .prop('disabled', true)
                        .text('⏳ Salvataggio in corso...');
                },
                success: function(res) {
                alert('✅ Noleggio finalizzato correttamente.\nID: ' + res.id + '\nStato: ' + res.stato);
                window.location.href = '<?php echo esc_url( home_url( '/app-noleggi/' ) ); ?>';
                 },

                error: function(err) {
                    console.error('Errore finalizzazione', err);
                    var msg = 'Errore durante la finalizzazione del noleggio.';
                    if (err.responseJSON && err.responseJSON.message) {
                        msg += '\n\nDettagli: ' + err.responseJSON.message;
                    }
                    alert('❌ ' + msg);
                },
                complete: function() {
                    $('#bsn-salva-finalizza, #bsn-salva-finalizza-2')
                        .prop('disabled', false)
                        .text('💾 Salva e Attiva Noleggio');
                }
            });

        }
        
        // Associa funzione a entrambi i pulsanti Salva
        $('#bsn-salva-finalizza, #bsn-salva-finalizza-2').on('click', validaESalva);
        
                // ===== STAMPA/PDF (funzionalità base) =====
        $('#bsn-stampa-documento, #bsn-stampa-documento-2').on('click', function() {
            window.print();
        });

        
    });
    </script>
    <?php endif; ?>

    <?php
}

function bsn_render_ispeziona_noleggio_page( $args = [] ) {
    global $wpdb;

    $args = wp_parse_args(
        $args,
        [
            'back_url' => home_url( '/app-noleggi/' ),
        ]
    );

    $noleggio_id = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';

    if ( empty( $noleggio_id ) ) {
        echo '<div class="wrap"><h1>Errore</h1><p>ID noleggio mancante.</p></div>';
        return;
    }

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $noleggio = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_noleggi WHERE id = %s",
            $noleggio_id
        ),
        ARRAY_A
    );

    if ( ! $noleggio ) {
        echo '<div class="wrap"><h1>Errore</h1><p>Noleggio non trovato.</p></div>';
        return;
    }

    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $cliente = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_clienti WHERE id = %d",
            $noleggio['cliente_id']
        ),
        ARRAY_A
    );

    if ( ! $cliente ) {
        echo '<div class="wrap"><h1>Errore</h1><p>Cliente non trovato.</p></div>';
        return;
    }

    // ===== RECUPERA INCARICATO AL RITIRO (se diverso dal cliente) =====
    $incaricato = bsn_get_incaricato_ritiro_data( $noleggio );

    $articoli = json_decode( $noleggio['articoli'], true );
    if ( ! is_array( $articoli ) ) {
        $articoli = [];
    }

    $periodo_utilizzo = bsn_get_periodo_utilizzo_noleggio( $noleggio );
    $periodo_logistico = bsn_get_periodo_logistico_noleggio( $noleggio );
    $service_snapshot = bsn_get_noleggio_service_snapshot( $noleggio );
    $data_inizio_formattata = $periodo_utilizzo['inizio_it'];
    $data_fine_formattata   = $periodo_utilizzo['fine_it'];

    $table_articoli = $wpdb->prefix . 'bs_articoli';
    $ids_articoli = array_map(
        function ( $a ) {
            return (int) $a['id'];
        },
        $articoli
    );
    $ids_articoli = array_values( array_unique( $ids_articoli ) );

    $mappa_articoli = [];
    if ( ! empty( $ids_articoli ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );
        $sql_art = "SELECT * FROM $table_articoli WHERE id IN ($placeholders)";
        $rows_art = $wpdb->get_results( $wpdb->prepare( $sql_art, $ids_articoli ), ARRAY_A );

        foreach ( $rows_art as $row ) {
            $mappa_articoli[ (int) $row['id'] ] = $row;
        }
    }

    ?>

    <div class="wrap bsn-ispeziona-wrap">
        <div class="bsn-ispeziona-header" style="background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #ccc;">
            <a href="<?php echo esc_url( $args['back_url'] ); ?>" class="button">← Torna ai noleggi</a>
            <button id="bsn-stampa-documento" class="button" style="float: right;">🖨️ Stampa/Scarica PDF</button>
            <div style="clear: both;"></div>
        </div>

        <div id="bsn-documento-ispezione" style="background: #fff; padding: 30px; max-width: 21cm; margin: 0 auto; border: 1px solid #ddd;">
            <div class="bsn-doc-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; padding-bottom: 10px;">
                <div style="flex: 0 0 auto;">
                    <img src="http://www.blackstarservice.it/wp-content/uploads/2021/04/logo-1500px.jpg"
                         alt="Black Star Service"
                         style="max-width: 280px; height: auto;">
                </div>
                <div style="flex: 1; text-align: right; font-size: 10px; line-height: 1.4; padding-left: 20px;">
                    <strong style="font-size: 11px;">BLACK STAR SERVICE S.R.L.</strong><br>
                    Sede Legale: Via Repubblica Argentina, 54 - 25124 Brescia<br>
                    Sede Operativa: Via Cerca, 28 - 25135 Brescia<br>
                    Partita IVA: 04130270988
                </div>
            </div>

            <div style="text-align: center; font-size: 9px; font-style: italic; margin-bottom: 10px;">
                Documento di ispezione noleggio - solo consultazione
            </div>

            <div style="border-bottom: 2px solid #000; margin-bottom: 8px;"></div>

            <div style="text-align: right; margin-bottom: 15px;">
                <strong style="font-size: 15px;">NOLEGGIO N° <?php echo esc_html( $noleggio_id ); ?></strong><br>
                <span style="font-size: 10px;">Data richiesta: <?php echo date( 'd-m-Y', strtotime( $noleggio['data_richiesta'] ) ); ?></span>
            </div>

            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 1; border: 1px solid #000; padding: 10px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin: -10px -10px 8px -10px;">CLIENTE / INTESTATARIO</h3>
                    <table style="width: 100%; font-size: 10px; line-height: 1.3;">
                        <tr>
                            <td style="width: 35%; padding: 2px 0;"><strong>Nome/Rag. Soc.:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $cliente['nome'] ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>CF/P.IVA:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $cliente['cf_piva'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Telefono:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $cliente['telefono'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Email:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $cliente['email'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Documento:</strong></td>
                            <td style="padding: 2px 0;">
                                <?php
                                $doc_tipo = ! empty( $cliente['tipo_documento'] ) ? esc_html( $cliente['tipo_documento'] ) : 'N/D';
                                $doc_numero = ! empty( $cliente['numero_documento'] ) ? ' - ' . esc_html( $cliente['numero_documento'] ) : '';
                                echo $doc_tipo . $doc_numero;
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Categoria:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( strtoupper( $cliente['categoria_cliente'] ?? 'Standard' ) ); ?></td>
                        </tr>
                    </table>
                </div>

                <div style="flex: 1; border: 1px solid #000; padding: 10px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin: -10px -10px 8px -10px;">DATI NOLEGGIO</h3>
                    <table style="width: 100%; font-size: 10px; line-height: 1.3;">
                        <tr>
                            <td style="width: 35%; padding: 2px 0;"><strong>Periodo di utilizzo:</strong></td>
                            <td style="padding: 2px 0;">dal <strong><?php echo esc_html( $data_inizio_formattata ); ?></strong> al <strong><?php echo esc_html( $data_fine_formattata ); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Periodo di ritiro/riconsegna:</strong></td>
                            <td style="padding: 2px 0;">dal <strong><?php echo esc_html( $periodo_logistico['ritiro_effettivo_it'] ); ?></strong> al <strong><?php echo esc_html( $periodo_logistico['riconsegna_effettiva_it'] ); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Luogo Dest.:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $noleggio['luogo_destinazione'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Trasporto:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $noleggio['trasporto_mezzo'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Cauzione:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $noleggio['cauzione'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Causale:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $noleggio['causale_trasporto'] ?? 'N/D' ); ?></td>
                        </tr>
                        <?php if ( ! empty( $noleggio['note'] ) ) : ?>
                        <tr>
                            <td style="padding: 2px 0; vertical-align: top;"><strong>Note:</strong></td>
                            <td style="padding: 2px 0;"><?php echo nl2br( esc_html( $noleggio['note'] ) ); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php echo bsn_render_pdf_incaricato_block( $incaricato ); ?>
            <?php echo bsn_render_pdf_incaricato_disclaimer( $incaricato ); ?>

            <?php echo bsn_render_noleggio_service_summary_html( $service_snapshot, array( 'context' => 'document', 'title' => 'SERVIZIO LOGISTICO / TECNICO' ) ); ?>

            <div class="bsn-doc-section" style="margin-bottom: 15px;">
                <h3 style="font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin-bottom: 8px;">ARTICOLI NOLEGGIATI</h3>

                <table style="width: 100%; border-collapse: collapse; font-size: 9px;">
                    <thead>
                        <tr style="background: #f0f0f0;">
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 10%;">Codice</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 25%;">Descrizione</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 25%;">Correlati</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 20%;">Ubicazione</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 10%;">Q.tà</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ( empty( $articoli ) ) {
                            echo '<tr><td colspan="5" style="border: 1px solid #ccc; padding: 6px;">Nessun articolo associato.</td></tr>';
                        } else {
                            foreach ( $articoli as $art ) {
                                $qty = isset( $art['qty'] ) ? (int) $art['qty'] : 1;
                                $articolo_id = isset( $art['id'] ) ? (int) $art['id'] : 0;

                                if ( ! isset( $mappa_articoli[ $articolo_id ] ) ) {
                                    continue;
                                }

                                $articolo_db = $mappa_articoli[ $articolo_id ];

                                $codice = $articolo_db['codice'] ?? 'N/D';
                                $nome = $articolo_db['nome'] ?? 'Articolo #' . $articolo_id;
                                $ubicazione = $articolo_db['ubicazione'] ?? '-';

                                $correlati_testo = '-';
                                if ( ! empty( $articolo_db['correlati'] ) ) {
                                    $correlati = json_decode( $articolo_db['correlati'], true );
                                    if ( is_array( $correlati ) && ! empty( $correlati ) ) {
                                        $corrLabels = array_map(
                                            function ( $c ) use ( $qty ) {
                                                $qtyCorr = ( (int) ( $c['qty'] ?? 1 ) ) * $qty;
                                                return $qtyCorr . 'x ' . ( $c['nome'] ?? '' );
                                            },
                                            $correlati
                                        );
                                        $correlati_testo = implode( ', ', $corrLabels );
                                    }
                                }
                                ?>
                                <tr>
                                    <td style="border: 1px solid #ccc; padding: 3px;"><?php echo esc_html( $codice ); ?></td>
                                    <td style="border: 1px solid #ccc; padding: 3px;"><?php echo esc_html( $nome ); ?></td>
                                    <td style="border: 1px solid #ccc; padding: 3px; font-size: 8px;"><?php echo esc_html( $correlati_testo ); ?></td>
                                    <td style="border: 1px solid #ccc; padding: 3px;"><?php echo esc_html( $ubicazione ); ?></td>
                                    <td style="border: 1px solid #ccc; padding: 3px; text-align: center;"><?php echo esc_html( $qty ); ?></td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <?php echo bsn_render_noleggio_kits_block( $noleggio_id ); ?>
            
            <div class="bsn-doc-section" style="margin-bottom: 15px;">
                <h3>Operatori</h3>
                <table style="width: 100%; font-size: 10px; line-height: 1.3;">
                    <tr>
                        <td style="width: 35%; padding: 2px 0;"><strong>Preparazione documento:</strong></td>
                        <td style="padding: 2px 0;"><?php echo esc_html( ( $noleggio['op_preparazione_documento'] ?? '' ) !== '' ? $noleggio['op_preparazione_documento'] : '-' ); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0;"><strong>Preparazione materiale:</strong></td>
                        <td style="padding: 2px 0;"><?php echo esc_html( ( $noleggio['op_preparazione_materiale'] ?? '' ) !== '' ? $noleggio['op_preparazione_materiale'] : '-' ); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0;"><strong>Consegna materiale:</strong></td>
                        <td style="padding: 2px 0;"><?php echo esc_html( ( $noleggio['op_consegna_materiale'] ?? '' ) !== '' ? $noleggio['op_consegna_materiale'] : '-' ); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0;"><strong>Rientro materiale:</strong></td>
                        <td style="padding: 2px 0;"><?php echo esc_html( ( $noleggio['op_rientro_materiale'] ?? '' ) !== '' ? $noleggio['op_rientro_materiale'] : '-' ); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="bsn-ispeziona-footer" style="background: #fff; padding: 15px; margin-top: 20px; border: 1px solid #ccc; text-align: center;">
            <button id="bsn-stampa-documento-2" class="button button-hero">🖨️ Stampa/Scarica PDF</button>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#bsn-stampa-documento, #bsn-stampa-documento-2').on('click', function() {
            window.print();
        });
    });
    </script>
    <?php
}

function bsn_render_preventivo_noleggio_page( $args = [] ) {
    global $wpdb;

    $args = wp_parse_args(
        $args,
        [
            'back_url'            => home_url( '/app-noleggi/' ),
            'noleggio_id'         => '',
            'render_document_only' => false,
        ]
    );

    $render_document_only = ! empty( $args['render_document_only'] );
    $noleggio_id = ! empty( $args['noleggio_id'] )
        ? sanitize_text_field( $args['noleggio_id'] )
        : ( isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '' );

    if ( empty( $noleggio_id ) ) {
        echo '<div class="wrap"><h1>Errore</h1><p>ID preventivo mancante.</p></div>';
        return;
    }

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $noleggio = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_noleggi WHERE id = %s",
            $noleggio_id
        ),
        ARRAY_A
    );

    if ( ! $noleggio ) {
        echo '<div class="wrap"><h1>Errore</h1><p>Preventivo non trovato.</p></div>';
        return;
    }

    if ( ! $render_document_only && $noleggio['stato'] !== 'preventivo' ) {
        echo '<div class="wrap"><h1>Attenzione</h1><p>Questo documento non è in stato preventivo.</p>';
        echo '<p><a href="' . esc_url( $args['back_url'] ) . '" class="button">← Torna ai noleggi</a></p></div>';
        return;
    }

    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $cliente = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_clienti WHERE id = %d",
            $noleggio['cliente_id']
        ),
        ARRAY_A
    );

    if ( ! $cliente ) {
        echo '<div class="wrap"><h1>Errore</h1><p>Cliente non trovato.</p></div>';
        return;
    }

    // ===== RECUPERA INCARICATO AL RITIRO (se diverso dal cliente) =====
    $incaricato = bsn_get_incaricato_ritiro_data( $noleggio );

    $articoli = json_decode( $noleggio['articoli'], true );
    if ( ! is_array( $articoli ) ) {
        $articoli = [];
    }

    $periodo_utilizzo = bsn_get_periodo_utilizzo_noleggio( $noleggio );
    $periodo_logistico = bsn_get_periodo_logistico_noleggio( $noleggio );
    $data_inizio_formattata = $periodo_utilizzo['inizio_it'];
    $data_fine_formattata   = $periodo_utilizzo['fine_it'];

    $giorni = 1;
    if ( $periodo_utilizzo['inizio'] && $periodo_utilizzo['fine'] ) {
        $ts_da = strtotime( $periodo_utilizzo['inizio'] );
        $ts_a = strtotime( $periodo_utilizzo['fine'] );
        if ( $ts_da && $ts_a && $ts_a > $ts_da ) {
            $giorni = max( 1, (int) ceil( ( $ts_a - $ts_da ) / 86400 ) );
        }
    }
    $fattore_giorni_scalare = sqrt( max( 1, (float) $giorni ) );

    $table_articoli = $wpdb->prefix . 'bs_articoli';
    $ids_articoli = array_map(
        function ( $a ) {
            return (int) $a['id'];
        },
        $articoli
    );
    $ids_articoli = array_values( array_unique( $ids_articoli ) );

    $mappa_articoli = [];
    if ( ! empty( $ids_articoli ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );
        $sql_art = "SELECT * FROM $table_articoli WHERE id IN ($placeholders)";
        $rows_art = $wpdb->get_results( $wpdb->prepare( $sql_art, $ids_articoli ), ARRAY_A );

        foreach ( $rows_art as $row ) {
            $mappa_articoli[ (int) $row['id'] ] = $row;
        }
    }

    $categoria = $cliente['categoria_cliente'] ?? 'standard';
    $regime = (float) ( $cliente['regime_percentuale'] ?? 22.00 );
    $subtotale = 0.0;
    $use_frontend_snapshot = bsn_should_use_frontend_snapshot_pricing( $noleggio );
    $service_snapshot = bsn_get_noleggio_service_snapshot( $noleggio );

    if ( $render_document_only ) :
    ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page { margin: 14mm 12mm; }
                body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }
            </style>
        </head>
        <body>
    <?php endif; ?>

    <?php if ( ! $render_document_only ) : ?>
    <div class="wrap bsn-preventivo-wrap">
        <div class="bsn-preventivo-header" style="background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #ccc;">
            <a href="<?php echo esc_url( $args['back_url'] ); ?>" class="button">← Torna ai noleggi</a>
            <div style="clear: both;"></div>
        </div>
    <?php endif; ?>

        <div id="bsn-documento-preventivo" style="background: #fff; padding: 30px; max-width: 21cm; margin: 0 auto; border: 1px solid #ddd;">
            <div class="bsn-doc-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; padding-bottom: 10px;">
                <div style="flex: 0 0 auto;">
                    <img src="http://www.blackstarservice.it/wp-content/uploads/2021/04/logo-1500px.jpg"
                         alt="Black Star Service"
                         style="max-width: 280px; height: auto;">
                </div>
                <div style="flex: 1; text-align: right; font-size: 10px; line-height: 1.4; padding-left: 20px;">
                    <strong style="font-size: 11px;">BLACK STAR SERVICE S.R.L.</strong><br>
                    Sede Legale: Via Repubblica Argentina, 54 - 25124 Brescia<br>
                    Sede Operativa: Via Cerca, 28 - 25135 Brescia<br>
                    Partita IVA: 04130270988
                </div>
            </div>

            <div style="text-align: center; font-size: 9px; font-style: italic; margin-bottom: 10px;">
                Documento di preventivo - solo proposta commerciale
            </div>

            <div style="border-bottom: 2px solid #000; margin-bottom: 8px;"></div>

            <div style="text-align: right; margin-bottom: 15px;">
                <strong style="font-size: 15px;">PREVENTIVO N° <?php echo esc_html( $noleggio_id ); ?></strong><br>
                <span style="font-size: 10px;">Data richiesta: <?php echo date( 'd-m-Y', strtotime( $noleggio['data_richiesta'] ) ); ?></span>
            </div>

            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 1; border: 1px solid #000; padding: 10px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin: -10px -10px 8px -10px;">CLIENTE / INTESTATARIO</h3>
                    <table style="width: 100%; font-size: 10px; line-height: 1.3;">
                        <tr>
                            <td style="width: 35%; padding: 2px 0;"><strong>Nome/Rag. Soc.:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $cliente['nome'] ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>CF/P.IVA:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $cliente['cf_piva'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Telefono:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $cliente['telefono'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Email:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $cliente['email'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Documento:</strong></td>
                            <td style="padding: 2px 0;">
                                <?php
                                $doc_tipo = ! empty( $cliente['tipo_documento'] ) ? esc_html( $cliente['tipo_documento'] ) : 'N/D';
                                $doc_numero = ! empty( $cliente['numero_documento'] ) ? ' - ' . esc_html( $cliente['numero_documento'] ) : '';
                                echo $doc_tipo . $doc_numero;
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Categoria:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( strtoupper( $cliente['categoria_cliente'] ?? 'Standard' ) ); ?></td>
                        </tr>
                    </table>
                </div>

                <div style="flex: 1; border: 1px solid #000; padding: 10px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin: -10px -10px 8px -10px;">DATI PREVENTIVO</h3>
                    <table style="width: 100%; font-size: 10px; line-height: 1.3;">
                        <tr>
                            <td style="width: 35%; padding: 2px 0;"><strong>Periodo di utilizzo:</strong></td>
                            <td style="padding: 2px 0;">dal <strong><?php echo esc_html( $data_inizio_formattata ); ?></strong> al <strong><?php echo esc_html( $data_fine_formattata ); ?></strong> (<?php echo intval( $giorni ); ?> giorni)</td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Periodo di ritiro/riconsegna:</strong></td>
                            <td style="padding: 2px 0;">dal <strong><?php echo esc_html( $periodo_logistico['ritiro_effettivo_it'] ); ?></strong> al <strong><?php echo esc_html( $periodo_logistico['riconsegna_effettiva_it'] ); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Luogo Dest.:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $noleggio['luogo_destinazione'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Trasporto:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $noleggio['trasporto_mezzo'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Cauzione:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $noleggio['cauzione'] ?? 'N/D' ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Causale:</strong></td>
                            <td style="padding: 2px 0;"><?php echo esc_html( $noleggio['causale_trasporto'] ?? 'N/D' ); ?></td>
                        </tr>
                        <?php if ( ! empty( $noleggio['note'] ) ) : ?>
                        <tr>
                            <td style="padding: 2px 0; vertical-align: top;"><strong>Note:</strong></td>
                            <td style="padding: 2px 0;"><?php echo nl2br( esc_html( $noleggio['note'] ) ); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php echo bsn_render_pdf_incaricato_block( $incaricato ); ?>
            <?php echo bsn_render_pdf_incaricato_disclaimer( $incaricato ); ?>

            <?php echo bsn_render_noleggio_service_summary_html( $service_snapshot, array( 'context' => 'document', 'title' => 'SERVIZIO LOGISTICO / TECNICO' ) ); ?>

            <div class="bsn-doc-section" style="margin-bottom: 15px;">
                <h3 style="font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin-bottom: 8px;">ARTICOLI PREVENTIVO</h3>

                <table style="width: 100%; border-collapse: collapse; font-size: 9px;">
                    <thead>
                        <tr style="background: #f0f0f0;">
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 8%;">Img</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 8%;">Codice</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 22%;">Prodotto</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: left; width: 15%;">Correlati</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: right; width: 7%;">Prezzo</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 5%;">Q.tà</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 8%;">Fattore</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: center; width: 7%;">Sconto</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: right; width: 8%;">Subtot.</th>
                            <th style="border: 1px solid #000; padding: 3px; text-align: right; width: 8%;">Val. Bene</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ( $articoli as $art ) {
                            $articolo_id = isset( $art['id'] ) ? (int) $art['id'] : 0;
                            $articolo_db = isset( $mappa_articoli[ $articolo_id ] ) ? $mappa_articoli[ $articolo_id ] : array();
                            $row_doc = bsn_prepare_noleggio_document_item_row(
                                $art,
                                $articolo_db,
                                $categoria,
                                $giorni,
                                $fattore_giorni_scalare,
                                $use_frontend_snapshot
                            );

                            if ( ! $row_doc ) {
                                continue;
                            }

                            $codice = $row_doc['codice'];
                            $nome_prodotto = $row_doc['nome_prodotto'];
                            $external_url = $row_doc['external_url'];
                            $external_image = $row_doc['external_image'];
                            $product_meta_lines = $row_doc['product_meta_lines'];
                            $correlati_testo = $row_doc['correlati_testo'];
                            $prezzo = $row_doc['prezzo'];
                            $qty = $row_doc['qty'];
                            $fattore_display = $row_doc['fattore_display'];

                            $sconto = $row_doc['sconto'];
                            $sconto_display = $row_doc['sconto_display'] ?? ( '-' . number_format( $sconto, 0 ) . '%' );

                            $subtotale_riga = $row_doc['subtotale_riga'];

                            $valore_bene = $row_doc['valore_bene'];

                            $subtotale += $subtotale_riga;
                            ?>
                            <tr>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align:center;">
                                    <?php if ( ! empty( $external_image ) ) : ?>
                                        <?php if ( ! empty( $external_url ) ) : ?>
                                            <a href="<?php echo esc_url( $external_url ); ?>" target="_blank" rel="noopener noreferrer">
                                                <img src="<?php echo esc_url( $external_image ); ?>" alt="" style="width:50px; height:auto;">
                                            </a>
                                        <?php else : ?>
                                            <img src="<?php echo esc_url( $external_image ); ?>" alt="" style="width:50px; height:auto;">
                                        <?php endif; ?>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="border: 1px solid #ccc; padding: 3px;"><?php echo esc_html( $codice ); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px;">
                                    <?php
                                    if ( ! empty( $external_url ) ) :
                                    ?>
                                        <a href="<?php echo esc_url( $external_url ); ?>" target="_blank" rel="noopener noreferrer" style="color:#000; text-decoration:underline;">
                                            <?php echo esc_html( $nome_prodotto ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $nome_prodotto ); ?>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $product_meta_lines ) ) : ?>
                                        <div style="margin-top:2px; font-size:8px; line-height:1.25; color:#555;">
                                            <?php echo esc_html( implode( ' | ', $product_meta_lines ) ); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="border: 1px solid #ccc; padding: 3px; font-size: 8px;"><?php echo esc_html( $correlati_testo ); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: right;">€ <?php echo number_format( $prezzo, 2, ',', '.' ); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: center;"><?php echo $qty; ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: center; font-size: 8px;"><?php echo esc_html( $fattore_display ); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: center;"><?php echo wp_kses_post( $sconto_display ); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: right;">€ <?php echo number_format( $subtotale_riga, 2, ',', '.' ); ?></td>
                                <td style="border: 1px solid #ccc; padding: 3px; text-align: right; color: #666;">€ <?php echo number_format( $valore_bene, 2, ',', '.' ); ?></td>
                            </tr>
                            <?php
                        }

                        $totals_context = bsn_get_noleggio_document_totals_context( $noleggio, $subtotale, $regime );
                        $importo_regime = $totals_context['importo_regime'];
                        $totale_noleggio = $totals_context['totale_noleggio'];
                        $totale_finale = $totals_context['totale_finale'];
                        $sconto_globale = $totals_context['sconto_globale'];
                        $pricing_note = $totals_context['pricing_note'];
                        ?>
                    </tbody>
                </table>

                <div style="margin-top: 10px; text-align: right; font-size: 11px; line-height: 1.6;">
                    <div><strong><?php echo esc_html( $totals_context['subtotale_label'] ); ?>:</strong> € <?php echo number_format( $subtotale, 2, ',', '.' ); ?></div>
                    <?php if ( ! empty( $totals_context['show_regime'] ) ) : ?>
                    <div><strong>IVA/Regime (<?php echo number_format( $regime, 2, ',', '.' ); ?>%):</strong> € <?php echo number_format( $importo_regime, 2, ',', '.' ); ?></div>
                    <?php endif; ?>
                    <div><strong><?php echo esc_html( $totals_context['totale_label'] ); ?>:</strong> € <?php echo number_format( $totale_noleggio, 2, ',', '.' ); ?></div>
                    <?php if ( abs( $sconto_globale ) > 0.01 ) : ?>
                    <div><strong>Sconto globale:</strong> € <?php echo number_format( $sconto_globale, 2, ',', '.' ); ?></div>
                    <?php endif; ?>
                    <div style="font-size: 14px; background: #f9f9f9; padding: 8px; display: inline-block; border: 2px solid #000; margin-top: 5px;">
                        <strong>TOTALE FINALE:</strong> € <?php echo number_format( $totale_finale, 2, ',', '.' ); ?>
                    </div>
                    <?php if ( ! empty( $pricing_note ) ) : ?>
                    <div style="max-width: 420px; margin-left: auto; margin-top: 8px; font-size: 9px; line-height: 1.4; color: #555;">
                        <?php echo esc_html( $pricing_note ); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php echo bsn_render_noleggio_kits_block( $noleggio_id ); ?>
        </div>

        <?php if ( ! $render_document_only ) : ?>
        <div class="bsn-preventivo-footer" style="background: #fff; padding: 15px; margin-top: 20px; border: 1px solid #ccc; text-align: center;">
            <a href="<?php echo esc_url( rest_url( 'bsn/v1/noleggi/preventivo/pdf' ) . '?id=' . rawurlencode( $noleggio_id ) . '&_wpnonce=' . rawurlencode( wp_create_nonce( 'wp_rest' ) ) ); ?>" class="button" target="_blank" rel="noopener noreferrer">⬇️ Scarica PDF</a>
            <button id="bsn-invia-preventivo" class="button button-primary">✉️ Invia PDF al cliente</button>
        </div>
        <?php endif; ?>

    <?php if ( $render_document_only ) : ?>
        </body>
        </html>
    <?php else : ?>
        <script>
            if (typeof BSN_API === 'undefined') {
            var BSN_API = {
                root: '<?php echo esc_js( rest_url( 'bsn/v1/' ) ); ?>',
                nonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
            };
        }
        jQuery(document).ready(function($) {
            $('#bsn-invia-preventivo').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Invio in corso...');
                $.ajax({
                    url: BSN_API.root + 'noleggi/preventivo/invia',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
                    },
                    data: { id: '<?php echo esc_js( $noleggio_id ); ?>' },
                    success: function() {
                        alert('✅ Preventivo inviato correttamente.');
                    },
                    error: function(err) {
                        console.error(err);
                        var msg = 'Errore durante l\'invio del preventivo.';
                        if (err && err.responseJSON && err.responseJSON.message) {
                            msg += '\n' + err.responseJSON.message;
                        }
                        alert(msg);
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('✉️ Invia PDF al cliente');
                    }
                });
            });
        });
        </script>
        </div>
    <?php endif; ?>
    <?php
}

// === PAGINA ADMIN: Rientro noleggio (scheletro base) ===
function bsn_render_rientro_noleggio_page() {
    if ( ! bsn_check_admin() ) {
        wp_die( 'Accesso negato.' );
    }

    global $wpdb;
    $table_noleggi  = $wpdb->prefix . 'bs_noleggi';
    $table_clienti  = $wpdb->prefix . 'bs_clienti';
    $table_articoli = $wpdb->prefix . 'bs_articoli';

    $id_noleggio = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

    echo '<div class="wrap">';

    if ( ! $id_noleggio ) {
        echo '<h1>Rientro noleggio</h1>';
        echo '<p>ID noleggio mancante.</p>';
        echo '</div>';
        return;
    }

    // Recupera noleggio + cliente (stessa logica di bsn_api_noleggi_dettaglio)
    $sql = $wpdb->prepare(
        "SELECT n.*, c.nome AS cliente_nome
         FROM $table_noleggi n
         LEFT JOIN $table_clienti c ON n.cliente_id = c.id
         WHERE n.id = %s
         LIMIT 1",
        $id_noleggio
    );
    $n = $wpdb->get_row( $sql );

    if ( ! $n ) {
        echo '<h1>Rientro noleggio #' . esc_html( $id_noleggio ) . '</h1>';
        echo '<p>Noleggio non trovato.</p>';
        echo '</div>';
        return;
    }

    $periodo_utilizzo = bsn_get_periodo_utilizzo_noleggio( $n );
    $periodo_logistico = bsn_get_periodo_logistico_noleggio( $n );

    // Aggiorna eventuale stato "ritardo" in base alla data riconsegna effettiva
    $oggi = current_time( 'Y-m-d' );
    if ( ! empty( $periodo_logistico['riconsegna_effettiva'] ) ) {
        $data_fine_check = $periodo_logistico['riconsegna_effettiva'];
        if ( $data_fine_check < $oggi && $n->stato === 'attivo' ) {
            $wpdb->update(
                $table_noleggi,
                [ 'stato' => 'ritardo' ],
                [ 'id' => $id_noleggio ],
                [ '%s' ],
                [ '%s' ]
            );
            $n->stato = 'ritardo';
        }
    }

    if ( $n->stato !== 'attivo' && $n->stato !== 'ritardo' ) {
        echo '<h1>Rientro noleggio #' . esc_html( $id_noleggio ) . '</h1>';
        echo '<p>Questo noleggio non è in stato attivo o ritardo.</p>';
        echo '<p><a href="' . esc_url( home_url( '/app-noleggi/' ) ) . '" class="button">← Torna ai noleggi</a></p>';
        echo '</div>';
        return;
    }

    // Decodifica articoli JSON
    $articoli = [];
    if ( ! empty( $n->articoli ) ) {
        $decoded = json_decode( $n->articoli, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            $articoli = $decoded;
        }
    }

    // Recupera dati base articoli (nome, codice, ubicazione, correlati)
    $ids_articoli = [];
    foreach ( $articoli as $a ) {
        if ( ! empty( $a['id'] ) ) {
            $ids_articoli[] = intval( $a['id'] );
        }
    }
    $ids_articoli = array_values( array_unique( array_filter( $ids_articoli ) ) );

    $mappa_articoli = [];
    if ( ! empty( $ids_articoli ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );
        $sql_art = "SELECT id, codice, nome, ubicazione, correlati
                    FROM $table_articoli
                    WHERE id IN ($placeholders)";
        $rows_art = $wpdb->get_results( $wpdb->prepare( $sql_art, $ids_articoli ) );
        foreach ( $rows_art as $r ) {
            $mappa_articoli[ intval( $r->id ) ] = [
                'codice'     => $r->codice,
                'nome'       => $r->nome,
                'ubicazione' => $r->ubicazione,
                'correlati'  => $r->correlati,
            ];
        }
    }

    $current_user  = wp_get_current_user();
    $op_default    = $current_user ? $current_user->user_login : '';

    echo '<h1>Rientro noleggio #' . esc_html( $id_noleggio ) . '</h1>';

    // Blocco info noleggio
    echo '<h2>Dati noleggio</h2>';
    echo '<p><strong>Cliente:</strong> ' . esc_html( $n->cliente_nome ) . '</p>';
    echo '<p><strong>Periodo di utilizzo:</strong> ' . esc_html( $periodo_utilizzo['inizio_it'] ) . ' / ' . esc_html( $periodo_utilizzo['fine_it'] ) . '</p>';
    echo '<p><strong>Periodo di ritiro/riconsegna:</strong> ' . esc_html( $periodo_logistico['ritiro_effettivo_it'] ) . ' / ' . esc_html( $periodo_logistico['riconsegna_effettiva_it'] ) . '</p>';
    if ( $n->stato === 'ritardo' ) {
        echo '<p style="color:#b00;"><strong>Stato:</strong> In ritardo (rientro tardivo)</p>';
    } else {
        echo '<p><strong>Stato:</strong> ' . esc_html( $n->stato ) . '</p>';
    }
    echo '<p><strong>Note:</strong> ' . nl2br( esc_html( $n->note ) ) . '</p>';

    // Campo operatore rientro
    echo '<h2>Rientro materiali</h2>';
    echo '<p>';
    echo '<label for="bsn-op-rientro">Operatore rientro materiali:</label> ';
    echo '<input type="text" id="bsn-op-rientro" value="' . esc_attr( $op_default ) . '" style="width: 250px;">';
    echo '</p>';

    // Tabella articoli
    echo '<table class="widefat striped bsn-tabella" id="bsn-tabella-rientro-articoli">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Articolo</th>';
    echo '<th>Qty noleggiata</th>';
    echo '<th>Qty rientrata</th>';
    echo '<th>Esito rientro</th>';
    echo '<th>Note</th>';
    echo '<th>Correlati</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    if ( empty( $articoli ) ) {
        echo '<tr><td colspan="6">Nessun articolo associato a questo noleggio.</td></tr>';
    } else {
        foreach ( $articoli as $riga ) {
            $art_id = isset( $riga['id'] ) ? intval( $riga['id'] ) : 0;
            $qty    = isset( $riga['qty'] ) ? intval( $riga['qty'] ) : 0;

            $nome      = '';
            $codice    = '';
            $correlati = '';
            if ( isset( $mappa_articoli[ $art_id ] ) ) {
                $nome   = $mappa_articoli[ $art_id ]['nome'];
                $codice = $mappa_articoli[ $art_id ]['codice'];
            }
            $correlati_raw = '';
            if ( isset( $mappa_articoli[ $art_id ]['correlati'] ) ) {
                $correlati_raw = $mappa_articoli[ $art_id ]['correlati'];
            } elseif ( isset( $riga['correlati'] ) && is_array( $riga['correlati'] ) ) {
                $correlati_raw = wp_json_encode( $riga['correlati'] );
            }

            if ( $correlati_raw ) {
                $decoded_corr = json_decode( $correlati_raw, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_corr ) && ! empty( $decoded_corr ) ) {
                    $corr_labels = array_map(
                        function( $c ) use ( $qty ) {
                            $nome_corr = isset( $c['nome'] ) ? $c['nome'] : '';
                            $qty_corr  = isset( $c['qty'] ) ? intval( $c['qty'] ) : 1;
                            return ( $qty_corr * $qty ) . 'x ' . $nome_corr;
                        },
                        $decoded_corr
                    );
                    $correlati = implode( ', ', $corr_labels );
                }
            }

            echo '<tr data-articolo-id="' . esc_attr( $art_id ) . '">';
            echo '<td>';
            echo esc_html( $nome );
            if ( $codice ) {
                echo '<br><small>Codice: ' . esc_html( $codice ) . '</small>';
            }
            echo '</td>';
            echo '<td>' . esc_html( $qty ) . '</td>';

            // Qty rientrata
            echo '<td style="text-align:center;">';
            echo '<input type="number" class="bsn-qty-rientrata" min="0" max="' . esc_attr( $qty ) . '" value="' . esc_attr( $qty ) . '" style="width:70px;">';
            echo '</td>';

            // Select esito rientro
            echo '<td>';
            echo '<select class="bsn-stato-rientro">';
            echo '<option value="ok">OK</option>';
            echo '<option value="ticket">Ticket (anomalia)</option>';
            echo '</select>';
            echo '</td>';

            // Note
            echo '<td>';
            echo '<input type="text" class="bsn-note-rientro" style="width: 100%;">';
            echo '</td>';

            echo '<td>' . esc_html( $correlati ? $correlati : '-' ) . '</td>';

            echo '</tr>';
        }
    }

    echo '</tbody>';
    echo '</table>';

        // Pulsante salvataggio
    echo '<p style="margin-top:20px;">';
    echo '<button class="button button-primary" id="bsn-salva-rientro">Salva Rientro e Chiudi Noleggio</button>';
    echo '</p>';

    // JS inline per salvataggio rientro
    ?>
    <script>
    (function(){
        const btn = document.getElementById('bsn-salva-rientro');
        if (!btn) return;

        btn.addEventListener('click', function(e){
            e.preventDefault();

            const idNoleggio = '<?php echo esc_js( $id_noleggio ); ?>';
            const opInput = document.getElementById('bsn-op-rientro');
            const opRientro = opInput ? opInput.value.trim() : '';

            if (!opRientro) {
                alert('Inserisci l\'operatore rientro materiali.');
                return;
            }

            const rows = document.querySelectorAll('#bsn-tabella-rientro-articoli tbody tr');
            const articoli = [];
            let validRows = 0;
            let hasError = false;

            rows.forEach(function(row){
                const articoloId = row.getAttribute('data-articolo-id');
                const qtyCell = row.querySelector('td:nth-child(2)');
                const qty = qtyCell ? parseInt(qtyCell.textContent.trim(), 10) || 0 : 0;
                const qtyRientroInput = row.querySelector('.bsn-qty-rientrata');
                const selStato = row.querySelector('.bsn-stato-rientro');
                const inpNote = row.querySelector('.bsn-note-rientro');

                const qtyRientrata = qtyRientroInput ? parseInt(qtyRientroInput.value, 10) || 0 : 0;
                const statoRientro = selStato ? selStato.value : 'ok';
                const note = inpNote ? inpNote.value.trim() : '';

                if (qtyRientrata < 0 || qtyRientrata > qty) {
                    alert('La quantità rientrata deve essere tra 0 e ' + qty + '.');
                    hasError = true;
                    return;
                }

                if (qtyRientrata < qty && statoRientro === 'ok') {
                    alert('Se la quantità rientrata è inferiore, seleziona "Ticket (anomalia)".');
                    hasError = true;
                    return;
                }

                if (statoRientro === 'ticket' && !note) {
                    alert('Inserisci una nota per ogni articolo con Ticket.');
                    hasError = true;
                    return;
                }

                articoli.push({
                    articolo_id: articoloId,
                    qty: qty,
                    qty_rientrata: qtyRientrata,
                    stato_rientro: statoRientro,
                    note: note
                });

                validRows++;
            });

            if (hasError) {
                return;
            }
            if (!validRows) {
                alert('Nessun articolo valido da salvare.');
                return;
            }

            if (typeof BSN_API === 'undefined' || !BSN_API.root || !BSN_API.nonce) {
                alert('Configurazione API non disponibile.');
                return;
            }

            const payload = {
                id_noleggio: idNoleggio,
                op_rientro: opRientro,
                articoli: articoli
            };

            fetch(BSN_API.root + 'noleggi/rientro', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': BSN_API.nonce
                },
                body: JSON.stringify(payload)
            })
            .then(function(res){
                if (!res.ok) {
                    return res.json().then(function(err){
                        throw err;
                    }).catch(function(){
                        throw { message: 'Errore sconosciuto (' + res.status + ')' };
                    });
                }
                return res.json();
            })
            .then(function(data){
                if (data && data.ticket_count && parseInt(data.ticket_count, 10) > 0) {
                    alert('Rientro salvato e ticket creati.');
                } else {
                    alert('Rientro salvato e noleggio chiuso.');
                }
                window.location.href = '<?php echo esc_js( home_url( '/app-noleggi/' ) ); ?>';
            })
            .catch(function(err){
                if (err && err.message) {
                    alert(err.message);
                } else {
                    alert('Errore durante il salvataggio del rientro.');
                }
            });
        });
    })();
    </script>
    <?php

    echo '</div>';
}

function bsn_admin_page() {
    echo '<h1>Black Star Noleggi Admin</h1>';
    echo '<p>Usa lo shortcode <code>[blackstar_noleggi]</code> nelle pagine.</p>';
}

/**
 * Shortcode [blackstar_noleggi]
 */
add_shortcode('blackstar_noleggi', 'bsn_shortcode');
function bsn_shortcode() {

    // === FORCE NO CACHE ===
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    }

    // === STEP A3: anche il frontend dell'app usa bsn_check_admin ===
    if ( ! bsn_check_admin() ) {
        return "\n\nAccesso negato. Permesso riservato agli operatori autorizzati.\n\n";
    }

$current_user = wp_get_current_user();
    $user_label = ( $current_user && $current_user->exists() ) ? $current_user->display_name : '';
    $public_products_link_data = bsn_get_public_products_for_linking();
    $inventory_mode_options = bsn_get_articolo_inventory_mode_options();
    $veicolo_minimo_options = bsn_get_articolo_veicolo_minimo_options();
    $stato_utilizzabilita_options = bsn_get_articolo_stato_utilizzabilita_options();

    ob_start();
    ?>
    <div id="bsn-app" style="max-width:1200px; margin:20px auto;">
        <script>
            window.BSN_PUBLIC_PRODUCTS = <?php echo wp_json_encode( $public_products_link_data ); ?>;
        </script>
        <div class="bsn-app-header">
            <h2>Black Star Rental Management</h2>
            <?php if ( ! empty( $user_label ) ) : ?>
                <span class="bsn-user-badge">👤 <?php echo esc_html( $user_label ); ?></span>
            <?php endif; ?>
        </div>

        <div id="bsn-tabs">
            <button class="tab-btn active" data-tab="clienti">Clienti</button>
            <button class="tab-btn" data-tab="articoli">Articoli</button>
            <button class="tab-btn" data-tab="noleggi">Noleggi</button>
            <button class="tab-btn" data-tab="calendario">Calendario</button>
            <button class="tab-btn" data-tab="ticket">Ticket</button>
            <button class="tab-btn" data-tab="etichette">Etichette</button>
        </div>

        <div id="bsn-content">

    <!-- CLIENTI -->
        <div id="clienti" class="tab-content active">
        <h3>Modulo Clienti</h3>
        <form id="bsn-form-cliente">
            <input type="hidden" name="cliente_id" id="bsn-cliente-id" value="">
            
            <!-- RIGA 1: Nome + CF/P.IVA + Categoria -->
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 2;">
                    <label>Nome e cognome / Ragione sociale *</label>
                    <input type="text" name="nome" required style="width:100%;">
                </div>
                <div style="flex: 1;">
                    <label>CF / P.IVA *</label>
                    <input type="text" name="cf_piva" required style="width:100%;">
                </div>
                <div style="flex: 0 0 180px;">
                    <label>Categoria cliente</label>
                    <select name="categoria_cliente" style="width:100%;">
                        <option value="standard">Standard</option>
                        <option value="fidato">Fidato</option>
                        <option value="premium">Premium</option>
                        <option value="service">Service</option>
                        <option value="collaboratori">Collaboratori</option>
                    </select>
                </div>
            </div>

            <!-- RIGA 2: Telefono + Email -->
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 1;">
                    <label>Telefono *</label>
                    <input type="text" name="telefono" required style="width:100%;">
                </div>
                <div style="flex: 1;">
                    <label>Email *</label>
                    <input type="email" name="email" required style="width:100%;">
                </div>
            </div>

            <!-- RIGA 3: Indirizzo + CAP + Città -->
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 2;">
                    <label>Indirizzo</label>
                    <input type="text" name="indirizzo" placeholder="Via e numero civico" style="width:100%;">
                </div>
                <div style="flex: 0 0 100px;">
                    <label>CAP</label>
                    <input type="text" name="cap" style="width:100%;">
                </div>
                <div style="flex: 1;">
                    <label>Città</label>
                    <input type="text" name="citta" style="width:100%;">
                </div>
            </div>

            <!-- RIGA 4: Regime fiscale % + Note -->
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 0 0 150px;">
                    <label>Regime fiscale (%)</label>
                    <input type="number" step="0.01" name="regime_percentuale" value="22.00" style="width:100%;">
                </div>
                <div style="flex: 1;">
                    <label>Regime fiscale - note</label>
                    <input type="text" name="regime_note" placeholder="es. esente art. 10, reverse charge" style="width:100%;">
                </div>
            </div>

            <!-- RIGA 5: Tipo documento + Numero documento -->
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 0 0 200px;">
                    <label>Tipo documento</label>
                    <select name="tipo_documento" style="width:100%;">
                        <option value="">Seleziona...</option>
                        <option value="carta_identita">Carta d'Identità</option>
                        <option value="patente">Patente di guida</option>
                        <option value="passaporto">Passaporto</option>
                        <option value="permesso_soggiorno">Permesso di soggiorno</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label>Numero documento</label>
                    <input type="text" name="numero_documento" placeholder="Es. CA12345678" style="width:100%;">
                </div>
            </div>

            <!-- RIGA 6: Documento fronte + retro -->
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 1;">
                    <label>Documento identità (fronte)</label>
                    <input type="file" id="bsn-doc-fronte-file" accept="image/*" style="width:100%;">
                    <input type="hidden" name="doc_fronte" id="bsn-doc-fronte-path">
                    <div id="bsn-doc-fronte-preview" style="margin-top:5px;"></div>
                    <button type="button" id="bsn-doc-fronte-webcam-start" class="btn btn-secondary" style="margin-top:5px;">📷 Apri webcam (fronte)</button>
                    <button type="button" id="bsn-doc-fronte-webcam-capture" class="btn btn-primary" style="display:none; margin-top:5px;">Scatta fronte</button>
                    <div id="bsn-doc-fronte-webcam-area" style="display:none; margin-top:10px;">
                        <video id="bsn-doc-fronte-video" width="320" height="240" autoplay style="border:1px solid #ccc;"></video>
                        <canvas id="bsn-doc-fronte-canvas" style="display:none;"></canvas>
                    </div>
                </div>
                <div style="flex: 1;">
                    <label>Documento identità (retro)</label>
                    <input type="file" id="bsn-doc-retro-file" accept="image/*" style="width:100%;">
                    <input type="hidden" name="doc_retro" id="bsn-doc-retro-path">
                    <div id="bsn-doc-retro-preview" style="margin-top:5px;"></div>
                    <button type="button" id="bsn-doc-retro-webcam-start" class="btn btn-secondary" style="margin-top:5px;">📷 Apri webcam (retro)</button>
                    <button type="button" id="bsn-doc-retro-webcam-capture" class="btn btn-primary" style="display:none; margin-top:5px;">Scatta retro</button>
                    <div id="bsn-doc-retro-webcam-area" style="display:none; margin-top:10px;">
                        <video id="bsn-doc-retro-video" width="320" height="240" autoplay style="border:1px solid #ccc;"></video>
                        <canvas id="bsn-doc-retro-canvas" style="display:none;"></canvas>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Note cliente</label>
                <textarea name="note" rows="3" style="width:100%;"></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Salva Cliente</button>
        </form>

                <!-- Ricerca + lista clienti -->

                <div class="bsn-list-toolbar" style="margin-top:20px;">
                    <input type="text" id="bsn-clienti-search" placeholder="Cerca nei clienti..." style="width:100%; max-width:360px; padding:5px;">
                    <label for="bsn-clienti-limit">Mostra:</label>
                    <select id="bsn-clienti-limit">
                        <option value="12" selected>12</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>

                <div id="bsn-lista-clienti" style="margin-top:10px;"></div>
            </div>

           <!-- ARTICOLI -->
            <div id="articoli" class="tab-content">
                <h3>Modulo Articoli (base)</h3>
                <form id="bsn-form-articolo">

                    <!-- RIGA 1: Nome articolo + Codice -->
                    <div style="display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap;">
                        <div style="flex:2 1 250px;">
                            <label>Nome articolo</label>
                            <input type="text" name="nome" required style="width:100%;">
                        </div>
                        <div style="flex:1 1 180px;">
                            <label>Codice (es. CDJ2000#1)</label>
                            <input type="text" name="codice" required style="width:100%;">
                        </div>
                    </div>

                    <!-- RIGA 2: Prezzo giorno + Valore del bene + Quantità -->
                    <div style="display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap;">
                        <div style="flex:1 1 180px;">
                            <label>Prezzo per giorno (€)</label>
                            <input type="number" step="0.01" name="prezzo_giorno" required style="width:100%;">
                        </div>
                        <div style="flex:1 1 180px;">
                            <label>Valore del bene (€)</label>
                            <input type="number" step="0.01" name="valore_bene" value="0.00" style="width:100%;">
                        </div>
                        <div style="flex:1 1 140px;">
                            <label>Quantità disponibile</label>
                            <input type="number" name="qty_disponibile" value="1" min="0" style="width:100%;">
                        </div>
                    </div>

                    <div style="display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap;">
                        <div style="flex:1 1 220px;">
                            <label>Modalita inventario</label>
                            <select name="inventory_mode" style="width:100%;">
                                <?php foreach ( $inventory_mode_options as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="display:block; color:#666; margin-top:4px;">
                                Suggerita in automatico da quantita disponibile, ma sempre modificabile.
                            </small>
                        </div>
                        <div style="flex:1 1 260px; position:relative;">
                            <label>Prodotto pubblico collegato</label>
                            <input type="hidden" name="prodotto_pubblico_id" value="">
                            <input type="text" id="bsn-prodotto-pubblico-search" placeholder="Cerca prodotto pubblico per nome o slug..." autocomplete="off" style="width:100%;">
                            <div id="bsn-prodotto-pubblico-risultati" style="border:1px solid #ccc; max-height:180px; overflow-y:auto; display:none; background:#fff; position:absolute; z-index:20; width:100%;"></div>
                            <small style="display:block; color:#666; margin-top:4px;">
                                Cerca il prodotto pubblico e collegalo all'articolo senza usare una tendina lunga.
                            </small>
                            <div id="bsn-prodotto-pubblico-logistica" style="margin-top:8px; padding:8px; border:1px solid #d9dee5; background:#f8fafc; border-radius:6px;">
                                Nessun prodotto pubblico collegato.
                            </div>
                        </div>
                        <div style="flex:1 1 160px;">
                            <label>Quantita minima noleggiabile</label>
                            <input type="number" name="min_qty" value="1" min="1" style="width:100%;">
                        </div>
                    </div>

                    <div style="display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap;">
                        <div style="flex:1 1 180px;">
                            <label>Stato base articolo</label>
                            <select name="stato_utilizzabilita" style="width:100%;">
                                <?php foreach ( $stato_utilizzabilita_options as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1 1 180px;">
                            <label>Veicolo minimo</label>
                            <select name="veicolo_minimo" style="width:100%;">
                                <?php foreach ( $veicolo_minimo_options as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1 1 140px;">
                            <label>Larghezza (cm)</label>
                            <input type="number" step="0.01" min="0" name="larghezza_cm" style="width:100%;">
                        </div>
                        <div style="flex:1 1 140px;">
                            <label>Altezza (cm)</label>
                            <input type="number" step="0.01" min="0" name="altezza_cm" style="width:100%;">
                        </div>
                        <div style="flex:1 1 140px;">
                            <label>Profondita (cm)</label>
                            <input type="number" step="0.01" min="0" name="profondita_cm" style="width:100%;">
                        </div>
                        <div style="flex:1 1 140px;">
                            <label>Peso (kg)</label>
                            <input type="number" step="0.01" min="0" name="peso_kg" style="width:100%;">
                        </div>
                    </div>

                    <!-- RIGA 3: Tutti gli sconti sulla stessa riga -->
                    <div style="display:flex; gap:10px; margin-bottom:15px; flex-wrap:wrap;">
                            <div style="flex:1 1 150px;">
                            <label style="font-size:0.9em;">Sconto STANDARD (%)</label>
                            <input type="number" step="0.01" name="sconto_standard" value="0" style="width:100%;">
                        </div>
                        <div style="flex:1 1 150px;">
                            <label style="font-size:0.9em;">Sconto FIDATO (%)</label>
                            <input type="number" step="0.01" name="sconto_fidato" value="0" style="width:100%;">
                        </div>
                        <div style="flex:1 1 150px;">
                            <label style="font-size:0.9em;">Sconto PREMIUM (%)</label>
                            <input type="number" step="0.01" name="sconto_premium" value="0" style="width:100%;">
                        </div>
                        <div style="flex:1 1 150px;">
                            <label style="font-size:0.9em;">Sconto SERVICE (%)</label>
                            <input type="number" step="0.01" name="sconto_service" value="0" style="width:100%;">
                        </div>
                        <div style="flex:1 1 150px;">
                            <label style="font-size:0.9em;">Sconto COLLABORATORI (%)</label>
                            <input type="number" step="0.01" name="sconto_collaboratori" value="0" style="width:100%;">
                        </div>
                    </div>

                    <div style="border:1px solid #d9dee5; background:#f8fafc; border-radius:6px; padding:12px; margin-bottom:15px;">
                        <label style="display:block; margin-bottom:10px;">
                            <input type="checkbox" name="qty_discount_enabled" value="1" style="margin-right:5px;">
                            Sconto lineare quantita attivo
                        </label>
                        <div style="display:flex; gap:12px; flex-wrap:wrap;">
                            <div style="flex:1 1 160px;">
                                <label>Quantita inizio sconto</label>
                                <input type="number" min="0" name="qty_discount_start_qty" value="" style="width:100%;">
                            </div>
                            <div style="flex:1 1 180px;">
                                <label>Quantita massima commerciale</label>
                                <input type="number" min="0" name="qty_discount_max_qty" value="" style="width:100%;">
                                <small style="display:block; color:#666; margin-top:4px;">Vuoto = usa quantita disponibile totale.</small>
                            </div>
                            <div style="flex:1 1 160px;">
                                <label>Sconto massimo (%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="qty_discount_max_pct" value="0" style="width:100%;">
                            </div>
                        </div>
                    </div>

                     <!-- Flag scalare + Profili Sconti sulla stessa riga -->
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; margin:10px 0;">
                        <!-- Checkbox noleggio scalare -->
                        <label style="margin:0; flex:0 0 auto;">
                            <input type="checkbox" name="noleggio_scalare" value="1" style="margin-right:5px;">
                            Noleggio scalare (usa √giorni invece del moltiplicatore lineare)
                        </label>

                        <!-- Profili Sconti -->
                        <div id="bsn-profili-sconti-box" style="display:flex; align-items:center; gap:8px; flex:1 1 auto; justify-content:flex-end; flex-wrap:wrap;">
                            <label for="bsn-profili-sconti-dropdown" style="margin:0; white-space:nowrap; font-weight:bold;">Profili Sconti:</label>
                            <select id="bsn-profili-sconti-dropdown" style="max-width:180px; padding:4px;"></select>
                            <button type="button" id="bsn-salva-profilo-btn" class="btn btn-primary" style="padding:4px 10px; font-size:13px; white-space:nowrap;">💾 Salva Profilo</button>
                            <button type="button" id="bsn-elimina-profilo-btn" class="btn" style="padding:4px 10px; font-size:13px; background:#dc3545; color:white; white-space:nowrap;">🗑 Elimina</button>
                            <span id="bsn-profili-sconti-messaggio" style="display:none; font-size:12px; color:green; margin-left:8px;"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Ubicazione magazzino (scaffale/colonna/ripiano)</label>
                        <input type="text" name="ubicazione">
                    </div>
                    <div class="form-group bsn-articolo-legacy-com-sync">
                        <details>
                            <summary style="cursor:pointer; font-weight:600;">Strumenti migrazione catalogo storico (.com)</summary>
                            <div style="margin-top:8px;">
                                <label>URL catalogo storico (.com)</label>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <input type="url" name="external_product_url" id="bsn-articolo-external-url" placeholder="https://blackstarservice.com/prodotto/nome-prodotto/" style="flex:1 1 320px;">
                                    <button type="button" class="btn btn-secondary" id="bsn-articolo-sync">Recupera dati prodotto</button>
                                </div>
                                <small style="display:block; color:#666; margin-top:4px;">
                                    Campo legacy per recuperi dal vecchio catalogo. Preventivi e PDF usano titolo, link e immagine del prodotto pubblico .it collegato, quando presente.
                                </small>
                                <div id="bsn-articolo-external-preview" style="margin-top:6px; font-size:12px; color:#333;"></div>
                            </div>
                        </details>
                        <input type="hidden" name="external_product_slug" id="bsn-articolo-external-slug">
                        <input type="hidden" name="external_product_name" id="bsn-articolo-external-name">
                        <input type="hidden" name="external_image_url" id="bsn-articolo-external-image">
                        <input type="hidden" name="external_last_sync" id="bsn-articolo-external-sync">
                    </div>
                    <div class="form-group">
                        <label>Note</label>
                        <textarea name="note"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Note logistiche</label>
                        <textarea name="note_logistiche" rows="3" placeholder="Trasporto, ingombri, movimentazione, accorgimenti operativi..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Correlati (es. cavi, accessori, hardware obbligatorio)</label>
                        <div id="bsn-correlati-wrapper">
                            <div class="bsn-correlato-row">
                                <input type="text" name="correlato_nome[]" placeholder="Nome correlato (es. Cavo alimentazione)">
                                <input type="number" name="correlato_qty[]" placeholder="Q.tà" min="1" value="1" style="max-width:80px;">
                                <button type="button" class="btn bsn-correlato-remove">X</button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary" id="bsn-correlato-add">+ Aggiungi correlato</button>
                        <p style="font-size:0.85em; color:#666; margin-top:5px;">
                            Esempi: CDJ → 1 cavo alimentazione, 1 RCA, 1 ethernet. Truss → 4 ovetti, 8 spine, 8 coppiglie.
                        </p>
                    </div>

                    <!-- Pulsante salvataggio articolo -->
                    <button type="submit" class="btn btn-primary">Salva Articolo</button>

                    <!-- Campo ricerca articoli (sotto Salva, prima della lista) -->
                     <div class="bsn-list-toolbar" style="margin-top:10px;">
                        <input type="text"
                               id="bsn-articoli-search"
                               placeholder="Cerca negli articoli (ID, nome, codice, prodotto pubblico, note, ubicazione)..."
                               style="width:100%; max-width:380px; padding:5px;">
                        <label for="bsn-articoli-limit">Mostra:</label>
                        <select id="bsn-articoli-limit">
                            <option value="12" selected>12</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <button type="button" class="btn btn-secondary" id="bsn-articoli-magazzino-export">Esporta CSV magazzino</button>
                        <button type="button" class="btn btn-secondary" id="bsn-articoli-magazzino-print">Stampa elenco magazzino</button>
                    </div>

                </form>

                <!-- Lista articoli -->
                <div id="bsn-lista-articoli" style="margin-top:20px;"></div>

                <!-- KIT -->
                <div id="bsn-kit-section" style="margin-top:30px; padding:15px; border:1px solid #e0e0e0; background:#f9fafb; border-radius:8px;">
                    <h3 style="margin-top:0;">Crea Kit</h3>
                    <form id="bsn-form-kit">
                        <input type="hidden" name="kit_id" id="bsn-kit-id" value="">
                        <p style="margin-top:0; font-size:12px; color:#555;">
                            Il Kit è un articolo “contenitore” con prezzo fisso. L’articolo KIT è l’articolo base
                            che cerchi nel noleggio, mentre i componenti sono gli articoli reali che lo compongono.
                        </p>
                        <div style="display:flex; gap:15px; flex-wrap:wrap; margin-bottom:10px;">
                            <div style="flex:2 1 240px;">
                                <label>Nome kit</label>
                                <input type="text" name="nome_kit" required style="width:100%;">
                                <small style="display:block; color:#666; margin-top:4px;">
                                    Etichetta del kit (es. “Kit Top Pro”) visualizzata nelle schermate di noleggio.
                                </small>
                            </div>
                            <div style="flex:2 1 240px;">
                                <label>Articolo KIT (articolo base con prezzo)</label>
                                <select id="bsn-kit-articolo-id" name="articolo_kit_id" required style="width:100%;"></select>
                                <small style="display:block; color:#666; margin-top:4px;">
                                    Questo è l’articolo “contenitore” con prezzo fisso. Le sue note restano le note
                                    dell’articolo, mentre le note del kit sotto sono note specifiche del kit.
                                </small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Note</label>
                            <textarea name="note" rows="2" style="width:100%;"></textarea>
                            <small style="display:block; color:#666; margin-top:4px;">
                                Note interne del kit (diverse dalle note dell’articolo base).
                            </small>
                        </div>

                        <div>
                            <label>Componenti del kit</label>
                            <small style="display:block; color:#666; margin:4px 0 8px;">
                                <strong>Ruolo</strong> = funzione del componente (es. TOP, SUB, CDJ).<br>
                                <strong>Gruppo equivalenza</strong> = famiglia/modello per limitare le sostituzioni
                                (es. TOP_PRO, SUB_PRO). Solo articoli con lo stesso gruppo saranno selezionabili
                                in sostituzione.<br>
                                <strong>Default</strong> = componente precompilato quando aggiungi il kit.<br>
                                <strong>Selezionabile</strong> = permette la sostituzione tramite dropdown; se disattivo
                                rimane fisso.
                            </small>
                            <div id="bsn-kit-componenti-wrapper"></div>
                            <button type="button" class="btn btn-secondary" id="bsn-kit-componente-add">+ Aggiungi componente</button>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top:10px;">Salva Kit</button>
                    </form>

                    <div id="bsn-lista-kits" style="margin-top:20px;"></div>
                </div>
            </div>

            <!-- MODAL QR CODE -->
<div id="bsn-modal-qr" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:none; justify-content:center; align-items:center;">
    <div style="background:white; padding:20px; border-radius:10px; text-align:center;">
        <h3 style="margin-top:0; margin-bottom:15px;">Codice QR Articolo</h3>

        <!-- BOX FISSO 180x180 -->
        <div id="bsn-qr-box">
            <div id="bsn-qr-container"></div>
        </div>

        <div id="bsn-qr-code-text" style="margin-top:10px; font-size:14px; font-weight:bold; color:#333;"></div>

        <div style="margin-top:15px;">
            <button type="button" id="bsn-qr-print" class="btn btn-primary" style="margin-right:10px;">🖨️ Stampa QR</button>
            <button type="button" id="bsn-qr-close" class="btn btn-secondary">Chiudi</button>
        </div>
    </div>
</div>



                <!-- ETICHETTE -->
            <div id="etichette" class="tab-content">
                <h2>Etichette</h2>
                <div class="bsn-list-toolbar" style="margin-bottom:10px;">
                    <input type="text" id="bsn-etichette-search" placeholder="Cerca articolo per ID, nome o codice..." style="width:100%; max-width:360px; padding:5px;">
                    <label for="bsn-etichette-limit">Mostra:</label>
                    <select id="bsn-etichette-limit">
                        <option value="12" selected>12</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <label style="display:flex; align-items:center; gap:4px;">
                        <input type="checkbox" id="bsn-etichette-only-unprinted" value="1"> Solo NON stampati
                    </label>
                    <button type="button" class="btn btn-secondary" id="bsn-etichette-export">Scarica CSV (selezionati)</button>
                    <button type="button" class="btn btn-secondary" id="bsn-etichette-mark-printed">Segna come stampati</button>
                    <button type="button" class="btn btn-secondary" id="bsn-etichette-mark-unprinted">Segna come NON stampati</button>
                    <button type="button" class="btn btn-secondary" id="bsn-etichette-clear-selection">Svuota selezione</button>
                </div>
                <div id="bsn-lista-etichette"><p>Nessun articolo caricato.</p></div>
            </div>

            <!-- NOLEGGI -->
            <div id="noleggi" class="tab-content">
                <h2>Noleggi</h2>

                            <!-- Form nuovo noleggio -->
            <form id="bsn-form-noleggio" style="margin-bottom:20px;">
                <!-- ID noleggio per modalità "Modifica" -->
                <input type="hidden" id="bsn-noleggio-id" name="noleggio_id" value="">
                
                <div class="form-group">
                    <label>Cliente / Intestatario noleggio</label>
                    <input type="text" id="bsn-noleggio-cliente-search" placeholder="Digita il nome del cliente..." autocomplete="off">
                    <input type="hidden" name="cliente_id" id="bsn-noleggio-cliente-id" required>
                    <div id="bsn-noleggio-clienti-risultati" class="bsn-risultati-clienti" style="border:1px solid #ccc; max-height:150px; overflow-y:auto; display:none; background:#fff; position:relative; z-index:10;"></div>
                </div>

                <!-- Incaricato al ritiro: riga compatta inline, si espande solo se flaggata -->
                <div class="form-group" style="margin-top:6px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <label style="font-weight:normal; display:flex; align-items:center; gap:5px; cursor:pointer; font-size:12px; color:#666; white-space:nowrap; margin:0;">
                        <input type="checkbox" id="bsn-noleggio-ha-incaricato" style="margin:0; cursor:pointer;">
                        Ritiro a cura di un incaricato diverso
                    </label>
                    <div id="bsn-noleggio-incaricato-wrapper" style="display:none; flex:1; min-width:200px; position:relative;">
                        <input type="text" id="bsn-noleggio-incaricato-search" placeholder="Cerca incaricato (nome o CF)..." autocomplete="off" style="width:100%; font-size:12px; padding:4px 6px;">
                        <input type="hidden" name="incaricato_ritiro_cliente_id" id="bsn-noleggio-incaricato-id">
                        <div id="bsn-noleggio-incaricato-risultati" class="bsn-risultati-clienti" style="border:1px solid #ccc; max-height:150px; overflow-y:auto; display:none; background:#fff; position:absolute; z-index:20; width:100%;"></div>
                    </div>
                </div>

                <div class="bsn-periodi-grid">
                    <div class="bsn-periodo-box">
                        <h4>Periodo di utilizzo</h4>
                        <div class="bsn-periodo-row">
                            <div style="flex:1 1 150px; min-width:140px;">
                                <label>Data inizio noleggio</label>
                                <input type="date" name="data_inizio" required style="width:100%;">
                            </div>
                            <div style="flex:1 1 150px; min-width:140px;">
                                <label>Data termine noleggio</label>
                                <input type="date" name="data_fine" required style="width:100%;">
                            </div>
                        </div>
                        <small style="display:block; color:#666; margin-top:4px;">Questo periodo determina giorni, prezzo, sconti e totale economico.</small>
                    </div>
                    <div class="bsn-periodo-box">
                        <h4>Periodo di ritiro/riconsegna</h4>
                        <div class="bsn-periodo-row">
                            <div style="flex:1 1 150px; min-width:140px;">
                                <label>Data ritiro</label>
                                <input type="date" name="data_ritiro" style="width:100%;">
                            </div>
                            <div style="flex:1 1 150px; min-width:140px;">
                                <label>Data riconsegna</label>
                                <input type="date" name="data_riconsegna" style="width:100%;">
                            </div>
                        </div>
                        <small style="display:block; color:#666; margin-top:4px;">Questo periodo determina disponibilità materiale, calendario e ritardi.</small>
                    </div>
                </div>

                <div class="form-group">
                    <!-- Stato noleggio/preventivo, gestito dai pulsanti -->
                    <input type="hidden" name="stato" value="bozza">
                </div>

                <!-- Campi logistica/trasporto - RIGA 1 -->
                <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label>Luogo di destinazione</label>
                        <input type="text" name="luogo_destinazione" placeholder="Es. Teatro Grande, Brescia" style="width:100%;">
                    </div>
                    <div style="flex: 1;">
                        <label>Trasporto a mezzo</label>
                        <input type="text" name="trasporto_mezzo" placeholder="Es. Mezzo proprio cliente, furgone Black Star, corriere..." style="width:100%;">
                    </div>
                </div>

                <!-- Campi logistica/trasporto - RIGA 2 -->
                <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label>Cauzione</label>
                        <input type="text" name="cauzione" placeholder="Es. € 500 contanti, documento in originale, carta di credito..." style="width:100%;">
                    </div>
                    <div style="flex: 1;">
                        <label>Causale di trasporto</label>
                        <input type="text" name="causale_trasporto" placeholder="Es. Noleggio materiale audio per evento, prova tecnica, ecc." style="width:100%;">
                    </div>
                </div>

                <div class="form-group">
                    <label>Metodo di pagamento</label>
                    <input type="text" name="metodo_pagamento" placeholder="Es. Bonifico, carta, contanti" style="width:100%;">
                </div>

                <div class="form-group">
                    <label>Note</label>
                    <textarea name="note"></textarea>
                </div>

                <div class="form-group">
                    <label>Articoli nel noleggio</label>
                    <div id="bsn-noleggio-articoli-wrapper">
                        <!-- Prima riga di esempio, clonata via JS -->
                           <div class="bsn-noleggio-articolo-row">
                                <input type="text" class="bsn-noleggio-articolo-search" placeholder="Cerca articolo..." autocomplete="off" style="flex:1 1 200px; min-width:150px;">
                                <input type="hidden" class="bsn-noleggio-articolo-id" name="articoli_id[]">
                                <input type="number" class="bsn-noleggio-articolo-prezzo" name="articoli_prezzo[]" min="0" step="0.01" placeholder="Prezzo" style="max-width:90px;">
                                <input type="number" class="bsn-noleggio-articolo-qty" name="articoli_qty[]" min="1" value="1" style="max-width:60px;">
                                <button type="button" class="btn bsn-noleggio-articolo-remove">X</button>

                            <div class="bsn-noleggio-articoli-risultati" style="border:1px solid #ccc; max-height:150px; overflow-y:auto; display:none; background:#fff; position:relative; z-index:10;"></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary" id="bsn-noleggio-articolo-add">+ Aggiungi articolo</button>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="bsn-noleggio-consenti-overbook" name="consenti_overbook" value="1">
                        Consenti comunque il superamento della disponibilità (overbooking)
                    </label>
                </div>

                <!-- Anteprima Noleggio -->
                <div id="bsn-noleggio-preview" style="display:none; margin-top:25px; padding:20px; border:2px solid #0073aa; background:#f0f8ff; border-radius:8px;">
                    <h4 style="margin-top:0; color:#0073aa;">Anteprima Noleggio</h4>
                    
                    <p><strong>Cliente / Intestatario:</strong> <span id="bsn-preview-cliente-nome">-</span></p>
                    <p><strong>CF/P.IVA:</strong> <span id="bsn-preview-cliente-cf">-</span></p>
                    <p><strong>Categoria:</strong> <span id="bsn-preview-cliente-categoria">-</span></p>
                    <p id="bsn-preview-incaricato-row" style="display:none; color:#b00; font-size:12px;"><strong>Incaricato al ritiro:</strong> <span id="bsn-preview-incaricato"></span></p>
                    <p><strong>Periodo di utilizzo:</strong> <span id="bsn-preview-data-da">-</span> / <span id="bsn-preview-data-a">-</span> (<span id="bsn-preview-giorni">-</span> giorni)</p>
                    <p><strong>Periodo di ritiro/riconsegna:</strong> <span id="bsn-preview-data-ritiro">-</span> / <span id="bsn-preview-data-riconsegna">-</span></p>
                    
                    <p id="bsn-preview-note-cliente" style="display:none;">
                        <strong>Note cliente:</strong> <span id="bsn-preview-cliente-note">-</span>
                    </p>
                    
                    <div id="bsn-preview-logistica" style="display:none; margin:15px 0; padding:10px; background:#fff; border-left:3px solid #0073aa;">
                        <p><strong>Luogo destinazione:</strong> <span id="bsn-preview-luogo-dest">-</span></p>
                        <p><strong>Trasporto a mezzo:</strong> <span id="bsn-preview-trasporto">-</span></p>
                        <p><strong>Cauzione:</strong> <span id="bsn-preview-cauzione">-</span></p>
                        <p><strong>Causale trasporto:</strong> <span id="bsn-preview-causale">-</span></p>
                    </div>
                    
                    <div class="bsn-preview-articoli-table-wrap" style="margin:15px 0;">
                        <table class="bsn-preview-table" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#0073aa; color:#fff;">
                                    <th style="padding:8px; text-align:left;">Articolo</th>
                                    <th style="padding:8px; text-align:right;">Prezzo</th>
                                    <th style="padding:8px; text-align:center;">Qty</th>
                                    <th style="padding:8px; text-align:center;">Fattore</th>
                                    <th style="padding:8px; text-align:center;">Sconto</th>
                                    <th style="padding:8px; text-align:right;">Subtotale imponibile</th>
                                    <th style="padding:8px; text-align:left;">Ubicazione</th>
                                    <th style="padding:8px; text-align:left;">Correlati</th>
                                    <th class="bsn-preview-stato-col" style="padding:8px; text-align:center;">!</th>
                                </tr>
                            </thead>
                            <tbody id="bsn-preview-articoli-tbody">
                                <!-- Popolato da JS -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div id="bsn-preview-dettagli-articoli"></div>
                    <div id="bsn-preview-kit-componenti" style="margin-top:15px;"></div>
                    
                    <p><strong>Note:</strong></p>
                    <p><strong>Subtotale imponibile:</strong> <span id="bsn-preview-subtotale">€ 0,00</span></p>
                    <p><strong>IVA/Regime (<span id="bsn-preview-regime-perc">0</span>%):</strong> <span id="bsn-preview-regime-importo">€ 0,00</span></p>
                    <p><strong>Totale ivato:</strong> <span id="bsn-preview-totale">€ 0,00</span></p>
                    
                    <p id="bsn-preview-sconto-block" style="display:none;">
                        <strong>Sconto:</strong> <span id="bsn-preview-sconto">€ 0,00</span>
                    </p>
                    
                    <p style="font-size:1.3em; font-weight:bold; color:#0073aa;">
                        <strong>TOTALE FINALE:</strong> <span id="bsn-preview-totale-finale">€ 0,00</span>
                    </p>
                </div><!-- fine preview -->

                    <div class="form-group">
                        <label>Sconto globale (in €, es. -50 o +20)</label>
                        <input type="number" step="0.01" name="sconto_globale" id="bsn-noleggio-sconto-globale" placeholder="0.00" style="max-width:120px;">
                        <small style="display:block; color:#666; margin-top:3px;">Valori negativi riducono il totale, positivi lo aumentano.</small>
                    </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                    <button type="button" class="btn btn-warning bsn-save-preventivo" data-stato="preventivo">Salva Preventivo</button>
                    <button type="button" class="btn btn-primary bsn-save-noleggio" data-stato="bozza">Salva Noleggio</button>
                </div>
            </form>

                <!-- Warning sovrapposizioni -->
                <div id="bsn-noleggi-warning" style="display:none; margin-bottom:15px; padding:10px; border:1px solid #f5c6cb; background:#f8d7da; color:#721c24;">
                    <strong>Attenzione sovrapposizioni:</strong>
                    <ul id="bsn-noleggi-warning-list" style="margin:5px 0 0 20px; padding:0;"></ul>
                </div>

                <!-- Filtri noleggi -->
                <div id="bsn-noleggi-filtri" style="margin-bottom:10px;">
                    <label>
                        Stato:
                        <select id="bsn-filtro-stato">
                            <option value="">Tutti</option>
                            <option value="preventivo">Preventivi</option>
                            <option value="bozza">Bozze</option>
                            <option value="attivo">Attivi</option>
                            <option value="chiuso">Chiusi</option>
                            <option value="ritardo">In ritardo</option>
                        </select>
                    </label>
                    <div class="bsn-list-toolbar" style="margin-top:8px;">
                        <input type="text" id="bsn-noleggi-search" placeholder="Cerca nei noleggi..." style="width:100%; max-width:360px; padding:5px;">
                        <label for="bsn-noleggi-limit">Mostra:</label>
                        <select id="bsn-noleggi-limit">
                            <option value="12" selected>12</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>

                <!-- Lista noleggi -->
                 <div id="bsn-lista-noleggi">
                    <p>Nessun noleggio caricato.</p>
                </div>
            </div>

            <!-- CALENDARIO -->
            <div id="calendario" class="tab-content">
                <h2>Calendario Noleggi</h2>

                <div class="bsn-calendar-toolbar">
                    <div class="bsn-calendar-nav">
                        <button type="button" class="btn btn-secondary" id="bsn-calendar-prev">◀</button>
                        <div id="bsn-calendar-title">-</div>
                        <button type="button" class="btn btn-secondary" id="bsn-calendar-next">▶</button>
                    </div>
                    <div class="bsn-calendar-views">
                        <button type="button" class="btn btn-secondary bsn-calendar-view active" data-view="month">Mese</button>
                        <button type="button" class="btn btn-secondary bsn-calendar-view" data-view="week">Settimana</button>
                        <button type="button" class="btn btn-secondary bsn-calendar-view" data-view="day">Giorno</button>
                    </div>
                </div>

                <div class="bsn-calendar-filters">
                    <input type="text" id="bsn-calendar-search" placeholder="Cerca per cliente, materiale o ID..." />
                </div>

                <div id="bsn-calendar" class="bsn-calendar">
                    <div class="bsn-calendar-weekdays">
                        <div>Lun</div>
                        <div>Mar</div>
                        <div>Mer</div>
                        <div>Gio</div>
                        <div>Ven</div>
                        <div>Sab</div>
                        <div>Dom</div>
                    </div>
                    <div id="bsn-calendar-grid" class="bsn-calendar-grid"></div>
                    <div id="bsn-calendar-day" class="bsn-calendar-day" hidden></div>
                </div>
            </div>

            <!-- TICKET -->
            <div id="ticket" class="tab-content">
                <h2>Ticket (anomalia materiale)</h2>

                <form id="bsn-form-ticket" style="margin-bottom:20px;">
                    <div style="display:flex; gap:15px; flex-wrap:wrap;">
                        <div style="flex:1 1 260px; position:relative;">
                            <label>Articolo *</label>
                            <input type="text" id="bsn-ticket-articolo-search" placeholder="Cerca per nome o ID..." autocomplete="off" style="width:100%;">
                            <input type="hidden" id="bsn-ticket-articolo-id">
                            <input type="hidden" id="bsn-ticket-id">
                            <div id="bsn-ticket-articoli-risultati"
                                 style="border:1px solid #ccc; max-height:150px; overflow-y:auto; display:none; background:#fff; position:absolute; z-index:10; width:100%;"></div>
                        </div>
                        <div style="flex:0 0 140px;">
                            <label>Quantità *</label>
                            <input type="number" id="bsn-ticket-qty" min="1" value="1" style="width:100%;">
                        </div>
                        <div style="flex:1 1 200px;">
                            <label>Tipo problema *</label>
                            <select id="bsn-ticket-tipo" style="width:100%;">
                                <option value="mancante">Mancante</option>
                                <option value="non_rientrato">Non rientrato</option>
                                <option value="quantita_inferiore">Quantità inferiore</option>
                                <option value="danneggiato">Danneggiato</option>
                                <option value="problematico_utilizzabile">Problematico ma utilizzabile</option>
                            </select>
                        </div>
                        <div style="flex:0 0 140px;">
                            <label>Stato</label>
                            <select id="bsn-ticket-stato" style="width:100%;">
                                <option value="aperto">Aperto</option>
                                <option value="chiuso">Chiuso</option>
                            </select>
                        </div>
                        <div style="flex:1 1 200px;">
                            <label>Riferimento noleggio</label>
                            <input type="text" id="bsn-ticket-noleggio" placeholder="Es. 2026/001" style="width:100%;">
                        </div>
                    </div>
                    <div style="margin-top:10px;">
                        <label>Note operative / warning cliente</label>
                        <textarea id="bsn-ticket-note" rows="3" style="width:100%;" placeholder="Es. Porta USB non funzionante, ingresso HDMI ok. Se il ticket è 'problematico ma utilizzabile', questa nota può essere mostrata anche nella scheda prodotto pubblica."></textarea>
                    </div>
                    <div style="margin-top:10px;">
                        <label>Foto (opzionale)</label>
                        <input type="file" id="bsn-ticket-foto" accept="image/*" capture="environment" multiple style="width:100%;">
                        <input type="hidden" id="bsn-ticket-foto-urls" value="">
                        <div id="bsn-ticket-foto-preview" style="margin-top:6px;"></div>
                        <!-- BSN: Ticket webcam -->
                        <button type="button" id="bsn-ticket-webcam-start" class="btn btn-secondary" style="margin-top:6px;">📷 Apri webcam</button>
                        <button type="button" id="bsn-ticket-webcam-capture" class="btn btn-primary" style="display:none; margin-top:6px;">Scatta foto</button>
                        <div id="bsn-ticket-webcam-area" style="display:none; margin-top:10px;">
                            <video id="bsn-ticket-webcam-video" width="320" height="240" autoplay style="border:1px solid #ccc;"></video>
                            <canvas id="bsn-ticket-webcam-canvas" style="display:none;"></canvas>
                        </div>
                        <!-- BSN: Ticket webcam end -->
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:10px;">Apri ticket</button>
                </form>

                <div id="bsn-ticket-filtri" style="margin-bottom:10px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <input type="text" id="bsn-ticket-search" placeholder="Cerca nei ticket..." style="width:100%; max-width:300px; padding:5px;">
                    <label style="display:flex; align-items:center; gap:6px; margin:0;">
                        Stato:
                        <select id="bsn-ticket-filtro-stato">
                            <option value="">Tutti</option>
                            <option value="aperto" selected>Aperti</option>
                            <option value="chiuso">Chiusi</option>
                        </select>
                    </label>
                </div>

                <div id="bsn-lista-ticket">
                    <p>Nessun ticket caricato.</p>
                </div>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode( 'blackstar_finalizza', 'bsn_shortcode_finalizza' );
function bsn_shortcode_finalizza() {
    if ( ! bsn_check_admin() ) {
        return '<p>Accesso negato. Permesso riservato agli operatori autorizzati.</p>';
    }

    ob_start();
    bsn_render_finalizza_noleggio_page(
        [
            'back_url' => home_url( '/app-noleggi/' ),
        ]
    );
    return ob_get_clean();
}

add_shortcode( 'blackstar_ispeziona', 'bsn_shortcode_ispeziona' );
function bsn_shortcode_ispeziona() {
    if ( ! bsn_check_admin() ) {
        return '<p>Accesso negato. Permesso riservato agli operatori autorizzati.</p>';
    }

    ob_start();
    bsn_render_ispeziona_noleggio_page(
        [
            'back_url' => home_url( '/app-noleggi/' ),
        ]
    );
    return ob_get_clean();
}

add_shortcode( 'blackstar_preventivo', 'bsn_shortcode_preventivo' );
add_shortcode( 'blackstar_preventivi', 'bsn_shortcode_preventivo' );
function bsn_shortcode_preventivo() {
    if ( ! bsn_check_admin() ) {
        return '<p>Accesso negato. Permesso riservato agli operatori autorizzati.</p>';
    }

    ob_start();
    bsn_render_preventivo_noleggio_page(
        [
            'back_url' => home_url( '/app-noleggi/' ),
        ]
    );
    return ob_get_clean();
}

add_shortcode( 'blackstar_carrello_noleggio', 'bsn_shortcode_quote_cart' );
function bsn_shortcode_quote_cart() {
    $archive_url = get_post_type_archive_link( 'bs_prodotto' );
    if ( ! $archive_url ) {
        $archive_url = home_url( '/catalogo-noleggio/' );
    }

    ob_start();
    ?>
    <main class="bsn-public-page bsn-quote-cart-page">
        <div class="bsn-public-shell">
            <header class="bsn-archive-header">
                <p class="bsn-eyebrow">Preventivo online</p>
                <h1>Carrello noleggio</h1>
                <p>Un solo periodo globale per tutto il carrello. Modificare le date qui ricalcola tutti i prodotti insieme.</p>
            </header>

            <div id="bsn-quote-cart-app">
                <div class="bsn-public-card">
                    <p>Caricamento carrello in corso...</p>
                </div>
            </div>
        </div>
    </main>
    <?php
    // Carica Google Maps JS API solo se la chiave frontend è configurata
    $bsn_maps_frontend_key = bsn_get_google_maps_frontend_key();
    if ( $bsn_maps_frontend_key !== '' ) {
        $maps_src = 'https://maps.googleapis.com/maps/api/js?key=' . esc_attr( $bsn_maps_frontend_key ) . '&libraries=places&loading=async&callback=bsnMapsApiReady';
        echo '<script src="' . esc_url( $maps_src ) . '" defer></script>' . "\n";
    }
    ?>
    <script>
    // Callback chiamata da Google Maps API quando è pronta
    window.bsnMapsApiReady = function() { window.bsnMapsApiLoaded = true; bsnInitServiceAutocomplete(); };

    // Dati salvati dall'autocomplete per ogni campo indirizzo
    window.bsnServiceGeoData = {};

    // Inizializza Places Autocomplete sui campi indirizzo servizio visibili
    function bsnInitServiceAutocomplete() {
        if ( typeof window.google === 'undefined' || !google.maps || !google.maps.places ) { return; }
        var ids = [
            'bsn-service-location-address',
            'bsn-service-location-address-install',
            'bsn-service-location-address-tech'
        ];
        ids.forEach(function(id) {
            var input = document.getElementById(id);
            if (!input || input.dataset.bsnAutocompleteInit) { return; }
            input.dataset.bsnAutocompleteInit = '1';
            var ac = new google.maps.places.Autocomplete(input, { types: ['geocode'], componentRestrictions: { country: 'it' } });
            // Quando l'utente seleziona un suggerimento
            ac.addListener('place_changed', function() {
                var place = ac.getPlace();
                var geo = { place_id: '', lat: '', lng: '', formatted_address: '' };
                if (place.place_id) { geo.place_id = place.place_id; }
                if (place.formatted_address) { geo.formatted_address = place.formatted_address; }
                if (place.geometry && place.geometry.location) {
                    geo.lat = place.geometry.location.lat();
                    geo.lng = place.geometry.location.lng();
                }
                window.bsnServiceGeoData[id] = geo;
            });
            // Se l'utente cancella il campo, pulisce i dati salvati
            input.addEventListener('input', function() {
                if (!input.value) { delete window.bsnServiceGeoData[id]; }
            });
        });
    }

    (function() {
        var root = <?php echo wp_json_encode( esc_url_raw( rest_url( 'bsn/v1/' ) ) ); ?>;
        var restNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        var archiveUrl = <?php echo wp_json_encode( esc_url_raw( $archive_url ) ); ?>;
        var app = document.getElementById('bsn-quote-cart-app');
        var submitNoteDraft = '';
        var submitNoteInitialized = false;

        if (!app) {
            return;
        }

        function escHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function euro(value) {
            var num = Number(value || 0);
            return num.toFixed(2) + ' EUR';
        }

        function formatDateIt(value) {
            if (!value) {
                return '';
            }
            var raw = String(value);
            var datePart = raw.indexOf(' ') > -1 ? raw.split(' ')[0] : raw;
            var bits = datePart.split('-');
            if (bits.length !== 3) {
                return raw;
            }
            return bits[2] + '-' + bits[1] + '-' + bits[0];
        }

        function getInclusiveDayCount(startDate, endDate) {
            if (!startDate || !endDate) {
                return 0;
            }
            var start = new Date(String(startDate) + 'T00:00:00');
            var end = new Date(String(endDate) + 'T00:00:00');
            if (isNaN(start.getTime()) || isNaN(end.getTime()) || end.getTime() < start.getTime()) {
                return 0;
            }
            return Math.max(1, Math.round((end.getTime() - start.getTime()) / 86400000) + 1);
        }

        function getCategoryLabel(code) {
            var map = {
                standard: 'Guest / standard',
                fidato: 'Cliente fidato',
                premium: 'Cliente premium',
                service: 'Cliente service',
                collaboratori: 'Collaboratori'
            };
            return map[code] || 'Guest / standard';
        }

        function boolAttr(value) {
            return value ? ' checked' : '';
        }

        function selectedAttr(value, current) {
            return String(value || '') === String(current || '') ? ' selected' : '';
        }

        function serviceEstimatedLabel(service) {
            if (!service) {
                return 'Da definire';
            }
            if (service.estimated_total !== null && service.estimated_total !== undefined && String(service.estimated_total) !== '') {
                return euro(service.estimated_total);
            }
            return 'Da definire';
        }

        function getTechDayTypeLabel(value) {
            return String(value || '') === 'half_day' ? 'Mezza giornata' : (String(value || '') === 'full_day' ? 'Giornata intera' : '');
        }

        function getTechDayTypeFromTimes(startTime, endTime) {
            if (!startTime || !endTime || startTime >= endTime) {
                return '';
            }
            var startBits = String(startTime).split(':');
            var endBits = String(endTime).split(':');
            if (startBits.length < 2 || endBits.length < 2) {
                return '';
            }
            var startMinutes = (parseInt(startBits[0], 10) || 0) * 60 + (parseInt(startBits[1], 10) || 0);
            var endMinutes = (parseInt(endBits[0], 10) || 0) * 60 + (parseInt(endBits[1], 10) || 0);
            if (endMinutes <= startMinutes) {
                return '';
            }
            var hours = (endMinutes - startMinutes) / 60;
            return (hours > 0 && hours <= 6) ? 'half_day' : 'full_day';
        }

        function getSelectedRadioValue(name) {
            var input = app.querySelector('input[name="' + name + '"]:checked');
            return input ? input.value : '';
        }

        function renderServiceAlerts(service) {
            var html = '';
            if (service && Array.isArray(service.messages) && service.messages.length) {
                html += '<div class="bsn-public-warning-box"><strong>Nota servizi</strong><ul>' +
                    service.messages.map(function(message) {
                        return '<li>' + escHtml(message) + '</li>';
                    }).join('') +
                '</ul></div>';
            }
            if (service && Array.isArray(service.errors) && service.errors.length) {
                html += '<div class="bsn-public-warning-box"><strong>Dati servizio da completare</strong><ul>' +
                    service.errors.map(function(message) {
                        return '<li>' + escHtml(message) + '</li>';
                    }).join('') +
                '</ul></div>';
            }
            return html;
        }

        function renderServiceSection(data) {
            var service = data && data.service ? data.service : { mode: 'pickup' };
            var serviceMode = service.mode || 'pickup';
            var distanceLabel = (service.distance_km !== null && service.distance_km !== undefined && String(service.distance_km) !== '')
                ? (String(service.distance_km) + ' km stimati')
                : 'Distanza in verifica';

            return '' +
                '<section class="bsn-public-card bsn-quote-cart-service-box">' +
                    '<div class="bsn-quote-cart-service-header">' +
                        '<div>' +
                            '<h3>Modalita di consegna / servizi</h3>' +
                            '<p class="bsn-price-note">Scegli una sola modalita. I costi logistici e tecnici sono sempre indicativi e verranno confermati dallo staff.</p>' +
                        '</div>' +
                        '<div class="bsn-quote-cart-service-estimate">' +
                            '<span>Stima servizi</span>' +
                            '<strong>' + escHtml(serviceEstimatedLabel(service)) + '</strong>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-mode-list">' +
                        '<label class="bsn-service-mode-card"><input type="radio" name="bsn_service_mode" value="pickup"' + boolAttr(serviceMode === 'pickup') + '> <span>Ritiro in sede - € 0,00</span></label>' +
                        '<label class="bsn-service-mode-card"><input type="radio" name="bsn_service_mode" value="delivery_only"' + boolAttr(serviceMode === 'delivery_only') + '> <span>Solo consegna</span></label>' +
                        '<label class="bsn-service-mode-card"><input type="radio" name="bsn_service_mode" value="delivery_install"' + boolAttr(serviceMode === 'delivery_install') + '> <span>Trasporto con montaggio</span></label>' +
                        '<label class="bsn-service-mode-card"><input type="radio" name="bsn_service_mode" value="delivery_install_tech"' + boolAttr(serviceMode === 'delivery_install_tech') + '> <span>Trasporto con montaggio e gestione tecnica</span></label>' +
                    '</div>' +
                    renderServiceAlerts(service) +
                    '<div class="bsn-service-panel" data-service-mode="pickup">' +
                        '<div class="bsn-service-copy">' +
                            '<p><strong>Black Star Service SRL</strong><br>Via Cerca 28, Brescia (zona Sant\'Eufemia)</p>' +
                            '<p>Orari ritiro:<br>Lun-Ven 09:30-12:30 / 14:30-17:30<br>Sab 09:30-12:30</p>' +
                            '<p>Ritiro e riconsegna coincidono con le date selezionate nel noleggio.</p>' +
                            '<p><a class="bsn-public-btn bsn-public-btn-ghost" href="https://www.google.com/maps/search/?api=1&query=Via+Cerca+28+Brescia" target="_blank" rel="noopener noreferrer">Apri su Google Maps</a></p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-panel" data-service-mode="delivery_only">' +
                        '<div class="bsn-service-copy">' +
                            '<p>Consegna bordo strada o ingresso location. Non include montaggio, facchinaggio complesso o ritiro finale se non selezionato.</p>' +
                            '<p>Prezzo base consegna: 40 EUR + 1,20 EUR al km. Se aggiungi anche il ritiro finale, la tratta viene raddoppiata.</p>' +
                        '</div>' +
                        '<div class="bsn-service-form-grid">' +
                            '<label class="bsn-service-field bsn-service-field-full">Indirizzo location' +
                                '<input type="text" id="bsn-service-location-address" value="' + escHtml(service.location_address || '') + '" placeholder="Via, numero civico, citta">' +
                            '</label>' +
                            '<label class="bsn-service-field">Referente in loco' +
                                '<input type="text" id="bsn-service-onsite-contact-name" value="' + escHtml(service.onsite_contact_name || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Telefono referente' +
                                '<input type="text" id="bsn-service-onsite-contact-phone" value="' + escHtml(service.onsite_contact_phone || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field bsn-service-checkbox bsn-service-field-full"><input type="checkbox" id="bsn-service-ack-delivery-only-terms"' + boolAttr(!!service.ack_delivery_only_terms) + '> Ho compreso che questa opzione include solo la consegna e non include montaggio, facchinaggio complesso o ritiro finale se non espressamente selezionato.</label>' +
                            '<label class="bsn-service-field bsn-service-checkbox bsn-service-field-full"><input type="checkbox" id="bsn-service-delivery-return-requested"' + boolAttr(!!service.delivery_return_requested) + '> Aggiungi ritiro a fine noleggio</label>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-panel" data-service-mode="delivery_install">' +
                        '<div class="bsn-service-copy">' +
                            '<p>Include consegna e montaggio standard con 1 tecnico. Il numero reale di tecnici puo variare in base a materiali, accessi e complessita.</p>' +
                            '<p>Prezzo base trasporto con montaggio: 100 EUR + 1,20 EUR al km. Se aggiungi smontaggio e ritiro finale, la tratta viene raddoppiata.</p>' +
                        '</div>' +
                        '<div class="bsn-service-form-grid">' +
                            '<label class="bsn-service-field bsn-service-field-full">Indirizzo location' +
                                '<input type="text" id="bsn-service-location-address-install" value="' + escHtml(service.location_address || '') + '" placeholder="Via, numero civico, citta">' +
                            '</label>' +
                            '<label class="bsn-service-field">Referente in loco' +
                                '<input type="text" id="bsn-service-onsite-contact-name-install" value="' + escHtml(service.onsite_contact_name || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Telefono referente' +
                                '<input type="text" id="bsn-service-onsite-contact-phone-install" value="' + escHtml(service.onsite_contact_phone || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field bsn-service-checkbox bsn-service-field-full"><input type="checkbox" id="bsn-service-dismantling-requested"' + boolAttr(!!service.dismantling_requested) + '> Aggiungi smontaggio e ritiro a fine noleggio</label>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-panel" data-service-mode="delivery_install_tech">' +
                        '<div class="bsn-service-copy">' +
                            '<p>Include trasporto, montaggio, presenza tecnica durante l\'evento e smontaggio finale. Il numero reale di tecnici viene sempre definito dallo staff.</p>' +
                            '<p>Stima minima V1: trasferta + 1 tecnico. Se l\'impegno rientra in mezza giornata usiamo la tariffa base da 180 EUR, altrimenti 250 EUR.</p>' +
                        '</div>' +
                        '<div class="bsn-service-form-grid">' +
                            '<label class="bsn-service-field bsn-service-field-full">Indirizzo location' +
                                '<input type="text" id="bsn-service-location-address-tech" value="' + escHtml(service.location_address || '') + '" placeholder="Via, numero civico, citta">' +
                            '</label>' +
                            '<label class="bsn-service-field">Referente in loco' +
                                '<input type="text" id="bsn-service-onsite-contact-name-tech" value="' + escHtml(service.onsite_contact_name || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Telefono referente' +
                                '<input type="text" id="bsn-service-onsite-contact-phone-tech" value="' + escHtml(service.onsite_contact_phone || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Data evento' +
                                '<input type="date" id="bsn-service-event-date" value="' + escHtml(service.event_date || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Orario inizio evento' +
                                '<input type="time" id="bsn-service-event-start-time" value="' + escHtml(service.event_start_time || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Orario fine evento' +
                                '<input type="time" id="bsn-service-event-end-time" value="' + escHtml(service.event_end_time || '') + '">' +
                            '</label>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-panel bsn-service-panel-common" data-service-mode="delivery_only,delivery_install,delivery_install_tech">' +
                        '<div class="bsn-service-form-grid">' +
                            '<label class="bsn-service-field">ZTL' +
                                '<select id="bsn-service-logistics-ztl">' +
                                    '<option value="0"' + selectedAttr('0', service.logistics_ztl ? '1' : '0') + '>No</option>' +
                                    '<option value="1"' + selectedAttr('1', service.logistics_ztl ? '1' : '0') + '>Si</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field">Scale' +
                                '<select id="bsn-service-logistics-stairs">' +
                                    '<option value="none"' + selectedAttr('none', service.logistics_stairs || 'none') + '>Nessuna</option>' +
                                    '<option value="1_floor"' + selectedAttr('1_floor', service.logistics_stairs || 'none') + '>1 piano</option>' +
                                    '<option value="multi_floor"' + selectedAttr('multi_floor', service.logistics_stairs || 'none') + '>Piu piani</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field">Ascensore / montacarichi' +
                                '<select id="bsn-service-logistics-lift">' +
                                    '<option value="0"' + selectedAttr('0', service.logistics_lift ? '1' : '0') + '>No</option>' +
                                    '<option value="1"' + selectedAttr('1', service.logistics_lift ? '1' : '0') + '>Si</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field">Accesso difficile / varchi stretti' +
                                '<select id="bsn-service-logistics-tight-access">' +
                                    '<option value="0"' + selectedAttr('0', service.logistics_tight_access ? '1' : '0') + '>No</option>' +
                                    '<option value="1"' + selectedAttr('1', service.logistics_tight_access ? '1' : '0') + '>Si</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field">Distanza scarico → utilizzo' +
                                '<select id="bsn-service-logistics-walk-distance">' +
                                    '<option value="short"' + selectedAttr('short', service.logistics_walk_distance || 'short') + '>Breve</option>' +
                                    '<option value="medium"' + selectedAttr('medium', service.logistics_walk_distance || 'short') + '>Media</option>' +
                                    '<option value="long"' + selectedAttr('long', service.logistics_walk_distance || 'short') + '>Lunga</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field bsn-service-field-full">Note logistiche' +
                                '<textarea id="bsn-service-notes" rows="4" placeholder="Accessi, vincoli, orari, informazioni utili per consegna, montaggio o gestione tecnica...">' + escHtml(service.notes || '') + '</textarea>' +
                            '</label>' +
                            '<div class="bsn-service-distance-meta">' +
                                '<strong>' + escHtml(distanceLabel) + '</strong>' +
                                (service.distance_status === 'failed'
                                    ? '<span>Costo trasporto da definire dopo verifica indirizzo.</span>'
                                    : '<span>' + escHtml(service.distance_message || service.label || '') + '</span>') +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-quote-cart-submit-actions">' +
                        '<button type="button" class="bsn-public-btn bsn-public-btn-ghost" id="bsn-quote-cart-update-service">Aggiorna stima servizi</button>' +
                    '</div>' +
                '</section>';
        }

        function collectServicePayload() {
            var checkedMode = app.querySelector('input[name=\"bsn_service_mode\"]:checked');
            var mode = checkedMode ? checkedMode.value : 'pickup';
            var suffix = '';

            if (mode === 'delivery_install') {
                suffix = '-install';
            } else if (mode === 'delivery_install_tech') {
                suffix = '-tech';
            }

            var locationAddress = '';
            var onsiteName = '';
            var onsitePhone = '';
            var notes = '';

            var activeAddressId = '';
            if (mode === 'delivery_only') {
                activeAddressId = 'bsn-service-location-address';
                locationAddress = (document.getElementById(activeAddressId) || {}).value || '';
                onsiteName = (document.getElementById('bsn-service-onsite-contact-name') || {}).value || '';
                onsitePhone = (document.getElementById('bsn-service-onsite-contact-phone') || {}).value || '';
            } else if (mode === 'delivery_install') {
                activeAddressId = 'bsn-service-location-address-install';
                locationAddress = (document.getElementById(activeAddressId) || {}).value || '';
                onsiteName = (document.getElementById('bsn-service-onsite-contact-name-install') || {}).value || '';
                onsitePhone = (document.getElementById('bsn-service-onsite-contact-phone-install') || {}).value || '';
            } else if (mode === 'delivery_install_tech') {
                activeAddressId = 'bsn-service-location-address-tech';
                locationAddress = (document.getElementById(activeAddressId) || {}).value || '';
                onsiteName = (document.getElementById('bsn-service-onsite-contact-name-tech') || {}).value || '';
                onsitePhone = (document.getElementById('bsn-service-onsite-contact-phone-tech') || {}).value || '';
            }

            if (mode !== 'pickup') {
                notes = (document.getElementById('bsn-service-notes') || {}).value || '';
            }

            return {
                service_mode: mode,
                service_delivery_return_requested: document.getElementById('bsn-service-delivery-return-requested') && document.getElementById('bsn-service-delivery-return-requested').checked ? '1' : '',
                service_dismantling_requested: document.getElementById('bsn-service-dismantling-requested') && document.getElementById('bsn-service-dismantling-requested').checked ? '1' : '',
                service_ack_delivery_only_terms: document.getElementById('bsn-service-ack-delivery-only-terms') && document.getElementById('bsn-service-ack-delivery-only-terms').checked ? '1' : '',
                service_location_address: locationAddress,
                // Dati pre-geocodificati dall'autocomplete Places (evitano geocoding lato server)
                service_location_lat:      (window.bsnServiceGeoData[activeAddressId] || {}).lat      || '',
                service_location_lng:      (window.bsnServiceGeoData[activeAddressId] || {}).lng      || '',
                service_location_place_id: (window.bsnServiceGeoData[activeAddressId] || {}).place_id || '',
                service_onsite_contact_name: onsiteName,
                service_onsite_contact_phone: onsitePhone,
                service_logistics_ztl: document.getElementById('bsn-service-logistics-ztl') ? document.getElementById('bsn-service-logistics-ztl').value : '0',
                service_logistics_stairs: document.getElementById('bsn-service-logistics-stairs') ? document.getElementById('bsn-service-logistics-stairs').value : 'none',
                service_logistics_lift: document.getElementById('bsn-service-logistics-lift') ? document.getElementById('bsn-service-logistics-lift').value : '0',
                service_logistics_tight_access: document.getElementById('bsn-service-logistics-tight-access') ? document.getElementById('bsn-service-logistics-tight-access').value : '0',
                service_logistics_walk_distance: document.getElementById('bsn-service-logistics-walk-distance') ? document.getElementById('bsn-service-logistics-walk-distance').value : 'short',
                service_notes: notes,
                service_event_date: document.getElementById('bsn-service-event-date') ? document.getElementById('bsn-service-event-date').value : '',
                service_event_start_time: document.getElementById('bsn-service-event-start-time') ? document.getElementById('bsn-service-event-start-time').value : '',
                service_event_end_time: document.getElementById('bsn-service-event-end-time') ? document.getElementById('bsn-service-event-end-time').value : ''
            };
        }

        function syncServiceModeVisibility() {
            var checkedMode = app.querySelector('input[name=\"bsn_service_mode\"]:checked');
            var mode = checkedMode ? checkedMode.value : 'pickup';
            app.querySelectorAll('[data-service-mode]').forEach(function(panel) {
                var allowed = String(panel.getAttribute('data-service-mode') || '').split(',');
                var visible = allowed.indexOf(mode) !== -1;
                panel.hidden = !visible;
                panel.style.display = visible ? '' : 'none';
            });
        }

        function renderServiceSection(data) {
            var service = data && data.service ? data.service : { mode: 'pickup' };
            var serviceMode = service.mode || 'pickup';
            var deliveryChoice = service.delivery_return_choice || (service.delivery_return_requested ? 'yes' : '');
            var dismantlingChoice = service.dismantling_choice || (service.dismantling_requested ? 'yes' : '');
            var eventStartDate = service.event_start_date || ((data && data.dates && data.dates.data_ritiro) ? data.dates.data_ritiro : '');
            var eventEndDate = service.event_end_date || ((data && data.dates && data.dates.data_riconsegna) ? data.dates.data_riconsegna : '');
            var eventDays = Number(service.event_days || 0);
            var techDayType = service.tech_day_type || getTechDayTypeFromTimes(service.event_start_time || '', service.event_end_time || '');
            var techDayTypeLabel = getTechDayTypeLabel(techDayType);
            var distanceLabel = (service.distance_km !== null && service.distance_km !== undefined && String(service.distance_km) !== '')
                ? (String(service.distance_km) + ' km stimati')
                : 'Distanza in verifica';
            var techSummaryHtml = '';

            if (eventDays < 1) {
                eventDays = getInclusiveDayCount(eventStartDate, eventEndDate);
            }

            if (serviceMode === 'delivery_install_tech' && eventStartDate && eventEndDate) {
                techSummaryHtml =
                    '<div class="bsn-service-tech-summary">' +
                        '<div><span>Data inizio evento</span><strong>' + escHtml(formatDateIt(eventStartDate)) + '</strong></div>' +
                        '<div><span>Data fine evento</span><strong>' + escHtml(formatDateIt(eventEndDate)) + '</strong></div>' +
                        '<div><span>Giorni considerati</span><strong>' + escHtml(eventDays || 1) + '</strong></div>' +
                        '<div><span>Calcolo tecnico</span><strong>' + escHtml(techDayTypeLabel || 'Da definire') + '</strong></div>' +
                        '<div><span>Base di stima</span><strong>1 tecnico minimo</strong></div>' +
                        '<div><span>Nota</span><strong>Stima indicativa da confermare con lo staff</strong></div>' +
                    '</div>';
            }

            return '' +
                '<section class="bsn-public-card bsn-quote-cart-service-box">' +
                    '<div class="bsn-quote-cart-service-header">' +
                        '<div>' +
                            '<h3>Modalit&agrave; di consegna / servizi</h3>' +
                            '<p class="bsn-price-note">Scegli una sola modalit&agrave;. I costi logistici e tecnici sono sempre indicativi e verranno confermati dallo staff.</p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-mode-list">' +
                        '<label class="bsn-service-mode-card"><input type="radio" name="bsn_service_mode" value="pickup"' + boolAttr(serviceMode === 'pickup') + '> <span>Ritiro in sede - EUR 0,00</span></label>' +
                        '<label class="bsn-service-mode-card"><input type="radio" name="bsn_service_mode" value="delivery_only"' + boolAttr(serviceMode === 'delivery_only') + '> <span>Solo consegna</span></label>' +
                        '<label class="bsn-service-mode-card"><input type="radio" name="bsn_service_mode" value="delivery_install"' + boolAttr(serviceMode === 'delivery_install') + '> <span>Trasporto con montaggio</span></label>' +
                        '<label class="bsn-service-mode-card"><input type="radio" name="bsn_service_mode" value="delivery_install_tech"' + boolAttr(serviceMode === 'delivery_install_tech') + '> <span>Trasporto con montaggio e gestione tecnica</span></label>' +
                    '</div>' +
                    renderServiceAlerts(service) +
                    '<div class="bsn-service-panel" data-service-mode="pickup">' +
                        '<div class="bsn-service-copy">' +
                            '<p><strong>Black Star Service SRL</strong><br>Via Cerca 28, Brescia (zona Sant\'Eufemia)</p>' +
                            '<p>Orari ritiro:<br>Lun-Ven 09:30-12:30 / 14:30-17:30<br>Sab 09:30-12:30</p>' +
                            '<p>Ritiro e riconsegna coincidono con le date selezionate nel noleggio.</p>' +
                            '<p><a class="bsn-public-btn bsn-public-btn-ghost" href="https://www.google.com/maps/search/?api=1&query=Via+Cerca+28+Brescia" target="_blank" rel="noopener noreferrer">Apri su Google Maps</a></p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-panel" data-service-mode="delivery_only">' +
                        '<div class="bsn-service-copy">' +
                            '<p>Consegna bordo strada o ingresso location. Non include montaggio, facchinaggio complesso o ritiro finale se non selezionato.</p>' +
                            '<p>Prezzo base consegna: 40 EUR + 1,20 EUR al km. Se aggiungi anche il ritiro finale, la tratta viene raddoppiata.</p>' +
                        '</div>' +
                        '<div class="bsn-service-decision-box">' +
                            '<strong>Vuoi aggiungere anche il ritiro del materiale a fine evento?</strong>' +
                            '<div class="bsn-service-choice-list">' +
                                '<label class="bsn-service-choice"><input type="radio" name="bsn_service_delivery_return_choice" value="yes"' + boolAttr(deliveryChoice === 'yes') + '> <span>S&igrave;, desidero consegna + ritiro</span></label>' +
                                '<label class="bsn-service-choice"><input type="radio" name="bsn_service_delivery_return_choice" value="no"' + boolAttr(deliveryChoice === 'no') + '> <span>No, desidero solo la consegna; il materiale lo riporteremo noi</span></label>' +
                            '</div>' +
                        '</div>' +
                        '<div class="bsn-service-choice-details" data-service-choice-parent="bsn_service_delivery_return_choice">' +
                            '<div class="bsn-service-form-grid">' +
                                '<label class="bsn-service-field bsn-service-field-full">Indirizzo location' +
                                    '<input type="text" id="bsn-service-location-address" value="' + escHtml(service.location_address || '') + '" placeholder="Via, numero civico, citta">' +
                                '</label>' +
                                '<label class="bsn-service-field">Referente in loco' +
                                    '<input type="text" id="bsn-service-onsite-contact-name" value="' + escHtml(service.onsite_contact_name || '') + '">' +
                                '</label>' +
                                '<label class="bsn-service-field">Telefono referente' +
                                    '<input type="text" id="bsn-service-onsite-contact-phone" value="' + escHtml(service.onsite_contact_phone || '') + '">' +
                                '</label>' +
                                '<label class="bsn-service-field bsn-service-checkbox bsn-service-field-full"><input type="checkbox" id="bsn-service-ack-delivery-only-terms"' + boolAttr(!!service.ack_delivery_only_terms) + '> Ho compreso che questa opzione include solo la consegna e non include montaggio, facchinaggio complesso o ritiro finale se non espressamente selezionato.</label>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-panel" data-service-mode="delivery_install">' +
                        '<div class="bsn-service-copy">' +
                            '<p>Include consegna e montaggio standard con 1 tecnico. Il numero reale di tecnici puo variare in base a materiali, accessi e complessita.</p>' +
                            '<p>Prezzo base trasporto con montaggio: 100 EUR + 1,20 EUR al km. Se aggiungi smontaggio e ritiro finale, la tratta viene raddoppiata.</p>' +
                        '</div>' +
                        '<div class="bsn-service-decision-box">' +
                            '<strong>Vuoi aggiungere anche smontaggio e ritiro finale?</strong>' +
                            '<div class="bsn-service-choice-list">' +
                                '<label class="bsn-service-choice"><input type="radio" name="bsn_service_dismantling_choice" value="yes"' + boolAttr(dismantlingChoice === 'yes') + '> <span>S&igrave;, desidero montaggio + smontaggio + ritiro</span></label>' +
                                '<label class="bsn-service-choice"><input type="radio" name="bsn_service_dismantling_choice" value="no"' + boolAttr(dismantlingChoice === 'no') + '> <span>No, desidero solo trasporto con montaggio; smontaggio e riconsegna saranno a nostro carico</span></label>' +
                            '</div>' +
                        '</div>' +
                        '<div class="bsn-service-choice-details" data-service-choice-parent="bsn_service_dismantling_choice">' +
                            '<div class="bsn-service-form-grid">' +
                                '<label class="bsn-service-field bsn-service-field-full">Indirizzo location' +
                                    '<input type="text" id="bsn-service-location-address-install" value="' + escHtml(service.location_address || '') + '" placeholder="Via, numero civico, citta">' +
                                '</label>' +
                                '<label class="bsn-service-field">Referente in loco' +
                                    '<input type="text" id="bsn-service-onsite-contact-name-install" value="' + escHtml(service.onsite_contact_name || '') + '">' +
                                '</label>' +
                                '<label class="bsn-service-field">Telefono referente' +
                                    '<input type="text" id="bsn-service-onsite-contact-phone-install" value="' + escHtml(service.onsite_contact_phone || '') + '">' +
                                '</label>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-panel" data-service-mode="delivery_install_tech">' +
                        '<div class="bsn-service-copy">' +
                            '<p>Include trasporto, montaggio, presenza tecnica durante l\'evento e smontaggio finale. Il numero reale di tecnici viene sempre definito dallo staff.</p>' +
                            '<p>Stima minima V1: trasferta + 1 tecnico. Se l\'impegno rientra in mezza giornata usiamo la tariffa base da 180 EUR, altrimenti 250 EUR.</p>' +
                        '</div>' +
                        '<div class="bsn-service-form-grid">' +
                            '<label class="bsn-service-field bsn-service-field-full">Indirizzo location' +
                                '<input type="text" id="bsn-service-location-address-tech" value="' + escHtml(service.location_address || '') + '" placeholder="Via, numero civico, citta">' +
                            '</label>' +
                            '<label class="bsn-service-field">Referente in loco' +
                                '<input type="text" id="bsn-service-onsite-contact-name-tech" value="' + escHtml(service.onsite_contact_name || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Telefono referente' +
                                '<input type="text" id="bsn-service-onsite-contact-phone-tech" value="' + escHtml(service.onsite_contact_phone || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Data inizio evento' +
                                '<span class="bsn-service-readonly-value">' + escHtml(eventStartDate ? formatDateIt(eventStartDate) : 'Da definire con le date del noleggio') + '</span>' +
                            '</label>' +
                            '<label class="bsn-service-field">Data fine evento' +
                                '<span class="bsn-service-readonly-value">' + escHtml(eventEndDate ? formatDateIt(eventEndDate) : 'Da definire con le date del noleggio') + '</span>' +
                            '</label>' +
                            '<label class="bsn-service-field">Orario inizio evento' +
                                '<input type="time" id="bsn-service-event-start-time" value="' + escHtml(service.event_start_time || '') + '">' +
                            '</label>' +
                            '<label class="bsn-service-field">Orario fine evento' +
                                '<input type="time" id="bsn-service-event-end-time" value="' + escHtml(service.event_end_time || '') + '">' +
                            '</label>' +
                        '</div>' +
                        techSummaryHtml +
                    '</div>' +
                    '<div class="bsn-service-panel bsn-service-panel-common" data-service-mode="delivery_only,delivery_install,delivery_install_tech">' +
                        '<div class="bsn-service-form-grid">' +
                            '<label class="bsn-service-field">ZTL' +
                                '<select id="bsn-service-logistics-ztl">' +
                                    '<option value="0"' + selectedAttr('0', service.logistics_ztl ? '1' : '0') + '>No</option>' +
                                    '<option value="1"' + selectedAttr('1', service.logistics_ztl ? '1' : '0') + '>Si</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field">Scale' +
                                '<select id="bsn-service-logistics-stairs">' +
                                    '<option value="none"' + selectedAttr('none', service.logistics_stairs || 'none') + '>Nessuna</option>' +
                                    '<option value="1_floor"' + selectedAttr('1_floor', service.logistics_stairs || 'none') + '>1 piano</option>' +
                                    '<option value="multi_floor"' + selectedAttr('multi_floor', service.logistics_stairs || 'none') + '>Piu piani</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field">Ascensore / montacarichi' +
                                '<select id="bsn-service-logistics-lift">' +
                                    '<option value="0"' + selectedAttr('0', service.logistics_lift ? '1' : '0') + '>No</option>' +
                                    '<option value="1"' + selectedAttr('1', service.logistics_lift ? '1' : '0') + '>Si</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field">Accesso difficile / varchi stretti' +
                                '<select id="bsn-service-logistics-tight-access">' +
                                    '<option value="0"' + selectedAttr('0', service.logistics_tight_access ? '1' : '0') + '>No</option>' +
                                    '<option value="1"' + selectedAttr('1', service.logistics_tight_access ? '1' : '0') + '>Si</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field">Distanza scarico -> utilizzo' +
                                '<select id="bsn-service-logistics-walk-distance">' +
                                    '<option value="short"' + selectedAttr('short', service.logistics_walk_distance || 'short') + '>Breve</option>' +
                                    '<option value="medium"' + selectedAttr('medium', service.logistics_walk_distance || 'short') + '>Media</option>' +
                                    '<option value="long"' + selectedAttr('long', service.logistics_walk_distance || 'short') + '>Lunga</option>' +
                                '</select>' +
                            '</label>' +
                            '<label class="bsn-service-field bsn-service-field-full">Note servizio' +
                                '<textarea id="bsn-service-notes" rows="4" placeholder="Accessi, vincoli, orari, informazioni utili per consegna, montaggio o gestione tecnica...">' + escHtml(service.notes || '') + '</textarea>' +
                            '</label>' +
                            '<div class="bsn-service-distance-meta">' +
                                '<strong>' + escHtml(distanceLabel) + '</strong>' +
                                (service.distance_status === 'failed'
                                    ? '<span>Costo trasporto da definire dopo verifica indirizzo.</span>'
                                    : '<span>' + escHtml(service.label || '') + '</span>') +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bsn-service-actions-bar">' +
                        '<button type="button" class="bsn-public-btn bsn-public-btn-secondary" id="bsn-quote-cart-update-service">CLICCA QUI PER UNA STIMA DEI SERVIZI</button>' +
                        '<div class="bsn-quote-cart-service-estimate-inline">' +
                            '<span>Stima servizi</span>' +
                            '<strong>' + escHtml(serviceEstimatedLabel(service)) + '</strong>' +
                        '</div>' +
                    '</div>' +
                '</section>';
        }

        function collectServicePayload() {
            var checkedMode = app.querySelector('input[name=\"bsn_service_mode\"]:checked');
            var mode = checkedMode ? checkedMode.value : 'pickup';
            var locationAddress = '';
            var onsiteName = '';
            var onsitePhone = '';
            var notes = '';
            var deliveryReturnChoice = getSelectedRadioValue('bsn_service_delivery_return_choice');
            var dismantlingChoice = getSelectedRadioValue('bsn_service_dismantling_choice');

            var activeAddressId = '';
            if (mode === 'delivery_only') {
                activeAddressId = 'bsn-service-location-address';
                locationAddress = (document.getElementById(activeAddressId) || {}).value || '';
                onsiteName = (document.getElementById('bsn-service-onsite-contact-name') || {}).value || '';
                onsitePhone = (document.getElementById('bsn-service-onsite-contact-phone') || {}).value || '';
            } else if (mode === 'delivery_install') {
                activeAddressId = 'bsn-service-location-address-install';
                locationAddress = (document.getElementById(activeAddressId) || {}).value || '';
                onsiteName = (document.getElementById('bsn-service-onsite-contact-name-install') || {}).value || '';
                onsitePhone = (document.getElementById('bsn-service-onsite-contact-phone-install') || {}).value || '';
            } else if (mode === 'delivery_install_tech') {
                activeAddressId = 'bsn-service-location-address-tech';
                locationAddress = (document.getElementById(activeAddressId) || {}).value || '';
                onsiteName = (document.getElementById('bsn-service-onsite-contact-name-tech') || {}).value || '';
                onsitePhone = (document.getElementById('bsn-service-onsite-contact-phone-tech') || {}).value || '';
            }

            if (mode !== 'pickup') {
                notes = (document.getElementById('bsn-service-notes') || {}).value || '';
            }

            return {
                service_mode: mode,
                service_delivery_return_choice: deliveryReturnChoice,
                service_dismantling_choice: dismantlingChoice,
                service_delivery_return_requested: deliveryReturnChoice === 'yes' ? '1' : '',
                service_dismantling_requested: dismantlingChoice === 'yes' ? '1' : '',
                service_ack_delivery_only_terms: document.getElementById('bsn-service-ack-delivery-only-terms') && document.getElementById('bsn-service-ack-delivery-only-terms').checked ? '1' : '',
                service_location_address: locationAddress,
                // Dati pre-geocodificati dall'autocomplete Places (evitano geocoding lato server)
                service_location_lat:      (window.bsnServiceGeoData[activeAddressId] || {}).lat      || '',
                service_location_lng:      (window.bsnServiceGeoData[activeAddressId] || {}).lng      || '',
                service_location_place_id: (window.bsnServiceGeoData[activeAddressId] || {}).place_id || '',
                service_onsite_contact_name: onsiteName,
                service_onsite_contact_phone: onsitePhone,
                service_logistics_ztl: document.getElementById('bsn-service-logistics-ztl') ? document.getElementById('bsn-service-logistics-ztl').value : '0',
                service_logistics_stairs: document.getElementById('bsn-service-logistics-stairs') ? document.getElementById('bsn-service-logistics-stairs').value : 'none',
                service_logistics_lift: document.getElementById('bsn-service-logistics-lift') ? document.getElementById('bsn-service-logistics-lift').value : '0',
                service_logistics_tight_access: document.getElementById('bsn-service-logistics-tight-access') ? document.getElementById('bsn-service-logistics-tight-access').value : '0',
                service_logistics_walk_distance: document.getElementById('bsn-service-logistics-walk-distance') ? document.getElementById('bsn-service-logistics-walk-distance').value : 'short',
                service_notes: notes,
                service_event_date: document.getElementById('bsn-quote-cart-data-ritiro') ? document.getElementById('bsn-quote-cart-data-ritiro').value : '',
                service_event_start_time: document.getElementById('bsn-service-event-start-time') ? document.getElementById('bsn-service-event-start-time').value : '',
                service_event_end_time: document.getElementById('bsn-service-event-end-time') ? document.getElementById('bsn-service-event-end-time').value : ''
            };
        }

        function syncServiceModeVisibility() {
            var checkedMode = app.querySelector('input[name=\"bsn_service_mode\"]:checked');
            var mode = checkedMode ? checkedMode.value : 'pickup';
            var deliveryChoice = getSelectedRadioValue('bsn_service_delivery_return_choice');
            var dismantlingChoice = getSelectedRadioValue('bsn_service_dismantling_choice');
            app.querySelectorAll('[data-service-mode]').forEach(function(panel) {
                var allowed = String(panel.getAttribute('data-service-mode') || '').split(',');
                var visible = allowed.indexOf(mode) !== -1;
                panel.hidden = !visible;
                panel.style.display = visible ? '' : 'none';
            });
            app.querySelectorAll('[data-service-choice-parent]').forEach(function(panel) {
                var visible = !!getSelectedRadioValue(String(panel.getAttribute('data-service-choice-parent') || ''));
                panel.hidden = !visible;
                panel.style.display = visible ? '' : 'none';
            });
            app.querySelectorAll('.bsn-service-panel-common').forEach(function(panel) {
                var visible = false;
                if (mode === 'delivery_only') {
                    visible = !!deliveryChoice;
                } else if (mode === 'delivery_install') {
                    visible = !!dismantlingChoice;
                } else if (mode === 'delivery_install_tech') {
                    visible = true;
                }
                panel.hidden = !visible;
                panel.style.display = visible ? '' : 'none';
            });
        }

        function callApi(path, method, params) {
            var options = {
                method: method || 'GET',
                credentials: 'same-origin',
                headers: {}
            };

            if (restNonce) {
                options.headers['X-WP-Nonce'] = restNonce;
            }

            if (method && method !== 'GET') {
                var body = new URLSearchParams();
                Object.keys(params || {}).forEach(function(key) {
                    body.append(key, params[key]);
                });
                options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                options.body = body.toString();
            }

            return fetch(root + path, options).then(function(response) {
                return response.json().catch(function() {
                    return { message: 'Risposta non valida dal server.' };
                }).then(function(data) {
                    if (!response.ok) {
                        throw data;
                    }
                    return data;
                });
            });
        }

        function renderMessages(errors) {
            if (!Array.isArray(errors) || !errors.length) {
                return '';
            }
            return '<div class="bsn-public-warning-box"><strong>Controlli da verificare</strong><ul>' +
                errors.map(function(item) {
                    return '<li>' + escHtml(item) + '</li>';
                }).join('') +
            '</ul></div>';
        }

        function renderEditingBanner(data) {
            var editing = data && data.editing ? data.editing : null;
            if (!editing || !editing.noleggio_id) {
                return '';
            }

            if (editing.is_locked) {
                return '' +
                    '<div class="bsn-public-warning-box">' +
                        '<strong>Richiesta non piu modificabile</strong>' +
                        '<p>' + escHtml(editing.message || ('La richiesta ' + editing.noleggio_id + ' e gia stata confermata.')) + '</p>' +
                    '</div>';
            }

            return '' +
                '<div class="bsn-public-success-box">' +
                    '<strong>Stai modificando la richiesta ' + escHtml(editing.noleggio_id) + '</strong>' +
                    '<p>Puoi aggiungere, togliere o cambiare le date finche resta in revisione. Con il prossimo invio aggiornerai questa richiesta, non ne creerai una nuova.</p>' +
                '</div>';
        }

        function renderSubmitBlock(data) {
            var actions = '';
            var isEditing = !!(data && data.editing && data.editing.noleggio_id);
            var noteValue = submitNoteDraft;

            if (!submitNoteInitialized) {
                noteValue = (data && data.editing && data.editing.customer_note) ? data.editing.customer_note : '';
                submitNoteDraft = noteValue;
                submitNoteInitialized = true;
            }

            if (!data.is_logged_in) {
                return '' +
                    '<div class="bsn-quote-cart-submit-box">' +
                        '<h3>Invia la richiesta</h3>' +
                        '<p class="bsn-price-note">Per inviare il preventivo devi prima accedere o creare un account cliente.</p>' +
                        '<div class="bsn-quote-cart-submit-actions">' +
                            '<a class="bsn-public-btn" href="' + escHtml(data.login_url || '#') + '">Accedi</a>' +
                            '<a class="bsn-public-btn bsn-public-btn-ghost" href="' + escHtml(data.register_url || '#') + '">Registrati</a>' +
                        '</div>' +
                    '</div>';
            }

            if (data.customer) {
                actions += '' +
                    '<div class="bsn-quote-cart-customer-box">' +
                        '<h3>Dati account</h3>' +
                        '<div class="bsn-quote-cart-customer-row"><span>Nominativo</span><strong>' + escHtml(data.customer.display_name || '-') + '</strong></div>' +
                        '<div class="bsn-quote-cart-customer-row"><span>Email</span><strong>' + escHtml(data.customer.email || '-') + '</strong></div>' +
                        '<div class="bsn-quote-cart-customer-row"><span>Telefono</span><strong>' + escHtml(data.customer.telefono || '-') + '</strong></div>' +
                        '<div class="bsn-quote-cart-customer-row"><span>Categoria</span><strong>' + escHtml(data.customer.categoria_label || getCategoryLabel(data.customer.categoria || 'standard')) + '</strong></div>' +
                    '</div>';
            }

            actions += '' +
                '<div class="bsn-quote-cart-submit-box">' +
                    '<h3>' + (isEditing ? 'Aggiorna la richiesta' : 'Invia la richiesta') + '</h3>' +
                    '<p class="bsn-price-note">' + (isEditing
                        ? 'Il materiale verra ricalcolato e la richiesta esistente restera in revisione finche il nostro staff non la conferma.'
                        : 'Il materiale non verra bloccato automaticamente: il preventivo restera in revisione operatore finche non riceverai conferma.') + '</p>' +
                    '<label for="bsn-quote-cart-submit-note">Note per il nostro staff</label>' +
                    '<textarea id="bsn-quote-cart-submit-note" class="bsn-quote-cart-submit-note" rows="4" placeholder="Eventuali dettagli utili, tipo di evento, richieste particolari...">' + escHtml(noteValue) + '</textarea>';

            if (Array.isArray(data.submit_blockers) && data.submit_blockers.length) {
                actions += '<div class="bsn-public-warning-box"><strong>Prima dell\'invio</strong><ul>' +
                    data.submit_blockers.map(function(item) {
                        return '<li>' + escHtml(item) + '</li>';
                    }).join('') +
                '</ul></div>';
            }

            actions += '' +
                    '<div class="bsn-quote-cart-submit-actions">' +
                        '<button type="button" class="bsn-public-btn bsn-public-btn-secondary" id="bsn-quote-cart-submit"' + (data.can_submit ? '' : ' disabled') + '>' + (isEditing ? 'Aggiorna richiesta' : 'Invia richiesta di preventivo') + '</button>' +
                    '</div>' +
                '</div>';

            return actions;
        }

        function renderCart(data) {
            if (!data || !data.item_count) {
                app.innerHTML =
                    renderEditingBanner(data || {}) +
                    '<div class="bsn-public-card bsn-quote-cart-empty">' +
                        '<h2>Il carrello e vuoto</h2>' +
                        '<p>Seleziona un prodotto dal catalogo, imposta date e quantita, poi aggiungilo al preventivo.</p>' +
                        '<a class="bsn-public-btn" href="' + escHtml(archiveUrl) + '">Vai al catalogo</a>' +
                    '</div>';
                return;
            }

            var giorniNoleggio = Number(data.giorni_noleggio || 0);
            var dateSummary = '';
            if (data && data.dates && data.dates.data_ritiro && data.dates.data_riconsegna) {
                dateSummary = 'Periodo selezionato: ' + formatDateIt(data.dates.data_ritiro) + ' - ' + formatDateIt(data.dates.data_riconsegna) + '. ';
            }
            var rows = data.items.map(function(item) {
                var warnings = '';
                var errors = '';
                var availabilityLabel = item.availability_badge || 'Da verificare';
                var availabilityCount = data.has_dates
                    ? (String(item.available_units || 0) + ' disponibili per il periodo selezionato')
                    : 'Definisci le date per verificare la disponibilita reale';
                var totalHtml = item.line_total !== null ? euro(item.line_total) + ' <small class="bsn-vat-note-inline">+ IVA</small>' : '-';
                var lineSaving = Number(item.line_savings || 0);
                var savingHtml = lineSaving > 0.009
                    ? '<div class="bsn-quote-cart-line-saving">Risparmio ' + euro(lineSaving) + '</div>'
                    : '';
                var productMeta = '<div class="bsn-quote-cart-product-meta">Prodotto nel preventivo</div>';
                if (item.dimension_summary) {
                    productMeta += '<div class="bsn-quote-cart-product-meta bsn-quote-cart-dimension-meta">' + escHtml(item.dimension_summary) + '</div>';
                }
                if (item.selected_preset_label) {
                    productMeta += '<div class="bsn-quote-cart-product-meta">Preset: ' + escHtml(item.selected_preset_label) + '</div>';
                }
                if (item.dimension_note) {
                    productMeta += '<div class="bsn-quote-cart-product-meta">Nota: ' + escHtml(item.dimension_note) + '</div>';
                }
                if (Number(item.qty_discount_applied_pct || 0) > 0) {
                    productMeta += '<div class="bsn-quote-cart-product-meta">Sconto quantita: -' + escHtml(Number(item.qty_discount_applied_pct || 0).toFixed(2)) + '%</div>';
                }

                if (Array.isArray(item.warning_messages) && item.warning_messages.length) {
                    warnings = '<div class="bsn-quote-cart-inline-note">' + item.warning_messages.map(function(msg) {
                        return escHtml(msg);
                    }).join(' | ') + '</div>';
                }

                if (Array.isArray(item.errors) && item.errors.length) {
                    errors = '<div class="bsn-quote-cart-inline-error">' + item.errors.map(function(msg) {
                        return escHtml(msg);
                    }).join(' | ') + '</div>';
                }

                return '' +
                    '<tr>' +
                        '<td class="bsn-quote-cart-product-cell">' +
                            (item.image_url ? '<img class="bsn-quote-cart-thumb" src="' + escHtml(item.image_url) + '" alt="' + escHtml(item.title) + '">' : '<div class="bsn-quote-cart-thumb bsn-quote-cart-thumb-empty">Img</div>') +
                            '<div>' +
                                '<div class="bsn-quote-cart-product-title"><a href="' + escHtml(item.permalink || '#') + '">' + escHtml(item.title) + '</a></div>' +
                                productMeta +
                            '</div>' +
                        '</td>' +
                        '<td class="bsn-quote-cart-availability-cell">' +
                            '<div class="bsn-live-badge">' + escHtml(availabilityLabel) + '</div>' +
                            '<div class="bsn-quote-cart-product-meta">' + escHtml(availabilityCount) + '</div>' +
                            warnings +
                            errors +
                        '</td>' +
                        '<td>' +
                            '<input type="number" class="bsn-quote-cart-qty" data-product-id="' + escHtml(item.product_id) + '" data-line-key="' + escHtml(item.line_key || '') + '" min="' + escHtml(item.min_qty || 1) + '" value="' + escHtml(item.qty) + '">' +
                            '<div class="bsn-quote-cart-minqty">Min ' + escHtml(item.min_qty || 1) + '</div>' +
                        '</td>' +
                        '<td>' + (item.base_price !== null ? euro(item.base_price) + ' <small class="bsn-vat-note-inline">+ IVA</small>' : '-') + '</td>' +
                        '<td>' + (item.giorni ? escHtml(item.giorni) : '-') + '</td>' +
                        '<td class="bsn-quote-cart-total-cell">' + totalHtml + savingHtml + '</td>' +
                        '<td><button type="button" class="bsn-public-btn bsn-public-btn-ghost bsn-quote-cart-remove" data-product-id="' + escHtml(item.product_id) + '" data-line-key="' + escHtml(item.line_key || '') + '">Rimuovi</button></td>' +
                    '</tr>';
            }).join('');

            app.innerHTML =
                renderEditingBanner(data) +
                renderMessages(data.errors) +
                '<div class="bsn-quote-cart-layout">' +
                    '<section class="bsn-public-card">' +
                        '<div class="bsn-quote-cart-dates">' +
                            '<div>' +
                                '<label for="bsn-quote-cart-data-ritiro">Data ritiro</label>' +
                                '<input type="date" id="bsn-quote-cart-data-ritiro" value="' + escHtml(data.dates.data_ritiro || '') + '">' +
                            '</div>' +
                            '<div>' +
                                '<label for="bsn-quote-cart-data-riconsegna">Data riconsegna</label>' +
                                '<input type="date" id="bsn-quote-cart-data-riconsegna" value="' + escHtml(data.dates.data_riconsegna || '') + '">' +
                            '</div>' +
                            '<div class="bsn-quote-cart-date-actions">' +
                                '<button type="button" class="bsn-public-btn" id="bsn-quote-cart-update-dates">Aggiorna date</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="bsn-quote-cart-date-meta">' + escHtml(dateSummary) + 'Le date qui impostate valgono per tutto il preventivo. Se ti servono periodi diversi, crea una seconda richiesta separata.</div>' +
                        '<div class="bsn-quote-cart-table-wrap">' +
                            '<table class="bsn-tabella bsn-quote-cart-table">' +
                                '<thead>' +
                                    '<tr>' +
                                        '<th>Prodotto</th>' +
                                        '<th>Disponibilita</th>' +
                                        '<th>Quantita</th>' +
                                        '<th>Prezzo base</th>' +
                                        '<th>Giorni</th>' +
                                        '<th>Totale</th>' +
                                        '<th></th>' +
                                    '</tr>' +
                                '</thead>' +
                                '<tbody>' + rows + '</tbody>' +
                            '</table>' +
                        '</div>' +
                    '</section>' +
                    renderServiceSection(data) +
                    '<aside class="bsn-public-card bsn-quote-cart-summary">' +
                        '<div class="bsn-price-label">Riepilogo</div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Prodotti</span><strong>' + escHtml(data.item_count) + '</strong></div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Quantita totale</span><strong>' + escHtml(data.total_qty || 0) + '</strong></div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Giorni noleggio</span><strong>' + escHtml(giorniNoleggio > 0 ? giorniNoleggio : '-') + '</strong></div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Totale prodotti</span><strong>' + euro(data.totale_prodotti_stimato || 0) + ' <small class="bsn-vat-note-inline">+ IVA</small></strong></div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Servizi stimati</span><strong>' + escHtml(serviceEstimatedLabel(data.service || {})) + '</strong></div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Risparmio noleggio scalare</span><strong>' + euro(data.risparmio_scalare_totale || 0) + '</strong></div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Risparmio categoria</span><strong>' + euro(data.risparmio_categoria_totale || 0) + '</strong></div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Risparmio quantita</span><strong>' + euro(data.risparmio_quantita_totale || 0) + '</strong></div>' +
                        '<div class="bsn-quote-cart-summary-row"><span>Risparmio totale</span><strong>' + euro(data.risparmio_totale || 0) + '</strong></div>' +
                        '<div class="bsn-public-quote-total"><strong class="bsn-public-quote-total-label">' + ((data.service && data.service.mode && data.service.mode !== 'pickup' && (!data.service.estimated_total || String(data.service.estimated_total) === '')) ? 'Totale prodotti stimato:' : 'Totale stimato:') + '</strong> <strong class="bsn-public-quote-total-value">' + euro(data.totale_stimato || 0) + ' <small class="bsn-vat-note-inline">+ IVA</small></strong></div>' +
                        '<div class="bsn-price-note">Prezzi IVA esclusa. Stima ' + escHtml(getCategoryLabel(data.categoria_cliente || 'standard')) + '. I servizi logistici e tecnici restano sempre indicativi e verificabili dallo staff.</div>' +
                        renderSubmitBlock(data) +
                        '<div class="bsn-quote-cart-summary-actions">' +
                            '<a class="bsn-public-btn" href="' + escHtml(archiveUrl) + '">Aggiungi altri prodotti</a>' +
                            '<button type="button" class="bsn-public-btn bsn-public-btn-ghost bsn-quote-cart-clear-secondary" id="bsn-quote-cart-clear">Svuota carrello</button>' +
                        '</div>' +
                    '</aside>' +
                '</div>';

            syncServiceModeVisibility();
            // Collega l'autocomplete Places ai campi indirizzo appena renderizzati
            if (window.bsnMapsApiLoaded) { bsnInitServiceAutocomplete(); }
        }
        function refreshCart() {
            callApi('quote-cart', 'GET')
                .then(renderCart)
                .catch(function(error) {
                    app.innerHTML = '<div class="bsn-public-card"><p>' + escHtml((error && error.message) ? error.message : 'Errore nel caricamento del carrello.') + '</p></div>';
                });
        }

        app.addEventListener('click', function(event) {
            var removeBtn = event.target.closest('.bsn-quote-cart-remove');
            var updateDatesBtn = event.target.closest('#bsn-quote-cart-update-dates');
            var updateServiceBtn = event.target.closest('#bsn-quote-cart-update-service');
            var clearBtn = event.target.closest('#bsn-quote-cart-clear');
            var submitBtn = event.target.closest('#bsn-quote-cart-submit');

            if (removeBtn) {
                callApi('quote-cart/remove-item', 'POST', {
                    product_id: removeBtn.getAttribute('data-product-id'),
                    line_key: removeBtn.getAttribute('data-line-key') || ''
                }).then(function(response) {
                    renderCart(response.cart || {});
                }).catch(function(error) {
                    alert((error && error.message) ? error.message : 'Errore durante la rimozione del prodotto.');
                });
                return;
            }

            if (updateDatesBtn) {
                var inputRitiro = document.getElementById('bsn-quote-cart-data-ritiro');
                var inputRiconsegna = document.getElementById('bsn-quote-cart-data-riconsegna');
                var dataRitiro = inputRitiro ? inputRitiro.value : '';
                var dataRiconsegna = inputRiconsegna ? inputRiconsegna.value : '';

                if (dataRitiro && dataRiconsegna && dataRitiro > dataRiconsegna) {
                    alert('La data di riconsegna deve essere uguale o successiva alla data di ritiro.');
                    return;
                }

                callApi('quote-cart/dates', 'POST', {
                    data_ritiro: dataRitiro,
                    data_riconsegna: dataRiconsegna
                }).then(function(response) {
                    renderCart(response.cart || {});
                }).catch(function(error) {
                    alert((error && error.message) ? error.message : 'Errore durante l\'aggiornamento delle date.');
                });
                return;
            }

            if (updateServiceBtn) {
                callApi('quote-cart/service', 'POST', collectServicePayload()).then(function(response) {
                    renderCart(response.cart || {});
                }).catch(function(error) {
                    alert((error && error.message) ? error.message : 'Errore durante l\'aggiornamento dei servizi.');
                });
                return;
            }

            if (clearBtn) {
                if (!window.confirm('Vuoi svuotare il carrello preventivo?')) {
                    return;
                }
                callApi('quote-cart/clear', 'POST', {}).then(function(response) {
                    renderCart(response.cart || {});
                }).catch(function(error) {
                    alert((error && error.message) ? error.message : 'Errore durante lo svuotamento del carrello.');
                });
                return;
            }

            if (submitBtn) {
                var isEditingSubmit = !!(submitBtn.textContent && submitBtn.textContent.indexOf('Aggiorna') !== -1);
                var servicePayload = collectServicePayload();
                submitBtn.disabled = true;
                submitBtn.textContent = isEditingSubmit ? 'Aggiornamento in corso...' : 'Invio in corso...';

                callApi('quote-cart/submit', 'POST', {
                    note: submitNoteDraft,
                    service_mode: servicePayload.service_mode,
                    service_delivery_return_choice: servicePayload.service_delivery_return_choice,
                    service_dismantling_choice: servicePayload.service_dismantling_choice,
                    service_delivery_return_requested: servicePayload.service_delivery_return_requested,
                    service_dismantling_requested: servicePayload.service_dismantling_requested,
                    service_ack_delivery_only_terms: servicePayload.service_ack_delivery_only_terms,
                    service_location_address: servicePayload.service_location_address,
                    service_onsite_contact_name: servicePayload.service_onsite_contact_name,
                    service_onsite_contact_phone: servicePayload.service_onsite_contact_phone,
                    service_logistics_ztl: servicePayload.service_logistics_ztl,
                    service_logistics_stairs: servicePayload.service_logistics_stairs,
                    service_logistics_lift: servicePayload.service_logistics_lift,
                    service_logistics_tight_access: servicePayload.service_logistics_tight_access,
                    service_logistics_walk_distance: servicePayload.service_logistics_walk_distance,
                    service_notes: servicePayload.service_notes,
                    service_event_date: servicePayload.service_event_date,
                    service_event_start_time: servicePayload.service_event_start_time,
                    service_event_end_time: servicePayload.service_event_end_time
                }).then(function(response) {
                    submitNoteDraft = '';
                    if (response && response.redirect_url) {
                        window.location.href = response.redirect_url;
                        return;
                    }
                    alert('Richiesta inviata correttamente.');
                    renderCart(response.cart || {});
                }).catch(function(error) {
                    alert((error && error.message) ? error.message : 'Errore durante l\'invio della richiesta di preventivo.');
                    refreshCart();
                });
            }
        });

        app.addEventListener('change', function(event) {
            var qtyInput = event.target.closest('.bsn-quote-cart-qty');
            var serviceModeInput = event.target.closest('input[name="bsn_service_mode"]');
            var serviceChoiceInput = event.target.closest('input[name="bsn_service_delivery_return_choice"], input[name="bsn_service_dismantling_choice"]');
            if (serviceModeInput) {
                syncServiceModeVisibility();
                return;
            }
            if (serviceChoiceInput) {
                syncServiceModeVisibility();
                return;
            }
            if (!qtyInput) {
                return;
            }

            callApi('quote-cart/update-item', 'POST', {
                product_id: qtyInput.getAttribute('data-product-id'),
                line_key: qtyInput.getAttribute('data-line-key') || '',
                qty: qtyInput.value || 1
            }).then(function(response) {
                renderCart(response.cart || {});
            }).catch(function(error) {
                alert((error && error.message) ? error.message : 'Errore durante l\'aggiornamento della quantita.');
                refreshCart();
            });
        });

        app.addEventListener('input', function(event) {
            var noteInput = event.target.closest('#bsn-quote-cart-submit-note');
            if (!noteInput) {
                return;
            }

            submitNoteDraft = noteInput.value || '';
            submitNoteInitialized = true;
        });

        refreshCart();
    })();
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode( 'blackstar_rientro', 'bsn_shortcode_rientro' );
function bsn_shortcode_rientro() {
    if ( ! bsn_check_admin() ) {
        return '<p>Accesso negato. Permesso riservato agli operatori autorizzati.</p>';
    }

    ob_start();
    bsn_render_rientro_noleggio_page();
    return ob_get_clean();
}


/**
 * Enqueue CSS/JS solo dove serve
 */
function bsn_enqueue_assets() {
    $is_public_catalog = is_singular( 'bs_prodotto' ) || is_post_type_archive( 'bs_prodotto' ) || is_tax( 'bs_categoria_prodotto' );
    $post = get_post();
    if ( ! $is_public_catalog && ! is_singular() ) {
        return;
    }

    $has_app = $post ? has_shortcode( $post->post_content, 'blackstar_noleggi' ) : false;
    $has_finalizza = $post ? has_shortcode( $post->post_content, 'blackstar_finalizza' ) : false;
    $has_ispeziona = $post ? has_shortcode( $post->post_content, 'blackstar_ispeziona' ) : false;
    $has_rientro = $post ? has_shortcode( $post->post_content, 'blackstar_rientro' ) : false;
    $has_preventivo = $post ? has_shortcode( $post->post_content, 'blackstar_preventivo' ) : false;
    $has_preventivi = $post ? has_shortcode( $post->post_content, 'blackstar_preventivi' ) : false;
    $has_quote_cart = $post ? has_shortcode( $post->post_content, 'blackstar_carrello_noleggio' ) : false;
    $has_login_cliente = $post ? has_shortcode( $post->post_content, 'blackstar_login_cliente' ) : false;
    $has_registrazione_cliente = $post ? has_shortcode( $post->post_content, 'blackstar_registrazione_cliente' ) : false;
    $has_area_cliente = $post ? has_shortcode( $post->post_content, 'blackstar_area_cliente' ) : false;
    $has_quote_submitted = $post ? has_shortcode( $post->post_content, 'blackstar_conferma_preventivo' ) : false;
    $bsn_style_version = file_exists( BSN_PATH . 'assets/css/style.css' ) ? (string) filemtime( BSN_PATH . 'assets/css/style.css' ) : BSN_VERSION;
    $bsn_script_version = file_exists( BSN_PATH . 'assets/js/script.js' ) ? (string) filemtime( BSN_PATH . 'assets/js/script.js' ) : BSN_VERSION;

    if ( $has_app ) {
        wp_enqueue_style( 'bsn-style', BSN_URL . 'assets/css/style.css', [], $bsn_style_version );
        wp_enqueue_script( 'bsn-script', BSN_URL . 'assets/js/script.js', ['jquery'], $bsn_script_version, true );
               
        // Includi libreria qrcode.js da CDN
        wp_enqueue_script(
            'qrcode-js',
            'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
            [],
            '1.0.0',
            true
        );
        wp_localize_script('bsn-script', 'BSN_API', [
            'root'  => esc_url_raw(rest_url('bsn/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'preventivo_url' => esc_url_raw( bsn_get_preventivo_page_url() ),
        ]);
    }

     if ( $has_finalizza || $has_ispeziona || $has_rientro || $has_preventivo || $has_preventivi ) {
        wp_enqueue_style( 'bsn-style-frontend-doc', BSN_URL . 'assets/css/style.css', [], $bsn_style_version );
        wp_enqueue_script( 'jquery' );
        wp_register_script(
            'bsn-frontend-doc',
            '',
            [ 'jquery' ],
            BSN_VERSION,
            true
        );
        wp_enqueue_script( 'bsn-frontend-doc' );
        wp_localize_script(
            'bsn-frontend-doc',
            'BSN_API',
            [
                'root'  => esc_url_raw( rest_url( 'bsn/v1/' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ]
        );
    }

    if ( $is_public_catalog || $has_quote_cart || $has_login_cliente || $has_registrazione_cliente || $has_area_cliente || $has_quote_submitted ) {
        wp_enqueue_style( 'bsn-style-public-catalog', BSN_URL . 'assets/css/style.css', [], $bsn_style_version );
    }
}

function bsn_ensure_quote_cart_page() {
    $page_id = get_option( 'bsn_quote_cart_page_id' );
    if ( $page_id && get_post( $page_id ) ) {
        return;
    }

    $page = get_page_by_path( 'carrello-noleggio' );
    if ( $page ) {
        update_option( 'bsn_quote_cart_page_id', $page->ID );
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $page_id = wp_insert_post(
        [
            'post_title'   => 'Carrello Noleggio',
            'post_name'    => 'carrello-noleggio',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[blackstar_carrello_noleggio]',
        ]
    );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        update_option( 'bsn_quote_cart_page_id', $page_id );
    }
}

function bsn_ensure_quote_submitted_page() {
    $page_id = get_option( 'bsn_quote_submitted_page_id' );
    if ( $page_id && get_post( $page_id ) ) {
        return;
    }

    $page = get_page_by_path( 'preventivo-inviato' );
    if ( $page ) {
        update_option( 'bsn_quote_submitted_page_id', $page->ID );
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $page_id = wp_insert_post(
        [
            'post_title'   => 'Preventivo Inviato',
            'post_name'    => 'preventivo-inviato',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[blackstar_conferma_preventivo]',
        ]
    );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        update_option( 'bsn_quote_submitted_page_id', $page_id );
    }
}
add_action('wp_enqueue_scripts', 'bsn_enqueue_assets');

/**
 * Enqueue JS in admin per pagina Finalizza Noleggio
 */
function bsn_enqueue_admin_finalizza( $hook ) {
    // Carica solo sulla pagina admin.php?page=bsn-finalizza-noleggio
    if ( $hook !== 'toplevel_page_blackstar-noleggi'
         && $hook !== 'noleggi_page_bsn-finalizza-noleggio'
         && $hook !== 'admin_page_bsn-finalizza-noleggio'
    ) {
        return;
    }

    // CSS dell'app anche in admin, così vale @media print
    wp_enqueue_style(
        'bsn-style-admin',
        BSN_URL . 'assets/css/style.css',
        [],
        BSN_VERSION
    );

    // jQuery è già in admin, ma per sicurezza:
    wp_enqueue_script( 'jquery' );

    // Script vuoto solo per avere BSN_API
    wp_register_script(
        'bsn-admin-finalizza',
        '',
        [ 'jquery' ],
        BSN_VERSION,
        true
    );

    wp_enqueue_script( 'bsn-admin-finalizza' );

    wp_localize_script(
        'bsn-admin-finalizza',
        'BSN_API',
        [
            'root'  => esc_url_raw( rest_url( 'bsn/v1/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ]
    );
}
add_action( 'admin_enqueue_scripts', 'bsn_enqueue_admin_finalizza' );

function bsn_enqueue_admin_bs_prodotto_scripts( $hook ) {
    global $post;
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
        return;
    }
    if ( empty( $post ) || $post->post_type !== 'bs_prodotto' ) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_script(
        'bsn-admin-prodotto',
        BSN_URL . 'assets/js/admin-prodotto.js',
        [ 'jquery', 'media-upload' ],
        BSN_VERSION,
        true
    );
}
add_action( 'admin_enqueue_scripts', 'bsn_enqueue_admin_bs_prodotto_scripts' );

/**
 * Endpoint cambio stato noleggio
 */
add_action('rest_api_init', function () {
    register_rest_route('bsn/v1', '/noleggi/stato', array(
        'methods'             => 'POST',
        'callback'            => 'bsn_cambia_stato_noleggio',
                'permission_callback' => function () {
            return bsn_check_admin();
        },

        'args'                => array(
            'id_noleggio' => array(
                'required' => true,
                'type'     => 'string',
            ),
            'nuovo_stato' => array(
                'required' => true,
                'type'     => 'string',
            ),
        ),
    ));
});

// === Endpoint Rientro noleggio (attivo/ritardo -> chiuso) ===
add_action('rest_api_init', function () {
    register_rest_route(
        'bsn/v1',
        '/noleggi/rientro',
        array(
            'methods'             => 'POST',
            'callback'            => 'bsn_api_noleggi_rientro',
            'permission_callback' => 'bsn_check_admin',
        )
    );
});

/**
 * API REST base (Clienti + Articoli + Noleggi)
 */
add_action('rest_api_init', 'bsn_register_routes');
function bsn_register_routes() {
    // CLIENTI
    register_rest_route('bsn/v1', '/clienti', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_clienti_get',
        'permission_callback' => 'bsn_check_admin',
    ]);

     register_rest_route('bsn/v1', '/clienti/search', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_clienti_search',
        'permission_callback' => 'bsn_check_admin',
    ]);

        register_rest_route('bsn/v1', '/clienti/delete', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_clienti_delete',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [
                'required' => true,
                'type'     => 'integer',
            ],
        ],
    ]);

    register_rest_route('bsn/v1', '/clienti', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_clienti_post',
        'permission_callback' => 'bsn_check_admin',
    ]);

        // CLIENTI UPDATE
    register_rest_route('bsn/v1', '/clienti/update', [
        'methods'             => 'POST', // possiamo usare anche 'PUT', ma JS ora usa POST
        'callback'            => 'bsn_api_clienti_update',
        'permission_callback' => 'bsn_check_admin',
    ]);

        register_rest_route('bsn/v1', '/articoli', [
        'methods'             => 'PUT',
        'callback'            => 'bsn_api_articoli_put',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli/delete', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_articoli_delete',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [
                'required' => true,
                'type'     => 'integer',
            ],
        ],
    ]);

    register_rest_route('bsn/v1', '/articoli/clone', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_articoli_clone',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [
                'required' => true,
                'type'     => 'integer',
            ],
        ],
    ]);

    // ARTICOLI
    register_rest_route('bsn/v1', '/articoli', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_articoli_get',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli/search', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_articoli_search',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/migration/status', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_migration_status',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/migration/articoli', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_migration_articoli_get',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_articoli_post',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli/woocommerce', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_articoli_woocommerce',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'external_product_url' => [ 'required' => true, 'type' => 'string' ],
        ],
    ]);


     register_rest_route('bsn/v1', '/articoli/export', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_articoli_export',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli/magazzino-export', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_articoli_magazzino_export',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli/magazzino-print', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_articoli_magazzino_print',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli/etichette', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_articoli_etichette_get',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli/etichette-export', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_articoli_etichette_export',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/articoli/etichette-stato', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_articoli_etichette_stato',
        'permission_callback' => 'bsn_check_admin',
    ]);

        // PROFILI SCONTI (precompilazione articoli)
    register_rest_route('bsn/v1', '/profili-sconti', array(
        'methods'  => 'GET',
        'callback' => 'bsn_api_profili_sconti_get',
        'permission_callback' => 'bsn_check_admin',
    ));

    register_rest_route('bsn/v1', '/profili-sconti', array(
        'methods'  => 'POST',
        'callback' => 'bsn_api_profili_sconti_create',
        'permission_callback' => 'bsn_check_admin',
    ));

    register_rest_route('bsn/v1', '/profili-sconti/delete', array(
        'methods'  => 'POST',
        'callback' => 'bsn_api_profili_sconti_delete',
        'permission_callback' => 'bsn_check_admin',
    ));

        // NOLEGGI (GET + POST)
    register_rest_route('bsn/v1', '/noleggi', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_noleggi_get',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/noleggi', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_noleggi_post',
        'permission_callback' => 'bsn_check_admin',
    ]);

    // PROFILI SCONTI (precompilazione articoli)
    register_rest_route('bsn/v1', '/profili-sconti', array(
        'methods' => 'GET',
        'callback' => 'bsn_api_profili_sconti_get',
        'permission_callback' => 'bsn_check_admin',
    ));
    register_rest_route('bsn/v1', '/profili-sconti', array(
        'methods' => 'POST',
        'callback' => 'bsn_api_profili_sconti_create',
        'permission_callback' => 'bsn_check_admin',
    ));
    register_rest_route('bsn/v1', '/profili-sconti/delete', array(
        'methods' => 'POST',
        'callback' => 'bsn_api_profili_sconti_delete',
        'permission_callback' => 'bsn_check_admin',
    ));

    // NOLEGGI – DUPLICA
        register_rest_route('bsn/v1', '/noleggi/delete', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_noleggi_delete',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [
                'required' => true,
                'type'     => 'string',
            ],
        ],
    ]);
}

// === STEP A1: Controllo permessi unico per tutta l'app ===
function bsn_check_admin( $request = null ) {
    // Admin classico
    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }

    // Editor (se lo usi per l'app)
    if ( current_user_can( 'edit_others_posts' ) ) {
        return true;
    }

    // Ruolo/capability dedicata all'app noleggi
    if ( current_user_can( 'bsn_manage_noleggi' ) ) {
        return true;
    }

    return false;
}

/**
 * Endpoint upload documento cliente
 * POST /wp-json/bsn/v1/upload-doc
 * Accetta un file (campo "file") e restituisce l'URL.
 */
add_action('rest_api_init', function () {
    register_rest_route('bsn/v1', '/upload-doc', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_upload_doc',
        'permission_callback' => 'bsn_check_admin',
    ]);
});

function bsn_api_upload_doc( WP_REST_Request $request ) {
    if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
        return new WP_Error(
            'bsn_no_file',
            'Nessun file ricevuto',
            array( 'status' => 400 )
        );
    }

    $file = $_FILES['file'];

    // Limita a immagini
    $allowed_types = [ 'image/jpeg', 'image/jpg', 'image/png' ];
    if ( ! in_array( $file['type'], $allowed_types, true ) ) {
        return new WP_Error( 'bsn_file_type', 'Tipo di file non valido. Usa JPG o PNG.', [ 'status' => 400 ] );
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Carica nel media library, senza associare a post specifico
    $overrides = [ 'test_form' => false ];

    $file_info = wp_handle_upload( $file, $overrides );

    if ( isset( $file_info['error'] ) ) {
        return new WP_Error( 'bsn_upload_error', $file_info['error'], [ 'status' => 500 ] );
    }

    // Crea attachment
    $file_type = wp_check_filetype( $file_info['file'], null );
    $attachment = [
        'post_mime_type' => $file_type['type'],
        'post_title'     => sanitize_file_name( basename( $file_info['file'] ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

       $attach_id = wp_insert_attachment( $attachment, $file_info['file'] );

    if ( is_wp_error( $attach_id ) || ! $attach_id ) {
        return new WP_Error(
            'bsn_attach_error',
            'Errore nel creare l\'attachment',
            array( 'status' => 500 )
        );
    }

    $attach_data = wp_generate_attachment_metadata( $attach_id, $file_info['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    $url = wp_get_attachment_url( $attach_id );

    return rest_ensure_response( array(
        'success' => true,
        'id'      => $attach_id,
        'url'     => $url,
    ) );
}

/* ==== CLIENTI ==== */

function bsn_api_clienti_get($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_clienti';
    
    $search = trim( (string) $request->get_param( 'search' ) );
    $limit  = absint( $request->get_param( 'limit' ) );
    if ( $limit < 1 ) {
        $limit = 12;
    }
    if ( $limit > 100 ) {
        $limit = 100;
    }

    if ( $search !== '' ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $sql  = $wpdb->prepare(
            "SELECT * FROM $table
             WHERE
                CAST(id AS CHAR) LIKE %s
                OR nome LIKE %s
                OR cf_piva LIKE %s
                OR telefono LIKE %s
                OR email LIKE %s
                OR citta LIKE %s
             ORDER BY data_creazione DESC
             LIMIT %d",
            $like, $like, $like, $like, $like, $like, 100
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT * FROM $table ORDER BY data_creazione DESC LIMIT %d",
            $limit
        );
    }

    $clienti = $wpdb->get_results($sql, ARRAY_A);
    return rest_ensure_response(['clienti' => $clienti]);
}

function bsn_api_clienti_search( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_clienti';

    $id     = absint( $request->get_param( 'id' ) );
    $search = trim( (string) $request->get_param( 'search' ) );
    $limit  = absint( $request->get_param( 'limit' ) );

    if ( $limit < 1 ) {
        $limit = 15;
    }
    if ( $limit > 25 ) {
        $limit = 25;
    }

    if ( $id > 0 ) {
        $sql = $wpdb->prepare(
            "SELECT *
             FROM $table
             WHERE id = %d
             LIMIT 1",
            $id
        );
        $cliente = $wpdb->get_row( $sql, ARRAY_A );
        return rest_ensure_response( array( 'clienti' => $cliente ? array( $cliente ) : array() ) );
    }
    
    if ( $search === '' || strlen( $search ) < 2 ) {
        return rest_ensure_response( array( 'clienti' => array() ) );
    }

    $like = '%' . $wpdb->esc_like( $search ) . '%';
    $sql  = $wpdb->prepare(
         "SELECT *
         FROM $table
         WHERE CAST(id AS CHAR) LIKE %s
            OR nome LIKE %s
            OR cf_piva LIKE %s
            OR telefono LIKE %s
            OR email LIKE %s
            OR citta LIKE %s
         ORDER BY data_creazione DESC
         LIMIT %d",
        $like,
        $like,
        $like,
        $like,
        $like,
        $like,
        $limit
    );

    $clienti = $wpdb->get_results( $sql, ARRAY_A );

    return rest_ensure_response( array( 'clienti' => $clienti ) );
}

function bsn_api_clienti_post($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_clienti';

    $nome       = sanitize_text_field($request->get_param('nome'));
    $cf_piva    = sanitize_text_field($request->get_param('cf_piva'));
    $indirizzo  = sanitize_textarea_field($request->get_param('indirizzo'));
    $telefono   = sanitize_text_field($request->get_param('telefono'));
    $email      = sanitize_email($request->get_param('email'));

    // NUOVO: campi aggiuntivi
    $cap        = sanitize_text_field($request->get_param('cap'));
    $citta      = sanitize_text_field($request->get_param('citta'));

    $categoria_cliente  = sanitize_text_field($request->get_param('categoria_cliente'));
    $regime_percentuale = floatval($request->get_param('regime_percentuale')) ?: 22.00;
    $regime_note        = sanitize_text_field($request->get_param('regime_note'));

    // NUOVO: documenti
    $doc_fronte       = esc_url_raw($request->get_param('doc_fronte'));
    $doc_retro        = esc_url_raw($request->get_param('doc_retro'));
    $tipo_documento   = sanitize_text_field($request->get_param('tipo_documento'));
    $numero_documento = sanitize_text_field($request->get_param('numero_documento'));

    // Note
    $note = sanitize_textarea_field($request->get_param('note'));

    if (empty($nome)) {
        return new WP_Error('bsn_no_nome', 'Il nome è obbligatorio', ['status' => 400]);
    }

    $result = $wpdb->insert($table, [
        'nome'               => $nome,
        'cf_piva'            => $cf_piva,
        'indirizzo'          => $indirizzo,
        'cap'                => $cap,
        'citta'              => $citta,
        'telefono'           => $telefono,
        'email'              => $email,
        'categoria_cliente'  => $categoria_cliente,
        'regime_percentuale' => $regime_percentuale,
        'regime_note'        => $regime_note,
        'doc_fronte'         => $doc_fronte,
        'doc_retro'          => $doc_retro,
        'tipo_documento'     => $tipo_documento,
        'numero_documento'   => $numero_documento,
        'note'               => $note,
        'data_creazione'     => current_time('mysql'),
    ]);

    if (!$result) {
        return new WP_Error('bsn_insert_error', 'Errore nel salvataggio cliente', ['status' => 500]);
    }

    return rest_ensure_response([
        'success' => true,
        'id'      => $wpdb->insert_id,
    ]);
}

function bsn_api_clienti_update( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_clienti';

    $cliente_id = intval( $request->get_param('cliente_id') );
    if ( ! $cliente_id ) {
        return new WP_Error(
            'bsn_cliente_id_mancante',
            'ID cliente mancante per l\'aggiornamento',
            [ 'status' => 400 ]
        );
    }

    // Leggi tutti i campi come in POST
    $nome       = sanitize_text_field($request->get_param('nome'));
    $cf_piva    = sanitize_text_field($request->get_param('cf_piva'));
    $indirizzo  = sanitize_textarea_field($request->get_param('indirizzo'));
    $telefono   = sanitize_text_field($request->get_param('telefono'));
    $email      = sanitize_email($request->get_param('email'));

    $cap        = sanitize_text_field($request->get_param('cap'));
    $citta      = sanitize_text_field($request->get_param('citta'));

    $categoria_cliente  = sanitize_text_field($request->get_param('categoria_cliente'));
    $regime_percentuale = floatval($request->get_param('regime_percentuale')) ?: 22.00;
    $regime_note        = sanitize_text_field($request->get_param('regime_note'));

    $doc_fronte       = esc_url_raw($request->get_param('doc_fronte'));
    $doc_retro        = esc_url_raw($request->get_param('doc_retro'));
    $tipo_documento   = sanitize_text_field($request->get_param('tipo_documento'));
    $numero_documento = sanitize_text_field($request->get_param('numero_documento'));

    $note = sanitize_textarea_field($request->get_param('note'));

    if (empty($nome)) {
        return new WP_Error('bsn_no_nome', 'Il nome è obbligatorio', ['status' => 400]);
    }

    $data_update = [
        'nome'               => $nome,
        'cf_piva'            => $cf_piva,
        'indirizzo'          => $indirizzo,
        'cap'                => $cap,
        'citta'              => $citta,
        'telefono'           => $telefono,
        'email'              => $email,
        'categoria_cliente'  => $categoria_cliente,
        'regime_percentuale' => $regime_percentuale,
        'regime_note'        => $regime_note,
        'doc_fronte'         => $doc_fronte,
        'doc_retro'          => $doc_retro,
        'tipo_documento'     => $tipo_documento,
        'numero_documento'   => $numero_documento,
        'note'               => $note,
    ];

    $updated = $wpdb->update(
        $table,
        $data_update,
        [ 'id' => $cliente_id ],
        null,
        [ '%d' ]
    );

    if ( $updated === false ) {
        return new WP_Error(
            'bsn_update_cliente_error',
            'Errore nell\'aggiornamento cliente',
            [ 'status' => 500 ]
        );
    }

    return rest_ensure_response([
        'success' => true,
        'id'      => $cliente_id,
    ]);
}

/* ==== CLIENTI: DELETE ==== */

function bsn_api_clienti_delete( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_clienti';

    $id = intval( $request->get_param( 'id' ) );
    if ( ! $id ) {
        return new WP_Error(
            'bsn_cliente_id_mancante',
            'ID cliente mancante',
            [ 'status' => 400 ]
        );
    }

    $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

    if ( $deleted === false ) {
        return new WP_Error(
            'bsn_delete_cliente_error',
            'Errore nella cancellazione cliente',
            [ 'status' => 500 ]
        );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $id,
    ] );
}

/* ==== ARTICOLI ==== */

function bsn_get_wc_api_credentials() {
    $base_url = defined( 'BSN_WC_COM_URL' ) ? rtrim( constant( 'BSN_WC_COM_URL' ), '/' ) : '';
    $consumer_key = defined( 'BSN_WC_COM_CK' ) ? constant( 'BSN_WC_COM_CK' ) : '';
    $consumer_secret = defined( 'BSN_WC_COM_CS' ) ? constant( 'BSN_WC_COM_CS' ) : '';

    if ( $base_url && $consumer_key && $consumer_secret ) {
        return [
            'base_url'       => $base_url,
            'consumer_key'    => $consumer_key,
            'consumer_secret' => $consumer_secret,
        ];
    }

    return new WP_Error(
        'bsn_wc_credentials_missing',
        'Credenziali WooCommerce non disponibili.',
        [ 'status' => 500 ]
    );
}

function bsn_extract_wc_product_slug( $url ) {
    $parsed = wp_parse_url( $url );
    if ( empty( $parsed['path'] ) ) {
        return '';
    }

    $path = trim( $parsed['path'], '/' );
    if ( $path === '' ) {
        return '';
    }

    $segments = explode( '/', $path );
    return sanitize_title( end( $segments ) );
}

function bsn_log_wc_error( $endpoint, $status, $body, $error_message = '' ) {
    $snippet = substr( wp_strip_all_tags( (string) $body ), 0, 200 );
    $message = 'BSN WC: endpoint=' . $endpoint . ' status=' . $status;
    if ( $error_message ) {
        $message .= ' error=' . $error_message;
    }
    if ( $snippet !== '' ) {
        $message .= ' body=' . $snippet;
    }
    error_log( $message );
}

function bsn_fetch_wc_product_data( $slug ) {
    $slug = sanitize_title( $slug );
    if ( $slug === '' ) {
        return new WP_Error( 'bsn_wc_slug_missing', 'Slug prodotto mancante.', [ 'status' => 400 ] );
    }

    $cache_key = 'bsn_wc_product_' . md5( $slug );
    $cached = get_transient( $cache_key );
    if ( ! empty( $cached ) ) {
        return $cached;
    }

    $creds = bsn_get_wc_api_credentials();
    if ( is_wp_error( $creds ) ) {
        return $creds;
    }

    $endpoint = trailingslashit( $creds['base_url'] ) . 'wp-json/wc/v3/products';
    $query_args = [
        'slug' => $slug,
    ];

    $auth_header = 'Basic ' . base64_encode( $creds['consumer_key'] . ':' . $creds['consumer_secret'] );
    $endpoint_url = add_query_arg( $query_args, $endpoint );
    $response = wp_remote_get(
        $endpoint_url,
        [
            'timeout' => 12,
            'headers' => [
                'Authorization' => $auth_header,
            ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        bsn_log_wc_error( $endpoint_url, 'wp_error', '', $response->get_error_message() );
        return new WP_Error( 'bsn_wc_api_error', $response->get_error_message(), [ 'status' => 500 ] );
    }

    $status = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    if ( $status === 401 || $status === 403 ) {
        $fallback_args = [
            'slug'            => $slug,
            'consumer_key'    => $creds['consumer_key'],
            'consumer_secret' => $creds['consumer_secret'],
        ];
        $fallback_url = add_query_arg( $fallback_args, $endpoint );
        $response = wp_remote_get(
            $fallback_url,
            [
                'timeout' => 12,
            ]
        );
        if ( is_wp_error( $response ) ) {
            bsn_log_wc_error( $fallback_url, 'wp_error', '', $response->get_error_message() );
            return new WP_Error( 'bsn_wc_api_error', $response->get_error_message(), [ 'status' => 500 ] );
        }
        $status = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
    }

    if ( $status < 200 || $status >= 300 ) {
        $logged_endpoint = isset( $fallback_url ) ? $fallback_url : $endpoint_url;
        bsn_log_wc_error( $logged_endpoint, $status, $body );
        $message = 'Errore WooCommerce REST API.';
        if ( $status === 401 || $status === 403 ) {
            $message = $status . ' Unauthorized: chiavi errate o permessi insufficienti.';
        } elseif ( $status === 404 ) {
            $message = '404: prodotto non trovato per slug.';
        } elseif ( $status === 408 ) {
            $message = 'Timeout durante la chiamata WooCommerce.';
        }
        return new WP_Error( 'bsn_wc_api_error', $message, [ 'status' => $status ] );
    }

    $decoded = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) || empty( $decoded ) ) {
        bsn_log_wc_error( $endpoint_url, $status, $body, 'empty_response' );
        return new WP_Error( 'bsn_wc_empty', '404: prodotto non trovato per slug.', [ 'status' => 404 ] );
    }

    $product = $decoded[0];
    $data = [
        'external_product_slug' => $slug,
        'external_product_name' => $product['name'] ?? '',
        'external_product_url'  => $product['permalink'] ?? '',
        'external_image_url'    => $product['images'][0]['src'] ?? '',
    ];

    set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );

    return $data;
}

function bsn_api_articoli_woocommerce( WP_REST_Request $request ) {
    $url = trim( (string) $request->get_param( 'external_product_url' ) );
    if ( $url === '' ) {
        return new WP_Error( 'bsn_wc_url_missing', 'URL prodotto mancante.', [ 'status' => 400 ] );
    }

    $slug = bsn_extract_wc_product_slug( $url );
    if ( $slug === '' ) {
        return new WP_Error( 'bsn_wc_slug_missing', 'Slug prodotto non valido.', [ 'status' => 400 ] );
    }

    $creds = bsn_get_wc_api_credentials();
    if ( is_wp_error( $creds ) ) {
        return $creds;
    }
    $endpoint = trailingslashit( $creds['base_url'] ) . 'wp-json/wc/v3/products?slug=' . rawurlencode( $slug );

    $data = bsn_fetch_wc_product_data( $slug );
    if ( is_wp_error( $data ) ) {
        $error_data = $data->get_error_data();
        if ( ! is_array( $error_data ) ) {
            $error_data = [];
        }
        $error_data['slug'] = $slug;
        $error_data['endpoint'] = $endpoint;
        return new WP_Error( $data->get_error_code(), $data->get_error_message(), $error_data );
    }

    $data['external_last_sync'] = current_time( 'mysql' );
    $data['external_product_slug'] = $slug;
    $data['external_product_url'] = $data['external_product_url'] ?: $url;
    $data['success'] = true;
    $data['endpoint'] = $endpoint;

    return rest_ensure_response( $data );
}

function bsn_articoli_query_where( $search ) {
    global $wpdb;

    $search = trim( (string) $search );
    $where = '1=1';
    $params = array();

    if ( $search !== '' ) {
        $matching_public_product_ids = bsn_find_public_product_ids_by_search( $search );
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $search_where = array(
            'CAST(id AS CHAR) LIKE %s',
            'nome LIKE %s',
            'codice LIKE %s',
            'ubicazione LIKE %s',
            'note LIKE %s',
            'note_logistiche LIKE %s',
            'external_product_name LIKE %s',
            'external_product_slug LIKE %s',
        );
        $params = array( $like, $like, $like, $like, $like, $like, $like, $like );

        if ( ! empty( $matching_public_product_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $matching_public_product_ids ), '%d' ) );
            $search_where[] = "prodotto_pubblico_id IN ($placeholders)";
            $params = array_merge( $params, $matching_public_product_ids );
        }

        $where = '(' . implode( ' OR ', $search_where ) . ')';
    }

    return array( $where, $params );
}

function bsn_articoli_query_rows( $search = '', $limit = 100, $offset = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    list( $where, $params ) = bsn_articoli_query_where( $search );

    $limit = max( 1, absint( $limit ) );
    $offset = max( 0, absint( $offset ) );

    $params[] = $limit;
    $params[] = $offset;

    $sql = $wpdb->prepare(
        "SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
        $params
    );

    return $wpdb->get_results( $sql, ARRAY_A );
}

function bsn_articoli_query_total( $search = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    list( $where, $params ) = bsn_articoli_query_where( $search );
    $sql = "SELECT COUNT(*) FROM $table WHERE $where";

    if ( ! empty( $params ) ) {
        $sql = $wpdb->prepare( $sql, $params );
    }

    return (int) $wpdb->get_var( $sql );
}

function bsn_api_articoli_get( WP_REST_Request $request ) {
    $search = trim( (string) $request->get_param( 'search' ) );
    $limit  = absint( $request->get_param( 'limit' ) );
    if ( $limit < 1 ) {
        $limit = 12;
    }
    if ( $limit > 100 ) {
        $limit = 100;
    }

    $page = absint( $request->get_param( 'page' ) );
    if ( $page < 1 ) {
        $page = 1;
    }

    $total = bsn_articoli_query_total( $search );
    $total_pages = $limit > 0 ? max( 1, (int) ceil( $total / $limit ) ) : 1;
    if ( $page > $total_pages ) {
        $page = $total_pages;
    }

    $offset = ( $page - 1 ) * $limit;
    $rows = bsn_articoli_query_rows( $search, $limit, $offset );
    $rows = bsn_enrich_articoli_rows( $rows );

    return rest_ensure_response( array(
        'success'     => true,
        'articoli'    => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $limit,
        'total_pages' => $total_pages,
        'has_more'    => ( $offset + count( $rows ) ) < $total,
        'from'        => $total > 0 ? $offset + 1 : 0,
        'to'          => min( $total, $offset + count( $rows ) ),
    ) );
}

function bsn_api_migration_status( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';
    $posts_table = $wpdb->posts;

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    $max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM $table" );
    $linked_id_present = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE prodotto_pubblico_id IS NOT NULL AND prodotto_pubblico_id > 0" );
    $linked_existing = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM $table a
         INNER JOIN $posts_table p ON p.ID = a.prodotto_pubblico_id
         WHERE a.prodotto_pubblico_id IS NOT NULL
           AND a.prodotto_pubblico_id > 0
           AND p.post_type = 'bs_prodotto'
           AND p.post_status NOT IN ('trash', 'auto-draft')"
    );
    $linked_published = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM $table a
         INNER JOIN $posts_table p ON p.ID = a.prodotto_pubblico_id
         WHERE a.prodotto_pubblico_id IS NOT NULL
           AND a.prodotto_pubblico_id > 0
           AND p.post_type = 'bs_prodotto'
           AND p.post_status = 'publish'"
    );
    $with_external_url = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE external_product_url IS NOT NULL AND external_product_url <> ''" );

    return rest_ensure_response( array(
        'success' => true,
        'total' => $total,
        'max_id' => $max_id,
        'linked' => $linked_existing,
        'linked_id_present' => $linked_id_present,
        'linked_existing' => $linked_existing,
        'linked_published' => $linked_published,
        'linked_broken' => max( 0, $linked_id_present - $linked_existing ),
        'missing_public_product' => max( 0, $total - $linked_existing ),
        'with_external_product_url' => $with_external_url,
        'recommended_per_page' => 200,
    ) );
}

function bsn_api_migration_articoli_get( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $page = max( 1, absint( $request->get_param( 'page' ) ) );
    $per_page = absint( $request->get_param( 'per_page' ) );
    if ( $per_page < 1 ) {
        $per_page = 200;
    }
    if ( $per_page > 500 ) {
        $per_page = 500;
    }

    $order = strtoupper( sanitize_key( (string) $request->get_param( 'order' ) ) );
    if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
        $order = 'ASC';
    }

    $offset = ( $page - 1 ) * $per_page;
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    $max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM $table" );

    $sql = $wpdb->prepare(
        "SELECT * FROM $table ORDER BY id $order LIMIT %d OFFSET %d",
        $per_page,
        $offset
    );
    $rows = $wpdb->get_results( $sql, ARRAY_A );
    $rows = bsn_enrich_articoli_rows( $rows );

    return rest_ensure_response( array(
        'success' => true,
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'max_id' => $max_id,
        'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
        'has_more' => ( $offset + count( $rows ) ) < $total,
        'articoli' => $rows,
    ) );
}

function bsn_api_articoli_search( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $id     = absint( $request->get_param( 'id' ) );
    $search = trim( (string) $request->get_param( 'search' ) );
    $matching_public_product_ids = $search !== '' ? bsn_find_public_product_ids_by_search( $search ) : [];
    $limit  = absint( $request->get_param( 'limit' ) );

    if ( $limit < 1 ) {
        $limit = 15;
    }
    if ( $limit > 25 ) {
        $limit = 25;
    }

    if ( $id > 0 ) {
        $sql = $wpdb->prepare(
            "SELECT id, nome, codice, prezzo_giorno,
                    sconto_standard, sconto_fidato, sconto_premium, sconto_service, sconto_collaboratori,
                    qty_discount_enabled, qty_discount_start_qty, qty_discount_max_qty, qty_discount_max_pct,
                    noleggio_scalare, ubicazione, correlati, prodotto_pubblico_id
             FROM $table
             WHERE id = %d
             LIMIT 1",
            $id
        );
        $articolo = $wpdb->get_row( $sql, ARRAY_A );
        $articolo = $articolo ? bsn_enrich_articoli_rows( [ $articolo ] ) : [];
        return rest_ensure_response( array( 'articoli' => $articolo ? $articolo : array() ) );
    }
    
    if ( $search === '' || strlen( $search ) < 2 ) {
        return rest_ensure_response( array( 'articoli' => array() ) );
    }

    $like = '%' . $wpdb->esc_like( $search ) . '%';
    $where = [
        'CAST(id AS CHAR) LIKE %s',
        'nome LIKE %s',
        'codice LIKE %s',
        'note LIKE %s',
    ];
    $params = [ $like, $like, $like, $like ];

    if ( ! empty( $matching_public_product_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $matching_public_product_ids ), '%d' ) );
        $where[] = "prodotto_pubblico_id IN ($placeholders)";
        $params = array_merge( $params, $matching_public_product_ids );
    }

    $params[] = $limit;
    $sql  = $wpdb->prepare(
        "SELECT id, nome, codice, prezzo_giorno,
                sconto_standard, sconto_fidato, sconto_premium, sconto_service, sconto_collaboratori,
                qty_discount_enabled, qty_discount_start_qty, qty_discount_max_qty, qty_discount_max_pct,
                noleggio_scalare, ubicazione, correlati, prodotto_pubblico_id
         FROM $table
         WHERE " . implode( ' OR ', $where ) . "
         ORDER BY id DESC
         LIMIT %d",
        $params
    );

    $articoli = $wpdb->get_results( $sql, ARRAY_A );
    $articoli = bsn_enrich_articoli_rows( $articoli );

    return rest_ensure_response( array( 'articoli' => $articoli ) );
}

function bsn_api_articoli_export( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) {
        $params = array();
    }

    $ids = isset( $params['ids'] ) && is_array( $params['ids'] ) ? array_map( 'absint', $params['ids'] ) : array();
    $ids = array_values( array_filter( array_unique( $ids ) ) );

    $search = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';
    $max_export = 1000;

    if ( empty( $ids ) && $search === '' ) {
        return new WP_Error( 'bsn_export_empty', 'Nessun articolo selezionato per export.', array( 'status' => 400 ) );
    }

    if ( ! empty( $ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT id, nome, codice, external_product_slug, ubicazione, note FROM $table WHERE id IN ($placeholders) ORDER BY id DESC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
    } else {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $sql = $wpdb->prepare(
            "SELECT id, nome, codice, external_product_slug, ubicazione, note
             FROM $table
             WHERE CAST(id AS CHAR) LIKE %s OR nome LIKE %s OR codice LIKE %s OR ubicazione LIKE %s OR note LIKE %s
             ORDER BY id DESC
             LIMIT %d",
            $like, $like, $like, $like, $like, $max_export
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
    }

    if ( empty( $rows ) ) {
        return new WP_Error( 'bsn_export_no_rows', 'Nessun articolo trovato per export.', array( 'status' => 404 ) );
    }

    $upload_dir = wp_upload_dir();
    $dir = trailingslashit( $upload_dir['basedir'] ) . 'bsn-exports';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    $filename = 'bsn-articoli-' . current_time( 'Ymd-His' ) . '.csv';
    $filepath = trailingslashit( $dir ) . $filename;
    $fileurl  = trailingslashit( $upload_dir['baseurl'] ) . 'bsn-exports/' . $filename;

    $fp = fopen( $filepath, 'w' );
    if ( ! $fp ) {
        return new WP_Error( 'bsn_export_file_error', 'Impossibile creare il file CSV.', array( 'status' => 500 ) );
    }

    fputcsv( $fp, array( 'id_articolo', 'nome_articolo', 'codice', 'valore_qr', 'categoria_ubicazione', 'note' ) );

    foreach ( $rows as $row ) {
        $id = absint( $row['id'] );
        $nome = sanitize_text_field( $row['nome'] );
        $codice = sanitize_text_field( $row['codice'] );
        $slug = sanitize_text_field( $row['external_product_slug'] );
        $valore_qr = $codice !== '' ? $codice : ( $slug !== '' ? $slug : (string) $id );

        fputcsv( $fp, array(
            $id,
            $nome,
            $codice,
            $valore_qr,
            sanitize_text_field( $row['ubicazione'] ),
            sanitize_textarea_field( $row['note'] ),
        ) );
    }

    fclose( $fp );

    return rest_ensure_response( array(
        'success'  => true,
        'filename' => $filename,
        'url'      => esc_url_raw( $fileurl ),
        'count'    => count( $rows ),
    ) );
}

function bsn_format_correlati_magazzino( $correlati_json ) {
    $correlati_json = (string) $correlati_json;
    if ( $correlati_json === '' ) {
        return '';
    }

    $decoded = json_decode( $correlati_json, true );
    if ( ! is_array( $decoded ) ) {
        return $correlati_json;
    }

    $parts = array();
    foreach ( $decoded as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }
        $qty = isset( $item['qty'] ) ? max( 1, absint( $item['qty'] ) ) : 1;
        $nome = sanitize_text_field( (string) ( $item['nome'] ?? '' ) );
        if ( $nome !== '' ) {
            $parts[] = $qty . 'x ' . $nome;
        }
    }

    return implode( ', ', $parts );
}

function bsn_get_articoli_magazzino_rows( WP_REST_Request $request ) {
    $search = trim( (string) $request->get_param( 'search' ) );
    $max_export = 10000;
    $rows = bsn_articoli_query_rows( $search, $max_export, 0 );
    return bsn_enrich_articoli_rows( $rows );
}

function bsn_api_articoli_magazzino_export( WP_REST_Request $request ) {
    $rows = bsn_get_articoli_magazzino_rows( $request );

    if ( empty( $rows ) ) {
        return new WP_Error( 'bsn_magazzino_export_empty', 'Nessun articolo trovato per export magazzino.', array( 'status' => 404 ) );
    }

    $fp = fopen( 'php://temp', 'w+' );
    if ( ! $fp ) {
        return new WP_Error( 'bsn_magazzino_export_file', 'Impossibile creare CSV temporaneo.', array( 'status' => 500 ) );
    }

    fputs( $fp, "\xEF\xBB\xBF" );
    fputcsv( $fp, array(
        'ID',
        'Codice',
        'Nome articolo',
        'Quantita gestionale',
        'Modalita inventario',
        'Ubicazione magazzino',
        'Correlati',
        'Note logistiche',
        'Note',
        'Stato',
        'Prodotto pubblico collegato',
        'Ubicazione corretta',
        'Quantita reale',
        'Correlati da aggiungere',
        'Note magazziniere',
    ) );

    foreach ( $rows as $row ) {
        fputcsv( $fp, array(
            absint( $row['id'] ?? 0 ),
            sanitize_text_field( (string) ( $row['codice'] ?? '' ) ),
            sanitize_text_field( (string) ( $row['nome'] ?? '' ) ),
            intval( $row['qty_disponibile'] ?? 0 ),
            sanitize_text_field( (string) ( $row['inventory_mode'] ?? '' ) ),
            sanitize_text_field( (string) ( $row['ubicazione'] ?? '' ) ),
            bsn_format_correlati_magazzino( $row['correlati'] ?? '' ),
            sanitize_textarea_field( (string) ( $row['note_logistiche'] ?? '' ) ),
            sanitize_textarea_field( (string) ( $row['note'] ?? '' ) ),
            sanitize_text_field( (string) ( $row['stato_utilizzabilita'] ?? '' ) ),
            ! empty( $row['prodotto_pubblico_titolo'] ) ? sanitize_text_field( (string) $row['prodotto_pubblico_titolo'] ) : '',
            '',
            '',
            '',
            '',
        ) );
    }

    rewind( $fp );
    $csv_content = stream_get_contents( $fp );
    fclose( $fp );

    return rest_ensure_response( array(
        'success' => true,
        'filename' => 'bsn-magazzino-articoli-' . current_time( 'Ymd-His' ) . '.csv',
        'mime' => 'text/csv;charset=utf-8',
        'csv_base64' => base64_encode( $csv_content ),
        'count' => count( $rows ),
    ) );
}

function bsn_api_articoli_magazzino_print( WP_REST_Request $request ) {
    $rows = bsn_get_articoli_magazzino_rows( $request );
    $search = trim( (string) $request->get_param( 'search' ) );

    if ( empty( $rows ) ) {
        return new WP_Error( 'bsn_magazzino_print_empty', 'Nessun articolo trovato per stampa magazzino.', array( 'status' => 404 ) );
    }

    ob_start();
    ?>
    <!doctype html>
    <html lang="it">
    <head>
        <meta charset="utf-8">
        <title>Elenco magazzino articoli</title>
        <style>
            @page { size: A4 landscape; margin: 8mm; }
            * { box-sizing: border-box; }
            body { margin: 0; background: #fff; color: #000; font-family: Arial, sans-serif; font-size: 9px; }
            h1 { font-size: 16px; margin: 0 0 4px; }
            .meta { margin: 0 0 8px; font-size: 10px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #000; padding: 3px 4px; vertical-align: top; }
            th { background: #e8eef5; font-weight: 700; }
            td.blank { min-width: 72px; height: 18px; }
            .col-id { width: 36px; }
            .col-code { width: 70px; }
            .col-name { width: 190px; }
            .col-qty { width: 52px; text-align: center; }
            .col-mode { width: 70px; }
            .col-location { width: 110px; }
            .col-correlati { width: 150px; }
            .col-note { width: 150px; }
            .screen-actions { margin: 10px 0; }
            .screen-actions button { padding: 6px 10px; }
            @media print {
                .screen-actions { display: none; }
                tr { page-break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="screen-actions">
            <button type="button" onclick="window.print()">Stampa</button>
        </div>
        <h1>Elenco magazzino articoli</h1>
        <p class="meta">
            Totale righe: <?php echo esc_html( count( $rows ) ); ?>
            <?php if ( $search !== '' ) : ?>
                - filtro ricerca: <?php echo esc_html( $search ); ?>
            <?php endif; ?>
            - generato il <?php echo esc_html( current_time( 'd-m-Y H:i' ) ); ?>
        </p>
        <table>
            <thead>
                <tr>
                    <th class="col-id">ID</th>
                    <th class="col-code">Codice</th>
                    <th class="col-name">Nome articolo</th>
                    <th class="col-qty">Qtà</th>
                    <th class="col-mode">Inventario</th>
                    <th class="col-location">Ubicazione</th>
                    <th class="col-correlati">Correlati</th>
                    <th class="col-note">Note logistiche</th>
                    <th>Ubicazione corretta</th>
                    <th>Quantità reale</th>
                    <th>Correlati da aggiungere</th>
                    <th>Note magazziniere</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td class="col-id"><?php echo esc_html( absint( $row['id'] ?? 0 ) ); ?></td>
                        <td class="col-code"><?php echo esc_html( (string) ( $row['codice'] ?? '' ) ); ?></td>
                        <td class="col-name"><?php echo esc_html( (string) ( $row['nome'] ?? '' ) ); ?></td>
                        <td class="col-qty"><?php echo esc_html( (string) intval( $row['qty_disponibile'] ?? 0 ) ); ?></td>
                        <td class="col-mode"><?php echo esc_html( (string) ( $row['inventory_mode'] ?? '' ) ); ?></td>
                        <td class="col-location"><?php echo esc_html( (string) ( $row['ubicazione'] ?? '' ) ); ?></td>
                        <td class="col-correlati"><?php echo esc_html( bsn_format_correlati_magazzino( $row['correlati'] ?? '' ) ); ?></td>
                        <td class="col-note"><?php echo esc_html( (string) ( $row['note_logistiche'] ?? '' ) ); ?></td>
                        <td class="blank"></td>
                        <td class="blank"></td>
                        <td class="blank"></td>
                        <td class="blank"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    return rest_ensure_response( array(
        'success' => true,
        'count' => count( $rows ),
        'html' => $html,
    ) );
}


function bsn_api_articoli_etichette_get( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $search = trim( (string) $request->get_param( 'search' ) );
    $limit = absint( $request->get_param( 'limit' ) );
    $only_unprinted = absint( $request->get_param( 'only_unprinted' ) ) ? 1 : 0;

    if ( $limit < 1 ) {
        $limit = 12;
    }
    if ( $limit > 100 ) {
        $limit = 100;
    }

    $where = array();
    $params = array();

    if ( $search !== '' ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $where[] = '(CAST(id AS CHAR) LIKE %s OR nome LIKE %s OR codice LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ( $only_unprinted ) {
        $where[] = 'COALESCE(qr_stampato, 0) = 0';
    }

    $sql = "SELECT id, nome, codice, data_creazione, data_modifica, qr_stampato, qr_stampato_at, qr_stampato_by FROM $table";
    if ( ! empty( $where ) ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where );
    }

    $sql .= ' ORDER BY id DESC LIMIT %d';
    $params[] = ( $search !== '' ) ? 200 : $limit;

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

    return rest_ensure_response( array(
        'articoli' => $rows,
    ) );
}

function bsn_api_articoli_etichette_stato( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) {
        $params = array();
    }

    $ids = isset( $params['ids'] ) && is_array( $params['ids'] )
        ? array_values( array_filter( array_unique( array_map( 'absint', $params['ids'] ) ) ) )
        : array();

    if ( empty( $ids ) || ! isset( $params['printed'] ) ) {
        return new WP_Error( 'bsn_etichette_stato_invalid', 'Parametri mancanti per aggiornare lo stato.', array( 'status' => 400 ) );
    }

    $printed = (bool) $params['printed'];
    $now = current_time( 'mysql' );
    $user_id = get_current_user_id();
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

    if ( $printed ) {
        $sql = "UPDATE $table SET qr_stampato = 1, qr_stampato_at = %s, qr_stampato_by = %d, data_modifica = %s WHERE id IN ($placeholders)";
        $args = array_merge( array( $now, $user_id, $now ), $ids );
    } else {
        $sql = "UPDATE $table SET qr_stampato = 0, qr_stampato_at = NULL, qr_stampato_by = NULL, data_modifica = %s WHERE id IN ($placeholders)";
        $args = array_merge( array( $now ), $ids );
    }

    $updated = $wpdb->query( $wpdb->prepare( $sql, $args ) );
    if ( $updated === false ) {
        return new WP_Error( 'bsn_etichette_stato_error', 'Errore durante aggiornamento stato.', array( 'status' => 500 ) );
    }

    return rest_ensure_response( array( 'success' => true, 'updated' => intval( $updated ) ) );
}

function bsn_api_articoli_etichette_export( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) {
        $params = array();
    }

    $raw_items = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
    $items = array();

    foreach ( $raw_items as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }

        $id = absint( $item['id'] ?? 0 );
        $copies = absint( $item['copies'] ?? 1 );

        if ( $id < 1 ) {
            continue;
        }

        if ( $copies < 1 ) {
            $copies = 1;
        }
        if ( $copies > 100 ) {
            $copies = 100;
        }

        $items[] = array(
            'id' => $id,
            'copies' => $copies,
        );
    }

    if ( empty( $items ) ) {
        return new WP_Error( 'bsn_etichette_export_empty', 'Seleziona almeno un articolo.', array( 'status' => 400 ) );
    }

    $ids = array_values( array_unique( array_map( function( $item ) {
        return $item['id'];
    }, $items ) ) );

    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $sql = "SELECT id, codice FROM $table WHERE id IN ($placeholders)";
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );

    if ( empty( $rows ) ) {
        return new WP_Error( 'bsn_etichette_export_not_found', 'Nessun articolo trovato.', array( 'status' => 404 ) );
    }

    $map = array();
    foreach ( $rows as $row ) {
        $map[ absint( $row['id'] ) ] = sanitize_text_field( (string) $row['codice'] );
    }

     $max_rows = 10000;
    $rows_written = 0;

     $fp = fopen( 'php://temp', 'w+' );
    if ( ! $fp ) {
        return new WP_Error( 'bsn_etichette_export_file', 'Impossibile creare CSV temporaneo.', array( 'status' => 500 ) );
    }

    fputcsv( $fp, array( 'CODICE' ) );

    foreach ( $items as $item ) {
        $id = $item['id'];
        $code = $map[ $id ] ?? '';
        if ( $code === '' ) {
            continue;
        }

        for ( $i = 0; $i < $item['copies']; $i++ ) {
             $rows_written++;
            if ( $rows_written > $max_rows ) {
                fclose( $fp );
                return new WP_Error( 'bsn_etichette_export_limit', 'Troppe etichette richieste in un solo export (max 10.000 righe).', array( 'status' => 400 ) );
            }
            fputcsv( $fp, array( $code ) );
        }
    }

    rewind( $fp );
    $csv_content = stream_get_contents( $fp );
    fclose( $fp );

    if ( ! is_string( $csv_content ) || $csv_content === '' ) {
        return new WP_Error( 'bsn_etichette_export_empty_file', 'CSV vuoto non valido.', array( 'status' => 500 ) );
    }

    $now = current_time( 'mysql' );
    $user_id = get_current_user_id();
    $sql_update = "UPDATE $table SET qr_stampato = 1, qr_stampato_at = %s, qr_stampato_by = %d, data_modifica = %s WHERE id IN ($placeholders)";
    $args_update = array_merge( array( $now, $user_id, $now ), $ids );
    $wpdb->query( $wpdb->prepare( $sql_update, $args_update ) );

    return rest_ensure_response( array(
        'success' => true,
        'filename' => 'bsn-etichette-' . current_time( 'Ymd-Hi' ) . '.csv',
        'mime' => 'text/csv;charset=utf-8',
        'csv_base64' => base64_encode( $csv_content ),
        'count' => $rows_written,
    ) );
}

function bsn_api_articoli_post($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $nome          = sanitize_text_field($request->get_param('nome'));
    $codice        = sanitize_text_field($request->get_param('codice'));
    $prezzo_giorno = floatval($request->get_param('prezzo_giorno'));
    $valore_bene   = floatval($request->get_param('valore_bene'));

    // Campi sconto per categoria cliente
    $sconto_standard      = floatval($request->get_param('sconto_standard'));
    $sconto_fidato        = floatval($request->get_param('sconto_fidato'));
    $sconto_premium       = floatval($request->get_param('sconto_premium'));
    $sconto_service       = floatval($request->get_param('sconto_service'));
    $sconto_collaboratori = floatval($request->get_param('sconto_collaboratori'));
    $qty_discount_enabled = intval($request->get_param('qty_discount_enabled')) ? 1 : 0;
    $qty_discount_start_qty = max(0, intval($request->get_param('qty_discount_start_qty')));
    $qty_discount_max_qty_param = $request->get_param('qty_discount_max_qty');
    $qty_discount_max_qty = $qty_discount_max_qty_param === null || $qty_discount_max_qty_param === '' ? null : max(0, intval($qty_discount_max_qty_param));
    $qty_discount_max_pct = max(0, min(100, floatval($request->get_param('qty_discount_max_pct'))));

    $qty_disponibile = intval($request->get_param('qty_disponibile'));
    $inventory_mode = bsn_normalize_articolo_inventory_mode($request->get_param('inventory_mode'), $qty_disponibile);
    $prodotto_pubblico_id = bsn_sanitize_articolo_public_product_id($request->get_param('prodotto_pubblico_id'));
    $min_qty = max(1, intval($request->get_param('min_qty')));
    $larghezza_cm = $request->get_param('larghezza_cm');
    $altezza_cm = $request->get_param('altezza_cm');
    $profondita_cm = $request->get_param('profondita_cm');
    $peso_kg = $request->get_param('peso_kg');
    $veicolo_minimo = sanitize_key((string) $request->get_param('veicolo_minimo'));
    $veicoli_validi = array_keys(bsn_get_articolo_veicolo_minimo_options());
    if (!in_array($veicolo_minimo, $veicoli_validi, true)) {
        $veicolo_minimo = '';
    }
    $stato_utilizzabilita = bsn_normalize_articolo_stato_utilizzabilita($request->get_param('stato_utilizzabilita'));
    $ubicazione      = sanitize_text_field($request->get_param('ubicazione'));
    $note            = sanitize_textarea_field($request->get_param('note'));
    $note_logistiche = sanitize_textarea_field($request->get_param('note_logistiche'));
    $external_product_url  = esc_url_raw( $request->get_param( 'external_product_url' ) );
    $external_product_slug = sanitize_text_field( $request->get_param( 'external_product_slug' ) );
    $external_product_name = sanitize_text_field( $request->get_param( 'external_product_name' ) );
    $external_image_url    = esc_url_raw( $request->get_param( 'external_image_url' ) );
    $external_last_sync    = sanitize_text_field( $request->get_param( 'external_last_sync' ) );

     if ( $external_product_url === '' ) {
        $external_product_slug = '';
        $external_product_name = '';
        $external_image_url = '';
        $external_last_sync = null;
    }
    $noleggio_scalare = intval($request->get_param('noleggio_scalare')) ? 1 : 0;

    // Stringa JSON dei correlati
    $correlati_raw  = $request->get_param('correlati');
    $correlati_json = null;

    if (!empty($correlati_raw)) {
        $decoded = json_decode($correlati_raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $correlati_json = wp_json_encode($decoded);
        }
    }

    if ( empty( $nome ) || empty( $codice ) ) {
        return new WP_Error(
            'bsn_articolo_dati_mancanti',
            'Nome e codice sono obbligatori',
            ['status' => 400]
        );
    }

    $data = [
        'nome'               => $nome,
        'codice'             => $codice,
        'prezzo_giorno'      => $prezzo_giorno,
        'valore_bene'        => $valore_bene,
        'sconto_standard'    => $sconto_standard,
        'sconto_fidato'      => $sconto_fidato,
        'sconto_premium'     => $sconto_premium,
        'sconto_service'     => $sconto_service,
        'sconto_collaboratori' => $sconto_collaboratori,
        'qty_discount_enabled' => $qty_discount_enabled,
        'qty_discount_start_qty' => $qty_discount_start_qty,
        'qty_discount_max_qty' => $qty_discount_max_qty,
        'qty_discount_max_pct' => $qty_discount_max_pct,
        'noleggio_scalare'   => $noleggio_scalare,
        'qty_disponibile'    => $qty_disponibile,
        'inventory_mode'     => $inventory_mode,
        'prodotto_pubblico_id' => $prodotto_pubblico_id ?: null,
        'min_qty'            => $min_qty,
        'larghezza_cm'       => $larghezza_cm !== null && $larghezza_cm !== '' ? floatval($larghezza_cm) : null,
        'altezza_cm'         => $altezza_cm !== null && $altezza_cm !== '' ? floatval($altezza_cm) : null,
        'profondita_cm'      => $profondita_cm !== null && $profondita_cm !== '' ? floatval($profondita_cm) : null,
        'peso_kg'            => $peso_kg !== null && $peso_kg !== '' ? floatval($peso_kg) : null,
        'veicolo_minimo'     => $veicolo_minimo !== '' ? $veicolo_minimo : null,
        'stato_utilizzabilita' => $stato_utilizzabilita,
        'ubicazione'         => $ubicazione,
        'note'               => $note,
        'note_logistiche'    => $note_logistiche,
        'external_product_url'  => $external_product_url,
        'external_product_slug' => $external_product_slug,
        'external_product_name' => $external_product_name,
        'external_image_url'    => $external_image_url,
        'external_last_sync'    => $external_last_sync,
        'qr_stampato'           => 0,
        'qr_stampato_at'        => null,
        'qr_stampato_by'        => null,
        'data_creazione'        => current_time('mysql'),
        'data_modifica'         => current_time('mysql'),
    ];

    if ($correlati_json !== null) {
        $data['correlati'] = $correlati_json;
    }

    $result = $wpdb->insert($table, $data);

    if (!$result) {
        return new WP_Error(
            'bsn_insert_articolo_error',
            'Errore nel salvataggio articolo',
            ['status' => 500]
        );
    }

    return rest_ensure_response([
        'success' => true,
        'id'      => $wpdb->insert_id,
    ]);
}

/* ==== ARTICOLI: UPDATE ==== */

function bsn_api_articoli_put( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $id = intval( $request->get_param( 'id' ) );
    if ( ! $id ) {
        return new WP_Error(
            'bsn_articolo_id_mancante',
            'ID articolo mancante',
            [ 'status' => 400 ]
        );
    }

    $data = [];
    $fields = [
        'nome',
        'codice',
        'prezzo_giorno',
        'valore_bene',
        'sconto_standard',
        'sconto_fidato',
        'sconto_premium',
        'sconto_service',
        'sconto_collaboratori',
        'qty_discount_enabled',
        'qty_discount_start_qty',
        'qty_discount_max_qty',
        'qty_discount_max_pct',
        'qty_disponibile',
        'inventory_mode',
        'prodotto_pubblico_id',
        'min_qty',
        'larghezza_cm',
        'altezza_cm',
        'profondita_cm',
        'peso_kg',
        'veicolo_minimo',
        'stato_utilizzabilita',
        'ubicazione',
        'note',
        'note_logistiche',
        'noleggio_scalare',
        'correlati',
        'external_product_url',
        'external_product_slug',
        'external_product_name',
        'external_image_url',
        'external_last_sync',
    ];

    foreach ( $fields as $field ) {
        $value = $request->get_param( $field );
        if ( $value !== null ) {
            switch ( $field ) {
                case 'prezzo_giorno':
                case 'valore_bene':
                case 'sconto_standard':
                case 'sconto_fidato':
                case 'sconto_premium':
                case 'sconto_service':
                case 'sconto_collaboratori':
                case 'qty_discount_max_pct':
                    $data[ $field ] = floatval( $value );
                    break;
                case 'qty_disponibile':
                case 'noleggio_scalare':
                case 'prodotto_pubblico_id':
                case 'min_qty':
                case 'qty_discount_enabled':
                case 'qty_discount_start_qty':
                case 'qty_discount_max_qty':
                    $data[ $field ] = intval( $value );
                    break;
                case 'larghezza_cm':
                case 'altezza_cm':
                case 'profondita_cm':
                case 'peso_kg':
                    $data[ $field ] = $value === '' ? null : floatval( $value );
                    break;
                case 'inventory_mode':
                    $data[ $field ] = bsn_normalize_articolo_inventory_mode( $value, $request->get_param( 'qty_disponibile' ) );
                    break;
                case 'stato_utilizzabilita':
                    $data[ $field ] = bsn_normalize_articolo_stato_utilizzabilita( $value );
                    break;
                case 'veicolo_minimo':
                    $normalized = sanitize_key( (string) $value );
                    $data[ $field ] = array_key_exists( $normalized, bsn_get_articolo_veicolo_minimo_options() ) ? $normalized : '';
                    break;
                    case 'external_product_url':
                case 'external_image_url':
                    $data[ $field ] = esc_url_raw( $value );
                    break;
                case 'correlati':
                    $decoded = json_decode( $value, true );
                    if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                        $data[ $field ] = wp_json_encode( $decoded );
                    }
                    break;
                default:
                    $data[ $field ] = sanitize_text_field( $value );
                    break;
            }
        }
    }

    if ( isset( $data['external_product_url'] ) && $data['external_product_url'] === '' ) {
        $data['external_product_slug'] = '';
        $data['external_product_name'] = '';
        $data['external_image_url'] = '';
        $data['external_last_sync'] = null;
    }

    if ( array_key_exists( 'prodotto_pubblico_id', $data ) ) {
        $data['prodotto_pubblico_id'] = bsn_sanitize_articolo_public_product_id( $data['prodotto_pubblico_id'] ) ?: null;
    }

    if ( array_key_exists( 'min_qty', $data ) ) {
        $data['min_qty'] = max( 1, intval( $data['min_qty'] ) );
    }

    if ( array_key_exists( 'qty_discount_enabled', $data ) ) {
        $data['qty_discount_enabled'] = ! empty( $data['qty_discount_enabled'] ) ? 1 : 0;
    }

    if ( array_key_exists( 'qty_discount_start_qty', $data ) ) {
        $data['qty_discount_start_qty'] = max( 0, intval( $data['qty_discount_start_qty'] ) );
    }

    if ( array_key_exists( 'qty_discount_max_qty', $data ) ) {
        $data['qty_discount_max_qty'] = $request->get_param( 'qty_discount_max_qty' ) === '' ? null : max( 0, intval( $data['qty_discount_max_qty'] ) );
    }

    if ( array_key_exists( 'qty_discount_max_pct', $data ) ) {
        $data['qty_discount_max_pct'] = max( 0, min( 100, (float) $data['qty_discount_max_pct'] ) );
    }
    
    if ( empty( $data ) ) {
        return new WP_Error(
            'bsn_articolo_nessun_campo',
            'Nessun campo da aggiornare',
            [ 'status' => 400 ]
        );
    }

     $data['data_modifica'] = current_time( 'mysql' );
     
    $updated = $wpdb->update(
        $table,
        $data,
        [ 'id' => $id ],
        null,
        [ '%d' ]
    );

    if ( $updated === false ) {
        return new WP_Error(
            'bsn_update_articolo_error',
            'Errore nell\'aggiornamento articolo',
            [ 'status' => 500 ]
        );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $id,
    ] );
}

/* ==== ARTICOLI: DELETE ==== */

function bsn_api_articoli_delete( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $id = intval( $request->get_param( 'id' ) );
    if ( ! $id ) {
        return new WP_Error(
            'bsn_articolo_id_mancante',
            'ID articolo mancante',
            [ 'status' => 400 ]
        );
    }

    $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

    if ( $deleted === false ) {
        return new WP_Error(
            'bsn_delete_articolo_error',
            'Errore nella cancellazione articolo',
            [ 'status' => 500 ]
        );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $id,
    ] );
}

/* ==== ARTICOLI: CLONE ==== */

function bsn_get_next_available_articolo_code( $table, $current_code ) {
    global $wpdb;

    $current_code = trim( (string) $current_code );
    if ( $current_code === '' ) {
        return '';
    }

    if ( preg_match( '/^(.*?#)(\d+)$/', $current_code, $m ) ) {
        $prefix = $m[1];
        $next_number = intval( $m[2] ) + 1;
    } else {
        $prefix = $current_code . '#';
        $next_number = 1;
    }

    $max_attempts = 5000;
    for ( $i = 0; $i < $max_attempts; $i++ ) {
        $candidate = $prefix . $next_number;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE codice = %s LIMIT 1",
                $candidate
            )
        );

        if ( ! $exists ) {
            return $candidate;
        }

        $next_number++;
    }

    return '';
}

function bsn_api_articoli_clone( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_articoli';

    $id = intval( $request->get_param( 'id' ) );
    if ( ! $id ) {
        return new WP_Error(
            'bsn_articolo_id_mancante',
            'ID articolo da clonare mancante',
            [ 'status' => 400 ]
        );
    }

    $articolo = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ),
        ARRAY_A
    );

    if ( ! $articolo ) {
        return new WP_Error(
            'bsn_articolo_non_trovato',
            'Articolo da clonare non trovato',
            [ 'status' => 404 ]
        );
    }

    $codice = $articolo['codice'];
    $nuovo_codice = bsn_get_next_available_articolo_code( $table, $codice );
    if ( $nuovo_codice === '' ) {
        return new WP_Error(
            'bsn_clone_articolo_code_error',
            'Impossibile determinare un nuovo codice disponibile per la clonazione.',
            [ 'status' => 500 ]
        );
    }

    unset( $articolo['id'] );
    $articolo['codice']         = $nuovo_codice;
    $articolo['data_creazione'] = current_time( 'mysql' );
    $articolo['data_modifica']  = current_time( 'mysql' );

    $result = $wpdb->insert( $table, $articolo );

    if ( ! $result ) {
        return new WP_Error(
            'bsn_clone_articolo_error',
            'Errore nella clonazione articolo: ' . ( $wpdb->last_error ?: 'controlla il log del database.' ),
            [ 'status' => 500 ]
        );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $wpdb->insert_id,
        'codice'  => $nuovo_codice,
    ] );
}

/* ==== KIT (bundle articoli) ==== */

function bsn_get_kits_with_components( $kit_ids = [] ) {
    global $wpdb;

    $table_kits          = $wpdb->prefix . 'bsn_kits';
    $table_kit_componenti = $wpdb->prefix . 'bsn_kit_componenti';
    $table_articoli      = $wpdb->prefix . 'bs_articoli';

    $kits = [];
    if ( ! empty( $kit_ids ) ) {
        $kit_ids = array_values( array_unique( array_filter( array_map( 'intval', $kit_ids ) ) ) );
        if ( empty( $kit_ids ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $kit_ids ), '%d' ) );
        $sql = "SELECT k.*, a.nome AS articolo_kit_nome, a.codice AS articolo_kit_codice
                FROM $table_kits k
                LEFT JOIN $table_articoli a ON k.articolo_kit_id = a.id
                WHERE k.id IN ($placeholders)
                ORDER BY k.id DESC";
        $kits = $wpdb->get_results( $wpdb->prepare( $sql, $kit_ids ), ARRAY_A );
    } else {
        $kits = $wpdb->get_results(
            "SELECT k.*, a.nome AS articolo_kit_nome, a.codice AS articolo_kit_codice
             FROM $table_kits k
             LEFT JOIN $table_articoli a ON k.articolo_kit_id = a.id
             ORDER BY k.id DESC",
            ARRAY_A
        );
    }

    if ( empty( $kits ) ) {
        return [];
    }

    $kits_map = [];
    foreach ( $kits as $kit ) {
        $kit_id = intval( $kit['id'] );
        $kits_map[ $kit_id ] = [
            'id'                => $kit_id,
            'nome_kit'          => $kit['nome_kit'],
            'articolo_kit_id'   => intval( $kit['articolo_kit_id'] ),
            'articolo_kit_nome' => $kit['articolo_kit_nome'] ?? '',
            'articolo_kit_codice' => $kit['articolo_kit_codice'] ?? '',
            'note'              => $kit['note'] ?? '',
            'components'        => [],
        ];
    }

    $kit_ids = array_keys( $kits_map );
    $placeholders = implode( ',', array_fill( 0, count( $kit_ids ), '%d' ) );
    $sql_components = "SELECT kc.*, a.nome AS articolo_nome, a.codice AS articolo_codice
                       FROM $table_kit_componenti kc
                       LEFT JOIN $table_articoli a ON kc.articolo_id = a.id
                       WHERE kc.kit_id IN ($placeholders)
                       ORDER BY kc.id ASC";
    $components = $wpdb->get_results( $wpdb->prepare( $sql_components, $kit_ids ), ARRAY_A );

    foreach ( $components as $component ) {
        $kit_id = intval( $component['kit_id'] );
        if ( ! isset( $kits_map[ $kit_id ] ) ) {
            continue;
        }
        $kits_map[ $kit_id ]['components'][] = [
            'id'                => intval( $component['id'] ),
            'articolo_id'       => intval( $component['articolo_id'] ),
            'articolo_nome'     => $component['articolo_nome'] ?? '',
            'articolo_codice'   => $component['articolo_codice'] ?? '',
            'ruolo'             => $component['ruolo'],
            'qty'               => intval( $component['qty'] ),
            'is_default'        => intval( $component['is_default'] ),
            'is_selectable'     => intval( $component['is_selectable'] ),
            'gruppo_equivalenza'=> $component['gruppo_equivalenza'],
        ];
    }

    return array_values( $kits_map );
}

function bsn_api_kits_get( WP_REST_Request $request ) {
    $kits = bsn_get_kits_with_components();
    return rest_ensure_response( [ 'kits' => $kits ] );
}

function bsn_api_kits_post( WP_REST_Request $request ) {
    global $wpdb;

    $table_kits           = $wpdb->prefix . 'bsn_kits';
    $table_kit_componenti = $wpdb->prefix . 'bsn_kit_componenti';
    $table_articoli       = $wpdb->prefix . 'bs_articoli';

    $nome_kit = sanitize_text_field( $request->get_param( 'nome_kit' ) );
    $articolo_kit_id = intval( $request->get_param( 'articolo_kit_id' ) );
    $note = sanitize_textarea_field( $request->get_param( 'note' ) );

    if ( empty( $nome_kit ) || $articolo_kit_id <= 0 ) {
        return new WP_Error( 'bsn_kit_dati_mancanti', 'Nome kit e articolo kit sono obbligatori.', [ 'status' => 400 ] );
    }

    $articolo_esiste = $wpdb->get_var(
        $wpdb->prepare( "SELECT id FROM $table_articoli WHERE id = %d", $articolo_kit_id )
    );
    if ( ! $articolo_esiste ) {
        return new WP_Error( 'bsn_kit_articolo_non_trovato', 'Articolo kit non trovato.', [ 'status' => 404 ] );
    }

    $componenti_raw = $request->get_param( 'componenti' );
    $componenti = [];
    if ( ! empty( $componenti_raw ) ) {
        $decoded = json_decode( $componenti_raw, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            foreach ( $decoded as $comp ) {
                $articolo_id = isset( $comp['articolo_id'] ) ? intval( $comp['articolo_id'] ) : 0;
                $ruolo = isset( $comp['ruolo'] ) ? sanitize_text_field( $comp['ruolo'] ) : '';
                $qty = isset( $comp['qty'] ) ? intval( $comp['qty'] ) : 0;
                $gruppo = isset( $comp['gruppo_equivalenza'] ) ? sanitize_text_field( $comp['gruppo_equivalenza'] ) : '';
                $is_default = ! empty( $comp['is_default'] ) ? 1 : 0;
                $is_selectable = ! empty( $comp['is_selectable'] ) ? 1 : 0;

                if ( $articolo_id > 0 && $ruolo !== '' && $qty > 0 && $gruppo !== '' ) {
                    $componenti[] = [
                        'articolo_id'       => $articolo_id,
                        'ruolo'             => $ruolo,
                        'qty'               => $qty,
                        'is_default'        => $is_default,
                        'is_selectable'     => $is_selectable,
                        'gruppo_equivalenza'=> $gruppo,
                    ];
                }
            }
        }
    }

    if ( empty( $componenti ) ) {
        return new WP_Error( 'bsn_kit_componenti_mancanti', 'Inserisci almeno un componente valido.', [ 'status' => 400 ] );
    }

    $inserted = $wpdb->insert(
        $table_kits,
        [
            'nome_kit'        => $nome_kit,
            'articolo_kit_id' => $articolo_kit_id,
            'note'            => $note,
            'data_creazione'  => current_time( 'mysql' ),
        ],
        [ '%s', '%d', '%s', '%s' ]
    );

    if ( ! $inserted ) {
        return new WP_Error( 'bsn_kit_insert_error', 'Errore nel salvataggio del kit.', [ 'status' => 500 ] );
    }

    $kit_id = (int) $wpdb->insert_id;

    foreach ( $componenti as $comp ) {
        $wpdb->insert(
            $table_kit_componenti,
            [
                'kit_id'             => $kit_id,
                'articolo_id'        => $comp['articolo_id'],
                'ruolo'              => $comp['ruolo'],
                'qty'                => $comp['qty'],
                'is_default'         => $comp['is_default'],
                'is_selectable'      => $comp['is_selectable'],
                'gruppo_equivalenza' => $comp['gruppo_equivalenza'],
            ],
            [ '%d', '%d', '%s', '%d', '%d', '%d', '%s' ]
        );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $kit_id,
    ] );
}

function bsn_api_kits_update( WP_REST_Request $request ) {
    global $wpdb;

    $table_kits           = $wpdb->prefix . 'bsn_kits';
    $table_kit_componenti = $wpdb->prefix . 'bsn_kit_componenti';
    $table_articoli       = $wpdb->prefix . 'bs_articoli';

    $kit_id = intval( $request->get_param( 'id' ) );
    if ( ! $kit_id ) {
        return new WP_Error( 'bsn_kit_id_mancante', 'ID kit mancante.', [ 'status' => 400 ] );
    }

    $nome_kit = sanitize_text_field( $request->get_param( 'nome_kit' ) );
    $articolo_kit_id = intval( $request->get_param( 'articolo_kit_id' ) );
    $note = sanitize_textarea_field( $request->get_param( 'note' ) );

    if ( empty( $nome_kit ) || $articolo_kit_id <= 0 ) {
        return new WP_Error( 'bsn_kit_dati_mancanti', 'Nome kit e articolo kit sono obbligatori.', [ 'status' => 400 ] );
    }

    $articolo_esiste = $wpdb->get_var(
        $wpdb->prepare( "SELECT id FROM $table_articoli WHERE id = %d", $articolo_kit_id )
    );
    if ( ! $articolo_esiste ) {
        return new WP_Error( 'bsn_kit_articolo_non_trovato', 'Articolo kit non trovato.', [ 'status' => 404 ] );
    }

    $componenti_raw = $request->get_param( 'componenti' );
    $componenti = [];
    if ( ! empty( $componenti_raw ) ) {
        $decoded = json_decode( $componenti_raw, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            foreach ( $decoded as $comp ) {
                $articolo_id = isset( $comp['articolo_id'] ) ? intval( $comp['articolo_id'] ) : 0;
                $ruolo = isset( $comp['ruolo'] ) ? sanitize_text_field( $comp['ruolo'] ) : '';
                $qty = isset( $comp['qty'] ) ? intval( $comp['qty'] ) : 0;
                $gruppo = isset( $comp['gruppo_equivalenza'] ) ? sanitize_text_field( $comp['gruppo_equivalenza'] ) : '';
                $is_default = ! empty( $comp['is_default'] ) ? 1 : 0;
                $is_selectable = ! empty( $comp['is_selectable'] ) ? 1 : 0;

                if ( $articolo_id > 0 && $ruolo !== '' && $qty > 0 && $gruppo !== '' ) {
                    $componenti[] = [
                        'articolo_id'       => $articolo_id,
                        'ruolo'             => $ruolo,
                        'qty'               => $qty,
                        'is_default'        => $is_default,
                        'is_selectable'     => $is_selectable,
                        'gruppo_equivalenza'=> $gruppo,
                    ];
                }
            }
        }
    }

    if ( empty( $componenti ) ) {
        return new WP_Error( 'bsn_kit_componenti_mancanti', 'Inserisci almeno un componente valido.', [ 'status' => 400 ] );
    }

    $updated = $wpdb->update(
        $table_kits,
        [
            'nome_kit'        => $nome_kit,
            'articolo_kit_id' => $articolo_kit_id,
            'note'            => $note,
        ],
        [ 'id' => $kit_id ],
        [ '%s', '%d', '%s' ],
        [ '%d' ]
    );

    if ( $updated === false ) {
        return new WP_Error( 'bsn_kit_update_error', 'Errore nell\'aggiornamento del kit.', [ 'status' => 500 ] );
    }

    $wpdb->delete( $table_kit_componenti, [ 'kit_id' => $kit_id ], [ '%d' ] );
    foreach ( $componenti as $comp ) {
        $wpdb->insert(
            $table_kit_componenti,
            [
                'kit_id'             => $kit_id,
                'articolo_id'        => $comp['articolo_id'],
                'ruolo'              => $comp['ruolo'],
                'qty'                => $comp['qty'],
                'is_default'         => $comp['is_default'],
                'is_selectable'      => $comp['is_selectable'],
                'gruppo_equivalenza' => $comp['gruppo_equivalenza'],
            ],
            [ '%d', '%d', '%s', '%d', '%d', '%d', '%s' ]
        );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $kit_id,
    ] );
}

function bsn_api_kits_delete( WP_REST_Request $request ) {
    global $wpdb;

    $table_kits           = $wpdb->prefix . 'bsn_kits';
    $table_kit_componenti = $wpdb->prefix . 'bsn_kit_componenti';

    $kit_id = intval( $request->get_param( 'id' ) );
    if ( ! $kit_id ) {
        return new WP_Error( 'bsn_kit_id_mancante', 'ID kit mancante.', [ 'status' => 400 ] );
    }

    $wpdb->delete( $table_kit_componenti, [ 'kit_id' => $kit_id ], [ '%d' ] );
    $deleted = $wpdb->delete( $table_kits, [ 'id' => $kit_id ], [ '%d' ] );

    if ( $deleted === false ) {
        return new WP_Error( 'bsn_kit_delete_error', 'Errore nell\'eliminazione del kit.', [ 'status' => 500 ] );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $kit_id,
    ] );
}

function bsn_get_noleggio_kits( $noleggio_id ) {
    global $wpdb;

    $table_noleggio_kits = $wpdb->prefix . 'bsn_noleggio_kits';
    $table_noleggio_kit_componenti = $wpdb->prefix . 'bsn_noleggio_kit_componenti';
    $table_kits = $wpdb->prefix . 'bsn_kits';
    $table_articoli = $wpdb->prefix . 'bs_articoli';

    $kits_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT nk.id, nk.kit_id, nk.kit_label, k.nome_kit, k.articolo_kit_id,
                    a.nome AS articolo_kit_nome, a.codice AS articolo_kit_codice
             FROM $table_noleggio_kits nk
             LEFT JOIN $table_kits k ON nk.kit_id = k.id
             LEFT JOIN $table_articoli a ON k.articolo_kit_id = a.id
             WHERE nk.noleggio_id = %s
             ORDER BY nk.id ASC",
            $noleggio_id
        ),
        ARRAY_A
    );

    if ( empty( $kits_rows ) ) {
        return [];
    }

    $kits_map = [];
    $noleggio_kit_ids = [];
    foreach ( $kits_rows as $row ) {
        $nk_id = intval( $row['id'] );
        $kits_map[ $nk_id ] = [
            'noleggio_kit_id'    => $nk_id,
            'kit_id'             => intval( $row['kit_id'] ),
            'kit_label'          => $row['kit_label'] ?: $row['nome_kit'],
            'nome_kit'           => $row['nome_kit'],
            'articolo_kit_id'    => intval( $row['articolo_kit_id'] ),
            'articolo_kit_nome'  => $row['articolo_kit_nome'] ?? '',
            'articolo_kit_codice'=> $row['articolo_kit_codice'] ?? '',
            'components'         => [],
        ];
        $noleggio_kit_ids[] = $nk_id;
    }

    $placeholders = implode( ',', array_fill( 0, count( $noleggio_kit_ids ), '%d' ) );
    $components = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT nkc.*, a.nome AS articolo_nome, a.codice AS articolo_codice
             FROM $table_noleggio_kit_componenti nkc
             LEFT JOIN $table_articoli a ON nkc.articolo_id_scelto = a.id
             WHERE nkc.noleggio_kit_id IN ($placeholders)
             ORDER BY nkc.id ASC",
            $noleggio_kit_ids
        ),
        ARRAY_A
    );

    foreach ( $components as $component ) {
        $nk_id = intval( $component['noleggio_kit_id'] );
        if ( ! isset( $kits_map[ $nk_id ] ) ) {
            continue;
        }
        $kits_map[ $nk_id ]['components'][] = [
            'ruolo'             => $component['ruolo'],
            'gruppo_equivalenza'=> $component['gruppo_equivalenza'],
            'articolo_id'       => intval( $component['articolo_id_scelto'] ),
            'articolo_nome'     => $component['articolo_nome'] ?? '',
            'articolo_codice'   => $component['articolo_codice'] ?? '',
            'qty'               => intval( $component['qty'] ),
        ];
    }

    return array_values( $kits_map );
}

function bsn_render_noleggio_kits_block( $noleggio_id ) {
    $kits = bsn_get_noleggio_kits( $noleggio_id );
    if ( empty( $kits ) ) {
        return '';
    }

    ob_start();
    ?>
    <div class="bsn-doc-section" style="margin-bottom: 15px;">
        <h3 style="font-size: 12px; background: #000; color: #fff; padding: 3px 8px; margin-bottom: 8px;">KIT PRESENTI NEL NOLEGGIO</h3>
        <?php foreach ( $kits as $kit ) : ?>
            <div style="margin-bottom: 8px;">
                <strong><?php echo esc_html( $kit['kit_label'] ?: $kit['nome_kit'] ); ?></strong>
                <ul style="margin: 5px 0 0 15px; padding: 0; font-size: 10px;">
                    <?php foreach ( $kit['components'] as $comp ) : ?>
                        <li>
                            <?php
                            $label = esc_html( $comp['ruolo'] );
                            $codice = $comp['articolo_codice'] ? '[' . $comp['articolo_codice'] . '] ' : '';
                            $nome_articolo = $comp['articolo_nome'] ?: ( 'Articolo #' . $comp['articolo_id'] );
                            echo $label . ' – ' . esc_html( $comp['qty'] ) . 'x ' . esc_html( $codice . $nome_articolo );
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function bsn_parse_noleggio_kits_payload( $kits_json, $articoli_puliti ) {
    global $wpdb;

    if ( empty( $kits_json ) ) {
        return [];
    }

    $decoded = json_decode( $kits_json, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
        return new WP_Error( 'bsn_kit_json_invalid', 'Dati kit non validi.', [ 'status' => 400 ] );
    }

    $kit_ids = [];
    foreach ( $decoded as $kit ) {
        $kit_id = isset( $kit['kit_id'] ) ? intval( $kit['kit_id'] ) : 0;
        if ( $kit_id > 0 ) {
            $kit_ids[] = $kit_id;
        }
    }

    if ( empty( $kit_ids ) ) {
        return [];
    }

    $kit_defs = bsn_get_kits_with_components( $kit_ids );
    $kit_defs_map = [];
    $allowed_map = [];

    foreach ( $kit_defs as $kit_def ) {
        $kit_id = intval( $kit_def['id'] );
        $kit_defs_map[ $kit_id ] = $kit_def;
        foreach ( $kit_def['components'] as $comp ) {
            $ruolo = $comp['ruolo'];
            $gruppo = $comp['gruppo_equivalenza'];
            if ( ! isset( $allowed_map[ $kit_id ] ) ) {
                $allowed_map[ $kit_id ] = [];
            }
            if ( ! isset( $allowed_map[ $kit_id ][ $ruolo ] ) ) {
                $allowed_map[ $kit_id ][ $ruolo ] = [];
            }
            if ( ! isset( $allowed_map[ $kit_id ][ $ruolo ][ $gruppo ] ) ) {
                $allowed_map[ $kit_id ][ $ruolo ][ $gruppo ] = [];
            }
            $allowed_map[ $kit_id ][ $ruolo ][ $gruppo ][] = intval( $comp['articolo_id'] );
        }
    }

    $articoli_ids = array_map( function( $a ) { return intval( $a['id'] ); }, $articoli_puliti );
    $articoli_ids = array_values( array_unique( array_filter( $articoli_ids ) ) );

    $kits_payload = [];
    $kit_component_ids = [];

    foreach ( $decoded as $kit ) {
        $kit_id = isset( $kit['kit_id'] ) ? intval( $kit['kit_id'] ) : 0;
        if ( ! $kit_id || ! isset( $kit_defs_map[ $kit_id ] ) ) {
            return new WP_Error( 'bsn_kit_non_trovato', 'Kit non valido o mancante.', [ 'status' => 404 ] );
        }

        $kit_label = isset( $kit['kit_label'] ) ? sanitize_text_field( $kit['kit_label'] ) : '';
        $components = isset( $kit['components'] ) && is_array( $kit['components'] ) ? $kit['components'] : [];

        if ( empty( $components ) ) {
            return new WP_Error( 'bsn_kit_componenti_mancanti', 'Componenti kit mancanti.', [ 'status' => 400 ] );
        }

        $components_payload = [];
        foreach ( $components as $comp ) {
            $ruolo = isset( $comp['ruolo'] ) ? sanitize_text_field( $comp['ruolo'] ) : '';
            $gruppo = isset( $comp['gruppo_equivalenza'] ) ? sanitize_text_field( $comp['gruppo_equivalenza'] ) : '';
            $articolo_id = isset( $comp['articolo_id'] ) ? intval( $comp['articolo_id'] ) : 0;
            $qty = isset( $comp['qty'] ) ? intval( $comp['qty'] ) : 0;

            if ( $ruolo === '' || $gruppo === '' || $articolo_id <= 0 || $qty <= 0 ) {
                return new WP_Error( 'bsn_kit_componenti_invalidi', 'Componenti kit non validi.', [ 'status' => 400 ] );
            }

            $allowed = $allowed_map[ $kit_id ][ $ruolo ][ $gruppo ] ?? [];
            if ( empty( $allowed ) || ! in_array( $articolo_id, $allowed, true ) ) {
                return new WP_Error( 'bsn_kit_componenti_non_equivalenti', 'Componente kit non appartiene al gruppo selezionato.', [ 'status' => 400 ] );
            }

            $components_payload[] = [
                'ruolo'             => $ruolo,
                'gruppo_equivalenza'=> $gruppo,
                'articolo_id'       => $articolo_id,
                'qty'               => $qty,
            ];

            $kit_component_ids[] = $articolo_id;
        }

        $kits_payload[] = [
            'kit_id'           => $kit_id,
            'kit_label'        => $kit_label ?: ( $kit_defs_map[ $kit_id ]['nome_kit'] ?? '' ),
            'components'       => $components_payload,
            'articolo_kit_id'  => intval( $kit_defs_map[ $kit_id ]['articolo_kit_id'] ),
        ];
    }

    $duplicati = array_diff_assoc( $kit_component_ids, array_unique( $kit_component_ids ) );
    if ( ! empty( $duplicati ) ) {
        return new WP_Error( 'bsn_kit_componenti_duplicati', 'Componente kit duplicato nel noleggio.', [ 'status' => 400 ] );
    }

    $intersezione = array_intersect( $kit_component_ids, $articoli_ids );
    if ( ! empty( $intersezione ) ) {
        return new WP_Error( 'bsn_kit_componenti_in_noleggio', 'Un componente kit è già presente tra gli articoli del noleggio.', [ 'status' => 400 ] );
    }

    return $kits_payload;
}

function bsn_merge_articoli_con_kit_componenti( $articoli_puliti, $kits_payload ) {
    $merged = [];

    foreach ( $articoli_puliti as $art ) {
        $id = intval( $art['id'] );
        $qty = intval( $art['qty'] );
        if ( $id > 0 && $qty > 0 ) {
            if ( ! isset( $merged[ $id ] ) ) {
                $merged[ $id ] = [ 'id' => $id, 'qty' => 0 ];
            }
            $merged[ $id ]['qty'] += $qty;
        }
    }

    foreach ( $kits_payload as $kit ) {
        foreach ( $kit['components'] as $comp ) {
            $id = intval( $comp['articolo_id'] );
            $qty = intval( $comp['qty'] );
            if ( $id > 0 && $qty > 0 ) {
                if ( ! isset( $merged[ $id ] ) ) {
                    $merged[ $id ] = [ 'id' => $id, 'qty' => 0 ];
                }
                $merged[ $id ]['qty'] += $qty;
            }
        }
    }

    return array_values( $merged );
}

function bsn_save_noleggio_kits( $noleggio_id, $kits_payload ) {
    global $wpdb;

    $table_noleggio_kits = $wpdb->prefix . 'bsn_noleggio_kits';
    $table_noleggio_kit_componenti = $wpdb->prefix . 'bsn_noleggio_kit_componenti';

    $existing = $wpdb->get_results(
        $wpdb->prepare( "SELECT id FROM $table_noleggio_kits WHERE noleggio_id = %s", $noleggio_id ),
        ARRAY_A
    );
    if ( ! empty( $existing ) ) {
        $existing_ids = array_map( function( $row ) { return intval( $row['id'] ); }, $existing );
        $placeholders = implode( ',', array_fill( 0, count( $existing_ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table_noleggio_kit_componenti WHERE noleggio_kit_id IN ($placeholders)", $existing_ids ) );
        $wpdb->delete( $table_noleggio_kits, [ 'noleggio_id' => $noleggio_id ], [ '%s' ] );
    }

    if ( empty( $kits_payload ) ) {
        return;
    }

    foreach ( $kits_payload as $kit ) {
        $wpdb->insert(
            $table_noleggio_kits,
            [
                'noleggio_id' => $noleggio_id,
                'kit_id'      => $kit['kit_id'],
                'kit_label'   => $kit['kit_label'],
            ],
            [ '%s', '%d', '%s' ]
        );
        $noleggio_kit_id = (int) $wpdb->insert_id;

        foreach ( $kit['components'] as $component ) {
            $wpdb->insert(
                $table_noleggio_kit_componenti,
                [
                    'noleggio_kit_id'   => $noleggio_kit_id,
                    'ruolo'             => $component['ruolo'],
                    'gruppo_equivalenza'=> $component['gruppo_equivalenza'],
                    'articolo_id_scelto'=> $component['articolo_id'],
                    'qty'               => $component['qty'],
                ],
                [ '%d', '%s', '%s', '%d', '%d' ]
            );
        }
    }
}

function bsn_get_kit_component_usage_map( $noleggio_ids ) {
    global $wpdb;

    $noleggio_ids = array_values( array_unique( array_filter( $noleggio_ids ) ) );
    if ( empty( $noleggio_ids ) ) {
        return [];
    }

    $table_noleggio_kits = $wpdb->prefix . 'bsn_noleggio_kits';
    $table_noleggio_kit_componenti = $wpdb->prefix . 'bsn_noleggio_kit_componenti';

    $placeholders = implode( ',', array_fill( 0, count( $noleggio_ids ), '%s' ) );
    $sql = "SELECT nkc.articolo_id_scelto AS articolo_id, SUM(nkc.qty) AS qty_totale
            FROM $table_noleggio_kit_componenti nkc
            INNER JOIN $table_noleggio_kits nk ON nkc.noleggio_kit_id = nk.id
            WHERE nk.noleggio_id IN ($placeholders)
            GROUP BY nkc.articolo_id_scelto";

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $noleggio_ids ) );
    $map = [];
    foreach ( $rows as $row ) {
        $map[ intval( $row->articolo_id ) ] = intval( $row->qty_totale );
    }

    return $map;
}

add_action('rest_api_init', function () {
    register_rest_route('bsn/v1', '/kits', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_kits_get',
        'permission_callback' => 'bsn_check_admin',
    ]);

    register_rest_route('bsn/v1', '/kits', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_kits_post',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'nome_kit'        => [ 'required' => true, 'type' => 'string' ],
            'articolo_kit_id' => [ 'required' => true, 'type' => 'integer' ],
        ],
    ]);

    register_rest_route('bsn/v1', '/kits/update', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_kits_update',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id'              => [ 'required' => true, 'type' => 'integer' ],
            'nome_kit'        => [ 'required' => true, 'type' => 'string' ],
            'articolo_kit_id' => [ 'required' => true, 'type' => 'integer' ],
        ],
    ]);

    register_rest_route('bsn/v1', '/kits/delete', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_kits_delete',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [ 'required' => true, 'type' => 'integer' ],
        ],
    ]);
});

/* ==== PROFILI SCONTI (precompilazione) ==== */

/**
 * GET /profili-sconti
 */
function bsn_api_profili_sconti_get( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_profili_sconti';

    $rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY nome ASC", ARRAY_A );
    return rest_ensure_response( array( 'profili' => $rows ) );
}

/**
 * POST /profili-sconti
 * param: nome, sconto_standard, sconto_fidato, sconto_premium, sconto_service, sconto_collaboratori
 */
function bsn_api_profili_sconti_create( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_profili_sconti';

    $nome = trim( (string) $request->get_param( 'nome' ) );
    if ( $nome === '' ) {
        return new WP_Error( 'missing_nome', 'Nome profilo obbligatorio.', array( 'status' => 400 ) );
    }

    // esiste già?
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE nome = %s LIMIT 1",
        $nome
    ) );
    if ( $exists ) {
        return new WP_Error( 'duplicato', 'Un profilo con questo nome esiste già. Usa un nome diverso.', array( 'status' => 409 ) );
    }

    $data = array(
        'nome'                 => $nome,
        'sconto_standard'      => floatval( $request->get_param( 'sconto_standard' ) ),
        'sconto_fidato'        => floatval( $request->get_param( 'sconto_fidato' ) ),
        'sconto_premium'       => floatval( $request->get_param( 'sconto_premium' ) ),
        'sconto_service'       => floatval( $request->get_param( 'sconto_service' ) ),
        'sconto_collaboratori' => floatval( $request->get_param( 'sconto_collaboratori' ) ),
    );

    $wpdb->insert(
        $table,
        $data,
        array( '%s', '%f', '%f', '%f', '%f', '%f' )
    );

    if ( ! $wpdb->insert_id ) {
        return new WP_Error( 'insert_failed', 'Impossibile salvare il profilo.', array( 'status' => 500 ) );
    }

    return rest_ensure_response( array(
        'success' => true,
        'id'      => $wpdb->insert_id,
        'nome'    => $nome,
        'profile' => $data,
    ) );
}

/**
 * POST /profili-sconti/delete
 * param: id
 */
function bsn_api_profili_sconti_delete( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_profili_sconti';

    $id = intval( $request->get_param( 'id' ) );
    if ( ! $id ) {
        return new WP_Error( 'missing_id', 'ID profilo obbligatorio.', array( 'status' => 400 ) );
    }

    $profilo = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ),
        ARRAY_A
    );
    if ( ! $profilo ) {
        return new WP_Error( 'not_found', 'Profilo non trovato.', array( 'status' => 404 ) );
    }

    $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

    return rest_ensure_response( array(
        'success' => true,
        'id'      => $id,
        'nome'    => $profilo['nome'],
    ) );
}

/* ==== NOLEGGI ==== */

function bsn_sync_noleggi_ritardo() {
    global $wpdb;
    $table_noleggi = $wpdb->prefix . 'bs_noleggi';

    $oggi = current_time( 'Y-m-d' );
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE $table_noleggi
             SET stato = 'ritardo'
             WHERE stato = 'attivo'
               AND COALESCE(data_riconsegna, data_fine) IS NOT NULL
               AND DATE(COALESCE(data_riconsegna, data_fine)) < %s",
            $oggi
        )
    );
}

function bsn_api_noleggi_get($request) {
    global $wpdb;
    $table_noleggi  = $wpdb->prefix . 'bs_noleggi';
    $table_clienti  = $wpdb->prefix . 'bs_clienti';
    $table_articoli = $wpdb->prefix . 'bs_articoli';

    bsn_sync_noleggi_ritardo();

    $search = trim( (string) $request->get_param( 'search' ) );
    $stato  = sanitize_text_field( (string) $request->get_param( 'stato' ) );
    $limit  = absint( $request->get_param( 'limit' ) );
    if ( $limit < 1 ) {
        $limit = 12;
    }
    if ( $limit > 100 ) {
        $limit = 100;
    }

    $where  = [];
    $params = [];

    if ( $stato !== '' ) {
        $where[]  = 'n.stato = %s';
        $params[] = $stato;
    }

    if ( $search !== '' ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $where[] = '(CAST(n.id AS CHAR) LIKE %s OR c.nome LIKE %s OR n.note LIKE %s OR n.articoli LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT n.*, c.nome AS cliente_nome
            FROM $table_noleggi n
             LEFT JOIN $table_clienti c ON n.cliente_id = c.id";

    if ( ! empty( $where ) ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where );
    }

    $sql .= ' ORDER BY n.data_richiesta DESC';

    if ( $search === '' ) {
        $sql .= ' LIMIT %d';
        $params[] = $limit;
    } else {
        $sql .= ' LIMIT %d';
        $params[] = 100;
    }

    if ( ! empty( $params ) ) {
        $sql = $wpdb->prepare( $sql, $params );
    }

    $noleggi = $wpdb->get_results($sql);

    $ids_articoli = [];
    foreach ($noleggi as $n) {
        if (!empty($n->articoli)) {
            $articoli = json_decode($n->articoli, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($articoli)) {
                foreach ($articoli as $a) {
                    if (!empty($a['id'])) {
                        $ids_articoli[] = intval($a['id']);
                    }
                }
            }
        }
    }
    $ids_articoli = array_values(array_unique(array_filter($ids_articoli)));

    $mappa_articoli = [];
    if (!empty($ids_articoli)) {
        $placeholders = implode(',', array_fill(0, count($ids_articoli), '%d'));
        $sql_art = "SELECT id, codice, nome FROM $table_articoli WHERE id IN ($placeholders)";
        $rows_art = $wpdb->get_results($wpdb->prepare($sql_art, $ids_articoli));
        foreach ($rows_art as $r) {
            $mappa_articoli[intval($r->id)] = [
                'codice' => $r->codice,
                'nome'   => $r->nome,
            ];
        }
    }

    $table_ticket   = $wpdb->prefix . 'bs_ticket';
    $ticket_map = [];
    $ticket_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_ticket ) );
    if ( $ticket_table_exists === $table_ticket && ! empty( $ids_articoli ) ) {
        $placeholders = implode(',', array_fill(0, count($ids_articoli), '%d'));
        $sql_ticket = "SELECT articolo_id, COUNT(*) AS totale FROM $table_ticket WHERE stato = 'aperto' AND articolo_id IN ($placeholders) GROUP BY articolo_id";
        $rows_ticket = $wpdb->get_results( $wpdb->prepare( $sql_ticket, $ids_articoli ) );
        foreach ( $rows_ticket as $row ) {
            $ticket_map[ intval( $row->articolo_id ) ] = intval( $row->totale );
        }
    }

    $output = [];
    foreach ($noleggi as $n) {
        $articoli_riassunto = '-';
        if (!empty($n->articoli)) {
            $articoli = json_decode($n->articoli, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($articoli) && !empty($articoli)) {
                $parts = [];
                foreach ($articoli as $a) {
                    $qty    = isset($a['qty']) ? intval($a['qty']) : 1;
                    $id_art = isset($a['id'])  ? intval($a['id'])  : 0;
                    if ($id_art > 0 && $qty > 0) {
                        $label = $qty . 'x ';
                        if (isset($mappa_articoli[$id_art])) {
                            $info = $mappa_articoli[$id_art];
                            if (!empty($info['codice'])) {
                                $label .= '[' . $info['codice'] . '] ';
                            }
                            $label .= $info['nome'];
                        } else {
                            $label .= 'ID#' . $id_art;
                        }
                        $parts[] = $label;
                    }
                }
                if (!empty($parts)) {
                    $articoli_riassunto = implode(', ', $parts);
                }
            }
        }

        $periodo_utilizzo = bsn_get_periodo_utilizzo_noleggio( $n );
        $periodo_logistico = bsn_get_periodo_logistico_noleggio( $n );

        $has_ticket = false;
        if (!empty($n->articoli)) {
            $articoli_ticket = json_decode($n->articoli, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($articoli_ticket)) {
                foreach ($articoli_ticket as $a) {
                    $id_art = isset($a['id']) ? intval($a['id']) : 0;
                    if ($id_art > 0 && ! empty($ticket_map[$id_art])) {
                        $has_ticket = true;
                        break;
                    }
                }
            }
        }

        $output[] = [
            'id'                 => $n->id,
            'cliente_nome'       => $n->cliente_nome,
            'data_da'            => $periodo_utilizzo['inizio_it'],
            'data_a'             => $periodo_utilizzo['fine_it'],
            'data_inizio_raw'    => $periodo_utilizzo['inizio'],
            'data_fine_raw'      => $periodo_utilizzo['fine'],
            'data_ritiro'        => $periodo_logistico['ritiro_it'],
            'data_riconsegna'    => $periodo_logistico['riconsegna_it'],
            'data_ritiro_raw'    => $periodo_logistico['ritiro'],
            'data_riconsegna_raw' => $periodo_logistico['riconsegna'],
            'data_ritiro_effettiva' => $periodo_logistico['ritiro_effettivo_it'],
            'data_riconsegna_effettiva' => $periodo_logistico['riconsegna_effettiva_it'],
            'data_ritiro_effettiva_raw' => $periodo_logistico['ritiro_effettivo'],
            'data_riconsegna_effettiva_raw' => $periodo_logistico['riconsegna_effettiva'],
            'stato'              => $n->stato,
            'origine'            => isset( $n->origine ) ? $n->origine : 'manuale',
            'articoli_riassunto' => $articoli_riassunto,
            'ticket_alert'       => $has_ticket ? 1 : 0,
        ];
    }

    return rest_ensure_response(['noleggi' => $output]);
}
/**
 * Calcola il totale noleggio in base a:
 * - articoli (array con id, qty)
 * - giorni
 * - categoria cliente
 * - regime_percentuale
 *
 * Regole:
 * - sconto per categoria cliente
 * - articoli scalari (noleggio_scalare = 1): prezzo_netto * qty * sqrt(giorni)
 * - articoli NON scalari: prezzo_netto * qty (senza moltiplicatore giorni)
 * - alla fine applica regime_percentuale sul totale
 */
function bsn_calcola_totale_noleggio( $articoli_puliti, $giorni, $categoria_cliente, $regime_percentuale, $wpdb, $table_articoli ) {
    $totale_calcolato = 0.0;

    if ( empty( $articoli_puliti ) ) {
        return 0.0;
    }

    // Raccogli ID articoli
    $ids_articoli = array_map(
        function( $a ) {
            return $a['id'];
        },
        $articoli_puliti
    );
    $ids_articoli = array_values( array_unique( $ids_articoli ) );

    if ( empty( $ids_articoli ) ) {
        return 0.0;
    }

    $placeholders = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );

    // Leggiamo tutti i campi necessari al calcolo prezzo/sconto quantita.
    $sql_art = "
        SELECT *
        FROM $table_articoli
        WHERE id IN ($placeholders)
    ";
    $rows_art = $wpdb->get_results( $wpdb->prepare( $sql_art, $ids_articoli ) );

    if ( empty( $rows_art ) ) {
        return 0.0;
    }

    $mappa_prezzi = [];
    foreach ( $rows_art as $row ) {
        $mappa_prezzi[ (int) $row->id ] = bsn_normalize_article_row_array( $row );
    }

    // Fattore giorni per articoli SCALARI
    $fattore_giorni_scalare = sqrt( max( 1, (float) $giorni ) );

    foreach ( $articoli_puliti as $a ) {
        $id_art = isset( $a['id'] )  ? (int) $a['id']  : 0;
        $qty    = isset( $a['qty'] ) ? (int) $a['qty'] : 0;

        if ( $id_art <= 0 || $qty <= 0 ) {
            continue;
        }

        if ( ! isset( $mappa_prezzi[ $id_art ] ) ) {
            continue;
        }

        $info = $mappa_prezzi[ $id_art ];
        $line_price = bsn_calculate_noleggio_article_line_price( $a, $info, $categoria_cliente, $giorni, true );
        $totale_calcolato += (float) ( $line_price['line_total'] ?? 0 );
        continue;

        // Se nell'array pulito c'è un prezzo custom, usalo
        if ( isset( $a['prezzo'] ) && $a['prezzo'] !== '' && $a['prezzo'] !== null ) {
            $prezzo = (float) $a['prezzo'];
        } else {
            $prezzo = (float) $info['prezzo_giorno'];
        }

        // Sconto in base alla categoria cliente
        $sconto = 0.0;
        switch ( $categoria_cliente ) {
            case 'fidato':
                $sconto = $info['sconto_fidato'];
                break;
            case 'premium':
                $sconto = $info['sconto_premium'];
                break;
            case 'service':
                $sconto = $info['sconto_service'];
                break;
            case 'collaboratori':
                $sconto = $info['sconto_collaboratori'];
                break;
            case 'standard':
            default:
                $sconto = $info['sconto_standard'];
                break;
        }

        $fattore_sconto = max( 0, min( 100, (float) $sconto ) );
        $prezzo_netto   = $prezzo * ( 1 - $fattore_sconto / 100 );

        // Articolo SCALARE → usa √giorni
        // Articolo NON scalare → no giorni
        if ( ! empty( $info['noleggio_scalare'] ) ) {
            $totale_calcolato += $prezzo_netto * $qty * $fattore_giorni_scalare;
        } else {
            $totale_calcolato += $prezzo_netto * $qty;
        }
    }

    // Applicazione regime fiscale sul totale
    $regime = (float) $regime_percentuale;
    if ( $regime > 0 ) {
        $totale_calcolato = $totale_calcolato * ( 1 + ( $regime / 100 ) );
    }

    return $totale_calcolato;
}

/**
 * Calcola le sovrapposizioni per un noleggio (nuovo o esistente).
 *
 * @param array  $articoli_puliti  Array di articoli [{id, qty, prezzo}, ...]
 * @param string $data_inizio      'YYYY-MM-DD'
 * @param string $data_fine        'YYYY-MM-DD'
 * @param string $id_noleggio_corrente ID noleggio da escludere ('' se nuovo)
 * @param wpdb   $wpdb
 * @param string $table_articoli   Nome tabella articoli
 * @param string $table_noleggi    Nome tabella noleggi
 *
 * @return array Elenco stringhe descrittive delle sovrapposizioni
 */
function bsn_calcola_sovrapposizioni_noleggio( $articoli_puliti, $data_inizio, $data_fine, $id_noleggio_corrente, $wpdb, $table_articoli, $table_noleggi ) {
    $sovrapposizioni = [];
    $ticket_qty_map  = [];

    if ( empty( $articoli_puliti ) ) {
        return $sovrapposizioni;
    }

    $ids_articoli = array_map( function( $a ) { return $a['id']; }, $articoli_puliti );
    $ids_articoli = array_values( array_unique( $ids_articoli ) );

    if ( empty( $ids_articoli ) ) {
        return $sovrapposizioni;
    }

    // Mappa disponibilità articoli
    $placeholders = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );
    $sql_art      = "SELECT id, qty_disponibile, nome, codice FROM $table_articoli WHERE id IN ($placeholders)";
    $art_rows     = $wpdb->get_results( $wpdb->prepare( $sql_art, $ids_articoli ) );

    if ( empty( $art_rows ) ) {
        return $sovrapposizioni;
    }

    $mappa_disponibile = [];
    foreach ( $art_rows as $row ) {
        $mappa_disponibile[ intval( $row->id ) ] = [
            'qty_disponibile' => intval( $row->qty_disponibile ),
            'nome'            => $row->nome,
            'codice'          => $row->codice,
        ];
    }

    // Ticket aperti (se tabella esiste)
    $table_ticket = $wpdb->prefix . 'bs_ticket';
    $ticket_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_ticket ) );
    if ( $ticket_table_exists === $table_ticket ) {
        $placeholders_ticket = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );
        $sql_ticket = "SELECT articolo_id, SUM(qty) AS qty_ticket
                       FROM $table_ticket
                       WHERE stato = 'aperto'
                       AND articolo_id IN ($placeholders_ticket)
                       GROUP BY articolo_id";
        $rows_ticket = $wpdb->get_results( $wpdb->prepare( $sql_ticket, $ids_articoli ) );
        foreach ( $rows_ticket as $row ) {
            $ticket_qty_map[ intval( $row->articolo_id ) ] = intval( $row->qty_ticket );
        }
    }

    $ticket_map = [];
    $ticket_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_ticket ) );
    if ( $ticket_table_exists === $table_ticket && ! empty( $ids_articoli ) ) {
        $placeholders = implode(',', array_fill(0, count($ids_articoli), '%d'));
        $sql_ticket = "SELECT articolo_id, COUNT(*) AS totale
                       FROM $table_ticket
                       WHERE stato = 'aperto'
                       AND articolo_id IN ($placeholders)
                       GROUP BY articolo_id";
        $rows_ticket = $wpdb->get_results( $wpdb->prepare( $sql_ticket, $ids_articoli ) );
        foreach ( $rows_ticket as $row ) {
            $ticket_map[ intval( $row->articolo_id ) ] = intval( $row->totale );
        }
    }
    
    $data_inizio_full = bsn_normalize_date_input( $data_inizio, false );
    $data_fine_full   = bsn_normalize_date_input( $data_fine, true );

    if ( ! empty( $id_noleggio_corrente ) ) {
        $sql_nol = "SELECT id, articoli, data_inizio, data_fine, data_ritiro, data_riconsegna
            FROM $table_noleggi
            WHERE id <> %s
              AND COALESCE(data_ritiro, data_inizio) <= %s
              AND COALESCE(data_riconsegna, data_fine) >= %s
              AND stato IN ('bozza', 'attivo', 'ritardo')";
        $existing = $wpdb->get_results( $wpdb->prepare( $sql_nol, $id_noleggio_corrente, $data_fine_full, $data_inizio_full ) );
    } else {
        $sql_nol = "SELECT id, articoli, data_inizio, data_fine, data_ritiro, data_riconsegna
            FROM $table_noleggi
            WHERE COALESCE(data_ritiro, data_inizio) <= %s
              AND COALESCE(data_riconsegna, data_fine) >= %s
              AND stato IN ('bozza', 'attivo', 'ritardo')";
        $existing = $wpdb->get_results( $wpdb->prepare( $sql_nol, $data_fine_full, $data_inizio_full ) );
    }

    $existing_ids = [];
    if ( ! empty( $existing ) ) {
        foreach ( $existing as $ex ) {
            $existing_ids[] = $ex->id;
        }
    }
    $kit_usage_map = bsn_get_kit_component_usage_map( $existing_ids );

    foreach ( $articoli_puliti as $a ) {
        $id_art  = $a['id'];
        $qty_new = $a['qty'];

        if ( ! isset( $mappa_disponibile[ $id_art ] ) ) {
            continue;
        }

        $qty_disp = $mappa_disponibile[ $id_art ]['qty_disponibile'];

        $qty_in_uso = 0;
        if ( ! empty( $existing ) ) {
            foreach ( $existing as $ex ) {
                if ( empty( $ex->articoli ) ) {
                    continue;
                }
                $ex_art = json_decode( $ex->articoli, true );
                if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $ex_art ) ) {
                    continue;
                }
                foreach ( $ex_art as $ea ) {
                    if ( isset( $ea['id'] ) && intval( $ea['id'] ) === $id_art ) {
                        $qty_in_uso += isset( $ea['qty'] ) ? intval( $ea['qty'] ) : 0;
                    }
                }
            }
        }

        if ( isset( $kit_usage_map[ $id_art ] ) ) {
            $qty_in_uso += $kit_usage_map[ $id_art ];
        }

        $qty_totale = $qty_in_uso + $qty_new;
        if ( $qty_totale > $qty_disp ) {
            $info        = $mappa_disponibile[ $id_art ];
            $qty_libere  = max( 0, $qty_disp - $qty_in_uso );
            $qty_ticket  = isset( $ticket_qty_map[ $id_art ] ) ? $ticket_qty_map[ $id_art ] : 0;
            $sovrapposizioni[] = sprintf(
                '%dx richiesti per [%s] %s (a stock: %d, già in uso: %d, disponibilità: %d, in tiket: %d)',
                $qty_new,
                $info['codice'],
                $info['nome'],
                $qty_disp,
                $qty_in_uso,
                $qty_libere,
                $qty_ticket
            );
        }
    }

    return $sovrapposizioni;
}

/**
 * Endpoint batch per stati alert in anteprima noleggio.
 * Restituisce disponibilità + ticket aperti per ciascun articolo.
 */
function bsn_api_noleggi_alert_stato( WP_REST_Request $request ) {
    global $wpdb;

    $table_noleggi  = $wpdb->prefix . 'bs_noleggi';
    $table_articoli = $wpdb->prefix . 'bs_articoli';
    $table_ticket   = $wpdb->prefix . 'bs_ticket';

    $data_inizio = sanitize_text_field( $request->get_param( 'data_inizio' ) );
    $data_fine   = sanitize_text_field( $request->get_param( 'data_fine' ) );
    $items       = $request->get_param( 'items' );
    $exclude_id  = sanitize_text_field( $request->get_param( 'exclude_noleggio_id' ) );

    if ( empty( $data_inizio ) || empty( $data_fine ) || empty( $items ) || ! is_array( $items ) ) {
        return rest_ensure_response( [] );
    }

    $requested_map = [];
    foreach ( $items as $item ) {
        $art_id = isset( $item['articolo_id'] ) ? intval( $item['articolo_id'] ) : 0;
        $qty    = isset( $item['qty'] ) ? intval( $item['qty'] ) : 0;
        if ( $art_id > 0 && $qty > 0 ) {
            if ( ! isset( $requested_map[ $art_id ] ) ) {
                $requested_map[ $art_id ] = 0;
            }
            $requested_map[ $art_id ] += $qty;
        }
    }

    if ( empty( $requested_map ) ) {
        return rest_ensure_response( [] );
    }

    $ids_articoli = array_keys( $requested_map );
    $placeholders = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );

    $art_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, qty_disponibile FROM $table_articoli WHERE id IN ($placeholders)",
            $ids_articoli
        )
    );

    $disponibili = [];
    foreach ( $art_rows as $row ) {
        $disponibili[ intval( $row->id ) ] = intval( $row->qty_disponibile );
    }

    $ticket_map = [];
    $ticket_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_ticket ) );
    if ( $ticket_table_exists === $table_ticket ) {
        $rows_ticket = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT articolo_id, COUNT(*) AS totale
                 FROM $table_ticket
                 WHERE stato = 'aperto'
                   AND articolo_id IN ($placeholders)
                 GROUP BY articolo_id",
                $ids_articoli
            )
        );
        foreach ( $rows_ticket as $row ) {
            $ticket_map[ intval( $row->articolo_id ) ] = intval( $row->totale ) > 0;
        }
    }

    $data_inizio_full = bsn_normalize_date_input( $data_inizio, false );
    $data_fine_full   = bsn_normalize_date_input( $data_fine, true );

    if ( ! empty( $exclude_id ) ) {
        $sql_nol = "SELECT id, articoli, data_inizio, data_fine, data_ritiro, data_riconsegna
            FROM $table_noleggi
            WHERE id <> %s
              AND COALESCE(data_ritiro, data_inizio) <= %s
              AND COALESCE(data_riconsegna, data_fine) >= %s
              AND stato IN ('bozza', 'attivo', 'ritardo')
            ORDER BY data_inizio ASC";
        $existing = $wpdb->get_results( $wpdb->prepare( $sql_nol, $exclude_id, $data_fine_full, $data_inizio_full ) );
    } else {
        $sql_nol = "SELECT id, articoli, data_inizio, data_fine, data_ritiro, data_riconsegna
            FROM $table_noleggi
            WHERE COALESCE(data_ritiro, data_inizio) <= %s
              AND COALESCE(data_riconsegna, data_fine) >= %s
              AND stato IN ('bozza', 'attivo', 'ritardo')
            ORDER BY data_inizio ASC";
        $existing = $wpdb->get_results( $wpdb->prepare( $sql_nol, $data_fine_full, $data_inizio_full ) );
    }

    $qty_in_uso = array_fill_keys( $ids_articoli, 0 );
    $first_conflict = array_fill_keys( $ids_articoli, '' );

    if ( ! empty( $existing ) ) {
        foreach ( $existing as $ex ) {
            if ( empty( $ex->articoli ) ) {
                continue;
            }
            $ex_art = json_decode( $ex->articoli, true );
            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $ex_art ) ) {
                continue;
            }
            foreach ( $ex_art as $ea ) {
                $id_art = isset( $ea['id'] ) ? intval( $ea['id'] ) : 0;
                $qty    = isset( $ea['qty'] ) ? intval( $ea['qty'] ) : 0;
                if ( $id_art > 0 && $qty > 0 && isset( $qty_in_uso[ $id_art ] ) ) {
                    $qty_in_uso[ $id_art ] += $qty;
                    if ( empty( $first_conflict[ $id_art ] ) ) {
                        $first_conflict[ $id_art ] = $ex->id;
                    }
                }
            }
        }
    }

    $existing_ids = [];
    if ( ! empty( $existing ) ) {
        foreach ( $existing as $ex ) {
            $existing_ids[] = $ex->id;
        }
    }
    $kit_usage_map = bsn_get_kit_component_usage_map( $existing_ids );
    foreach ( $kit_usage_map as $art_id => $qty ) {
        if ( isset( $qty_in_uso[ $art_id ] ) ) {
            $qty_in_uso[ $art_id ] += $qty;
        }
    }

    $response = [];
    foreach ( $requested_map as $art_id => $qty_requested ) {
        $qty_disp = isset( $disponibili[ $art_id ] ) ? $disponibili[ $art_id ] : 0;
        $qty_used = isset( $qty_in_uso[ $art_id ] ) ? $qty_in_uso[ $art_id ] : 0;
        $qty_available = max( 0, $qty_disp - $qty_used );
        $is_available = ( $qty_requested <= $qty_available );

        $response[ $art_id ] = [
            'is_available' => $is_available,
            'first_conflict_noleggio_id' => $is_available ? '' : ( $first_conflict[ $art_id ] ?? '' ),
            'has_open_ticket' => ! empty( $ticket_map[ $art_id ] ),
            'qty_requested' => $qty_requested,
            'qty_available' => $qty_available,
        ];
    }

    return rest_ensure_response( $response );
}

function bsn_api_noleggi_post($request) {
    global $wpdb;
    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $table_articoli = $wpdb->prefix . 'bs_articoli';

    // ID noleggio (per UPDATE o per generare nuovo)
    $noleggio_id = sanitize_text_field($request->get_param('noleggio_id'));
    $is_update = !empty($noleggio_id);
    $noleggio_esistente = null;
    if ( $is_update ) {
        $noleggio_esistente = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table_noleggi WHERE id = %s LIMIT 1", $noleggio_id ),
            ARRAY_A
        );
        if ( ! $noleggio_esistente ) {
            return new WP_Error('bsn_noleggio_non_trovato', 'Noleggio non trovato', ['status' => 404]);
        }
    }

    // Dati base
    $cliente_id = intval($request->get_param('cliente_id'));
    $data_inizio = sanitize_text_field($request->get_param('data_inizio'));
    $data_fine = sanitize_text_field($request->get_param('data_fine'));
    $data_ritiro = sanitize_text_field($request->get_param('data_ritiro'));
    $data_riconsegna = sanitize_text_field($request->get_param('data_riconsegna'));
    $stato = sanitize_text_field($request->get_param('stato')) ?: 'bozza';
    $note = sanitize_textarea_field($request->get_param('note'));
    $metodo_pagamento = sanitize_text_field($request->get_param('metodo_pagamento'));

    // ===== NUOVI CAMPI LOGISTICA =====
    $luogo_destinazione = sanitize_text_field($request->get_param('luogo_destinazione'));
    $trasporto_mezzo = sanitize_text_field($request->get_param('trasporto_mezzo'));
    $cauzione = sanitize_text_field($request->get_param('cauzione'));
    $causale_trasporto = sanitize_text_field($request->get_param('causale_trasporto'));

    // ===== INCARICATO AL RITIRO (opzionale) =====
    $incaricato_id_raw = $request->get_param('incaricato_ritiro_cliente_id');
    $incaricato_ritiro_cliente_id = ( $incaricato_id_raw !== null && $incaricato_id_raw !== '' )
        ? absint( $incaricato_id_raw )
        : null;
    // Valida che l'incaricato esista in bs_clienti (se fornito)
    if ( $incaricato_ritiro_cliente_id !== null && $incaricato_ritiro_cliente_id > 0 ) {
        $incaricato_exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM $table_clienti WHERE id = %d LIMIT 1", $incaricato_ritiro_cliente_id )
        );
        if ( ! $incaricato_exists ) {
            return new WP_Error( 'bsn_incaricato_non_trovato', 'Incaricato al ritiro non trovato in anagrafica.', [ 'status' => 400 ] );
        }
    }

    // ===== ARTICOLI CON PREZZI CUSTOM =====
$articoli_json = $request->get_param('articoli');

if (empty($cliente_id) || empty($data_inizio) || empty($data_fine)) {
    return new WP_Error('bsn_dati_mancanti', 'Dati obbligatori mancanti', ['status' => 400]);
}

$data_ritiro_effettiva = $data_ritiro ?: $data_inizio;
$data_riconsegna_effettiva = $data_riconsegna ?: $data_fine;

if ( bsn_is_invalid_date_range( $data_inizio, $data_fine ) ) {
    return new WP_Error( 'bsn_periodo_utilizzo_non_valido', 'La Data inizio noleggio non può essere successiva alla Data termine noleggio.', [ 'status' => 400 ] );
}

if ( bsn_is_invalid_date_range( $data_ritiro_effettiva, $data_riconsegna_effettiva ) ) {
    return new WP_Error( 'bsn_periodo_logistico_non_valido', 'La Data ritiro non può essere successiva alla Data riconsegna.', [ 'status' => 400 ] );
}

// Decodifica il JSON ricevuto dal JS
$articoli = [];
if (!empty($articoli_json)) {
    $decoded = json_decode($articoli_json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        // Pulisci e valida ogni articolo
        foreach ($decoded as $a) {
            $id_art = isset($a['id']) ? intval($a['id']) : 0;
            $qty = isset($a['qty']) ? intval($a['qty']) : 0;
            if ($id_art > 0 && $qty > 0) {
                $articolo_item = [
                    'id' => $id_art,
                    'qty' => $qty,
                    'prezzo' => isset($a['prezzo']) ? floatval($a['prezzo']) : null,
                ];
                if ( ! empty( $a['service_internal'] ) ) {
                    $articolo_item['service_internal'] = 1;
                }
                foreach ( array( 'service_component_label', 'service_component_code', 'service_mode', 'public_product_title', 'public_product_permalink', 'public_product_image_url', 'public_product_code', 'public_pricing_category', 'public_request_origin', 'calculation_type', 'dimension_summary', 'selected_preset_label', 'dimension_note' ) as $optional_key ) {
                    if ( isset( $a[ $optional_key ] ) && $a[ $optional_key ] !== '' && $a[ $optional_key ] !== null ) {
                        $articolo_item[ $optional_key ] = sanitize_text_field( (string) $a[ $optional_key ] );
                    }
                }
                foreach ( array( 'public_product_id', 'public_min_qty', 'modules_x', 'modules_y', 'modules_total' ) as $optional_int_key ) {
                    if ( isset( $a[ $optional_int_key ] ) && $a[ $optional_int_key ] !== '' && $a[ $optional_int_key ] !== null ) {
                        $articolo_item[ $optional_int_key ] = intval( $a[ $optional_int_key ] );
                    }
                }
                foreach ( array( 'public_unit_price_net', 'public_line_total', 'requested_width_m', 'requested_height_m', 'technical_width_m', 'technical_height_m', 'area_mq', 'qty_discount_applied_pct', 'unit_price_before_qty_discount', 'unit_price_after_qty_discount' ) as $optional_float_key ) {
                    if ( isset( $a[ $optional_float_key ] ) && $a[ $optional_float_key ] !== '' && $a[ $optional_float_key ] !== null ) {
                        $articolo_item[ $optional_float_key ] = round( (float) $a[ $optional_float_key ], 2 );
                    }
                }
                $articoli[] = $articolo_item;
            }
        }
    }
}

 $kits_payload = bsn_parse_noleggio_kits_payload( $request->get_param( 'kits' ), $articoli );
    if ( is_wp_error( $kits_payload ) ) {
        return $kits_payload;
    }

    // ===== CALCOLO TOTALE (con prezzi custom e sconto globale) =====
    $cliente = $wpdb->get_row($wpdb->prepare(
        "SELECT categoria_cliente, regime_percentuale FROM $table_clienti WHERE id = %d",
        $cliente_id
    ), ARRAY_A);

    if (!$cliente) {
        return new WP_Error('bsn_cliente_non_trovato', 'Cliente non trovato', ['status' => 404]);
    }

    $categoria = $cliente['categoria_cliente'] ?? 'standard';
    $regime = (float)($cliente['regime_percentuale'] ?? 22.00);

    // Calcolo giorni
    $ts_inizio = strtotime($data_inizio);
    $ts_fine = strtotime($data_fine);
    $giorni = max(1, (int)ceil(($ts_fine - $ts_inizio) / 86400));
    $fattore_scalare = sqrt($giorni);

    // Recupera info articoli dal DB
    $ids_articoli = array_map(function($a) { return (int)$a['id']; }, $articoli);
    $mappa_articoli = [];
    
    if (!empty($ids_articoli)) {
        $placeholders = implode(',', array_fill(0, count($ids_articoli), '%d'));
        $sql = "SELECT * FROM $table_articoli WHERE id IN ($placeholders)";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $ids_articoli), ARRAY_A);
        
        foreach ($rows as $row) {
            $mappa_articoli[(int)$row['id']] = $row;
        }
    }

    foreach ( $articoli as &$art ) {
        $art_id = (int) ( $art['id'] ?? 0 );
        if ( $art_id < 1 || empty( $mappa_articoli[ $art_id ] ) ) {
            continue;
        }

        $articolo_db = $mappa_articoli[ $art_id ];
        $service_code = trim( (string) ( $art['service_component_code'] ?? ( $articolo_db['codice'] ?? '' ) ) );
        if ( bsn_is_internal_service_article_code( $service_code ) ) {
            $art['service_internal'] = 1;
            $art['service_component_code'] = $service_code;
            $art['service_component_label'] = bsn_get_internal_service_article_display_label(
                $service_code,
                (string) ( $art['service_component_label'] ?? $articolo_db['nome'] ?? '' )
            );
        }
    }
    unset( $art );

    // Calcolo subtotale
    $subtotale = 0;
    foreach ($articoli as &$art) {
        $art_id = $art['id'];
        $qty = $art['qty'];
        $prezzo_custom = $art['prezzo'];
        
        if (!isset($mappa_articoli[$art_id])) continue;
        
        $art_db = $mappa_articoli[$art_id];
        $prezzo_base = (float)($art_db['prezzo_giorno'] ?? 0);
        $noleggio_scalare = (int)($art_db['noleggio_scalare'] ?? 0);
        
        // Usa prezzo custom se fornito, altrimenti prezzo base
        $prezzo = ($prezzo_custom !== null) ? $prezzo_custom : $prezzo_base;
        
    // ===== IMPORTANTE: Se prezzo_custom è null, lo impostiamo al prezzo_base =====
        // In modo che venga salvato nel JSON
        if ($art['prezzo'] === null) {
            $art['prezzo'] = $prezzo_base;
        }

        $line_price = bsn_calculate_noleggio_article_line_price( $art, $art_db, $categoria, $giorni, true );
        $subtotale += (float) ( $line_price['line_total'] ?? 0 );
        continue;
        
        // Applica sconto categoria
        $sconto = 0;
        switch ($categoria) {
            case 'fidato': $sconto = (float)($art_db['sconto_fidato'] ?? 0); break;
            case 'premium': $sconto = (float)($art_db['sconto_premium'] ?? 0); break;
            case 'service': $sconto = (float)($art_db['sconto_service'] ?? 0); break;
            case 'collaboratori': $sconto = (float)($art_db['sconto_collaboratori'] ?? 0); break;
            default: $sconto = (float)($art_db['sconto_standard'] ?? 0); break;
        }
        
        $prezzo_netto = $prezzo * (1 - ($sconto / 100));
        
        // Calcolo subtotale riga
        if ($noleggio_scalare === 1) {
            $subtotale_riga = $prezzo_netto * $qty * $fattore_scalare;
        } else {
            $subtotale_riga = $prezzo_netto * $qty;
        }
        
        $subtotale += $subtotale_riga;
    }

    // Calcolo totale con regime
    $importo_regime = $subtotale * ($regime / 100);
    $totale_noleggio = $subtotale + $importo_regime;

    // ===== SCONTO GLOBALE =====
    $sconto_globale = (float)($request->get_param('sconto_globale') ?? 0);
    $totale_finale = $totale_noleggio + $sconto_globale; // + perché se negativo riduce, se positivo aumenta

    // ----- CONTROLLO SOVRAPPOSIZIONI ANCHE IN CREAZIONE (POST) -----
    $sovrapposizioni = [];
    if ( $stato !== 'preventivo' ) {
        $articoli_disponibilita = bsn_merge_articoli_con_kit_componenti( $articoli, $kits_payload );
        $sovrapposizioni = bsn_calcola_sovrapposizioni_noleggio(
            $articoli_disponibilita,
            $data_ritiro ?: $data_inizio,
            $data_riconsegna ?: $data_fine,
            '', // nuovo noleggio → nessun ID da escludere
            $wpdb,
            $table_articoli,
            $table_noleggi
        );

    $consenti_overbook = intval( $request->get_param( 'consenti_overbook' ) );
        if ( ! empty( $sovrapposizioni ) && ! $consenti_overbook ) {
            return new WP_Error(
                'bsn_sovrapposizioni_blocco',
                "Sovrapposizioni rilevate:\n- " . implode( "\n- ", $sovrapposizioni ),
                [ 'status' => 400, 'sovrapposizioni' => $sovrapposizioni ]
            );
        }
    }

    // ===== GENERA O MANTIENE ID NOLEGGIO =====
    if (!$is_update) {
        $anno = date('Y');
        $ultimo = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(id, '/', -1) AS UNSIGNED)) 
             FROM $table_noleggi 
             WHERE id LIKE %s",
            $anno . '/%'
        ));
        $progressivo = str_pad((intval($ultimo) + 1), 3, '0', STR_PAD_LEFT);
        $noleggio_id = "$anno/$progressivo";
    }

    // ===== PREPARA DATI PER SALVATAGGIO =====
    $data = [
        'cliente_id' => $cliente_id,
        'data_richiesta' => current_time('mysql'),
        'data_inizio' => bsn_normalize_date_input( $data_inizio, false ),
        'data_fine' => bsn_normalize_date_input( $data_fine, true ),
        'data_ritiro' => bsn_normalize_date_input( $data_ritiro, false ),
        'data_riconsegna' => bsn_normalize_date_input( $data_riconsegna, true ),
        'stato' => $stato,
        'articoli' => wp_json_encode($articoli), // ← ORA INCLUDE I PREZZI!
        'totale_calcolato' => $totale_finale,
        'sconto_globale' => $sconto_globale,
        'note' => $note,
        'metodo_pagamento' => $metodo_pagamento,
        'luogo_destinazione' => $luogo_destinazione,
        'trasporto_mezzo' => $trasporto_mezzo,
        'cauzione' => $cauzione,
        'causale_trasporto'            => $causale_trasporto,
        'incaricato_ritiro_cliente_id' => ( $incaricato_ritiro_cliente_id > 0 ) ? $incaricato_ritiro_cliente_id : null,
        'origine'                      => sanitize_key( (string) ( $request->get_param( 'origine' ) ?: 'manuale' ) ),
    ];

    if (
        $is_update
        && (
            bsn_is_frontend_quote_noleggio( $noleggio_esistente )
            || is_array( bsn_get_noleggio_snapshot_json( $noleggio_esistente, 'snapshot_prezzi_json' ) )
        )
    ) {
        $data['snapshot_prezzi_json'] = wp_json_encode(
            bsn_get_frontend_quote_snapshot_after_admin_update( $noleggio_esistente, $totale_finale, $sconto_globale )
        );
    }

    // ===== SALVA (INSERT O UPDATE) =====
    if ($is_update) {
        $result = $wpdb->update($table_noleggi, $data, ['id' => $noleggio_id]);
        if ($result === false) {
            return new WP_Error('bsn_update_error', 'Errore aggiornamento noleggio', ['status' => 500]);
        }
    } else {
        $data['id'] = $noleggio_id;
        $result = $wpdb->insert($table_noleggi, $data);
        if (!$result) {
            return new WP_Error('bsn_insert_error', 'Errore salvataggio noleggio', ['status' => 500]);
        }
    }

    if ( $is_update ) {
        bsn_invalidate_preventivo_pdf_cache( $noleggio_id );
    }

    bsn_save_noleggio_kits( $noleggio_id, $kits_payload );

    if ( $is_update && $noleggio_esistente && sanitize_key( (string) ( $noleggio_esistente['stato'] ?? '' ) ) === 'preventivo' && $stato === 'bozza' ) {
        $cliente_notifica = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table_clienti WHERE id = %d LIMIT 1", (int) $cliente_id ),
            ARRAY_A
        );
        $noleggio_notifica = array_merge( $noleggio_esistente, $data, array( 'id' => $noleggio_id, 'cliente_id' => $cliente_id ) );
        $pdf_info = bsn_generate_preventivo_pdf( $noleggio_id, true );
        if ( is_wp_error( $pdf_info ) ) {
            error_log( 'BSN EMAIL: PDF conferma richiesta non disponibile per ' . $noleggio_id . ' - ' . $pdf_info->get_error_message() );
            $pdf_info = false;
        }
        bsn_invia_email_conferma_preventivo_cliente( $noleggio_notifica, $cliente_notifica, $pdf_info );
    }

    return rest_ensure_response([
        'success' => true,
        'id' => $noleggio_id,
        'totale' => $totale_finale,
        'sovrapposizioni'  => $sovrapposizioni,
    ]);
}

/**
 * Endpoint update noleggio esistente
 * POST /wp-json/bsn/v1/noleggi/update
 */
add_action('rest_api_init', function () {
    register_rest_route('bsn/v1', '/noleggi/update', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_noleggi_update',
        'permission_callback' => 'bsn_check_admin',
    ]);
});

/* ==== NOLEGGI: DELETE ==== */

function bsn_api_noleggi_delete( WP_REST_Request $request ) {
    $id = sanitize_text_field( $request->get_param( 'id' ) );
    if ( empty( $id ) ) {
        return new WP_Error(
            'bsn_noleggio_id_mancante',
            'ID noleggio mancante',
            [ 'status' => 400 ]
        );
    }

    $deleted = bsn_delete_noleggio_by_id( $id );
    if ( is_wp_error( $deleted ) ) {
        return new WP_Error(
            'bsn_delete_noleggio_error',
            $deleted->get_error_message(),
            [ 'status' => 500 ]
        );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $id,
    ] );
}


function bsn_api_noleggi_update( WP_REST_Request $request ) {
    global $wpdb;
    $table_noleggi  = $wpdb->prefix . 'bs_noleggi';
    $table_articoli = $wpdb->prefix . 'bs_articoli';
    $table_clienti  = $wpdb->prefix . 'bs_clienti';

    $id_noleggio = sanitize_text_field( $request->get_param('noleggio_id') );
    if ( empty( $id_noleggio ) ) {
        return new WP_Error('bsn_noleggio_id_mancante', 'ID noleggio mancante', ['status' => 400]);
    }

    $noleggio_esistente = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_noleggi WHERE id = %s LIMIT 1", $id_noleggio ),
        ARRAY_A
    );
    if ( ! $noleggio_esistente ) {
        return new WP_Error('bsn_noleggio_non_trovato', 'Noleggio non trovato', ['status' => 404]);
    }

    $cliente_id   = intval( $request->get_param('cliente_id') );
    $data_inizio  = sanitize_text_field( $request->get_param('data_inizio') );
    $data_fine    = sanitize_text_field( $request->get_param('data_fine') );
    $data_ritiro  = sanitize_text_field( $request->get_param('data_ritiro') );
    $data_riconsegna = sanitize_text_field( $request->get_param('data_riconsegna') );
    $stato        = sanitize_text_field( $request->get_param('stato') );
    $note         = sanitize_textarea_field( $request->get_param('note') );
    $metodo_pagamento = sanitize_text_field( $request->get_param('metodo_pagamento') );
        // Nuovi campi logistica/trasporto
    $luogo_destinazione = sanitize_text_field( $request->get_param('luogo_destinazione') );
    $trasporto_mezzo    = sanitize_text_field( $request->get_param('trasporto_mezzo') );
    $cauzione           = sanitize_text_field( $request->get_param('cauzione') );
    $causale_trasporto  = sanitize_text_field( $request->get_param('causale_trasporto') );

    // Incaricato al ritiro (opzionale)
    $incaricato_id_raw_upd = $request->get_param('incaricato_ritiro_cliente_id');
    $incaricato_ritiro_cliente_id = ( $incaricato_id_raw_upd !== null && $incaricato_id_raw_upd !== '' )
        ? absint( $incaricato_id_raw_upd )
        : null;
    if ( $incaricato_ritiro_cliente_id !== null && $incaricato_ritiro_cliente_id > 0 ) {
        $inc_exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM $table_clienti WHERE id = %d LIMIT 1", $incaricato_ritiro_cliente_id )
        );
        if ( ! $inc_exists ) {
            return new WP_Error( 'bsn_incaricato_non_trovato', 'Incaricato al ritiro non trovato in anagrafica.', [ 'status' => 400 ] );
        }
    }

    $articoli_raw = $request->get_param('articoli' );

    if ( ! $cliente_id || empty( $data_inizio ) || empty( $data_fine ) ) {
        return new WP_Error('bsn_noleggio_dati_mancanti', 'Cliente, data inizio e data fine sono obbligatori', ['status' => 400]);
    }

    $data_ritiro_effettiva = $data_ritiro ?: $data_inizio;
    $data_riconsegna_effettiva = $data_riconsegna ?: $data_fine;

    if ( bsn_is_invalid_date_range( $data_inizio, $data_fine ) ) {
        return new WP_Error( 'bsn_periodo_utilizzo_non_valido', 'La Data inizio noleggio non può essere successiva alla Data termine noleggio.', [ 'status' => 400 ] );
    }

    if ( bsn_is_invalid_date_range( $data_ritiro_effettiva, $data_riconsegna_effettiva ) ) {
        return new WP_Error( 'bsn_periodo_logistico_non_valido', 'La Data ritiro non può essere successiva alla Data riconsegna.', [ 'status' => 400 ] );
    }

    // Decodifica JSON articoli
$articoli_puliti = [];
if ( ! empty( $articoli_raw ) ) {
    $decoded = json_decode( $articoli_raw, true );
    if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
        foreach ( $decoded as $a ) {
            $id  = isset( $a['id'] )  ? intval( $a['id'] )  : 0;
            $qty = isset( $a['qty'] ) ? intval( $a['qty'] ) : 0;
            if ( $id > 0 && $qty > 0 ) {
                $articolo_item = [
                    'id'    => $id,
                    'qty'   => $qty,
                    'prezzo' => isset( $a['prezzo'] ) ? floatval( $a['prezzo'] ) : null,
                ];
                if ( ! empty( $a['service_internal'] ) ) {
                    $articolo_item['service_internal'] = 1;
                }
                foreach ( array( 'service_component_label', 'service_component_code', 'service_mode', 'public_product_title', 'public_product_permalink', 'public_product_image_url', 'public_product_code', 'public_pricing_category', 'public_request_origin', 'calculation_type', 'dimension_summary', 'selected_preset_label', 'dimension_note' ) as $optional_key ) {
                    if ( isset( $a[ $optional_key ] ) && $a[ $optional_key ] !== '' && $a[ $optional_key ] !== null ) {
                        $articolo_item[ $optional_key ] = sanitize_text_field( (string) $a[ $optional_key ] );
                    }
                }
                foreach ( array( 'public_product_id', 'public_min_qty', 'modules_x', 'modules_y', 'modules_total' ) as $optional_int_key ) {
                    if ( isset( $a[ $optional_int_key ] ) && $a[ $optional_int_key ] !== '' && $a[ $optional_int_key ] !== null ) {
                        $articolo_item[ $optional_int_key ] = intval( $a[ $optional_int_key ] );
                    }
                }
                foreach ( array( 'public_unit_price_net', 'public_line_total', 'requested_width_m', 'requested_height_m', 'technical_width_m', 'technical_height_m', 'area_mq', 'qty_discount_applied_pct', 'unit_price_before_qty_discount', 'unit_price_after_qty_discount' ) as $optional_float_key ) {
                    if ( isset( $a[ $optional_float_key ] ) && $a[ $optional_float_key ] !== '' && $a[ $optional_float_key ] !== null ) {
                        $articolo_item[ $optional_float_key ] = round( (float) $a[ $optional_float_key ], 2 );
                    }
                }
                $articoli_puliti[] = $articolo_item;
            }
        }
    }
}

    $kits_payload = bsn_parse_noleggio_kits_payload( $request->get_param( 'kits' ), $articoli_puliti );
    if ( is_wp_error( $kits_payload ) ) {
        return $kits_payload;
    }

        // Recupera categoria cliente + regime fiscale
    $cliente = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT categoria_cliente, regime_percentuale FROM $table_clienti WHERE id = %d",
            $cliente_id
        )
    );
    $categoria_cliente   = $cliente ? $cliente->categoria_cliente : 'standard';
    $regime_percentuale  = $cliente ? (float)$cliente->regime_percentuale : 0.0;

    // Giorni
        // Calcolo giorni (notti) - minimo 1
    $giorni = 1;
    $ts_da  = strtotime($data_inizio);
    $ts_a   = strtotime($data_fine);
    if ($ts_da && $ts_a && $ts_a > $ts_da) {
        // differenza in giornate intere, senza +1
        $giorni = max(1, (int)ceil(($ts_a - $ts_da) / 86400));
    }

    $ids_articoli = array_values(
        array_unique(
            array_filter(
                array_map(
                    function( $articolo ) {
                        return (int) ( $articolo['id'] ?? 0 );
                    },
                    $articoli_puliti
                )
            )
        )
    );
    $mappa_articoli = array();
    if ( ! empty( $ids_articoli ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );
        $rows_articoli = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, codice, nome FROM $table_articoli WHERE id IN ($placeholders)", $ids_articoli ),
            ARRAY_A
        );
        foreach ( (array) $rows_articoli as $row_articolo ) {
            $mappa_articoli[ (int) $row_articolo['id'] ] = $row_articolo;
        }
    }

    foreach ( $articoli_puliti as &$articolo_pulito ) {
        $articolo_id = (int) ( $articolo_pulito['id'] ?? 0 );
        if ( $articolo_id < 1 || empty( $mappa_articoli[ $articolo_id ] ) ) {
            continue;
        }

        $articolo_db = $mappa_articoli[ $articolo_id ];
        $service_code = trim( (string) ( $articolo_pulito['service_component_code'] ?? ( $articolo_db['codice'] ?? '' ) ) );
        if ( bsn_is_internal_service_article_code( $service_code ) ) {
            $articolo_pulito['service_internal'] = 1;
            $articolo_pulito['service_component_code'] = $service_code;
            $articolo_pulito['service_component_label'] = bsn_get_internal_service_article_display_label(
                $service_code,
                (string) ( $articolo_pulito['service_component_label'] ?? $articolo_db['nome'] ?? '' )
            );
        }
    }
    unset( $articolo_pulito );

    $articoli_json = ! empty( $articoli_puliti ) ? wp_json_encode( $articoli_puliti ) : null;

        // Totale calcolato con la stessa logica della POST
    $totale_calcolato = bsn_calcola_totale_noleggio(
        $articoli_puliti,
        $giorni,
        $categoria_cliente,
        $regime_percentuale,
        $wpdb,
        $table_articoli
    );
    
    // ===== SCONTO GLOBALE =====
$sconto_globale = (float)($request->get_param('sconto_globale') ?? 0);
$totale_calcolato = $totale_calcolato + $sconto_globale;


    // ----- CONTROLLO SOVRAPPOSIZIONI (UPDATE) -----
    $sovrapposizioni = [];
    $stato_effettivo = $stato ?: 'bozza';
    if ( $stato_effettivo !== 'preventivo' ) {
        $articoli_disponibilita = bsn_merge_articoli_con_kit_componenti( $articoli_puliti, $kits_payload );
        $sovrapposizioni = bsn_calcola_sovrapposizioni_noleggio(
            $articoli_disponibilita,
            $data_ritiro ?: $data_inizio,
            $data_riconsegna ?: $data_fine,
            $id_noleggio,      // in update escludiamo il noleggio stesso
            $wpdb,
            $table_articoli,
            $table_noleggi
        );

        $consenti_overbook = intval( $request->get_param( 'consenti_overbook' ) );
        if ( ! empty( $sovrapposizioni ) && ! $consenti_overbook ) {
            return new WP_Error(
                'bsn_sovrapposizioni_blocco',
                "Sovrapposizioni rilevate:\n- " . implode( "\n- ", $sovrapposizioni ),
                [ 'status' => 400, 'sovrapposizioni' => $sovrapposizioni ]
            );
        }
    }

        // ----- UPDATE NOLEGGIO -----
        $data_update = [
            'cliente_id'         => $cliente_id,
            'data_inizio'        => bsn_normalize_date_input( $data_inizio, false ),
            'data_fine'          => bsn_normalize_date_input( $data_fine, true ),
            'data_ritiro'        => bsn_normalize_date_input( $data_ritiro, false ),
            'data_riconsegna'    => bsn_normalize_date_input( $data_riconsegna, true ),
            'stato'              => $stato_effettivo,
            'note'               => $note,
            'totale_calcolato'   => $totale_calcolato,
            'sconto_globale'     => $sconto_globale, // << AGGIUNTO: allinea alla POST
            'metodo_pagamento'   => $metodo_pagamento,
            // nuovi campi logistica/trasporto
            'luogo_destinazione'           => $luogo_destinazione,
            'trasporto_mezzo'              => $trasporto_mezzo,
            'cauzione'                     => $cauzione,
            'causale_trasporto'            => $causale_trasporto,
            'incaricato_ritiro_cliente_id' => ( $incaricato_ritiro_cliente_id > 0 ) ? $incaricato_ritiro_cliente_id : null,
        ];

        if ( $articoli_json !== null ) {
            $data_update['articoli'] = $articoli_json;
        } else {
            $data_update['articoli'] = null;
        }

        if (
            bsn_is_frontend_quote_noleggio( $noleggio_esistente )
            || is_array( bsn_get_noleggio_snapshot_json( $noleggio_esistente, 'snapshot_prezzi_json' ) )
        ) {
            $data_update['snapshot_prezzi_json'] = wp_json_encode(
                bsn_get_frontend_quote_snapshot_after_admin_update( $noleggio_esistente, $totale_calcolato, $sconto_globale )
            );
        }


    $updated = $wpdb->update(
        $table_noleggi,
        $data_update,
        [ 'id' => $id_noleggio ],
        null,
        [ '%s' ]
    );

    if ( $updated === false ) {
        return new WP_Error('bsn_update_noleggio_error', 'Errore nell\'aggiornare il noleggio', ['status' => 500]);
    }

    bsn_invalidate_preventivo_pdf_cache( $id_noleggio );

    bsn_save_noleggio_kits( $id_noleggio, $kits_payload );

    $stato_precedente = sanitize_key( (string) ( $noleggio_esistente['stato'] ?? '' ) );
    if ( $stato_precedente === 'preventivo' && $stato_effettivo === 'bozza' ) {
        $cliente_notifica = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table_clienti WHERE id = %d LIMIT 1", (int) $cliente_id ),
            ARRAY_A
        );
        $noleggio_notifica = array_merge( $noleggio_esistente, $data_update, array( 'id' => $id_noleggio, 'cliente_id' => $cliente_id ) );
        $pdf_info = bsn_generate_preventivo_pdf( $id_noleggio, true );
        if ( is_wp_error( $pdf_info ) ) {
            error_log( 'BSN EMAIL: PDF conferma richiesta non disponibile per ' . $id_noleggio . ' - ' . $pdf_info->get_error_message() );
            $pdf_info = false;
        }
        bsn_invia_email_conferma_preventivo_cliente( $noleggio_notifica, $cliente_notifica, $pdf_info );
    }

    return rest_ensure_response([
        'success'         => true,
        'id'              => $id_noleggio,
        'totale'          => $totale_calcolato,
        'giorni'          => $giorni,
        'sovrapposizioni' => $sovrapposizioni,
    ]);
}

/**
 * Endpoint dettaglio noleggio
 * GET /wp-json/bsn/v1/noleggi/dettaglio?id=2026/001
 */
add_action('rest_api_init', function () {
    register_rest_route('bsn/v1', '/noleggi/dettaglio', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_noleggi_dettaglio',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [
                'required' => true,
                'type'     => 'string',
            ],
        ],
    ]);
});

/**
 * Restituisce tutti i dati di un singolo noleggio:
 * - cliente_id, cliente_nome
 * - date grezze (YYYY-MM-DD), stato, note
 * - articoli: [{id, qty, nome, codice, ubicazione, correlati}]
 */
function bsn_api_noleggi_dettaglio( WP_REST_Request $request ) {
    global $wpdb;
    $table_noleggi  = $wpdb->prefix . 'bs_noleggi';
    $table_clienti  = $wpdb->prefix . 'bs_clienti';
    $table_articoli = $wpdb->prefix . 'bs_articoli';

    $id = sanitize_text_field( $request->get_param('id') );
    if ( empty( $id ) ) {
        return new WP_Error(
            'bsn_noleggio_id_mancante',
            'ID noleggio mancante',
            [ 'status' => 400 ]
        );
    }

    // Recupera il noleggio specifico
    $sql = $wpdb->prepare(
        "SELECT n.*, c.nome AS cliente_nome
         FROM $table_noleggi n
         LEFT JOIN $table_clienti c ON n.cliente_id = c.id
         WHERE n.id = %s
         LIMIT 1",
        $id
    );
    $n = $wpdb->get_row( $sql );

    if ( ! $n ) {
        return new WP_Error(
            'bsn_noleggio_non_trovato',
            'Noleggio non trovato',
            [ 'status' => 404 ]
        );
    }

    $periodo_utilizzo = bsn_get_periodo_utilizzo_noleggio( $n );
    $periodo_logistico = bsn_get_periodo_logistico_noleggio( $n );

    // Decodifica articoli JSON
    $articoli = [];
    if ( ! empty( $n->articoli ) ) {
        $decoded = json_decode( $n->articoli, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            $articoli = $decoded;
        }
    }

    // Se ci sono articoli, recupera i dati base (nome, codice, ubicazione, correlati)
    $ids_articoli = [];
    foreach ( $articoli as $a ) {
        if ( ! empty( $a['id'] ) ) {
            $ids_articoli[] = intval( $a['id'] );
        }
    }
    $ids_articoli = array_values( array_unique( array_filter( $ids_articoli ) ) );

    $mappa_articoli = [];
    if ( ! empty( $ids_articoli ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $ids_articoli ), '%d' ) );
        $sql_art = "SELECT id, codice, nome, ubicazione, correlati
                    FROM $table_articoli
                    WHERE id IN ($placeholders)";
        $rows_art = $wpdb->get_results( $wpdb->prepare( $sql_art, $ids_articoli ) );
        foreach ( $rows_art as $r ) {
            $mappa_articoli[ intval( $r->id ) ] = [
                'codice'     => $r->codice,
                'nome'       => $r->nome,
                'ubicazione' => $r->ubicazione,
                'correlati'  => $r->correlati,
            ];
        }
    }

    $articoli_out = [];
    foreach ( $articoli as $a ) {
        $id_art = isset( $a['id'] )  ? intval( $a['id'] )  : 0;
        $qty    = isset( $a['qty'] ) ? intval( $a['qty'] ) : 0;
        $service_component_code = (string) ( $a['service_component_code'] ?? '' );
        $is_service_internal = ! empty( $a['service_internal'] );

        if ( $id_art <= 0 || $qty <= 0 ) {
            continue;
        }

        $nome       = $is_service_internal
            ? (string) ( $a['service_component_label'] ?? '' )
            : (string) ( $a['public_product_title'] ?? '' );
        $codice     = $is_service_internal
            ? (string) ( $a['service_component_code'] ?? '' )
            : (string) ( $a['public_product_code'] ?? '' );
        $ubicazione = '';
        $correlati  = [];
        $prezzo     = isset( $a['prezzo'] ) ? $a['prezzo'] : null;

        if ( isset( $mappa_articoli[ $id_art ] ) ) {
            if ( $service_component_code === '' ) {
                $service_component_code = (string) ( $mappa_articoli[ $id_art ]['codice'] ?? '' );
            }
            if ( ! $is_service_internal && bsn_is_internal_service_article_code( $service_component_code ) ) {
                $is_service_internal = true;
            }

            if ( $nome === '' ) {
                $nome = $is_service_internal
                    ? bsn_get_internal_service_article_display_label( $service_component_code, $mappa_articoli[ $id_art ]['nome'] )
                    : $mappa_articoli[ $id_art ]['nome'];
            }
            if ( $codice === '' ) {
                $codice = $is_service_internal
                    ? $service_component_code
                    : $mappa_articoli[ $id_art ]['codice'];
            }
            $ubicazione = $mappa_articoli[ $id_art ]['ubicazione'];

            // Prova a decodificare correlati come JSON [{nome, qty}, ...]
            $correlati_raw = $mappa_articoli[ $id_art ]['correlati'];
            if ( ! empty( $correlati_raw ) ) {
                $decoded_corr = json_decode( $correlati_raw, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_corr ) ) {
                    $correlati = $decoded_corr;
                }
            }
        }

        $articoli_out[] = [
            'id'         => $id_art,
            'qty'        => $qty,
            'prezzo'     => $prezzo,
            'nome'       => $nome,
            'codice'     => $codice,
            'ubicazione' => $ubicazione,
            'correlati'  => $correlati,
            'public_product_id' => isset( $a['public_product_id'] ) ? intval( $a['public_product_id'] ) : 0,
            'public_product_title' => (string) ( $a['public_product_title'] ?? '' ),
            'service_internal' => $is_service_internal ? 1 : 0,
            'service_component_label' => $is_service_internal
                ? bsn_get_internal_service_article_display_label( $service_component_code, (string) ( $a['service_component_label'] ?? '' ) )
                : (string) ( $a['service_component_label'] ?? '' ),
            'service_component_code' => $service_component_code,
            'service_mode' => (string) ( $a['service_mode'] ?? '' ),
            'public_product_permalink' => (string) ( $a['public_product_permalink'] ?? '' ),
            'public_product_image_url' => (string) ( $a['public_product_image_url'] ?? '' ),
            'public_product_code' => (string) ( $a['public_product_code'] ?? '' ),
            'public_pricing_category' => (string) ( $a['public_pricing_category'] ?? '' ),
            'public_request_origin' => (string) ( $a['public_request_origin'] ?? '' ),
            'calculation_type' => (string) ( $a['calculation_type'] ?? '' ),
            'dimension_summary' => (string) ( $a['dimension_summary'] ?? '' ),
            'selected_preset_label' => (string) ( $a['selected_preset_label'] ?? '' ),
            'dimension_note' => (string) ( $a['dimension_note'] ?? '' ),
            'public_min_qty' => isset( $a['public_min_qty'] ) ? intval( $a['public_min_qty'] ) : 0,
            'modules_x' => isset( $a['modules_x'] ) ? intval( $a['modules_x'] ) : 0,
            'modules_y' => isset( $a['modules_y'] ) ? intval( $a['modules_y'] ) : 0,
            'modules_total' => isset( $a['modules_total'] ) ? intval( $a['modules_total'] ) : 0,
            'public_unit_price_net' => isset( $a['public_unit_price_net'] ) ? (float) $a['public_unit_price_net'] : null,
            'public_line_total' => isset( $a['public_line_total'] ) ? (float) $a['public_line_total'] : null,
            'requested_width_m' => isset( $a['requested_width_m'] ) ? (float) $a['requested_width_m'] : null,
            'requested_height_m' => isset( $a['requested_height_m'] ) ? (float) $a['requested_height_m'] : null,
            'technical_width_m' => isset( $a['technical_width_m'] ) ? (float) $a['technical_width_m'] : null,
            'technical_height_m' => isset( $a['technical_height_m'] ) ? (float) $a['technical_height_m'] : null,
            'area_mq' => isset( $a['area_mq'] ) ? (float) $a['area_mq'] : null,
            'qty_discount_applied_pct' => isset( $a['qty_discount_applied_pct'] ) ? (float) $a['qty_discount_applied_pct'] : null,
            'unit_price_before_qty_discount' => isset( $a['unit_price_before_qty_discount'] ) ? (float) $a['unit_price_before_qty_discount'] : null,
            'unit_price_after_qty_discount' => isset( $a['unit_price_after_qty_discount'] ) ? (float) $a['unit_price_after_qty_discount'] : null,
        ];
    }

    $kits_out = bsn_get_noleggio_kits( $n->id );
    $note_sections = bsn_parse_noleggio_note_sections( $n->note );
    $service_snapshot = bsn_get_noleggio_service_snapshot( $n );
    $service_lines = array_values(
        array_filter(
            $articoli_out,
            function ( $articolo ) {
                return ! empty( $articolo['service_internal'] );
            }
        )
    );
    $articoli_materiali = array_values(
        array_filter(
            $articoli_out,
            function ( $articolo ) {
                return empty( $articolo['service_internal'] );
            }
        )
    );

    $response = [
        'id'             => $n->id,
        'cliente_id'     => intval( $n->cliente_id ),
        'cliente_nome'   => $n->cliente_nome,
        'data_inizio'    => $periodo_utilizzo['inizio'],
        'data_fine'      => $periodo_utilizzo['fine'],
        'data_ritiro'    => $periodo_logistico['ritiro'],
        'data_riconsegna' => $periodo_logistico['riconsegna'],
        'data_ritiro_effettiva' => $periodo_logistico['ritiro_effettivo'],
        'data_riconsegna_effettiva' => $periodo_logistico['riconsegna_effettiva'],
        'stato'          => $n->stato,
        'stato_public_label' => bsn_get_public_noleggio_status_label( $n->stato ),
        'note'           => $n->note,
        'note_operatore' => $note_sections['system_note'],
        'note_cliente'   => $note_sections['customer_note'],
        'luogo_destinazione' => $n->luogo_destinazione,
        'trasporto_mezzo'    => $n->trasporto_mezzo,
        'cauzione'           => $n->cauzione,
        'metodo_pagamento'   => $n->metodo_pagamento,
        'causale_trasporto'  => $n->causale_trasporto,
        'sconto_globale' => isset( $n->sconto_globale ) ? (float) $n->sconto_globale : 0.0,
        'origine' => isset( $n->origine ) ? $n->origine : 'manuale',
        // Incaricato al ritiro
        'incaricato_ritiro_cliente_id' => isset( $n->incaricato_ritiro_cliente_id ) ? absint( $n->incaricato_ritiro_cliente_id ) : null,
        'incaricato_ritiro'            => bsn_get_incaricato_ritiro_data( (array) $n ),
        'articoli'       => $articoli_out,
        'articoli_materiali' => $articoli_materiali,
        'service_lines'  => $service_lines,
        'service'        => $service_snapshot,
        'service_summary_html' => bsn_render_noleggio_service_summary_html(
            $service_snapshot,
            array(
                'context' => 'admin',
                'title' => 'Servizio selezionato',
            )
        ),
        'kits'           => $kits_out,
    ];

    return rest_ensure_response( $response );
}

/**
 * Duplica un noleggio esistente creando un nuovo record con nuovo ID.
 * Copia: cliente_id, date, stato (forzato a "bozza"), note, articoli, totale_calcolato.
 */
function bsn_api_noleggi_duplica( WP_REST_Request $request ) {
    global $wpdb;

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    bsn_ensure_noleggi_columns();

    $id_originale = sanitize_text_field( $request->get_param( 'id' ) );
    if ( empty( $id_originale ) ) {
        return new WP_Error( 'bsn_noleggio_id_mancante', 'ID noleggio mancante', [ 'status' => 400 ] );
    }

    // Recupera il noleggio originale
    $noleggio = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_noleggi WHERE id = %s LIMIT 1", $id_originale ),
        ARRAY_A
    );

    if ( ! $noleggio ) {
        return new WP_Error( 'bsn_noleggio_non_trovato', 'Noleggio originale non trovato.', [ 'status' => 404 ] );
    }

    // Genera un nuovo ID come in bsn_api_noleggi_post
    $anno = date( 'Y' );
    $ultimo = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table_noleggi WHERE id LIKE %s ORDER BY id DESC LIMIT 1",
            $anno . '/%'
        )
    );

    $progressivo = 1;
    if ( $ultimo ) {
        $pezzi = explode( '/', $ultimo );
        if ( ! empty( $pezzi[1] ) ) {
            $progressivo = intval( $pezzi[1], 10 ) + 1;
        }
    }

    $id_nuovo = $anno . '/' . str_pad( $progressivo, 3, '0', STR_PAD_LEFT );

    // Prepara dati per inserimento
    $current_user = wp_get_current_user();

    $data_insert = [
        'id'                 => $id_nuovo,
        'cliente_id'         => isset( $noleggio['cliente_id'] ) ? intval( $noleggio['cliente_id'] ) : 0,
        'data_richiesta'     => current_time( 'mysql' ),
        'data_inizio'        => isset( $noleggio['data_inizio'] ) ? $noleggio['data_inizio'] : null,
        'data_fine'          => isset( $noleggio['data_fine'] ) ? $noleggio['data_fine'] : null,
        'data_ritiro'        => isset( $noleggio['data_ritiro'] ) ? $noleggio['data_ritiro'] : null,
        'data_riconsegna'    => isset( $noleggio['data_riconsegna'] ) ? $noleggio['data_riconsegna'] : null,
        'stato'              => 'bozza', // il duplicato parte sempre come bozza
        'operatore_richiesta'=> $current_user ? $current_user->user_login : '',
        'operatore_verifica' => null,
        'articoli'           => isset( $noleggio['articoli'] ) ? $noleggio['articoli'] : null,
        'totale_calcolato'   => isset( $noleggio['totale_calcolato'] ) ? $noleggio['totale_calcolato'] : null,
        'note'               => isset( $noleggio['note'] ) ? $noleggio['note'] : '',
        'luogo_destinazione' => isset( $noleggio['luogo_destinazione'] ) ? $noleggio['luogo_destinazione'] : '',
        'trasporto_mezzo'    => isset( $noleggio['trasporto_mezzo'] ) ? $noleggio['trasporto_mezzo'] : '',
        'cauzione'           => isset( $noleggio['cauzione'] ) ? $noleggio['cauzione'] : '',
        'causale_trasporto'  => isset( $noleggio['causale_trasporto'] ) ? $noleggio['causale_trasporto'] : '',
        'consenso_privacy'   => 0,
        'consenso_condizioni' => 0,
        'firma_url'          => null,
        'firma_base64'       => '',
        'firma_data'         => null,
        'op_preparazione_documento' => '',
        'op_preparazione_materiale' => '',
        'op_consegna_materiale'     => '',
        'op_rientro_materiale'      => '',
    ];

    $formati = [
        '%s', // id
        '%d', // cliente_id
        '%s', // data_richiesta
        '%s', // data_inizio
        '%s', // data_fine
        '%s', // data_ritiro
        '%s', // data_riconsegna
        '%s', // stato
        '%s', // operatore_richiesta
        '%s', // operatore_verifica
        '%s', // articoli
        '%f', // totale_calcolato
        '%s', // note
        '%s', // luogo_destinazione
        '%s', // trasporto_mezzo
        '%s', // cauzione
        '%s', // causale_trasporto
        '%d', // consenso_privacy
        '%d', // consenso_condizioni
        '%s', // firma_url
        '%s', // firma_base64
        '%s', // firma_data
        '%s', // op_preparazione_documento
        '%s', // op_preparazione_materiale
        '%s', // op_consegna_materiale
        '%s', // op_rientro_materiale
    ];

    $result = $wpdb->insert( $table_noleggi, $data_insert, $formati );

    if ( ! $result ) {
        return new WP_Error( 'bsn_duplica_noleggio_error', 'Errore nel duplicare il noleggio.', [ 'status' => 500 ] );
    }

    $kits_originali = bsn_get_noleggio_kits( $id_originale );
    if ( ! empty( $kits_originali ) ) {
        $kits_payload = [];
        foreach ( $kits_originali as $kit ) {
            $components_payload = [];
            foreach ( $kit['components'] as $comp ) {
                $components_payload[] = [
                    'ruolo'             => $comp['ruolo'],
                    'gruppo_equivalenza'=> $comp['gruppo_equivalenza'],
                    'articolo_id'       => $comp['articolo_id'],
                    'qty'               => $comp['qty'],
                ];
            }
            $kits_payload[] = [
                'kit_id'          => $kit['kit_id'],
                'kit_label'       => $kit['kit_label'],
                'components'      => $components_payload,
                'articolo_kit_id' => $kit['articolo_kit_id'],
            ];
        }
        bsn_save_noleggio_kits( $id_nuovo, $kits_payload );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $id_nuovo,
    ] );
}
/**
 * Endpoint finalizza noleggio
 * POST /wp-json/bsn/v1/noleggi/finalizza
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'bsn/v1', '/noleggi/finalizza', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_noleggi_finalizza',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id_noleggio'         => [ 'required' => true,  'type' => 'string'  ],
            'firma_base64'        => [ 'required' => true,  'type' => 'string'  ],
            'firma_data'          => [ 'required' => true,  'type' => 'string'  ],
            'consenso_privacy'    => [ 'required' => true,  'type' => 'integer' ],
            'consenso_condizioni' => [ 'required' => true,  'type' => 'integer' ],
            'op_documento'        => [ 'required' => true,  'type' => 'string'  ],
            'op_materiale'        => [ 'required' => true,  'type' => 'string'  ],
            'op_consegna'         => [ 'required' => false, 'type' => 'string'  ],
            'op_rientro'          => [ 'required' => false, 'type' => 'string'  ],
        ],
    ] );
} );

// === Ticket API ===
add_action( 'rest_api_init', function () {
    register_rest_route( 'bsn/v1', '/noleggi/duplica', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_noleggi_duplica',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [ 'required' => true, 'type' => 'string' ],
        ],
    ] );

    register_rest_route( 'bsn/v1', '/tickets', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_tickets_get',
        'permission_callback' => 'bsn_check_admin',
    ] );

    register_rest_route( 'bsn/v1', '/tickets', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_tickets_post',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'noleggio_id' => [ 'required' => false, 'type' => 'string' ],
            'articolo_id' => [ 'required' => true,  'type' => 'integer' ],
            'qty'         => [ 'required' => true,  'type' => 'integer' ],
            'tipo'        => [ 'required' => true,  'type' => 'string'  ],
            'note'        => [ 'required' => false, 'type' => 'string'  ],
            'foto'        => [ 'required' => false, 'type' => 'string'  ],
        ],
    ] );

    register_rest_route( 'bsn/v1', '/tickets/chiudi', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_tickets_chiudi',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'ticket_id' => [ 'required' => true, 'type' => 'integer' ],
        ],
    ] );

    register_rest_route( 'bsn/v1', '/tickets/update', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_tickets_update',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'ticket_id'  => [ 'required' => true,  'type' => 'integer' ],
            'articolo_id'=> [ 'required' => true,  'type' => 'integer' ],
            'qty'        => [ 'required' => true,  'type' => 'integer' ],
            'tipo'       => [ 'required' => true,  'type' => 'string'  ],
            'note'       => [ 'required' => false, 'type' => 'string'  ],
            'stato'      => [ 'required' => false, 'type' => 'string'  ],
            'foto'       => [ 'required' => false, 'type' => 'string'  ],
        ],
    ] );

    register_rest_route( 'bsn/v1', '/noleggi/alert-stato', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_noleggi_alert_stato',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'data_inizio' => [ 'required' => true, 'type' => 'string' ],
            'data_fine'   => [ 'required' => true, 'type' => 'string' ],
            'items'       => [ 'required' => true, 'type' => 'array' ],
            'exclude_noleggio_id' => [ 'required' => false, 'type' => 'string' ],
        ],
    ] );

    register_rest_route( 'bsn/v1', '/noleggi/pdf', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_noleggi_pdf_get',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [ 'required' => true, 'type' => 'string' ],
        ],
    ] );

    register_rest_route( 'bsn/v1', '/noleggi/preventivo/pdf', [
        'methods'             => 'GET',
        'callback'            => 'bsn_api_noleggi_preventivo_pdf_get',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [ 'required' => true, 'type' => 'string' ],
        ],
    ] );

    register_rest_route( 'bsn/v1', '/noleggi/preventivo/invia', [
        'methods'             => 'POST',
        'callback'            => 'bsn_api_noleggi_preventivo_invia',
        'permission_callback' => 'bsn_check_admin',
        'args'                => [
            'id' => [ 'required' => true, 'type' => 'string' ],
        ],
    ] );
} );

// === STEP A4: Redirect dopo login per operatori ed editor verso /app-noleggi/ ===
function bsn_login_redirect( $redirect_to, $request, $user ) {
    if ( ! $user || is_wp_error( $user ) ) {
        return $redirect_to;
    }

    // Admin resta nel backend classico
    if ( user_can( $user, 'manage_options' ) ) {
        return $redirect_to;
    }

    // Utenti autorizzati all'app (editor + bsn_operatore) → /app-noleggi/
    if ( user_can( $user, 'bsn_manage_noleggi' ) || user_can( $user, 'edit_others_posts' ) ) {
        return home_url( '/app-noleggi/' );
    }

    // Altri ruoli → comportamento standard
    return $redirect_to;
}
add_filter( 'login_redirect', 'bsn_login_redirect', 10, 3 );

// === STEP A5: Blocca l'accesso a /wp-admin/ per bsn_operatore ===
function bsn_restrict_admin_for_operator() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        $user = wp_get_current_user();

        // Se è operatore noleggi ma NON è admin/editor, blocchiamo wp-admin
        if ( in_array( 'bsn_operatore', (array) $user->roles, true )
             && ! current_user_can( 'manage_options' )
             && ! current_user_can( 'edit_others_posts' ) ) {

            wp_redirect( home_url( '/app-noleggi/' ) );
            exit;
        }
    }
}
add_action( 'admin_init', 'bsn_restrict_admin_for_operator' );

// === STEP A6: Nascondi admin bar per ruoli che non ne hanno bisogno ===
function bsn_hide_admin_bar_for_operator() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    $user = wp_get_current_user();

    // bsn_operatore: non ha accesso al backend, nascondi admin bar ovunque
    if ( in_array( 'bsn_operatore', (array) $user->roles, true ) ) {
        show_admin_bar( false );
        return;
    }

    // subscriber (clienti frontend): nascondi admin bar solo sul frontend
    if ( ! is_admin() && in_array( 'subscriber', (array) $user->roles, true ) ) {
        show_admin_bar( false );
    }
}
add_action( 'after_setup_theme', 'bsn_hide_admin_bar_for_operator' );

/**
 * Finalizza un noleggio:
 * - verifica stato = 'bozza'
 * - salva firma PNG in uploads/bsn-firme/firma-noleggio-ID.png
 * - salva consensi e operatori
 * - imposta stato = 'attivo'
 */
function bsn_api_noleggi_finalizza( WP_REST_Request $request ) {
    global $wpdb;

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    bsn_ensure_noleggi_columns();

    $id_noleggio         = sanitize_text_field( $request->get_param( 'id_noleggio' ) );
    $firma_base64        = $request->get_param( 'firma_base64' );
    $firma_data_raw      = sanitize_text_field( $request->get_param( 'firma_data' ) );
    $consenso_privacy    = intval( $request->get_param( 'consenso_privacy' ) );
    $consenso_condizioni = intval( $request->get_param( 'consenso_condizioni' ) );
    $op_documento        = sanitize_text_field( $request->get_param( 'op_documento' ) );
    $op_materiale        = sanitize_text_field( $request->get_param( 'op_materiale' ) );
    $op_consegna         = sanitize_text_field( $request->get_param( 'op_consegna' ) );
    $op_rientro          = sanitize_text_field( $request->get_param( 'op_rientro' ) );

    if ( empty( $id_noleggio ) ) {
        return new WP_Error(
            'bsn_finalizza_id_mancante',
            'ID noleggio mancante.',
            [ 'status' => 400 ]
        );
    }

    // Recupera noleggio
    $noleggio = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_noleggi WHERE id = %s LIMIT 1",
            $id_noleggio
        ),
        ARRAY_A
    );

    if ( ! $noleggio ) {
        return new WP_Error(
            'bsn_finalizza_noleggio_non_trovato',
            'Noleggio non trovato.',
            [ 'status' => 404 ]
        );
    }

    if ( $noleggio['stato'] !== 'bozza' ) {
        return new WP_Error(
            'bsn_finalizza_stato_non_valido',
            'Solo i noleggi in stato "bozza" possono essere finalizzati.',
            [ 'status' => 400 ]
        );
    }

    if ( ! $consenso_privacy || ! $consenso_condizioni ) {
        return new WP_Error(
            'bsn_finalizza_consenso_mancante',
            'Per finalizzare il noleggio servono entrambi i consensi.',
            [ 'status' => 400 ]
        );
    }

    if ( empty( $op_documento ) || empty( $op_materiale ) ) {
        return new WP_Error(
            'bsn_finalizza_operatori_mancanti',
            'Inserisci gli operatori di preparazione documento e materiale.',
            [ 'status' => 400 ]
        );
    }
    
    // --- Salvataggio firma PNG in uploads/bsn-firme/ ---
    if ( empty( $firma_base64 ) || strpos( $firma_base64, 'data:image/png;base64,' ) !== 0 ) {
        return new WP_Error(
            'bsn_finalizza_firma_non_valida',
            'Firma non valida.',
            [ 'status' => 400 ]
        );
    }

    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['error'] ) ) {
        return new WP_Error(
            'bsn_finalizza_upload_dir_error',
            'Impossibile accedere alla cartella upload.',
            [ 'status' => 500 ]
        );
    }

    $firma_data_clean = str_replace( 'data:image/png;base64,', '', $firma_base64 );
    $firma_data_clean = str_replace( ' ', '+', $firma_data_clean );
    $firma_bin        = base64_decode( $firma_data_clean );

    if ( ! $firma_bin ) {
        return new WP_Error(
            'bsn_finalizza_decode_error',
            'Errore nella decodifica della firma.',
            [ 'status' => 400 ]
        );
    }

    // Usiamo wp_upload_bits per rispettare le policy dell'hosting
    $firma_filename = 'firma-noleggio-' . preg_replace( '/[^A-Za-z0-9_\-]/', '_', $id_noleggio ) . '.png';

    $upload_bits = wp_upload_bits(
        'bsn-firme/' . $firma_filename,
        null,
        $firma_bin
    );

    if ( ! empty( $upload_bits['error'] ) ) {
        return new WP_Error(
            'bsn_finalizza_scrittura_firma',
            'Errore nel salvataggio del file firma.',
            [ 'status' => 500, 'detail' => $upload_bits['error'] ]
        );
    }

    $firma_url = $upload_bits['url'];

    // --- Conversione data firma in Y-m-d H:i:s ---
    if ( ! empty( $firma_data_raw ) ) {
        $firma_data_parse = str_replace( '/', '-', $firma_data_raw );
        $dt = DateTime::createFromFormat( 'd-m-Y H:i', $firma_data_parse );
        if ( $dt ) {
            $firma_data_mysql = $dt->format( 'Y-m-d H:i:s' );
        } else {
            $firma_data_mysql = current_time( 'mysql' );
        }
    } else {
        $firma_data_mysql = current_time( 'mysql' );
    }

    // --- Aggiorna record noleggio ---
    $data_update = [
        'stato'                     => 'attivo',
        'consenso_privacy'          => $consenso_privacy ? 1 : 0,
        'consenso_condizioni'       => $consenso_condizioni ? 1 : 0,
        'firma_url'                 => $firma_url,
        'firma_base64'              => $firma_base64,
        'firma_data'                => $firma_data_mysql,
        'op_preparazione_documento' => $op_documento,
        'op_preparazione_materiale' => $op_materiale,
        'op_consegna_materiale'     => $op_consegna,
        'op_rientro_materiale'      => $op_rientro,
    ];

    $updated = $wpdb->update(
        $table_noleggi,
        $data_update,
        [ 'id' => $id_noleggio ],
        null,
        [ '%s' ]
    );

    if ( $updated === false ) {
        return new WP_Error(
            'bsn_finalizza_update_error',
            'Errore nell\'aggiornare il noleggio.',
            [ 'status' => 500 ]
        );
    }

    $pdf_info = bsn_generate_noleggio_pdf( $id_noleggio, true );
    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $cliente = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_clienti WHERE id = %d",
            $noleggio['cliente_id']
        ),
        ARRAY_A
    );

    if ( $cliente ) {
        bsn_invia_email_noleggio_finalizzato( $noleggio, $cliente, $pdf_info );
    } else {
        bsn_invia_email_noleggio_finalizzato( $noleggio, null, $pdf_info );
    }

    return rest_ensure_response( [
        'success'   => true,
        'id'        => $id_noleggio,
        'stato'     => 'attivo',
        'firma_url' => $firma_url,
    ] );
}

// [BSN_PDF_EMAIL_START]
/**
 * Genera l'HTML del documento di finalizzazione per PDF.
 */
function bsn_get_finalizza_document_html( $noleggio_id ) {
    ob_start();
    bsn_render_finalizza_noleggio_page(
        [
            'render_document_only' => true,
            'noleggio_id' => $noleggio_id,
        ]
    );
    return ob_get_clean();
}

/**
 * Restituisce path e url della cartella PDF.
 */
function bsn_get_pdf_directory() {
    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['error'] ) ) {
        return false;
    }

    $dir = trailingslashit( $upload_dir['basedir'] ) . 'bsn-pdf';
    $url = trailingslashit( $upload_dir['baseurl'] ) . 'bsn-pdf';

    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    return [
        'dir' => $dir,
        'url' => $url,
    ];
}

/**
 * Genera il PDF del noleggio e lo salva su filesystem.
 */
function bsn_generate_noleggio_pdf( $noleggio_id, $force = false ) {
    $folder = bsn_get_pdf_directory();
    if ( ! $folder ) {
        return false;
    }

    $safe_id = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $noleggio_id );
    $filename = 'noleggio-' . $safe_id . '.pdf';
    $path = trailingslashit( $folder['dir'] ) . $filename;
    $url = trailingslashit( $folder['url'] ) . $filename;

    if ( ! $force && file_exists( $path ) ) {
        return [ 'path' => $path, 'url' => $url ];
    }

    $html = bsn_get_finalizza_document_html( $noleggio_id );
    if ( empty( $html ) ) {
        error_log( 'BSN PDF: HTML vuoto per noleggio ' . $noleggio_id );
        return false;
    }

    if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
        $autoload_path = bsn_get_dompdf_autoload_path();
        if ( empty( $autoload_path ) ) {
            $autoload_path = bsn_download_dompdf_library();
        }
        if ( is_wp_error( $autoload_path ) || empty( $autoload_path ) ) {
            return is_wp_error( $autoload_path )
                ? $autoload_path
                : new WP_Error(
                    'bsn_pdf_missing_library',
                    'Libreria PDF mancante. Dompdf non è stato trovato.',
                    [ 'status' => 500 ]
                );
        }
        require_once $autoload_path;
    }

    $options = new Dompdf\Options();
    $options->set( 'isRemoteEnabled', true );
    $options->set( 'isHtml5ParserEnabled', true );
    try {
        $dompdf = new Dompdf\Dompdf( $options );
        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->render();

        $output = $dompdf->output();
        file_put_contents( $path, $output );
    } catch ( Exception $e ) {
        error_log( 'BSN PDF: errore generazione noleggio ' . $noleggio_id . ' - ' . $e->getMessage() );
        return false;
    }

    return [ 'path' => $path, 'url' => $url ];
}

/**
 * Genera l'HTML del preventivo per PDF.
 */
// Genera il blocco HTML "INCARICATO AL RITIRO" per PDF/documenti (solo se diverso dal cliente)
function bsn_render_pdf_incaricato_block( $incaricato ) {
    if ( ! $incaricato ) {
        return '';
    }
    $doc_tipo   = ! empty( $incaricato['tipo_documento'] )   ? esc_html( $incaricato['tipo_documento'] )   : 'N/D';
    $doc_numero = ! empty( $incaricato['numero_documento'] ) ? ' — ' . esc_html( $incaricato['numero_documento'] ) : '';
    $html  = '<div style="border: 1px solid #000; padding: 10px; margin-bottom: 10px;">';
    $html .= '<h3 style="margin: -10px -10px 8px -10px; font-size: 12px; background: #c00; color: #fff; padding: 3px 8px;">INCARICATO AL RITIRO</h3>';
    $html .= '<table style="width: 100%; font-size: 10px; line-height: 1.3;">';
    $html .= '<tr><td style="width:35%; padding:2px 0;"><strong>Nome/Rag. Soc.:</strong></td><td style="padding:2px 0;">' . esc_html( $incaricato['nome'] ) . '</td></tr>';
    $html .= '<tr><td style="padding:2px 0;"><strong>CF/P.IVA:</strong></td><td style="padding:2px 0;">' . esc_html( $incaricato['cf_piva'] ?? 'N/D' ) . '</td></tr>';
    $html .= '<tr><td style="padding:2px 0;"><strong>Telefono:</strong></td><td style="padding:2px 0;">' . esc_html( $incaricato['telefono'] ?? 'N/D' ) . '</td></tr>';
    $html .= '<tr><td style="padding:2px 0;"><strong>Email:</strong></td><td style="padding:2px 0;">' . esc_html( $incaricato['email'] ?? 'N/D' ) . '</td></tr>';
    $html .= '<tr><td style="padding:2px 0;"><strong>Documento:</strong></td><td style="padding:2px 0;">' . $doc_tipo . $doc_numero . '</td></tr>';
    $html .= '</table></div>';
    return $html;
}

// Genera il disclaimer responsabilità incaricato per PDF/documenti (solo se c'è un incaricato diverso)
function bsn_render_pdf_incaricato_disclaimer( $incaricato ) {
    if ( ! $incaricato ) {
        return '';
    }
    return '<div style="margin:0 0 10px 0; padding:8px 10px; border:1px solid #aaa; background:#f9f9f9; font-size:9px; line-height:1.4;">'
        . '<strong>Nota — Responsabilità presa in carico:</strong> '
        . 'Il materiale viene consegnato alla persona indicata come Incaricato al ritiro, che con la firma si assume la responsabilità della presa in carico, della verifica e della riconsegna del materiale. '
        . 'Eventuali rapporti, deleghe o responsabilità interne tra l\'incaricato e il cliente/intestatario del noleggio restano a carico delle parti e non modificano la responsabilità assunta da chi ritira e firma il documento.'
        . '</div>';
}

// Genera il blocco HTML "INCARICATO AL RITIRO" per email (solo se diverso dal cliente)
function bsn_render_email_incaricato_block( $incaricato ) {
    if ( ! $incaricato ) {
        return '';
    }
    $doc_tipo   = ! empty( $incaricato['tipo_documento'] )   ? esc_html( $incaricato['tipo_documento'] )   : 'N/D';
    $doc_numero = ! empty( $incaricato['numero_documento'] ) ? ' — ' . esc_html( $incaricato['numero_documento'] ) : '';
    $html  = '<div style="margin:12px 0; border:2px solid #c00; padding:10px 14px; background:#fff8f8;">';
    $html .= '<p style="margin:0 0 6px 0; font-weight:bold; color:#c00; font-size:13px;">INCARICATO AL RITIRO</p>';
    $html .= '<p style="margin:2px 0;"><strong>Nome:</strong> ' . esc_html( $incaricato['nome'] ) . '</p>';
    $html .= '<p style="margin:2px 0;"><strong>CF/P.IVA:</strong> ' . esc_html( $incaricato['cf_piva'] ?? 'N/D' ) . '</p>';
    $html .= '<p style="margin:2px 0;"><strong>Telefono:</strong> ' . esc_html( $incaricato['telefono'] ?? 'N/D' ) . '</p>';
    $html .= '<p style="margin:2px 0;"><strong>Email:</strong> ' . esc_html( $incaricato['email'] ?? 'N/D' ) . '</p>';
    $html .= '<p style="margin:2px 0;"><strong>Documento:</strong> ' . $doc_tipo . $doc_numero . '</p>';
    $html .= '<p style="margin:10px 0 0 0; font-size:11px; color:#666;">'
        . '<em>Nota: il materiale verrà consegnato all\'incaricato indicato, che assume la responsabilità della presa in carico e della riconsegna.</em>'
        . '</p>';
    $html .= '</div>';
    return $html;
}

function bsn_get_preventivo_document_html( $noleggio_id ) {
    ob_start();
    bsn_render_preventivo_noleggio_page(
        [
            'render_document_only' => true,
            'noleggio_id'          => $noleggio_id,
        ]
    );
    return ob_get_clean();
}

function bsn_get_preventivo_pdf_path( $noleggio_id ) {
    $folder = bsn_get_pdf_directory();
    if ( ! $folder ) {
        return '';
    }

    $safe_id = preg_replace( '/[^A-Za-z0-9_\-]/', '_', (string) $noleggio_id );
    return trailingslashit( $folder['dir'] ) . 'preventivo-' . $safe_id . '.pdf';
}

function bsn_invalidate_preventivo_pdf_cache( $noleggio_id ) {
    $path = bsn_get_preventivo_pdf_path( $noleggio_id );
    if ( $path === '' || ! file_exists( $path ) ) {
        return true;
    }

    return @unlink( $path );
}

/**
 * Genera il PDF del preventivo e lo salva su filesystem.
 */
function bsn_generate_preventivo_pdf( $noleggio_id, $force = false ) {
    $folder = bsn_get_pdf_directory();
    if ( ! $folder ) {
        return false;
    }

    $safe_id = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $noleggio_id );
    $filename = 'preventivo-' . $safe_id . '.pdf';
    $path = trailingslashit( $folder['dir'] ) . $filename;
    $url = trailingslashit( $folder['url'] ) . $filename;

    if ( ! $force && file_exists( $path ) ) {
        return [ 'path' => $path, 'url' => $url ];
    }

    $html = bsn_get_preventivo_document_html( $noleggio_id );
    if ( empty( $html ) ) {
        error_log( 'BSN PDF: HTML vuoto per preventivo ' . $noleggio_id );
        return false;
    }

    if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
        $autoload_path = bsn_get_dompdf_autoload_path();
        if ( empty( $autoload_path ) ) {
            $autoload_path = bsn_download_dompdf_library();
        }
        if ( is_wp_error( $autoload_path ) || empty( $autoload_path ) ) {
            return is_wp_error( $autoload_path )
                ? $autoload_path
                : new WP_Error(
                    'bsn_pdf_missing_library',
                    'Libreria PDF mancante. Dompdf non è stato trovato.',
                    [ 'status' => 500 ]
                );
        }
        require_once $autoload_path;
    }

    $options = new Dompdf\Options();
    $options->set( 'isRemoteEnabled', true );
    $options->set( 'isHtml5ParserEnabled', true );
    try {
        $dompdf = new Dompdf\Dompdf( $options );
        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->render();

        $output = $dompdf->output();
        file_put_contents( $path, $output );
    } catch ( Exception $e ) {
        error_log( 'BSN PDF: errore generazione preventivo ' . $noleggio_id . ' - ' . $e->getMessage() );
        return false;
    }

    return [ 'path' => $path, 'url' => $url ];
}

/**
 * Invia email di conferma noleggio con PDF allegato.
 */
function bsn_invia_email_noleggio_finalizzato( $noleggio, $cliente, $pdf_info ) {
    $pdf_path = is_array( $pdf_info ) && ! empty( $pdf_info['path'] ) ? $pdf_info['path'] : '';

    $cliente_nome  = $cliente && ! empty( $cliente['nome'] )  ? $cliente['nome']  : 'Cliente';
    $cliente_email = $cliente && ! empty( $cliente['email'] ) ? $cliente['email'] : '';

    // Incaricato al ritiro (se diverso dal cliente)
    $incaricato = bsn_get_incaricato_ritiro_data( $noleggio );

    $periodo_utilizzo  = bsn_get_periodo_utilizzo_noleggio( $noleggio );
    $periodo_logistico = bsn_get_periodo_logistico_noleggio( $noleggio );

    $subject = 'Conferma noleggio #' . $noleggio['id'] . ' – Riepilogo e documento in PDF - Black Star Service Srl';

    $body = '
    <div style="font-family: Arial, sans-serif; font-size:14px; color:#222;">
        <p><img src="http://www.blackstarservice.it/wp-content/uploads/2021/04/logo-1500px-1.jpg" alt="Black Star Service" style="max-width:260px; height:auto;"></p>
        <p>Ciao ' . esc_html( $cliente_nome ) . ',</p>
        <p>grazie per aver scelto Black Star Service srl.</p>
        <p>In allegato trovi il riepilogo del tuo noleggio in formato PDF (documento di conferma).</p>
        <p><strong>Promemoria importante</strong></p>
        <p>Periodo di utilizzo: <strong>' . esc_html( $periodo_utilizzo['inizio_it'] ?: '-' ) . '</strong> / <strong>' . esc_html( $periodo_utilizzo['fine_it'] ?: '-' ) . '</strong><br>
        Periodo di ritiro/riconsegna: <strong>' . esc_html( $periodo_logistico['ritiro_effettivo_it'] ?: '-' ) . '</strong> / <strong>' . esc_html( $periodo_logistico['riconsegna_effettiva_it'] ?: '-' ) . '</strong><br>
        Riferimento ordine: <strong>#' . esc_html( $noleggio['id'] ) . '</strong></p>
        ' . bsn_render_noleggio_service_summary_html(
            bsn_get_noleggio_service_snapshot( $noleggio ),
            array(
                'context' => 'email',
                'title' => 'Servizio logistico / tecnico',
            )
        ) . '
        ' . bsn_render_email_incaricato_block( $incaricato ) . '
        <hr>
        <p><strong>BLACK STAR SERVICE SRL</strong><br>
        Sede Operativa: Via Cerca 28 - cap : 25135 - Brescia<br>
        Nota: Tutti i ritiri e le consegne devono essere fatti nel sopra citato indirizzo ⬆️ </p>
        ⏰ <strong>Orari di ritiro e riconsegna:</strong><br>
        Dal lunedì al venerdì: <strong>09:30 - 12:30</strong> e <strong>14:30 - 17:30</strong><br>
        Sabato: <strong>09:30 - 12:30</strong><br>
        Domenica: <strong>chiuso</strong>
        <p>Sede Legale: Via Repubblica Argentina 54 - cap 25124 - Brescia<br>
        Nota: L\'indirizzo di fatturazione deve essere quello della sede legale.</p>
        <p>📞 Tel amministrazione: 3921135447<br>
        📱 Mobile tecnico: 3201169791<br>
        📧 E-mail: info@blackstarservice.it<br>
        📧 E-mail: noleggi@blackstarservice.it<br>
        <p>🖥️ Sito web: https://www.blackstarservice.com/</p>
        <p>📍 Info e posizione: https://goo.gl/maps/mvj6Tc9VVCEgjDUw5</p>
        <p>PI/CF: 04130270988<br>
        CODICE UNIV: M5UXCR1</p>
        <p>AZIENDA ABILITATA MEPA</p>
        <p>Cordiali saluti, Black Star Service SRL</p>
    </div>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Black Star Service SRL <noleggi@blackstarservice.it>',
    ];

    $attachments = $pdf_path ? [ $pdf_path ] : [];

    if ( $cliente_email ) {
        $sent_cliente = wp_mail( $cliente_email, $subject, $body, $headers, $attachments );
        if ( ! $sent_cliente ) {
            error_log( 'BSN EMAIL: invio fallito a cliente per noleggio ' . $noleggio['id'] );
        }
    }

    // Invia copia anche all'incaricato (se ha email diversa dal cliente)
    if ( $incaricato && ! empty( $incaricato['email'] ) && $incaricato['email'] !== $cliente_email ) {
        $subject_inc = 'Noleggio #' . $noleggio['id'] . ' – Sei indicato come incaricato al ritiro - Black Star Service Srl';
        $body_inc = str_replace(
            'Ciao ' . esc_html( $cliente_nome ) . ',',
            'Ciao ' . esc_html( $incaricato['nome'] ) . ',<br><em>Sei stato indicato come incaricato al ritiro per il noleggio del cliente ' . esc_html( $cliente_nome ) . '.</em>',
            $body
        );
        $sent_inc = wp_mail( $incaricato['email'], $subject_inc, $body_inc, $headers, $attachments );
        if ( ! $sent_inc ) {
            error_log( 'BSN EMAIL: invio fallito a incaricato per noleggio ' . $noleggio['id'] );
        }
    }

    $sent_staff = wp_mail( 'noleggi@blackstarservice.it', $subject, $body, $headers, $attachments );
    if ( ! $sent_staff ) {
        error_log( 'BSN EMAIL: invio fallito a staff per noleggio ' . $noleggio['id'] );
    }
}

/**
 * Invia email preventivo con PDF allegato.
 */
function bsn_invia_email_preventivo( $noleggio, $cliente, $pdf_info ) {
    $pdf_path = is_array( $pdf_info ) && ! empty( $pdf_info['path'] ) ? $pdf_info['path'] : '';

    $cliente_nome  = $cliente && ! empty( $cliente['nome'] )  ? $cliente['nome']  : 'Cliente';
    $cliente_email = $cliente && ! empty( $cliente['email'] ) ? $cliente['email'] : '';

    // Incaricato al ritiro (se diverso dal cliente)
    $incaricato = bsn_get_incaricato_ritiro_data( $noleggio );

    $periodo_utilizzo  = bsn_get_periodo_utilizzo_noleggio( $noleggio );
    $periodo_logistico = bsn_get_periodo_logistico_noleggio( $noleggio );
    $service_html = bsn_render_noleggio_service_summary_html(
        bsn_get_noleggio_service_snapshot( $noleggio ),
        array(
            'context' => 'email',
            'title' => 'Servizio richiesto',
        )
    );

    $subject = 'PREVENTIVO BLACK STAR SERVICE';

    $body = '
    <div style="font-family: Arial, sans-serif; font-size:14px; color:#222;">
        <p><img src="http://www.blackstarservice.it/wp-content/uploads/2021/04/logo-1500px-1.jpg" alt="Black Star Service" style="max-width:260px; height:auto;"></p>
        <p>Ciao ' . esc_html( $cliente_nome ) . ',</p>
        <p>grazie per aver scelto Black Star Service Srl.</p>
        <p>In allegato trovi il riepilogo del tuo <strong>PREVENTIVO</strong> in formato PDF.</p>
        <p>Periodo di utilizzo: <strong>' . esc_html( $periodo_utilizzo['inizio_it'] ?: '-' ) . '</strong> / <strong>' . esc_html( $periodo_utilizzo['fine_it'] ?: '-' ) . '</strong><br>
        Periodo di ritiro/riconsegna: <strong>' . esc_html( $periodo_logistico['ritiro_effettivo_it'] ?: '-' ) . '</strong> / <strong>' . esc_html( $periodo_logistico['riconsegna_effettiva_it'] ?: '-' ) . '</strong></p>
        ' . $service_html . '
        ' . bsn_render_email_incaricato_block( $incaricato ) . '
        <p><strong>PROMEMORIA IMPORTANTE</strong><br>
        <strong>IL PREVENTIVO NON IMPLICA LA CONFERMA DEL MATERIALE</strong><br>
        È necessario confermare telefonicamente al numero:<br>
        392 113 5447 (anche WhatsApp)<br>
        includendo il riferimento ordine: <strong>' . esc_html( $noleggio['id'] ) . '</strong></p>
        <hr>
        <p><strong>BLACK STAR SERVICE SRL</strong><br>
        Sede Operativa: Via Cerca 28 – 25135 Brescia<br>
        Nota: Tutti i ritiri e le consegne devono avvenire presso questo indirizzo.</p>
        <p>⏰ Orari:<br>
        L–V 09:30–12:30 / 14:30–17:30<br>
        Sabato 09:30–12:30<br>
        Domenica chiuso</p>
        <p>📞 Amministrazione: 392 113 5447<br>
        📱 Tecnico: 320 116 9791<br>
        📧 info@blackstarservice.it<br>
        📧 noleggi@blackstarservice.it</p>
        <p>🖥️ https://www.blackstarservice.com<br>
        📍 https://goo.gl/maps/mvj6Tc9VVCEgjDUw5</p>
        <p>PI/CF: 04130270988<br>
        CODICE UNIV: M5UXCR1</p>
        <p>AZIENDA ABILITATA MEPA</p>
        <p>Cordiali saluti<br>
        Black Star Service Srl</p>
    </div>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Black Star Service SRL <noleggi@blackstarservice.it>',
    ];

    $attachments = $pdf_path ? [ $pdf_path ] : [];

    if ( $cliente_email ) {
        $sent_cliente = wp_mail( $cliente_email, $subject, $body, $headers, $attachments );
        if ( ! $sent_cliente ) {
            error_log( 'BSN EMAIL: invio fallito a cliente per preventivo ' . $noleggio['id'] );
        }
    }

    // Invia copia anche all'incaricato (se ha email diversa dal cliente)
    if ( $incaricato && ! empty( $incaricato['email'] ) && $incaricato['email'] !== $cliente_email ) {
        $subject_inc = 'PREVENTIVO #' . $noleggio['id'] . ' – Sei indicato come incaricato al ritiro - Black Star Service Srl';
        $body_inc = str_replace(
            'Ciao ' . esc_html( $cliente_nome ) . ',',
            'Ciao ' . esc_html( $incaricato['nome'] ) . ',<br><em>Sei stato indicato come incaricato al ritiro per il preventivo del cliente ' . esc_html( $cliente_nome ) . '.</em>',
            $body
        );
        $sent_inc = wp_mail( $incaricato['email'], $subject_inc, $body_inc, $headers, $attachments );
        if ( ! $sent_inc ) {
            error_log( 'BSN EMAIL: invio fallito a incaricato per preventivo ' . $noleggio['id'] );
        }
    }

    $sent_staff = wp_mail( 'noleggi@blackstarservice.it', $subject, $body, $headers, $attachments );
    if ( ! $sent_staff ) {
        error_log( 'BSN EMAIL: invio fallito a staff per preventivo ' . $noleggio['id'] );
    }
}

function bsn_invia_email_conferma_preventivo_cliente( $noleggio, $cliente, $pdf_info ) {
    $pdf_path = is_array( $pdf_info ) && ! empty( $pdf_info['path'] ) ? $pdf_info['path'] : '';
    $cliente_nome  = $cliente && ! empty( $cliente['nome'] )  ? $cliente['nome']  : 'Cliente';
    $cliente_email = $cliente && ! empty( $cliente['email'] ) ? $cliente['email'] : '';

    if ( $cliente_email === '' ) {
        return false;
    }

    // Incaricato al ritiro (se diverso dal cliente)
    $incaricato = bsn_get_incaricato_ritiro_data( $noleggio );

    $periodo_utilizzo  = bsn_get_periodo_utilizzo_noleggio( $noleggio );
    $periodo_logistico = bsn_get_periodo_logistico_noleggio( $noleggio );
    $service_html = bsn_render_noleggio_service_summary_html(
        bsn_get_noleggio_service_snapshot( $noleggio ),
        array(
            'context' => 'email',
            'title' => 'Servizio confermato / da coordinare',
        )
    );
    $subject = 'Richiesta confermata #' . $noleggio['id'] . ' - Black Star Service';

    $body = '
    <div style="font-family: Arial, sans-serif; font-size:14px; color:#222;">
        <p><img src="http://www.blackstarservice.it/wp-content/uploads/2021/04/logo-1500px-1.jpg" alt="Black Star Service" style="max-width:260px; height:auto;"></p>
        <p>Ciao ' . esc_html( $cliente_nome ) . ',</p>
        <p>la tua richiesta <strong>#' . esc_html( $noleggio['id'] ) . '</strong> è stata verificata dal nostro staff ed è ora <strong>confermata</strong>.</p>
        <p>In allegato trovi il PDF riepilogativo attualmente disponibile. La presa in carico operativa del ritiro e delle successive fasi continuerà a seguire il flusso interno Black Star Service.</p>
        <p>Periodo di utilizzo: <strong>' . esc_html( $periodo_utilizzo['inizio_it'] ?: '-' ) . '</strong> / <strong>' . esc_html( $periodo_utilizzo['fine_it'] ?: '-' ) . '</strong><br>
        Periodo di ritiro/riconsegna: <strong>' . esc_html( $periodo_logistico['ritiro_effettivo_it'] ?: '-' ) . '</strong> / <strong>' . esc_html( $periodo_logistico['riconsegna_effettiva_it'] ?: '-' ) . '</strong></p>
        ' . $service_html . '
        ' . bsn_render_email_incaricato_block( $incaricato ) . '
        <p>Per qualsiasi dubbio puoi rispondere a questa email oppure contattarci su <strong>noleggi@blackstarservice.it</strong>.</p>
        <p>Cordiali saluti<br>Black Star Service Srl</p>
    </div>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Black Star Service SRL <noleggi@blackstarservice.it>',
    ];

    $attachments = $pdf_path ? [ $pdf_path ] : [];
    $sent = wp_mail( $cliente_email, $subject, $body, $headers, $attachments );
    if ( ! $sent ) {
        error_log( 'BSN EMAIL: invio conferma richiesta fallito per noleggio ' . $noleggio['id'] );
    }

    // Invia copia anche all'incaricato (se ha email diversa dal cliente)
    if ( $incaricato && ! empty( $incaricato['email'] ) && $incaricato['email'] !== $cliente_email ) {
        $subject_inc = 'Noleggio #' . $noleggio['id'] . ' confermato – Sei indicato come incaricato al ritiro - Black Star Service';
        $body_inc = str_replace(
            'Ciao ' . esc_html( $cliente_nome ) . ',',
            'Ciao ' . esc_html( $incaricato['nome'] ) . ',<br><em>Sei stato indicato come incaricato al ritiro per il noleggio del cliente ' . esc_html( $cliente_nome ) . '.</em>',
            $body
        );
        $sent_inc = wp_mail( $incaricato['email'], $subject_inc, $body_inc, $headers, $attachments );
        if ( ! $sent_inc ) {
            error_log( 'BSN EMAIL: invio conferma a incaricato fallito per noleggio ' . $noleggio['id'] );
        }
    }

    return $sent;
}
// [BSN_PDF_EMAIL_END]

/**
 * Endpoint PDF noleggio: restituisce il file generato.
 */
function bsn_api_noleggi_pdf_get( WP_REST_Request $request ) {
    ob_start();
    $noleggio_id = sanitize_text_field( $request->get_param( 'id' ) );
    if ( empty( $noleggio_id ) ) {
        ob_end_clean();
        return new WP_Error( 'bsn_pdf_id', 'ID noleggio mancante.', [ 'status' => 400 ] );
    }

    $pdf_info = bsn_generate_noleggio_pdf( $noleggio_id, false );
    if ( is_wp_error( $pdf_info ) ) {
        ob_end_clean();
        return $pdf_info;
    }
    if ( ! $pdf_info || empty( $pdf_info['path'] ) || ! file_exists( $pdf_info['path'] ) ) {
        ob_end_clean();
        return new WP_Error( 'bsn_pdf_missing', 'PDF non trovato.', [ 'status' => 404 ] );
    }

    if ( ob_get_length() ) {
        ob_clean();
    }
    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: inline; filename="' . basename( $pdf_info['path'] ) . '"' );
    header( 'Content-Length: ' . filesize( $pdf_info['path'] ) );
    readfile( $pdf_info['path'] );
    exit;
}

/**
 * Endpoint PDF preventivo: restituisce il file generato.
 */
function bsn_api_noleggi_preventivo_pdf_get( WP_REST_Request $request ) {
    ob_start();
    $noleggio_id = sanitize_text_field( $request->get_param( 'id' ) );
    if ( empty( $noleggio_id ) ) {
        ob_end_clean();
        return new WP_Error( 'bsn_pdf_id', 'ID preventivo mancante.', [ 'status' => 400 ] );
    }

    global $wpdb;
    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $stato = $wpdb->get_var(
        $wpdb->prepare( "SELECT stato FROM $table_noleggi WHERE id = %s", $noleggio_id )
    );
    if ( $stato !== 'preventivo' ) {
        ob_end_clean();
        return new WP_Error( 'bsn_pdf_stato', 'Documento non in stato preventivo.', [ 'status' => 400 ] );
    }

    $pdf_info = bsn_generate_preventivo_pdf( $noleggio_id, true );
    if ( is_wp_error( $pdf_info ) ) {
        ob_end_clean();
        return $pdf_info;
    }

    if ( ! $pdf_info || empty( $pdf_info['path'] ) || ! file_exists( $pdf_info['path'] ) ) {
        ob_end_clean();
        return new WP_Error( 'bsn_pdf_missing', 'PDF preventivo non trovato.', [ 'status' => 404 ] );
    }

    ob_end_clean();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: inline; filename="' . basename( $pdf_info['path'] ) . '"' );
    header( 'Content-Length: ' . filesize( $pdf_info['path'] ) );
    readfile( $pdf_info['path'] );
    exit;
}

/**
 * Endpoint invio preventivo con PDF via email.
 */
function bsn_api_noleggi_preventivo_invia( WP_REST_Request $request ) {
    global $wpdb;

    $id_noleggio = sanitize_text_field( $request->get_param( 'id' ) );
    if ( empty( $id_noleggio ) ) {
        return new WP_Error( 'bsn_preventivo_id', 'ID preventivo mancante.', [ 'status' => 400 ] );
    }

    $table_noleggi = $wpdb->prefix . 'bs_noleggi';
    $noleggio = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_noleggi WHERE id = %s", $id_noleggio ),
        ARRAY_A
    );
    if ( ! $noleggio ) {
        return new WP_Error( 'bsn_preventivo_non_trovato', 'Preventivo non trovato.', [ 'status' => 404 ] );
    }
    if ( $noleggio['stato'] !== 'preventivo' ) {
        return new WP_Error( 'bsn_preventivo_stato', 'Il documento non è in stato preventivo.', [ 'status' => 400 ] );
    }

    $pdf_info = bsn_generate_preventivo_pdf( $id_noleggio, true );
    if ( is_wp_error( $pdf_info ) ) {
        return $pdf_info;
    }

    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $cliente = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_clienti WHERE id = %d", $noleggio['cliente_id'] ),
        ARRAY_A
    );

    if ( $cliente ) {
        bsn_invia_email_preventivo( $noleggio, $cliente, $pdf_info );
    } else {
        bsn_invia_email_preventivo( $noleggio, null, $pdf_info );
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $id_noleggio,
    ] );
}

/**
 * Restituisce il path di autoload dompdf se presente.
 */
function bsn_get_dompdf_autoload_path() {
    $candidates = [
        BSN_PATH . 'lib/dompdf/autoload.inc.php',
        WP_CONTENT_DIR . '/uploads/bsn-dompdf/dompdf/autoload.inc.php',
        WP_CONTENT_DIR . '/uploads/bsn-dompdf/dompdf-2.0.4/autoload.inc.php',
    ];

    foreach ( $candidates as $path ) {
        if ( file_exists( $path ) ) {
            return $path;
        }
    }

    return '';
}

/**
 * Scarica dompdf dal repository e lo salva in uploads/bsn-dompdf.
 */
function bsn_download_dompdf_library() {
    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['error'] ) ) {
        return new WP_Error(
            'bsn_pdf_upload_dir',
            'Impossibile accedere alla cartella upload per dompdf.',
            [ 'status' => 500 ]
        );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';

    $target_dir = trailingslashit( $upload_dir['basedir'] ) . 'bsn-dompdf';
    if ( ! file_exists( $target_dir ) ) {
        wp_mkdir_p( $target_dir );
    }

    $zip_url = 'https://github.com/dompdf/dompdf/archive/refs/tags/v2.0.4.zip';
    $tmp_file = download_url( $zip_url );
    if ( is_wp_error( $tmp_file ) ) {
        return new WP_Error(
            'bsn_pdf_download',
            'Errore nel download di dompdf.',
            [ 'status' => 500 ]
        );
    }

    $unzipped = unzip_file( $tmp_file, $target_dir );
    @unlink( $tmp_file );

    if ( is_wp_error( $unzipped ) ) {
        return new WP_Error(
            'bsn_pdf_unzip',
            'Errore nell\'estrazione di dompdf.',
            [ 'status' => 500 ]
        );
    }

    $autoload_path = $target_dir . '/dompdf-2.0.4/autoload.inc.php';
    if ( ! file_exists( $autoload_path ) ) {
        return new WP_Error(
            'bsn_pdf_missing_library',
            'Libreria PDF mancante dopo il download.',
            [ 'status' => 500 ]
        );
    }

    return $autoload_path;
}

/**
 * Endpoint cambio stato noleggio - callback
 */
function bsn_cambia_stato_noleggio( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bs_noleggi';

    $id_noleggio = sanitize_text_field( $request->get_param( 'id_noleggio' ) );
    $nuovo_stato = sanitize_text_field( $request->get_param( 'nuovo_stato' ) );

    $stati_validi = array( 'preventivo', 'bozza', 'attivo', 'chiuso', 'ritardo' );
    if ( ! in_array( $nuovo_stato, $stati_validi, true ) ) {
        return new WP_Error( 'stato_non_valido', 'Stato non valido', array( 'status' => 400 ) );
    }

    $noleggio = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table WHERE id = %s LIMIT 1", $id_noleggio ),
        ARRAY_A
    );
    if ( ! $noleggio ) {
        return new WP_Error( 'noleggio_non_trovato', 'Noleggio non trovato', array( 'status' => 404 ) );
    }

    $stato_precedente = sanitize_key( (string) ( $noleggio['stato'] ?? '' ) );

    $updated = $wpdb->update(
        $table,
        array( 'stato' => $nuovo_stato ),
        array( 'id'    => $id_noleggio ),
        array( '%s' ),
        array( '%s' )
    );

    if ( $updated === false ) {
        return new WP_Error( 'db_error', 'Errore nel salvataggio', array( 'status' => 500 ) );
    }

    if ( $stato_precedente === 'preventivo' && $nuovo_stato === 'bozza' ) {
        $table_clienti = $wpdb->prefix . 'bs_clienti';
        $cliente = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table_clienti WHERE id = %d LIMIT 1", (int) $noleggio['cliente_id'] ),
            ARRAY_A
        );
        $noleggio['stato'] = 'bozza';
        $pdf_info = bsn_generate_preventivo_pdf( $id_noleggio, true );
        if ( is_wp_error( $pdf_info ) ) {
            error_log( 'BSN EMAIL: PDF conferma richiesta non disponibile per ' . $id_noleggio . ' - ' . $pdf_info->get_error_message() );
            $pdf_info = false;
        }
        bsn_invia_email_conferma_preventivo_cliente( $noleggio, $cliente, $pdf_info );
    }

    return array(
        'success'     => true,
        'id_noleggio' => $id_noleggio,
        'stato'       => $nuovo_stato,
    );
}

function bsn_ticket_tipo_impatta_disponibilita( $tipo ) {
    $tipi_bloccanti = [
        'mancante',
        'non_rientrato',
        'quantita_inferiore',
        'danneggiato',
    ];

    return in_array( $tipo, $tipi_bloccanti, true );
}

function bsn_ticket_aggiorna_disponibilita( $articolo_id, $qty_delta ) {
    global $wpdb;
    $table_articoli = $wpdb->prefix . 'bs_articoli';

    $articolo_id = intval( $articolo_id );
    $qty_delta   = intval( $qty_delta );

    if ( $articolo_id <= 0 || $qty_delta === 0 ) {
        return;
    }

    $current_qty = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT qty_disponibile FROM $table_articoli WHERE id = %d",
            $articolo_id
        )
    );

    if ( $current_qty === null ) {
        return;
    }

    $nuova_qty = max( 0, intval( $current_qty ) + $qty_delta );
    $wpdb->update(
        $table_articoli,
        [ 'qty_disponibile' => $nuova_qty ],
        [ 'id' => $articolo_id ],
        [ '%d' ],
        [ '%d' ]
    );
}

/**
 * Normalizza le foto ticket in JSON (array di URL).
 */
function bsn_ticket_normalizza_foto( $foto_param ) {
    if ( empty( $foto_param ) ) {
        return '';
    }

    if ( is_array( $foto_param ) ) {
        $urls = array_filter( array_map( 'esc_url_raw', $foto_param ) );
        return $urls ? wp_json_encode( array_values( $urls ) ) : '';
    }

    if ( is_string( $foto_param ) ) {
        $decoded = json_decode( $foto_param, true );
        if ( is_array( $decoded ) ) {
            $urls = array_filter( array_map( 'esc_url_raw', $decoded ) );
            return $urls ? wp_json_encode( array_values( $urls ) ) : '';
        }

        $single = esc_url_raw( $foto_param );
        return $single ? wp_json_encode( [ $single ] ) : '';
    }

    return '';
}

function bsn_crea_ticket( $data ) {
    global $wpdb;
    $table_ticket = $wpdb->prefix . 'bs_ticket';

    bsn_ensure_ticket_table();

    $insert = [
        'noleggio_id' => ! empty( $data['noleggio_id'] ) ? $data['noleggio_id'] : null,
        'articolo_id' => intval( $data['articolo_id'] ),
        'qty'         => max( 1, intval( $data['qty'] ) ),
        'tipo'        => $data['tipo'],
        'note'        => ! empty( $data['note'] ) ? $data['note'] : '',
        'foto'        => ! empty( $data['foto'] ) ? $data['foto'] : '',
        'operatore'   => ! empty( $data['operatore'] ) ? $data['operatore'] : '',
        'origine'     => ! empty( $data['origine'] ) ? $data['origine'] : 'manuale',
        'stato'       => 'aperto',
        'creato_il'   => current_time( 'mysql' ),
    ];

    $result = $wpdb->insert(
        $table_ticket,
        $insert,
        [ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
    );

    if ( ! $result ) {
        return false;
    }

    if ( bsn_ticket_tipo_impatta_disponibilita( $insert['tipo'] ) ) {
        bsn_ticket_aggiorna_disponibilita( $insert['articolo_id'], -1 * $insert['qty'] );
    }

    return $wpdb->insert_id;
}

function bsn_api_tickets_get() {
    global $wpdb;
    $table_ticket   = $wpdb->prefix . 'bs_ticket';
    $table_articoli = $wpdb->prefix . 'bs_articoli';

    bsn_ensure_ticket_table();

    $sql = "SELECT t.*, a.nome AS articolo_nome, a.codice AS articolo_codice
            FROM $table_ticket t
            LEFT JOIN $table_articoli a ON t.articolo_id = a.id
            ORDER BY t.creato_il DESC";

    $tickets = $wpdb->get_results( $sql, ARRAY_A );

    return rest_ensure_response( [ 'tickets' => $tickets ] );
}

function bsn_api_tickets_post( WP_REST_Request $request ) {
    bsn_ensure_ticket_table();
    
    $noleggio_id = sanitize_text_field( $request->get_param( 'noleggio_id' ) );
    $articolo_id = intval( $request->get_param( 'articolo_id' ) );
    $qty         = intval( $request->get_param( 'qty' ) );
    $tipo        = sanitize_text_field( $request->get_param( 'tipo' ) );
    $note        = sanitize_text_field( $request->get_param( 'note' ) );
    $stato       = sanitize_text_field( $request->get_param( 'stato' ) );
    $foto_raw    = $request->get_param( 'foto' );
    $foto_json   = bsn_ticket_normalizza_foto( $foto_raw );

    if ( $articolo_id <= 0 ) {
        return new WP_Error( 'bsn_ticket_articolo', 'Articolo non valido.', [ 'status' => 400 ] );
    }

    if ( $qty <= 0 ) {
        return new WP_Error( 'bsn_ticket_qty', 'Quantità non valida.', [ 'status' => 400 ] );
    }

    $tipi_validi = [ 'mancante', 'non_rientrato', 'quantita_inferiore', 'danneggiato', 'problematico_utilizzabile' ];
    if ( ! in_array( $tipo, $tipi_validi, true ) ) {
        return new WP_Error( 'bsn_ticket_tipo', 'Tipo ticket non valido.', [ 'status' => 400 ] );
    }

    $current_user = wp_get_current_user();
    $ticket_id = bsn_crea_ticket( [
        'noleggio_id' => $noleggio_id,
        'articolo_id' => $articolo_id,
        'qty'         => $qty,
        'tipo'        => $tipo,
        'note'        => $note,
        'foto'        => $foto_json,
        'operatore'   => $current_user ? $current_user->user_login : '',
        'origine'     => 'manuale',
    ] );

    if ( ! $ticket_id ) {
       global $wpdb;
        $detail = $wpdb->last_error ? $wpdb->last_error : 'Errore sconosciuto DB.';
        return new WP_Error( 'bsn_ticket_error', 'Errore nella creazione del ticket.', [ 'status' => 500, 'detail' => $detail ] );
    }

    return rest_ensure_response( [
        'success'   => true,
        'ticket_id' => $ticket_id,
    ] );
}

function bsn_api_tickets_update( WP_REST_Request $request ) {
    global $wpdb;
    $table_ticket = $wpdb->prefix . 'bs_ticket';

    bsn_ensure_ticket_table();

    $ticket_id  = intval( $request->get_param( 'ticket_id' ) );
    $articolo_id = intval( $request->get_param( 'articolo_id' ) );
    $qty         = intval( $request->get_param( 'qty' ) );
    $tipo        = sanitize_text_field( $request->get_param( 'tipo' ) );
    $note        = sanitize_text_field( $request->get_param( 'note' ) );
    $stato       = sanitize_text_field( $request->get_param( 'stato' ) );
    $foto_raw    = $request->get_param( 'foto' );
    $foto_json   = bsn_ticket_normalizza_foto( $foto_raw );

    if ( $ticket_id <= 0 ) {
        return new WP_Error( 'bsn_ticket_id', 'ID ticket non valido.', [ 'status' => 400 ] );
    }
    if ( $articolo_id <= 0 || $qty <= 0 ) {
        return new WP_Error( 'bsn_ticket_data', 'Dati ticket non validi.', [ 'status' => 400 ] );
    }

    $tipi_validi = [ 'mancante', 'non_rientrato', 'quantita_inferiore', 'danneggiato', 'problematico_utilizzabile' ];
    if ( ! in_array( $tipo, $tipi_validi, true ) ) {
        return new WP_Error( 'bsn_ticket_tipo', 'Tipo ticket non valido.', [ 'status' => 400 ] );
    }

    $stati_validi = [ 'aperto', 'chiuso' ];
    if ( ! in_array( $stato, $stati_validi, true ) ) {
        $stato = 'aperto';
    }

    $ticket = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_ticket WHERE id = %d", $ticket_id ),
        ARRAY_A
    );

    if ( ! $ticket ) {
        return new WP_Error( 'bsn_ticket_not_found', 'Ticket non trovato.', [ 'status' => 404 ] );
    }

    $old_impacts = ( $ticket['stato'] === 'aperto' ) && bsn_ticket_tipo_impatta_disponibilita( $ticket['tipo'] );
    $new_impacts = ( $stato === 'aperto' ) && bsn_ticket_tipo_impatta_disponibilita( $tipo );

    if ( $old_impacts ) {
        bsn_ticket_aggiorna_disponibilita( intval( $ticket['articolo_id'] ), intval( $ticket['qty'] ) );
    }

    if ( $new_impacts ) {
        bsn_ticket_aggiorna_disponibilita( $articolo_id, -1 * $qty );
    }

    $updated = $wpdb->update(
        $table_ticket,
        [
            'articolo_id' => $articolo_id,
            'qty'         => $qty,
            'tipo'        => $tipo,
            'note'        => $note,
            'stato'       => $stato,
            'foto'        => $foto_json,
        ],
        [ 'id' => $ticket_id ],
        [ '%d', '%d', '%s', '%s', '%s', '%s' ],
        [ '%d' ]
    );

    if ( $updated === false ) {
        return new WP_Error( 'bsn_ticket_update', 'Errore nel salvataggio ticket.', [ 'status' => 500 ] );
    }

    return rest_ensure_response( [ 'success' => true, 'ticket_id' => $ticket_id ] );
}

function bsn_api_tickets_chiudi( WP_REST_Request $request ) {
    global $wpdb;
    $table_ticket = $wpdb->prefix . 'bs_ticket';

    bsn_ensure_ticket_table();

    $ticket_id = intval( $request->get_param( 'ticket_id' ) );
    if ( $ticket_id <= 0 ) {
        return new WP_Error( 'bsn_ticket_id', 'ID ticket non valido.', [ 'status' => 400 ] );
    }

    $ticket = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_ticket WHERE id = %d", $ticket_id ),
        ARRAY_A
    );

    if ( ! $ticket ) {
        return new WP_Error( 'bsn_ticket_not_found', 'Ticket non trovato.', [ 'status' => 404 ] );
    }

    if ( $ticket['stato'] === 'chiuso' ) {
        return rest_ensure_response( [ 'success' => true, 'ticket_id' => $ticket_id ] );
    }

    $updated = $wpdb->update(
        $table_ticket,
        [
            'stato'     => 'chiuso',
            'chiuso_il' => current_time( 'mysql' ),
        ],
        [ 'id' => $ticket_id ],
        [ '%s', '%s' ],
        [ '%d' ]
    );

    if ( $updated === false ) {
        return new WP_Error( 'bsn_ticket_update', 'Errore nella chiusura ticket.', [ 'status' => 500 ] );
    }

    if ( bsn_ticket_tipo_impatta_disponibilita( $ticket['tipo'] ) ) {
        bsn_ticket_aggiorna_disponibilita( intval( $ticket['articolo_id'] ), intval( $ticket['qty'] ) );
    }

    return rest_ensure_response( [ 'success' => true, 'ticket_id' => $ticket_id ] );
}

/**
 * Endpoint Rientro noleggio - callback
 */
function bsn_api_noleggi_rientro( $request ) {
    global $wpdb;
    $table_noleggi = $wpdb->prefix . 'bs_noleggi';

    bsn_ensure_ticket_table();
    bsn_ensure_noleggi_columns();
    
    $params      = $request->get_json_params();
    $id_noleggio = isset( $params['id_noleggio'] ) ? sanitize_text_field( $params['id_noleggio'] ) : '';
    $op_rientro  = isset( $params['op_rientro'] ) ? sanitize_text_field( $params['op_rientro'] ) : '';
    $articoli    = isset( $params['articoli'] ) && is_array( $params['articoli'] ) ? $params['articoli'] : array();

    if ( ! $id_noleggio ) {
        return new WP_Error( 'bsn_no_id', 'ID noleggio mancante.', array( 'status' => 400 ) );
    }

    // Recupera noleggio
    $noleggio = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_noleggi WHERE id = %s",
            $id_noleggio
        )
    );

    if ( ! $noleggio ) {
        return new WP_Error( 'bsn_not_found', 'Noleggio non trovato.', array( 'status' => 404 ) );
    }

    if ( $noleggio->stato !== 'attivo' && $noleggio->stato !== 'ritardo' ) {
        return new WP_Error( 'bsn_bad_state', 'Il noleggio non è in stato attivo o ritardo.', array( 'status' => 400 ) );
    }

    if ( ! $op_rientro ) {
        return new WP_Error( 'bsn_no_op', 'Operatore rientro materiali mancante.', array( 'status' => 400 ) );
    }

    if ( empty( $articoli ) ) {
        return new WP_Error( 'bsn_no_articoli', 'Nessun articolo fornito per il rientro.', array( 'status' => 400 ) );
    }

    $report     = [];
    $current_user = wp_get_current_user();

    $ticket_items = [];
    $tipi_validi = [ 'ok', 'ticket' ];

    foreach ( $articoli as $riga ) {
        $articolo_id   = isset( $riga['articolo_id'] ) ? intval( $riga['articolo_id'] ) : 0;
        $qty           = isset( $riga['qty'] ) ? intval( $riga['qty'] ) : 0;
        $qty_rientrata = isset( $riga['qty_rientrata'] ) ? intval( $riga['qty_rientrata'] ) : 0;
        $stato_rientro = isset( $riga['stato_rientro'] ) ? sanitize_text_field( $riga['stato_rientro'] ) : 'ok';
        $note          = isset( $riga['note'] ) ? sanitize_text_field( $riga['note'] ) : '';

        if ( $articolo_id <= 0 || $qty <= 0 ) {
            continue;
        }

        if ( $qty_rientrata < 0 || $qty_rientrata > $qty ) {
            return new WP_Error( 'bsn_qty_rientro', 'Quantità rientrata non valida per un articolo.', array( 'status' => 400 ) );
        }

        if ( ! in_array( $stato_rientro, $tipi_validi, true ) ) {
            return new WP_Error( 'bsn_stato_rientro', 'Stato rientro non valido.', array( 'status' => 400 ) );
        }

        $report[] = [
            'articolo_id'   => $articolo_id,
            'qty'           => $qty,
            'qty_rientrata' => $qty_rientrata,
            'stato_rientro' => $stato_rientro,
            'note'          => $note,
        ];

        $richiede_ticket = ( $stato_rientro !== 'ok' ) || ( $qty_rientrata < $qty );
        if ( ! $richiede_ticket ) {
            continue;
        }

        $tipo_ticket = $stato_rientro;
        if ( $stato_rientro === 'ok' && $qty_rientrata < $qty ) {
            $tipo_ticket = 'quantita_inferiore';
        }

        $qty_ticket = 0;
        if ( in_array( $tipo_ticket, [ 'mancante', 'non_rientrato', 'quantita_inferiore' ], true ) ) {
            $qty_ticket = max( 1, $qty - $qty_rientrata );
        } elseif ( in_array( $tipo_ticket, [ 'danneggiato', 'problematico_utilizzabile' ], true ) ) {
            $qty_ticket = max( 1, $qty_rientrata );
        } else {
            $qty_ticket = max( 1, $qty );
        }

        $ticket_id = bsn_crea_ticket( [
            'noleggio_id' => $id_noleggio,
            'articolo_id' => $articolo_id,
            'qty'         => $qty_ticket,
            'tipo'        => $tipo_ticket,
            'note'        => $note,
            'operatore'   => $current_user ? $current_user->user_login : $op_rientro,
        ] );

        if ( $ticket_id ) {
            $ticket_ids[] = $ticket_id;
        }
    }

    // STEP B2: chiusura noleggio + operatore rientro + report
    $updated = $wpdb->update(
        $table_noleggi,
        array(
            'op_rientro_materiale' => $op_rientro,
            'stato'                => 'chiuso',
            'rientro_report'       => wp_json_encode( $report ),
            'rientro_data'         => current_time( 'mysql' ),
        ),
        array(
            'id' => $id_noleggio,
        ),
        array( '%s', '%s', '%s', '%s' ),
        array( '%s' )
    );

    if ( $updated === false ) {
        return new WP_Error( 'bsn_db_error', 'Errore durante l\'aggiornamento del noleggio.', array( 'status' => 500 ) );
    }

    return array(
        'success'    => true,
        'message'    => 'Rientro salvato e noleggio chiuso.',
        'stato'      => 'chiuso',
        'id'         => $id_noleggio,
        'ticket_ids' => $ticket_ids,
    );
}



function bsn_ensure_clienti_columns_frontend_auth() {
    global $wpdb;
    $table_clienti = $wpdb->prefix . 'bs_clienti';
    $columns = array(
        'wp_user_id' => "ALTER TABLE $table_clienti ADD wp_user_id bigint(20) unsigned NULL",
        'account_source' => "ALTER TABLE $table_clienti ADD account_source varchar(30) NULL",
        'data_registrazione_frontend' => "ALTER TABLE $table_clienti ADD data_registrazione_frontend datetime NULL",
    );

    foreach ( $columns as $column => $sql ) {
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table_clienti LIKE %s", $column ) );
        if ( ! $exists ) {
            $wpdb->query( $sql );
        }
    }
}
add_action( 'init', 'bsn_ensure_clienti_columns_frontend_auth', 20 );

function bsn_clienti_table_has_column( $column ) {
    global $wpdb;

    static $cache = array();
    $column = sanitize_key( (string) $column );
    if ( $column === '' ) {
        return false;
    }

    if ( array_key_exists( $column, $cache ) ) {
        return $cache[ $column ];
    }

    $table = $wpdb->prefix . 'bs_clienti';
    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) );
    $cache[ $column ] = ! empty( $exists );

    return $cache[ $column ];
}

function bsn_filter_clienti_table_data( $data ) {
    if ( ! is_array( $data ) || empty( $data ) ) {
        return array();
    }

    $allowed = array();
    foreach ( array_keys( $data ) as $column ) {
        if ( bsn_clienti_table_has_column( $column ) ) {
            $allowed[ $column ] = true;
        }
    }

    return array_intersect_key( $data, $allowed );
}

function bsn_get_customer_from_wp_user( $user_id ) {
    global $wpdb;

    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return null;
    }

    $cliente = null;
    $table = $wpdb->prefix . 'bs_clienti';
    if ( bsn_clienti_table_has_column( 'wp_user_id' ) ) {
        $sql = $wpdb->prepare( "SELECT * FROM $table WHERE wp_user_id = %d LIMIT 1", $user_id );
        $cliente = $wpdb->get_row( $sql, ARRAY_A );
    }

    if ( ! $cliente ) {
        $user = get_user_by( 'id', $user_id );
        if ( $user && ! empty( $user->user_email ) ) {
            $cliente = bsn_get_customer_from_email( $user->user_email );
        }
    }

    return $cliente ?: null;
}

function bsn_get_customer_from_email( $email ) {
    global $wpdb;

    $email = sanitize_email( $email );
    if ( $email === '' ) {
        return null;
    }

    $table = $wpdb->prefix . 'bs_clienti';
    $sql = $wpdb->prepare( "SELECT * FROM $table WHERE email = %s ORDER BY id DESC LIMIT 1", $email );
    $cliente = $wpdb->get_row( $sql, ARRAY_A );

    return $cliente ?: null;
}

function bsn_link_user_to_customer( $user_id, $customer_id ) {
    global $wpdb;

    $user_id = absint( $user_id );
    $customer_id = absint( $customer_id );
    if ( $user_id < 1 || $customer_id < 1 ) {
        return false;
    }

    if ( ! bsn_clienti_table_has_column( 'wp_user_id' ) ) {
        return false;
    }

    $table = $wpdb->prefix . 'bs_clienti';
    $wpdb->update(
        $table,
        array( 'wp_user_id' => $user_id ),
        array( 'id' => $customer_id ),
        array( '%d' ),
        array( '%d' )
    );

    return true;
}

function bsn_get_current_public_customer_category() {
    if ( ! is_user_logged_in() ) {
        return 'standard';
    }

    $cliente = bsn_get_customer_from_wp_user( get_current_user_id() );
    if ( ! $cliente ) {
        return 'standard';
    }

    $categoria = sanitize_key( (string) ( $cliente['categoria_cliente'] ?? 'standard' ) );
    return $categoria !== '' ? $categoria : 'standard';
}

function bsn_get_public_customer_category_label( $categoria ) {
    $labels = array(
        'standard' => 'Guest / standard',
        'fidato' => 'Cliente fidato',
        'premium' => 'Cliente premium',
        'service' => 'Cliente service',
        'collaboratori' => 'Collaboratori',
    );

    $categoria = sanitize_key( (string) $categoria );
    return $labels[ $categoria ] ?? 'Guest / standard';
}

function bsn_upsert_frontend_customer_for_user( $user_id, $payload ) {
    global $wpdb;

    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return new WP_Error( 'bsn_frontend_user_missing', 'Utente non valido.' );
    }

    $table = $wpdb->prefix . 'bs_clienti';
    $nome = sanitize_text_field( (string) ( $payload['nome'] ?? '' ) );
    $cognome = sanitize_text_field( (string) ( $payload['cognome'] ?? '' ) );
    $cf_piva = sanitize_text_field( (string) ( $payload['cf_piva'] ?? '' ) );
    $telefono = sanitize_text_field( (string) ( $payload['telefono'] ?? '' ) );
    $email = sanitize_email( (string) ( $payload['email'] ?? '' ) );
    $indirizzo = sanitize_text_field( (string) ( $payload['indirizzo'] ?? '' ) );
    $cap = sanitize_text_field( (string) ( $payload['cap'] ?? '' ) );
    $citta = sanitize_text_field( (string) ( $payload['citta'] ?? '' ) );

    $can_link_by_wp = bsn_clienti_table_has_column( 'wp_user_id' );
    $cliente = $can_link_by_wp ? bsn_get_customer_from_wp_user( $user_id ) : null;
    if ( ! $cliente && $email !== '' ) {
        $cliente = bsn_get_customer_from_email( $email );
    }

    if ( ! $cliente && $cf_piva !== '' ) {
        $sql = $wpdb->prepare( "SELECT * FROM $table WHERE cf_piva = %s ORDER BY id DESC LIMIT 1", $cf_piva );
        $cliente = $wpdb->get_row( $sql, ARRAY_A );
    }

    $data = bsn_filter_clienti_table_data( array(
        'nome' => trim( $nome . ( $cognome !== '' ? ' ' . $cognome : '' ) ),
        'cognome' => $cognome,
        'cf_piva' => $cf_piva,
        'telefono' => $telefono,
        'indirizzo' => $indirizzo,
        'cap' => $cap,
        'citta' => $citta,
        'email' => $email,
        'categoria_cliente' => 'standard',
        'wp_user_id' => $user_id,
        'account_source' => 'frontend_wp',
        'data_registrazione_frontend' => current_time( 'mysql' ),
    ) );

    if ( $cliente ) {
        $customer_id = absint( $cliente['id'] );
        $updated = $wpdb->update( $table, $data, array( 'id' => $customer_id ) );
        if ( false === $updated ) {
            error_log( 'BSN frontend customer update error: ' . $wpdb->last_error );
            return new WP_Error( 'bsn_frontend_customer_update', 'Impossibile aggiornare il record cliente.' );
        }

        if ( $can_link_by_wp ) {
            bsn_link_user_to_customer( $user_id, $customer_id );
        }

        $cliente_collegato = bsn_get_customer_from_wp_user( $user_id );
        if ( ! $cliente_collegato ) {
            $sql = $wpdb->prepare( "SELECT * FROM $table WHERE id = %d LIMIT 1", $customer_id );
            $cliente_collegato = $wpdb->get_row( $sql, ARRAY_A );
        }

        return $cliente_collegato ?: new WP_Error( 'bsn_frontend_customer_link', 'Cliente aggiornato ma non collegato correttamente all\'account.' );
    }

    $result = $wpdb->insert( $table, $data );
    if ( ! $result ) {
        error_log( 'BSN frontend customer insert error: ' . $wpdb->last_error );
        return new WP_Error( 'bsn_frontend_customer_insert', 'Impossibile creare il record cliente.' );
    }

    $customer_id = absint( $wpdb->insert_id );
    if ( $customer_id > 0 && $can_link_by_wp ) {
        bsn_link_user_to_customer( $user_id, $customer_id );
    }

    $cliente_creato = bsn_get_customer_from_wp_user( $user_id );
    if ( ! $cliente_creato && $customer_id > 0 ) {
        $sql = $wpdb->prepare( "SELECT * FROM $table WHERE id = %d LIMIT 1", $customer_id );
        $cliente_creato = $wpdb->get_row( $sql, ARRAY_A );
    }
    if ( ! $cliente_creato && $email !== '' ) {
        $cliente_creato = bsn_get_customer_from_email( $email );
    }

    return $cliente_creato ?: new WP_Error( 'bsn_frontend_customer_link', 'Cliente creato ma non collegato correttamente all\'account.' );
}

function bsn_maybe_link_existing_customer_for_user( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id < 1 || bsn_get_customer_from_wp_user( $user_id ) ) {
        return;
    }

    $user = get_user_by( 'id', $user_id );
    if ( ! $user || empty( $user->user_email ) ) {
        return;
    }

    $cliente = bsn_get_customer_from_email( $user->user_email );
    if ( ! $cliente ) {
        return;
    }

    bsn_link_user_to_customer( $user_id, (int) $cliente['id'] );
}

function bsn_get_frontend_customer_payload_from_user( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return array();
    }

    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        return array();
    }

    $nome = (string) get_user_meta( $user_id, 'first_name', true );
    $cognome = (string) get_user_meta( $user_id, 'last_name', true );

    if ( $nome === '' ) {
        $nome = (string) ( $user->display_name ?: $user->user_login );
    }

    return array(
        'nome' => $nome,
        'cognome' => $cognome,
        'email' => (string) $user->user_email,
        'telefono' => (string) get_user_meta( $user_id, 'bsn_telefono', true ),
        'cf_piva' => (string) get_user_meta( $user_id, 'bsn_cf_piva', true ),
        'indirizzo' => (string) get_user_meta( $user_id, 'bsn_indirizzo', true ),
        'cap' => (string) get_user_meta( $user_id, 'bsn_cap', true ),
        'citta' => (string) get_user_meta( $user_id, 'bsn_citta', true ),
    );
}

function bsn_ensure_customer_for_wp_user( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return null;
    }

    $cliente = bsn_get_customer_from_wp_user( $user_id );
    if ( $cliente ) {
        return $cliente;
    }

    bsn_maybe_link_existing_customer_for_user( $user_id );
    $cliente = bsn_get_customer_from_wp_user( $user_id );
    if ( $cliente ) {
        return $cliente;
    }

    $payload = bsn_get_frontend_customer_payload_from_user( $user_id );
    if (
        empty( $payload['nome'] ) ||
        empty( $payload['email'] ) ||
        empty( $payload['telefono'] ) ||
        empty( $payload['cf_piva'] )
    ) {
        return null;
    }

    $cliente = bsn_upsert_frontend_customer_for_user( $user_id, $payload );
    return is_wp_error( $cliente ) ? null : $cliente;
}

function bsn_is_user_email_verified( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return false;
    }

    if ( ! metadata_exists( 'user', $user_id, 'bsn_email_verified' ) ) {
        return true;
    }

    return get_user_meta( $user_id, 'bsn_email_verified', true ) === '1';
}

function bsn_mark_user_email_verified( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return false;
    }

    update_user_meta( $user_id, 'bsn_email_verified', '1' );
    delete_user_meta( $user_id, 'bsn_email_verification_token' );
    delete_user_meta( $user_id, 'bsn_email_verification_expires' );
    delete_user_meta( $user_id, 'bsn_email_verification_sent_at' );

    return true;
}

function bsn_get_email_verification_link( $user_id, $token ) {
    $user_id = absint( $user_id );
    $token = sanitize_text_field( (string) $token );
    if ( $user_id < 1 || $token === '' ) {
        return '';
    }

    return add_query_arg(
        array(
            'bsn_verify_email' => '1',
            'uid' => $user_id,
            'token' => $token,
        ),
        bsn_get_register_page_url()
    );
}

function bsn_prepare_user_email_verification( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return new WP_Error( 'bsn_verify_invalid_user', 'Utente non valido per la verifica email.' );
    }

    $token = wp_generate_password( 48, false, false );
    $expires = time() + DAY_IN_SECONDS;

    update_user_meta( $user_id, 'bsn_email_verified', '0' );
    update_user_meta( $user_id, 'bsn_email_verification_token', wp_hash_password( $token ) );
    update_user_meta( $user_id, 'bsn_email_verification_expires', $expires );
    update_user_meta( $user_id, 'bsn_email_verification_sent_at', time() );

    return array(
        'token' => $token,
        'link' => bsn_get_email_verification_link( $user_id, $token ),
        'expires' => $expires,
    );
}

function bsn_send_frontend_verification_email( $user_id, $context = 'register' ) {
    $user_id = absint( $user_id );
    $user = get_user_by( 'id', $user_id );
    if ( ! $user || empty( $user->user_email ) ) {
        return new WP_Error( 'bsn_verify_user_missing', 'Impossibile inviare la verifica email: utente non trovato.' );
    }

    $verification = bsn_prepare_user_email_verification( $user_id );
    if ( is_wp_error( $verification ) ) {
        return $verification;
    }

    $display_name = trim( (string) $user->display_name );
    if ( $display_name === '' ) {
        $display_name = (string) $user->user_login;
    }

    $subject = $context === 'resend'
        ? 'Blackstar Noleggi - nuovo link di verifica email'
        : 'Blackstar Noleggi - verifica il tuo account';

    $message = '<p>Ciao ' . esc_html( $display_name ) . ',</p>';
    $message .= '<p>per attivare il tuo account Blackstar Noleggi conferma il tuo indirizzo email cliccando qui:</p>';
    $message .= '<p><a href="' . esc_url( $verification['link'] ) . '"><strong>Conferma la tua email</strong></a></p>';
    $message .= '<p>Il link resta valido per 24 ore. Se non hai richiesto tu la registrazione, ignora pure questa email.</p>';

    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    $sent = wp_mail( $user->user_email, $subject, $message, $headers );

    if ( ! $sent ) {
        return new WP_Error( 'bsn_verify_email_send', 'Non sono riuscito a inviare l\'email di verifica.' );
    }

    return true;
}

function bsn_get_frontend_captcha_challenge() {
    $a = wp_rand( 1, 9 );
    $b = wp_rand( 1, 9 );
    $rendered_at = time();
    $signature = hash_hmac( 'sha256', $a . '|' . $b . '|' . $rendered_at, wp_salt( 'nonce' ) . '|bsn_frontend_captcha' );

    return array(
        'a' => $a,
        'b' => $b,
        'rendered_at' => $rendered_at,
        'signature' => $signature,
        'label' => sprintf( 'Quanto fa %d + %d?', $a, $b ),
    );
}

function bsn_validate_frontend_registration_guard( $payload ) {
    $honeypot = sanitize_text_field( wp_unslash( $payload['website'] ?? '' ) );
    if ( $honeypot !== '' ) {
        return new WP_Error( 'bsn_frontend_bot', 'Invio non valido. Riprova.' );
    }

    $a = absint( $payload['bsn_captcha_a'] ?? 0 );
    $b = absint( $payload['bsn_captcha_b'] ?? 0 );
    $rendered_at = absint( $payload['bsn_captcha_rendered_at'] ?? 0 );
    $signature = sanitize_text_field( wp_unslash( $payload['bsn_captcha_signature'] ?? '' ) );
    $answer = trim( (string) wp_unslash( $payload['bsn_captcha_answer'] ?? '' ) );

    if ( $a < 1 || $b < 1 || $rendered_at < 1 || $signature === '' || $answer === '' ) {
        return new WP_Error( 'bsn_frontend_captcha_missing', 'Completa il controllo anti-bot per continuare.' );
    }

    $expected_signature = hash_hmac( 'sha256', $a . '|' . $b . '|' . $rendered_at, wp_salt( 'nonce' ) . '|bsn_frontend_captcha' );
    if ( ! hash_equals( $expected_signature, $signature ) ) {
        return new WP_Error( 'bsn_frontend_captcha_invalid', 'Controllo anti-bot non valido. Aggiorna la pagina e riprova.' );
    }

    $elapsed = time() - $rendered_at;
    if ( $elapsed < 3 ) {
        return new WP_Error( 'bsn_frontend_too_fast', 'Compilazione troppo rapida. Riprova con calma.' );
    }

    if ( $elapsed > DAY_IN_SECONDS ) {
        return new WP_Error( 'bsn_frontend_captcha_expired', 'La sessione del modulo e scaduta. Aggiorna la pagina e riprova.' );
    }

    if ( (int) $answer !== ( $a + $b ) ) {
        return new WP_Error( 'bsn_frontend_captcha_wrong', 'Il risultato del controllo anti-bot non e corretto.' );
    }

    return true;
}

function bsn_handle_frontend_email_verification() {
    if ( empty( $_GET['bsn_verify_email'] ) ) {
        return;
    }

    $user_id = absint( $_GET['uid'] ?? 0 );
    $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
    $redirect = bsn_get_login_page_url();

    if ( $user_id < 1 || $token === '' ) {
        bsn_set_frontend_auth_notice( 'error', 'Link di verifica non valido o incompleto.', 'login' );
        wp_safe_redirect( $redirect );
        exit;
    }

    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        bsn_set_frontend_auth_notice( 'error', 'Account non trovato per la verifica email.', 'login' );
        wp_safe_redirect( $redirect );
        exit;
    }

    if ( bsn_is_user_email_verified( $user_id ) ) {
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        bsn_set_frontend_auth_notice( 'success', 'Email gia verificata. Accesso effettuato correttamente.' );
        wp_safe_redirect( bsn_get_account_page_url() );
        exit;
    }

    $stored_hash = (string) get_user_meta( $user_id, 'bsn_email_verification_token', true );
    $expires = absint( get_user_meta( $user_id, 'bsn_email_verification_expires', true ) );
    $token_valid = $stored_hash !== '' && wp_check_password( $token, $stored_hash, $user_id );

    if ( ! $token_valid || ( $expires > 0 && time() > $expires ) ) {
        bsn_set_frontend_auth_notice( 'error', 'Il link di verifica non e piu valido. Richiedine uno nuovo dalla pagina di accesso.', 'login' );
        wp_safe_redirect( $redirect );
        exit;
    }

    bsn_mark_user_email_verified( $user_id );
    bsn_ensure_customer_for_wp_user( $user_id );
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true );
    bsn_set_frontend_auth_notice( 'success', 'Email verificata con successo. Il tuo account e ora attivo.' );
    wp_safe_redirect( bsn_get_account_page_url() );
    exit;
}
add_action( 'template_redirect', 'bsn_handle_frontend_email_verification', 5 );

function bsn_block_unverified_frontend_login( $user ) {
    if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
        return $user;
    }

    if ( bsn_is_user_email_verified( $user->ID ) ) {
        return $user;
    }

    return new WP_Error(
        'bsn_email_not_verified',
        'Devi verificare la tua email prima di accedere. Se serve, usa il reinvio del link di attivazione.'
    );
}
add_filter( 'wp_authenticate_user', 'bsn_block_unverified_frontend_login' );

function bsn_get_frontend_auth_notice() {
    static $notice_cache = null;
    static $notice_loaded = false;

    if ( ! function_exists( 'bsn_quote_cart_boot_session' ) || ! bsn_quote_cart_boot_session() ) {
        return null;
    }

    if ( ! $notice_loaded ) {
        $notice_cache = isset( $_SESSION['bsn_frontend_auth_notice'] ) ? $_SESSION['bsn_frontend_auth_notice'] : null;
        $notice_loaded = true;
    }

    unset( $_SESSION['bsn_frontend_auth_notice'] );

    return is_array( $notice_cache ) ? $notice_cache : null;
}

function bsn_set_frontend_auth_notice( $type, $message, $form = '', $data = array() ) {
    if ( ! function_exists( 'bsn_quote_cart_boot_session' ) || ! bsn_quote_cart_boot_session() ) {
        return;
    }

    $_SESSION['bsn_frontend_auth_notice'] = array(
        'type' => sanitize_key( $type ),
        'message' => (string) $message,
        'form' => sanitize_key( $form ),
        'data' => is_array( $data ) ? $data : array(),
    );
}

function bsn_get_login_page_url() {
    $page_id = get_option( 'bsn_login_page_id' );
    return $page_id && get_post( $page_id ) ? get_permalink( $page_id ) : home_url( '/accedi/' );
}

function bsn_get_register_page_url() {
    $page_id = get_option( 'bsn_register_page_id' );
    return $page_id && get_post( $page_id ) ? get_permalink( $page_id ) : home_url( '/registrati/' );
}

function bsn_get_account_page_url() {
    $page_id = get_option( 'bsn_account_page_id' );
    return $page_id && get_post( $page_id ) ? get_permalink( $page_id ) : home_url( '/account/' );
}

function bsn_ensure_frontend_account_page( $option_name, $slug, $title, $shortcode ) {
    $page_id = get_option( $option_name );
    if ( $page_id && get_post( $page_id ) ) {
        return;
    }

    $page = get_page_by_path( $slug );
    if ( $page ) {
        update_option( $option_name, $page->ID );
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $page_id = wp_insert_post(
        array(
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => $shortcode,
        )
    );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        update_option( $option_name, $page_id );
    }
}

function bsn_ensure_frontend_auth_pages() {
    bsn_ensure_frontend_account_page( 'bsn_login_page_id', 'accedi', 'Accedi', '[blackstar_login_cliente]' );
    bsn_ensure_frontend_account_page( 'bsn_register_page_id', 'registrati', 'Registrati', '[blackstar_registrazione_cliente]' );
    bsn_ensure_frontend_account_page( 'bsn_account_page_id', 'account', 'Account', '[blackstar_area_cliente]' );
}
add_action( 'init', 'bsn_ensure_frontend_auth_pages', 25 );
register_activation_hook( __FILE__, 'bsn_ensure_frontend_auth_pages' );

function bsn_handle_frontend_auth_forms() {
    if ( empty( $_POST['bsn_frontend_action'] ) || empty( $_POST['bsn_frontend_nonce'] ) ) {
        return;
    }

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsn_frontend_nonce'] ) ), 'bsn_frontend_auth' ) ) {
        bsn_set_frontend_auth_notice( 'error', 'Sessione non valida, aggiorna la pagina e riprova.' );
        return;
    }

    $action = sanitize_key( wp_unslash( $_POST['bsn_frontend_action'] ) );
    $redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
    if ( $redirect_to === '' ) {
        $redirect_to = wp_get_referer() ?: home_url( '/' );
    }

    if ( $action === 'register' ) {
        $nome = sanitize_text_field( wp_unslash( $_POST['nome'] ?? '' ) );
        $cognome = sanitize_text_field( wp_unslash( $_POST['cognome'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $telefono = sanitize_text_field( wp_unslash( $_POST['telefono'] ?? '' ) );
        $cf_piva = sanitize_text_field( wp_unslash( $_POST['cf_piva'] ?? '' ) );
        $password = (string) ( $_POST['password'] ?? '' );
        $indirizzo = sanitize_text_field( wp_unslash( $_POST['indirizzo'] ?? '' ) );
        $cap = sanitize_text_field( wp_unslash( $_POST['cap'] ?? '' ) );
        $citta = sanitize_text_field( wp_unslash( $_POST['citta'] ?? '' ) );

        $guard_check = bsn_validate_frontend_registration_guard( $_POST );
        if ( is_wp_error( $guard_check ) ) {
            bsn_set_frontend_auth_notice( 'error', $guard_check->get_error_message(), 'register', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        if ( $nome === '' || $email === '' || $telefono === '' || $cf_piva === '' || $password === '' ) {
            bsn_set_frontend_auth_notice( 'error', 'Compila tutti i campi obbligatori per completare la registrazione.', 'register', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        if ( email_exists( $email ) ) {
            bsn_set_frontend_auth_notice( 'error', 'Esiste gia un account con questa email. Usa la pagina di accesso.', 'register', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        $username = sanitize_user( current( explode( '@', $email ) ), true );
        if ( $username === '' ) {
            $username = 'cliente';
        }
        $base_username = $username;
        $counter = 1;
        while ( username_exists( $username ) ) {
            $counter++;
            $username = $base_username . $counter;
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            bsn_set_frontend_auth_notice( 'error', $user_id->get_error_message(), 'register', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        wp_update_user(
            array(
                'ID' => $user_id,
                'display_name' => trim( $nome . ' ' . $cognome ),
                'first_name' => $nome,
                'last_name' => $cognome,
            )
        );

        update_user_meta( $user_id, 'bsn_telefono', $telefono );
        update_user_meta( $user_id, 'bsn_cf_piva', $cf_piva );
        update_user_meta( $user_id, 'bsn_indirizzo', $indirizzo );
        update_user_meta( $user_id, 'bsn_cap', $cap );
        update_user_meta( $user_id, 'bsn_citta', $citta );

        $cliente = bsn_upsert_frontend_customer_for_user(
            $user_id,
            array(
                'nome' => $nome,
                'cognome' => $cognome,
                'email' => $email,
                'telefono' => $telefono,
                'cf_piva' => $cf_piva,
                'indirizzo' => $indirizzo,
                'cap' => $cap,
                'citta' => $citta,
            )
        );

        if ( is_wp_error( $cliente ) ) {
            wp_delete_user( $user_id );
            bsn_set_frontend_auth_notice( 'error', $cliente->get_error_message(), 'register', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        $verification_sent = bsn_send_frontend_verification_email( $user_id, 'register' );
        if ( is_wp_error( $verification_sent ) ) {
            bsn_set_frontend_auth_notice(
                'error',
                'Account creato, ma l\'email di verifica non e stata inviata. Contattaci o prova a reinviare il link dalla pagina di accesso.',
                'login'
            );
            wp_safe_redirect( bsn_get_login_page_url() );
            exit;
        }

        wp_logout();
        bsn_set_frontend_auth_notice( 'success', 'Registrazione completata. Ti abbiamo inviato un link di verifica via email: confermalo prima di accedere.', 'login' );
        wp_safe_redirect( bsn_get_login_page_url() );
        exit;
    }

    if ( $action === 'login' ) {
        $email_or_username = sanitize_text_field( wp_unslash( $_POST['email_or_username'] ?? '' ) );
        $password = (string) ( $_POST['password'] ?? '' );
        $remember = ! empty( $_POST['rememberme'] );

        if ( $email_or_username === '' || $password === '' ) {
            bsn_set_frontend_auth_notice( 'error', 'Inserisci email e password per accedere.', 'login', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        $login = $email_or_username;
        if ( is_email( $email_or_username ) ) {
            $user = get_user_by( 'email', $email_or_username );
            if ( $user ) {
                $login = $user->user_login;
            }
        }

        $user = wp_signon(
            array(
                'user_login' => $login,
                'user_password' => $password,
                'remember' => $remember,
            ),
            is_ssl()
        );

        if ( is_wp_error( $user ) ) {
            $message = $user->get_error_message();
            if ( $message === '' ) {
                $message = 'Accesso non riuscito. Controlla i dati e riprova.';
            }
            bsn_set_frontend_auth_notice( 'error', $message, 'login', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        bsn_ensure_customer_for_wp_user( $user->ID );
        bsn_set_frontend_auth_notice( 'success', 'Accesso effettuato correttamente.' );
        wp_safe_redirect( bsn_get_account_page_url() );
        exit;
    }

    if ( $action === 'resend_verification' ) {
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( $email === '' ) {
            bsn_set_frontend_auth_notice( 'error', 'Inserisci la tua email per ricevere un nuovo link di verifica.', 'login', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            bsn_set_frontend_auth_notice( 'error', 'Nessun account trovato con questa email.', 'login', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        if ( bsn_is_user_email_verified( $user->ID ) ) {
            bsn_set_frontend_auth_notice( 'success', 'Questo account risulta gia verificato. Puoi accedere subito.', 'login' );
            wp_safe_redirect( bsn_get_login_page_url() );
            exit;
        }

        $verification_sent = bsn_send_frontend_verification_email( $user->ID, 'resend' );
        if ( is_wp_error( $verification_sent ) ) {
            bsn_set_frontend_auth_notice( 'error', $verification_sent->get_error_message(), 'login', $_POST );
            wp_safe_redirect( $redirect_to );
            exit;
        }

        bsn_set_frontend_auth_notice( 'success', 'Ti abbiamo inviato un nuovo link di verifica via email.', 'login' );
        wp_safe_redirect( bsn_get_login_page_url() );
        exit;
    }
}
add_action( 'template_redirect', 'bsn_handle_frontend_auth_forms' );

function bsn_render_frontend_auth_notice( $form ) {
    $notice = bsn_get_frontend_auth_notice();
    if ( ! $notice || ( ! empty( $notice['form'] ) && $notice['form'] !== $form && $notice['type'] !== 'success' ) ) {
        return '';
    }

    $class = $notice['type'] === 'success' ? 'bsn-public-success-box' : 'bsn-public-warning-box';
    return '<div class="' . esc_attr( $class ) . '">' . esc_html( (string) $notice['message'] ) . '</div>';
}

function bsn_get_frontend_form_value( $form, $field ) {
    $notice = bsn_get_frontend_auth_notice();
    if ( ! is_array( $notice ) || ( $notice['form'] ?? '' ) !== $form || empty( $notice['data'][ $field ] ) ) {
        return '';
    }

    return is_scalar( $notice['data'][ $field ] ) ? (string) $notice['data'][ $field ] : '';
}

function bsn_shortcode_login_cliente() {
    if ( is_user_logged_in() ) {
        return '<div class="bsn-public-card"><p>Sei gia autenticato.</p><a class="bsn-public-btn" href="' . esc_url( bsn_get_account_page_url() ) . '">Vai al tuo account</a></div>';
    }

    ob_start();
    ?>
    <div class="bsn-public-page">
        <div class="bsn-public-shell bsn-auth-shell">
            <div class="bsn-public-card bsn-auth-card">
                <h2>Accedi al tuo account cliente</h2>
                <?php echo bsn_render_frontend_auth_notice( 'login' ); ?>
                <form method="post" class="bsn-auth-form">
                    <?php wp_nonce_field( 'bsn_frontend_auth', 'bsn_frontend_nonce' ); ?>
                    <input type="hidden" name="bsn_frontend_action" value="login">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr( bsn_get_login_page_url() ); ?>">
                    <label>Email o username
                        <input type="text" name="email_or_username" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'login', 'email_or_username' ) ); ?>" required>
                    </label>
                    <label>Password
                        <input type="password" name="password" required>
                    </label>
                    <label class="bsn-auth-checkbox">
                        <input type="checkbox" name="rememberme" value="1"> Ricordami su questo dispositivo
                    </label>
                    <button type="submit" class="bsn-public-btn">Accedi</button>
                </form>
                <form method="post" class="bsn-auth-form bsn-auth-resend-form">
                    <?php wp_nonce_field( 'bsn_frontend_auth', 'bsn_frontend_nonce' ); ?>
                    <input type="hidden" name="bsn_frontend_action" value="resend_verification">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr( bsn_get_login_page_url() ); ?>">
                    <label>Non hai ricevuto il link di verifica?
                        <input type="email" name="email" placeholder="Inserisci la tua email" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'login', 'email' ) ); ?>">
                    </label>
                    <button type="submit" class="bsn-public-btn bsn-public-btn-ghost">Reinvia link di verifica</button>
                </form>
                <p class="bsn-price-note">Non hai ancora un account? <a href="<?php echo esc_url( bsn_get_register_page_url() ); ?>">Registrati qui</a>.</p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'blackstar_login_cliente', 'bsn_shortcode_login_cliente' );

function bsn_shortcode_registrazione_cliente() {
    if ( is_user_logged_in() ) {
        return '<div class="bsn-public-card"><p>Hai gia un account attivo.</p><a class="bsn-public-btn" href="' . esc_url( bsn_get_account_page_url() ) . '">Vai al tuo account</a></div>';
    }

    $captcha = bsn_get_frontend_captcha_challenge();

    ob_start();
    ?>
    <div class="bsn-public-page">
        <div class="bsn-public-shell bsn-auth-shell">
            <div class="bsn-public-card bsn-auth-card">
                <h2>Crea il tuo account cliente</h2>
                <?php echo bsn_render_frontend_auth_notice( 'register' ); ?>
                <form method="post" class="bsn-auth-form bsn-auth-form-grid">
                    <?php wp_nonce_field( 'bsn_frontend_auth', 'bsn_frontend_nonce' ); ?>
                    <input type="hidden" name="bsn_frontend_action" value="register">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr( bsn_get_register_page_url() ); ?>">
                    <label>Nome
                        <input type="text" name="nome" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'register', 'nome' ) ); ?>" required>
                    </label>
                    <label>Cognome
                        <input type="text" name="cognome" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'register', 'cognome' ) ); ?>">
                    </label>
                    <label>Email
                        <input type="email" name="email" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'register', 'email' ) ); ?>" required>
                    </label>
                    <label>Telefono
                        <input type="text" name="telefono" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'register', 'telefono' ) ); ?>" required>
                    </label>
                    <label>CF / P.IVA
                        <input type="text" name="cf_piva" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'register', 'cf_piva' ) ); ?>" required>
                    </label>
                    <label>Password
                        <input type="password" name="password" required>
                    </label>
                    <label>Indirizzo
                        <input type="text" name="indirizzo" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'register', 'indirizzo' ) ); ?>">
                    </label>
                    <label>CAP
                        <input type="text" name="cap" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'register', 'cap' ) ); ?>">
                    </label>
                    <label>Citta
                        <input type="text" name="citta" value="<?php echo esc_attr( bsn_get_frontend_form_value( 'register', 'citta' ) ); ?>">
                    </label>
                    <div class="bsn-auth-form-full">
                        <div class="bsn-auth-guard-box">
                            <p class="bsn-price-note">Controllo rapido anti-bot: compila il risultato e lascia vuoto il campo nascosto.</p>
                            <input type="hidden" name="bsn_captcha_a" value="<?php echo esc_attr( $captcha['a'] ); ?>">
                            <input type="hidden" name="bsn_captcha_b" value="<?php echo esc_attr( $captcha['b'] ); ?>">
                            <input type="hidden" name="bsn_captcha_rendered_at" value="<?php echo esc_attr( $captcha['rendered_at'] ); ?>">
                            <input type="hidden" name="bsn_captcha_signature" value="<?php echo esc_attr( $captcha['signature'] ); ?>">
                            <label><?php echo esc_html( $captcha['label'] ); ?>
                                <input type="text" name="bsn_captcha_answer" inputmode="numeric" autocomplete="off" required>
                            </label>
                            <div class="bsn-auth-honeypot" aria-hidden="true" hidden style="display:none !important;">
                                <label>Sito web
                                    <input type="text" name="website" tabindex="-1" autocomplete="off">
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="bsn-auth-form-full">
                        <button type="submit" class="bsn-public-btn">Registrati</button>
                    </div>
                </form>
                <p class="bsn-price-note">Dopo la registrazione ti invieremo un link di verifica email. Solo dopo la conferma potrai accedere al tuo account.</p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'blackstar_registrazione_cliente', 'bsn_shortcode_registrazione_cliente' );

function bsn_get_customer_recent_noleggi( $cliente_id ) {
    global $wpdb;

    $cliente_id = absint( $cliente_id );
    if ( $cliente_id < 1 ) {
        return array();
    }

    $table = $wpdb->prefix . 'bs_noleggi';
    $sql = $wpdb->prepare(
        "SELECT id, stato, data_ritiro, data_riconsegna, totale_calcolato, articoli, origine, note
         FROM $table
         WHERE cliente_id = %d
         ORDER BY COALESCE(data_ritiro, data_inizio, data_richiesta) DESC
         LIMIT 12",
        $cliente_id
    );

    $rows = $wpdb->get_results( $sql, ARRAY_A );
    return is_array( $rows ) ? $rows : array();
}

function bsn_handle_frontend_account_actions() {
    if ( is_admin() || ! is_user_logged_in() ) {
        return;
    }

    if ( isset( $_GET['bsn_account_pdf'] ) ) {
        $noleggio_id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );
        $noleggio = bsn_get_customer_owned_noleggio( get_current_user_id(), $noleggio_id );

        if ( ! $noleggio ) {
            wp_die( esc_html__( 'Documento non disponibile per questo account.', 'blackstar-noleggi' ), '', array( 'response' => 403 ) );
        }

        $is_frontend_order = sanitize_key( (string) ( $noleggio['origine'] ?? '' ) ) === 'frontend';
        $use_preventivo_pdf = ( $noleggio['stato'] === 'preventivo' ) || ( $is_frontend_order && $noleggio['stato'] === 'bozza' );
        $pdf_info = $use_preventivo_pdf
            ? bsn_generate_preventivo_pdf( $noleggio['id'], true )
            : bsn_generate_noleggio_pdf( $noleggio['id'], true );

        if ( is_wp_error( $pdf_info ) || empty( $pdf_info['path'] ) || ! file_exists( $pdf_info['path'] ) ) {
            wp_die( esc_html__( 'Impossibile generare il PDF richiesto.', 'blackstar-noleggi' ), '', array( 'response' => 500 ) );
        }

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . basename( $pdf_info['path'] ) . '"' );
        header( 'Content-Length: ' . filesize( $pdf_info['path'] ) );
        readfile( $pdf_info['path'] );
        exit;
    }

    $action = sanitize_key( wp_unslash( $_POST['bsn_account_action'] ?? '' ) );
    if ( ! in_array( $action, array( 'delete_preventivo', 'edit_preventivo' ), true ) ) {
        return;
    }

    if ( ! isset( $_POST['bsn_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsn_account_nonce'] ) ), 'bsn_frontend_account_action' ) ) {
        bsn_set_frontend_auth_notice( 'error', 'Sessione non valida. Aggiorna la pagina e riprova.', 'success' );
        wp_safe_redirect( bsn_get_account_page_url() );
        exit;
    }

    $noleggio_id = sanitize_text_field( wp_unslash( $_POST['noleggio_id'] ?? '' ) );
    $noleggio = bsn_get_customer_owned_noleggio( get_current_user_id(), $noleggio_id );
    if ( ! $noleggio ) {
        bsn_set_frontend_auth_notice( 'error', 'Richiesta non trovata o non collegata al tuo account.', 'success' );
        wp_safe_redirect( bsn_get_account_page_url() );
        exit;
    }

    if ( ( $noleggio['stato'] ?? '' ) !== 'preventivo' ) {
        bsn_set_frontend_auth_notice( 'error', 'Puoi gestire dal sito solo richieste ancora in stato preventivo.', 'success' );
        wp_safe_redirect( bsn_get_account_page_url() );
        exit;
    }

    if ( $action === 'edit_preventivo' ) {
        if ( ! bsn_is_frontend_quote_noleggio( $noleggio ) ) {
            bsn_set_frontend_auth_notice( 'error', 'Questa richiesta non nasce dal catalogo pubblico e non puo essere ricaricata nel carrello online.', 'success' );
            wp_safe_redirect( bsn_get_account_page_url() );
            exit;
        }

        $cart = bsn_build_quote_cart_from_noleggio( $noleggio );
        if ( is_wp_error( $cart ) ) {
            bsn_set_frontend_auth_notice( 'error', $cart->get_error_message(), 'success' );
            wp_safe_redirect( bsn_get_account_page_url() );
            exit;
        }

        bsn_set_quote_cart( $cart );
        wp_safe_redirect( bsn_get_quote_cart_page_url() );
        exit;
    }

    $deleted = bsn_delete_noleggio_by_id( $noleggio['id'] );
    if ( is_wp_error( $deleted ) ) {
        bsn_set_frontend_auth_notice( 'error', $deleted->get_error_message(), 'success' );
    } else {
        bsn_set_frontend_auth_notice( 'success', 'Richiesta eliminata correttamente.', 'success' );
    }

    wp_safe_redirect( bsn_get_account_page_url() );
    exit;
}
add_action( 'template_redirect', 'bsn_handle_frontend_account_actions', 6 );

function bsn_shortcode_area_cliente() {
    if ( ! is_user_logged_in() ) {
        return '<div class="bsn-public-card"><p>Per vedere il tuo account devi prima accedere.</p><a class="bsn-public-btn" href="' . esc_url( bsn_get_login_page_url() ) . '">Accedi</a></div>';
    }

    $user = wp_get_current_user();
    $cliente = bsn_ensure_customer_for_wp_user( $user->ID );
    $noleggi = $cliente ? bsn_get_customer_recent_noleggi( (int) $cliente['id'] ) : array();

    ob_start();
    ?>
    <div class="bsn-public-page">
        <div class="bsn-public-shell bsn-auth-shell">
            <div class="bsn-public-card bsn-auth-card">
                <?php echo bsn_render_frontend_auth_notice( 'success' ); ?>
                <div class="bsn-auth-account-header">
                    <div>
                        <h2>Il tuo account</h2>
                        <p class="bsn-price-note">Bentornato, <?php echo esc_html( $user->display_name ?: $user->user_login ); ?>.</p>
                    </div>
                    <a class="bsn-public-btn bsn-public-btn-ghost" href="<?php echo esc_url( wp_logout_url( bsn_get_login_page_url() ) ); ?>">Esci</a>
                </div>

                <div class="bsn-auth-account-grid">
                    <div>
                        <h3>Dati accesso</h3>
                        <p><strong>Email:</strong> <?php echo esc_html( $user->user_email ); ?></p>
                        <p><strong>Categoria attiva:</strong> <?php echo esc_html( bsn_get_public_customer_category_label( bsn_get_current_public_customer_category() ) ); ?></p>
                    </div>
                    <div>
                        <h3>Dati cliente BS</h3>
                        <?php if ( $cliente ) : ?>
                            <p><strong>Ragione sociale / nominativo:</strong> <?php echo esc_html( $cliente['nome'] ); ?></p>
                            <p><strong>Telefono:</strong> <?php echo esc_html( $cliente['telefono'] ); ?></p>
                            <p><strong>CF / P.IVA:</strong> <?php echo esc_html( $cliente['cf_piva'] ); ?></p>
                            <p><strong>Citta:</strong> <?php echo esc_html( $cliente['citta'] ); ?></p>
                        <?php else : ?>
                            <p>I dati cliente verranno completati dal nostro staff se necessario.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bsn-auth-account-actions">
                    <a class="bsn-public-btn" href="<?php echo esc_url( get_post_type_archive_link( 'bs_prodotto' ) ); ?>">Vai al catalogo</a>
                    <a class="bsn-public-btn bsn-public-btn-ghost" href="<?php echo esc_url( bsn_get_quote_cart_page_url() ); ?>">Apri il carrello</a>
                </div>

                <div class="bsn-auth-account-list">
                    <h3>Le tue richieste e i tuoi noleggi</h3>
                    <?php if ( empty( $noleggi ) ) : ?>
                        <p class="bsn-price-note">Non risultano ancora richieste collegate a questo account.</p>
                    <?php else : ?>
                        <table class="bsn-tabella bsn-auth-account-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Stato</th>
                                    <th>Ritiro</th>
                                    <th>Riconsegna</th>
                                    <th>Totale</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $noleggi as $noleggio ) : ?>
                                    <?php
                                    $status = sanitize_key( (string) ( $noleggio['stato'] ?? '' ) );
                                    $status_label = bsn_get_public_noleggio_status_label( $status );
                                    $is_frontend_order = sanitize_key( (string) ( $noleggio['origine'] ?? '' ) ) === 'frontend';
                                    $pdf_url = add_query_arg(
                                        array(
                                            'bsn_account_pdf' => '1',
                                            'id' => $noleggio['id'],
                                        ),
                                        bsn_get_account_page_url()
                                    );
                                    $note_sections = bsn_parse_noleggio_note_sections( $noleggio['note'] ?? '' );
                                    $articoli = json_decode( (string) ( $noleggio['articoli'] ?? '' ), true );
                                    if ( ! is_array( $articoli ) ) {
                                        $articoli = array();
                                    }
                                    $service_snapshot = bsn_get_noleggio_service_snapshot( $noleggio );
                                    $service_summary_html = bsn_render_noleggio_service_summary_html(
                                        $service_snapshot,
                                        array(
                                            'context' => 'account',
                                            'title' => 'Servizio selezionato',
                                        )
                                    );
                                    $articoli_materiali = array_values(
                                        array_filter(
                                            $articoli,
                                            function ( $articolo ) {
                                                return empty( $articolo['service_internal'] );
                                            }
                                        )
                                    );
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $noleggio['id'] ); ?></td>
                                        <td><?php echo esc_html( $status_label ); ?></td>
                                        <td><?php echo esc_html( ! empty( $noleggio['data_ritiro'] ) ? mysql2date( 'd-m-Y', $noleggio['data_ritiro'] ) : '-' ); ?></td>
                                        <td><?php echo esc_html( ! empty( $noleggio['data_riconsegna'] ) ? mysql2date( 'd-m-Y', $noleggio['data_riconsegna'] ) : '-' ); ?></td>
                                        <td><?php echo esc_html( number_format_i18n( (float) ( $noleggio['totale_calcolato'] ?? 0 ), 2 ) ); ?> EUR</td>
                                        <td>
                                            <div class="bsn-auth-order-actions">
                                                <a class="bsn-public-btn bsn-public-btn-ghost" href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener noreferrer">PDF</a>
                                                <?php if ( $status === 'preventivo' && $is_frontend_order ) : ?>
                                                    <form method="post" class="bsn-auth-order-inline-form">
                                                        <?php wp_nonce_field( 'bsn_frontend_account_action', 'bsn_account_nonce' ); ?>
                                                        <input type="hidden" name="bsn_account_action" value="edit_preventivo">
                                                        <input type="hidden" name="noleggio_id" value="<?php echo esc_attr( $noleggio['id'] ); ?>">
                                                        <button type="submit" class="bsn-public-btn">Modifica richiesta</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ( $status === 'preventivo' ) : ?>
                                                    <form method="post" class="bsn-auth-order-inline-form" onsubmit="return window.confirm('Vuoi eliminare questa richiesta di preventivo?');">
                                                        <?php wp_nonce_field( 'bsn_frontend_account_action', 'bsn_account_nonce' ); ?>
                                                        <input type="hidden" name="bsn_account_action" value="delete_preventivo">
                                                        <input type="hidden" name="noleggio_id" value="<?php echo esc_attr( $noleggio['id'] ); ?>">
                                                        <button type="submit" class="bsn-public-btn bsn-public-btn-danger">Elimina richiesta</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                            <details class="bsn-auth-order-details">
                                                <summary>Dettagli</summary>
                                                <div class="bsn-auth-order-details-body">
                                                    <p><strong>Origine:</strong> <?php echo esc_html( bsn_get_noleggio_origine_label( $noleggio['origine'] ?? '' ) ); ?></p>
                                                    <?php if ( $note_sections['customer_note'] !== '' ) : ?>
                                                        <p><strong>Note cliente:</strong> <?php echo nl2br( esc_html( $note_sections['customer_note'] ) ); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ( $note_sections['system_note'] !== '' ) : ?>
                                                        <p><strong>Note richiesta:</strong> <?php echo nl2br( esc_html( $note_sections['system_note'] ) ); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ( $service_summary_html !== '' ) : ?>
                                                        <?php echo $service_summary_html; ?>
                                                    <?php endif; ?>
                                                    <div>
                                                        <strong>Materiali richiesti</strong>
                                                        <?php if ( empty( $articoli_materiali ) ) : ?>
                                                            <p class="bsn-price-note">Nessun materiale disponibile nel riepilogo.</p>
                                                        <?php else : ?>
                                                            <ul class="bsn-auth-order-items">
                                                                <?php foreach ( $articoli_materiali as $articolo ) : ?>
                                                                    <?php
                                                                    $articolo_qty = max( 1, intval( $articolo['qty'] ?? 1 ) );
                                                                    $articolo_label = (string) ( $articolo['public_product_title'] ?? $articolo['nome'] ?? '' );
                                                                    $articolo_code = trim( (string) ( $articolo['codice'] ?? '' ) );
                                                                    if ( $articolo_label === '' ) {
                                                                        $articolo_label = 'Articolo #' . intval( $articolo['id'] ?? 0 );
                                                                    }
                                                                    if ( $articolo_code !== '' ) {
                                                                        $articolo_label = '[' . $articolo_code . '] ' . $articolo_label;
                                                                    }
                                                                    ?>
                                                                    <li><?php echo esc_html( $articolo_qty . 'x ' . $articolo_label ); ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'blackstar_area_cliente', 'bsn_shortcode_area_cliente' );

function bsn_shortcode_conferma_preventivo() {
    $noleggio_id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );

    ob_start();
    ?>
    <div class="bsn-public-page">
        <div class="bsn-public-shell bsn-auth-shell">
            <div class="bsn-public-card bsn-auth-card bsn-quote-success-card">
                <div class="bsn-public-success-box">
                    <strong>Richiesta inviata correttamente.</strong><br>
                    Il nostro staff la controllera e ti ricontattera al piu presto.
                </div>
                <h2>Preventivo inviato</h2>
                <?php if ( $noleggio_id !== '' ) : ?>
                    <p><strong>Riferimento richiesta:</strong> <?php echo esc_html( $noleggio_id ); ?></p>
                <?php endif; ?>
                <p class="bsn-price-note">A breve riceverai via email il riepilogo della tua richiesta con il PDF allegato.</p>
                <p class="bsn-quote-success-warning"><strong>Il materiale non si considera confermato finche un operatore Black Star non completa la verifica.</strong></p>
                <div class="bsn-auth-account-actions">
                    <a class="bsn-public-btn" href="<?php echo esc_url( bsn_get_account_page_url() ); ?>">Vai al tuo account</a>
                    <a class="bsn-public-btn bsn-public-btn-ghost" href="<?php echo esc_url( get_post_type_archive_link( 'bs_prodotto' ) ); ?>">Torna al catalogo</a>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'blackstar_conferma_preventivo', 'bsn_shortcode_conferma_preventivo' );


// =====================================================================
// MODULO: MODALITÀ CLIENTE
// Permette all'operatore di navigare il frontend come un cliente
// specifico per creare preventivi/carrelli per conto suo.
// =====================================================================

define( 'BSN_MC_COOKIE', 'bsn_mc_token' );
define( 'BSN_MC_TTL',    4 * HOUR_IN_SECONDS );

// ===== MIGRAZIONE: tabella log sessioni =====
function bsn_ensure_modalita_cliente_log_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'bsn_modalita_cliente_log';
    $charset = $wpdb->get_charset_collate();

    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
        return;
    }

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        operator_user_id bigint(20) unsigned NOT NULL,
        customer_user_id bigint(20) unsigned NULL,
        cliente_id bigint(20) unsigned NOT NULL,
        started_at datetime NOT NULL,
        ended_at datetime NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        created_quote_id bigint(20) NULL,
        ip_address varchar(45) NULL,
        user_agent text NULL,
        PRIMARY KEY (id),
        KEY operator_user_id (operator_user_id),
        KEY cliente_id (cliente_id),
        KEY status (status)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
add_action( 'init', 'bsn_ensure_modalita_cliente_log_table', 30 );
register_activation_hook( __FILE__, 'bsn_ensure_modalita_cliente_log_table' );

// ===== HELPER: trovare/creare utente WP per un cliente gestionale =====
// Restituisce user_id (int) o WP_Error.
function bsn_get_or_create_wp_user_for_cliente( $cliente_id ) {
    global $wpdb;

    $cliente_id = absint( $cliente_id );
    if ( $cliente_id < 1 ) {
        return new WP_Error( 'bsn_mc_invalid_id', 'ID cliente non valido.' );
    }

    $table   = $wpdb->prefix . 'bs_clienti';
    $cliente = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d LIMIT 1", $cliente_id ), ARRAY_A );

    if ( ! $cliente ) {
        return new WP_Error( 'bsn_mc_not_found', 'Cliente non trovato.' );
    }

    // 1. Già collegato a un utente WP valido?
    if ( ! empty( $cliente['wp_user_id'] ) ) {
        $user_id = absint( $cliente['wp_user_id'] );
        if ( get_user_by( 'id', $user_id ) ) {
            return $user_id;
        }
        // user_id salvato ma l'utente WP non esiste più — continua per ricrearlo
    }

    // 2. Cerca per email un utente WP esistente
    $email = sanitize_email( $cliente['email'] ?? '' );
    if ( $email === '' ) {
        return new WP_Error(
            'bsn_mc_no_email',
            'Il cliente non ha un\'email valida. Aggiungi l\'email al cliente prima di usare Modalità cliente.'
        );
    }

    $existing_user = get_user_by( 'email', $email );
    if ( $existing_user ) {
        bsn_link_user_to_customer( $existing_user->ID, $cliente_id );
        update_user_meta( $existing_user->ID, 'bsn_cliente_id', $cliente_id );
        return $existing_user->ID;
    }

    // 3. Crea nuovo utente WordPress
    $nome         = sanitize_text_field( $cliente['nome'] ?? '' );
    $base_user    = sanitize_user( strtolower( strstr( $email, '@', true ) ), true );
    $base_user    = $base_user !== '' ? $base_user : 'cliente';
    $username     = $base_user;
    $counter      = 1;
    while ( username_exists( $username ) ) {
        $username = $base_user . $counter++;
    }

    $user_id = wp_insert_user( array(
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => wp_generate_password( 16, true, false ),
        'display_name' => $nome,
        'role'         => 'subscriber',
    ) );

    if ( is_wp_error( $user_id ) ) {
        return $user_id;
    }

    bsn_link_user_to_customer( $user_id, $cliente_id );
    update_user_meta( $user_id, 'bsn_cliente_id', $cliente_id );

    return $user_id;
}

// ===== LOG: apertura sessione =====
function bsn_mc_log_start( $operator_id, $customer_user_id, $cliente_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bsn_modalita_cliente_log';

    $wpdb->insert( $table, array(
        'operator_user_id' => absint( $operator_id ),
        'customer_user_id' => $customer_user_id ? absint( $customer_user_id ) : null,
        'cliente_id'       => absint( $cliente_id ),
        'started_at'       => current_time( 'mysql' ),
        'status'           => 'active',
        'ip_address'       => sanitize_text_field( substr( $_SERVER['REMOTE_ADDR'] ?? '', 0, 45 ) ),
        'user_agent'       => sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250 ) ),
    ), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' ) );

    return (int) $wpdb->insert_id;
}

// ===== LOG: chiusura sessione =====
function bsn_mc_log_end( $log_id ) {
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'bsn_modalita_cliente_log',
        array( 'ended_at' => current_time( 'mysql' ), 'status' => 'closed' ),
        array( 'id' => absint( $log_id ) ),
        array( '%s', '%s' ),
        array( '%d' )
    );
}

// ===== HELPER: legge la sessione attiva dal cookie =====
function bsn_mc_get_session() {
    $token = isset( $_COOKIE[ BSN_MC_COOKIE ] ) ? sanitize_text_field( $_COOKIE[ BSN_MC_COOKIE ] ) : '';
    if ( $token === '' ) return null;

    $session = get_transient( 'bsn_mc_' . $token );
    return ( $session && is_array( $session ) ) ? $session : null;
}

// ===== HELPER: nome cliente =====
function bsn_mc_get_cliente_nome( $cliente_id ) {
    global $wpdb;
    $nome = $wpdb->get_var( $wpdb->prepare(
        "SELECT nome FROM {$wpdb->prefix}bs_clienti WHERE id = %d LIMIT 1",
        absint( $cliente_id )
    ) );
    return $nome ? esc_html( $nome ) : 'Cliente #' . absint( $cliente_id );
}

// ===== ENDPOINT REST: avvia modalità cliente =====
add_action( 'rest_api_init', function () {
    register_rest_route( 'bsn/v1', '/modalita-cliente/avvia', array(
        'methods'             => 'POST',
        'callback'            => 'bsn_api_mc_avvia',
        'permission_callback' => 'bsn_check_admin',
    ) );
    register_rest_route( 'bsn/v1', '/modalita-cliente/esci', array(
        'methods'             => 'POST',
        'callback'            => 'bsn_api_mc_esci',
        'permission_callback' => '__return_true',
    ) );
} );

// Trova l'URL della pagina gestionale cercando lo shortcode [blackstar_noleggi]
function bsn_mc_get_gestionale_url() {
    global $wpdb;
    $page_id = $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type = 'page'
         AND post_content LIKE '%blackstar_noleggi%'
         LIMIT 1"
    );
    return $page_id ? get_permalink( $page_id ) : home_url( '/app-noleggi/' );
}

function bsn_api_mc_avvia( WP_REST_Request $request ) {
    $params     = $request->get_json_params() ?: array();
    $cliente_id = absint( $params['cliente_id'] ?? 0 );

    if ( $cliente_id < 1 ) {
        return new WP_Error( 'bsn_mc_bad', 'ID cliente mancante.', array( 'status' => 400 ) );
    }

    $operator_id = get_current_user_id();

    $customer_user_id = bsn_get_or_create_wp_user_for_cliente( $cliente_id );
    if ( is_wp_error( $customer_user_id ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => $customer_user_id->get_error_message(),
        ), 400 );
    }

    // Token casuale sicuro
    $token = wp_generate_password( 40, false, false );

    // Salva log
    $log_id = bsn_mc_log_start( $operator_id, $customer_user_id, $cliente_id );

    // Sessione nel transient (server-side)
    set_transient( 'bsn_mc_' . $token, array(
        'token'            => $token,
        'operator_user_id' => $operator_id,
        'customer_user_id' => $customer_user_id,
        'cliente_id'       => $cliente_id,
        'log_id'           => $log_id,
        'started_at'       => time(),
        'gestionale_url'   => bsn_mc_get_gestionale_url(),
    ), BSN_MC_TTL );

    // Cookie nel browser
    $cookie_params = array(
        'expires'  => time() + BSN_MC_TTL,
        'path'     => COOKIEPATH,
        'domain'   => COOKIE_DOMAIN,
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    );
    setcookie( BSN_MC_COOKIE, $token, $cookie_params );
    $_COOKIE[ BSN_MC_COOKIE ] = $token;

    // Switch sessione WP: l'operatore naviga come il cliente
    wp_clear_auth_cookie();
    wp_set_auth_cookie( $customer_user_id, false );
    wp_set_current_user( $customer_user_id );

    $catalog_url = get_post_type_archive_link( 'bs_prodotto' ) ?: home_url( '/' );

    return new WP_REST_Response( array(
        'success'      => true,
        'redirect_url' => $catalog_url,
        'cliente_nome' => bsn_mc_get_cliente_nome( $cliente_id ),
    ), 200 );
}

// bsn_api_mc_esci è mantenuto per compatibilità REST ma la logica principale
// è ora in bsn_handle_mc_esci_post (admin-post.php, più affidabile per i cookie).
function bsn_api_mc_esci( WP_REST_Request $request ) {
    // Redirect al handler PHP affidabile — il fetch JS non è il flusso principale
    return new WP_REST_Response( array(
        'success'      => false,
        'redirect_url' => wp_nonce_url( admin_url( 'admin-post.php?action=bsn_mc_esci' ), 'bsn_mc_esci' ),
        'message'      => 'Usa il pulsante del banner.',
    ), 200 );
}

// ===== USCITA MODALITÀ CLIENTE via admin-post.php (flusso PHP affidabile) =====
// Funziona per utenti loggati (come cliente) e ripristina l'operatore originale.
add_action( 'admin_post_bsn_mc_esci',        'bsn_handle_mc_esci_post' );
add_action( 'admin_post_nopriv_bsn_mc_esci', 'bsn_handle_mc_esci_post' );

function bsn_handle_mc_esci_post() {
    if ( ! check_admin_referer( 'bsn_mc_esci' ) ) {
        wp_die( 'Token non valido. <a href="' . esc_url( home_url() ) . '">Torna al sito</a>.' );
    }

    $token   = isset( $_COOKIE[ BSN_MC_COOKIE ] ) ? sanitize_text_field( $_COOKIE[ BSN_MC_COOKIE ] ) : '';
    $session = bsn_mc_get_session();
    $redirect = isset( $session['gestionale_url'] ) && $session['gestionale_url']
        ? $session['gestionale_url']
        : bsn_mc_get_gestionale_url();

    if ( $session ) {
        $operator_id = absint( $session['operator_user_id'] );
        $log_id      = absint( $session['log_id'] ?? 0 );

        // Chiudi log
        if ( $log_id > 0 ) {
            bsn_mc_log_end( $log_id );
        }

        // Elimina transient sessione
        if ( $token !== '' ) {
            delete_transient( 'bsn_mc_' . $token );
        }

        // Cancella cookie modalità cliente
        setcookie( BSN_MC_COOKIE, '', array(
            'expires'  => time() - 3600,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ) );
        unset( $_COOKIE[ BSN_MC_COOKIE ] );

        // Ripristina l'operatore originale
        wp_clear_auth_cookie();
        if ( $operator_id > 0 && get_user_by( 'id', $operator_id ) ) {
            wp_set_auth_cookie( $operator_id, false );
        }
    }

    // Redirect diretto al gestionale — a questo punto i cookie sono impostati correttamente
    wp_redirect( $redirect );
    exit;
}

// ===== BANNER FRONTEND: barra rossa visibile durante la sessione =====
// Nasconde anche l'admin bar WP per non coprire il banner
add_filter( 'show_admin_bar', 'bsn_mc_hide_admin_bar_during_session' );
function bsn_mc_hide_admin_bar_during_session( $show ) {
    if ( ! is_admin() && bsn_mc_get_session() ) {
        return false;
    }
    return $show;
}

add_action( 'wp_footer', 'bsn_mc_render_banner' );
function bsn_mc_render_banner() {
    if ( is_admin() ) return;

    $session = bsn_mc_get_session();
    if ( ! $session ) return;

    $cliente_nome = bsn_mc_get_cliente_nome( $session['cliente_id'] );
    // URL di uscita: form POST verso admin-post.php con nonce
    $exit_url = esc_url( wp_nonce_url(
        admin_url( 'admin-post.php?action=bsn_mc_esci' ),
        'bsn_mc_esci'
    ) );
    ?>
    <div id="bsn-mc-banner" style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#c0392b;color:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;font-size:14px;display:flex;align-items:center;justify-content:space-between;padding:8px 20px;box-shadow:0 2px 8px rgba(0,0,0,0.35);">
        <span>🔴 <strong>Modalità cliente attiva:</strong> stai operando per conto di <strong><?php echo $cliente_nome; ?></strong>. Carrello e preventivi saranno intestati a questo cliente.</span>
        <a href="<?php echo $exit_url; ?>"
           id="bsn-mc-esci-btn"
           style="background:#fff;color:#c0392b;border:2px solid #c0392b;border-radius:4px;padding:5px 14px;font-weight:bold;cursor:pointer;font-size:13px;white-space:nowrap;margin-left:20px;flex-shrink:0;text-decoration:none;display:inline-block;">
            Esci dalla modalità cliente
        </a>
    </div>
    <style>body { margin-top: 41px !important; padding-top: 0 !important; }</style>
    <?php
}

// ===== SUBMENU ADMIN: log sessioni (solo amministratori) =====
add_action( 'admin_menu', function () {
    add_submenu_page(
        'bs-noleggi',
        'Log Modalità Cliente',
        '👤 Log Mod. Cliente',
        'manage_options',
        'bs-noleggi-mc-log',
        'bsn_render_mc_log_page'
    );
}, 20 );

function bsn_render_mc_log_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Accesso negato.' );
    }

    global $wpdb;
    $table_log     = $wpdb->prefix . 'bsn_modalita_cliente_log';
    $table_clienti = $wpdb->prefix . 'bs_clienti';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_log'" ) !== $table_log ) {
        bsn_ensure_modalita_cliente_log_table();
        echo '<div class="wrap"><h1>Log Modalità Cliente</h1><p>Tabella appena creata. Ricarica la pagina.</p></div>';
        return;
    }

    $rows = $wpdb->get_results( "
        SELECT l.*, c.nome AS cliente_nome, c.email AS cliente_email
        FROM {$table_log} l
        LEFT JOIN {$table_clienti} c ON c.id = l.cliente_id
        ORDER BY l.id DESC LIMIT 200
    ", ARRAY_A );
    ?>
    <div class="wrap">
        <h1>Log Modalità Cliente</h1>
        <p class="description">Registro delle sessioni operatore. Visibile solo agli amministratori.</p>
        <table class="widefat striped" style="margin-top:15px;">
            <thead>
                <tr>
                    <th>#</th><th>Operatore</th><th>Cliente impersonato</th>
                    <th>Inizio</th><th>Fine</th><th>Durata</th><th>Stato</th><th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="8"><em>Nessuna sessione registrata.</em></td></tr>
            <?php else : foreach ( $rows as $r ) :
                $op    = get_user_by( 'id', $r['operator_user_id'] );
                $op_l  = $op ? esc_html( $op->display_name ) . ' <small>(' . esc_html( $op->user_email ) . ')</small>' : 'Utente #' . esc_html( $r['operator_user_id'] );
                $cl_l  = esc_html( $r['cliente_nome'] ?? 'Cliente #' . $r['cliente_id'] ) . ( $r['cliente_email'] ? ' — ' . esc_html( $r['cliente_email'] ) : '' );
                $t_s   = $r['started_at'] ? date_i18n( 'd-m-Y H:i', strtotime( $r['started_at'] ) ) : '-';
                $t_e   = $r['ended_at']   ? date_i18n( 'd-m-Y H:i', strtotime( $r['ended_at'] ) )   : '-';
                $dur   = '-';
                if ( $r['started_at'] && $r['ended_at'] ) {
                    $s   = strtotime( $r['ended_at'] ) - strtotime( $r['started_at'] );
                    $dur = floor( $s / 60 ) . 'min ' . ( $s % 60 ) . 's';
                } elseif ( $r['started_at'] ) {
                    $s   = time() - strtotime( $r['started_at'] );
                    $dur = '<em style="color:#c00;">In corso — ' . floor( $s / 60 ) . 'min</em>';
                }
                $col = $r['status'] === 'active' ? '#c0392b' : '#27ae60';
            ?>
                <tr>
                    <td><?php echo esc_html( $r['id'] ); ?></td>
                    <td><?php echo $op_l; ?></td>
                    <td><?php echo $cl_l; ?></td>
                    <td><?php echo esc_html( $t_s ); ?></td>
                    <td><?php echo esc_html( $t_e ); ?></td>
                    <td><?php echo $dur; ?></td>
                    <td><strong style="color:<?php echo $col; ?>"><?php echo esc_html( strtoupper( $r['status'] ) ); ?></strong></td>
                    <td><?php echo esc_html( $r['ip_address'] ?? '-' ); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// =====================================================================
// FINE MODULO MODALITÀ CLIENTE
// =====================================================================
