<?php
/**
 * Dimension calculator helpers for public products.
 */

if ( ! function_exists( 'bsn_get_dimension_calculation_type_options' ) ) {
    function bsn_get_dimension_calculation_type_options() {
        return array(
            'standard' => 'Standard / quantita normale',
            'dimensionale_modulare' => 'Dimensionale modulare',
            'dimensionale_mq' => 'Dimensione libera a mq',
            'dimensionale_lineare' => 'Metro lineare',
        );
    }
}

if ( ! function_exists( 'bsn_validate_dimension_calculation_type' ) ) {
    function bsn_validate_dimension_calculation_type( $type ) {
        $type = is_string( $type ) ? trim( $type ) : '';
        return array_key_exists( $type, bsn_get_dimension_calculation_type_options() ) ? $type : 'standard';
    }
}

if ( ! function_exists( 'bsn_dimension_to_float' ) ) {
    function bsn_dimension_to_float( $value ) {
        if ( is_string( $value ) ) {
            $value = str_replace( ',', '.', trim( $value ) );
        }

        return is_numeric( $value ) ? (float) $value : 0.0;
    }
}

if ( ! function_exists( 'bsn_cm_to_m' ) ) {
    function bsn_cm_to_m( $cm ) {
        $cm = bsn_dimension_to_float( $cm );
        return $cm > 0 ? $cm / 100 : 0.0;
    }
}

if ( ! function_exists( 'bsn_is_multiple_of_step' ) ) {
    function bsn_is_multiple_of_step( $value, $step ) {
        $value = bsn_dimension_to_float( $value );
        $step = bsn_dimension_to_float( $step );

        if ( $value <= 0 || $step <= 0 ) {
            return false;
        }

        $nearest = round( $value / $step ) * $step;
        return abs( $nearest - $value ) <= 0.00001;
    }
}

if ( ! function_exists( 'bsn_round_up_to_step' ) ) {
    function bsn_round_up_to_step( $value, $step ) {
        $value = bsn_dimension_to_float( $value );
        $step = bsn_dimension_to_float( $step );

        if ( $value <= 0 || $step <= 0 ) {
            return 0.0;
        }

        return round( ceil( ( $value - 0.00001 ) / $step ) * $step, 4 );
    }
}

if ( ! function_exists( 'bsn_normalize_dimension_config' ) ) {
    function bsn_normalize_dimension_config( $config ) {
        $config = is_array( $config ) ? $config : array();
        $enabled = ! empty( $config['enabled'] ) || ! empty( $config['dimension_calculator_enabled'] );
        $type = bsn_validate_dimension_calculation_type(
            $config['calculation_type'] ?? ( $config['dimension_calculation_type'] ?? 'standard' )
        );

        if ( $type === 'standard' ) {
            $enabled = false;
        }

        return array(
            'enabled' => (bool) $enabled,
            'calculation_type' => $type,
            'module_width_cm' => max( 0.0, bsn_dimension_to_float( $config['module_width_cm'] ?? ( $config['dimension_module_width_cm'] ?? 0 ) ) ),
            'module_height_cm' => max( 0.0, bsn_dimension_to_float( $config['module_height_cm'] ?? ( $config['dimension_module_height_cm'] ?? 0 ) ) ),
            'presets' => $config['presets'] ?? ( $config['dimension_presets'] ?? '' ),
            'customer_note' => trim( (string) ( $config['customer_note'] ?? ( $config['dimension_customer_note'] ?? '' ) ) ),
        );
    }
}

if ( ! function_exists( 'bsn_get_dimension_config' ) ) {
    function bsn_get_dimension_config( $product_id ) {
        $product_id = function_exists( 'absint' ) ? absint( $product_id ) : abs( (int) $product_id );
        if ( $product_id < 1 || ! function_exists( 'get_post_meta' ) ) {
            return bsn_normalize_dimension_config( array() );
        }

        return bsn_normalize_dimension_config(
            array(
                'dimension_calculator_enabled' => get_post_meta( $product_id, '_bsn_dimension_calculator_enabled', true ),
                'dimension_calculation_type' => get_post_meta( $product_id, '_bsn_dimension_calculation_type', true ),
                'dimension_module_width_cm' => get_post_meta( $product_id, '_bsn_dimension_module_width_cm', true ),
                'dimension_module_height_cm' => get_post_meta( $product_id, '_bsn_dimension_module_height_cm', true ),
                'dimension_presets' => get_post_meta( $product_id, '_bsn_dimension_presets', true ),
                'dimension_customer_note' => get_post_meta( $product_id, '_bsn_dimension_customer_note', true ),
            )
        );
    }
}

