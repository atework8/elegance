<?php
/**
 * Plugin Name: Store Shopping Polish
 * Description: Focused presentation and reassurance enhancements for the WooCommerce shop and product pages.
 * Version: 1.3.5
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_woocommerce' ) || ( ! is_shop() && ! is_product_category() && ! is_product() && ! is_cart() ) ) {
		return;
	}

	$base = plugin_dir_url( __FILE__ );
	wp_enqueue_style( 'store-shopping-polish', $base . 'assets/shopping.css', array(), '1.3.5' );

	if ( is_product() ) {
		wp_enqueue_script( 'store-shopping-polish', $base . 'assets/product.js', array( 'jquery' ), '1.3.0', true );
	}

	if ( is_cart() ) {
		$digital_urls = array();
		foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'downloadable' => true, 'return' => 'objects' ) ) as $downloadable_product ) {
			$digital_urls[] = wp_parse_url( $downloadable_product->get_permalink(), PHP_URL_PATH );
		}
		wp_enqueue_script( 'store-cart-polish', $base . 'assets/cart.js', array(), '1.3.0', true );
		wp_localize_script( 'store-cart-polish', 'storeCartPolish', array(
			'digitalPaths'   => array_values( array_filter( $digital_urls ) ),
			'shopUrl'        => wc_get_page_permalink( 'shop' ),
			'digitalLabel'   => __( 'Digital download', 'store-shopping-polish' ),
			'physicalLabel'  => __( 'Physical item', 'store-shopping-polish' ),
			'digitalContext' => __( 'Digital items are delivered through secure download access after purchase. No shipping is required.', 'store-shopping-polish' ),
			'physicalContext'=> __( 'Shipping options for physical items are confirmed during checkout.', 'store-shopping-polish' ),
			'mixedContext'   => __( 'This is one order: physical items will be shipped, while digital items receive secure download access after purchase.', 'store-shopping-polish' ),
			'emptyText'      => __( 'Explore the collection and add something you will love.', 'store-shopping-polish' ),
			'continueText'   => __( 'Continue shopping', 'store-shopping-polish' ),
			'quantityReset'  => __( 'Quantity was reset to the minimum allowed value.', 'store-shopping-polish' ),
		) );
	}
}, 30 );

add_filter( 'body_class', function ( $classes ) {
	if ( is_product_category() ) {
		$term = get_queried_object();
		$classes[] = $term instanceof WP_Term && $term->parent
			? 'store-product-category--child'
			: 'store-product-category--top';
	}

	if ( is_product() ) {
		$product = wc_get_product( get_queried_object_id() );
		if ( $product && $product->is_downloadable() ) {
			$classes[] = 'store-digital-product';
		} else {
			$classes[] = 'store-physical-product';
		}
	}
	return $classes;
} );

add_action( 'blocksy:hero:title:before', function () {
	if ( ! is_product_category() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term instanceof WP_Term || ! $term->parent || ! function_exists( 'woocommerce_breadcrumb' ) ) {
		return;
	}

	woocommerce_breadcrumb( array(
		'delimiter'   => '<span aria-hidden="true">/</span>',
		'wrap_before' => '<nav class="woocommerce-breadcrumb store-archive-breadcrumb" aria-label="' . esc_attr__( 'Breadcrumb', 'store-shopping-polish' ) . '">',
		'wrap_after'  => '</nav>',
	) );
}, 5 );

add_action( 'woocommerce_archive_description', function () {
	if ( is_shop() ) {
		echo '<p class="store-shop-intro">' . esc_html__( 'Thoughtfully selected physical goods and digital products, presented with clear pricing and availability.', 'store-shopping-polish' ) . '</p>';
	}
}, 8 );

add_action( 'woocommerce_before_shop_loop', function () {
	if ( ! is_shop() && ! is_product_category() ) {
		return;
	}

	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0 ) );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return;
	}
	$preferred_order = array_flip( array( 'men', 'women', 'kids', 'digital-products' ) );
	usort( $terms, function ( $a, $b ) use ( $preferred_order ) {
		return ( $preferred_order[ $a->slug ] ?? 99 ) <=> ( $preferred_order[ $b->slug ] ?? 99 );
	} );

	echo '<nav class="store-category-nav" aria-label="' . esc_attr__( 'Shop categories', 'store-shopping-polish' ) . '">';
	echo '<a class="' . ( is_shop() ? 'is-current' : '' ) . '" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '"' . ( is_shop() ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'All products', 'store-shopping-polish' ) . '</a>';
	foreach ( $terms as $term ) {
		$current = is_product_category( $term->slug );
		echo '<a class="' . ( $current ? 'is-current' : '' ) . '" href="' . esc_url( get_term_link( $term ) ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $term->name ) . '</a>';
	}
	echo '</nav>';

	if ( is_product_category() ) {
		$current_term = get_queried_object();
		$family_id = $current_term->parent ? (int) $current_term->parent : (int) $current_term->term_id;
		$children = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $family_id ) );
		if ( ! is_wp_error( $children ) && $children ) {
			echo '<nav class="store-category-nav store-category-nav--children" aria-label="' . esc_attr( get_term( $family_id )->name . ' ' . __( 'departments', 'store-shopping-polish' ) ) . '">';
			foreach ( $children as $child ) {
				$current = (int) $current_term->term_id === (int) $child->term_id;
				echo '<a class="' . ( $current ? 'is-current' : '' ) . '" href="' . esc_url( get_term_link( $child ) ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $child->name ) . '</a>';
			}
			echo '</nav>';
		}
	}
}, 12 );

add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product;
	if ( $product && ! $product->is_in_stock() ) {
		echo '<p class="store-loop-stock store-loop-stock--out"><span aria-hidden="true">×</span>' . esc_html__( 'Out of stock', 'store-shopping-polish' ) . '</p>';
	}
}, 8 );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product ) {
		return;
	}

	if ( $product->is_downloadable() ) {
		echo '<div class="store-product-kind store-product-kind--digital"><span aria-hidden="true">↓</span><div><strong>' . esc_html__( 'Digital download', 'store-shopping-polish' ) . '</strong><small>' . esc_html__( 'Secure access is provided after purchase. No physical item will be shipped.', 'store-shopping-polish' ) . '</small></div></div>';
	} else {
		echo '<div class="store-product-kind store-product-kind--physical"><span aria-hidden="true">✓</span><div><strong>' . esc_html__( 'Ready for secure delivery', 'store-shopping-polish' ) . '</strong><small>' . esc_html__( 'Carefully packed, with order support when you need it.', 'store-shopping-polish' ) . '</small></div></div>';
	}
}, 27 );

add_filter( 'woocommerce_get_stock_html', function ( $html, $product ) {
	if ( ! $product || ! is_product() ) {
		return $html;
	}
	if ( '' === trim( $html ) && ! $product->is_type( 'variable' ) ) {
		$html = '<p class="stock ' . ( $product->is_in_stock() ? 'in-stock' : 'out-of-stock' ) . '">' . esc_html( $product->is_in_stock() ? __( 'In stock', 'store-shopping-polish' ) : __( 'Out of stock', 'store-shopping-polish' ) ) . '</p>';
	}
	$status = $product->is_in_stock() ? __( 'Available', 'store-shopping-polish' ) : __( 'Unavailable', 'store-shopping-polish' );
	return '<div class="store-stock-wrap"><span class="store-stock-label">' . esc_html__( 'Availability:', 'store-shopping-polish' ) . '</span>' . $html . '<span class="screen-reader-text">' . esc_html( $status ) . '</span></div>';
}, 20, 2 );

add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	if ( isset( $tabs['description'] ) ) {
		$tabs['description']['title'] = __( 'Product details', 'store-shopping-polish' );
	}
	return $tabs;
} );

add_filter( 'woocommerce_output_related_products_args', function ( $args ) {
	$args['posts_per_page'] = 4;
	$args['columns']        = 4;
	return $args;
} );
