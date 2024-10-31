<?php

    #error_reporting(E_ALL);
    #ini_set('display_errors', 'On');
	
	$ipAddress = getenv('HTTP_CLIENT_IP')?:
	getenv('HTTP_X_FORWARDED_FOR')?:
	getenv('HTTP_X_FORWARDED')?:
	getenv('HTTP_FORWARDED_FOR')?:
	getenv('HTTP_FORWARDED')?:
	getenv('REMOTE_ADDR');

    require_once('/home/paywithcoins/public_html/wp-config.php'); 
	
	$validCall = true;

	if (empty($_POST["order_id"])) {
		$order_id = $_REQUEST["order_id"];
		$business_pernum = $_REQUEST["business_pernum"];
		$amount = $_REQUEST["amount"];
		$txn_id = $_REQUEST["txn_id"];
	} else {
		$order_id = $_POST["order_id"];
		$business_pernum = $_POST["business_pernum"];
		$amount = $_POST["amount"];
		$txn_id = $_POST["txn_id"];
	}

	$thisSettings  = get_option( 'woocommerce_pay-with-coins_settings', false );

	/**
	 * Verify that the data sent matches what we have stored.
	 */

	if ( $ipAddress !== "89.238.65.19" ) {
		$validCall = false;
		$errorMsg = "Access denied for IP address";
	}
	if ( $business_pernum !== $thisSettings['api_key'] ) {
		$validCall = false;
		$errorMsg = "Access denied for your pernum";
	}
	$eth_value = get_post_meta( $order_id, '_twnkl_eth_value', true );
	if ( ! compare_amounts( $eth_value, $amount ) ) {
		$validCall = false;
		$errorMsg = "Access denied - amounts do not match";
	}

	// Trigger the emails to be registered and hooked.
	//WC()->mailer()->init_transactional_emails();
	
	if ($validCall) {
	    
	    echo "valid";

		$order = new WC_Order( $order_id );
		$order->add_order_note(
			sprintf(
				__( 'Successful payment notification received from PayWithCoins.net. Transaction hash %s', 'pay_with_coins' ),
				$txn_id
			)
		);
		update_post_meta( $order_id, '_transaction_id', $txn_id );
		$order->add_order_note(
			__( 'Order updated to successful.', 'pay_with_coins' )
		);
		$order->update_status( 'completed' );

	} else {
		
		$order = new WC_Order( $order_id );
		$order->add_order_note($errorMsg);
		
	}
	
	header( 'HTTP/1.1 201 Callback received' );
	exit;

	


	/**
	 * Compare the two amounts as strings to avoid FP precision issues.
	 *
	 * @param  string $a  Value to compare.
	 * @param  string $b  Value to compare.
	 *
	 * @return bool    True on match, false otherwise.
	 */
	function compare_amounts( $a, $b ) {
		$a = rtrim( $a, '0' );
		$b = rtrim( $b, '0' );
		return $a === $b;
	}
