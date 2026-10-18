<?php

namespace Vatline;

class Settings {

	const OPTION = 'vatline_settings';

	/** @var Settings|null */
	private static $instance = null;

	public static function get(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'store_vat_number'             => '',
			'outage_policy'                => 'charge',
			'cache_ttl_valid_days'         => 7,
			'allow_domestic_exemption'     => false,
			'oss_registered'               => false,
			'fixed_prices'                 => false,
			'self_certification'           => true,
			'notify_admin_on_revalidation' => true,
			'retention_years'              => 10,
			'hmrc_client_id'               => '',
			'hmrc_client_secret'           => '',
			'report_currency'              => 'EUR',
			'delete_data_on_uninstall'     => false,
		);
	}

	public function store_vat_number(): string {
		return (string) $this->value( 'store_vat_number' );
	}

	public function outage_policy(): string {
		$policy = (string) $this->value( 'outage_policy' );
		return in_array( $policy, array( 'charge', 'exempt_pending', 'reject' ), true ) ? $policy : 'charge';
	}

	public function cache_ttl_valid_days(): int {
		return (int) $this->value( 'cache_ttl_valid_days' );
	}

	public function allow_domestic_exemption(): bool {
		return (bool) $this->value( 'allow_domestic_exemption' );
	}

	public function oss_registered(): bool {
		return (bool) $this->value( 'oss_registered' );
	}

	public function fixed_prices(): bool {
		return (bool) $this->value( 'fixed_prices' );
	}

	public function self_certification(): bool {
		return (bool) $this->value( 'self_certification' );
	}

	public function notify_admin_on_revalidation(): bool {
		return (bool) $this->value( 'notify_admin_on_revalidation' );
	}

	public function retention_years(): int {
		return (int) $this->value( 'retention_years' );
	}

	public function hmrc_client_id(): string {
		return (string) $this->value( 'hmrc_client_id' );
	}

	public function hmrc_client_secret(): string {
		return (string) $this->value( 'hmrc_client_secret' );
	}

	public function report_currency(): string {
		return (string) $this->value( 'report_currency' );
	}

	public function delete_data_on_uninstall(): bool {
		return (bool) $this->value( 'delete_data_on_uninstall' );
	}

	/**
	 * @return mixed
	 */
	private function value( string $key ) {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}
		return $defaults[ $key ];
	}
}
