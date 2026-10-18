<?php

namespace Levy\EuVat\Admin;

use Levy\EuVat\Rates\RateMapping;
use Levy\EuVat\Rates\RateSet;
use Levy\EuVat\Rates\RateSync;

class RateMappingEditor {

	const FIELD = 'levy_eu_vat_mapping';

	/** @var RateSync */
	private $sync;

	public function __construct( RateSync $sync ) {
		$this->sync = $sync;
	}

	public function render(): void {
		$set     = $this->sync->latest();
		$mapping = RateMapping::load()->to_array();
		$types   = $this->types();

		foreach ( array_keys( wc_get_product_tax_class_options() ) as $index => $class ) {
			$class  = (string) $class;
			$config = $mapping[ $class ] ?? null;
			$type   = null === $config ? '' : $config['type'];
			$id     = self::FIELD . '-' . $index;
			?>
			<tr>
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( wc_get_product_tax_class_options()[ $class ] ); ?></label>
				</th>
				<td class="forminp forminp-select">
					<input type="hidden" name="<?php echo esc_attr( self::FIELD . '[' . $index . '][class]' ); ?>" value="<?php echo esc_attr( $class ); ?>" />
					<select id="<?php echo esc_attr( $id ); ?>" class="levy-eu-vat-class-type" name="<?php echo esc_attr( self::FIELD . '[' . $index . '][type]' ); ?>">
						<?php foreach ( $types as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php $this->render_countries( $set, $index, $config['per_country'] ?? array(), 'reduced' !== $type ); ?>
				</td>
			</tr>
			<?php
		}
	}

	public function save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Woo verifies the nonce before this action; every value is checked against known classes, types and TEDB rates below.
		$posted = isset( $_POST[ self::FIELD ] ) ? (array) wp_unslash( $_POST[ self::FIELD ] ) : array();
		if ( ! $posted ) {
			return;
		}

		$set     = $this->sync->latest();
		$classes = wc_get_product_tax_class_options();
		$map     = array();

		foreach ( $posted as $row ) {
			$class = is_array( $row ) && isset( $row['class'] ) ? (string) $row['class'] : null;
			$type  = is_array( $row ) && isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( null === $class || ! array_key_exists( $class, $classes ) || ! array_key_exists( $type, $this->types() ) || '' === $type ) {
				continue;
			}
			$map[ $class ] = array(
				'type'        => $type,
				'per_country' => 'reduced' === $type ? $this->picked_rates( $set, $row['rates'] ?? array() ) : array(),
			);
		}

		update_option( RateMapping::OPTION, $map, false );
	}

	/**
	 * @return array<string,string>
	 */
	private function types(): array {
		return array(
			''         => __( 'Not mapped', 'levy-eu-vat' ),
			'standard' => __( 'Standard rate', 'levy-eu-vat' ),
			'reduced'  => __( 'Reduced rate', 'levy-eu-vat' ),
			'zero'     => __( 'Zero rate', 'levy-eu-vat' ),
		);
	}

	/**
	 * @param mixed $rates
	 * @return array<string,float>
	 */
	private function picked_rates( RateSet $set, $rates ): array {
		$picked = array();
		foreach ( (array) $rates as $country => $value ) {
			$country = (string) $country;
			if ( ! $set->has( $country ) || ! is_scalar( $value ) ) {
				continue;
			}
			foreach ( $set->reduced( $country ) as $reduced ) {
				if ( abs( $reduced['rate'] - (float) $value ) < 0.0001 ) {
					$picked[ $country ] = $reduced['rate'];
				}
			}
		}
		return $picked;
	}

	/**
	 * @param array<string,float|null> $current
	 */
	private function render_countries( RateSet $set, int $index, array $current, bool $hidden ): void {
		$countries = WC()->countries->get_countries();
		?>
		<table class="widefat striped levy-eu-vat-countries" <?php echo $hidden ? 'hidden' : ''; ?>>
			<tbody>
				<?php foreach ( $set->country_codes() as $country ) : ?>
					<?php $options = $this->rate_options( $set, $country ); ?>
					<?php if ( ! $options ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<?php $field = self::FIELD . '-' . $index . '-' . $country; ?>
					<tr>
						<td><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $countries[ $country ] ?? $country ); ?></label></td>
						<td>
							<select id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( self::FIELD . '[' . $index . '][rates][' . $country . ']' ); ?>">
								<option value=""><?php esc_html_e( 'Not mapped', 'levy-eu-vat' ); ?></option>
								<?php foreach ( $options as $option ) : ?>
									<option value="<?php echo esc_attr( (string) $option['rate'] ); ?>" <?php selected( isset( $current[ $country ] ) && abs( (float) $current[ $country ] - $option['rate'] ) < 0.0001 ); ?>><?php echo esc_html( $option['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @return array<int,array{rate:float,label:string}>
	 */
	private function rate_options( RateSet $set, string $country ): array {
		$options = array();
		foreach ( $set->reduced( $country ) as $reduced ) {
			foreach ( $options as $existing ) {
				if ( $existing['rate'] === $reduced['rate'] ) {
					continue 2;
				}
			}
			$names     = array_map(
				static function ( $category ) {
					return ucfirst( strtolower( str_replace( '_', ' ', $category ) ) );
				},
				array_slice( $reduced['categories'], 0, 3 )
			);
			$label     = $reduced['rate'] . '%';
			$label    .= 'super_reduced' === $reduced['type'] ? ' ' . __( '(super reduced)', 'levy-eu-vat' ) : '';
			$options[] = array(
				'rate'  => $reduced['rate'],
				'label' => $names ? $label . ' - ' . implode( ', ', $names ) : $label,
			);
		}
		return $options;
	}
}
