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

function get_post( int $post_id ) {
	return (object) array( 'ID' => $post_id, 'post_content' => $GLOBALS['translation_content'][ $post_id ] ?? '' );
}

function apply_filters( string $name, $value, ...$args ) {
	if ( 'mcp_abilities_elementor_translation_sibling_post_ids' === $name ) {
		return $GLOBALS['translation_siblings'][ $args[0] ] ?? array();
	}
	return $value;
}

function get_post_meta( int $post_id, string $meta_key, bool $single = true ) {
	return $GLOBALS['translation_meta'][ $post_id ][ $meta_key ] ?? '';
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function update_post_meta( int $post_id, string $meta_key, $value ) {
	$GLOBALS['translation_meta'][ $post_id ][ $meta_key ] = wp_unslash( $value );
	return true;
}

function wp_update_post( array $data, bool $wp_error = false ) {
	if ( isset( $data['post_content'] ) ) {
		$GLOBALS['translation_content'][ $data['ID'] ] = stripslashes( $data['post_content'] );
	}
	return $data['ID'];
}

function clean_post_cache( int $post_id ): void {}

function wp_json_encode( $value ) {
	return json_encode( $value );
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

$structured_settings = array( 'hide_title' => 'yes', 'nested' => array( 'text' => '<a href="/en/">"English"</a> C:\\files' ) );
assert_same(
	wp_slash( $structured_settings ),
	mcp_abilities_elementor_prepare_sibling_meta_restore_value( '_elementor_page_settings', $structured_settings ),
	'Structured page settings preserve their native array shape and escaped values'
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

echo "Restore meta tests passed.\n";

// Exercise the real shutdown callbacks, including copies after a guarded save.
$english = '[{"title":"FIND A DEALER","editor":"<a href=\\"/en/find-a-dealer/\\">English</a>"}]';
$norwegian_one = '[{"title":"Finn forhandler"}]';
$norwegian_two = '[{"title":"Finn forhandler","lead":"Din lokale glassmester"}]';
$english_edit = '[{"title":"FIND YOUR LOCAL DEALER"}]';
$expected = array();
$expected_content = array();
$english_content = '<h1>FIND A DEALER</h1> [wpsl]';

foreach ( array( 1, 11, 21, 31, 41, 51, 61 ) as $source_id ) {
	$sibling_id = $source_id + 1;
	$GLOBALS['translation_siblings'][ $source_id ] = array( $sibling_id );
	$GLOBALS['translation_siblings'][ $sibling_id ] = array( $source_id );
	$GLOBALS['translation_meta'][ $sibling_id ]['_elementor_data'] = $english;
	$expected[ $sibling_id ]['_elementor_data'] = $english;
	$GLOBALS['translation_content'][ $source_id ] = '<h1>Finn forhandler</h1>';
	$GLOBALS['translation_content'][ $sibling_id ] = $english_content;
	$expected_content[ $source_id ] = $GLOBALS['translation_content'][ $source_id ];
	$expected_content[ $sibling_id ] = $english_content;
}

// Model the observed WPML event before the owner's final PHP restore:
// translated metadata is copied, then Elementor derives post_content from it.
register_shutdown_function(
	static function (): void {
		foreach ( array( 1, 11, 21, 31, 41, 51, 61 ) as $source_id ) {
			foreach ( $GLOBALS['translation_siblings'][ $source_id ] as $sibling_id ) {
				$data = get_post_meta( $source_id, '_elementor_data', true );
				if ( '' !== $data ) {
					update_post_meta( $sibling_id, '_elementor_data', wp_slash( $data ) );
				}
				wp_update_post( array( 'ID' => $sibling_id, 'post_content' => wp_slash( '<h1>Finn forhandler</h1>' ) ) );
			}
		}
	}
);

// A single source write still restores a copy made by a late save hook.
mcp_abilities_elementor_update_guarded_elementor_data( 1, wp_slash( $norwegian_one ) );
update_post_meta( 2, '_elementor_data', wp_slash( $norwegian_one ) );
$expected[1]['_elementor_data'] = $norwegian_one;

// A second source write must not replace the first English baseline.
foreach ( array( $norwegian_one, $norwegian_two ) as $value ) {
	mcp_abilities_elementor_update_guarded_elementor_data( 11, wp_slash( $value ) );
	update_post_meta( 12, '_elementor_data', wp_slash( $value ) );
}
$expected[11]['_elementor_data'] = $norwegian_two;

// The page-settings guard shares the same request-level restoration.
$settings_english = '{"title":"English settings"}';
$settings_one = '{"title":"Source settings"}';
$settings_two = '{"title":"Source settings","hide_title":"yes"}';
$settings_edit = '{"title":"Updated English settings"}';
$GLOBALS['translation_meta'][12]['_elementor_page_settings'] = $structured_settings;
foreach ( array( $settings_one, $settings_two ) as $value ) {
	mcp_abilities_elementor_update_guarded_page_settings( 11, wp_slash( $value ) );
	update_post_meta( 12, '_elementor_page_settings', wp_slash( $value ) );
}
$expected[11]['_elementor_page_settings'] = $settings_two;
$expected[12]['_elementor_page_settings'] = $structured_settings;
$GLOBALS['translation_meta'][22]['_elementor_page_settings'] = $settings_english;
foreach ( array( $settings_one, $settings_two ) as $value ) {
	mcp_abilities_elementor_update_guarded_page_settings( 21, wp_slash( $value ) );
	update_post_meta( 22, '_elementor_page_settings', wp_slash( $value ) );
}
mcp_abilities_elementor_update_guarded_page_settings( 22, wp_slash( $settings_edit ) );
mcp_abilities_elementor_update_guarded_page_settings( 21, wp_slash( $settings_two ) );
update_post_meta( 22, '_elementor_page_settings', wp_slash( $settings_two ) );
$expected[21]['_elementor_page_settings'] = $settings_two;
$expected[22]['_elementor_page_settings'] = $settings_edit;

// Multiple siblings retain their own first values.
$GLOBALS['translation_siblings'][31][] = 33;
$GLOBALS['translation_meta'][33]['_elementor_data'] = '[{"title":"HITTA EN BUTIK"}]';
$expected[33]['_elementor_data'] = $GLOBALS['translation_meta'][33]['_elementor_data'];
$GLOBALS['translation_content'][33] = '<h1>HITTA EN BUTIK</h1>';
$expected_content[33] = $GLOBALS['translation_content'][33];
foreach ( array( $norwegian_one, $norwegian_two ) as $value ) {
	mcp_abilities_elementor_update_guarded_elementor_data( 31, wp_slash( $value ) );
	foreach ( array( 32, 33 ) as $sibling_id ) {
		update_post_meta( $sibling_id, '_elementor_data', wp_slash( $value ) );
	}
}
$expected[31]['_elementor_data'] = $norwegian_two;

// An explicit sibling-target edit is authoritative within the same request.
mcp_abilities_elementor_update_guarded_elementor_data( 41, wp_slash( $norwegian_one ) );
update_post_meta( 42, '_elementor_data', wp_slash( $norwegian_one ) );
wp_update_post( array( 'ID' => 42, 'post_content' => wp_slash( '<h1>FIND YOUR LOCAL DEALER</h1>' ) ) );
$expected_content[42] = '<h1>FIND YOUR LOCAL DEALER</h1>';
mcp_abilities_elementor_update_guarded_elementor_data( 42, wp_slash( $english_edit ) );
mcp_abilities_elementor_update_guarded_elementor_data( 41, wp_slash( $norwegian_two ) );
update_post_meta( 42, '_elementor_data', wp_slash( $norwegian_two ) );
$expected[41]['_elementor_data'] = $norwegian_two;
$expected[42]['_elementor_data'] = $english_edit;

// Empty captured meta is a valid baseline, not an uncaptured value.
$GLOBALS['translation_meta'][52]['_elementor_data'] = '';
$GLOBALS['translation_content'][52] = '';
$expected_content[52] = '';
foreach ( array( $norwegian_one, $norwegian_two ) as $value ) {
	mcp_abilities_elementor_update_guarded_elementor_data( 51, wp_slash( $value ) );
	update_post_meta( 52, '_elementor_data', wp_slash( $value ) );
}
$expected[51]['_elementor_data'] = $norwegian_two;
$expected[52]['_elementor_data'] = '';

// JSON quotes and backslashes survive repeated restoration byte-for-byte.
$quoted = wp_json_encode( array( array( 'editor' => '<a href="/en/">"English"</a> C:\\files' ) ) );
$GLOBALS['translation_meta'][62]['_elementor_data'] = $quoted;
$GLOBALS['translation_content'][62] = '<p>\"English\" C:\\files</p>';
$expected_content[62] = $GLOBALS['translation_content'][62];
foreach ( array( $norwegian_one, $norwegian_two ) as $value ) {
	mcp_abilities_elementor_update_guarded_elementor_data( 61, wp_slash( $value ) );
	update_post_meta( 62, '_elementor_data', wp_slash( $value ) );
}
$expected[61]['_elementor_data'] = $norwegian_two;
$expected[62]['_elementor_data'] = $quoted;

register_shutdown_function(
	static function () use ( $expected, $expected_content ): void {
		foreach ( $expected as $post_id => $values ) {
			foreach ( $values as $meta_key => $value ) {
				assert_same( $value, get_post_meta( $post_id, $meta_key, true ), "Request preserves {$post_id} {$meta_key}" );
			}
		}
		echo "Request sibling restore tests passed (7 scenarios).\n";
		foreach ( $expected_content as $post_id => $content ) {
			assert_same( $content, get_post( $post_id )->post_content, "Request preserves {$post_id} post_content after translated plain-text save" );
		}
		echo "Translated post_content restore tests passed (7 scenarios).\n";
	}
);
