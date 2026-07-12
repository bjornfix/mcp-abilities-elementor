<?php
/**
 * Plugin Name: MCP Abilities - Elementor
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-elementor
 * Description: Elementor abilities for MCP. Get, update, and patch Elementor page data. Manage templates and cache.
 * Version: 2.3.31
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Tested up to: 7.0
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/ability-schema.php';
require_once __DIR__ . '/includes/ability-registry.php';
require_once __DIR__ . '/includes/document-repository.php';
require_once __DIR__ . '/includes/guidance.php';
require_once __DIR__ . '/includes/template-query.php';
require_once __DIR__ . '/includes/design-audit-runner.php';
require_once __DIR__ . '/includes/register-document.php';
require_once __DIR__ . '/includes/register-design.php';
require_once __DIR__ . '/includes/register-elements.php';
require_once __DIR__ . '/includes/register-templates.php';
require_once __DIR__ . '/includes/register-pro.php';
require_once __DIR__ . '/includes/register-site-tools.php';

/**
 * Check if Abilities API is available.
 */
function mcp_abilities_elementor_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Elementor</strong> requires the <a href="https://github.com/WordPress/abilities-api">Abilities API</a> plugin to be installed and activated.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Check if Elementor is active.
 */
function mcp_abilities_elementor_is_active(): bool {
	return class_exists( '\\Elementor\\Plugin' ) || defined( 'ELEMENTOR_VERSION' );
}

/**
 * Return input schema fragment for high-risk Elementor document writes.
 *
 * @param string $ability_name Ability name.
 * @return array
 */
function mcp_abilities_elementor_dangerous_action_confirmation_schema( string $ability_name ): array {
	return array(
		'type'        => 'string',
		'description' => sprintf(
			/* translators: %s: Ability name. */
			__( 'Required for this high-risk Elementor document write. Must exactly equal "%s".', 'mcp-abilities-elementor' ),
			$ability_name
		),
	);
}

/**
 * Require explicit per-ability confirmation for full/raw Elementor document writes.
 *
 * @param array  $input        Ability input.
 * @param string $ability_name Ability name.
 * @return true|WP_Error
 */
function mcp_abilities_elementor_confirm_dangerous_action( array $input, string $ability_name ) {
	$confirmation = isset( $input['confirm_dangerous_action'] ) ? (string) $input['confirm_dangerous_action'] : '';
	if ( $ability_name === $confirmation ) {
		return true;
	}

	return new WP_Error(
		'mcp_elementor_dangerous_action_confirmation_required',
		sprintf(
			/* translators: 1: Ability name, 2: Confirmation parameter name, 3: Confirmation value. */
			__( 'High-risk Elementor ability "%1$s" requires explicit confirmation. Set %2$s to "%3$s" after verifying the target and rollback path.', 'mcp-abilities-elementor' ),
			$ability_name,
			'confirm_dangerous_action',
			$ability_name
		)
	);
}

/**
 * Convert an Elementor dangerous-action guard result to ability output.
 *
 * @param true|WP_Error $result Guard result.
 * @param string        $ability_name Ability name.
 * @return array|null
 */
function mcp_abilities_elementor_dangerous_action_error_response( $result, string $ability_name ): ?array {
	if ( ! is_wp_error( $result ) ) {
		return null;
	}

	return array(
		'success' => false,
		'message' => $result->get_error_message(),
		'ability' => $ability_name,
		'code'    => $result->get_error_code(),
	);
}

/**
 * Normalize cache scope input for write abilities.
 *
 * Supported values:
 * - none: skip cache invalidation (advanced/debug use)
 * - post: clear post-level caches and touch the post (default)
 * - site: clear post-level caches + site-wide Elementor cache
 *
 * @param mixed  $raw Raw input value.
 * @param string $default Default scope.
 * @return string
 */
function mcp_abilities_elementor_normalize_cache_scope( $raw, string $default = 'post' ): string {
	$scope = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';

	if ( in_array( $scope, array( 'none', 'post', 'site' ), true ) ) {
		return $scope;
	}

	return $default;
}

/**
 * Get Elementor raw data meta normalized as a JSON string.
 *
 * Older/broken writes can leave the meta in unexpected shapes. This helper
 * normalizes the value so read abilities can remain schema-safe.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function mcp_abilities_elementor_get_raw_data_meta( int $post_id ): string {
	$value = get_post_meta( $post_id, '_elementor_data', true );

	if ( is_string( $value ) ) {
		return $value;
	}

	if ( is_array( $value ) ) {
		$json = wp_json_encode( $value );
		return is_string( $json ) ? $json : '';
	}

	return '';
}

/**
 * Filter name for translation sibling providers used by guarded writes.
 *
 * @return string
 */
function mcp_abilities_elementor_translation_sibling_filter_name(): string {
	return 'mcp_abilities_elementor_translation_sibling_post_ids';
}

/**
 * Get sibling translation post IDs for a post from registered language providers.
 *
 * Elementor document meta must be independently editable per language. Some
 * multilingual plugins can copy custom fields such as _elementor_data to
 * translation siblings during update_post_meta(), so guarded writes snapshot
 * sibling rows first and restore them if a sync hook changes them.
 *
 * @param int $post_id Source post ID.
 * @return int[]
 */
function mcp_abilities_elementor_get_translation_sibling_post_ids( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}

	$sibling_ids = array();

	$sibling_ids = apply_filters( mcp_abilities_elementor_translation_sibling_filter_name(), $sibling_ids, $post_id, $post );

	if ( ! is_array( $sibling_ids ) ) {
		return array();
	}

	return array_values(
		array_unique(
			array_filter(
				array_map( 'intval', $sibling_ids ),
				static function ( int $sibling_id ) use ( $post_id ): bool {
					return $sibling_id > 0 && $sibling_id !== $post_id;
				}
			)
		)
	);
}

/**
 * Capture sibling meta values through WordPress metadata APIs.
 *
 * @param int[]  $post_ids Post IDs.
 * @param string $meta_key Meta key.
 * @return array<int,mixed>
 */
function mcp_abilities_elementor_capture_sibling_meta_values( array $post_ids, string $meta_key ): array {
	$snapshot = array();
	foreach ( $post_ids as $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id > 0 ) {
			$snapshot[ $post_id ] = get_post_meta( $post_id, $meta_key, true );
		}
	}

	return $snapshot;
}

/**
 * Prepare a captured meta value for safe restore through update_post_meta().
 *
 * WordPress unslashes meta values before storage. JSON-backed Elementor meta
 * captured through get_post_meta() is already unslashed, so restoring it as a
 * plain string corrupts escaped quotes in values such as links and text editor
 * HTML. Slashing only the JSON-backed string values mirrors normal Elementor
 * write paths while leaving scalar/non-JSON meta untouched.
 *
 * @param string $meta_key Meta key.
 * @param mixed  $value    Captured meta value.
 * @return mixed
 */
function mcp_abilities_elementor_prepare_sibling_meta_restore_value( string $meta_key, $value ) {
	$json_meta_keys = array(
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_popup_display_settings',
		'_elementor_conditions',
	);

	if ( ! in_array( $meta_key, $json_meta_keys, true ) ) {
		return $value;
	}

	if ( is_string( $value ) ) {
		return wp_slash( $value );
	}

	if ( is_array( $value ) ) {
		$json = wp_json_encode( $value );
		return is_string( $json ) ? wp_slash( $json ) : $value;
	}

	return $value;
}

/**
 * Restore sibling meta values if a multilingual sync changed them.
 *
 * @param array<int,mixed> $snapshot Captured sibling values.
 * @param string           $meta_key Meta key.
 * @return array
 */
function mcp_abilities_elementor_restore_sibling_meta_values( array $snapshot, string $meta_key ): array {
	$restored = array();
	foreach ( $snapshot as $post_id => $value ) {
		$post_id = (int) $post_id;
		if ( $post_id > 0 && get_post_meta( $post_id, $meta_key, true ) !== $value ) {
			update_post_meta( $post_id, $meta_key, mcp_abilities_elementor_prepare_sibling_meta_restore_value( $meta_key, $value ) );
			clean_post_cache( $post_id );
			$restored[] = $post_id;
		}
	}

	return array(
		'restored_post_ids' => $restored,
		'restored_count'    => count( $restored ),
	);
}

/**
 * Schedule a final sibling meta restore after late multilingual sync hooks.
 *
 * @param array<int,mixed> $snapshot Captured sibling values.
 * @param string           $meta_key Meta key.
 * @return bool
 */
function mcp_abilities_elementor_schedule_shutdown_sibling_meta_restore( array $snapshot, string $meta_key ): bool {
	if ( empty( $snapshot ) ) {
		return false;
	}

	register_shutdown_function(
		static function () use ( $snapshot, $meta_key ): void {
			mcp_abilities_elementor_restore_sibling_meta_values( $snapshot, $meta_key );
		}
	);

	return true;
}

/**
 * Update _elementor_data while preserving translated sibling documents.
 *
 * @param int   $post_id Post ID.
 * @param mixed $meta_value Meta value.
 * @return array
 */
function mcp_abilities_elementor_update_guarded_elementor_data( int $post_id, $meta_value ): array {
	$sibling_ids = mcp_abilities_elementor_get_translation_sibling_post_ids( $post_id );
	$snapshot    = mcp_abilities_elementor_capture_sibling_meta_values( $sibling_ids, '_elementor_data' );
	$result      = update_post_meta( $post_id, '_elementor_data', $meta_value );
	$restore     = mcp_abilities_elementor_restore_sibling_meta_values( $snapshot, '_elementor_data' );
	$scheduled   = mcp_abilities_elementor_schedule_shutdown_sibling_meta_restore( $snapshot, '_elementor_data' );

	return array(
		'updated'                    => false !== $result,
		'protected_post_ids'         => $sibling_ids,
		'protected_count'            => count( $sibling_ids ),
		'restored_post_ids'          => $restore['restored_post_ids'],
		'restored_count'             => $restore['restored_count'],
		'shutdown_restore_scheduled' => $scheduled,
	);
}

/**
 * Update _elementor_page_settings while preserving translated sibling settings.
 *
 * @param int   $post_id Post ID.
 * @param mixed $meta_value Meta value.
 * @return array
 */
function mcp_abilities_elementor_update_guarded_page_settings( int $post_id, $meta_value ): array {
	$sibling_ids = mcp_abilities_elementor_get_translation_sibling_post_ids( $post_id );
	$snapshot    = mcp_abilities_elementor_capture_sibling_meta_values( $sibling_ids, '_elementor_page_settings' );
	$result      = update_post_meta( $post_id, '_elementor_page_settings', $meta_value );
	$restore     = mcp_abilities_elementor_restore_sibling_meta_values( $snapshot, '_elementor_page_settings' );
	$scheduled   = mcp_abilities_elementor_schedule_shutdown_sibling_meta_restore( $snapshot, '_elementor_page_settings' );

	return array(
		'updated'                    => false !== $result,
		'protected_post_ids'         => $sibling_ids,
		'protected_count'            => count( $sibling_ids ),
		'restored_post_ids'          => $restore['restored_post_ids'],
		'restored_count'             => $restore['restored_count'],
		'shutdown_restore_scheduled' => $scheduled,
	);
}

/**
 * Decode Elementor JSON into a schema-safe array.
 *
 * @param mixed       $raw Raw Elementor data value.
 * @param string|null $error Optional decode error output.
 * @return array
 */
function mcp_abilities_elementor_decode_data_meta( $raw, ?string &$error = null ): array {
	$error = null;

	if ( is_array( $raw ) ) {
		return $raw;
	}

	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}

	$decoded = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		$error = json_last_error_msg();
		return array();
	}

	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Check whether an Elementor element is a container using a background image.
 *
 * Elementor's CSS compiler can be sensitive to incomplete hand-authored
 * container payloads. We treat background-image containers specially so
 * targeted replacements inherit enough of the original frame to remain
 * compiler-safe.
 *
 * @param array $element Elementor element data.
 * @return bool
 */
function mcp_abilities_elementor_is_background_image_container( array $element ): bool {
	if ( 'container' !== ( $element['elType'] ?? '' ) ) {
		return false;
	}

	$settings = $element['settings'] ?? null;
	if ( ! is_array( $settings ) ) {
		return false;
	}

	$background_type = $settings['background_background'] ?? '';
	$background      = $settings['background_image'] ?? null;

	if ( 'classic' !== $background_type ) {
		return false;
	}

	if ( is_array( $background ) ) {
		return ! empty( $background['url'] ) || ! empty( $background['id'] );
	}

	if ( is_string( $background ) ) {
		return '' !== trim( $background );
	}

	return false;
}

/**
 * Normalize replacement payload for background-image containers.
 *
 * Copy compiler-relevant container settings from the original element when the
 * incoming payload omits them. This keeps targeted updates from silently
 * dropping background CSS generation on installs where Elementor expects a
 * fuller container frame.
 *
 * @param array $new_element Replacement payload.
 * @param array $original_element Existing stored element.
 * @return array
 */
function mcp_abilities_elementor_normalize_background_container_element( array $new_element, array $original_element ): array {
	if ( ! mcp_abilities_elementor_is_background_image_container( $new_element ) ) {
		return $new_element;
	}

	if ( 'container' !== ( $original_element['elType'] ?? '' ) ) {
		return $new_element;
	}

	if ( ! isset( $new_element['settings'] ) || ! is_array( $new_element['settings'] ) ) {
		$new_element['settings'] = array();
	}

	$settings          = $new_element['settings'];
	$original_settings = is_array( $original_element['settings'] ?? null ) ? $original_element['settings'] : array();

	$inherited_setting_keys = array(
		'content_width',
		'width',
		'width_tablet',
		'width_mobile',
		'flex_basis',
		'flex_basis_tablet',
		'flex_basis_mobile',
		'min_height',
		'min_height_tablet',
		'min_height_mobile',
		'flex_direction',
		'flex_justify_content',
		'flex_align_items',
		'padding',
		'padding_tablet',
		'padding_mobile',
		'padding_laptop',
		'padding_widescreen',
		'padding_widescreen_extra',
		'padding_mobile_extra',
		'padding_tablet_extra',
	);

	foreach ( $inherited_setting_keys as $key ) {
		if ( array_key_exists( $key, $settings ) || ! array_key_exists( $key, $original_settings ) ) {
			continue;
		}
		$settings[ $key ] = $original_settings[ $key ];
	}

	if ( empty( $settings['content_width'] ) ) {
		$settings['content_width'] = 'full';
	}

	if ( empty( $settings['flex_basis'] ) && ! empty( $settings['width'] ) ) {
		$settings['flex_basis'] = $settings['width'];
	}

	if ( empty( $settings['flex_direction'] ) ) {
		$settings['flex_direction'] = 'column';
	}

	$new_element['settings'] = $settings;

	return $new_element;
}

/**
 * Append a CSS class to Elementor settings without duplicating tokens.
 *
 * @param array  $settings Elementor settings array.
 * @param string $class_name CSS class to ensure on the element.
 * @return array
 */
function mcp_abilities_elementor_append_css_class( array $settings, string $class_name ): array {
	$class_name = trim( $class_name );
	if ( '' === $class_name ) {
		return $settings;
	}

	$existing = isset( $settings['css_classes'] ) && is_string( $settings['css_classes'] ) ? $settings['css_classes'] : '';
	$tokens   = preg_split( '/\s+/', trim( $existing ) );
	$tokens   = is_array( $tokens ) ? array_values( array_filter( $tokens, 'strlen' ) ) : array();

	if ( ! in_array( $class_name, $tokens, true ) ) {
		$tokens[] = $class_name;
	}

	$settings['css_classes'] = implode( ' ', $tokens );

	return $settings;
}

/**
 * Determine whether an Elementor subtree contains a background-image container.
 *
 * @param array $element Elementor element data.
 * @return bool
 */
