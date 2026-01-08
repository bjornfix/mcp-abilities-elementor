<?php
/**
 * Plugin Name: MCP Abilities - Elementor
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-elementor
 * Description: Elementor abilities for MCP. Get, update, and patch Elementor page data. Manage templates and cache.
 * Version: 2.0.3
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

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to access this post' );
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

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
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
					// Use taxonomy query instead of meta_query for better performance.
					$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Necessary for template type filtering.
						array(
							'taxonomy' => 'elementor_library_type',
							'field'    => 'slug',
							'terms'    => $type_filter,
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
					// Clear all Elementor CSS files using Elementor's API.
					if ( class_exists( '\Elementor\Plugin' ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
						return array( 'success' => true, 'message' => 'All Elementor cache cleared' );
					} else {
						// Elementor not loaded - cannot clear cache without it.
						return array( 'success' => false, 'message' => 'Elementor plugin not loaded. Cannot clear cache.' );
					}
				}

				if ( ! empty( $input['id'] ) ) {
					$post = get_post( $input['id'] );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					if ( ! current_user_can( 'edit_post', $post->ID ) ) {
						return array( 'success' => false, 'message' => 'You do not have permission to clear cache for this post' );
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

				// Create the post.
				$post_data = array(
					'post_title'  => sanitize_text_field( $input['title'] ),
					'post_type'   => 'elementor_library',
					'post_status' => $input['status'] ?? 'publish',
				);

				$post_id = wp_insert_post( $post_data, true );
				if ( is_wp_error( $post_id ) ) {
					return array( 'success' => false, 'message' => 'Failed to create template: ' . $post_id->get_error_message() );
				}

				// Set template type.
				update_post_meta( $post_id, '_elementor_template_type', $input['type'] );

				// Set edit mode to builder.
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

				// Set Elementor data.
				$elementor_data = $input['data'] ?? array();
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

				// Set display conditions (for theme builder templates).
				if ( ! empty( $input['conditions'] ) && is_array( $input['conditions'] ) ) {
					update_post_meta( $post_id, '_elementor_conditions', $input['conditions'] );

					// Also update the global conditions option for Elementor Pro.
					$theme_builder_conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
					$theme_builder_conditions[ $input['type'] ][ $post_id ] = $input['conditions'];
					update_option( 'elementor_pro_theme_builder_conditions', $theme_builder_conditions );
				}

				// Set popup display settings (for popups).
				if ( 'popup' === $input['type'] && ! empty( $input['popup_display'] ) && is_array( $input['popup_display'] ) ) {
					$popup_settings = array();

					// Process triggers.
					if ( ! empty( $input['popup_display']['triggers'] ) ) {
						foreach ( $input['popup_display']['triggers'] as $trigger ) {
							if ( 'on_page_load' === $trigger ) {
								$popup_settings['triggers'] = array_merge(
									$popup_settings['triggers'] ?? array(),
									array( 'page_load' => 'yes' )
								);
							} elseif ( 'on_scroll' === $trigger ) {
								$popup_settings['triggers'] = array_merge(
									$popup_settings['triggers'] ?? array(),
									array( 'scrolling' => 'yes', 'scrolling_direction' => 'down' )
								);
							} elseif ( 'on_exit_intent' === $trigger ) {
								$popup_settings['triggers'] = array_merge(
									$popup_settings['triggers'] ?? array(),
									array( 'exit_intent' => 'yes' )
								);
							} elseif ( 'on_click' === $trigger ) {
								$popup_settings['triggers'] = array_merge(
									$popup_settings['triggers'] ?? array(),
									array( 'click' => 'yes', 'click_times' => 1 )
								);
							} elseif ( 'after_inactivity' === $trigger ) {
								$popup_settings['triggers'] = array_merge(
									$popup_settings['triggers'] ?? array(),
									array( 'inactivity' => 'yes', 'inactivity_time' => 30 )
								);
							}
						}
					}

					// Process timing.
					if ( ! empty( $input['popup_display']['timing'] ) ) {
						$timing = $input['popup_display']['timing'];
						if ( isset( $timing['show_after'] ) ) {
							$popup_settings['timing'] = array_merge(
								$popup_settings['timing'] ?? array(),
								array( 'page_load_delay' => (int) $timing['show_after'] )
							);
						}
						if ( isset( $timing['show_times'] ) ) {
							$popup_settings['timing'] = array_merge(
								$popup_settings['timing'] ?? array(),
								array( 'times_count' => (int) $timing['show_times'], 'times' => 'yes' )
							);
						}
					}

					if ( ! empty( $popup_settings ) ) {
						update_post_meta( $post_id, '_elementor_popup_display_settings', $popup_settings );
					}
				}

				// Set taxonomy term for template type (Elementor uses this for filtering).
				wp_set_object_terms( $post_id, $input['type'], 'elementor_library_type' );

				// Build edit URL.
				$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=elementor' );

				return array(
					'success' => true,
					'id'      => $post_id,
					'title'   => $input['title'],
					'type'    => $input['type'],
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
					update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $input['data'] ) ) );
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

				// Update display conditions if provided.
				if ( ! empty( $input['conditions'] ) && is_array( $input['conditions'] ) ) {
					update_post_meta( $post->ID, '_elementor_conditions', $input['conditions'] );

					$theme_builder_conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
					$theme_builder_conditions[ $template_type ][ $post->ID ] = $input['conditions'];
					update_option( 'elementor_pro_theme_builder_conditions', $theme_builder_conditions );
				}

				// Update popup display settings if provided.
				if ( 'popup' === $template_type && ! empty( $input['popup_display'] ) && is_array( $input['popup_display'] ) ) {
					$popup_settings = get_post_meta( $post->ID, '_elementor_popup_display_settings', true );
					$popup_settings = is_array( $popup_settings ) ? $popup_settings : array();

					if ( ! empty( $input['popup_display']['triggers'] ) ) {
						$popup_settings['triggers'] = array();
						foreach ( $input['popup_display']['triggers'] as $trigger ) {
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

					if ( ! empty( $input['popup_display']['timing'] ) ) {
						$timing = $input['popup_display']['timing'];
						if ( isset( $timing['show_after'] ) ) {
							$popup_settings['timing']['page_load_delay'] = (int) $timing['show_after'];
						}
						if ( isset( $timing['show_times'] ) ) {
							$popup_settings['timing']['times_count'] = (int) $timing['show_times'];
							$popup_settings['timing']['times'] = 'yes';
						}
					}

					update_post_meta( $post->ID, '_elementor_popup_display_settings', $popup_settings );
				}

				// Refresh post data.
				$post = get_post( $post->ID );
				$edit_url = admin_url( 'post.php?post=' . $post->ID . '&action=elementor' );

				return array(
					'success' => true,
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'type'    => $template_type,
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
					'idempotent'  => true,
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
				$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
				$page_settings  = get_post_meta( $post->ID, '_elementor_page_settings', true );
				$conditions     = get_post_meta( $post->ID, '_elementor_conditions', true );
				$popup_settings = get_post_meta( $post->ID, '_elementor_popup_display_settings', true );

				return array(
					'success'        => true,
					'id'             => $post->ID,
					'title'          => $post->post_title,
					'type'           => $template_type ?: 'unknown',
					'status'         => $post->post_status,
					'data'           => $elementor_data ? json_decode( $elementor_data, true ) : array(),
					'page_settings'  => $page_settings ?: array(),
					'conditions'     => $conditions ?: array(),
					'popup_settings' => $popup_settings ?: array(),
					'link'           => get_permalink( $post->ID ),
					'edit'           => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ),
					'message'        => 'Template retrieved successfully',
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
					'posts_per_page' => -1,
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

				$trashed = get_posts( $args );
				$deleted = 0;

				foreach ( $trashed as $post_id ) {
					if ( wp_delete_post( $post_id, true ) ) {
						$deleted++;
					}
				}

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
					'_elementor_edit_mode',
					'_elementor_data',
					'_elementor_page_settings',
					'_elementor_popup_display_settings',
				);

				foreach ( $meta_keys as $key ) {
					$value = get_post_meta( $original->ID, $key, true );
					if ( $value ) {
						update_post_meta( $new_post_id, $key, $value );
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
				$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
				$page_settings  = get_post_meta( $post->ID, '_elementor_page_settings', true );

				$export_data = array(
					'version'       => '1.0',
					'title'         => $post->post_title,
					'type'          => $template_type ?: 'page',
					'content'       => $elementor_data ? json_decode( $elementor_data, true ) : array(),
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
				update_post_meta( $post_id, '_elementor_template_type', $template_type );
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data['content'] ) ) );

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
					'widgets' => array( 'type' => 'array' ),
					'total'   => array( 'type' => 'integer' ),
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
					'widgets' => $widgets,
					'total'   => count( $widgets ),
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
				if ( class_exists( '\Elementor\Plugin' ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}

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
