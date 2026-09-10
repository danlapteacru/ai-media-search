<?php
/**
 * Schedules indexing on upload through WP-Cron.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Queue {
	const HOOK        = 'aims_index_attachment';
	const DELAY       = 10;
	const RETRY_DELAY = 300;
	const RETRYABLE   = array( 'rate_limited', 'server_error' );

	/** @var Indexer */
	private $indexer;

	public function __construct( ?Indexer $indexer = null ) {
		$this->indexer = $indexer ?? new Indexer();
	}

	public function register(): void {
		add_action( 'add_attachment', array( $this, 'on_add_attachment' ) );
		add_action( self::HOOK, array( $this, 'handle' ) );
	}

	public function on_add_attachment( int $id ): void {
		if ( ! Settings::get( 'auto_index' ) ) {
			return;
		}
		if ( ! Image_Preparer::is_eligible_mime( (string) get_post_mime_type( $id ) ) ) {
			return;
		}
		self::schedule( $id );
	}

	public static function schedule( int $id, int $delay = self::DELAY ): bool {
		if ( wp_next_scheduled( self::HOOK, array( $id ) ) ) {
			return false;
		}
		return (bool) wp_schedule_single_event( time() + $delay, self::HOOK, array( $id ) );
	}

	public function handle( int $id ): void {
		$result = $this->indexer->index_attachment( $id );
		if ( ! is_wp_error( $result ) ) {
			return;
		}
		if ( ! in_array( $result->get_error_code(), self::RETRYABLE, true ) ) {
			return;
		}
		$retries = (int) get_post_meta( $id, Indexer::META_RETRY, true );
		if ( $retries >= 1 ) {
			return;
		}
		update_post_meta( $id, Indexer::META_RETRY, $retries + 1 );
		self::schedule( $id, self::RETRY_DELAY );
	}

	public static function clear_all(): void {
		wp_unschedule_hook( self::HOOK );
	}
}
