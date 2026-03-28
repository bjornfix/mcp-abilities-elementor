<?php
/**
 * Plugin Name: MCP Abilities - Elementor
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-elementor
 * Description: Elementor abilities for MCP. Get, update, and patch Elementor page data. Manage templates and cache.
 * Version: 2.2.36
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Return the official Elementor guidance catalog used by design audits.
 *
 * Pattern and widget recommendations should be grounded in Elementor's own
 * documentation first. Site-local payloads are only fallback implementation
 * references when a recommendation has already been chosen from official docs.
 *
 * @return array
 */
function mcp_abilities_elementor_get_official_guidance_catalog(): array {
	return array(
		'policy'  => array(
			'pattern_source_of_truth'      => 'official_elementor_docs_first',
			'implementation_fallback_only' => 'site_local_payloads_after_pattern_choice',
			'description'                  => 'Use Elementor.com as the canonical source for widget/layout pattern recommendations. Inspect local Elementor payloads only after the official pattern choice is clear, and only to satisfy serialization or implementation details.',
		),
		'layout'  => array(
			'grid_for_symmetric_columns' => array(
				'label' => 'Grid for equal symmetric columns',
				'url'   => 'https://elementor.com/help/create-a-grid-container/',
			),
			'grid_vs_flex' => array(
				'label' => 'Grid vs Flex layout options',
				'url'   => 'https://elementor.com/help/grid-container-layout-options/',
			),
		),
		'widgets' => array(
			'accordion' => array(
				'label' => 'Accordion widget',
				'url'   => 'https://elementor.com/help/accordion-widget/',
			),
			'tabs' => array(
				'label' => 'Tabs widget with nested containers',
				'url'   => 'https://elementor.com/help/tabs-with-nested-containers/',
			),
			'call_to_action' => array(
				'label' => 'Call to Action widget',
				'url'   => 'https://elementor.com/help/call-to-action-widget/',
			),
			'icon_list' => array(
				'label' => 'Icon List widget',
				'url'   => 'https://elementor.com/help/icon-list-widget/',
			),
		),
	);
}

/**
 * Return which design topics are grounded in official Elementor docs versus
 * internal plugin heuristics.
 *
 * @return array
 */
function mcp_abilities_elementor_get_design_guidance_basis(): array {
	return array(
		'official_elementor_topics' => array(
			'layout_mechanism_fit',
			'native_widget_opportunities',
		),
		'plugin_heuristic_topics'   => array(
			'generic_layout',
			'distinctiveness',
			'component_overuse',
			'surface_overuse',
			'emphasis_drift',
			'section_rivalry',
			'composition_rhythm',
			'separator_discipline',
			'column_patterns',
			'column_dominance',
			'column_alignment',
			'column_balance',
			'column_necessity',
		),
		'description'               => 'Official Elementor.com guidance should drive widget/layout pattern choice where Elementor explicitly documents the mechanism or widget fit. The remaining design audits are plugin heuristics intended to review composition, pacing, emphasis, and repetition without pretending they are official Elementor rules.',
	);
}

/**
 * Normalize a widget name into a stable slug-like token.
 *
 * @param string $name Widget name.
 * @return string
 */
function mcp_abilities_elementor_normalize_widget_catalog_slug( string $name ): string {
	$slug = strtolower( trim( $name ) );
	$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug ) ?? $slug;
	$slug = trim( $slug, '-' );

	return $slug;
}

/**
 * Fetch the official Elementor widget catalog from elementor.com/widgets.
 *
 * This provides the canonical availability surface for Elementor widgets.
 * Per-widget help pages can still be layered on later, but the catalog itself
 * should come from Elementor's official widgets index rather than from our own
 * sites or a hand-maintained shortlist.
 *
 * @param bool $force_refresh Whether to bypass transient cache.
 * @return array|\WP_Error
 */
