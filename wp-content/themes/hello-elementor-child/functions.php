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
 * Shortcode: [iwin_user_field field="pharmacy"]
 *
 * Fallback for displaying any UM user meta field in Elementor via a
 * Shortcode widget. Reads the UM profile user when on a profile page,
 * otherwise falls back to the logged-in user.
 *
 * Usage in Elementor Shortcode widget: [iwin_user_field field="pharmacy"]
 */
add_shortcode( 'iwin_user_field', 'iwin_user_field_shortcode' );
function iwin_user_field_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'field' => '' ), $atts, 'iwin_user_field' );
	$meta_key = sanitize_key( $atts['field'] );
	if ( ! $meta_key ) {
		return '';
	}

	$user_id = 0;
	if ( function_exists( 'um_profile_id' ) ) {
		$user_id = (int) um_profile_id();
	}
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id ) {
		return '';
	}

	return esc_html( get_user_meta( $user_id, $meta_key, true ) );
}

/**
 * Custom Elementor Dynamic Tag: "UM Profile User Meta"
 *
 * Reads any usermeta key for the UM profile user currently being viewed.
 * Falls back to the logged-in user when not on a UM profile page.
 * Appears in Elementor under Dynamic Tags → User → "UM Profile Field".
 *
 * The class is defined at file scope (not inside the function) to prevent
 * a PHP fatal "Cannot redeclare class" error when Elementor calls the
 * registration hook more than once in the editor context.
 */
add_action( 'elementor/dynamic_tags/register', 'iwin_register_dynamic_tags' );
function iwin_register_dynamic_tags( $manager ) {
	if ( ! class_exists( 'IWin_UM_Profile_Field_Tag' ) ) {
		return;
	}
	// Register a dedicated "User" group so the tag appears in the Elementor panel.
	$manager->register_group( 'iwin-user', [ 'title' => 'User' ] );
	$manager->register( new IWin_UM_Profile_Field_Tag() );
}

if ( class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) :

class IWin_UM_Profile_Field_Tag extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'iwin-um-profile-field';
	}

	public function get_title() {
		return 'UM Profile Field';
	}

	public function get_group() {
		return 'iwin-user';
	}

	public function get_categories() {
		// 'text' for string fields; 'number' so it appears on numeric fields (e.g. myCred User ID).
		return [ 'text', 'number' ];
	}

	protected function register_controls() {
		$this->add_control(
			'meta_key',
			[
				'label'       => 'Meta Key',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'e.g. pharmacy',
				'default'     => 'pharmacy',
			]
		);
	}

	public function render() {
		$meta_key = sanitize_key( $this->get_settings( 'meta_key' ) );
		if ( ! $meta_key ) {
			return;
		}

		// On a UM profile page use the profile user; otherwise the logged-in user.
		$user_id = 0;
		if ( function_exists( 'um_profile_id' ) ) {
			$user_id = (int) um_profile_id();
		}
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return;
		}

		// Special case: return the user ID itself (useful for myCred rank/points widgets).
		if ( in_array( $meta_key, array( 'user_id', 'id' ), true ) ) {
			echo esc_html( $user_id );
			return;
		}

		// Map friendly keys to core WP user object properties (wp_users table).
		$core_fields = array(
			'email'        => 'user_email',
			'user_email'   => 'user_email',
			'username'     => 'user_login',
			'user_login'   => 'user_login',
			'display_name' => 'display_name',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'name'         => 'display_name',
		);

		if ( isset( $core_fields[ $meta_key ] ) ) {
			$user_data = get_userdata( $user_id );
			$value     = $user_data ? $user_data->{ $core_fields[ $meta_key ] } : '';
		} else {
			$value = get_user_meta( $user_id, $meta_key, true );
		}

		if ( ! empty( $value ) ) {
			echo esc_html( $value );
		}
	}
}

endif;

/**
 * Shortcode: [iwin_profile_rank]
 *
 * Renders the myCred rank for the UM profile user currently being viewed.
 * Falls back to the logged-in user when not on a UM profile page.
 * Use in an Elementor Shortcode widget on the UM profile template.
 *
 * Supports all attributes of [mycred_my_rank]: show_title, show_logo, logo_size, ctype, first.
 * Example: [iwin_profile_rank show_title="1" show_logo="1"]
 */
add_shortcode( 'iwin_profile_rank', 'iwin_profile_rank_shortcode' );
function iwin_profile_rank_shortcode( $atts ) {
	$user_id = 0;
	if ( function_exists( 'um_profile_id' ) ) {
		$user_id = (int) um_profile_id();
	}
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id ) {
		return '';
	}

	// Pass any extra attributes (show_title, show_logo, ctype, etc.) through to mycred_my_rank.
	$extra = '';
	if ( is_array( $atts ) ) {
		foreach ( $atts as $key => $val ) {
			$extra .= ' ' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}
	}

	// [mycred_my_rank] is the shortcode used by the myCred "My Rank" Elementor widget.
	return do_shortcode( '[mycred_my_rank user_id="' . $user_id . '"' . $extra . ']' );
}

/**
 * Shortcode: [iwin_profile_balance]
 *
 * Renders the myCred total balance for the UM profile user currently being viewed.
 * Falls back to the logged-in user when not on a UM profile page.
 *
 * Supports the same attributes as [mycred_total_balance] (type, decimals, etc.)
 */
add_shortcode( 'iwin_profile_balance', 'iwin_profile_balance_shortcode' );
function iwin_profile_balance_shortcode( $atts ) {
	$user_id = 0;
	if ( function_exists( 'um_profile_id' ) ) {
		$user_id = (int) um_profile_id();
	}
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id ) {
		return '';
	}

	$extra = '';
	if ( is_array( $atts ) ) {
		foreach ( $atts as $key => $val ) {
			$extra .= ' ' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}
	}

	return do_shortcode( '[mycred_total_balance user_id="' . $user_id . '"' . $extra . ']' );
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
