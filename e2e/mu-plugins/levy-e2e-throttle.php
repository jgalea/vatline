<?php

/**
 * The e2e suite runs every checkout from one client, so it would hit the
 * per-client lookup cap long before the end of a run.
 */
add_filter(
	'levy_eu_vat_client_lookup_limit',
	static function () {
		return array( 10000, 10 * MINUTE_IN_SECONDS );
	}
);
add_filter(
	'levy_eu_vat_global_lookup_limit',
	static function () {
		return array( 100000, HOUR_IN_SECONDS );
	}
);
