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
 * Include editable Elementor metadata in WordPress revision change detection.
 *
 * Native Elementor copies its metadata when WordPress creates a revision. Its
 * public preview snapshots use the same revision API, including metadata-only
 * edits that leave the ordinary post fields unchanged. Derived render caches
 * are deliberately excluded from change detection.
 *
 * @param bool    $post_has_changed Existing WordPress or plugin result.
 * @param WP_Post $latest_revision Latest non-autosave revision.
 * @param WP_Post $post Current document.
 * @return bool
 */
function mcp_abilities_elementor_revision_has_changed( bool $post_has_changed, WP_Post $latest_revision, WP_Post $post ): bool {
	if ( $post_has_changed || 'revision' !== $latest_revision->post_type || (int) $latest_revision->post_parent !== (int) $post->ID || 'revision' === $post->post_type ) {
		return $post_has_changed;
	}

	if ( '' === get_post_meta( $post->ID, '_elementor_data', true ) && '' === get_post_meta( $latest_revision->ID, '_elementor_data', true ) && ! get_post_meta( $post->ID, '_elementor_edit_mode', true ) && ! get_post_meta( $latest_revision->ID, '_elementor_edit_mode', true ) ) {
		return false;
	}

	$meta_keys = array( '_elementor_data', '_elementor_page_settings', '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_wp_page_template', '_thumbnail_id' );
	foreach ( $meta_keys as $meta_key ) {
		if ( get_post_meta( $post->ID, $meta_key, false ) !== get_post_meta( $latest_revision->ID, $meta_key, false ) ) {
			return true;
		}
	}

	// Native preview falls back to the highest revision ID, including autosaves.
	// WordPress compares a non-autosave above; both candidates must be current.
	$latest = wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC' ) );
	$preview_revision = $latest ? reset( $latest ) : null;
	if ( $preview_revision instanceof WP_Post && (int) $preview_revision->ID !== (int) $latest_revision->ID && (int) $preview_revision->post_parent === (int) $post->ID ) {
		foreach ( $meta_keys as $meta_key ) {
			if ( get_post_meta( $post->ID, $meta_key, false ) !== get_post_meta( $preview_revision->ID, $meta_key, false ) ) {
				return true;
			}
		}
	}

	return false;
}
