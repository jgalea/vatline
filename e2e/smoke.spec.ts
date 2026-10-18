import { test, expect } from '@playwright/test';

test('wp-env site is up and serves the storefront', async ({ page }) => {
	const response = await page.goto('/');
	expect(response?.status()).toBe(200);
});
