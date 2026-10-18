import { test, expect } from '@playwright/test';
import { changePostcode, enterVat, fillCustomer, notice, openCheckout, placeOrder, taxRow, updated } from './classic-flow';
import {
	NUMBERS,
	PRODUCTS,
	orderCount,
	orderIdFromUrl,
	orderRecord,
	registryCalls,
	resetState,
	visitorIpIn,
} from './helpers';

test.beforeEach( () => resetState() );

test( 'B2C customer in Germany pays the store country VAT below the threshold', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115' } );
	await expect( taxRow( page ).first() ).toBeVisible();
	await expect( notice( page ) ).toHaveCount( 0 );

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'B2C_ORIGIN_BELOW_THRESHOLD' );
	expect( record.exempt ).toBe( 'no' );
	expect( Number( record.total_tax ) ).toBeGreaterThan( 0 );
} );

test( 'valid business number removes VAT and shows the reverse charge wording', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115' } );
	await expect( taxRow( page ).first() ).toBeVisible();

	await enterVat( page, NUMBERS.validDe );
	await expect( notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );
	await expect( taxRow( page ) ).toHaveCount( 0 );

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'RC_GOODS_ART138' );
	expect( record.exempt ).toBe( 'yes' );
	expect( record.number ).toBe( NUMBERS.validDe );
	expect( Number( record.total_tax ) ).toBe( 0 );
	expect( registryCalls() ).toBe( 1 );
} );

test( 'invalid number keeps VAT, explains why and blocks the order', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115', vat: NUMBERS.invalidDe } );

	await expect( notice( page ) ).toContainText( 'This VAT number is not valid' );
	await expect( taxRow( page ).first() ).toBeVisible();

	const before = orderCount();
	await placeOrder( page );
	await expect( page.getByRole( 'alert' ).filter( { hasText: 'This VAT number is not valid' } ) ).toBeVisible();
	expect( page.url() ).not.toContain( 'order-received' );
	expect( orderCount() ).toBe( before );
} );

test( 'registry outage places the order with VAT charged and flagged pending', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115', vat: NUMBERS.outageDe } );

	await expect( notice( page ) ).toContainText( 'could not be checked just now' );
	await expect( taxRow( page ).first() ).toBeVisible();

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'PENDING_CHARGED' );
	expect( record.decision.flags ).toContain( 'validation_pending' );
	expect( record.exempt ).toBe( 'no' );
	expect( Number( record.total_tax ) ).toBeGreaterThan( 0 );
} );

test( 'conflicting location evidence asks for self-certification', async ( { page, context } ) => {
	await visitorIpIn( context, 'FR' );
	await openCheckout( page, PRODUCTS.ebook );
	await fillCustomer( page, { country: 'DE', postcode: '10115' } );

	const checkbox = page.getByRole( 'checkbox', { name: 'I confirm I am located in Germany.' } );
	await expect( checkbox ).toBeVisible();
	await expect( checkbox ).not.toBeChecked();

	await updated( page, () => checkbox.check() );
	await expect( checkbox ).toBeChecked();

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.flags ).toContain( 'evidence_conflict_self_certified' );
	expect( record.decision.evidence.self_certified ).toBe( 'DE' );
} );

test( 'Northern Ireland postcode is treated as EU for goods', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'GB', postcode: 'SW1A 1AA' } );
	await expect( taxRow( page ) ).toHaveCount( 0 );

	await changePostcode( page, 'BT1 1AA' );
	await expect( taxRow( page ).first() ).toBeVisible();

	await enterVat( page, NUMBERS.validXi );
	await expect( notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );
	await expect( taxRow( page ) ).toHaveCount( 0 );

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'RC_GOODS_ART138' );
	expect( record.decision.vat_number.prefix ).toBe( 'XI' );
	expect( record.exempt ).toBe( 'yes' );
} );
