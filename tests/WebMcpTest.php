<?php
/**
 * WebMcp — the opt-in, experimental browser-tool bridge.
 *
 * The unit under test is the tool MANIFEST: it must ship one genuinely callable
 * read-only tool (site search over the core REST API), let a companion plugin
 * extend it via the `agentimus_webmcp_tools` filter, and silently drop anything
 * malformed so a bad provider entry can never reach the page. Also locks the
 * default-OFF guarantee — a fresh install adds no front-end script.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\WebMcp;
use PHPUnit\Framework\TestCase;

final class WebMcpTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Invoke the private manifest builder. */
	private function tools(): array {
		$method = new \ReflectionMethod( WebMcp::class, 'tools' );
		$method->setAccessible( true );
		return (array) $method->invoke( new WebMcp( new Settings() ) );
	}

	/** Find a tool by name in a manifest. */
	private function find( array $tools, string $name ) {
		foreach ( $tools as $tool ) {
			if ( isset( $tool['name'] ) && $name === $tool['name'] ) {
				return $tool;
			}
		}
		return null;
	}

	/* -- The default tool ------------------------------------------------- */

	public function test_ships_a_callable_read_only_site_search_tool() {
		$search = $this->find( $this->tools(), 'search_site' );

		$this->assertIsArray( $search, 'A default install must expose site search.' );
		$this->assertSame( 'GET', $search['method'], 'Search must be a read-only GET.' );
		$this->assertStringContainsString( 'wp/v2/search', $search['endpoint'], 'Search must hit the real core REST endpoint, not a dead URL.' );
		$this->assertSame( array( 'search' ), $search['inputSchema']['required'] );
		$this->assertArrayHasKey( 'search', $search['inputSchema']['properties'] );
	}

	/* -- Provider extension via the filter -------------------------------- */

	public function test_filter_can_add_a_provider_tool() {
		add_filter(
			'agentimus_webmcp_tools',
			static function ( $tools ) {
				$tools[] = array(
					'name'        => 'check_availability',
					'description' => 'List open slots.',
					'inputSchema' => array( 'type' => 'object', 'properties' => array() ),
					'endpoint'    => 'https://example.test/wp-json/orbit/v1/availability',
					'method'      => 'GET',
				);
				return $tools;
			}
		);

		$this->assertNotNull( $this->find( $this->tools(), 'check_availability' ) );
	}

	public function test_malformed_filter_entries_are_dropped() {
		add_filter(
			'agentimus_webmcp_tools',
			static function ( $tools ) {
				$tools[] = array( 'description' => 'no name, no endpoint' ); // missing name + endpoint
				$tools[] = array( 'name' => 'no_endpoint' );                  // missing endpoint
				$tools[] = 'not-an-array';                                    // wrong type
				return $tools;
			}
		);

		$tools = $this->tools();
		foreach ( $tools as $tool ) {
			$this->assertIsArray( $tool );
			$this->assertNotEmpty( $tool['name'] );
			$this->assertNotEmpty( $tool['endpoint'] );
		}
		// The valid baseline tool still survives the cull.
		$this->assertNotNull( $this->find( $tools, 'search_site' ) );
	}

	public function test_filter_returning_a_non_array_is_handled_safely() {
		add_filter( 'agentimus_webmcp_tools', static function () { return 'boom'; } );
		$this->assertSame( array(), $this->tools(), 'A hostile filter return must not fatal — just yield no tools.' );
	}

	/* -- Default-OFF guarantee -------------------------------------------- */

	public function test_feature_is_off_by_default() {
		$this->assertFalse( ( new Settings() )->defaults()['enable_webmcp'] );
		$this->assertFalse( ( new Settings() )->sanitize( array() )['enable_webmcp'] );
		$this->assertTrue( ( new Settings() )->sanitize( array( 'enable_webmcp' => '1' ) )['enable_webmcp'] );
	}
}
