#!/usr/bin/env bash
set -euo pipefail

wp() { npx wp-env run cli wp "$@"; }

wp option update woocommerce_default_country MT
wp option update woocommerce_currency EUR
wp option update woocommerce_calc_taxes yes
wp option update woocommerce_coming_soon no
wp option update woocommerce_store_pages_only no
wp option update woocommerce_tax_based_on shipping
wp option update woocommerce_custom_orders_table_enabled yes
wp option update woocommerce_custom_orders_table_data_sync_enabled no
wp option update woocommerce_cheque_settings '{"enabled":"yes","title":"Check payments"}' --format=json
wp wc --user=admin tool run install_pages >/dev/null 2>&1 || true

ensure_product() {
	local name="$1" virtual="$2"
	if [ -z "$(wp post list --post_type=product --name="$3" --format=ids)" ]; then
		wp wc --user=admin product create --name="$name" --slug="$3" --type=simple --regular_price=100 --virtual="$virtual" --status=publish >/dev/null
	fi
}
ensure_product "Book" false book
ensure_product "E-book" true e-book
ensure_product "Consulting" true consulting

if [ -z "$(wp post list --post_type=page --name=classic-checkout --format=ids)" ]; then
	wp post create --post_type=page --post_status=publish --post_title="Classic checkout" --post_name=classic-checkout --post_content="[woocommerce_checkout]" >/dev/null
fi

wp eval 'Vatline\Ledger\Schema::install();'
wp eval-file wp-content/plugins/vatline/e2e/seed.php
