<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

if ( ! function_exists( 'bsn_public_product_compare_text' ) ) {
    function bsn_public_product_compare_text( $text ) {
        $text = wp_strip_all_tags( (string) $text );
        $text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
        $text = strtolower( remove_accents( $text ) );
        $text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }
}

if ( ! function_exists( 'bsn_public_product_texts_are_similar' ) ) {
    function bsn_public_product_texts_are_similar( $first, $second ) {
        $first_normalized  = bsn_public_product_compare_text( $first );
        $second_normalized = bsn_public_product_compare_text( $second );

        if ( $first_normalized === '' || $second_normalized === '' ) {
            return false;
        }

        if ( $first_normalized === $second_normalized ) {
            return true;
        }

        similar_text( $first_normalized, $second_normalized, $percent );
        return $percent >= 88;
    }
}

if ( ! function_exists( 'bsn_public_product_remove_internal_lines' ) ) {
    function bsn_public_product_remove_internal_lines( $text ) {
        $lines = preg_split( '/\R+/', (string) $text );
        $safe_lines = [];

        foreach ( is_array( $lines ) ? $lines : [] as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }

            if ( preg_match( '/(articolo\s+gestionale|gestionale\s+[A-Z0-9_-]+\s+collegato|collegato\s+a\s+questa\s+scheda|prodotto_pubblico|database|migrazione|agganciat[oi])/iu', $line ) ) {
                continue;
            }

            $safe_lines[] = $line;
        }

        return trim( implode( "\n", $safe_lines ) );
    }
}

