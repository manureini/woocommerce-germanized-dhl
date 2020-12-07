<?php

namespace Vendidero\Germanized\DHL\Api;

use Exception;
use Vendidero\Germanized\DHL\DeutschePostLabel;
use Vendidero\Germanized\DHL\DeutschePostReturnLabel;
use Vendidero\Germanized\DHL\Package;

defined( 'ABSPATH' ) || exit;

class ImWarenpostIntRest extends Rest {

	public function __construct() {
		parent::__construct();
	}

	public function get_base_url() {
		return self::is_sandbox() ? 'https://api-qa.deutschepost.com' : 'https://api.deutschepost.com';
	}

	public function get_pdf( $awb ) {
		$pdf = $this->get_request( '/dpi/shipping/v1/shipments/' . $awb . '/itemlabels', array(), 'pdf' );

		return $pdf;
	}

	/**
	 * Updates the label
	 *
	 * @param DeutschePostLabel|DeutschePostReturnLabel $label
	 * @param \stdClass $result
	 *
	 * @throws Exception
	 */
	public function update_label( &$label, $result ) {
		$order_id = wc_clean( $result->orderId );
		$awb      = wc_clean( $result->shipments[0]->awb );
		$barcode  = wc_clean( $result->shipments[0]->items[0]->barcode );
		$pdf      = $this->get_pdf( $awb );

		if ( ! $pdf ) {
			throw new Exception( _x( 'Error while fetching label PDF', 'dhl', 'woocommerce-germanized-dhl' ) );
		}

		$filename = wc_gzd_dhl_generate_label_filename( $label, 'dp-wp-int-label' );

		if ( $path = wc_gzd_dhl_upload_data( $filename, $pdf ) ) {
			$label->set_default_path( $path );
			$label->set_path( $path );
		} else {
			throw new Exception( _x( 'Error while fetching label PDF', 'dhl', 'woocommerce-germanized-dhl' ) );
		}

		$label->set_shop_order_id( $order_id );
		$label->set_wp_int_awb( $awb );
		$label->set_wp_int_barcode( $barcode );
		$label->set_number( $barcode );

		$label->save();

		return $label;
	}

