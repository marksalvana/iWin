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

// =============================================================================
// Preset Avatar Picker
// Replaces UM's file-upload avatar field with a grid of 18 preset images.
// On registration and profile edit, users pick one preset; no custom uploads.
// =============================================================================

/**
 * Returns an array of preset avatar data.
 * Each entry: [ 'filename' => 'name.png', 'url' => 'https://…/name.png' ]
 */
function iwin_get_preset_avatars() {
	$src_dir = get_stylesheet_directory() . '/assets/images/';

	// Serve images from wp-content/uploads/iwin-presets/ so they are always
	// publicly accessible (WP Engine blocks direct access to theme subdirs).
	$upload   = wp_upload_dir();
	$dest_dir = $upload['basedir'] . '/iwin-presets/';
	$dest_url = $upload['baseurl'] . '/iwin-presets/';
	wp_mkdir_p( $dest_dir );

	$list = [];
	foreach ( glob( $src_dir . '*.png' ) as $file ) {
		$name      = basename( $file );
		$dest_path = $dest_dir . $name;
		if ( ! file_exists( $dest_path ) ) {
			copy( $file, $dest_path );
		}
		$list[] = [
			'filename' => $name,
			'url'      => $dest_url . $name,
		];
	}
	return $list;
}

/**
 * Hide the native UM upload controls without hiding the avatar display.
 *
 * - .um-field-profile_photo  → upload field inside the UM form builder (registration)
 * - .um-profile-photo-overlay → camera-hover overlay that opens the upload dialog
 *
 * We do NOT hide .um-profile-photo or .um-profile-photo-wrap because those
 * elements also contain the <img> that renders the chosen avatar.
 */
add_action( 'um_before_register_fields', 'iwin_hide_um_photo_upload_css' );
add_action( 'um_before_profile_fields',  'iwin_hide_um_photo_upload_css' );
function iwin_hide_um_photo_upload_css() {
	echo '<style>.um-field-profile_photo,.um-profile-photo-overlay{display:none!important}</style>';
}

/**
 * Remove all items from the UM photo dropdown menu so users cannot upload,
 * change, or remove their avatar via UM's built-in controls.
 * um_user_photo_menu_view  → menu when the user has no photo yet (view mode)
 * um_user_photo_menu_edit  → menu when a photo exists and the form is in edit mode
 */
add_filter( 'um_user_photo_menu_view', '__return_empty_array' );
add_filter( 'um_user_photo_menu_edit', '__return_empty_array' );

/**
 * Render the preset avatar picker grid after UM form fields.
 * On profile edit, the user's current selection is pre-highlighted.
 */
add_action( 'um_after_register_fields', 'iwin_avatar_picker_html' );
add_action( 'um_after_profile_fields',  'iwin_avatar_picker_html' );
function iwin_avatar_picker_html( $args ) {
	$presets = iwin_get_preset_avatars();
	if ( empty( $presets ) ) {
		return;
	}

	// On profile edit use the previously saved preset; on registration default to the first.
	$stored_source = '';
	if ( is_user_logged_in() ) {
		$stored_source = (string) get_user_meta( get_current_user_id(), 'iwin_preset_avatar_source', true );
	}
	$current = ! empty( $stored_source ) ? $stored_source : $presets[0]['filename'];

	echo '<div class="iwin-avatar-picker">';
	echo '<p class="iwin-avatar-picker-label">Choose your avatar</p>';
	echo '<div class="iwin-avatar-grid">';
	foreach ( $presets as $preset ) {
		$is_selected = ( $current === $preset['filename'] );
		$cls         = 'iwin-avatar-option' . ( $is_selected ? ' selected' : '' );
		echo '<img src="' . esc_url( $preset['url'] ) . '" '
			. 'class="' . esc_attr( $cls ) . '" '
			. 'data-filename="' . esc_attr( $preset['filename'] ) . '" '
			. 'alt="Avatar option" />';
	}
	echo '</div>';
	// Hidden input pre-populated with current/default selection so an avatar is
	// always submitted even if the user does not click anything.
	echo '<input type="hidden" name="iwin_preset_avatar" id="iwin_preset_avatar" value="' . esc_attr( $current ) . '" />';
	echo '</div>';
	?>
	<script>
	(function(){
		var options = document.querySelectorAll('.iwin-avatar-option');
		var input   = document.getElementById('iwin_preset_avatar');
		if ( ! input ) { return; }
		options.forEach(function(img){
			img.addEventListener('click', function(){
				options.forEach(function(i){ i.classList.remove('selected'); });
				img.classList.add('selected');
				input.value = img.dataset.filename;
			});
		});
	})();
	</script>
	<?php
}

/**
 * Copy the chosen preset image into the user's UM upload directory and
 * update the profile_photo meta so UM displays it everywhere.
 * Also stores the source filename in iwin_preset_avatar_source for
 * re-selecting the correct avatar on the profile edit form.
 *
 * @param int $user_id
 */
