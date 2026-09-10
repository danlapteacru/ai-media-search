<?php
/**
 * Runs prepare -> provider -> meta for one attachment.
 *
 * @package AIMS
 */

namespace AIMS;

use AIMS\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Runs prepare -> provider -> meta for one attachment.
 *
 * Not final: the queue tests replace it with a PHPUnit mock.
 */
class Indexer {
	const STATUS_PENDING = 'pending';
	const STATUS_INDEXED = 'indexed';
	const STATUS_FAILED  = 'failed';
	const STATUS_SKIPPED = 'skipped';

	const META_DESCRIPTION = '_aims_description';
	const META_TAGS        = '_aims_tags';
	const META_ALT         = '_aims_alt';
	const META_SEARCH      = '_aims_search_text';
	const META_STATUS      = '_aims_status';
	const META_ERROR       = '_aims_error';
	const META_INDEXED_AT  = '_aims_indexed_at';
	const META_PROVIDER    = '_aims_provider';
	const META_RETRY       = '_aims_retry_count';

	const LOCK_TTL = 120;

	/** @var Image_Preparer */
	private $preparer;

	/** @var callable Returns Provider_Interface|WP_Error. */
	private $provider_factory;

	public function __construct( ?Image_Preparer $preparer = null, ?callable $provider_factory = null ) {
		$this->preparer         = $preparer ?? new Image_Preparer();
		$this->provider_factory = $provider_factory ?? array( Registry::class, 'active' );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function index_attachment( int $id ) {
		$lock = 'aims_lock_' . $id;
		if ( get_transient( $lock ) ) {
			return new \WP_Error( 'locked', __( 'This file is already being processed.', 'ai-media-search' ) );
		}
		set_transient( $lock, 1, self::LOCK_TTL );

		try {
			return $this->run( $id );
		} finally {
			delete_transient( $lock );
			Stats_Cache::clear();
		}
	}

	/**
	 * @return true|\WP_Error
	 */
	private function run( int $id ) {
		update_post_meta( $id, self::META_STATUS, self::STATUS_PENDING );

		$prepared = $this->preparer->prepare( $id );
		if ( is_wp_error( $prepared ) ) {
			if ( in_array( $prepared->get_error_code(), array( 'unsupported', 'no_preview' ), true ) ) {
				$this->mark( $id, self::STATUS_SKIPPED, $prepared->get_error_message() );
				return true;
			}
			$this->mark( $id, self::STATUS_FAILED, $prepared->get_error_message() );
			return $prepared;
		}

		$provider = call_user_func( $this->provider_factory );
		if ( is_wp_error( $provider ) ) {
			$this->preparer->cleanup( $prepared );
			$this->mark( $id, self::STATUS_FAILED, $provider->get_error_message() );
			return $provider;
		}

		$instructions = Prompt::instructions( (string) Settings::get( 'language' ), (string) Settings::get( 'custom_prompt' ) );
		$result       = $provider->describe( $prepared['path'], $prepared['mime'], $instructions );
		$this->preparer->cleanup( $prepared );

		if ( is_wp_error( $result ) ) {
			$this->mark( $id, self::STATUS_FAILED, $result->get_error_message() );
			return $result;
		}

		update_post_meta( $id, self::META_DESCRIPTION, $result->description );
		update_post_meta( $id, self::META_TAGS, $result->tags );
		update_post_meta( $id, self::META_ALT, $result->alt );
		update_post_meta( $id, self::META_SEARCH, self::build_search_text( $result->description, $result->tags, $result->alt ) );
		update_post_meta( $id, self::META_INDEXED_AT, time() );
		update_post_meta( $id, self::META_PROVIDER, $provider::get_id() . ':' . Settings::get_model( $provider::get_id() ) );
		delete_post_meta( $id, self::META_ERROR );
		delete_post_meta( $id, self::META_RETRY );
		update_post_meta( $id, self::META_STATUS, self::STATUS_INDEXED );

		if ( Settings::get( 'fill_alt' ) && '' !== $result->alt ) {
			$existing = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( '' === trim( $existing ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $result->alt );
			}
		}

		return true;
	}

	private function mark( int $id, string $status, string $error ): void {
		update_post_meta( $id, self::META_STATUS, $status );
		update_post_meta( $id, self::META_ERROR, $error );
	}

	public static function build_search_text( string $description, array $tags, string $alt ): string {
		$text = $description . ' ' . implode( ' ', $tags ) . ' ' . $alt;
		$text = strtolower( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	public static function rebuild_search_text( int $id ): void {
		$description = (string) get_post_meta( $id, self::META_DESCRIPTION, true );
		$tags        = get_post_meta( $id, self::META_TAGS, true );
		$alt         = (string) get_post_meta( $id, self::META_ALT, true );
		update_post_meta( $id, self::META_SEARCH, self::build_search_text( $description, is_array( $tags ) ? $tags : array(), $alt ) );
	}

	public static function payload( int $id ): array {
		$tags = get_post_meta( $id, self::META_TAGS, true );
		return array(
			'id'          => $id,
			'description' => (string) get_post_meta( $id, self::META_DESCRIPTION, true ),
			'tags'        => is_array( $tags ) ? array_values( $tags ) : array(),
			'alt'         => (string) get_post_meta( $id, self::META_ALT, true ),
			'status'      => (string) get_post_meta( $id, self::META_STATUS, true ),
			'error'       => (string) get_post_meta( $id, self::META_ERROR, true ),
			'indexed_at'  => (int) get_post_meta( $id, self::META_INDEXED_AT, true ),
			'provider'    => (string) get_post_meta( $id, self::META_PROVIDER, true ),
		);
	}
}
