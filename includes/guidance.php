<?php
/**
 * Elementor guidance catalog helpers.
 *
 * @package MCP_Abilities_Elementor
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the official Elementor guidance catalog used by design audits.
 *
 * Pattern and widget recommendations should be grounded in Elementor's own
 * documentation first. Site-local payloads are only fallback implementation
 * references when a recommendation has already been chosen from official docs.
 *
 * @return array
 */
function mcp_abilities_elementor_get_official_guidance_catalog(): array {
	return array(
		'policy'  => array(
			'pattern_source_of_truth'      => 'official_elementor_docs_first',
			'implementation_fallback_only' => 'site_local_payloads_after_pattern_choice',
			'template_reuse_policy'        => 'saved_templates_before_raw_authoring',
			'description'                  => 'Use Elementor.com as the canonical source for widget/layout pattern recommendations. Inspect local Elementor payloads only after the official pattern choice is clear, and only to satisfy serialization or implementation details. When a repeated pattern exists as a saved Elementor template on the current site, reuse that template before hand-building raw containers/widgets. When a reusable pattern is identified and no suitable template exists, create a saved Elementor template before applying the pattern repeatedly.',
		),
		'layout'  => array(
			'grid_for_symmetric_columns' => array(
				'label' => 'Grid for equal symmetric columns',
				'url'   => 'https://elementor.com/help/create-a-grid-container/',
			),
			'grid_vs_flex' => array(
				'label' => 'Grid vs Flex layout options',
				'url'   => 'https://elementor.com/help/grid-container-layout-options/',
			),
			'background_slideshow' => array(
				'label' => 'Background slideshow',
				'url'   => 'https://elementor.com/help/background-slideshow/',
			),
		),
		'widgets' => array(
			'slides' => array(
				'label' => 'Slides widget',
				'url'   => 'https://elementor.com/widgets/pro/slides-widget/',
				'native_authoring_note' => 'Use the Elementor Pro Slides widget when the design needs a full-height slide surface whose images behave as cover backgrounds. It is a better fit for split-panel image surfaces beside text panels than Media Carousel, because the image is the slide background rather than a media item floating inside a carousel frame.',
				'height_control_note' => 'On current Elementor Pro builds the native height control is `slides_height`. It supports px, em, rem, vh, and custom units; percent is not an ordinary supported unit. Match sibling panels with concrete native height/min-height controls instead of CSS.',
			),
			'media_carousel' => array(
				'label' => 'Media Carousel widget',
				'url'   => 'https://elementor.com/widgets/',
				'native_authoring_note' => 'Use Media Carousel for actual image/video galleries, product media collections, portfolios, and visual story strips where the media items are the content. Do not use it as a layout surface when the design calls for one full-height background-like image panel beside a text panel.',
			),
			'accordion' => array(
				'label' => 'Accordion widget',
				'url'   => 'https://elementor.com/help/accordion-widget/',
			),
			'tabs' => array(
				'label' => 'Tabs widget with nested containers',
				'url'   => 'https://elementor.com/help/tabs-with-nested-containers/',
			),
			'call_to_action' => array(
				'label' => 'Call to Action widget',
				'url'   => 'https://elementor.com/help/call-to-action-widget/',
				'native_authoring_note' => 'Use Call to Action for repeated promo modules that combine media, title, copy, and a button/action. It is usually more maintainable than rebuilding the same card from separate Image, Heading, Text Editor, and Button widgets.',
			),
			'image_box' => array(
				'label' => 'Image Box widget',
				'url'   => 'https://elementor.com/help/image-box-widget/',
				'native_authoring_note' => 'Use Image Box for static image cards where each item has one image, title, and short text. It keeps the content editable as one widget and avoids fragile hand-built card groups.',
			),
			'posts' => array(
				'label' => 'Posts widget',
				'url'   => 'https://elementor.com/help/posts-widget-pro/',
				'native_authoring_note' => 'Use Posts for dynamic blog/reference/product-related grids when WordPress posts, taxonomy queries, pagination, and translated content should drive the cards.',
			),
			'loop_grid' => array(
				'label' => 'Loop Grid widget',
				'url'   => 'https://elementor.com/help/loop-grid/',
				'native_authoring_note' => 'Use Loop Grid when a dynamic listing needs a custom repeated card layout that the Posts widget cannot express cleanly.',
			),
			'gallery' => array(
				'label' => 'Gallery widget',
				'url'   => 'https://elementor.com/help/gallery-widget/',
				'native_authoring_note' => 'Use Gallery for a static or curated set of images shown as an image collection. Do not use it for dynamic post cards or split-panel cover surfaces.',
			),
			'icon_list' => array(
				'label' => 'Icon List widget',
				'url'   => 'https://elementor.com/help/icon-list-widget/',
			),
			'social_icons' => array(
				'label' => 'Social Icons widget',
				'url'   => 'https://elementor.com/help/?p=83',
				'native_authoring_note' => 'Use the Social Icons widget for linked social profiles in headers, footers, and top bars. It provides native alignment, size, padding, spacing, and row-gap controls, and Elementor renders the icons as centered inline-flex items. Do not recreate this pattern with separate Icon widgets unless the design truly needs independent non-social icons.',
				'global_style_policy_note' => 'The `icon_color` setting is a mode selector, not a color value. `icon_color:"custom"` is allowed only when concrete color controls such as `icon_primary_color` and `icon_secondary_color` are normalized to Elementor Kit global color tokens.',
			),
			'nav_menu' => array(
				'label' => 'WordPress Menu / legacy Nav Menu widget',
				'url'   => 'https://elementor.com/help/nav-menu-widget-pro/',
				'native_authoring_note' => 'Use the WordPress Menu/Nav Menu widget when an existing WordPress menu should be rendered with simple native dropdown styling. It supports dropdown text/background colors, typography, border, box shadow, item padding, dividers, and dropdown distance.',
				'limitation_note' => 'The legacy Nav Menu widget does not expose native desktop submenu box width or dropdown line-height controls. If exact dropdown box sizing or line-height parity is required, prefer the newer Elementor Menu widget (`mega-menu`) instead of adding CSS patches.',
			),
			'menu' => array(
				'label' => 'Elementor Menu widget',
				'url'   => 'https://elementor.com/help/the-menu-widget/',
				'native_authoring_note' => 'Use the newer Elementor Menu widget (`mega-menu` in Elementor data) when a header needs richer native dropdown control such as Open On hover/click, Fit To Content dropdowns, dropdown box/content styling, and nested container content.',
				'data_shape_note' => 'The Menu widget stores top-level entries in `menu_items` and expects one child container per top-level menu item index. Dropdown content for item N belongs in child container N, even when some items have no dropdown content.',
			),
		),
		'patterns' => array(
			'split_panel_carousel_surface' => array(
				'label' => 'Split-panel carousel image surface',
				'recommended_widget' => 'slides',
				'avoid_widget' => 'media-carousel',
				'official_widget_url' => 'https://elementor.com/widgets/pro/slides-widget/',
				'control_probe' => array(
					'ability' => 'elementor/get-widget-controls',
					'params'  => array(
						'widget_type' => 'slides',
						'search'      => 'height',
					),
				),
				'when_to_use' => 'Use for a rotating image panel in a 50/50 or similar split row where the sibling is a dark text/content panel and the image must fill the row height edge-to-edge.',
				'native_controls' => array(
					'Set the parent row/container height or min-height as the visual source of truth.',
					'Use the Slides widget `slides_height` control for the slide surface.',
					'Use slide background images with background size cover and centered positioning.',
					'Keep slide heading/description/button empty when text belongs in the sibling content panel.',
					'Use native navigation/autoplay controls only; do not repair white gaps with widget/page custom CSS.',
					'Before raw authoring, call `elementor/find-template-for-pattern` and reuse a matching saved Elementor template when available.',
					'If this becomes a repeated site pattern and no matching template exists, create a saved Elementor template before applying it to more pages.',
				),
				'why_not_media_carousel' => 'Media Carousel is documented as a media gallery/carousel widget and renders media items inside a carousel frame. In split-panel surface layouts that makes height, crop, and blank-space parity less reliable than a Slides background surface.',
			),
			'static_split_panel_image_surface' => array(
				'label' => 'Static split-panel image surface',
				'recommended_widget' => 'container_background_image',
				'official_layout_url' => 'https://elementor.com/help/background-slideshow/',
				'when_to_use' => 'Use for a non-rotating image panel beside a text/content panel when the image should behave as a cover surface rather than inline image content.',
				'native_controls' => array(
					'Apply the image as a native container background image.',
					'Set background size to cover, repeat to no-repeat, and position to the visually correct focal point.',
					'Set the container min-height to match the sibling panel or the live design rhythm.',
					'Use an empty Spacer widget only when Elementor needs a child element to keep the background container renderable/editable.',
					'Do not use CSS or an inline Image widget when the goal is full-height cover behavior.',
					'Before raw authoring, call `elementor/find-template-for-pattern` and reuse a matching saved Elementor template when available.',
					'If this becomes a repeated site pattern and no matching template exists, create a saved Elementor template before applying it to more pages.',
				),
				'why_use_background_image' => 'A native container background image fills its container like a design surface and avoids inline image aspect-ratio gaps in split rows.',
			),
			'actual_media_gallery_carousel' => array(
				'label' => 'Actual media gallery carousel',
				'recommended_widget' => 'media-carousel',
				'official_widget_url' => 'https://elementor.com/widgets/',
				'when_to_use' => 'Use when the visitor is meant to browse a set of images/videos as gallery content, such as portfolio media, product options, or visual story collections.',
				'native_controls' => array(
					'Use Media Carousel skin, slides-per-view, navigation, autoplay, and height controls as appropriate.',
					'Keep it as a content widget, not a replacement for a split-row background image surface.',
					'Before raw authoring repeated carousel/text rows, call `elementor/find-template-for-pattern` and reuse a matching saved Elementor template when available.',
					'If this becomes a repeated site pattern and no matching template exists, create a saved Elementor template before applying it to more pages.',
				),
			),
			'dynamic_related_or_archive_cards' => array(
				'label' => 'Dynamic related/archive card list',
				'recommended_widget' => 'posts_or_loop_grid',
				'official_widget_urls' => array(
					'https://elementor.com/help/posts-widget-pro/',
					'https://elementor.com/help/loop-grid/',
				),
				'when_to_use' => 'Use for blog, reference, inspiration, product-related, or archive-like card lists where WordPress posts and taxonomies are the source of truth.',
				'native_controls' => array(
					'Use Posts when the built-in card structure and query controls are sufficient.',
					'Use Loop Grid when the card layout must preserve a custom design while remaining dynamic.',
					'Set the correct taxonomy, language/WPML context, posts per page, ordering, pagination/load-more, and image ratio controls.',
					'Do not keep manual/fake repeated cards when published post data can render the list dynamically.',
					'For custom repeated card layouts, reuse an existing Loop Item/template first; if none exists, create one before repeating the layout.',
				),
				'why_not_manual_cards' => 'Manual card lists drift from published content, translations, pagination, and future edits. They also create duplicate maintenance work.',
			),
			'static_image_card_grid' => array(
				'label' => 'Static image-card grid',
				'recommended_widget' => 'image-box',
				'official_widget_url' => 'https://elementor.com/help/image-box-widget/',
				'when_to_use' => 'Use for a small fixed set of cards where each item is not backed by a WordPress post/query and only needs image, title, and short text.',
				'native_controls' => array(
					'Use one Image Box per card.',
					'Use native container/grid controls for columns, width, gap, and responsive stacking.',
					'Use Image Box image spacing and typography/global controls rather than custom CSS.',
					'If the card grid becomes a repeated site pattern, save it as an Elementor template before applying it across pages.',
				),
				'why_not_hand_built_groups' => 'Separate Image + Heading + Text Editor groups are harder for clients to maintain and often produce uneven spacing across breakpoints.',
			),
			'curated_image_gallery' => array(
				'label' => 'Curated image gallery',
				'recommended_widget' => 'gallery',
				'official_widget_url' => 'https://elementor.com/help/gallery-widget/',
				'when_to_use' => 'Use when the content is a curated image set, not a post list, not a split-panel surface, and not an image/video carousel story.',
				'native_controls' => array(
					'Use Gallery layout, columns, spacing, image size, and lightbox controls.',
					'Use Media Carousel only when the desired experience is carousel browsing rather than a grid/gallery.',
					'Reuse a saved gallery-section template when the site already has one for the same pattern.',
				),
			),
			'repeated_promo_or_cta_modules' => array(
				'label' => 'Repeated promo or CTA modules',
				'recommended_widget' => 'call-to-action',
				'official_widget_url' => 'https://elementor.com/help/call-to-action-widget/',
				'when_to_use' => 'Use when repeated modules combine an image/media area, heading, copy, and a button/action.',
				'native_controls' => array(
					'Use Call to Action widgets for each module when the widget can express the design.',
					'Use native style controls for image, content, button, hover, and spacing.',
					'Only use raw containers when the module structure cannot be represented by a native widget without harming editability or parity.',
					'If the CTA module pattern repeats across pages, create or reuse an Elementor template instead of rebuilding the same module by hand.',
				),
			),
			'saved_template_reuse' => array(
				'label' => 'Saved template reuse before raw authoring',
				'recommended_ability' => 'elementor/find-template-for-pattern',
				'when_to_use' => 'Use before manually creating a repeated layout pattern from raw containers or widgets, especially split media/text rows, related-card lists, static card grids, gallery sections, and CTA modules.',
				'native_controls' => array(
					'Search the current site Elementor Library for the intended pattern first.',
					'Prefer importing/reusing a matching saved template and editing its native widget settings over reconstructing the pattern manually.',
					'If no matching template exists and the pattern will be repeated, create a reusable Elementor template before applying the pattern to multiple pages.',
					'Do not hardcode site-specific template IDs in this public plugin. Template selection must come from the current site library.',
				),
				'why_template_first' => 'Saved templates preserve proven Elementor structure, spacing, responsive controls, and widget choices. Reusing them reduces drift and keeps later client edits inside Elementor.',
			),
		),
	);
}

