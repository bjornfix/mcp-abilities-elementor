<?php
/**
 * Shared ability registration helpers.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register an MCP ability with common defaults and annotation normalization.
 *
 * @param string $name Ability name.
 * @param array  $args Ability registration arguments.
 * @return void
 */
function mcp_abilities_elementor_register_ability( string $name, array $args ): void {
	$args['category'] = isset( $args['category'] ) && is_string( $args['category'] ) ? $args['category'] : 'site';

	if ( ! isset( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
		$args['permission_callback'] = 'mcp_abilities_elementor_can_edit_posts';
	}

	if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
		$args['meta'] = mcp_abilities_elementor_ability_meta( true );
	}

	wp_register_ability( $name, $args );
}

/**
 * Register a read-only Elementor ability.
 *
 * @param string   $name Ability name.
 * @param array    $args Ability registration arguments.
 * @param callable $callback Execute callback.
 * @return void
 */
function mcp_abilities_elementor_register_read_ability( string $name, array $args, callable $callback ): void {
	$args['execute_callback'] = $callback;
	$args['meta']             = mcp_abilities_elementor_ability_meta( true );
	mcp_abilities_elementor_register_ability( $name, $args );
}

/**
 * Register a mutating Elementor ability.
 *
 * @param string   $name Ability name.
 * @param array    $args Ability registration arguments.
 * @param callable $callback Execute callback.
 * @param bool     $destructive Whether the ability is destructive.
 * @return void
 */
function mcp_abilities_elementor_register_write_ability( string $name, array $args, callable $callback, bool $destructive = false ): void {
	$args['execute_callback'] = $callback;
	$args['meta']             = mcp_abilities_elementor_ability_meta( false, $destructive, false );
	mcp_abilities_elementor_register_ability( $name, $args );
}

