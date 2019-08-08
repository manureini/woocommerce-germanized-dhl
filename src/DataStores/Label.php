<?php

namespace Vendidero\Germanized\DHL\DataStores;
use WC_Data_Store_WP;
use WC_Object_Data_Store_Interface;
use Exception;
use WC_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract Order Data Store: Stored in CPT.
 *
 * @version  3.0.0
 */
class Label extends WC_Data_Store_WP implements WC_Object_Data_Store_Interface {

    /**
     * Internal meta type used to store order data.
     *
     * @var string
     */
    protected $meta_type = 'gzd_dhl_label';

    protected $core_props = array(
        'shipment_id',
        'number',
        'path',
        'export_path',
        'dhl_product',
        'date_created',
        'date_created_gmt',
    );

    /**
     * Data stored in meta keys, but not considered "meta" for an order.
     *
     * @since 3.0.0
     * @var array
     */
    protected $internal_meta_keys = array(
        '_preferred_day',
        '_preferred_time',
        '_preferred_location',
	    '_preferred_neighbor',
	    '_ident_date_of_birth',
	    '_ident_min_age',
	    '_visual_min_age',
	    '_email_notification',
	    '_codeable_address_only',
	    '_return_address',
        '_return',
        '_services'
    );

    /*
    |--------------------------------------------------------------------------
    | CRUD Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Method to create a new shipment in the database.
     *
     * @param \Vendidero\Germanized\DHL\Label $label Label object.
     */
    public function create( &$label ) {
        global $wpdb;

        $label->set_date_created( current_time( 'timestamp', true ) );

        $data = array(
            'label_number'              => $label->get_number(),
            'label_shipment_id'         => $label->get_shipment_id(),
            'label_path'                => $label->get_path(),
            'label_export_path'         => $label->get_export_path(),
            'label_dhl_product'         => $label->get_dhl_product(),
            'label_date_created'        => gmdate( 'Y-m-d H:i:s', $label->get_date_created( 'edit' )->getOffsetTimestamp() ),
            'label_date_created_gmt'    => gmdate( 'Y-m-d H:i:s', $label->get_date_created( 'edit' )->getTimestamp() ),
        );

        $wpdb->insert(
            $wpdb->gzd_dhl_labels,
            $data
        );

        $label_id = $wpdb->insert_id;

        if ( $label_id ) {
            $label->set_id( $label_id );

            $this->save_label_data( $label );

            $label->save_meta_data();
            $label->apply_changes();

            $this->clear_caches( $label );

            do_action( 'woocommerce_gzd_new_dhl_label', $label_id );
        }
    }

    /**
     * Method to update a shipment in the database.
     *
     * @param \Vendidero\Germanized\DHL\Label $label Label object.
     */
    public function update( &$label ) {
        global $wpdb;

        $updated_props = array();
        $core_props    = $this->core_props;
        $changed_props = array_keys( $label->get_changes() );
        $label_data    = array();

        foreach ( $changed_props as $prop ) {

            if ( ! in_array( $prop, $core_props, true ) ) {
                continue;
            }

            switch( $prop ) {
                case "date_created":
                    $label_data[ 'label' . $prop ]           = gmdate( 'Y-m-d H:i:s', $label->{'get_' . $prop}( 'edit' )->getOffsetTimestamp() );
                    $label_data[ 'label_' . $prop . '_gmt' ] = gmdate( 'Y-m-d H:i:s', $label->{'get_' . $prop}( 'edit' )->getTimestamp() );
                    break;
                default:
                    $label_data[ 'label_' . $prop ] = $label->{'get_' . $prop}( 'edit' );
                    break;
            }
        }

        if ( ! empty( $shipment_data ) ) {
            $wpdb->update(
                $wpdb->gzd_dhl_labels,
                $label_data,
                array( 'label_id' => $label->get_id() )
            );
        }

        $this->save_label_data( $label );
        $label->save_meta_data();

        $label->apply_changes();
        $this->clear_caches( $label );

        do_action( 'woocommerce_gzd_dhl_label_updated', $label->get_id() );
    }