	/**
	 * Creates a new order based on the given data
	 *
	 * @see https://api-qa.deutschepost.com/dpi-apidoc/index_prod_v1.html#/reference/orders/create-order/create-order
	 *
	 * @param DeutschePostLabel|DeutschePostReturnLabel $label
	 *
	 * @throws Exception
	 */
	public function create_label( &$label )  {

		if ( ! $shipment = $label->get_shipment() ) {
			throw new Exception( _x( 'Missing shipment', 'dhl', 'woocommerce-germanized-dhl' ) );
		}

		$customs_data   = wc_gzd_dhl_get_shipment_customs_data( $label );
		$positions      = array();
		$position_index = 0;

		foreach( $customs_data['ExportDocPosition'] as $position ) {
			array_push($positions, array(
				'contentPieceIndexNumber' => $position_index++,
				'contentPieceHsCode'      => $position['customsTariffNumber'],
				'contentPieceDescription' => substr( $position['description'], 0, 33 ),
				'contentPieceValue'       => $position['customsValue'],
				'contentPieceNetweight'   => wc_get_weight( $position['netWeightInKG'], 'g', 'kg' ),
				'contentPieceOrigin'      => $position['countryCodeOrigin'],
				'contentPieceAmount'      => $position['amount']
			) );
		}

		$is_return = is_a( $shipment, 'Vendidero\Germanized\Shipments\ReturnShipment' );

		if ( $is_return ) {
			$sender_name = ( $shipment->get_sender_company() ? $shipment->get_sender_company() . ' ' : '' ) . $shipment->get_formatted_sender_full_name();
		} else {
			$sender_name = ( Package::get_setting( 'shipper_company' ) ? Package::get_setting( 'shipper_company' ) . ' ' : '' ) . Package::get_setting( 'shipper_name' );
		}

		$request_data = array(
			'customerEkp' => $this->get_ekp(),
			'orderId'     => null,
			'items'       => array(
				array(
					'id'                  => 0,
					'product'             => $label->get_dhl_product(),
					'serviceLevel'        => 'STANDARD',
					'recipient'           => $shipment->get_formatted_full_name(),
					'recipientPhone'      => $shipment->get_phone(),
					'recipientEmail'      => $shipment->get_email(),
					'addressLine1'        => $shipment->get_address_1(),
					'addressLine2'        => $shipment->get_address_2(),
					'city'                => $shipment->get_city(),
					'state'               => $shipment->get_state(),
					'postalCode'          => $shipment->get_postcode(),
					'destinationCountry'  => $shipment->get_country(),
					'shipmentAmount'      => wc_format_decimal( $shipment->get_total() + $shipment->get_additional_total(), 2 ),
					'shipmentCurrency'    => get_woocommerce_currency(),
					'shipmentGrossWeight' => wc_get_weight( $label->get_weight(), 'g', 'kg' ),
					'senderName'          => $sender_name,
					'senderAddressLine1'  => $is_return ? $shipment->get_sender_address_1() : Package::get_setting( 'shipper_street' ) . ' ' . Package::get_setting( 'shipper_street_no' ),
					'senderAddressLine2'  => $is_return ? $shipment->get_sender_address_2() : '',
					'senderCountry'       => $is_return ? $shipment->get_sender_country() : Package::get_setting( 'shipper_country' ),
					'senderCity'          => $is_return ? $shipment->get_sender_city() : Package::get_setting( 'shipper_city' ),
					'senderPostalCode'    => $is_return ? $shipment->get_sender_postcode() : Package::get_setting( 'shipper_postcode' ),
					'senderPhone'         => $is_return ? $shipment->get_sender_phone() : Package::get_setting( 'shipper_phone' ),
					'senderEmail'         => $is_return ? $shipment->get_sender_email() : Package::get_setting( 'shipper_email' ),
					'returnItemWanted'    => false,
					'shipmentNaturetype'  => strtoupper( apply_filters( 'woocommerce_gzd_deutsche_post_label_api_customs_shipment_nature_type', ( is_a( $label, 'Vendidero\Germanized\DHL\DeutschePostReturnLabel' ) ? 'RETURN_GOODS' : 'SALE_GOODS' ), $label ) ),
					'contents'            => $positions
				)
			),
			'orderStatus' => 'FINALIZE',
			'paperwork' => array(
				'contactName'     => $sender_name,
				'awbCopyCount'    => 1,
				'jobReference'    => null,
				'pickupType'      => 'CUSTOMER_DROP_OFF',
				'pickupLocation'  => null,
				'pickupDate'      => null,
				'pickupTimeSlot'  => null,
				'telephoneNumber' => null
			)
		);

		$transmit_data = 'yes' === Package::get_setting( 'label_force_email_transfer' );

		if ( ! apply_filters( 'woocommerce_gzd_deutsche_post_label_api_customs_transmit_communication_data', $transmit_data ) ) {
			if ( $is_return ) {
				$request_data['senderPhone'] = '';
				$request_data['senderEmail'] = '';
			} else {
				$request_data['recipientPhone'] = '';
				$request_data['recipientEmail'] = '';
			}
		}

		$request_data = $this->walk_recursive_remove( $request_data );
		$result       = $this->post_request( '/dpi/shipping/v1/orders', json_encode( $request_data, JSON_PRETTY_PRINT ) );

		if ( isset( $result->shipments ) ) {
			return $this->update_label( $label, $result );
		} else {
			throw new Exception( _x( 'Invalid API response', 'dhl', 'woocommerce-germanized-dhl' ) );
		}
	}

	protected function get_user_token() {
		$user_token = false;

		delete_transient( 'woocommerce_gzd_im_wp_int_user_token' );

		if ( get_transient( 'woocommerce_gzd_im_wp_int_user_token' ) ) {
			$user_token = get_transient( 'woocommerce_gzd_im_wp_int_user_token' );
		} else {
			$response_body = $this->get_request( '/v1/auth/accesstoken', array(), 'xml' );

			$reg_exp_ut = "/<userToken>(.+?)<\/userToken>/";

			if ( preg_match( $reg_exp_ut, $response_body, $match_ut ) ) {
				$user_token = $match_ut[1];

				set_transient( 'woocommerce_gzd_im_wp_int_user_token', $user_token, ( MINUTE_IN_SECONDS * 3 ) );
			}
		}

		if ( ! $user_token ) {
			throw new Exception( _x( 'Error while authenticating user.', 'dhl', 'woocommerce-germanized-dhl' ) );
		}

		return $user_token;
	}

