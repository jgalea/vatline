<?php

namespace Vatline\Rates;

use WC_Admin_Settings;

class RatesScreen {

	const NONCE = 'vatline_rates';

	/** @var RateSync */
	private $sync;

	public function __construct( RateSync $sync ) {
		$this->sync = $sync;
	}

	public function register(): void {
		add_action( 'admin_post_vatline_rates_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_vatline_rates_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_notices', array( $this, 'pending_notice' ) );
		add_action( 'woocommerce_before_settings_tax', array( $this, 'result_message' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=tax&section=vatline' );
	}

	public function render(): void {
		$diff = $this->sync->plan( $this->sync->latest(), RateMapping::load() );
		?>
		<h2><?php esc_html_e( 'EU VAT rates', 'vatline' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: rate source, 2: date the rates apply on. */
					__( 'Rates from %1$s, valid on %2$s. Nothing changes until you apply it.', 'vatline' ),
					strtoupper( $diff->source() ),
					$diff->source_date()
				)
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="vatline_rates_apply" />
			<table class="widefat striped">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Country', 'vatline' ); ?></th>
						<th><?php esc_html_e( 'Tax class', 'vatline' ); ?></th>
						<th><?php esc_html_e( 'Current', 'vatline' ); ?></th>
						<th><?php esc_html_e( 'New', 'vatline' ); ?></th>
						<th><?php esc_html_e( 'Status', 'vatline' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $diff->entries() as $entry ) : ?>
						<tr>
							<td>
								<?php if ( RateDiff::UNCHANGED !== $entry['status'] ) : ?>
									<input type="checkbox" name="vatline_rate_keys[]" value="<?php echo esc_attr( $entry['key'] ); ?>" <?php checked( RateDiff::EDITED !== $entry['status'] ); ?> />
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( '' === $entry['postcode'] ? $entry['country'] : $entry['country'] . ' (' . $entry['postcode'] . ')' ); ?></td>
							<td><?php echo esc_html( '' === $entry['class'] ? __( 'Standard', 'vatline' ) : $entry['class'] ); ?></td>
							<td><?php echo null === $entry['current'] ? '&ndash;' : esc_html( $entry['current'] . '%' ); ?></td>
							<td><?php echo esc_html( $entry['rate'] . '%' ); ?></td>
							<td><?php echo esc_html( $this->status_label( $entry['status'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label>
					<input type="checkbox" name="vatline_overwrite_edited" value="1" />
					<?php esc_html_e( 'Also overwrite rates I edited (tick the rows first)', 'vatline' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply selected rates', 'vatline' ); ?></button>
				<button type="submit" class="button" name="action" value="vatline_rates_refresh"><?php esc_html_e( 'Fetch latest from EU TEDB', 'vatline' ); ?></button>
			</p>
		</form>
		<?php
	}

	public function handle_apply(): void {
		$this->authorise();
		$keys      = isset( $_POST['vatline_rate_keys'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['vatline_rate_keys'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorise().
		$overwrite = ! empty( $_POST['vatline_overwrite_edited'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$diff      = $this->sync->plan( $this->sync->latest(), RateMapping::load() );
		$applied   = $this->sync->apply( $diff, $keys, $overwrite );
		wp_safe_redirect( add_query_arg( 'vatline_applied', $applied, self::url() ) );
		exit;
	}

	public function handle_refresh(): void {
		$this->authorise();
		$ok = $this->sync->refresh();
		wp_safe_redirect( add_query_arg( 'vatline_refreshed', $ok ? '1' : '0', self::url() ) );
		exit;
	}

	/**
	 * Reports the outcome of the apply or refresh that redirected here, in the
	 * spot WooCommerce shows "Your settings have been saved".
	 */
	public function result_message(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect's outcome.
		if ( isset( $_GET['vatline_applied'] ) ) {
			$applied = absint( $_GET['vatline_applied'] );
			WC_Admin_Settings::add_message(
				0 === $applied
					? __( 'No VAT rates were changed. Tick the rows you want to apply.', 'vatline' )
					/* translators: %d: number of tax rates written. */
					: sprintf( _n( '%d VAT rate applied.', '%d VAT rates applied.', $applied, 'vatline' ), $applied )
			);
		}
		if ( isset( $_GET['vatline_refreshed'] ) ) {
			if ( '1' === $_GET['vatline_refreshed'] ) {
				WC_Admin_Settings::add_message( __( 'Fetched the latest rates from EU TEDB.', 'vatline' ) );
			} else {
				WC_Admin_Settings::add_error( __( 'EU TEDB could not be reached, so the rates below haven\'t changed.', 'vatline' ) );
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
					_n( '%d VAT rate change available.', '%d VAT rate changes available.', $pending, 'vatline' ),
					$pending
				)
			),
			esc_url( self::url() ),
			esc_html__( 'Review', 'vatline' )
		);
	}

	private function authorise(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'You do not have permission to do this.', 'vatline' ), 403 );
		}
	}

	private function status_label( string $status ): string {
		$labels = array(
			RateDiff::ADD       => __( 'New', 'vatline' ),
			RateDiff::CHANGE    => __( 'Rate changed', 'vatline' ),
			RateDiff::EDITED    => __( 'Edited by you', 'vatline' ),
			RateDiff::UNCHANGED => __( 'Up to date', 'vatline' ),
		);
		return $labels[ $status ];
	}
}