if ( ! function_exists( 'bsn_parse_dimension_presets' ) ) {
    function bsn_parse_dimension_presets( $raw_presets ) {
        if ( is_array( $raw_presets ) ) {
            $rows = $raw_presets;
        } else {
            $decoded = json_decode( (string) $raw_presets, true );
            $rows = is_array( $decoded ) ? $decoded : preg_split( '/\R+/', (string) $raw_presets );
        }

        $presets = array();
        foreach ( (array) $rows as $row ) {
            $label = '';
            $width = 0.0;
            $height = 0.0;
            $note = '';

            if ( is_array( $row ) ) {
                $label = trim( (string) ( $row['label'] ?? $row['name'] ?? '' ) );
                $width = bsn_dimension_to_float( $row['width_m'] ?? $row['larghezza_m'] ?? $row['width'] ?? 0 );
                $height = bsn_dimension_to_float( $row['height_m'] ?? $row['profondita_m'] ?? $row['altezza_m'] ?? $row['height'] ?? $row['depth'] ?? 0 );
                $note = trim( (string) ( $row['note'] ?? '' ) );
            } else {
                $line = trim( (string) $row );
                if ( $line === '' ) {
                    continue;
                }

                $parts = array_map( 'trim', preg_split( '/[|;]/', $line ) );
                if ( count( $parts ) >= 3 && preg_match( '/^([0-9]+(?:[,.][0-9]+)?)\s*[xX]\s*([0-9]+(?:[,.][0-9]+)?)/', $parts[1], $match ) ) {
                    $label = $parts[0];
                    $width = bsn_dimension_to_float( $match[1] );
                    $height = bsn_dimension_to_float( $match[2] );
                    $note = $parts[2] ?? '';
                } elseif ( count( $parts ) >= 3 ) {
                    $label = $parts[0];
                    $width = bsn_dimension_to_float( $parts[1] );
                    $height = bsn_dimension_to_float( $parts[2] );
                    $note = $parts[3] ?? '';
                } elseif ( preg_match( '/^(.+?)\s+([0-9]+(?:[,.][0-9]+)?)\s*[xX]\s*([0-9]+(?:[,.][0-9]+)?)(?:\s+(.+))?$/', $line, $match ) ) {
                    $label = trim( $match[1] );
                    $width = bsn_dimension_to_float( $match[2] );
                    $height = bsn_dimension_to_float( $match[3] );
                    $note = trim( $match[4] ?? '' );
                } elseif ( preg_match( '/^([0-9]+(?:[,.][0-9]+)?)\s*[xX]\s*([0-9]+(?:[,.][0-9]+)?)(?:\s+(.+))?$/', $line, $match ) ) {
                    $width = bsn_dimension_to_float( $match[1] );
                    $height = bsn_dimension_to_float( $match[2] );
                    $label = trim( $match[3] ?? '' );
                }
            }

            if ( $width <= 0 || $height <= 0 ) {
                continue;
            }

            if ( $label === '' ) {
                $label = bsn_format_dimension_number( $width ) . 'x' . bsn_format_dimension_number( $height ) . ' m';
            }

            $presets[] = array(
                'label' => $label,
                'width_m' => $width,
                'height_m' => $height,
                'note' => $note,
            );
        }

        return $presets;
    }
}

if ( ! function_exists( 'bsn_dimension_input_value' ) ) {
    function bsn_dimension_input_value( $input, $keys ) {
        foreach ( $keys as $key ) {
            if ( is_array( $input ) && array_key_exists( $key, $input ) ) {
                return bsn_dimension_to_float( $input[ $key ] );
            }
        }

        return 0.0;
    }
}

