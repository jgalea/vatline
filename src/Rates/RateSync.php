<?php

namespace Vatline\Rates;

use WC_Tax;

class RateSync {

	const HOOK = 'vatline_rates_check';

	const OPTION_LATEST = 'vatline_rates_latest';

	const OPTION_PENDING = 'vatline_rates_pending';

	/** @var Tedb */
	private $tedb;

	public function __construct( Tedb $tedb ) {
		$this->tedb = $tedb;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'check' ) );
	}

	public function latest(): RateSet {
		$stored = get_option( self::OPTION_LATEST );
		return is_array( $stored ) ? RateSet::from_array( $stored ) : Snapshot::load();
	}

	public function refresh(): bool {
		$set = $this->tedb->fetch( gmdate( 'Y-m-d' ) );
		if ( null === $set ) {
			return false;
		}
		update_option( self::OPTION_LATEST, $set->to_array(), false );
		return true;
	}

	public function check(): void {
		$this->refresh();
		$diff = $this->plan( $this->latest(), RateMapping::load() );
		update_option( self::OPTION_PENDING, $diff->pending_count(), false );
	}

	public function plan( RateSet $set, RateMapping $mapping ): RateDiff {
		global $wpdb;

		$targets  = $mapping->targets( $set );
		$imported = array();
		$table    = $wpdb->prefix . 'vatline_rate_rows';
		$rows     = $wpdb->get_results( "SELECT woo_rate_id, imported_rate FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		foreach ( (array) $rows as $row ) {
			$imported[ (int) $row->woo_rate_id ] = (float) $row->imported_rate;
		}

		$existing = array();
		foreach ( array_unique( array_column( $targets, 'class' ) ) as $class ) {
			foreach ( (array) WC_Tax::get_rates_for_tax_class( $class ) as $rate ) {
				$covers = RateMapping::NORTHERN_IRELAND['country'] === $rate->tax_rate_country
					? array( RateMapping::NORTHERN_IRELAND['postcode'] ) === array_values( (array) ( $rate->postcode ?? array() ) )
					: ! $rate->postcode_count;
				if ( '' !== $rate->tax_rate_state || $rate->city_count || ! $covers ) {
					continue;
				}
				$key = RateDiff::key( $rate->tax_rate_country, $class );
				if ( ! isset( $existing[ $key ] ) ) {
					$existing[ $key ] = array(
						'woo_rate_id' => (int) $rate->tax_rate_id,
						'current'     => (float) $rate->tax_rate,
						'imported'    => $imported[ (int) $rate->tax_rate_id ] ?? null,
					);
				}
			}
		}
		return RateDiff::build( $targets, $existing, $set->source(), $set->situation_on() );
	}

	/**
	 * @param array<int,string> $accepted_keys
	 */
	public function apply( RateDiff $diff, array $accepted_keys, bool $overwrite_edited ): int {
		global $wpdb;

		$applied = 0;
		foreach ( $diff->entries() as $key => $entry ) {
			if ( ! in_array( $key, $accepted_keys, true ) || RateDiff::UNCHANGED === $entry['status'] ) {
				continue;
			}
			if ( RateDiff::EDITED === $entry['status'] && ! $overwrite_edited ) {
				continue;
			}
			if ( null === $entry['woo_rate_id'] ) {
				$rate_id = (int) WC_Tax::_insert_tax_rate(
					array(
						'tax_rate_country'  => $entry['country'],
						'tax_rate_state'    => '',
						'tax_rate'          => (string) $entry['rate'],
						'tax_rate_name'     => 'VAT',
						'tax_rate_priority' => 1,
						'tax_rate_compound' => 0,
						'tax_rate_shipping' => 'standard' === $entry['type'] ? 1 : 0,
						'tax_rate_order'    => 0,
						'tax_rate_class'    => $entry['class'],
					)
				);
				if ( '' !== $entry['postcode'] ) {
					WC_Tax::_update_tax_rate_postcodes( $rate_id, $entry['postcode'] );
				}
			} else {
				$rate_id = $entry['woo_rate_id'];
				WC_Tax::_update_tax_rate( $rate_id, array( 'tax_rate' => (string) $entry['rate'] ) );
			}
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'vatline_rate_rows',
				array(
					'woo_rate_id'   => $rate_id,
					'country'       => $entry['country'],
					'tax_class'     => $entry['class'],
					'imported_rate' => $entry['rate'],
					'source'        => '' === $entry['postcode'] ? $diff->source() : 'uk',
					'source_date'   => $diff->source_date(),
				)
			);
			++$applied;
		}
		update_option( self::OPTION_PENDING, max( 0, $diff->pending_count() - $applied ), false );
		return $applied;
	}
}
