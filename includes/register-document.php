<?php
/**
 * Document editing abilities.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register document editing abilities.
 */
function mcp_abilities_elementor_register_document_abilities(): void {
	// =========================================================================
	// ELEMENTOR - Get Data
	// =========================================================================
	mcp_abilities_elementor_register_ability(
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
				$document = mcp_abilities_elementor_load_document_from_input( $input );

				if ( empty( $document['success'] ) ) {
					return $document;
				}

				$format = $input['format'] ?? 'array';
				$data         = ( 'json' === $format ) ? (string) $document['raw_data'] : (array) $document['data'];
				$message      = 'Elementor data retrieved successfully';
				if ( null !== $document['decode_error'] ) {
					$message .= ' (data was invalid JSON and was normalized to an empty array)';
				}

				return array(
					'success'       => true,
					'id'            => (int) $document['id'],
					'title'         => (string) $document['title'],
					'edit_mode'     => (string) $document['edit_mode'],
					'data'          => $data,
					'page_settings' => (array) $document['page_settings'],
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
	// ELEMENTOR - Get Widget Controls
	// =========================================================================
	mcp_abilities_elementor_register_ability(
		'elementor/get-widget-controls',
		array(
			'label'               => 'Get Elementor Widget Controls',
			'description'         => 'Returns schema-safe summaries of the native Elementor controls exposed by a widget type on the target site.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'widget_type' ),
				'properties'           => array(
					'widget_type' => array(
						'type'        => 'string',
						'description' => 'Elementor widget type, for example "nav-menu", "mega-menu", or "media-carousel".',
					),
					'search'      => array(
						'type'        => 'string',
						'description' => 'Optional case-insensitive filter matched against control name, label, description, section, and type.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'widget_type' => array( 'type' => 'string' ),
					'count'       => array( 'type' => 'integer' ),
					'controls'    => array( 'type' => 'array' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input       = is_array( $input ) ? $input : array();
				$widget_type = isset( $input['widget_type'] ) ? sanitize_key( (string) $input['widget_type'] ) : '';
				$search      = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';

				if ( '' === $widget_type ) {
					return array( 'success' => false, 'message' => 'widget_type is required' );
				}

				if ( ! class_exists( '\Elementor\Plugin' ) ) {
					return array( 'success' => false, 'widget_type' => $widget_type, 'message' => 'Elementor is not loaded' );
				}

				$elementor = \Elementor\Plugin::instance();
				$widgets_manager = $elementor->widgets_manager ?? null;
				if ( ! $widgets_manager || ! method_exists( $widgets_manager, 'get_widget_types' ) ) {
					return array( 'success' => false, 'widget_type' => $widget_type, 'message' => 'Elementor widgets manager is unavailable' );
				}

				$widget = $widgets_manager->get_widget_types( $widget_type );
				if ( ! $widget || ! is_object( $widget ) || ! method_exists( $widget, 'get_controls' ) ) {
					return array( 'success' => false, 'widget_type' => $widget_type, 'message' => 'Widget type not found or does not expose controls' );
				}

				$controls = $widget->get_controls();
				$controls = is_array( $controls ) ? $controls : array();
				$summaries = mcp_abilities_elementor_summarize_widget_controls( $controls, $search );

				return array(
					'success'     => true,
					'widget_type' => $widget_type,
					'count'       => count( $summaries ),
					'controls'    => $summaries,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Data
	// =========================================================================
	mcp_abilities_elementor_register_ability(
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
					'confirm_dangerous_action' => mcp_abilities_elementor_dangerous_action_confirmation_schema( 'elementor/update-data' ),
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
					'elementor_write_guard' => array( 'type' => 'object' ),
					'elementor_translation_guard' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$confirmation_error = mcp_abilities_elementor_dangerous_action_error_response(
					mcp_abilities_elementor_confirm_dangerous_action( $input, 'elementor/update-data' ),
					'elementor/update-data'
				);
				if ( null !== $confirmation_error ) {
					return $confirmation_error;
				}

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
				if ( '' === $existing_data || null === $existing_data ) {
					if ( ! $force_replace ) {
						return array( 'success' => false, 'message' => 'No existing Elementor data found; use force_replace=true to initialize this post' );
					}
					$existing_tree = array();
				} else {
					$existing_tree = json_decode( $existing_data, true );
				}
				if ( null === $existing_tree && JSON_ERROR_NONE !== json_last_error() ) {
					if ( ! $force_replace ) {
						return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
					}
					$existing_tree = array();
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
					$style_policy    = mcp_abilities_elementor_enforce_global_style_policy( $normalized_data );
					if ( empty( $style_policy['success'] ) ) {
						return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
					}
					$normalized_data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $normalized_data;
					$write_guard     = mcp_abilities_elementor_audit_write_guard( $normalized_data );
					if ( empty( $write_guard['success'] ) ) {
						return mcp_abilities_elementor_write_guard_error_response( $write_guard );
					}

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
				$translation_guard = mcp_abilities_elementor_update_guarded_elementor_data( $input['id'], wp_slash( $json_data ) );

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
					'elementor_translation_guard' => $translation_guard,
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
	// ELEMENTOR - Create Page
	// =========================================================================
	mcp_abilities_elementor_register_ability(
		'elementor/create-page',
		array(
			'label'               => 'Create Elementor Page',
			'description'         => 'Creates a new WordPress page or post with Elementor builder mode enabled and optional initial Elementor data.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title' ),
				'properties'           => array_merge(
					array(
					'title'     => array( 'type' => 'string', 'description' => 'Post/page title.' ),
					'content'   => array( 'type' => 'string', 'description' => 'Optional WordPress post content.' ),
					'status'    => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private' ), 'default' => 'draft' ),
					'post_type' => array( 'type' => 'string', 'default' => 'page', 'description' => 'Post type to create. Defaults to page.' ),
					'slug'      => array( 'type' => 'string', 'description' => 'Optional post slug.' ),
					'data'      => array( 'type' => 'array', 'description' => 'Optional initial Elementor data array.' ),
					'page_settings' => array( 'type' => 'object', 'description' => 'Optional Elementor page settings.' ),
					),
					mcp_abilities_elementor_get_template_lookup_schema_properties(),
					array(
						'cache_scope' => mcp_abilities_elementor_cache_scope_schema(),
					)
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
					'link'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
					'cache'   => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
				if ( '' === $title ) {
					return array( 'success' => false, 'message' => 'Title is required' );
				}

				$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'page';
				if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
					return array( 'success' => false, 'message' => 'Invalid post_type' );
				}

				if ( isset( $input['data'] ) && is_array( $input['data'] ) && ! empty( $input['data'] ) ) {
					$template_guard = mcp_abilities_elementor_template_lookup_guard_response( $input, 'elementor/create-page' );
					if ( null !== $template_guard ) {
						return $template_guard;
					}
				}

				$post_type_object = get_post_type_object( $post_type );
				$capability = ( $post_type_object && isset( $post_type_object->cap->create_posts ) ) ? $post_type_object->cap->create_posts : 'edit_posts';
				if ( ! current_user_can( $capability ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to create this post type' );
				}

				$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
				if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
					$status = 'draft';
				}

				$post_id = wp_insert_post(
					array(
						'post_title'   => $title,
						'post_content' => isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '',
						'post_status'  => $status,
						'post_type'    => $post_type,
						'post_name'    => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					return array( 'success' => false, 'message' => $post_id->get_error_message() );
				}

				$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
				$save = mcp_abilities_elementor_save_document_data(
					(int) $post_id,
					$data,
					mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' )
				);
				if ( empty( $save['success'] ) ) {
					wp_delete_post( (int) $post_id, true );
					return array(
						'success' => false,
						'message' => (string) ( $save['message'] ?? 'Failed to initialize Elementor data' ),
						'violations' => $save['violations'] ?? array(),
					);
				}

				if ( isset( $input['page_settings'] ) && is_array( $input['page_settings'] ) ) {
					mcp_abilities_elementor_update_guarded_page_settings( (int) $post_id, $input['page_settings'] );
				}

				return array(
					'success' => true,
					'id'      => (int) $post_id,
					'title'   => get_the_title( (int) $post_id ),
					'status'  => get_post_status( (int) $post_id ) ?: $status,
					'link'    => get_permalink( (int) $post_id ) ?: '',
					'message' => 'Elementor page created successfully',
					'cache'   => $save['cache'] ?? array(),
				);
			},
			'permission_callback' => function (): bool {
				return mcp_abilities_elementor_can_edit_posts();
			},
			'meta'                => mcp_abilities_elementor_ability_meta( false, false, false ),
		)
	);

	// =========================================================================
	// ELEMENTOR - Add Container
	// =========================================================================
	mcp_abilities_elementor_register_ability(
		'elementor/add-container',
		array(
			'label'               => 'Add Elementor Container',
			'description'         => 'Adds a new Elementor container at the top level or inside an existing container.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array_merge(
					array(
					'id'                => array( 'type' => 'integer', 'description' => 'Post/Page ID.' ),
					'parent_element_id' => array( 'type' => 'string', 'description' => 'Optional parent element ID. Omit for top-level insertion.' ),
					'position'          => array( 'type' => 'integer', 'default' => -1, 'description' => 'Insert position. -1 appends.' ),
					'element_id'        => array( 'type' => 'string', 'description' => 'Optional explicit element ID.' ),
					'settings'          => array( 'type' => 'object', 'description' => 'Container settings.' ),
					),
					mcp_abilities_elementor_get_template_lookup_schema_properties(),
					array(
						'cache_scope' => mcp_abilities_elementor_cache_scope_schema(),
					)
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
					'cache'      => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$post_id = (int) ( $input['id'] ?? 0 );
				if ( $post_id <= 0 ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				$post = get_post( $post_id );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$template_guard = mcp_abilities_elementor_template_lookup_guard_response( $input, 'elementor/add-container' );
				if ( null !== $template_guard ) {
					return $template_guard;
				}

				$data = mcp_abilities_elementor_get_post_elements( $post_id );
				$element_id = mcp_abilities_elementor_unique_element_id( $data, isset( $input['element_id'] ) ? (string) $input['element_id'] : null );
				$container = mcp_abilities_elementor_build_container_element(
					isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array(),
					array(),
					$element_id
				);

				$inserted = mcp_abilities_elementor_insert_element_in_tree(
					$data,
					$container,
					isset( $input['parent_element_id'] ) ? (string) $input['parent_element_id'] : null,
					isset( $input['position'] ) ? (int) $input['position'] : -1
				);
				if ( ! $inserted ) {
					return array( 'success' => false, 'message' => 'Parent element not found' );
				}

				$save = mcp_abilities_elementor_save_document_data( $post_id, $data, (string) ( $input['cache_scope'] ?? 'post' ) );
				if ( empty( $save['success'] ) ) {
					return array( 'success' => false, 'message' => (string) ( $save['message'] ?? 'Failed to save Elementor data' ) );
				}

				return array(
					'success'    => true,
					'id'         => $post_id,
					'element_id' => $element_id,
					'message'    => 'Elementor container added successfully',
					'link'       => get_permalink( $post_id ) ?: '',
					'cache'      => $save['cache'] ?? array(),
				);
			},
			'permission_callback' => function (): bool {
				return mcp_abilities_elementor_can_edit_posts();
			},
			'meta'                => mcp_abilities_elementor_ability_meta( false, false, false ),
		)
	);

	// =========================================================================
	// ELEMENTOR - Add Widget
	// =========================================================================
	$register_add_widget_ability = static function ( string $ability_name, string $label, string $widget_type, array $extra_properties = array(), array $required = array() ): void {
		mcp_abilities_elementor_register_ability(
			$ability_name,
			array(
				'label'               => $label,
				'description'         => 'Adds an Elementor widget into an existing container or top-level document.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array_values( array_unique( array_merge( array( 'id' ), $required ) ) ),
					'properties'           => array_merge(
						array(
							'id'                => array( 'type' => 'integer', 'description' => 'Post/Page ID.' ),
							'parent_element_id' => array( 'type' => 'string', 'description' => 'Optional parent container ID. Omit for top-level insertion.' ),
							'position'          => array( 'type' => 'integer', 'default' => -1, 'description' => 'Insert position. -1 appends.' ),
							'element_id'        => array( 'type' => 'string', 'description' => 'Optional explicit element ID.' ),
							'settings'          => array( 'type' => 'object', 'description' => 'Widget settings merged with convenience inputs.' ),
							'cache_scope'       => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
						),
						$extra_properties
					),
					'additionalProperties' => true,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'id'          => array( 'type' => 'integer' ),
						'element_id'  => array( 'type' => 'string' ),
						'widget_type' => array( 'type' => 'string' ),
						'message'     => array( 'type' => 'string' ),
						'link'        => array( 'type' => 'string' ),
						'cache'       => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => function ( $input = array() ) use ( $widget_type ): array {
					$input = is_array( $input ) ? $input : array();
					$post_id = (int) ( $input['id'] ?? 0 );
					if ( $post_id <= 0 ) {
						return array( 'success' => false, 'message' => 'Post/Page ID is required' );
					}
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}
					if ( ! current_user_can( 'edit_post', $post->ID ) ) {
						return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
					}

					$effective_widget_type = 'elementor/add-widget' === current_filter() && isset( $input['widget_type'] ) ? sanitize_key( (string) $input['widget_type'] ) : $widget_type;
					if ( '' === $effective_widget_type && isset( $input['widget_type'] ) ) {
						$effective_widget_type = sanitize_key( (string) $input['widget_type'] );
					}
					if ( '' === $effective_widget_type ) {
						return array( 'success' => false, 'message' => 'widget_type is required' );
					}

					$data = mcp_abilities_elementor_get_post_elements( $post_id );
					$element_id = mcp_abilities_elementor_unique_element_id( $data, isset( $input['element_id'] ) ? (string) $input['element_id'] : null );
					$settings = mcp_abilities_elementor_build_convenience_widget_settings( $effective_widget_type, $input );
					$widget = mcp_abilities_elementor_build_widget_element( $effective_widget_type, $settings, $element_id );

					$inserted = mcp_abilities_elementor_insert_element_in_tree(
						$data,
						$widget,
						isset( $input['parent_element_id'] ) ? (string) $input['parent_element_id'] : null,
						isset( $input['position'] ) ? (int) $input['position'] : -1
					);
					if ( ! $inserted ) {
						return array( 'success' => false, 'message' => 'Parent element not found' );
					}

					$save = mcp_abilities_elementor_save_document_data( $post_id, $data, (string) ( $input['cache_scope'] ?? 'post' ) );
					if ( empty( $save['success'] ) ) {
						return array( 'success' => false, 'message' => (string) ( $save['message'] ?? 'Failed to save Elementor data' ) );
					}

					return array(
						'success'     => true,
						'id'          => $post_id,
						'element_id'  => $element_id,
						'widget_type' => $effective_widget_type,
						'message'     => 'Elementor widget added successfully',
						'link'        => get_permalink( $post_id ) ?: '',
						'cache'       => $save['cache'] ?? array(),
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
	};

	$register_add_widget_ability(
		'elementor/add-widget',
		'Add Elementor Widget',
		'',
		array(
			'widget_type' => array( 'type' => 'string', 'description' => 'Elementor widget type, for example heading, text-editor, image, button.' ),
		),
		array( 'widget_type' )
	);
	$register_add_widget_ability(
		'elementor/add-heading',
		'Add Elementor Heading',
		'heading',
		array(
			'title'       => array( 'type' => 'string', 'description' => 'Heading text.' ),
			'header_size' => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'default' => 'h2' ),
			'align'       => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
			'title_color' => array( 'type' => 'string', 'description' => 'Heading color.' ),
		),
		array( 'title' )
	);
	$register_add_widget_ability(
		'elementor/add-text-editor',
		'Add Elementor Text Editor',
		'text-editor',
		array(
			'editor'     => array( 'type' => 'string', 'description' => 'Rich text/HTML content.' ),
			'align'      => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
			'text_color' => array( 'type' => 'string', 'description' => 'Text color.' ),
		),
		array( 'editor' )
	);
	$register_add_widget_ability(
		'elementor/add-image',
		'Add Elementor Image',
		'image',
		array(
			'image_id'   => array( 'type' => 'integer', 'description' => 'Attachment ID.' ),
			'image_url'  => array( 'type' => 'string', 'description' => 'Image URL when no attachment ID is available.' ),
			'image_size' => array( 'type' => 'string', 'default' => 'large' ),
			'align'      => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ),
		)
	);
	$register_add_widget_ability(
		'elementor/add-button',
		'Add Elementor Button',
		'button',
		array(
			'text'       => array( 'type' => 'string', 'description' => 'Button text.' ),
			'url'        => array( 'type' => 'string', 'description' => 'Button URL.' ),
			'align'      => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
			'button_type'=> array( 'type' => 'string', 'description' => 'Elementor button type/style.' ),
		),
		array( 'text' )
	);

	// =========================================================================
	// ELEMENTOR - Add Post Tabs
	// =========================================================================
	mcp_abilities_elementor_register_ability(
		'elementor/add-post-tabs',
		array(
			'label'               => 'Add Elementor Post Tabs',
			'description'         => 'Adds a native Elementor Nested Tabs widget where each tab contains a native Posts widget. Use this for tabbed blog/post lists instead of manual cards or custom filter markup.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'tabs' ),
				'properties'           => array(
					'id'                  => array( 'type' => 'integer', 'description' => 'Post/Page ID.' ),
					'parent_element_id'   => array( 'type' => 'string', 'description' => 'Optional parent container ID. Omit for top-level insertion.' ),
					'position'            => array( 'type' => 'integer', 'default' => -1, 'description' => 'Insert position. -1 appends.' ),
					'element_id'          => array( 'type' => 'string', 'description' => 'Optional explicit Nested Tabs element ID.' ),
					'tabs_settings'       => array( 'type' => 'object', 'description' => 'Native nested-tabs widget settings.' ),
					'base_posts_settings' => array( 'type' => 'object', 'description' => 'Native Posts widget settings shared by all tabs.' ),
					'tabs'                => array(
						'type'        => 'array',
						'minItems'    => 1,
						'description' => 'Tab definitions. Each tab requires title and can override posts_settings/container_settings.',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'title' ),
							'properties'           => array(
								'title'            => array( 'type' => 'string' ),
								'tab_id'           => array( 'type' => 'string', 'description' => 'Optional explicit tab container/repeater ID.' ),
								'posts_element_id' => array( 'type' => 'string', 'description' => 'Optional explicit Posts widget ID inside this tab.' ),
								'posts_settings'   => array( 'type' => 'object', 'description' => 'Native Posts widget settings for this tab.' ),
								'container_settings'=> array( 'type' => 'object', 'description' => 'Native container settings for this tab panel.' ),
							),
							'additionalProperties' => false,
						),
					),
					'cache_scope'         => mcp_abilities_elementor_cache_scope_schema(),
					'dry_run'             => array( 'type' => 'boolean', 'default' => false, 'description' => 'Return the prepared Nested Tabs element without writing.' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'tab_ids'    => array( 'type' => 'array' ),
					'posts_widget_ids' => array( 'type' => 'array' ),
					'message'    => array( 'type' => 'string' ),
					'link'       => array( 'type' => 'string' ),
					'cache'      => array( 'type' => 'object' ),
					'element'    => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input   = is_array( $input ) ? $input : array();
				$post_id = (int) ( $input['id'] ?? 0 );
				if ( $post_id <= 0 ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}

				$post = get_post( $post_id );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to update this post' );
				}

				$tabs = isset( $input['tabs'] ) && is_array( $input['tabs'] ) ? $input['tabs'] : array();
				if ( empty( $tabs ) ) {
					return array( 'success' => false, 'message' => 'tabs array is required' );
				}

				$data = mcp_abilities_elementor_get_post_elements( $post_id );
				$element = mcp_abilities_elementor_build_post_tabs_element(
					$tabs,
					isset( $input['base_posts_settings'] ) && is_array( $input['base_posts_settings'] ) ? $input['base_posts_settings'] : array(),
					isset( $input['tabs_settings'] ) && is_array( $input['tabs_settings'] ) ? $input['tabs_settings'] : array(),
					isset( $input['element_id'] ) ? (string) $input['element_id'] : null,
					mcp_abilities_elementor_collect_element_ids( $data )
				);

				if ( is_wp_error( $element ) ) {
					return array( 'success' => false, 'message' => $element->get_error_message(), 'code' => $element->get_error_code() );
				}

				$tab_ids = array();
				$posts_widget_ids = array();
				foreach ( (array) ( $element['elements'] ?? array() ) as $tab_container ) {
					if ( is_array( $tab_container ) && isset( $tab_container['id'] ) ) {
						$tab_ids[] = (string) $tab_container['id'];
					}
					$first_child = is_array( $tab_container['elements'][0] ?? null ) ? $tab_container['elements'][0] : array();
					if ( isset( $first_child['id'] ) ) {
						$posts_widget_ids[] = (string) $first_child['id'];
					}
				}

				if ( ! empty( $input['dry_run'] ) ) {
					return array(
						'success'          => true,
						'id'               => $post_id,
						'element_id'       => (string) $element['id'],
						'tab_ids'          => $tab_ids,
						'posts_widget_ids' => $posts_widget_ids,
						'message'          => 'Dry run: Elementor post tabs prepared successfully',
						'element'          => $element,
					);
				}

				$inserted = mcp_abilities_elementor_insert_element_in_tree(
					$data,
					$element,
					isset( $input['parent_element_id'] ) ? (string) $input['parent_element_id'] : null,
					isset( $input['position'] ) ? (int) $input['position'] : -1
				);
				if ( ! $inserted ) {
					return array( 'success' => false, 'message' => 'Parent element not found' );
				}

				$save = mcp_abilities_elementor_save_document_data( $post_id, $data, (string) ( $input['cache_scope'] ?? 'post' ) );
				if ( empty( $save['success'] ) ) {
					return array( 'success' => false, 'message' => (string) ( $save['message'] ?? 'Failed to save Elementor data' ) );
				}

				return array(
					'success'          => true,
					'id'               => $post_id,
					'element_id'       => (string) $element['id'],
					'tab_ids'          => $tab_ids,
					'posts_widget_ids' => $posts_widget_ids,
					'message'          => 'Elementor post tabs added successfully',
					'link'             => get_permalink( $post_id ) ?: '',
					'cache'            => $save['cache'] ?? array(),
				);
			},
			'permission_callback' => function (): bool {
				return mcp_abilities_elementor_can_edit_posts();
			},
			'meta'                => mcp_abilities_elementor_ability_meta( false, false, false ),
		)
	);

	// =========================================================================
	// ELEMENTOR - Move / Remove / Duplicate / Reorder Elements
	// =========================================================================
	mcp_abilities_elementor_register_ability(
		'elementor/move-element',
		array(
			'label'               => 'Move Elementor Element',
			'description'         => 'Moves an Elementor element to a new parent/position without changing its data.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'                    => array( 'type' => 'integer' ),
					'element_id'            => array( 'type' => 'string' ),
					'new_parent_element_id' => array( 'type' => 'string', 'description' => 'New parent ID. Omit for top-level.' ),
					'position'              => array( 'type' => 'integer', 'default' => -1 ),
					'cache_scope'           => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
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
					'cache'      => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$post_id = (int) ( $input['id'] ?? 0 );
				$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
				if ( $post_id <= 0 || '' === $element_id ) {
					return array( 'success' => false, 'message' => 'id and element_id are required' );
				}
				$post = get_post( $post_id );
				if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'Post not found or permission denied' );
				}
				$data = mcp_abilities_elementor_get_post_elements( $post_id );
				$target_meta = mcp_abilities_elementor_find_element_meta( $data, $element_id );
				if ( ! is_array( $target_meta ) || ! is_array( $target_meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Element not found' );
				}
				$new_parent_id = isset( $input['new_parent_element_id'] ) ? trim( (string) $input['new_parent_element_id'] ) : '';
				if ( '' !== $new_parent_id && mcp_abilities_elementor_subtree_contains_element_id( $target_meta['element'], $new_parent_id ) ) {
					return array( 'success' => false, 'message' => 'Cannot move an element inside itself or one of its descendants' );
				}
				$removed = array();
				mcp_abilities_elementor_remove_element_from_tree( $data, $element_id, $removed );
				$inserted = mcp_abilities_elementor_insert_element_in_tree( $data, $removed['element'], $new_parent_id, isset( $input['position'] ) ? (int) $input['position'] : -1 );
				if ( ! $inserted ) {
					return array( 'success' => false, 'message' => 'New parent element not found' );
				}
				$save = mcp_abilities_elementor_save_document_data( $post_id, $data, (string) ( $input['cache_scope'] ?? 'post' ) );
				if ( empty( $save['success'] ) ) {
					return array( 'success' => false, 'message' => (string) ( $save['message'] ?? 'Failed to save Elementor data' ) );
				}
				return array( 'success' => true, 'id' => $post_id, 'element_id' => $element_id, 'message' => 'Element moved successfully', 'link' => get_permalink( $post_id ) ?: '', 'cache' => $save['cache'] ?? array() );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ),
		)
	);

	mcp_abilities_elementor_register_ability(
		'elementor/remove-element',
		array(
			'label'               => 'Remove Elementor Element',
			'description'         => 'Removes an Elementor element. Top-level or populated elements require force_delete=true.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'           => array( 'type' => 'integer' ),
					'element_id'   => array( 'type' => 'string' ),
					'force_delete' => array( 'type' => 'boolean', 'default' => false ),
					'cache_scope'  => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ), 'element_id' => array( 'type' => 'string' ), 'message' => array( 'type' => 'string' ), 'link' => array( 'type' => 'string' ), 'cache' => array( 'type' => 'object' ) ) ),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$post_id = (int) ( $input['id'] ?? 0 );
				$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
				if ( $post_id <= 0 || '' === $element_id ) {
					return array( 'success' => false, 'message' => 'id and element_id are required' );
				}
				$post = get_post( $post_id );
				if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'Post not found or permission denied' );
				}
				$data = mcp_abilities_elementor_get_post_elements( $post_id );
				$meta = mcp_abilities_elementor_find_element_meta( $data, $element_id );
				if ( ! is_array( $meta ) || ! is_array( $meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Element not found' );
				}
				$children = isset( $meta['element']['elements'] ) && is_array( $meta['element']['elements'] ) ? $meta['element']['elements'] : array();
				if ( empty( $input['force_delete'] ) && ( 0 === (int) ( $meta['depth'] ?? 0 ) || ! empty( $children ) ) ) {
					return array( 'success' => false, 'message' => 'Refusing to remove a top-level or populated Elementor element without force_delete=true' );
				}
				$removed = array();
				mcp_abilities_elementor_remove_element_from_tree( $data, $element_id, $removed );
				$save = mcp_abilities_elementor_save_document_data( $post_id, $data, (string) ( $input['cache_scope'] ?? 'post' ) );
				if ( empty( $save['success'] ) ) {
					return array( 'success' => false, 'message' => (string) ( $save['message'] ?? 'Failed to save Elementor data' ) );
				}
				return array( 'success' => true, 'id' => $post_id, 'element_id' => $element_id, 'message' => 'Element removed successfully', 'link' => get_permalink( $post_id ) ?: '', 'cache' => $save['cache'] ?? array() );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ),
		)
	);

	mcp_abilities_elementor_register_ability(
		'elementor/duplicate-element',
		array(
			'label'               => 'Duplicate Elementor Element',
			'description'         => 'Duplicates an Elementor element subtree with fresh IDs.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'                => array( 'type' => 'integer' ),
					'element_id'        => array( 'type' => 'string' ),
					'parent_element_id' => array( 'type' => 'string', 'description' => 'Optional destination parent. Omit to duplicate beside source at top level when possible.' ),
					'position'          => array( 'type' => 'integer', 'default' => -1 ),
					'cache_scope'       => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ), 'element_id' => array( 'type' => 'string' ), 'new_element_id' => array( 'type' => 'string' ), 'message' => array( 'type' => 'string' ), 'link' => array( 'type' => 'string' ), 'cache' => array( 'type' => 'object' ) ) ),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$post_id = (int) ( $input['id'] ?? 0 );
				$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
				if ( $post_id <= 0 || '' === $element_id ) {
					return array( 'success' => false, 'message' => 'id and element_id are required' );
				}
				$post = get_post( $post_id );
				if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'Post not found or permission denied' );
				}
				$data = mcp_abilities_elementor_get_post_elements( $post_id );
				$meta = mcp_abilities_elementor_find_element_meta( $data, $element_id );
				if ( ! is_array( $meta ) || ! is_array( $meta['element'] ?? null ) ) {
					return array( 'success' => false, 'message' => 'Element not found' );
				}
				$existing = mcp_abilities_elementor_collect_element_ids( $data );
				$duplicate = mcp_abilities_elementor_reassign_subtree_ids( $meta['element'], $existing );
				$inserted = mcp_abilities_elementor_insert_element_in_tree(
					$data,
					$duplicate,
					isset( $input['parent_element_id'] ) ? (string) $input['parent_element_id'] : null,
					isset( $input['position'] ) ? (int) $input['position'] : -1
				);
				if ( ! $inserted ) {
					return array( 'success' => false, 'message' => 'Destination parent not found' );
				}
				$save = mcp_abilities_elementor_save_document_data( $post_id, $data, (string) ( $input['cache_scope'] ?? 'post' ) );
				if ( empty( $save['success'] ) ) {
					return array( 'success' => false, 'message' => (string) ( $save['message'] ?? 'Failed to save Elementor data' ) );
				}
				return array( 'success' => true, 'id' => $post_id, 'element_id' => $element_id, 'new_element_id' => (string) $duplicate['id'], 'message' => 'Element duplicated successfully', 'link' => get_permalink( $post_id ) ?: '', 'cache' => $save['cache'] ?? array() );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ),
		)
	);

	mcp_abilities_elementor_register_ability(
		'elementor/reorder-elements',
		array(
			'label'               => 'Reorder Elementor Elements',
			'description'         => 'Reorders direct children under a parent container, or top-level elements when no parent is provided.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_ids' ),
				'properties'           => array(
					'id'                => array( 'type' => 'integer' ),
					'parent_element_id' => array( 'type' => 'string' ),
					'element_ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Element IDs in desired leading order. Unmentioned siblings stay after them.' ),
					'cache_scope'       => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ), 'message' => array( 'type' => 'string' ), 'link' => array( 'type' => 'string' ), 'cache' => array( 'type' => 'object' ) ) ),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$post_id = (int) ( $input['id'] ?? 0 );
				$ordered_ids = isset( $input['element_ids'] ) && is_array( $input['element_ids'] ) ? array_values( array_filter( array_map( 'strval', $input['element_ids'] ) ) ) : array();
				if ( $post_id <= 0 || empty( $ordered_ids ) ) {
					return array( 'success' => false, 'message' => 'id and element_ids are required' );
				}
				$post = get_post( $post_id );
				if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => 'Post not found or permission denied' );
				}
				$data = mcp_abilities_elementor_get_post_elements( $post_id );
				$reordered = mcp_abilities_elementor_reorder_children_in_tree( $data, $ordered_ids, isset( $input['parent_element_id'] ) ? (string) $input['parent_element_id'] : null );
				if ( ! $reordered ) {
					return array( 'success' => false, 'message' => 'Parent element not found or has no children' );
				}
				$save = mcp_abilities_elementor_save_document_data( $post_id, $data, (string) ( $input['cache_scope'] ?? 'post' ) );
				if ( empty( $save['success'] ) ) {
					return array( 'success' => false, 'message' => (string) ( $save['message'] ?? 'Failed to save Elementor data' ) );
				}
				return array( 'success' => true, 'id' => $post_id, 'message' => 'Elements reordered successfully', 'link' => get_permalink( $post_id ) ?: '', 'cache' => $save['cache'] ?? array() );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ),
		)
	);

	// =========================================================================
	// ELEMENTOR - Clone Data
	// =========================================================================
	mcp_abilities_elementor_register_ability(
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
					'confirm_dangerous_action' => mcp_abilities_elementor_dangerous_action_confirmation_schema( 'elementor/clone-data' ),
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
				$confirmation_error = mcp_abilities_elementor_dangerous_action_error_response(
					mcp_abilities_elementor_confirm_dangerous_action( $input, 'elementor/clone-data' ),
					'elementor/clone-data'
				);
				if ( null !== $confirmation_error ) {
					return $confirmation_error;
				}

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

				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $normalized_source_data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$normalized_source_data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $normalized_source_data;
				$json_data = wp_json_encode( $normalized_source_data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode cloned Elementor data after global style policy normalization' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( $target->ID, wp_slash( $json_data ) );
				update_post_meta( $target->ID, '_elementor_edit_mode', 'builder' );

				if ( $include_page_settings ) {
					mcp_abilities_elementor_update_guarded_page_settings( $target->ID, $source_page_settings ?: array() );
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
	mcp_abilities_elementor_register_ability(
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
					'confirm_dangerous_action' => mcp_abilities_elementor_dangerous_action_confirmation_schema( 'elementor/patch-data' ),
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
					'elementor_write_guard' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$confirmation_error = mcp_abilities_elementor_dangerous_action_error_response(
					mcp_abilities_elementor_confirm_dangerous_action( $input, 'elementor/patch-data' ),
					'elementor/patch-data'
				);
				if ( null !== $confirmation_error ) {
					return $confirmation_error;
				}

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
					$style_policy    = mcp_abilities_elementor_enforce_global_style_policy( $normalized_data );
					if ( empty( $style_policy['success'] ) ) {
						return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
					}
					$normalized_data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $normalized_data;
					$write_guard     = mcp_abilities_elementor_audit_write_guard( $normalized_data );
					if ( empty( $write_guard['success'] ) ) {
						return mcp_abilities_elementor_write_guard_error_response( $write_guard );
					}
					$normalized_json = wp_json_encode( $normalized_data );
					if ( false === $normalized_json ) {
						return array( 'success' => false, 'message' => 'Replacement produced valid JSON but failed to re-encode after normalization' );
				}

				// Update Elementor data.
				mcp_abilities_elementor_update_guarded_elementor_data( $input['id'], wp_slash( $normalized_json ) );

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
	mcp_abilities_elementor_register_ability(
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
					'elementor_write_guard' => array( 'type' => 'object' ),
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$write_guard = mcp_abilities_elementor_audit_write_guard( $data );
				if ( empty( $write_guard['success'] ) ) {
					return mcp_abilities_elementor_write_guard_error_response( $write_guard );
				}

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

				mcp_abilities_elementor_update_guarded_elementor_data( $input['id'], wp_slash( $json_data ) );

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
	mcp_abilities_elementor_register_ability(
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
					'elementor_write_guard' => array( 'type' => 'object' ),
					'elementor_translation_guard' => array( 'type' => 'object' ),
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

				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( array( $merged_element ) );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				if ( isset( $style_policy['data'][0] ) && is_array( $style_policy['data'][0] ) ) {
					$merged_element = $style_policy['data'][0];
				}

				mcp_abilities_elementor_replace_element_in_tree( $data, (string) $input['element_id'], $merged_element );
				$data = mcp_abilities_elementor_normalize_background_container_subtrees( $data );
				$write_guard = mcp_abilities_elementor_audit_write_guard( $data );
				if ( empty( $write_guard['success'] ) ) {
					return mcp_abilities_elementor_write_guard_error_response( $write_guard );
				}
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				$translation_guard = mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
				$cache_details = mcp_abilities_elementor_invalidate_after_write( (int) $input['id'], $requested_cache_scope );

				return array(
					'success'                    => true,
					'id'                         => (int) $input['id'],
					'element_id'                 => (string) $input['element_id'],
					'message'                    => 'Element settings merged successfully',
					'link'                       => get_permalink( (int) $input['id'] ),
					'dry_run'                    => false,
					'unchanged'                  => false,
					'settings'                   => $merged_element['settings'],
					'cache'                      => $cache_details,
					'elementor_translation_guard' => $translation_guard,
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	// ELEMENTOR - Apply Text Hierarchy
	// =========================================================================
	mcp_abilities_elementor_register_ability(
		'elementor/apply-text-hierarchy',
		array(
			'label'               => 'Apply Elementor Text Hierarchy',
				'description'         => 'Applies a coherent text hierarchy to a subtree using Elementor Kit global typography references. Local font-size/weight/line-height overrides are rejected by the global style policy. Supports `dry_run`.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id' ),
				'properties'           => array(
					'id'            => array( 'type' => 'integer', 'description' => 'Post/Page ID containing the subtree.' ),
					'element_id'    => array( 'type' => 'string', 'description' => 'Root element ID for the subtree.' ),
					'include_root'  => array( 'type' => 'boolean', 'default' => true, 'description' => 'If true, include the root element.' ),
					'max_depth'     => array( 'type' => 'integer', 'default' => -1, 'description' => 'Maximum descendant depth. Use -1 for unlimited.' ),
						'heading_scale' => array( 'type' => 'object', 'description' => 'Optional map of heading tag (h1-h6/default) to global typography token refs such as { "global_typography": "primary" }.' ),
						'body_style'    => array( 'type' => 'object', 'description' => 'Optional body text global typography token ref, for example { "global_typography": "text" }.' ),
						'button_style'  => array( 'type' => 'object', 'description' => 'Optional button global typography token ref, for example { "global_typography": "accent" }.' ),
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
						'h1'      => array( 'global_typography' => 'primary' ),
						'h2'      => array( 'global_typography' => 'primary' ),
						'h3'      => array( 'global_typography' => 'secondary' ),
						'h4'      => array( 'global_typography' => 'secondary' ),
						'h5'      => array( 'global_typography' => 'secondary' ),
						'h6'      => array( 'global_typography' => 'secondary' ),
						'default' => array( 'global_typography' => 'secondary' ),
					);
					$body_style = isset( $input['body_style'] ) && is_array( $input['body_style'] ) ? $input['body_style'] : array(
						'global_typography' => 'text',
					);
					$button_style = isset( $input['button_style'] ) && is_array( $input['button_style'] ) ? $input['button_style'] : array(
						'global_typography' => 'accent',
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}
				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}
				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}
				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}
				mcp_abilities_elementor_update_guarded_elementor_data( (int) $input['id'], wp_slash( $json_data ) );
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
}
