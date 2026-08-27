<?php
/**
 * Plugin Name: Store Header Experience
 * Description: Compact Blocksy commerce header utilities and discovery navigation.
 * Version: 1.0.7
 */

defined( 'ABSPATH' ) || exit;

function store_header_collection_url( $type ) {
	return add_query_arg( 'store_collection', $type, wc_get_page_permalink( 'shop' ) );
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'store-header-experience', plugin_dir_url( __FILE__ ) . 'assets/header.css', array(), '1.0.7' );
	wp_enqueue_script( 'store-header-experience', plugin_dir_url( __FILE__ ) . 'assets/header.js', array(), '1.0.5', true );
}, 40 );

add_action( 'blocksy:header:after', function () {
	$links = array(
		'Shop All'      => wc_get_page_permalink( 'shop' ),
		'New Arrivals'  => store_header_collection_url( 'new' ),
		'Best Sellers'  => store_header_collection_url( 'best-sellers' ),
		'On Sale'       => store_header_collection_url( 'on-sale' ),
		'Digital Picks' => get_term_link( 'digital-products', 'product_cat' ),
	);
	echo '<nav class="store-discovery-nav" aria-label="Store discovery"><div class="ct-container">';
	foreach ( $links as $label => $url ) {
		echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</div></nav>';
}, 5 );

add_action( 'blocksy:header:offcanvas:mobile:bottom', function () {
	$links = array(
		'Shop All'     => wc_get_page_permalink( 'shop' ),
		'New Arrivals' => store_header_collection_url( 'new' ),
		'Best Sellers' => store_header_collection_url( 'best-sellers' ),
		'On Sale'      => store_header_collection_url( 'on-sale' ),
	);
	echo '<nav class="store-mobile-discovery" aria-label="Store discovery">';
	foreach ( $links as $label => $url ) {
		echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';
} );

add_action( 'wp_footer', function () {
	$account_label = is_user_logged_in() ? __( 'My Account', 'store-header-experience' ) : __( 'Login', 'store-header-experience' );
	?>
	<template id="store-header-utilities">
		<a class="store-header-utility store-account-utility" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" aria-label="<?php echo esc_attr( $account_label ); ?>">
			<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm-8 9a8 8 0 0 1 16 0"/></svg><span><?php echo esc_html( $account_label ); ?></span>
		</a>
		<button class="store-header-utility store-wishlist-utility" type="button" aria-label="<?php esc_attr_e( 'Favorites — coming soon', 'store-header-experience' ); ?>" aria-disabled="true" title="<?php esc_attr_e( 'Favorites coming soon', 'store-header-experience' ); ?>">
			<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg><span><?php esc_html_e( 'Favorites', 'store-header-experience' ); ?></span>
		</button>
	</template>
	<?php
}, 5 );

add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! is_shop() || empty( $_GET['store_collection'] ) ) {
		return;
	}
	$collection = sanitize_key( wp_unslash( $_GET['store_collection'] ) );
	if ( 'new' === $collection ) {
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );
	} elseif ( 'best-sellers' === $collection ) {
		$query->set( 'meta_key', 'total_sales' );
		$query->set( 'orderby', 'meta_value_num' );
		$query->set( 'order', 'DESC' );
	} elseif ( 'on-sale' === $collection ) {
		$ids = wc_get_product_ids_on_sale();
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
	}
} );
