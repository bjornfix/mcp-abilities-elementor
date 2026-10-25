<?php
/**
 * Native settings and script-enqueue boundary regression tests.
 *
 * @package MCP_Abilities_Elementor
 */
declare( strict_types=1 );
namespace Elementor {
	class Plugin {
		public static function instance() { return $GLOBALS['test_elementor']; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	class Test_Scripts {
		public array $registered = array();
		public array $extra = array();
		public function get_data( $handle, $key ) { return $this->extra[ $handle ][ $key ] ?? false; }
	}
	class Test_Frontend {
		public $messages;
		public array $keys = array();
		public function get_settings( $key ) { $this->keys[] = $key; return $this->messages; }
	}
	function is_admin() { return $GLOBALS['test_admin']; }
	function get_locale() { return $GLOBALS['test_locale']; }
	function wp_scripts() { return $GLOBALS['test_scripts']; }
	function wp_script_is( $handle, $state = 'enqueued' ) { return $GLOBALS['test_script_states'][ $handle ][ $state ] ?? false; }
	function wp_json_encode( $data, $flags = 0 ) { return $GLOBALS['test_json_fail'] ? false : json_encode( $data, $flags ); }
	function wp_add_inline_script( $handle, $script, $position = 'after' ) { $GLOBALS['test_scripts']->extra[ $handle ][ $position ][] = $script; return true; }
	function reset_case( $messages ): void {
		$GLOBALS['test_admin'] = false;
		$GLOBALS['test_locale'] = 'nb_NO';
		$GLOBALS['test_json_fail'] = false;
		$GLOBALS['test_script_states'] = array( 'elementor-frontend' => array( 'registered' => true, 'done' => false ), 'wp-i18n' => array( 'registered' => true ) );
		$scripts = new Test_Scripts();
		$scripts->registered['elementor-frontend'] = (object) array( 'deps' => array( 'jquery', 'elementor-frontend-modules' ) );
		$scripts->extra['elementor-frontend']['before'] = array( 'existingBefore();' );
		$GLOBALS['test_scripts'] = $scripts;
		$frontend = new Test_Frontend();
		$frontend->messages = $messages;
		$GLOBALS['test_elementor'] = (object) array( 'frontend' => $frontend );
	}
	$checks = 0;
	function check( bool $ok, string $message ): void { ++$GLOBALS['checks']; if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit(1); } }
	$helper = $argv[1] ?? __DIR__ . '/../includes/frontend-i18n.php';
	check( is_file( $helper ), 'frontend integration exists' );
	require $helper;
	$no = array( 'a11yCarouselPrevSlideMessage' => 'Forrige lysbilde', 'a11yCarouselNextSlideMessage' => 'Neste lysbilde', 'unrelated' => 'Retain me' );
	$en = array( 'a11yCarouselPrevSlideMessage' => 'Previous slide', 'a11yCarouselNextSlideMessage' => 'Next slide' );
	check( '' === mcp_abilities_elementor_frontend_i18n_script( $en, 'en_US' ), 'source-language site produces no bridge' );
	check( '' === mcp_abilities_elementor_frontend_i18n_script( array(), 'nb_NO' ), 'missing native messages produce no invented text' );
	check( '' === mcp_abilities_elementor_frontend_i18n_script( array( 'a11yCarouselNextSlideMessage' => 42 ), 'nb_NO' ), 'non-string message ignored' );
	reset_case( $en );
	mcp_abilities_elementor_enqueue_frontend_i18n();
	check( array( 'jquery', 'elementor-frontend-modules' ) === wp_scripts()->registered['elementor-frontend']->deps, 'English dependencies unchanged' );
	check( array( 'existingBefore();' ) === wp_scripts()->get_data( 'elementor-frontend', 'before' ), 'English inline scripts unchanged' );
	reset_case( $no );
	mcp_abilities_elementor_enqueue_frontend_i18n();
	check( array( 'jquery', 'elementor-frontend-modules', 'wp-i18n' ) === wp_scripts()->registered['elementor-frontend']->deps, 'native i18n dependency appended preserving order' );
	check( 2 === count( wp_scripts()->get_data( 'elementor-frontend', 'before' ) ), 'catalog added before native frontend' );
	check( $no === $GLOBALS['test_elementor']->frontend->messages, 'native configuration not mutated' );
	check( array( 'i18n' ) === $GLOBALS['test_elementor']->frontend->keys, 'public native settings interface used' );
	mcp_abilities_elementor_enqueue_frontend_i18n();
	check( 2 === count( wp_scripts()->get_data( 'elementor-frontend', 'before' ) ), 'repeated enqueue does not duplicate catalog' );
	check( 3 === count( wp_scripts()->registered['elementor-frontend']->deps ), 'repeated enqueue does not duplicate dependency' );
	reset_case( $no );
	$GLOBALS['test_script_states']['elementor-frontend-modules'] = array( 'registered' => true, 'done' => false );
	wp_scripts()->registered['elementor-frontend-modules'] = (object) array( 'deps' => array( 'jquery', 'elementor-webpack-runtime' ) );
	mcp_abilities_elementor_enqueue_frontend_i18n();
	check( array( 'jquery', 'elementor-webpack-runtime', 'wp-i18n' ) === wp_scripts()->registered['elementor-frontend-modules']->deps, 'core i18n loads before earliest native module' );
	check( 1 === count( wp_scripts()->get_data( 'elementor-frontend-modules', 'before' ) ), 'catalog precedes module evaluation' );
	check( array( 'existingBefore();' ) === wp_scripts()->get_data( 'elementor-frontend', 'before' ), 'later native frontend config remains untouched' );
	check( array( 'jquery', 'elementor-frontend-modules' ) === wp_scripts()->registered['elementor-frontend']->deps, 'only earliest consumer dependency changed' );
	$GLOBALS['test_script_states']['elementor-frontend-modules']['done'] = true;
	mcp_abilities_elementor_enqueue_frontend_i18n();
	check( 2 === count( wp_scripts()->get_data( 'elementor-frontend', 'before' ) ), 'already printed module safely falls back to unprinted frontend' );
	reset_case( $no );
	$fixtures = array( 'no_script' => mcp_abilities_elementor_frontend_i18n_script( $no, 'nb_NO' ), 'english_script' => mcp_abilities_elementor_frontend_i18n_script( $en, 'en_US' ) );
	foreach ( array( 'admin', 'unregistered', 'done', 'missing_i18n', 'invalid_messages', 'missing_frontend', 'json_failure' ) as $case ) {
		reset_case( $no );
		if ( 'admin' === $case ) { $GLOBALS['test_admin'] = true; }
		if ( 'unregistered' === $case ) { $GLOBALS['test_script_states']['elementor-frontend']['registered'] = false; }
		if ( 'done' === $case ) { $GLOBALS['test_script_states']['elementor-frontend']['done'] = true; }
		if ( 'missing_i18n' === $case ) { $GLOBALS['test_script_states']['wp-i18n']['registered'] = false; }
		if ( 'invalid_messages' === $case ) { $GLOBALS['test_elementor']->frontend->messages = null; }
		if ( 'missing_frontend' === $case ) { $GLOBALS['test_elementor']->frontend = null; }
		if ( 'json_failure' === $case ) { $GLOBALS['test_json_fail'] = true; }
		mcp_abilities_elementor_enqueue_frontend_i18n();
		check( array( 'existingBefore();' ) === wp_scripts()->get_data( 'elementor-frontend', 'before' ), "$case fails closed without inline changes" );
		check( array( 'jquery', 'elementor-frontend-modules' ) === wp_scripts()->registered['elementor-frontend']->deps, "$case preserves native dependencies" );
	}
	reset_case( $no );
	$special = 'Next "item" </script><script>unsafe</script> & previous';
	$fixtures['special_value'] = $special;
	$fixtures['special_script'] = mcp_abilities_elementor_frontend_i18n_script( array( 'a11yCarouselNextSlideMessage' => $special ), 'el' );
	check( false === strpos( $fixtures['special_script'], '</script>' ), 'script terminators JSON escaped' );
	$fixtures['swiper_no'] = mcp_abilities_elementor_swiper_i18n_script( $no );
	$fixtures['swiper_en'] = mcp_abilities_elementor_swiper_i18n_script( $en );
	$fixtures['swiper_special'] = mcp_abilities_elementor_swiper_i18n_script( array( 'a11yCarouselNextSlideMessage' => $special ) );
	check( '' === $fixtures['swiper_en'], 'source-language Swiper site is unchanged' );
	check( '' === mcp_abilities_elementor_swiper_i18n_script( array() ), 'no Swiper text invented without native messages' );
	check( '' === mcp_abilities_elementor_swiper_i18n_script( array( 'a11yCarouselNextSlideMessage' => 42 ) ), 'malformed native Swiper message ignored' );
	check( false === strpos( $fixtures['swiper_special'], '</script>' ), 'Swiper message terminators escaped' );
	reset_case( $no );
	$GLOBALS['test_script_states']['swiper'] = array( 'registered' => true, 'done' => false );
	wp_scripts()->registered['swiper'] = (object) array( 'deps' => array( 'existing-swiper-dependency' ) );
	wp_scripts()->extra['swiper']['after'] = array( 'existingAfter();' );
	mcp_abilities_elementor_enqueue_frontend_i18n();
	check( array( 'existing-swiper-dependency' ) === wp_scripts()->registered['swiper']->deps, 'Swiper dependencies are preserved' );
	check( 2 === count( wp_scripts()->get_data( 'swiper', 'after' ) ), 'native Swiper handle receives one after script' );
	check( 'existingAfter();' === wp_scripts()->get_data( 'swiper', 'after' )[0], 'existing after script remains first' );
	check( $no === $GLOBALS['test_elementor']->frontend->messages, 'Swiper configuration source remains unchanged' );
	mcp_abilities_elementor_enqueue_frontend_i18n();
	check( 2 === count( wp_scripts()->get_data( 'swiper', 'after' ) ), 'repeated hook adds no duplicate Swiper script' );
	foreach ( array( 'english', 'done', 'missing', 'admin' ) as $case ) {
		reset_case( 'english' === $case ? $en : $no );
		$GLOBALS['test_script_states']['swiper'] = array( 'registered' => 'missing' !== $case, 'done' => 'done' === $case );
		if ( 'admin' === $case ) { $GLOBALS['test_admin'] = true; }
		mcp_abilities_elementor_enqueue_frontend_i18n();
		check( false === wp_scripts()->get_data( 'swiper', 'after' ), "$case leaves Swiper scripts unchanged" );
	}
	if ( isset( $argv[2] ) ) { file_put_contents( $argv[2], json_encode( $fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" ); }
	echo "Frontend i18n PHP boundary checks passed: {$checks}.\n";
}
