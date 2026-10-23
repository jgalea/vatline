<?php
/**
 * Records VIES fixtures from the live public service. Dev-only.
 *
 * VATRIGHT_RECORD=1 VATRIGHT_STORE_VAT=MT12345678 php bin/record-fixtures.php [output-dir]
 *
 * VATRIGHT_STORE_VAT is a real, valid Maltese number used as the store and the
 * requester. Its digits, name and address are replaced with placeholders
 * before the fixtures are written.
 */

use Vatright\Domain\VatNumber;
use Vatright\Registry\HttpTransport;
use Vatright\Registry\Vies;

if ( '1' !== getenv( 'VATRIGHT_RECORD' ) ) {
	fwrite( STDERR, "Set VATRIGHT_RECORD=1 to call the live VIES service.\n" );
	exit( 1 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

$store_vat = (string) getenv( 'VATRIGHT_STORE_VAT' );
if ( 0 !== strpos( $store_vat, 'MT' ) ) {
	fwrite( STDERR, "Set VATRIGHT_STORE_VAT to a valid Maltese VAT number, e.g. MT12345678.\n" );
	exit( 1 );
}

$out_dir = rtrim( $argv[1] ?? dirname( __DIR__ ) . '/tests/fixtures/vies', '/' );
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0775, true ) ) {
	fwrite( STDERR, "Cannot create $out_dir\n" );
	exit( 1 );
}

class RecordingTransport implements HttpTransport {

	/** @var array<string,mixed> */
	public $last = array();

	public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
		sleep( 2 );
		$header_lines = array();
		foreach ( $headers as $name => $value ) {
			$header_lines[] = "$name: $value";
		}
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => $header_lines,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 15,
			)
		);
		$response_body = curl_exec( $ch );
		$response      = array(
			'status' => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ),
			'body'   => false === $response_body ? '' : (string) $response_body,
			'error'  => false === $response_body ? curl_error( $ch ) : '',
		);

		$this->last = array(
			'method'   => $method,
			'url'      => $url,
			'response' => $response,
		);
		return $response;
	}
}

function vatright_save_fixture( string $dir, string $name, string $body_contains, array $exchange, array $extra = array() ): void {
	$fixture = array_merge(
		array(
			'_recorded_at' => gmdate( 'c' ),
			'request'      => array(
				'method'        => $exchange['method'],
				'url'           => $exchange['url'],
				'body_contains' => $body_contains,
			),
			'response'     => $exchange['response'],
		),
		$extra
	);
	file_put_contents( "$dir/$name.json", json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	fwrite( STDOUT, "$name\n" );
}

$transport = new RecordingTransport();
$vies      = new Vies( $transport );

$store = VatNumber::parse( $store_vat, 'MT' );

function vatright_anonymise_store( array $exchange, string $digits ): array {
	$body = json_decode( $exchange['response']['body'], true );
	if ( isset( $body['name'] ) ) {
		$body['name']    = 'Example Ltd';
		$body['address'] = "1\nExample Street\nVLT 1000\nValletta";
	}
	$json = str_replace( $digits, '20000010', (string) json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

	$exchange['response']['body'] = $json;
	return $exchange;
}

$digits = substr( $store->canonical(), 2 );

// The store's own company, also the requester for consultation numbers.
$vies->check( $store, $store );
vatright_save_fixture( $out_dir, 'valid_with_consultation', '"requesterNumber":"20000010"', vatright_anonymise_store( $transport->last, $digits ) );

$vies->check( $store, null );
vatright_save_fixture( $out_dir, 'valid_mt', '{"countryCode":"MT","vatNumber":"20000010"}', vatright_anonymise_store( $transport->last, $digits ) );

$vies->check( VatNumber::parse( 'BE0417497106', 'BE' ), null );
vatright_save_fixture( $out_dir, 'valid', '"vatNumber":"0417497106"', $transport->last );

$vies->check( VatNumber::parse( 'MT12345678', 'MT' ), null );
vatright_save_fixture( $out_dir, 'invalid', '"vatNumber":"12345678"', $transport->last );

$vies->check( $store, VatNumber::parse( 'MT99999999', 'MT' ) );
vatright_save_fixture( $out_dir, 'invalid_requester', '"requesterNumber":"99999999"', vatright_anonymise_store( $transport->last, $digits ) );

$transport->request( 'POST', Vies::BASE_URL . '/check-vat-number', 'garbage', array( 'Content-Type' => 'application/json' ) );
vatright_save_fixture(
	$out_dir,
	'http_500',
	'"vatNumber":"500"',
	$transport->last,
	array( '_note' => 'Recorded by posting an unparseable body; replayed for vatNumber 500.' )
);

$vies->status();
vatright_save_fixture( $out_dir, 'check_status', '', $transport->last );

// Numbers lifted from public imprints and filings; the Domain tests use them
// as "real" numbers. Only EU and XI numbers exist in VIES.
$samples = array(
	'DE123475223',
	'DE114103379',
	'DE129273398',
	'DE115235681',
	'IT00159560366',
	'IT00905811006',
	'IT00743110157',
	'ESA15075062',
	'ESA82018474',
	'ESA39000013',
	'NL820646660B01',
	'NL004495445B01',
	'NL805734958B01',
	'BE0417497106',
	'BE0202239951',
	'BE0403019261',
	'PL7740001454',
	'PL7342867148',
	'PT500697256',
	'PT504499777',
	'XI980780684',
);
foreach ( $samples as $canonical ) {
	$number = VatNumber::parse( $canonical, substr( $canonical, 0, 2 ) );
	$vies->check( $number, null );
	vatright_save_fixture( $out_dir, 'sample_' . $canonical, '"vatNumber":"' . $number->number() . '"', $transport->last );
}
