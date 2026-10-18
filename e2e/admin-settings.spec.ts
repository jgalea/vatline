import { test, expect } from '@playwright/test';
import { resetState, wp, wpEval } from './helpers';

const forgetDismissal = () => wpEval( 'delete_user_meta( 1, "vatline_dismissed_setup_notice" ); echo "@@1@@";' );

test.beforeEach( async ( { page } ) => {
	resetState();
	forgetDismissal();
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
} );

test.afterEach( () => {
	wp( 'option', 'update', 'woocommerce_tax_based_on', 'shipping' );
	forgetDismissal();
} );

test( 'a dismissed setup notice stays dismissed after a reload', async ( { page } ) => {
	wp( 'option', 'update', 'woocommerce_tax_based_on', 'base' );
	await page.goto( '/wp-admin/index.php' );
	const notice = page.locator( '.vatline-setup-notice' );
	await expect( notice ).toBeVisible();

	await notice.locator( '.notice-dismiss' ).click();
	await expect( notice ).toBeHidden();
	await expect.poll( () => wpEval( 'echo "@@" . wp_json_encode( get_user_meta( 1, "vatline_dismissed_setup_notice", true ) ) . "@@";' ) ).toBe( 'tax_based_on_shop_base' );

	await page.reload();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	await expect( notice ).toHaveCount( 0 );
} );

test( 'picking reduced rate in the mapping editor shows the country selects without saving', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=tax&section=vatline' );
	const type = page.locator( '#vatline_mapping-0' );
	const countries = type.locator( 'xpath=..' ).locator( '.vatline-countries' );
	await expect( countries ).toBeHidden();

	await type.selectOption( 'reduced' );
	await expect( countries ).toBeVisible();
	await expect( countries.locator( 'select' ).first() ).toBeVisible();

	await type.selectOption( 'standard' );
	await expect( countries ).toBeHidden();
} );

const RATES = '/wp-admin/admin.php?page=wc-settings&tab=tax&section=vatline';

/** What a click in the middle of the button would land on, after scrolling it as far into view as the page allows. */
async function applyButtonIsClickable( page: import( '@playwright/test' ).Page ): Promise< string > {
	const button = page.getByRole( 'button', { name: 'Apply selected rates' } );
	await button.scrollIntoViewIfNeeded();
	return button.evaluate( ( el ) => {
		const rect = el.getBoundingClientRect();
		const hit = document.elementFromPoint( rect.left + rect.width / 2, rect.top + rect.height / 2 );
		if ( hit && ( hit === el || el.contains( hit ) ) ) {
			return 'ok';
		}
		return hit ? hit.tagName.toLowerCase() + ( hit.id ? '#' + hit.id : '' ) + ' ' + ( hit.closest( '[id]' )?.id ?? '' ) : 'nothing';
	} );
}

for ( const size of [ { width: 390, height: 844 }, { width: 768, height: 1024 }, { width: 1280, height: 577 }, { width: 1440, height: 900 } ] ) {
	test( `the rates form buttons clear the admin footer at ${ size.width }x${ size.height }`, async ( { page } ) => {
		await page.setViewportSize( size );
		await page.goto( RATES );
		await page.addStyleTag( { content: '*{scroll-behavior:auto!important}' } );

		expect( await applyButtonIsClickable( page ) ).toBe( 'ok' );
		if ( process.env.VISUAL_QA ) {
			await page.screenshot( { path: `${ process.env.VISUAL_QA_OUT || 'test-results' }/rates-apply-${ size.width }x${ size.height }.png` } );
		}
	} );
}

test( 'the footer check fails when the rates block loses its room', async ( { page } ) => {
	await page.setViewportSize( { width: 1280, height: 577 } );
	await page.goto( RATES );
	await page.addStyleTag( { content: '*{scroll-behavior:auto!important}.vatline-rates{padding-block-end:0!important}' } );

	expect( await applyButtonIsClickable( page ) ).not.toBe( 'ok' );
} );

test( 'applying rates says how many were applied', async ( { page } ) => {
	await page.goto( RATES );
	await page.getByRole( 'button', { name: 'Apply selected rates' } ).click();

	await expect( page ).toHaveURL( /vatline_applied=\d+/ );
	await expect( page.locator( '#message.updated' ) ).toContainText( /VAT rates? applied\.|No VAT rates were changed\./ );
} );

test( 'with taxes off, the setup notice links to the setting that turns them on', async ( { page } ) => {
	wp( 'option', 'update', 'woocommerce_calc_taxes', 'no' );
	try {
		await page.goto( '/wp-admin/index.php' );
		await page.locator( '.vatline-setup-notice a' ).click();

		await expect( page ).toHaveURL( /tab=general/ );
		await expect( page.locator( '#woocommerce_calc_taxes' ) ).toBeVisible();
	} finally {
		wp( 'option', 'update', 'woocommerce_calc_taxes', 'yes' );
	}
} );