function mcp_abilities_elementor_subtree_has_background_image_container( array $element ): bool {
	if ( mcp_abilities_elementor_is_background_image_container( $element ) ) {
		return true;
	}

	$children = $element['elements'] ?? null;
	if ( ! is_array( $children ) ) {
		return false;
	}

	foreach ( $children as $child ) {
		if ( is_array( $child ) && mcp_abilities_elementor_subtree_has_background_image_container( $child ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Normalize a full Elementor tree for background-image subtree safety.
 *
 * Elementor can apply lazyload descendant resets from top-level parent
 * containers. When a top-level subtree contains a native background-image
 * container, append `e-no-lazyload` to that subtree root so generated
 * background CSS can actually paint on the frontend.
 *
 * @param array $elements Top-level Elementor data array.
 * @return array
 */
function mcp_abilities_elementor_normalize_background_container_subtrees( array $elements ): array {
	foreach ( $elements as $index => $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		if (
			'container' === ( $element['elType'] ?? '' ) &&
			mcp_abilities_elementor_subtree_has_background_image_container( $element )
		) {
			$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
			$element['settings'] = mcp_abilities_elementor_append_css_class( $settings, 'e-no-lazyload' );
		}

		$elements[ $index ] = $element;
	}

	return $elements;
}

/**
 * Deep-merge Elementor settings arrays.
 *
 * Scalar values from $overrides replace values in $base. Nested arrays are
 * merged recursively unless either side is a numerically indexed list, in
 * which case the override replaces the base.
 *
 * @param array $base Existing settings.
 * @param array $overrides Incoming overrides.
 * @return array
 */
function mcp_abilities_elementor_merge_settings( array $base, array $overrides ): array {
	foreach ( $overrides as $key => $value ) {
		if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
			$base_is_list     = array_values( $base[ $key ] ) === $base[ $key ];
			$override_is_list = array_values( $value ) === $value;

			if ( $base_is_list || $override_is_list ) {
				$base[ $key ] = $value;
				continue;
			}

			$base[ $key ] = mcp_abilities_elementor_merge_settings( $base[ $key ], $value );
			continue;
		}

		$base[ $key ] = $value;
	}

	return $base;
}

/**
 * Find an Elementor element by ID with depth/path metadata.
 *
 * @param array  $elements Elementor tree.
 * @param string $target_id Target element ID.
 * @param int    $depth Current recursion depth.
 * @param array  $path Current ID path.
 * @return array|null
 */
function mcp_abilities_elementor_find_element_meta( array $elements, string $target_id, int $depth = 0, array $path = array() ): ?array {
	foreach ( $elements as $index => $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		$current_path = $path;
		if ( isset( $element['id'] ) && is_string( $element['id'] ) ) {
			$current_path[] = $element['id'];
		}

		if ( ( $element['id'] ?? null ) === $target_id ) {
			return array(
				'element' => $element,
				'depth'   => $depth,
				'index'   => $index,
				'path'    => $current_path,
			);
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$child_meta = mcp_abilities_elementor_find_element_meta( $element['elements'], $target_id, $depth + 1, $current_path );
			if ( is_array( $child_meta ) ) {
				return $child_meta;
			}
		}
	}

	return null;
}

/**
 * Replace an Elementor element in a tree by ID.
 *
 * @param array  $elements Elementor tree.
 * @param string $target_id Target element ID.
 * @param array  $new_element Replacement element.
 * @return bool True when replaced.
 */
function mcp_abilities_elementor_replace_element_in_tree( array &$elements, string $target_id, array $new_element ): bool {
	foreach ( $elements as $index => &$element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		if ( ( $element['id'] ?? null ) === $target_id ) {
			$elements[ $index ] = $new_element;
			return true;
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			if ( mcp_abilities_elementor_replace_element_in_tree( $element['elements'], $target_id, $new_element ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Generate a short Elementor-like element ID.
 *
 * Elementor stores IDs as short hex-ish strings. A random 7 character token is
 * enough for practical uniqueness inside one page, and we still collision-check
 * before writing.
 *
 * @return string
 */
function mcp_abilities_elementor_generate_element_id(): string {
	try {
		return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	} catch ( \Throwable $e ) {
		return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 7 );
	}
}

/**
 * Collect all element IDs from a tree.
 *
 * @param array $elements Elementor tree.
 * @param array $ids Existing IDs.
 * @return array
 */
function mcp_abilities_elementor_collect_element_ids( array $elements, array $ids = array() ): array {
	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		if ( isset( $element['id'] ) && is_string( $element['id'] ) && '' !== $element['id'] ) {
			$ids[] = $element['id'];
		}

		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$ids = mcp_abilities_elementor_collect_element_ids( $element['elements'], $ids );
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Generate an element ID that is not already present in the tree.
 *
 * @param array       $elements Elementor tree.
 * @param string|null $requested_id Optional caller-provided ID.
 * @return string
 */
function mcp_abilities_elementor_unique_element_id( array $elements, ?string $requested_id = null ): string {
	$existing = mcp_abilities_elementor_collect_element_ids( $elements );
	$requested_id = is_string( $requested_id ) ? sanitize_key( $requested_id ) : '';

	if ( '' !== $requested_id && ! in_array( $requested_id, $existing, true ) ) {
		return $requested_id;
	}

	do {
		$id = mcp_abilities_elementor_generate_element_id();
	} while ( in_array( $id, $existing, true ) );

	return $id;
}

/**
 * Create a minimal Elementor container element.
 *
 * @param array       $settings Container settings.
 * @param array       $children Child elements.
 * @param string|null $id Optional ID.
 * @return array
 */
function mcp_abilities_elementor_build_container_element( array $settings = array(), array $children = array(), ?string $id = null ): array {
	return array(
		'id'       => $id ?: mcp_abilities_elementor_generate_element_id(),
		'elType'   => 'container',
		'settings' => $settings,
		'elements' => array_values( array_filter( $children, 'is_array' ) ),
	);
}

/**
 * Create a minimal Elementor widget element.
 *
 * @param string      $widget_type Elementor widget type.
 * @param array       $settings Widget settings.
 * @param string|null $id Optional ID.
 * @return array
 */
function mcp_abilities_elementor_build_widget_element( string $widget_type, array $settings = array(), ?string $id = null ): array {
	return array(
		'id'         => $id ?: mcp_abilities_elementor_generate_element_id(),
		'elType'     => 'widget',
		'widgetType' => sanitize_key( $widget_type ),
		'settings'   => $settings,
		'elements'   => array(),
	);
}

/**
 * Create a native Elementor Nested Tabs widget containing Posts widgets.
 *
 * @param array       $tabs Tab definitions.
 * @param array       $base_posts_settings Settings shared by all Posts widgets.
 * @param array       $tabs_settings Nested Tabs widget settings.
 * @param string|null $id Optional tabs widget ID.
 * @param array       $existing_ids IDs already present in the document.
 * @return array|WP_Error
 */
function mcp_abilities_elementor_build_post_tabs_element( array $tabs, array $base_posts_settings = array(), array $tabs_settings = array(), ?string $id = null, array $existing_ids = array() ) {
	$existing_ids = array_values( array_unique( array_filter( array_map( 'strval', $existing_ids ) ) ) );

	$next_id = static function ( ?string $requested_id = null ) use ( &$existing_ids ): string {
		$requested_id = is_string( $requested_id ) ? sanitize_key( $requested_id ) : '';
		if ( '' !== $requested_id && ! in_array( $requested_id, $existing_ids, true ) ) {
			$existing_ids[] = $requested_id;
			return $requested_id;
		}

		do {
			$generated = mcp_abilities_elementor_generate_element_id();
		} while ( in_array( $generated, $existing_ids, true ) );

		$existing_ids[] = $generated;
		return $generated;
	};

	$tabs_widget_id = $next_id( $id );
	$settings       = array_replace_recursive(
		array(
			'tabs_direction'          => 'block-start',
			'tabs_justify_horizontal' => 'start',
			'horizontal_scroll'       => 'disable',
			'breakpoint_selector'     => 'none',
		),
		$tabs_settings
	);
	if ( isset( $settings['horizontal_scroll_mobile'] ) && ! isset( $settings['horizontal_scroll'] ) ) {
		$settings['horizontal_scroll'] = $settings['horizontal_scroll_mobile'];
	}
	if ( isset( $settings['tabs_direction'] ) && 'row' === $settings['tabs_direction'] ) {
		$settings['tabs_direction'] = 'block-start';
	}
	if ( isset( $settings['tabs_justify_horizontal'] ) && 'flex-start' === $settings['tabs_justify_horizontal'] ) {
		$settings['tabs_justify_horizontal'] = 'start';
	}
	$settings['tabs'] = array();
	$child_containers  = array();

	foreach ( $tabs as $index => $tab ) {
		if ( ! is_array( $tab ) ) {
			return new WP_Error( 'mcp_elementor_invalid_post_tab', 'Each tab must be an object.' );
		}

		$title = isset( $tab['title'] ) ? sanitize_text_field( (string) $tab['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'mcp_elementor_invalid_post_tab_title', 'Each tab requires a non-empty title.' );
		}

		$tab_id   = $next_id( isset( $tab['tab_id'] ) ? (string) $tab['tab_id'] : $tabs_widget_id . 'tab' . ( $index + 1 ) );
		$posts_id = $next_id( isset( $tab['posts_element_id'] ) ? (string) $tab['posts_element_id'] : $tab_id . 'posts' );

		$post_settings = $base_posts_settings;
		if ( isset( $tab['posts_settings'] ) && is_array( $tab['posts_settings'] ) ) {
			$post_settings = array_replace_recursive( $post_settings, $tab['posts_settings'] );
		}

		$settings['tabs'][] = array(
			'_id'       => $tab_id,
			'tab_title' => $title,
		);

		$container_settings = array(
			'content_width'  => 'full',
			'flex_direction' => 'column',
			'padding'        => array(
				'unit'     => 'px',
				'top'      => 0,
				'right'    => 0,
				'bottom'   => 0,
				'left'     => 0,
				'isLinked' => false,
			),
		);
		if ( isset( $tab['container_settings'] ) && is_array( $tab['container_settings'] ) ) {
			$container_settings = array_replace_recursive( $container_settings, $tab['container_settings'] );
		}

		$child_containers[] = mcp_abilities_elementor_build_container_element(
			$container_settings,
			array(
				mcp_abilities_elementor_build_widget_element( 'posts', $post_settings, $posts_id ),
			),
			$tab_id
		);
	}

	if ( empty( $settings['tabs'] ) ) {
		return new WP_Error( 'mcp_elementor_post_tabs_empty', 'At least one tab is required.' );
	}

	return array(
		'id'         => $tabs_widget_id,
		'elType'     => 'widget',
		'widgetType' => 'nested-tabs',
		'settings'   => $settings,
		'elements'   => $child_containers,
	);
}

/**
 * Insert an element into an Elementor tree.
 *
 * @param array       $elements Elementor tree by reference.
 * @param array       $new_element Element to insert.
 * @param string|null $parent_id Parent element ID, or empty for top level.
 * @param int         $position Position, -1 to append.
 * @return bool
 */
function mcp_abilities_elementor_insert_element_in_tree( array &$elements, array $new_element, ?string $parent_id = null, int $position = -1 ): bool {
	$parent_id = is_string( $parent_id ) ? trim( $parent_id ) : '';

	if ( '' === $parent_id ) {
		$insert_at = ( $position >= 0 ) ? min( $position, count( $elements ) ) : count( $elements );
		array_splice( $elements, $insert_at, 0, array( $new_element ) );
		return true;
	}

	foreach ( $elements as &$element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		if ( ( $element['id'] ?? null ) === $parent_id ) {
			if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) {
				$element['elements'] = array();
			}
			$insert_at = ( $position >= 0 ) ? min( $position, count( $element['elements'] ) ) : count( $element['elements'] );
			array_splice( $element['elements'], $insert_at, 0, array( $new_element ) );
			return true;
		}

		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			if ( mcp_abilities_elementor_insert_element_in_tree( $element['elements'], $new_element, $parent_id, $position ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Remove an element from an Elementor tree and return the removed element.
 *
 * @param array  $elements Elementor tree by reference.
 * @param string $target_id Element ID.
 * @param array  $removed Removed element output.
 * @param int    $depth Current depth.
 * @return bool
 */
function mcp_abilities_elementor_remove_element_from_tree( array &$elements, string $target_id, array &$removed = array(), int $depth = 0 ): bool {
	foreach ( $elements as $index => &$element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		if ( ( $element['id'] ?? null ) === $target_id ) {
			$removed = array(
				'element' => $element,
				'depth'   => $depth,
				'index'   => $index,
			);
			unset( $elements[ $index ] );
			$elements = array_values( $elements );
			return true;
		}

		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			if ( mcp_abilities_elementor_remove_element_from_tree( $element['elements'], $target_id, $removed, $depth + 1 ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Check whether a subtree contains an element ID.
 *
 * @param array  $element Elementor element.
 * @param string $target_id Target ID.
 * @return bool
 */
function mcp_abilities_elementor_subtree_contains_element_id( array $element, string $target_id ): bool {
	if ( ( $element['id'] ?? null ) === $target_id ) {
		return true;
	}

	$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
	foreach ( $children as $child ) {
		if ( is_array( $child ) && mcp_abilities_elementor_subtree_contains_element_id( $child, $target_id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Reassign all IDs in a duplicated Elementor subtree.
 *
 * @param array $element Elementor element.
 * @param array $existing Existing IDs.
 * @return array
 */
function mcp_abilities_elementor_reassign_subtree_ids( array $element, array &$existing ): array {
	$id = mcp_abilities_elementor_generate_element_id();
	while ( in_array( $id, $existing, true ) ) {
		$id = mcp_abilities_elementor_generate_element_id();
	}
	$existing[] = $id;
	$element['id'] = $id;

	if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( is_array( $child ) ) {
				$element['elements'][ $index ] = mcp_abilities_elementor_reassign_subtree_ids( $child, $existing );
			}
		}
	}

	return $element;
}

/**
 * Reorder direct children under a parent, or top-level elements when parent is empty.
 *
 * @param array       $elements Elementor tree by reference.
 * @param array       $ordered_ids Desired child order.
 * @param string|null $parent_id Parent element ID.
 * @return bool
 */
function mcp_abilities_elementor_reorder_children_in_tree( array &$elements, array $ordered_ids, ?string $parent_id = null ): bool {
	$parent_id = is_string( $parent_id ) ? trim( $parent_id ) : '';

	if ( '' === $parent_id ) {
		$lookup = array();
		$rest   = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && in_array( $element['id'], $ordered_ids, true ) ) {
				$lookup[ $element['id'] ] = $element;
			} else {
				$rest[] = $element;
			}
		}
		$ordered = array();
		foreach ( $ordered_ids as $id ) {
			if ( isset( $lookup[ $id ] ) ) {
				$ordered[] = $lookup[ $id ];
			}
		}
		$elements = array_values( array_merge( $ordered, $rest ) );
		return true;
	}

	foreach ( $elements as &$element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		if ( ( $element['id'] ?? null ) === $parent_id ) {
			if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) {
				return false;
			}
			return mcp_abilities_elementor_reorder_children_in_tree( $element['elements'], $ordered_ids, null );
		}

		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			if ( mcp_abilities_elementor_reorder_children_in_tree( $element['elements'], $ordered_ids, $parent_id ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Save Elementor data for a post and apply common cache/runtime handling.
 *
 * @param int    $post_id Post ID.
 * @param array  $data Elementor data.
 * @param string $cache_scope Cache scope.
 * @param bool   $allow_legacy_style_preservation Allow unchanged legacy style debt.
 * @return array
 */
function mcp_abilities_elementor_save_document_data( int $post_id, array $data, string $cache_scope = 'post', bool $allow_legacy_style_preservation = false ): array {
	$normalized_data = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
	$style_policy    = mcp_abilities_elementor_enforce_global_style_policy( $normalized_data );
	if ( empty( $style_policy['success'] ) ) {
		$existing_data   = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		$existing_policy = is_array( $existing_data ) ? mcp_abilities_elementor_enforce_global_style_policy( $existing_data ) : array();
		if ( ! $allow_legacy_style_preservation || ! mcp_abilities_elementor_legacy_style_violations_preserved( $existing_policy, $style_policy ) ) {
			return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
		}
	}

	$normalized_data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $normalized_data;
	$json_data       = wp_json_encode( $normalized_data );

	if ( false === $json_data ) {
		return array( 'success' => false, 'message' => 'Failed to encode Elementor data to JSON' );
	}

	mcp_abilities_elementor_update_guarded_elementor_data( $post_id, wp_slash( $json_data ) );
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

	$cache_details = mcp_abilities_elementor_invalidate_after_write(
		$post_id,
		mcp_abilities_elementor_normalize_cache_scope( $cache_scope, 'post' )
	);

	return array(
		'success' => true,
		'cache'   => $cache_details,
		'data'    => $normalized_data,
		'style_policy' => array(
			'enforced' => true,
			'legacy_preserved' => ! empty( $style_policy['violations'] ),
			'normalized' => $style_policy['normalized'] ?? array(),
		),
	);
}

/**
 * Build settings for common convenience widgets.
 *
 * @param string $widget_type Elementor widget type.
 * @param array  $input Raw ability input.
 * @return array
 */
function mcp_abilities_elementor_build_convenience_widget_settings( string $widget_type, array $input ): array {
	$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
	$skip     = array( 'id', 'parent_element_id', 'position', 'element_id', 'widget_type', 'settings', 'cache_scope' );

	foreach ( $input as $key => $value ) {
		if ( in_array( $key, $skip, true ) ) {
			continue;
		}
		$settings[ $key ] = $value;
	}

	if ( 'heading' === $widget_type && isset( $input['title'] ) ) {
		$settings['title'] = (string) $input['title'];
		$settings['header_size'] = isset( $settings['header_size'] ) ? (string) $settings['header_size'] : 'h2';
	}

	if ( 'text-editor' === $widget_type && isset( $input['editor'] ) ) {
		$settings['editor'] = (string) $input['editor'];
	}

	if ( 'button' === $widget_type ) {
		if ( isset( $input['text'] ) ) {
			$settings['text'] = (string) $input['text'];
		}
		if ( isset( $input['url'] ) && ! isset( $settings['link'] ) ) {
			$settings['link'] = array( 'url' => esc_url_raw( (string) $input['url'] ) );
		}
	}

	if ( 'image' === $widget_type ) {
		if ( isset( $input['image_id'] ) ) {
			$attachment_id = (int) $input['image_id'];
			$settings['image'] = array(
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ) ?: '',
			);
		} elseif ( isset( $input['image_url'] ) ) {
			$settings['image'] = array(
				'id'  => 0,
				'url' => esc_url_raw( (string) $input['image_url'] ),
			);
		}
	}

	return $settings;
}

/**
 * Normalize a spacing box array to explicit zero values.
 *
 * @param mixed $existing Existing spacing value.
 * @return array
 */
function mcp_abilities_elementor_zero_spacing_box( $existing = null ): array {
	$box = is_array( $existing ) ? $existing : array();

	$box['unit']     = isset( $box['unit'] ) && is_string( $box['unit'] ) ? $box['unit'] : 'px';
	$box['top']      = '0';
	$box['right']    = '0';
	$box['bottom']   = '0';
	$box['left']     = '0';
	$box['isLinked'] = false;

	return $box;
}

/**
 * Set negative values in an Elementor spacing box to zero.
 *
 * @param mixed $spacing Existing spacing structure.
 * @return array|null
 */
function mcp_abilities_elementor_clamp_negative_spacing_box( $spacing ): ?array {
	if ( ! is_array( $spacing ) ) {
		return null;
	}

	$updated = $spacing;
	$changed = false;

	foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
		if ( ! array_key_exists( $side, $updated ) ) {
			continue;
		}

		$value = $updated[ $side ];
		if ( is_numeric( $value ) && (float) $value < 0 ) {
			$updated[ $side ] = '0';
			$changed          = true;
		}
	}

	return $changed ? $updated : $spacing;
}

/**
 * Recursively zero container padding in an Elementor subtree.
 *
 * @param array $element Elementor element.
 * @param bool  $include_root Whether to include the root element.
 * @param int   $max_depth Maximum descendant depth, -1 for unlimited.
 * @param int   $depth Current depth.
 * @param array $changed_ids Changed element IDs.
 * @return array
 */
function mcp_abilities_elementor_zero_container_padding_subtree( array $element, bool $include_root, int $max_depth, int $depth, array &$changed_ids ): array {
	$should_touch = ( 0 === $depth && $include_root ) || ( $depth > 0 );
	$within_depth = $max_depth < 0 || $depth <= $max_depth;

	if ( $should_touch && $within_depth && 'container' === ( $element['elType'] ?? '' ) ) {
		$settings            = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$settings['padding'] = mcp_abilities_elementor_zero_spacing_box( $settings['padding'] ?? null );
		$element['settings'] = $settings;
		$changed_ids[]       = (string) ( $element['id'] ?? '' );
	}

	if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$element['elements'][ $index ] = mcp_abilities_elementor_zero_container_padding_subtree( $child, true, $max_depth, $depth + 1, $changed_ids );
		}
	}

	return $element;
}

/**
 * Recursively clamp negative widget margins in an Elementor subtree.
 *
 * @param array $element Elementor element.
 * @param bool  $include_root Whether to include the root element.
 * @param bool  $widgets_only Whether to only touch widgets.
 * @param array $changed_ids Changed element IDs.
 * @return array
 */
function mcp_abilities_elementor_reset_negative_margins_subtree( array $element, bool $include_root, bool $widgets_only, array &$changed_ids ): array {
	$should_touch = $include_root;
	$is_widget    = 'widget' === ( $element['elType'] ?? '' );
	$is_container = 'container' === ( $element['elType'] ?? '' );

	if ( $should_touch && ( $is_widget || ( ! $widgets_only && $is_container ) ) ) {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$changed  = false;

		foreach ( array( '_margin', '_margin_mobile', '_margin_tablet' ) as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			$normalized = mcp_abilities_elementor_clamp_negative_spacing_box( $settings[ $key ] );
			if ( is_array( $normalized ) && $normalized !== $settings[ $key ] ) {
				$settings[ $key ] = $normalized;
				$changed          = true;
			}
		}

		if ( $changed ) {
			$element['settings'] = $settings;
			$changed_ids[]       = (string) ( $element['id'] ?? '' );
		}
	}

	if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$element['elements'][ $index ] = mcp_abilities_elementor_reset_negative_margins_subtree( $child, true, $widgets_only, $changed_ids );
		}
	}

	return $element;
}

/**
 * Copy lane-defining settings from one Elementor element to another.
 *
 * @param array $target_element Target element.
 * @param array $source_element Source element.
 * @param array $setting_keys Keys to copy.
 * @return array
 */
function mcp_abilities_elementor_copy_lane_settings( array $target_element, array $source_element, array $setting_keys ): array {
	$target_settings = is_array( $target_element['settings'] ?? null ) ? $target_element['settings'] : array();
	$source_settings = is_array( $source_element['settings'] ?? null ) ? $source_element['settings'] : array();

	foreach ( $setting_keys as $key ) {
		if ( ! is_string( $key ) || '' === $key || ! array_key_exists( $key, $source_settings ) ) {
			continue;
		}
		$target_settings[ $key ] = $source_settings[ $key ];
	}

	$target_element['settings'] = $target_settings;

	return $target_element;
}

/**
 * Copy a set of settings keys from one Elementor element to another.
 *
 * @param array $target_element Target element.
 * @param array $source_element Source element.
 * @param array $setting_keys Keys to copy.
 * @return array
 */
function mcp_abilities_elementor_copy_element_settings_keys( array $target_element, array $source_element, array $setting_keys ): array {
	return mcp_abilities_elementor_copy_lane_settings( $target_element, $source_element, $setting_keys );
}

/**
 * Copy row-balance settings from one direct-child row to another.
 *
 * Copies the row's own spacing settings and mirrors width/flex/padding
 * settings from each direct child onto the matching child in the target row.
 *
 * @param array $target_row Target row/container.
 * @param array $source_row Source row/container.
 * @param array $row_setting_keys Keys to copy on the row itself.
 * @param array $child_setting_keys Keys to copy on each direct child.
 * @param bool  $allow_partial Whether to allow differing child counts.
 * @param array $changed_child_ids Collect target child IDs that changed.
 * @return array
 */
function mcp_abilities_elementor_copy_row_balance(
	array $target_row,
	array $source_row,
	array $row_setting_keys,
	array $child_setting_keys,
	bool $allow_partial,
	array &$changed_child_ids
): array {
	$target_row = mcp_abilities_elementor_copy_element_settings_keys( $target_row, $source_row, $row_setting_keys );

	$source_children = is_array( $source_row['elements'] ?? null ) ? array_values( $source_row['elements'] ) : array();
	$target_children = is_array( $target_row['elements'] ?? null ) ? array_values( $target_row['elements'] ) : array();

	$source_count = count( $source_children );
	$target_count = count( $target_children );

	if ( $source_count !== $target_count && ! $allow_partial ) {
		return $target_row;
	}

	$pair_count = min( $source_count, $target_count );
	for ( $index = 0; $index < $pair_count; $index++ ) {
		if ( ! is_array( $source_children[ $index ] ) || ! is_array( $target_children[ $index ] ) ) {
			continue;
		}

		$updated_child = mcp_abilities_elementor_copy_element_settings_keys(
			$target_children[ $index ],
			$source_children[ $index ],
			$child_setting_keys
		);

		if ( $updated_child !== $target_children[ $index ] && ! empty( $updated_child['id'] ) && is_string( $updated_child['id'] ) ) {
			$changed_child_ids[] = $updated_child['id'];
		}

		$target_children[ $index ] = $updated_child;
	}

	$target_row['elements'] = $target_children;

	return $target_row;
}

/**
 * Recursively find the first image widget inside an Elementor subtree.
 *
 * @param array $element Elementor element/subtree.
 * @return array|null
 */
function mcp_abilities_elementor_find_first_image_widget( array $element ): ?array {
	if ( 'widget' === ( $element['elType'] ?? '' ) && 'image' === ( $element['widgetType'] ?? '' ) ) {
		return $element;
	}

	$children = $element['elements'] ?? null;
	if ( ! is_array( $children ) ) {
		return null;
	}

	foreach ( $children as $child ) {
		if ( ! is_array( $child ) ) {
			continue;
		}

		$match = mcp_abilities_elementor_find_first_image_widget( $child );
		if ( is_array( $match ) ) {
			return $match;
		}
	}

	return null;
}

/**
 * Resolve a media payload from an Elementor image widget settings array.
 *
 * @param array $widget Elementor image widget.
 * @return array|null
 */
function mcp_abilities_elementor_extract_widget_image_media( array $widget ): ?array {
	if ( 'widget' !== ( $widget['elType'] ?? '' ) || 'image' !== ( $widget['widgetType'] ?? '' ) ) {
		return null;
	}

	$settings = is_array( $widget['settings'] ?? null ) ? $widget['settings'] : array();
	$image    = is_array( $settings['image'] ?? null ) ? $settings['image'] : array();
	$media_id = isset( $image['id'] ) ? (int) $image['id'] : 0;
	$url      = '';

	if ( ! empty( $image['url'] ) && is_string( $image['url'] ) ) {
		$url = trim( $image['url'] );
	}

	if ( '' === $url && $media_id > 0 ) {
		$attachment_url = wp_get_attachment_url( $media_id );
		if ( is_string( $attachment_url ) ) {
			$url = $attachment_url;
		}
	}

	if ( '' === $url && empty( $media_id ) ) {
		return null;
	}

	return array(
		'id'  => $media_id,
		'url' => $url,
		'alt' => is_string( $settings['image_alt'] ?? null ) ? $settings['image_alt'] : '',
	);
}

/**
 * Merge settings into a specific element inside an Elementor data tree.
 *
 * @param array  $data Elementor data tree.
 * @param string $element_id Element ID.
 * @param array  $settings Settings to deep-merge.
 * @return array
 */
function mcp_abilities_elementor_merge_settings_into_tree( array $data, string $element_id, array $settings ): array {
	$element_meta = mcp_abilities_elementor_find_element_meta( $data, $element_id );
	if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
		return $data;
	}

	$original_element           = $element_meta['element'];
	$updated_element            = $original_element;
	$existing_settings          = is_array( $original_element['settings'] ?? null ) ? $original_element['settings'] : array();
	$updated_element['settings'] = mcp_abilities_elementor_merge_settings( $existing_settings, $settings );
	$updated_element            = mcp_abilities_elementor_normalize_background_container_element( $updated_element, $original_element );

	mcp_abilities_elementor_replace_element_in_tree( $data, $element_id, $updated_element );

	return $data;
}

/**
 * Force the top value of a spacing box to zero.
 *
 * @param mixed $spacing Existing spacing structure.
 * @return array
 */
function mcp_abilities_elementor_zero_top_spacing_box( $spacing ): array {
	$box = is_array( $spacing ) ? $spacing : array();

	$box['unit']     = isset( $box['unit'] ) && is_string( $box['unit'] ) ? $box['unit'] : 'px';
	$box['top']      = '0';
	$box['isLinked'] = false;

	return $box;
}

/**
 * Force the left/right values of a spacing box to zero.
 *
 * @param mixed $spacing Existing spacing structure.
 * @return array
 */
function mcp_abilities_elementor_zero_horizontal_spacing_box( $spacing ): array {
	$box = is_array( $spacing ) ? $spacing : array();

	$box['unit']     = isset( $box['unit'] ) && is_string( $box['unit'] ) ? $box['unit'] : 'px';
	$box['right']    = '0';
	$box['left']     = '0';
	$box['isLinked'] = false;

	return $box;
}

/**
 * Determine whether a value is numeric or a numeric string.
 *
 * @param mixed $value Value to inspect.
 * @return bool
 */
function mcp_abilities_elementor_is_numeric_value( $value ): bool {
	return is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( trim( $value ) ) );
}

/**
 * Normalize a scalar to a stringified numeric value with no trailing zeros.
 *
 * @param float $value Numeric value.
 * @return string
 */
function mcp_abilities_elementor_format_numeric_value( float $value ): string {
	$rounded = round( $value, 4 );
	if ( abs( $rounded - round( $rounded ) ) < 0.0001 ) {
		return (string) (int) round( $rounded );
	}

	return rtrim( rtrim( sprintf( '%.4F', $rounded ), '0' ), '.' );
}

/**
 * Snap a numeric value to the nearest rhythm step.
 *
 * @param mixed $value Numeric value.
 * @param int   $step Step size in px.
 * @return mixed
 */
function mcp_abilities_elementor_snap_numeric_value( $value, int $step ) {
	if ( $step <= 0 || ! mcp_abilities_elementor_is_numeric_value( $value ) ) {
		return $value;
	}

	$numeric = (float) $value;
	$snapped = round( $numeric / $step ) * $step;

	return mcp_abilities_elementor_format_numeric_value( (float) $snapped );
}

/**
 * Scale a numeric value by factor.
 *
 * @param mixed $value Numeric value.
 * @param float $factor Scale factor.
 * @return mixed
 */
function mcp_abilities_elementor_scale_numeric_value( $value, float $factor ) {
	if ( ! mcp_abilities_elementor_is_numeric_value( $value ) ) {
		return $value;
	}

	$scaled = (float) $value * $factor;
	return mcp_abilities_elementor_format_numeric_value( $scaled );
}

/**
 * Detect whether a value looks like an Elementor size control.
 *
 * @param mixed $value Value to inspect.
 * @return bool
 */
function mcp_abilities_elementor_is_size_control( $value ): bool {
	return is_array( $value ) && array_key_exists( 'size', $value );
}

/**
 * Normalize a numeric size token into Elementor control format.
 *
 * @param mixed  $value Incoming value.
 * @param string $default_unit Default unit.
 * @return array
 */
function mcp_abilities_elementor_make_size_control( $value, string $default_unit = 'px' ): array {
	if ( is_array( $value ) ) {
		$control         = $value;
		$control['unit'] = isset( $control['unit'] ) && is_string( $control['unit'] ) ? $control['unit'] : $default_unit;
		if ( array_key_exists( 'size', $control ) && mcp_abilities_elementor_is_numeric_value( $control['size'] ) ) {
			$control['size'] = mcp_abilities_elementor_format_numeric_value( (float) $control['size'] );
		}
		return $control;
	}

	return array(
		'size' => mcp_abilities_elementor_is_numeric_value( $value ) ? mcp_abilities_elementor_format_numeric_value( (float) $value ) : '0',
		'unit' => $default_unit,
	);
}

/**
 * Snap an Elementor size control or numeric scalar to the nearest step.
 *
 * @param mixed $value Value to snap.
 * @param int   $step Rhythm step in px.
 * @return mixed
 */
function mcp_abilities_elementor_snap_size_value( $value, int $step ) {
	if ( mcp_abilities_elementor_is_size_control( $value ) ) {
		$unit = isset( $value['unit'] ) && is_string( $value['unit'] ) ? strtolower( $value['unit'] ) : 'px';
		if ( 'px' !== $unit || ! mcp_abilities_elementor_is_numeric_value( $value['size'] ?? null ) ) {
			return $value;
		}

		$value['size'] = mcp_abilities_elementor_snap_numeric_value( $value['size'], $step );
		return $value;
	}

	return mcp_abilities_elementor_snap_numeric_value( $value, $step );
}

/**
 * Scale an Elementor size control or numeric scalar.
 *
 * @param mixed $value Value to scale.
 * @param float $factor Scale factor.
 * @return mixed
 */
function mcp_abilities_elementor_scale_size_value( $value, float $factor ) {
	if ( mcp_abilities_elementor_is_size_control( $value ) ) {
		if ( ! mcp_abilities_elementor_is_numeric_value( $value['size'] ?? null ) ) {
			return $value;
		}

		$value['size'] = mcp_abilities_elementor_scale_numeric_value( $value['size'], $factor );
		return $value;
	}

	return mcp_abilities_elementor_scale_numeric_value( $value, $factor );
}

/**
 * Snap spacing box sides to a rhythm.
 *
 * @param mixed $spacing Existing spacing structure.
 * @param int   $step Rhythm step.
 * @param array $sides Sides to snap.
 * @return mixed
 */
function mcp_abilities_elementor_snap_spacing_box( $spacing, int $step, array $sides = array( 'top', 'right', 'bottom', 'left' ) ) {
	if ( ! is_array( $spacing ) ) {
		return $spacing;
	}

	$updated = $spacing;
	foreach ( $sides as $side ) {
		if ( ! is_string( $side ) || ! array_key_exists( $side, $updated ) ) {
			continue;
		}
		$updated[ $side ] = mcp_abilities_elementor_snap_numeric_value( $updated[ $side ], $step );
	}
	$updated['isLinked'] = false;

	return $updated;
}

/**
 * Scale spacing box sides by factor.
 *
 * @param mixed $spacing Existing spacing structure.
 * @param float $factor Scale factor.
 * @return mixed
 */
function mcp_abilities_elementor_scale_spacing_box( $spacing, float $factor ) {
	if ( ! is_array( $spacing ) ) {
		return $spacing;
	}

	$updated = $spacing;
	foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
		if ( ! array_key_exists( $side, $updated ) ) {
			continue;
		}
		$updated[ $side ] = mcp_abilities_elementor_scale_numeric_value( $updated[ $side ], $factor );
	}
	$updated['isLinked'] = false;

	return $updated;
}

/**
 * Cap horizontal spacing values in a spacing box.
 *
 * @param mixed      $spacing Existing spacing structure.
 * @param float|null $horizontal_cap Maximum absolute px value for left/right sides.
 * @return mixed
 */
function mcp_abilities_elementor_cap_spacing_box_horizontal( $spacing, ?float $horizontal_cap ) {
	if ( ! is_array( $spacing ) || null === $horizontal_cap || $horizontal_cap < 0 ) {
		return $spacing;
	}

	$updated = $spacing;
	foreach ( array( 'right', 'left' ) as $side ) {
		if ( ! array_key_exists( $side, $updated ) ) {
			continue;
		}

		$raw_value = $updated[ $side ];
		if ( is_numeric( $raw_value ) ) {
			$numeric = (float) $raw_value;
			if ( abs( $numeric ) > $horizontal_cap ) {
				$updated[ $side ] = (string) ( $numeric < 0 ? -$horizontal_cap : $horizontal_cap );
			}
			continue;
		}

		if ( ! is_string( $raw_value ) ) {
			continue;
		}

		if ( preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*(px)?\s*$/i', $raw_value, $matches ) ) {
			$numeric = (float) $matches[1];
			if ( abs( $numeric ) > $horizontal_cap ) {
				$updated[ $side ] = (string) ( $numeric < 0 ? -$horizontal_cap : $horizontal_cap );
			}
		}
	}

	$updated['isLinked'] = false;

	return $updated;
}

/**
 * Determine whether a settings key is design-relevant.
 *
 * @param string $key Setting key.
 * @return bool
 */
function mcp_abilities_elementor_is_design_setting_key( string $key ): bool {
	$direct_keys = array(
		'boxed_width',
		'content_width',
		'width',
		'width_tablet',
		'width_mobile',
		'flex_basis',
		'flex_basis_tablet',
		'flex_basis_mobile',
		'flex_gap',
		'flex_gap_tablet',
		'flex_gap_mobile',
		'padding',
		'padding_tablet',
		'padding_mobile',
		'_margin',
		'_margin_tablet',
		'_margin_mobile',
		'button_padding',
		'button_padding_tablet',
		'button_padding_mobile',
		'align',
		'align_tablet',
		'align_mobile',
		'text_align',
		'min_height',
		'min_height_tablet',
		'min_height_mobile',
	);

	if ( in_array( $key, $direct_keys, true ) ) {
		return true;
	}

	$patterns = array(
		'color',
		'typography',
		'background',
		'border',
		'box_shadow',
		'font_',
		'line_height',
		'letter_spacing',
		'text_transform',
		'text_decoration',
	);

	foreach ( $patterns as $pattern ) {
		if ( false !== strpos( $key, $pattern ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Filter an Elementor settings array down to design-relevant keys.
 *
 * @param array $settings Settings array.
 * @param array $include_keys Additional explicit keys to include.
 * @return array
 */
function mcp_abilities_elementor_filter_design_settings( array $settings, array $include_keys = array() ): array {
	$include_lookup = array();
	foreach ( $include_keys as $key ) {
		if ( is_string( $key ) && '' !== $key ) {
			$include_lookup[ $key ] = true;
		}
	}

	$filtered = array();
	foreach ( $settings as $key => $value ) {
		if ( ! is_string( $key ) ) {
			continue;
		}

		if ( isset( $include_lookup[ $key ] ) || mcp_abilities_elementor_is_design_setting_key( $key ) ) {
			$filtered[ $key ] = $value;
		}
	}

	return $filtered;
}

/**
 * Collect raw design token frequencies from a settings array.
 *
 * @param array $settings Settings array.
 * @param array $collector Collector by reference.
 * @return void
 */
function mcp_abilities_elementor_collect_tokens_from_settings( array $settings, array &$collector ): void {
	foreach ( $settings as $key => $value ) {
		if ( ! is_string( $key ) ) {
			continue;
		}

		if ( false !== strpos( $key, 'color' ) && is_string( $value ) && '' !== trim( $value ) ) {
			$token = trim( $value );
			$collector['colors'][ $token ] = ( $collector['colors'][ $token ] ?? 0 ) + 1;
		}

		if ( false !== strpos( $key, 'font_family' ) && is_string( $value ) && '' !== trim( $value ) ) {
			$token = trim( $value );
			$collector['font_families'][ $token ] = ( $collector['font_families'][ $token ] ?? 0 ) + 1;
		}

		if ( false !== strpos( $key, 'font_weight' ) && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			$token = trim( (string) $value );
			$collector['font_weights'][ $token ] = ( $collector['font_weights'][ $token ] ?? 0 ) + 1;
		}

		if ( false !== strpos( $key, 'font_size' ) ) {
			$token = mcp_abilities_elementor_tokenize_dimension_value( $value );
			if ( '' !== $token ) {
				$collector['font_sizes'][ $token ] = ( $collector['font_sizes'][ $token ] ?? 0 ) + 1;
			}
		}

		if ( false !== strpos( $key, 'line_height' ) ) {
			$token = mcp_abilities_elementor_tokenize_dimension_value( $value );
			if ( '' !== $token ) {
				$collector['line_heights'][ $token ] = ( $collector['line_heights'][ $token ] ?? 0 ) + 1;
			}
		}

		if ( false !== strpos( $key, 'gap' ) ) {
			$token = mcp_abilities_elementor_tokenize_dimension_value( $value );
			if ( '' !== $token ) {
				$collector['gaps'][ $token ] = ( $collector['gaps'][ $token ] ?? 0 ) + 1;
			}
		}

		if ( false !== strpos( $key, 'width' ) || false !== strpos( $key, 'basis' ) || false !== strpos( $key, 'height' ) ) {
			$token = mcp_abilities_elementor_tokenize_dimension_value( $value );
			if ( '' !== $token ) {
				$collector['dimensions'][ $token ] = ( $collector['dimensions'][ $token ] ?? 0 ) + 1;
			}
		}

		if ( is_array( $value ) && array_intersect( array_keys( $value ), array( 'top', 'right', 'bottom', 'left' ) ) ) {
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
				if ( ! array_key_exists( $side, $value ) ) {
					continue;
				}
				$token = mcp_abilities_elementor_tokenize_dimension_value(
					array(
						'size' => $value[ $side ],
						'unit' => is_string( $value['unit'] ?? null ) ? $value['unit'] : 'px',
					)
				);
				if ( '' !== $token ) {
					$collector['spacing'][ $token ] = ( $collector['spacing'][ $token ] ?? 0 ) + 1;
				}
			}
		}
	}
}

/**
 * Convert Elementor dimension-like values to comparable string tokens.
 *
 * @param mixed $value Value to normalize.
 * @return string
 */
function mcp_abilities_elementor_tokenize_dimension_value( $value ): string {
	if ( mcp_abilities_elementor_is_size_control( $value ) ) {
		if ( ! mcp_abilities_elementor_is_numeric_value( $value['size'] ?? null ) ) {
			return '';
		}

		$unit = isset( $value['unit'] ) && is_string( $value['unit'] ) ? $value['unit'] : 'px';
		return mcp_abilities_elementor_format_numeric_value( (float) $value['size'] ) . $unit;
	}

	if ( mcp_abilities_elementor_is_numeric_value( $value ) ) {
		return mcp_abilities_elementor_format_numeric_value( (float) $value ) . 'px';
	}

	if ( is_string( $value ) && '' !== trim( $value ) ) {
		return trim( $value );
	}

	return '';
}

/**
 * Sort token frequency maps into stable lists.
 *
 * @param array $map Frequency map.
 * @return array
 */
function mcp_abilities_elementor_sort_token_frequency_map( array $map ): array {
	arsort( $map );
	$tokens = array();
	foreach ( $map as $value => $count ) {
		$tokens[] = array(
			'value' => (string) $value,
			'count' => (int) $count,
		);
	}

	return $tokens;
}

/**
 * Collect design token frequencies from an Elementor subtree.
 *
 * @param array $element Elementor element/subtree.
 * @param array $collector Collector by reference.
 * @param int   $max_depth Maximum depth, -1 for unlimited.
 * @param int   $depth Current depth.
 * @return void
 */
function mcp_abilities_elementor_collect_design_tokens_from_subtree( array $element, array &$collector, int $max_depth = -1, int $depth = 0 ): void {
	if ( $max_depth >= 0 && $depth > $max_depth ) {
		return;
	}

	$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
	mcp_abilities_elementor_collect_tokens_from_settings( $settings, $collector );

	$children = $element['elements'] ?? null;
	if ( ! is_array( $children ) ) {
		return;
	}

	foreach ( $children as $child ) {
		if ( is_array( $child ) ) {
			mcp_abilities_elementor_collect_design_tokens_from_subtree( $child, $collector, $max_depth, $depth + 1 );
		}
	}
}

/**
 * Prepare a normalized design token payload.
 *
 * @param array $collector Raw frequency collector.
 * @return array
 */
function mcp_abilities_elementor_finalize_design_tokens( array $collector ): array {
	$keys = array(
		'colors',
		'font_families',
		'font_sizes',
		'font_weights',
		'line_heights',
		'gaps',
		'dimensions',
		'spacing',
	);

	$tokens = array();
	foreach ( $keys as $key ) {
		$tokens[ $key ] = mcp_abilities_elementor_sort_token_frequency_map( is_array( $collector[ $key ] ?? null ) ? $collector[ $key ] : array() );
	}

	return $tokens;
}

/**
 * Build a compact structural signature for an Elementor subtree.
 *
 * @param array $element Elementor element.
 * @param int   $max_depth Maximum depth to include.
 * @param int   $depth Current depth.
 * @return string
 */
function mcp_abilities_elementor_build_structure_signature( array $element, int $max_depth = 1, int $depth = 0 ): string {
	$base = (string) ( $element['elType'] ?? 'unknown' );
	if ( 'widget' === $base ) {
		$base .= ':' . (string) ( $element['widgetType'] ?? 'unknown' );
	}

	if ( $depth >= $max_depth ) {
		return $base;
	}

	$children = array();
	foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
		if ( is_array( $child ) ) {
			$children[] = mcp_abilities_elementor_build_structure_signature( $child, $max_depth, $depth + 1 );
		}
	}

	if ( empty( $children ) ) {
		return $base;
	}

	sort( $children );

	return $base . '[' . implode( '|', $children ) . ']';
}

/**
 * Detect whether a subtree contains a widget type.
 *
 * @param array  $element Elementor element.
 * @param string $widget_type Widget type.
 * @return bool
 */
function mcp_abilities_elementor_subtree_contains_widget_type( array $element, string $widget_type ): bool {
	if ( 'widget' === ( $element['elType'] ?? '' ) && $widget_type === (string) ( $element['widgetType'] ?? '' ) ) {
		return true;
	}

	foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
		if ( is_array( $child ) && mcp_abilities_elementor_subtree_contains_widget_type( $child, $widget_type ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Detect whether a subtree contains a specific heading tag.
 *
 * @param array  $element Elementor element.
 * @param string $heading_tag Heading tag such as h1.
 * @return bool
 */
function mcp_abilities_elementor_subtree_contains_heading_tag( array $element, string $heading_tag ): bool {
	if ( 'widget' === ( $element['elType'] ?? '' ) && 'heading' === (string) ( $element['widgetType'] ?? '' ) ) {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		if ( strtolower( (string) ( $settings['header_size'] ?? '' ) ) === strtolower( $heading_tag ) ) {
			return true;
		}
	}

	foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
		if ( is_array( $child ) && mcp_abilities_elementor_subtree_contains_heading_tag( $child, $heading_tag ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Detect whether a subtree contains any heading widget.
 *
 * @param array $element Elementor element.
 * @return bool
 */
function mcp_abilities_elementor_subtree_contains_heading( array $element ): bool {
	if ( 'widget' === ( $element['elType'] ?? '' ) && 'heading' === (string) ( $element['widgetType'] ?? '' ) ) {
		return true;
	}

	foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
		if ( is_array( $child ) && mcp_abilities_elementor_subtree_contains_heading( $child ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Detect whether a subtree contains text-editor content.
 *
 * @param array $element Elementor element.
 * @return bool
 */
function mcp_abilities_elementor_subtree_contains_text_editor( array $element ): bool {
	return mcp_abilities_elementor_subtree_contains_widget_type( $element, 'text-editor' );
}

/**
 * Extract a normalized row-child width token from element settings.
 *
 * @param array $settings Element settings.
 * @return string
 */
function mcp_abilities_elementor_extract_child_width_token( array $settings ): string {
	foreach ( array( 'flex_basis', 'width' ) as $key ) {
		if ( ! array_key_exists( $key, $settings ) ) {
			continue;
		}

		$token = mcp_abilities_elementor_tokenize_dimension_value( $settings[ $key ] );
		if ( '' !== $token ) {
			return $token;
		}
	}

	return '';
}

/**
 * Audit a single Elementor container for generic layout patterns.
 *
 * @param array $element Elementor element.
 * @param int   $depth Current depth.
 * @param array $stats Collector by reference.
 * @return void
 */
function mcp_abilities_elementor_collect_generic_pattern_stats( array $element, int $depth, array &$stats ): void {
	if ( 'container' !== ( $element['elType'] ?? '' ) ) {
		return;
	}

	$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
	$children = array_values(
		array_filter(
			(array) ( $element['elements'] ?? array() ),
			static function ( $child ) {
				return is_array( $child ) && isset( $child['elType'] );
			}
		)
	);

	$direction = strtolower( (string) ( $settings['flex_direction'] ?? '' ) );
	if ( 'row' !== $direction || count( $children ) < 2 ) {
		return;
	}

	$child_signatures = array();
	$width_tokens     = array();
	$container_count  = 0;
	foreach ( $children as $child ) {
		$child_signatures[] = mcp_abilities_elementor_build_structure_signature( $child, 1 );
		$child_settings     = is_array( $child['settings'] ?? null ) ? $child['settings'] : array();
		$width_token        = mcp_abilities_elementor_extract_child_width_token( $child_settings );
		if ( '' !== $width_token ) {
			$width_tokens[] = $width_token;
		}
		if ( 'container' === ( $child['elType'] ?? '' ) ) {
			++$container_count;
		}
	}

	$unique_signatures = array_values( array_unique( $child_signatures ) );
	$unique_widths     = array_values( array_unique( $width_tokens ) );
	$child_count       = count( $children );
	$element_id        = (string) ( $element['id'] ?? '' );

	if ( 2 === $child_count && count( $width_tokens ) === $child_count && 1 === count( $unique_widths ) ) {
		$stats['patterns']['symmetric_two_column'][] = $element_id;
	}

	if ( 3 === $child_count && count( $width_tokens ) === $child_count && 1 === count( $unique_widths ) ) {
		$stats['patterns']['three_up_grid'][] = $element_id;
	}

	if ( $child_count >= 4 && count( $width_tokens ) === $child_count && 1 === count( $unique_widths ) ) {
		$stats['patterns']['uniform_multi_grid'][] = $element_id;
	}

	if ( $container_count === $child_count && 1 === count( $unique_signatures ) ) {
		$stats['patterns']['repeated_component_row'][] = $element_id;
	}

	if ( $depth <= 1 && 2 === $child_count ) {
		$left_has_h1    = mcp_abilities_elementor_subtree_contains_heading_tag( $children[0], 'h1' );
		$right_has_h1   = mcp_abilities_elementor_subtree_contains_heading_tag( $children[1], 'h1' );
		$left_has_head  = mcp_abilities_elementor_subtree_contains_heading( $children[0] );
		$right_has_head = mcp_abilities_elementor_subtree_contains_heading( $children[1] );
		$left_has_text  = mcp_abilities_elementor_subtree_contains_text_editor( $children[0] );
		$right_has_text = mcp_abilities_elementor_subtree_contains_text_editor( $children[1] );
		$left_has_btn   = mcp_abilities_elementor_subtree_contains_widget_type( $children[0], 'button' );
		$right_has_btn  = mcp_abilities_elementor_subtree_contains_widget_type( $children[1], 'button' );
		$left_has_media = mcp_abilities_elementor_subtree_contains_widget_type( $children[0], 'image' );
		$right_has_media = mcp_abilities_elementor_subtree_contains_widget_type( $children[1], 'image' );

		$left_is_hero_copy  = $left_has_h1 || ( $left_has_head && $left_has_text && $left_has_btn );
		$right_is_hero_copy = $right_has_h1 || ( $right_has_head && $right_has_text && $right_has_btn );

		if ( ( $left_is_hero_copy && $right_has_media ) || ( $right_is_hero_copy && $left_has_media ) ) {
			$stats['patterns']['standard_split_hero'][] = $element_id;
		}
	}
}

/**
 * Recursively collect generic-layout statistics from an Elementor subtree.
 *
 * @param array $element Elementor element.
 * @param array $stats Collector by reference.
 * @param int   $max_depth Maximum depth, -1 for unlimited.
 * @param int   $depth Current depth.
 * @return void
 */
function mcp_abilities_elementor_collect_generic_layout_stats_from_subtree( array $element, array &$stats, int $max_depth = -1, int $depth = 0 ): void {
	if ( $max_depth >= 0 && $depth > $max_depth ) {
		return;
	}

	if ( 0 === $depth ) {
		$stats['section_signatures'][] = mcp_abilities_elementor_build_structure_signature( $element, 1 );
	}

	mcp_abilities_elementor_collect_generic_pattern_stats( $element, $depth, $stats );

	foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
		if ( is_array( $child ) ) {
			mcp_abilities_elementor_collect_generic_layout_stats_from_subtree( $child, $stats, $max_depth, $depth + 1 );
		}
	}
}

/**
 * Finalize generic layout audit data.
 *
 * @param array $stats Raw stats.
 * @return array
 */
function mcp_abilities_elementor_finalize_generic_layout_audit( array $stats ): array {
	$patterns = array();
	foreach ( (array) ( $stats['patterns'] ?? array() ) as $name => $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'strval', (array) $ids ) ) ) );
		$patterns[ $name ] = array(
			'count'       => count( $ids ),
			'element_ids' => $ids,
		);
	}

	$section_signatures = array_values( array_filter( (array) ( $stats['section_signatures'] ?? array() ) ) );
	$signature_counts   = array_count_values( $section_signatures );
	arsort( $signature_counts );
	$top_repeated = array_slice( $signature_counts, 0, 5, true );

	$recommendations = array();
	if ( ! empty( $patterns['standard_split_hero']['count'] ) ) {
		$recommendations[] = 'Consider breaking the default split-hero formula by varying media placement, information density, or section sequencing.';
	}
	if ( ! empty( $patterns['three_up_grid']['count'] ) || ! empty( $patterns['uniform_multi_grid']['count'] ) ) {
		$recommendations[] = 'Reduce repeated equal-width grids. Keep at least one major section on a different column rhythm or card count.';
	}
	if ( ! empty( $patterns['repeated_component_row']['count'] ) ) {
		$recommendations[] = 'Introduce at least one section whose child components do not all share the same internal structure.';
	}
	if ( ! empty( $patterns['symmetric_two_column']['count'] ) && $patterns['symmetric_two_column']['count'] > 1 ) {
		$recommendations[] = 'Avoid stacking multiple 50/50 rows in sequence. Vary section ratios so the page does not settle into a repetitive beat.';
	}
	if ( ! empty( $top_repeated ) && max( $top_repeated ) > 1 ) {
		$recommendations[] = 'Top-level section composition is repeating. Increase compositional contrast between adjacent sections.';
	}

	return array(
		'patterns'            => $patterns,
		'section_signatures'  => array_map(
			static function ( $signature, $count ) {
				return array(
					'signature' => (string) $signature,
					'count'     => (int) $count,
				);
			},
			array_keys( $top_repeated ),
			array_values( $top_repeated )
		),
		'recommendations'     => array_values( array_unique( $recommendations ) ),
	);
}

/**
 * Compute a neutral distinctiveness score from generic-layout audit data.
 *
 * @param array $audit Finalized audit payload.
 * @return array
 */
function mcp_abilities_elementor_score_distinctiveness_from_audit( array $audit ): array {
	$patterns = is_array( $audit['patterns'] ?? null ) ? $audit['patterns'] : array();
	$penalties = array();

	$weights = array(
		'standard_split_hero'   => 18,
		'symmetric_two_column'  => 8,
		'three_up_grid'         => 10,
		'uniform_multi_grid'    => 12,
		'repeated_component_row'=> 10,
	);

	foreach ( $weights as $pattern => $weight ) {
		$count = (int) ( $patterns[ $pattern ]['count'] ?? 0 );
		if ( $count <= 0 ) {
			continue;
		}
		$penalties[] = array(
			'pattern' => $pattern,
			'count'   => $count,
			'points'  => min( 30, $count * $weight ),
		);
	}

	$section_signature_penalty = 0;
	foreach ( (array) ( $audit['section_signatures'] ?? array() ) as $entry ) {
		$count = (int) ( $entry['count'] ?? 0 );
		if ( $count > 1 ) {
			$section_signature_penalty += min( 12, ( $count - 1 ) * 6 );
		}
	}
	if ( $section_signature_penalty > 0 ) {
		$penalties[] = array(
			'pattern' => 'top_level_repetition',
			'count'   => $section_signature_penalty / 6,
			'points'  => min( 24, $section_signature_penalty ),
		);
	}

	$total_penalty = 0;
	foreach ( $penalties as $penalty ) {
		$total_penalty += (int) $penalty['points'];
	}

	$score = max( 0, 100 - min( 90, $total_penalty ) );

	return array(
		'score'          => $score,
		'penalties'      => $penalties,
		'recommendations'=> array_values( array_unique( (array) ( $audit['recommendations'] ?? array() ) ) ),
	);
}

/**
 * Extract plain text from Elementor subtree content.
 *
 * @param array $element Elementor element.
 * @return string
 */
function mcp_abilities_elementor_extract_text_from_subtree( array $element ): string {
	$text = '';

	if ( 'widget' === ( $element['elType'] ?? '' ) ) {
		$widget_type = (string) ( $element['widgetType'] ?? '' );
		$settings    = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();

		if ( 'heading' === $widget_type && ! empty( $settings['title'] ) && is_string( $settings['title'] ) ) {
			$text .= ' ' . wp_strip_all_tags( $settings['title'] );
		}

		if ( 'text-editor' === $widget_type && ! empty( $settings['editor'] ) && is_string( $settings['editor'] ) ) {
			$text .= ' ' . wp_strip_all_tags( $settings['editor'] );
		}

		if ( 'button' === $widget_type && ! empty( $settings['text'] ) && is_string( $settings['text'] ) ) {
			$text .= ' ' . wp_strip_all_tags( $settings['text'] );
		}
	}

	foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
		if ( is_array( $child ) ) {
			$text .= ' ' . mcp_abilities_elementor_extract_text_from_subtree( $child );
		}
	}

	return trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
}

/**
 * Count words in subtree text content.
 *
 * @param array $element Elementor element.
 * @return int
 */
function mcp_abilities_elementor_count_subtree_words( array $element ): int {
	$text = mcp_abilities_elementor_extract_text_from_subtree( $element );
	if ( '' === $text ) {
		return 0;
	}

	$words = preg_split( '/\s+/', $text );
	return is_array( $words ) ? count( array_filter( $words, 'strlen' ) ) : 0;
}

/**
 * Build a column role profile for a subtree.
 *
 * @param array $element Elementor element.
 * @return array
 */
function mcp_abilities_elementor_build_column_role_profile( array $element ): array {
	$heading_count = 0;
	$text_count    = 0;
	$button_count  = 0;
	$image_count   = 0;

	$walker = static function ( array $node ) use ( &$walker, &$heading_count, &$text_count, &$button_count, &$image_count ): void {
		if ( 'widget' === ( $node['elType'] ?? '' ) ) {
			$widget_type = (string) ( $node['widgetType'] ?? '' );
			if ( 'heading' === $widget_type ) {
				++$heading_count;
			} elseif ( 'text-editor' === $widget_type ) {
				++$text_count;
			} elseif ( 'button' === $widget_type ) {
				++$button_count;
			} elseif ( 'image' === $widget_type ) {
				++$image_count;
			}
		}

		foreach ( (array) ( $node['elements'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$walker( $child );
			}
		}
	};

	$walker( $element );

	$word_count   = mcp_abilities_elementor_count_subtree_words( $element );
	$copy_score   = ( $heading_count * 3 ) + ( $text_count * 3 ) + ( $button_count * 2 ) + min( 8, (int) floor( $word_count / 20 ) );
	$media_score  = $image_count * 4;
	$total_score  = $copy_score + $media_score;
	$role         = 'light';

	if ( $copy_score >= ( $media_score + 3 ) && $copy_score >= 4 ) {
		$role = 'copy';
	} elseif ( $media_score >= ( $copy_score + 3 ) && $media_score >= 4 ) {
		$role = 'media';
	} elseif ( $total_score >= 5 ) {
		$role = 'mixed';
	}

	return array(
		'heading_count' => $heading_count,
		'text_count'    => $text_count,
		'button_count'  => $button_count,
		'image_count'   => $image_count,
		'word_count'    => $word_count,
		'copy_score'    => $copy_score,
		'media_score'   => $media_score,
		'total_score'   => $total_score,
		'role'          => $role,
	);
}

/**
 * Build a normalized row signature for a column container.
 *
 * @param array $width_tokens Width tokens.
 * @return string
 */
function mcp_abilities_elementor_build_ratio_signature( array $width_tokens ): string {
	$tokens = array_values( array_filter( array_map( 'strval', $width_tokens ) ) );
	if ( empty( $tokens ) ) {
		return 'auto';
	}

	return implode( '|', $tokens );
}

/**
 * Audit a row container for column-specific issues.
 *
 * @param array $element Elementor element.
 * @param int   $depth Current depth.
 * @param array $stats Collector by reference.
 * @return void
 */
function mcp_abilities_elementor_collect_column_audit_stats( array $element, int $depth, array &$stats ): void {
	if ( 'container' !== ( $element['elType'] ?? '' ) ) {
		return;
	}

	$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
	$children = array_values(
		array_filter(
			(array) ( $element['elements'] ?? array() ),
			static function ( $child ) {
				return is_array( $child ) && isset( $child['elType'] );
			}
		)
	);

	$direction = strtolower( (string) ( $settings['flex_direction'] ?? '' ) );
	if ( 'row' !== $direction || count( $children ) < 2 ) {
		return;
	}

	$element_id     = (string) ( $element['id'] ?? '' );
	$width_tokens   = array();
	$child_profiles = array();
	$container_count = 0;
	$image_widget_count = 0;
	foreach ( $children as $child ) {
		$child_settings  = is_array( $child['settings'] ?? null ) ? $child['settings'] : array();
		$width_tokens[]  = mcp_abilities_elementor_extract_child_width_token( $child_settings );
		$child_profiles[] = mcp_abilities_elementor_build_column_role_profile( $child );
		if ( 'container' === ( $child['elType'] ?? '' ) ) {
			++$container_count;
		}
		if ( 'widget' === ( $child['elType'] ?? '' ) && 'image' === (string) ( $child['widgetType'] ?? '' ) ) {
			++$image_widget_count;
		}
	}

	if ( 0 === $container_count && $image_widget_count < 1 ) {
		return;
	}

	$ratio_signature = mcp_abilities_elementor_build_ratio_signature( $width_tokens );
	$gap_token       = mcp_abilities_elementor_tokenize_dimension_value( $settings['flex_gap'] ?? null );
	$gap_token       = '' !== $gap_token ? $gap_token : 'gap:auto';

	$stats['rows'][] = array(
		'element_id'       => $element_id,
		'depth'            => $depth,
		'child_count'      => count( $children ),
		'ratio_signature'  => $ratio_signature,
		'gap_token'        => $gap_token,
		'child_profiles'   => $child_profiles,
	);
}

/**
 * Recursively collect column audit stats from an Elementor subtree.
 *
 * @param array $element Elementor element.
 * @param array $stats Collector by reference.
 * @param int   $max_depth Maximum depth, -1 for unlimited.
 * @param int   $depth Current depth.
 * @return void
 */
function mcp_abilities_elementor_collect_column_audit_stats_from_subtree( array $element, array &$stats, int $max_depth = -1, int $depth = 0 ): void {
	if ( $max_depth >= 0 && $depth > $max_depth ) {
		return;
	}

	mcp_abilities_elementor_collect_column_audit_stats( $element, $depth, $stats );

	foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
		if ( is_array( $child ) ) {
			mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $child, $stats, $max_depth, $depth + 1 );
		}
	}
}

/**
 * Finalize column pattern audit.
 *
 * @param array $rows Row audit entries.
 * @return array
 */
function mcp_abilities_elementor_finalize_column_patterns_audit( array $rows ): array {
	$ratio_map        = array();
	$equal_split_ids  = array();
	$equal_third_ids  = array();
	$recommendations  = array();

	foreach ( $rows as $row ) {
		$ratio_signature = (string) ( $row['ratio_signature'] ?? 'auto' );
		$child_count     = (int) ( $row['child_count'] ?? 0 );
		$element_id      = (string) ( $row['element_id'] ?? '' );

		if ( ! isset( $ratio_map[ $ratio_signature ] ) ) {
			$ratio_map[ $ratio_signature ] = array();
		}
		$ratio_map[ $ratio_signature ][] = $element_id;

		$parts = array_values( array_filter( explode( '|', $ratio_signature ), 'strlen' ) );
		if ( 2 === $child_count && 2 === count( $parts ) && 1 === count( array_unique( $parts ) ) ) {
			$equal_split_ids[] = $element_id;
		}
		if ( 3 === $child_count && 3 === count( $parts ) && 1 === count( array_unique( $parts ) ) ) {
			$equal_third_ids[] = $element_id;
		}
	}

	$repeated_ratios = array();
	foreach ( $ratio_map as $signature => $ids ) {
		$ids = array_values( array_filter( array_unique( array_map( 'strval', $ids ) ) ) );
		if ( count( $ids ) > 1 ) {
			$repeated_ratios[] = array(
				'ratio_signature' => (string) $signature,
				'count'           => count( $ids ),
				'element_ids'     => $ids,
			);
		}
	}

	usort(
		$repeated_ratios,
		static function ( array $left, array $right ): int {
			return (int) $right['count'] <=> (int) $left['count'];
		}
	);

	if ( count( $equal_split_ids ) > 1 ) {
		$recommendations[] = 'Repeated equal two-column splits can make the page settle into a predictable beat. That is only worth changing when the split no longer feels earned by the content.';
	}
	if ( count( $equal_third_ids ) > 0 ) {
		$recommendations[] = 'Equal thirds are easy to default to. Use them when comparison is the point, not just because the grid is available.';
	}

	return array(
		'row_count'        => count( $rows ),
		'equal_split_ids'  => array_values( array_unique( $equal_split_ids ) ),
		'equal_third_ids'  => array_values( array_unique( $equal_third_ids ) ),
		'repeated_ratios'  => array_slice( $repeated_ratios, 0, 8 ),
		'recommendations'  => array_values( array_unique( $recommendations ) ),
	);
}

/**
 * Finalize layout mechanism fit audit.
 *
 * Uses Elementor's own Grid-vs-Flex guidance:
 * - Grid is for equal, symmetric rows/columns.
 * - Flexbox is for user-shaped directional patterns.
 *
 * @param array $rows Row audit entries.
 * @return array
 */
function mcp_abilities_elementor_finalize_layout_mechanism_fit_audit( array $rows ): array {
	$grid_candidates  = array();
	$recommendations  = array();
	$guidance_catalog = mcp_abilities_elementor_get_official_guidance_catalog();
	$guidance_sources = array_values(
		array_filter(
			array_map(
				static function ( array $entry ): string {
					return (string) ( $entry['url'] ?? '' );
				},
				(array) ( $guidance_catalog['layout'] ?? array() )
			)
		)
	);

	foreach ( $rows as $row ) {
		$ratio_signature = (string) ( $row['ratio_signature'] ?? 'auto' );
		$parts           = array_values( array_filter( explode( '|', $ratio_signature ), 'strlen' ) );
		$child_count     = (int) ( $row['child_count'] ?? 0 );
		$element_id      = (string) ( $row['element_id'] ?? '' );
		$gap_token       = (string) ( $row['gap_token'] ?? 'gap:auto' );

		if ( $child_count < 2 || count( $parts ) !== $child_count ) {
			continue;
		}

		if ( count( array_unique( $parts ) ) !== 1 ) {
			continue;
		}

		$grid_candidates[] = array(
			'element_id'       => $element_id,
			'child_count'      => $child_count,
			'ratio_signature'  => $ratio_signature,
			'gap_token'        => $gap_token,
			'recommended_mode' => 'grid',
			'reason'           => 'Equal, symmetric columns are a better fit for Elementor Grid containers than Flexbox rows with guessed child widths.',
		);
	}

	if ( ! empty( $grid_candidates ) ) {
		$recommendations[] = 'For equal, symmetric column groups, prefer Elementor Grid containers. Elementor documents Grid for equal symmetric rows/columns and Flexbox for user-shaped directional patterns.';
	}

	return array(
		'source_policy'         => $guidance_catalog['policy'],
		'grid_candidate_count' => count( $grid_candidates ),
		'grid_candidates'      => array_values( $grid_candidates ),
		'guidance_sources'     => $guidance_sources,
		'recommendations'      => array_values( array_unique( $recommendations ) ),
	);
}

/**
 * Build subtree widget counts for native-widget opportunity audits.
 *
 * @param array $element Elementor element.
 * @return array
 */
function mcp_abilities_elementor_build_subtree_widget_stats( array $element ): array {
	$stats = array(
		'heading'      => 0,
		'text-editor'  => 0,
		'button'       => 0,
		'image'        => 0,
		'icon'         => 0,
		'icon-list'    => 0,
	);

	$walker = static function ( array $node ) use ( &$walker, &$stats ): void {
		if ( 'widget' === ( $node['elType'] ?? '' ) ) {
			$widget_type = (string) ( $node['widgetType'] ?? '' );
			if ( isset( $stats[ $widget_type ] ) ) {
				++$stats[ $widget_type ];
			}
		}

		foreach ( (array) ( $node['elements'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$walker( $child );
			}
		}
	};

	$walker( $element );

	return $stats;
}

/**
 * Finalize native Elementor widget opportunities.
 *
 * @param array $elements Root elements.
 * @return array
 */
function mcp_abilities_elementor_finalize_native_widget_opportunity_audit( array $elements ): array {
	$opportunities = array();
	$guidance_catalog = mcp_abilities_elementor_get_official_guidance_catalog();
	$sources          = array(
		'accordion'      => (string) ( $guidance_catalog['widgets']['accordion']['url'] ?? '' ),
		'tabs'           => (string) ( $guidance_catalog['widgets']['tabs']['url'] ?? '' ),
		'call_to_action' => (string) ( $guidance_catalog['widgets']['call_to_action']['url'] ?? '' ),
		'icon_list'      => (string) ( $guidance_catalog['widgets']['icon_list']['url'] ?? '' ),
	);

	$walk = static function ( array $element ) use ( &$walk, &$opportunities, $sources ): void {
		if ( 'container' === ( $element['elType'] ?? '' ) ) {
			$element_settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
			$parent_direction = strtolower( (string) ( $element_settings['flex_direction'] ?? '' ) );
			$children = array_values(
				array_filter(
					(array) ( $element['elements'] ?? array() ),
					static function ( $child ) {
						return is_array( $child ) && 'container' === ( $child['elType'] ?? '' );
					}
				)
			);

			if ( count( $children ) >= 3 ) {
				$substantive_children = array();
				$child_stats          = array();

				foreach ( $children as $child ) {
					$stats      = mcp_abilities_elementor_build_subtree_widget_stats( $child );
					$word_count = mcp_abilities_elementor_count_subtree_words( $child );
					$total_bits = array_sum( $stats ) + $word_count;
					if ( $total_bits <= 0 ) {
						continue;
					}
					$substantive_children[] = $child;
					$child_stats[]          = $stats;
				}

				if ( count( $substantive_children ) < 3 ) {
					foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
						if ( is_array( $child ) ) {
							$walk( $child );
						}
					}
					return;
				}

				$service_like = true;
				$promo_like   = true;
				$lean_list    = true;

				foreach ( $child_stats as $stats ) {
					$has_copy = ( (int) $stats['heading'] >= 1 ) && ( (int) $stats['text-editor'] >= 1 );
					if ( ! $has_copy ) {
						$service_like = false;
						$promo_like   = false;
						$lean_list    = false;
						break;
					}

					if ( (int) $stats['button'] < 1 && (int) $stats['image'] < 1 && (int) $stats['icon'] < 1 ) {
						$promo_like = false;
					}

					if ( (int) $stats['text-editor'] > 1 || (int) $stats['heading'] > 2 || (int) $stats['button'] > 0 || (int) $stats['image'] > 0 ) {
						$lean_list = false;
					}
				}

				if ( $service_like && 'column' === $parent_direction && count( $substantive_children ) >= 4 ) {
					$opportunities[] = array(
						'element_id'      => (string) ( $element['id'] ?? '' ),
						'pattern'         => 'repeated_service_items',
						'recommended_widget' => 'accordion_or_nested_tabs',
						'reason'          => 'Repeated service items with heading+copy content are often clearer as native Accordion or Nested Tabs than as hand-built container stacks.',
						'sources'         => array( $sources['accordion'], $sources['tabs'] ),
					);
				}

				if ( $promo_like && count( $substantive_children ) >= 3 ) {
					$opportunities[] = array(
						'element_id'      => (string) ( $element['id'] ?? '' ),
						'pattern'         => 'promo_modules',
						'recommended_widget' => 'call_to_action',
						'reason'          => 'Repeated promo blocks with title/copy and button or media are a better fit for Elementor Call to Action widgets than ad-hoc container compositions.',
						'sources'         => array( $sources['call_to_action'] ),
					);
				}

				if ( $lean_list && 'column' === $parent_direction && count( $substantive_children ) >= 4 ) {
					$opportunities[] = array(
						'element_id'      => (string) ( $element['id'] ?? '' ),
						'pattern'         => 'concise_capability_list',
						'recommended_widget' => 'icon_list',
						'reason'          => 'Short repeated capability items are often cleaner as a native Icon List than as repeated mini containers.',
						'sources'         => array( $sources['icon_list'] ),
					);
				}
			}
		}

		foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$walk( $child );
			}
		}
	};

	foreach ( $elements as $element ) {
		if ( is_array( $element ) ) {
			$walk( $element );
		}
	}

	$deduped = array();
	$seen    = array();
	foreach ( $opportunities as $opportunity ) {
		$key = (string) ( $opportunity['element_id'] ?? '' ) . '|' . (string) ( $opportunity['recommended_widget'] ?? '' );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$deduped[]    = $opportunity;
	}

	$recommendations = array();
	if ( ! empty( $deduped ) ) {
		$recommendations[] = 'When Elementor already has a native widget pattern for the content, prefer that over rebuilding the same idea from raw containers.';
	}

	return array(
		'source_policy'      => $guidance_catalog['policy'],
		'opportunity_count' => count( $deduped ),
		'opportunities'     => array_values( $deduped ),
		'recommendations'   => array_values( array_unique( $recommendations ) ),
	);
}

/**
 * Finalize column dominance audit.
 *
 * @param array $rows Row audit entries.
 * @return array
 */
function mcp_abilities_elementor_finalize_column_dominance_audit( array $rows ): array {
	$issues          = array();
	$recommendations = array();

	foreach ( $rows as $row ) {
		$child_profiles = is_array( $row['child_profiles'] ?? null ) ? $row['child_profiles'] : array();
		$ratio_signature = (string) ( $row['ratio_signature'] ?? 'auto' );
		$parts = array_values( array_filter( explode( '|', $ratio_signature ), 'strlen' ) );

		if ( 2 !== count( $child_profiles ) ) {
			continue;
		}

		$left  = $child_profiles[0];
		$right = $child_profiles[1];
		$delta = abs( (int) ( $left['total_score'] ?? 0 ) - (int) ( $right['total_score'] ?? 0 ) );
		$is_equal_split = 2 === count( $parts ) && 1 === count( array_unique( $parts ) );

		if ( $is_equal_split && $delta >= 4 ) {
			$issues[] = array(
				'type'          => 'equal_split_with_clear_dominant_side',
				'element_id'    => (string) ( $row['element_id'] ?? '' ),
				'ratio'         => $ratio_signature,
				'left_role'     => (string) ( $left['role'] ?? 'light' ),
				'right_role'    => (string) ( $right['role'] ?? 'light' ),
				'left_score'    => (int) ( $left['total_score'] ?? 0 ),
				'right_score'   => (int) ( $right['total_score'] ?? 0 ),
			);
		}
	}

	if ( ! empty( $issues ) ) {
		$recommendations[] = 'Some equal column splits appear to hide a clear dominant side. Consider whether those rows are understating their own hierarchy.';
	}

	return array(
		'issues'         => $issues,
		'recommendations'=> $recommendations,
	);
}

/**
 * Finalize column alignment rhythm audit.
 *
 * @param array $rows Row audit entries.
 * @return array
 */
function mcp_abilities_elementor_finalize_column_alignment_rhythm_audit( array $rows ): array {
	$gap_map          = array();
	$ratio_gap_map    = array();
	$recommendations  = array();

	foreach ( $rows as $row ) {
		$gap   = (string) ( $row['gap_token'] ?? 'gap:auto' );
		$ratio = (string) ( $row['ratio_signature'] ?? 'auto' );
		$id    = (string) ( $row['element_id'] ?? '' );

		if ( ! isset( $gap_map[ $gap ] ) ) {
			$gap_map[ $gap ] = array();
		}
		$gap_map[ $gap ][] = $id;

		if ( ! isset( $ratio_gap_map[ $ratio ] ) ) {
			$ratio_gap_map[ $ratio ] = array();
		}
		$ratio_gap_map[ $ratio ][] = $gap;
	}

	$inconsistent_rows = array();
	foreach ( $ratio_gap_map as $ratio => $gaps ) {
		$unique_gaps = array_values( array_unique( array_filter( array_map( 'strval', $gaps ) ) ) );
		if ( count( $unique_gaps ) > 1 ) {
			$inconsistent_rows[] = array(
				'ratio_signature' => (string) $ratio,
				'gap_tokens'      => $unique_gaps,
			);
		}
	}

	if ( ! empty( $inconsistent_rows ) ) {
		$recommendations[] = 'Similar column ratios are using different gutter rhythms. That can be deliberate, but it is worth checking whether the spacing differences help the message or just add noise.';
	}

	return array(
		'gap_groups'       => array_map(
			static function ( $gap, $ids ): array {
				return array(
					'gap_token'   => (string) $gap,
					'count'       => count( array_unique( $ids ) ),
					'element_ids' => array_values( array_unique( array_map( 'strval', $ids ) ) ),
				);
			},
			array_keys( $gap_map ),
			array_values( $gap_map )
		),
		'inconsistent_ratios' => $inconsistent_rows,
		'recommendations'     => $recommendations,
	);
}

/**
 * Finalize column balance audit.
 *
 * @param array $rows Row audit entries.
 * @return array
 */
function mcp_abilities_elementor_finalize_column_balance_audit( array $rows ): array {
	$issues          = array();
	$recommendations = array();

	foreach ( $rows as $row ) {
		$child_profiles = is_array( $row['child_profiles'] ?? null ) ? $row['child_profiles'] : array();
		$ratio_signature = (string) ( $row['ratio_signature'] ?? 'auto' );
		$parts = array_values( array_filter( explode( '|', $ratio_signature ), 'strlen' ) );
		if ( 2 !== count( $child_profiles ) || 2 !== count( $parts ) ) {
			continue;
		}

		$left  = $child_profiles[0];
		$right = $child_profiles[1];
		$delta = abs( (int) ( $left['total_score'] ?? 0 ) - (int) ( $right['total_score'] ?? 0 ) );
		$is_equal_split = 1 === count( array_unique( $parts ) );

		if ( ! $is_equal_split && $delta <= 1 ) {
			$issues[] = array(
				'type'         => 'asymmetry_without_clear_content_reason',
				'element_id'   => (string) ( $row['element_id'] ?? '' ),
				'ratio'        => $ratio_signature,
				'left_score'   => (int) ( $left['total_score'] ?? 0 ),
				'right_score'  => (int) ( $right['total_score'] ?? 0 ),
			);
		}
	}

	if ( ! empty( $issues ) ) {
		$recommendations[] = 'Some asymmetric rows have very similar content weight on both sides. Check whether the asymmetry is buying clarity or just visual tension.';
	}

	return array(
		'issues'         => $issues,
		'recommendations'=> $recommendations,
	);
}

/**
 * Finalize column necessity audit.
 *
 * @param array $rows Row audit entries.
 * @return array
 */
function mcp_abilities_elementor_finalize_column_necessity_audit( array $rows ): array {
	$issues          = array();
	$recommendations = array();

	foreach ( $rows as $row ) {
		$child_profiles = is_array( $row['child_profiles'] ?? null ) ? $row['child_profiles'] : array();
		if ( 2 !== count( $child_profiles ) ) {
			continue;
		}

		$left  = $child_profiles[0];
		$right = $child_profiles[1];
		$roles = array( (string) ( $left['role'] ?? 'light' ), (string) ( $right['role'] ?? 'light' ) );

		$both_light = 'light' === $roles[0] && 'light' === $roles[1];
		$both_copy  = 'copy' === $roles[0] && 'copy' === $roles[1];
		$low_words  = ( (int) ( $left['word_count'] ?? 0 ) + (int) ( $right['word_count'] ?? 0 ) ) <= 45;
		$no_media   = 0 === ( (int) ( $left['image_count'] ?? 0 ) + (int) ( $right['image_count'] ?? 0 ) );

		if ( $both_light || ( $both_copy && $low_words && $no_media ) ) {
			$issues[] = array(
				'type'          => 'split_may_not_be_earning_its_complexity',
				'element_id'    => (string) ( $row['element_id'] ?? '' ),
				'ratio'         => (string) ( $row['ratio_signature'] ?? 'auto' ),
				'left_role'     => $roles[0],
				'right_role'    => $roles[1],
				'left_words'    => (int) ( $left['word_count'] ?? 0 ),
				'right_words'   => (int) ( $right['word_count'] ?? 0 ),
			);
		}
	}

	if ( ! empty( $issues ) ) {
		$recommendations[] = 'Some splits may not be earning their complexity. Check whether those rows would read more clearly as one lane instead of two parallel columns.';
	}

	return array(
		'issues'         => $issues,
		'recommendations'=> $recommendations,
	);
}

/**
 * Parse a simple color string into RGB components when possible.
 *
 * @param string $color Color string.
 * @return array|null
 */
function mcp_abilities_elementor_parse_color_to_rgb( string $color ): ?array {
	$color = trim( strtolower( $color ) );
	if ( '' === $color || 'transparent' === $color ) {
		return null;
	}

	if ( preg_match( '/^#([0-9a-f]{3})$/', $color, $matches ) ) {
		return array(
			hexdec( str_repeat( $matches[1][0], 2 ) ),
			hexdec( str_repeat( $matches[1][1], 2 ) ),
			hexdec( str_repeat( $matches[1][2], 2 ) ),
		);
	}

	if ( preg_match( '/^#([0-9a-f]{6})$/', $color, $matches ) ) {
		return array(
			hexdec( substr( $matches[1], 0, 2 ) ),
			hexdec( substr( $matches[1], 2, 2 ) ),
			hexdec( substr( $matches[1], 4, 2 ) ),
		);
	}

	if ( preg_match( '/^rgba?\(([^)]+)\)$/', $color, $matches ) ) {
		$parts = array_map( 'trim', explode( ',', $matches[1] ) );
		if ( count( $parts ) >= 3 ) {
			return array(
				max( 0, min( 255, (int) round( (float) $parts[0] ) ) ),
				max( 0, min( 255, (int) round( (float) $parts[1] ) ) ),
				max( 0, min( 255, (int) round( (float) $parts[2] ) ) ),
			);
		}
	}

	return null;
}

/**
 * Classify a background color into a broad tone bucket.
 *
 * @param array $settings Elementor settings.
 * @return string
 */
function mcp_abilities_elementor_classify_surface_tone( array $settings ): string {
	$color = '';
	foreach ( array( 'background_color', 'background_color_b' ) as $key ) {
		if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
			$color = $settings[ $key ];
			break;
		}
	}

	if ( '' === $color ) {
		return 'none';
	}

	$rgb = mcp_abilities_elementor_parse_color_to_rgb( $color );
	if ( null === $rgb ) {
		return 'styled';
	}

	list( $red, $green, $blue ) = $rgb;
	$luma = ( 0.2126 * $red ) + ( 0.7152 * $green ) + ( 0.0722 * $blue );

	if ( $luma < 70 ) {
		return 'dark';
	}

	if ( $luma > 220 ) {
		return 'light';
	}

	if ( $red > ( $green + 20 ) && $red > ( $blue + 20 ) ) {
		return 'accent';
	}

	if ( abs( $red - $green ) < 14 && abs( $green - $blue ) < 14 ) {
		return 'muted';
	}

	return 'mid';
}

/**
 * Build a normalized surface signature for styled containers.
 *
 * @param array $element Elementor element.
 * @return string
 */
function mcp_abilities_elementor_build_surface_signature( array $element ): string {
	if ( 'container' !== ( $element['elType'] ?? '' ) ) {
		return '';
	}

	$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
	$tone     = mcp_abilities_elementor_classify_surface_tone( $settings );
	$radius   = mcp_abilities_elementor_tokenize_dimension_value( $settings['border_radius'] ?? null );
	$padding  = mcp_abilities_elementor_tokenize_dimension_value( $settings['padding'] ?? null );
	$border   = ! empty( $settings['border_border'] ) ? 'border' : 'plain';
	$shadow   = ! empty( $settings['box_shadow_box_shadow_type'] ) || ! empty( $settings['image_box_shadow_box_shadow_type'] ) ? 'shadow' : 'flat';

	if ( 'none' === $tone && '' === $radius && 'border' === $border && 'flat' === $shadow ) {
		return '';
	}

	if ( 'none' === $tone && '' === $radius && 'plain' === $border && 'flat' === $shadow ) {
		return '';
	}

	if ( 'none' === $tone && '' === $radius && '' === $padding && 'plain' === $border && 'flat' === $shadow ) {
		return '';
	}

	return implode(
		'|',
		array(
			$tone,
			'' !== $radius ? $radius : 'radius:none',
			'' !== $padding ? $padding : 'padding:none',
			$border,
			$shadow,
		)
	);
}

/**
 * Determine whether a subtree looks like a reusable card/panel pattern.
 *
 * @param array $element Elementor element.
 * @return bool
 */
function mcp_abilities_elementor_is_card_like_container( array $element ): bool {
	if ( 'container' !== ( $element['elType'] ?? '' ) ) {
		return false;
	}

	if ( empty( $element['isInner'] ) ) {
		return false;
	}

	$signature = mcp_abilities_elementor_build_surface_signature( $element );
	if ( '' === $signature ) {
		return false;
	}

	$settings      = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
	$has_treatment = ! empty( $settings['border_border'] ) || ! empty( $settings['border_radius'] ) || ! empty( $settings['background_color'] ) || ! empty( $settings['background_color_b'] ) || ! empty( $settings['box_shadow_box_shadow_type'] );
	if ( ! $has_treatment ) {
		return false;
	}

	$has_heading = mcp_abilities_elementor_subtree_contains_heading( $element );
	$has_text    = mcp_abilities_elementor_subtree_contains_text_editor( $element );
	$has_button  = mcp_abilities_elementor_subtree_contains_widget_type( $element, 'button' );

	return $has_heading && ( $has_text || $has_button );
}

/**
 * Build a compact component profile for a subtree.
 *
 * @param array $element Elementor element.
 * @return array
 */
function mcp_abilities_elementor_build_component_profile( array $element ): array {
	$widget_counts = array(
		'button'      => 0,
		'image'       => 0,
		'heading'     => 0,
		'text-editor' => 0,
		'icon'        => 0,
	);
	$card_like_ids = array();

	$walker = static function ( array $node ) use ( &$walker, &$widget_counts, &$card_like_ids ): void {
		if ( 'widget' === ( $node['elType'] ?? '' ) ) {
			$widget_type = (string) ( $node['widgetType'] ?? '' );
			if ( array_key_exists( $widget_type, $widget_counts ) ) {
				++$widget_counts[ $widget_type ];
			}
		}

		if ( mcp_abilities_elementor_is_card_like_container( $node ) ) {
			$card_like_ids[] = (string) ( $node['id'] ?? '' );
		}

		foreach ( (array) ( $node['elements'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$walker( $child );
			}
		}
	};

	$walker( $element );

	return array(
		'widget_counts' => $widget_counts,
		'card_like_ids' => array_values( array_filter( array_unique( $card_like_ids ) ) ),
	);
}

/**
 * Finalize component overuse audit.
 *
 * @param array $profile Component profile.
 * @return array
 */
function mcp_abilities_elementor_finalize_component_overuse_audit( array $profile ): array {
	$widget_counts = is_array( $profile['widget_counts'] ?? null ) ? $profile['widget_counts'] : array();
	$card_like_ids = array_values( array_filter( array_map( 'strval', (array) ( $profile['card_like_ids'] ?? array() ) ) ) );
	$issues        = array();
	$recommendations = array();

	$button_count = (int) ( $widget_counts['button'] ?? 0 );
	if ( $button_count >= 4 ) {
		$issues[] = array(
			'type'  => 'button_overuse',
			'count' => $button_count,
		);
		$recommendations[] = 'Too many buttons can make a page feel like stock landing-page furniture. Keep calls to action more selective.';
	}

	if ( count( $card_like_ids ) >= 3 ) {
		$issues[] = array(
			'type'        => 'card_like_surface_overuse',
			'count'       => count( $card_like_ids ),
			'element_ids' => $card_like_ids,
		);
		$recommendations[] = 'Repeated panel/card treatment is starting to dominate the page. Use that surface language more selectively.';
	}

	return array(
		'widget_counts'   => $widget_counts,
		'card_like_count' => count( $card_like_ids ),
		'card_like_ids'   => $card_like_ids,
		'issues'          => $issues,
		'recommendations' => array_values( array_unique( $recommendations ) ),
	);
}

/**
 * Collect repeated surface signatures from a subtree.
 *
 * @param array $element Elementor element.
 * @param array $collector Collector by reference.
 * @return void
 */
function mcp_abilities_elementor_collect_surface_signatures_from_subtree( array $element, array &$collector ): void {
	$signature = mcp_abilities_elementor_build_surface_signature( $element );
	if ( '' !== $signature && ! empty( $element['isInner'] ) ) {
		if ( ! isset( $collector[ $signature ] ) || ! is_array( $collector[ $signature ] ) ) {
			$collector[ $signature ] = array();
		}
		$collector[ $signature ][] = (string) ( $element['id'] ?? '' );
	}

	foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
		if ( is_array( $child ) ) {
			mcp_abilities_elementor_collect_surface_signatures_from_subtree( $child, $collector );
		}
	}
}

/**
 * Finalize repeated surface audit.
 *
 * @param array $collector Surface collector.
 * @return array
 */
function mcp_abilities_elementor_finalize_surface_overuse_audit( array $collector ): array {
	$repeated = array();
	$recommendations = array();

	foreach ( $collector as $signature => $ids ) {
		$ids = array_values( array_filter( array_unique( array_map( 'strval', (array) $ids ) ) ) );
		if ( count( $ids ) < 3 ) {
			continue;
		}
		$repeated[] = array(
			'signature'  => (string) $signature,
			'count'      => count( $ids ),
			'element_ids'=> $ids,
		);
	}

	usort(
		$repeated,
		static function ( array $left, array $right ): int {
			return (int) $right['count'] <=> (int) $left['count'];
		}
	);

	if ( ! empty( $repeated ) ) {
		$recommendations[] = 'A single surface treatment is repeating often. That is only worth changing if it starts to feel formulaic rather than intentionally restrained.';
	}

	return array(
		'repeated_surfaces' => array_slice( $repeated, 0, 8 ),
		'recommendations'   => $recommendations,
	);
}

/**
 * Compute a broad emphasis score for a section subtree.
 *
 * @param array $element Elementor element.
 * @return array
 */
function mcp_abilities_elementor_compute_section_emphasis_profile( array $element ): array {
	$settings         = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
	$tone             = mcp_abilities_elementor_classify_surface_tone( $settings );
	$has_h1           = mcp_abilities_elementor_subtree_contains_heading_tag( $element, 'h1' );
	$has_h2           = mcp_abilities_elementor_subtree_contains_heading_tag( $element, 'h2' );
	$has_media        = mcp_abilities_elementor_subtree_contains_widget_type( $element, 'image' );
	$has_btn          = mcp_abilities_elementor_subtree_contains_widget_type( $element, 'button' );
	$has_text         = mcp_abilities_elementor_subtree_contains_text_editor( $element );
	$component        = mcp_abilities_elementor_build_component_profile( $element );
	$widget_counts    = is_array( $component['widget_counts'] ?? null ) ? $component['widget_counts'] : array();
	$card_like_ids    = array_values( array_filter( array_map( 'strval', (array) ( $component['card_like_ids'] ?? array() ) ) ) );
	$button_count     = (int) ( $widget_counts['button'] ?? 0 );
	$card_like_count  = count( $card_like_ids );

	$score = 0;
	$score += $has_h1 ? 4 : 0;
	$score += $has_h2 ? 3 : 0;
	$score += $has_media ? 2 : 0;
	$score += $has_btn ? 2 : 0;
	$score += $has_text ? 1 : 0;
	$score += in_array( $tone, array( 'dark', 'accent' ), true ) ? 1 : 0;

	$spotlight_score = 0;
	$spotlight_score += $score >= 8 ? 2 : ( $score >= 6 ? 1 : 0 );
	$spotlight_score += in_array( $tone, array( 'dark', 'accent' ), true ) ? 1 : 0;
	$spotlight_score += $button_count >= 2 ? 1 : 0;
	$spotlight_score += $card_like_count >= 1 ? 1 : 0;
	$spotlight_score += ( $has_media && ( $has_h1 || $has_h2 ) ) ? 1 : 0;

	return array(
		'element_id'       => (string) ( $element['id'] ?? '' ),
		'score'            => $score,
		'tone'             => $tone,
		'has_h1'           => $has_h1,
		'has_h2'           => $has_h2,
		'has_media'        => $has_media,
		'has_button'       => $has_btn,
		'has_text'         => $has_text,
		'button_count'     => $button_count,
		'card_like_count'  => $card_like_count,
		'card_like_ids'    => $card_like_ids,
		'spotlight_score'  => $spotlight_score,
	);
}

/**
 * Finalize emphasis drift audit across top-level sections.
 *
 * @param array $profiles Section emphasis profiles.
 * @return array
 */
function mcp_abilities_elementor_finalize_emphasis_drift_audit( array $profiles ): array {
	$scores = array_values(
		array_map(
			static function ( array $profile ): int {
				return (int) ( $profile['score'] ?? 0 );
			},
			$profiles
		)
	);

	$recommendations = array();
	$range           = empty( $scores ) ? 0 : ( max( $scores ) - min( $scores ) );
	$cta_sections    = 0;
	foreach ( $profiles as $profile ) {
		if ( ! empty( $profile['has_button'] ) ) {
			++$cta_sections;
		}
	}

	$flat_sections = array();
	if ( count( $profiles ) >= 4 && $range <= 2 && $cta_sections >= 3 ) {
		foreach ( $profiles as $profile ) {
			$flat_sections[] = (string) ( $profile['element_id'] ?? '' );
		}
		$recommendations[] = 'Section emphasis is quite flat across the page. That can be fine for restraint, but it risks making key moments land with the same force as supporting sections.';
	}

	return array(
		'section_profiles' => $profiles,
		'score_range'      => $range,
		'cta_section_count'=> $cta_sections,
		'flat_section_ids' => array_values( array_filter( $flat_sections ) ),
		'recommendations'  => $recommendations,
	);
}

/**
 * Finalize composition rhythm audit across top-level sections.
 *
 * @param array $profiles Section emphasis profiles.
 * @return array
 */
function mcp_abilities_elementor_finalize_composition_rhythm_audit( array $profiles ): array {
	$tone_runs       = array();
	$current_tone    = '';
	$current_ids     = array();
	$recommendations = array();

	foreach ( $profiles as $profile ) {
		$tone = (string) ( $profile['tone'] ?? 'none' );
		$id   = (string) ( $profile['element_id'] ?? '' );

		if ( '' === $current_tone ) {
			$current_tone = $tone;
			$current_ids  = array( $id );
			continue;
		}

		if ( $tone === $current_tone ) {
			$current_ids[] = $id;
			continue;
		}

		if ( count( $current_ids ) >= 3 && 'none' !== $current_tone ) {
			$tone_runs[] = array(
				'tone'       => $current_tone,
				'count'      => count( $current_ids ),
				'element_ids'=> $current_ids,
			);
		}

		$current_tone = $tone;
		$current_ids  = array( $id );
	}

	if ( count( $current_ids ) >= 3 && 'none' !== $current_tone ) {
		$tone_runs[] = array(
			'tone'       => $current_tone,
			'count'      => count( $current_ids ),
			'element_ids'=> $current_ids,
		);
	}

	if ( ! empty( $tone_runs ) ) {
		$recommendations[] = 'Several adjacent sections share the same tonal weight. That is not automatically wrong, but it can reduce pacing if no later section breaks the run.';
	}

	return array(
		'section_profiles' => $profiles,
		'tone_runs'        => $tone_runs,
		'recommendations'  => $recommendations,
	);
}

/**
 * Determine whether an element settings array carries a visible top border.
 *
 * @param array $settings Elementor settings.
 * @return bool
 */
function mcp_abilities_elementor_has_visible_top_border( array $settings ): bool {
	$border_style = isset( $settings['border_border'] ) ? (string) $settings['border_border'] : '';
	if ( '' === $border_style || 'none' === $border_style ) {
		return false;
	}

	$top_width = 0.0;
	if ( isset( $settings['border_width'] ) && is_array( $settings['border_width'] ) ) {
		$top_width = isset( $settings['border_width']['top'] ) ? (float) $settings['border_width']['top'] : 0.0;
	} elseif ( isset( $settings['border_top_width'] ) ) {
		$top_width = (float) $settings['border_top_width'];
	}

	if ( $top_width <= 0 ) {
		return false;
	}

	$border_color = isset( $settings['border_color'] ) ? strtolower( trim( (string) $settings['border_color'] ) ) : '';
	if ( '' === $border_color ) {
		return true;
	}

	if ( 'transparent' === $border_color || 'rgba(0,0,0,0)' === $border_color || 'rgba(0, 0, 0, 0)' === $border_color ) {
		return false;
	}

	return true;
}

/**
 * Compute a top-level section separator profile.
 *
 * @param array $element Elementor element.
 * @return array
 */
function mcp_abilities_elementor_compute_section_separator_profile( array $element ): array {
	$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();

	return array(
		'element_id'          => (string) ( $element['id'] ?? '' ),
		'has_section_top_border' => mcp_abilities_elementor_has_visible_top_border( $settings ),
		'tone'                => mcp_abilities_elementor_classify_surface_tone( $settings ),
	);
}

/**
 * Finalize separator discipline audit across top-level sections.
 *
 * This is intentionally soft. It should only speak up when separators start
 * flattening the page into repeated major-block boundaries.
 *
 * @param array $profiles Separator profiles.
 * @return array
 */
function mcp_abilities_elementor_finalize_separator_discipline_audit( array $profiles ): array {
	$top_border_ids    = array();
	$top_border_runs   = array();
	$current_run       = array();
	$recommendations   = array();
	$total_sections    = count( $profiles );

	foreach ( $profiles as $profile ) {
		$id             = (string) ( $profile['element_id'] ?? '' );
		$has_top_border = ! empty( $profile['has_section_top_border'] );

		if ( $has_top_border ) {
			$top_border_ids[] = $id;
			$current_run[]    = $id;
			continue;
		}

		if ( count( $current_run ) >= 3 ) {
			$top_border_runs[] = array(
				'count'       => count( $current_run ),
				'element_ids' => array_values( array_filter( $current_run ) ),
			);
		}
		$current_run = array();
	}

	if ( count( $current_run ) >= 3 ) {
		$top_border_runs[] = array(
			'count'       => count( $current_run ),
			'element_ids' => array_values( array_filter( $current_run ) ),
		);
	}

	$top_border_ids = array_values( array_filter( array_unique( $top_border_ids ) ) );
	$top_border_count = count( $top_border_ids );

	if ( $total_sections >= 5 && $top_border_count >= 4 ) {
		$recommendations[] = 'Separator treatment is appearing on too many major section boundaries. That can make the page feel mechanically divided instead of intentionally paced.';
	}

	if ( ! empty( $top_border_runs ) ) {
		$recommendations[] = 'Consecutive top-level sections are all using explicit separator boundaries. Keep separators inside section families more than between every major block.';
	}

	return array(
		'section_profiles'        => $profiles,
		'section_top_border_ids'  => $top_border_ids,
		'section_top_border_count'=> $top_border_count,
		'section_top_border_runs' => $top_border_runs,
		'recommendations'         => array_values( array_unique( $recommendations ) ),
	);
}

/**
 * Finalize section rivalry audit across top-level sections.
 *
 * This tries to catch pages where several sections are all acting like local climaxes.
 * It is intentionally cautious: restrained/simple pages should not be penalized.
 *
 * @param array $profiles Section emphasis profiles.
 * @return array
 */
function mcp_abilities_elementor_finalize_section_rivalry_audit( array $profiles ): array {
	$peak_sections      = array();
	$peak_ids           = array();
	$high_emphasis_ids  = array();
	$adjacent_peak_runs = array();
	$recommendations    = array();
	$current_run        = array();

	foreach ( $profiles as $profile ) {
		$score           = (int) ( $profile['score'] ?? 0 );
		$spotlight_score = (int) ( $profile['spotlight_score'] ?? 0 );
		$tone            = (string) ( $profile['tone'] ?? 'none' );
		$button_count    = (int) ( $profile['button_count'] ?? 0 );
		$card_like_count = (int) ( $profile['card_like_count'] ?? 0 );

		$is_peak = $spotlight_score >= 4
			|| ( $spotlight_score >= 3 && $score >= 7 && ( in_array( $tone, array( 'dark', 'accent' ), true ) || $card_like_count >= 1 ) );

		if ( $score >= 7 ) {
			$high_emphasis_ids[] = (string) ( $profile['element_id'] ?? '' );
		}

		if ( $is_peak ) {
			$peak_sections[] = array(
				'element_id'      => (string) ( $profile['element_id'] ?? '' ),
				'score'           => $score,
				'spotlight_score' => $spotlight_score,
				'tone'            => $tone,
				'button_count'    => $button_count,
				'card_like_count' => $card_like_count,
			);
			$peak_ids[] = (string) ( $profile['element_id'] ?? '' );
			$current_run[] = (string) ( $profile['element_id'] ?? '' );
			continue;
		}

		if ( count( $current_run ) >= 2 ) {
			$adjacent_peak_runs[] = array(
				'count'       => count( $current_run ),
				'element_ids' => array_values( array_filter( $current_run ) ),
			);
		}
		$current_run = array();
	}

	if ( count( $current_run ) >= 2 ) {
		$adjacent_peak_runs[] = array(
			'count'       => count( $current_run ),
			'element_ids' => array_values( array_filter( $current_run ) ),
		);
	}

	$total_sections       = count( $profiles );
	$peak_count           = count( $peak_sections );
	$has_adjacent_rivalry = ! empty( $adjacent_peak_runs );
	$peak_ratio           = $total_sections > 0 ? ( $peak_count / $total_sections ) : 0;

	if ( $total_sections >= 4 && $peak_count >= 3 && ( $has_adjacent_rivalry || $peak_ratio >= 0.5 ) ) {
		$recommendations[] = 'Several sections are carrying peak-emphasis signals at once. That can make the page feel like each block is trying to be the main event rather than letting one section lead.';
	}

	if ( count( $adjacent_peak_runs ) >= 2 ) {
		$recommendations[] = 'Strong sections are clustering together in multiple places. Consider calming one of the neighboring sections so the page has a clearer hierarchy of loud versus supporting moments.';
	}

	return array(
		'section_profiles'     => $profiles,
		'peak_section_count'   => $peak_count,
		'peak_section_ids'     => array_values( array_filter( array_unique( $peak_ids ) ) ),
		'peak_sections'        => $peak_sections,
		'high_emphasis_ids'    => array_values( array_filter( array_unique( $high_emphasis_ids ) ) ),
		'adjacent_peak_runs'   => $adjacent_peak_runs,
		'recommendations'      => array_values( array_unique( $recommendations ) ),
	);
}

/**
 * Get the active Elementor kit ID.
 *
 * @return int
 */
function mcp_abilities_elementor_get_active_kit_id(): int {
	return (int) get_option( 'elementor_active_kit', 0 );
}

/**
 * Get the active Elementor kit settings.
 *
 * @return array
 */
function mcp_abilities_elementor_get_active_kit_settings(): array {
	$kit_id = mcp_abilities_elementor_get_active_kit_id();
	if ( $kit_id <= 0 ) {
		return array();
	}

	$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
	return is_array( $settings ) ? $settings : array();
}

/**
 * Normalize a CSS hex color for exact kit-token matching.
 *
 * @param mixed $value Color value.
 * @return string
 */
function mcp_abilities_elementor_normalize_hex_color( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$value = strtolower( trim( $value ) );
	if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $matches ) ) {
		$chars = str_split( $matches[1] );
		return '#' . $chars[0] . $chars[0] . $chars[1] . $chars[1] . $chars[2] . $chars[2];
	}

	if ( preg_match( '/^#([0-9a-f]{6})$/', $value ) ) {
		return $value;
	}

	return '';
}

/**
 * Build an exact color-to-global-reference map from the active Elementor kit.
 *
 * @return array
 */
function mcp_abilities_elementor_get_global_color_reference_map(): array {
	$settings = mcp_abilities_elementor_get_active_kit_settings();
	$groups   = array( 'system_colors', 'custom_colors' );
	$map      = array();

	foreach ( $groups as $group ) {
		$tokens = isset( $settings[ $group ] ) && is_array( $settings[ $group ] ) ? $settings[ $group ] : array();
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			$id    = isset( $token['_id'] ) && is_string( $token['_id'] ) ? trim( $token['_id'] ) : '';
			$color = mcp_abilities_elementor_normalize_hex_color( $token['color'] ?? '' );
			if ( '' === $id || '' === $color ) {
				continue;
			}

			$map[ $color ] = 'globals/colors?id=' . $id;
		}
	}

	return $map;
}

/**
 * Determine whether a setting key is a local color control.
 *
 * @param string $key Setting key.
 * @return bool
 */
function mcp_abilities_elementor_is_local_color_setting_key( string $key ): bool {
	if ( '__globals__' === $key || '' === $key ) {
		return false;
	}

	if ( preg_match( '/_background$/', $key ) ) {
		return false;
	}

	return 'color' === $key || false !== strpos( $key, '_color' ) || false !== strpos( $key, 'color_' );
}

/**
 * Determine whether a color-looking key is an Elementor mode selector rather
 * than a concrete color value.
 *
 * Elementor's Social Icons widget uses `icon_color` to choose between
 * "official" and "custom" color modes. The actual color values live in
 * `icon_primary_color` and `icon_secondary_color`, which remain subject to the
 * global token policy.
 *
 * @param string $key Setting key.
 * @param array  $element Elementor element.
 * @return bool
 */
function mcp_abilities_elementor_is_color_mode_selector_setting_key( string $key, array $element ): bool {
	$widget_type = isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ? $element['widgetType'] : '';
	if ( 'social-icons' !== $widget_type ) {
		return false;
	}

	return in_array( $key, array( 'icon_color', 'item_icon_color' ), true );
}

/**
 * Determine whether a setting key is a local typography control.
 *
 * @param string $key Setting key.
 * @return bool
 */
function mcp_abilities_elementor_is_local_typography_setting_key( string $key ): bool {
	if ( '__globals__' === $key || '' === $key ) {
		return false;
	}

	$fragments = array(
		'typography',
		'font_family',
		'font_size',
		'font_weight',
		'font_style',
		'line_height',
		'letter_spacing',
		'word_spacing',
		'text_transform',
		'text_decoration',
	);

	foreach ( $fragments as $fragment ) {
		if ( $key === $fragment || false !== strpos( $key, $fragment ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Add a bounded global-style policy violation.
 *
 * @param array  $violations Violation list.
 * @param array  $element Elementor element.
 * @param string $path Data path.
 * @param string $setting Setting key.
 * @param string $type Violation type.
 * @param mixed  $value Setting value.
 * @param string $message Human-readable message.
 * @return void
 */
function mcp_abilities_elementor_add_global_style_violation( array &$violations, array $element, string $path, string $setting, string $type, $value, string $message ): void {
	if ( count( $violations ) >= 25 ) {
		return;
	}

	$display_value = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
	$violations[] = array(
		'element_id'  => isset( $element['id'] ) && is_string( $element['id'] ) ? $element['id'] : '',
		'el_type'     => isset( $element['elType'] ) && is_string( $element['elType'] ) ? $element['elType'] : '',
		'widget_type' => isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ? $element['widgetType'] : '',
		'path'        => $path,
		'setting'     => $setting,
		'type'        => $type,
		'value'       => $display_value,
		'message'     => $message,
	);
}

/**
 * Enforce global Elementor style policy on a single element.
 *
 * @param array  $element Elementor element.
 * @param array  $color_map Exact color-to-global-reference map.
 * @param array  $violations Violation list.
 * @param string $path Data path.
 * @return array
 */
function mcp_abilities_elementor_enforce_global_style_policy_on_element( array $element, array $color_map, array &$violations, string $path ): array {
	$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
	$globals  = isset( $settings['__globals__'] ) && is_array( $settings['__globals__'] ) ? $settings['__globals__'] : array();

	foreach ( $settings as $key => $value ) {
		if ( '__globals__' === $key || ! is_string( $key ) ) {
			continue;
		}

		if ( is_string( $value ) && preg_match( '/\sstyle\s*=/i', $value ) ) {
			mcp_abilities_elementor_add_global_style_violation(
				$violations,
				$element,
				$path . '.settings.' . $key,
				$key,
				'inline_style',
				$value,
				'Inline style attributes are not allowed in Elementor write abilities; use widget settings backed by global kit values.'
			);
			continue;
		}

		if ( mcp_abilities_elementor_is_local_typography_setting_key( $key ) ) {
			$global_ref = isset( $globals[ $key ] ) && is_string( $globals[ $key ] ) ? $globals[ $key ] : '';
			if ( 0 === strpos( $global_ref, 'globals/typography?id=' ) ) {
				continue;
			}

			mcp_abilities_elementor_add_global_style_violation(
				$violations,
				$element,
				$path . '.settings.' . $key,
				$key,
				'local_typography',
				$value,
				'Local typography settings are not allowed; update the Elementor Kit typography or reference a global typography token instead.'
			);
			continue;
		}

		if ( mcp_abilities_elementor_is_color_mode_selector_setting_key( $key, $element ) ) {
			continue;
		}

		if ( mcp_abilities_elementor_is_local_color_setting_key( $key ) && is_string( $value ) && '' !== trim( $value ) ) {
			$global_ref = isset( $globals[ $key ] ) && is_string( $globals[ $key ] ) ? $globals[ $key ] : '';
			if ( 0 === strpos( $global_ref, 'globals/colors?id=' ) ) {
				continue;
			}

			$normalized_color = mcp_abilities_elementor_normalize_hex_color( $value );
			if ( '' !== $normalized_color && isset( $color_map[ $normalized_color ] ) ) {
				$globals[ $key ] = $color_map[ $normalized_color ];
				continue;
			}

			mcp_abilities_elementor_add_global_style_violation(
				$violations,
				$element,
				$path . '.settings.' . $key,
				$key,
				'local_color',
				$value,
				'Local colors are not allowed; use an Elementor Kit global color token or update the kit first.'
			);
		}
	}

	if ( ! empty( $globals ) ) {
		$settings['__globals__'] = $globals;
		$element['settings']    = $settings;
	}

	if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( is_array( $child ) ) {
				$element['elements'][ $index ] = mcp_abilities_elementor_enforce_global_style_policy_on_element(
					$child,
					$color_map,
					$violations,
					$path . '.elements[' . $index . ']'
				);
			}
		}
	}

	return $element;
}

/**
 * Enforce the default Elementor write policy: global colors/typography only.
 *
 * @param array $data Elementor document data.
 * @return array
 */
function mcp_abilities_elementor_enforce_global_style_policy( array $data ): array {
	$color_map  = mcp_abilities_elementor_get_global_color_reference_map();
	$violations = array();

	foreach ( $data as $index => $element ) {
		if ( is_array( $element ) ) {
			$data[ $index ] = mcp_abilities_elementor_enforce_global_style_policy_on_element(
				$element,
				$color_map,
				$violations,
				'data[' . $index . ']'
			);
		}
	}

	return array(
		'success'      => empty( $violations ),
		'data'         => $data,
		'violations'   => $violations,
		'normalized'   => array(
			'color_reference_count' => count( $color_map ),
		),
	);
}

/**
 * Get the numeric size from an Elementor slider-like setting.
 *
 * @param mixed $value Elementor setting value.
 * @return float|null
 */
function mcp_abilities_elementor_slider_size_value( $value ): ?float {
	if ( is_array( $value ) && array_key_exists( 'size', $value ) ) {
		$value = $value['size'];
	}

	if ( '' === $value || null === $value ) {
		return null;
	}

	return is_numeric( $value ) ? (float) $value : null;
}

/**
 * Add a bounded Elementor write guard issue.
 *
 * @param array  $issues Issue list.
 * @param array  $element Elementor element.
 * @param string $path Data path.
 * @param string $setting Setting key.
 * @param string $type Issue type.
 * @param string $severity Issue severity.
 * @param string $message Human-readable message.
 * @return void
 */
function mcp_abilities_elementor_add_write_guard_issue( array &$issues, array $element, string $path, string $setting, string $type, string $severity, string $message ): void {
	if ( count( $issues ) >= 25 ) {
		return;
	}

	$issues[] = array(
		'element_id'  => isset( $element['id'] ) && is_string( $element['id'] ) ? $element['id'] : '',
		'el_type'     => isset( $element['elType'] ) && is_string( $element['elType'] ) ? $element['elType'] : '',
		'widget_type' => isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ? $element['widgetType'] : '',
		'path'        => $path,
		'setting'     => $setting,
		'type'        => $type,
		'severity'    => $severity,
		'message'     => $message,
	);
}

/**
 * Audit one Elementor element for write guard issues that Elementor would render differently per device.
 *
 * @param array  $element Elementor element.
 * @param array  $issues Issue list.
 * @param string $path Data path.
 * @return void
 */
function mcp_abilities_elementor_collect_write_guard_issues_on_element( array $element, array &$issues, string $path ): void {
	$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

	foreach ( $settings as $key => $value ) {
		if ( ! is_string( $key ) || '__globals__' === $key ) {
			continue;
		}

		if ( is_string( $value ) && false !== stripos( $value, 'calc(' ) && 'custom_css' !== $key ) {
			mcp_abilities_elementor_add_write_guard_issue(
				$issues,
				$element,
				$path . '.settings.' . $key,
				$key,
				'calculated_field_value',
				'error',
				'Elementor control fields must store concrete values, not CSS calc() expressions. Move the intent to native Elementor controls or generated CSS owned by Elementor.'
			);
		}
	}

	if ( 'posts' === ( $element['widgetType'] ?? '' ) ) {
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^(.*)_item_ratio$/', $key, $matches ) ) {
				continue;
			}

			$base_size = mcp_abilities_elementor_slider_size_value( $value );
			if ( null === $base_size ) {
				continue;
			}

			$tablet_key  = $key . '_tablet';
			$mobile_key  = $key . '_mobile';
			$tablet_size = mcp_abilities_elementor_slider_size_value( $settings[ $tablet_key ] ?? null );
			$mobile_size = mcp_abilities_elementor_slider_size_value( $settings[ $mobile_key ] ?? null );

			if ( null === $mobile_size ) {
				mcp_abilities_elementor_add_write_guard_issue(
					$issues,
					$element,
					$path . '.settings.' . $mobile_key,
					$mobile_key,
					'missing_responsive_posts_image_ratio',
					'error',
					'Posts widget image ratio is a responsive Elementor slider. Setting `' . $key . '` does not set mobile; Elementor Pro defaults mobile image ratio to 0.5. Set `' . $mobile_key . '` explicitly, for example {unit:"px",size:' . rtrim( rtrim( (string) $base_size, '0' ), '.' ) . ',sizes:[]}.'
				);
			}

			if ( null === $tablet_size ) {
				mcp_abilities_elementor_add_write_guard_issue(
					$issues,
					$element,
					$path . '.settings.' . $tablet_key,
					$tablet_key,
					'missing_responsive_posts_image_ratio',
					'warning',
					'Posts widget image ratio is responsive. Set `' . $tablet_key . '` explicitly when the desktop ratio is intentional across breakpoints.'
				);
			}
		}
	}

	if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( is_array( $child ) ) {
				mcp_abilities_elementor_collect_write_guard_issues_on_element( $child, $issues, $path . '.elements[' . $index . ']' );
			}
		}
	}
}

/**
 * Audit Elementor data for write guard issues that should be caught before saving.
 *
 * @param array $data Elementor document data.
 * @return array
 */
function mcp_abilities_elementor_audit_write_guard( array $data ): array {
	$issues = array();

	foreach ( $data as $index => $element ) {
		if ( is_array( $element ) ) {
			mcp_abilities_elementor_collect_write_guard_issues_on_element( $element, $issues, 'data[' . $index . ']' );
		}
	}

	$errors = array_values(
		array_filter(
			$issues,
			static fn( array $issue ): bool => 'error' === ( $issue['severity'] ?? '' )
		)
	);

	return array(
		'success'       => empty( $errors ),
		'error_count'   => count( $errors ),
		'warning_count' => count( $issues ) - count( $errors ),
		'issues'        => array_values( $issues ),
	);
}

/**
 * Build a standard failed response for Elementor write guard issues.
 *
 * @param array $guard_result Guard result.
 * @return array
 */
function mcp_abilities_elementor_write_guard_error_response( array $guard_result ): array {
	return array(
		'success'               => false,
		'message'               => 'Elementor write guard blocked the update. Fix the reported Elementor data issue(s) before saving.',
		'elementor_write_guard' => $guard_result,
	);
}

/**
 * Build a standard failed response for global style policy violations.
 *
 * @param array $policy_result Policy result.
 * @return array
 */
function mcp_abilities_elementor_global_style_policy_error_response( array $policy_result ): array {
	return array(
		'success'    => false,
		'message'    => 'Global Elementor style policy rejected local style settings. Use Elementor Kit global colors/typography and remove inline style attributes before writing.',
		'violations' => $policy_result['violations'] ?? array(),
	);
}

/**
 * Allow a narrow legacy document update only when every remaining style
 * violation already existed at the same element/setting and its style value
 * is unchanged. Removing legacy violations is allowed; introducing or changing
 * one is not.
 *
 * @param array $before_policy Policy result for the stored document.
 * @param array $after_policy Policy result for the proposed document.
 * @return bool
 */
function mcp_abilities_elementor_legacy_style_violations_preserved( array $before_policy, array $after_policy ): bool {
	$before = array();
	foreach ( (array) ( $before_policy['violations'] ?? array() ) as $violation ) {
		if ( ! is_array( $violation ) ) {
			continue;
		}
		$key = implode( '|', array( (string) ( $violation['element_id'] ?? '' ), (string) ( $violation['setting'] ?? '' ), (string) ( $violation['type'] ?? '' ) ) );
		$before[ $key ] = (string) ( $violation['value'] ?? '' );
	}

	foreach ( (array) ( $after_policy['violations'] ?? array() ) as $violation ) {
		if ( ! is_array( $violation ) ) {
			return false;
		}
		$key   = implode( '|', array( (string) ( $violation['element_id'] ?? '' ), (string) ( $violation['setting'] ?? '' ), (string) ( $violation['type'] ?? '' ) ) );
		$value = (string) ( $violation['value'] ?? '' );
		if ( ! array_key_exists( $key, $before ) ) {
			return false;
		}
		if ( 'inline_style' === (string) ( $violation['type'] ?? '' ) ) {
			preg_match_all( '/\\sstyle\\s*=\\s*(["\'])(.*?)\\1/is', $before[ $key ], $before_styles );
			preg_match_all( '/\\sstyle\\s*=\\s*(["\'])(.*?)\\1/is', $value, $after_styles );
			if ( ( $before_styles[2] ?? array() ) !== ( $after_styles[2] ?? array() ) ) {
				return false;
			}
		} elseif ( $before[ $key ] !== $value ) {
			return false;
		}
	}

	return true;
}

/**
 * Summarize current Elementor/theme context.
 *
 * @return array
 */
function mcp_abilities_elementor_get_theme_context_summary(): array {
	$theme  = wp_get_theme();
	$kit_id = mcp_abilities_elementor_get_active_kit_id();
	$kit    = $kit_id > 0 ? get_post( $kit_id ) : null;

	return array(
		'theme' => array(
			'name'           => $theme->get( 'Name' ),
			'stylesheet'     => $theme->get_stylesheet(),
			'template'       => $theme->get_template(),
			'version'        => $theme->get( 'Version' ),
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
		),
		'elementor' => array(
			'version' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			'active_kit' => array(
				'id'      => $kit_id,
				'title'   => $kit instanceof WP_Post ? $kit->post_title : '',
				'status'  => $kit instanceof WP_Post ? $kit->post_status : '',
			),
			'viewport_options' => array(
				'elementor_viewport_lg' => get_option( 'elementor_viewport_lg', '' ),
				'elementor_viewport_md' => get_option( 'elementor_viewport_md', '' ),
			),
		),
	);
}

/**
 * Build a style-guide summary from the active Elementor kit.
 *
 * @return array
 */
function mcp_abilities_elementor_get_style_guide_summary(): array {
	$kit_settings = mcp_abilities_elementor_get_active_kit_settings();
	$collector    = array(
		'colors'        => array(),
		'font_families' => array(),
		'font_sizes'    => array(),
		'font_weights'  => array(),
		'line_heights'  => array(),
		'gaps'          => array(),
		'dimensions'    => array(),
		'spacing'       => array(),
	);

	if ( ! empty( $kit_settings ) ) {
		mcp_abilities_elementor_collect_tokens_from_settings( $kit_settings, $collector );
	}

	return array(
		'kit_id'         => mcp_abilities_elementor_get_active_kit_id(),
		'tokens'         => mcp_abilities_elementor_finalize_design_tokens( $collector ),
		'layout'         => array(
			'container_width'          => $kit_settings['container_width'] ?? null,
			'content_width'            => $kit_settings['content_width'] ?? null,
			'space_between_widgets'    => $kit_settings['space_between_widgets'] ?? null,
			'viewport_lg'              => get_option( 'elementor_viewport_lg', '' ),
			'viewport_md'              => get_option( 'elementor_viewport_md', '' ),
		),
		'global_colors'  => array_values( (array) ( $kit_settings['system_colors'] ?? array() ) ),
		'global_typography' => array_values( (array) ( $kit_settings['system_typography'] ?? array() ) ),
		'raw_settings'   => $kit_settings,
	);
}

/**
 * Resolve Elementor data/elements for an audit-oriented ability.
 *
 * @param int    $post_id Post/Page ID.
 * @param string $element_id Optional root element ID.
 * @return array|\WP_Error
 */
function mcp_abilities_elementor_resolve_audit_scope( int $post_id, string $element_id = '' ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', 'Post not found' );
	}

	$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
	if ( empty( $elementor_data ) ) {
		return new WP_Error( 'missing_elementor_data', 'No Elementor data found for this post' );
	}

	$data = json_decode( $elementor_data, true );
	if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error( 'invalid_elementor_data', 'Failed to parse existing Elementor data' );
	}

	$elements = is_array( $data ) ? $data : array();
	if ( '' !== $element_id ) {
		$element_meta = mcp_abilities_elementor_find_element_meta( $data, $element_id );
		if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
			return new WP_Error( 'element_not_found', 'Element not found' );
		}
		$elements = array( $element_meta['element'] );
	}

	return array(
		'post'       => $post,
		'data'       => $data,
		'elements'   => $elements,
		'element_id' => $element_id,
	);
}

/**
 * Collect section emphasis profiles from a set of root elements.
 *
 * @param array $elements Root elements.
 * @return array
 */
function mcp_abilities_elementor_collect_section_profiles( array $elements ): array {
	$profiles = array();
	foreach ( $elements as $element ) {
		if ( is_array( $element ) ) {
			$profiles[] = mcp_abilities_elementor_compute_section_emphasis_profile( $element );
		}
	}

	return $profiles;
}

/**
 * Evaluate Elementor design coherence from a root-element set.
 *
 * @param array $elements Root elements.
 * @return array
 */
function mcp_abilities_elementor_evaluate_design_from_elements( array $elements ): array {
	$generic_stats = array(
		'patterns'           => array(
			'standard_split_hero'    => array(),
			'symmetric_two_column'   => array(),
			'three_up_grid'          => array(),
			'uniform_multi_grid'     => array(),
			'repeated_component_row' => array(),
		),
		'section_signatures' => array(),
	);
	$surface_collector = array();
	$column_stats      = array( 'rows' => array() );
	$component_root    = array(
		'id'       => '__page__',
		'elType'   => 'container',
		'isInner'  => false,
		'settings' => array(),
		'elements' => $elements,
	);

	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		mcp_abilities_elementor_collect_generic_layout_stats_from_subtree( $element, $generic_stats, -1 );
		mcp_abilities_elementor_collect_surface_signatures_from_subtree( $element, $surface_collector );
		mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element, $column_stats, -1 );
	}

	$profiles              = mcp_abilities_elementor_collect_section_profiles( $elements );
	$separator_profiles    = array();
	foreach ( $elements as $element ) {
		if ( is_array( $element ) ) {
			$separator_profiles[] = mcp_abilities_elementor_compute_section_separator_profile( $element );
		}
	}
	$generic_audit         = mcp_abilities_elementor_finalize_generic_layout_audit( $generic_stats );
	$distinctiveness       = mcp_abilities_elementor_score_distinctiveness_from_audit( $generic_audit );
	$component_audit       = mcp_abilities_elementor_finalize_component_overuse_audit( mcp_abilities_elementor_build_component_profile( $component_root ) );
	$surface_audit         = mcp_abilities_elementor_finalize_surface_overuse_audit( $surface_collector );
	$emphasis_drift_audit  = mcp_abilities_elementor_finalize_emphasis_drift_audit( $profiles );
	$section_rivalry_audit = mcp_abilities_elementor_finalize_section_rivalry_audit( $profiles );
	$composition_audit     = mcp_abilities_elementor_finalize_composition_rhythm_audit( $profiles );
	$separator_audit       = mcp_abilities_elementor_finalize_separator_discipline_audit( $separator_profiles );
	$rows                  = (array) ( $column_stats['rows'] ?? array() );
	$column_patterns_audit = mcp_abilities_elementor_finalize_column_patterns_audit( $rows );
	$layout_mechanism_audit = mcp_abilities_elementor_finalize_layout_mechanism_fit_audit( $rows );
	$native_widget_audit   = mcp_abilities_elementor_finalize_native_widget_opportunity_audit( $elements );
	$column_dominance_audit = mcp_abilities_elementor_finalize_column_dominance_audit( $rows );
	$column_alignment_audit = mcp_abilities_elementor_finalize_column_alignment_rhythm_audit( $rows );
	$column_balance_audit   = mcp_abilities_elementor_finalize_column_balance_audit( $rows );
	$column_necessity_audit = mcp_abilities_elementor_finalize_column_necessity_audit( $rows );

	$issues = array();

	if ( (int) ( $distinctiveness['score'] ?? 100 ) <= 80 && ! empty( $generic_audit['recommendations'] ) ) {
		$issues[] = array(
			'type'            => 'generic_layout_repetition',
			'score'           => (int) ( $distinctiveness['score'] ?? 100 ),
			'patterns'        => $generic_audit['patterns'] ?? array(),
			'recommendations' => array_values( (array) ( $generic_audit['recommendations'] ?? array() ) ),
		);
	}

	foreach ( (array) ( $component_audit['issues'] ?? array() ) as $issue ) {
		if ( is_array( $issue ) ) {
			$issues[] = $issue;
		}
	}

	if ( ! empty( $surface_audit['repeated_surfaces'] ) ) {
		$issues[] = array(
			'type'              => 'surface_overuse',
			'repeated_surfaces' => $surface_audit['repeated_surfaces'],
		);
	}

	if ( ! empty( $section_rivalry_audit['recommendations'] ) ) {
		$issues[] = array(
			'type'               => 'section_rivalry',
			'peak_section_ids'   => $section_rivalry_audit['peak_section_ids'] ?? array(),
			'adjacent_peak_runs' => $section_rivalry_audit['adjacent_peak_runs'] ?? array(),
		);
	}

	if ( ! empty( $emphasis_drift_audit['recommendations'] ) ) {
		$issues[] = array(
			'type'             => 'emphasis_drift',
			'flat_section_ids' => $emphasis_drift_audit['flat_section_ids'] ?? array(),
		);
	}

	if ( ! empty( $composition_audit['tone_runs'] ) ) {
		$issues[] = array(
			'type'      => 'composition_rhythm',
			'tone_runs' => $composition_audit['tone_runs'],
		);
	}

	if ( ! empty( $separator_audit['recommendations'] ) ) {
		$issues[] = array(
			'type'                   => 'separator_overuse',
			'section_top_border_ids' => $separator_audit['section_top_border_ids'] ?? array(),
			'section_top_border_runs'=> $separator_audit['section_top_border_runs'] ?? array(),
		);
	}

	if ( ! empty( $column_patterns_audit['repeated_ratios'] ) ) {
		$issues[] = array(
			'type'            => 'column_pattern_repetition',
			'repeated_ratios' => $column_patterns_audit['repeated_ratios'],
		);
	}

	if ( ! empty( $layout_mechanism_audit['grid_candidates'] ) ) {
		$issues[] = array(
			'type'            => 'layout_mechanism_fit',
			'grid_candidates' => $layout_mechanism_audit['grid_candidates'],
			'guidance_sources'=> $layout_mechanism_audit['guidance_sources'] ?? array(),
		);
	}

	if ( ! empty( $native_widget_audit['opportunities'] ) ) {
		$issues[] = array(
			'type'          => 'native_widget_opportunity',
			'opportunities' => $native_widget_audit['opportunities'],
		);
	}

	if ( ! empty( $column_alignment_audit['inconsistent_ratios'] ) ) {
		$issues[] = array(
			'type'                => 'column_alignment_rhythm',
			'inconsistent_ratios' => $column_alignment_audit['inconsistent_ratios'],
		);
	}

	foreach ( array(
		$column_dominance_audit,
		$column_balance_audit,
		$column_necessity_audit,
	) as $column_issue_group ) {
		foreach ( (array) ( $column_issue_group['issues'] ?? array() ) as $issue ) {
			if ( is_array( $issue ) ) {
				$issues[] = $issue;
			}
		}
	}

	$score = (int) ( $distinctiveness['score'] ?? 100 );
	foreach ( $issues as $issue ) {
		$type = (string) ( $issue['type'] ?? '' );
		if ( 'button_overuse' === $type || 'card_like_surface_overuse' === $type ) {
			$score -= 8;
		} elseif ( 'surface_overuse' === $type ) {
			$score -= 8;
		} elseif ( 'section_rivalry' === $type ) {
			$score -= 14;
		} elseif ( 'emphasis_drift' === $type ) {
			$score -= 8;
		} elseif ( 'composition_rhythm' === $type ) {
			$score -= 6;
		} elseif ( 'separator_overuse' === $type ) {
			$score -= 5;
		} elseif ( 'column_pattern_repetition' === $type || 'column_alignment_rhythm' === $type ) {
			$score -= 6;
		} elseif ( in_array( $type, array( 'equal_split_with_clear_dominant_side', 'asymmetry_without_clear_content_reason', 'split_may_not_be_earning_its_complexity' ), true ) ) {
			$score -= 8;
		}
	}
	$score = max( 0, min( 100, $score ) );

	$blocking_issue_types = array();
	foreach ( $issues as $issue ) {
		$type = (string) ( $issue['type'] ?? '' );
		if ( 'section_rivalry' === $type ) {
			$blocking_issue_types[] = $type;
		}
		if ( 'generic_layout_repetition' === $type && (int) ( $distinctiveness['score'] ?? 100 ) <= 60 ) {
			$blocking_issue_types[] = $type;
		}
	}
	$blocking_issue_types = array_values( array_unique( array_filter( array_map( 'strval', $blocking_issue_types ) ) ) );

	$recommendations = array_values(
		array_unique(
			array_merge(
				array_values( (array) ( $distinctiveness['recommendations'] ?? array() ) ),
				array_values( (array) ( $component_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $surface_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $emphasis_drift_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $section_rivalry_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $composition_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $separator_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $column_patterns_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $layout_mechanism_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $native_widget_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $column_dominance_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $column_alignment_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $column_balance_audit['recommendations'] ?? array() ) ),
				array_values( (array) ( $column_necessity_audit['recommendations'] ?? array() ) )
			)
		)
	);

	return array(
		'score'                => $score,
		'issues'               => $issues,
		'issue_count'          => count( $issues ),
		'passes'               => 0 === count( $blocking_issue_types ),
		'blocking_issue_count' => count( $blocking_issue_types ),
		'blocking_issue_types' => $blocking_issue_types,
		'source_policy'        => mcp_abilities_elementor_get_official_guidance_catalog()['policy'],
		'guidance_basis'       => mcp_abilities_elementor_get_design_guidance_basis(),
		'recommendations'      => $recommendations,
		'audits'               => array(
			'generic_layout'    => $generic_audit,
			'distinctiveness'   => $distinctiveness,
			'component_overuse' => $component_audit,
			'surface_overuse'   => $surface_audit,
			'emphasis_drift'    => $emphasis_drift_audit,
			'section_rivalry'   => $section_rivalry_audit,
			'composition_rhythm'=> $composition_audit,
			'separator_discipline' => $separator_audit,
			'column_patterns'   => $column_patterns_audit,
			'layout_mechanism_fit' => $layout_mechanism_audit,
			'native_widget_opportunities' => $native_widget_audit,
			'column_dominance'  => $column_dominance_audit,
			'column_alignment'  => $column_alignment_audit,
			'column_balance'    => $column_balance_audit,
			'column_necessity'  => $column_necessity_audit,
		),
	);
}

/**
 * Suggest concrete Elementor design fixes from an evaluation payload.
 *
 * @param array $evaluation Evaluation payload.
 * @return array
 */
function mcp_abilities_elementor_suggest_design_fixes_from_evaluation( array $evaluation ): array {
	$issues       = is_array( $evaluation['issues'] ?? null ) ? $evaluation['issues'] : array();
	$suggestions  = array();
	$seen_types   = array();
	$source_policy = mcp_abilities_elementor_get_official_guidance_catalog()['policy'];

	foreach ( $issues as $issue ) {
		$type = (string) ( $issue['type'] ?? '' );
		if ( '' === $type || isset( $seen_types[ $type ] ) ) {
			continue;
		}
		if ( 'surface_overuse' === $type && isset( $seen_types['card_like_surface_overuse'] ) ) {
			continue;
		}
		$seen_types[ $type ] = true;

		if ( 'generic_layout_repetition' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'Too many sections are leaning on the same stock layout patterns.',
				'fixes'   => array(
					'Break repeated 50/50 and equal-grid sequences with at least one section that uses a different ratio or information density.',
					'Let one section become more linear or open instead of keeping every block on the same module pattern.',
				),
			);
		} elseif ( 'button_overuse' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'The page is asking for action too often.',
				'fixes'   => array(
					'Reduce the number of CTA moments so the strongest action is easier to believe.',
					'Replace some secondary buttons with plain text links or proof statements.',
				),
			);
		} elseif ( 'card_like_surface_overuse' === $type || 'surface_overuse' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'Contained panel treatment is starting to dominate the page.',
				'fixes'   => array(
					'Flatten some sections so they live directly on the page background instead of inside another card-like surface.',
					'Reserve boxed treatment for modules that truly need containment.',
				),
			);
		} elseif ( 'section_rivalry' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'Several sections are trying to be local climaxes at the same time.',
				'fixes'   => array(
					'Keep one dominant hero and one clear secondary proof/evidence moment, then calm the surrounding sections.',
					'Reduce framed drama, CTA pressure, or high-contrast styling in neighboring sections so the page has a clearer hierarchy of loud versus supporting moments.',
				),
			);
		} elseif ( 'emphasis_drift' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'Too many sections are landing with similar emphasis weight.',
				'fixes'   => array(
					'Let support sections become quieter in headline size, contrast, or containment.',
					'Reserve the strongest emphasis for the sections that carry the selling job.',
				),
			);
		} elseif ( 'composition_rhythm' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'The page pacing is settling into one long tonal run.',
				'fixes'   => array(
					'Break long sequences of similar section tone with one calmer or more open section.',
					'Use contrast changes deliberately, not on every section.',
				),
			);
		} elseif ( 'separator_overuse' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'Major section separators are starting to flatten the page hierarchy.',
				'fixes'   => array(
					'Use separators inside section families such as rule lists or service sequences more than between every major block.',
					'Let some major section transitions happen through spacing, tone, or composition changes instead of another hard line.',
				),
			);
		} elseif ( 'column_pattern_repetition' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'Column ratios are repeating too predictably.',
				'fixes'   => array(
					'Vary repeated equal splits when the content roles are not actually symmetrical.',
					'Use equal thirds when comparison is the point, not just as a default grid habit.',
				),
			);
		} elseif ( 'layout_mechanism_fit' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'Some symmetric column groups are using Flexbox where Elementor Grid is the more reliable fit.',
				'fixes'   => array(
					'For equal, symmetric column groups, switch from Flexbox width-guessing to an Elementor Grid container.',
					'Keep Flexbox for directional or intentionally uneven rows, and use Grid when comparison or equal peer columns are the point.',
				),
				'source_policy' => $source_policy,
				'sources' => array_values( (array) ( $issue['guidance_sources'] ?? array() ) ),
			);
		} elseif ( 'native_widget_opportunity' === $type ) {
			$sources = array();
			foreach ( (array) ( $issue['opportunities'] ?? array() ) as $opportunity ) {
				foreach ( (array) ( $opportunity['sources'] ?? array() ) as $source ) {
					if ( is_string( $source ) && '' !== $source ) {
						$sources[] = $source;
					}
				}
			}
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'A hand-built container pattern is likely recreating something Elementor already offers as a native widget.',
				'fixes'   => array(
					'Use Accordion or Nested Tabs when repeated service items need one native interaction pattern instead of repeated handmade rows.',
					'Use Call to Action widgets for repeated promo blocks with title, text, and button/media instead of rebuilding the same module from raw containers.',
					'Use Icon List when the content is really a concise capability list rather than a mini card system.',
				),
				'source_policy' => $source_policy,
				'sources' => array_values( array_unique( $sources ) ),
			);
		} elseif ( 'column_alignment_rhythm' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'Similar rows are using different gutter rhythms.',
				'fixes'   => array(
					'Normalize gap logic across rows that are meant to feel like one family.',
					'Keep spacing differences only where they help the message instead of adding noise.',
				),
			);
		} elseif ( 'equal_split_with_clear_dominant_side' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'An equal split is hiding a clearly dominant side.',
				'fixes'   => array(
					'Let the row ratio reflect the real hierarchy instead of forcing a 50/50 split.',
					'If the split stays equal, reduce the dominance of the stronger side so the row feels honest again.',
				),
			);
		} elseif ( 'asymmetry_without_clear_content_reason' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'A row is asymmetrical without earning that tension.',
				'fixes'   => array(
					'Either give one side a clearer dominant role or bring the row back toward a calmer equal split.',
					'Do not keep asymmetry just because it seems more designed on paper.',
				),
			);
		} elseif ( 'split_may_not_be_earning_its_complexity' === $type ) {
			$suggestions[] = array(
				'type'    => $type,
				'problem' => 'A split row may not be earning the complexity of two columns.',
				'fixes'   => array(
					'Collapse the content into one lane if the two sides are too light or too similar.',
					'Only keep the split if comparison, contrast, or media/copy interplay is doing real work.',
				),
			);
		}
	}

	return array(
		'score'                => (int) ( $evaluation['score'] ?? 0 ),
		'issue_count'          => (int) ( $evaluation['issue_count'] ?? 0 ),
		'passes'               => (bool) ( $evaluation['passes'] ?? false ),
		'blocking_issue_count' => (int) ( $evaluation['blocking_issue_count'] ?? 0 ),
		'blocking_issue_types' => array_values( array_map( 'strval', (array) ( $evaluation['blocking_issue_types'] ?? array() ) ) ),
		'source_policy'        => $source_policy,
		'guidance_basis'       => mcp_abilities_elementor_get_design_guidance_basis(),
		'issues'               => $issues,
		'suggestions'          => $suggestions,
	);
}

