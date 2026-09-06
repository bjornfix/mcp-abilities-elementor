<?php
/**
 * Elementor template lookup helpers.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return generic title/search keywords for reusable Elementor template patterns.
 *
 * Keep this public-plugin catalog site-neutral. It must describe reusable
 * pattern language, not client names, page names, or site-specific template IDs.
 *
 * @return array
 */
function mcp_abilities_elementor_get_template_pattern_keywords(): array {
	return array(
		'split_panel_carousel_surface' => array(
			'split',
			'panel',
			'carousel',
			'slides',
			'slider',
			'media',
			'image',
			'text',
		),
		'static_split_panel_image_surface' => array(
			'split',
			'panel',
			'image',
			'background',
			'cover',
			'media',
			'text',
		),
		'actual_media_gallery_carousel' => array(
			'carousel',
			'gallery',
			'media',
			'image',
			'slider',
			'slides',
		),
		'dynamic_related_or_archive_cards' => array(
			'related',
			'archive',
			'posts',
			'loop',
			'grid',
			'cards',
			'listing',
		),
		'static_image_card_grid' => array(
			'image',
			'box',
			'card',
			'cards',
			'grid',
		),
		'curated_image_gallery' => array(
			'gallery',
			'images',
			'grid',
			'portfolio',
		),
		'repeated_promo_or_cta_modules' => array(
			'cta',
			'call',
			'action',
			'promo',
			'teaser',
			'module',
		),
	);
}

/**
 * Return reusable pattern names accepted by template lookup-aware abilities.
 *
 * @return array
 */
function mcp_abilities_elementor_get_template_pattern_names(): array {
	return array_merge( array_keys( mcp_abilities_elementor_get_template_pattern_keywords() ), array( 'custom' ) );
}

/**
 * Build schema properties used to make raw authoring template-aware.
 *
 * @return array
 */
function mcp_abilities_elementor_get_template_lookup_schema_properties(): array {
	return array(
		'template_lookup_status' => array(
			'type'        => 'string',
			'enum'        => array( 'matched_template_used', 'no_matching_template_create_first', 'not_a_reusable_pattern' ),
			'description' => 'Required for raw container authoring. Call elementor/find-template-for-pattern first when the work resembles a reusable pattern. Use matched_template_used only when the submitted data contains a native linked template widget or template shortcode, no_matching_template_create_first when a reusable pattern has no saved template yet, or not_a_reusable_pattern for one-off structural edits.',
		),
		'template_pattern' => array(
			'type'        => 'string',
			'enum'        => mcp_abilities_elementor_get_template_pattern_names(),
			'description' => 'Reusable pattern checked with elementor/find-template-for-pattern, if applicable.',
		),
		'template_id' => array(
			'type'        => 'integer',
			'description' => 'Existing published, readable Elementor template ID linked in the submitted data (or add-container elements). Copied template content does not count as a linked use.',
		),
	);
}

/**
 * Enforce template lookup on raw Elementor container authoring.
 *
 * This intentionally targets raw container creation, because repeated layout
 * drift usually starts there. It does not block creating templates themselves.
 *
 * @param array  $input Ability input.
 * @param string $ability_name Ability name.
 * @param array  $data Proposed new elements to check for a native template link.
 * @return array|null Error response or null when allowed.
 */
function mcp_abilities_elementor_template_lookup_guard_response( array $input, string $ability_name, array $data = array() ): ?array {
	$status = isset( $input['template_lookup_status'] ) && is_string( $input['template_lookup_status'] )
		? sanitize_key( $input['template_lookup_status'] )
		: '';

	if ( '' === $status ) {
		return array(
			'success' => false,
			'message' => 'Raw Elementor container authoring requires template lookup status. Call elementor/find-template-for-pattern first when this resembles a reusable pattern, or set template_lookup_status to not_a_reusable_pattern for a one-off structural edit.',
			'ability' => $ability_name,
			'template_first_required' => true,
			'required_next_step' => 'elementor/find-template-for-pattern',
		);
	}

	if ( 'no_matching_template_create_first' === $status ) {
		return array(
			'success' => false,
			'message' => 'A reusable Elementor pattern was identified but no matching saved template exists. Create a saved Elementor template with elementor/create-template before applying this pattern broadly.',
			'ability' => $ability_name,
			'template_first_required' => true,
			'required_next_step' => 'elementor/create-template',
		);
	}

	if ( 'matched_template_used' === $status ) {
		$template_id = (int) ( $input['template_id'] ?? 0 );
		$template = $template_id > 0 ? get_post( $template_id ) : null;
		if ( ! $template || 'elementor_library' !== $template->post_type || 'publish' !== $template->post_status || ! current_user_can( 'read_post', $template_id ) ) {
			return array( 'success' => false, 'message' => 'template_id must identify an existing, published, readable Elementor template. Publish the saved template before linking it.', 'ability' => $ability_name, 'template_first_required' => true );
		}
		if ( ! mcp_abilities_elementor_has_linked_template( $data, $template_id ) ) {
			return array(
				'success' => false,
				'message' => 'matched_template_used requires a native template widget or active elementor-template shortcode linked to template_id in the submitted data. For add-container, provide the linked widget in elements. Copied template content does not stay linked.',
				'ability' => $ability_name,
				'template_first_required' => true,
				'required_next_step' => 'elementor/get-widget-controls',
			);
		}
	}

	if ( ! in_array( $status, array( 'matched_template_used', 'not_a_reusable_pattern' ), true ) ) {
		return array(
			'success' => false,
			'message' => 'Invalid template_lookup_status.',
			'ability' => $ability_name,
			'template_first_required' => true,
		);
	}

	return null;
}

