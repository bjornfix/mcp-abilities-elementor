<?php
/**
 * Boundary-coherence regression checks.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ): void {}

require_once __DIR__ . '/../mcp-abilities-elementor.php';

function assert_boundary_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$boxed_changed = array();
$boxed = mcp_abilities_elementor_enforce_boundary_coherence_subtree(
	array(
		'id'       => 'boxed-root',
		'elType'   => 'container',
		'settings' => array( 'content_width' => 'full' ),
		'elements' => array(),
	),
	'boxed',
	1140,
	true,
	0,
	0,
	false,
	false,
	false,
	array( 'unit' => 'px', 'size' => 1140 ),
	$boxed_changed
);

assert_boundary_true( 'boxed' === $boxed['settings']['content_width'], 'boxed mode selects native boxed content width' );
assert_boundary_true( 1140 === $boxed['settings']['boxed_width']['size'], 'boxed mode stores the requested width' );
assert_boundary_true( array( 'boxed-root' ) === $boxed_changed, 'boxed content-width change is observable' );

$full_changed = array();
$full = mcp_abilities_elementor_enforce_boundary_coherence_subtree(
	array(
		'id'       => 'full-root',
		'elType'   => 'container',
		'settings' => array( 'content_width' => 'boxed' ),
		'elements' => array(),
	),
	'full_width',
	null,
	true,
	0,
	0,
	false,
	false,
	false,
	null,
	$full_changed
);

assert_boundary_true( 'full' === $full['settings']['content_width'], 'full-width mode selects native full content width' );
assert_boundary_true( array( 'full-root' ) === $full_changed, 'full content-width change is observable without boxed_width' );

echo "Boundary coherence tests passed.\n";