/**
 * Fetch rendered HTML for a permalink.
 *
 * @param string $url URL to fetch.
 * @return array|\WP_Error
 */
function mcp_abilities_elementor_fetch_rendered_html( string $url ) {
	if ( '' === $url ) {
		return new WP_Error( 'missing_url', 'URL is required' );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 15,
			'redirection' => 5,
			'headers'     => array(
				'Accept' => 'text/html',
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return array(
		'status_code' => (int) wp_remote_retrieve_response_code( $response ),
		'html'        => (string) wp_remote_retrieve_body( $response ),
	);
}

/**
 * Evaluate wrapper/render context around an Elementor page.
 *
 * @param int $post_id Post/Page ID.
 * @return array|\WP_Error
 */
function mcp_abilities_elementor_evaluate_render_context( int $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', 'Post not found' );
	}

	$url = (string) get_permalink( $post_id );
	if ( '' === $url ) {
		return new WP_Error( 'missing_permalink', 'Could not determine permalink for this post' );
	}

	$fetched = mcp_abilities_elementor_fetch_rendered_html( $url );
	if ( is_wp_error( $fetched ) ) {
		return $fetched;
	}

	$html        = (string) ( $fetched['html'] ?? '' );
	$status_code = (int) ( $fetched['status_code'] ?? 0 );
	$issues      = array();
	$observations = array(
		'main_found'                => false,
		'content_wrapper_found'     => false,
		'content_wrapper_selector'  => '',
		'main_child_element_count'  => 0,
		'pre_content_sibling_count' => 0,
		'leading_content_child_tag' => '',
		'embedded_style_block_count'=> 0,
		'elementor_root_count'      => 0,
	);

	if ( '' === $html ) {
		return array(
			'url'          => $url,
			'status_code'  => $status_code,
			'post'         => array(
				'id'     => $post_id,
				'type'   => $post->post_type,
				'status' => $post->post_status,
				'slug'   => $post->post_name,
				'title'  => get_the_title( $post ),
			),
			'issues'       => array(
				array(
					'type'     => 'empty_rendered_html',
					'severity' => 'warning',
					'message'  => 'The rendered page returned no HTML body to inspect.',
				),
			),
			'observations' => $observations,
		);
	}

	if ( ! class_exists( 'DOMDocument' ) ) {
		return array(
			'url'          => $url,
			'status_code'  => $status_code,
			'post'         => array(
				'id'     => $post_id,
				'type'   => $post->post_type,
				'status' => $post->post_status,
				'slug'   => $post->post_name,
				'title'  => get_the_title( $post ),
			),
			'issues'       => array(
				array(
					'type'     => 'dom_extension_missing',
					'severity' => 'warning',
					'message'  => 'DOM extension is unavailable, so render-context inspection could not parse the HTML tree.',
				),
			),
			'observations' => $observations,
		);
	}

	libxml_use_internal_errors( true );
	$dom = new DOMDocument();
	$dom->loadHTML( $html );
	libxml_clear_errors();
	$xpath = new DOMXPath( $dom );

	$main = $xpath->query( '//main' )->item( 0 );
	if ( $main instanceof DOMElement ) {
		$observations['main_found'] = true;
		foreach ( $main->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				++$observations['main_child_element_count'];
			}
		}
	}

	$content_wrapper = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " elementor ")]' )->item( 0 );
	if ( $content_wrapper instanceof DOMElement ) {
		$observations['content_wrapper_found']    = true;
		$observations['content_wrapper_selector'] = strtolower( $content_wrapper->tagName ) . '.' . trim( preg_replace( '/\s+/', '.', (string) $content_wrapper->getAttribute( 'class' ) ), '.' );
		$elementor_roots = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " elementor ")]' );
		$observations['elementor_root_count'] = $elementor_roots ? (int) $elementor_roots->length : 0;

		foreach ( $content_wrapper->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$observations['leading_content_child_tag'] = strtolower( $child->nodeName );
				break;
			}
		}

		$previous = $content_wrapper->previousSibling;
		while ( $previous ) {
			if ( XML_ELEMENT_NODE === $previous->nodeType ) {
				++$observations['pre_content_sibling_count'];
			}
			$previous = $previous->previousSibling;
		}

		$observations['embedded_style_block_count'] = (int) $xpath->query( './/style', $content_wrapper )->length;
	}

	if ( ! $observations['content_wrapper_found'] ) {
		$issues[] = array(
			'type'     => 'missing_elementor_wrapper',
			'severity' => 'warning',
			'message'  => 'Rendered page did not expose a detectable Elementor root wrapper. Theme or plugin chrome may be obscuring the content lane.',
		);
	}

	if ( $observations['pre_content_sibling_count'] > 0 ) {
		$issues[] = array(
			'type'     => 'pre_content_wrappers',
			'severity' => 'warning',
			'count'    => $observations['pre_content_sibling_count'],
			'message'  => 'Rendered page contains wrapper elements before the Elementor content wrapper; these can create unexpected spacing or layout chrome above the first Elementor section.',
		);
	}

	if ( 'style' === $observations['leading_content_child_tag'] ) {
		$issues[] = array(
			'type'     => 'leading_style_block',
			'severity' => 'warning',
			'message'  => 'The first rendered child inside the Elementor wrapper is a style block. This can interact badly with theme flow spacing ahead of the hero.',
		);
	}

	return array(
		'url'          => $url,
		'status_code'  => $status_code,
		'post'         => array(
			'id'     => $post_id,
			'type'   => $post->post_type,
			'status' => $post->post_status,
			'slug'   => $post->post_name,
			'title'  => get_the_title( $post ),
		),
		'issues'       => $issues,
		'observations' => $observations,
	);
}

