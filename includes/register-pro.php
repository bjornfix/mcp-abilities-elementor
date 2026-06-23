<?php
/**
 * Elementor Pro data abilities.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register elementor pro data abilities.
 */
function mcp_abilities_elementor_register_pro_abilities(): void {
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
}
