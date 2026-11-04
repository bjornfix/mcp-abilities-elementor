<?php
/** Verify native additions against real WordPress metadata on the authorized dev site. */
declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'https://dev.devenia.com' !== untrailingslashit( home_url() ) || ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'An existing administrator on the authorized dev site is required.' );
}
$owned = 0;
$admin = get_current_user_id();
$checks = array();
$assert = static function ( bool $ok, string $name ) use ( &$checks ): void {
	$checks[ $name ] = $ok;
	if ( ! $ok ) { throw new RuntimeException( $name ); }
};
$call = static function ( string $name, array $params ) {
	return wp_get_ability( 'elementor/' . $name )->execute( $params );
};
$error = null;
try {
	$owned = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Temporary native Elementor additions contract' ), true );
	if ( is_wp_error( $owned ) ) { throw new RuntimeException( $owned->get_error_message() ); }
	$legacy = array( array( 'id' => 'legacyroot', 'elType' => 'container', 'settings' => array( 'flex_direction' => 'column' ), 'elements' => array( array( 'id' => 'legacychild', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Retained existing heading', 'title_color' => '#abcdef' ), 'elements' => array() ) ) ) );
	$assert( empty( mcp_abilities_elementor_enforce_global_style_policy( $legacy )['success'] ), 'fixture_has_existing_legacy_style' );
	foreach ( array( 'add-widget', 'add-container' ) as $ability ) {
		update_post_meta( $owned, '_elementor_data', wp_slash( wp_json_encode( $legacy ) ) );
		update_post_meta( $owned, '_elementor_edit_mode', 'builder' );
		$before = get_post_meta( $owned, '_elementor_data', true );
		$params = array( 'id' => $owned, 'parent_element_id' => 'legacyroot', 'element_id' => 'newchild', 'cache_scope' => 'none' );
		if ( 'add-widget' === $ability ) { $params['widget_type'] = 'heading'; $params['settings'] = array( 'title' => 'Inherited new heading' ); }
		if ( 'add-container' === $ability ) { $params['template_lookup_status'] = 'not_a_reusable_pattern'; }
		$r = $call( $ability, $params );
		$assert( ! is_wp_error( $r ) && empty( $r['success'] ) && $before === get_post_meta( $owned, '_elementor_data', true ), $ability . '_strict_default_no_write' );
		$r = $call( $ability, $params + array( 'allow_legacy_style_preservation' => true ) );
		$assert( ! is_wp_error( $r ) && ! empty( $r['success'] ), $ability . '_explicit_preservation_allowed' );
		$after = json_decode( get_post_meta( $owned, '_elementor_data', true ), true );
		$assert( 2 === count( $after[0]['elements'] ) && $legacy[0]['elements'][0] === $after[0]['elements'][0], $ability . '_native_persisted_existing_element_unchanged' );
		$assert( ! isset( $after[0]['elements'][1]['settings']['allow_legacy_style_preservation'] ), $ability . '_control_not_stored_as_content' );
		foreach ( array( array( 'background_color' => '#badcab' ), array( '__globals__' => array( 'background_color' => 'globals/colors?id=nonexistent-contract-token' ) ) ) as $index => $bad ) {
			update_post_meta( $owned, '_elementor_data', wp_slash( wp_json_encode( $legacy ) ) );
			$r = $call( $ability, array_replace( $params, array( 'settings' => $bad, 'allow_legacy_style_preservation' => true ) ) );
			$assert( ! is_wp_error( $r ) && empty( $r['success'] ) && $before === get_post_meta( $owned, '_elementor_data', true ), $ability . '_reject_new_style_or_invalid_reference_' . $index );
		}
		wp_set_current_user( 0 );
		$r = $call( $ability, $params + array( 'allow_legacy_style_preservation' => true ) );
		wp_set_current_user( $admin );
		$assert( is_wp_error( $r ) && $before === get_post_meta( $owned, '_elementor_data', true ), $ability . '_permission_guard_kept' );
	}
} catch ( Throwable $caught ) {
	$error = $caught->getMessage();
} finally {
	wp_set_current_user( $admin );
	if ( $owned && ! is_wp_error( $owned ) ) { wp_delete_post( $owned, true ); }
}
$checks['own_fixture_deleted'] = $owned && ! get_post( $owned );
echo wp_json_encode( array( 'passed' => null === $error && ! in_array( false, $checks, true ), 'checks' => $checks, 'error' => $error ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( $error ) { throw new RuntimeException( $error ); }
