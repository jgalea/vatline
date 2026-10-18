<?php

namespace Levy\EuVat\Rates;

use WC_Admin_Settings;

class RatesScreen {

	const NONCE = 'levy_eu_vat_rates';

	/** @var RateSync */
	private $sync;

	public function __construct( RateSync $sync ) {
		$this->sync = $sync;
	}

	public function register(): void {
		add_action( 'admin_post_levy_eu_vat_rates_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_levy_eu_vat_rates_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_notices', array( $this, 'pending_notice' ) );
		add_action( 'woocommerce_before_settings_tax', array( $this, 'result_message' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=tax&section=levy_eu_vat' );
	}

	public function render(): void {
		$diff = $this->sync->plan( $this->sync->latest(), RateMapping::load() );
		?>
		<h2><?php esc_html_e( 'EU VAT rates', 'levy-eu-vat' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: rate source, 2: date the rates apply on. */
					__( 'Rates from %1$s, valid on %2$s. Nothing changes until you apply it.', 'levy-eu-vat' ),
					strtoupper( $diff->source() ),
					$diff->source_date()
				)
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="levy_eu_vat_rates_apply" />
			<table class="widefat striped">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Country', 'levy-eu-vat' ); ?></th>
						<th><?php esc_html_e( 'Tax class', 'levy-eu-vat' ); ?></th>
						<th><?php esc_html_e( 'Current', 'levy-eu-vat' ); ?></th>
						<th><?php esc_html_e( 'New', 'levy-eu-vat' ); ?></th>
						<th><?php esc_html_e( 'Status', 'levy-eu-vat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $diff->entries() as $entry ) : ?>
						<tr>
							<td>
								<?php if ( RateDiff::UNCHANGED !== $entry['status'] ) : ?>
									<input type="checkbox" name="levy_eu_vat_rate_keys[]" value="<?php echo esc_attr( $entry['key'] ); ?>" <?php checked( RateDiff::EDITED !== $entry['status'] ); ?> />
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( '' === $entry['postcode'] ? $entry['country'] : $entry['country'] . ' (' . $entry['postcode'] . ')' ); ?></td>
							<td><?php echo esc_html( '' === $entry['class'] ? __( 'Standard', 'levy-eu-vat' ) : $entry['class'] ); ?></td>
							<td><?php echo null === $entry['current'] ? '&ndash;' : esc_html( $entry['current'] . '%' ); ?></td>
							<td><?php echo esc_html( $entry['rate'] . '%' ); ?></td>
							<td><?php echo esc_html( $this->status_label( $entry['status'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label>
					<input type="checkbox" name="levy_eu_vat_overwrite_edited" value="1" />
					<?php esc_html_e( 'Also overwrite rates I edited (tick the rows first)', 'levy-eu-vat' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply selected rates', 'levy-eu-vat' ); ?></button>
				<button type="submit" class="button" name="action" value="levy_eu_vat_rates_refresh"><?php esc_html_e( 'Fetch latest from EU TEDB', 'levy-eu-vat' ); ?></button>
			</p>
		</form>
		<?php
	}

	public function handle_apply(): void {
		$this->authorise();
		$keys      = isset( $_POST['levy_eu_vat_rate_keys'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['levy_eu_vat_rate_keys'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorise().
		$overwrite = ! empty( $_POST['levy_eu_vat_overwrite_edited'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$diff      = $this->sync->plan( $this->sync->latest(), RateMapping::load() );
		$applied   = $this->sync->apply( $diff, $keys, $overwrite );
		wp_safe_redirect( add_query_arg( 'levy_eu_vat_applied', $applied, self::url() ) );
		exit;
	}

	public function handle_refresh(): void {
		$this->authorise();
		$ok = $this->sync->refresh();
		wp_safe_redirect( add_query_arg( 'levy_eu_vat_refreshed', $ok ? '1' : '0', self::url() ) );
		exit;
	}

	/**
	 * Reports the outcome of the apply or refresh that redirected here, in the
	 * spot WooCommerce shows "Your settings have been saved".
	 */
	public function result_message(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect's outcome.
		if ( isset( $_GET['levy_eu_vat_applied'] ) ) {
			$applied = absint( $_GET['levy_eu_vat_applied'] );
			WC_Admin_Settings::add_message(
				0 === $applied
					? __( 'No VAT rates were changed. Tick the rows you want to apply.', 'levy-eu-vat' )
					/* translators: %d: number of tax rates written. */
					: sprintf( _n( '%d VAT rate applied.', '%d VAT rates applied.', $applied, 'levy-eu-vat' ), $applied )
			);
		}
		if ( isset( $_GET['levy_eu_vat_refreshed'] ) ) {
			if ( '1' === $_GET['levy_eu_vat_refreshed'] ) {
				WC_Admin_Settings::add_message( __( 'Fetched the latest rates from EU TEDB.', 'levy-eu-vat' ) );
			} else {
				WC_Admin_Settings::add_error( __( 'EU TEDB could not be reached, so the rates below haven\'t changed.', 'levy-eu-vat' ) );
			}
		}
		// phpcs:enable
	}

	public function pending_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		$pending = (int) get_option( RateSync::OPTION_PENDING, 0 );
		if ( $pending < 1 ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of rate changes. */
					_n( '%d VAT rate change available.', '%d VAT rate changes available.', $pending, 'levy-eu-vat' ),
					$pending
				)
			),
			esc_url( self::url() ),
			esc_html__( 'Review', 'levy-eu-vat' )
		);
	}

	private function authorise(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'You do not have permission to do this.', 'levy-eu-vat' ), 403 );
		}
	}

	private function status_label( string $status ): string {
		$labels = array(
			RateDiff::ADD       => __( 'New', 'levy-eu-vat' ),
			RateDiff::CHANGE    => __( 'Rate changed', 'levy-eu-vat' ),
			RateDiff::EDITED    => __( 'Edited by you', 'levy-eu-vat' ),
			RateDiff::UNCHANGED => __( 'Up to date', 'levy-eu-vat' ),
		);
		return $labels[ $status ];
	}
}
