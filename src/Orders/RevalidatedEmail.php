<?php

namespace Vatright\Orders;

use WC_DateTime;
use WC_Email;
use WC_Order;

class RevalidatedEmail extends WC_Email {

	const KEY = 'vatright_revalidated';

	const REFUND  = 'refund';
	const INVALID = 'invalid';

	/** @var string */
	public $kind = self::REFUND;

	public function __construct() {
		$this->id             = self::KEY;
		$this->customer_email = false;
		$this->title          = __( 'VAT number re-checked', 'vatright' );
		$this->description    = __( 'Sent to the store admin when a VAT number that could not be checked at checkout is checked later and the answer changes what is owed.', 'vatright' );
		$this->template_html  = 'emails/vatright-revalidated.php';
		$this->template_plain = 'emails/plain/vatright-revalidated.php';
		$this->template_base  = dirname( __DIR__, 2 ) . '/templates/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		if ( self::INVALID === $this->kind ) {
			return __( '[{site_title}]: VAT not charged on order {order_number}, number invalid', 'vatright' );
		}
		return __( '[{site_title}]: VAT refund may be due on order {order_number}', 'vatright' );
	}

	public function get_default_heading() {
		if ( self::INVALID === $this->kind ) {
			return __( 'VAT number reported invalid', 'vatright' );
		}
		return __( 'VAT number confirmed valid', 'vatright' );
	}

	public function trigger( int $order_id, string $kind ): void {
		$this->setup_locale();
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->kind                           = $kind;
			$this->object                         = $order;
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() ?? new WC_DateTime() );
			$this->placeholders['{order_number}'] = $order->get_order_number();

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		}
		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function template_args( bool $plain_text ): array {
		return array(
			'order'              => $this->object,
			'kind'               => $this->kind,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
