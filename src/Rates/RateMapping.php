<?php

namespace Vatright\Rates;

class RateMapping {

	const OPTION = 'vatright_rate_mapping';

	/**
	 * Goods to Northern Ireland follow EU VAT rules but carry UK VAT
	 * (Windsor Framework Art. 8 and Annex 3), so standard-rate classes also
	 * get the UK standard rate (VATA 1994 s. 2(1)) limited to BT postcodes.
	 */
	const NORTHERN_IRELAND = array(
		'country'  => 'GB',
		'postcode' => 'BT*',
		'rate'     => 20.0,
	);

	/** @var array<string,array{type:string,per_country:array<string,float|null>}> */
	private $map;

	/**
	 * @param array<string,array{type:string,per_country:array<string,float|null>}> $map Keyed by WooCommerce tax class slug, '' for the standard class.
	 */
	public function __construct( array $map ) {
		$this->map = $map;
	}

	/**
	 * @return array<string,array{type:string,per_country:array<string,float|null>}>
	 */
	public static function defaults(): array {
		return array(
			''             => array(
				'type'        => 'standard',
				'per_country' => array(),
			),
			'reduced-rate' => array(
				'type'        => 'reduced',
				'per_country' => array(),
			),
		);
	}

	public static function load(): RateMapping {
		return new self( get_option( self::OPTION, self::defaults() ) );
	}

	/**
	 * @return array<string,array{type:string,per_country:array<string,float|null>}>
	 */
	public function to_array(): array {
		return $this->map;
	}

	/**
	 * @return array<int,array{country:string,class:string,type:string,rate:float,postcode?:string}>
	 */
	public function targets( RateSet $set ): array {
		$targets = array();
		foreach ( $this->map as $class => $config ) {
			foreach ( $set->country_codes() as $country ) {
				$rate = $this->rate_for( (string) $class, $country, $set );
				if ( null !== $rate ) {
					$targets[] = array(
						'country' => $country,
						'class'   => (string) $class,
						'type'    => $config['type'],
						'rate'    => $rate,
					);
				}
			}
			if ( 'standard' === $config['type'] ) {
				$targets[] = array(
					'country'  => self::NORTHERN_IRELAND['country'],
					'class'    => (string) $class,
					'type'     => 'standard',
					'rate'     => self::NORTHERN_IRELAND['rate'],
					'postcode' => self::NORTHERN_IRELAND['postcode'],
				);
			}
		}
		return $targets;
	}

	private function rate_for( string $tax_class, string $country, RateSet $set ): ?float {
		$config = $this->map[ $tax_class ];
		switch ( $config['type'] ) {
			case 'standard':
				return $set->standard( $country );
			case 'zero':
				return 0.0;
			default:
				$picked = $config['per_country'][ $country ] ?? null;
				return null === $picked ? null : (float) $picked;
		}
	}
}