while ( have_posts() ) :
    the_post();

    $product_id = get_the_ID();
    $meta = bsn_get_public_product_meta( $product_id );
    $gallery_urls = bsn_get_public_product_gallery_urls( $product_id );
    $video_embeds = bsn_get_public_product_video_embeds( $product_id );
    $data_ritiro = isset( $_GET['data_ritiro'] ) ? sanitize_text_field( wp_unslash( $_GET['data_ritiro'] ) ) : '';
    $data_riconsegna = isset( $_GET['data_riconsegna'] ) ? sanitize_text_field( wp_unslash( $_GET['data_riconsegna'] ) ) : '';
    $quote_cart = function_exists( 'bsn_get_quote_cart' ) ? bsn_get_quote_cart() : [ 'dates' => [] ];
    if ( $data_ritiro === '' && ! empty( $quote_cart['dates']['data_ritiro'] ) ) {
        $data_ritiro = sanitize_text_field( (string) $quote_cart['dates']['data_ritiro'] );
    }
    if ( $data_riconsegna === '' && ! empty( $quote_cart['dates']['data_riconsegna'] ) ) {
        $data_riconsegna = sanitize_text_field( (string) $quote_cart['dates']['data_riconsegna'] );
    }
    $current_rental_dates = function_exists( 'bsn_validate_public_rental_dates' )
        ? bsn_validate_public_rental_dates( $data_ritiro, $data_riconsegna )
        : [ 'valid' => false ];
    $quote_cart_url = function_exists( 'bsn_get_quote_cart_page_url' ) ? bsn_get_quote_cart_page_url() : home_url( '/carrello-noleggio/' );
    $catalog_url = get_post_type_archive_link( 'bs_prodotto' );
    if ( function_exists( 'bsn_append_public_rental_dates_to_url' ) ) {
        $catalog_url = bsn_append_public_rental_dates_to_url( $catalog_url, $current_rental_dates );
        $quote_cart_url = bsn_append_public_rental_dates_to_url( $quote_cart_url, $current_rental_dates );
    }
    $availability_html = bsn_render_public_product_availability_html( $product_id, $data_ritiro, $data_riconsegna, [
        'title' => 'Disponibilità del prodotto',
    ] );
    $customer_category = function_exists( 'bsn_get_current_public_customer_category' ) ? bsn_get_current_public_customer_category() : 'standard';
    $customer_category_label = function_exists( 'bsn_get_public_customer_category_label' ) ? bsn_get_public_customer_category_label( $customer_category ) : 'Guest / standard';
    $price_standard = bsn_get_public_product_price_from( $product_id, 'standard' );
    $price_reserved = $customer_category !== 'standard' ? bsn_get_public_product_price_from( $product_id, $customer_category ) : null;
    $tariffa_label = function_exists( 'bsn_get_public_product_tariff_label' ) ? bsn_get_public_product_tariff_label( $product_id ) : 'Tariffa 1 giorno';
    $display_price_label = $tariffa_label;
    $show_standard_price = $price_standard !== null && $price_reserved !== null && $price_reserved < $price_standard;
    $display_price = $show_standard_price ? $price_reserved : $price_standard;
    $display_standard = $price_standard;
    $price_range = function_exists( 'bsn_get_public_product_quantity_discount_price_range' )
        ? bsn_get_public_product_quantity_discount_price_range( $product_id, $customer_category )
        : null;
    $show_price_range = is_array( $price_range ) && empty( $current_rental_dates['valid'] );
    if ( ! empty( $current_rental_dates['valid'] ) && function_exists( 'bsn_get_public_product_quote_preview' ) ) {
        $quote_preview = bsn_get_public_product_quote_preview(
            $product_id,
            1,
            $current_rental_dates['data_ritiro'],
            $current_rental_dates['data_riconsegna'],
            $customer_category
        );

        if ( ! empty( $quote_preview['success'] ) ) {
            $display_price = (float) $quote_preview['totale_stimato'];
            $display_standard = null;
            $show_standard_price = false;
            $show_price_range = false;
            if ( $display_price_label === 'Tariffa 1 giorno' ) {
                $display_price_label = 'Stima periodo';
            }
        }
    }
    $articoli_collegati = bsn_get_public_product_articles( $product_id );
    $min_qty = 1;
    foreach ( $articoli_collegati as $articolo_collegato ) {
        $min_qty = max( $min_qty, intval( $articolo_collegato['min_qty'] ?? 1 ) );
    }
    $dimension_config = function_exists( 'bsn_get_dimension_config' ) ? bsn_get_dimension_config( $product_id ) : [ 'enabled' => false ];
    $dimension_type = (string) ( $dimension_config['calculation_type'] ?? 'standard' );
    $dimension_enabled = ! empty( $dimension_config['enabled'] ) && in_array( $dimension_type, [ 'dimensionale_modulare', 'dimensionale_mq' ], true );
    $dimension_presets = $dimension_enabled && function_exists( 'bsn_parse_dimension_presets' )
        ? bsn_parse_dimension_presets( $dimension_config['presets'] ?? '' )
        : [];
    $dimension_public_config = [
        'enabled' => $dimension_enabled,
        'calculation_type' => $dimension_type,
        'module_width_cm' => (float) ( $dimension_config['module_width_cm'] ?? 0 ),
        'module_height_cm' => (float) ( $dimension_config['module_height_cm'] ?? 0 ),
        'customer_note' => (string) ( $dimension_config['customer_note'] ?? '' ),
        'presets' => $dimension_presets,
    ];
    $categorie          = get_the_terms( $product_id, 'bs_categoria_prodotto' );
    $consigliati_posts  = function_exists( 'bsn_get_consigliati_products' ) ? bsn_get_consigliati_products( $product_id ) : [];
    $product_downloads  = function_exists( 'bsn_get_product_downloads' )    ? bsn_get_product_downloads( $product_id )    : [];
    $subtitle_text      = trim( (string) ( $meta['sottotitolo_catalogo'] ?? '' ) );
    $excerpt_text       = has_excerpt() ? trim( (string) get_the_excerpt() ) : '';
    $show_excerpt       = $excerpt_text !== '' && ! bsn_public_product_texts_are_similar( $subtitle_text, $excerpt_text );
    $utilizzo_consigliato = bsn_public_product_remove_internal_lines( $meta['utilizzo_consigliato'] ?? '' );
    $specifiche_pubbliche = bsn_public_product_remove_internal_lines( $meta['specifiche_tecniche'] ?? '' );
    $faq_pubbliche        = bsn_public_product_remove_internal_lines( $meta['faq'] ?? '' );
    $tipo_download_labels = [
        'manuale'          => 'Manuale',
        'scheda_tecnica'   => 'Scheda tecnica',
        'scheda_sicurezza' => 'Scheda sicurezza',
        'software'         => 'Software',
        ''                 => 'Download',
    ];
    ?>
    <main class="bsn-public-page bsn-prodotto-page">
        <div class="bsn-public-shell">
            <!-- Inline SVG icon sprite (chip logistica) — added 2026-05 -->
            <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
                <symbol id="ic-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></symbol>
                <symbol id="ic-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/></symbol>
                <symbol id="ic-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></symbol>
            </svg>
            <nav class="bsn-breadcrumbs">
                <a href="<?php echo esc_url( $catalog_url ); ?>">Catalogo noleggio</a>
                <span>/</span>
                <span><?php the_title(); ?></span>
            </nav>

            <article <?php post_class( 'bsn-prodotto-layout' ); ?>>
                <div class="bsn-prodotto-hero">
                    <div class="bsn-prodotto-media">
                        <?php if ( ! empty( $gallery_urls ) ) : ?>
                            <div class="bsn-prodotto-main-image">
                                <img id="bsn-gallery-main-img" src="<?php echo esc_url( $gallery_urls[0] ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>">
                            </div>
                            <?php if ( count( $gallery_urls ) > 1 ) : ?>
                                <div class="bsn-prodotto-thumb-grid">
                                    <?php foreach ( $gallery_urls as $thumb_idx => $gallery_url ) : ?>
                                        <img class="bsn-thumb-item<?php echo $thumb_idx === 0 ? ' bsn-thumb-active' : ''; ?>"
                                             src="<?php echo esc_url( $gallery_url ); ?>"
                                             data-src="<?php echo esc_url( $gallery_url ); ?>"
                                             alt="<?php echo esc_attr( get_the_title() ); ?> - immagine <?php echo esc_attr( $thumb_idx + 1 ); ?>"
                                             tabindex="0"
                                             role="button"
                                             aria-label="Visualizza immagine <?php echo esc_attr( $thumb_idx + 1 ); ?>">
                                    <?php endforeach; ?>
                                </div>
                                <script>
                                (function () {
                                    var mainImg = document.getElementById('bsn-gallery-main-img');
                                    var thumbs  = document.querySelectorAll('.bsn-thumb-item');
                                    if (!mainImg || !thumbs.length) return;

                                    function activateThumb(thumb) {
                                        thumbs.forEach(function (t) { t.classList.remove('bsn-thumb-active'); });
                                        thumb.classList.add('bsn-thumb-active');
                                        var newSrc = thumb.getAttribute('data-src');
                                        mainImg.style.opacity = '0';
                                        setTimeout(function () {
                                            mainImg.src = newSrc;
                                            mainImg.style.opacity = '1';
                                        }, 200);
                                    }

                                    thumbs.forEach(function (thumb) {
                                        thumb.addEventListener('click', function () { activateThumb(this); });
                                        thumb.addEventListener('keydown', function (e) {
                                            if (e.key === 'Enter' || e.key === ' ') {
                                                e.preventDefault();
                                                activateThumb(this);
                                            }
                                        });
                                    });
                                })();
                                </script>
                            <?php endif; ?>
                        <?php else : ?>
                            <div class="bsn-prodotto-media-placeholder">Immagini in aggiornamento</div>
                        <?php endif; ?>

                        <?php if ( ! empty( $video_embeds ) ) : ?>
                            <div class="bsn-prodotto-video-list">
                                <h3>Video utili</h3>
                                <div class="bsn-video-embed-grid">
                                    <?php foreach ( $video_embeds as $index => $video_item ) : ?>
                                        <div class="bsn-video-embed-card">
                                            <?php if ( ! empty( $video_item['html'] ) ) : ?>
                                                <?php echo $video_item['html']; ?>
                                            <?php else : ?>
                                                <a href="<?php echo esc_url( $video_item['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php echo esc_html( 'Apri video ' . ( $index + 1 ) ); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="bsn-prodotto-summary">
                        <?php if ( ! empty( $categorie ) && ! is_wp_error( $categorie ) ) : ?>
                            <div class="bsn-chip-row">
                                <?php foreach ( $categorie as $categoria ) : ?>
                                    <?php
                                    $categoria_url = get_term_link( $categoria );
                                    if ( ! is_wp_error( $categoria_url ) && function_exists( 'bsn_append_public_rental_dates_to_url' ) ) {
                                        $categoria_url = bsn_append_public_rental_dates_to_url( $categoria_url, $current_rental_dates );
                                    }
                                    ?>
                                    <a class="bsn-chip" href="<?php echo esc_url( $categoria_url ); ?>">
                                        <?php echo esc_html( $categoria->name ); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <h1 class="bsn-prodotto-title"><?php the_title(); ?></h1>

                        <?php if ( $subtitle_text !== '' ) : ?>
                            <p class="bsn-prodotto-subtitle"><?php echo esc_html( $subtitle_text ); ?></p>
                        <?php endif; ?>

                        <?php if ( $show_excerpt ) : ?>
                            <div class="bsn-prodotto-excerpt"><?php echo wp_kses_post( wpautop( $excerpt_text ) ); ?></div>
                        <?php endif; ?>

                        <div class="bsn-prodotto-price-box">
                            <div class="bsn-price-label"><?php echo esc_html( $display_price_label ); ?></div>
                            <?php if ( $show_price_range && is_array( $price_range ) ) : ?>
                                <div class="bsn-price-main"><?php echo esc_html( number_format_i18n( (float) $price_range['max_price'], 2 ) ); ?> - <?php echo esc_html( number_format_i18n( (float) $price_range['min_price'], 2 ) ); ?> EUR <small class="bsn-vat-note-inline">+ IVA</small></div>
                                <div class="bsn-price-note">Per noleggi pi&ugrave; lunghi il costo medio si riduce con il <b>calcolo scalare</b>.</div>
                            <?php elseif ( null !== $display_price ) : ?>
                                <?php if ( $show_standard_price && null !== $display_standard ) : ?>
                                    <div class="bsn-price-main"><?php echo esc_html( number_format_i18n( $display_price, 2 ) ); ?> EUR <small class="bsn-vat-note-inline">+ IVA</small></div>
                                    <div class="bsn-price-strikethrough">Tariffa standard: <?php echo esc_html( number_format_i18n( $display_standard, 2 ) ); ?> EUR</div>
                                    <div class="bsn-price-note">Prezzo riservato <?php echo esc_html( $customer_category_label ); ?>. Per noleggi pi&ugrave; lunghi il costo medio si riduce con il <b>calcolo scalare</b>.</div>
                                <?php else : ?>
                                    <div class="bsn-price-main"><?php echo esc_html( number_format_i18n( $display_price, 2 ) ); ?> EUR <small class="bsn-vat-note-inline">+ IVA</small></div>
                                    <div class="bsn-price-note">Per noleggi pi&ugrave; lunghi il costo medio si riduce con il <b>calcolo scalare</b>.</div>
                                <?php endif; ?>
                            <?php else : ?>
                                <div class="bsn-price-note">Prezzo su richiesta</div>
                            <?php endif; ?>
                        </div>

                        <div class="bsn-prodotto-logistics">
                            <span class="bsn-meta-chip">
                                <svg width="14" height="14" aria-hidden="true"><use href="#ic-pin"/></svg>
                                Sede <strong><?php echo esc_html( $meta['sede_operativa'] ?: 'Da confermare' ); ?></strong>
                            </span>
                            <span class="bsn-meta-chip">
                                <svg width="14" height="14" aria-hidden="true"><use href="#ic-box"/></svg>
                                Min. <strong><?php echo esc_html( $min_qty ); ?> pz</strong>
                            </span>
                            <span class="bsn-meta-chip">
                                <svg width="14" height="14" aria-hidden="true"><use href="#ic-truck"/></svg>
                                Veicolo <strong><?php echo esc_html( bsn_get_articolo_veicolo_minimo_options()[ $meta['veicolo_minimo'] ] ?? 'Non specificato' ); ?></strong>
                            </span>
                        </div>

                        <div class="bsn-prodotto-date-box">
                            <div id="bsn-public-quote-intro" class="bsn-public-quote-box bsn-public-quote-box-intro">
                                <div class="bsn-price-label">Anteprima prezzo</div>
                                <div class="bsn-price-note">Inserisci date e quantit&agrave; per ottenere una stima pre-carrello. Clicca poi Verifica disponibilit&agrave;.</div>
                            </div>

                            <div id="bsn-public-cart-note" class="bsn-public-inline-note" hidden></div>

                            <div class="bsn-date-grid">
                                <div class="bsn-public-date-field" data-date-lock-trigger="1">
                                    <label for="bsn-public-data-ritiro">Data ritiro</label>
                                    <div class="bsn-public-date-shell">
                                        <div class="bsn-public-date-display" id="bsn-public-data-ritiro-display">gg-mm-aaaa</div>
                                        <input type="date" id="bsn-public-data-ritiro" class="bsn-public-date-native" value="<?php echo esc_attr( $data_ritiro ); ?>">
                                    </div>
                                </div>
                                <div class="bsn-public-date-field" data-date-lock-trigger="1">
                                    <label for="bsn-public-data-riconsegna">Data riconsegna</label>
                                    <div class="bsn-public-date-shell">
                                        <div class="bsn-public-date-display" id="bsn-public-data-riconsegna-display">gg-mm-aaaa</div>
                                        <input type="date" id="bsn-public-data-riconsegna" class="bsn-public-date-native" value="<?php echo esc_attr( $data_riconsegna ); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="bsn-date-priority-helper">Date obbligatorie per disponibilit&agrave; e prezzo</div>

                            <div class="bsn-date-actions" id="bsn-public-check-actions">
                                <button type="button" class="bsn-public-btn" id="bsn-public-check-availability">Verifica disponibilit&agrave;</button>
                            </div>

                            <div id="bsn-public-availability-result" class="bsn-prodotto-availability-slot">
                                <?php echo $availability_html; ?>
                            </div>

                            <?php if ( $dimension_enabled ) : ?>
                                <div class="bsn-dimension-calculator" id="bsn-dimension-calculator">
                                    <div class="bsn-dimension-heading">
                                        <div class="bsn-price-label">Calcolo dimensionale</div>
                                        <?php if ( ! empty( $dimension_config['customer_note'] ) ) : ?>
                                            <div class="bsn-price-note"><?php echo esc_html( $dimension_config['customer_note'] ); ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ( ! empty( $dimension_presets ) ) : ?>
                                        <div class="bsn-dimension-presets" aria-label="Preset dimensioni">
                                            <?php foreach ( $dimension_presets as $preset ) : ?>
                                                <button type="button" class="bsn-dimension-preset" data-width-m="<?php echo esc_attr( $preset['width_m'] ); ?>" data-height-m="<?php echo esc_attr( $preset['height_m'] ); ?>" data-label="<?php echo esc_attr( $preset['label'] ); ?>">
                                                    <span><?php echo esc_html( $preset['label'] ); ?></span>
                                                    <?php if ( ! empty( $preset['note'] ) ) : ?>
                                                        <small><?php echo esc_html( $preset['note'] ); ?></small>
                                                    <?php endif; ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="bsn-dimension-grid">
                                        <label for="bsn-dimension-width">Larghezza (m)
                                            <input type="number" id="bsn-dimension-width" min="0" step="0.01" inputmode="decimal">
                                        </label>
                                        <label for="bsn-dimension-height"><?php echo $dimension_type === 'dimensionale_mq' ? 'Profondit&agrave; (m)' : 'Altezza/profondit&agrave; (m)'; ?>
                                            <input type="number" id="bsn-dimension-height" min="0" step="0.01" inputmode="decimal">
                                        </label>
                                        <button type="button" class="bsn-public-btn bsn-public-btn-secondary" id="bsn-dimension-calculate">Calcola</button>
                                    </div>

                                    <div id="bsn-dimension-result" class="bsn-dimension-result" hidden></div>
                                    <div id="bsn-dimension-warning" class="bsn-public-warning-box bsn-dimension-warning" hidden></div>

                                    <label class="bsn-dimension-note" for="bsn-dimension-note">Note dimensioni
                                        <textarea id="bsn-dimension-note" rows="2" placeholder="Dimensione desiderata, vincoli o informazioni utili per il montaggio"></textarea>
                                    </label>
                                </div>
                            <?php endif; ?>

                            <div class="bsn-public-qty-row" id="bsn-public-qty-row">
                                <div class="bsn-public-qty-field">
                                    <label for="bsn-public-qty">Quantità richiesta</label>
                                    <input type="number" id="bsn-public-qty" min="<?php echo esc_attr( $min_qty ); ?>" step="1" value="<?php echo esc_attr( $min_qty ); ?>">
                                </div>
                            </div>

                            <div id="bsn-public-quote-preview" class="bsn-public-quote-box bsn-public-quote-box-result" hidden>
                                <div class="bsn-price-label">Anteprima prezzo</div>
                                <div class="bsn-price-note">La stima comparir&agrave; qui dopo la verifica della disponibilit&agrave;.</div>
                            </div>

                            <div class="bsn-date-actions bsn-date-actions-add bsn-add-to-quote-wrap is-disabled" id="bsn-public-add-actions" data-tooltip="Inserisci prima la data di ritiro e riconsegna." tabindex="0" hidden>
                                <button type="button" class="bsn-public-btn bsn-public-btn-secondary bsn-public-btn-pending" id="bsn-public-add-to-cart" aria-disabled="true" disabled>Aggiungi al preventivo</button>
                            </div>
                            <div id="bsn-public-add-feedback" class="bsn-public-add-feedback" hidden></div>
                        </div>
                        <div class="bsn-public-disclaimer">
                            Il carrello preventivo usa date globali uniche. Dal carrello puoi completare la richiesta e scegliere anche modalit&agrave; di consegna e servizi.
                        </div>

                        <?php if ( ! empty( $meta['disclaimer_preventivo'] ) ) : ?>
                            <div class="bsn-public-disclaimer">
                                <?php echo nl2br( esc_html( $meta['disclaimer_preventivo'] ) ); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bsn-prodotto-sections">
                    <section class="bsn-public-card">
                        <h2>Descrizione</h2>
                        <div class="bsn-rich-text">
                            <?php the_content(); ?>
                        </div>
                    </section>

                    <?php if ( $utilizzo_consigliato !== '' ) : ?>
                        <section class="bsn-public-card bsn-utilizzo-consigliato-card">
                            <h2>Utilizzo consigliato</h2>
                            <div class="bsn-rich-text"><?php echo wp_kses_post( wpautop( $utilizzo_consigliato ) ); ?></div>
                        </section>
                    <?php endif; ?>

                    <?php if ( $specifiche_pubbliche !== '' ) : ?>
                        <section class="bsn-public-card">
                            <h2>Specifiche tecniche</h2>
                            <div class="bsn-rich-text"><?php echo wp_kses_post( wpautop( $specifiche_pubbliche ) ); ?></div>
                        </section>
                    <?php endif; ?>

                    <?php if ( $faq_pubbliche !== '' ) : ?>
                        <section class="bsn-public-card">
                            <h2>FAQ</h2>
                            <div class="bsn-rich-text"><?php echo wp_kses_post( wpautop( $faq_pubbliche ) ); ?></div>
                        </section>
                    <?php endif; ?>

                    <?php if ( ! empty( $product_downloads ) ) : ?>
                        <section class="bsn-public-card">
                            <h2>Download</h2>
                            <ul class="bsn-download-list">
                                <?php foreach ( $product_downloads as $dl ) :
                                    $tipo       = isset( $dl['tipo'] ) ? $dl['tipo'] : '';
                                    $tipo_label = $tipo_download_labels[ $tipo ] ?? 'Download';
                                    $titolo     = ! empty( $dl['titolo'] ) ? $dl['titolo'] : $tipo_label;
                                ?>
                                    <li class="bsn-download-item">
                                        <a href="<?php echo esc_url( $dl['url'] ); ?>" class="bsn-download-link" target="_blank" rel="noopener noreferrer">
                                            <span class="bsn-download-icon" aria-hidden="true">&#8595;</span>
                                            <span class="bsn-download-title"><?php echo esc_html( $titolo ); ?></span>
                                            <span class="bsn-download-badge"><?php echo esc_html( $tipo_label ); ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>

                    <?php if ( ! empty( $consigliati_posts ) ) : ?>
                        <section class="bsn-public-card bsn-consigliati-section">
                            <h2>Prodotti consigliati</h2>
                            <div class="bsn-consigliati-grid">
                                <?php foreach ( $consigliati_posts as $cp ) :
                                    $cp_gallery = bsn_get_public_product_gallery_urls( $cp->ID );
                                    $cp_meta    = bsn_get_public_product_meta( $cp->ID );
                                ?>
                                    <a href="<?php echo esc_url( get_permalink( $cp->ID ) ); ?>" class="bsn-consigliato-card">
                                        <div class="bsn-consigliato-img">
                                            <?php if ( ! empty( $cp_gallery ) ) : ?>
                                                <img src="<?php echo esc_url( $cp_gallery[0] ); ?>" alt="<?php echo esc_attr( $cp->post_title ); ?>">
                                            <?php else : ?>
                                                <span class="bsn-consigliato-img-placeholder">&#128247;</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="bsn-consigliato-body">
                                            <div class="bsn-consigliato-title"><?php echo esc_html( $cp->post_title ); ?></div>
                                            <?php if ( ! empty( $cp_meta['sottotitolo_catalogo'] ) ) : ?>
                                                <div class="bsn-consigliato-sub"><?php echo esc_html( $cp_meta['sottotitolo_catalogo'] ); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    </main>

    <script>
    (function() {
        var productId = <?php echo (int) $product_id; ?>;
        var root = <?php echo wp_json_encode( esc_url_raw( rest_url( 'bsn/v1/' ) ) ); ?>;
        var restNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        var btn = document.getElementById('bsn-public-check-availability');
        var addBtn = document.getElementById('bsn-public-add-to-cart');
        var checkActions = document.getElementById('bsn-public-check-actions');
        var addActions = document.getElementById('bsn-public-add-actions');
        var introBox = document.getElementById('bsn-public-quote-intro');
        var inputRitiro = document.getElementById('bsn-public-data-ritiro');
        var inputRiconsegna = document.getElementById('bsn-public-data-riconsegna');
        var inputRitiroDisplay = document.getElementById('bsn-public-data-ritiro-display');
        var inputRiconsegnaDisplay = document.getElementById('bsn-public-data-riconsegna-display');
        var inputQty = document.getElementById('bsn-public-qty');
        var qtyRow = document.getElementById('bsn-public-qty-row');
        var result = document.getElementById('bsn-public-availability-result');
        var quoteBox = document.getElementById('bsn-public-quote-preview');
        var cartNote = document.getElementById('bsn-public-cart-note');
        var feedbackBox = document.getElementById('bsn-public-add-feedback');
        var cartUrl = <?php echo wp_json_encode( esc_url_raw( $quote_cart_url ) ); ?>;
        var catalogUrl = <?php echo wp_json_encode( esc_url_raw( $catalog_url ) ); ?>;
        var dimensionConfig = <?php echo wp_json_encode( $dimension_public_config ); ?>;
        var dimensionEnabled = !!(dimensionConfig && dimensionConfig.enabled);
        var dimensionWidthInput = document.getElementById('bsn-dimension-width');
        var dimensionHeightInput = document.getElementById('bsn-dimension-height');
        var dimensionCalculateBtn = document.getElementById('bsn-dimension-calculate');
        var dimensionResult = document.getElementById('bsn-dimension-result');
        var dimensionWarning = document.getElementById('bsn-dimension-warning');
        var dimensionNoteInput = document.getElementById('bsn-dimension-note');
        var minQty = <?php echo (int) $min_qty; ?>;
        var dateFields = document.querySelectorAll('[data-date-lock-trigger="1"]');
        var dateShells = document.querySelectorAll('.bsn-public-date-shell');
        var lockedDatesMessage = 'Le date di questo noleggio sono gia state definite dal carrello. Per modificarle agisci dal carrello.';
        var missingDatesTooltip = 'Inserisci prima la data di ritiro e riconsegna.';
        var state = {
            cartLocked: false,
            verified: false,
            lastAvailableUnits: 0,
            dimensionData: null,
            dimensionManualQty: false,
            updatingQtyFromDimension: false,
            pendingDimensionCalc: null
        };

        if (!btn || !addBtn || !checkActions || !addActions || !introBox || !inputRitiro || !inputRiconsegna || !inputRitiroDisplay || !inputRiconsegnaDisplay || !inputQty || !qtyRow || !result || !quoteBox || !cartNote || !feedbackBox) {
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

        function getQtyValue() {
            var qty = Math.max(minQty, parseInt(inputQty.value || String(minQty), 10) || minQty);
            inputQty.value = String(qty);
            return qty;
        }

        function parseDimensionNumber(value) {
            var normalized = String(value || '').replace(',', '.').trim();
            var parsed = parseFloat(normalized);
            return isFinite(parsed) ? parsed : 0;
        }

        function formatDimensionNumber(value) {
            var num = parseDimensionNumber(value);
            return String(Number(num.toFixed(2))).replace('.', ',');
        }

        function roundUpToStep(value, step) {
            value = parseDimensionNumber(value);
            step = parseDimensionNumber(step);
            if (value <= 0 || step <= 0) {
                return 0;
            }
            return Math.ceil((value - 0.00001) / step) * step;
        }

        function isMultipleOfStep(value, step) {
            value = parseDimensionNumber(value);
            step = parseDimensionNumber(step);
            if (value <= 0 || step <= 0) {
                return false;
            }
            return Math.abs((Math.round(value / step) * step) - value) <= 0.00001;
        }

        function setQtyFromDimension(qty) {
            state.updatingQtyFromDimension = true;
            inputQty.value = String(Math.max(minQty, Math.ceil(Number(qty || minQty))));
            state.updatingQtyFromDimension = false;
        }

        function getDimensionNote() {
            return dimensionNoteInput ? String(dimensionNoteInput.value || '').trim() : '';
        }

        function showDimensionResult(summary) {
            if (!dimensionResult) {
                return;
            }
            dimensionResult.hidden = false;
            dimensionResult.textContent = summary || '';
        }

        function hideDimensionWarning() {
            if (!dimensionWarning) {
                return;
            }
            dimensionWarning.hidden = true;
            dimensionWarning.innerHTML = '';
        }

        function buildDimensionSummary(data) {
            if (!data) {
                return '';
            }
            if (data.calculation_type === 'dimensionale_modulare') {
                return formatDimensionNumber(data.technical_width_m) + 'x' + formatDimensionNumber(data.technical_height_m) + ' m - ' +
                    String(data.modules_total || 0) + ' moduli - ' + formatDimensionNumber(data.area_mq) + ' mq';
            }
            return formatDimensionNumber(data.technical_width_m || data.requested_width_m) + 'x' +
                formatDimensionNumber(data.technical_height_m || data.requested_height_m) + ' m - ' +
                formatDimensionNumber(data.area_mq) + ' mq';
        }

        function applyDimensionCalculation(data) {
            data.selected_preset_label = state.pendingPresetLabel || data.selected_preset_label || '';
            data.dimension_summary = buildDimensionSummary(data);
            data.dimension_note = getDimensionNote();
            state.dimensionData = data;
            state.dimensionManualQty = false;
            hideDimensionWarning();
            showDimensionResult(data.dimension_summary);
            setQtyFromDimension(data.qty_for_cart);
            restoreDefaultNote();
            resetQuotePreviewBox();
            disableAddButton();
            if (hasCompleteDates()) {
                runAvailabilityCheck({ auto: true });
            }
        }

        function showDimensionWarning(calc) {
            if (!dimensionWarning) {
                return;
            }
            state.pendingDimensionCalc = calc;
            dimensionWarning.hidden = false;
            dimensionWarning.innerHTML =
                '<strong>Misura non compatibile.</strong>' +
                '<div>Questo articolo accetta misure compatibili con moduli ' + escHtml(formatDimensionNumber(calc.module_width_m)) + ' x ' + escHtml(formatDimensionNumber(calc.module_height_m)) + ' m. Misura tecnica proposta: ' + escHtml(formatDimensionNumber(calc.technical_width_m)) + ' x ' + escHtml(formatDimensionNumber(calc.technical_height_m)) + ' m.</div>' +
                '<div class="bsn-dimension-warning-actions">' +
                    '<button type="button" class="bsn-public-btn bsn-public-btn-secondary" data-dimension-use-technical="1">Usa misura tecnica</button>' +
                    '<button type="button" class="bsn-public-btn bsn-public-btn-ghost" data-dimension-modify="1">Modifica</button>' +
                '</div>';
        }

        function calculateDimension(useTechnical) {
            if (!dimensionEnabled || !dimensionWidthInput || !dimensionHeightInput) {
                return false;
            }

            var width = parseDimensionNumber(dimensionWidthInput.value);
            var height = parseDimensionNumber(dimensionHeightInput.value);
            var type = String(dimensionConfig.calculation_type || 'standard');
            var data;
            var moduleWidthM;
            var moduleHeightM;
            var compatibleWidth;
            var compatibleHeight;
            var technicalWidth;
            var technicalHeight;
            var modulesX;
            var modulesY;

            if (width <= 0 || height <= 0) {
                alert('Inserisci larghezza e altezza/profondita maggiori di zero.');
                return false;
            }

            if (type === 'dimensionale_mq') {
                data = {
                    calculation_type: type,
                    requested_width_m: width,
                    requested_height_m: height,
                    technical_width_m: width,
                    technical_height_m: height,
                    area_mq: width * height,
                    qty_for_cart: Math.ceil(width * height)
                };
                applyDimensionCalculation(data);
                return true;
            }

            moduleWidthM = parseDimensionNumber(dimensionConfig.module_width_cm) / 100;
            moduleHeightM = parseDimensionNumber(dimensionConfig.module_height_cm) / 100;
            if (moduleWidthM <= 0 || moduleHeightM <= 0) {
                alert('Configurazione moduli non valida per questo prodotto.');
                return false;
            }

            compatibleWidth = isMultipleOfStep(width, moduleWidthM);
            compatibleHeight = isMultipleOfStep(height, moduleHeightM);
            technicalWidth = roundUpToStep(width, moduleWidthM);
            technicalHeight = roundUpToStep(height, moduleHeightM);
            modulesX = Math.max(1, Math.round(technicalWidth / moduleWidthM));
            modulesY = Math.max(1, Math.round(technicalHeight / moduleHeightM));
            data = {
                calculation_type: type,
                requested_width_m: width,
                requested_height_m: height,
                technical_width_m: technicalWidth,
                technical_height_m: technicalHeight,
                module_width_m: moduleWidthM,
                module_height_m: moduleHeightM,
                modules_x: modulesX,
                modules_y: modulesY,
                modules_total: modulesX * modulesY,
                area_mq: technicalWidth * technicalHeight,
                qty_for_cart: modulesX * modulesY
            };

            if ((!compatibleWidth || !compatibleHeight) && !useTechnical) {
                showDimensionWarning(data);
                return false;
            }

            applyDimensionCalculation(data);
            return true;
        }

        function markDimensionManualQty() {
            if (!dimensionEnabled || state.updatingQtyFromDimension) {
                return;
            }
            state.dimensionData = null;
            state.dimensionManualQty = true;
            if (dimensionResult) {
                dimensionResult.hidden = true;
                dimensionResult.textContent = '';
            }
            hideDimensionWarning();
            setCartNote('Per aiutare i tecnici al montaggio, puoi indicare nelle note la dimensione desiderata.', true);
        }

        function appendDimensionParams(params) {
            var data = state.dimensionData || null;
            if (data) {
                params.set('dimension_width_m', String(data.requested_width_m || data.technical_width_m || ''));
                params.set('dimension_height_m', String(data.requested_height_m || data.technical_height_m || ''));
                params.set('selected_preset_label', String(data.selected_preset_label || ''));
            }
            if (dimensionEnabled && getDimensionNote()) {
                params.set('dimension_note', getDimensionNote());
            }
        }

        function formatDateForDisplay(isoDate) {
            var parts;
            if (!isoDate || !/^\d{4}-\d{2}-\d{2}$/.test(String(isoDate))) {
                return 'gg-mm-aaaa';
            }
            parts = String(isoDate).split('-');
            return [parts[2], parts[1], parts[0]].join('-');
        }

        function syncDateDisplay(input, display) {
            var formatted = formatDateForDisplay(input.value);
            display.textContent = formatted;
            display.classList.toggle('is-placeholder', formatted === 'gg-mm-aaaa');
        }

        function syncDateDisplays() {
            syncDateDisplay(inputRitiro, inputRitiroDisplay);
            syncDateDisplay(inputRiconsegna, inputRiconsegnaDisplay);
        }

        function resetQuotePreviewBox() {
            quoteBox.hidden = true;
            quoteBox.innerHTML =
                '<div class="bsn-price-label">Anteprima prezzo</div>' +
                '<div class="bsn-price-note">La stima comparira qui dopo la verifica della disponibilita.</div>';
        }

        function hasCompleteDates() {
            return !!(inputRitiro.value && inputRiconsegna.value);
        }

        function syncAddToQuoteTooltip() {
            var missingDates = !hasCompleteDates();
            addActions.classList.toggle('is-disabled', missingDates);
            if (missingDates) {
                addActions.setAttribute('data-tooltip', missingDatesTooltip);
                addActions.setAttribute('tabindex', '0');
            } else {
                addActions.removeAttribute('data-tooltip');
                addActions.removeAttribute('tabindex');
            }
        }

        function showIntroBox() {
            introBox.hidden = false;
        }

        function hideIntroBox() {
            introBox.hidden = true;
        }

        function showQtyRow() {
            qtyRow.hidden = false;
        }

        function hideQtyRow() {
            qtyRow.hidden = false;
        }

        function resetAvailabilityResult(message) {
            result.innerHTML =
                '<div class="bsn-public-availability-live bsn-public-availability-live-neutral">' +
                    '<div class="bsn-live-badge">Disponibilita da verificare</div>' +
                    '<div class="bsn-live-note">' + escHtml(message || 'Inserisci date e quantita, poi clicca Verifica disponibilita.') + '</div>' +
                '</div>';
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

        function setCartNote(message, warning) {
            if (!message) {
                if (dimensionEnabled && state.dimensionManualQty && !state.cartLocked) {
                    cartNote.hidden = false;
                    cartNote.textContent = 'Per aiutare i tecnici al montaggio, puoi indicare nelle note la dimensione desiderata.';
                    cartNote.classList.add('is-warning');
                    return;
                }
                cartNote.hidden = true;
                cartNote.textContent = '';
                cartNote.classList.remove('is-warning');
                return;
            }
            cartNote.hidden = false;
            cartNote.textContent = message;
            cartNote.classList.toggle('is-warning', !!warning);
        }

        function clearAddFeedback() {
            feedbackBox.hidden = true;
            feedbackBox.innerHTML = '';
        }

        function showAddSuccess(message) {
            feedbackBox.hidden = false;
            feedbackBox.innerHTML =
                '<div class="bsn-public-success-box">' +
                    '<strong>' + escHtml(message || 'Prodotto aggiunto al preventivo.') + '</strong>' +
                    '<div class="bsn-public-add-feedback-actions">' +
                        '<a class="bsn-public-btn bsn-public-btn-ghost" href="' + escHtml(catalogUrl || '#') + '">Continua a noleggiare</a>' +
                        '<a class="bsn-public-btn" href="' + escHtml(cartUrl || '#') + '">Vai al preventivo</a>' +
                    '</div>' +
                '</div>';
        }

        function showCheckButton() {
            checkActions.hidden = false;
            checkActions.style.display = '';
            btn.hidden = false;
            btn.disabled = false;
        }

        function hideCheckButton() {
            checkActions.hidden = true;
            checkActions.style.display = 'none';
            btn.hidden = true;
            btn.disabled = true;
        }

        function showAddButtonSection() {
            addActions.hidden = false;
        }

        function hideAddButtonSection() {
            addActions.hidden = true;
        }

        function disableAddButton() {
            addBtn.disabled = true;
            addBtn.classList.remove('bsn-public-btn-pending');
            addBtn.removeAttribute('aria-busy');
            addBtn.setAttribute('aria-disabled', 'true');
            syncAddToQuoteTooltip();
        }

        function enableAddButton() {
            addBtn.disabled = false;
            addBtn.classList.remove('bsn-public-btn-pending');
            addBtn.setAttribute('aria-disabled', 'false');
            syncAddToQuoteTooltip();
        }

        function lockDateInputs(locked) {
            inputRitiro.disabled = !!locked;
            inputRiconsegna.disabled = !!locked;
            dateFields.forEach(function(field) {
                field.classList.toggle('is-locked', !!locked);
                field.setAttribute('title', locked ? lockedDatesMessage : '');
            });
        }

        function restoreDefaultNote() {
            if (state.cartLocked) {
                setCartNote(lockedDatesMessage, false);
            } else {
                setCartNote('', false);
            }
            clearAddFeedback();
        }

        function resetVerificationState() {
            state.verified = false;
            state.lastAvailableUnits = 0;
            resetAvailabilityResult(
                state.cartLocked
                    ? 'Aggiornamento automatico sulla disponibilita del periodo gia fissato nel carrello.'
                    : 'Inserisci date e quantita, poi clicca Verifica disponibilita.'
            );
            resetQuotePreviewBox();
            disableAddButton();
            if (!state.cartLocked) {
                showIntroBox();
                showQtyRow();
                showAddButtonSection();
                showCheckButton();
                setCartNote('', false);
            } else {
                hideIntroBox();
                showQtyRow();
                showAddButtonSection();
                hideCheckButton();
            }
            clearAddFeedback();
        }

        function validateDateRange() {
            if (!inputRitiro.value || !inputRiconsegna.value) {
                return true;
            }
            if (inputRitiro.value && inputRiconsegna.value && inputRitiro.value > inputRiconsegna.value) {
                disableAddButton();
                if (!state.cartLocked) {
                    hideAddButtonSection();
                }
                state.verified = false;
                state.lastAvailableUnits = 0;
                alert('La data di riconsegna deve essere uguale o successiva alla data di ritiro.');
                resetQuotePreviewBox();
                result.innerHTML = '<div class="bsn-public-warning-box">Le date inserite non sono valide: la riconsegna non puo essere precedente al ritiro.</div>';
                clearAddFeedback();
                return false;
            }
            return true;
        }

        function renderQuotePreview(data) {
            if (!data || data.success === false) {
                resetQuotePreviewBox();
                return false;
            }

            var qty = Number(data.qty || getQtyValue());
            var prezzoUnitario = Number(data.prezzo_netto || 0).toFixed(2);
            var totaleStimato = Number(data.totale_stimato || 0);
            var risparmioScalare = Number(data.risparmio_scalare || 0);
            var risparmioCategoria = Number(data.risparmio_categoria || 0);
            var risparmioQuantita = Number(data.risparmio_quantita || 0);
            var totaleSenzaSconti = totaleStimato + risparmioCategoria + risparmioQuantita;
            var typeLabel = data.noleggio_scalare ? 'Scalare' : 'Lineare';
            var extraMessage = data.message ? '<div class="bsn-price-note">' + escHtml(data.message) + '</div>' : '';
            var dimensionLine = data.dimension && data.dimension.dimension_summary
                ? '<div><strong>Dimensioni:</strong> ' + escHtml(data.dimension.dimension_summary) + '</div>'
                : '';
            var qtyDiscountLine = risparmioQuantita > 0.009
                ? '<div><strong>Risparmio quantita:</strong> ' + escHtml(risparmioQuantita.toFixed(2)) + ' EUR' + (Number(data.qty_discount_applied_pct || 0) > 0 ? ' (-' + escHtml(Number(data.qty_discount_applied_pct || 0).toFixed(2)) + '%)' : '') + '</div>'
                : '';

            quoteBox.hidden = false;
            quoteBox.innerHTML =
                '<div class="bsn-price-label">Anteprima prezzo</div>' +
                '<div class="bsn-public-quote-meta">' +
                    '<div><strong>Prezzo unitario:</strong> ' + escHtml(prezzoUnitario) + ' EUR <small class="bsn-vat-note-inline">+ IVA</small></div>' +
                    '<div><strong>Categoria prezzo:</strong> ' + escHtml(getCategoryLabel(data.categoria_cliente || 'standard')) + '</div>' +
                    '<div><strong>Quantita:</strong> ' + escHtml(qty) + '</div>' +
                    dimensionLine +
                    '<div><strong>Giorni:</strong> ' + escHtml(data.giorni || 1) + '</div>' +
                    '<div><strong>Fattore:</strong> ' + escHtml(typeLabel) + '</div>' +
                    '<div><strong>Totale senza sconti:</strong> ' + escHtml(totaleSenzaSconti.toFixed(2)) + ' EUR <small class="bsn-vat-note-inline">+ IVA</small></div>' +
                    '<div><strong>Risparmio noleggio scalare:</strong> ' + escHtml(risparmioScalare.toFixed(2)) + ' EUR</div>' +
                    '<div><strong>Risparmio categoria:</strong> ' + escHtml(risparmioCategoria.toFixed(2)) + ' EUR</div>' +
                    qtyDiscountLine +
                '</div>' +
                '<div class="bsn-public-quote-total"><strong class="bsn-public-quote-total-label">Totale stimato:</strong> <strong class="bsn-public-quote-total-value">' + escHtml(totaleStimato.toFixed(2)) + ' EUR <small class="bsn-vat-note-inline">+ IVA</small></strong></div>' +
                '<div class="bsn-price-note">Prezzi IVA esclusa. Stima ' + escHtml(getCategoryLabel(data.categoria_cliente || 'standard')) + '.</div>' +
                extraMessage;

            return true;
        }

        function renderAvailability(data, qty) {
            var badge = data.badge_marketing || data.badge || 'Non disponibile';
            var availableUnits = Number(data.available_units || 0);
            var rawAvailableUnits = Number(data.available_units_raw || availableUnits || 0);
            var cartReservedQty = Number(data.cart_reserved_qty || 0);
            var warningBlocks = [];

            if (Array.isArray(data.warning_messages) && data.warning_messages.length) {
                warningBlocks.push(
                    '<div class="bsn-public-warning-box"><strong>Avvisi sul materiale selezionabile</strong><ul>' +
                        data.warning_messages.map(function(item) {
                            return '<li>' + escHtml(item) + '</li>';
                        }).join('') +
                    '</ul></div>'
                );
            }

            if (availableUnits < qty) {
                if (cartReservedQty > 0) {
                    warningBlocks.push(
                        '<div class="bsn-public-warning-box"><strong>Hai gia nel preventivo ' + escHtml(cartReservedQty) + ' di questo prodotto.</strong><div>Disponibilita residua per queste date: ' + escHtml(availableUnits) + ' unita.</div></div>'
                    );
                    setCartNote('Hai gia nel preventivo ' + cartReservedQty + ' di questo prodotto. Disponibilita residua: ' + availableUnits + '.', true);
                } else {
                    warningBlocks.push(
                        '<div class="bsn-public-warning-box"><strong>Quantita richiesta superiore a quella disponibile per il periodo selezionato.</strong><div>Disponibili subito: ' + escHtml(availableUnits) + ' unita. Riduci la quantita oppure contattaci per una verifica manuale.</div></div>'
                    );
                    setCartNote('Quantita richiesta superiore a quella disponibile per il periodo selezionato.', true);
                }
            } else {
                restoreDefaultNote();
            }

            result.innerHTML =
                '<div class="bsn-public-availability-live">' +
                    '<div class="bsn-live-badge">' + escHtml(badge) + '</div>' +
                    '<div class="bsn-live-count">' + escHtml(availableUnits) + ' / ' + escHtml(data.total_units || 0) + ' unita disponibili per il periodo selezionato</div>' +
                    (cartReservedQty > 0 ? '<div class="bsn-live-note">Hai gia nel preventivo ' + escHtml(cartReservedQty) + ' unita di questo prodotto. Disponibilita reale del magazzino: ' + escHtml(rawAvailableUnits) + '.</div>' : '') +
                    '<div class="bsn-live-note">Se ti serve piu materiale, contattaci per una verifica dedicata.</div>' +
                '</div>' +
                warningBlocks.join('');

            return availableUnits;
        }

        function updateButtonsAfterCheck(quoteOk, enoughAvailability) {
            if (state.cartLocked) {
                hideCheckButton();
                showAddButtonSection();
            } else {
                showCheckButton();
                if (quoteOk) {
                    showAddButtonSection();
                } else {
                    hideAddButtonSection();
                }
            }

            if (quoteOk && enoughAvailability) {
                enableAddButton();
            } else {
                disableAddButton();
            }
        }

        function runAvailabilityCheck(options) {
            options = options || {};
            var qty = getQtyValue();
            var params = new URLSearchParams({ product_id: String(productId) });

            if (!hasCompleteDates()) {
                state.verified = false;
                state.lastAvailableUnits = 0;
                resetQuotePreviewBox();
                disableAddButton();
                if (!state.cartLocked) {
                    showQtyRow();
                    showAddButtonSection();
                }
                result.innerHTML = '<div class="bsn-public-warning-box">Seleziona data ritiro e data riconsegna prima di verificare la disponibilita.</div>';
                if (!options.auto) {
                    alert('Seleziona data ritiro e data riconsegna prima di verificare la disponibilita.');
                }
                return Promise.resolve(false);
            }

            if (inputRitiro.value) {
                params.set('data_ritiro', inputRitiro.value);
            }
            if (inputRiconsegna.value) {
                params.set('data_riconsegna', inputRiconsegna.value);
            }
            params.set('qty', String(qty));
            appendDimensionParams(params);

            if (!validateDateRange()) {
                return Promise.resolve(false);
            }

            state.verified = false;
            state.lastAvailableUnits = 0;
            clearAddFeedback();
            resetQuotePreviewBox();
            disableAddButton();
            showQtyRow();
            if (state.cartLocked) {
                showAddButtonSection();
            } else {
                hideAddButtonSection();
            }

            if (!state.cartLocked) {
                btn.disabled = true;
                btn.textContent = options.auto ? 'Aggiornamento...' : 'Verifica in corso...';
            }

            return fetch(root + 'public-products/availability?' + params.toString(), {
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': restNonce
                }
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    state.lastAvailableUnits = renderAvailability(data, qty);
                    return fetch(root + 'public-products/quote-preview?' + params.toString(), {
                        credentials: 'same-origin',
                        headers: {
                            'X-WP-Nonce': restNonce
                        }
                    })
                        .then(function(response) { return response.json(); })
                        .then(function(quoteData) {
                            return {
                                availability: data,
                                quote: quoteData
                            };
                        });
                })
                .then(function(payload) {
                    var quoteOk = renderQuotePreview(payload.quote);
                    var enoughAvailability = state.lastAvailableUnits >= qty;
                    state.verified = !!quoteOk && enoughAvailability;
                    updateButtonsAfterCheck(quoteOk, enoughAvailability);
                    return state.verified;
                })
                .catch(function() {
                    disableAddButton();
                    state.verified = false;
                    state.lastAvailableUnits = 0;
                    result.innerHTML = '<div class="bsn-public-warning-box">Errore nel recupero della disponibilita.</div>';
                    resetQuotePreviewBox();
                    if (!state.cartLocked) {
                        hideAddButtonSection();
                    }
                    return false;
                })
                .finally(function() {
                    if (!state.cartLocked) {
                        btn.disabled = false;
                        btn.textContent = 'Verifica disponibilita';
                    }
                });
        }

        function applyCartState(cart, source) {
            var hasLockedCart = !!(cart && cart.item_count > 0 && cart.has_dates && !cart.invalid_dates);

            if (!hasLockedCart) {
                state.cartLocked = false;
                showIntroBox();
                lockDateInputs(false);
                showCheckButton();
                setCartNote('', false);
                resetVerificationState();
                return false;
            }

            var cartRitiro = String(cart.dates.data_ritiro || '');
            var cartRiconsegna = String(cart.dates.data_riconsegna || '');
            var staleDates = (
                (inputRitiro.value && inputRitiro.value !== cartRitiro) ||
                (inputRiconsegna.value && inputRiconsegna.value !== cartRiconsegna)
            );

            inputRitiro.value = cartRitiro;
            inputRiconsegna.value = cartRiconsegna;
            syncDateDisplays();
            state.cartLocked = true;
            state.verified = false;
            hideIntroBox();
            lockDateInputs(true);
            hideCheckButton();
            showAddButtonSection();
            showQtyRow();
            disableAddButton();

            if (staleDates || source === 'stale') {
                setCartNote('Le date di questa scheda erano diverse da quelle gia presenti nel carrello. Sono state riallineate al periodo globale del preventivo.', true);
            } else {
                setCartNote(lockedDatesMessage, false);
            }

            return true;
        }

        function fetchCartState() {
            return fetch(root + 'quote-cart', {
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': restNonce
                }
            })
                .then(function(response) { return response.json(); })
                .catch(function() { return null; });
        }

        function submitAddToCart(forceDates) {
            var params = new URLSearchParams({
                product_id: String(productId),
                qty: String(getQtyValue())
            });

            if (inputRitiro.value) {
                params.set('data_ritiro', inputRitiro.value);
            }
            if (inputRiconsegna.value) {
                params.set('data_riconsegna', inputRiconsegna.value);
            }
            if (forceDates) {
                params.set('force_dates', '1');
            }
            appendDimensionParams(params);

            addBtn.disabled = true;
            addBtn.setAttribute('aria-busy', 'true');
            addBtn.textContent = 'Aggiunta in corso...';

            return fetch(root + 'quote-cart/add', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-WP-Nonce': restNonce
                },
                body: params.toString()
            })
            .then(function(response) {
                return response.json().then(function(data) {
                    if (!response.ok) {
                        throw data;
                    }
                    return data;
                });
            })
            .then(function(data) {
                if (data && data.success === false && data.code === 'dates_conflict') {
                    if (window.confirm(data.message || 'Il carrello usa gia un altro periodo. Vuoi aggiornarlo?')) {
                        return submitAddToCart(true);
                    }
                    return null;
                }
                if (data && data.success) {
                    showAddSuccess('Prodotto aggiunto al preventivo.');
                    return null;
                }
                throw data || { message: 'Errore durante l\'aggiunta al preventivo.' };
            })
            .catch(function(error) {
                alert((error && error.message) ? error.message : 'Errore durante l\'aggiunta al preventivo.');
                return null;
            })
            .finally(function() {
                addBtn.removeAttribute('aria-busy');
                addBtn.disabled = false;
                addBtn.textContent = 'Aggiungi al preventivo';
                syncAddToQuoteTooltip();
            });
        }

        btn.addEventListener('click', function() {
            runAvailabilityCheck({ auto: false });
        });

        dateFields.forEach(function(field) {
            field.addEventListener('click', function() {
                if (!state.cartLocked) {
                    return;
                }
                setCartNote(lockedDatesMessage, true);
            });
        });

        [inputRitiro, inputRiconsegna].forEach(function(input) {
            input.addEventListener('change', function() {
                if (state.cartLocked) {
                    setCartNote(lockedDatesMessage, true);
                    return;
                }
                resetVerificationState();
            });
        });

        var qtyDebounceTimer = null;

        function handleQtyChange() {
            if (qtyDebounceTimer) {
                clearTimeout(qtyDebounceTimer);
                qtyDebounceTimer = null;
            }
            getQtyValue();
            if (hasCompleteDates() && (state.cartLocked || !btn.hidden || !quoteBox.hidden || !qtyRow.hidden)) {
                runAvailabilityCheck({ auto: true });
                return;
            }
            resetVerificationState();
        }

        function scheduleQtyChange() {
            if (qtyDebounceTimer) {
                clearTimeout(qtyDebounceTimer);
            }
            qtyDebounceTimer = setTimeout(handleQtyChange, 350);
        }

        if (dimensionCalculateBtn) {
            dimensionCalculateBtn.addEventListener('click', function() {
                calculateDimension(false);
            });
        }

        document.querySelectorAll('.bsn-dimension-preset').forEach(function(presetBtn) {
            presetBtn.addEventListener('click', function() {
                if (dimensionWidthInput) {
                    dimensionWidthInput.value = presetBtn.getAttribute('data-width-m') || '';
                }
                if (dimensionHeightInput) {
                    dimensionHeightInput.value = presetBtn.getAttribute('data-height-m') || '';
                }
                state.pendingPresetLabel = presetBtn.getAttribute('data-label') || '';
                calculateDimension(false);
                if (state.dimensionData) {
                    state.dimensionData.selected_preset_label = state.pendingPresetLabel || '';
                }
            });
        });

        if (dimensionWarning) {
            dimensionWarning.addEventListener('click', function(event) {
                if (event.target.closest('[data-dimension-use-technical]')) {
                    calculateDimension(true);
                    return;
                }
                if (event.target.closest('[data-dimension-modify]')) {
                    hideDimensionWarning();
                    if (dimensionWidthInput) {
                        dimensionWidthInput.focus();
                    }
                }
            });
        }

        if (dimensionNoteInput) {
            dimensionNoteInput.addEventListener('input', function() {
                if (state.dimensionData) {
                    state.dimensionData.dimension_note = getDimensionNote();
                }
            });
        }

        [dimensionWidthInput, dimensionHeightInput].forEach(function(input) {
            if (!input) {
                return;
            }
            input.addEventListener('input', function() {
                state.pendingPresetLabel = '';
            });
        });

        inputQty.addEventListener('change', function() {
            markDimensionManualQty();
            handleQtyChange();
        });
        inputQty.addEventListener('input', function() {
            markDimensionManualQty();
            scheduleQtyChange();
        });

        addBtn.addEventListener('click', function() {
            var qty = getQtyValue();

            if (addBtn.disabled || addBtn.getAttribute('aria-disabled') === 'true') {
                return;
            }

            if (!inputRitiro.value || !inputRiconsegna.value) {
                alert('Seleziona data ritiro e data riconsegna prima di aggiungere il prodotto al preventivo.');
                return;
            }
            if (!validateDateRange()) {
                return;
            }

            fetchCartState().then(function(cart) {
                if (cart && cart.item_count > 0 && cart.has_dates && !cart.invalid_dates) {
                    var cartRitiro = String(cart.dates.data_ritiro || '');
                    var cartRiconsegna = String(cart.dates.data_riconsegna || '');
                    if (inputRitiro.value !== cartRitiro || inputRiconsegna.value !== cartRiconsegna) {
                        applyCartState(cart, 'stale');
                        alert('Questo prodotto era aperto con date diverse da quelle del carrello. Le date sono state riallineate: verifica il prodotto e poi ripeti l\'aggiunta.');
                        runAvailabilityCheck({ auto: true });
                        return;
                    }
                }

                if (!state.cartLocked && !state.verified) {
                    alert('Prima di aggiungere al preventivo, verifica la disponibilita per le date selezionate.');
                    return;
                }
                if (qty > state.lastAvailableUnits) {
                    alert('Quantita richiesta superiore a quella disponibile per il periodo selezionato.');
                    return;
                }

                submitAddToCart(false);
            });
        });

        syncDateDisplays();
        resetQuotePreviewBox();
        disableAddButton();
        showAddButtonSection();
        showQtyRow();

        getQtyValue();
        fetchCartState().then(function(cart) {
            if (applyCartState(cart, 'load')) {
                runAvailabilityCheck({ auto: true });
            } else {
                lockDateInputs(false);
                showIntroBox();
                showCheckButton();
                showQtyRow();
                showAddButtonSection();
            }
        });

        function openDatePicker(input) {
            if (!input || input.disabled) {
                return;
            }

            try {
                if (typeof input.showPicker === 'function') {
                    input.showPicker();
                    return;
                }
            } catch (error) {
            }

            input.focus();
        }

        dateShells.forEach(function(shell) {
            var input = shell.querySelector('input[type="date"]');
            if (!input) {
                return;
            }

            shell.addEventListener('click', function(event) {
                if (input.disabled) {
                    return;
                }
                if (event.target === input) {
                    return;
                }
                event.preventDefault();
                openDatePicker(input);
            });
        });

        [inputRitiro, inputRiconsegna].forEach(function(input) {
            input.addEventListener('click', function() {
                openDatePicker(input);
            });
        });

        [inputRitiro, inputRiconsegna].forEach(function(input) {
            input.addEventListener('change', syncDateDisplays);
            input.addEventListener('input', syncDateDisplays);
            input.addEventListener('change', syncAddToQuoteTooltip);
            input.addEventListener('input', syncAddToQuoteTooltip);
        });
    })();
    </script>
    <?php
endwhile;

get_footer();
















