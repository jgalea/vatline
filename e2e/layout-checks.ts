import type { Page } from '@playwright/test';

/**
 * In-page layout assertions for the checkout screens. Returns a list of
 * problems; an empty list means every check passed. `scope` limits the
 * element checks to the VAT notice and the elements around it, while the
 * horizontal scroll check covers the whole page.
 */
export async function layoutProblems( page: Page, scope: string ): Promise< { problems: string[]; checked: number } > {
	return page.evaluate( ( scopeSelector ) => {
		const problems: string[] = [];
		const visible = ( el: Element ) => {
			const style = getComputedStyle( el );
			const rect = el.getBoundingClientRect();
			return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
		};
		const name = ( el: Element ) =>
			el.tagName.toLowerCase() + ( el.id ? '#' + el.id : '' ) + ( typeof el.className === 'string' && el.className ? '.' + el.className.trim().split( /\s+/ ).join( '.' ) : '' );

		if ( document.documentElement.scrollWidth > innerWidth + 1 ) {
			problems.push( `horizontal scroll: page is ${ document.documentElement.scrollWidth }px wide in a ${ innerWidth }px viewport` );
		}

		const roots = [ ...document.querySelectorAll( scopeSelector ) ].filter( visible );
		if ( roots.length === 0 ) {
			return { problems: [ `scope ${ scopeSelector } matched no visible element` ], checked: 0 };
		}
		const hiddenOnPurpose = '.screen-reader-text, .screen-reader-only, .visually-hidden, .wc-block-components-visually-hidden';
		const elements = roots
			.flatMap( ( root ) => [ root, ...root.querySelectorAll( '*' ) ] )
			.filter( visible )
			.filter( ( el ) => ! el.closest( hiddenOnPurpose ) );

		for ( const el of elements ) {
			const rect = el.getBoundingClientRect();
			if ( rect.right > innerWidth + 1 || rect.left < -1 ) {
				problems.push( `${ name( el ) } sticks out of the viewport (${ Math.round( rect.left ) }..${ Math.round( rect.right ) })` );
			}
			if ( el.scrollWidth > el.clientWidth + 2 && getComputedStyle( el ).overflowX !== 'visible' ) {
				problems.push( `clipped content in ${ name( el ) }` );
			}
		}

		const luminance = ( color: string ) => {
			const m = color.match( /[\d.]+/g );
			if ( ! m ) {
				return null;
			}
			const [ r, g, b ] = m.slice( 0, 3 ).map( Number ).map( ( v ) => {
				v /= 255;
				return v <= 0.03928 ? v / 12.92 : ( ( v + 0.055 ) / 1.055 ) ** 2.4;
			} );
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		const backgroundOf = ( el: Element ) => {
			for ( let node: Element | null = el; node; node = node.parentElement ) {
				const color = getComputedStyle( node ).backgroundColor;
				if ( color && ! /,\s*0\)$/.test( color ) && color !== 'transparent' ) {
					return color;
				}
			}
			return 'rgb(255, 255, 255)';
		};
		const texts = elements.filter( ( el ) => [ ...el.childNodes ].some( ( n ) => n.nodeType === 3 && ( n.textContent || '' ).trim() ) );
		for ( const el of texts ) {
			const l1 = luminance( getComputedStyle( el ).color );
			const l2 = luminance( backgroundOf( el ) );
			if ( l1 === null || l2 === null ) {
				continue;
			}
			const ratio = ( Math.max( l1, l2 ) + 0.05 ) / ( Math.min( l1, l2 ) + 0.05 );
			if ( ratio < 4.5 ) {
				problems.push( `low contrast ${ ratio.toFixed( 2 ) } in ${ name( el ) }: "${ ( el.textContent || '' ).trim().slice( 0, 30 ) }"` );
			}
		}

		for ( let i = 0; i < texts.length; i++ ) {
			for ( let j = i + 1; j < texts.length; j++ ) {
				const a = texts[ i ];
				const b = texts[ j ];
				if ( a.contains( b ) || b.contains( a ) ) {
					continue;
				}
				const ra = a.getBoundingClientRect();
				const rb = b.getBoundingClientRect();
				const ox = Math.min( ra.right, rb.right ) - Math.max( ra.left, rb.left );
				const oy = Math.min( ra.bottom, rb.bottom ) - Math.max( ra.top, rb.top );
				if ( ox > 4 && oy > 4 ) {
					problems.push( `overlap ${ Math.round( ox ) }x${ Math.round( oy ) }px: ${ name( a ) } and ${ name( b ) }` );
				}
			}
		}

		for ( const box of [ ...document.querySelectorAll( '.vatright-notice input[type="checkbox"]' ) ].filter( visible ) ) {
			const target = ( box.closest( 'label' ) || box ).getBoundingClientRect();
			if ( target.height < 24 ) {
				problems.push( `small tap target ${ Math.round( target.height ) }px: ${ name( box ) }` );
			}
		}

		return { problems: [ ...new Set( problems ) ], checked: elements.length };
	}, scope );
}
