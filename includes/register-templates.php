<?php
/**
 * Template management abilities.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register template management abilities.
 */
function mcp_abilities_elementor_register_template_abilities(): void {
	// =========================================================================
	// ELEMENTOR - List Templates
	// =========================================================================
	mcp_abilities_elementor_register_ability(
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
				$input  = is_array( $input ) ? $input : array();
				$result = mcp_abilities_elementor_query_templates(
					array(
						'type'          => isset( $input['type'] ) && is_string( $input['type'] ) ? $input['type'] : 'all',
						'sub_type'      => isset( $input['sub_type'] ) && is_string( $input['sub_type'] ) ? $input['sub_type'] : '',
						'sub_type_like' => ! empty( $input['sub_type_like'] ),
					)
				);

				if ( empty( $result['success'] ) ) {
					return $result;
				}

				return array(
					'success'   => true,
					'templates' => $result['templates'],
					'total'     => $result['total'],
					'message'   => 'Templates retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return mcp_abilities_elementor_can_edit_posts();
			},
			'meta'                => mcp_abilities_elementor_ability_meta( true ),
		)
	);

	// =========================================================================
	// ELEMENTOR - Find Template For Pattern
	// =========================================================================
	mcp_abilities_elementor_register_ability(
		'elementor/find-template-for-pattern',
		array(
			'label'               => 'Find Elementor Template For Pattern',
			'description'         => 'Finds saved Elementor templates that may match a reusable layout pattern. Use this before manually authoring repeated containers/widgets; if no match exists for a repeated pattern, create a template before applying the pattern broadly.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'pattern' => array(
						'type'        => 'string',
						'enum'        => array(
							'split_panel_carousel_surface',
							'static_split_panel_image_surface',
							'actual_media_gallery_carousel',
							'dynamic_related_or_archive_cards',
							'static_image_card_grid',
							'curated_image_gallery',
							'repeated_promo_or_cta_modules',
							'custom',
						),
						'default'     => 'custom',
						'description' => 'Reusable layout pattern to search for.',
					),
					'search' => array(
						'type'        => 'string',
						'description' => 'Optional additional search words from the current site or design pattern.',
					),
					'type' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'page', 'section', 'container', 'loop-item', 'header', 'footer', 'single', 'archive', 'popup' ),
						'default'     => 'all',
						'description' => 'Optional Elementor template type filter.',
					),
					'min_score' => array(
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Minimum title-match score required to include a template candidate.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'                         => array( 'type' => 'boolean' ),
					'pattern'                         => array( 'type' => 'string' ),
					'template_first_required'         => array( 'type' => 'boolean' ),
					'create_template_if_missing'      => array( 'type' => 'boolean' ),
					'template_creation_guidance'      => array( 'type' => 'string' ),
					'candidates'                      => array( 'type' => 'array' ),
					'total'                           => array( 'type' => 'integer' ),
					'message'                         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input    = is_array( $input ) ? $input : array();
				$pattern  = isset( $input['pattern'] ) && is_string( $input['pattern'] ) ? sanitize_key( $input['pattern'] ) : 'custom';
				$search   = isset( $input['search'] ) && is_string( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';
				$type     = isset( $input['type'] ) && is_string( $input['type'] ) ? sanitize_key( $input['type'] ) : 'all';
				$min_score = isset( $input['min_score'] ) ? max( 0, (int) $input['min_score'] ) : 1;

				$pattern_keywords = mcp_abilities_elementor_get_template_pattern_keywords();
				$keywords         = $pattern_keywords[ $pattern ] ?? array();
				if ( 'custom' === $pattern && '' !== $search ) {
					$keywords = preg_split( '/[^a-z0-9]+/', strtolower( $search ) );
					$keywords = is_array( $keywords ) ? array_values( array_filter( $keywords, static function ( string $word ): bool {
						return strlen( $word ) >= 3;
					} ) ) : array();
				}

				$template_result = mcp_abilities_elementor_query_templates(
					array(
						'type' => $type,
					)
				);

				if ( empty( $template_result['success'] ) ) {
					return $template_result;
				}

				$candidates = array();

				foreach ( $template_result['templates'] as $template ) {
					$score = mcp_abilities_elementor_score_template_pattern_match( (string) $template['title'], $keywords, $search );

					if ( $score < $min_score ) {
						continue;
					}

					$candidates[] = array(
						'id'       => (int) $template['id'],
						'title'    => (string) $template['title'],
						'type'     => (string) $template['type'],
						'sub_type' => (string) $template['sub_type'],
						'score'    => $score,
						'edit'     => admin_url( 'post.php?post=' . (int) $template['id'] . '&action=elementor' ),
						'modified' => (string) $template['modified'],
					);
				}

				usort(
					$candidates,
					static function ( array $left, array $right ): int {
						$score_compare = (int) $right['score'] <=> (int) $left['score'];
						if ( 0 !== $score_compare ) {
							return $score_compare;
						}
						return strcasecmp( (string) $left['title'], (string) $right['title'] );
					}
				);

				$message = empty( $candidates )
					? 'No matching saved Elementor template found. If this pattern will be reused, create a template before applying it broadly.'
					: 'Matching saved Elementor templates found. Reuse a candidate before raw authoring.';

				return array(
					'success'                    => true,
					'pattern'                    => $pattern,
					'template_first_required'    => true,
					'create_template_if_missing' => true,
					'template_creation_guidance' => 'When a reusable Elementor pattern is identified and no suitable saved template exists, create a template with elementor/create-template before applying the same pattern to additional pages.',
					'candidates'                 => array_values( $candidates ),
					'total'                      => count( $candidates ),
					'message'                    => $message,
				);
			},
			'permission_callback' => function (): bool {
				return mcp_abilities_elementor_can_edit_posts();
			},
			'meta'                => mcp_abilities_elementor_ability_meta( true ),
		)
	);

	// =========================================================================
	// ELEMENTOR - Clear Cache
	// =========================================================================
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
					$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $elementor_data );
					if ( empty( $style_policy['success'] ) ) {
						return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
					}
					$elementor_data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $elementor_data;
					$json_data = wp_json_encode( $elementor_data );
					if ( false === $json_data ) {
						return array( 'success' => false, 'message' => 'Failed to encode Elementor document data' );
					}
					update_post_meta( $post_id, '_elementor_data', wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
					$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $normalized_data );
					if ( empty( $style_policy['success'] ) ) {
						return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
					}
					$normalized_data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $normalized_data;
					$json_data = wp_json_encode( $normalized_data );
					if ( false === $json_data ) {
						return array( 'success' => false, 'message' => 'Failed to encode updated Elementor template data' );
					}
					update_post_meta( $post->ID, '_elementor_data', wp_slash( $json_data ) );
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $normalized_content );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$normalized_content = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $normalized_content;
				$json_data = wp_json_encode( $normalized_content );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode imported Elementor template data' );
				}
				update_post_meta( $post_id, '_elementor_template_type', $template_type );
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $post_id, '_elementor_data', wp_slash( $json_data ) );

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
}
