<?php
/**
 * Verify that the compact style guide retains custom Kit tokens.
 *
 * @package MCP_Abilities_Elementor
 */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ): void {}
function get_option( string $name, $default = false ) {
	return 'elementor_active_kit' === $name ? 259 : $default;
}
function get_post_meta( int $id, string $key, bool $single ) {
	return $GLOBALS['kit_settings'];
}
require_once __DIR__ . '/../mcp-abilities-elementor.php';
$kit_settings = array(
	'system_colors' => array( array( '_id' => 'primary', 'color' => '#112233' ) ),
	'custom_colors' => array( array( '_id' => 'brand-accent', 'color' => '#445566' ) ),
	'system_typography' => array( array( '_id' => 'primary', 'typography_font_family' => 'Arial' ) ),
	'custom_typography' => array( array( '_id' => 'editorial', 'typography_font_family' => 'Georgia' ) ),
);
$guide = mcp_abilities_elementor_get_style_guide_summary();
unset( $guide['raw_settings'] );
if ( array( 'primary', 'brand-accent' ) !== array_column( $guide['global_colors'], '_id' ) ||
	array( 'primary', 'editorial' ) !== array_column( $guide['global_typography'], '_id' ) ) {
	fwrite( STDERR, "FAIL: compact style guide must expose system and custom Kit tokens.\n" );
	exit( 1 );
}
$kit_settings = array();
$guide = mcp_abilities_elementor_get_style_guide_summary();
if ( array() !== $guide['global_colors'] || array() !== $guide['global_typography'] ) {
	fwrite( STDERR, "FAIL: absent Kit must not invent design tokens.\n" );
	exit( 1 );
}
echo "Style guide tests passed.\n";
