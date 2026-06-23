<?php
/**
 * Elementor document loading and saving helpers.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a standard failed ability response.
 *
 * @param string $message Failure message.
 * @param array  $extra Additional response data.
 * @return array
 */
function mcp_abilities_elementor_failure( string $message, array $extra = array() ): array {
	return array_merge(
		array(
			'success' => false,
			'message' => $message,
		),
		$extra
	);
}

/**
 * Load an Elementor document and centralize post, permission, data and settings handling.
 *
 * @param int  $post_id Post ID.
 * @param bool $require_data Whether empty Elementor data is a failure.
 * @param bool $check_permission Whether to require edit_post on the post.
 * @return array
 */
function mcp_abilities_elementor_load_document( int $post_id, bool $require_data = true, bool $check_permission = true ): array {
	if ( $post_id <= 0 ) {
		return mcp_abilities_elementor_failure( 'Post/Page ID is required' );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return mcp_abilities_elementor_failure( 'Post not found' );
	}

	if ( $check_permission && ! current_user_can( 'edit_post', $post->ID ) ) {
		return mcp_abilities_elementor_failure( 'You do not have permission to access this post' );
	}

	$raw_data = mcp_abilities_elementor_get_raw_data_meta( $post_id );
	if ( $require_data && '' === $raw_data ) {
		return mcp_abilities_elementor_failure(
			'No Elementor data found for this post',
			array(
				'id'    => $post_id,
				'title' => $post->post_title,
			)
		);
	}

	$decode_error = null;
	$data         = mcp_abilities_elementor_decode_data_meta( $raw_data, $decode_error );

	return array(
		'success'       => true,
		'id'            => $post_id,
		'post'          => $post,
		'title'         => $post->post_title,
		'raw_data'      => $raw_data,
		'data'          => $data,
		'decode_error'  => $decode_error,
		'edit_mode'     => get_post_meta( $post_id, '_elementor_edit_mode', true ) ?: 'not set',
		'page_settings' => get_post_meta( $post_id, '_elementor_page_settings', true ) ?: array(),
	);
}

/**
 * Load an Elementor document from ability input.
 *
 * @param array $input Ability input.
 * @param bool  $require_data Whether empty Elementor data is a failure.
 * @return array
 */
function mcp_abilities_elementor_load_document_from_input( array $input, bool $require_data = true ): array {
	return mcp_abilities_elementor_load_document( isset( $input['id'] ) ? (int) $input['id'] : 0, $require_data );
}

/**
 * Save document data with normalized cache scope from ability input.
 *
 * @param int   $post_id Post ID.
 * @param array $data Elementor data.
 * @param array $input Ability input.
 * @return array
 */
function mcp_abilities_elementor_save_document_from_input( int $post_id, array $data, array $input ): array {
	return mcp_abilities_elementor_save_document_data(
		$post_id,
		$data,
		mcp_abilities_elementor_normalize_cache_scope( $input['cache_scope'] ?? 'post', 'post' )
	);
}