/**
 * Return which design topics are grounded in official Elementor docs versus
 * internal plugin heuristics.
 *
 * @return array
 */
function mcp_abilities_elementor_get_design_guidance_basis(): array {
	return array(
		'official_elementor_topics' => array(
			'layout_mechanism_fit',
			'native_widget_opportunities',
			'split_panel_carousel_surface_widget_fit',
			'static_split_panel_background_image_fit',
			'dynamic_listing_widget_fit',
			'static_card_widget_fit',
			'gallery_widget_fit',
			'promo_module_widget_fit',
			'saved_template_reuse',
		),
		'plugin_heuristic_topics'   => array(
			'generic_layout',
			'distinctiveness',
			'component_overuse',
			'surface_overuse',
			'emphasis_drift',
			'section_rivalry',
			'composition_rhythm',
			'separator_discipline',
			'column_patterns',
			'column_dominance',
			'column_alignment',
			'column_balance',
			'column_necessity',
		),
		'description'               => 'Official Elementor.com guidance should drive widget/layout pattern choice where Elementor explicitly documents the mechanism or widget fit. The remaining design audits are plugin heuristics intended to review composition, pacing, emphasis, and repetition without pretending they are official Elementor rules.',
	);
}

/**
 * Normalize a widget name into a stable slug-like token.
 *
 * @param string $name Widget name.
 * @return string
 */
