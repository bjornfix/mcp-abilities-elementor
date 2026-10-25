<?php
/**
 * Keep native Elementor frontend and JavaScript accessibility messages aligned.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a missing-message catalog from Elementor's already localized settings.
 *
 * Existing JavaScript catalog entries remain authoritative. Source-language
 * settings produce no script, and no language-specific text is stored here.
 *
 * @param array  $messages Native frontend i18n settings.
 * @param string $locale Current WordPress locale.
 * @return string Missing-message bridge, or an empty string when unnecessary.
 */
function mcp_abilities_elementor_frontend_i18n_script( array $messages, string $locale ): string {
	$keys = array(
		'Previous slide' => 'a11yCarouselPrevSlideMessage',
		'Next slide'     => 'a11yCarouselNextSlideMessage',
	);
	$translated = array();
	foreach ( $keys as $source => $key ) {
		$value = $messages[ $key ] ?? null;
		if ( is_string( $value ) && '' !== trim( $value ) && $source !== $value ) {
			$translated[ $source ] = array( $value );
		}
	}
	if ( empty( $translated ) ) {
		return '';
	}
	$json = wp_json_encode(
		array( 'domain' => 'elementor', 'locale' => $locale, 'messages' => $translated ),
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
	);
	if ( false === $json ) {
		return '';
	}
	return '(function(w){var data=' . $json . ';var i=w.wp&&w.wp.i18n;'
		. 'if(!i||typeof i.getLocaleData!=="function"||typeof i.setLocaleData!=="function"){return;}'
		. 'var current=i.getLocaleData(data.domain)||{};var merged=Object.assign({},current);var changed=false;'
		. 'Object.keys(data.messages).forEach(function(key){if(!Object.prototype.hasOwnProperty.call(current,key)){merged[key]=data.messages[key];changed=true;}});'
		. 'if(!changed){return;}if(!Object.prototype.hasOwnProperty.call(current,"")){merged[""]={domain:data.domain,lang:data.locale};}'
		. 'i.setLocaleData(merged,data.domain);})(window);';
}

/**
 * Fill missing lightbox messages through the standard WordPress i18n runtime.
 *
 * This runs after Elementor has assembled its native frontend settings. It
 * neither rewrites page markup nor changes controls, styles, or saved content.
 *
 * @return void
 */
function mcp_abilities_elementor_enqueue_frontend_i18n(): void {
	$handle = 'elementor-frontend';
	if ( is_admin() || ! class_exists( '\Elementor\Plugin' ) || ! wp_script_is( $handle, 'registered' )
		|| wp_script_is( $handle, 'done' ) || ! wp_script_is( 'wp-i18n', 'registered' ) ) {
		return;
	}
	$frontend = \Elementor\Plugin::instance()->frontend ?? null;
	if ( ! is_object( $frontend ) || ! is_callable( array( $frontend, 'get_settings' ) ) ) {
		return;
	}
	$messages = $frontend->get_settings( 'i18n' );
	if ( ! is_array( $messages ) ) {
		return;
	}
	$script = mcp_abilities_elementor_frontend_i18n_script( $messages, get_locale() );
	if ( '' === $script ) {
		return;
	}
	// Fill the catalog before the earliest native consumer can capture defaults.
	if ( wp_script_is( 'elementor-frontend-modules', 'registered' ) && ! wp_script_is( 'elementor-frontend-modules', 'done' ) ) {
		$handle = 'elementor-frontend-modules';
	}
	$scripts = wp_scripts();
	$registered = $scripts->registered[ $handle ] ?? null;
	if ( ! is_object( $registered ) || ! is_array( $registered->deps ?? null ) ) {
		return;
	}
	if ( in_array( $script, (array) $scripts->get_data( $handle, 'before' ), true ) ) {
		return;
	}
	if ( ! in_array( 'wp-i18n', $registered->deps, true ) ) {
		$registered->deps[] = 'wp-i18n';
	}
	wp_add_inline_script( $handle, $script, 'before' );
}
