<?php
/**
 * Plugin Name: MCP Abilities - Elementor
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-elementor
 * Description: Elementor abilities for MCP. Get, update, and patch Elementor page data. Manage templates and cache.
 * Version: 1.0.1
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Requires Plugins: abilities-api
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
function mcp_elementor_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Elementor</strong> requires the <a href="https://github.com/WordPress/abilities-api">Abilities API</a> plugin to be installed and activated.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Register Elementor abilities.
 */
function mcp_register_elementor_abilities(): void {
	if ( ! mcp_elementor_check_dependencies() ) {
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
					'data'          => array( 'type' => 'array' ),
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

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
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
				$data   = ( 'json' === $format ) ? $elementor_data : json_decode( $elementor_data, true );

				return array(
					'success'       => true,
					'id'            => $input['id'],
					'title'         => $post->post_title,
					'edit_mode'     => $edit_mode ?: 'not set',
					'data'          => $data,
					'page_settings' => $page_settings ?: array(),
					'message'       => 'Elementor data retrieved successfully',
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
			'description'         => 'Updates the Elementor JSON data for a page or post. Automatically clears Elementor CSS cache. Use with caution - invalid JSON will break the page.',
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

				// Encode data to JSON.
				$json_data = wp_json_encode( $input['data'] );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode data to JSON' );
				}

				// Update Elementor data.
				update_post_meta( $input['id'], '_elementor_data', wp_slash( $json_data ) );

				// Ensure edit mode is set to builder.
				update_post_meta( $input['id'], '_elementor_edit_mode', 'builder' );

				// Clear Elementor CSS cache for this post.
				delete_post_meta( $input['id'], '_elementor_css' );

				// Update post modified time to trigger regeneration.
				wp_update_post( array(
					'ID'            => $input['id'],
					'post_modified' => current_time( 'mysql' ),
				) );

				return array(
					'success' => true,
					'id'      => $input['id'],
					'message' => 'Elementor data updated successfully',
					'link'    => get_permalink( $input['id'] ),
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
	// ELEMENTOR - Patch Data (Find & Replace in JSON)
	// =========================================================================
	wp_register_ability(
		'elementor/patch-data',
		array(
			'label'               => 'Patch Elementor Data',
			'description'         => 'Performs find-and-replace operations within Elementor JSON data. Works on the raw JSON string, so you can replace text, URLs, settings values, etc.',
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
					return array(
						'success'      => true,
						'id'           => $input['id'],
						'replacements' => 0,
						'message'      => 'No matches found - Elementor data unchanged',
						'link'         => get_permalink( $input['id'] ),
					);
				}

				// Validate that result is still valid JSON.
				$test_decode = json_decode( $new_data, true );
				if ( null === $test_decode && json_last_error() !== JSON_ERROR_NONE ) {
					return array( 'success' => false, 'message' => 'Replacement would result in invalid JSON - aborted' );
				}

				// Update Elementor data.
				update_post_meta( $input['id'], '_elementor_data', wp_slash( $new_data ) );

				// Clear Elementor CSS cache.
				delete_post_meta( $input['id'], '_elementor_css' );

				// Update post modified time.
				wp_update_post( array(
					'ID'            => $input['id'],
					'post_modified' => current_time( 'mysql' ),
				) );

				return array(
					'success'      => true,
					'id'           => $input['id'],
					'replacements' => $count,
					'message'      => "Successfully replaced {$count} occurrence(s) in Elementor data",
					'link'         => get_permalink( $input['id'] ),
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
	// ELEMENTOR - Update Element (targeted container/widget replacement)
	// =========================================================================
	wp_register_ability(
		'elementor/update-element',
		array(
			'label'               => 'Update Elementor Element',
			'description'         => 'Replaces a specific element (container or widget) by ID within the Elementor page structure. Useful for targeted updates without re-uploading the entire page.',
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

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

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

				$replace_element( $data, $input['element_id'], $input['element_data'] );

				if ( ! $found ) {
					return array(
						'success'    => false,
						'id'         => $input['id'],
						'element_id' => $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				// Encode and save.
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( $input['id'], '_elementor_data', wp_slash( $json_data ) );

				// Clear Elementor CSS cache.
				delete_post_meta( $input['id'], '_elementor_css' );

				// Update post modified time.
				wp_update_post( array(
					'ID'            => $input['id'],
					'post_modified' => current_time( 'mysql' ),
				) );

				return array(
					'success'    => true,
					'id'         => $input['id'],
					'element_id' => $input['element_id'],
					'message'    => 'Element "' . $input['element_id'] . '" updated successfully',
					'link'       => get_permalink( $input['id'] ),
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
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'templates' => array( 'type' => 'array' ),
					'total'     => array( 'type' => 'integer' ),
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
					$args['meta_query'] = array(
						array(
							'key'   => '_elementor_template_type',
							'value' => $type_filter,
						),
					);
				}

				$query     = new WP_Query( $args );
				$templates = array();

				foreach ( $query->posts as $template ) {
					$template_type = get_post_meta( $template->ID, '_elementor_template_type', true );
					$templates[]   = array(
						'id'       => $template->ID,
						'title'    => $template->post_title,
						'type'     => $template_type ?: 'unknown',
						'date'     => $template->post_date,
						'modified' => $template->post_modified,
					);
				}

				return array(
					'templates' => $templates,
					'total'     => count( $templates ),
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
			'description'         => 'Clears Elementor CSS cache for a specific post or the entire site.',
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
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! empty( $input['all'] ) ) {
					// Clear all Elementor CSS files.
					if ( class_exists( '\Elementor\Plugin' ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
						return array( 'success' => true, 'message' => 'All Elementor cache cleared' );
					} else {
						// Manual fallback - delete all _elementor_css meta.
						global $wpdb;
						$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_elementor_css' ) );
						return array( 'success' => true, 'message' => 'Elementor CSS meta cleared (Elementor not loaded)' );
					}
				}

				if ( ! empty( $input['id'] ) ) {
					$post = get_post( $input['id'] );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					delete_post_meta( $input['id'], '_elementor_css' );
					return array( 'success' => true, 'message' => "Cache cleared for post {$input['id']}" );
				}

				return array( 'success' => false, 'message' => 'Provide either "id" or set "all" to true' );
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
				if ( (int) $active_kit === (int) $input['id'] && class_exists( '\Elementor\Plugin' ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
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
add_action( 'wp_abilities_api_init', 'mcp_register_elementor_abilities' );
