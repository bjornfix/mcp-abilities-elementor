<?php
/**
 * Lightweight architecture regression checks for helper modules.
 *
 * These tests intentionally avoid bootstrapping WordPress. They cover pure
 * helper interfaces so refactors can catch contract drift before Plugin Check.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$registered_abilities = array();
$workflow_authorizations = array();

function current_user_can( string $capability ): bool {
	return 'edit_posts' === $capability;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function wp_register_ability( string $name, array $args ): void {
	global $registered_abilities;
	$registered_abilities[ $name ] = $args;
}

function apply_filters( string $hook, $value, ...$args ) {
	global $workflow_authorizations;
	if ( 'devenia_workflow_elementor_source_write_authorized' === $hook ) {
		$workflow_authorizations[] = array( $args[0] ?? 0, $args[1] ?? '' );
	}
	return $value;
}

require_once __DIR__ . '/../includes/ability-schema.php';
require_once __DIR__ . '/../includes/ability-registry.php';
require_once __DIR__ . '/../includes/template-query.php';
require_once __DIR__ . '/../includes/document-repository.php';
require_once __DIR__ . '/../includes/design-audit-runner.php';

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$meta = mcp_abilities_elementor_ability_meta( false, true, false );
assert_true( false === $meta['annotations']['readonly'], 'write meta readonly flag' );
assert_true( true === $meta['annotations']['destructive'], 'write meta destructive flag' );
assert_true( false === $meta['annotations']['idempotent'], 'write meta idempotent flag' );

$cache_schema = mcp_abilities_elementor_cache_scope_schema();
assert_true( array( 'none', 'post', 'site' ) === $cache_schema['enum'], 'cache scope enum' );
assert_true( 'post' === $cache_schema['default'], 'cache scope default' );

mcp_abilities_elementor_register_read_ability(
	'elementor/test-read',
	array(
		'label'         => 'Test Read',
		'description'   => 'Test read ability.',
		'input_schema'  => array( 'type' => 'object' ),
		'output_schema' => array( 'type' => 'object' ),
	),
	static function (): array {
		return array( 'success' => true );
	}
);

assert_true( isset( $registered_abilities['elementor/test-read'] ), 'ability registered through seam' );
assert_true( is_callable( $registered_abilities['elementor/test-read']['permission_callback'] ), 'default permission callback' );
assert_true( true === $registered_abilities['elementor/test-read']['meta']['annotations']['readonly'], 'read registrar annotation' );

mcp_abilities_elementor_register_write_ability(
	'elementor/test-write',
	array(
		'label'         => 'Test Write',
		'description'   => 'Test write ability.',
		'input_schema'  => array( 'type' => 'object' ),
		'output_schema' => array( 'type' => 'object' ),
	),
	static function ( array $input ): array {
		return array( 'success' => true, 'id' => $input['id'] ?? 0 );
	}
);

$write_result = $registered_abilities['elementor/test-write']['execute_callback']( array( 'id' => 123 ) );
assert_true( true === $write_result['success'], 'write wrapper invokes callback' );
assert_true( array( 123, 'elementor/test-write' ) === $workflow_authorizations[0], 'write wrapper issues source authority request' );

$patterns = mcp_abilities_elementor_get_template_pattern_names();
assert_true( in_array( 'custom', $patterns, true ), 'custom template pattern exists' );
assert_true( in_array( 'split_panel_carousel_surface', $patterns, true ), 'split carousel pattern exists' );

$score = mcp_abilities_elementor_score_template_pattern_match(
	'Split Panel Carousel Template',
	array( 'split', 'panel', 'carousel' ),
	'carousel'
);
assert_true( $score >= 7, 'template pattern score includes keywords and search words' );

$failure = mcp_abilities_elementor_failure( 'Nope', array( 'id' => 123 ) );
assert_true( false === $failure['success'], 'standard failure success flag' );
assert_true( 123 === $failure['id'], 'standard failure extras' );

$audit = mcp_abilities_elementor_run_design_audit(
	array(),
	static function ( array $element, array &$collector ): void {
		$collector[] = $element;
	},
	static function ( array $collector ): array {
		return array( 'count' => count( $collector ) );
	},
	'Done',
	array()
);
assert_true( true === $audit['success'], 'audit runner empty input success' );
assert_true( 0 === $audit['audit']['count'], 'audit runner empty collector' );

echo "Architecture helper tests passed.\n";
