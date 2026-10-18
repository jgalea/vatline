<?php
// phpcs:ignoreFile -- dev-only script, not shipped. Rebuilds data/rates.json from the live EU TEDB service: php bin/build-rate-snapshot.php [YYYY-MM-DD]

use Vatline\Rates\Tedb;
use Vatline\Registry\HttpTransport;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$transport = new class() implements HttpTransport {
	public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
		$lines = array();
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		$curl = curl_init( $url );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => $lines,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $timeout,
			)
		);
		$response = curl_exec( $curl );
		$result   = array(
			'status' => (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ),
			'body'   => is_string( $response ) ? $response : '',
			'error'  => curl_error( $curl ),
		);
		return $result;
	}
};

$date = $argv[1] ?? gmdate( 'Y-m-d' );
$set  = ( new Tedb( $transport ) )->fetch( $date );
if ( null === $set ) {
	fwrite( STDERR, "TEDB did not return a complete rate set for $date\n" );
	exit( 1 );
}
file_put_contents( dirname( __DIR__ ) . '/data/rates.json', json_encode( $set->to_array(), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION ) . "\n" );
echo 'Wrote ' . count( $set->country_codes() ) . " countries for $date\n";