function iwin_apply_preset_avatar( $user_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- UM handles nonce
	// Use sanitize_text_field (not sanitize_file_name) so spaces in preset filenames are preserved.
	$selected = isset( $_POST['iwin_preset_avatar'] ) ? sanitize_text_field( wp_unslash( $_POST['iwin_preset_avatar'] ) ) : '';

	if ( empty( $selected ) ) {
		return;
	}

	// Whitelist: must exactly match one of our preset filenames.
	$valid = wp_list_pluck( iwin_get_preset_avatars(), 'filename' );
	if ( ! in_array( $selected, $valid, true ) ) {
		return;
	}

	$source = get_stylesheet_directory() . '/assets/images/' . $selected;
	if ( ! file_exists( $source ) ) {
		return;
	}

	// Ensure the user's UM upload directory exists.
	$dest_dir = UM()->uploader()->get_upload_base_dir() . $user_id . DIRECTORY_SEPARATOR;
	wp_mkdir_p( $dest_dir );

	// UM resolves the avatar by looking for "profile_photo.{ext}" in the user dir.
	$ext       = strtolower( pathinfo( $selected, PATHINFO_EXTENSION ) );
	$dest_name = 'profile_photo.' . $ext;
	$dest_path = $dest_dir . $dest_name;

	if ( ! copy( $source, $dest_path ) ) {
		return;
	}

	// Generate thumbnail files at all UM-configured sizes (e.g. profile_photo-96x96.png).
	// UM's display code looks for these sized variants; without them no avatar renders.
	$image = wp_get_image_editor( $dest_path );
	if ( ! is_wp_error( $image ) ) {
		$thumb_sizes = UM()->options()->get( 'photo_thumb_sizes' );
		if ( ! empty( $thumb_sizes ) && is_array( $thumb_sizes ) ) {
			$sizes_array = array();
			foreach ( $thumb_sizes as $size ) {
				// crop => true forces exact WxH output so UM finds "profile_photo-96x96.png".
				$sizes_array[] = array( 'width' => (int) $size, 'height' => (int) $size, 'crop' => true );
			}
			$quality = (int) UM()->options()->get( 'image_compression' );
			if ( $quality > 0 ) {
				$image->set_quality( $quality );
			}
			$image->multi_resize( $sizes_array );
		}
	}

	update_user_meta( $user_id, 'profile_photo', $dest_name );
	// Store source filename so the picker can re-highlight it on the profile edit form.
	update_user_meta( $user_id, 'iwin_preset_avatar_source', $selected );
}

/**
 * Save preset avatar on new user registration.
 */
add_action( 'um_registration_complete', 'iwin_save_preset_avatar_on_register', 10, 3 );
function iwin_save_preset_avatar_on_register( $user_id, $args, $form_data ) {
	iwin_apply_preset_avatar( $user_id );
}

/**
 * Save preset avatar when user updates their profile.
 */
add_action( 'um_user_after_updating_profile', 'iwin_save_preset_avatar_on_profile', 10, 3 );
function iwin_save_preset_avatar_on_profile( $to_update, $user_id, $form_data ) {
	iwin_apply_preset_avatar( $user_id );
}

// =============================================================================
// Pharmacy dropdown — 6,746 store names from the iWin pharmacy list.
// Uses UM's field-specific filter; no DB writes or UM admin changes needed.
// Select2 (bundled by UM) provides live search automatically.
// =============================================================================
add_filter( 'um_select_dropdown_dynamic_options_pharmacy', 'iwin_pharmacy_dropdown_options' );
function iwin_pharmacy_dropdown_options( $options ) {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = require get_stylesheet_directory() . '/assets/data/pharmacy-list.php';
	return $cache;
}

// =============================================================================
// Store ID — saved to `store_id` usermeta whenever a pharmacy is selected.
// Looks up the StoreId from the pharmacy name using the store map data file.
// Runs server-side so no JS or hidden form field is required.
// =============================================================================

/**
 * Saves the StoreId matching the selected pharmacy name to `store_id` usermeta.
 *
 * @param int $user_id
 */
function iwin_save_store_id( $user_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- UM handles nonce
	$pharmacy = isset( $_POST['pharmacy'] ) ? sanitize_text_field( wp_unslash( $_POST['pharmacy'] ) ) : '';
	if ( empty( $pharmacy ) ) {
		return;
	}

	static $map = null;
	if ( null === $map ) {
		$map = require get_stylesheet_directory() . '/assets/data/pharmacy-store-map.php';
	}

	if ( isset( $map[ $pharmacy ] ) ) {
		update_user_meta( $user_id, 'store_id', sanitize_text_field( $map[ $pharmacy ] ) );
	}
}

add_action( 'um_registration_complete', 'iwin_save_store_id_on_register', 10, 3 );
function iwin_save_store_id_on_register( $user_id, $args, $form_data ) {
	iwin_save_store_id( $user_id );
}

add_action( 'um_user_after_updating_profile', 'iwin_save_store_id_on_profile', 10, 3 );
function iwin_save_store_id_on_profile( $to_update, $user_id, $form_data ) {
	iwin_save_store_id( $user_id );
}
