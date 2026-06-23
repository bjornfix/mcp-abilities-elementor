<?php
/**
 * Shared ability schema and annotation helpers.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check the standard editing capability for Elementor content abilities.
 *
 * @return bool
 */
function mcp_abilities_elementor_can_edit_posts(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Build a standard MCP ability annotation block.
 *
 * @param bool $readonly Whether the ability is read-only.
 * @param bool $destructive Whether the ability is destructive.
 * @param bool $idempotent Whether repeated calls are expected to be idempotent.
 * @return array
 */
function mcp_abilities_elementor_ability_meta( bool $readonly, bool $destructive = false, bool $idempotent = true ): array {
	return array(
		'annotations' => array(
			'readonly'    => $readonly,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		),
	);
}

/**
 * Build the standard cache-scope input schema.
 *
 * @param string $default Default cache scope.
 * @return array
 */
function mcp_abilities_elementor_cache_scope_schema( string $default = 'post' ): array {
	return array(
		'type'        => 'string',
		'enum'        => array( 'none', 'post', 'site' ),
		'default'     => $default,
		'description' => 'Cache invalidation scope after the write. Use post by default, site for stronger invalidation, or none only for debugging.',
	);
}

