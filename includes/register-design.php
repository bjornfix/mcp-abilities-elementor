<?php
/**
 * Design audit and guidance abilities.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register design audit and guidance abilities.
 */
function mcp_abilities_elementor_register_design_abilities(): void {
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
						'enum'        => array( 'all', 'layout', 'widgets', 'patterns', 'policy' ),
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
				} elseif ( 'patterns' === $topic ) {
					$guidance = array(
						'policy'   => $catalog['policy'],
						'patterns' => $catalog['patterns'],
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
}
