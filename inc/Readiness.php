<?php
/**
 * Readiness report — a list of pass/warn/fail checks that the admin "Readiness"
 * panel renders. Each check is cheap and side-effect free.
 *
 * @package Agentify
 */

namespace Agentify;

defined( 'ABSPATH' ) || exit;

final class Readiness {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run the checks.
	 *
	 * @return array<int,array<string,string>> List of { id, label, status, detail }.
	 */
	public function report() {
		$checks = array(
			$this->check_site_public(),
			$this->check_permalinks(),
			$this->check_llms_txt(),
			$this->check_llms_full(),
			$this->check_post_types(),
			$this->check_about(),
			$this->check_expertise(),
			$this->check_same_as(),
			$this->check_schema_conflict(),
			$this->check_static_robots(),
			$this->check_sitemap(),
		);

		/**
		 * Filter the readiness checks (a Pro add-on can append its own).
		 *
		 * @param array    $checks   Check rows.
		 * @param Settings $settings Settings store.
		 */
		return apply_filters( 'agentify_readiness_checks', $checks, $this->settings );
	}

	/**
	 * Build one check row.
	 *
	 * @param string $id     Identifier.
	 * @param string $label  Human label.
	 * @param string $status pass|warn|fail.
	 * @param string $detail Explanation.
	 * @return array
	 */
	private function row( $id, $label, $status, $detail ) {
		return compact( 'id', 'label', 'status', 'detail' );
	}

	private function check_site_public() {
		$public = (bool) get_option( 'blog_public', 1 );
		return $public
			? $this->row( 'public', __( 'Search engine visibility', 'agentify' ), 'pass', __( 'The site is public, so agents and crawlers can read it.', 'agentify' ) )
			: $this->row( 'public', __( 'Search engine visibility', 'agentify' ), 'fail', __( 'Settings → Reading is set to discourage search engines. Agents will be blocked.', 'agentify' ) );
	}

	private function check_permalinks() {
		$pretty = (bool) get_option( 'permalink_structure' );
		return $pretty
			? $this->row( 'permalinks', __( 'Pretty permalinks', 'agentify' ), 'pass', __( 'Markdown URLs like /post-slug.md resolve cleanly.', 'agentify' ) )
			: $this->row( 'permalinks', __( 'Pretty permalinks', 'agentify' ), 'warn', __( 'Plain permalinks are active. Switch to a pretty structure for tidy .md URLs.', 'agentify' ) );
	}

	private function check_llms_txt() {
		return $this->settings->enabled( 'enable_llms_txt' )
			? $this->row( 'llms', __( '/llms.txt index', 'agentify' ), 'pass', sprintf( /* translators: %s: URL. */ __( 'Served at %s.', 'agentify' ), home_url( '/llms.txt' ) ) )
			: $this->row( 'llms', __( '/llms.txt index', 'agentify' ), 'warn', __( 'Disabled. Enable it so agents can discover your content map.', 'agentify' ) );
	}

	private function check_llms_full() {
		return $this->settings->enabled( 'enable_llms_full' )
			? $this->row( 'llms_full', __( '/llms-full.txt full text', 'agentify' ), 'pass', sprintf( /* translators: %s: URL. */ __( 'Served at %s.', 'agentify' ), home_url( '/llms-full.txt' ) ) )
			: $this->row( 'llms_full', __( '/llms-full.txt full text', 'agentify' ), 'warn', __( 'Disabled. The full-text edition lets agents ingest everything in one request.', 'agentify' ) );
	}

	private function check_post_types() {
		$types  = Content::post_types();
		$labels = array_map( array( Content::class, 'label' ), $types );
		return $this->row(
			'post_types',
			__( 'Content coverage', 'agentify' ),
			'pass',
			sprintf(
				/* translators: 1: number of content types, 2: comma-separated list. */
				__( 'Indexing %1$d content type(s): %2$s.', 'agentify' ),
				count( $types ),
				implode( ', ', $labels )
			)
		);
	}

	private function check_about() {
		return '' !== trim( (string) $this->settings->identity( 'about' ) )
			? $this->row( 'about', __( 'Author / entity profile', 'agentify' ), 'pass', __( 'A profile sentence is set — the highest-signal line for retrieval.', 'agentify' ) )
			: $this->row( 'about', __( 'Author / entity profile', 'agentify' ), 'fail', __( 'No profile set. Add one under Identity so agents know who owns this site.', 'agentify' ) );
	}

	private function check_expertise() {
		return ! empty( array_filter( (array) $this->settings->identity( 'expertise', array() ) ) )
			? $this->row( 'expertise', __( 'Expertise topics', 'agentify' ), 'pass', __( 'Expertise is declared (feeds llms.txt and knowsAbout).', 'agentify' ) )
			: $this->row( 'expertise', __( 'Expertise topics', 'agentify' ), 'warn', __( 'No expertise topics set. They establish topical authority for agents.', 'agentify' ) );
	}

	private function check_same_as() {
		return ! empty( array_filter( (array) $this->settings->identity( 'same_as', array() ) ) )
			? $this->row( 'same_as', __( 'sameAs profiles', 'agentify' ), 'pass', __( 'Linked profiles help agents resolve your entity confidently.', 'agentify' ) )
			: $this->row( 'same_as', __( 'sameAs profiles', 'agentify' ), 'warn', __( 'No sameAs links (GitHub, LinkedIn, X…). Add them to strengthen entity matching.', 'agentify' ) );
	}

	private function check_schema_conflict() {
		$schema = new Schema( $this->settings );
		if ( ! $this->settings->enabled( 'enable_schema' ) ) {
			return $this->row( 'schema', __( 'JSON-LD structured data', 'agentify' ), 'warn', __( 'Schema output is disabled in settings.', 'agentify' ) );
		}
		return $schema->seo_plugin_active()
			? $this->row( 'schema', __( 'JSON-LD structured data', 'agentify' ), 'pass', __( 'An SEO plugin owns schema; Agentify is standing down to avoid duplicates.', 'agentify' ) )
			: $this->row( 'schema', __( 'JSON-LD structured data', 'agentify' ), 'pass', __( 'Agentify is emitting WebSite + entity + article schema.', 'agentify' ) );
	}

	private function check_static_robots() {
		$file = ABSPATH . 'robots.txt';
		return file_exists( $file )
			? $this->row( 'robots', __( 'robots.txt control', 'agentify' ), 'warn', __( 'A static robots.txt exists at the web root and overrides this plugin. Remove it to let Agentify manage robots rules, or edit it by hand.', 'agentify' ) )
			: $this->row( 'robots', __( 'robots.txt control', 'agentify' ), 'pass', __( 'WordPress serves a virtual robots.txt that this plugin manages.', 'agentify' ) );
	}

	private function check_sitemap() {
		$has = function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server() && wp_sitemaps_get_server()->sitemaps_enabled();
		return $has
			? $this->row( 'sitemap', __( 'XML sitemap', 'agentify' ), 'pass', __( 'A sitemap is available and advertised in robots.txt and llms.txt.', 'agentify' ) )
			: $this->row( 'sitemap', __( 'XML sitemap', 'agentify' ), 'warn', __( 'No core sitemap detected. An SEO plugin sitemap will still be linked if present.', 'agentify' ) );
	}
}
