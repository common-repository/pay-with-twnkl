<?php

/*
Plugin Name: Pay With TWNKL for WooCommerce
Plugin URI: https://wordpress.org/pay-with-twnkl
Description: Payment gateway for accepting payments using Cryptocurrency. Offers integration with PerNumPay.com for automated payment processing.
Version: 1.0.19
Author: Stephen Hodgkiss
Author URI: https://stephenhodgkiss.com/

License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

define('TWNKL_Token_Symbol', 'TWNKL');
define('TWNKL_Token_Symbol_LC', 'twnkl');
define('TWNKL_Token_Name', 'Twinkle Coins');
define('TWNKL_Token_Source', 'www.cryptocoinstreet.com');
define('TWNKL_Token_DecimalsPlaces', 3);
define('TWNKL_Token_Decimals', 10 ** TWNKL_Token_DecimalsPlaces);


if ( version_compare( phpversion(), '5.6', '<' ) ) {
	add_action( 'admin_init', TWNKL_Token_Symbol_LC.'_plugin_deactivate' );
	add_action( 'admin_notices', TWNKL_Token_Symbol_LC.'_plugin_admin_notice' );
	function twnkl_plugin_deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}
	function twnkl_plugin_admin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="error"><p><strong>Pay With '.TWNKL_Token_Symbol.'</strong> requires PHP version 5.6 or above.</p></div>';
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}
} else {
	// Add autoloaders, and load up the plugin.
	require_once( dirname( __FILE__ ) . '/autoload.php' );
	$GLOBALS['pay_with_coins'] = new \Ademti\Pwe\Main( plugins_url( '', __FILE__ ), plugin_dir_path( __FILE__ ) );
	$GLOBALS['pay_with_coins']->run();
}

	
function twnkl_custom_cron_schedule( $schedules ) {
	$schedules['every_two_hours'] = array(
		'interval' => 7200, // Every 2 hours
		'display'  => __( 'Every 2 hours' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', TWNKL_Token_Symbol_LC.'_custom_cron_schedule' );

//Schedule an action if it's not already scheduled
if ( ! wp_next_scheduled( TWNKL_Token_Symbol_LC.'_cron_hook' ) ) {
	wp_schedule_event( time(), 'hourly', TWNKL_Token_Symbol_LC.'_cron_hook' );
}

///Hook into that action that'll fire every Every hour
add_action( TWNKL_Token_Symbol_LC.'_cron_hook', TWNKL_Token_Symbol_LC.'_cron_function' );

if ( ! function_exists('write_log')) {
   function write_log ( $log )  {
      if ( is_array( $log ) || is_object( $log ) ) {
         error_log( print_r( $log, true ) );
      } else {
         error_log( $log );
      }
   }
}

//create function, that runs on cron
function twnkl_cron_function() {
	//$completed_orders = wc_get_orders( array( 'status' => 'on-hold' ) );
	$onhold_orders = wc_get_orders( array(
					'status' => 'on-hold',
					'date_created' => '<' . ( time() - 600 ) // 10 minutes
	) );
	
	// Cancel the orders
	$orderIDX = 0;
	while ($orderIDX < count($onhold_orders)) {
		$order_id = $onhold_orders[$orderIDX]->id;
		$order = wc_get_order( $order_id );
		$order->update_status( 'cancelled', __( 'Awaiting payment.', 'pay_with_coins' ) );
		twnkl_reduce_stock($order_id);
		write_log ($onhold_orders[$orderIDX]->id);
		$orderIDX += 1;
	}
}

function twnkl_reduce_stock( $order_id ) {
	
	$order = new WC_Order( $order_id );

	if ( ! get_option('woocommerce_manage_stock') == 'yes' && ! sizeof( $order->get_items() ) > 0 ) {
		return;
	}

	foreach ( $order->get_items() as $item ) {

		if ( $item['product_id'] > 0 ) {
			$_product = $order->get_product_from_item( $item );

			if ( $_product && $_product->exists() && $_product->managing_stock() ) {

				$old_stock = $_product->stock;

				$qty = apply_filters( 'woocommerce_order_item_quantity', $item['qty'], $this, $item );

				$new_quantity = $_product->increase_stock( $qty );

				do_action( 'woocommerce_auto_stock_restored', $_product, $item );

				$order->add_order_note( sprintf( __( 'Item #%s stock incremented from %s to %s.', 'woocommerce' ), $item['product_id'], $old_stock, $new_quantity) );

				$order->send_stock_notifications( $_product, $new_quantity, $item['qty'] );
			}
		}
	}
}
