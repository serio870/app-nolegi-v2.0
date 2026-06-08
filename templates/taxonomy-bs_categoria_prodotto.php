<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
$term = get_queried_object();
$customer_category = function_exists( 'bsn_get_current_public_customer_category' ) ? bsn_get_current_public_customer_category() : 'standard';
$customer_category_label = function_exists( 'bsn_get_public_customer_category_label' ) ? bsn_get_public_customer_category_label( $customer_category ) : 'Guest / standard';
$rental_dates = function_exists( 'bsn_get_current_public_rental_dates' ) ? bsn_get_current_public_rental_dates() : [ 'valid' => false ];
$has_rental_dates = ! empty( $rental_dates['valid'] );
$is_root_category = $term && isset( $term->parent ) && (int) $term->parent === 0;
$child_terms = [];
if ( $term && ! empty( $term->term_id ) ) {
    $child_terms = get_terms([
        'taxonomy'   => 'bs_categoria_prodotto',
        'hide_empty' => false,
        'parent'     => (int) $term->term_id,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if ( is_wp_error( $child_terms ) ) {
        $child_terms = [];
    }
    if ( function_exists( 'bsn_sort_categoria_prodotto_terms_by_order' ) ) {
        $child_terms = bsn_sort_categoria_prodotto_terms_by_order( $child_terms );
    }
}

$bsn_products_query = null;
$bsn_products_have_posts = have_posts();
if ( ! $bsn_products_have_posts && $term && ! empty( $term->term_id ) ) {
    $paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
    $per_page = (int) apply_filters( 'bsn_public_catalog_posts_per_page', 15 );
    $bsn_products_query = new WP_Query([
        'post_type'           => 'bs_prodotto',
        'post_status'         => 'publish',
        'posts_per_page'      => max( 1, $per_page ),
        'paged'               => $paged,
        'ignore_sticky_posts' => true,
        'tax_query'           => [
            [
                'taxonomy'         => 'bs_categoria_prodotto',
                'field'            => 'term_id',
                'terms'            => [ (int) $term->term_id ],
                'include_children' => true,
            ],
        ],
    ]);
    $bsn_products_have_posts = $bsn_products_query->have_posts();
}
?>
<main class="bsn-public-page bsn-taxonomy-page">
    <div class="bsn-public-shell">
        <header class="bsn-archive-header">
            <p class="bsn-eyebrow">Categoria noleggio</p>
            <h1><?php echo esc_html( single_term_title( '', false ) ); ?></h1>
            <?php if ( $term && ! empty( $term->description ) ) : ?>
                <div class="bsn-tax-description"><?php echo wp_kses_post( wpautop( $term->description ) ); ?></div>
            <?php else : ?>
                <p>Prodotti pubblici disponibili in questa categoria.</p>
            <?php endif; ?>
        </header>

        <?php
        if ( $is_root_category && function_exists( 'bsn_render_public_rental_dates_bar' ) ) {
            echo bsn_render_public_rental_dates_bar();
        }
        ?>

        <?php if ( ! empty( $child_terms ) ) : ?>
            <section class="bsn-public-card bsn-term-section">
                <h2>Sottocategorie</h2>
                <div class="bsn-card-grid bsn-term-grid">
                    <?php foreach ( $child_terms as $child_term ) : ?>
                        <?php
                        $child_meta = function_exists( 'bsn_get_categoria_prodotto_meta' ) ? bsn_get_categoria_prodotto_meta( $child_term->term_id ) : [];
                        $child_image = trim( (string) ( $child_meta['image_url'] ?? '' ) );
                        $child_desc = trim( (string) ( $child_meta['sottotitolo'] ?? '' ) );
                        if ( $child_desc === '' && ! empty( $child_term->description ) ) {
                            $child_desc = wp_trim_words( wp_strip_all_tags( $child_term->description ), 14 );
                        }
                        $child_url = get_term_link( $child_term );
                        if ( ! is_wp_error( $child_url ) && function_exists( 'bsn_append_public_rental_dates_to_url' ) ) {
                            $child_url = bsn_append_public_rental_dates_to_url( $child_url, $rental_dates );
                        }
                        $term_card_classes = 'bsn-term-card' . ( $child_image !== '' ? ' has-image' : '' );
                        $term_card_style = $child_image !== '' ? ' style="background-image: url(' . esc_url( $child_image ) . ');"' : '';
                        ?>
                        <a class="<?php echo esc_attr( $term_card_classes ); ?>" href="<?php echo esc_url( $child_url ); ?>"<?php echo $term_card_style; ?>>
                            <span class="bsn-term-card-title"><?php echo esc_html( $child_term->name ); ?></span>
                            <span class="bsn-term-card-desc"><?php echo esc_html( $child_desc !== '' ? $child_desc : 'Apri categoria' ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ( $bsn_products_have_posts ) : ?>
            <section class="bsn-term-section">
                <h2>Prodotti</h2>
                <div class="bsn-card-grid">
                    <?php
                    while ( $bsn_products_query ? $bsn_products_query->have_posts() : have_posts() ) :
                        if ( $bsn_products_query ) {
                            $bsn_products_query->the_post();
                        } else {
                            the_post();
                        }
                        $product_id = get_the_ID();
                        $meta = bsn_get_public_product_meta( $product_id );
                        $gallery_urls = bsn_get_public_product_gallery_urls( $product_id );
                        $price_standard = bsn_get_public_product_price_from( $product_id, 'standard' );
                        $price_reserved = $customer_category !== 'standard' ? bsn_get_public_product_price_from( $product_id, $customer_category ) : null;
                        $price_label = function_exists( 'bsn_get_public_product_tariff_label' ) ? bsn_get_public_product_tariff_label( $product_id ) : 'Tariffa 1 giorno';
                        $show_standard_price = $price_standard !== null && $price_reserved !== null && $price_reserved < $price_standard;
                        $display_price = $show_standard_price ? $price_reserved : $price_standard;
                        $display_standard = $price_standard;
                        $price_range = ! $has_rental_dates && function_exists( 'bsn_get_public_product_quantity_discount_price_range' )
                            ? bsn_get_public_product_quantity_discount_price_range( $product_id, $customer_category )
                            : null;
                        $show_price_range = is_array( $price_range );

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
                                $show_price_range = false;
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
                                        <?php if ( $show_price_range && is_array( $price_range ) ) : ?>
                                            <div class="bsn-card-price"><?php echo esc_html( number_format_i18n( (float) $price_range['max_price'], 2 ) ); ?> - <?php echo esc_html( number_format_i18n( (float) $price_range['min_price'], 2 ) ); ?> &euro; <small class="bsn-vat-note-inline">+ IVA</small></div>
                                        <?php elseif ( null !== $display_price ) : ?>
                                            <?php if ( $show_standard_price && null !== $display_standard ) : ?>
                                                <div class="bsn-card-price-standard"><?php echo esc_html( number_format_i18n( $display_standard, 2 ) ); ?> &euro;</div>
                                                <div class="bsn-card-price-reserved"><?php echo esc_html( number_format_i18n( $display_price, 2 ) ); ?> &euro; <small class="bsn-vat-note-inline">+ IVA</small></div>
                                            <?php else : ?>
                                                <div class="bsn-card-price"><?php echo esc_html( number_format_i18n( $display_price, 2 ) ); ?> &euro; <small class="bsn-vat-note-inline">+ IVA</small></div>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <div class="bsn-card-price bsn-card-price-on-request">Prezzo su richiesta</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endwhile; ?>
                    <?php
                    if ( $bsn_products_query ) {
                        wp_reset_postdata();
                    }
                    ?>
                </div>
            </section>

            <div class="bsn-pagination">
                <?php
                if ( $bsn_products_query ) {
                    $pagination_args = [
                        'total'   => max( 1, (int) $bsn_products_query->max_num_pages ),
                        'current' => max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ),
                    ];
                    if ( $has_rental_dates ) {
                        $pagination_args['add_args'] = [
                            'data_ritiro' => $rental_dates['data_ritiro'],
                            'data_riconsegna' => $rental_dates['data_riconsegna'],
                        ];
                    }
                    echo paginate_links( $pagination_args );
                } else {
                    the_posts_pagination();
                }
                ?>
            </div>
        <?php else : ?>
            <div class="bsn-public-card">
                <h2>Nessun prodotto in questa categoria</h2>
                <p>Popoleremo questa sezione man mano che colleghiamo gli articoli al catalogo pubblico.</p>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();
