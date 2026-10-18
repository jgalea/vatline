<?php

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$log   = get_option( 'vatline_e2e_mail_log', array() );
		$log[] = array(
			'to'      => $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
		);
		update_option( 'vatline_e2e_mail_log', $log, false );
		return true;
	},
	10,
	2
);
