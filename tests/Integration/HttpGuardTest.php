<?php

namespace Levy\EuVat\Tests\Integration;

use Levy\EuVat\Tests\Support\HttpGuard;
use Levy\EuVat\Tests\Support\IntegrationTestCase;
use RuntimeException;

class HttpGuardTest extends IntegrationTestCase {

	public function test_unstubbed_request_throws(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unstubbed outbound request: https://ec.europa.eu/' );
		wp_remote_get( 'https://ec.europa.eu/' );
	}

	public function test_stubbed_request_returns_the_stub(): void {
		HttpGuard::stub(
			'#^https://ec\.europa\.eu/#',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'ok',
				'headers'  => array(),
			)
		);
		$this->assertSame( 'ok', wp_remote_retrieve_body( wp_remote_get( 'https://ec.europa.eu/' ) ) );
	}

	public function test_an_earlier_filters_answer_is_kept(): void {
		$answer = static function ( $preempt, $args ) {
			return false !== strpos( (string) ( $args['body'] ?? '' ), '"vatNumber":"100"' ) ? array( 'body' => 'from earlier filter' ) : $preempt;
		};
		add_filter( 'pre_http_request', $answer, 10, 2 );

		$this->assertSame( 'from earlier filter', wp_remote_retrieve_body( wp_remote_post( 'https://ec.europa.eu/', array( 'body' => '{"vatNumber":"100"}' ) ) ) );

		$this->expectException( RuntimeException::class );
		wp_remote_post( 'https://ec.europa.eu/', array( 'body' => '{"vatNumber":"200"}' ) );
	}
}
