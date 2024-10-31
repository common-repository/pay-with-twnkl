<?php

namespace Ademti\Pwe;

use WC_Logger;
use WC_Order;
use WC_Payment_Gateway;
use WC_Admin_Settings;

/**
 * WooCommerce gateway class implementation.
 */
class Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor, set variables etc. and add hooks/filters
	 */
	function __construct() {
		$this->id                   = 'pay-with-coins';
		$this->method_title         = __( 'Pay with '.TWNKL_Token_Symbol, 'pay-with-coins' );
		$this->has_fields           = true;
		$this->supports             = array(
			'products',
		);
		$this->view_transaction_url = 'https://etherscan.io/tx/%s';

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Set the public facing title according to the user's setting.
		$this->title = $this->settings['title'];
		$this->description = $this->settings['short_description'];

		// Save options from admin forms.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'verify_api_connection' ) );

		// Show gateway icon.
		add_filter( 'woocommerce_gateway_icon', array( $this, 'show_icons' ), 10, 2 );

		// Show payment instructions on thank you page.
		add_action( 'woocommerce_thankyou_pay-with-coins', array( $this, 'thank_you_page' ) );
	}

	/**
	 * Output the logo.
	 *
	 * @param  string $icon    The default WC-generated icon.
	 * @param  string $gateway The gateway the icons are for.
	 *
	 * @return string          The HTML for the selected iconsm or empty string if none
	 */
	public function show_icons( $icon, $gateway ) {
		if ( $this->id !== $gateway ) {
			return $icon;
		}
		$img_url = $GLOBALS['pay_with_coins']->base_url . '/img/etherium-icon.png';
		return '<img src="' . esc_attr( $img_url ) . '" width="25" height="25">';
	}

	/**
	 * Tell the user how much their order will cost if they pay by coin.
	 */
	public function payment_fields() {
		$total = WC()->cart->total;
		$currency = get_woocommerce_currency();
		try {
			$convertor = new CurrencyConvertor( $currency, TWNKL_Token_Symbol );
			$eth_value = $convertor->convert( $total );
			$eth_value = floor($this->apply_markup( $eth_value ));
			WC()->session->set(
				'twnkl_calculated_value',
				array(
					'eth_value' => $eth_value,
					'timestamp' => time(),
				)
			);
			echo '<p class="pwe-eth-pricing-note"><strong>';
			printf( __( 'Maximum payment of %s '.TWNKL_Token_Symbol.' will be due.', 'pay_with_coins' ), number_format($eth_value,0) );
			echo '</p></strong>';
		} catch ( \Exception $e ) {
			$GLOBALS['pay_with_coins']->log(
				sprintf(
					__( 'Problem performing currency conversion: %s', 'pay_with_coins' ),
					$e->getMessage()
				)
			);
			echo '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">';
			echo '<ul class="woocommerce-error">';
			echo '<li>';
			_e(
				'Unable to provide an order value in '.TWNKL_Token_Symbol.' at this time. Please contact support.',
				'pay_with_coins'
			);
			echo '</li>';
			echo '</ul>';
			echo '</div>';
		}
	}
	
	/**
	 * Gets the next order sequence.
	 */
