<?php
/** Exercise registered condition abilities against the native Pro cache contract. */
declare( strict_types=1 );

namespace ElementorPro\Modules\ThemeBuilder\Documents {

	class Theme_Document {
		public function __construct( public int $id, public string $location ) {}
		public function get_main_id(): int { return $this->id; }
		public function get_location(): string { return $this->location; }
	}
}

namespace ElementorPro\Modules\ThemeBuilder {

	class Module {
		public static $manager;
		public static function instance(): self { return new self(); }
		public function get_document( int $id ) { return $GLOBALS['documents'][ $id ] ?? false; }
		public function get_conditions_manager() { return self::$manager; }
	}
}

namespace Elementor {
	class Plugin {
		public static $instance;
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	$abilities = array();
	$posts = array();
	$meta = array();
	$options = array();
	$documents = array();
	$can_edit = true;
	$errors = array();
	function absint( $value ): int { return abs( (int) $value ); }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( $key, $value ): bool { $GLOBALS['options'][ $key ] = $value; return true; }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value ): bool { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
	function delete_post_meta( $id, $key ): bool { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
	function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
	function current_user_can( ...$args ): bool { return $GLOBALS['can_edit']; }
	function admin_url( $path ): string { return 'https://example.com/wp-admin/' . $path; }
	function get_permalink( $id ): string { return 'https://example.com/template/' . $id; }
	function did_action( $name ): int { return 1; }
	function is_wp_error( $value ): bool { return false; }
	function sanitize_text_field( $value ): string { return $value; }
	function wp_set_object_terms( ...$args ): void {}
	function mcp_abilities_elementor_prepare_document_data( $id, $data ): array { return array( 'success' => true, 'data' => $data ); }
	function mcp_abilities_elementor_save_document_data( ...$args ): array { return array( 'success' => true ); }
	function mcp_abilities_elementor_register_ability( $name, $definition ): void { $GLOBALS['abilities'][ $name ] = $definition; }
	function mcp_abilities_elementor_ability_meta( ...$args ): array { return array(); }
	function mcp_abilities_elementor_authoring_guidance(): string { return ''; }
	function check( bool $ok, string $label ): void { if ( ! $ok ) { $GLOBALS['errors'][] = $label; } }

	// Use the actual installed Pro Conditions_Cache supplied outside the plugin package.
	$native_cache = getenv( 'MCP_ELEMENTOR_NATIVE_CACHE_FIXTURE' );
	if ( ! $native_cache || ! is_file( $native_cache ) ) {
		fwrite( STDERR, "Set MCP_ELEMENTOR_NATIVE_CACHE_FIXTURE to the installed Pro conditions-cache.php.\n" );
		exit( 2 );
	}
	require $native_cache;
	$manager = new class() {
		public $cache;
		public int $clears = 0;
		public function __construct() { $this->cache = new \ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache(); }
		public function get_cache() { return $this->cache; }
		public function clear_location_cache(): void { ++$this->clears; }
	};
	\ElementorPro\Modules\ThemeBuilder\Module::$manager = $manager;
	\Elementor\Plugin::$instance = (object) array( 'documents' => new class() {
		public function get_document_types(): array { return array( 'header' => true ); }
		public function create( $type, $data ) {
			$id = 14;
			$GLOBALS['posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'elementor_library', 'post_title' => $data['post_title'], 'post_status' => $data['post_status'] );
			$GLOBALS['meta'][ $id ]['_elementor_template_type'] = $type;
			$document = new \ElementorPro\Modules\ThemeBuilder\Documents\Theme_Document( $id, $type );
			$GLOBALS['documents'][ $id ] = $document;
			return $document;
		}
	} );

	// Load only the owning pure helpers, leaving the WordPress boundary observable above.
	$root = getenv( 'MCP_ELEMENTOR_TEST_SOURCE' ) ?: dirname( __DIR__ );
	$source = file_get_contents( $root . '/mcp-abilities-elementor.php' );
	$tokens = token_get_all( $source );
	for ( $i = 0, $count = count( $tokens ); $i < $count; ++$i ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) { continue; }
		$j = $i + 1;
		while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) { ++$j; }
		$name = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : '';
		if ( ! in_array( $name, array( 'mcp_abilities_elementor_normalize_conditions', 'mcp_abilities_elementor_condition_document', 'mcp_abilities_elementor_condition_location', 'mcp_abilities_elementor_save_conditions' ), true ) ) { continue; }
		$code = '';
		$depth = 0;
		$started = false;
		for ( $j = $i; $j < $count; ++$j ) {
			$piece = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			$code .= $piece;
			if ( '{' === $tokens[ $j ] ) { ++$depth; $started = true; }
			if ( '}' === $tokens[ $j ] && 0 === --$depth && $started ) { break; }
		}
		eval( $code );
	}
	require $root . '/includes/register-templates.php';
	mcp_abilities_elementor_register_template_abilities();
	foreach ( array( 11 => 'single-post', 12 => 'single-post', 13 => 'header' ) as $id => $type ) {
		$posts[ $id ] = (object) array( 'ID' => $id, 'post_type' => 'elementor_library', 'post_title' => 'Example', 'post_status' => 'draft' );
		$meta[ $id ]['_elementor_template_type'] = $type;
		$documents[ $id ] = new \ElementorPro\Modules\ThemeBuilder\Documents\Theme_Document( $id, 'single-post' === $type ? 'single' : $type );
	}
	$unrelated = array( 'include/general', 'exclude/singular/page/999' );
	$options['elementor_pro_theme_builder_conditions'] = array(
		'single' => array( 11 => array( 'include/general' ), 99 => $unrelated ),
		'single-post' => array( 11 => array( 'include/general' ) ),
		'header' => array( 98 => $unrelated ),
		'popup' => array( 97 => $unrelated ),
	);
	$write = $abilities['elementor/update-template']['execute_callback'];
	$read = $abilities['elementor/get-theme-builder-conditions']['execute_callback'];
	$conditions = array( 'include/singular/post/101', 'exclude/singular/post/102' );
	$r = $write( array( 'id' => 11, 'conditions' => $conditions ) );
	check( $r['success'], 'ordinary update-template succeeds' );
	check( get_post_meta( 11, '_elementor_conditions', true ) === $conditions, 'ordinary write stores exact finite/exclude metadata' );
	check( ( get_option( 'elementor_pro_theme_builder_conditions' )['single'][11] ?? null ) === $conditions, 'ordinary single-post write replaces stale broad runtime at native single location' );
	check( ! isset( get_option( 'elementor_pro_theme_builder_conditions' )['single-post'][11] ), 'target stale document-type cache entry removed' );
	$r = $read( array( 'id' => 11 ) );
	check( $r['success'] && 'single' === $r['type'] && $conditions === $r['conditions'], 'default reader returns actual runtime location and same conditions' );
	$write( array( 'id' => 12, 'conditions' => array( 'include/singular/post/103' ) ) );
	check( array( 'include/singular/post/103' ) === ( get_option( 'elementor_pro_theme_builder_conditions' )['single'][12] ?? null ), 'second template gets its own runtime entry' );
	$write( array( 'id' => 11, 'conditions' => array( 'include/singular/post/104' ) ) );
	check( array( 'include/singular/post/104' ) === $read( array( 'id' => 11 ) )['conditions'], 'later ordinary write cannot diverge again' );
	$write( array( 'id' => 13, 'conditions' => array( 'exclude/singular/page/105' ) ) );
	check( array( 'exclude/singular/page/105' ) === $read( array( 'id' => 13 ) )['conditions'], 'same-type/location header control works' );
	$write( array( 'id' => 11, 'conditions' => array() ) );
	check( array() === $read( array( 'id' => 11 ) )['conditions'] && ! isset( get_option( 'elementor_pro_theme_builder_conditions' )['single'][11] ), 'empty means no assignment, never general' );
	$default_write = $abilities['elementor/update-theme-builder-conditions']['execute_callback'];
	$default_write( array( 'id' => 11, 'conditions' => $conditions ) );
	check( $conditions === $read( array( 'id' => 11 ) )['conditions'], 'dedicated writer defaults to native location' );
	$default_write( array( 'id' => 11, 'type' => 'single', 'conditions' => $conditions ) );
	check( $conditions === $read( array( 'id' => 11 ) )['conditions'], 'existing explicit runtime-location caller remains compatible' );
	$r = $abilities['elementor/create-template']['execute_callback']( array( 'title' => 'Example header', 'type' => 'header', 'status' => 'draft', 'conditions' => array( 'exclude/singular/page/106' ) ) );
	check( $r['success'] && array( 'exclude/singular/page/106' ) === $read( array( 'id' => 14 ) )['conditions'], 'registered create-template also persists through the same native cache Interface' );
	check( $manager->clears > 0, 'same-request location selections are invalidated after assignment changes' );
	$all = get_option( 'elementor_pro_theme_builder_conditions' );
	check( $all['single'][99] === $unrelated && $all['header'][98] === $unrelated && $all['popup'][97] === $unrelated, 'unrelated same/other-location entries unchanged byte-for-byte' );
	check( 'single-post' === get_post_meta( 11, '_elementor_template_type', true ), 'document identity unchanged' );
	$can_edit = false;
	$before = array( $meta, $options );
	check( ! $default_write( array( 'id' => 11, 'conditions' => array( 'include/general' ) ) )['success'] && array( $meta, $options ) === $before, 'denied edit cannot mutate either store' );
	check( ! $read( array( 'id' => 11 ) )['success'], 'default reader preserves document permission boundary' );
	$schema = $abilities['elementor/get-theme-builder-conditions']['output_schema']['properties']['conditions']['type'];
	check( is_array( $schema ) && in_array( 'array', $schema, true ) && in_array( 'object', $schema, true ), 'reader schema accepts exact-ID lists and location maps' );
	if ( $errors ) { fwrite( STDERR, implode( "\n", $errors ) . "\n" ); exit( 1 ); }
	echo "Registered Theme Builder condition tests passed.\n";
}
