<?php
/** Verify registered template writers against Elementor Pro's public runtime fields. */
declare( strict_types=1 );

namespace Elementor {
	class Plugin {
		public static $instance;
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	$abilities = array();
	$meta = array( 11 => array( '_elementor_template_type' => 'popup' ) );
	$posts = array( 11 => (object) array( 'ID' => 11, 'post_type' => 'elementor_library', 'post_title' => 'Existing popup' ) );
	$can_edit = true;
	$errors = array();
	$checks = 0;
	function current_user_can( ...$args ): bool { return $GLOBALS['can_edit']; }
	function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
	function get_posts( $args ): array { return array( 11 ); }
	function get_the_title( $id ): string { return $GLOBALS['posts'][ $id ]->post_title; }
	function update_post_meta( $id, $key, $value ): bool { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
	function admin_url( $path ): string { return 'https://example.com/wp-admin/' . $path; }
	function get_permalink( $id ): string { return 'https://example.com/template/' . $id; }
	function did_action( $name ): int { return 1; }
	function is_wp_error( $value ): bool { return false; }
	function sanitize_text_field( $value ): string { return $value; }
	function wp_set_object_terms( ...$args ): void {}
	function mcp_abilities_elementor_prepare_document_data( $id, $data ): array { return array( 'success' => true, 'data' => $data ); }
	function mcp_abilities_elementor_save_document_data( ...$args ): array { return array( 'success' => true ); }
	function mcp_abilities_elementor_register_ability( $name, $definition ): void { $GLOBALS['abilities'][ $name ] = $definition; }
	function mcp_abilities_elementor_authoring_guidance(): string { return ''; }
	function mcp_abilities_elementor_ability_meta( ...$args ): array { return array(); }
	function check( bool $ok, string $label ): void {
		++$GLOBALS['checks'];
		if ( ! $ok ) { $GLOBALS['errors'][] = $label; }
	}

	// Load the actual owning helpers and registered callbacks without booting WordPress.
	$root = getenv( 'MCP_ELEMENTOR_TEST_SOURCE' ) ?: dirname( __DIR__ );
	$source = file_get_contents( $argv[1] ?? $root . '/mcp-abilities-elementor.php' );
	$tokens = token_get_all( $source );
	for ( $i = 0, $count = count( $tokens ); $i < $count; ++$i ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) { continue; }
		$j = $i + 1;
		while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) { ++$j; }
		$name = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : '';
		if ( ! in_array( $name, array( 'mcp_abilities_elementor_normalize_popup_display_settings', 'mcp_abilities_elementor_build_popup_display_settings', 'mcp_abilities_elementor_audit_theme_builder_runtime_health' ), true ) ) { continue; }
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
	\Elementor\Plugin::$instance = (object) array( 'documents' => new class() {
		public function get_document_types(): array { return array( 'popup' => true ); }
		public function create( $type, $data ) {
			$GLOBALS['posts'][12] = (object) array( 'ID' => 12, 'post_type' => 'elementor_library', 'post_title' => $data['post_title'] );
			$GLOBALS['meta'][12]['_elementor_template_type'] = $type;
			return new class() { public function get_main_id(): int { return 12; } };
		}
	} );
	$update = $abilities['elementor/update-template']['execute_callback'];
	$create = $abilities['elementor/create-template']['execute_callback'];
	$display_key = '_elementor_popup_display_settings';
	$input = array( 'triggers' => array( 'on_page_load' ), 'timing' => array( 'show_after' => 9, 'show_times' => 5 ) );
	$r = $create( array( 'title' => 'New campaign', 'type' => 'popup', 'status' => 'draft', 'popup_display' => $input ) );
	check( $r['success'], 'registered create-template succeeds' );
	$saved = $meta[12][$display_key];
	check( 9 === ( $saved['triggers']['page_load_delay'] ?? null ), 'create stores delay at the native trigger field' );
	check( 5 === ( $saved['timing']['times_times'] ?? null ), 'create stores the requested limit instead of native default 3' );
	check( 'close' === ( $saved['timing']['times_count'] ?? null ), 'create stores a valid native count mode' );
	$meta[11][$display_key] = array( 'triggers' => array( 'page_load' => 'yes' ), 'timing' => array( 'page_load_delay' => 0, 'times_count' => 2147483647, 'times' => 'yes', 'devices' => 'yes', 'devices_devices' => array( 'desktop', 'mobile' ) ) );
	$r = $update( array( 'id' => 11, 'popup_display' => array( 'triggers' => array( 'on_page_load' ), 'timing' => array( 'show_after' => 0, 'show_times' => 3 ) ) ) );
	check( $r['success'], 'registered update-template succeeds' );
	$saved = $meta[11][$display_key];
	check( 0 === ( $saved['triggers']['page_load_delay'] ?? null ) && ! isset( $saved['timing']['page_load_delay'] ), 'update replaces obsolete delay metadata at its owning native field' );
	check( 3 === ( $saved['timing']['times_times'] ?? null ) && 'close' === $saved['timing']['times_count'], 'update repairs a previously malformed numeric count mode' );
	check( array( 'desktop', 'mobile' ) === $saved['timing']['devices_devices'], 'unrelated existing visitor rules are preserved' );
	$meta[11][$display_key]['timing']['times_count'] = 'open';
	$meta[11][$display_key]['timing']['times_period'] = 'session';
	$update( array( 'id' => 11, 'popup_display' => array( 'timing' => array( 'show_times' => 7 ) ) ) );
	check( 'open' === $meta[11][$display_key]['timing']['times_count'] && 'session' === $meta[11][$display_key]['timing']['times_period'], 'valid native count mode and period survive a limit update' );
	$before = $meta;
	$can_edit = false;
	$r = $update( array( 'id' => 11, 'popup_display' => $input ) );
	check( ! $r['success'] && $before === $meta, 'denied update does not mutate settings' );
	$valid = $meta[11][$display_key];
	check( mcp_abilities_elementor_audit_theme_builder_runtime_health()['healthy'], 'runtime audit accepts the zero-delay native settings written by update-template' );
	check( $before === $meta, 'runtime audit does not mutate saved popup settings' );
	$meta[11][$display_key]['triggers']['page_load_delay'] = 9;
	check( mcp_abilities_elementor_audit_theme_builder_runtime_health()['healthy'], 'runtime audit accepts a nonzero native trigger delay' );
	unset( $meta[11][$display_key]['triggers']['page_load_delay'] );
	check( ! mcp_abilities_elementor_audit_theme_builder_runtime_health()['healthy'], 'runtime audit rejects a missing required trigger delay' );
	$meta[11][$display_key]['timing']['page_load_delay'] = 0;
	$invalid = mcp_abilities_elementor_audit_theme_builder_runtime_health();
	check( ! $invalid['healthy'] && ! $invalid['issues'][0]['details']['has_page_load_delay'], 'obsolete timing-group delay does not satisfy the native trigger requirement' );
	$meta[11][$display_key] = $valid;
	unset( $meta[11][$display_key]['timing'] );
	check( ! mcp_abilities_elementor_audit_theme_builder_runtime_health()['healthy'], 'runtime audit still rejects a missing timing group' );
	$meta[11][$display_key] = $valid;
	if ( $errors ) {
		fwrite( STDERR, implode( "\n", $errors ) . "\n" );
		exit( 1 );
	}
	echo $checks . " popup-display contract checks passed.\n";
}
