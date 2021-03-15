<?php
/**
 * ShippingProvider impl.
 *
 * @package WooCommerce/Blocks
 */
namespace Vendidero\Germanized\DHL\ShippingProvider;

use Vendidero\Germanized\DHL\Package;
use Vendidero\Germanized\Shipments\ShippingProvider;

defined( 'ABSPATH' ) || exit;

class DHL extends ShippingProvider {

	public function is_manual_integration() {
		return false;
	}

	public function get_label_classname( $type ) {
		if ( 'return' === $type ) {
			return '\Vendidero\Germanized\DHL\Label\DHLReturn';
		} else {
			return '\Vendidero\Germanized\DHL\Label\DHL';
		}
	}

	public function supports_labels( $label_type ) {
		$label_types = array( 'simple' );

		if ( 'yes' === Package::get_setting( 'dhl_label_retoure_enable' ) ) {
			$label_types[] = 'return';
		}

		return in_array( $label_type, $label_types );
	}

	public function supports_customer_return_requests() {
		return ( 'yes' === Package::get_setting( 'dhl_label_retoure_enable' ) ? true : false );
	}

	public function is_activated() {
		return Package::is_dhl_enabled();
	}

	public function get_title( $context = 'view' ) {
		return _x( 'DHL', 'dhl', 'woocommerce-germanized-dhl' );
	}

	public function get_name( $context = 'view' ) {
		return 'dhl';
	}

	public function get_description( $context = 'view' ) {
		return _x( 'Complete DHL integration supporting labels, preferred services and packstation delivery.', 'dhl', 'woocommerce-germanized-dhl' );
	}

	public function get_additional_options_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=germanized-dhl' );
	}

	public function get_default_tracking_url_placeholder() {
		return 'https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html?lang=de&idc={tracking_id}&rfn=&extendedSearch=true';
	}

	public function get_tracking_url_placeholder( $context = 'view' ) {
		$data = parent::get_tracking_url_placeholder( $context );

		// In case the option value is not stored in DB yet
		if ( 'view' === $context && empty( $data ) ) {
			$data = $this->get_default_tracking_url_placeholder();
		}

		return $data;
	}

	public function get_tracking_desc_placeholder( $context = 'view' ) {
		$data = parent::get_tracking_desc_placeholder( $context );

		// In case the option value is not stored in DB yet
		if ( 'view' === $context && empty( $data ) ) {
			$data = $this->get_default_tracking_desc_placeholder();
		}

		return $data;
	}

	public function deactivate() {
		update_option( 'woocommerce_gzd_dhl_enable', 'no' );

		/**
		 * This action is documented in woocommerce-germanized-shipments/src/ShippingProvider.php
		 */
		do_action( 'woocommerce_gzd_shipping_provider_activated', $this );
	}

	public function activate() {
		update_option( 'woocommerce_gzd_dhl_enable', 'yes' );

		/**
		 * This action is documented in woocommerce-germanized-shipments/src/ShippingProvider.php
		 */
		do_action( 'woocommerce_gzd_shipping_provider_deactivated', $this );
	}
}