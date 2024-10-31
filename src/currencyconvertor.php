<?php

namespace Ademti\Pwe;

class CurrencyConvertor {

	/**
	 * The source currency code.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * The destination currency code.
	 *
	 * @var string
	 */
	private $destination;

	/**
	 * Construct the class. Store the source and destination.
	 *
	 * @param string $source       The source currency code.
	 * @param string $destination  The destination currency code.
	 */
	public function __construct( $source, $destination ) {
		$this->source = $source;
		$this->destination = $destination;
	}

	/**
	 * Convert a price from source to destination.
	 *
	 * @param  float $price  The price to convert (in source currency).
	 *
	 * @return float         The converted price (in destination currency).
	 */
	public function convert( $price ) {
		$rate  = $this->get_exchange_rate();
		return apply_filters(
			'twnkl_converted_price',
			$price / $rate,
			$this->source,
			$price,
			$this->destination
		);
	}

	/**
	 * Retrieve the current exchange rate for this currency combination.
	 *
	 * Caches the value in a transient for 1 minute (filterable), if no
	 * cached value available then calls out to API to retrieve current value.
	 *
	 * @return float  The exchange rate.
	 */
	private function get_exchange_rate() {
		$transient_key = 'twnkl_exchange_rate_coinrate';
		// Check for a cached rate first. Use it if present.
 		$rate = get_transient( $transient_key );
		if ( false !== $rate ) {
			return apply_filters( 'twnkl_exchange_rate', (float) $rate );
		}
		$rate = $this->get_rate_from_api();
		set_transient( $transient_key, $rate, apply_filters( 'twnkl_exchange_rate_cache_duration', 60 ) );
		return apply_filters( 'twnkl_exchange_rate', (float) $rate );
	}

	/**
	 * Retrieve the exchange rate from the API.
	 *
	 * @throws \Exception    Throws exception on error.
	 *
	 * @return float  The exchange rate.
	 */
	private function get_rate_from_api() {
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "https://www.coinsworldstreet.com/api/prices/TWNKL/USD");
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		$result = curl_exec($ch);

		$data = json_decode($result);

		$bidPrice = $data->Bid;

		$coinPrice = $bidPrice;

		if ($coinPrice < 0.0001) {
			$coinPrice = 0.0001;
		}

		return (float) number_format($coinPrice,4);
		
	}
}
