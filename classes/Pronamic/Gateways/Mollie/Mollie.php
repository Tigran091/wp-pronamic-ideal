<?php

/**
 * Title: Mollie
 * Description: 
 * Copyright: Copyright (c) 2005 - 2011
 * Company: Pronamic
 * @author Remco Tolsma
 * @version 1.0
 */
class Pronamic_Gateways_Mollie_Mollie {
	/**
	 * Mollie API endpoint URL
	 * 
	 * @var string
	 */
	const API_URL = 'https://secure.mollie.nl//xml/ideal/';
	
	/////////////////////////////////////////////////

	private $partner_id;

	private $profile_key;

	/////////////////////////////////////////////////

	/**
	 * Indicator to use test mode or not
	 * 
	 * @var boolean
	 */
	private $test_mode;

	/////////////////////////////////////////////////

	/**
	 * Error
	 * 
	 * @var WP_Error
	 */
	private $error;

	/////////////////////////////////////////////////

	/**
	 * Constructs and initializes an Mollie client object
	 * 
	 * @param string $partner_id
	 */
	public function __construct( $partner_id ) {
		$this->partner_id = $partner_id;
	}

	/////////////////////////////////////////////////

	/**
	 * Error
	 * 
	 * @return WP_Error
	 */
	public function get_error() {
		return $this->error;
	}

	/////////////////////////////////////////////////

	/**
	 * Set test mode
	 * 
	 * @param boolean $test_mode
	 */
	public function set_test_mode( $test_mode ) {
		$this->test_mode = $test_mode;
	}
	
	//////////////////////////////////////////////////

	private function remote_get( $url ) {
		$result = false;

		$response = wp_remote_get( $url );

		if ( ! is_wp_error( $response ) ) {
			if ( wp_remote_retrieve_response_code( $response ) == 200 ) {
				$result = wp_remote_retrieve_body( $response );
			} else {
				$this->error = new WP_Error( 'test' );
			}
		} else {
			$this->error = $response;
		}

		return $result;
	}

	private function parse_xml( $xml ) {
		$result = false;

		// Suppress all XML errors
		$use_errors = libxml_use_internal_errors( true );
		
		$document = simplexml_load_string( $xml );
		
		if ( $document !== false ) {
			$result = $document;
		} else {
			$this->error = new WP_Error( 'xml_load_error', __( 'Could not load the XML response meessage from the iDEAL provider.', 'pronamic_ideal' ) );
		
			foreach ( libxml_get_errors() as $error ) {
				$this->error->add( 'libxml_error', $error->message, $error );
			}
		
			libxml_clear_errors();
		}
		
		// Set back to previous value
		libxml_use_internal_errors( $use_errors );

		return $result;
	}
	
	//////////////////////////////////////////////////

	private function get_parameters( $action, array $parameters = array() ) {
		$parameters['a']          = $action;
		$parameters['partnerid'] = $this->partner_id;

		if ( $this->test_mode ) {
			$parameters['testmode'] = 'true';
		}
		
		return $parameters;
	}
	
	//////////////////////////////////////////////////

	private function send_request( $action, array $parameters = array() ) {
		$parameters = $this->get_parameters( $action, $parameters );
		
		// WordPress functions uses URL encoding
		// @see http://codex.wordpress.org/Function_Reference/build_query
		// @see http://codex.wordpress.org/Function_Reference/add_query_arg
		$url = self::API_URL . '?' . _http_build_query( $parameters, null, '&' );

		return $this->remote_get( $url );
	}
	
	//////////////////////////////////////////////////

	/**
	 * Get banks
	 * 
	 * @return Ambigous <boolean, multitype:string >
	 */
	public function get_banks() {
		$banks = false;

		$result = $this->send_request( Pronamic_Gateways_Mollie_Actions::BANK_LIST );

		if ( $result !== false ) {
			$xml = $this->parse_xml( $result );

			if ( $xml !== false ) {
				$banks = array();

				foreach ( $xml->bank as $bank ) {
					$id   = (string) $bank->bank_id;
					$name = (string) $bank->bank_name;

					$banks[$id] = $name;
				}
			}
		}
		
		return $banks;
	}
	
	//////////////////////////////////////////////////

	public function create_payment( $bank_id, $amount, $description, $return_url, $report_url ) {
		$result = false;

		$parameters = array (
			'bank_id'     => $bank_id,
			'amount'      => $amount,
			'description' => $description,
			'reporturl'   => $report_url,
			'returnurl'   => $return_url
		);

		if ( $this->profile_key ) {
			$parameters['profile_key'] = $this->profile_key;
		}

		$result = $this->send_request( Pronamic_Gateways_Mollie_Actions::FETCH, $parameters );

		if ( $result !== false ) {
			$xml = $this->parse_xml( $result );
			
			if ( $xml !== false ) {
				$order = new stdClass();
				
				$order->transaction_id = (string) $xml->order->transaction_id;
				$order->amount         = (string) $xml->order->amount;
				$order->currency       = (string) $xml->order->currency;
				$order->url            = (string) $xml->order->URL;
				$order->message        = (string) $xml->order->message;
				
				$result = $order;
			}
		}

		return $result;
	}
	
	//////////////////////////////////////////////////

	public function check_payment( $transaction_id ) {
		$result = false;

		$parameters = array (
			'transaction_id' => $transaction_id
		);

		$result = $this->send_request( Pronamic_Gateways_Mollie_Actions::CHECK, $parameters );

		if ( $result !== false ) {
			$xml = $this->parse_xml( $result );
			
			if ( $xml !== false ) {
				$order = new stdClass();
				
				$order->transaction_id = (string) $xml->order->transaction_id;
				$order->amount         = (string) $xml->order->amount;
				$order->payed          = filter_var( $xml->order->currency, FILTER_VALIDATE_BOOLEAN );
				$order->status         = (string) $xml->order->status;

				$order->consumer          = new stdClass();
				$order->consumer->name    = (string) $xml->order->consumer->consumerName;
				$order->consumer->account = (string) $xml->order->consumer->consumerAccount;
				$order->consumer->city    = (string) $xml->order->consumer->consumerCity;
				
				$result = $order;
			}
		}

		return $result;
	}
}