/**
 * Apply text hierarchy rules to a single widget element.
 *
 * @param array $element Elementor element.
 * @param array $heading_scale Heading scale map.
 * @param array $body_style Body typography.
 * @param array $button_style Button typography.
 * @return array
 */
function mcp_abilities_elementor_apply_text_hierarchy_to_widget( array $element, array $heading_scale, array $body_style, array $button_style ): array {
	if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
		return $element;
	}

	$widget_type = (string) ( $element['widgetType'] ?? '' );
	$settings    = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
	$globals     = isset( $settings['__globals__'] ) && is_array( $settings['__globals__'] ) ? $settings['__globals__'] : array();
	$unset_local_typography = static function ( array &$settings ): void {
		foreach ( array_keys( $settings ) as $key ) {
			if ( is_string( $key ) && mcp_abilities_elementor_is_local_typography_setting_key( $key ) ) {
				unset( $settings[ $key ] );
			}
		}
	};

	if ( 'heading' === $widget_type ) {
		$tag   = is_string( $settings['header_size'] ?? null ) ? strtolower( $settings['header_size'] ) : 'h2';
		$style = is_array( $heading_scale[ $tag ] ?? null ) ? $heading_scale[ $tag ] : ( $heading_scale['default'] ?? array() );

		if ( ! empty( $style['global_typography'] ) ) {
			$unset_local_typography( $settings );
			$globals['typography_typography'] = 'globals/typography?id=' . sanitize_key( (string) $style['global_typography'] );
		}
		if ( ! empty( $style['font_family'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_font_family'] = (string) $style['font_family'];
		}
		if ( isset( $style['font_size'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_font_size']  = mcp_abilities_elementor_make_size_control( $style['font_size'], 'px' );
		}
		if ( isset( $style['line_height'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_line_height'] = mcp_abilities_elementor_make_size_control( $style['line_height'], 'em' );
		}
		if ( isset( $style['font_weight'] ) ) {
			$settings['typography_font_weight'] = (string) $style['font_weight'];
		}
		if ( ! empty( $style['color'] ) ) {
			$settings['title_color'] = (string) $style['color'];
		}
		if ( ! empty( $style['align'] ) ) {
			$settings['align'] = (string) $style['align'];
		}
	}

	if ( 'text-editor' === $widget_type ) {
		if ( ! empty( $body_style['global_typography'] ) ) {
			$unset_local_typography( $settings );
			$globals['typography_typography'] = 'globals/typography?id=' . sanitize_key( (string) $body_style['global_typography'] );
		}
		if ( ! empty( $body_style['font_family'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_font_family'] = (string) $body_style['font_family'];
		}
		if ( isset( $body_style['font_size'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_font_size']  = mcp_abilities_elementor_make_size_control( $body_style['font_size'], 'px' );
		}
		if ( isset( $body_style['line_height'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_line_height'] = mcp_abilities_elementor_make_size_control( $body_style['line_height'], 'em' );
		}
		if ( isset( $body_style['font_weight'] ) ) {
			$settings['typography_font_weight'] = (string) $body_style['font_weight'];
		}
		if ( ! empty( $body_style['color'] ) ) {
			$settings['text_color'] = (string) $body_style['color'];
		}
		if ( ! empty( $body_style['align'] ) ) {
			$settings['align'] = (string) $body_style['align'];
		}
	}

	if ( 'button' === $widget_type ) {
		if ( ! empty( $button_style['global_typography'] ) ) {
			$unset_local_typography( $settings );
			$globals['typography_typography'] = 'globals/typography?id=' . sanitize_key( (string) $button_style['global_typography'] );
		}
		if ( ! empty( $button_style['font_family'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_font_family'] = (string) $button_style['font_family'];
		}
		if ( isset( $button_style['font_size'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_font_size']  = mcp_abilities_elementor_make_size_control( $button_style['font_size'], 'px' );
		}
		if ( isset( $button_style['line_height'] ) ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_line_height'] = mcp_abilities_elementor_make_size_control( $button_style['line_height'], 'em' );
		}
		if ( isset( $button_style['font_weight'] ) ) {
			$settings['typography_font_weight'] = (string) $button_style['font_weight'];
		}
		if ( ! empty( $button_style['text_color'] ) ) {
			$settings['button_text_color'] = (string) $button_style['text_color'];
		}
		if ( ! empty( $button_style['background_color'] ) ) {
			$settings['background_color'] = (string) $button_style['background_color'];
		}
		if ( ! empty( $button_style['align'] ) ) {
			$settings['align'] = (string) $button_style['align'];
		}
		if ( isset( $button_style['padding'] ) ) {
			$settings['button_padding'] = is_array( $button_style['padding'] )
				? $button_style['padding']
				: mcp_abilities_elementor_zero_spacing_box( null );
		}
	}

	if ( ! empty( $globals ) ) {
		$settings['__globals__'] = $globals;
	}

	$element['settings'] = $settings;
	return $element;
}

/**
 * Recursively apply text hierarchy rules through an Elementor subtree.
 *
 * @param array $element Elementor element.
 * @param array $heading_scale Heading rules.
 * @param array $body_style Body rules.
 * @param array $button_style Button rules.
 * @param bool  $include_root Whether to update the root element too.
 * @param int   $max_depth Maximum depth.
 * @param int   $depth Current depth.
 * @param array $changed_ids Changed IDs.
 * @return array
 */
function mcp_abilities_elementor_apply_text_hierarchy_subtree( array $element, array $heading_scale, array $body_style, array $button_style, bool $include_root, int $max_depth, int $depth, array &$changed_ids ): array {
	if ( $max_depth >= 0 && $depth > $max_depth ) {
		return $element;
	}

	$should_touch = ( 0 === $depth && $include_root ) || $depth > 0;
	if ( $should_touch ) {
		$updated = mcp_abilities_elementor_apply_text_hierarchy_to_widget( $element, $heading_scale, $body_style, $button_style );
		if ( $updated !== $element && ! empty( $updated['id'] ) && is_string( $updated['id'] ) ) {
			$changed_ids[] = $updated['id'];
		}
		$element = $updated;
	}

	if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( is_array( $child ) ) {
				$element['elements'][ $index ] = mcp_abilities_elementor_apply_text_hierarchy_subtree( $child, $heading_scale, $body_style, $button_style, true, $max_depth, $depth + 1, $changed_ids );
			}
		}
	}

	return $element;
}

/**
 * Recursively normalize spacing rhythm in a subtree.
 *
 * @param array $element Elementor element.
 * @param bool  $include_root Whether to include the root element.
 * @param int   $max_depth Maximum depth.
 * @param int   $depth Current depth.
 * @param int   $rhythm_step Rhythm step in px.
 * @param array $sides Sides to snap on spacing boxes.
 * @param bool  $include_margin Whether to include margins.
 * @param mixed $target_gap Optional explicit gap override.
 * @param array $changed_ids Changed IDs.
 * @return array
 */
function mcp_abilities_elementor_normalize_spacing_rhythm_subtree( array $element, bool $include_root, int $max_depth, int $depth, int $rhythm_step, array $sides, bool $include_margin, $target_gap, array &$changed_ids ): array {
	if ( $max_depth >= 0 && $depth > $max_depth ) {
		return $element;
	}

	$should_touch = ( 0 === $depth && $include_root ) || $depth > 0;
	if ( $should_touch ) {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$original = $settings;

		foreach ( array( 'padding', 'padding_tablet', 'padding_mobile', 'button_padding', 'button_padding_tablet', 'button_padding_mobile' ) as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = mcp_abilities_elementor_snap_spacing_box( $settings[ $key ], $rhythm_step, $sides );
			}
		}

		if ( $include_margin ) {
			foreach ( array( '_margin', '_margin_tablet', '_margin_mobile' ) as $key ) {
				if ( array_key_exists( $key, $settings ) ) {
					$settings[ $key ] = mcp_abilities_elementor_snap_spacing_box( $settings[ $key ], $rhythm_step, $sides );
				}
			}
		}

		foreach ( array( 'flex_gap', 'flex_gap_tablet', 'flex_gap_mobile' ) as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$settings[ $key ] = null !== $target_gap ? mcp_abilities_elementor_snap_size_value( $target_gap, $rhythm_step ) : mcp_abilities_elementor_snap_size_value( $settings[ $key ], $rhythm_step );
		}

		if ( $settings !== $original ) {
			$element['settings'] = $settings;
			if ( ! empty( $element['id'] ) && is_string( $element['id'] ) ) {
				$changed_ids[] = $element['id'];
			}
		}
	}

	if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( is_array( $child ) ) {
				$element['elements'][ $index ] = mcp_abilities_elementor_normalize_spacing_rhythm_subtree( $child, true, $max_depth, $depth + 1, $rhythm_step, $sides, $include_margin, $target_gap, $changed_ids );
			}
		}
	}

	return $element;
}

/**
 * Recursively normalize responsive values in a subtree.
 *
 * @param array $element Elementor element.
 * @param bool  $include_root Whether to include root.
 * @param int   $max_depth Maximum depth.
 * @param int   $depth Current depth.
 * @param bool  $fill_missing_only Only fill missing responsive values.
 * @param float $tablet_factor Tablet scale factor.
 * @param float $mobile_factor Mobile scale factor.
 * @param array $changed_ids Changed IDs.
 * @return array
 */
function mcp_abilities_elementor_normalize_responsive_values_subtree( array $element, bool $include_root, int $max_depth, int $depth, bool $fill_missing_only, float $tablet_factor, float $mobile_factor, ?float $tablet_horizontal_spacing_cap, ?float $mobile_horizontal_spacing_cap, array &$changed_ids ): array {
	if ( $max_depth >= 0 && $depth > $max_depth ) {
		return $element;
	}

	$should_touch = ( 0 === $depth && $include_root ) || $depth > 0;
	if ( $should_touch ) {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$original = $settings;

		$families = array(
			array( 'desktop' => 'width', 'tablet' => 'width_tablet', 'mobile' => 'width_mobile', 'type' => 'size' ),
			array( 'desktop' => 'flex_basis', 'tablet' => 'flex_basis_tablet', 'mobile' => 'flex_basis_mobile', 'type' => 'size' ),
			array( 'desktop' => 'min_height', 'tablet' => 'min_height_tablet', 'mobile' => 'min_height_mobile', 'type' => 'size' ),
			array( 'desktop' => 'flex_gap', 'tablet' => 'flex_gap_tablet', 'mobile' => 'flex_gap_mobile', 'type' => 'size' ),
			array( 'desktop' => 'padding', 'tablet' => 'padding_tablet', 'mobile' => 'padding_mobile', 'type' => 'spacing' ),
			array( 'desktop' => '_margin', 'tablet' => '_margin_tablet', 'mobile' => '_margin_mobile', 'type' => 'spacing' ),
			array( 'desktop' => 'button_padding', 'tablet' => 'button_padding_tablet', 'mobile' => 'button_padding_mobile', 'type' => 'spacing' ),
			array( 'desktop' => 'typography_font_size', 'tablet' => 'typography_font_size_tablet', 'mobile' => 'typography_font_size_mobile', 'type' => 'size' ),
			array( 'desktop' => 'typography_line_height', 'tablet' => 'typography_line_height_tablet', 'mobile' => 'typography_line_height_mobile', 'type' => 'size' ),
		);

		foreach ( $families as $family ) {
			$desktop_key = $family['desktop'];
			$tablet_key  = $family['tablet'];
			$mobile_key  = $family['mobile'];

			if ( ! array_key_exists( $desktop_key, $settings ) ) {
				continue;
			}

			$desktop_value = $settings[ $desktop_key ];
			$should_set_tablet = ! $fill_missing_only || ! array_key_exists( $tablet_key, $settings ) || null === $settings[ $tablet_key ] || '' === $settings[ $tablet_key ];
			$should_set_mobile = ! $fill_missing_only || ! array_key_exists( $mobile_key, $settings ) || null === $settings[ $mobile_key ] || '' === $settings[ $mobile_key ];

			if ( 'spacing' === $family['type'] ) {
				if ( $should_set_tablet ) {
					$settings[ $tablet_key ] = mcp_abilities_elementor_cap_spacing_box_horizontal(
						mcp_abilities_elementor_scale_spacing_box( $desktop_value, $tablet_factor ),
						$tablet_horizontal_spacing_cap
					);
				}
				if ( $should_set_mobile ) {
					$settings[ $mobile_key ] = mcp_abilities_elementor_cap_spacing_box_horizontal(
						mcp_abilities_elementor_scale_spacing_box( $desktop_value, $mobile_factor ),
						$mobile_horizontal_spacing_cap
					);
				}
				continue;
			}

			if ( $should_set_tablet ) {
				$settings[ $tablet_key ] = mcp_abilities_elementor_scale_size_value( $desktop_value, $tablet_factor );
			}
			if ( $should_set_mobile ) {
				$settings[ $mobile_key ] = mcp_abilities_elementor_scale_size_value( $desktop_value, $mobile_factor );
			}
		}

		if ( $settings !== $original ) {
			$element['settings'] = $settings;
			if ( ! empty( $element['id'] ) && is_string( $element['id'] ) ) {
				$changed_ids[] = $element['id'];
			}
		}
	}

	if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( is_array( $child ) ) {
				$element['elements'][ $index ] = mcp_abilities_elementor_normalize_responsive_values_subtree( $child, true, $max_depth, $depth + 1, $fill_missing_only, $tablet_factor, $mobile_factor, $tablet_horizontal_spacing_cap, $mobile_horizontal_spacing_cap, $changed_ids );
			}
		}
	}

	return $element;
}

/**
 * Sync design settings from one subtree variant to another.
 *
 * @param array $source_element Source subtree.
 * @param array $target_element Target subtree.
 * @param bool  $allow_partial Allow differing child counts.
 * @param array $include_keys Additional settings keys to copy.
 * @param array $changed_ids Changed IDs.
 * @return array
 */
function mcp_abilities_elementor_sync_component_variant_subtree( array $source_element, array $target_element, bool $allow_partial, array $include_keys, array &$changed_ids ): array {
	$source_settings = is_array( $source_element['settings'] ?? null ) ? $source_element['settings'] : array();
	$target_settings = is_array( $target_element['settings'] ?? null ) ? $target_element['settings'] : array();
	$filtered        = mcp_abilities_elementor_filter_design_settings( $source_settings, $include_keys );

	if ( ! empty( $filtered ) ) {
		$merged = mcp_abilities_elementor_merge_settings( $target_settings, $filtered );
		if ( $merged !== $target_settings ) {
			$target_element['settings'] = $merged;
			if ( ! empty( $target_element['id'] ) && is_string( $target_element['id'] ) ) {
				$changed_ids[] = $target_element['id'];
			}
		}
	}

	$source_children = is_array( $source_element['elements'] ?? null ) ? array_values( $source_element['elements'] ) : array();
	$target_children = is_array( $target_element['elements'] ?? null ) ? array_values( $target_element['elements'] ) : array();

	if ( count( $source_children ) !== count( $target_children ) && ! $allow_partial ) {
		return $target_element;
	}

	$pair_count = min( count( $source_children ), count( $target_children ) );
	for ( $index = 0; $index < $pair_count; $index++ ) {
		if ( ! is_array( $source_children[ $index ] ) || ! is_array( $target_children[ $index ] ) ) {
			continue;
		}

		$source_child = $source_children[ $index ];
		$target_child = $target_children[ $index ];

		if ( ( $source_child['elType'] ?? '' ) !== ( $target_child['elType'] ?? '' ) ) {
			continue;
		}
		if (
			'widget' === ( $source_child['elType'] ?? '' ) &&
			( $source_child['widgetType'] ?? '' ) !== ( $target_child['widgetType'] ?? '' )
		) {
			continue;
		}

		$target_children[ $index ] = mcp_abilities_elementor_sync_component_variant_subtree(
			$source_child,
			$target_child,
			$allow_partial,
			$include_keys,
			$changed_ids
		);
	}

	$target_element['elements'] = $target_children;
	return $target_element;
}

/**
 * Normalize horizontal boundary coherence inside an Elementor subtree.
 *
 * @param array       $element Elementor element.
 * @param string      $mode Boundary mode: `full_width` or `boxed`.
 * @param int|null    $boxed_width Boxed lane width when mode is `boxed`.
 * @param bool        $include_root Whether to normalize the root element too.
 * @param int         $max_depth Maximum descendant depth, -1 for unlimited.
 * @param int         $depth Current recursion depth.
 * @param bool        $zero_side_padding Whether to zero left/right padding.
 * @param bool        $zero_side_margins Whether to zero left/right margins.
 * @param bool        $normalize_nested_boxed_widths Whether to sync descendant boxed widths to the root.
 * @param array|null  $root_boxed_setting Root boxed_width setting to inherit.
 * @param array       $changed_ids Changed element IDs.
 * @return array
 */
function mcp_abilities_elementor_enforce_boundary_coherence_subtree(
	array $element,
	string $mode,
	?int $boxed_width,
	bool $include_root,
	int $max_depth,
	int $depth,
	bool $zero_side_padding,
	bool $zero_side_margins,
	bool $normalize_nested_boxed_widths,
	?array $root_boxed_setting,
	array &$changed_ids
): array {
	$should_touch = ( 0 === $depth && $include_root ) || ( $depth > 0 );
	$within_depth = $max_depth < 0 || $depth <= $max_depth;

	if ( $should_touch && $within_depth ) {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$changed  = false;

		if ( 'container' === ( $element['elType'] ?? '' ) ) {
			if ( $zero_side_padding ) {
				$padding = mcp_abilities_elementor_zero_horizontal_spacing_box( $settings['padding'] ?? null );
				if ( $padding !== ( $settings['padding'] ?? null ) ) {
					$settings['padding'] = $padding;
					$changed             = true;
				}
			}

			if ( 0 === $depth ) {
				$settings['content_width'] = 'full';
				if ( 'full_width' === $mode ) {
					if ( array_key_exists( 'boxed_width', $settings ) ) {
						unset( $settings['boxed_width'] );
						$changed = true;
					}
				} elseif ( 'boxed' === $mode && null !== $boxed_width ) {
					$new_boxed_width = array(
						'unit' => 'px',
						'size' => $boxed_width,
					);
					if ( ! isset( $settings['boxed_width'] ) || $settings['boxed_width'] !== $new_boxed_width ) {
						$settings['boxed_width'] = $new_boxed_width;
						$changed                 = true;
					}
					$root_boxed_setting = $new_boxed_width;
				}
			} elseif ( 'boxed' === $mode && $normalize_nested_boxed_widths && null !== $root_boxed_setting && array_key_exists( 'boxed_width', $settings ) ) {
				if ( $settings['boxed_width'] !== $root_boxed_setting ) {
					$settings['boxed_width'] = $root_boxed_setting;
					$changed                 = true;
				}
			}
		}

		if ( $zero_side_margins ) {
			foreach ( array( '_margin', '_margin_tablet', '_margin_mobile' ) as $margin_key ) {
				if ( ! array_key_exists( $margin_key, $settings ) ) {
					continue;
				}
				$margin = mcp_abilities_elementor_zero_horizontal_spacing_box( $settings[ $margin_key ] );
				if ( $margin !== $settings[ $margin_key ] ) {
					$settings[ $margin_key ] = $margin;
					$changed                 = true;
				}
			}
		}

		if ( $changed ) {
			$element['settings'] = $settings;
			$changed_ids[]       = (string) ( $element['id'] ?? '' );
		}
	}

	if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $index => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$element['elements'][ $index ] = mcp_abilities_elementor_enforce_boundary_coherence_subtree(
				$child,
				$mode,
				$boxed_width,
				true,
				$max_depth,
				$depth + 1,
				$zero_side_padding,
				$zero_side_margins,
				$normalize_nested_boxed_widths,
				$root_boxed_setting,
				$changed_ids
			);
		}
	}

	return $element;
}

/**
 * Prepare Elementor template meta for safe duplication.
 *
 * JSON-backed meta values must be slashed before saving through post meta APIs
 * or WordPress will strip escape characters and corrupt the JSON payload.
 *
 * @param string $meta_key Meta key.
 * @param mixed  $value Raw meta value from the original template.
 * @return mixed
 */
function mcp_abilities_elementor_prepare_duplicated_meta_value( string $meta_key, $value ) {
	$json_meta_keys = array(
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_popup_display_settings',
		'_elementor_conditions',
	);

	if ( ! in_array( $meta_key, $json_meta_keys, true ) ) {
		return $value;
	}

	if ( is_array( $value ) ) {
		return wp_slash( wp_json_encode( $value ) );
	}

	if ( is_string( $value ) ) {
		return wp_slash( $value );
	}

	return $value;
}

/**
 * Touch a post to refresh modified timestamps.
 *
 * @param int $post_id Post ID.
 * @return bool True if wp_update_post succeeded.
 */
function mcp_abilities_elementor_touch_post( int $post_id ): bool {
	$local_time = current_time( 'mysql' );
	$gmt_time   = current_time( 'mysql', true );

	$result = wp_update_post(
		array(
			'ID'                => $post_id,
			'post_modified'     => $local_time,
			'post_modified_gmt' => $gmt_time,
			'edit_date'         => true,
		),
		true
	);

	return ! is_wp_error( $result );
}

/**
 * Clear Elementor site-wide cache via Elementor's files manager.
 *
 * @return array
 */
function mcp_abilities_elementor_clear_site_cache(): array {
	$details = array(
		'elementor_cache_cleared' => false,
		'page_cache_provider'     => '',
		'page_cache_scope'        => 'none',
		'page_cache_cleared'      => false,
		'warnings'                => array(),
	);

	if ( ! class_exists( '\Elementor\Plugin' ) ) {
		$details['warnings'][] = 'Elementor plugin not loaded';
		return $details;
	}

	try {
		$elementor_instance = \Elementor\Plugin::$instance ?? null;
		if ( ! is_object( $elementor_instance ) ) {
			$details['warnings'][] = 'Elementor instance not available';
			return $details;
		}

		if ( isset( $elementor_instance->files_manager ) && is_object( $elementor_instance->files_manager ) ) {
			$elementor_instance->files_manager->clear_cache();
			$details['elementor_cache_cleared'] = true;
		} else {
			$details['warnings'][] = 'Elementor files_manager not available';
		}
	} catch ( \Throwable $e ) {
		$details['warnings'][] = 'Elementor cache clear failed: ' . $e->getMessage();
	}

	$page_cache_details = mcp_abilities_elementor_clear_page_cache( 0, 'site' );
	$details['page_cache_provider'] = $page_cache_details['provider'];
	$details['page_cache_scope']    = $page_cache_details['scope'];
	$details['page_cache_cleared']  = $page_cache_details['cleared'];
	if ( ! empty( $page_cache_details['warnings'] ) ) {
		$details['warnings'] = array_merge( $details['warnings'], $page_cache_details['warnings'] );
	}

	return $details;
}

/**
 * Clear known WordPress page-cache plugins after Elementor changes.
 *
 * This intentionally uses public plugin APIs instead of deleting cache files.
 * Supported providers are detected at runtime so sites without them are no-ops.
 *
 * @param int    $post_id Post/Page ID for post-scope cache clearing.
 * @param string $scope Cache scope (`post` or `site`).
 * @return array{provider:string,scope:string,cleared:bool,warnings:array<int,string>}
 */
function mcp_abilities_elementor_clear_page_cache( int $post_id = 0, string $scope = 'post' ): array {
	$details = array(
		'provider' => '',
		'scope'    => 'none',
		'cleared'  => false,
		'warnings' => array(),
	);

	$has_cache_enabler = class_exists( 'Cache_Enabler' )
		&& (
			is_callable( array( 'Cache_Enabler', 'clear_site_cache' ) )
			|| is_callable( array( 'Cache_Enabler', 'clear_page_cache_by_post' ) )
			|| is_callable( array( 'Cache_Enabler', 'clear_page_cache_by_url' ) )
		);

	if ( ! $has_cache_enabler ) {
		return $details;
	}

	$details['provider'] = 'cache-enabler';

	try {
		if ( 'site' === $scope && is_callable( array( 'Cache_Enabler', 'clear_site_cache' ) ) ) {
			Cache_Enabler::clear_site_cache();
			$details['scope']   = 'site';
			$details['cleared'] = true;
			return $details;
		}

		if ( $post_id > 0 && is_callable( array( 'Cache_Enabler', 'clear_page_cache_by_post' ) ) ) {
			Cache_Enabler::clear_page_cache_by_post( $post_id );
			$details['scope']   = 'post';
			$details['cleared'] = true;
		}

		if ( $post_id > 0 && is_callable( array( 'Cache_Enabler', 'clear_page_cache_by_url' ) ) ) {
			$permalink = get_permalink( $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				Cache_Enabler::clear_page_cache_by_url( $permalink );
				$details['scope']   = 'post';
				$details['cleared'] = true;
			}
		}
	} catch ( \Throwable $e ) {
		$details['warnings'][] = 'Cache Enabler clear failed: ' . $e->getMessage();
	}

	return $details;
}

/**
 * Regenerate Elementor's generated CSS file for a single post.
 *
 * @param int $post_id Post/Page ID.
 * @return array
 */
function mcp_abilities_elementor_regenerate_post_css( int $post_id ): array {
	$details = array(
		'post_css_regenerated' => false,
		'post_css_exists'      => false,
		'post_css_file'        => '',
		'warnings'             => array(),
	);

	if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
		$details['warnings'][] = 'Elementor post CSS generator not available';
		return $details;
	}

	try {
		$post_css = new \Elementor\Core\Files\CSS\Post( $post_id );
		if ( ! method_exists( $post_css, 'update' ) ) {
			$details['warnings'][] = 'Elementor post CSS update method not available';
			return $details;
		}

		$post_css->update();
		$details['post_css_regenerated'] = true;
	} catch ( \Throwable $e ) {
		$details['warnings'][] = 'Elementor post CSS regeneration failed: ' . $e->getMessage();
		return $details;
	}

	$uploads = wp_upload_dir();
	if ( empty( $uploads['basedir'] ) ) {
		$details['warnings'][] = 'WordPress upload directory unavailable';
		return $details;
	}

	$file = trailingslashit( (string) $uploads['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
	$details['post_css_file']   = $file;
	$details['post_css_exists'] = is_readable( $file );

	if ( ! $details['post_css_exists'] ) {
		$details['warnings'][] = 'Elementor post CSS file was not created';
	}

	return $details;
}

/**
 * Build a cache details response for no-op write paths.
 *
 * @param string $requested_scope Requested cache scope by caller.
 * @return array
 */
function mcp_abilities_elementor_build_noop_cache_details( string $requested_scope = 'post' ): array {
	return array(
		'requested_scope'          => mcp_abilities_elementor_normalize_cache_scope( $requested_scope, 'post' ),
		'effective_scope'          => 'none',
		'post_id'                  => 0,
		'post_meta_css_deleted'    => false,
		'post_meta_assets_deleted' => false,
		'post_cache_cleaned'       => false,
		'post_touched'             => false,
		'elementor_cache_cleared'  => false,
		'post_css_regenerated'     => false,
		'post_css_exists'          => false,
		'post_css_file'            => '',
		'page_cache_provider'      => '',
		'page_cache_scope'         => 'none',
		'page_cache_cleared'       => false,
		'warnings'                 => array(),
	);
}

/**
 * Invalidate Elementor + WordPress caches after Elementor data writes.
 *
 * @param int    $post_id Post/Page ID.
 * @param string $cache_scope Cache scope (`none`, `post`, `site`).
 * @param bool   $touch_post Whether to touch/retimestamp the post after clearing caches.
 * @return array
 */
function mcp_abilities_elementor_invalidate_after_write( int $post_id, string $cache_scope = 'post', bool $touch_post = true ): array {
	$cache_scope = mcp_abilities_elementor_normalize_cache_scope( $cache_scope, 'post' );

	$details = array(
		'requested_scope'          => $cache_scope,
		'effective_scope'          => 'none',
		'post_id'                  => $post_id,
		'post_meta_css_deleted'    => false,
		'post_meta_assets_deleted' => false,
		'post_cache_cleaned'       => false,
		'post_touched'             => false,
		'elementor_cache_cleared'  => false,
		'post_css_regenerated'     => false,
		'post_css_exists'          => false,
		'post_css_file'            => '',
		'page_cache_provider'      => '',
		'page_cache_scope'         => 'none',
		'page_cache_cleared'       => false,
		'warnings'                 => array(),
	);

	if ( 'none' === $cache_scope ) {
		return $details;
	}

	$css_deleted = delete_post_meta( $post_id, '_elementor_css' );
	$assets_deleted = delete_post_meta( $post_id, '_elementor_page_assets' );
	$details['post_meta_css_deleted'] = (bool) $css_deleted;
	$details['post_meta_assets_deleted'] = (bool) $assets_deleted;

	if ( $touch_post ) {
		$details['post_touched'] = mcp_abilities_elementor_touch_post( $post_id );
		clean_post_cache( $post_id );
	} else {
		clean_post_cache( $post_id );
	}
	$details['post_cache_cleaned'] = true;
	$details['effective_scope']    = 'post';

	$page_cache_details = mcp_abilities_elementor_clear_page_cache( $post_id, 'post' );
	$details['page_cache_provider'] = $page_cache_details['provider'];
	$details['page_cache_scope']    = $page_cache_details['scope'];
	$details['page_cache_cleared']  = $page_cache_details['cleared'];
	if ( ! empty( $page_cache_details['warnings'] ) ) {
		$details['warnings'] = array_merge( $details['warnings'], $page_cache_details['warnings'] );
	}

	if ( 'site' === $cache_scope ) {
		$site_cache_details = mcp_abilities_elementor_clear_site_cache();
		$details['elementor_cache_cleared'] = ! empty( $site_cache_details['elementor_cache_cleared'] );
		$details['page_cache_provider']     = (string) ( $site_cache_details['page_cache_provider'] ?? $details['page_cache_provider'] );
		$details['page_cache_scope']        = (string) ( $site_cache_details['page_cache_scope'] ?? $details['page_cache_scope'] );
		$details['page_cache_cleared']      = ! empty( $site_cache_details['page_cache_cleared'] );
		if ( ! empty( $site_cache_details['warnings'] ) && is_array( $site_cache_details['warnings'] ) ) {
			$details['warnings'] = array_merge( $details['warnings'], $site_cache_details['warnings'] );
		}
		if ( $details['elementor_cache_cleared'] ) {
			$details['effective_scope'] = 'site';
		}
	}

	return $details;
}

/**
 * Collect widget usage from an Elementor document tree.
 *
 * @param array $elements Elementor elements tree.
 * @return array<int, array{id:string, widget_type:string}>
 */
function mcp_abilities_elementor_collect_widget_usage( array $elements ): array {
	$usage = array();

	$walk = function ( array $nodes ) use ( &$walk, &$usage ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( 'widget' === ( $node['elType'] ?? null ) && ! empty( $node['widgetType'] ) ) {
				$usage[] = array(
					'id'          => (string) ( $node['id'] ?? '' ),
					'widget_type' => (string) $node['widgetType'],
				);
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$walk( $node['elements'] );
			}
		}
	};

	$walk( $elements );

	return $usage;
}

/**
 * Convert Elementor widget control definitions into schema-safe summaries.
 *
 * @param array  $controls Raw Elementor controls.
 * @param string $search   Optional case-insensitive filter.
 * @return array<int,array<string,mixed>>
 */
function mcp_abilities_elementor_summarize_widget_controls( array $controls, string $search = '' ): array {
	$summaries   = array();
	$search      = strtolower( trim( $search ) );
	$scalar_keys = array( 'type', 'label', 'description', 'tab', 'section', 'separator', 'prefix_class', 'render_type', 'frontend_available', 'responsive' );

	foreach ( $controls as $name => $control ) {
		if ( ! is_array( $control ) ) {
			continue;
		}

		$haystack = strtolower(
			(string) $name . ' ' .
			(string) ( $control['label'] ?? '' ) . ' ' .
			(string) ( $control['description'] ?? '' ) . ' ' .
			(string) ( $control['section'] ?? '' ) . ' ' .
			(string) ( $control['type'] ?? '' )
		);

		if ( '' !== $search && false === strpos( $haystack, $search ) ) {
			continue;
		}

		$summary = array(
			'name' => (string) $name,
		);

		foreach ( $scalar_keys as $key ) {
			if ( isset( $control[ $key ] ) && ( is_scalar( $control[ $key ] ) || null === $control[ $key ] ) ) {
				$summary[ $key ] = $control[ $key ];
			}
		}

		if ( isset( $control['default'] ) && ( is_scalar( $control['default'] ) || is_array( $control['default'] ) || null === $control['default'] ) ) {
			$summary['default'] = $control['default'];
		}

		if ( isset( $control['options'] ) && is_array( $control['options'] ) ) {
			$summary['options'] = $control['options'];
		}

		if ( isset( $control['size_units'] ) && is_array( $control['size_units'] ) ) {
			$summary['size_units'] = array_values( $control['size_units'] );
		}

		if ( isset( $control['range'] ) && is_array( $control['range'] ) ) {
			$summary['range'] = $control['range'];
		}

		if ( isset( $control['devices'] ) && is_array( $control['devices'] ) ) {
			$summary['devices'] = array_values( $control['devices'] );
		}

		if ( isset( $control['condition'] ) && is_array( $control['condition'] ) ) {
			$summary['condition'] = $control['condition'];
		}

		if ( isset( $control['selectors'] ) && is_array( $control['selectors'] ) ) {
			$summary['selector_count'] = count( $control['selectors'] );
			$summary['selector_keys']  = array_values( array_map( 'strval', array_keys( $control['selectors'] ) ) );
		}

		$summaries[] = $summary;
	}

	return $summaries;
}

/**
 * Return authoring warnings for Elementor menu widgets.
 *
 * The legacy Nav Menu/WordPress Menu widget and the newer Menu (`mega-menu`)
 * widget both produce navigation, but their native control surfaces differ in
 * important ways. Write abilities surface this guidance so agents do not solve
 * menu parity gaps with ad-hoc CSS when Elementor has a better widget fit.
 *
 * @param array $elements Elementor elements tree.
 * @return array<string,mixed>
 */
function mcp_abilities_elementor_collect_menu_widget_guidance_warnings( array $elements ): array {
	$warnings = array();
	$catalog  = mcp_abilities_elementor_get_official_guidance_catalog();
	$sources  = array(
		'nav_menu' => (string) ( $catalog['widgets']['nav_menu']['url'] ?? '' ),
		'menu'     => (string) ( $catalog['widgets']['menu']['url'] ?? '' ),
	);

	$walk = static function ( array $nodes ) use ( &$walk, &$warnings, $sources ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( 'widget' === ( $node['elType'] ?? null ) ) {
				$widget_type = (string) ( $node['widgetType'] ?? '' );
				$settings    = is_array( $node['settings'] ?? null ) ? $node['settings'] : array();
				$element_id  = (string) ( $node['id'] ?? '' );

				if ( 'nav-menu' === $widget_type ) {
					$has_dropdown_controls = false;
					foreach ( array_keys( $settings ) as $key ) {
						if ( is_string( $key ) && false !== strpos( $key, 'dropdown' ) ) {
							$has_dropdown_controls = true;
							break;
						}
					}

					$warnings[] = array(
						'element_id'       => $element_id,
						'widget_type'      => $widget_type,
						'type'             => 'legacy_nav_menu_control_limit',
						'severity'         => $has_dropdown_controls ? 'warning' : 'info',
						'message'          => 'Legacy Nav Menu/WordPress Menu supports basic dropdown styling, but does not expose native desktop submenu box width or dropdown line-height controls. Use the newer Elementor Menu widget (`mega-menu`) when exact dropdown parity is required.',
						'recommended_widget' => 'mega-menu',
						'sources'          => array_values( array_filter( array( $sources['nav_menu'], $sources['menu'] ) ) ),
					);
				}

				if ( 'mega-menu' === $widget_type ) {
					$menu_items = is_array( $settings['menu_items'] ?? null ) ? array_values( $settings['menu_items'] ) : array();
					$children   = is_array( $node['elements'] ?? null ) ? array_values( $node['elements'] ) : array();

					if ( count( $menu_items ) !== count( $children ) ) {
						$warnings[] = array(
							'element_id'  => $element_id,
							'widget_type' => $widget_type,
							'type'        => 'mega_menu_child_container_count_mismatch',
							'severity'    => 'warning',
							'message'     => 'Elementor Menu (`mega-menu`) expects one child container per top-level `menu_items` entry, in the same order. Dropdown content for item N belongs in child container N.',
							'expected'    => count( $menu_items ),
							'actual'      => count( $children ),
							'sources'     => array_values( array_filter( array( $sources['menu'] ) ) ),
						);
					}

					foreach ( $menu_items as $index => $item ) {
						$dropdown_enabled = is_array( $item ) && 'yes' === (string) ( $item['item_dropdown_content'] ?? 'no' );
						if ( ! $dropdown_enabled ) {
							continue;
						}

						$child = $children[ $index ] ?? array();
						$child_elements = is_array( $child ) && is_array( $child['elements'] ?? null ) ? $child['elements'] : array();
						if ( empty( $child_elements ) ) {
							$warnings[] = array(
								'element_id'  => $element_id,
								'widget_type' => $widget_type,
								'type'        => 'mega_menu_enabled_dropdown_without_content',
								'severity'    => 'warning',
								'message'     => 'This Menu item has `item_dropdown_content` enabled, but the matching child container is empty. Add dropdown content to the child container with the same index or disable dropdown content for this item.',
								'item_index'  => $index,
								'item_title'  => is_array( $item ) ? (string) ( $item['item_title'] ?? '' ) : '',
								'sources'     => array_values( array_filter( array( $sources['menu'] ) ) ),
							);
						}
					}
				}
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$walk( $node['elements'] );
			}
		}
	};

	$walk( $elements );

	return array(
		'source_policy' => $catalog['policy'],
		'warning_count' => count( $warnings ),
		'warnings'      => array_values( $warnings ),
	);
}

/**
 * Return interactive widget usage that depends on Elementor frontend runtime.
 *
 * @param array $elements Elementor elements tree.
 * @return array<int, array{id:string, widget_type:string}>
 */
function mcp_abilities_elementor_collect_interactive_widget_usage( array $elements ): array {
	$interactive_widget_types = array(
		'accordion',
		'tabs',
		'toggle',
		'nested-tabs',
		'nested-accordion',
		'image-carousel',
		'media-carousel',
		'testimonial-carousel',
		'slides',
		'loop-carousel',
		'loop-grid',
		'video',
		'animated-headline',
		'search-form',
		'mega-menu',
		'posts',
		'portfolio',
		'gallery',
		'form',
		'login',
		'lottie',
		'price-table',
		'hotspot',
		'flip-box',
	);

	$usage = mcp_abilities_elementor_collect_widget_usage( $elements );

	return array_values(
		array_filter(
			$usage,
			static function ( array $item ) use ( $interactive_widget_types ): bool {
				return in_array( $item['widget_type'], $interactive_widget_types, true );
			}
		)
	);
}

/**
 * Normalize Elementor Pro popup display settings into a frontend-safe shape.
 *
 * Elementor Pro popup rendering expects both `triggers` and `timing` arrays to
 * exist once popup display settings are saved. Earlier plugin writes could
 * persist trigger-only arrays, which then surface as frontend warnings/fatals
 * inside Elementor Pro popup rendering.
 *
 * @param mixed $popup_settings Raw popup settings meta.
 * @return array
 */
function mcp_abilities_elementor_normalize_popup_display_settings( $popup_settings ): array {
	$popup_settings = is_array( $popup_settings ) ? $popup_settings : array();
	$triggers       = is_array( $popup_settings['triggers'] ?? null ) ? $popup_settings['triggers'] : array();
	$timing         = is_array( $popup_settings['timing'] ?? null ) ? $popup_settings['timing'] : array();

	// Elementor Pro popup documents expect `timing` to exist. If page-load
	// triggering is enabled and no explicit delay is provided, treat it as 0.
	if ( isset( $triggers['page_load'] ) && 'yes' === $triggers['page_load'] && ! isset( $timing['page_load_delay'] ) ) {
		$timing['page_load_delay'] = 0;
	}

	$popup_settings['triggers'] = $triggers;
	$popup_settings['timing']   = $timing;

	return $popup_settings;
}

/**
 * Convert high-level popup display input into Elementor Pro popup settings meta.
 *
 * @param array $popup_display User input payload.
 * @param array $base_settings Existing popup settings.
 * @return array
 */
function mcp_abilities_elementor_build_popup_display_settings( array $popup_display, array $base_settings = array() ): array {
	$popup_settings = mcp_abilities_elementor_normalize_popup_display_settings( $base_settings );

	if ( ! empty( $popup_display['triggers'] ) && is_array( $popup_display['triggers'] ) ) {
		$popup_settings['triggers'] = array();
		foreach ( $popup_display['triggers'] as $trigger ) {
			if ( 'on_page_load' === $trigger ) {
				$popup_settings['triggers']['page_load'] = 'yes';
			} elseif ( 'on_scroll' === $trigger ) {
				$popup_settings['triggers']['scrolling'] = 'yes';
				$popup_settings['triggers']['scrolling_direction'] = 'down';
			} elseif ( 'on_exit_intent' === $trigger ) {
				$popup_settings['triggers']['exit_intent'] = 'yes';
			} elseif ( 'on_click' === $trigger ) {
				$popup_settings['triggers']['click'] = 'yes';
				$popup_settings['triggers']['click_times'] = 1;
			} elseif ( 'after_inactivity' === $trigger ) {
				$popup_settings['triggers']['inactivity'] = 'yes';
				$popup_settings['triggers']['inactivity_time'] = 30;
			}
		}
	}

	if ( ! empty( $popup_display['timing'] ) && is_array( $popup_display['timing'] ) ) {
		$timing = $popup_display['timing'];
		if ( isset( $timing['show_after'] ) ) {
			$popup_settings['timing']['page_load_delay'] = (int) $timing['show_after'];
		}
		if ( isset( $timing['show_times'] ) ) {
			$popup_settings['timing']['times_count'] = (int) $timing['show_times'];
			$popup_settings['timing']['times']       = 'yes';
		}
	}

	return mcp_abilities_elementor_normalize_popup_display_settings( $popup_settings );
}

/**
 * Audit published Elementor Pro popup/theme-builder documents for known
 * frontend-breaking metadata problems.
 *
 * @return array<string,mixed>
 */
function mcp_abilities_elementor_audit_theme_builder_runtime_health(): array {
	$template_ids = get_posts(
		array(
			'post_type'              => 'elementor_library',
			'post_status'            => 'publish',
			'posts_per_page'         => 200,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		)
	);
	$target_template_types = array( 'popup', 'header', 'footer', 'single', 'archive' );

	$issues = array();
	foreach ( $template_ids as $template_id ) {
		$template_id   = (int) $template_id;
		$template_type = (string) get_post_meta( $template_id, '_elementor_template_type', true );
		$title         = get_the_title( $template_id );

		if ( ! in_array( $template_type, $target_template_types, true ) ) {
			continue;
		}

		if ( 'popup' === $template_type ) {
			$popup_settings = get_post_meta( $template_id, '_elementor_popup_display_settings', true );
			$raw_is_array   = is_array( $popup_settings );
			$triggers       = $raw_is_array && is_array( $popup_settings['triggers'] ?? null ) ? $popup_settings['triggers'] : array();
			$timing         = $raw_is_array && is_array( $popup_settings['timing'] ?? null ) ? $popup_settings['timing'] : null;

			if ( ! $raw_is_array || null === $timing || ( isset( $triggers['page_load'] ) && 'yes' === $triggers['page_load'] && ! isset( $timing['page_load_delay'] ) ) ) {
				$issues[] = array(
					'id'           => $template_id,
					'title'        => $title,
					'type'         => $template_type,
					'issue'        => 'popup_display_settings_incomplete',
					'details'      => array(
						'has_settings_array' => $raw_is_array,
						'has_triggers'       => ! empty( $triggers ),
						'has_timing_array'   => is_array( $timing ),
						'page_load_trigger'  => isset( $triggers['page_load'] ) && 'yes' === $triggers['page_load'],
						'has_page_load_delay'=> is_array( $timing ) && isset( $timing['page_load_delay'] ),
					),
					'recommendation'=> 'Normalize popup display settings so both triggers and timing arrays exist before relying on interactive frontend runtime.',
				);
			}
		}
	}

	return array(
		'healthy' => empty( $issues ),
		'issues'  => $issues,
	);
}

/**
 * Audit whether the published page includes Elementor frontend runtime when needed.
 *
 * @param int   $post_id Post ID.
 * @param array $elements Elementor elements tree after write.
 * @return array<string,mixed>
 */
function mcp_abilities_elementor_audit_frontend_runtime_readiness( int $post_id, array $elements ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array(
			'required' => false,
			'ready'    => false,
			'skipped'  => true,
			'reason'   => 'post_not_found',
		);
	}

	$interactive_usage = mcp_abilities_elementor_collect_interactive_widget_usage( $elements );
	if ( empty( $interactive_usage ) ) {
		return array(
			'required' => false,
			'ready'    => true,
			'skipped'  => true,
			'reason'   => 'no_interactive_widgets',
		);
	}

	if ( 'publish' !== $post->post_status ) {
		return array(
			'required'                => true,
			'ready'                   => true,
			'skipped'                 => true,
			'reason'                  => 'post_not_published',
			'interactive_widget_ids'  => array_values( array_map( static fn( array $item ): string => $item['id'], $interactive_usage ) ),
			'interactive_widget_types'=> array_values( array_unique( array_map( static fn( array $item ): string => $item['widget_type'], $interactive_usage ) ) ),
		);
	}

	$url = get_permalink( $post_id );
	if ( empty( $url ) ) {
		return array(
			'required'                => true,
			'ready'                   => false,
			'skipped'                 => true,
			'reason'                  => 'missing_permalink',
			'interactive_widget_ids'  => array_values( array_map( static fn( array $item ): string => $item['id'], $interactive_usage ) ),
			'interactive_widget_types'=> array_values( array_unique( array_map( static fn( array $item ): string => $item['widget_type'], $interactive_usage ) ) ),
		);
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'required'                => true,
			'ready'                   => false,
			'url'                     => $url,
			'fetch_error'             => $response->get_error_message(),
			'interactive_widget_ids'  => array_values( array_map( static fn( array $item ): string => $item['id'], $interactive_usage ) ),
			'interactive_widget_types'=> array_values( array_unique( array_map( static fn( array $item ): string => $item['widget_type'], $interactive_usage ) ) ),
		);
	}

	$body = (string) wp_remote_retrieve_body( $response );
	$checks = array(
		'has_frontend_config'  => false !== strpos( $body, 'elementorFrontendConfig' ),
		'has_webpack_runtime'  => false !== strpos( $body, '/elementor/assets/js/webpack.runtime.min.js' ),
		'has_frontend_modules' => false !== strpos( $body, '/elementor/assets/js/frontend-modules.min.js' ),
		'has_frontend_script'  => false !== strpos( $body, '/elementor/assets/js/frontend.min.js' ),
	);

	$issues = array();
	foreach ( $checks as $key => $passed ) {
		if ( ! $passed ) {
			$issues[] = $key;
		}
	}

	$theme_builder_health = mcp_abilities_elementor_audit_theme_builder_runtime_health();
	if ( ! empty( $theme_builder_health['healthy'] ) ) {
		$theme_builder_health = array(
			'healthy' => true,
			'issues'  => array(),
		);
	} else {
		$issues[] = 'theme_builder_runtime_health';
	}

	return array(
		'required'                => true,
		'ready'                   => empty( $issues ),
		'url'                     => $url,
		'status_code'             => (int) wp_remote_retrieve_response_code( $response ),
		'checks'                  => $checks,
		'issues'                  => $issues,
		'theme_builder_health'    => $theme_builder_health,
		'interactive_widget_ids'  => array_values( array_map( static fn( array $item ): string => $item['id'], $interactive_usage ) ),
		'interactive_widget_types'=> array_values( array_unique( array_map( static fn( array $item ): string => $item['widget_type'], $interactive_usage ) ) ),
	);
}

/**
 * Attach frontend runtime guard results to a write response.
 *
 * @param array $response Write response.
 * @param int   $post_id Post ID.
 * @param array $elements Elementor elements tree after write.
 * @return array
 */
function mcp_abilities_elementor_apply_frontend_runtime_guard( array $response, int $post_id, array $elements ): array {
	$guard = mcp_abilities_elementor_audit_frontend_runtime_readiness( $post_id, $elements );
	$response['frontend_runtime'] = $guard;

	$write_guard = mcp_abilities_elementor_audit_write_guard( $elements );
	if ( ! empty( $write_guard['warning_count'] ) || ! empty( $write_guard['error_count'] ) ) {
		$response['elementor_write_guard'] = $write_guard;
	}

	$menu_guidance = mcp_abilities_elementor_collect_menu_widget_guidance_warnings( $elements );
	if ( ! empty( $menu_guidance['warning_count'] ) ) {
		$response['menu_widget_guidance'] = $menu_guidance;
	}

	if ( ! empty( $guard['required'] ) && empty( $guard['ready'] ) ) {
		$response['success'] = false;
		$message = rtrim( (string) ( $response['message'] ?? 'Elementor write completed' ), '.' );
		if ( ! empty( $guard['theme_builder_health']['issues'] ) ) {
			$message .= '. Frontend runtime guard failed: interactive widgets are present, and one or more published Elementor Pro popup/theme-builder documents are in a frontend-breaking state.';
		} else {
			$message .= '. Frontend runtime guard failed: interactive widgets are present but Elementor frontend assets/config are missing on the published page.';
		}
		$response['message'] = $message;
		$response['guard_failed'] = true;
	}

	return $response;
}

/**
 * Decode Elementor document data for a post.
 *
 * @param int $post_id Post ID.
 * @return array<int,mixed>
 */
function mcp_abilities_elementor_get_post_elements( int $post_id ): array {
	$raw = get_post_meta( $post_id, '_elementor_data', true );

	if ( is_array( $raw ) ) {
		return $raw;
	}

	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return array();
	}

	$data = json_decode( wp_unslash( $raw ), true );

	return is_array( $data ) ? $data : array();
}

/**
 * Return the currently queried singular post ID when available.
 *
 * @return int
 */
function mcp_abilities_elementor_get_current_frontend_post_id(): int {
	$post_id = get_queried_object_id();
	if ( is_numeric( $post_id ) && (int) $post_id > 0 ) {
		return (int) $post_id;
	}

	if ( is_front_page() ) {
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id > 0 ) {
			return $front_page_id;
		}
	}

	if ( is_home() ) {
		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id > 0 ) {
			return $posts_page_id;
		}
	}

	global $post;
	if ( $post instanceof WP_Post && $post->ID > 0 ) {
		return (int) $post->ID;
	}

	return 0;
}

