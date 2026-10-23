<?php

namespace Vatright\Tests\Unit\Rates;

use Vatright\Rates\RateDiff;
use Vatright\Rates\RateMapping;
use Vatright\Rates\RateSet;
use PHPUnit\Framework\TestCase;

class RateDiffTest extends TestCase {

	/**
	 * @param array<string,array{woo_rate_id:int,current:float,imported:float|null}> $existing
	 */
	private function status_for( float $target_rate, array $existing ): string {
		$target = array(
			'country' => 'DE',
			'class'   => '',
			'type'    => 'standard',
			'rate'    => $target_rate,
		);
		$diff   = RateDiff::build( array( $target ), $existing, 'tedb', '2026-10-17' );
		return $diff->entries()['DE|']['status'];
	}

	public function test_missing_rate_is_an_add(): void {
		$this->assertSame( RateDiff::ADD, $this->status_for( 19.0, array() ) );
	}

	public function test_untouched_imported_rate_with_new_value_is_a_change(): void {
		$existing = array(
			'DE|' => array(
				'woo_rate_id' => 5,
				'current'     => 16.0,
				'imported'    => 16.0,
			),
		);
		$this->assertSame( RateDiff::CHANGE, $this->status_for( 19.0, $existing ) );
	}

	public function test_diff_detects_merchant_edit(): void {
		$existing = array(
			'DE|' => array(
				'woo_rate_id' => 5,
				'current'     => 20.0,
				'imported'    => 16.0,
			),
		);
		$this->assertSame( RateDiff::EDITED, $this->status_for( 19.0, $existing ) );
	}

	public function test_rate_matching_the_new_value_is_unchanged_even_if_edited(): void {
		$existing = array(
			'DE|' => array(
				'woo_rate_id' => 5,
				'current'     => 19.0,
				'imported'    => 16.0,
			),
		);
		$this->assertSame( RateDiff::UNCHANGED, $this->status_for( 19.0, $existing ) );
	}

	public function test_unmanaged_rate_that_differs_counts_as_edited(): void {
		$existing = array(
			'DE|' => array(
				'woo_rate_id' => 5,
				'current'     => 18.0,
				'imported'    => null,
			),
		);
		$this->assertSame( RateDiff::EDITED, $this->status_for( 19.0, $existing ) );
	}

	public function test_pending_count_excludes_unchanged(): void {
		$targets  = array(
			array(
				'country' => 'DE',
				'class'   => '',
				'type'    => 'standard',
				'rate'    => 19.0,
			),
			array(
				'country' => 'FR',
				'class'   => '',
				'type'    => 'standard',
				'rate'    => 20.0,
			),
		);
		$existing = array(
			'FR|' => array(
				'woo_rate_id' => 6,
				'current'     => 20.0,
				'imported'    => 20.0,
			),
		);
		$diff     = RateDiff::build( $targets, $existing, 'tedb', '2026-10-17' );

		$this->assertSame( 1, $diff->pending_count() );
		$this->assertSame( array( 'FR|' ), array_keys( $diff->with_status( RateDiff::UNCHANGED ) ) );
	}

	public function test_default_mapping_targets_standard_class_for_every_country_and_northern_ireland(): void {
		$set     = $this->rate_set();
		$targets = ( new RateMapping( RateMapping::defaults() ) )->targets( $set );

		$this->assertSame(
			array( array( 'DE', '', 'standard', 19.0, '' ), array( 'FR', '', 'standard', 20.0, '' ), array( 'GB', '', 'standard', 20.0, 'BT*' ) ),
			array_map(
				static function ( array $t ): array {
					return array( $t['country'], $t['class'], $t['type'], $t['rate'], $t['postcode'] ?? '' );
				},
				$targets
			)
		);
	}

	public function test_reduced_class_is_unmapped_until_a_rate_is_picked(): void {
		$mapping                                      = RateMapping::defaults();
		$mapping['reduced-rate']['per_country']['DE'] = 7.0;
		$targets                                      = ( new RateMapping( $mapping ) )->targets( $this->rate_set() );

		$reduced = array_values(
			array_filter(
				$targets,
				static function ( array $t ): bool {
					return 'reduced-rate' === $t['class'];
				}
			)
		);
		$this->assertCount( 1, $reduced );
		$this->assertSame( 'DE', $reduced[0]['country'] );
		$this->assertSame( 7.0, $reduced[0]['rate'] );
	}

	private function rate_set(): RateSet {
		return new RateSet(
			array(
				'DE' => array(
					'standard' => 19.0,
					'reduced'  => array(),
				),
				'FR' => array(
					'standard' => 20.0,
					'reduced'  => array(),
				),
			),
			'tedb',
			'2026-10-17'
		);
	}
}