    /**
     * Remove a shipment from the database.
     *
     * @since 3.0.0
     * @param \Vendidero\Germanized\DHL\Label $label Label object.
     * @param bool                $force_delete Unused param.
     */
    public function delete( &$label, $force_delete = false ) {
        global $wpdb;

        // Delete files
        if ( $file = $label->get_file() ) {
            wp_delete_file( $file );
        }

        if ( $file_export = $label->get_export_file() ) {
            wp_delete_file( $file_export );
        }

        $wpdb->delete( $wpdb->gzd_dhl_labels, array( 'label_id' => $label->get_id() ), array( '%d' ) );
        $wpdb->delete( $wpdb->gzd_dhl_labelmeta, array( 'label_id' => $label->get_id() ), array( '%d' ) );

        $this->clear_caches( $label );

        do_action( 'woocommerce_gzd_dhl_label_deleted', $label->get_id(), $label );
    }

    /**
     * Read a shipment from the database.
     *
     * @since 3.0.0
     *
     * @param \Vendidero\Germanized\DHL\Label $label Label object.
     *
     * @throws Exception Throw exception if invalid shipment.
     */
    public function read( &$label ) {
        global $wpdb;

        $data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->gzd_dhl_labels} WHERE label_id = %d LIMIT 1",
                $label->get_id()
            )
        );

        if ( $data ) {
            $label->set_props(
                array(
                    'shipment_id'  => $data->label_shipment_id,
                    'number'       => $data->label_number,
                    'path'         => $data->label_path,
                    'export_path'  => $data->label_export_path,
                    'dhl_product'  => $data->label_dhl_product,
                    'date_created' => 0 < $data->label_date_created_gmt ? wc_string_to_timestamp( $data->label_date_created_gmt ) : null,
                )
            );

            $this->read_label_data( $label );
            $label->read_meta_data();
            $label->set_object_read( true );

            do_action( 'woocommerce_gzd_label_loaded', $label );
        } else {
            throw new Exception( __( 'Invalid label.', 'woocommerce-germanized-dhl' ) );
        }
    }

    /**
     * Clear any caches.
     *
     * @param \Vendidero\Germanized\DHL\Label $label Label object.
     * @since 3.0.0
     */
    protected function clear_caches( &$label ) {
        wp_cache_delete( $label->get_id(), $this->meta_type . '_meta' );
    }

    /*
    |--------------------------------------------------------------------------
    | Additional Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Read extra data associated with the shipment.
     *
     * @param \Vendidero\Germanized\DHL\Label $label Label object.
     * @since 3.0.0
     */
    protected function read_label_data( &$label ) {
        $props = array();

        foreach( $this->internal_meta_keys as $meta_key ) {
            $props[ substr( $meta_key, 1 ) ] = get_metadata( 'gzd_dhl_label', $label->get_id(), $meta_key, true );
        }

        $label->set_props( $props );
    }

    protected function save_label_data( &$label ) {
        $updated_props     = array();
        $meta_key_to_props = array();

        foreach( $this->internal_meta_keys as $meta_key ) {
            $prop_name = substr( $meta_key, 1 );

            if ( in_array( $prop_name, $this->core_props ) ) {
                continue;
            }

            $meta_key_to_props[ $meta_key ] = $prop_name;
        }

        $props_to_update = $this->get_props_to_update( $label, $meta_key_to_props, 'gzd_dhl_label' );

        foreach ( $props_to_update as $meta_key => $prop ) {

            $value = $label->{"get_$prop"}( 'edit' );
            $value = is_string( $value ) ? wp_slash( $value ) : $value;

            switch ( $prop ) {
                case "preferred_day":
                    $value = $value ? strtotime( date("Y-m-d", $value->getOffsetTimestamp() ) ) : '';
                    break;
	            case "preferred_time":
		            $value = $value ? strtotime( date("H:i:s", $value->getOffsetTimestamp() ) ) : '';
		            break;
	            case "email_notification":
	            case "return":
	            case "codeable_address_only":
		            $value = wc_bool_to_string( $value );
		            break;
            }

            $updated = $this->update_or_delete_meta( $label, $meta_key, $value );

            if ( $updated ) {
                $updated_props[] = $prop;
            }
        }

        do_action( 'woocommerce_gzd_dhl_label_object_updated_props', $label, $updated_props );
    }

    /**
     * Update meta data in, or delete it from, the database.
     *
     * Avoids storing meta when it's either an empty string or empty array.
     * Other empty values such as numeric 0 and null should still be stored.
     * Data-stores can force meta to exist using `must_exist_meta_keys`.
     *
     * Note: WordPress `get_metadata` function returns an empty string when meta data does not exist.
     *
     * @param WC_Data $object The WP_Data object (WC_Coupon for coupons, etc).
     * @param string  $meta_key Meta key to update.
     * @param mixed   $meta_value Value to save.
     *
     * @since 3.6.0 Added to prevent empty meta being stored unless required.
     *
     * @return bool True if updated/deleted.
     */
    protected function update_or_delete_meta( $object, $meta_key, $meta_value ) {
        if ( in_array( $meta_value, array( array(), '' ), true ) && ! in_array( $meta_key, $this->must_exist_meta_keys, true ) ) {
            $updated = delete_metadata( 'gzd_dhl_label', $object->get_id(), $meta_key );
        } else {
            $updated = update_metadata( 'gzd_dhl_label', $object->get_id(), $meta_key, $meta_value );
        }

        return (bool) $updated;
    }

    /**
     * Get valid WP_Query args from a WC_Order_Query's query variables.
     *
     * @since 3.1.0
     * @param array $query_vars query vars from a WC_Order_Query.
     * @return array
     */
    protected function get_wp_query_args( $query_vars ) {
        global $wpdb;

        $wp_query_args = parent::get_wp_query_args( $query_vars );

        if ( ! isset( $wp_query_args['date_query'] ) ) {
            $wp_query_args['date_query'] = array();
        }

        if ( ! isset( $wp_query_args['meta_query'] ) ) {
            $wp_query_args['meta_query'] = array();
        }

        // Allow Woo to treat these props as date query compatible
        $date_queries = array(
            'date_created' => 'post_date',
        );

        foreach ( $date_queries as $query_var_key => $db_key ) {
            if ( isset( $query_vars[ $query_var_key ] ) && '' !== $query_vars[ $query_var_key ] ) {

                // Remove any existing meta queries for the same keys to prevent conflicts.
                $existing_queries = wp_list_pluck( $wp_query_args['meta_query'], 'key', true );
                $meta_query_index = array_search( $db_key, $existing_queries, true );

                if ( false !== $meta_query_index ) {
                    unset( $wp_query_args['meta_query'][ $meta_query_index ] );
                }

                $wp_query_args = $this->parse_date_for_wp_query( $query_vars[ $query_var_key ], $db_key, $wp_query_args );
            }
        }

        /**
         * Replace date query columns after Woo parsed dates.
         * Include table name because otherwise WP_Date_Query won't accept our custom column.
         */
        if ( isset( $wp_query_args['date_query'] ) ) {
            foreach( $wp_query_args['date_query'] as $key => $date_query ) {
                if ( isset( $date_query['column'] ) && in_array( $date_query['column'], $date_queries, true ) ) {
                    $wp_query_args['date_query'][ $key ]['column'] = $wpdb->gzd_dhl_labels . '.label_' . array_search( $date_query['column'], $date_queries, true );
                }
            }
        }

        if ( ! isset( $query_vars['paginate'] ) || ! $query_vars['paginate'] ) {
            $wp_query_args['no_found_rows'] = true;
        }

        return apply_filters( 'woocommerce_gzd_dhl_label_data_store_get_labels_query', $wp_query_args, $query_vars, $this );
    }

    public function get_query_args( $query_vars ) {
        return $this->get_wp_query_args( $query_vars );
    }

    /**
     * Table structure is slightly different between meta types, this function will return what we need to know.
     *
     * @since  3.0.0
     * @return array Array elements: table, object_id_field, meta_id_field
     */
    protected function get_db_info() {
        global $wpdb;

        $meta_id_field   = 'meta_id'; // for some reason users calls this umeta_id so we need to track this as well.
        $table           = $wpdb->gzd_dhl_labelmeta;
        $object_id_field = $this->meta_type . '_id';

        if ( ! empty( $this->object_id_field_for_meta ) ) {
            $object_id_field = $this->object_id_field_for_meta;
        }

        return array(
            'table'           => $table,
            'object_id_field' => $object_id_field,
            'meta_id_field'   => $meta_id_field,
        );
    }

    public function get_label_count() {
        global $wpdb;

        return absint( $wpdb->get_var( "SELECT COUNT( * ) FROM {$wpdb->gzd_dhl_labels}" ) );
    }
}
