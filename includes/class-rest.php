<?php
/**
 * REST routes used by the admin JS.
 *
 * @package AIMS
 */

namespace AIMS;

use AIMS\Providers\Registry;

defined( 'ABSPATH' ) || exit;

final class Rest {
	const NS          = 'aims/v1';
	const TIME_BUDGET = 20;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/index/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'index_one' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'ids'          => array(
						'type'     => 'array',
						'required' => false,
					),
					'batch_size'   => array(
						'type'     => 'integer',
						'required' => false,
					),
					'retry_failed' => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_edit_attachment( $request ): bool {
		$id = (int) $request['id'];
		return $id > 0 && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * @param mixed $raw Anything the client sent as ids.
	 * @return int[] Unique positive integers in the order received.
	 */
	public static function normalize_ids( $raw ): array {
		if ( is_string( $raw ) || is_int( $raw ) ) {
			$raw = array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $value ) {
			if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
				$id = (int) $value;
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Whether the client explicitly sent an `ids` parameter, as opposed to
	 * omitting it entirely (in which case the server picks the batch).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function is_explicit_mode( $request ): bool {
		return $request->has_param( 'ids' );
	}

	private static function result_for( int $id, $outcome ): array {
		$payload          = Indexer::payload( $id );
		$payload['ok']    = ! is_wp_error( $outcome );
		$payload['title'] = (string) get_the_title( $id );
		if ( is_wp_error( $outcome ) ) {
			$payload['error'] = $outcome->get_error_message();
			$payload['code']  = $outcome->get_error_code();
		}
		return $payload;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function index_one( $request ) {
		$id = (int) $request['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'not_found', __( 'Attachment not found.', 'ai-media-search' ), array( 'status' => 404 ) );
		}
		$outcome = ( new Indexer() )->index_attachment( $id );
		return rest_ensure_response( self::result_for( $id, $outcome ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function bulk( $request ) {
		$batch_size    = (int) ( $request['batch_size'] ?? 0 );
		$batch_size    = $batch_size > 0 ? min( 10, $batch_size ) : (int) Settings::get( 'batch_size' );
		$retry_failed  = ! empty( $request['retry_failed'] );
		$explicit_mode = self::is_explicit_mode( $request );
		$explicit      = $explicit_mode ? self::normalize_ids( $request['ids'] ?? null ) : array();

		if ( $explicit_mode ) {
			// The client named ids explicitly, even if the list normalizes to
			// empty (all invalid) or was sent empty; never substitute a
			// server-selected batch in that case.
			$ids       = array_slice( $explicit, 0, $batch_size );
			$remaining = array_slice( $explicit, $batch_size );
		} else {
			$ids       = Stats::next_ids( $batch_size, $retry_failed );
			$remaining = array();
		}

		$indexer = new Indexer();
		$results = array();
		$started = microtime( true );
		foreach ( $ids as $position => $id ) {
			if ( $position > 0 && ( microtime( true ) - $started ) > self::TIME_BUDGET ) {
				// Out of time for this request; hand the rest back to the client.
				$remaining = array_merge( array_slice( $ids, $position ), $remaining );
				break;
			}
			$results[] = self::result_for( $id, $indexer->index_attachment( $id ) );
		}

		return rest_ensure_response(
			array(
				'results'         => $results,
				'remaining_ids'   => array_values( $remaining ),
				'remaining_count' => $explicit_mode ? count( $remaining ) : Stats::remaining_count( $retry_failed ),
				'stats'           => Stats::counts(),
			)
		);
	}

	public function stats() {
		return rest_ensure_response( Stats::counts() );
	}

	public function test() {
		$provider = Registry::active();
		if ( is_wp_error( $provider ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'message' => $provider->get_error_message(),
				)
			);
		}
		$outcome = $provider->test_connection();
		if ( is_wp_error( $outcome ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'message' => $outcome->get_error_message(),
				)
			);
		}
		return rest_ensure_response(
			array(
				'ok'      => true,
				/* translators: 1: provider label, 2: model id */
				'message' => sprintf( __( 'Connected to %1$s using %2$s.', 'ai-media-search' ), $provider::get_label(), Settings::get_active_model() ),
			)
		);
	}
}
