<?php
/**
 * MVRK Demo Seeder (PREVIEW ONLY — do NOT ship to the client's production WordPress).
 *
 * Populates a fresh site so the client preview looks complete: 4 WooCommerce products
 * with corrected specs + placeholder prices, a few approved giveaway entrants (so the
 * wheel shows names), one demo winner, and disables WooCommerce "coming soon" mode.
 *
 * Runs once (guarded by the `mvrk_demo_seeded` option). Remove this folder for production.
 *
 * @package MVRK_Demo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Preview only: keep WooCommerce "Launch Your Store / Coming Soon" OFF so the shop is
// publicly visible (it otherwise redirects non-logged-in visitors). Belt-and-suspenders:
// (1) WC's own bypass filter on every request, (2) disable the option + feature on init.
add_filter( 'woocommerce_coming_soon_exclude', '__return_true', 999 );

add_action( 'init', 'mvrk_demo_disable_coming_soon', 1 );
function mvrk_demo_disable_coming_soon() {
	$flags = array(
		'woocommerce_coming_soon'                        => 'no',
		'woocommerce_store_pages_only'                   => 'no',
		'woocommerce_feature_launch-your-store_enabled'  => 'no',
	);
	foreach ( $flags as $opt => $val ) {
		if ( $val !== get_option( $opt ) ) {
			update_option( $opt, $val );
		}
	}
}

// Front-end only — never during admin/activation/REST/cron requests.
add_action( 'template_redirect', 'mvrk_demo_seed' );

function mvrk_demo_seed() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( 'yes' === get_option( 'mvrk_demo_seeded' ) ) {
		return;
	}
	// Need WooCommerce + the giveaway plugin loaded.
	if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'mvrk_ga_insert_entry' ) ) {
		return;
	}
	// Mark seeded immediately so a slow first request can't double-seed.
	update_option( 'mvrk_demo_seeded', 'yes' );

	// Turn off WooCommerce "coming soon" so the shop is visible in the preview.
	update_option( 'woocommerce_coming_soon', 'no' );
	update_option( 'woocommerce_store_pages_only', 'no' );

	mvrk_demo_products();
	mvrk_demo_entrants();
	mvrk_demo_winner();

	update_option( 'mvrk_demo_seeded', 'yes' );
}

/**
 * Create the 4 SKUs (placeholder prices — CONFIRM WITH CLIENT).
 */
function mvrk_demo_products() {
	$items = array(
		array( 'next-generation-alkaloids',                 'Next Generation Alkaloids – Cherry Flavor',   'Next Generation Alkaloids', '39.99', 'next-gen-cherry.png',
			'Precision-dosed Next Generation Alkaloid tablets in a clean cherry profile. 300mg per tablet, scored into quarters for a measured 75mg serving — 40 servings per jar. Measured, predictable, repeatable. For adults 21+.' ),
		array( 'next-generation-alkaloids-blue-razz-flavor', 'Next Generation Alkaloids – Blue Razz Flavor', 'Next Generation Alkaloids', '39.99', 'next-gen-blue-razz.png',
			'The same 300mg precision format in a bold blue razz profile. Scored to 75mg quarter-servings, 40 per jar. Consistency you can feel. For adults 21+.' ),
		array( '7-hydroxy-tablets',                          '7-Hydroxy Tablets – Cherry Flavor',           '7-Hydroxy',                 '27.99', '7-hydroxy-cherry.png',
			'7-Hydroxy tablets in cherry. 100mg per tablet, scored into quarters for a 25mg serving — 40 servings per jar. Compact, carry-anywhere, dosed by the quarter. For adults 21+.' ),
		array( '7-hydroxy-tablets-blue-razz-flavor',         '7-Hydroxy Tablets – Blue Razz Flavor',        '7-Hydroxy',                 '27.99', '7-hydroxy-blue-razz.png',
			'7-Hydroxy in blue razz at 100mg per tablet (25mg per quarter-serving), 40 servings per jar. The same clean, no-guesswork serving in every jar. For adults 21+.' ),
	);

	foreach ( $items as $it ) {
		list( $slug, $name, $cat, $price, $img, $desc ) = $it;
		if ( get_page_by_path( $slug, OBJECT, 'product' ) ) {
			continue;
		}
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_slug( $slug );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_regular_price( $price );
		$product->set_description( $desc );
		$product->set_short_description( $desc );
		$term = term_exists( $cat, 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( $cat, 'product_cat' );
		}
		if ( ! is_wp_error( $term ) ) {
			$product->set_category_ids( array( (int) $term['term_id'] ) );
		}
		$product_id = $product->save();

		// Attach the featured image from the theme files (local copy — no HTTP).
		if ( $product_id && ! has_post_thumbnail( $product_id ) ) {
			$att_id = mvrk_demo_attach_local_image(
				get_template_directory() . '/assets/img/products/' . $img,
				$product_id,
				$name
			);
			if ( $att_id ) {
				set_post_thumbnail( $product_id, $att_id );
			}
		}
	}
}

/**
 * Create an attachment from a local file (no network), return attachment ID or 0.
 */
function mvrk_demo_attach_local_image( $src, $parent_id, $title ) {
	if ( ! file_exists( $src ) ) {
		return 0;
	}
	$uploads  = wp_upload_dir();
	$basename = wp_unique_filename( $uploads['path'], basename( $src ) );
	$dest     = trailingslashit( $uploads['path'] ) . $basename;
	if ( ! @copy( $src, $dest ) ) {
		return 0;
	}
	$filetype = wp_check_filetype( $basename, null );
	$att_id   = wp_insert_attachment( array(
		'post_mime_type' => $filetype['type'],
		'post_title'     => $title,
		'post_status'    => 'inherit',
	), $dest, $parent_id );
	if ( ! $att_id || is_wp_error( $att_id ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$meta = wp_generate_attachment_metadata( $att_id, $dest );
	wp_update_attachment_metadata( $att_id, $meta );
	return (int) $att_id;
}

/**
 * A few approved entrants for the current month so the wheel isn't empty.
 */
function mvrk_demo_entrants() {
	$month = gmdate( 'Y-m' );
	if ( mvrk_ga_count( $month, 'approved' ) > 0 ) {
		return;
	}
	$names = array( 'Marcus Bell', 'Jordan Vega', 'Riley Stone', 'Sam Ortega', 'Casey Nguyen', 'Devon Pierce' );
	foreach ( $names as $i => $n ) {
		mvrk_ga_insert_entry( array(
			'name'        => $n,
			'email'       => 'demo' . $i . '@example.com',
			'entry_type'  => 'purchase',
			'receipt_url' => '',
			'month'       => $month,
			'status'      => 'approved',
		) );
	}
}

/**
 * One demo winner (last month) so the Winners page shows the layout.
 */
function mvrk_demo_winner() {
	$existing = get_posts( array( 'post_type' => 'mvrk_winner', 'numberposts' => 1, 'fields' => 'ids' ) );
	if ( $existing ) {
		return;
	}
	$last_month = gmdate( 'Y-m', strtotime( 'first day of last month' ) );
	$id = wp_insert_post( array(
		'post_type'   => 'mvrk_winner',
		'post_status' => 'publish',
		'post_title'  => 'Winner — ' . $last_month,
	) );
	if ( $id && ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_mvrk_month', $last_month );
		update_post_meta( $id, '_mvrk_winner_name', 'Alex R.' );
		update_post_meta( $id, '_mvrk_prize', 'One free jar of MVRK (winner\'s choice)' );
		// Placeholder draw video — replace with the owner's real monthly video.
		update_post_meta( $id, '_mvrk_video_url', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ' );
	}
}
