import { test, expect, type Page } from '@playwright/test';
import { NUMBERS, resetState, wpEval } from './helpers';

const vatInputs = ( page: Page ) => page.locator( '#order_data input[id*="vatline"], #order_data input[id*="vatline"]' );

test.beforeEach( async ( { page } ) => {
	resetState();
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
} );

function createOrder(): number {
	return wpEval( `
		$order = wc_create_order();
		$order->add_product( wc_get_product( 10 ), 1 );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_country( 'DE' );
		$order->set_billing_postcode( '10115' );
		$order->set_shipping_country( 'DE' );
		$order->set_shipping_postcode( '10115' );
		$order->calculate_totals();
		$order->save();
		echo '@@' . $order->get_id() . '@@';
	` );
}

test( 'the order screen has exactly one VAT input and saves the number', async ( { page } ) => {
	const id = createOrder();
	await page.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` );
	await page.locator( '.order_data_column a.edit_address' ).first().click();
	await expect( vatInputs( page ) ).toHaveCount( 1 );

	await vatInputs( page ).fill( NUMBERS.validDe );
	await page.getByRole( 'button', { name: 'Update' } ).click();
	await expect( page.locator( '.notice-success, #message.updated' ).first() ).toBeVisible();

	const number = wpEval( `echo "@@" . wp_json_encode( wc_get_order( ${ id } )->get_meta( '_vatline_number' ) ) . "@@";` );
	expect( number ).toBe( NUMBERS.validDe );
} );
