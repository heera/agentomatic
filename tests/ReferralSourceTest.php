<?php
/**
 * Referrals::source_for() — the pure matcher that decides whether a visit came
 * from an AI assistant, from the referrer host and/or the utm_source tag.
 *
 * Load-bearing behaviour: high precision (what it matches really is that source),
 * www- and subdomain-tolerant, case-insensitive, and "" for anything unknown so
 * normal traffic is never miscounted.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Referrals;
use PHPUnit\Framework\TestCase;

final class ReferralSourceTest extends TestCase {

	public function test_matches_referrer_host() {
		$this->assertSame( 'ChatGPT', Referrals::source_for( 'https://chatgpt.com/', '' ) );
		$this->assertSame( 'Perplexity', Referrals::source_for( 'https://www.perplexity.ai/search?q=x', '' ) );
		$this->assertSame( 'Gemini', Referrals::source_for( 'https://gemini.google.com/app', '' ) );
		$this->assertSame( 'Claude', Referrals::source_for( 'https://claude.ai/chat/abc', '' ) );
	}

	public function test_matches_subdomain_of_a_known_source() {
		$this->assertSame( 'ChatGPT', Referrals::source_for( 'https://eu.chatgpt.com/', '' ) );
	}

	public function test_is_case_insensitive() {
		$this->assertSame( 'ChatGPT', Referrals::source_for( 'HTTPS://ChatGPT.COM/', '' ) );
	}

	public function test_matches_utm_source_when_referrer_is_missing() {
		// ChatGPT stamps utm_source=chatgpt.com, so it's caught even with no referrer.
		$this->assertSame( 'ChatGPT', Referrals::source_for( '', 'chatgpt.com' ) );
		$this->assertSame( 'Perplexity', Referrals::source_for( '', 'Perplexity.ai' ) );
	}

	public function test_unknown_or_empty_is_not_a_source() {
		$this->assertSame( '', Referrals::source_for( 'https://example.com/page', '' ) );
		$this->assertSame( '', Referrals::source_for( 'https://www.google.com/search?q=x', '' ), 'Plain Google search must not count as AI.' );
		$this->assertSame( '', Referrals::source_for( '', '' ) );
		$this->assertSame( '', Referrals::source_for( 'not-a-url', 'nope' ) );
	}

	public function test_lookalike_domain_does_not_false_match() {
		// "foryou.com" must not match the "you.com" source.
		$this->assertSame( '', Referrals::source_for( 'https://foryou.com/', '' ) );
	}
}
