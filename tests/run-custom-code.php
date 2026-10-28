<?php
/** Exercise the registered Custom Code Interface. Optional installed Pro fixtures verify native rendering/cache. */
declare( strict_types=1 );

namespace ElementorPro\Modules\ThemeBuilder\Documents {
	class Theme_Document {
		protected $post;
		public function __construct( $post ) { $this->post = $post; }
		public function get_id() { return $this->post->ID; }
		public function get_main_id() { return $this->post->ID; }
		public function get_location() { return \get_post_meta( $this->post->ID, '_elementor_location', true ); }
	}
}
namespace ElementorPro\Modules\AssetsManager\Classes {
	class Assets_Base {}
}
namespace ElementorPro\Modules\CustomCode {
	class Module { const CPT = 'elementor_snippet'; const DOCUMENT_TYPE = 'code_snippet'; const CAPABILITY = 'manage_options'; }
}
namespace ElementorPro\Modules\ThemeBuilder {
	class Module {
		public static $manager;
		public static function instance() { return new self(); }
		public function get_conditions_manager() { return self::$manager; }
		public function get_document( $id ) {
			return class_exists( '\ElementorPro\Modules\CustomCode\Document' ) && \get_post( $id )
				? new \ElementorPro\Modules\CustomCode\Document( \get_post( $id ) ) : false;
		}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	$abilities = $posts = $meta = $options = array();
	$capabilities = array( 'manage_options' => true, 'unfiltered_html' => true, 'edit_post' => true, 'delete_post' => true );
	$denied_ids = array();
	$available = true;
	$fail_meta_key = '';
	$checks = 0;
	function add_action( ...$args ): void {}
	function wp_register_ability( $name, $args ): void { $GLOBALS['abilities'][ $name ] = $args; }
	function post_type_exists( $type ): bool { return $GLOBALS['available']; }
	function current_user_can( $cap, $id = 0 ): bool { return ( $GLOBALS['capabilities'][ $cap ] ?? false ) && ! in_array( $id, $GLOBALS['denied_ids'], true ); }
	function absint( $v ): int { return abs( (int) $v ); }
	function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
	function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
	function sanitize_text_field( $v ): string { return strip_tags( $v ); }
	function wp_kses_post( $v ): string { return strip_tags( $v, '<b>' ); }
	function is_wp_error( $v ): bool { return false; }
	function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
	function get_post_meta( $id, $key = '', $single = false ) {
		$values = $GLOBALS['meta'][ $id ] ?? array();
		return '' === $key ? array_map( static fn( $v ) => array( $v ), $values ) : ( $values[ $key ] ?? '' );
	}
	function update_post_meta( $id, $key, $v ): bool {
		if ( $key === $GLOBALS['fail_meta_key'] ) { return false; }
		$v = wp_unslash( $v );
		$GLOBALS['meta'][ $id ][ $key ] = is_scalar( $v ) ? (string) $v : $v;
		return true;
	}
	function delete_post_meta( $id, $key ): bool { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( $key, $v ): bool { $GLOBALS['options'][ $key ] = $v; return true; }
	function wp_insert_post( $data, $error ) {
		$id = count( $GLOBALS['posts'] ) + 1;
		$data = wp_unslash( $data ) + array( 'ID' => $id, 'post_content' => '', 'post_date' => '2026-10-28', 'post_modified' => '2026-10-28' );
		$GLOBALS['posts'][ $id ] = (object) $data;
		return $id;
	}
	function wp_update_post( $data, $error ) {
		foreach ( wp_unslash( $data ) as $key => $v ) { $GLOBALS['posts'][ $data['ID'] ]->$key = $v; }
		return $data['ID'];
	}
	function wp_trash_post( $id ) { $GLOBALS['posts'][ $id ]->post_status = 'trash'; return $GLOBALS['posts'][ $id ]; }
	function wp_delete_post( $id, $force ) { $post = $GLOBALS['posts'][ $id ]; unset( $GLOBALS['posts'][ $id ], $GLOBALS['meta'][ $id ] ); return $post; }
	class WP_Query {
		public array $posts;
		public function __construct( $args ) {
			$this->posts = array_values( array_filter( $GLOBALS['posts'], static fn( $p ) =>
				$p->post_type === $args['post_type'] && in_array( $p->post_status, (array) $args['post_status'], true ) ) );
			$this->posts = array_slice( $this->posts, $args['offset'], $args['posts_per_page'] );
		}
	}
	function check( bool $ok, string $message ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( $message ); } }
	function call_snippet( string $name, array $input = array() ): array {
		$a = $GLOBALS['abilities'][ 'elementor/' . $name . '-custom-code' ];
		return $a['permission_callback']() ? $a['execute_callback']( $input ) : array( 'success' => false, 'message' => 'Permission denied' );
	}
	$native = in_array( '--native', $argv, true );
	if ( $native ) {
		foreach ( array( 'CACHE', 'METABOX', 'DOCUMENT' ) as $fixture ) {
			$path = getenv( 'MCP_ELEMENTOR_NATIVE_' . $fixture . '_FIXTURE' );
			if ( ! $path || ! is_file( $path ) ) { fwrite( STDERR, 'Missing installed Pro fixture: ' . $fixture . "\n" ); exit( 2 ); }
			require $path;
		}
		\ElementorPro\Modules\ThemeBuilder\Module::$manager = new class() {
			public $cache;
			public int $clears = 0;
			public function __construct() { $this->cache = new \ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache(); }
			public function get_cache() { return $this->cache; }
			public function clear_location_cache(): void { ++$this->clears; }
		};
	}
	$root = getenv( 'MCP_ELEMENTOR_TEST_SOURCE' ) ?: dirname( __DIR__ );
	require $root . '/mcp-abilities-elementor.php';
	mcp_abilities_elementor_register_pro_abilities();

