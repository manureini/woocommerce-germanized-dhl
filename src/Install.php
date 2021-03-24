<?php

namespace Vendidero\Germanized\DHL;

use Vendidero\Germanized\Shipments\ShippingProvider\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Main package class.
 */
class Install {

    public static function install() {
	    $current_version       = get_option( 'woocommerce_gzd_dhl_version', null );
	    $needs_settings_update = false;

	    self::create_upload_dir();
		self::create_db();

	    /**
	     * Older versions did not support custom versioning
	     */
	    if ( is_null( $current_version ) ) {
		    add_option( 'woocommerce_gzd_dhl_version', Package::get_version() );

		    // Legacy settings -> indicate update necessary
		    $needs_settings_update = ( get_option( 'woocommerce_gzd_dhl_enable' ) || get_option( 'woocommerce_gzd_deutsche_post_enable' ) ) && ! get_option( 'woocommerce_gzd_migrated_settings' );
	    }

	    if ( $needs_settings_update ) {
	    	self::migrate_settings();
	    }
    }

    private static function migrate_settings() {
	    global $wpdb;

	    /**
	     * Make sure to reload shipping providers to make sure our classes were registered accordingly as the
	     * install script may be called later than on plugins loaded.
	     */
	    Helper::instance()->load_shipping_providers();

	    $plugin_options   = $wpdb->get_results( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'woocommerce_gzd_dhl_%'" );
		$dhl              = wc_gzd_get_shipping_provider( 'dhl' );
	    $deutsche_post    = wc_gzd_get_shipping_provider( 'deutsche_post' );
	    $excluded_options = array(
	    	'woocommerce_gzd_dhl_upload_dir_suffix',
		    'woocommerce_gzd_dhl_enable',
		    'woocommerce_gzd_dhl_enable_internetmarke',
		    'woocommerce_gzd_dhl_internetmarke_enable',
		    'woocommerce_gzd_dhl_version'
	    );

	    /**
	     * Error while retrieving shipping provider instance
	     */
	    if ( ! is_a( $dhl, '\Vendidero\Germanized\DHL\ShippingProvider\DHL' ) || ! is_a( $deutsche_post, '\Vendidero\Germanized\DHL\ShippingProvider\DeutschePost' ) ) {
	    	return false;
	    }

	    foreach( $plugin_options as $option ) {
	    	$option_name  = $option->option_name;

	    	if ( in_array( $option_name, $excluded_options ) ) {
	    		continue;
		    }

			$option_value = get_option( $option->option_name, '' );
			$is_dp        = strpos( $option_name, '_im_' ) !== false || strpos( $option_name, '_internetmarke_' ) !== false || strpos( $option_name, '_deutsche_post_' ) !== false;

			if ( ! $is_dp ) {
				$option_name_clean = str_replace( 'woocommerce_gzd_dhl_', '', $option_name );

				if ( strpos( $option_name_clean, '_shipper_' ) !== false || strpos( $option_name_clean, '_return_address_' ) !== false ) {
					continue;
				} else {
					self::update_provider_setting( $dhl, $option_name_clean, $option_value );
				}
			} else {
				$option_name_clean = str_replace( 'woocommerce_gzd_dhl_', '', $option_name );
				$option_name_clean = str_replace( 'deutsche_post_', '', $option_name_clean );
				$option_name_clean = str_replace( 'im_', '', $option_name_clean );

				self::update_provider_setting( $deutsche_post, $option_name_clean, $option_value );
			}
	    }

	    $deutsche_post->set_label_default_shipment_weight( get_option( 'woocommerce_gzd_dhl_label_default_shipment_weight' ) );
	    $deutsche_post->set_label_minimum_shipment_weight( get_option( 'woocommerce_gzd_dhl_label_minimum_shipment_weight' ) );

	    // Update address data
	    $shipper_address = array(
		    'company'       => get_option( 'woocommerce_gzd_dhl_shipper_company' ),
		    'name'          => get_option( 'woocommerce_gzd_dhl_shipper_name' ),
		    'street'        => get_option( 'woocommerce_gzd_dhl_shipper_street' ),
		    'street_number' => get_option( 'woocommerce_gzd_dhl_shipper_street_no' ),
		    'postcode'      => get_option( 'woocommerce_gzd_dhl_shipper_postcode' ),
		    'country'       => get_option( 'woocommerce_gzd_dhl_shipper_country' ),
		    'city'          => get_option( 'woocommerce_gzd_dhl_shipper_city' ),
		    'phone'         => get_option( 'woocommerce_gzd_dhl_shipper_phone' ),
		    'email'         => get_option( 'woocommerce_gzd_dhl_shipper_email' ),
	    );

	    $shipper_address = array_filter( $shipper_address );

	    if ( ! empty( $shipper_address ) ) {
		    $dhl->set_shipper_address( $shipper_address );
		    $deutsche_post->set_shipper_address( $shipper_address );
	    }

	    $return_address = array(
		    'company'       => get_option( 'woocommerce_gzd_dhl_return_address_company' ),
		    'name'          => get_option( 'woocommerce_gzd_dhl_return_address_name' ),
		    'street'        => get_option( 'woocommerce_gzd_dhl_return_address_street' ),
		    'street_number' => get_option( 'woocommerce_gzd_dhl_return_address_street_no' ),
		    'postcode'      => get_option( 'woocommerce_gzd_dhl_return_address_postcode' ),
		    'country'       => get_option( 'woocommerce_gzd_dhl_return_address_country' ),
		    'city'          => get_option( 'woocommerce_gzd_dhl_return_address_city' ),
		    'phone'         => get_option( 'woocommerce_gzd_dhl_return_address_phone' ),
		    'email'         => get_option( 'woocommerce_gzd_dhl_return_address_email' ),
	    );

	    $return_address = array_filter( $return_address );

	    if ( ! empty( $return_address ) ) {
		    $dhl->set_return_address( $return_address );
	    }

	    $dhl->save();
	    $deutsche_post->save();

	    update_option( 'woocommerce_gzd_migrated_settings', 'yes' );

	    return true;
    }

    protected static function update_provider_setting( $provider, $key, $value ) {
	    $setter = 'set_' . $key;

	    if ( is_callable( array( $provider, $setter ) ) ) {
		    $provider->{$setter}( $value );
	    } else {
		    $provider->update_meta_data( $key, $value );
	    }
    }

    private static function create_db() {
	    global $wpdb;
	    $wpdb->hide_errors();
	    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	    dbDelta( self::get_schema() );
    }

    private static function create_upload_dir() {
    	Package::maybe_set_upload_dir();

	    $dir = Package::get_upload_dir();

	    if ( ! @is_dir( $dir['basedir'] ) ) {
	    	@mkdir( $dir['basedir'] );
	    }

	    if ( ! file_exists( trailingslashit( $dir['basedir'] ) . '.htaccess' ) ) {
		    @file_put_contents( trailingslashit( $dir['basedir'] ) . '.htaccess', 'deny from all' );
	    }

	    if ( ! file_exists( trailingslashit( $dir['basedir'] ) . 'index.php' ) ) {
		    @touch( trailingslashit( $dir['basedir'] ) . 'index.php' );
	    }
    }

    private static function get_schema() {
        global $wpdb;

        $collate = '';

        if ( $wpdb->has_cap( 'collation' ) ) {
            $collate = $wpdb->get_charset_collate();
        }

        $tables = "
CREATE TABLE {$wpdb->prefix}woocommerce_gzd_dhl_im_products (
  product_id BIGINT UNSIGNED NOT NULL auto_increment,
  product_im_id BIGINT UNSIGNED NOT NULL,
  product_code INT(16) NOT NULL,
  product_name varchar(150) NOT NULL DEFAULT '',
  product_slug varchar(150) NOT NULL DEFAULT '',
  product_version INT(5) NOT NULL DEFAULT 1,
  product_annotation varchar(500) NOT NULL DEFAULT '',
  product_description varchar(500) NOT NULL DEFAULT '',
  product_information_text TEXT NOT NULL DEFAULT '',
  product_type varchar(50) NOT NULL DEFAULT 'sales',
  product_destination varchar(20) NOT NULL DEFAULT 'national',
  product_price INT(8) NOT NULL,
  product_length_min INT(8) NULL,
  product_length_max INT(8) NULL,
  product_length_unit VARCHAR(8) NULL,
  product_width_min INT(8) NULL,
  product_width_max INT(8) NULL,
  product_width_unit VARCHAR(8) NULL,
  product_height_min INT(8) NULL,
  product_height_max INT(8) NULL,
  product_height_unit VARCHAR(8) NULL,
  product_weight_min INT(8) NULL,
  product_weight_max INT(8) NULL,
  product_weight_unit VARCHAR(8) NULL,
  product_parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  product_service_count INT(3) NOT NULL DEFAULT 0,
  product_is_wp_int INT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (product_id),
  KEY product_im_id (product_im_id),
  KEY product_code (product_code)
) $collate;
CREATE TABLE {$wpdb->prefix}woocommerce_gzd_dhl_im_product_services (
  product_service_id BIGINT UNSIGNED NOT NULL auto_increment,
  product_service_product_id BIGINT UNSIGNED NOT NULL,
  product_service_product_parent_id BIGINT UNSIGNED NOT NULL,
  product_service_slug VARCHAR(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (product_service_id),
  KEY product_service_product_id (product_service_product_id),
  KEY product_service_product_parent_id (product_service_product_parent_id)
) $collate;";

        return $tables;
    }
}