/**
 * Check whether frontend runtime repair is allowed for the current document.
 *
 * Normal Elementor pages should be left to Elementor's own frontend bootstrap.
 * This repair path is only for canvas/headless-style documents that intentionally
 * bypass the theme wrapper and can miss the usual runtime output.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function mcp_abilities_elementor_is_frontend_runtime_repair_allowed( int $post_id ): bool {
	if ( $post_id <= 0 ) {
		return false;
	}

	$template = (string) get_page_template_slug( $post_id );
	if ( 'elementor_canvas' === $template ) {
		return true;
	}

	/**
	 * Allow site-specific headless/canvas templates to opt into Elementor
	 * frontend runtime repair without enabling it on every normal page.
	 *
	 * @param bool   $allowed  Whether repair is allowed.
	 * @param int    $post_id  Current post ID.
	 * @param string $template Page template slug.
	 */
	return (bool) apply_filters( 'mcp_abilities_elementor_allow_frontend_runtime_repair', false, $post_id, $template );
}

/**
 * Detect whether the current frontend request needs Elementor runtime repair.
 *
 * @return array<string,mixed>
 */
function mcp_abilities_elementor_get_current_frontend_runtime_context(): array {
	static $context = null;

	$default = array(
		'needed'                   => false,
		'post_id'                  => 0,
		'interactive_widget_types' => array(),
		'interactive_widget_ids'   => array(),
	);

	if ( null !== $context ) {
		return $context;
	}

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! did_action( 'elementor/loaded' ) ) {
		return $default;
	}

	// Avoid caching a false negative before the main query is ready.
	if ( ! did_action( 'wp' ) ) {
		return $default;
	}

	$post_id = mcp_abilities_elementor_get_current_frontend_post_id();
	if ( $post_id <= 0 ) {
		$context = $default;
		return $context;
	}

	if ( ! mcp_abilities_elementor_is_frontend_runtime_repair_allowed( $post_id ) ) {
		$context = $default;
		$context['reason'] = 'runtime_repair_not_allowed_for_template';
		return $context;
	}

	if ( 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
		$context = $default;
		return $context;
	}

	$elements = mcp_abilities_elementor_get_post_elements( $post_id );
	if ( empty( $elements ) ) {
		$context = $default;
		return $context;
	}

	$interactive_usage = mcp_abilities_elementor_collect_interactive_widget_usage( $elements );
	if ( empty( $interactive_usage ) ) {
		$context = $default;
		return $context;
	}

	$context = array(
		'needed'                   => true,
		'post_id'                  => $post_id,
		'interactive_widget_types' => array_values( array_unique( array_map( static fn( array $item ): string => $item['widget_type'], $interactive_usage ) ) ),
		'interactive_widget_ids'   => array_values( array_map( static fn( array $item ): string => $item['id'], $interactive_usage ) ),
	);

	return $context;
}

