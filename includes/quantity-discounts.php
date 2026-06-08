<?php
/**
 * Linear quantity discount helpers.
 */

if ( ! function_exists( 'bsn_quantity_discount_to_float' ) ) {
    function bsn_quantity_discount_to_float( $value ) {
        if ( is_string( $value ) ) {
            $value = str_replace( ',', '.', trim( $value ) );
        }

        return is_numeric( $value ) ? (float) $value : 0.0;
    }
}

if ( ! function_exists( 'bsn_normalize_quantity_discount_config' ) ) {
    function bsn_normalize_quantity_discount_config( $config, $fallback_max_qty = null ) {
        $config = is_array( $config ) ? $config : array();
        $enabled = ! empty( $config['enabled'] ) || ! empty( $config['qty_discount_enabled'] );
        $start_qty = (int) max( 0, bsn_quantity_discount_to_float( $config['start_qty'] ?? ( $config['qty_discount_start_qty'] ?? 0 ) ) );

        $raw_max_qty = $config['max_qty'] ?? ( $config['qty_discount_max_qty'] ?? null );
        $max_qty = $raw_max_qty === null || $raw_max_qty === ''
            ? (int) max( 0, bsn_quantity_discount_to_float( $fallback_max_qty ) )
            : (int) max( 0, bsn_quantity_discount_to_float( $raw_max_qty ) );

        $max_discount_pct = bsn_quantity_discount_to_float(
            $config['max_discount_pct'] ?? ( $config['qty_discount_max_pct'] ?? 0 )
        );
        $max_discount_pct = max( 0.0, min( 100.0, $max_discount_pct ) );

        return array(
            'enabled' => (bool) $enabled,
            'start_qty' => $start_qty,
            'max_qty' => $max_qty,
            'max_discount_pct' => $max_discount_pct,
        );
    }
}

if ( ! function_exists( 'bsn_get_quantity_discount_config_from_row' ) ) {
    function bsn_get_quantity_discount_config_from_row( $article_row ) {
        $article_row = is_array( $article_row ) ? $article_row : array();
        $fallback_max_qty = $article_row['qty_disponibile'] ?? null;

        return bsn_normalize_quantity_discount_config(
            array(
                'qty_discount_enabled' => $article_row['qty_discount_enabled'] ?? 0,
                'qty_discount_start_qty' => $article_row['qty_discount_start_qty'] ?? 0,
                'qty_discount_max_qty' => $article_row['qty_discount_max_qty'] ?? null,
                'qty_discount_max_pct' => $article_row['qty_discount_max_pct'] ?? 0,
            ),
            $fallback_max_qty
        );
    }
}

if ( ! function_exists( 'bsn_get_quantity_discount_config' ) ) {
    function bsn_get_quantity_discount_config( $article_id ) {
        if ( is_array( $article_id ) ) {
            return bsn_get_quantity_discount_config_from_row( $article_id );
        }

        $article_id = function_exists( 'absint' ) ? absint( $article_id ) : abs( (int) $article_id );
        if ( $article_id < 1 || ! isset( $GLOBALS['wpdb'] ) ) {
            return bsn_normalize_quantity_discount_config( array() );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bs_articoli';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT qty_disponibile, qty_discount_enabled, qty_discount_start_qty, qty_discount_max_qty, qty_discount_max_pct FROM $table WHERE id = %d",
                $article_id
            ),
            ARRAY_A
        );

        return bsn_get_quantity_discount_config_from_row( $row );
    }
}

if ( ! function_exists( 'bsn_calculate_linear_quantity_discount' ) ) {
    function bsn_calculate_linear_quantity_discount( $base_unit_price, $qty, $config ) {
        $base_unit_price = max( 0.0, bsn_quantity_discount_to_float( $base_unit_price ) );
        $qty = max( 0.0, bsn_quantity_discount_to_float( $qty ) );
        $config = bsn_normalize_quantity_discount_config( $config );

        $discount_pct = 0.0;
        if (
            $config['enabled'] &&
            $base_unit_price > 0 &&
            $qty > 0 &&
            $config['start_qty'] > 0 &&
            $config['max_discount_pct'] > 0 &&
            $qty >= $config['start_qty']
        ) {
            if ( $config['max_qty'] <= $config['start_qty'] || $qty >= $config['max_qty'] ) {
                $discount_pct = $config['max_discount_pct'];
            } else {
                $discount_pct = ( ( $qty - $config['start_qty'] ) / ( $config['max_qty'] - $config['start_qty'] ) ) * $config['max_discount_pct'];
            }
        }

        $discount_pct = max( 0.0, min( 100.0, $discount_pct ) );
        $unit_discount_amount = $base_unit_price * ( $discount_pct / 100 );
        $unit_price = max( 0.0, $base_unit_price - $unit_discount_amount );

        return array(
            'enabled' => (bool) $config['enabled'],
            'base_unit_price' => $base_unit_price,
            'qty' => $qty,
            'start_qty' => $config['start_qty'],
            'max_qty' => $config['max_qty'],
            'max_discount_pct' => $config['max_discount_pct'],
            'discount_pct' => $discount_pct,
            'unit_discount_amount' => $unit_discount_amount,
            'unit_price' => $unit_price,
            'line_total_before_discount' => $base_unit_price * $qty,
            'line_total_after_discount' => $unit_price * $qty,
            'line_discount_amount' => ( $base_unit_price - $unit_price ) * $qty,
            'applied' => $discount_pct > 0,
        );
    }
}

if ( ! function_exists( 'bsn_get_quantity_discount_price_range' ) ) {
    function bsn_get_quantity_discount_price_range( $article_id, $base_unit_price ) {
        $config = bsn_get_quantity_discount_config( $article_id );
        $base_unit_price = max( 0.0, bsn_quantity_discount_to_float( $base_unit_price ) );

        if ( ! $config['enabled'] || $base_unit_price <= 0 || $config['max_discount_pct'] <= 0 ) {
            return array(
                'enabled' => false,
                'max_price' => $base_unit_price,
                'min_price' => $base_unit_price,
                'max_discount_pct' => 0.0,
            );
        }

        $max_discount = bsn_calculate_linear_quantity_discount(
            $base_unit_price,
            max( $config['start_qty'], $config['max_qty'] ),
            $config
        );

        return array(
            'enabled' => true,
            'max_price' => $base_unit_price,
            'min_price' => $max_discount['unit_price'],
            'max_discount_pct' => $config['max_discount_pct'],
            'start_qty' => $config['start_qty'],
            'max_qty' => $config['max_qty'],
        );
    }
}