function mcp_abilities_elementor_normalize_widget_catalog_slug( string $name ): string {
	$slug = strtolower( trim( $name ) );
	$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug ) ?? $slug;
	$slug = trim( $slug, '-' );

	return $slug;
}

/**
 * Fetch the official Elementor widget catalog from elementor.com/widgets.
 *
 * This provides the canonical availability surface for Elementor widgets.
 * Per-widget help pages can still be layered on later, but the catalog itself
 * should come from Elementor's official widgets index rather than from our own
 * sites or a hand-maintained shortlist.
 *
 * @param bool $force_refresh Whether to bypass transient cache.
 * @return array|\WP_Error
 */
function mcp_abilities_elementor_fetch_official_widget_catalog( bool $force_refresh = false ) {
	$transient_key = 'mcp_elem_official_widget_catalog_v1';

	if ( ! $force_refresh ) {
		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) && ! empty( $cached['categories'] ) ) {
			return $cached;
		}
	}

	$response = wp_remote_get(
		'https://elementor.com/widgets',
		array(
			'timeout'     => 20,
			'redirection' => 5,
			'headers'     => array(
				'Accept' => 'text/html',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'widget_catalog_fetch_failed', 'Failed to fetch official Elementor widget catalog' );
	}

	$html = (string) wp_remote_retrieve_body( $response );
	if ( '' === trim( $html ) ) {
		return new WP_Error( 'widget_catalog_empty', 'Official Elementor widget catalog response was empty' );
	}

	$recognized_h2s = array(
		'Basic Widgets'       => 'basic',
		'Pro Widgets'         => 'pro',
		'Theme Elements'      => 'theme',
		'WooCommerce Widgets' => 'woocommerce',
	);
	$category_labels = array(
		'basic'       => 'Basic Widgets',
		'pro'         => 'Pro Widgets',
		'theme'       => 'Theme Elements',
		'woocommerce' => 'WooCommerce Widgets',
	);
	$categories = array(
		'basic'       => array(),
		'pro'         => array(),
		'theme'       => array(),
		'woocommerce' => array(),
	);

	if ( class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' ) ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return new WP_Error( 'widget_catalog_parse_failed', 'Failed to parse official Elementor widget catalog HTML' );
		}

		$xpath            = new DOMXPath( $dom );
		$current_category = '';
		$nodes            = $xpath->query( '//h2 | //h3' );
		if ( false === $nodes ) {
			return new WP_Error( 'widget_catalog_xpath_failed', 'Failed to inspect official Elementor widget catalog structure' );
		}

		foreach ( $nodes as $node ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) ?? '' );
			if ( '' === $text ) {
				continue;
			}

			if ( 'h2' === strtolower( $node->nodeName ) ) {
				$current_category = $recognized_h2s[ $text ] ?? '';
				continue;
			}

			if ( '' === $current_category || 'h3' !== strtolower( $node->nodeName ) ) {
				continue;
			}

			$categories[ $current_category ][ $text ] = array(
				'name'               => $text,
				'slug'               => mcp_abilities_elementor_normalize_widget_catalog_slug( $text ),
				'category'           => $current_category,
				'category_label'     => $category_labels[ $current_category ],
				'catalog_source_url' => 'https://elementor.com/widgets',
			);
		}
	} else {
		$heading_order = array_keys( $recognized_h2s );
		foreach ( $heading_order as $index => $heading_label ) {
			$current_category = $recognized_h2s[ $heading_label ];
			$pattern          = '#<h2[^>]*>\s*' . preg_quote( $heading_label, '#' ) . '\s*</h2>(.*?)(?=<h2[^>]*>|$)#si';

			if ( ! preg_match( $pattern, $html, $matches ) ) {
				continue;
			}

			$section_html = (string) ( $matches[1] ?? '' );
			if ( '' === $section_html ) {
				continue;
			}

			if ( preg_match_all( '#<h3[^>]*>(.*?)</h3>#si', $section_html, $heading_matches ) ) {
				foreach ( (array) ( $heading_matches[1] ?? array() ) as $raw_widget_name ) {
					$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $raw_widget_name ) ) ?? '' );
					if ( '' === $text ) {
						continue;
					}

					$categories[ $current_category ][ $text ] = array(
						'name'               => $text,
						'slug'               => mcp_abilities_elementor_normalize_widget_catalog_slug( $text ),
						'category'           => $current_category,
						'category_label'     => $category_labels[ $current_category ],
						'catalog_source_url' => 'https://elementor.com/widgets',
					);
				}
			}
		}
	}

	$normalized_categories = array();
	$total_widgets         = 0;
	foreach ( $categories as $key => $widgets ) {
		$normalized_categories[ $key ] = array_values( $widgets );
		$total_widgets += count( $normalized_categories[ $key ] );
	}

	$result = array(
		'source_policy'     => mcp_abilities_elementor_get_official_guidance_catalog()['policy'],
		'catalog_source_url'=> 'https://elementor.com/widgets',
		'fetched_at'        => gmdate( 'c' ),
		'total_widgets'     => $total_widgets,
		'categories'        => $normalized_categories,
	);

	set_transient( $transient_key, $result, 12 * HOUR_IN_SECONDS );

	return $result;
}
