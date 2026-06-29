<?php
/**
 * WebMCP bridge (experimental, opt-in).
 *
 * Registers the site's READ-ONLY tools with in-browser AI agents through the
 * WebMCP browser API (`navigator.modelContext`), a W3C Web Machine Learning CG
 * draft shipping behind a flag in Chrome/Edge. It is the client-side companion
 * to the server-side discovery this plugin already publishes (mcp.json,
 * agent-skills): same capabilities, exposed where a *browsing* agent can call
 * them live.
 *
 * Design constraints (so the plugin's "no front-end footprint" promise holds):
 *   - OFF by default; only loads when the owner opts in AND tools exist.
 *   - The script is INERT in any browser without `navigator.modelContext`
 *     (≈every browser today), so human visitors see and pay nothing.
 *   - Anonymous visitors get READ-ONLY tools only. `execute()` runs in the
 *     visitor's own session, so a write tool would act as whoever is logged in.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class WebMcp {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the front-end enqueue — only when the feature is enabled, so a default
	 * install adds nothing to `wp_enqueue_scripts` at all.
	 */
	public function register() {
		if ( ! $this->settings->enabled( 'enable_webmcp' ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the tiny bridge script and hand it the tool manifest. Bails when
	 * there are no tools to expose (e.g. every tool was filtered away).
	 */
	public function enqueue() {
		if ( is_admin() ) {
			return;
		}
		$tools = $this->tools();
		if ( empty( $tools ) ) {
			return;
		}

		wp_enqueue_script(
			'agentimus-webmcp',
			AGENTIMUS_URL . 'assets/webmcp.js',
			array(),
			AGENTIMUS_VERSION,
			true // In the footer; this is agent infrastructure, never render-blocking.
		);
		wp_localize_script( 'agentimus-webmcp', 'AgentimusWebMCP', array( 'tools' => $tools ) );
	}

	/**
	 * The tools registered with the browsing agent. Ships one genuinely useful,
	 * always-callable READ-ONLY tool — site search over the core REST API — and
	 * exposes a filter so a companion plugin can add its own callable tools.
	 *
	 * Every tool: name, description, inputSchema (JSON Schema), endpoint (URL),
	 * method (GET|POST). For anonymous visitors keep them READ-ONLY (see the
	 * class doc) — a write tool would act as the logged-in user.
	 *
	 * @return array<int,array>
	 */
	private function tools() {
		$tools = array(
			array(
				'name'        => 'search_site',
				/* translators: %s: site name. */
				'description' => sprintf( 'Search %s and return matching posts and pages.', get_bloginfo( 'name' ) ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'The words to search the site for.',
						),
					),
					'required'   => array( 'search' ),
				),
				'endpoint'    => rest_url( 'wp/v2/search' ),
				'method'      => 'GET',
			),
		);

		/**
		 * Filter the WebMCP tools registered with in-browser agents.
		 *
		 * Each entry needs: name, description, inputSchema, endpoint, method.
		 * IMPORTANT: only expose READ-ONLY tools to anonymous visitors —
		 * `execute()` runs in the visitor's browser session.
		 *
		 * @param array<int,array> $tools    Tool definitions.
		 * @param Settings         $settings Settings store.
		 */
		$tools = apply_filters( 'agentimus_webmcp_tools', $tools, $this->settings );

		if ( ! is_array( $tools ) ) {
			return array();
		}

		// Drop anything malformed so a bad provider entry can never reach the page.
		return array_values(
			array_filter(
				$tools,
				static function ( $tool ) {
					return is_array( $tool ) && ! empty( $tool['name'] ) && ! empty( $tool['endpoint'] );
				}
			)
		);
	}
}