	$posts[501] = (object) array( 'ID' => 501, 'post_type' => 'elementor_snippet', 'post_status' => 'draft', 'post_content' => '', 'post_title' => 'Native editor snippet', 'post_date' => '2026-10-28', 'post_modified' => '2026-10-28' );
	$meta[501] = array( '_elementor_code' => '<script>const nativeOnly = true;</script>', '_elementor_location' => 'elementor_head', '_elementor_priority' => '1', '_elementor_extra_options' => array( 'ensure_jquery' ) );
	$native_before = serialize( array( $posts[501], $meta[501] ) );
	check( $meta[501]['_elementor_code'] === call_snippet( 'get', array( 'id' => 501 ) )['code'], 'Existing meta-only native snippet reads correctly without a migration' );
	$code = <<<'CODE'
<script>const path = "C:\\files"; const pattern = /\d+/; const text = "a\"b";</script>
CODE;
	$r = call_snippet( 'create', array( 'title' => 'Example', 'code' => $code, 'status' => 'draft' ) );
	check( $r['success'], 'Create draft succeeds' );
	$id = $r['id'];
	check( get_post_meta( $id, '_elementor_code', true ) === $code, 'Create preserves exact code in native rendered metadata' );
	check( '' === $posts[ $id ]->post_content, 'Create does not store a second code source in post_content' );
	check( 'elementor_head' === $r['location'] && 1 === $r['priority'] && array() === $r['conditions'], 'Create supplies native placement/priority defaults' );
	check( $code === call_snippet( 'get', array( 'id' => $id ) )['code'], 'Get reads native code without include_meta' );
	$conditions = array( 'include/singular/page/123', 'exclude/singular/page/456' );
	$updated = $code . "\n// changed";
	$r = call_snippet( 'update', array( 'id' => $id, 'code' => $updated, 'location' => 'elementor_body_end', 'priority' => 7, 'conditions' => $conditions, 'status' => 'publish' ) );
	check( $r['success'] && 'publish' === $r['status'], 'Update publishes the snippet' );
	check( $updated === call_snippet( 'get', array( 'id' => $id ) )['code'], 'Update preserves code slashes and quotes' );
	$json_code = '<script type="application/json">' . json_encode( array( 'path' => 'C:\\files', 'quote' => 'a"b', 'pattern' => '\\d+' ) ) . '</script>';
	call_snippet( 'update', array( 'id' => $id, 'code' => $json_code ) );
	check( $json_code === call_snippet( 'get', array( 'id' => $id ) )['code'], 'JSON, quotes and backslashes round-trip byte-for-byte' );
	call_snippet( 'update', array( 'id' => $id, 'code' => $updated ) );

