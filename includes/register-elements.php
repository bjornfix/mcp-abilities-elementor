<?php
/**
 * Element lookup abilities.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register element lookup abilities.
 */
function mcp_abilities_elementor_register_element_lookup_abilities(): void {
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
				$style_policy = mcp_abilities_elementor_enforce_global_style_policy( $data );
				if ( empty( $style_policy['success'] ) ) {
					return mcp_abilities_elementor_global_style_policy_error_response( $style_policy );
				}
				$data = is_array( $style_policy['data'] ?? null ) ? $style_policy['data'] : $data;
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
}
