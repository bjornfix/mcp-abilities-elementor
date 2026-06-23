<?php
/**
 * Site and kit management abilities.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register site and kit management abilities.
 */
function mcp_abilities_elementor_register_site_tool_abilities(): void {
	// =========================================================================
	// ELEMENTOR - List Global Widgets
	// =========================================================================
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
	mcp_abilities_elementor_register_ability(
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