/**
 * Conditionally enqueue Elementor frontend runtime for interactive Elementor documents.
 *
 * @return void
 */
function mcp_abilities_elementor_enqueue_frontend_runtime_when_needed(): void {
	$context = mcp_abilities_elementor_get_current_frontend_runtime_context();
	if ( empty( $context['needed'] ) || ! class_exists( '\Elementor\Plugin' ) ) {
		return;
	}

	$elementor = \Elementor\Plugin::instance();

	if ( isset( $elementor->frontend ) ) {
		if ( method_exists( $elementor->frontend, 'enqueue_styles' ) ) {
			$elementor->frontend->enqueue_styles();
		}
		if ( method_exists( $elementor->frontend, 'enqueue_scripts' ) ) {
			$elementor->frontend->enqueue_scripts();
		}
	}

	if ( class_exists( '\ElementorPro\Plugin' ) ) {
		$elementor_pro = \ElementorPro\Plugin::instance();
		if ( isset( $elementor_pro->frontend ) ) {
			if ( method_exists( $elementor_pro->frontend, 'enqueue_styles' ) ) {
				$elementor_pro->frontend->enqueue_styles();
			}
			if ( method_exists( $elementor_pro->frontend, 'enqueue_scripts' ) ) {
				$elementor_pro->frontend->enqueue_scripts();
			}
		}
	}

	foreach ( array( 'elementor-webpack-runtime', 'elementor-frontend-modules', 'elementor-frontend', 'elementor-pro-frontend' ) as $handle ) {
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_enqueue_script( $handle );
		}
	}

	foreach ( array( 'elementor-frontend', 'elementor-pro-frontend' ) as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
		}
	}
}

