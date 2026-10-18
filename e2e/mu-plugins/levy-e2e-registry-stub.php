<?php

/**
 * Registry answers keyed by the number's digits. The DE numbers are checksum-valid
 * (recorded in tests/fixtures/vies); XI980780684 is made valid here, the
 * recorded fixture says invalid.
 */
function levy_eu_vat_e2e_vies_answer( $digits ) {
	$valid = array( '114103379', '980780684' );
	if ( in_array( $digits, $valid, true ) ) {
		return array(
			'valid'             => true,
			'requestDate'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'requestIdentifier' => '',
			'name'              => '---',
			'address'           => '---',
		);
	}
	if ( '123475223' === $digits ) {
		return array(
			'valid'       => false,
			'requestDate' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}
	if ( '129273398' === $digits ) {
		return array(
			'actionSucceed' => false,
			'errorWrappers' => array( array( 'error' => 'MS_UNAVAILABLE' ) ),
		);
	}
	return null;
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$hosts = array( 'ec.europa.eu', 'api.service.hmrc.gov.uk', 'test-api.service.hmrc.gov.uk', 'uid-wse.admin.ch', 'data.brreg.no' );
		$host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, $hosts, true ) ) {
			return $preempt;
		}
		if ( false !== strpos( $url, '/check-vat-number' ) ) {
			$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
			$answer = is_array( $body ) ? levy_eu_vat_e2e_vies_answer( (string) ( $body['vatNumber'] ?? '' ) ) : null;
			if ( null !== $answer ) {
				$count = (int) get_option( 'levy_e2e_vies_calls', 0 );
				update_option( 'levy_e2e_vies_calls', $count + 1, false );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $answer ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return new WP_Error( 'levy_e2e_unstubbed', 'No e2e stub for ' . $url );
	},
	10,
	3
);

add_filter(
	'woocommerce_geolocate_ip',
	static function ( $country ) {
		$cookie = isset( $_COOKIE['levy_e2e_ip_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['levy_e2e_ip_country'] ) ) ) : '';
		return preg_match( '/^[A-Z]{2}$/', $cookie ) ? $cookie : $country;
	}
);
