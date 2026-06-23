<?php
/**
 * Shared runner for read-only Elementor design audits.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run a design audit over optional Elementor document data.
 *
 * @param array    $input Ability input.
 * @param callable $collect_element Receives an Elementor element and collector by reference.
 * @param callable $finalize Receives collector and returns the audit payload.
 * @param string   $message Success message.
 * @param mixed    $initial_collector Initial collector value.
 * @return array
 */
function mcp_abilities_elementor_run_design_audit( array $input, callable $collect_element, callable $finalize, string $message, $initial_collector = array() ): array {
	$post_id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$collector = $initial_collector;

	if ( $post_id > 0 ) {
		$document = mcp_abilities_elementor_load_document( $post_id );
		if ( empty( $document['success'] ) ) {
			return $document;
		}

		foreach ( (array) $document['data'] as $element ) {
			if ( is_array( $element ) ) {
				$collect_element( $element, $collector );
			}
		}
	}

	return array(
		'success' => true,
		'id'      => $post_id,
		'audit'   => $finalize( $collector ),
		'message' => $message,
	);
}

