<?php
/** Run via WP-CLI eval-file on dev only. Real WordPress/Pro; own objects; no backup or license writes. */
declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'https://dev.devenia.com' !== untrailingslashit( home_url() ) ) {
	throw new RuntimeException( 'This test requires the authorized dev WordPress CLI.' );
}
if ( ! current_user_can( 'manage_options' ) || ! post_type_exists( 'elementor_snippet' ) || ! \ElementorPro\License\API::is_license_active() ) {
	throw new RuntimeException( 'Native licensed Custom Code and an existing administrator are required.' );
}
if ( '2.3.57' !== get_file_data( WP_PLUGIN_DIR . '/mcp-abilities-elementor/mcp-abilities-elementor.php', array( 'Version' => 'Version' ) )['Version'] || '4.3.2' !== ELEMENTOR_PRO_VERSION ) {
	throw new RuntimeException( 'The authorized installed versions have changed.' );
}

$run = 'mcp-snippet-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) );
$admin = get_current_user_id();
$owned = $checks = $http = $cleanup_delete_results = array();
$error = null;
$cache_key = 'elementor_pro_theme_builder_conditions';
$normalize_cache = static function ( array $cache ): array {
	foreach ( $cache as $location => $assignments ) {
		if ( ! $assignments ) { unset( $cache[ $location ] ); } else { ksort( $cache[ $location ] ); }
	}
	ksort( $cache );
	return $cache;
};
$before_cache = $normalize_cache( (array) get_option( $cache_key, array() ) );
$inventory = static function (): array {
	$ids = get_posts( array( 'post_type' => 'elementor_snippet', 'post_status' => array( 'publish', 'draft', 'private', 'trash', 'pending', 'future', 'auto-draft' ), 'numberposts' => -1, 'fields' => 'ids' ) );
	sort( $ids );
	return $ids;
};
$before_ids = $inventory();
$before_existing = array();
foreach ( $before_ids as $id ) { $before_existing[ $id ] = hash( 'sha256', serialize( array( get_post( $id ), get_post_meta( $id ) ) ) ); }
$assert = static function ( bool $ok, string $name ) use ( &$checks ): void {
	$checks[ $name ] = $ok;
	if ( ! $ok ) { throw new RuntimeException( $name ); }
};
$observe = static function ( bool $ok, string $name ) use ( &$checks ): void { $checks[ $name ] = $ok; };
$call = static function ( string $operation, array $input = array() ): array {
	$result = wp_get_ability( 'elementor/' . $operation . '-custom-code' )->execute( $input );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $operation . ': ' . $result->get_error_code() . ': ' . $result->get_error_message() ); }
	return $result;
};
$create = static function ( string $label, string $code, array $extra = array() ) use ( $call, $run, &$owned, $assert ): int {
	$result = $call( 'create', array( 'title' => $run . '-' . $label, 'code' => $code, 'status' => 'draft' ) + $extra );
	if ( ! empty( $result['id'] ) ) { $owned[] = (int) $result['id']; }
	$assert( true === $result['success'] && ! empty( $result['id'] ), 'create_' . $label );
	return (int) $result['id'];
};
$fetch = static function ( int $id, string $phase ) use ( $run, $admin, &$http, $assert ): string {
    // The dev frontend requires login. Render through the existing administrator
    // WP-CLI Interface, in a fresh WordPress process, without creating auth tokens.
    $render_code = 'if ("https://dev.devenia.com" !== untrailingslashit(home_url())) { throw new RuntimeException("Wrong target"); } '
        . '$_GET = array("page_id"=>' . $id . '); $_REQUEST = $_GET; $_SERVER["REQUEST_URI"] = "/?page_id=' . $id . '"; '
        . 'global $wp; $wp->main(array("page_id"=>' . $id . ')); '
        . 'if (!is_page(' . $id . ')) { throw new RuntimeException("Wrong queried page"); } '
        . 'do_action("template_redirect"); $template=apply_filters("template_include",get_page_template()); '
        . 'if (!$template || !is_file($template)) {throw new RuntimeException("No native page template");} include $template;';
    $result = WP_CLI::runcommand( 'eval ' . escapeshellarg( $render_code ) . ' --allow-root --user=' . $admin . ' --url=https://dev.devenia.com', array( 'launch' => true, 'return' => 'all', 'exit_error' => false ) );
    $body = $result->stdout;
    $positions = array();
    foreach ( array( 'first', 'exact', 'body-start', 'body-end' ) as $name ) { $positions[ $name ] = strpos( $body, 'id="' . $run . '-' . $name . '"' ); }
    $positions['head_end'] = stripos( $body, '</head>' );
    $positions['body_open'] = stripos( $body, '<body' );
    $positions['body_close'] = stripos( $body, '</body>' );
    $http[] = array( 'phase' => $phase, 'page_id' => $id, 'mode' => 'native_theme_via_existing_wp_cli', 'process_exit_code' => $result->return_code, 'body_sha256' => hash( 'sha256', $body ), 'positions' => $positions, 'own_page_title_present' => str_contains( $body, $run . '-' . ( str_contains( $phase, 'excluded' ) || 'disabled' === $phase ? 'excluded' : 'included' ) ), 'process_error' => substr( $result->stderr, 0, 1000 ) );
    $assert( 0 === $result->return_code, 'render_process_ok_' . $phase );
    return $body;
};
$code_for = static fn( string $name ): string => '<script type="application/json" id="' . $run . '-' . $name . '">{"test":"' . $name . '"}</script>';

