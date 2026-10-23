import apiFetch from '@wordpress/api-fetch';
import { registerPlugin } from '@wordpress/plugins';
import { dispatch, useSelect } from '@wordpress/data';
import { createPortal, useEffect, useRef, useState } from '@wordpress/element';

const { CheckboxControl } = window.wc.blocksCheckout;

const NAMESPACE = 'vatright';
const FIELD = 'vatright/vat-number';
const DEBOUNCE_MS = 600;
const FIELD_WRAPPER = '.wc-block-components-address-form__vatright-vat-number';

/**
 * extensionCartUpdate() puts the server's copy of the address back in the
 * form when the cart has none of the shopper's latest edits, which blanks
 * fields they are typing in. The reply is applied without its addresses.
 */
const updateCart = ( data ) =>
	apiFetch( {
		path: '/wc/store/v1/cart/extensions',
		method: 'POST',
		data: { namespace: NAMESPACE, data },
		cache: 'no-store',
	} )
		.then( ( cart ) => dispatch( 'wc/store/cart' ).receiveCartContents( cart ) )
		.catch( () => {} );

/**
 * The address the shopper is filling in: the shipping form stands in for
 * billing while "use same address for billing" is on.
 */
const useVatState = () =>
	useSelect( ( select ) => {
		const cart = select( 'wc/store/cart' );
		const customer = cart.getCustomerData();
		const sameAddress =
			cart.getNeedsShipping() &&
			select( 'wc/store/checkout' ).getUseShippingAsBilling();
		const form = sameAddress ? customer.shippingAddress : customer.billingAddress;
		return {
			vatNumber: select( 'wc/store/checkout' ).getAdditionalFields()[ FIELD ] || '',
			country: form.country || '',
			postcode: form.postcode || '',
			saving: cart.isCustomerDataUpdating(),
			extension: cart.getCartData().extensions?.[ NAMESPACE ] || {},
		};
	}, [] );

/**
 * Woo has no slot under the contact fields, and the order summary slot is
 * remounted empty when the layout switches between narrow and wide. The
 * notice is instead rendered into its own element placed right after the VAT
 * field (or after the contact form while the field is hidden), which is put
 * back whenever the form re-renders around it.
 */
const useNoticeHost = () => {
	const [ host, setHost ] = useState( null );
	const [ hasField, setHasField ] = useState( false );

	useEffect( () => {
		const element = document.createElement( 'div' );
		element.className = 'vatright-notice-host';

		const place = () => {
			const field = document.querySelector( FIELD_WRAPPER );
			setHasField( !! field );
			const anchor = field || document.getElementById( 'contact' );
			if ( ! anchor ) {
				element.remove();
			} else if ( anchor.nextElementSibling !== element ) {
				anchor.after( element );
			}
		};

		place();
		setHost( element );
		const observer = new window.MutationObserver( place );
		observer.observe( document.body, { childList: true, subtree: true } );

		return () => {
			observer.disconnect();
			element.remove();
		};
	}, [] );

	return { host, hasField };
};

const VatrightNotice = () => {
	const { vatNumber, country, postcode, saving, extension } = useVatState();
	const { host, hasField } = useNoticeHost();
	const lastKey = useRef( null );
	const sentNumber = useRef( '' );

	useEffect( () => {
		const key = `${ vatNumber }|${ country }|${ postcode }`;
		const nothingToSay = vatNumber === '' && sentNumber.current === '';
		if ( saving || nothingToSay || lastKey.current === key ) {
			return undefined;
		}
		const timer = setTimeout( () => {
			lastKey.current = key;
			sentNumber.current = vatNumber;
			updateCart( { vat_number: vatNumber, country, postcode } );
		}, DEBOUNCE_MS );
		return () => clearTimeout( timer );
	}, [ vatNumber, country, postcode, saving ] );

	const showCertification = extension.needs_self_certification || extension.self_certified;

	if ( ! host ) {
		return null;
	}

	return createPortal(
		<div className="vatright-notice">
			{ hasField && window.vatrightCheckout?.disclosure ? (
				<p className="vatright-notice__disclosure" id="vatright-disclosure">{ window.vatrightCheckout.disclosure }</p>
			) : null }
			<div aria-live="polite">
				{ extension.message ? (
					<p
						className={ `vatright-notice__message vatright-notice__message--${ extension.status }` }
					>
						{ extension.message }
					</p>
				) : null }
			</div>
			{ showCertification ? (
				<CheckboxControl
					label={ extension.self_certification_label }
					checked={ !! extension.self_certified }
					onChange={ ( checked ) => updateCart( { self_certify: checked } ) }
				/>
			) : null }
		</div>,
		host
	);
};

registerPlugin( 'vatright', {
	scope: 'woocommerce-checkout',
	render: VatrightNotice,
} );
