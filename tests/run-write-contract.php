<?php
/**
 * Exercise registered write abilities against an in-memory WordPress metadata seam.
 *
 * @package MCP_Abilities_Elementor
 */
declare( strict_types=1 );
namespace Elementor {
	class Plugin {
		public static $instance;
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	function add_action( ...$args ): void {}
	function __( string $text, ...$args ): string { return $text; }
	function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_slash( $value ) { return addslashes( (string) $value ); }
	function get_option( string $key, $default = false ) { return 'elementor_active_kit' === $key ? 259 : $default; }
	function get_post_meta( int $id, string $key, bool $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
	function update_post_meta( int $id, string $key, $value ) {
		++$GLOBALS['writes'];
		if ( $GLOBALS['fail_write'] ) { return false; }
		$GLOBALS['meta'][ $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
		return true;
	}
	function get_post( int $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
	function current_user_can( ...$args ): bool { return true; }
	function get_permalink( int $id ): string { return 'https://example.com/?p=' . $id; }
	function apply_filters( string $name, $value, ...$args ) { return $value; }
	function is_wp_error( $value ): bool { return false; }
	function sanitize_key( string $key ): string { return strtolower( $key ); }
	function absint( $value ): int { return abs( (int) $value ); }
	function shortcode_exists( string $name ): bool { return false; }
	function wp_register_ability( string $name, array $args ): void { $GLOBALS['abilities'][ $name ] = $args; }
	function expect( bool $ok, string $message ): void {
		if ( ! $ok ) { throw new \RuntimeException( $message ); }
	}
	function call_write( string $name, array $input ): array {
		return $GLOBALS['abilities'][ 'elementor/' . $name ]['execute_callback']( $input );
	}
	function element( string $id, array $settings = array() ): array {
		return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => $settings, 'elements' => array() );
	}
	function seed( array $data ): void {
		$GLOBALS['meta'][1] = array( '_elementor_data' => wp_json_encode( $data ), '_elementor_edit_mode' => 'builder' );
		$GLOBALS['writes'] = 0;
	}
	$posts = array( 1 => (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'draft' ), 77 => (object) array( 'ID' => 77, 'post_type' => 'elementor_library', 'post_status' => 'publish' ) );
	$meta = array( 259 => array( '_elementor_page_settings' => array(
		'system_colors' => array( array( '_id' => 'primary', 'color' => '#112233' ) ),
		'custom_colors' => array( array( '_id' => 'accent', 'color' => '#445566' ) ),
		'system_typography' => array( array( '_id' => 'primary' ) ),
		'custom_typography' => array( array( '_id' => 'editorial' ) ),
	) ) );
	$fail_write = false;
	\Elementor\Plugin::$instance = (object) array( 'widgets_manager' => new class {
		public function get_widget_types( string $name ) { return 'template' === $name ? (object) array() : null; }
	} );
	\Elementor\Plugin::$instance->documents = new class {
		public function get( int $id ) {
			return new class( $id ) {
				private int $id;
				public function __construct( int $id ) { $this->id = $id; }
				public function get_name(): string { return 'kit'; }
				public function get_settings(): array { return (array) get_post_meta( $this->id, '_elementor_page_settings', true ); }
			};
		}
	};
	require_once __DIR__ . '/../mcp-abilities-elementor.php';
	mcp_abilities_elementor_register_document_abilities();
	mcp_abilities_elementor_register_element_lookup_abilities();
	mcp_abilities_elementor_register_template_abilities();

	seed( array( element( 'a' ) ) );
	$input = array( 'id' => 1, 'element_id' => 'a', 'cache_scope' => 'none', 'settings' => array( 'title_color' => '#112233' ) );
	$dry = call_write( 'merge-element-settings', $input + array( 'dry_run' => true ) );
	expect( $dry['success'] && 0 === $writes, 'valid dry run must not write' );
	expect( 'globals/colors?id=primary' === $dry['settings']['__globals__']['title_color'], 'dry run returns normalized global binding' );
	$real = call_write( 'merge-element-settings', $input );
	expect( $real['success'] && $writes > 0 && $real['settings'] === $dry['settings'], 'save must match dry-run normalization' );
	expect( json_decode( $meta[1]['_elementor_data'], true )[0]['settings'] === $dry['settings'], 'normalized settings actually persisted' );

	foreach ( array( 'globals/colors?id=missing', 'globals/typography?id=editorial', 'globals/colors?id=primary&extra=1' ) as $bad ) {
		foreach ( array( true, false ) as $dry_run ) {
			seed( array( element( 'a' ) ) );
			$result = call_write( 'merge-element-settings', array( 'id' => 1, 'element_id' => 'a', 'settings' => array( '__globals__' => array( 'title_color' => $bad ) ), 'dry_run' => $dry_run, 'cache_scope' => 'none' ) );
			expect( ! $result['success'] && 0 === $writes, 'invalid or wrong-type reference-only binding must reject without writing' );
		}
	}
	foreach ( array( array( 'title_color' => 'globals/colors?id=accent' ), array( 'typography_typography' => 'globals/typography?id=editorial' ) ) as $binding ) {
		$result = mcp_abilities_elementor_prepare_document_data( 1, array( element( 'a', array( '__globals__' => $binding ) ) ) );
		expect( $result['success'], 'system and custom references must be accepted by type' );
	}
	$old_kit = $meta[259];
	$meta[259] = array();
	expect( ! mcp_abilities_elementor_prepare_document_data( 1, array( element( 'a', array( '__globals__' => array( 'title_color' => 'globals/colors?id=primary' ) ) ) ) )['success'], 'absent Kit must reject missing tokens' );
	$meta[259] = $old_kit;

	$legacy = array();
	for ( $i = 0; $i < 26; ++$i ) { $legacy[] = element( 'e' . $i, array( 'title_color' => '#abcdef' ) ); }
	seed( $legacy );
	$result = call_write( 'merge-element-settings', array( 'id' => 1, 'element_id' => 'e25', 'settings' => array( 'title_color' => '#fedcba' ), 'dry_run' => true, 'allow_legacy_style_preservation' => true ) );
	expect( ! $result['success'] && 0 === $writes, 'changed violation 26 must not pass legacy preservation' );
	$result = call_write( 'merge-element-settings', array( 'id' => 1, 'element_id' => 'e25', 'settings' => array( 'title' => 'Changed text' ), 'dry_run' => true, 'allow_legacy_style_preservation' => true ) );
	expect( $result['success'] && 0 === $writes, 'unchanged legacy styles remain allowed during text edits' );
	$result = call_write( 'delete-element', array( 'id' => 1, 'element_id' => 'e0', 'force_delete' => true, 'allow_legacy_style_preservation' => true, 'cache_scope' => 'none' ) );
	expect( $result['success'] && 25 === count( json_decode( $meta[1]['_elementor_data'], true ) ), 'deletion preserves unrelated legacy styles through shared save' );

	seed( array( element( 'a' ) ) );
	$bad_data = array( element( 'a', array( 'width' => 'calc(100% - 2px)' ) ) );
	$result = call_write( 'update-data', array( 'id' => 1, 'data' => $bad_data, 'confirm_dangerous_action' => 'elementor/update-data' ) );
	expect( ! $result['success'] && 0 === $writes, 'raw write enforces common write guard' );
	$result = mcp_abilities_elementor_save_document_data( 1, $bad_data, 'none', false, true, true );
	expect( ! $result['success'] && 0 === $writes, 'shared dry run enforces same write guard' );
	$fail_write = true;
	$result = mcp_abilities_elementor_save_document_data( 1, array( element( 'b' ) ), 'none' );
	expect( ! $result['success'], 'failed metadata persistence must not report success' );
	$fail_write = false;

	$container = array( 'id' => 'c', 'elType' => 'container', 'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => '10', 'right' => '10', 'bottom' => '10', 'left' => '10', 'isLinked' => true ) ), 'elements' => array( element( 'bad', array( 'title_color' => '#abcdef' ) ) ) );
	foreach ( array( true, false ) as $dry_run ) {
		seed( array( $container ) );
		$result = call_write( 'zero-container-padding-subtree', array( 'id' => 1, 'element_id' => 'c', 'dry_run' => $dry_run, 'cache_scope' => 'none' ) );
		expect( ! $result['success'] && 0 === $writes, 'specialized subtree dry run and save both enforce style policy' );
	}
	$container['elements'] = array();
	seed( array( $container ) );
	$result = call_write( 'zero-container-padding-subtree', array( 'id' => 1, 'element_id' => 'c', 'dry_run' => true, 'cache_scope' => 'none' ) );
	expect( $result['success'] && 0 === $writes, 'specialized dry run leaves metadata untouched' );
	$result = call_write( 'zero-container-padding-subtree', array( 'id' => 1, 'element_id' => 'c', 'cache_scope' => 'none' ) );
	expect( $result['success'] && '0' === json_decode( $meta[1]['_elementor_data'], true )[0]['settings']['padding']['top'], 'specialized save persists prepared subtree' );

	$meta[77]['_elementor_data'] = wp_json_encode( $bad_data );
	$writes = 0;
	foreach ( array( 'duplicate-template', 'update-template' ) as $ability ) {
		$result = call_write( $ability, array( 'id' => 77, 'data' => $bad_data, 'title' => 'Must not be written' ) );
		expect( ! $result['success'] && 0 === $writes, 'template copy and update reject invalid data before other mutations' );
	}
	$result = call_write( 'import-template', array( 'data' => array( 'content' => $bad_data ) ) );
	expect( ! $result['success'] && 0 === $writes, 'import validates before creating a template' );

	$claim = array( 'template_lookup_status' => 'matched_template_used', 'template_id' => 77 );
	$link = array( 'id' => 'linked', 'elType' => 'widget', 'widgetType' => 'template', 'settings' => array( 'template_id' => 77 ), 'elements' => array() );
	expect( null === mcp_abilities_elementor_template_lookup_guard_response( $claim, 'test', array( $link ) ), 'existing native linked template accepted' );
	foreach ( array( array(), array( element( 'copy' ) ), array( element( 'arbitrary', array( 'template_id' => 77 ) ) ) ) as $data ) {
		expect( null !== mcp_abilities_elementor_template_lookup_guard_response( $claim, 'test', $data ), 'claim without actual linked template rejected' );
	}
	expect( null !== mcp_abilities_elementor_template_lookup_guard_response( array_replace( $claim, array( 'template_id' => 999999 ) ), 'test', array( $link ) ), 'nonexistent library ID rejected' );
	expect( null !== mcp_abilities_elementor_template_lookup_guard_response( array_replace( $claim, array( 'template_id' => 1 ) ), 'test', array( $link ) ), 'ordinary page ID rejected' );
	seed( array() );
	$result = call_write( 'add-container', $claim + array( 'id' => 1, 'element_id' => 'container', 'elements' => array( $link ), 'cache_scope' => 'none' ) );
	expect( $result['success'] && 77 === json_decode( $meta[1]['_elementor_data'], true )[0]['elements'][0]['settings']['template_id'], 'add-container persists actual template child' );
	echo "Write contract tests passed.\n";
}
