<?php
/**
 * Content registry — the single source of truth for *which* content is
 * agent-visible and *how* its body is sourced. This is the seam that lets the
 * plugin cover any site: WooCommerce products, custom post types, and
 * page-builder content all flow through here via settings + filters.
 *
 * @package Agentify
 */

namespace Agentify;

defined( 'ABSPATH' ) || exit;

final class Content {

	/**
	 * Public post types that are candidates for inclusion (minus attachments).
	 *
	 * @return string[]
	 */
	public static function available() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * The post types Agentify actually exposes: the configured selection
	 * (intersected with what's available), falling back to everything public,
	 * then filtered so an add-on can add or remove types programmatically.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$available  = self::available();
		$configured = (array) ( new Settings() )->get( 'post_types', array() );

		$types = array_values( array_intersect( $configured, $available ) );
		if ( empty( $types ) ) {
			$types = $available;
		}

		/**
		 * Filter the agent-visible post types.
		 *
		 * @param string[] $types     Resolved post types.
		 * @param string[] $available All public post types.
		 */
		$types = (array) apply_filters( 'agentify_post_types', $types, $available );
		return array_values( array_unique( array_filter( $types ) ) );
	}

	/**
	 * Post types ordered for the index: pages first, posts second, the rest
	 * alphabetically — so the document reads predictably on any site.
	 *
	 * @return string[]
	 */
	public static function index_sections() {
		$types = self::post_types();
		usort(
			$types,
			static function ( $a, $b ) {
				$rank = static function ( $t ) {
					return 'page' === $t ? 0 : ( 'post' === $t ? 1 : 2 );
				};
				$ra = $rank( $a );
				$rb = $rank( $b );
				return $ra === $rb ? strcmp( $a, $b ) : $ra - $rb;
			}
		);
		return $types;
	}

	/**
	 * The plural label for a post type's section heading.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function label( $post_type ) {
		$obj = get_post_type_object( $post_type );
		if ( $obj && isset( $obj->labels->name ) && '' !== $obj->labels->name ) {
			return $obj->labels->name;
		}
		return ucfirst( $post_type );
	}

	/**
	 * A human source hint for a post type, to disambiguate collisions (e.g.
	 * WooCommerce and Fluent Cart both label theirs "Products"). Known commerce/
	 * LMS types map to their plugin; everything else returns '' (the slug already
	 * disambiguates). Extensible via the filter.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function source( $post_type ) {
		$map = array(
			'product'           => 'WooCommerce',
			'product_variation' => 'WooCommerce',
			'fluent-products'   => 'Fluent Cart',
			'download'          => 'Easy Digital Downloads',
			'sfwd-courses'      => 'LearnDash',
		);
		$source = isset( $map[ $post_type ] ) ? $map[ $post_type ] : '';

		/**
		 * Filter the source label shown next to a post type.
		 *
		 * @param string $source    Source plugin name, or ''.
		 * @param string $post_type Post type slug.
		 */
		return (string) apply_filters( 'agentify_post_type_source', $source, $post_type );
	}

	/**
	 * Published items of a post type, newest first (pages by menu order).
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $limit     Max items.
	 * @return \WP_Post[]
	 */
	public static function query( $post_type, $limit ) {
		$limit = $limit > 0 ? $limit : 50;

		if ( 'page' === $post_type ) {
			return (array) get_pages(
				array(
					'sort_column' => 'menu_order,post_title',
					'number'      => $limit,
				)
			);
		}

		return (array) get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Resolve the HTML body for a post. Add-ons (page builders, custom
	 * renderers) can short-circuit with `agentify_markdown_source`;
	 * otherwise we run the standard `the_content` filter.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function markdown_source( $post ) {
		/**
		 * Filter the HTML source for a post's markdown rendering. Return a
		 * string to override; return null to use post_content.
		 *
		 * @param string|null $html Override HTML, or null.
		 * @param \WP_Post     $post Post.
		 */
		$html = apply_filters( 'agentify_markdown_source', null, $post );
		if ( null === $html ) {
			$html = apply_filters( 'the_content', $post->post_content );
		}
		return (string) $html;
	}

	/**
	 * Whether a post has a renderable body (so template-only / builder-empty
	 * items don't become title-only stubs in the full-text edition).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function has_body( $post ) {
		if ( '' !== trim( (string) $post->post_content ) ) {
			return true;
		}
		return null !== apply_filters( 'agentify_markdown_source', null, $post );
	}
}