try {
	$pages = array();
	foreach ( array( 'included', 'excluded' ) as $label ) {
		$id = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $run . '-' . $label, 'post_name' => $run . '-' . $label, 'post_content' => '<p>Temporary native snippet validation.</p>' ) ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$owned[] = $pages[ $label ] = (int) $id;
	}
	$conditions = array( 'include/singular/page/' . $pages['included'], 'exclude/singular/page/' . $pages['excluded'] );
	$code = '<script type="application/json" id="' . $run . '-exact">' . wp_json_encode( array( 'path' => 'C:\\files', 'quote' => 'a"b', 'pattern' => '\\d+', 'unicode' => 'æøå' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	$a = $create( 'exact', $code, array( 'conditions' => $conditions ) );
	$r = $call( 'get', array( 'id' => $a, 'include_meta' => true ) );
	$assert( $code === $r['code'] && '' === get_post( $a )->post_content, 'native_meta_only_exact_escaping' );
	$assert( 'elementor_head' === $r['location'] && 1 === $r['priority'] && $conditions === $r['conditions'], 'native_defaults_and_conditions' );
	$doc = mcp_abilities_elementor_condition_document( $a );
	$assert( $doc instanceof \ElementorPro\Modules\CustomCode\Document, 'real_native_document_type' );
	wp_set_current_user( 0 );
	ob_start(); $doc->print_content(); $rendered = ob_get_clean();
	wp_set_current_user( $admin );
	$assert( $code . PHP_EOL === $rendered, 'native_document_renders_exact_bytes' );
	wp_update_post( array( 'ID' => $a, 'post_content' => 'Stale legacy body on own test snippet' ) );
	$call( 'update', array( 'id' => $a, 'code' => '' ) );
	$assert( '' === $call( 'get', array( 'id' => $a ) )['code'], 'empty_code_never_revives_legacy_body' );
	$r = $call( 'update', array( 'id' => $a, 'code' => $code, 'location' => 'elementor_body_end', 'priority' => 7, 'conditions' => $conditions ) );
	$cache = get_option( $cache_key, array() );
	$assert( $r['success'] && ! isset( $cache['elementor_head'][ $a ] ) && $conditions === $cache['elementor_body_end'][ $a ], 'real_native_cache_relocation' );
	$call( 'update', array( 'id' => $a, 'conditions' => array() ) );
	$cache = get_option( $cache_key, array() );
	$assert( ! get_post_meta( $a, '_elementor_conditions', true ) && ! isset( $cache['elementor_body_end'][ $a ] ), 'empty_conditions_remove_native_assignment' );
	$call( 'update', array( 'id' => $a, 'conditions' => $conditions ) );
	$before_invalid = serialize( array( get_post( $a ), get_post_meta( $a ), get_option( $cache_key ) ) );
	foreach ( array( array( 'priority' => 11 ), array( 'location' => 'footer' ) ) as $invalid ) {
		$r = wp_get_ability( 'elementor/update-custom-code' )->execute( array( 'id' => $a, 'title' => $run . '-must-not-save' ) + $invalid );
		$assert( is_wp_error( $r ) && 'ability_invalid_input' === $r->get_error_code(), 'framework_rejects_' . key( $invalid ) );
	}
	$r = $call( 'update', array( 'id' => $a, 'title' => $run . '-must-not-save', 'conditions' => array( 'general' ) ) );
	$assert( false === $r['success'] && $before_invalid === serialize( array( get_post( $a ), get_post_meta( $a ), get_option( $cache_key ) ) ), 'invalid_condition_has_no_partial_write' );
	$force_error = static fn( $empty, $postarr ) => str_starts_with( $postarr['post_title'] ?? '', $run . '-forced-error' ) ? true : $empty;
	add_filter( 'wp_insert_post_empty_content', $force_error, 10, 2 );
	try {
		foreach ( array( 'create', 'update' ) as $operation ) {
			$input = array( 'title' => $run . '-forced-error', 'code' => '', 'status' => 'draft' );
			if ( 'update' === $operation ) { $input['id'] = $a; }
			$r = $call( $operation, $input );
			$assert( false === $r['success'] && array( 'success', 'message' ) === array_keys( $r ), 'real_wp_error_' . $operation );
		}
	} finally { remove_filter( 'wp_insert_post_empty_content', $force_error, 10 ); }
	$old_code = get_post_meta( $a, '_elementor_code', true );
	$old_cache = get_option( $cache_key );
	$fail_meta = static fn( $check, $id, $key ) => $a === $id && '_elementor_code' === $key ? false : $check;
	add_filter( 'update_post_metadata', $fail_meta, 10, 3 );
	try { $r = $call( 'update', array( 'id' => $a, 'code' => 'Rejected own metadata write' ) ); }
	finally { remove_filter( 'update_post_metadata', $fail_meta, 10 ); }
	$assert( false === $r['success'] && $old_code === get_post_meta( $a, '_elementor_code', true ) && $old_cache === get_option( $cache_key ), 'metadata_failure_preserves_code_and_assignments' );
	$deny_html = static function ( $caps ) { $caps['unfiltered_html'] = false; return $caps; };
	add_filter( 'user_has_cap', $deny_html );
	try {
		$unsafe = '<b>Allowed</b><script>inert_test_text</script>';
		$r = $call( 'update', array( 'id' => $a, 'code' => $unsafe ) );
		$assert( $r['success'] && wp_kses_post( $unsafe ) === get_post_meta( $a, '_elementor_code', true ), 'real_unfiltered_html_capability_restriction' );
	} finally { remove_filter( 'user_has_cap', $deny_html ); }
	$call( 'update', array( 'id' => $a, 'code' => $code, 'location' => 'elementor_head', 'priority' => 9, 'conditions' => $conditions, 'status' => 'publish' ) );
	$b = $create( 'first', $code_for( 'first' ), array( 'priority' => 1, 'conditions' => $conditions ) );
	$c = $create( 'legacy', '', array( 'meta' => array( '_elementor_code' => $code_for( 'body-start' ), '_elementor_location' => 'elementor_body_start', '_elementor_priority' => '3', '_elementor_conditions' => $conditions ) ) );
	$d = $create( 'body-end', $code_for( 'body-end' ), array( 'location' => 'elementor_body_end', 'priority' => 6, 'conditions' => $conditions ) );
	$assert( 3 === $call( 'get', array( 'id' => $c ) )['priority'] && $code_for( 'body-start' ) === $call( 'get', array( 'id' => $c ) )['code'], 'legacy_meta_caller_uses_native_storage' );
	foreach ( array( $b, $c, $d ) as $id ) { $assert( $call( 'update', array( 'id' => $id, 'status' => 'publish' ) )['success'], 'publish_' . $id ); }
	$list = $call( 'list', array( 'include_code' => true, 'limit' => 200 ) );
	$assert( count( array_intersect( array( $a, $b, $c, $d ), array_column( $list['snippets'], 'id' ) ) ) === 4, 'published_snippets_listed' );
	$before_rejected_delete = get_option( $cache_key );
	$reject_delete = static fn( $check, $post, $force ) => $d === $post->ID && $force ? false : $check;
	add_filter( 'pre_delete_post', $reject_delete, 10, 3 );
	try { $r = $call( 'delete', array( 'id' => $d, 'force' => true ) ); }
	finally { remove_filter( 'pre_delete_post', $reject_delete, 10 ); }
	$assert( false === $r['success'] && (bool) get_post( $d ), 'rejected_force_preserves_post' );
	$assert( $before_rejected_delete === get_option( $cache_key ), 'rejected_force_preserves_all_assignments' );
	wp_set_current_user( 0 );
	foreach ( array( 'list', 'get', 'create', 'update', 'delete' ) as $operation ) {
		$input = 'list' === $operation ? array() : ( 'create' === $operation ? array( 'title' => $run . '-denied', 'code' => '', 'status' => 'draft' ) : array( 'id' => $a ) );
		$r = wp_get_ability( 'elementor/' . $operation . '-custom-code' )->execute( $input );
		$assert( is_wp_error( $r ) && 'ability_invalid_permissions' === $r->get_error_code(), 'anonymous_denied_' . $operation );
	}
	wp_set_current_user( $admin );
	$html = $fetch( $pages['included'], 'included-initial' );
	$first = strpos( $html, 'id="' . $run . '-first"' ); $exact = strpos( $html, 'id="' . $run . '-exact"' );
	$body_start = strpos( $html, 'id="' . $run . '-body-start"' ); $body_end = strpos( $html, 'id="' . $run . '-body-end"' );
	$head_end = stripos( $html, '</head>' ); $body_open = stripos( $html, '<body' ); $body_close = stripos( $html, '</body>' );
	$observe( false !== $first && false !== $exact && false !== $head_end && $first < $exact && $exact < $head_end, 'render_head_priority_order' );
	$observe( false !== $body_start && false !== $body_end && false !== $body_open && false !== $body_close && $body_open < $body_start && $body_start < $body_end && $body_end < $body_close, 'render_body_start_and_end_placement' );
	$observe( str_contains( $html, $code ), 'render_exact_escaped_code' );
	$observe( ! str_contains( $fetch( $pages['excluded'], 'excluded-initial' ), 'id="' . $run . '-exact"' ), 'render_excluded_page_has_no_snippet' );
	$new_conditions = array( 'include/singular/page/' . $pages['excluded'], 'exclude/singular/page/' . $pages['included'] );
	$call( 'update', array( 'id' => $a, 'conditions' => $new_conditions, 'location' => 'elementor_body_end', 'priority' => 2 ) );
	$observe( ! str_contains( $fetch( $pages['included'], 'included-after-move' ), 'id="' . $run . '-exact"' ), 'render_condition_update_removes_old_page' );
	$html = $fetch( $pages['excluded'], 'excluded-after-move' ); $position = strpos( $html, 'id="' . $run . '-exact"' );
	$observe( false !== $position && stripos( $html, '<body' ) < $position && $position < stripos( $html, '</body>' ), 'render_condition_and_location_update' );
	$cache = get_option( $cache_key, array() );
	$assert( ! isset( $cache['elementor_head'][ $a ] ) && $new_conditions === $cache['elementor_body_end'][ $a ], 'cache_matches_relocated_document' );
	$call( 'update', array( 'id' => $a, 'status' => 'draft' ) );
	$observe( ! str_contains( $fetch( $pages['excluded'], 'disabled' ), 'id="' . $run . '-exact"' ), 'render_draft_disables_rendering' );
	$assert( $call( 'delete', array( 'id' => $a ) )['success'] && 'trash' === get_post_status( $a ), 'delete_trashes_by_default' );
	$assert( $call( 'delete', array( 'id' => $a, 'force' => true ) )['success'] && ! get_post( $a ), 'forced_delete_removes_own_snippet' );
	$cache = get_option( $cache_key, array() );
	$observe( ! array_filter( $cache, static fn( array $assignments ): bool => isset( $assignments[ $a ] ) ), 'native_cache_removes_deleted_assignment' );
	$r = $call( 'get', array( 'id' => $a ) );
	$assert( false === $r['success'], 'deleted_snippet_returns_domain_failure' );
} catch ( Throwable $exception ) { $error = $exception->getMessage(); }
finally {
	wp_set_current_user( $admin );
	// Recover an own object even if output validation failed after a successful write.
	$orphans = get_posts( array( 'post_type' => array( 'page', 'elementor_snippet' ), 'post_status' => array( 'publish', 'draft', 'private', 'trash', 'pending', 'auto-draft' ), 's' => $run, 'numberposts' => 50 ) );
	foreach ( $orphans as $post ) { if ( str_starts_with( $post->post_title, $run . '-' ) ) { $owned[] = (int) $post->ID; } }
	$owned = array_values( array_unique( $owned ) );
	foreach ( $owned as $id ) {
		$post = get_post( $id );
		if ( ! $post ) { continue; }
		if ( ! str_starts_with( $post->post_title, $run . '-' ) ) { $error = ( $error ? $error . '; ' : '' ) . 'cleanup ownership mismatch: ' . $id; continue; }
		if ( 'elementor_snippet' === $post->post_type ) {
			try { $cleanup_delete_results[ $id ] = $call( 'delete', array( 'id' => $id, 'force' => true ) ); }
			catch ( Throwable $cleanup_error ) { $checks['cleanup_ability_delete_' . $id] = false; }
		}
		if ( get_post( $id ) ) { wp_delete_post( $id, true ); }
	}
	$cache = get_option( $cache_key, array() );
	$stale = array();
	foreach ( $cache as $assignments ) { foreach ( $owned as $id ) { if ( isset( $assignments[ $id ] ) ) { $stale[] = $id; } } }
	$checks['native_deletion_clears_own_assignments'] = ! $stale;
	if ( $stale ) {
		$manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		$native_cache = $manager->get_cache()->refresh();
		foreach ( array_unique( $stale ) as $id ) { $native_cache->remove( $id ); }
		$native_cache->save();
		$manager->clear_location_cache();
	}
	$remaining = array_values( array_filter( $owned, static fn( int $id ): bool => (bool) get_post( $id ) ) );
	$checks['cleanup_all_own_objects_removed'] = array() === $remaining;
	$checks['existing_snippet_inventory_preserved'] = $before_ids === $inventory();
	$checks['unrelated_native_assignments_preserved'] = $before_cache === $normalize_cache( (array) get_option( $cache_key, array() ) );
	foreach ( $before_existing as $id => $digest ) {
		$checks['existing_snippet_unchanged_' . $id] = $digest === hash( 'sha256', serialize( array( get_post( $id ), get_post_meta( $id ) ) ) );
	}
}
$report = array( 'status' => ! $error && ! in_array( false, $checks, true ) ? 'PASS' : 'FAIL', 'run' => $run, 'error' => $error, 'checks_passed' => count( array_filter( $checks ) ), 'checks_total' => count( $checks ), 'checks' => $checks, 'render_requests' => $http, 'owned_object_ids' => $owned, 'remaining_object_ids' => $remaining, 'cleanup_ability_force_results' => $cleanup_delete_results, 'stale_assignments_removed_during_cleanup' => array_values( array_unique( $stale ) ), 'existing_snippet_count' => count( $before_ids ), 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'elementor' => ELEMENTOR_VERSION, 'elementor_pro' => ELEMENTOR_PRO_VERSION, 'mcp' => '2.3.57' );
echo 'MCP_NATIVE_REPORT=' . wp_json_encode( $report, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( 'PASS' !== $report['status'] ) { WP_CLI::halt( 1 ); }
