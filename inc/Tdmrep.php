<?php
/**
 * /.well-known/tdmrep.json — the W3C TDM Reservation Protocol opt-out file.
 *
 * Publishes the site's AI-training stance (content_signal.ai_train) as a
 * machine-readable, standardised reservation, so a crawler that ignores
 * robots.txt still has a recognised signal to read. The reservation mirrors the
 * robots.txt Content-Signal exactly: ai_train=false ("ai-train=no") = reserved.
 *
 * Site-wide ("/") in v1: TDMRep is content-scoped, NOT crawler-scoped — there is
 * no per-bot dial in this file. Per-bot blocks therefore stay where they belong:
 * the named list in robots.txt (advisory) and Guard (a real 403).
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Tdmrep {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the site reserves its content from AI training. Mirrors the
	 * robots.txt Content-Signal decision: ai_train=false ("no") = reserved.
	 *
	 * @return bool
	 */
	public function reserved() {
		$signal = (array) $this->settings->get( 'content_signal', array() );
		return empty( $signal['ai_train'] );
	}

	/**
	 * The tdmrep.json body, or '' (→ a clean 404 from the WellKnown router) when
	 * the channel is off OR there is nothing to reserve.
	 *
	 * An opt-out file only makes sense when you're opting out: if AI training is
	 * allowed, the web default (no file) already means "not reserved", so an open
	 * site serves no file rather than a redundant `tdm-reservation: 0`.
	 *
	 * @return string
	 */
	public function json() {
		if ( ! $this->settings->enabled( 'enable_tdmrep' ) || ! $this->reserved() ) {
			return '';
		}

		$entry = array(
			'location'        => '/',
			'tdm-reservation' => 1,
		);

		$policy = trim( (string) $this->settings->get( 'tdm_policy_url', '' ) );
		if ( '' !== $policy ) {
			$entry['tdm-policy'] = $policy;
		}

		return (string) wp_json_encode( array( $entry ) );
	}
}