/**
 * Print Elementor frontend config early when interactive widgets require runtime bootstrap.
 *
 * @return void
 */
function mcp_abilities_elementor_print_frontend_config_when_needed(): void {
	$context = mcp_abilities_elementor_get_current_frontend_runtime_context();
	if ( empty( $context['needed'] ) || ! class_exists( '\Elementor\Plugin' ) ) {
		return;
	}

	$elementor = \Elementor\Plugin::instance();
	if ( ! isset( $elementor->frontend ) ) {
		return;
	}

	if ( is_callable( array( $elementor->frontend, 'print_config' ) ) ) {
		$elementor->frontend->print_config();
		if ( has_action( 'wp_footer', array( $elementor->frontend, 'print_config' ) ) ) {
			remove_action( 'wp_footer', array( $elementor->frontend, 'print_config' ) );
		}
	}
}
/**
 * Print footer scripts early as a fallback when interactive Elementor pages need runtime boot.
 *
 * Canvas-like templates can omit the usual footer script output, so print the
 * queued runtime in-head as a last resort when Elementor frontend handles are enqueued.
 *
 * @return void
 */
function mcp_abilities_elementor_print_footer_scripts_early_when_needed(): void {
	$context = mcp_abilities_elementor_get_current_frontend_runtime_context();
	if ( empty( $context['needed'] ) || did_action( 'wp_print_footer_scripts' ) ) {
		return;
	}

	if ( ! wp_script_is( 'elementor-frontend', 'enqueued' ) && ! wp_script_is( 'elementor-pro-frontend', 'enqueued' ) ) {
		return;
	}

	wp_print_footer_scripts();

	if ( has_action( 'wp_footer', 'wp_print_footer_scripts' ) ) {
		remove_action( 'wp_footer', 'wp_print_footer_scripts', 20 );
	}
}

