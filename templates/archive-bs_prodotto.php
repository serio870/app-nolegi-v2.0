<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
$customer_category = function_exists( 'bsn_get_current_public_customer_category' ) ? bsn_get_current_public_customer_category() : 'standard';
$customer_category_label = function_exists( 'bsn_get_public_customer_category_label' ) ? bsn_get_public_customer_category_label( $customer_category ) : 'Guest / standard';
$rental_dates = function_exists( 'bsn_get_current_public_rental_dates' ) ? bsn_get_current_public_rental_dates() : [ 'valid' => false ];
$has_rental_dates = ! empty( $rental_dates['valid'] );
?>
<main class="bsn-public-page bsn-archive-page">
    <div class="bsn-public-shell">
        <header class="bsn-archive-header">
            <p class="bsn-eyebrow">Catalogo noleggio</p>
            <h1><?php post_type_archive_title(); ?></h1>
            <p>Esplora i prodotti pubblici collegati al gestionale Black Star Noleggi. La disponibilit&agrave; visualizzata deriva dagli articoli interni reali.</p>
        </header>

        <?php
        if ( function_exists( 'bsn_render_public_rental_dates_bar' ) ) {
            echo bsn_render_public_rental_dates_bar();
        }
        ?>

        <?php if ( have_posts() ) : ?>
            <div class="bsn-card-grid">
                <?php
                while ( have_posts() ) :
                    the_post();
                    $product_id = get_the_ID();
                    $meta = bsn_get_public_product_meta( $product_id );
                    $gallery_urls = bsn_get_public_product_gallery_urls( $product_id );
                    $price_standard = bsn_get_public_product_price_from( $product_id, 'standard' );
                    $price_reserved = $customer_category !== 'standard' ? bsn_get_public_product_price_from( $product_id, $customer_category ) : null;
                    $price_label = function_exists( 'bsn_get_public_product_tariff_label' ) ? bsn_get_public_product_tariff_label( $product_id ) : 'Tariffa 1 giorno';
                    $show_standard_price = $price_standard !== null && $price_reserved !== null && $price_reserved < $price_standard;
                    $display_price = $show_standard_price ? $price_reserved : $price_standard;
                    $display_standard = $price_standard;

                    if ( $has_rental_dates && function_exists( 'bsn_get_public_product_quote_preview' ) ) {
                        $quote_preview = bsn_get_public_product_quote_preview(
                            $product_id,
                            1,
                            $rental_dates['data_ritiro'],
                            $rental_dates['data_riconsegna'],
                            $customer_category
                        );
                        if ( ! empty( $quote_preview['success'] ) ) {
                            $display_price = (float) $quote_preview['totale_stimato'];
                            $display_standard = null;
                            $show_standard_price = false;
                            if ( $price_label === 'Tariffa 1 giorno' ) {
                                $price_label = 'Stima periodo';
                            }
                        }
                    }

                    $product_link = function_exists( 'bsn_append_public_rental_dates_to_url' )
                        ? bsn_append_public_rental_dates_to_url( get_permalink(), $rental_dates )
                        : get_permalink();
                    ?>
                    <article <?php post_class( 'bsn-public-product-card' ); ?>>
                        <div class="bsn-card-media">
                            <?php if ( ! empty( $gallery_urls ) ) : ?>
                                <img src="<?php echo esc_url( $gallery_urls[0] ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy">
                            <?php else : ?>
                                <span>Nessuna immagine</span>
                            <?php endif; ?>
                        </div>
                        <div class="bsn-card-body">
                            <h2 class="bsn-card-title"><a href="<?php echo esc_url( $product_link ); ?>"><?php the_title(); ?></a></h2>
                            <?php if ( ! empty( $meta['sottotitolo_catalogo'] ) ) : ?>
                                <p class="bsn-card-subtitle"><?php echo esc_html( $meta['sottotitolo_catalogo'] ); ?></p>
                            <?php else : ?>
                                <p class="bsn-card-subtitle bsn-card-subtitle-empty">&nbsp;</p>
                            <?php endif; ?>
                            <div class="bsn-card-availability">
                                <?php
                                echo bsn_render_public_product_availability_html(
                                    $product_id,
                                    $has_rental_dates ? $rental_dates['data_ritiro'] : '',
                                    $has_rental_dates ? $rental_dates['data_riconsegna'] : '',
                                    [ 'title' => '', 'compact' => true ]
                                );
                                ?>
                            </div>
                            <div class="bsn-card-content-spacer"></div>
                            <div class="bsn-card-footer">
                                <div class="bsn-card-price-stack">
                                    <div class="bsn-card-price-label"><?php echo esc_html( $price_label ); ?></div>
                                    <?php if ( null !== $display_price ) : ?>
                                        <?php if ( $show_standard_price && null !== $display_standard ) : ?>
                                            <div class="bsn-card-price-standard"><?php echo esc_html( number_format_i18n( $display_standard, 2 ) ); ?> &euro;</div>
                                            <div class="bsn-card-price-reserved"><?php echo esc_html( number_format_i18n( $display_price, 2 ) ); ?> &euro;</div>
                                        <?php else : ?>
                                            <div class="bsn-card-price"><?php echo esc_html( number_format_i18n( $display_price, 2 ) ); ?> &euro;</div>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <div class="bsn-card-price bsn-card-price-on-request">Prezzo su richiesta</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endwhile; ?>
            </div>

            <div class="bsn-pagination">
                <?php the_posts_pagination(); ?>
            </div>
        <?php else : ?>
            <div class="bsn-public-card">
                <h2>Nessun prodotto disponibile</h2>
                <p>Il catalogo pubblico &egrave; in aggiornamento.</p>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();
