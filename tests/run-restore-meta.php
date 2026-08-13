<?php
/**
 * Regression checks for guarded translation sibling meta restore.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ): void {
	unset( $args );
}

function __( ...$args ): string {
	return (string) $args[0];
}

function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}

	return addslashes( (string) $value );
}

function get_option( string $name, $default = false ) {
	if ( 'elementor_active_kit' === $name ) {
		return '259';
	}

	return $default;
}

require_once __DIR__ . '/../mcp-abilities-elementor.php';

function assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_false( bool $actual, string $message ): void {
	if ( false !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_true( bool $actual, string $message ): void {
	if ( true !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$json = '[{"id":"x","settings":{"editor":"<p><a href=\"/en/free-inspection/\">Text</a></p>"}}]';
assert_same(
	wp_slash( $json ),
	mcp_abilities_elementor_prepare_sibling_meta_restore_value( '_elementor_data', $json ),
	'Elementor data JSON string is slashed before restore'
);

$settings = '{"hide_title":"yes"}';
assert_same(
	wp_slash( $settings ),
	mcp_abilities_elementor_prepare_sibling_meta_restore_value( '_elementor_page_settings', $settings ),
	'Elementor page settings JSON string is slashed before restore'
);

$plain = 'plain text';
assert_same(
	$plain,
	mcp_abilities_elementor_prepare_sibling_meta_restore_value( '_thumbnail_id', $plain ),
	'Non-JSON meta is not slashed'
);

assert_false(
	mcp_abilities_elementor_is_local_color_setting_key( 'tabs_title_background_color_background' ),
	'Elementor background mode selector is not treated as a local color'
);

assert_true(
	mcp_abilities_elementor_is_local_color_setting_key( 'tabs_title_background_color_color' ),
	'Elementor background color value remains subject to global color policy'
);

assert_same(
	259,
	mcp_abilities_elementor_get_active_kit_id(),
	'Active Elementor kit option is normalized to an integer at the owner seam'
);

$popup_display_settings = mcp_abilities_elementor_build_popup_display_settings(
	array(
		'timing' => array(
			'show_times' => 0,
		),
	),
	array(
		'triggers' => array(
			'page_load' => 'yes',
		),
		'timing'   => array(
			'page_load_delay' => 0,
			'times'           => 'yes',
			'times_count'     => 1,
		),
	)
);
assert_same(
	array(
		'page_load_delay' => 0,
	),
	$popup_display_settings['timing'],
	'A zero show-times value clears an existing popup display limit'
);

$popup_display_schema = mcp_abilities_elementor_popup_display_schema();
assert_same(
	0,
	$popup_display_schema['properties']['timing']['properties']['show_times']['minimum'],
	'Popup display Interface exposes zero as the explicit limit-clearing value'
);

echo "Restore meta tests passed.\n";
