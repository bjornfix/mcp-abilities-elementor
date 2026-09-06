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

/**
 * Explain the native shared design workflow before authoring Elementor data.
 *
 * @return string
 */
function mcp_abilities_elementor_authoring_guidance(): string {
	return 'Before authoring, call elementor/get-style-guide and elementor/find-template-for-pattern. Use the active Kit token IDs in settings.__globals__: colors use globals/colors?id=<id>; typography uses globals/typography?id=<id>. Read elementor/get-widget-controls for the widget setting keys. Preserve inherited styles and existing global references; do not copy resolved colors or font values into local settings. For repeated content, use a native shared template reference or Theme Builder conditions so later template edits reach every consumer; inserting a copy of template data does not keep that link. Inspect elementor/get-template before reuse. Change a shared Kit or template only when the requested change should affect its consumers.';
}
