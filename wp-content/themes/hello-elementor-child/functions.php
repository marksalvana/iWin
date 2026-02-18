<?php
/**
 * Theme functions and definitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function child_theme_enqueue_styles() {
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array('parent-style')
    );
}
add_action('wp_enqueue_scripts', 'child_theme_enqueue_styles');

if (!function_exists('iwin_displays_gallery')) {
	/**
	 * The [iwin_displays_gallery] shortcode.
	 *
	 * Display user uploaded images
	 *
	 * @param array  $atts    Shortcode attributes. Default empty.
	 * @param string $content Shortcode content. Default null.
	 * @param string $tag     Shortcode tag (name). Default empty.
	 * @return string Shortcode output.
	 */
	function iwin_displays_gallery( $atts = [], $content = null, $tag = '') {
		$form_id = $atts['forminator_form'] ?? null;
		$upload_field_id = $atts['upload_field'] ?? 'upload-1';
		$user_meta_key_field = $atts['user_meta_field'] ?? 'hidden-1';
		$brand_meta_key_field = $atts['brand_meta_field'] ?? 'select-1';
		$limit = $atts['limit'] ?? 12;

		global $wpdb;

		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id
				FROM {$wpdb->prefix}frmt_form_entry
				WHERE form_id = %d AND status = 'active' ORDER BY date_created DESC LIMIT %d",
				$form_id,
				$limit
			)
		);

		
		if (empty($entries)) {
			return '<p class="empty-filter-result">No result found</p>';
		}

		$output = '';
		$filter_template_path = get_stylesheet_directory() . '/gallery/image-grid-filter-template.php';
		
		if (file_exists($filter_template_path)) {
			ob_start();
			include $filter_template_path;
			$output .= ob_get_clean();
		}

		$output .= '<div class="forminator-lightbox-grid">';


		foreach ($entries as $entry) {

			$meta = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value
					FROM {$wpdb->prefix}frmt_form_entry_meta
					WHERE entry_id = %d",
					$entry->entry_id
				)
			);

			if (!$meta) continue;

			$files = [];

			foreach ($meta as $m) {
				if ($m->meta_key === $upload_field_id) {
					$list = maybe_unserialize($m->meta_value);
					if (isset($list) && is_array($list)) {
						$files[$entry->entry_id] = $list;
					}
				}

				if ($m->meta_key === $user_meta_key_field) {
					$user = get_user_meta($m->meta_value);
					$files[$entry->entry_id]['pharmacy'] = isset($user['pharmacy']) && is_array($user['pharmacy']) ? current($user['pharmacy']) : '';
				}

				if ($m->meta_key === $brand_meta_key_field) {
					$files[$entry->entry_id]['brand'] = $m->meta_value;
				}
			}

			if (!is_array($files)) continue;

			$template_path = get_stylesheet_directory() . '/gallery/forminator-image-grid-template.php';
			if (file_exists($template_path)) {
				ob_start();
				include $template_path;
				$output .= ob_get_clean();
			}
		}

		$output .= '</div>';

		return $output;
	}

	add_shortcode('iwin_gallery', 'iwin_displays_gallery');

	function enqueue_forminator_gallery_filter_script() {
		// Make sure jQuery is loaded as dependency
		wp_enqueue_script(
			'image-gallery-filter', 
			get_stylesheet_directory_uri() . '/gallery/js/image-grid-filter.js', 
			array('jquery'), 
			'1.0', 
			true
		);
	}
	add_action('wp_enqueue_scripts', 'enqueue_forminator_gallery_filter_script');
}

/**
 * Leaderboard: replace each myCred <li> row with a flex row showing
 * rank, avatar, name + pharmacy (below in gray), and score on the right.
 */
add_filter( 'mycred_ranking_row', 'iwin_leaderboard_custom_row', 10, 5 );
function iwin_leaderboard_custom_row( $layout, $template, $user, $position, $query ) {
	$user_id   = absint( $user['ID'] );
	$user_data = get_userdata( $user_id );

	// Name: first name + initial of last name
	$first = $user_data ? trim( $user_data->first_name ) : '';
	$last  = $user_data ? trim( $user_data->last_name )  : '';
	if ( empty( $first ) && $user_data ) {
		$first = $user_data->display_name;
	}
	$last_initial = ! empty( $last ) ? ' ' . strtoupper( mb_substr( $last, 0, 1 ) ) . '.' : '';
	$display      = esc_html( $first . $last_initial );

	// Clickable profile link (author archive, consistent with myCred default)
	$profile_url = esc_url( get_author_posts_url( $user_id ) );

	// Avatar (UM overrides get_avatar with the user's profile photo if set)
	$avatar = get_avatar( $user_id, 48, '', $display, array( 'class' => 'iwin-lb-avatar-img' ) );

	// Pharmacy custom field (Ultimate Member meta_key: pharmacy)
	$pharmacy      = get_user_meta( $user_id, 'pharmacy', true );
	$pharmacy_html = ! empty( $pharmacy )
		? '<span class="iwin-lb-pharmacy">' . esc_html( $pharmacy ) . '</span>'
		: '';

	// Carry over myCred row classes (item-N, alt, current-user, first-item)
	preg_match( '/class="([^"]*)"/', $layout, $matches );
	$classes = isset( $matches[1] ) ? esc_attr( $matches[1] ) : '';

	return '<li class="iwin-lb-item ' . $classes . '">'
		. '<span class="iwin-lb-rank">' . absint( $position ) . '</span>'
		. '<div class="iwin-lb-avatar">' . $avatar . '</div>'
		. '<div class="iwin-lb-info">'
			. '<a href="' . $profile_url . '" class="iwin-lb-name">' . $display . '</a>'
			. $pharmacy_html
		. '</div>'
		. '<span class="iwin-lb-score">' . esc_html( $user['cred'] ) . '</span>'
		. '</li>' . "\n";
}

/**
 * Leaderboard: replace the <ol> wrapper with a styled list + column header.
 */
add_filter( 'mycred_leaderboard', 'iwin_leaderboard_table_wrapper', 10, 3 );
function iwin_leaderboard_table_wrapper( $output, $args, $query ) {
	if ( strpos( $output, '<ol class="myCRED-leaderboard' ) === false ) {
		return $output; // empty state or non-list format — leave untouched
	}

	$header = '<div class="iwin-leaderboard-wrap">'
		. '<div class="iwin-lb-header">'
		. '<span class="iwin-lb-header-rank">Rank</span>'
		. '<span class="iwin-lb-header-agent">Name</span>'
		. '<span class="iwin-lb-header-score">Score</span>'
		. '</div>'
		. '<ul class="iwin-leaderboard-list">';

	$output = str_replace( '<ol class="myCRED-leaderboard list-unstyled">', $header,       $output );
	$output = str_replace( '</ol>',                                          '</ul></div>', $output );

	return $output;
}