if ( ! function_exists( 'bsn_calculate_dimension_quantity_from_config' ) ) {
    function bsn_calculate_dimension_quantity_from_config( $config, $input ) {
        $config = bsn_normalize_dimension_config( $config );
        $input = is_array( $input ) ? $input : array();
        $type = $config['calculation_type'];

        $width = bsn_dimension_input_value( $input, array( 'requested_width_m', 'width_m', 'larghezza_m', 'width' ) );
        $height = bsn_dimension_input_value( $input, array( 'requested_height_m', 'height_m', 'profondita_m', 'altezza_m', 'height', 'depth' ) );

        $base = array(
            'success' => false,
            'calculation_type' => $type,
            'requested_width_m' => $width,
            'requested_height_m' => $height,
            'warnings' => array(),
            'errors' => array(),
            'summary' => '',
        );

        if ( ! $config['enabled'] || $type === 'standard' ) {
            $base['errors'][] = 'Calcolatrice dimensionale non attiva per questo prodotto.';
            return $base;
        }

        if ( $width <= 0 || $height <= 0 ) {
            $base['errors'][] = 'Inserisci larghezza e altezza/profondita maggiori di zero.';
            return $base;
        }

        if ( $type === 'dimensionale_mq' ) {
            $area = round( $width * $height, 4 );
            $result = array_merge(
                $base,
                array(
                    'success' => true,
                    'area_mq' => $area,
                    'qty_for_cart' => $area,
                )
            );
            $result['summary'] = bsn_format_dimension_summary( $result );
            return $result;
        }

        if ( $type !== 'dimensionale_modulare' ) {
            $base['errors'][] = 'Tipo calcolo dimensionale non implementato.';
            return $base;
        }

        $module_width_m = bsn_cm_to_m( $config['module_width_cm'] );
        $module_height_m = bsn_cm_to_m( $config['module_height_cm'] );

        if ( $module_width_m <= 0 || $module_height_m <= 0 ) {
            $base['errors'][] = 'Dimensioni modulo non valide.';
            return $base;
        }

        $compatible_width = bsn_is_multiple_of_step( $width, $module_width_m );
        $compatible_height = bsn_is_multiple_of_step( $height, $module_height_m );
        $suggested_width = bsn_round_up_to_step( $width, $module_width_m );
        $suggested_height = bsn_round_up_to_step( $height, $module_height_m );
        $modules_x = max( 1, (int) round( $suggested_width / $module_width_m ) );
        $modules_y = max( 1, (int) round( $suggested_height / $module_height_m ) );
        $modules_total = $modules_x * $modules_y;

        $result = array_merge(
            $base,
            array(
                'success' => true,
                'module_width_m' => $module_width_m,
                'module_height_m' => $module_height_m,
                'compatible_width' => $compatible_width,
                'compatible_height' => $compatible_height,
                'suggested_width_m' => $suggested_width,
                'suggested_height_m' => $suggested_height,
                'technical_width_m' => $suggested_width,
                'technical_height_m' => $suggested_height,
                'modules_x' => $modules_x,
                'modules_y' => $modules_y,
                'modules_total' => $modules_total,
                'requested_area_mq' => round( $width * $height, 4 ),
                'area_mq' => round( $suggested_width * $suggested_height, 4 ),
                'qty_for_cart' => $modules_total,
            )
        );

        if ( ! $compatible_width || ! $compatible_height ) {
            $result['warnings'][] = sprintf(
                'Misura non compatibile: questo articolo accetta multipli %.2f x %.2f m. Misura tecnica proposta: %.2f x %.2f m.',
                $module_width_m,
                $module_height_m,
                $suggested_width,
                $suggested_height
            );
        }

        $result['summary'] = bsn_format_dimension_summary( $result );
        return $result;
    }
}

if ( ! function_exists( 'bsn_calculate_dimension_quantity' ) ) {
    function bsn_calculate_dimension_quantity( $product_id, $input ) {
        return bsn_calculate_dimension_quantity_from_config( bsn_get_dimension_config( $product_id ), $input );
    }
}

if ( ! function_exists( 'bsn_format_dimension_number' ) ) {
    function bsn_format_dimension_number( $value ) {
        $value = bsn_dimension_to_float( $value );
        $formatted = number_format( $value, 2, ',', '' );
        return rtrim( rtrim( $formatted, '0' ), ',' );
    }
}

if ( ! function_exists( 'bsn_format_dimension_summary' ) ) {
    function bsn_format_dimension_summary( $calc ) {
        if ( ! is_array( $calc ) || empty( $calc['success'] ) ) {
            return '';
        }

        $width = bsn_format_dimension_number( $calc['suggested_width_m'] ?? $calc['requested_width_m'] ?? 0 );
        $height = bsn_format_dimension_number( $calc['suggested_height_m'] ?? $calc['requested_height_m'] ?? 0 );
        $area = bsn_format_dimension_number( $calc['area_mq'] ?? 0 );

        if ( ( $calc['calculation_type'] ?? '' ) === 'dimensionale_modulare' ) {
            return $width . 'x' . $height . ' m - ' . (int) ( $calc['modules_total'] ?? 0 ) . ' moduli - ' . $area . ' mq';
        }

        return $width . 'x' . $height . ' m - ' . $area . ' mq';
    }
}

if ( ! function_exists( 'bsn_build_direct_quantity_dimension_result' ) ) {
    function bsn_build_direct_quantity_dimension_result( $qty, $note = '' ) {
        $qty = max( 0, bsn_dimension_to_float( $qty ) );

        return array(
            'success' => true,
            'calculation_type' => 'direct_quantity',
            'qty_for_cart' => $qty,
            'dimension_note' => trim( (string) $note ),
            'summary' => '',
            'warnings' => array(),
            'errors' => array(),
        );
    }
}