	protected function is_sandbox() {
		return Package::is_debug_mode() && defined( 'WC_GZD_DHL_IM_WP_SANDBOX_USER' );
	}

	protected function get_auth() {
		return $this->get_basic_auth_encode( Package::get_internetmarke_warenpost_int_username(), Package::get_internetmarke_warenpost_int_password() );
	}

	/**
	 * Could be either:
	 *
	 * - application/pdf (A6)
	 * - application/pdf+singlepage (A6)
	 * - application/pdf+singlepage+6x4 (6x4 inch)
	 * - application/zpl (A6)
	 * - application/zpl+rotated (rotated by 90 degrees for label printers)
	 * - application/zpl+6x4 (6x4 inch)
	 * - application/zpl+rotated+6x4 (6x4 inch and rotated by 90 degrees for label printers)
	 *
	 * @return string
	 */
	protected function get_pdf_accept_header() {
		return 'application/pdf';
	}

	protected function set_header( $authorization = '', $request_type = 'GET', $endpoint = '' ) {
		if ( '/v1/auth/accesstoken' !== $endpoint ) {
			$token         = $this->get_user_token();
			$authorization = $token;
		}

		parent::set_header( $authorization );

		/**
		 * Add PDF header to make sure we are receiving the right file type from DP API
		 */
		if ( strpos( $endpoint, 'itemlabels' ) !== false ) {
			$this->remote_header['Accept'] = $this->get_pdf_accept_header();
		}

		$date = new \DateTime( null, new \DateTimeZone( 'Europe/Berlin' ) );

		$this->remote_header = array_merge( $this->remote_header, array(
			'KEY_PHASE'         => $this->get_key_phase(),
			'PARTNER_ID'        => $this->get_partner_id(),
			'REQUEST_TIMESTAMP' => $date->format( 'dmY-His' ),
			'PARTNER_SIGNATURE' => $this->get_signature( $date ),
		) );
	}

	protected function get_ekp() {
		return Package::get_internetmarke_warenpost_int_ekp();
	}

	protected function walk_recursive_remove( array $array ) {
		foreach ( $array as $k => $v ) {

			if ( is_array( $v ) ) {
				$array[ $k ] = $this->walk_recursive_remove( $v );
			}

			// Explicitly allow street_number fields to equal 0
			if ( '' === $v || is_null( $v ) ) {
				unset( $array[ $k ] );
			}
		}

		return $array;
	}

	protected function get_basic_auth_encode( $user, $pass ) {
		return base64_encode( $user . ':' . $pass );
	}

	protected function handle_get_response( $response_code, $response_body ) {
		switch ( $response_code ) {
			case '200':
			case '201':
				break;
			default:
				throw new Exception( _x( 'Error during Warenpost International request.', 'dhl', 'woocommerce-germanized-dhl' ) );
		}
	}

	protected function handle_post_response( $response_code, $response_body ) {
		switch ( $response_code ) {
			case '200':
			case '201':
				break;
			default:
				$error_message = '';

				if ( isset( $response_body->messages ) ) {
					foreach( $response_body->messages as $message ) {
						$error_message .= ( ! empty( $error_message ) ? ', ' : '' ) . $message;
					}
				}

				throw new Exception( sprintf( _x( 'Error during request: %s', 'dhl', 'woocommerce-germanized-dhl' ), $error_message ) );
		}
	}

	protected function get_partner_id() {
		return $this->is_sandbox() ? 'DP_LT' : Package::get_internetmarke_partner_id();
	}

	protected function get_key_phase() {
		return $this->is_sandbox() ? 1 : Package::get_internetmarke_key_phase();
	}

	protected function get_partner_token() {
		return Package::get_internetmarke_token();
	}

	protected function get_signature( $date = null ) {
		if ( ! $date ) {
			$date = new \DateTime( null, new \DateTimeZone( 'Europe/Berlin' ) );
		}

		return substr(
			md5(
				join(
					'::',
					array(
						$this->get_partner_id(),
						$date->format('dmY-His'),
						$this->get_key_phase(),
						$this->get_partner_token()
					)
				)
			),
			0,
			8
		);
	}
}