/* 	private function getOrderSequence()
	{
		global $wpdb;
		
		$userID = 1;
		
		$table_name = $wpdb->prefix . 'my_coinorders';

		$rowOrderseq = $wpdb->get_row("SELECT id, orderseq FROM " . $table_name . " WHERE coinusername = '" . $userID . "'");
		
		if ($rowOrderseq->orderseq) {
			
			$nextOrderSeq = $rowOrderseq->orderseq + 1;
			if ($nextOrderSeq > 999) {
				$nextOrderSeq = 1;
			}

			$wpdb->query( $wpdb->prepare("UPDATE $table_name 
				SET orderseq = %s WHERE id = %s",$nextOrderSeq, $rowOrderseq->id)
			);			
			
		} else {
		
			$wpdb->insert($table_name, [
				'coinusername' => $userID,
				'orderseq' => 1,
			]);
			
			$nextOrderSeq = 1;
		
		}
		
		return $nextOrderSeq;
		
	} */

	/**
	 * Checks that not too much time has passed since we quoted them a price.
	 */
	public function validate_fields() {
		$price_info = WC()->session->get( 'twnkl_calculated_value' );
		// Prices quoted at checkout must be re-calculated if more than 15
		// minutes have passed.
		$validity_period = apply_filters( 'twnkl_checkout_validity_time', 900 );
		if ( $price_info['timestamp'] + $validity_period < time() ) {
			wc_add_notice( __( TWNKL_Token_Symbol.' price quote has been updated, please check and confirm before proceeding.', 'pay_with_coins' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Mark up a price by the configured amount.
	 *
	 * @param  float $price  The price to be marked up.
	 *
	 * @return float         The marked up price.
	 */
	private function apply_markup( $price ) {
		$markup_percent = $this->settings['markup_percent'];
		$multiplier = ( $markup_percent / 100 ) + 1;
		$newPrice = round( $price * $multiplier, 5, PHP_ROUND_HALF_UP );
		$diffPrice = $price - $newPrice;
		
		if ($markup_percent < 0) { echo "<span style='color:blue'>Congratulations! You saved ".number_format($diffPrice,3)." ".TWNKL_Token_Symbol."</span>"." (exchange rate discounted by ".abs($markup_percent)."%)<br><br>"; }
		if ($markup_percent > 0) { echo "Exchange rate fee ".abs($markup_percent)."%<br><br>"; }
		
		$transient_key = 'twnkl_exchange_rate_coinrate';
		// Check for a cached rate first. Use it if present.
 		$rate = get_transient( $transient_key );
		if ( false !== $rate ) {
			echo "Official exchange rate used 1 ".TWNKL_Token_Symbol." = $".number_format($rate,4)." USD (source ".TWNKL_Token_Source.")<br><br>";
		}
		
		return $newPrice;
	}

	/**
	 * Check if we have API access.
	 *
	 * @return  boolean Return true if we have keys.
	 */
	private function have_api_access() {
		$api_verified_at = get_option( 'twnkl_api_verified', false );
		return false !== $api_verified_at;
	}

	/**
	 * Get the time that the API was last verified.
	 *
	 * @param  boolean $formatted  True for a human-formatted date/time, false
	 *                             for a UNIX timestamp.
	 *
	 * @return string              Time.
	 */
	private function get_api_verified_time( $formatted = true ) {
		$api_verified_at = get_option( 'twnkl_api_verified', false );
		if ( false === $api_verified_at ) {
			return __( 'Unknown. Please re-verify', 'pay_with_coins' );
		}
		if ( $formatted ) {
			return date( _x( 'd M Y, H:i', 'Date format for verification timestamp', 'pay_with_coins' ), $api_verified_at );
		} else {
			return $api_verified_at;
		}
	}
	/**
	 * Initialise Gateway Settings Form Fields
	 */
	function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable / disable', 'pay_with_coins' ),
				'label'       => __( 'Enable payment with '.TWNKL_Token_Symbol, 'pay_with_coins' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
		);
		$this->form_fields['automate_receipts'] = array(
			'title'       => __( 'Payment receipt automation', 'pay_with_coins' ),
			'type'        => 'title',
		);
		if ( ! $this->have_api_access() ) {
			$description = '<p><strong>' . __( '<span style="color: #900" class="dashicons dashicons-thumbs-down"></span> Not connected.', 'pay_with_coins' ) . '</strong></p>';
			//$description .= '<p>' . sprintf( __( "COMING SOON - We can integrate with <a href='%s'>PayWithCoins</a>. A service that will seamlessly monitor the block-chain and update your orders when payment has been received. If you have an account, we'll automatically verify / re-verify your access when you save your settings.", 'pay_with_coins' ), 'https://paywithcoins.net' ) . '</p><p>' . sprintf( __( "If you don't have an account, you can <a href='%s' target='_blank'>sign up here</a> or enter your API key above.", 'pay_with_coins' ), 'https://paywithcoins.net' ) . '</p>';
		} else {
			$description = '<p><strong>' . __( '<span style="color: #090" class="dashicons dashicons-thumbs-up"></span> Connected.', 'pay_with_coins' ) . '</strong></p><p>' . sprintf( __( 'Last checked at %s', 'pay_with_coins' ), $this->get_api_verified_time() ) . '</p>';
		}
		$this->form_fields['api_key'] = array(
			'title'       => __( 'Your Business PerNum', 'pay_with_coins' ),
			'type'        => 'text',
			'description' => $description,
		);
		$this->form_fields += array(
			'basic_settings' => array(
				'title'       => __( 'Basic settings', 'pay_with_coins' ),
				'type'        => 'title',
				'description' => '',
			),
			'debug' => array(
				'title'       => __( 'Enable debug mode', 'pay_with_coins' ),
				'label'       => __( 'Enable only if you are diagnosing problems.', 'pay_with_coins' ),
				'type'        => 'checkbox',
				'description' => sprintf( __( 'Log interactions inside <code>%s</code>', 'pay_with_coins' ), wc_get_log_file_path( $this->id ) ),
				'default'     => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'pay_with_coins' ),
				'type'        => 'text',
				'description' => __( 'This controls the name of the payment option that the user sees during checkout.', 'pay_with_coins' ),
				'default'     => __( 'Pay with '.TWNKL_Token_Symbol, 'pay_with_coins' ),
			),
			'short_description' => array(
				'title'       => __( 'Short description', 'pay_with_coins' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description of the payment option that the user sees during checkout.', 'pay_with_coins' ),
				'default'     => 'Pay with your '.TWNKL_Token_Name.' ('.TWNKL_Token_Symbol.').',
			),
			'your_details' => array(
				'title'       => __( 'Payment details', 'pay_with_coins' ),
				'type'        => 'title',
				'description' => '',
			),
/*			'payment_address' => array(
				'title'       => __( 'Your '.TWNKL_Token_Name.' Ethereum Address', 'pay_with_coins' ),
				'type'        => 'text',
				'description' => __( 'This your Ethereum Address that holds your '.TWNKL_Token_Name.' and is where your customers should send payment to.', 'pay_with_coins' ),
				'default'     => '',
			),*/
			'payment_description' => array(
				'title'       => __( 'Payment instructions', 'pay_with_coins' ),
				'type'        => 'textarea',
				'description' => __( 'The payment instructions shown to your customers after their order has been placed, and emailed to them when ordering.', 'pay_with_coins' ),
				'default'     => __( 'After paying, please check your inbox and spam folders for the confirmation of the order being paid.', 'pay_with_coins' ),
			),
			'your_details' => array(
				'title'       => __( TWNKL_Token_Symbol.' Pricing', 'pay_with_coins' ),
				'type'        => 'title',
				'description' => '',
			),
			'markup_percent' => array(
				'title'    => __( 'Mark '.TWNKL_Token_Symbol.' price up by %', 'pay_with_coins' ),
				'description'     => __( 'To help cover currency fluctuations the plugin can automatically mark up converted rates for you. These are applied as percentage markup, so a 1'.TWNKL_Token_Symbol.' value with a 1.00% markup will be presented to the customer as 1.01'.TWNKL_Token_Symbol.'. You can also specify a negative value to give a discount e.g. -20 to give a 20% discount off the exchange rate.', 'pay_with_ether.' ),
				'default'  => '2.0',
				'type'     => 'number',
				'css'      => 'width:100px;',
				'custom_attributes' => array(
					'min'  => -100,
					'max'  => 100,
					'step' => 0.5,
				),
			),
		);
	}

	/**
	 * Do not allow enabling of the gateway without providing a business pernum.
	 */
	public function validate_enabled_field( $key, $value ) {
		$post_data = $this->get_post_data();
		if ( $value ) {
			if ( ! $this->have_api_access() ) {
				WC_Admin_Settings::add_error( 'You must provide a Business PerNum before enabling the gateway' );
				return 'no';
			} else {
				return 'yes';
			}
		}
		return 'no';
	}

	/**
	 * Output the gateway settings page.
	 */
	public function admin_options() {
		?>
		<h3><?php _e( 'Pay with '.TWNKL_Token_Symbol, 'pay_with_coins' ); ?></h3>
		<p><?php echo sprintf( __( 'Your customers will be given instructions about where, and how much to pay. Your orders will be marked as pending when they are placed. Once you\'ve received payment, you can update your orders on the <a href="%s">WooCommerce Orders</a> page.', 'pay_with_coins' ), admin_url( 'edit.php?post_type=shop_order' ) ); ?></p>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table><!--/.form-table-->
		<p><?php echo sprintf( __( 'Below, you can find a number of images that show your users that you accept this coin on your website.', 'pay_with_coins' ), admin_url( 'edit.php?post_type=shop_order' ) ); ?></p>
		<p><?php echo sprintf( __( '<img height="100px" src="https://rainbowcurrency.com/images/paywithtwnkl1.png">', 'pay_with_coins' ), admin_url( 'edit.php?post_type=shop_order' ) ); ?></p>
		<p><?php echo sprintf( __( '<img height="100px" src="https://rainbowcurrency.com/images/paywithtwnkl2.png">', 'pay_with_coins' ), admin_url( 'edit.php?post_type=shop_order' ) ); ?></p>
		<p><?php echo sprintf( __( '<img height="100px" src="https://rainbowcurrency.com/images/paywithtwnkl3.png">', 'pay_with_coins' ), admin_url( 'edit.php?post_type=shop_order' ) ); ?></p>
		<p><?php echo sprintf( __( '<img height="100px" src="https://rainbowcurrency.com/images/paywithtwnkl4.png">', 'pay_with_coins' ), admin_url( 'edit.php?post_type=shop_order' ) ); ?></p>
		<p><?php echo sprintf( __( '<img height="100px" src="https://rainbowcurrency.com/images/paywithtwnkl5.png">', 'pay_with_coins' ), admin_url( 'edit.php?post_type=shop_order' ) ); ?></p>
		<p><?php echo sprintf( __( '<img height="100px" src="https://rainbowcurrency.com/images/paywithtwnkl6.png">', 'pay_with_coins' ), admin_url( 'edit.php?post_type=shop_order' ) ); ?></p>
		<?php
	}

	/**
	 * See if the site can be connected to the auto-verification service.
	 */
	public function verify_api_connection() {
		if ( empty( $this->settings['api_key'] ) ) {
			delete_option( 'twnkl_api_verified' );
			return;
		}
		if (!is_numeric( $this->settings['api_key'] )) {
			delete_option( 'twnkl_api_verified' );
			return;
		}
		update_option( 'twnkl_api_verified', time() ); // always mark it as verified 
		//$api_client = new ApiClient( $this->settings['api_key'] );
		//$code = $api_client->post( 'user/auth' );
		//$GLOBALS['pay_with_coins']->log( 'Verifying API connection, received code : ' . $code );
		//$GLOBALS['pay_with_coins']->log( 'Verifying API connection, received response : ' . print_r( $api_client->get_response_body(), 1 ) );
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id  The order ID to update.
	 */
	function process_payment( $order_id ) {

		// Load the order.
		$order = new WC_Order( $order_id );

		// Retrieve the coin value.
		$stored_info = WC()->session->get( 'twnkl_calculated_value' );

		// Add order note.
		$order->add_order_note(
			__( 'Order submitted, and payment with '.TWNKL_Token_Symbol.' requested.', 'pay_with_coins' )
		);

		// Store the coin amount required against the order.
		$eth_value = $stored_info['eth_value'];
		
		// Adjust amount to make it unique to the order number
		// eg. eth_value = 5000 and orderid = 12345
		// new eth_value = 4999.345
		//$eth_value = $eth_value - 1;
		//$eth_value_modulo = $order->get_id() % TWNKL_Token_Decimals;
		//$eth_value = $eth_value + ($eth_value_modulo / TWNKL_Token_Decimals);
		
		update_post_meta( $order_id, '_twnkl_eth_value', $eth_value );
		$order->add_order_note( sprintf(
			__( 'Order value calculated as %f '.TWNKL_Token_Symbol, 'pay_with_coins' ),
			$eth_value
		) );

		// Place the order on hold.
		$order->update_status( 'on-hold', __( 'Awaiting payment.', 'pay_with_coins' ) );

		// Reduce stock levels.
		if ( is_callable( 'wc_reduce_stock_levels' ) ) {
			wc_reduce_stock_levels( $order->get_id() );
		} else {
			$order->reduce_order_stock();
		}

		// Remove cart.
		WC()->cart->empty_cart();

		// Log the order details with the monitoring service if enabled.
		if ( $this->have_api_access() ) {

			// Get the myaccount page.
			$myaccount_page_id = get_option( 'woocommerce_myaccount_page_id' );
			if ( $myaccount_page_id ) {
				$returnURL = get_permalink( $myaccount_page_id );
			} else {
				$returnURL = home_url();
			}
			
			// Get the cancel page.
			if ( method_exists( $order, 'get_cart_url' ) ) {
				$cancel_return = $order->get_cart_url();
			} else {
				$cancel_return = home_url();
			}

			$tx_ref     = new TransactionReference( $order_id );
			
			/*
			$orderID	= $tx_ref->get();
			$api_client = new ApiClient( $this->settings['api_key'] );
			$code       = $api_client->post(
				'payment/',
				[
					//'to'          => $this->settings['payment_address'],
					//'callbackUrl' => home_url(),
					//'ethVal'      => $eth_value,
					//'reference'   => $tx_ref->get(),
					'business'		=> $this->settings['api_key'],
					'currency_code'	=> 'TWN',
					'invoice'		=> 'invoice-'.$order_id,
					'return'		=> $returnURL,
					'cancel_return'	=> $cancel_return,
					'notify_url'	=> home_url().'/wp-content/plugins/pay-with-twnkl/src/callbackhandler.php',
					'paymentaction'	=> 'sale',
					'custom'		=> $order_id,
					'total'			=> $eth_value
				]
			)
			if ( 200 === $code ) {
				$response = $api_client->get_response_body();
				$response = json_decode( $response );
				$tx_id     = $response->data->txId;
				$order->add_order_note( sprintf(
					__( 'Payment accepted. txId %s', 'pay_with_coins' ),
					$tx_id
				) );
				update_post_meta( $order_id, '_twnkl_txId', $tx_id );
			} else {
				$order->add_order_note(
					__( 'Payment rejected', 'pay_with_coins' )
				);
			}
			
			$GLOBALS['pay_with_coins']->log( 'Requesting payment via pernumpay, received code ' . $code );
			$GLOBALS['pay_with_coins']->log( 'Logging order with monitoring service, received response ' . print_r( $api_client->get_response_body(), 1 ) );
			*/
			
			$eth_value_formatted = $eth_value;
			
			$submit_vars = array (
					'business'		=> $this->settings['api_key'],
					'currency_code'	=> 'TWN',
					'invoice'		=> 'invoice-'.$order_id,
					'return'		=> $returnURL,
					'cancel_return'	=> $cancel_return,
					'notify_url'	=> home_url().'/wp-content/plugins/pay-with-twnkl/src/callbackhandler.php',
					'paymentaction'	=> 'sale',
					'custom'		=> $order_id,
					'total'			=> $eth_value_formatted
			);

			$fields = "";
			foreach( $submit_vars as $key => $value ) $fields .= "$key=" . urlencode( $value ) . "&";

			$redirect = 'https://pernumpay.com/payment/?' . $fields;

		} else {
			
			$redirect = home_url();
			
		}

		// Return thank you page redirect.
		return array(
			'result'    => 'success',
			'redirect'  => $redirect,
		);
	}

	/**
	 * Output the payment information onto the thank you page.
	 *
	 * @param  int $order_id  The order ID.
	 */
	public function thank_you_page( $order_id ) {
		$order       = new WC_Order( $order_id );
		if ( is_callable( array( $order, 'get_meta' ) ) ) {
			$eth_value   = $order->get_meta( '_twnkl_eth_value' );
		} else {
			$eth_value = get_post_meta( $order_id, '_twnkl_eth_value', true );
		}
		$description = $this->settings['payment_description'];
		$tx_ref      = new TransactionReference( $order_id );

		// Output everything.
		?>
		<section class="pwe-payment-instructions">
			<h2>Pay with <?php echo TWNKL_Token_Symbol; ?></h2>
			<p>
				<?php echo esc_html( $description ); ?>
			</p>
			<!--<p class="pwe-tutorial-link">
				<a target="_blank" href="https://paywithcoins.net/tutorial.php"><?php _e( 'Tutorial', 'pay_with_coins' ); ?></a>
			</p>-->
			<ul>
				<li><?php _e( 'Amount', 'pay_by_ether' ); ?>: <strong><?php echo esc_html( $eth_value ); ?></strong> <?php echo TWNKL_Token_Symbol; ?></li>
				<!--<li><?php _e( 'Address', 'pay_by_ether' ); ?>: <strong><?php echo esc_html( $this->settings['payment_address'] ); ?></strong></li>-->
				<!--<li><?php _e( 'Data', 'pay_by_ether' ); ?>: <strong><?php echo esc_html( $tx_ref->get() ); ?></strong></li>-->
			</ul>
			<?php /*
			$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			if ( apply_filters( 'twnkl_pay_with_metamask_button', true ) ) {
				?>
				<div class="pwe-metamask-button">Pay with MetaMask</button>
				<style type="text/css">
					div.pwe-metamask-button {
						background-image: url('<?php echo $GLOBALS['pay_with_coins']->base_url . '/img/1_pay_mm_off.png'; ?>');
					}
					div.pwe-metamask-button:hover {
						background-image: url('<?php echo $GLOBALS['pay_with_coins']->base_url . '/img/1_pay_mm_over.png'; ?>');
					}
					div.pwe-metamask-button:active {
						background-image: url('<?php echo $GLOBALS['pay_with_coins']->base_url . '/img/1_pay_mm_off.png'; ?>');
					}
				</style>
				<?php
				wp_enqueue_script(
					'paywithether',
					$GLOBALS['pay_with_coins']->base_url . "/js/pay-with-coins{$min}.js",
					array( 'jquery' )
				);
				wp_enqueue_style(
					'paywithether',
					$GLOBALS['pay_with_coins']->base_url . "/css/pay-with-coins.css",
					array()
				);
				wp_localize_script(
					'paywithether',
					'pwe',
					[
						'payment_address' => $this->settings['payment_address'],
						'eth_value' => $eth_value,
						'tx_ref' => $tx_ref->get(),
					]
				);
			}
			*/ ?>
		</section>
		<?php
	}

}
