/**
 * Takes the plugin directory's screenshots.
 *
 * Drives a headless Chromium against the test bench. Run it from a directory
 * where `npm install puppeteer` has been done — it produces release assets, it
 * is not a dependency of the plugin, so nothing ships it and it is in neither
 * composer.json nor a package.json.
 *
 * The bench sits behind HTTP basic auth *and* needs a WordPress session, and two
 * of the shots are of screens that only exist once something has been bought, so
 * run `wp eval-file scripts/bench-seed.php` first.
 *
 * Usage:
 *   node screenshots.mjs <output-directory>
 *
 * Credentials come from the environment so none of them is in the repository:
 *   OXS_BASIC=user:pass  OXS_ADMIN=user:pass
 */

import puppeteer from 'puppeteer';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const SITE = process.env.OXS_SITE ?? 'https://test.44123.it/oxysuppliers';
const OUT = process.argv[ 2 ] ?? '.';

const WIDTH = 1280;
const HEIGHT = 900;

const pair = ( value, what ) => {
	if ( ! value || ! value.includes( ':' ) ) {
		throw new Error( `Set ${ what } to "user:password".` );
	}

	const at = value.indexOf( ':' );

	return { username: value.slice( 0, at ), password: value.slice( at + 1 ) };
};

const basic = pair( process.env.OXS_BASIC, 'OXS_BASIC' );
const admin = pair( process.env.OXS_ADMIN, 'OXS_ADMIN' );

const PAGE = `${ SITE }/wp-admin/admin.php?page=oxysuppliers`;

/**
 * The shots, in the order the directory shows them.
 *
 * The first is the question the plugin exists to answer. Somebody scrolling a
 * list of plugins looks at that one and nothing else.
 */
const SHOTS = [
	{ n: 1, url: `${ PAGE }&tab=requirements`, wait: '.wp-list-table' },
	{ n: 2, url: `${ PAGE }&tab=orders`, wait: '.wp-list-table' },
	{ n: 3, url: `${ PAGE }&tab=orders&action=view&id=${ process.env.OXS_ORDER ?? '5' }`, wait: '.oxysuppliers-wrap' },
	{
		n: 4,
		url: `${ PAGE }&tab=orders&action=view&id=${ process.env.OXS_PARTIAL ?? '6' }`,
		wait: '.oxysuppliers-wrap',
		scrollTo: 'h2',
		scrollText: 'Receive what has arrived',
	},
	{ n: 5, url: `${ PAGE }&tab=suppliers`, wait: '.wp-list-table' },
	{
		n: 6,
		url: `${ SITE }/wp-admin/post.php?post=${ process.env.OXS_PRODUCT ?? '10' }&action=edit`,
		wait: '#oxysuppliers_product_data',
		then: 'open-supplier-tab',
	},
	{ n: 7, url: `${ PAGE }&tab=reports`, wait: '.oxysuppliers-wrap' },
];

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const browser = await puppeteer.launch( {
	headless: true,
	args: [ '--no-sandbox', `--window-size=${ WIDTH },${ HEIGHT }` ],
} );

await mkdir( OUT, { recursive: true } );

const page = await browser.newPage();

await page.setViewport( { width: WIDTH, height: HEIGHT, deviceScaleFactor: 1 } );
await page.authenticate( basic );

await page.goto( `${ SITE }/wp-login.php`, { waitUntil: 'networkidle2' } );
await page.type( '#user_login', admin.username );
await page.type( '#user_pass', admin.password );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'networkidle2' } ),
	page.click( '#wp-submit' ),
] );

let taken = 0;

for ( const shot of SHOTS ) {
	const file = path.join( OUT, `screenshot-${ shot.n }.png` );

	await page.goto( shot.url, { waitUntil: 'networkidle2' } );

	let found = true;

	try {
		await page.waitForSelector( shot.wait, { timeout: 10000 } );
	} catch {
		found = false;
	}

	if ( 'open-supplier-tab' === shot.then ) {
		// The product data box opens on whichever tab WooCommerce opens on, and
		// ours is hidden until somebody clicks it.
		await page.evaluate( () => {
			const tab = document.querySelector( 'li.oxysuppliers_tab a, li.oxysuppliers_options a, .product_data_tabs a[href="#oxysuppliers_product_data"]' );

			if ( tab ) {
				tab.click();

				return;
			}

			// Belt and braces: show the panel directly if the tab is not where
			// WooCommerce usually puts it.
			document.querySelectorAll( '.woocommerce_options_panel' ).forEach( ( panel ) => {
				panel.style.display = 'oxysuppliers_product_data' === panel.id ? 'block' : 'none';
			} );
		} );

		await settle( 800 );

		const box = await page.$( '#woocommerce-product-data' );

		if ( box ) {
			await box.scrollIntoView().catch( () => {} );
			await settle( 400 );
		}
	}

	if ( shot.scrollText ) {
		await page.evaluate(
			( selector, text ) => {
				const heading = Array.from( document.querySelectorAll( selector ) ).find(
					( node ) => node.textContent.trim().startsWith( text )
				);

				if ( heading ) {
					heading.scrollIntoView( { block: 'start' } );
					window.scrollBy( 0, -60 );
				}
			},
			shot.scrollTo,
			shot.scrollText
		);

		await settle( 400 );
	}

	// WordPress's own toolbar belongs to WordPress. A screenshot of somebody
	// else's furniture teaches a reader nothing.
	await page
		.addStyleTag( { content: '#wpadminbar{display:none!important}html{margin-top:0!important}' } )
		.catch( () => {} );

	await settle( 600 );

	await page.screenshot( { path: file, captureBeyondViewport: false } );

	taken += 1;
	console.log( `${ found ? 'ok  ' : 'WARN' }  ${ shot.n }  ${ shot.url }${ found ? '' : ` — ${ shot.wait } non trovato` }` );
}

await browser.close();

console.log( `\n${ taken } schermate in ${ OUT }` );
