<?php
/**
 * Picks (or produces) a reasonably sized image file to send to a provider.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

// Not final: the indexer tests replace it with a PHPUnit mock.
class Image_Preparer {
	const IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	const PDF_MIME    = 'application/pdf';
	const MIN_EDGE    = 1000;
	const MAX_EDGE    = 1600;

	public static function is_eligible_mime( string $mime ): bool {
		return self::PDF_MIME === $mime || in_array( $mime, self::IMAGE_MIMES, true );
	}

	/**
	 * Pure size selection.
	 *
	 * Returns the key of the smallest registered size whose long edge is at least
	 * MIN_EDGE, or 'full' when the original fits within MAX_EDGE, or null when a
	 * resize is needed.
	 */
	public static function choose_size( array $metadata ) {
		$best_key  = null;
		$best_edge = PHP_INT_MAX;

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $key => $size ) {
			$edge = max( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) );
			if ( $edge >= self::MIN_EDGE && $edge < $best_edge ) {
				$best_key  = (string) $key;
				$best_edge = $edge;
			}
		}
		if ( null !== $best_key ) {
			return $best_key;
		}

		$full_edge = max( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) );
		if ( $full_edge > 0 && $full_edge <= self::MAX_EDGE ) {
			return 'full';
		}
		return null;
	}

	/**
	 * @return array{path:string,mime:string,temporary:bool}|\WP_Error
	 */
	public function prepare( int $attachment_id ) {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! self::is_eligible_mime( $mime ) ) {
			return new \WP_Error( 'unsupported', __( 'Only images and PDFs can be described.', 'ai-media-search' ) );
		}

		$original = (string) get_attached_file( $attachment_id );
		if ( '' === $original ) {
			return new \WP_Error( 'no_file', __( 'The attachment has no file.', 'ai-media-search' ) );
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$dir      = dirname( $original );

		if ( self::PDF_MIME === $mime ) {
			// WordPress renders PDF previews into metadata['sizes'] when Imagick is available.
			if ( empty( $metadata['sizes'] ) ) {
				return new \WP_Error( 'no_preview', __( 'WordPress did not generate a preview image for this PDF.', 'ai-media-search' ) );
			}
			$key = self::choose_size( array( 'sizes' => $metadata['sizes'] ) );
			if ( null === $key || 'full' === $key ) {
				// Fall back to whichever preview size is largest.
				$key = self::largest_size_key( $metadata['sizes'] );
			}
			$size = $metadata['sizes'][ $key ];
			return array(
				'path'      => path_join( $dir, (string) $size['file'] ),
				'mime'      => (string) ( $size['mime-type'] ?? 'image/jpeg' ),
				'temporary' => false,
			);
		}

		$key = self::choose_size( $metadata );
		if ( 'full' === $key ) {
			return array( 'path' => $original, 'mime' => $mime, 'temporary' => false );
		}
		if ( null !== $key && isset( $metadata['sizes'][ $key ]['file'] ) ) {
			$size = $metadata['sizes'][ $key ];
			return array(
				'path'      => path_join( $dir, (string) $size['file'] ),
				'mime'      => (string) ( $size['mime-type'] ?? $mime ),
				'temporary' => false,
			);
		}

		return $this->resize( $attachment_id, $original, $mime );
	}

	/**
	 * @return array{path:string,mime:string,temporary:bool}|\WP_Error
	 */
	private function resize( int $attachment_id, string $original, string $mime ) {
		$editor = wp_get_image_editor( $original );
		if ( is_wp_error( $editor ) ) {
			return new \WP_Error( 'resize_failed', $editor->get_error_message() );
		}
		$resized = $editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
		if ( is_wp_error( $resized ) ) {
			return new \WP_Error( 'resize_failed', $resized->get_error_message() );
		}
		$ext  = pathinfo( $original, PATHINFO_EXTENSION );
		$dest = get_temp_dir() . 'aims-' . $attachment_id . '-' . wp_rand( 1000, 9999 ) . '.' . $ext;
		$saved = $editor->save( $dest );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return new \WP_Error( 'resize_failed', __( 'Could not save the resized copy.', 'ai-media-search' ) );
		}
		return array(
			'path'      => (string) $saved['path'],
			'mime'      => (string) ( $saved['mime-type'] ?? $mime ),
			'temporary' => true,
		);
	}

	public function cleanup( array $prepared ): void {
		if ( ! empty( $prepared['temporary'] ) && ! empty( $prepared['path'] ) && file_exists( $prepared['path'] ) ) {
			wp_delete_file( $prepared['path'] );
		}
	}

	private static function largest_size_key( array $sizes ): string {
		$best_key  = (string) array_key_first( $sizes );
		$best_edge = 0;
		foreach ( $sizes as $key => $size ) {
			$edge = max( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) );
			if ( $edge > $best_edge ) {
				$best_edge = $edge;
				$best_key  = (string) $key;
			}
		}
		return $best_key;
	}
}
