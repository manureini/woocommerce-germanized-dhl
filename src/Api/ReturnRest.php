<?php

namespace Vendidero\Germanized\DHL\Api;

use Exception;
use Vendidero\Germanized\DHL\Package;
use Vendidero\Germanized\DHL\ReturnLabel;

defined( 'ABSPATH' ) || exit;

class ReturnRest extends Rest {

	public function __construct() {}

	/**
	 * @param Label $label
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function get_return_label( &$label ) {
		return $this->create_return_label( $label );
	}

	/**
	 * @param ReturnLabel $label
	 */
	protected function get_request_args( $label ) {
		$shipment     = $label->get_shipment();
		$countries    = WC()->countries->get_countries();
		$country_name = $label->get_sender_country();

		if ( ! $shipment ) {
			throw new Exception( sprintf( _x( 'Could not fetch shipment %d.', 'dhl', 'woocommerce-germanized-dhl' ), $label->get_shipment_id() ) );
		}

		if ( ! $parent_shipment = $shipment->get_parent() ) {
			throw new Exception( sprintf( _x( 'Could not fetch parent shipment %d.', 'dhl', 'woocommerce-germanized-dhl' ), $shipment->get_parent_id() ) );
		}

		$parent_label = wc_gzd_dhl_get_label( $parent_shipment );
		$order        = $shipment->get_order();

		if ( isset( $countries[ $country_name ] ) ) {
			$country_name = $countries[ $country_name ];
		}

		$label->get_sender_country();

		$request_args = array(
			'receiverId'        => $label->get_receiver_id(),
			"customerReference" => wc_gzd_dhl_get_label_reference( _x( 'Return #{shipment_id} to shipment #{original_shipment_id}', 'dhl', 'woocommerce-germanized-dhl' ), array( '{shipment_id}' => $shipment->get_id(), '{original_shipment_id}' => $parent_shipment->get_id() ) ),
			"shipmentReference" => '',
			"senderAddress"     => array(
				'name1'       => $label->get_sender_company() ? $label->get_sender_company() : $label->get_sender_formatted_full_name(),
				'name2'       => $label->get_sender_company() ? $label->get_sender_formatted_full_name() : '',
				'streetName'  => $label->get_sender_street(),
				'houseNumber' => $label->get_sender_street_number(),
				'postCode'    => $label->get_sender_postcode(),
				'city'        => $label->get_sender_city(),
				'country'     => array(
					'countryISOCode' => Package::get_country_iso_alpha3( $label->get_sender_country() ),
					'country'        => $country_name,
					'state'          => $label->get_sender_state(),
				),
			),
			'email'              => Package::get_setting( 'return_address_email' ),
			'telephoneNumber'    => Package::get_setting( 'return_address_phone' ),
			"weightInGrams"      => wc_get_weight( $label->get_weight(), 'g', 'kg' ),
			'value'              => $shipment->get_total(),
			'returnDocumentType' => 'SHIPMENT_LABEL'
		);

		if ( Package::is_crossborder_shipment( $label->get_sender_country() ) ) {
			$items = array();

			foreach( $shipment->get_items() as $item ) {
				$dhl_product = false;

				if ( $product = $item->get_product() ) {
					$dhl_product = wc_gzd_dhl_get_product( $product );
				}

				$items[] = array(
					'positionDescription' => substr( $item->get_name(), 0, 50 ),
					'count'               => $item->get_quantity(),
					'weightInGrams'       => intval( wc_get_weight( $item->get_weight(), 'g', $shipment->get_weight_unit() ) ),
					'values'              => wc_format_decimal( floatval( $item->get_total() ), 2 ),
					'originCountry'       => $dhl_product ? Package::get_country_iso_alpha3( $dhl_product->get_manufacture_country() ) : '',
					'articleReference'    => '',
					'tarifNumber'         => $dhl_product ? $dhl_product->get_hs_code() : '',
				);
			}

			$request_args['customsDocument'] = array(
				'currency'               => $order ? $order->get_currency() : 'EUR',
				'originalShipmentNumber' => $parent_label ? $parent_label->get_number() : '',
				'originalOperator'       => $parent_shipment->get_shipping_provider(),
				'originalInvoiceNumber'  => $parent_shipment->get_id(),
				'originalInvoiceDate'    => $parent_shipment->get_date_created()->format( 'Y-m-d' ),
				'positions'              => $items,
			);
		}

		return $request_args;
	}

	public function create_return_label( &$label ) {
		try {
			$request_args = $this->get_request_args( $label );
			$result       = $this->post_request( '/returns/', json_encode( $request_args ) );

			Package::log( '"returns" called with: ' . print_r( $request_args, true ) );
		} catch ( Exception $e ) {
			Package::log( 'Response Error: ' . $e->getMessage() );
			throw $e;
		}

		return $this->update_return_label( $label, $result );
	}

	protected function update_return_label( $label, $response_body ) {

		try {

			if ( isset( $response_body->shipmentNumber ) ) {
				$label->set_number( $response_body->shipmentNumber );
			}

			$default_file = base64_decode( $response_body->labelData );

			// Store the downloaded label as default file
			if ( ! $filename_label = $label->get_default_filename() ) {
				$filename_label = wc_gzd_dhl_generate_label_filename( $label, 'label-default' );
			}

			if ( $path = wc_gzd_dhl_upload_data( $filename_label, $default_file ) ) {
				$label->set_default_path( $path );
				$label->set_path( $path );
			}

		} catch( Exception $e ) {
			// Delete the label dues to errors.
			$label->delete();

			throw new Exception( _x( 'Error while creating and uploading the label', 'dhl', 'woocommerce-germanized-dhl' ) );
		}

		return $label;
	}

	protected function get_retoure_auth() {
		return base64_encode( Package::get_retoure_api_user() . ':' . Package::get_retoure_api_signature() );
	}

	protected function set_header( $authorization = '' ) {
		parent::set_header();

		if ( ! empty( $authorization ) ) {
			$this->remote_header['Authorization'] = $authorization;
		}

		$this->remote_header['DPDHL-User-Authentication-Token'] = $this->get_retoure_auth();
	}
}