function mcp_abilities_elementor_fetch_official_widget_catalog( bool $force_refresh = false ) {
	$transient_key = 'mcp_elem_official_widget_catalog_v1';

	if ( ! $force_refresh ) {
		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) && ! empty( $cached['categories'] ) ) {
			return $cached;
		}
	}

	$response = wp_remote_get(
		'https://elementor.com/widgets',
		array(
			'timeout'     => 20,
			'redirection' => 5,
			'headers'     => array(
				'Accept' => 'text/html',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'widget_catalog_fetch_failed', 'Failed to fetch official Elementor widget catalog' );
	}

	$html = (string) wp_remote_retrieve_body( $response );
	if ( '' === trim( $html ) ) {
		return new WP_Error( 'widget_catalog_empty', 'Official Elementor widget catalog response was empty' );
	}

	$recognized_h2s = array(
		'Basic Widgets'       => 'basic',
		'Pro Widgets'         => 'pro',
		'Theme Elements'      => 'theme',
		'WooCommerce Widgets' => 'woocommerce',
	);
	$category_labels = array(
		'basic'       => 'Basic Widgets',
		'pro'         => 'Pro Widgets',
		'theme'       => 'Theme Elements',
		'woocommerce' => 'WooCommerce Widgets',
	);
	$categories = array(
		'basic'       => array(),
		'pro'         => array(),
		'theme'       => array(),
		'woocommerce' => array(),
	);

	if ( class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' ) ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return new WP_Error( 'widget_catalog_parse_failed', 'Failed to parse official Elementor widget catalog HTML' );
		}

		$xpath            = new DOMXPath( $dom );
		$current_category = '';
		$nodes            = $xpath->query( '//h2 | //h3' );
		if ( false === $nodes ) {
			return new WP_Error( 'widget_catalog_xpath_failed', 'Failed to inspect official Elementor widget catalog structure' );
		}

		foreach ( $nodes as $node ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) ?? '' );
			if ( '' === $text ) {
				continue;
			}

			if ( 'h2' === strtolower( $node->nodeName ) ) {
				$current_category = $recognized_h2s[ $text ] ?? '';
				continue;
			}

			if ( '' === $current_category || 'h3' !== strtolower( $node->nodeName ) ) {
				continue;
			}

			$categories[ $current_category ][ $text ] = array(
				'name'               => $text,
				'slug'               => mcp_abilities_elementor_normalize_widget_catalog_slug( $text ),
				'category'           => $current_category,
				'category_label'     => $category_labels[ $current_category ],
				'catalog_source_url' => 'https://elementor.com/widgets',
			);
		}
	} else {
		$heading_order = array_keys( $recognized_h2s );
		foreach ( $heading_order as $index => $heading_label ) {
			$current_category = $recognized_h2s[ $heading_label ];
			$pattern          = '#<h2[^>]*>\s*' . preg_quote( $heading_label, '#' ) . '\s*</h2>(.*?)(?=<h2[^>]*>|$)#si';

			if ( ! preg_match( $pattern, $html, $matches ) ) {
				continue;
			}

			$section_html = (string) ( $matches[1] ?? '' );
			if ( '' === $section_html ) {
				continue;
			}

			if ( preg_match_all( '#<h3[^>]*>(.*?)</h3>#si', $section_html, $heading_matches ) ) {
				foreach ( (array) ( $heading_matches[1] ?? array() ) as $raw_widget_name ) {
					$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $raw_widget_name ) ) ?? '' );
					if ( '' === $text ) {
						continue;
					}

					$categories[ $current_category ][ $text ] = array(
						'name'               => $text,
						'slug'               => mcp_abilities_elementor_normalize_widget_catalog_slug( $text ),
						'category'           => $current_category,
						'category_label'     => $category_labels[ $current_category ],
						'catalog_source_url' => 'https://elementor.com/widgets',
					);
				}
			}
		}
	}

	$normalized_categories = array();
	$total_widgets         = 0;
	foreach ( $categories as $key => $widgets ) {
		$normalized_categories[ $key ] = array_values( $widgets );
		$total_widgets += count( $normalized_categories[ $key ] );
	}

	$result = array(
		'source_policy'     => mcp_abilities_elementor_get_official_guidance_catalog()['policy'],
		'catalog_source_url'=> 'https://elementor.com/widgets',
		'fetched_at'        => gmdate( 'c' ),
		'total_widgets'     => $total_widgets,
		'categories'        => $normalized_categories,
	);

	set_transient( $transient_key, $result, 12 * HOUR_IN_SECONDS );

	return $result;
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

	if ( 'heading' === $widget_type ) {
		$tag   = is_string( $settings['header_size'] ?? null ) ? strtolower( $settings['header_size'] ) : 'h2';
		$style = is_array( $heading_scale[ $tag ] ?? null ) ? $heading_scale[ $tag ] : ( $heading_scale['default'] ?? array() );

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
		'elementor_cache_cleared' => false,
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

	if ( 'site' === $cache_scope ) {
		$site_cache_details = mcp_abilities_elementor_clear_site_cache();
		$details['elementor_cache_cleared'] = ! empty( $site_cache_details['elementor_cache_cleared'] );
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
		'nav-menu',
		'search-form',
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
	$query = new WP_Query(
		array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'tax_query'      => array(
				array(
					'taxonomy' => 'elementor_library_type',
					'field'    => 'slug',
					'terms'    => array( 'popup', 'header', 'footer', 'single', 'archive' ),
				),
			),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$issues = array();
	foreach ( $query->posts as $template_id ) {
		$template_id   = (int) $template_id;
		$template_type = (string) get_post_meta( $template_id, '_elementor_template_type', true );
		$title         = get_the_title( $template_id );

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

	if ( method_exists( $elementor->frontend, 'print_config' ) ) {
		$elementor->frontend->print_config();
		if ( has_action( 'wp_footer', array( $elementor->frontend, 'print_config' ) ) ) {
			remove_action( 'wp_footer', array( $elementor->frontend, 'print_config' ) );
		}
	}
}

/**
 * Print a direct Elementor core runtime fallback when script handles never materialize.
 *
 * Some canvas/front-page setups expose Elementor config and CSS but never emit
 * the core JS runtime handles. In that case, print the three core Elementor JS
 * runtime assets directly so interactive widgets can still bootstrap.
 *
 * @return void
 */
function mcp_abilities_elementor_print_frontend_script_fallback_when_needed(): void {
	$context = mcp_abilities_elementor_get_current_frontend_runtime_context();
	if ( empty( $context['needed'] ) ) {
		return;
	}

	$base_url = defined( 'ELEMENTOR_URL' ) ? trailingslashit( ELEMENTOR_URL ) : '';
	if ( '' === $base_url ) {
		return;
	}

	$version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
	$scripts = array(
		$base_url . 'assets/js/webpack.runtime.min.js',
		$base_url . 'assets/js/frontend-modules.min.js',
		$base_url . 'assets/js/frontend.min.js',
	);

	foreach ( $scripts as $src ) {
		$url = null === $version ? $src : add_query_arg( 'ver', $version, $src );
		printf(
			"<script src=\"%s\"></script>\n",
			esc_url( $url )
		);
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

	// =========================================================================
	// ELEMENTOR - Get Data
	// =========================================================================
	wp_register_ability(
		'elementor/get-data',
		array(
			'label'               => 'Get Elementor Data',
			'description'         => 'Retrieves the Elementor JSON data for a page or post. Returns the raw Elementor structure including containers, widgets, and settings.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to get Elementor data from.',
					),
					'format' => array(
						'type'        => 'string',
						'enum'        => array( 'array', 'json' ),
						'default'     => 'array',
						'description' => 'Return format: "array" for parsed PHP array, "json" for raw JSON string.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'title'         => array( 'type' => 'string' ),
					'edit_mode'     => array( 'type' => 'string' ),
					'data'          => array(
						'type'        => array( 'array', 'string' ),
						'description' => 'Elementor data as array or raw JSON string when format is "json".',
					),
					'page_settings' => array( 'type' => 'object' ),
					'message'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to access this post' );
				}

				$elementor_data = mcp_abilities_elementor_get_raw_data_meta( (int) $input['id'] );
				$edit_mode      = get_post_meta( $input['id'], '_elementor_edit_mode', true );
				$page_settings  = get_post_meta( $input['id'], '_elementor_page_settings', true );

				if ( empty( $elementor_data ) ) {
					return array(
						'success' => false,
						'id'      => $input['id'],
						'title'   => $post->post_title,
						'message' => 'No Elementor data found for this post',
					);
				}

				$format = $input['format'] ?? 'array';
				$decode_error = null;
				$data         = ( 'json' === $format ) ? $elementor_data : mcp_abilities_elementor_decode_data_meta( $elementor_data, $decode_error );
				$message      = 'Elementor data retrieved successfully';
				if ( null !== $decode_error ) {
					$message .= ' (data was invalid JSON and was normalized to an empty array)';
				}

				return array(
					'success'       => true,
					'id'            => $input['id'],
					'title'         => $post->post_title,
					'edit_mode'     => $edit_mode ?: 'not set',
					'data'          => $data,
					'page_settings' => $page_settings ?: array(),
					'message'       => $message,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Data
	// =========================================================================
	wp_register_ability(
		'elementor/update-data',
		array(
			'label'               => 'Update Elementor Data',
			'description'         => 'Updates the Elementor JSON data for a page or post and invalidates caches. Supports cache_scope (`post` default, `site` for stronger invalidation, `none` for debugging). Use with caution - invalid JSON will break the page.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'data' ),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to update.',
					),
					'data' => array(
						'type'        => 'array',
						'description' => 'Elementor data array (will be JSON encoded).',
					),
					'force_replace' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Allow destructive full-document replacement when the new page structure is empty or drastically smaller than the current one.',
					),
					'cache_scope' => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. `post` clears post-level caches and touches the post; `site` also clears Elementor site-wide cache; `none` skips cache invalidation.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
					'link'    => array( 'type' => 'string' ),
					'unchanged' => array( 'type' => 'boolean' ),
					'cache'   => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( ! isset( $input['data'] ) || ! is_array( $input['data'] ) ) {
					return array( 'success' => false, 'message' => 'Elementor data array is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$force_replace = ! empty( $input['force_replace'] );
				$existing_data = get_post_meta( $input['id'], '_elementor_data', true );
				$existing_tree = json_decode( $existing_data, true );
				if ( null === $existing_tree && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				if ( ! $force_replace ) {
					$existing_top_level = is_array( $existing_tree ) ? count( $existing_tree ) : 0;
					$new_top_level      = count( $input['data'] );

					if ( $existing_top_level > 0 && 0 === $new_top_level ) {
						return array( 'success' => false, 'message' => 'Refusing to replace populated Elementor document with empty data without force_replace=true' );
					}

					if ( $existing_top_level > 1 && $new_top_level < (int) ceil( $existing_top_level / 2 ) ) {
						return array( 'success' => false, 'message' => 'Refusing to drastically shrink Elementor document structure without force_replace=true' );
					}
				}

				$normalized_data = mcp_abilities_elementor_normalize_background_container_subtrees( $input['data'] );

				// Encode data to JSON.
				$json_data = wp_json_encode( $normalized_data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode data to JSON' );
				}

				$edit_mode     = get_post_meta( $input['id'], '_elementor_edit_mode', true );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				if ( is_string( $existing_data ) && $existing_data === $json_data && 'builder' === $edit_mode ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'   => true,
						'id'        => $input['id'],
						'message'   => 'Elementor data unchanged - no write performed',
						'link'      => get_permalink( $input['id'] ),
						'unchanged' => true,
						'cache'     => $cache_details,
					);
				}

				// Update Elementor data.
				update_post_meta( $input['id'], '_elementor_data', wp_slash( $json_data ) );

				// Ensure edit mode is set to builder.
				update_post_meta( $input['id'], '_elementor_edit_mode', 'builder' );

				$cache_details = mcp_abilities_elementor_invalidate_after_write(
					(int) $input['id'],
					$requested_cache_scope
				);

				$response = array(
					'success'   => true,
					'id'        => $input['id'],
					'message'   => 'Elementor data updated successfully',
					'link'      => get_permalink( $input['id'] ),
					'unchanged' => false,
					'cache'     => $cache_details,
				);

				return mcp_abilities_elementor_apply_frontend_runtime_guard(
					$response,
					(int) $input['id'],
					$normalized_data
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Clone Data
	// =========================================================================
	wp_register_ability(
		'elementor/clone-data',
		array(
			'label'               => 'Clone Elementor Data',
			'description'         => 'Clones Elementor data and page settings from a source page/template/post to a target page/post. Useful for reusing native Elementor structures without exporting/importing manually. Supports cache_scope (`post` default, `site` for stronger invalidation, `none` for debugging).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'source_id', 'target_id' ),
				'properties'           => array(
					'source_id' => array(
						'type'        => 'integer',
						'description' => 'Source post/page/template ID to clone Elementor data from.',
					),
					'target_id' => array(
						'type'        => 'integer',
						'description' => 'Target post/page ID to clone Elementor data to.',
					),
					'include_page_settings' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Also copy Elementor page settings from source to target.',
					),
					'cache_scope' => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. `post` clears post-level caches and touches the post; `site` also clears Elementor site-wide cache; `none` skips cache invalidation.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'source_id' => array( 'type' => 'integer' ),
					'target_id' => array( 'type' => 'integer' ),
					'message'   => array( 'type' => 'string' ),
					'link'      => array( 'type' => 'string' ),
					'unchanged' => array( 'type' => 'boolean' ),
					'cache'     => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['source_id'] ) || empty( $input['target_id'] ) ) {
					return array( 'success' => false, 'message' => 'Source ID and target ID are required' );
				}

				$source = get_post( (int) $input['source_id'] );
				$target = get_post( (int) $input['target_id'] );
				if ( ! $source || ! $target ) {
					return array( 'success' => false, 'message' => 'Source or target post not found' );
				}

				if ( ! current_user_can( 'edit_post', $source->ID ) || ! current_user_can( 'edit_post', $target->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to clone Elementor data between these posts' );
				}

				$source_data_raw = mcp_abilities_elementor_get_raw_data_meta( $source->ID );
				if ( '' === $source_data_raw ) {
					return array(
						'success'   => false,
						'source_id' => $source->ID,
						'target_id' => $target->ID,
						'message'   => 'No Elementor data found on source post',
					);
				}

				$decode_error = null;
				$source_data  = mcp_abilities_elementor_decode_data_meta( $source_data_raw, $decode_error );
				if ( null !== $decode_error ) {
					return array(
						'success'   => false,
						'source_id' => $source->ID,
						'target_id' => $target->ID,
						'message'   => 'Source Elementor data is invalid JSON: ' . $decode_error,
					);
				}

				$normalized_source_data = mcp_abilities_elementor_normalize_background_container_subtrees( $source_data );
				$json_data              = wp_json_encode( $normalized_source_data );
				if ( false === $json_data ) {
					return array(
						'success'   => false,
						'source_id' => $source->ID,
						'target_id' => $target->ID,
						'message'   => 'Failed to encode source Elementor data to JSON',
					);
				}

				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				$existing_target_data  = mcp_abilities_elementor_get_raw_data_meta( $target->ID );
				$include_page_settings = ! array_key_exists( 'include_page_settings', $input ) || ! empty( $input['include_page_settings'] );
				$source_page_settings  = get_post_meta( $source->ID, '_elementor_page_settings', true );
				$target_page_settings  = get_post_meta( $target->ID, '_elementor_page_settings', true );

				$page_settings_match = true;
				if ( $include_page_settings ) {
					$page_settings_match = $source_page_settings == $target_page_settings;
				}

				if ( is_string( $existing_target_data ) && $existing_target_data === $json_data && $page_settings_match ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $target->ID;
					return array(
						'success'   => true,
						'source_id' => $source->ID,
						'target_id' => $target->ID,
						'message'   => 'Target already matches source Elementor data - no write performed',
						'link'      => get_permalink( $target->ID ),
						'unchanged' => true,
						'cache'     => $cache_details,
					);
				}

				update_post_meta( $target->ID, '_elementor_data', wp_slash( $json_data ) );
				update_post_meta( $target->ID, '_elementor_edit_mode', 'builder' );

				if ( $include_page_settings ) {
					update_post_meta( $target->ID, '_elementor_page_settings', $source_page_settings ?: array() );
				}

				$cache_details = mcp_abilities_elementor_invalidate_after_write(
					(int) $target->ID,
					$requested_cache_scope
				);

				$response = array(
					'success'   => true,
					'source_id' => $source->ID,
					'target_id' => $target->ID,
					'message'   => 'Elementor data cloned successfully',
					'link'      => get_permalink( $target->ID ),
					'unchanged' => false,
					'cache'     => $cache_details,
				);

				return mcp_abilities_elementor_apply_frontend_runtime_guard(
					$response,
					(int) $target->ID,
					$normalized_source_data
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Patch Data (Find & Replace in JSON)
	// =========================================================================
	wp_register_ability(
		'elementor/patch-data',
		array(
			'label'               => 'Patch Elementor Data',
			'description'         => 'Performs find-and-replace operations within Elementor JSON data. Works on the raw JSON string, so you can replace text, URLs, settings values, etc. Supports cache_scope (`post` default, `site` for stronger invalidation, `none` for debugging).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'find', 'replace' ),
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to patch.',
					),
					'find'    => array(
						'type'        => 'string',
						'description' => 'String to find in the Elementor JSON.',
					),
					'replace' => array(
						'type'        => 'string',
						'description' => 'Replacement string.',
					),
					'regex'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, treat "find" as a regex pattern.',
					),
					'cache_scope' => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. `post` clears post-level caches and touches the post; `site` also clears Elementor site-wide cache; `none` skips cache invalidation.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
					'message'      => array( 'type' => 'string' ),
					'link'         => array( 'type' => 'string' ),
					'cache'        => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( ! isset( $input['find'] ) || '' === $input['find'] ) {
					return array( 'success' => false, 'message' => 'Find string is required' );
				}
				if ( ! isset( $input['replace'] ) ) {
					return array( 'success' => false, 'message' => 'Replace string is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$find      = $input['find'];
				$replace   = $input['replace'];
				$use_regex = ! empty( $input['regex'] );
				$count     = 0;

				if ( $use_regex ) {
					$new_data = preg_replace( $find, $replace, $elementor_data, -1, $count );
					if ( null === $new_data ) {
						return array( 'success' => false, 'message' => 'Invalid regex pattern' );
					}
				} else {
					$new_data = str_replace( $find, $replace, $elementor_data, $count );
				}

				if ( 0 === $count ) {
					$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'      => true,
						'id'           => $input['id'],
						'replacements' => 0,
						'message'      => 'No matches found - Elementor data unchanged',
						'link'         => get_permalink( $input['id'] ),
						'cache'        => $cache_details,
					);
				}

				// Validate that result is still valid JSON.
				$test_decode = json_decode( $new_data, true );
				if ( null === $test_decode && json_last_error() !== JSON_ERROR_NONE ) {
					return array( 'success' => false, 'message' => 'Replacement would result in invalid JSON - aborted' );
				}

				$normalized_data = mcp_abilities_elementor_normalize_background_container_subtrees( $test_decode );
				$normalized_json = wp_json_encode( $normalized_data );
				if ( false === $normalized_json ) {
					return array( 'success' => false, 'message' => 'Replacement produced valid JSON but failed to re-encode after normalization' );
				}

				// Update Elementor data.
				update_post_meta( $input['id'], '_elementor_data', wp_slash( $normalized_json ) );

				$cache_details = mcp_abilities_elementor_invalidate_after_write(
					(int) $input['id'],
					mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' )
				);

				$response = array(
					'success'      => true,
					'id'           => $input['id'],
					'replacements' => $count,
					'message'      => "Successfully replaced {$count} occurrence(s) in Elementor data",
					'link'         => get_permalink( $input['id'] ),
					'cache'        => $cache_details,
				);

				return mcp_abilities_elementor_apply_frontend_runtime_guard(
					$response,
					(int) $input['id'],
					$normalized_data
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Element (targeted container/widget replacement)
	// =========================================================================
	wp_register_ability(
		'elementor/update-element',
		array(
			'label'               => 'Update Elementor Element',
			'description'         => 'Replaces a specific element (container or widget) by ID within the Elementor page structure. Useful for targeted updates without re-uploading the entire page. Supports cache_scope (`post` default, `site` for stronger invalidation, `none` for debugging).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id', 'element_data' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the element.',
					),
					'element_id'   => array(
						'type'        => 'string',
						'description' => 'The ID of the element to replace (e.g., "col1", "hero_section").',
					),
					'element_data' => array(
						'type'        => 'object',
						'description' => 'The new element data to replace it with. Must include "id", "elType", and other required Elementor fields.',
					),
					'cache_scope'  => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. `post` clears post-level caches and touches the post; `site` also clears Elementor site-wide cache; `none` skips cache invalidation.',
					),
					'force_replace' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Allow destructive full replacement when the new payload changes element shape (for example container/widget type changes or replacing a populated container with an empty one).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'message'    => array( 'type' => 'string' ),
					'link'       => array( 'type' => 'string' ),
					'unchanged'  => array( 'type' => 'boolean' ),
					'cache'      => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}
				if ( ! isset( $input['element_data'] ) || ! is_array( $input['element_data'] ) ) {
					return array( 'success' => false, 'message' => 'Element data object is required' );
				}
				if ( empty( $input['element_data']['id'] ) || ! is_string( $input['element_data']['id'] ) ) {
					return array( 'success' => false, 'message' => 'Element data must include a string "id"' );
				}
				if ( empty( $input['element_data']['elType'] ) || ! is_string( $input['element_data']['elType'] ) ) {
					return array( 'success' => false, 'message' => 'Element data must include a string "elType"' );
				}
				if ( $input['element_data']['id'] !== $input['element_id'] ) {
					return array( 'success' => false, 'message' => 'Element data "id" must match the target element_id' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$force_replace = ! empty( $input['force_replace'] );

				// Recursive function to find an element by ID.
				$original_element = null;
				$find_element     = function ( $elements, $target_id ) use ( &$find_element, &$original_element ) {
					if ( ! is_array( $elements ) ) {
						return false;
					}
					foreach ( $elements as $element ) {
						if ( isset( $element['id'] ) && $element['id'] === $target_id ) {
							$original_element = $element;
							return true;
						}
						if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
							if ( $find_element( $element['elements'], $target_id ) ) {
								return true;
							}
						}
					}
					return false;
				};

				$find_element( $data, $input['element_id'] );

				if ( ! is_array( $original_element ) ) {
					return array(
						'success'    => false,
						'id'         => $input['id'],
						'element_id' => $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				if ( ! $force_replace ) {
					if ( ( $original_element['elType'] ?? null ) !== $input['element_data']['elType'] ) {
						return array(
							'success'    => false,
							'id'         => $input['id'],
							'element_id' => $input['element_id'],
							'message'    => 'Refusing to replace element with different elType without force_replace=true',
						);
					}

					if ( 'widget' === ( $original_element['elType'] ?? null ) ) {
						$original_widget_type = $original_element['widgetType'] ?? null;
						$new_widget_type      = $input['element_data']['widgetType'] ?? null;
						if ( empty( $new_widget_type ) || $original_widget_type !== $new_widget_type ) {
							return array(
								'success'    => false,
								'id'         => $input['id'],
								'element_id' => $input['element_id'],
								'message'    => 'Refusing to replace widget with different or missing widgetType without force_replace=true',
							);
						}
					}

					$original_children = isset( $original_element['elements'] ) && is_array( $original_element['elements'] ) ? $original_element['elements'] : array();
					$new_has_elements  = array_key_exists( 'elements', $input['element_data'] );
					$new_children      = $new_has_elements && is_array( $input['element_data']['elements'] ) ? $input['element_data']['elements'] : null;
					if ( ! empty( $original_children ) && ( ! $new_has_elements || ! is_array( $new_children ) || 0 === count( $new_children ) ) ) {
						return array(
							'success'    => false,
							'id'         => $input['id'],
							'element_id' => $input['element_id'],
							'message'    => 'Refusing to replace populated container/element with empty or missing children without force_replace=true',
						);
					}
				}

				$normalized_element = mcp_abilities_elementor_normalize_background_container_element(
					$input['element_data'],
					$original_element
				);

				// Recursive function to find and replace element by ID.
				$found = false;
				$replace_element = function ( &$elements, $target_id, $new_element ) use ( &$replace_element, &$found ) {
					foreach ( $elements as $index => &$element ) {
						if ( isset( $element['id'] ) && $element['id'] === $target_id ) {
							$elements[ $index ] = $new_element;
							$found = true;
							return true;
						}
						if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
							if ( $replace_element( $element['elements'], $target_id, $new_element ) ) {
								return true;
							}
						}
					}
					return false;
				};

				$replace_element( $data, $input['element_id'], $normalized_element );

				if ( ! $found ) {
					return array(
						'success'    => false,
						'id'         => $input['id'],
						'element_id' => $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				$data = mcp_abilities_elementor_normalize_background_container_subtrees( $data );

				// Encode and save.
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				if ( is_string( $elementor_data ) && $elementor_data === $json_data ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'    => true,
						'id'         => $input['id'],
						'element_id' => $input['element_id'],
						'message'    => 'Element update produced no change - no write performed',
						'link'       => get_permalink( $input['id'] ),
						'unchanged'  => true,
						'cache'      => $cache_details,
					);
				}

				update_post_meta( $input['id'], '_elementor_data', wp_slash( $json_data ) );

				$cache_details = mcp_abilities_elementor_invalidate_after_write(
					(int) $input['id'],
					$requested_cache_scope
				);

				$response = array(
					'success'    => true,
					'id'         => $input['id'],
					'element_id' => $input['element_id'],
					'message'    => 'Element "' . $input['element_id'] . '" updated successfully',
					'link'       => get_permalink( $input['id'] ),
					'unchanged'  => false,
					'cache'      => $cache_details,
				);

				return mcp_abilities_elementor_apply_frontend_runtime_guard(
					$response,
					(int) $input['id'],
					$data
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Merge Element Settings
	// =========================================================================
	wp_register_ability(
		'elementor/merge-element-settings',
		array(
			'label'               => 'Merge Elementor Element Settings',
			'description'         => 'Deep-merges one or more settings into an existing Elementor element without requiring a full element replacement payload. Supports cache_scope (`post` default, `site` for stronger invalidation, `none` for debugging) and `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id', 'settings' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the element.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Element ID to update.',
					),
					'settings'   => array(
						'type'        => 'object',
						'description' => 'Settings to merge into the element settings array.',
					),
					'dry_run'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the merged result without writing to the database.',
					),
					'cache_scope' => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'integer' ),
					'element_id'  => array( 'type' => 'string' ),
					'message'     => array( 'type' => 'string' ),
					'link'        => array( 'type' => 'string' ),
					'dry_run'     => array( 'type' => 'boolean' ),
					'unchanged'   => array( 'type' => 'boolean' ),
					'settings'    => array( 'type' => 'object' ),
					'cache'       => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}
				if ( ! isset( $input['settings'] ) || ! is_array( $input['settings'] ) ) {
					return array( 'success' => false, 'message' => 'Settings object is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
				if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
					return array(
						'success'    => false,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				$original_element            = $element_meta['element'];
				$merged_element              = $original_element;
				$existing_settings           = is_array( $original_element['settings'] ?? null ) ? $original_element['settings'] : array();
				$merged_element['settings']  = mcp_abilities_elementor_merge_settings( $existing_settings, $input['settings'] );
				$merged_element              = mcp_abilities_elementor_normalize_background_container_element( $merged_element, $original_element );
				$requested_cache_scope       = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				$dry_run                     = ! empty( $input['dry_run'] );

				if ( $merged_element === $original_element ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'    => true,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'message'    => 'Settings merge produced no change',
						'link'       => get_permalink( (int) $input['id'] ),
						'dry_run'    => $dry_run,
						'unchanged'  => true,
						'settings'   => $merged_element['settings'],
						'cache'      => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'    => true,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'message'    => 'Dry run: settings merged successfully',
						'link'       => get_permalink( (int) $input['id'] ),
						'dry_run'    => true,
						'unchanged'  => false,
						'settings'   => $merged_element['settings'],
						'cache'      => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['element_id'], $merged_element );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'    => true,
					'id'         => (int) $input['id'],
					'element_id' => (string) $input['element_id'],
					'message'    => 'Element settings merged successfully',
					'link'       => get_permalink( (int) $input['id'] ),
					'dry_run'    => false,
					'unchanged'  => false,
					'settings'   => $merged_element['settings'],
					'cache'      => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Zero Container Padding In Subtree
	// =========================================================================
	wp_register_ability(
		'elementor/zero-container-padding-subtree',
		array(
			'label'               => 'Zero Container Padding In Subtree',
			'description'         => 'Recursively sets container padding to zero in an Elementor subtree. Useful when hidden default padding is causing lane and width drift. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the root element.',
					),
					'element_id'   => array(
						'type'        => 'string',
						'description' => 'Root element ID for the subtree.',
					),
					'include_root' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, zero padding on the root container too.',
					),
					'max_depth'    => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum descendant depth to touch. Use -1 for unlimited.',
					),
					'dry_run'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the would-change IDs without writing.',
					),
					'cache_scope'  => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'id'              => array( 'type' => 'integer' ),
					'element_id'      => array( 'type' => 'string' ),
					'message'         => array( 'type' => 'string' ),
					'link'            => array( 'type' => 'string' ),
					'dry_run'         => array( 'type' => 'boolean' ),
					'changed_count'   => array( 'type' => 'integer' ),
					'changed_ids'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'           => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
				if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
					return array(
						'success'    => false,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				$changed_ids    = array();
				$max_depth      = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$include_root   = ! array_key_exists( 'include_root', $input ) || ! empty( $input['include_root'] );
				$updated_element = mcp_abilities_elementor_zero_container_padding_subtree(
					$element_meta['element'],
					$include_root,
					$max_depth,
					0,
					$changed_ids
				);
				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );
				$dry_run     = ! empty( $input['dry_run'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );

				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'No container padding changes were needed',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => $dry_run,
						'changed_count' => 0,
						'changed_ids'   => array(),
						'cache'         => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'Dry run: container padding normalization prepared',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => true,
						'changed_count' => count( $changed_ids ),
						'changed_ids'   => $changed_ids,
						'cache'         => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['element_id'], $updated_element );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'       => true,
					'id'            => (int) $input['id'],
					'element_id'    => (string) $input['element_id'],
					'message'       => 'Container padding normalized successfully',
					'link'          => get_permalink( (int) $input['id'] ),
					'dry_run'       => false,
					'changed_count' => count( $changed_ids ),
					'changed_ids'   => $changed_ids,
					'cache'         => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Copy Lane Settings
	// =========================================================================
	wp_register_ability(
		'elementor/copy-lane-settings',
		array(
			'label'               => 'Copy Elementor Lane Settings',
			'description'         => 'Copies lane-defining layout settings from one Elementor element to another. Default keys are `content_width`, `boxed_width`, and `flex_gap`. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'source_element_id', 'target_element_id' ),
				'properties'           => array(
					'id'                => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing both elements.',
					),
					'source_element_id' => array(
						'type'        => 'string',
						'description' => 'Element ID to copy lane settings from.',
					),
					'target_element_id' => array(
						'type'        => 'string',
						'description' => 'Element ID to copy lane settings to.',
					),
					'setting_keys'      => array(
						'type'        => 'array',
						'description' => 'Optional list of setting keys to copy. Defaults to content_width, boxed_width, flex_gap.',
						'items'       => array( 'type' => 'string' ),
					),
					'dry_run'           => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the copied settings without writing.',
					),
					'cache_scope'       => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'           => array( 'type' => 'boolean' ),
					'id'                => array( 'type' => 'integer' ),
					'source_element_id' => array( 'type' => 'string' ),
					'target_element_id' => array( 'type' => 'string' ),
					'message'           => array( 'type' => 'string' ),
					'link'              => array( 'type' => 'string' ),
					'dry_run'           => array( 'type' => 'boolean' ),
					'unchanged'         => array( 'type' => 'boolean' ),
					'setting_keys'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'target_settings'   => array( 'type' => 'object' ),
					'cache'             => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['source_element_id'] ) || empty( $input['target_element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Both source_element_id and target_element_id are required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$source_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['source_element_id'] );
				$target_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['target_element_id'] );

				if ( ! is_array( $source_meta ) || ! is_array( $source_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Source element not found' );
				}
				if ( ! is_array( $target_meta ) || ! is_array( $target_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Target element not found' );
				}

				$setting_keys = isset( $input['setting_keys'] ) && is_array( $input['setting_keys'] ) && ! empty( $input['setting_keys'] )
					? array_values( array_filter( $input['setting_keys'], 'is_string' ) )
					: array( 'content_width', 'boxed_width', 'flex_gap' );

				$target_element = mcp_abilities_elementor_copy_lane_settings(
					$target_meta['element'],
					$source_meta['element'],
					$setting_keys
				);
				$target_element = mcp_abilities_elementor_normalize_background_container_element( $target_element, $target_meta['element'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				$dry_run = ! empty( $input['dry_run'] );

				if ( $target_element === $target_meta['element'] ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'           => true,
						'id'                => (int) $input['id'],
						'source_element_id' => (string) $input['source_element_id'],
						'target_element_id' => (string) $input['target_element_id'],
						'message'           => 'Lane copy produced no change',
						'link'              => get_permalink( (int) $input['id'] ),
						'dry_run'           => $dry_run,
						'unchanged'         => true,
						'setting_keys'      => $setting_keys,
						'target_settings'   => $target_element['settings'] ?? array(),
						'cache'             => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'           => true,
						'id'                => (int) $input['id'],
						'source_element_id' => (string) $input['source_element_id'],
						'target_element_id' => (string) $input['target_element_id'],
						'message'           => 'Dry run: lane settings copied successfully',
						'link'              => get_permalink( (int) $input['id'] ),
						'dry_run'           => true,
						'unchanged'         => false,
						'setting_keys'      => $setting_keys,
						'target_settings'   => $target_element['settings'] ?? array(),
						'cache'             => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['target_element_id'], $target_element );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'           => true,
					'id'                => (int) $input['id'],
					'source_element_id' => (string) $input['source_element_id'],
					'target_element_id' => (string) $input['target_element_id'],
					'message'           => 'Lane settings copied successfully',
					'link'              => get_permalink( (int) $input['id'] ),
					'dry_run'           => false,
					'unchanged'         => false,
					'setting_keys'      => $setting_keys,
					'target_settings'   => $target_element['settings'] ?? array(),
					'cache'             => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Copy Row Balance
	// =========================================================================
	wp_register_ability(
		'elementor/copy-row-balance',
		array(
			'label'               => 'Copy Elementor Row Balance',
			'description'         => 'Copies balance-defining settings from one Elementor row/container to another. Copies row gap settings and mirrors direct-child width/flex/padding settings by index so image/card rows keep the same visual rhythm. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'source_element_id', 'target_element_id' ),
				'properties'           => array(
					'id'                => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing both rows.',
					),
					'source_element_id' => array(
						'type'        => 'string',
						'description' => 'Row/container ID to copy the visual balance from.',
					),
					'target_element_id' => array(
						'type'        => 'string',
						'description' => 'Row/container ID to copy the visual balance to.',
					),
					'row_setting_keys'  => array(
						'type'        => 'array',
						'description' => 'Optional row-level keys to copy. Defaults to flex_gap.',
						'items'       => array( 'type' => 'string' ),
					),
					'child_setting_keys' => array(
						'type'        => 'array',
						'description' => 'Optional direct-child keys to copy. Defaults to width/flex-basis/padding for balanced columns.',
						'items'       => array( 'type' => 'string' ),
					),
					'allow_partial'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, copy only across the overlapping direct-child count when the rows have different child counts.',
					),
					'dry_run'           => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the prepared balance changes without writing.',
					),
					'cache_scope'       => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'           => array( 'type' => 'boolean' ),
					'id'                => array( 'type' => 'integer' ),
					'source_element_id' => array( 'type' => 'string' ),
					'target_element_id' => array( 'type' => 'string' ),
					'message'           => array( 'type' => 'string' ),
					'link'              => array( 'type' => 'string' ),
					'dry_run'           => array( 'type' => 'boolean' ),
					'unchanged'         => array( 'type' => 'boolean' ),
					'row_setting_keys'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'child_setting_keys'=> array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'changed_child_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'target_settings'   => array( 'type' => 'object' ),
					'cache'             => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['source_element_id'] ) || empty( $input['target_element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Both source_element_id and target_element_id are required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$source_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['source_element_id'] );
				$target_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['target_element_id'] );

				if ( ! is_array( $source_meta ) || ! is_array( $source_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Source element not found' );
				}
				if ( ! is_array( $target_meta ) || ! is_array( $target_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Target element not found' );
				}

				$row_setting_keys = isset( $input['row_setting_keys'] ) && is_array( $input['row_setting_keys'] ) && ! empty( $input['row_setting_keys'] )
					? array_values( array_filter( $input['row_setting_keys'], 'is_string' ) )
					: array( 'flex_gap' );
				$child_setting_keys = isset( $input['child_setting_keys'] ) && is_array( $input['child_setting_keys'] ) && ! empty( $input['child_setting_keys'] )
					? array_values( array_filter( $input['child_setting_keys'], 'is_string' ) )
					: array( 'width', 'width_tablet', 'width_mobile', 'flex_basis', 'flex_basis_tablet', 'flex_basis_mobile', 'padding', 'padding_tablet', 'padding_mobile' );
				$allow_partial       = ! empty( $input['allow_partial'] );
				$dry_run             = ! empty( $input['dry_run'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );

				$source_children = is_array( $source_meta['element']['elements'] ?? null ) ? array_values( $source_meta['element']['elements'] ) : array();
				$target_children = is_array( $target_meta['element']['elements'] ?? null ) ? array_values( $target_meta['element']['elements'] ) : array();

				if ( count( $source_children ) !== count( $target_children ) && ! $allow_partial ) {
					return array(
						'success'           => false,
						'id'                => (int) $input['id'],
						'source_element_id' => (string) $input['source_element_id'],
						'target_element_id' => (string) $input['target_element_id'],
						'message'           => 'Source and target rows have different direct-child counts; set allow_partial=true to copy only the overlapping children',
					);
				}

				$changed_child_ids = array();
				$target_element    = mcp_abilities_elementor_copy_row_balance(
					$target_meta['element'],
					$source_meta['element'],
					$row_setting_keys,
					$child_setting_keys,
					$allow_partial,
					$changed_child_ids
				);
				$target_element = mcp_abilities_elementor_normalize_background_container_element( $target_element, $target_meta['element'] );

				if ( $target_element === $target_meta['element'] ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'           => true,
						'id'                => (int) $input['id'],
						'source_element_id' => (string) $input['source_element_id'],
						'target_element_id' => (string) $input['target_element_id'],
						'message'           => 'Row balance copy produced no change',
						'link'              => get_permalink( (int) $input['id'] ),
						'dry_run'           => $dry_run,
						'unchanged'         => true,
						'row_setting_keys'  => $row_setting_keys,
						'child_setting_keys'=> $child_setting_keys,
						'changed_child_ids' => array_values( array_unique( array_filter( $changed_child_ids ) ) ),
						'target_settings'   => $target_element['settings'] ?? array(),
						'cache'             => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'           => true,
						'id'                => (int) $input['id'],
						'source_element_id' => (string) $input['source_element_id'],
						'target_element_id' => (string) $input['target_element_id'],
						'message'           => 'Dry run: row balance copied successfully',
						'link'              => get_permalink( (int) $input['id'] ),
						'dry_run'           => true,
						'unchanged'         => false,
						'row_setting_keys'  => $row_setting_keys,
						'child_setting_keys'=> $child_setting_keys,
						'changed_child_ids' => array_values( array_unique( array_filter( $changed_child_ids ) ) ),
						'target_settings'   => $target_element['settings'] ?? array(),
						'cache'             => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['target_element_id'], $target_element );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'           => true,
					'id'                => (int) $input['id'],
					'source_element_id' => (string) $input['source_element_id'],
					'target_element_id' => (string) $input['target_element_id'],
					'message'           => 'Row balance copied successfully',
					'link'              => get_permalink( (int) $input['id'] ),
					'dry_run'           => false,
					'unchanged'         => false,
					'row_setting_keys'  => $row_setting_keys,
					'child_setting_keys'=> $child_setting_keys,
					'changed_child_ids' => array_values( array_unique( array_filter( $changed_child_ids ) ) ),
					'target_settings'   => $target_element['settings'] ?? array(),
					'cache'             => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Normalize Campaign Detail Page
	// =========================================================================
	wp_register_ability(
		'elementor/normalize-campaign-detail-page',
		array(
			'label'               => 'Normalize Elementor Campaign Detail Page',
			'description'         => 'Applies the standard migrated campaign-detail layout recipe to a page: `1140px` lane, zero hidden left/right gutters, `18px` row rhythm, full-width feature image, and a widened `OM GARDEROBEMEKKA` block. Use on price-example/campaign-detail pages after the page structure already exists. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'elements' ),
				'properties'           => array(
					'id'              => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to normalize.',
					),
					'elements'        => array(
						'type'                 => 'object',
						'description'          => 'Element IDs for the standard campaign-detail layout zones.',
						'properties'           => array(
							'hero_inner'      => array( 'type' => 'string' ),
							'offer'           => array( 'type' => 'string' ),
							'body'            => array( 'type' => 'string' ),
							'body_inner'      => array( 'type' => 'string' ),
							'cta_wrap'        => array( 'type' => 'string' ),
							'gallery'         => array( 'type' => 'string' ),
							'gallery_row'     => array( 'type' => 'string' ),
							'gallery_columns' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'feature_wrap'    => array( 'type' => 'string' ),
							'feature_image'   => array( 'type' => 'string' ),
							'about'           => array( 'type' => 'string' ),
							'about_text_wrap' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
					'lane_width'      => array(
						'type'        => 'integer',
						'default'     => 1140,
						'description' => 'Standard boxed lane width.',
					),
					'rhythm_gap'      => array(
						'type'        => 'integer',
						'default'     => 18,
						'description' => 'Standard row rhythm in pixels.',
					),
					'about_padding'   => array(
						'type'        => 'integer',
						'default'     => 64,
						'description' => 'Left/right inner padding for the about block in pixels.',
					),
					'dry_run'         => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the elements that would change without writing.',
					),
					'cache_scope'     => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'message'       => array( 'type' => 'string' ),
					'link'          => array( 'type' => 'string' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'changed_count' => array( 'type' => 'integer' ),
					'changed_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'skipped_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'         => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['elements'] ) || ! is_array( $input['elements'] ) ) {
					return array( 'success' => false, 'message' => 'elements object is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$lane_width      = isset( $input['lane_width'] ) ? (int) $input['lane_width'] : 1140;
				$rhythm_gap      = isset( $input['rhythm_gap'] ) ? (int) $input['rhythm_gap'] : 18;
				$about_padding   = isset( $input['about_padding'] ) ? (int) $input['about_padding'] : 64;
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				$dry_run         = ! empty( $input['dry_run'] );
				$changed_ids     = array();
				$skipped_ids     = array();
				$elements        = $input['elements'];

				$apply_merge = function ( string $element_id, array $settings ) use ( &$data, &$changed_ids, &$skipped_ids ): void {
					if ( '' === $element_id ) {
						return;
					}

					$element_meta = mcp_abilities_elementor_find_element_meta( $data, $element_id );
					if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
						$skipped_ids[] = $element_id;
						return;
					}

					$original_data = $data;
					$data          = mcp_abilities_elementor_merge_settings_into_tree( $data, $element_id, $settings );

					if ( $data !== $original_data ) {
						$changed_ids[] = $element_id;
					}
				};

				$zero_padding = array(
					'unit'     => 'px',
					'top'      => '0',
					'right'    => '0',
					'bottom'   => '0',
					'left'     => '0',
					'isLinked' => false,
				);
				$rhythm_box = array(
					'unit'   => 'px',
					'column' => (string) $rhythm_gap,
					'row'    => (string) $rhythm_gap,
					'size'   => $rhythm_gap,
				);

				$apply_merge(
					(string) ( $elements['hero_inner'] ?? '' ),
					array(
						'boxed_width' => array(
							'unit' => 'px',
							'size' => $lane_width,
						),
						'padding'     => $zero_padding,
					)
				);
				$apply_merge(
					(string) ( $elements['offer'] ?? '' ),
					array(
						'boxed_width' => array(
							'unit' => 'px',
							'size' => $lane_width,
						),
						'padding'     => array(
							'unit'     => 'px',
							'top'      => '60',
							'right'    => '0',
							'bottom'   => '60',
							'left'     => '0',
							'isLinked' => false,
						),
						'flex_gap'    => $rhythm_box,
						'css_classes' => 'e-no-lazyload',
					)
				);
				$apply_merge( (string) ( $elements['body'] ?? '' ), array( 'padding' => array_merge( $zero_padding, array( 'bottom' => '30' ) ) ) );
				$apply_merge(
					(string) ( $elements['body_inner'] ?? '' ),
					array(
						'boxed_width' => array(
							'unit' => 'px',
							'size' => $lane_width,
						),
						'padding'     => $zero_padding,
					)
				);
				$apply_merge( (string) ( $elements['cta_wrap'] ?? '' ), array( 'flex_gap' => $rhythm_box ) );
				$apply_merge(
					(string) ( $elements['gallery'] ?? '' ),
					array(
						'boxed_width' => array(
							'unit' => 'px',
							'size' => $lane_width,
						),
						'padding'     => array_merge( $zero_padding, array( 'bottom' => '50' ) ),
						'flex_gap'    => $rhythm_box,
					)
				);
				$apply_merge( (string) ( $elements['gallery_row'] ?? '' ), array( 'padding' => $zero_padding, 'flex_gap' => $rhythm_box ) );

				if ( ! empty( $elements['gallery_columns'] ) && is_array( $elements['gallery_columns'] ) ) {
					foreach ( $elements['gallery_columns'] as $gallery_column_id ) {
						if ( is_string( $gallery_column_id ) ) {
							$apply_merge( $gallery_column_id, array( 'padding' => $zero_padding ) );
						}
					}
				}

				$apply_merge( (string) ( $elements['feature_wrap'] ?? '' ), array( 'padding' => $zero_padding ) );
				$apply_merge(
					(string) ( $elements['feature_image'] ?? '' ),
					array(
						'width' => array(
							'unit' => '%',
							'size' => 100,
						),
						'align' => 'stretch',
					)
				);
				$apply_merge( (string) ( $elements['about'] ?? '' ), array( 'padding' => array_merge( $zero_padding, array( 'bottom' => '80' ) ) ) );
				$apply_merge(
					(string) ( $elements['about_text_wrap'] ?? '' ),
					array(
						'boxed_width'           => array(
							'unit' => 'px',
							'size' => $lane_width,
						),
						'width'                 => array(
							'unit' => '%',
							'size' => 100,
						),
						'width_mobile'          => array(
							'unit' => '%',
							'size' => 100,
						),
						'padding'               => array(
							'unit'     => 'px',
							'top'      => '60',
							'right'    => (string) $about_padding,
							'bottom'   => '60',
							'left'     => (string) $about_padding,
							'isLinked' => false,
						),
						'background_background' => 'classic',
						'background_color'      => '#F5F5F5',
					)
				);

				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );
				$skipped_ids = array_values( array_unique( array_filter( $skipped_ids ) ) );

				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'message'       => 'Campaign-detail normalization produced no change',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => $dry_run,
						'changed_count' => 0,
						'changed_ids'   => array(),
						'skipped_ids'   => $skipped_ids,
						'cache'         => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'message'       => 'Dry run: campaign-detail normalization prepared successfully',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => true,
						'changed_count' => count( $changed_ids ),
						'changed_ids'   => $changed_ids,
						'skipped_ids'   => $skipped_ids,
						'cache'         => $cache_details,
					);
				}

				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'       => true,
					'id'            => (int) $input['id'],
					'message'       => 'Campaign-detail layout normalized successfully',
					'link'          => get_permalink( (int) $input['id'] ),
					'dry_run'       => false,
					'changed_count' => count( $changed_ids ),
					'changed_ids'   => $changed_ids,
					'skipped_ids'   => $skipped_ids,
					'cache'         => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Convert Image Widget To Background Container
	// =========================================================================
	wp_register_ability(
		'elementor/image-widget-to-background-container',
		array(
			'label'               => 'Convert Elementor Image Widget To Background Container',
			'description'         => 'Replaces a container subtree that currently holds an image widget with a native background-image container using the same media. Useful for 50/50 offer rows where the image needs to stretch to the full height of the sibling content block. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'container_element_id' ),
				'properties'           => array(
					'id'                    => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the container.',
					),
					'container_element_id'  => array(
						'type'        => 'string',
						'description' => 'Container element ID to convert.',
					),
					'image_widget_id'       => array(
						'type'        => 'string',
						'description' => 'Optional image widget ID to use as the media source. If omitted, the first image widget in the container subtree is used.',
					),
					'background_size'       => array(
						'type'        => 'string',
						'default'     => 'cover',
						'description' => 'Background-size value to apply.',
					),
					'background_position'   => array(
						'type'        => 'string',
						'default'     => 'center center',
						'description' => 'Background-position value to apply.',
					),
					'background_repeat'     => array(
						'type'        => 'string',
						'default'     => 'no-repeat',
						'description' => 'Background-repeat value to apply.',
					),
					'zero_padding'          => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, zero the container padding in the replacement payload.',
					),
					'spacer_size'           => array(
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Spacer widget size to keep the container rendering reliably on the frontend.',
					),
					'dry_run'               => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the prepared replacement without writing.',
					),
					'cache_scope'           => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'              => array( 'type' => 'boolean' ),
					'id'                   => array( 'type' => 'integer' ),
					'container_element_id' => array( 'type' => 'string' ),
					'image_widget_id'      => array( 'type' => 'string' ),
					'message'              => array( 'type' => 'string' ),
					'link'                 => array( 'type' => 'string' ),
					'dry_run'              => array( 'type' => 'boolean' ),
					'unchanged'            => array( 'type' => 'boolean' ),
					'media'                => array( 'type' => 'object' ),
					'cache'                => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['container_element_id'] ) ) {
					return array( 'success' => false, 'message' => 'container_element_id is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$container_id   = (string) $input['container_element_id'];
				$container_meta = mcp_abilities_elementor_find_element_meta( $data, $container_id );
				if ( ! is_array( $container_meta ) || ! is_array( $container_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Container element not found' );
				}
				if ( 'container' !== ( $container_meta['element']['elType'] ?? '' ) ) {
					return array( 'success' => false, 'message' => 'Target element is not a container' );
				}

				$image_widget = null;
				if ( ! empty( $input['image_widget_id'] ) && is_string( $input['image_widget_id'] ) ) {
					$image_meta = mcp_abilities_elementor_find_element_meta( array( $container_meta['element'] ), (string) $input['image_widget_id'] );
					if ( is_array( $image_meta ) && is_array( $image_meta['element'] ?? null ) ) {
						$image_widget = $image_meta['element'];
					}
				}
				if ( ! is_array( $image_widget ) ) {
					$image_widget = mcp_abilities_elementor_find_first_image_widget( $container_meta['element'] );
				}
				if ( ! is_array( $image_widget ) ) {
					return array( 'success' => false, 'message' => 'No image widget found inside the container subtree' );
				}

				$media = mcp_abilities_elementor_extract_widget_image_media( $image_widget );
				if ( ! is_array( $media ) || empty( $media['url'] ) ) {
					return array( 'success' => false, 'message' => 'Failed to resolve media URL from the image widget' );
				}

				$original_element  = $container_meta['element'];
				$original_settings = is_array( $original_element['settings'] ?? null ) ? $original_element['settings'] : array();
				$zero_padding      = ! array_key_exists( 'zero_padding', $input ) || ! empty( $input['zero_padding'] );
				$spacer_size       = isset( $input['spacer_size'] ) ? max( 1, (int) $input['spacer_size'] ) : 1;
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				$dry_run          = ! empty( $input['dry_run'] );

				$replacement_settings = $original_settings;
				$replacement_settings['_title']                 = is_string( $replacement_settings['_title'] ?? null ) ? $replacement_settings['_title'] : 'background image';
				$replacement_settings['content_width']          = $replacement_settings['content_width'] ?? 'full';
				$replacement_settings['flex_direction']         = $replacement_settings['flex_direction'] ?? 'column';
				$replacement_settings['flex_justify_content']   = $replacement_settings['flex_justify_content'] ?? 'center';
				$replacement_settings['flex_align_items']       = $replacement_settings['flex_align_items'] ?? 'center';
				$replacement_settings['background_background']  = 'classic';
				$replacement_settings['background_image']       = array(
					'url' => $media['url'],
					'id'  => (int) $media['id'],
				);
				$replacement_settings['background_position']    = is_string( $input['background_position'] ?? null ) ? $input['background_position'] : 'center center';
				$replacement_settings['background_size']        = is_string( $input['background_size'] ?? null ) ? $input['background_size'] : 'cover';
				$replacement_settings['background_repeat']      = is_string( $input['background_repeat'] ?? null ) ? $input['background_repeat'] : 'no-repeat';
				$replacement_settings['_flex_size']             = $replacement_settings['_flex_size'] ?? 'none';
				$replacement_settings['_element_width']         = $replacement_settings['_element_width'] ?? 'initial';

				if ( $zero_padding ) {
					$replacement_settings['padding'] = mcp_abilities_elementor_zero_spacing_box( $replacement_settings['padding'] ?? null );
				}

				$replacement_element = array(
					'id'      => $container_id,
					'elType'  => 'container',
					'isInner' => ! empty( $original_element['isInner'] ),
					'settings'=> $replacement_settings,
					'elements'=> array(
						array(
							'id'         => $container_id . '_bg_spacer',
							'elType'     => 'widget',
							'widgetType' => 'spacer',
							'settings'   => array(
								'space' => array(
									'unit' => 'px',
									'size' => $spacer_size,
								),
							),
							'elements'   => array(),
						),
					),
				);
				$replacement_element = mcp_abilities_elementor_normalize_background_container_element( $replacement_element, $original_element );

				if ( $replacement_element === $original_element ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'              => true,
						'id'                   => (int) $input['id'],
						'container_element_id' => $container_id,
						'image_widget_id'      => (string) ( $image_widget['id'] ?? '' ),
						'message'              => 'Container conversion produced no change',
						'link'                 => get_permalink( (int) $input['id'] ),
						'dry_run'              => $dry_run,
						'unchanged'            => true,
						'media'                => $media,
						'cache'                => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'              => true,
						'id'                   => (int) $input['id'],
						'container_element_id' => $container_id,
						'image_widget_id'      => (string) ( $image_widget['id'] ?? '' ),
						'message'              => 'Dry run: background-container conversion prepared successfully',
						'link'                 => get_permalink( (int) $input['id'] ),
						'dry_run'              => true,
						'unchanged'            => false,
						'media'                => $media,
						'cache'                => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, $container_id, $replacement_element );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'              => true,
					'id'                   => (int) $input['id'],
					'container_element_id' => $container_id,
					'image_widget_id'      => (string) ( $image_widget['id'] ?? '' ),
					'message'              => 'Image widget converted to background container successfully',
					'link'                 => get_permalink( (int) $input['id'] ),
					'dry_run'              => false,
					'unchanged'            => false,
					'media'                => $media,
					'cache'                => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Fix Visible Gap Rhythm
	// =========================================================================
	wp_register_ability(
		'elementor/fix-visible-gap-rhythm',
		array(
			'label'               => 'Fix Elementor Visible Gap Rhythm',
			'description'         => 'Normalizes hidden leading-edge spacing that makes the visible gap look larger than the intended row rhythm. By default it zeroes the target container top padding and the first child top margin so wrapper-gap math and visible content spacing line up. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'target_container_id' ),
				'properties'           => array(
					'id'                         => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the target block.',
					),
					'target_container_id'        => array(
						'type'        => 'string',
						'description' => 'Container ID whose leading visible edge should be normalized.',
					),
					'first_child_id'             => array(
						'type'        => 'string',
						'description' => 'Optional explicit first visual child ID. Defaults to the first direct child inside the container.',
					),
					'zero_root_top_padding'      => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, zero the target container top padding.',
					),
					'zero_first_child_margin_top'=> array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, zero the first child top margin values.',
					),
					'zero_first_child_padding_top'=> array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, zero the first child top padding too.',
					),
					'dry_run'                    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the elements that would change without writing.',
					),
					'cache_scope'                => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'message'       => array( 'type' => 'string' ),
					'link'          => array( 'type' => 'string' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'changed_count' => array( 'type' => 'integer' ),
					'changed_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'         => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['target_container_id'] ) ) {
					return array( 'success' => false, 'message' => 'target_container_id is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$target_id             = (string) $input['target_container_id'];
				$container_meta        = mcp_abilities_elementor_find_element_meta( $data, $target_id );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				$dry_run               = ! empty( $input['dry_run'] );
				$changed_ids           = array();

				if ( ! is_array( $container_meta ) || ! is_array( $container_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Target container not found' );
				}

				$container = $container_meta['element'];
				if ( 'container' !== ( $container['elType'] ?? '' ) ) {
					return array( 'success' => false, 'message' => 'Target element is not a container' );
				}

				if ( ! empty( $input['zero_root_top_padding'] ) ) {
					$settings = is_array( $container['settings'] ?? null ) ? $container['settings'] : array();
					$padding  = mcp_abilities_elementor_zero_top_spacing_box( $settings['padding'] ?? null );
					if ( $padding !== ( $settings['padding'] ?? null ) ) {
						$settings['padding']  = $padding;
						$container['settings'] = $settings;
						$changed_ids[]        = $target_id;
					}
				}

				$first_child_id = '';
				if ( ! empty( $input['first_child_id'] ) && is_string( $input['first_child_id'] ) ) {
					$first_child_id = $input['first_child_id'];
				} elseif ( ! empty( $container['elements'][0]['id'] ) && is_string( $container['elements'][0]['id'] ) ) {
					$first_child_id = $container['elements'][0]['id'];
				}

				if ( '' !== $first_child_id ) {
					$first_child_meta = mcp_abilities_elementor_find_element_meta( array( $container ), $first_child_id );
					if ( is_array( $first_child_meta ) && is_array( $first_child_meta['element'] ?? null ) ) {
						$first_child = $first_child_meta['element'];
						$settings    = is_array( $first_child['settings'] ?? null ) ? $first_child['settings'] : array();
						$child_changed = false;

						if ( ! empty( $input['zero_first_child_margin_top'] ) ) {
							foreach ( array( '_margin', '_margin_tablet', '_margin_mobile' ) as $margin_key ) {
								$margin = mcp_abilities_elementor_zero_top_spacing_box( $settings[ $margin_key ] ?? null );
								if ( $margin !== ( $settings[ $margin_key ] ?? null ) ) {
									$settings[ $margin_key ] = $margin;
									$child_changed           = true;
								}
							}
						}

						if ( ! empty( $input['zero_first_child_padding_top'] ) ) {
							$padding = mcp_abilities_elementor_zero_top_spacing_box( $settings['padding'] ?? null );
							if ( $padding !== ( $settings['padding'] ?? null ) ) {
								$settings['padding'] = $padding;
								$child_changed       = true;
							}
						}

						if ( $child_changed ) {
							$first_child['settings'] = $settings;
							mcp_abilities_elementor_replace_element_in_tree( $container['elements'], $first_child_id, $first_child );
							$changed_ids[] = $first_child_id;
						}
					}
				}

				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );
				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'message'       => 'Visible-gap rhythm fix produced no change',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => $dry_run,
						'changed_count' => 0,
						'changed_ids'   => array(),
						'cache'         => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'message'       => 'Dry run: visible-gap rhythm fix prepared successfully',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => true,
						'changed_count' => count( $changed_ids ),
						'changed_ids'   => $changed_ids,
						'cache'         => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, $target_id, $container );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'       => true,
					'id'            => (int) $input['id'],
					'message'       => 'Visible-gap rhythm normalized successfully',
					'link'          => get_permalink( (int) $input['id'] ),
					'dry_run'       => false,
					'changed_count' => count( $changed_ids ),
					'changed_ids'   => $changed_ids,
					'cache'         => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Enforce Boundary Coherence
	// =========================================================================
	wp_register_ability(
		'elementor/enforce-boundary-coherence',
		array(
			'label'               => 'Enforce Elementor Boundary Coherence',
			'description'         => 'Normalizes a container subtree so outer and inner left/right boundaries stay coherent. Use `mode=full_width` for true edge-to-edge sections, or `mode=boxed` with `boxed_width` for consistent boxed lanes. By default it zeroes hidden left/right padding in the subtree and can also normalize nested boxed widths. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id', 'mode' ),
				'properties'           => array(
					'id'                           => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the subtree root.',
					),
					'element_id'                   => array(
						'type'        => 'string',
						'description' => 'Root element ID for the subtree to normalize.',
					),
					'mode'                         => array(
						'type'        => 'string',
						'enum'        => array( 'full_width', 'boxed' ),
						'description' => 'Boundary mode to enforce.',
					),
					'boxed_width'                  => array(
						'type'        => 'integer',
						'description' => 'Required when mode=boxed. The coherent boxed lane width in pixels.',
					),
					'include_root'                 => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, normalize the root container too.',
					),
					'max_depth'                    => array(
						'type'        => 'integer',
						'default'     => 2,
						'description' => 'Maximum descendant depth to normalize. Use -1 for unlimited.',
					),
					'zero_side_padding'            => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, zero left/right padding in the normalized subtree.',
					),
					'zero_side_margins'            => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, also zero left/right Elementor margin settings in the normalized subtree.',
					),
					'normalize_nested_boxed_widths'=> array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true in boxed mode, descendant containers that already define boxed_width are aligned to the same boxed_width.',
					),
					'dry_run'                      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the elements that would change without writing.',
					),
					'cache_scope'                  => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'element_id'    => array( 'type' => 'string' ),
					'mode'          => array( 'type' => 'string' ),
					'message'       => array( 'type' => 'string' ),
					'link'          => array( 'type' => 'string' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'changed_count' => array( 'type' => 'integer' ),
					'changed_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'         => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}
				if ( empty( $input['mode'] ) || ! is_string( $input['mode'] ) ) {
					return array( 'success' => false, 'message' => 'mode is required' );
				}

				$mode = strtolower( trim( (string) $input['mode'] ) );
				if ( ! in_array( $mode, array( 'full_width', 'boxed' ), true ) ) {
					return array( 'success' => false, 'message' => 'mode must be full_width or boxed' );
				}

				$boxed_width = isset( $input['boxed_width'] ) ? (int) $input['boxed_width'] : null;
				if ( 'boxed' === $mode && ( null === $boxed_width || $boxed_width <= 0 ) ) {
					return array( 'success' => false, 'message' => 'boxed_width is required when mode=boxed' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
				if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
					return array(
						'success'    => false,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				$changed_ids           = array();
				$include_root          = ! array_key_exists( 'include_root', $input ) || ! empty( $input['include_root'] );
				$max_depth             = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : 2;
				$zero_side_padding     = ! array_key_exists( 'zero_side_padding', $input ) || ! empty( $input['zero_side_padding'] );
				$zero_side_margins     = ! empty( $input['zero_side_margins'] );
				$normalize_nested_boxed_widths = ! array_key_exists( 'normalize_nested_boxed_widths', $input ) || ! empty( $input['normalize_nested_boxed_widths'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				$dry_run               = ! empty( $input['dry_run'] );
				$root_boxed_setting    = 'boxed' === $mode && null !== $boxed_width ? array(
					'unit' => 'px',
					'size' => $boxed_width,
				) : null;

				$updated_element = mcp_abilities_elementor_enforce_boundary_coherence_subtree(
					$element_meta['element'],
					$mode,
					$boxed_width,
					$include_root,
					$max_depth,
					0,
					$zero_side_padding,
					$zero_side_margins,
					$normalize_nested_boxed_widths,
					$root_boxed_setting,
					$changed_ids
				);
				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );

				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'mode'          => $mode,
						'message'       => 'Boundary coherence enforcement produced no change',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => $dry_run,
						'changed_count' => 0,
						'changed_ids'   => array(),
						'cache'         => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'mode'          => $mode,
						'message'       => 'Dry run: boundary coherence enforcement prepared successfully',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => true,
						'changed_count' => count( $changed_ids ),
						'changed_ids'   => $changed_ids,
						'cache'         => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['element_id'], $updated_element );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'       => true,
					'id'            => (int) $input['id'],
					'element_id'    => (string) $input['element_id'],
					'mode'          => $mode,
					'message'       => 'Boundary coherence enforced successfully',
					'link'          => get_permalink( (int) $input['id'] ),
					'dry_run'       => false,
					'changed_count' => count( $changed_ids ),
					'changed_ids'   => $changed_ids,
					'cache'         => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Reset Negative Margins In Subtree
	// =========================================================================
	wp_register_ability(
		'elementor/reset-negative-margins-subtree',
		array(
			'label'               => 'Reset Negative Margins In Subtree',
			'description'         => 'Recursively clamps negative Elementor widget margins to zero inside a subtree. Useful when hidden negative offsets cancel intended spacing. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the root element.',
					),
					'element_id'   => array(
						'type'        => 'string',
						'description' => 'Root element ID for the subtree.',
					),
					'include_root' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, inspect the root element too.',
					),
					'widgets_only' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, only inspect widgets. If false, inspect containers too.',
					),
					'dry_run'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, return the would-change IDs without writing.',
					),
					'cache_scope'  => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'element_id'    => array( 'type' => 'string' ),
					'message'       => array( 'type' => 'string' ),
					'link'          => array( 'type' => 'string' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'changed_count' => array( 'type' => 'integer' ),
					'changed_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'         => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
				if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
					return array(
						'success'    => false,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				$changed_ids    = array();
				$include_root   = ! array_key_exists( 'include_root', $input ) || ! empty( $input['include_root'] );
				$widgets_only   = ! array_key_exists( 'widgets_only', $input ) || ! empty( $input['widgets_only'] );
				$updated_element = mcp_abilities_elementor_reset_negative_margins_subtree(
					$element_meta['element'],
					$include_root,
					$widgets_only,
					$changed_ids
				);
				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );
				$dry_run     = ! empty( $input['dry_run'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );

				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'No negative margins were found',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => $dry_run,
						'changed_count' => 0,
						'changed_ids'   => array(),
						'cache'         => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'Dry run: negative margins would be reset',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => true,
						'changed_count' => count( $changed_ids ),
						'changed_ids'   => $changed_ids,
						'cache'         => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['element_id'], $updated_element );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'       => true,
					'id'            => (int) $input['id'],
					'element_id'    => (string) $input['element_id'],
					'message'       => 'Negative margins reset successfully',
					'link'          => get_permalink( (int) $input['id'] ),
					'dry_run'       => false,
					'changed_count' => count( $changed_ids ),
					'changed_ids'   => $changed_ids,
					'cache'         => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Extract Design Tokens
	// =========================================================================
	wp_register_ability(
		'elementor/extract-design-tokens',
		array(
			'label'               => 'Extract Elementor Design Tokens',
			'description'         => 'Extracts recurring design tokens from an Elementor page/subtree and optionally includes the active kit settings. Useful for seeing the actual colors, type styles, spacing, and dimensional rhythm already in use before normalizing a migrated page.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id'   => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict extraction to a subtree.',
					),
					'include_kit'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, also extract recurring tokens from the active Elementor kit settings.',
					),
					'max_depth'    => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Optional maximum subtree depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'id'       => array( 'type' => 'integer' ),
					'kit_id'   => array( 'type' => 'integer' ),
					'tokens'   => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$include_kit = ! array_key_exists( 'include_kit', $input ) || ! empty( $input['include_kit'] );
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$collector = array(
					'colors'        => array(),
					'font_families' => array(),
					'font_sizes'    => array(),
					'font_weights'  => array(),
					'line_heights'  => array(),
					'gaps'          => array(),
					'dimensions'    => array(),
					'spacing'       => array(),
				);

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_design_tokens_from_subtree( $element_meta['element'], $collector, $max_depth );
					} else {
						foreach ( $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_design_tokens_from_subtree( $element, $collector, $max_depth );
							}
						}
					}
				}

				$kit_id = 0;
				if ( $include_kit ) {
					$kit_id = (int) get_option( 'elementor_active_kit' );
					if ( $kit_id > 0 ) {
						$kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
						if ( is_array( $kit_settings ) ) {
							mcp_abilities_elementor_collect_tokens_from_settings( $kit_settings, $collector );
						}
					}
				}

				return array(
					'success' => true,
					'id'      => $post_id,
					'kit_id'  => $kit_id,
					'tokens'  => mcp_abilities_elementor_finalize_design_tokens( $collector ),
					'message' => 'Design tokens extracted successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Generic Layout Patterns
	// =========================================================================
	wp_register_ability(
		'elementor/audit-generic-layout-patterns',
		array(
			'label'               => 'Audit Elementor Generic Layout Patterns',
			'description'         => 'Analyzes an Elementor page/subtree for common generic landing-page patterns such as repeated 50/50 rows, equal-width card grids, repeated card rows, and standard split-hero structures. This is style-neutral: it does not prescribe fonts, colors, or any branded look.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
					'max_depth'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'id'              => array( 'type' => 'integer' ),
					'element_id'      => array( 'type' => 'string' ),
					'audit'           => array( 'type' => 'object' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$stats     = array(
					'patterns'           => array(
						'standard_split_hero'    => array(),
						'symmetric_two_column'   => array(),
						'three_up_grid'          => array(),
						'uniform_multi_grid'     => array(),
						'repeated_component_row' => array(),
					),
					'section_signatures' => array(),
				);

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_generic_layout_stats_from_subtree( $element_meta['element'], $stats, $max_depth );
					} else {
						foreach ( $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_generic_layout_stats_from_subtree( $element, $stats, $max_depth );
							}
						}
					}
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_generic_layout_audit( $stats ),
					'message'    => 'Generic layout audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Score Distinctiveness
	// =========================================================================
	wp_register_ability(
		'elementor/score-distinctiveness',
		array(
			'label'               => 'Score Elementor Distinctiveness',
			'description'         => 'Scores an Elementor page/subtree for compositional distinctiveness based on structural repetition and generic-layout patterns. This is style-neutral: it does not push any particular aesthetic, only flags repetition and symmetry overuse.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict scoring to a subtree.',
					),
					'max_depth'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'id'              => array( 'type' => 'integer' ),
					'element_id'      => array( 'type' => 'string' ),
					'score'           => array( 'type' => 'integer' ),
					'penalties'       => array( 'type' => 'array' ),
					'recommendations' => array( 'type' => 'array' ),
					'audit'           => array( 'type' => 'object' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$stats     = array(
					'patterns'           => array(
						'standard_split_hero'    => array(),
						'symmetric_two_column'   => array(),
						'three_up_grid'          => array(),
						'uniform_multi_grid'     => array(),
						'repeated_component_row' => array(),
					),
					'section_signatures' => array(),
				);

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_generic_layout_stats_from_subtree( $element_meta['element'], $stats, $max_depth );
					} else {
						foreach ( $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_generic_layout_stats_from_subtree( $element, $stats, $max_depth );
							}
						}
					}
				}

				$audit = mcp_abilities_elementor_finalize_generic_layout_audit( $stats );
				$score = mcp_abilities_elementor_score_distinctiveness_from_audit( $audit );

				return array(
					'success'         => true,
					'id'              => $post_id,
					'element_id'      => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'score'           => (int) ( $score['score'] ?? 0 ),
					'penalties'       => array_values( (array) ( $score['penalties'] ?? array() ) ),
					'recommendations' => array_values( (array) ( $score['recommendations'] ?? array() ) ),
					'audit'           => $audit,
					'message'         => 'Distinctiveness scored successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Column Patterns
	// =========================================================================
	wp_register_ability(
		'elementor/audit-column-patterns',
		array(
			'label'               => 'Audit Elementor Column Patterns',
			'description'         => 'Audits repeated column ratios such as repeated 50/50 and equal-third rows. This is readonly and does not assume asymmetry is automatically better.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
					'max_depth'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'audit'      => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$stats     = array( 'rows' => array() );

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element_meta['element'], $stats, $max_depth );
					} else {
						foreach ( (array) $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element, $stats, $max_depth );
							}
						}
					}
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_column_patterns_audit( (array) ( $stats['rows'] ?? array() ) ),
					'message'    => 'Column pattern audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Layout Mechanism Fit
	// =========================================================================
	wp_register_ability(
		'elementor/audit-layout-mechanism-fit',
		array(
			'label'               => 'Audit Elementor Layout Mechanism Fit',
			'description'         => 'Checks whether symmetric equal-column groups are using the right Elementor layout mechanism. Uses Elementor’s own guidance: Grid for equal, symmetric rows/columns; Flexbox for user-shaped directional patterns.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
					'max_depth'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'audit'      => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$stats     = array( 'rows' => array() );

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element_meta['element'], $stats, $max_depth );
					} else {
						foreach ( (array) $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element, $stats, $max_depth );
							}
						}
					}
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_layout_mechanism_fit_audit( (array) ( $stats['rows'] ?? array() ) ),
					'message'    => 'Layout mechanism fit audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Native Widget Opportunities
	// =========================================================================
	wp_register_ability(
		'elementor/audit-native-widget-opportunities',
		array(
			'label'               => 'Audit Elementor Native Widget Opportunities',
			'description'         => 'Checks whether a hand-built Elementor container pattern is better served by a native widget or Pro widget such as Accordion, Nested Tabs, Call to Action, or Icon List.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'audit'      => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input      = is_array( $input ) ? $input : array();
				$post_id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$element_id = isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '';
				$elements   = array();

				if ( $post_id > 0 ) {
					$scope = mcp_abilities_elementor_resolve_audit_scope( $post_id, $element_id );
					if ( is_wp_error( $scope ) ) {
						return array( 'success' => false, 'message' => $scope->get_error_message() );
					}
					$elements = (array) ( $scope['elements'] ?? array() );
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => $element_id,
					'audit'      => mcp_abilities_elementor_finalize_native_widget_opportunity_audit( $elements ),
					'message'    => 'Native widget opportunity audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Column Dominance
	// =========================================================================
	wp_register_ability(
		'elementor/audit-column-dominance',
		array(
			'label'               => 'Audit Elementor Column Dominance',
			'description'         => 'Checks whether equal column splits are hiding a clearly dominant side. This is readonly and only flags rows where the content hierarchy may be stronger than the ratio suggests.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
					'max_depth'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'audit'      => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$stats     = array( 'rows' => array() );

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element_meta['element'], $stats, $max_depth );
					} else {
						foreach ( (array) $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element, $stats, $max_depth );
							}
						}
					}
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_column_dominance_audit( (array) ( $stats['rows'] ?? array() ) ),
					'message'    => 'Column dominance audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Column Alignment Rhythm
	// =========================================================================
	wp_register_ability(
		'elementor/audit-column-alignment-rhythm',
		array(
			'label'               => 'Audit Elementor Column Alignment Rhythm',
			'description'         => 'Reports whether similar column ratios are using inconsistent gutter rhythms. This is readonly and does not assume uniform spacing is always better.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
					'max_depth'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'audit'      => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$stats     = array( 'rows' => array() );

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element_meta['element'], $stats, $max_depth );
					} else {
						foreach ( (array) $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element, $stats, $max_depth );
							}
						}
					}
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_column_alignment_rhythm_audit( (array) ( $stats['rows'] ?? array() ) ),
					'message'    => 'Column alignment rhythm audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Column Balance
	// =========================================================================
	wp_register_ability(
		'elementor/audit-column-balance',
		array(
			'label'               => 'Audit Elementor Column Balance',
			'description'         => 'Checks whether asymmetric rows appear to be earning their asymmetry. This is readonly and does not assume equal splits are better.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
					'max_depth'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'audit'      => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$stats     = array( 'rows' => array() );

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element_meta['element'], $stats, $max_depth );
					} else {
						foreach ( (array) $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element, $stats, $max_depth );
							}
						}
					}
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_column_balance_audit( (array) ( $stats['rows'] ?? array() ) ),
					'message'    => 'Column balance audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Column Necessity
	// =========================================================================
	wp_register_ability(
		'elementor/audit-column-necessity',
		array(
			'label'               => 'Audit Elementor Column Necessity',
			'description'         => 'Flags column splits that may not be earning their complexity. This is readonly and only suggests checking whether a row would read more clearly as one lane.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
					'max_depth'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum depth to inspect. Use -1 for unlimited.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'audit'      => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$stats     = array( 'rows' => array() );

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element_meta['element'], $stats, $max_depth );
					} else {
						foreach ( (array) $data as $element ) {
							if ( is_array( $element ) ) {
								mcp_abilities_elementor_collect_column_audit_stats_from_subtree( $element, $stats, $max_depth );
							}
						}
					}
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_column_necessity_audit( (array) ( $stats['rows'] ?? array() ) ),
					'message'    => 'Column necessity audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Generic Component Repetition
	// =========================================================================
	wp_register_ability(
		'elementor/audit-generic-component-repetition',
		array(
			'label'               => 'Audit Elementor Generic Component Repetition',
			'description'         => 'Flags repeated landing-page furniture such as excessive buttons and repeated card-like panel treatments. This is style-neutral and does not punish simple layouts for being restrained.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'id'              => array( 'type' => 'integer' ),
					'element_id'      => array( 'type' => 'string' ),
					'audit'           => array( 'type' => 'object' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input   = is_array( $input ) ? $input : array();
				$post_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$root    = null;

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						$root = $element_meta['element'];
					} else {
						$root = array(
							'id'       => 'document_root',
							'elType'   => 'container',
							'settings' => array(),
							'elements' => $data,
						);
					}
				}

				$profile = is_array( $root ) ? mcp_abilities_elementor_build_component_profile( $root ) : array(
					'widget_counts' => array(),
					'card_like_ids' => array(),
				);

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_component_overuse_audit( $profile ),
					'message'    => 'Generic component repetition audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Surface Overuse
	// =========================================================================
	wp_register_ability(
		'elementor/audit-surface-overuse',
		array(
			'label'               => 'Audit Elementor Surface Overuse',
			'description'         => 'Reports when the same panel/surface treatment repeats often enough to risk feeling formulaic. It is intentionally cautious and does not assume repeated surfaces are bad when simplicity is intentional.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to restrict the audit to a subtree.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'id'              => array( 'type' => 'integer' ),
					'element_id'      => array( 'type' => 'string' ),
					'audit'           => array( 'type' => 'object' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input     = is_array( $input ) ? $input : array();
				$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$collector = array();
				$elements  = array();

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					if ( ! empty( $input['element_id'] ) && is_string( $input['element_id'] ) ) {
						$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
						if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
							return array( 'success' => false, 'message' => 'Element not found' );
						}
						$elements = array( $element_meta['element'] );
					} else {
						$elements = is_array( $data ) ? $data : array();
					}
				}

				foreach ( $elements as $element ) {
					if ( is_array( $element ) ) {
						mcp_abilities_elementor_collect_surface_signatures_from_subtree( $element, $collector );
					}
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '',
					'audit'      => mcp_abilities_elementor_finalize_surface_overuse_audit( $collector ),
					'message'    => 'Surface overuse audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Emphasis Drift
	// =========================================================================
	wp_register_ability(
		'elementor/audit-emphasis-drift',
		array(
			'label'               => 'Audit Elementor Emphasis Drift',
			'description'         => 'Checks whether top-level sections are all carrying roughly the same emphasis weight. It is cautious by design and only warns when a page risks making every section land with the same force.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'id'              => array( 'type' => 'integer' ),
					'audit'           => array( 'type' => 'object' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input   = is_array( $input ) ? $input : array();
				$post_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$profiles = array();

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					foreach ( (array) $data as $element ) {
						if ( is_array( $element ) ) {
							$profiles[] = mcp_abilities_elementor_compute_section_emphasis_profile( $element );
						}
					}
				}

				return array(
					'success' => true,
					'id'      => $post_id,
					'audit'   => mcp_abilities_elementor_finalize_emphasis_drift_audit( $profiles ),
					'message' => 'Emphasis drift audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Section Rivalry
	// =========================================================================
	wp_register_ability(
		'elementor/audit-section-rivalry',
		array(
			'label'               => 'Audit Elementor Section Rivalry',
			'description'         => 'Checks whether too many top-level sections are carrying peak-emphasis signals at once. It is style-neutral and tries to catch competing local climaxes without punishing restrained or simple pages.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'id'              => array( 'type' => 'integer' ),
					'audit'           => array( 'type' => 'object' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input    = is_array( $input ) ? $input : array();
				$post_id  = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$profiles = array();

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					foreach ( (array) $data as $element ) {
						if ( is_array( $element ) ) {
							$profiles[] = mcp_abilities_elementor_compute_section_emphasis_profile( $element );
						}
					}
				}

				return array(
					'success' => true,
					'id'      => $post_id,
					'audit'   => mcp_abilities_elementor_finalize_section_rivalry_audit( $profiles ),
					'message' => 'Section rivalry audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Composition Rhythm
	// =========================================================================
	wp_register_ability(
		'elementor/audit-composition-rhythm',
		array(
			'label'               => 'Audit Elementor Composition Rhythm',
			'description'         => 'Looks at top-level section pacing and tonal runs to spot pages that may be settling into one long beat. It does not assume that minimal or restrained pages are bad.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'id'              => array( 'type' => 'integer' ),
					'audit'           => array( 'type' => 'object' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input    = is_array( $input ) ? $input : array();
				$post_id  = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$profiles = array();

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					foreach ( (array) $data as $element ) {
						if ( is_array( $element ) ) {
							$profiles[] = mcp_abilities_elementor_compute_section_emphasis_profile( $element );
						}
					}
				}

				return array(
					'success' => true,
					'id'      => $post_id,
					'audit'   => mcp_abilities_elementor_finalize_composition_rhythm_audit( $profiles ),
					'message' => 'Composition rhythm audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Audit Separator Discipline
	// =========================================================================
	wp_register_ability(
		'elementor/audit-separator-discipline',
		array(
			'label'               => 'Audit Elementor Separator Discipline',
			'description'         => 'Checks whether top-level section separators are starting to flatten the page hierarchy. It is deliberately cautious and only warns when separators begin behaving like a page-wide default.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Optional Post/Page ID to inspect.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'audit'   => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input    = is_array( $input ) ? $input : array();
				$post_id  = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$profiles = array();

				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
					if ( empty( $elementor_data ) ) {
						return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
					}

					$data = json_decode( $elementor_data, true );
					if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}

					foreach ( (array) $data as $element ) {
						if ( is_array( $element ) ) {
							$profiles[] = mcp_abilities_elementor_compute_section_separator_profile( $element );
						}
					}
				}

				return array(
					'success' => true,
					'id'      => $post_id,
					'audit'   => mcp_abilities_elementor_finalize_separator_discipline_audit( $profiles ),
					'message' => 'Separator discipline audit completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Theme Context
	// =========================================================================
	wp_register_ability(
		'elementor/get-theme-context',
		array(
			'label'               => 'Get Elementor Theme Context',
			'description'         => 'Summarizes the active WordPress theme, Elementor version, active kit, and viewport options so design work starts from actual site context instead of guesswork.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'include_viewports' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If false, omit Elementor viewport option values from the returned context.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'context' => array( 'type' => 'object' ),
					'source_policy' => array( 'type' => 'object' ),
					'guidance_basis' => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input   = is_array( $input ) ? $input : array();
				$context = mcp_abilities_elementor_get_theme_context_summary();
				if ( array_key_exists( 'include_viewports', $input ) && empty( $input['include_viewports'] ) ) {
					unset( $context['elementor']['viewport_options'] );
				}

				return array(
					'success' => true,
					'context' => $context,
					'source_policy' => mcp_abilities_elementor_get_official_guidance_catalog()['policy'],
					'guidance_basis' => mcp_abilities_elementor_get_design_guidance_basis(),
					'message' => 'Theme context retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Official Widget Catalog
	// =========================================================================
	wp_register_ability(
		'elementor/get-official-widget-catalog',
		array(
			'label'               => 'Get Elementor Official Widget Catalog',
			'description'         => 'Fetches the official Elementor widget catalog from Elementor.com so the plugin can know the full Basic, Pro, Theme, and WooCommerce widget surface instead of relying on a hand-maintained shortlist.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'force_refresh' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, bypass the cached catalog and fetch it again from Elementor.com.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'catalog' => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input   = is_array( $input ) ? $input : array();
				$catalog = mcp_abilities_elementor_fetch_official_widget_catalog( ! empty( $input['force_refresh'] ) );

				if ( is_wp_error( $catalog ) ) {
					return array(
						'success' => false,
						'message' => $catalog->get_error_message(),
					);
				}

				return array(
					'success' => true,
					'catalog' => $catalog,
					'message' => 'Official Elementor widget catalog retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Official Pattern Guidance
	// =========================================================================
	wp_register_ability(
		'elementor/get-official-pattern-guidance',
		array(
			'label'               => 'Get Elementor Official Pattern Guidance',
			'description'         => 'Returns the official Elementor.com guidance catalog used by the design audits so pattern and widget recommendations stay grounded in Elementor docs instead of site-local guesswork.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'topic' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'layout', 'widgets', 'policy' ),
						'default'     => 'all',
						'description' => 'Optional guidance subset to return.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'topic'    => array( 'type' => 'string' ),
					'guidance' => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input    = is_array( $input ) ? $input : array();
				$topic    = isset( $input['topic'] ) && is_string( $input['topic'] ) ? strtolower( $input['topic'] ) : 'all';
				$catalog  = mcp_abilities_elementor_get_official_guidance_catalog();
				$guidance = $catalog;

				if ( 'layout' === $topic ) {
					$guidance = array(
						'policy' => $catalog['policy'],
						'layout' => $catalog['layout'],
					);
				} elseif ( 'widgets' === $topic ) {
					$guidance = array(
						'policy'  => $catalog['policy'],
						'widgets' => $catalog['widgets'],
					);
				} elseif ( 'policy' === $topic ) {
					$guidance = array(
						'policy' => $catalog['policy'],
					);
				}

				return array(
					'success'  => true,
					'topic'    => $topic,
					'guidance' => $guidance,
					'message'  => 'Official Elementor guidance retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Style Guide
	// =========================================================================
	wp_register_ability(
		'elementor/get-style-guide',
		array(
			'label'               => 'Get Elementor Style Guide',
			'description'         => 'Builds a style-guide summary from the active Elementor kit, including design tokens, layout settings, global colors, and global typography.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'include_raw_settings' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If false, omit the full raw kit settings payload from the response.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'source_policy' => array( 'type' => 'object' ),
					'guidance_basis' => array( 'type' => 'object' ),
					'style_guide' => array( 'type' => 'object' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input       = is_array( $input ) ? $input : array();
				$style_guide = mcp_abilities_elementor_get_style_guide_summary();
				if ( array_key_exists( 'include_raw_settings', $input ) && empty( $input['include_raw_settings'] ) ) {
					unset( $style_guide['raw_settings'] );
				}

				return array(
					'success'     => true,
					'source_policy' => mcp_abilities_elementor_get_official_guidance_catalog()['policy'],
					'guidance_basis' => mcp_abilities_elementor_get_design_guidance_basis(),
					'style_guide' => $style_guide,
					'message'     => 'Style guide retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Evaluate Design
	// =========================================================================
	wp_register_ability(
		'elementor/evaluate-design',
		array(
			'label'               => 'Evaluate Elementor Design',
			'description'         => 'Runs the main Elementor design audits together and returns one score, issue list, blocking issues, and recommendations so overlapping helpers become one evaluation surface.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to limit evaluation to a subtree.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'source_policy' => array( 'type' => 'object' ),
					'guidance_basis' => array( 'type' => 'object' ),
					'evaluation' => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input      = is_array( $input ) ? $input : array();
				$post_id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$element_id = isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '';

				if ( $post_id <= 0 ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}

				$scope = mcp_abilities_elementor_resolve_audit_scope( $post_id, $element_id );
				if ( is_wp_error( $scope ) ) {
					return array( 'success' => false, 'message' => $scope->get_error_message() );
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => $element_id,
					'source_policy' => mcp_abilities_elementor_get_official_guidance_catalog()['policy'],
					'guidance_basis' => mcp_abilities_elementor_get_design_guidance_basis(),
					'evaluation' => mcp_abilities_elementor_evaluate_design_from_elements( (array) ( $scope['elements'] ?? array() ) ),
					'message'    => 'Design evaluation completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Suggest Design Fixes
	// =========================================================================
	wp_register_ability(
		'elementor/suggest-design-fixes',
		array(
			'label'               => 'Suggest Elementor Design Fixes',
			'description'         => 'Turns the aggregated Elementor design evaluation into concrete, issue-type-specific design fix suggestions.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to inspect.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Optional root element ID to limit evaluation to a subtree.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'integer' ),
					'element_id'  => array( 'type' => 'string' ),
					'source_policy' => array( 'type' => 'object' ),
					'guidance_basis' => array( 'type' => 'object' ),
					'evaluation'  => array( 'type' => 'object' ),
					'suggestions' => array( 'type' => 'object' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input      = is_array( $input ) ? $input : array();
				$post_id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$element_id = isset( $input['element_id'] ) && is_string( $input['element_id'] ) ? $input['element_id'] : '';

				if ( $post_id <= 0 ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}

				$scope = mcp_abilities_elementor_resolve_audit_scope( $post_id, $element_id );
				if ( is_wp_error( $scope ) ) {
					return array( 'success' => false, 'message' => $scope->get_error_message() );
				}

				$evaluation = mcp_abilities_elementor_evaluate_design_from_elements( (array) ( $scope['elements'] ?? array() ) );

				return array(
					'success'     => true,
					'id'          => $post_id,
					'element_id'  => $element_id,
					'source_policy' => mcp_abilities_elementor_get_official_guidance_catalog()['policy'],
					'guidance_basis' => mcp_abilities_elementor_get_design_guidance_basis(),
					'evaluation'  => $evaluation,
					'suggestions' => mcp_abilities_elementor_suggest_design_fixes_from_evaluation( $evaluation ),
					'message'     => 'Design fix suggestions generated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Evaluate Render Context
	// =========================================================================
	wp_register_ability(
		'elementor/evaluate-render-context',
		array(
			'label'               => 'Evaluate Elementor Render Context',
			'description'         => 'Checks the rendered frontend around an Elementor page so wrapper-level problems stay separate from actual Elementor content quality.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to inspect.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'result'  => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input   = is_array( $input ) ? $input : array();
				$post_id = isset( $input['id'] ) ? (int) $input['id'] : 0;

				if ( $post_id <= 0 ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}

				$result = mcp_abilities_elementor_evaluate_render_context( $post_id );
				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				return array(
					'success' => true,
					'id'      => $post_id,
					'result'  => $result,
					'message' => 'Render context evaluation completed successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Apply Text Hierarchy
	// =========================================================================
	wp_register_ability(
		'elementor/apply-text-hierarchy',
		array(
			'label'               => 'Apply Elementor Text Hierarchy',
			'description'         => 'Applies a coherent text hierarchy to a subtree by normalizing heading, body-text, and button typography settings. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'            => array( 'type' => 'integer', 'description' => 'Post/Page ID containing the subtree.' ),
					'element_id'    => array( 'type' => 'string', 'description' => 'Root element ID for the subtree.' ),
					'include_root'  => array( 'type' => 'boolean', 'default' => true, 'description' => 'If true, include the root element.' ),
					'max_depth'     => array( 'type' => 'integer', 'default' => -1, 'description' => 'Maximum descendant depth. Use -1 for unlimited.' ),
					'heading_scale' => array( 'type' => 'object', 'description' => 'Optional map of heading tag (h1-h6/default) to font settings.' ),
					'body_style'    => array( 'type' => 'object', 'description' => 'Optional body text style overrides.' ),
					'button_style'  => array( 'type' => 'object', 'description' => 'Optional button text style overrides.' ),
					'dry_run'       => array( 'type' => 'boolean', 'default' => false, 'description' => 'If true, return changed IDs without writing.' ),
					'cache_scope'   => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'element_id'    => array( 'type' => 'string' ),
					'message'       => array( 'type' => 'string' ),
					'link'          => array( 'type' => 'string' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'changed_count' => array( 'type' => 'integer' ),
					'changed_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'         => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
				if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Element not found' );
				}

				$heading_scale = isset( $input['heading_scale'] ) && is_array( $input['heading_scale'] ) ? $input['heading_scale'] : array(
					'h1'      => array( 'font_size' => 56, 'line_height' => 1.05, 'font_weight' => '600' ),
					'h2'      => array( 'font_size' => 44, 'line_height' => 1.1, 'font_weight' => '600' ),
					'h3'      => array( 'font_size' => 34, 'line_height' => 1.15, 'font_weight' => '600' ),
					'h4'      => array( 'font_size' => 28, 'line_height' => 1.2, 'font_weight' => '600' ),
					'h5'      => array( 'font_size' => 22, 'line_height' => 1.25, 'font_weight' => '600' ),
					'h6'      => array( 'font_size' => 18, 'line_height' => 1.3, 'font_weight' => '600' ),
					'default' => array( 'font_size' => 34, 'line_height' => 1.15, 'font_weight' => '600' ),
				);
				$body_style = isset( $input['body_style'] ) && is_array( $input['body_style'] ) ? $input['body_style'] : array(
					'font_size'   => 18,
					'line_height' => 1.6,
					'font_weight' => '400',
				);
				$button_style = isset( $input['button_style'] ) && is_array( $input['button_style'] ) ? $input['button_style'] : array(
					'font_size'   => 16,
					'line_height' => 1.2,
					'font_weight' => '500',
				);

				$changed_ids  = array();
				$include_root = ! array_key_exists( 'include_root', $input ) || ! empty( $input['include_root'] );
				$max_depth    = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$updated      = mcp_abilities_elementor_apply_text_hierarchy_subtree(
					$element_meta['element'],
					$heading_scale,
					$body_style,
					$button_style,
					$include_root,
					$max_depth,
					0,
					$changed_ids
				);
				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );
				$dry_run = ! empty( $input['dry_run'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );

				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'Text hierarchy produced no change',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => $dry_run,
						'changed_count' => 0,
						'changed_ids'   => array(),
						'cache'         => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'Dry run: text hierarchy would be applied',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => true,
						'changed_count' => count( $changed_ids ),
						'changed_ids'   => $changed_ids,
						'cache'         => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['element_id'], $updated );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}
				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'       => true,
					'id'            => (int) $input['id'],
					'element_id'    => (string) $input['element_id'],
					'message'       => 'Text hierarchy applied successfully',
					'link'          => get_permalink( (int) $input['id'] ),
					'dry_run'       => false,
					'changed_count' => count( $changed_ids ),
					'changed_ids'   => $changed_ids,
					'cache'         => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Normalize Section Spacing Rhythm
	// =========================================================================
	wp_register_ability(
		'elementor/normalize-section-spacing-rhythm',
		array(
			'label'               => 'Normalize Elementor Section Spacing Rhythm',
			'description'         => 'Snaps padding, optional margins, and row gaps inside a subtree to a consistent rhythm step. Useful when migrated sections feel visually uneven even though the structure is correct. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'             => array( 'type' => 'integer', 'description' => 'Post/Page ID containing the subtree.' ),
					'element_id'     => array( 'type' => 'string', 'description' => 'Root element ID for the subtree.' ),
					'include_root'   => array( 'type' => 'boolean', 'default' => true, 'description' => 'If true, include the root element.' ),
					'max_depth'      => array( 'type' => 'integer', 'default' => -1, 'description' => 'Maximum descendant depth. Use -1 for unlimited.' ),
					'rhythm_step'    => array( 'type' => 'integer', 'default' => 8, 'description' => 'Rhythm step in px.' ),
					'sides'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Spacing-box sides to snap. Defaults to top and bottom.' ),
					'include_margin' => array( 'type' => 'boolean', 'default' => false, 'description' => 'If true, snap margins too.' ),
					'target_gap'     => array( 'description' => 'Optional explicit gap value to force after snapping.' ),
					'dry_run'        => array( 'type' => 'boolean', 'default' => false, 'description' => 'If true, return changed IDs without writing.' ),
					'cache_scope'    => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'element_id'    => array( 'type' => 'string' ),
					'message'       => array( 'type' => 'string' ),
					'link'          => array( 'type' => 'string' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'changed_count' => array( 'type' => 'integer' ),
					'changed_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'         => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}
				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
				if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Element not found' );
				}

				$changed_ids    = array();
				$include_root   = ! array_key_exists( 'include_root', $input ) || ! empty( $input['include_root'] );
				$max_depth      = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$rhythm_step    = isset( $input['rhythm_step'] ) ? max( 1, (int) $input['rhythm_step'] ) : 8;
				$sides          = isset( $input['sides'] ) && is_array( $input['sides'] ) && ! empty( $input['sides'] )
					? array_values( array_filter( $input['sides'], 'is_string' ) )
					: array( 'top', 'bottom' );
				$include_margin = ! empty( $input['include_margin'] );
				$target_gap     = $input['target_gap'] ?? null;

				$updated = mcp_abilities_elementor_normalize_spacing_rhythm_subtree(
					$element_meta['element'],
					$include_root,
					$max_depth,
					0,
					$rhythm_step,
					$sides,
					$include_margin,
					$target_gap,
					$changed_ids
				);
				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );
				$dry_run = ! empty( $input['dry_run'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );

				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'Spacing rhythm produced no change',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => $dry_run,
						'changed_count' => 0,
						'changed_ids'   => array(),
						'cache'         => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'Dry run: spacing rhythm would be normalized',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => true,
						'changed_count' => count( $changed_ids ),
						'changed_ids'   => $changed_ids,
						'cache'         => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['element_id'], $updated );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}
				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'       => true,
					'id'            => (int) $input['id'],
					'element_id'    => (string) $input['element_id'],
					'message'       => 'Spacing rhythm normalized successfully',
					'link'          => get_permalink( (int) $input['id'] ),
					'dry_run'       => false,
					'changed_count' => count( $changed_ids ),
					'changed_ids'   => $changed_ids,
					'cache'         => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Normalize Responsive Values
	// =========================================================================
	wp_register_ability(
		'elementor/normalize-responsive-values',
		array(
			'label'               => 'Normalize Elementor Responsive Values',
			'description'         => 'Fills or normalizes tablet/mobile spacing and size values from the desktop settings in a subtree. By default it also caps inherited left/right spacing on narrower breakpoints so desktop padding does not crush mobile/tablet layouts. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'                 => array( 'type' => 'integer', 'description' => 'Post/Page ID containing the subtree.' ),
					'element_id'         => array( 'type' => 'string', 'description' => 'Root element ID for the subtree.' ),
					'include_root'       => array( 'type' => 'boolean', 'default' => true, 'description' => 'If true, include the root element.' ),
					'max_depth'          => array( 'type' => 'integer', 'default' => -1, 'description' => 'Maximum descendant depth. Use -1 for unlimited.' ),
					'fill_missing_only'  => array( 'type' => 'boolean', 'default' => true, 'description' => 'If true, only fill missing tablet/mobile values.' ),
					'tablet_factor'      => array( 'type' => 'number', 'default' => 1, 'description' => 'Scale factor for generated tablet values.' ),
					'mobile_factor'      => array( 'type' => 'number', 'default' => 1, 'description' => 'Scale factor for generated mobile values.' ),
					'tablet_horizontal_spacing_cap' => array( 'type' => 'number', 'default' => 40, 'description' => 'Optional maximum px value for generated tablet left/right spacing.' ),
					'mobile_horizontal_spacing_cap' => array( 'type' => 'number', 'default' => 24, 'description' => 'Optional maximum px value for generated mobile left/right spacing.' ),
					'dry_run'            => array( 'type' => 'boolean', 'default' => false, 'description' => 'If true, return changed IDs without writing.' ),
					'cache_scope'        => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'element_id'    => array( 'type' => 'string' ),
					'message'       => array( 'type' => 'string' ),
					'link'          => array( 'type' => 'string' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'changed_count' => array( 'type' => 'integer' ),
					'changed_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'         => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}
				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$element_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['element_id'] );
				if ( ! is_array( $element_meta ) || ! is_array( $element_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Element not found' );
				}

				$changed_ids = array();
				$include_root = ! array_key_exists( 'include_root', $input ) || ! empty( $input['include_root'] );
				$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : -1;
				$fill_missing_only = ! array_key_exists( 'fill_missing_only', $input ) || ! empty( $input['fill_missing_only'] );
				$tablet_factor = isset( $input['tablet_factor'] ) ? (float) $input['tablet_factor'] : 1.0;
				$mobile_factor = isset( $input['mobile_factor'] ) ? (float) $input['mobile_factor'] : 1.0;
				$tablet_horizontal_spacing_cap = array_key_exists( 'tablet_horizontal_spacing_cap', $input ) ? ( null === $input['tablet_horizontal_spacing_cap'] ? null : (float) $input['tablet_horizontal_spacing_cap'] ) : 40.0;
				$mobile_horizontal_spacing_cap = array_key_exists( 'mobile_horizontal_spacing_cap', $input ) ? ( null === $input['mobile_horizontal_spacing_cap'] ? null : (float) $input['mobile_horizontal_spacing_cap'] ) : 24.0;
				$updated = mcp_abilities_elementor_normalize_responsive_values_subtree(
					$element_meta['element'],
					$include_root,
					$max_depth,
					0,
					$fill_missing_only,
					$tablet_factor,
					$mobile_factor,
					$tablet_horizontal_spacing_cap,
					$mobile_horizontal_spacing_cap,
					$changed_ids
				);
				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );
				$dry_run = ! empty( $input['dry_run'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );

				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'Responsive normalization produced no change',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => $dry_run,
						'changed_count' => 0,
						'changed_ids'   => array(),
						'cache'         => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'       => true,
						'id'            => (int) $input['id'],
						'element_id'    => (string) $input['element_id'],
						'message'       => 'Dry run: responsive values would be normalized',
						'link'          => get_permalink( (int) $input['id'] ),
						'dry_run'       => true,
						'changed_count' => count( $changed_ids ),
						'changed_ids'   => $changed_ids,
						'cache'         => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['element_id'], $updated );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}
				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'       => true,
					'id'            => (int) $input['id'],
					'element_id'    => (string) $input['element_id'],
					'message'       => 'Responsive values normalized successfully',
					'link'          => get_permalink( (int) $input['id'] ),
					'dry_run'       => false,
					'changed_count' => count( $changed_ids ),
					'changed_ids'   => $changed_ids,
					'cache'         => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Sync Component Variant
	// =========================================================================
	wp_register_ability(
		'elementor/sync-component-variant',
		array(
			'label'               => 'Sync Elementor Component Variant',
			'description'         => 'Copies design-relevant settings from one component subtree to another by matching node position and type. Useful when one hero/card/CTA block is the correct visual variant and a sibling section should inherit the same design treatment. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'source_element_id', 'target_element_id' ),
				'properties'           => array(
					'id'                => array( 'type' => 'integer', 'description' => 'Post/Page ID containing both component subtrees.' ),
					'source_element_id' => array( 'type' => 'string', 'description' => 'Source component root.' ),
					'target_element_id' => array( 'type' => 'string', 'description' => 'Target component root.' ),
					'allow_partial'     => array( 'type' => 'boolean', 'default' => false, 'description' => 'If true, sync across the overlapping subtree shape only.' ),
					'include_keys'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional extra settings keys to include beyond the built-in design filter.' ),
					'dry_run'           => array( 'type' => 'boolean', 'default' => false, 'description' => 'If true, return changed IDs without writing.' ),
					'cache_scope'       => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. Ignored when dry_run=true.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'           => array( 'type' => 'boolean' ),
					'id'                => array( 'type' => 'integer' ),
					'source_element_id' => array( 'type' => 'string' ),
					'target_element_id' => array( 'type' => 'string' ),
					'message'           => array( 'type' => 'string' ),
					'link'              => array( 'type' => 'string' ),
					'dry_run'           => array( 'type' => 'boolean' ),
					'changed_count'     => array( 'type' => 'integer' ),
					'changed_ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'cache'             => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['source_element_id'] ) || empty( $input['target_element_id'] ) ) {
					return array( 'success' => false, 'message' => 'source_element_id and target_element_id are required' );
				}

				$post = get_post( (int) $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( (int) $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}
				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$source_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['source_element_id'] );
				$target_meta = mcp_abilities_elementor_find_element_meta( $data, (string) $input['target_element_id'] );
				if ( ! is_array( $source_meta ) || ! is_array( $source_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Source element not found' );
				}
				if ( ! is_array( $target_meta ) || ! is_array( $target_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Target element not found' );
				}

				$changed_ids = array();
				$allow_partial = ! empty( $input['allow_partial'] );
				$include_keys = isset( $input['include_keys'] ) && is_array( $input['include_keys'] ) ? array_values( array_filter( $input['include_keys'], 'is_string' ) ) : array();
				$updated = mcp_abilities_elementor_sync_component_variant_subtree(
					$source_meta['element'],
					$target_meta['element'],
					$allow_partial,
					$include_keys,
					$changed_ids
				);
				$changed_ids = array_values( array_unique( array_filter( $changed_ids ) ) );
				$dry_run = ! empty( $input['dry_run'] );
				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );

				if ( empty( $changed_ids ) ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'           => true,
						'id'                => (int) $input['id'],
						'source_element_id' => (string) $input['source_element_id'],
						'target_element_id' => (string) $input['target_element_id'],
						'message'           => 'Component variant sync produced no change',
						'link'              => get_permalink( (int) $input['id'] ),
						'dry_run'           => $dry_run,
						'changed_count'     => 0,
						'changed_ids'       => array(),
						'cache'             => $cache_details,
					);
				}

				if ( $dry_run ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'           => true,
						'id'                => (int) $input['id'],
						'source_element_id' => (string) $input['source_element_id'],
						'target_element_id' => (string) $input['target_element_id'],
						'message'           => 'Dry run: component variant would be synced',
						'link'              => get_permalink( (int) $input['id'] ),
						'dry_run'           => true,
						'changed_count'     => count( $changed_ids ),
						'changed_ids'       => $changed_ids,
						'cache'             => $cache_details,
					);
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['target_element_id'], $updated );
				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}
				update_post_meta( (int) $input['id'], '_elementor_data', wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'           => true,
					'id'                => (int) $input['id'],
					'source_element_id' => (string) $input['source_element_id'],
					'target_element_id' => (string) $input['target_element_id'],
					'message'           => 'Component variant synced successfully',
					'link'              => get_permalink( (int) $input['id'] ),
					'dry_run'           => false,
					'changed_count'     => count( $changed_ids ),
					'changed_ids'       => $changed_ids,
					'cache'             => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Element
	// =========================================================================
	wp_register_ability(
		'elementor/delete-element',
		array(
			'label'               => 'Delete Elementor Element',
			'description'         => 'Deletes a specific Elementor element (container or widget) by ID from a page/template. Supports cache_scope (`post` default, `site` for stronger invalidation, `none` for debugging).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the element.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Element ID to delete.',
					),
					'force_delete' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Allow deletion of top-level elements or populated containers/widgets that would otherwise be blocked as a safety guard.',
					),
					'cache_scope' => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'post', 'site' ),
						'default'     => 'post',
						'description' => 'Cache invalidation scope after write. `post` clears post-level caches and touches the post; `site` also clears Elementor site-wide cache; `none` skips cache invalidation.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'message'    => array( 'type' => 'string' ),
					'link'       => array( 'type' => 'string' ),
					'deleted'    => array( 'type' => 'boolean' ),
					'cache'      => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				$force_delete = ! empty( $input['force_delete'] );

				$target_meta  = null;
				$find_element = function ( $elements, string $target_id, int $depth = 0 ) use ( &$find_element, &$target_meta ): bool {
					if ( ! is_array( $elements ) ) {
						return false;
					}

					foreach ( $elements as $element ) {
						if ( isset( $element['id'] ) && $element['id'] === $target_id ) {
							$target_meta = array(
								'element' => $element,
								'depth'   => $depth,
							);
							return true;
						}

						if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
							if ( $find_element( $element['elements'], $target_id, $depth + 1 ) ) {
								return true;
							}
						}
					}

					return false;
				};

				$find_element( $data, (string) $input['element_id'] );

				if ( ! is_array( $target_meta ) ) {
					return array(
						'success'    => false,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'deleted'    => false,
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				if ( ! $force_delete ) {
					$target_element   = is_array( $target_meta['element'] ?? null ) ? $target_meta['element'] : array();
					$target_depth     = (int) ( $target_meta['depth'] ?? 0 );
					$target_children  = isset( $target_element['elements'] ) && is_array( $target_element['elements'] ) ? $target_element['elements'] : array();
					$has_children     = ! empty( $target_children );

					if ( 0 === $target_depth ) {
						return array(
							'success'    => false,
							'id'         => (int) $input['id'],
							'element_id' => (string) $input['element_id'],
							'deleted'    => false,
							'message'    => 'Refusing to delete a top-level Elementor element without force_delete=true',
						);
					}

					if ( $has_children ) {
						return array(
							'success'    => false,
							'id'         => (int) $input['id'],
							'element_id' => (string) $input['element_id'],
							'deleted'    => false,
							'message'    => 'Refusing to delete a populated Elementor element without force_delete=true',
						);
					}
				}

				$deleted = false;
				$delete_element = function ( &$elements, string $target_id ) use ( &$delete_element, &$deleted ): bool {
					if ( ! is_array( $elements ) ) {
						return false;
					}

					foreach ( $elements as $index => &$element ) {
						if ( isset( $element['id'] ) && $element['id'] === $target_id ) {
							unset( $elements[ $index ] );
							$elements = array_values( $elements );
							$deleted  = true;
							return true;
						}

						if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
							if ( $delete_element( $element['elements'], $target_id ) ) {
								return true;
							}
						}
					}

					return false;
				};

				$delete_element( $data, (string) $input['element_id'] );

				if ( ! $deleted ) {
					return array(
						'success'    => false,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'deleted'    => false,
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				$data      = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				$requested_cache_scope = mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' );
				if ( is_string( $elementor_data ) && $elementor_data === $json_data ) {
					$cache_details = mcp_abilities_elementor_build_noop_cache_details( $requested_cache_scope );
					$cache_details['post_id'] = (int) $input['id'];
					return array(
						'success'    => true,
						'id'         => (int) $input['id'],
						'element_id' => (string) $input['element_id'],
						'message'    => 'Element deletion produced no change - no write performed',
						'link'       => get_permalink( $input['id'] ),
						'deleted'    => false,
						'cache'      => $cache_details,
					);
				}

				update_post_meta( $input['id'], '_elementor_data', wp_slash( $json_data ) );

				$cache_details = mcp_abilities_elementor_invalidate_after_write(
					(int) $input['id'],
					$requested_cache_scope
				);

				return array(
					'success'    => true,
					'id'         => (int) $input['id'],
					'element_id' => (string) $input['element_id'],
					'message'    => 'Element "' . $input['element_id'] . '" deleted successfully',
					'link'       => get_permalink( $input['id'] ),
					'deleted'    => true,
					'cache'      => $cache_details,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Element
	// =========================================================================
	wp_register_ability(
		'elementor/get-element',
		array(
			'label'               => 'Get Elementor Element',
			'description'         => 'Retrieves a single Elementor element (container or widget) by element ID from a page.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the element.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'Element ID to retrieve.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'path'       => array( 'type' => 'array' ),
					'element'    => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to access this post' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && json_last_error() !== JSON_ERROR_NONE ) {
					return array( 'success' => false, 'message' => 'Failed to parse Elementor data' );
				}

				$found_element = null;
				$found_path    = array();

				$find_element = function ( $elements, $target_id, $path = array() ) use ( &$find_element, &$found_element, &$found_path ) {
					foreach ( $elements as $element ) {
						$element_id   = $element['id'] ?? '';
						$element_path = $path;
						if ( '' !== $element_id ) {
							$element_path[] = $element_id;
						}

						if ( $element_id === $target_id ) {
							$found_element = $element;
							$found_path    = $element_path;
							return true;
						}

						if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
							if ( $find_element( $element['elements'], $target_id, $element_path ) ) {
								return true;
							}
						}
					}

					return false;
				};

				$find_element( $data, $input['element_id'], array() );

				if ( null === $found_element ) {
					return array(
						'success'    => false,
						'id'         => $input['id'],
						'element_id' => $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				return array(
					'success'    => true,
					'id'         => $input['id'],
					'element_id' => $input['element_id'],
					'path'       => $found_path,
					'element'    => $found_element,
					'message'    => 'Element retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Find Elements
	// =========================================================================
	wp_register_ability(
		'elementor/find-elements',
		array(
			'label'               => 'Find Elementor Elements',
			'description'         => 'Searches Elementor elements by type, widget, settings, or text. Useful for locating IDs before targeted updates.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'             => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to search.',
					),
					'element_type'  => array(
						'type'        => 'string',
						'description' => 'Element type to match (container, section, widget).',
					),
					'widget_type'   => array(
						'type'        => 'string',
						'description' => 'Widget type to match (e.g., heading, image, text-editor).',
					),
					'settings_key'  => array(
						'type'        => 'string',
						'description' => 'Settings key to match (e.g., title, text, link).',
					),
					'settings_value' => array(
						'type'        => 'string',
						'description' => 'String to match within the settings value.',
					),
					'contains'      => array(
						'type'        => 'string',
						'description' => 'String to search for within the element JSON.',
					),
					'case_sensitive' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, perform case-sensitive matches.',
					),
					'limit'         => array(
						'type'        => 'integer',
						'default'     => 20,
						'description' => 'Maximum number of matches to return.',
					),
					'include_element' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, include the full element data in results.',
					),
					'include_path' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, include the element ID path from root.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'id'        => array( 'type' => 'integer' ),
					'total'     => array( 'type' => 'integer' ),
					'truncated' => array( 'type' => 'boolean' ),
					'matches'   => array( 'type' => 'array' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}

				$has_filters = ! empty( $input['element_type'] )
					|| ! empty( $input['widget_type'] )
					|| ! empty( $input['settings_key'] )
					|| ! empty( $input['settings_value'] )
					|| ! empty( $input['contains'] );

				if ( ! $has_filters ) {
					return array( 'success' => false, 'message' => 'Provide at least one filter to search for elements' );
				}

				if ( ! empty( $input['settings_value'] ) && empty( $input['settings_key'] ) ) {
					return array( 'success' => false, 'message' => 'settings_key is required when settings_value is provided' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to access this post' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data && json_last_error() !== JSON_ERROR_NONE ) {
					return array( 'success' => false, 'message' => 'Failed to parse Elementor data' );
				}

				$element_type    = $input['element_type'] ?? '';
				$widget_type     = $input['widget_type'] ?? '';
				$settings_key    = $input['settings_key'] ?? '';
				$settings_value  = $input['settings_value'] ?? null;
				$contains        = $input['contains'] ?? '';
				$case_sensitive  = ! empty( $input['case_sensitive'] );
				$include_element = ! empty( $input['include_element'] );
				$include_path    = ! empty( $input['include_path'] );
				$limit           = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
				$limit           = max( 1, min( 200, $limit ) );

				$matches   = array();
				$truncated = false;

				$match_text = function ( string $haystack, string $needle ) use ( $case_sensitive ): bool {
					if ( '' === $needle ) {
						return true;
					}
					if ( $case_sensitive ) {
						return false !== strpos( $haystack, $needle );
					}
					return false !== stripos( $haystack, $needle );
				};

				$search_elements = function ( $elements, $path = array() ) use (
					&$search_elements,
					$element_type,
					$widget_type,
					$settings_key,
					$settings_value,
					$contains,
					$include_element,
					$include_path,
					$match_text,
					$limit,
					&$matches,
					&$truncated
				) {
					foreach ( $elements as $element ) {
						if ( $truncated ) {
							return;
						}

						$element_id   = $element['id'] ?? '';
						$element_path = $path;
						if ( '' !== $element_id ) {
							$element_path[] = $element_id;
						}

						$is_match = true;

						if ( '' !== $element_type && ( $element['elType'] ?? '' ) !== $element_type ) {
							$is_match = false;
						}

						if ( '' !== $widget_type && ( $element['widgetType'] ?? '' ) !== $widget_type ) {
							$is_match = false;
						}

						if ( $is_match && '' !== $settings_key ) {
							if ( empty( $element['settings'] ) || ! array_key_exists( $settings_key, $element['settings'] ) ) {
								$is_match = false;
							} elseif ( null !== $settings_value ) {
								$raw_value = $element['settings'][ $settings_key ];
								if ( is_array( $raw_value ) || is_object( $raw_value ) ) {
									$raw_value = wp_json_encode( $raw_value );
								}
								$raw_value = is_string( $raw_value ) ? $raw_value : (string) $raw_value;
								if ( ! $match_text( $raw_value, (string) $settings_value ) ) {
									$is_match = false;
								}
							}
						}

						if ( $is_match && '' !== $contains ) {
							$haystack = wp_json_encode( $element );
							if ( ! $match_text( $haystack, $contains ) ) {
								$is_match = false;
							}
						}

						if ( $is_match ) {
							$entry = array(
								'id'          => $element_id,
								'el_type'     => $element['elType'] ?? '',
								'widget_type' => $element['widgetType'] ?? '',
							);
							if ( $include_path ) {
								$entry['path'] = $element_path;
							}
							if ( $include_element ) {
								$entry['element'] = $element;
							}
							$matches[] = $entry;

							if ( count( $matches ) >= $limit ) {
								$truncated = true;
								return;
							}
						}

						if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
							$search_elements( $element['elements'], $element_path );
						}
					}
				};

				$search_elements( $data, array() );

				$message = $truncated
					? "Returned first {$limit} match(es) out of more results"
					: 'Matches retrieved successfully';

				return array(
					'success'   => true,
					'id'        => $input['id'],
					'total'     => count( $matches ),
					'truncated' => $truncated,
					'matches'   => $matches,
					'message'   => $message,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - List Templates
	// =========================================================================
	wp_register_ability(
		'elementor/list-templates',
		array(
			'label'               => 'List Elementor Templates',
			'description'         => 'Lists all saved Elementor templates (sections, pages, containers, etc.).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'page', 'section', 'container', 'loop-item', 'header', 'footer', 'single', 'archive', 'popup' ),
						'default'     => 'all',
						'description' => 'Filter by template type.',
					),
					'sub_type' => array(
						'type'        => 'string',
						'description' => 'Filter by template sub type (e.g., product, product-archive).',
					),
					'sub_type_like' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, match sub_type using LIKE instead of exact match.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'templates' => array( 'type' => 'array' ),
					'total'     => array( 'type' => 'integer' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'post_type'      => 'elementor_library',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
				);

				$type_filter = $input['type'] ?? 'all';
				if ( 'all' !== $type_filter ) {
					// Use taxonomy query instead of meta_query for better performance.
					$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Necessary for template type filtering.
						array(
							'taxonomy' => 'elementor_library_type',
							'field'    => 'slug',
							'terms'    => $type_filter,
						),
					);
				}

				$sub_type = $input['sub_type'] ?? '';
				if ( '' !== $sub_type ) {
					$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Optional sub type filter.
						array(
							'key'     => '_elementor_template_sub_type',
							'value'   => $sub_type,
							'compare' => ! empty( $input['sub_type_like'] ) ? 'LIKE' : '=',
						),
					);
				}

				$query     = new WP_Query( $args );
				$templates = array();

				foreach ( $query->posts as $template ) {
					$template_type = get_post_meta( $template->ID, '_elementor_template_type', true );
					$template_sub_type = get_post_meta( $template->ID, '_elementor_template_sub_type', true );
					$templates[]   = array(
						'id'       => $template->ID,
						'title'    => $template->post_title,
						'type'     => $template_type ?: 'unknown',
						'sub_type' => $template_sub_type ?: '',
						'date'     => $template->post_date,
						'modified' => $template->post_modified,
					);
				}

				return array(
					'success'   => true,
					'templates' => $templates,
					'total'     => count( $templates ),
					'message'   => 'Templates retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Clear Cache
	// =========================================================================
	wp_register_ability(
		'elementor/clear-cache',
		array(
			'label'               => 'Clear Elementor Cache',
			'description'         => 'Clears Elementor cache for a specific post or the entire site. Post scope clears post-level caches/meta without changing post modified timestamps; site scope also clears Elementor site-wide cache files.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'  => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to clear cache for. If omitted, clears all Elementor cache.',
					),
					'all' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, clears all Elementor cache site-wide.',
					),
					'scope' => array(
						'type'        => 'string',
						'enum'        => array( 'post', 'site' ),
						'description' => 'Optional alias for cache scope. `site` behaves like `all=true`. `post` requires `id`.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'cache'   => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$scope = mcp_abilities_elementor_normalize_cache_scope( $input['scope'] ?? '', '' );
				if ( '' === $scope ) {
					$scope = ! empty( $input['all'] ) ? 'site' : 'post';
				}

				if ( 'site' === $scope ) {
					$site_cache_result = mcp_abilities_elementor_clear_site_cache();
					$cache_details = array(
						'requested_scope'         => 'site',
						'effective_scope'         => ! empty( $site_cache_result['elementor_cache_cleared'] ) ? 'site' : 'none',
						'elementor_cache_cleared' => ! empty( $site_cache_result['elementor_cache_cleared'] ),
						'warnings'                => ! empty( $site_cache_result['warnings'] ) && is_array( $site_cache_result['warnings'] ) ? $site_cache_result['warnings'] : array(),
					);

					return array(
						'success' => ! empty( $site_cache_result['elementor_cache_cleared'] ),
						'message' => ! empty( $site_cache_result['elementor_cache_cleared'] ) ? 'All Elementor cache cleared' : 'Failed to clear Elementor site cache',
						'cache'   => $cache_details,
					);
				}

				if ( ! empty( $input['id'] ) ) {
					$post = get_post( $input['id'] );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					if ( ! current_user_can( 'edit_post', $post->ID ) ) {
						return array( 'success' => false, 'message' => 'You do not have permission to clear cache for this post' );
					}

					$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], 'post', false );

					return array(
						'success' => true,
						'message' => "Cache cleared for post {$input['id']}",
						'cache'   => $cache_details,
					);
				}

				return array( 'success' => false, 'message' => 'Provide either "id", set "all" to true, or use scope="site"' );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
	);

	// =========================================================================
	// ELEMENTOR - Replace URLs
	// =========================================================================
	wp_register_ability(
		'elementor/replace-urls',
		array(
			'label'               => 'Replace URLs in Elementor Data',
			'description'         => 'Replaces URLs inside Elementor data across the site using Elementor\'s built-in tool.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'from', 'to' ),
				'properties'           => array(
					'from' => array(
						'type'        => 'string',
						'description' => 'Old URL to replace.',
					),
					'to'   => array(
						'type'        => 'string',
						'description' => 'New URL to apply.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'from'    => array( 'type' => 'string' ),
					'to'      => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['from'] ) || empty( $input['to'] ) ) {
					return array( 'success' => false, 'message' => 'Both "from" and "to" URLs are required' );
				}

				if ( ! class_exists( '\Elementor\Utils' ) ) {
					return array( 'success' => false, 'message' => 'Elementor utilities are not available' );
				}

				try {
					$result = \Elementor\Utils::replace_urls( $input['from'], $input['to'] );
				} catch ( \Exception $e ) {
					return array( 'success' => false, 'message' => $e->getMessage() );
				}

				return array(
					'success' => true,
					'from'    => $input['from'],
					'to'      => $input['to'],
					'message' => $result,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);
	// =========================================================================
	// ELEMENTOR - Create Template
	// =========================================================================
	wp_register_ability(
		'elementor/create-template',
		array(
			'label'               => 'Create Elementor Template',
			'description'         => 'Creates a new Elementor template (page, section, popup, header, footer, etc.). For popups, can set display conditions and triggers.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title', 'type' ),
				'properties'           => array(
					'title'              => array(
						'type'        => 'string',
						'description' => 'Template title.',
					),
					'type'               => array(
						'type'        => 'string',
						'enum'        => array( 'page', 'section', 'container', 'loop-item', 'header', 'footer', 'single', 'archive', 'popup' ),
						'description' => 'Template type.',
					),
					'status'             => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'private' ),
						'default'     => 'publish',
						'description' => 'Post status.',
					),
					'data'               => array(
						'type'        => 'array',
						'description' => 'Initial Elementor data structure. If omitted, creates empty template.',
					),
					'page_settings'      => array(
						'type'        => 'object',
						'description' => 'Elementor page settings (dimensions, background, etc.).',
					),
					'template_sub_type'  => array(
						'type'        => 'string',
						'description' => 'Template sub type for theme builder (e.g., product, product-archive).',
					),
					'conditions'         => array(
						'type'        => 'array',
						'description' => 'Display conditions for theme builder templates (header/footer/popup). Format: [["include", "general"]] for entire site.',
					),
					'popup_display'      => array(
						'type'        => 'object',
						'description' => 'Popup display settings: timing, triggers, etc.',
						'properties'  => array(
							'triggers'         => array(
								'type'        => 'array',
								'description' => 'Trigger types: on_page_load, on_scroll, on_click, on_exit_intent, after_inactivity.',
							),
							'timing'           => array(
								'type'        => 'object',
								'description' => 'Timing settings: show_after (seconds), show_times (times to show), show_times_until (timestamp).',
							),
						),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'type'    => array( 'type' => 'string' ),
					'sub_type' => array( 'type' => 'string' ),
					'link'    => array( 'type' => 'string' ),
					'edit'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['title'] ) ) {
					return array( 'success' => false, 'message' => 'Template title is required' );
				}
				if ( empty( $input['type'] ) ) {
					return array( 'success' => false, 'message' => 'Template type is required' );
				}

				$allowed_types = array( 'page', 'section', 'container', 'loop-item', 'header', 'footer', 'single', 'archive', 'popup' );
				if ( ! in_array( $input['type'], $allowed_types, true ) ) {
					return array( 'success' => false, 'message' => 'Invalid template type: ' . $input['type'] );
				}

				// Check if Elementor is loaded.
				if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
					return array( 'success' => false, 'message' => 'Elementor is not loaded' );
				}

				// Use Elementor's Documents Manager to create the template properly.
				// This ensures all internal registration and hooks are triggered.
				$documents_manager = \Elementor\Plugin::$instance->documents;

				// Check if the document type is registered.
				$document_types = $documents_manager->get_document_types();
				if ( ! isset( $document_types[ $input['type'] ] ) ) {
					return array( 'success' => false, 'message' => 'Document type "' . $input['type'] . '" is not registered. Make sure Elementor Pro is active for theme builder templates.' );
				}

				// Create the document using Elementor's native API.
				$post_data = array(
					'post_title'  => sanitize_text_field( $input['title'] ),
					'post_type'   => 'elementor_library',
					'post_status' => $input['status'] ?? 'publish',
				);

				$document = $documents_manager->create( $input['type'], $post_data );

				if ( is_wp_error( $document ) ) {
					return array( 'success' => false, 'message' => 'Failed to create template: ' . $document->get_error_message() );
				}

				if ( ! $document ) {
					return array( 'success' => false, 'message' => 'Failed to create template document' );
				}

				$post_id = $document->get_main_id();

				// Set Elementor data.
				$elementor_data = $input['data'] ?? array();
				$elementor_data = mcp_abilities_elementor_normalize_background_container_subtrees( $elementor_data );
				if ( ! empty( $elementor_data ) ) {
					update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );
				} else {
					// Create minimal empty structure.
					update_post_meta( $post_id, '_elementor_data', '[]' );
				}

				// Set page settings if provided.
				if ( ! empty( $input['page_settings'] ) && is_array( $input['page_settings'] ) ) {
					update_post_meta( $post_id, '_elementor_page_settings', $input['page_settings'] );
				}

				// Set template sub type if provided.
				if ( array_key_exists( 'template_sub_type', $input ) ) {
					$sub_type = (string) $input['template_sub_type'];
					if ( '' === $sub_type ) {
						delete_post_meta( $post_id, '_elementor_template_sub_type' );
					} else {
						update_post_meta( $post_id, '_elementor_template_sub_type', $sub_type );
					}
				}

				// Set display conditions (for theme builder templates).
				if ( array_key_exists( 'conditions', $input ) && is_array( $input['conditions'] ) ) {
					$conditions_to_save = mcp_abilities_elementor_normalize_conditions( $input['conditions'] );
					mcp_abilities_elementor_save_conditions( $post_id, $input['type'], $conditions_to_save );
				}

				// Set popup display settings (for popups).
				if ( 'popup' === $input['type'] && ! empty( $input['popup_display'] ) && is_array( $input['popup_display'] ) ) {
					$popup_settings = mcp_abilities_elementor_build_popup_display_settings( $input['popup_display'] );
					update_post_meta( $post_id, '_elementor_popup_display_settings', $popup_settings );
				}

				// Set taxonomy term for template type (Elementor uses this for filtering).
				wp_set_object_terms( $post_id, $input['type'], 'elementor_library_type' );

				// Build edit URL.
				$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=elementor' );

				$template_sub_type = get_post_meta( $post_id, '_elementor_template_sub_type', true );

				return array(
					'success' => true,
					'id'      => $post_id,
					'title'   => $input['title'],
					'type'    => $input['type'],
					'sub_type' => $template_sub_type ?: '',
					'link'    => get_permalink( $post_id ),
					'edit'    => $edit_url,
					'message' => ucfirst( $input['type'] ) . ' template created successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Template
	// =========================================================================
	wp_register_ability(
		'elementor/update-template',
		array(
			'label'               => 'Update Elementor Template',
			'description'         => 'Updates an existing Elementor template (title, status, data, conditions, popup settings).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'                 => array(
						'type'        => 'integer',
						'description' => 'Template ID to update.',
					),
					'title'              => array(
						'type'        => 'string',
						'description' => 'New template title.',
					),
					'status'             => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'private' ),
						'description' => 'Post status.',
					),
					'data'               => array(
						'type'        => 'array',
						'description' => 'Elementor data structure. Replaces existing data.',
					),
					'page_settings'      => array(
						'type'        => 'object',
						'description' => 'Elementor page settings. Merged with existing unless replace_settings is true.',
					),
					'replace_settings'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, replace page_settings entirely instead of merging.',
					),
					'template_sub_type'  => array(
						'type'        => 'string',
						'description' => 'Template sub type for theme builder (e.g., product, product-archive).',
					),
					'conditions'         => array(
						'type'        => 'array',
						'description' => 'Display conditions for theme builder templates.',
					),
					'popup_display'      => array(
						'type'        => 'object',
						'description' => 'Popup display settings (triggers, timing).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'type'    => array( 'type' => 'string' ),
					'sub_type' => array( 'type' => 'string' ),
					'link'    => array( 'type' => 'string' ),
					'edit'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Template ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Template not found' );
				}

				if ( 'elementor_library' !== $post->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor template' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this template' );
				}

				$template_type = get_post_meta( $post->ID, '_elementor_template_type', true );

				// Update post data if provided.
				$post_update = array( 'ID' => $post->ID );
				$needs_update = false;

				if ( ! empty( $input['title'] ) ) {
					$post_update['post_title'] = sanitize_text_field( $input['title'] );
					$needs_update = true;
				}

				if ( ! empty( $input['status'] ) ) {
					$post_update['post_status'] = $input['status'];
					$needs_update = true;
				}

				if ( $needs_update ) {
					$result = wp_update_post( $post_update, true );
					if ( is_wp_error( $result ) ) {
						return array( 'success' => false, 'message' => 'Failed to update template: ' . $result->get_error_message() );
					}
				}

				// Update Elementor data if provided.
				if ( isset( $input['data'] ) && is_array( $input['data'] ) ) {
					$normalized_data = mcp_abilities_elementor_normalize_background_container_subtrees( $input['data'] );
					update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $normalized_data ) ) );
					delete_post_meta( $post->ID, '_elementor_css' );
				}

				// Update page settings if provided.
				if ( ! empty( $input['page_settings'] ) && is_array( $input['page_settings'] ) ) {
					$replace = ! empty( $input['replace_settings'] );
					if ( $replace ) {
						update_post_meta( $post->ID, '_elementor_page_settings', $input['page_settings'] );
					} else {
						$existing = get_post_meta( $post->ID, '_elementor_page_settings', true );
						$existing = is_array( $existing ) ? $existing : array();
						update_post_meta( $post->ID, '_elementor_page_settings', array_merge( $existing, $input['page_settings'] ) );
					}
					delete_post_meta( $post->ID, '_elementor_css' );
				}

				// Update template sub type if provided.
				if ( array_key_exists( 'template_sub_type', $input ) ) {
					$sub_type = (string) $input['template_sub_type'];
					if ( '' === $sub_type ) {
						delete_post_meta( $post->ID, '_elementor_template_sub_type' );
					} else {
						update_post_meta( $post->ID, '_elementor_template_sub_type', $sub_type );
					}
				}

				// Update display conditions if provided.
				if ( array_key_exists( 'conditions', $input ) && is_array( $input['conditions'] ) ) {
					$conditions_to_save = mcp_abilities_elementor_normalize_conditions( $input['conditions'] );
					mcp_abilities_elementor_save_conditions( $post->ID, $template_type, $conditions_to_save );
				}

				// Update popup display settings if provided.
				if ( 'popup' === $template_type && ! empty( $input['popup_display'] ) && is_array( $input['popup_display'] ) ) {
					$existing_popup_settings = get_post_meta( $post->ID, '_elementor_popup_display_settings', true );
					$popup_settings = mcp_abilities_elementor_build_popup_display_settings(
						$input['popup_display'],
						is_array( $existing_popup_settings ) ? $existing_popup_settings : array()
					);
					update_post_meta( $post->ID, '_elementor_popup_display_settings', $popup_settings );
				}

				// Refresh post data.
				$post = get_post( $post->ID );
				$edit_url = admin_url( 'post.php?post=' . $post->ID . '&action=elementor' );
				$template_sub_type = get_post_meta( $post->ID, '_elementor_template_sub_type', true );

				return array(
					'success' => true,
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'type'    => $template_type,
					'sub_type' => $template_sub_type ?: '',
					'link'    => get_permalink( $post->ID ),
					'edit'    => $edit_url,
					'message' => 'Template updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
	);

	// =========================================================================
	// ELEMENTOR - Delete Template
	// =========================================================================
	wp_register_ability(
		'elementor/delete-template',
		array(
			'label'               => 'Delete Elementor Template',
			'description'         => 'Deletes an Elementor template. By default moves to trash; use force=true for permanent deletion.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'Template ID to delete.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, permanently delete instead of moving to trash.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'type'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Template ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Template not found' );
				}

				if ( 'elementor_library' !== $post->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor template' );
				}

				if ( ! current_user_can( 'delete_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to delete this template' );
				}

				$template_type = get_post_meta( $post->ID, '_elementor_template_type', true );
				$title = $post->post_title;
				$force = ! empty( $input['force'] );

				// Remove from theme builder conditions.
				$theme_builder_conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
				if ( isset( $theme_builder_conditions[ $template_type ][ $post->ID ] ) ) {
					unset( $theme_builder_conditions[ $template_type ][ $post->ID ] );
					update_option( 'elementor_pro_theme_builder_conditions', $theme_builder_conditions );
				}

				// Delete or trash the post.
				// Note: wp_delete_post() ignores $force for custom post types, so we must use wp_trash_post() explicitly.
				if ( $force ) {
					$result = wp_delete_post( $post->ID, true );
				} else {
					$result = wp_trash_post( $post->ID );
				}
				if ( ! $result ) {
					return array( 'success' => false, 'message' => 'Failed to delete template' );
				}

				$action = $force ? 'permanently deleted' : 'moved to trash';

				return array(
					'success' => true,
					'id'      => $input['id'],
					'title'   => $title,
					'type'    => $template_type,
					'message' => "Template \"{$title}\" {$action}",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Template
	// =========================================================================
	wp_register_ability(
		'elementor/get-template',
		array(
			'label'               => 'Get Elementor Template',
			'description'         => 'Retrieves a single Elementor template with all its data, settings, and conditions.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Template ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'id'             => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'type'           => array( 'type' => 'string' ),
					'sub_type'       => array( 'type' => 'string' ),
					'status'         => array( 'type' => 'string' ),
					'data'           => array( 'type' => 'array' ),
					'page_settings'  => array( 'type' => 'object' ),
					'conditions'     => array( 'type' => 'array' ),
					'popup_settings' => array( 'type' => 'object' ),
					'link'           => array( 'type' => 'string' ),
					'edit'           => array( 'type' => 'string' ),
					'message'        => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Template ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Template not found' );
				}

				if ( 'elementor_library' !== $post->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor template' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to access this template' );
				}

				$template_type  = get_post_meta( $post->ID, '_elementor_template_type', true );
				$template_sub_type = get_post_meta( $post->ID, '_elementor_template_sub_type', true );
				$elementor_data = mcp_abilities_elementor_get_raw_data_meta( $post->ID );
				$page_settings  = get_post_meta( $post->ID, '_elementor_page_settings', true );
				$conditions     = get_post_meta( $post->ID, '_elementor_conditions', true );
				$popup_settings = get_post_meta( $post->ID, '_elementor_popup_display_settings', true );
				$decode_error   = null;
				$data           = mcp_abilities_elementor_decode_data_meta( $elementor_data, $decode_error );
				$message        = 'Template retrieved successfully';
				if ( null !== $decode_error ) {
					$message .= ' (template data was invalid JSON and was normalized to an empty array)';
				}

				return array(
					'success'        => true,
					'id'             => $post->ID,
					'title'          => $post->post_title,
					'type'           => $template_type ?: 'unknown',
					'sub_type'       => $template_sub_type ?: '',
					'status'         => $post->post_status,
					'data'           => $data,
					'page_settings'  => $page_settings ?: array(),
					'conditions'     => $conditions ?: array(),
					'popup_settings' => $popup_settings ?: array(),
					'link'           => get_permalink( $post->ID ),
					'edit'           => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ),
					'message'        => $message,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Theme Builder Conditions
	// =========================================================================
	wp_register_ability(
		'elementor/get-theme-builder-conditions',
		array(
			'label'               => 'Get Theme Builder Conditions',
			'description'         => 'Retrieves Elementor theme builder display conditions. Filter by template type or template ID.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type'        => 'string',
						'description' => 'Template type (header, footer, single, archive, popup). Optional.',
					),
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Template ID to fetch conditions for. Optional.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'type'       => array( 'type' => 'string' ),
					'id'         => array( 'type' => 'integer' ),
					'conditions' => array( 'type' => 'array' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
				if ( ! is_array( $conditions ) ) {
					$conditions = array();
				}

				if ( ! empty( $input['id'] ) ) {
					$post = get_post( $input['id'] );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Template not found' );
					}

					if ( ! current_user_can( 'edit_post', $post->ID ) ) {
						return array( 'success' => false, 'message' => 'You do not have permission to access this template' );
					}

					$template_type = $input['type'] ?? get_post_meta( $post->ID, '_elementor_template_type', true );
					if ( empty( $template_type ) ) {
						return array( 'success' => false, 'message' => 'Template type is required to fetch conditions' );
					}

					$template_conditions = $conditions[ $template_type ][ $post->ID ] ?? array();

					return array(
						'success'    => true,
						'type'       => $template_type,
						'id'         => $post->ID,
						'conditions' => $template_conditions,
						'message'    => 'Theme builder conditions retrieved successfully',
					);
				}

				if ( ! empty( $input['type'] ) ) {
					$type_conditions = $conditions[ $input['type'] ] ?? array();

					return array(
						'success'    => true,
						'type'       => $input['type'],
						'conditions' => $type_conditions,
						'message'    => 'Theme builder conditions retrieved successfully',
					);
				}

				return array(
					'success'    => true,
					'conditions' => $conditions,
					'message'    => 'Theme builder conditions retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Theme Builder Conditions
	// =========================================================================
	wp_register_ability(
		'elementor/update-theme-builder-conditions',
		array(
			'label'               => 'Update Theme Builder Conditions',
			'description'         => 'Updates Elementor theme builder display conditions for a template.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'conditions' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Template ID to update conditions for.',
					),
					'type'       => array(
						'type'        => 'string',
						'description' => 'Template type (header, footer, single, archive, popup). Optional if template has a saved type.',
					),
					'conditions' => array(
						'type'        => 'array',
						'description' => 'Conditions array to apply (will be normalized into Elementor format).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'type'       => array( 'type' => 'string' ),
					'conditions' => array( 'type' => 'array' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Template ID is required' );
				}
				if ( ! isset( $input['conditions'] ) || ! is_array( $input['conditions'] ) ) {
					return array( 'success' => false, 'message' => 'Conditions array is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Template not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this template' );
				}

				$template_type = $input['type'] ?? get_post_meta( $post->ID, '_elementor_template_type', true );
				if ( empty( $template_type ) ) {
					return array( 'success' => false, 'message' => 'Template type is required to update conditions' );
				}

				$conditions_to_save = mcp_abilities_elementor_normalize_conditions( $input['conditions'] );
				mcp_abilities_elementor_save_conditions( $post->ID, $template_type, $conditions_to_save );

				return array(
					'success'    => true,
					'id'         => $post->ID,
					'type'       => $template_type,
					'conditions' => $conditions_to_save,
					'message'    => 'Theme builder conditions updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
	);

	// =========================================================================
	// ELEMENTOR - Restore Template
	// =========================================================================
	wp_register_ability(
		'elementor/restore-template',
		array(
			'label'               => 'Restore Elementor Template',
			'description'         => 'Restores a trashed Elementor template back to draft or published status.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'Template ID to restore.',
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft' ),
						'default'     => 'draft',
						'description' => 'Status to restore to.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Template ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Template not found' );
				}

				if ( 'elementor_library' !== $post->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor template' );
				}

				if ( 'trash' !== $post->post_status ) {
					return array( 'success' => false, 'message' => 'Template is not in trash' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to restore this template' );
				}

				$new_status = $input['status'] ?? 'draft';
				$result = wp_update_post( array(
					'ID'          => $post->ID,
					'post_status' => $new_status,
				), true );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => 'Failed to restore template: ' . $result->get_error_message() );
				}

				return array(
					'success' => true,
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'status'  => $new_status,
					'message' => "Template \"{$post->post_title}\" restored to {$new_status}",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Empty Trash
	// =========================================================================
	wp_register_ability(
		'elementor/empty-trash',
		array(
			'label'               => 'Empty Elementor Template Trash',
			'description'         => 'Permanently deletes all trashed Elementor templates. This action cannot be undone.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'page', 'section', 'container', 'popup', 'header', 'footer', 'single', 'archive' ),
						'default'     => 'all',
						'description' => 'Only empty trash for specific template type, or all.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'deleted' => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! current_user_can( 'delete_posts' ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to empty trash' );
				}

					$args = array(
						'post_type'      => 'elementor_library',
						'post_status'    => 'trash',
						'posts_per_page' => 200,
						'paged'          => 1,
						'fields'         => 'ids',
					);

				$type_filter = $input['type'] ?? 'all';
				if ( 'all' !== $type_filter ) {
					$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Necessary for type filtering.
						array(
							'taxonomy' => 'elementor_library_type',
							'field'    => 'slug',
							'terms'    => $type_filter,
						),
					);
				}

					$deleted = 0;
					do {
						$trashed = get_posts( $args );
						foreach ( $trashed as $post_id ) {
							if ( wp_delete_post( $post_id, true ) ) {
								$deleted++;
							}
						}
						$args['paged']++;
					} while ( ! empty( $trashed ) );

				$type_msg = 'all' === $type_filter ? '' : " ({$type_filter} templates)";
				return array(
					'success' => true,
					'deleted' => $deleted,
					'message' => "Permanently deleted {$deleted} template(s) from trash{$type_msg}",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Duplicate Template
	// =========================================================================
	wp_register_ability(
		'elementor/duplicate-template',
		array(
			'label'               => 'Duplicate Elementor Template',
			'description'         => 'Creates a copy of an existing Elementor template with all its data and settings.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'Template ID to duplicate.',
					),
					'title' => array(
						'type'        => 'string',
						'description' => 'Title for the new template. Defaults to "Copy of [original title]".',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'integer' ),
					'original_id' => array( 'type' => 'integer' ),
					'title'       => array( 'type' => 'string' ),
					'type'        => array( 'type' => 'string' ),
					'edit'        => array( 'type' => 'string' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Template ID is required' );
				}

				$original = get_post( $input['id'] );
				if ( ! $original ) {
					return array( 'success' => false, 'message' => 'Template not found' );
				}

				if ( 'elementor_library' !== $original->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor template' );
				}

				if ( ! current_user_can( 'edit_post', $original->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to duplicate this template' );
				}

				$new_title = $input['title'] ?? 'Copy of ' . $original->post_title;

				// Create new post.
				$new_post_id = wp_insert_post( array(
					'post_title'  => sanitize_text_field( $new_title ),
					'post_type'   => 'elementor_library',
					'post_status' => 'draft',
				), true );

				if ( is_wp_error( $new_post_id ) ) {
					return array( 'success' => false, 'message' => 'Failed to create template: ' . $new_post_id->get_error_message() );
				}

				// Copy all meta.
				$meta_keys = array(
					'_elementor_template_type',
					'_elementor_template_sub_type',
					'_elementor_edit_mode',
					'_elementor_data',
					'_elementor_page_settings',
					'_elementor_conditions',
					'_elementor_popup_display_settings',
				);

				foreach ( $meta_keys as $key ) {
					$value = get_post_meta( $original->ID, $key, true );
					if ( '' !== $value && array() !== $value ) {
						update_post_meta( $new_post_id, $key, mcp_abilities_elementor_prepare_duplicated_meta_value( $key, $value ) );
					}
				}

				// Copy taxonomy terms.
				$terms = wp_get_object_terms( $original->ID, 'elementor_library_type', array( 'fields' => 'slugs' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					wp_set_object_terms( $new_post_id, $terms, 'elementor_library_type' );
				}

				$template_type = get_post_meta( $new_post_id, '_elementor_template_type', true );

				return array(
					'success'     => true,
					'id'          => $new_post_id,
					'original_id' => $original->ID,
					'title'       => $new_title,
					'type'        => $template_type ?: 'unknown',
					'edit'        => admin_url( 'post.php?post=' . $new_post_id . '&action=elementor' ),
					'message'     => "Template duplicated successfully as \"{$new_title}\"",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Export Template
	// =========================================================================
	wp_register_ability(
		'elementor/export-template',
		array(
			'label'               => 'Export Elementor Template',
			'description'         => 'Exports an Elementor template as JSON data that can be imported elsewhere.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Template ID to export.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'id'       => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'type'     => array( 'type' => 'string' ),
					'export'   => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Template ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Template not found' );
				}

				if ( 'elementor_library' !== $post->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor template' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to export this template' );
				}

				$template_type  = get_post_meta( $post->ID, '_elementor_template_type', true );
				$elementor_data = mcp_abilities_elementor_get_raw_data_meta( $post->ID );
				$page_settings  = get_post_meta( $post->ID, '_elementor_page_settings', true );
				$decode_error   = null;

				$export_data = array(
					'version'       => '1.0',
					'title'         => $post->post_title,
					'type'          => $template_type ?: 'page',
					'content'       => mcp_abilities_elementor_decode_data_meta( $elementor_data, $decode_error ),
					'page_settings' => $page_settings ?: array(),
				);

				return array(
					'success' => true,
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'type'    => $template_type ?: 'unknown',
					'export'  => $export_data,
					'message' => 'Template exported successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Import Template
	// =========================================================================
	wp_register_ability(
		'elementor/import-template',
		array(
			'label'               => 'Import Elementor Template',
			'description'         => 'Imports an Elementor template from JSON export data.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'data' ),
				'properties'           => array(
					'data'   => array(
						'type'        => 'object',
						'description' => 'Export data object from export-template ability.',
					),
					'title'  => array(
						'type'        => 'string',
						'description' => 'Override title for imported template.',
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft' ),
						'default'     => 'draft',
						'description' => 'Status for imported template.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'type'    => array( 'type' => 'string' ),
					'edit'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['data'] ) || ! is_array( $input['data'] ) ) {
					return array( 'success' => false, 'message' => 'Export data is required' );
				}

				$data = $input['data'];
				if ( empty( $data['content'] ) ) {
					return array( 'success' => false, 'message' => 'Invalid export data: missing content' );
				}

				$title         = $input['title'] ?? ( $data['title'] ?? 'Imported Template' );
				$template_type = $data['type'] ?? 'page';
				$status        = $input['status'] ?? 'draft';

				// Create the template.
				$post_id = wp_insert_post( array(
					'post_title'  => sanitize_text_field( $title ),
					'post_type'   => 'elementor_library',
					'post_status' => $status,
				), true );

				if ( is_wp_error( $post_id ) ) {
					return array( 'success' => false, 'message' => 'Failed to create template: ' . $post_id->get_error_message() );
				}

				// Set meta.
				$normalized_content = mcp_abilities_elementor_normalize_background_container_subtrees( $data['content'] );
				update_post_meta( $post_id, '_elementor_template_type', $template_type );
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $normalized_content ) ) );

				if ( ! empty( $data['page_settings'] ) ) {
					update_post_meta( $post_id, '_elementor_page_settings', $data['page_settings'] );
				}

				// Set taxonomy.
				wp_set_object_terms( $post_id, $template_type, 'elementor_library_type' );

				return array(
					'success' => true,
					'id'      => $post_id,
					'title'   => $title,
					'type'    => $template_type,
					'edit'    => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
					'message' => "Template \"{$title}\" imported successfully",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - List Custom Code
	// =========================================================================
	wp_register_ability(
		'elementor/list-custom-code',
		array(
			'label'               => 'List Elementor Custom Code',
			'description'         => 'Lists Elementor custom code snippets.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'private', 'trash', 'any' ),
						'default'     => 'publish',
						'description' => 'Filter by post status.',
					),
					'limit'  => array(
						'type'        => 'integer',
						'default'     => 50,
						'description' => 'Maximum number of snippets to return.',
					),
					'offset' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Offset for pagination.',
					),
					'include_code' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, include the snippet code.',
					),
					'include_meta' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, include all post meta for each snippet.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'snippets' => array( 'type' => 'array' ),
					'total'    => array( 'type' => 'integer' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				if ( ! post_type_exists( 'elementor_snippet' ) ) {
					return array( 'success' => false, 'message' => 'Elementor custom code post type is not available' );
				}

				$input  = is_array( $input ) ? $input : array();
				$status = $input['status'] ?? 'publish';
				$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
				$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
				$limit  = max( 1, min( 200, $limit ) );
				$offset = max( 0, $offset );

				$args = array(
					'post_type'      => 'elementor_snippet',
					'post_status'    => 'any' === $status ? array( 'publish', 'draft', 'private', 'trash' ) : $status,
					'posts_per_page' => $limit,
					'offset'         => $offset,
					'orderby'        => 'date',
					'order'          => 'DESC',
				);

				$query    = new WP_Query( $args );
				$snippets = array();

				foreach ( $query->posts as $snippet ) {
					$item = array(
						'id'       => $snippet->ID,
						'title'    => $snippet->post_title,
						'status'   => $snippet->post_status,
						'date'     => $snippet->post_date,
						'modified' => $snippet->post_modified,
					);

					if ( ! empty( $input['include_code'] ) ) {
						$item['code'] = $snippet->post_content;
					}

					if ( ! empty( $input['include_meta'] ) ) {
						$item['meta'] = get_post_meta( $snippet->ID );
					}

					$snippets[] = $item;
				}

				return array(
					'success'  => true,
					'snippets' => $snippets,
					'total'    => count( $snippets ),
					'message'  => 'Custom code snippets retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Custom Code
	// =========================================================================
	wp_register_ability(
		'elementor/get-custom-code',
		array(
			'label'               => 'Get Elementor Custom Code',
			'description'         => 'Retrieves a single Elementor custom code snippet.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Snippet ID.',
					),
					'include_meta' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, include all post meta.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string' ),
					'code'    => array( 'type' => 'string' ),
					'meta'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Snippet ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Snippet not found' );
				}

				if ( 'elementor_snippet' !== $post->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor custom code snippet' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to access this snippet' );
				}

				$response = array(
					'success' => true,
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'status'  => $post->post_status,
					'code'    => $post->post_content,
					'message' => 'Custom code snippet retrieved successfully',
				);

				if ( ! empty( $input['include_meta'] ) ) {
					$response['meta'] = get_post_meta( $post->ID );
				}

				return $response;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Create Custom Code
	// =========================================================================
	wp_register_ability(
		'elementor/create-custom-code',
		array(
			'label'               => 'Create Elementor Custom Code',
			'description'         => 'Creates a new Elementor custom code snippet.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title', 'code' ),
				'properties'           => array(
					'title' => array(
						'type'        => 'string',
						'description' => 'Snippet title.',
					),
					'code'  => array(
						'type'        => 'string',
						'description' => 'Code snippet content.',
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'private' ),
						'default'     => 'publish',
						'description' => 'Post status.',
					),
					'meta' => array(
						'type'        => 'object',
						'description' => 'Optional meta fields to store on the snippet.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['title'] ) ) {
					return array( 'success' => false, 'message' => 'Snippet title is required' );
				}
				if ( ! array_key_exists( 'code', $input ) ) {
					return array( 'success' => false, 'message' => 'Snippet code is required' );
				}

				if ( ! post_type_exists( 'elementor_snippet' ) ) {
					return array( 'success' => false, 'message' => 'Elementor custom code post type is not available' );
				}

				$post_id = wp_insert_post( array(
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_type'    => 'elementor_snippet',
					'post_status'  => $input['status'] ?? 'publish',
					'post_content' => (string) $input['code'],
				), true );

				if ( is_wp_error( $post_id ) ) {
					return array( 'success' => false, 'message' => 'Failed to create snippet: ' . $post_id->get_error_message() );
				}

				if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
					foreach ( $input['meta'] as $key => $value ) {
						update_post_meta( $post_id, (string) $key, $value );
					}
				}

				return array(
					'success' => true,
					'id'      => $post_id,
					'title'   => $input['title'],
					'status'  => $input['status'] ?? 'publish',
					'message' => 'Custom code snippet created successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Custom Code
	// =========================================================================
	wp_register_ability(
		'elementor/update-custom-code',
		array(
			'label'               => 'Update Elementor Custom Code',
			'description'         => 'Updates an existing Elementor custom code snippet.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Snippet ID.',
					),
					'title' => array(
						'type'        => 'string',
						'description' => 'New snippet title.',
					),
					'code'  => array(
						'type'        => 'string',
						'description' => 'New snippet code content.',
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'private' ),
						'description' => 'Post status.',
					),
					'meta' => array(
						'type'        => 'object',
						'description' => 'Meta fields to update.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Snippet ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Snippet not found' );
				}

				if ( 'elementor_snippet' !== $post->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor custom code snippet' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this snippet' );
				}

				$update = array( 'ID' => $post->ID );
				$has_update = false;

				if ( array_key_exists( 'title', $input ) ) {
					$update['post_title'] = sanitize_text_field( (string) $input['title'] );
					$has_update = true;
				}
				if ( array_key_exists( 'code', $input ) ) {
					$update['post_content'] = (string) $input['code'];
					$has_update = true;
				}
				if ( ! empty( $input['status'] ) ) {
					$update['post_status'] = $input['status'];
					$has_update = true;
				}

				if ( $has_update ) {
					$result = wp_update_post( $update, true );
					if ( is_wp_error( $result ) ) {
						return array( 'success' => false, 'message' => 'Failed to update snippet: ' . $result->get_error_message() );
					}
				}

				if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
					foreach ( $input['meta'] as $key => $value ) {
						update_post_meta( $post->ID, (string) $key, $value );
					}
				}

				$post = get_post( $post->ID );

				return array(
					'success' => true,
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'status'  => $post->post_status,
					'message' => 'Custom code snippet updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Delete Custom Code
	// =========================================================================
	wp_register_ability(
		'elementor/delete-custom-code',
		array(
			'label'               => 'Delete Elementor Custom Code',
			'description'         => 'Deletes an Elementor custom code snippet. Moves to trash unless force is true.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Snippet ID.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, permanently delete instead of moving to trash.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Snippet ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Snippet not found' );
				}

				if ( 'elementor_snippet' !== $post->post_type ) {
					return array( 'success' => false, 'message' => 'Post is not an Elementor custom code snippet' );
				}

				if ( ! current_user_can( 'delete_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to delete this snippet' );
				}

				$title = $post->post_title;
				$force = ! empty( $input['force'] );

				$result = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
				if ( ! $result ) {
					return array( 'success' => false, 'message' => 'Failed to delete snippet' );
				}

				$action = $force ? 'permanently deleted' : 'moved to trash';

				return array(
					'success' => true,
					'id'      => $post->ID,
					'title'   => $title,
					'message' => "Custom code snippet \"{$title}\" {$action}",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - List Form Submissions
	// =========================================================================
	wp_register_ability(
		'elementor/list-form-submissions',
		array(
			'label'               => 'List Elementor Form Submissions',
			'description'         => 'Lists Elementor form submissions (Elementor Pro).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'form_id' => array(
						'type'        => 'string',
						'description' => 'Filter by form ID if supported by the database schema.',
					),
					'form_name' => array(
						'type'        => 'string',
						'description' => 'Filter by form name if supported by the database schema.',
					),
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by post/page ID if supported by the database schema.',
					),
					'user_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by user ID if supported by the database schema.',
					),
					'status' => array(
						'type'        => 'string',
						'description' => 'Filter by submission status if supported by the database schema.',
					),
					'limit'  => array(
						'type'        => 'integer',
						'default'     => 50,
						'description' => 'Maximum number of submissions to return.',
					),
					'offset' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Offset for pagination.',
					),
					'include_values' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, include submission field values.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'          => array( 'type' => 'boolean' ),
					'submissions'      => array( 'type' => 'array' ),
					'total'            => array( 'type' => 'integer' ),
					'values_included'  => array( 'type' => 'boolean' ),
					'filters_applied'  => array( 'type' => 'array' ),
					'filters_ignored'  => array( 'type' => 'array' ),
					'message'          => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wpdb;

				$input = is_array( $input ) ? $input : array();

				$submissions_table = $wpdb->prefix . 'e_submissions';
				if ( ! mcp_abilities_elementor_table_exists( $submissions_table ) ) {
					return array( 'success' => false, 'message' => 'Elementor submissions table not found' );
				}

				$columns = mcp_abilities_elementor_table_columns( $submissions_table );
				$id_column = mcp_abilities_elementor_find_column( $columns, array( 'id', 'submission_id', 'submissionid' ) );
				$form_id_column = mcp_abilities_elementor_find_column( $columns, array( 'form_id', 'formid' ) );
				$form_name_column = mcp_abilities_elementor_find_column( $columns, array( 'form_name', 'formname', 'form_title', 'form' ) );
				$post_id_column = mcp_abilities_elementor_find_column( $columns, array( 'post_id', 'postid', 'page_id', 'pageid' ) );
				$user_id_column = mcp_abilities_elementor_find_column( $columns, array( 'user_id', 'userid', 'author_id' ) );
				$status_column = mcp_abilities_elementor_find_column( $columns, array( 'status', 'state' ) );
				$order_column = mcp_abilities_elementor_find_column( $columns, array( 'created_at', 'created', 'date_created', 'created_time', 'submitted_at', 'submitted', 'date_submitted', 'id' ) );

					$filters_applied = array();
					$filters_ignored = array();
					$where_clauses = array();
					$fallback_column = '' !== $id_column ? $id_column : ( ! empty( $columns ) ? (string) reset( $columns ) : '' );

					if ( '' === $fallback_column ) {
						return array( 'success' => false, 'message' => 'Could not determine a safe column for submissions query' );
					}

					if ( ! empty( $input['form_id'] ) ) {
						if ( '' !== $form_id_column ) {
							$where_clauses[] = $wpdb->prepare( '%i = %s', $form_id_column, (string) $input['form_id'] );
							$filters_applied[] = 'form_id';
						} else {
							$filters_ignored[] = 'form_id';
					}
				}

					if ( ! empty( $input['form_name'] ) ) {
						if ( '' !== $form_name_column ) {
							$where_clauses[] = $wpdb->prepare( '%i = %s', $form_name_column, (string) $input['form_name'] );
							$filters_applied[] = 'form_name';
						} else {
							$filters_ignored[] = 'form_name';
					}
				}

					if ( ! empty( $input['post_id'] ) ) {
						if ( '' !== $post_id_column ) {
							$where_clauses[] = $wpdb->prepare( '%i = %d', $post_id_column, (int) $input['post_id'] );
							$filters_applied[] = 'post_id';
						} else {
							$filters_ignored[] = 'post_id';
					}
				}

					if ( ! empty( $input['user_id'] ) ) {
						if ( '' !== $user_id_column ) {
							$where_clauses[] = $wpdb->prepare( '%i = %d', $user_id_column, (int) $input['user_id'] );
							$filters_applied[] = 'user_id';
						} else {
							$filters_ignored[] = 'user_id';
					}
				}

					if ( ! empty( $input['status'] ) ) {
						if ( '' !== $status_column ) {
							$where_clauses[] = $wpdb->prepare( '%i = %s', $status_column, (string) $input['status'] );
							$filters_applied[] = 'status';
						} else {
							$filters_ignored[] = 'status';
					}
				}

				$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
					$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
					$limit  = max( 1, min( 200, $limit ) );
					$offset = max( 0, $offset );

					$order_identifier = '' !== $order_column ? $order_column : $fallback_column;
						$sql = $wpdb->prepare( 'SELECT * FROM %i', $submissions_table );
						$count_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $submissions_table );
						if ( ! empty( $where_clauses ) ) {
							$where_sql = ' WHERE ' . implode( ' AND ', $where_clauses );
							$sql      .= $where_sql;
							$count_sql .= $where_sql;
						}
						$sql .= ' ORDER BY ' . $wpdb->prepare( '%i', $order_identifier ) . ' DESC';
						$sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read-only query against Elementor tables; identifier fragments are prepared with %i and limit/offset are integer-cast.
							$submissions = $wpdb->get_results( $sql, ARRAY_A );
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read-only count query with prepared identifier fragments.
							$total_submissions = (int) $wpdb->get_var( $count_sql );

				$values_included = false;
				if ( ! empty( $input['include_values'] ) && ! empty( $submissions ) && '' !== $id_column ) {
					$values_table = $wpdb->prefix . 'e_submissions_values';
					if ( mcp_abilities_elementor_table_exists( $values_table ) ) {
						$value_columns = mcp_abilities_elementor_table_columns( $values_table );
						$submission_id_column = mcp_abilities_elementor_find_column( $value_columns, array( 'submission_id', 'submissionid', 'submission' ) );

						if ( '' !== $submission_id_column ) {
							$ids = array();
							foreach ( $submissions as $row ) {
								if ( isset( $row[ $id_column ] ) ) {
									$ids[] = (int) $row[ $id_column ];
								}
							}

									if ( ! empty( $ids ) ) {
										$values_by_submission = array();
										$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

										foreach ( $ids as $submission_id ) {
											// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only query scoped to a single submission id.
											$values_rows = $wpdb->get_results(
												$wpdb->prepare(
													'SELECT * FROM %i WHERE %i = %d',
													$values_table,
													$submission_id_column,
													$submission_id
												),
												ARRAY_A
											);

											if ( ! empty( $values_rows ) ) {
												$values_by_submission[ $submission_id ] = $values_rows;
											}
										}

										foreach ( $submissions as $index => $row ) {
											$submission_id = isset( $row[ $id_column ] ) ? (int) $row[ $id_column ] : 0;
											$submissions[ $index ]['values'] = $values_by_submission[ $submission_id ] ?? array();
										}

										$values_included = true;
								}
							}
						}
					}

					return array(
						'success'         => true,
						'submissions'     => $submissions,
						'total'           => $total_submissions,
						'values_included' => $values_included,
						'filters_applied' => $filters_applied,
					'filters_ignored' => $filters_ignored,
					'message'         => 'Form submissions retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Form Submission
	// =========================================================================
	wp_register_ability(
		'elementor/get-form-submission',
		array(
			'label'               => 'Get Elementor Form Submission',
			'description'         => 'Retrieves a single Elementor form submission (Elementor Pro).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Submission ID.',
					),
					'include_values' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, include submission field values.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'submission'      => array( 'type' => 'object' ),
					'values'          => array( 'type' => 'array' ),
					'values_included' => array( 'type' => 'boolean' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wpdb;

				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Submission ID is required' );
				}

				$submissions_table = $wpdb->prefix . 'e_submissions';
				if ( ! mcp_abilities_elementor_table_exists( $submissions_table ) ) {
					return array( 'success' => false, 'message' => 'Elementor submissions table not found' );
				}

				$columns = mcp_abilities_elementor_table_columns( $submissions_table );
				$id_column = mcp_abilities_elementor_find_column( $columns, array( 'id', 'submission_id', 'submissionid' ) );
				if ( '' === $id_column ) {
					return array( 'success' => false, 'message' => 'Submission ID column not found' );
				}

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup by submission id.
						$submission = $wpdb->get_row(
							$wpdb->prepare(
							'SELECT * FROM %i WHERE %i = %d LIMIT 1',
							$submissions_table,
							$id_column,
							(int) $input['id']
						),
						ARRAY_A
					);

				if ( empty( $submission ) ) {
					return array( 'success' => false, 'message' => 'Submission not found' );
				}

				$values = array();
				$values_included = false;

				if ( ! empty( $input['include_values'] ) ) {
					$values_table = $wpdb->prefix . 'e_submissions_values';
					if ( mcp_abilities_elementor_table_exists( $values_table ) ) {
						$value_columns = mcp_abilities_elementor_table_columns( $values_table );
						$submission_id_column = mcp_abilities_elementor_find_column( $value_columns, array( 'submission_id', 'submissionid', 'submission' ) );

						if ( '' !== $submission_id_column ) {
									// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup by submission id.
									$values = $wpdb->get_results(
										$wpdb->prepare(
										'SELECT * FROM %i WHERE %i = %d',
										$values_table,
										$submission_id_column,
										(int) $input['id']
									),
									ARRAY_A
								);
							$values_included = true;
						}
					}
				}

				return array(
					'success'         => true,
					'submission'      => $submission,
					'values'          => $values,
					'values_included' => $values_included,
					'message'         => 'Form submission retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Delete Form Submission
	// =========================================================================
	wp_register_ability(
		'elementor/delete-form-submission',
		array(
			'label'               => 'Delete Elementor Form Submission',
			'description'         => 'Deletes a form submission from Elementor Pro tables.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Submission ID.',
					),
					'delete_values' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'If true, delete related field values.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'id'             => array( 'type' => 'integer' ),
					'deleted'        => array( 'type' => 'integer' ),
					'values_deleted' => array( 'type' => 'integer' ),
					'message'        => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wpdb;

				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Submission ID is required' );
				}

				$submissions_table = $wpdb->prefix . 'e_submissions';
				if ( ! mcp_abilities_elementor_table_exists( $submissions_table ) ) {
					return array( 'success' => false, 'message' => 'Elementor submissions table not found' );
				}

				$columns = mcp_abilities_elementor_table_columns( $submissions_table );
				$id_column = mcp_abilities_elementor_find_column( $columns, array( 'id', 'submission_id', 'submissionid' ) );
				if ( '' === $id_column ) {
					return array( 'success' => false, 'message' => 'Submission ID column not found' );
				}

				$values_deleted = 0;
				if ( ! empty( $input['delete_values'] ) ) {
					$values_table = $wpdb->prefix . 'e_submissions_values';
					if ( mcp_abilities_elementor_table_exists( $values_table ) ) {
						$value_columns = mcp_abilities_elementor_table_columns( $values_table );
							$submission_id_column = mcp_abilities_elementor_find_column( $value_columns, array( 'submission_id', 'submissionid', 'submission' ) );
							if ( '' !== $submission_id_column ) {
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct delete in Elementor-owned values table.
								$values_deleted = $wpdb->delete( $values_table, array( $submission_id_column => (int) $input['id'] ) );
							}
						}
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct delete in Elementor-owned submissions table.
					$deleted = $wpdb->delete( $submissions_table, array( $id_column => (int) $input['id'] ) );

				return array(
					'success'        => true,
					'id'             => (int) $input['id'],
					'deleted'        => (int) $deleted,
					'values_deleted' => (int) $values_deleted,
					'message'        => 'Form submission deleted successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - List Global Widgets
	// =========================================================================
	wp_register_ability(
		'elementor/list-global-widgets',
		array(
			'label'               => 'List Global Widgets',
			'description'         => 'Lists all Elementor global widgets (reusable widget instances).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'_' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Placeholder (ignored).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'widgets' => array( 'type' => 'array' ),
					'total'   => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$args = array(
					'post_type'      => 'elementor_library',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Necessary for widget filtering.
						array(
							'taxonomy' => 'elementor_library_type',
							'field'    => 'slug',
							'terms'    => 'widget',
						),
					),
				);

				$query   = new WP_Query( $args );
				$widgets = array();

				foreach ( $query->posts as $widget ) {
					$widgets[] = array(
						'id'       => $widget->ID,
						'title'    => $widget->post_title,
						'date'     => $widget->post_date,
						'modified' => $widget->post_modified,
					);
				}

				return array(
					'success' => true,
					'widgets' => $widgets,
					'total'   => count( $widgets ),
					'message' => 'Global widgets retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - List Kits
	// =========================================================================
	wp_register_ability(
		'elementor/list-kits',
		array(
			'label'               => 'List Elementor Kits',
			'description'         => 'List Elementor site kits and identify the active kit.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'kits'    => array( 'type' => 'array' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function (): array {
				$active_kit = (int) get_option( 'elementor_active_kit', 0 );

				$query = new WP_Query( array(
					'post_type'      => 'elementor_library',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 200,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Elementor stores kit type in post meta; constrained to elementor_library with bounded result set.
						'meta_query'     => array(
						array(
							'key'   => '_elementor_template_type',
							'value' => 'kit',
						),
					),
				) );

				$kits = array();
				foreach ( $query->posts as $post ) {
					$kits[] = array(
						'id'        => $post->ID,
						'title'     => $post->post_title,
						'status'    => $post->post_status,
						'modified'  => $post->post_modified_gmt,
						'is_active' => $post->ID === $active_kit,
					);
				}

				return array(
					'success' => true,
					'kits'    => $kits,
					'message' => 'Retrieved ' . count( $kits ) . ' kit(s).',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Kit Settings
	// =========================================================================
	wp_register_ability(
		'elementor/get-kit-settings',
		array(
			'label'               => 'Get Elementor Kit Settings',
			'description'         => 'Retrieves the active Elementor Site Kit settings (global colors, typography, theme style, etc.).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'_' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Placeholder (ignored).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'kit_id'   => array( 'type' => 'integer' ),
					'settings' => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$kit_id = get_option( 'elementor_active_kit' );
				if ( ! $kit_id ) {
					return array( 'success' => false, 'message' => 'No active Elementor kit found' );
				}

				$kit = get_post( $kit_id );
				if ( ! $kit || 'elementor_library' !== $kit->post_type ) {
					return array( 'success' => false, 'message' => 'Active kit not found or invalid' );
				}

				$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );

				return array(
					'success'  => true,
					'kit_id'   => (int) $kit_id,
					'settings' => $settings ?: array(),
					'message'  => 'Kit settings retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Kit Settings
	// =========================================================================
	wp_register_ability(
		'elementor/update-kit-settings',
		array(
			'label'               => 'Update Elementor Kit Settings',
			'description'         => 'Updates the active Elementor Site Kit settings. Use for global colors, typography, button styles, etc.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'settings' ),
				'properties'           => array(
					'settings' => array(
						'type'        => 'object',
						'description' => 'Settings to update. Will be merged with existing settings.',
					),
					'replace'  => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, replace entire settings instead of merging.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'kit_id'   => array( 'type' => 'integer' ),
					'settings' => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['settings'] ) || ! is_array( $input['settings'] ) ) {
					return array( 'success' => false, 'message' => 'Settings object is required' );
				}

				$kit_id = get_option( 'elementor_active_kit' );
				if ( ! $kit_id ) {
					return array( 'success' => false, 'message' => 'No active Elementor kit found' );
				}

				$kit = get_post( $kit_id );
				if ( ! $kit || 'elementor_library' !== $kit->post_type ) {
					return array( 'success' => false, 'message' => 'Active kit not found or invalid' );
				}

				if ( ! current_user_can( 'edit_post', $kit_id ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update kit settings' );
				}

				$existing = get_post_meta( $kit_id, '_elementor_page_settings', true );
				$existing = is_array( $existing ) ? $existing : array();

				$replace = ! empty( $input['replace'] );
				$final   = $replace ? $input['settings'] : array_merge( $existing, $input['settings'] );

				update_post_meta( $kit_id, '_elementor_page_settings', $final );

					// Clear all Elementor CSS cache since kit affects entire site.
					mcp_abilities_elementor_clear_site_cache();

				return array(
					'success'  => true,
					'kit_id'   => (int) $kit_id,
					'settings' => $final,
					'message'  => 'Kit settings updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Set Active Kit
	// =========================================================================
	wp_register_ability(
		'elementor/set-active-kit',
		array(
			'label'               => 'Set Active Elementor Kit',
			'description'         => 'Set the active Elementor site kit.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'kit_id' ),
				'properties'           => array(
					'kit_id' => array(
						'type'        => 'integer',
						'description' => 'Kit ID (elementor_library post).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'kit_id'  => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$kit_id = (int) ( $input['kit_id'] ?? 0 );
				if ( $kit_id <= 0 ) {
					return array( 'success' => false, 'message' => 'kit_id is required.' );
				}

				$kit = get_post( $kit_id );
				if ( ! $kit || 'elementor_library' !== $kit->post_type ) {
					return array( 'success' => false, 'message' => 'Kit not found.' );
				}

				update_option( 'elementor_active_kit', $kit_id );

					mcp_abilities_elementor_clear_site_cache();

				return array(
					'success' => true,
					'kit_id'  => $kit_id,
					'message' => 'Active kit updated.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Get Maintenance Mode
	// =========================================================================
	wp_register_ability(
		'elementor/get-maintenance-mode',
		array(
			'label'               => 'Get Elementor Maintenance Mode',
			'description'         => 'Retrieves current Elementor maintenance mode settings.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'_' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Placeholder (ignored).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'enabled'      => array( 'type' => 'boolean' ),
					'mode'         => array( 'type' => 'string' ),
					'template_id'  => array( 'type' => 'integer' ),
					'exclude_mode' => array( 'type' => 'string' ),
					'exclude_roles' => array( 'type' => 'array' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				if ( ! class_exists( '\Elementor\Maintenance_Mode' ) ) {
					return array( 'success' => false, 'message' => 'Elementor maintenance mode is not available' );
				}

				$mode         = \Elementor\Maintenance_Mode::get( 'mode' );
				$template_id  = (int) \Elementor\Maintenance_Mode::get( 'template_id' );
				$exclude_mode = \Elementor\Maintenance_Mode::get( 'exclude_mode', '' );
				$exclude_roles = \Elementor\Maintenance_Mode::get( 'exclude_roles', array() );
				$exclude_roles = is_array( $exclude_roles ) ? $exclude_roles : array();
				$enabled      = ! empty( $mode ) && ! empty( $template_id );

				return array(
					'success'      => true,
					'enabled'      => $enabled,
					'mode'         => $mode ?: '',
					'template_id'  => $template_id,
					'exclude_mode' => $exclude_mode ?: '',
					'exclude_roles' => $exclude_roles,
					'message'      => 'Maintenance mode settings retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Maintenance Mode
	// =========================================================================
	wp_register_ability(
		'elementor/update-maintenance-mode',
		array(
			'label'               => 'Update Elementor Maintenance Mode',
			'description'         => 'Enables or updates Elementor maintenance mode settings.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'enabled'      => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Set to false to disable maintenance mode.',
					),
					'mode'         => array(
						'type'        => 'string',
						'enum'        => array( 'maintenance', 'coming_soon' ),
						'description' => 'Maintenance mode type.',
					),
					'template_id'  => array(
						'type'        => 'integer',
						'description' => 'Elementor template ID to display.',
					),
					'exclude_mode' => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'logged_in', 'custom' ),
						'description' => 'Exclude visitors by mode (logged_in or custom roles).',
					),
					'exclude_roles' => array(
						'type'        => 'array',
						'description' => 'Roles to exclude when exclude_mode is custom.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'enabled'      => array( 'type' => 'boolean' ),
					'mode'         => array( 'type' => 'string' ),
					'template_id'  => array( 'type' => 'integer' ),
					'exclude_mode' => array( 'type' => 'string' ),
					'exclude_roles' => array( 'type' => 'array' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! class_exists( '\Elementor\Maintenance_Mode' ) ) {
					return array( 'success' => false, 'message' => 'Elementor maintenance mode is not available' );
				}

				$enabled = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : true;

				if ( ! $enabled ) {
					\Elementor\Maintenance_Mode::set( 'mode', '' );
					\Elementor\Maintenance_Mode::set( 'template_id', 0 );
					\Elementor\Maintenance_Mode::set( 'exclude_mode', '' );
					\Elementor\Maintenance_Mode::set( 'exclude_roles', array() );

					return array(
						'success'      => true,
						'enabled'      => false,
						'mode'         => '',
						'template_id'  => 0,
						'exclude_mode' => '',
						'exclude_roles' => array(),
						'message'      => 'Maintenance mode disabled',
					);
				}

				$mode = $input['mode'] ?? '';
				if ( '' === $mode ) {
					return array( 'success' => false, 'message' => 'Mode is required to enable maintenance mode' );
				}

				$template_id = (int) ( $input['template_id'] ?? 0 );
				if ( 0 === $template_id ) {
					return array( 'success' => false, 'message' => 'Template ID is required to enable maintenance mode' );
				}

				$template = get_post( $template_id );
				if ( ! $template ) {
					return array( 'success' => false, 'message' => 'Template not found' );
				}

				$exclude_mode = $input['exclude_mode'] ?? '';
				if ( 'none' === $exclude_mode ) {
					$exclude_mode = '';
				}

				$exclude_roles = array();
				if ( 'custom' === $exclude_mode ) {
					if ( empty( $input['exclude_roles'] ) || ! is_array( $input['exclude_roles'] ) ) {
						return array( 'success' => false, 'message' => 'exclude_roles is required when exclude_mode is custom' );
					}
					$exclude_roles = array_values( array_map( 'sanitize_text_field', $input['exclude_roles'] ) );
				}

				\Elementor\Maintenance_Mode::set( 'mode', $mode );
				\Elementor\Maintenance_Mode::set( 'template_id', $template_id );
				\Elementor\Maintenance_Mode::set( 'exclude_mode', $exclude_mode );
				\Elementor\Maintenance_Mode::set( 'exclude_roles', $exclude_roles );

				return array(
					'success'      => true,
					'enabled'      => true,
					'mode'         => $mode,
					'template_id'  => $template_id,
					'exclude_mode' => $exclude_mode,
					'exclude_roles' => $exclude_roles,
					'message'      => 'Maintenance mode updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - List Experiments
	// =========================================================================
	wp_register_ability(
		'elementor/list-experiments',
		array(
			'label'               => 'List Elementor Experiments',
			'description'         => 'Lists Elementor experiments and their current states.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'_' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Placeholder (ignored).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'experiments' => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->experiments ) ) {
					return array( 'success' => false, 'message' => 'Elementor experiments manager is not available' );
				}

				$manager = \Elementor\Plugin::$instance->experiments;
				$features = $manager->get_features();
				$experiments = array();

				foreach ( $features as $feature_name => $feature ) {
					$option_key   = $manager->get_feature_option_key( $feature_name );
					$saved_state  = get_option( $option_key );
					$saved_state  = $saved_state ? $saved_state : 'default';
					$default_state = $feature['default'] ?? 'default';
					$effective_state = ( 'default' === $saved_state ) ? $default_state : $saved_state;

					$dependencies = array();
					if ( ! empty( $feature['dependencies'] ) && is_array( $feature['dependencies'] ) ) {
						foreach ( $feature['dependencies'] as $dependency ) {
							if ( is_object( $dependency ) && method_exists( $dependency, 'get_name' ) ) {
								$dependencies[] = $dependency->get_name();
							} elseif ( is_string( $dependency ) ) {
								$dependencies[] = $dependency;
							}
						}
					}

					$experiments[] = array(
						'name'            => $feature['name'] ?? $feature_name,
						'title'           => isset( $feature['title'] ) ? wp_strip_all_tags( (string) $feature['title'] ) : '',
						'description'     => isset( $feature['description'] ) ? wp_strip_all_tags( (string) $feature['description'] ) : '',
						'tag'             => isset( $feature['tag'] ) ? wp_strip_all_tags( (string) $feature['tag'] ) : '',
						'release_status'  => $feature['release_status'] ?? '',
						'mutable'         => ! empty( $feature['mutable'] ),
						'default_state'   => $default_state,
						'saved_state'     => $saved_state,
						'effective_state' => $effective_state,
						'is_active'       => 'active' === $effective_state,
						'dependencies'    => $dependencies,
					);
				}

				return array(
					'success'     => true,
					'experiments' => $experiments,
					'total'       => count( $experiments ),
					'message'     => 'Experiments retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Experiment
	// =========================================================================
	wp_register_ability(
		'elementor/update-experiment',
		array(
			'label'               => 'Update Elementor Experiment',
			'description'         => 'Updates an Elementor experiment state.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'description' => 'Experiment name.',
					),
					'state' => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'active', 'inactive' ),
						'default'     => 'default',
						'description' => 'State to set (default, active, inactive).',
					),
					'reset' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, clears the saved state and uses the default.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'name'            => array( 'type' => 'string' ),
					'saved_state'     => array( 'type' => 'string' ),
					'effective_state' => array( 'type' => 'string' ),
					'is_active'       => array( 'type' => 'boolean' ),
					'message'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'message' => 'Experiment name is required' );
				}

				if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->experiments ) ) {
					return array( 'success' => false, 'message' => 'Elementor experiments manager is not available' );
				}

				$manager = \Elementor\Plugin::$instance->experiments;
				$feature = $manager->get_features( $input['name'] );

				if ( empty( $feature ) ) {
					return array( 'success' => false, 'message' => 'Experiment not found: ' . $input['name'] );
				}

				if ( empty( $feature['mutable'] ) ) {
					return array( 'success' => false, 'message' => 'Experiment is not mutable: ' . $input['name'] );
				}

				$state = $input['state'] ?? 'default';
				if ( ! in_array( $state, array( 'default', 'active', 'inactive' ), true ) ) {
					return array( 'success' => false, 'message' => 'Invalid state: ' . $state );
				}

				if ( 'active' === $state && ! empty( $feature['dependencies'] ) && is_array( $feature['dependencies'] ) ) {
					foreach ( $feature['dependencies'] as $dependency ) {
						$dependency_name = is_object( $dependency ) && method_exists( $dependency, 'get_name' )
							? $dependency->get_name()
							: ( is_string( $dependency ) ? $dependency : '' );

						if ( '' === $dependency_name ) {
							continue;
						}

						$dependency_feature = $manager->get_features( $dependency_name );
						$dependency_option_key = $manager->get_feature_option_key( $dependency_name );
						$dependency_saved_state = get_option( $dependency_option_key );
						$dependency_saved_state = $dependency_saved_state ? $dependency_saved_state : 'default';
						$dependency_default = $dependency_feature['default'] ?? 'default';
						$dependency_effective = ( 'default' === $dependency_saved_state ) ? $dependency_default : $dependency_saved_state;

						if ( 'active' !== $dependency_effective ) {
							return array( 'success' => false, 'message' => 'Dependency not active: ' . $dependency_name );
						}
					}
				}

				$option_key = $manager->get_feature_option_key( $input['name'] );
				$reset      = ! empty( $input['reset'] );
				$default_state = $feature['default'] ?? 'default';

				if ( $reset || 'default' === $state ) {
					delete_option( $option_key );
					$saved_state     = 'default';
					$effective_state = $default_state;
				} else {
					update_option( $option_key, $state );
					$saved_state     = $state;
					$effective_state = $state;
				}

					mcp_abilities_elementor_clear_site_cache();

				return array(
					'success'         => true,
					'name'            => $input['name'],
					'saved_state'     => $saved_state,
					'effective_state' => $effective_state,
					'is_active'       => 'active' === $effective_state,
					'message'         => 'Experiment updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Page Settings
	// =========================================================================
	wp_register_ability(
		'elementor/update-page-settings',
		array(
			'label'               => 'Update Elementor Page Settings',
			'description'         => 'Updates Elementor page settings (stored in _elementor_page_settings postmeta). Can update individual keys or replace entire settings object. Use for Site Settings Kit to set global padding, typography, colors, etc.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => 'Post/Page/Kit ID to update settings for.',
					),
					'settings' => array(
						'type'        => 'object',
						'description' => 'Settings object to merge with existing settings. Keys will be added/updated.',
					),
					'replace'  => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, replace entire settings object instead of merging.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'id'       => array( 'type' => 'integer' ),
					'message'  => array( 'type' => 'string' ),
					'settings' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page/Kit ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$existing_settings = get_post_meta( $input['id'], '_elementor_page_settings', true );
				if ( ! is_array( $existing_settings ) ) {
					$existing_settings = array();
				}

				$new_settings = $input['settings'] ?? array();
				$replace      = ! empty( $input['replace'] );

				if ( $replace ) {
					$final_settings = $new_settings;
				} else {
					// Merge new settings into existing (new values override).
					$final_settings = array_merge( $existing_settings, $new_settings );
				}

				update_post_meta( $input['id'], '_elementor_page_settings', $final_settings );

				// Clear Elementor CSS cache.
				delete_post_meta( $input['id'], '_elementor_css' );

					// If this is a kit, clear all site CSS.
					$active_kit = get_option( 'elementor_active_kit' );
					if ( (int) $active_kit === (int) $input['id'] ) {
						mcp_abilities_elementor_clear_site_cache();
					}

				return array(
					'success'  => true,
					'id'       => $input['id'],
					'message'  => 'Page settings updated successfully',
					'settings' => $final_settings,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'mcp_abilities_elementor_enqueue_frontend_runtime_when_needed', 5 );
add_action( 'elementor/frontend/after_register_scripts', 'mcp_abilities_elementor_enqueue_frontend_runtime_when_needed', 5 );
add_action( 'wp_head', 'mcp_abilities_elementor_print_frontend_config_when_needed', 1 );
add_action( 'wp_head', 'mcp_abilities_elementor_print_frontend_script_fallback_when_needed', 2 );
add_action( 'wp_head', 'mcp_abilities_elementor_print_footer_scripts_early_when_needed', 999 );
add_action( 'wp_abilities_api_init', 'mcp_abilities_elementor_register_abilities' );
