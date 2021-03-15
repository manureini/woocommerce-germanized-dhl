<?php

namespace Vendidero\Germanized\DHL\Label;
use Vendidero\Germanized\DHL\Package;
use Vendidero\Germanized\Shipments\Interfaces\ShipmentReturnLabel;

defined( 'ABSPATH' ) || exit;

/**
 * DHL ReturnLabel class.
 */
class DHLReturn extends Label implements ShipmentReturnLabel {

	/**
	 * Stores product data.
	 *
	 * @var array
	 */
	protected $extra_data = array(
		'page_format'    => '',
		'shop_order_id'  => '',
		'stamp_total'    => 0,
		'voucher_id'     => '',
		'original_url'   => '',
		'manifest_url'   => '',
		'parent_id'      => 0,
		'receiver_slug'  => '',
		'sender_address' => array()
	);

	protected function get_hook_prefix() {
		return 'woocommerce_gzd_dhl_return_label_get_';
	}

	public function get_type() {
		return 'return';
	}

	public function get_receiver_id() {
		$slug = $this->get_receiver_slug();
		$id   = '';

		if ( $has_id = Package::get_return_receiver_by_slug( $slug ) ) {
			$id = $has_id['id'];
		}

		/**
		 * Returns the return receiver id for a certain DHL label.
		 *
		 * The dynamic portion of the hook name, `$this->get_hook_prefix()` constructs an individual
		 * hook name which uses `woocommerce_gzd_dhl_return_label_get_` as a prefix.
		 *
		 * Example hook name: `woocommerce_gzd_dhl_return_label_get_receiver_id`
		 *
		 * @param string      $id The receiver id.
		 * @param ReturnLabel $label The return label
		 *
		 * @package Vendidero/Germanized/DHL
		 */
		return apply_filters( "{$this->get_hook_prefix()}receiver_id", $id, $this );
	}

	public function get_receiver_slug( $context = 'view' ) {
		return $this->get_prop( 'receiver_slug', $context );
	}

	public function get_sender_address( $context = 'view' ) {
		return $this->get_prop( 'sender_address', $context );
	}

	/**
	 * Gets a prop for a getter method.
	 *
	 * @since  3.0.0
	 * @param  string $prop Name of prop to get.
	 * @param  string $address billing or shipping.
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return mixed
	 */
	protected function get_sender_address_prop( $prop, $context = 'view' ) {
		$value = $this->get_address_prop( $prop, 'sender_address', $context );

		return $value;
	}

	public function get_sender_address_2( $context = 'view' ) {
		return $this->get_sender_address_prop( 'address_2', $context );
	}

	public function get_sender_address_addition() {
		$addition        = $this->get_sender_address_2();
		$street_addition = $this->get_sender_street_addition();

		if ( ! empty( $street_addition ) ) {
			$addition = $street_addition . ( ! empty( $addition ) ? ' ' . $addition : '' );
		}

		return trim( $addition );
	}

	public function get_sender_street( $context = 'view' ) {
		return $this->get_sender_address_prop( 'street', $context );
	}

	public function get_sender_street_number( $context = 'view' ) {
		return $this->get_sender_address_prop( 'street_number', $context );
	}

	public function get_sender_street_addition( $context = 'view' ) {
		return $this->get_sender_address_prop( 'street_addition', $context );
	}

	public function get_sender_company( $context = 'view' ) {
		return $this->get_sender_address_prop( 'company', $context );
	}

	public function get_sender_name( $context = 'view' ) {
		return $this->get_sender_address_prop( 'name', $context );
	}

	public function get_sender_formatted_full_name() {
		return sprintf( _x( '%1$s', 'dhl full name', 'woocommerce-germanized-dhl' ), $this->get_sender_name() );
	}

	public function get_sender_postcode( $context = 'view' ) {
		return $this->get_sender_address_prop( 'postcode', $context );
	}

	public function get_sender_city( $context = 'view' ) {
		return $this->get_sender_address_prop( 'city', $context );
	}

	public function get_sender_state( $context = 'view' ) {
		return $this->get_sender_address_prop( 'state', $context );
	}

	public function get_sender_country( $context = 'view' ) {
		return $this->get_sender_address_prop( 'country', $context );
	}

	public function get_sender_phone( $context = 'view' ) {
		return $this->get_sender_address_prop( 'phone', $context );
	}

	public function get_sender_email( $context = 'view' ) {
		return $this->get_sender_address_prop( 'email', $context );
	}

	public function set_receiver_slug( $receiver_slug ) {
		$this->set_prop( 'receiver_slug', $receiver_slug );
	}

	public function set_sender_address( $value ) {
		$this->set_prop( 'sender_address', empty( $value ) ? array() : (array) $value );
	}
}
