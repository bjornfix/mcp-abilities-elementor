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
 * Build missing Swiper accessibility defaults from native Elementor messages.
 *
 * Per-instance settings and existing extended defaults remain authoritative.
 * No node, class, style, content or source-language setting is changed.
 *
 * @param array $messages Native frontend i18n settings.
 * @return string Script for the native Swiper handle, or an empty string.
 */
function mcp_abilities_elementor_swiper_i18n_script( array $messages ): string {
	$keys = array(
		'prevSlideMessage' => array( 'a11yCarouselPrevSlideMessage', 'Previous slide' ),
		'nextSlideMessage' => array( 'a11yCarouselNextSlideMessage', 'Next slide' ),
	);
	$translated = array();
	foreach ( $keys as $key => $mapping ) {
		$value = $messages[ $mapping[0] ] ?? null;
		if ( is_string( $value ) && '' !== trim( $value ) && $mapping[1] !== $value ) {
			$translated[ $key ] = $value;
		}
	}
	if ( empty( $translated ) ) {
		return '';
	}
	$json = wp_json_encode( $translated, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	if ( false === $json ) {
		return '';
	}
	return '(function(w){var S=w.Swiper;'
		. 'if(!S||typeof S.extendDefaults!=="function"){return;}'
		. 'var extended=S.extendedDefaults||{};var current=extended.a11y;'
		. 'if(current!==undefined&&(!current||typeof current!=="object"||Array.isArray(current))){return;}'
		. 'current=current||{};var messages=' . $json . ';var merged=Object.assign({},current);var changed=false;'
		. 'Object.keys(messages).forEach(function(key){if(!Object.prototype.hasOwnProperty.call(current,key)){merged[key]=messages[key];changed=true;}});'
		. 'if(changed){S.extendDefaults({a11y:merged});}})(window);';
}

/**
 * Supply a missing dialog close name from the native current-language message.
 *
 * Adapts the native frontend manager factory before construction, including
 * dialogs loaded on demand. The native subtype preserves original defaults,
 * explicit per-dialog labels, controls and geometry remain authoritative.
 *
 * @param array $messages Native frontend i18n settings.
 * @return string Native dialog registration, or an empty string.
 */
function mcp_abilities_elementor_dialog_i18n_script( array $messages ): string {
	$close = $messages['close'] ?? null;
	if ( ! is_string( $close ) || '' === trim( $close ) || 'Close' === $close ) {
		return '';
	}
	$json = wp_json_encode( $close, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	if ( false === $json ) {
		return '';
	}
	return '(function(w){var label=' . $json . ';'
		. 'function register(){var D=w.DialogsManager;var $=w.jQuery;'
		. 'if(!D||typeof D.getWidgetType!=="function"||typeof D.addWidgetType!=="function"){return;}'
		. 'var Base=D.getWidgetType("lightbox");'
		. 'if(!Base||typeof Base.extend!=="function"||!$||typeof $.extend!=="function"||Base.mcpCloseLocalized){return;}'
		. 'var Localized=Base.extend("lightbox",{getDefaultSettings:function(){'
		. 'var defaults=Base.prototype.getDefaultSettings.apply(this,arguments);'
		. 'var attrs=defaults&&defaults.closeButtonOptions&&defaults.closeButtonOptions.attributes;'
		. 'if(attrs&&Object.prototype.hasOwnProperty.call(attrs,"aria-label")){return defaults;}'
		. 'return $.extend(true,{},defaults,{closeButtonOptions:{attributes:{"aria-label":label}}});}});'
		. 'Localized.mcpCloseLocalized=true;D.addWidgetType("lightbox",Localized);}'
		. 'function install(){var F=w.elementorFrontend;'
		. 'if(!F||typeof F.getDialogsManager!=="function"||F.getDialogsManager.mcpCloseLocalized){return;}'
		. 'var original=F.getDialogsManager;var localized=function(){register();return original.apply(this,arguments);};'
		. 'localized.mcpCloseLocalized=true;F.getDialogsManager=localized;register();}'
		. 'var $=w.jQuery;if(typeof $==="function"){var events=$(w);'
		. 'if(events&&typeof events.on==="function"){events.on("elementor/frontend/init",install);}}'
		. 'register();install();})(window);';
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
	$swiper_script = mcp_abilities_elementor_swiper_i18n_script( $messages );
	if ( '' !== $swiper_script && wp_script_is( 'swiper', 'registered' ) && ! wp_script_is( 'swiper', 'done' ) ) {
		$before_swiper = (array) wp_scripts()->get_data( 'swiper', 'after' );
		if ( ! in_array( $swiper_script, $before_swiper, true ) ) {
			wp_add_inline_script( 'swiper', $swiper_script, 'after' );
		}
	}
	$dialog_script = mcp_abilities_elementor_dialog_i18n_script( $messages );
	// Native dialogs may load on demand, so an inline script on that handle is not delivered.
	// Register on the queued frontend and adapt its public manager factory before construction.
	if ( '' !== $dialog_script && ! in_array( $dialog_script, (array) wp_scripts()->get_data( $handle, 'before' ), true ) ) {
		wp_add_inline_script( $handle, $dialog_script, 'before' );
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
