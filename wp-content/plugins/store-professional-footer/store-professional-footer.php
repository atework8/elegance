<?php
/**
 * Plugin Name: Store Professional Footer
 * Description: Accessible ecommerce trust strip, footer, and back-to-top control.
 * Version: 1.0.1
 */

defined( 'ABSPATH' ) || exit;

function store_footer_icon( $name ) {
	$icons = array(
		'shield'  => '<path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/>',
		'package' => '<path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7M12 11v10"/>',
		'help'    => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 1 1 3.6 2.1c-.9.5-1.4 1-1.4 1.9M12 17h.01"/>',
		'return'  => '<path d="M8 7H4V3"/><path d="M4.5 7.5A8 8 0 1 1 4 15"/><path d="m4 7 4-4"/>',
		'up'      => '<path d="m6 14 6-6 6 6"/>',
		'lock'    => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
	);
	return '<svg aria-hidden="true" viewBox="0 0 24 24">' . $icons[ $name ] . '</svg>';
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'store-professional-footer', plugin_dir_url( __FILE__ ) . 'assets/footer.css', array(), '1.0.1' );
	wp_enqueue_script( 'store-professional-footer', plugin_dir_url( __FILE__ ) . 'assets/footer.js', array(), '1.0.0', true );
}, 60 );

add_action( 'blocksy:footer:before', function () {
	$shop    = wc_get_page_permalink( 'shop' );
	$account = wc_get_page_permalink( 'myaccount' );
	$cart    = wc_get_cart_url();
	$privacy = get_privacy_policy_url();
	$returns = get_permalink( 11 );
	$shop_links = array(
		'Shop All'         => $shop,
		'Men'              => get_term_link( 'men', 'product_cat' ),
		'Women'            => get_term_link( 'women', 'product_cat' ),
		'Kids'             => get_term_link( 'kids', 'product_cat' ),
		'Digital Products' => get_term_link( 'digital-products', 'product_cat' ),
		'New Arrivals'     => add_query_arg( 'store_collection', 'new', $shop ),
		'On Sale'          => add_query_arg( 'store_collection', 'on-sale', $shop ),
	);
	$care_links = array( 'My Account' => $account, 'Cart' => $cart, 'Returns & Refunds' => $returns );
	$info_links = array( 'Privacy Policy' => $privacy, 'Refund / Returns Policy' => $returns );
	?>
	<footer id="store-footer" class="store-footer" aria-label="Site footer">
		<div class="store-footer__main ct-container">
			<section class="store-footer__brand" aria-labelledby="store-footer-brand"><h2 id="store-footer-brand">Elegance</h2><p>A curated destination for physical and digital products.</p><div class="store-footer__social-status"><span>Social</span><small>Profiles coming soon</small></div></section>
			<nav aria-labelledby="store-footer-shop"><h2 id="store-footer-shop">Shop</h2><ul><?php foreach ( $shop_links as $label => $url ) : ?><li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></li><?php endforeach; ?></ul></nav>
			<nav aria-labelledby="store-footer-care"><h2 id="store-footer-care">Customer Care</h2><ul><?php foreach ( $care_links as $label => $url ) : ?><li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></li><?php endforeach; ?></ul></nav>
			<nav aria-labelledby="store-footer-info"><h2 id="store-footer-info">Information</h2><ul><?php foreach ( $info_links as $label => $url ) : ?><li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></li><?php endforeach; ?></ul></nav>
		</div>
		<div class="store-footer__utility ct-container"><div class="store-footer__payments"><?php echo store_footer_icon( 'lock' ); ?><div><strong>Secure payments</strong><span>Payment methods shown at checkout</span></div></div><p>No payment gateway is enabled in this development store.</p></div>
		<div class="store-footer__legal ct-container"><p>Copyright &copy; 2026 Elegance</p><nav aria-label="Legal"><a href="<?php echo esc_url( $privacy ); ?>">Privacy</a><a href="<?php echo esc_url( $returns ); ?>">Returns</a></nav></div>
	</footer>
	<button class="store-back-to-top" type="button" aria-label="Back to top" title="Back to top"><?php echo store_footer_icon( 'up' ); ?><span class="screen-reader-text">Back to top</span></button>
	<?php
}, 5 );