	check( 'elementor_body_end' === $r['location'] && 7 === $r['priority'] && $conditions === $r['conditions'], 'Update returns stored placement, priority and conditions' );
	$r = call_snippet( 'list', array( 'include_code' => true ) );
	check( 1 === $r['total'] && $updated === $r['snippets'][0]['code'] && $conditions === $r['snippets'][0]['conditions'], 'List reads native code and assignment' );
	check( ! isset( call_snippet( 'list' )['snippets'][0]['code'] ), 'List omits code by default' );
	check( isset( call_snippet( 'get', array( 'id' => $id, 'include_meta' => true ) )['meta']['_elementor_code'] ), 'Raw meta inspection remains available' );
	$posts[ $id ]->post_content = 'Stale legacy body';
	call_snippet( 'update', array( 'id' => $id, 'code' => '' ) );
	check( '' === call_snippet( 'get', array( 'id' => $id ) )['code'], 'Explicit empty code does not revive legacy post_content' );
	call_snippet( 'update', array( 'id' => $id, 'code' => $updated ) );

	foreach ( array( array( 'location' => 'footer' ), array( 'priority' => 0 ), array( 'priority' => -9997 ), array( 'priority' => 11 ), array( 'priority' => '1' ), array( 'conditions' => array( 'general' ) ), array( 'conditions' => array( 5 ) ), array( 'meta' => array( '_elementor_location' => 'bad' ) ), array( 'status' => 'future' ) ) as $invalid ) {
		$before = serialize( array( $posts, $meta, $options ) );
		check( ! call_snippet( 'update', array( 'id' => $id, 'title' => 'Must not save' ) + $invalid )['success'], 'Reject invalid update ' . json_encode( $invalid ) );
		check( $before === serialize( array( $posts, $meta, $options ) ), 'Invalid request does not partially mutate data' );
	}
	foreach ( array( '_elementor_code', '_elementor_conditions' ) as $key ) {
		$before_options = $options;
		$fail_meta_key = $key;
		$change = '_elementor_code' === $key ? array( 'code' => 'Should fail' ) : array( 'conditions' => array( 'include/general' ) );
		$r = call_snippet( 'update', array( 'id' => $id ) + $change );
		check( ! $r['success'] && $id === $r['id'], 'Storage failure is reported with the affected snippet ID: ' . $key );
		check( $before_options === $options, 'Storage failure does not activate an unsaved condition assignment' );
	}
	$fail_meta_key = '';
	$before = count( $posts );
	check( ! call_snippet( 'create', array( 'title' => 'Invalid', 'code' => $code, 'location' => 'bad' ) )['success'] && $before === count( $posts ), 'Invalid create does not leave a new post' );
	foreach ( array( 'create', 'update' ) as $name ) {
		$schema = $abilities[ 'elementor/' . $name . '-custom-code' ]['input_schema']['properties'];
		check( isset( $schema['location'], $schema['priority'], $schema['conditions'] ), 'Published schema exposes native settings for ' . $name );
	}
	$unrelated = array( 'include/singular/post/999' );
	$options['elementor_pro_theme_builder_conditions']['header'][99] = $unrelated;
	call_snippet( 'update', array( 'id' => $id, 'location' => 'elementor_body_start' ) );
	if ( $native ) {
		$cache = $options['elementor_pro_theme_builder_conditions'];
		check( ! isset( $cache['elementor_body_end'][ $id ] ) && $conditions === $cache['elementor_body_start'][ $id ], 'Native cache moves assignment from old location to new location' );
		check( $unrelated === $cache['header'][99], 'Native cache preserves unrelated assignments' );
		$doc = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_document( $id );
		$capabilities['manage_options'] = false;
		ob_start(); $doc->print_content(); $html = ob_get_clean();
		$capabilities['manage_options'] = true;
		check( $updated . PHP_EOL === $html, 'Unmodified native Pro Document renders exact code written through the ability' );
		check( \ElementorPro\Modules\CustomCode\Custom_Code_Metabox::DEFAULT_LOCATION === 'elementor_head', 'Default matches installed native metabox' );
	}
	call_snippet( 'update', array( 'id' => $id, 'conditions' => array() ) );
	check( array() === call_snippet( 'get', array( 'id' => $id ) )['conditions'] && '' === get_post_meta( $id, '_elementor_conditions', true ), 'Empty conditions remove restrictions, preserving native site-wide behavior' );
	if ( $native ) { check( ! isset( $options['elementor_pro_theme_builder_conditions']['elementor_body_start'][ $id ] ), 'Empty conditions remove stale native cached restrictions' ); }
	$legacy = call_snippet( 'create', array( 'title' => 'Legacy client', 'code' => '', 'status' => 'draft', 'meta' => array( '_elementor_code' => $code, '_elementor_location' => 'elementor_body_end', '_elementor_priority' => '3', '_elementor_conditions' => $conditions ) ) );
	check( $legacy['success'] && 3 === $legacy['priority'] && $code === call_snippet( 'get', array( 'id' => $legacy['id'] ) )['code'] && $conditions === $legacy['conditions'], 'Legacy native meta caller uses the same validated storage/cache Interface' );
	$r = call_snippet( 'create', array( 'title' => 'Second caller', 'code' => '<style>body{color:red}</style>', 'location' => 'elementor_body_start', 'priority' => 10, 'conditions' => array( 'include/general' ) ) );
	check( $r['success'] && 'elementor_body_start' === $r['location'], 'Independent CSS caller uses the same native fields' );
	$capabilities['unfiltered_html'] = false;
	$r = call_snippet( 'update', array( 'id' => $id, 'code' => '<b>Allowed</b><script>alert(1)</script>' ) );
	check( $r['success'] && '<b>Allowed</b>alert(1)' === get_post_meta( $id, '_elementor_code', true ), 'Native unfiltered_html restriction is preserved' );
	$capabilities['unfiltered_html'] = true;
	$before = serialize( array( $posts, $meta, $options ) );
	$capabilities['manage_options'] = false;
	foreach ( array( 'list', 'get', 'create', 'update', 'delete' ) as $name ) {
		check( ! call_snippet( $name, array( 'id' => $id, 'title' => 'Denied', 'code' => 'Denied' ) )['success'], 'manage_options remains required for ' . $name );
	}
	check( $before === serialize( array( $posts, $meta, $options ) ), 'Denied abilities do not mutate data' );
	$capabilities['manage_options'] = true;
	$denied_ids = array( $id );
	foreach ( array( 'get', 'update', 'delete' ) as $name ) { check( ! call_snippet( $name, array( 'id' => $id, 'code' => 'Denied' ) )['success'], 'Document permission remains required for ' . $name ); }
	check( ! in_array( $id, array_column( call_snippet( 'list' )['snippets'], 'id' ), true ), 'List does not expose a denied document' );
	$denied_ids = array();
	$available = false;
	check( ! call_snippet( 'create', array( 'title' => 'Unavailable', 'code' => $code ) )['success'] && ! call_snippet( 'list' )['success'], 'Unavailable Pro Custom Code reports failure' );
	$available = true;
	call_snippet( 'update', array( 'id' => $id, 'status' => 'draft' ) );
	check( ! in_array( $id, array_column( call_snippet( 'list' )['snippets'], 'id' ), true ), 'Disabling a snippet removes it from published results' );
	check( call_snippet( 'delete', array( 'id' => $id ) )['success'] && 'trash' === $posts[ $id ]->post_status, 'Delete moves snippet to trash by default' );
	check( call_snippet( 'delete', array( 'id' => $id, 'force' => true ) )['success'] && ! get_post( $id ), 'Explicit force permanently deletes snippet' );
	check( $native_before === serialize( array( $posts[501], $meta[501] ) ), 'All writes preserve the independent existing native snippet and its options' );
	echo json_encode( array( 'status' => 'PASS', 'assertions' => $checks, 'native_pro_fixtures' => $native, 'scope' => 'Registered abilities with in-memory WordPress storage; optional unmodified installed Pro Document and Conditions_Cache' ), JSON_PRETTY_PRINT ) . "\n";
}