/**
 * Recognize native linked templates in the submitted subtree, not arbitrary IDs.
 *
 * @param array $data New elements.
 * @param int   $template_id Referenced library document.
 * @return bool
 */
function mcp_abilities_elementor_has_linked_template( array $data, int $template_id ): bool {
	foreach ( $data as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		if ( 'widget' === ( $element['elType'] ?? '' ) ) {
			$type = $element['widgetType'] ?? '';
			if ( 'template' === $type && $template_id === (int) ( $settings['template_id'] ?? 0 ) && class_exists( '\Elementor\Plugin' ) ) {
				$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( 'template' );
				// Elementor Free also registers a promotion placeholder named template.
				if ( $widget && isset( $widget->get_controls()['template_id'] ) ) {
					return true;
				}
			}
			if ( 'shortcode' === $type && is_string( $settings['shortcode'] ?? null ) && shortcode_exists( 'elementor-template' ) ) {
				preg_match_all( '/' . get_shortcode_regex( array( 'elementor-template' ) ) . '/s', $settings['shortcode'], $matches, PREG_SET_ORDER );
				foreach ( $matches as $match ) {
					if ( '[' === $match[1] && ']' === $match[6] ) {
						continue;
					}
					$attributes = shortcode_parse_atts( $match[3] );
					if ( is_array( $attributes ) && $template_id === (int) ( $attributes['id'] ?? 0 ) ) {
						return true;
					}
				}
			}
		}
		if ( is_array( $element['elements'] ?? null ) && mcp_abilities_elementor_has_linked_template( $element['elements'], $template_id ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Score a saved Elementor template title against generic pattern keywords.
 *
 * @param string $title Template title.
 * @param array  $keywords Pattern keywords.
 * @param string $search Optional caller search text.
 * @return int
 */
function mcp_abilities_elementor_score_template_pattern_match( string $title, array $keywords, string $search = '' ): int {
	$haystack = ' ' . strtolower( $title ) . ' ';
	$score    = 0;

	foreach ( $keywords as $keyword ) {
		if ( ! is_string( $keyword ) || '' === trim( $keyword ) ) {
			continue;
		}
		if ( false !== strpos( $haystack, strtolower( trim( $keyword ) ) ) ) {
			$score += 2;
		}
	}

	$search_words = preg_split( '/[^a-z0-9]+/', strtolower( $search ) );
	foreach ( is_array( $search_words ) ? $search_words : array() as $word ) {
		if ( strlen( $word ) < 3 ) {
			continue;
		}
		if ( false !== strpos( $haystack, $word ) ) {
			++$score;
		}
	}

	return $score;
}

/**
 * Query saved Elementor templates with a consistent response shape.
 *
 * @param array $options Query options.
 * @return array
 */
function mcp_abilities_elementor_query_templates( array $options = array() ): array {
	$type        = isset( $options['type'] ) && is_string( $options['type'] ) ? sanitize_key( $options['type'] ) : 'all';
	$post_status = isset( $options['post_status'] ) && is_string( $options['post_status'] ) ? sanitize_key( $options['post_status'] ) : 'publish';
	$limit       = isset( $options['limit'] ) ? max( 1, min( 100, (int) $options['limit'] ) ) : 100;
	$sub_type    = isset( $options['sub_type'] ) && is_string( $options['sub_type'] ) ? sanitize_text_field( $options['sub_type'] ) : '';
	$sub_type_like = ! empty( $options['sub_type_like'] );

	$allowed_types = array( 'all', 'page', 'section', 'container', 'loop-item', 'header', 'footer', 'single', 'archive', 'popup' );
	if ( ! in_array( $type, $allowed_types, true ) ) {
		return array(
			'success' => false,
			'message' => 'Invalid template type: ' . $type,
		);
	}

	$args = array(
		'post_type'      => 'elementor_library',
		'post_status'    => $post_status,
		'posts_per_page' => $limit,
		'orderby'        => 'title',
		'order'          => 'ASC',
	);

	if ( 'all' !== $type ) {
		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Elementor template type filtering.
			array(
				'taxonomy' => 'elementor_library_type',
				'field'    => 'slug',
				'terms'    => $type,
			),
		);
	}

	if ( '' !== $sub_type ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Optional Elementor template subtype filtering.
			array(
				'key'     => '_elementor_template_sub_type',
				'value'   => $sub_type,
				'compare' => $sub_type_like ? 'LIKE' : '=',
			),
		);
	}

	$query     = new WP_Query( $args );
	$templates = array();

	foreach ( $query->posts as $template ) {
		$template_type     = get_post_meta( $template->ID, '_elementor_template_type', true );
		$template_sub_type = get_post_meta( $template->ID, '_elementor_template_sub_type', true );
		$templates[]       = array(
			'id'       => (int) $template->ID,
			'title'    => (string) $template->post_title,
			'type'     => $template_type ?: 'unknown',
			'sub_type' => $template_sub_type ?: '',
			'date'     => (string) $template->post_date,
			'modified' => (string) $template->post_modified,
		);
	}

	return array(
		'success'   => true,
		'templates' => $templates,
		'total'     => count( $templates ),
	);
}