/**
 * Normalize theme builder conditions into Elementor's string format.
 *
 * @param array $conditions Raw conditions array.
 * @return array
 */
function mcp_abilities_elementor_normalize_conditions( array $conditions ): array {
	$normalized = array();

	foreach ( $conditions as $condition ) {
		if ( is_string( $condition ) ) {
			$normalized[] = $condition;
			continue;
		}

		if ( ! is_array( $condition ) ) {
			continue;
		}

		$parts = array();
		if ( isset( $condition['type'] ) ) {
			$parts[] = $condition['type'];
		}
		if ( isset( $condition['name'] ) ) {
			$parts[] = $condition['name'];
		}
		if ( isset( $condition['sub_name'] ) && '' !== $condition['sub_name'] ) {
			$parts[] = $condition['sub_name'];
		}
		if ( isset( $condition['sub_id'] ) && '' !== $condition['sub_id'] ) {
			$parts[] = $condition['sub_id'];
		}

		if ( ! empty( $parts ) ) {
			$normalized[] = implode( '/', $parts );
		}
	}

	return $normalized;
}

/**
 * Persist theme builder conditions to post meta and Elementor Pro options.
 *
 * @param int    $post_id Post ID.
 * @param string $template_type Template type.
 * @param array  $conditions_to_save Normalized conditions.
 */
function mcp_abilities_elementor_save_conditions( int $post_id, string $template_type, array $conditions_to_save ): void {
	update_post_meta( $post_id, '_elementor_conditions', $conditions_to_save );

	if ( '' === $template_type ) {
		return;
	}

	$theme_builder_conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
	if ( ! is_array( $theme_builder_conditions ) ) {
		$theme_builder_conditions = array();
	}
	if ( empty( $theme_builder_conditions[ $template_type ] ) || ! is_array( $theme_builder_conditions[ $template_type ] ) ) {
		$theme_builder_conditions[ $template_type ] = array();
	}
	$theme_builder_conditions[ $template_type ][ $post_id ] = $conditions_to_save;
	update_option( 'elementor_pro_theme_builder_conditions', $theme_builder_conditions );
}

/**
 * Check if a database table exists.
 *
 * @param string $table_name Table name.
 * @return bool
 */
function mcp_abilities_elementor_table_exists( string $table_name ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection query against plugin-managed table names.
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $found === $table_name;
}

/**
 * Get table column names.
 *
 * @param string $table_name Table name.
 * @return array
 */
function mcp_abilities_elementor_table_columns( string $table_name ): array {
	global $wpdb;

	$columns = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection query against plugin-managed table names.
	$results = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table_name ) . '`', ARRAY_A );

	if ( empty( $results ) ) {
		return $columns;
	}

	foreach ( $results as $row ) {
		if ( isset( $row['Field'] ) ) {
			$columns[] = $row['Field'];
		}
	}

	return $columns;
}

/**
 * Find a column name from candidate list.
 *
 * @param array $columns Column list.
 * @param array $candidates Candidate names.
 * @return string
 */
function mcp_abilities_elementor_find_column( array $columns, array $candidates ): string {
	if ( empty( $columns ) || empty( $candidates ) ) {
		return '';
	}

	$lookup = array();
	foreach ( $columns as $column ) {
		$lookup[ strtolower( (string) $column ) ] = (string) $column;
	}

	foreach ( $candidates as $candidate ) {
		$key = strtolower( (string) $candidate );
		if ( isset( $lookup[ $key ] ) ) {
			return $lookup[ $key ];
		}
	}

	return '';
}

/**
 * Register Elementor abilities.
 */
function mcp_abilities_elementor_register_abilities(): void {
	if ( ! mcp_abilities_elementor_check_dependencies() ) {
		return;
	}
	if ( ! mcp_abilities_elementor_is_active() ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Elementor</strong> requires the <a href="https://wordpress.org/plugins/elementor/">Elementor</a> plugin to be installed and activated.</p></div>';
		} );
		return;
	}

	mcp_abilities_elementor_register_document_abilities();
	mcp_abilities_elementor_register_design_abilities();
	mcp_abilities_elementor_register_element_lookup_abilities();
	mcp_abilities_elementor_register_template_abilities();
	mcp_abilities_elementor_register_pro_abilities();
	mcp_abilities_elementor_register_site_tool_abilities();

}
add_action( 'wp_enqueue_scripts', 'mcp_abilities_elementor_enqueue_frontend_runtime_when_needed', 5 );
add_action( 'elementor/frontend/after_register_scripts', 'mcp_abilities_elementor_enqueue_frontend_runtime_when_needed', 5 );
add_action( 'wp_head', 'mcp_abilities_elementor_print_frontend_config_when_needed', 1 );
add_action( 'wp_head', 'mcp_abilities_elementor_print_footer_scripts_early_when_needed', 999 );
add_action( 'wp_abilities_api_init', 'mcp_abilities_elementor_register_abilities' );
