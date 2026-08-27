<?php
/**
 * Plugin Name: Store Featured Collection
 * Description: Homepage-only curation and presentation for the featured product collection.
 * Version: 1.0.8
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_front_page() ) {
		return;
	}

	wp_enqueue_style(
		'store-featured-collection',
		plugin_dir_url( __FILE__ ) . 'assets/featured.css',
		array(),
		'1.0.8'
	);
}, 50 );

add_filter( 'woocommerce_product_get_image', function ( $html, $product, $size, $attr ) {
	if ( ! is_front_page() || ! $product instanceof WC_Product ) {
		return $html;
	}

	$map = array(
		17 => array( 'category-men.png', 'Editorial menswear still life' ),
		18 => array( 'category-men.png', 'Editorial menswear still life' ),
		19 => array( 'category-women.png', 'Editorial womenswear still life' ),
		26 => array( 'category-women.png', 'Editorial womenswear still life' ),
		27 => array( 'category-kids.png', 'Editorial kids lifestyle still life' ),
		23 => array( 'category-digital.png', 'Editorial digital workspace still life' ),
		24 => array( 'category-digital.png', 'Editorial digital workspace still life' ),
		25 => array( 'category-digital.png', 'Editorial digital workspace still life' ),
	);

	if ( ! isset( $map[ $product->get_id() ] ) ) {
		return $html;
	}

	$url = content_url( 'uploads/2026/08/category-editorial/' . $map[ $product->get_id() ][0] );
	return sprintf(
		'<img src="%1$s" alt="%2$s" class="wp-post-image store-featured-image" loading="lazy" decoding="async">',
		esc_url( $url ),
		esc_attr( $map[ $product->get_id() ][1] )
	);
}, 20, 4 );
