import { test, expect, type Page } from '@playwright/test';
import { NUMBERS, resetState, wpEval } from './helpers';

const vatInputs = ( page: Page ) => page.locator( 'input[name*="vatright"], input[name*="vatright"]' );

test.beforeEach( async ( { page } ) => {
	resetState();
	wpEval( 'update_user_meta( get_user_by( "login", "vatright-customer" )->ID, "_vatright_number", "" ); echo "@@1@@";' );
	await page.goto( '/my-account/' );
	await page.locator( '#username' ).fill( 'vatright-customer' );
	await page.locator( '#password' ).fill( 'vatright-pass' );
	await page.getByRole( 'button', { name: 'Log in' } ).click();
	await expect( page.locator( '.woocommerce-MyAccount-navigation' ) ).toBeVisible();
} );

test( 'account details carries exactly one VAT input and the address forms none', async ( { page } ) => {
	await page.goto( '/my-account/edit-account/' );
	await expect( vatInputs( page ) ).toHaveCount( 1 );

	for ( const address of [ 'billing', 'shipping' ] ) {
		await page.goto( `/my-account/edit-address/${ address }/` );
		await expect( page.locator( 'form input[name="save_address"], form button[name="save_address"]' ) ).toBeVisible();
		await expect( vatInputs( page ) ).toHaveCount( 0 );
	}
} );

test( 'the saved number reaches the customer record and clearing it clears the record', async ( { page } ) => {
	await page.goto( '/my-account/edit-account/' );
	await vatInputs( page ).fill( 'de 114.103.379' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.getByText( 'Account details changed successfully.' ) ).toBeVisible();

	const saved = () => wpEval( 'echo "@@" . wp_json_encode( get_user_meta( get_user_by( "login", "vatright-customer" )->ID, "_vatright_number", true ) ) . "@@";' );
	expect( saved() ).toBe( NUMBERS.validDe );

	await page.goto( '/my-account/edit-account/' );
	await expect( vatInputs( page ) ).toHaveValue( NUMBERS.validDe );
	await vatInputs( page ).fill( '' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.getByText( 'Account details changed successfully.' ) ).toBeVisible();
	expect( saved() ).toBe( '' );
} );
