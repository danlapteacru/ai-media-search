<?php
/**
 * Media modal fields, list-view column, and bulk action.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Attachment_Fields {
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', array( $this, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save' ), 10, 2 );
		add_filter( 'manage_media_columns', array( $this, 'column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	public static function status_label( string $status ): string {
		switch ( $status ) {
			case Indexer::STATUS_INDEXED:
				return __( 'Indexed', 'ai-media-search' );
			case Indexer::STATUS_FAILED:
				return __( 'Failed', 'ai-media-search' );
			case Indexer::STATUS_SKIPPED:
				return __( 'Skipped', 'ai-media-search' );
			case Indexer::STATUS_PENDING:
				return __( 'Pending', 'ai-media-search' );
			default:
				return __( 'Not indexed', 'ai-media-search' );
		}
	}

	public static function status_html( array $payload ): string {
		$status = (string) ( $payload['status'] ?? '' );
		$class  = 'aims-status aims-status-' . ( '' === $status ? 'none' : $status );
		$html   = '<span class="' . esc_attr( $class ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';

		if ( Indexer::STATUS_INDEXED === $status && ! empty( $payload['indexed_at'] ) ) {
			$html .= ' <span class="aims-muted">' . esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $payload['indexed_at'] ) ) . '</span>';
		}
		if ( ! empty( $payload['error'] ) && Indexer::STATUS_INDEXED !== $status ) {
			$html .= ' <span class="aims-error">' . esc_html( (string) $payload['error'] ) . '</span>';
		}
		return $html;
	}

	/**
	 * @param array  $fields Existing fields.
	 * @param object $post   WP_Post.
	 */
	public function fields( $fields, $post ) {
		$fields = (array) $fields;
		if ( ! Image_Preparer::is_eligible_mime( (string) $post->post_mime_type ) ) {
			return $fields;
		}
		$payload = Indexer::payload( (int) $post->ID );

		$fields['aims_description'] = array(
			'label' => __( 'AI description', 'ai-media-search' ),
			'input' => 'textarea',
			'value' => $payload['description'],
			'helps' => __( 'Used by the media search. Edit it to correct the AI.', 'ai-media-search' ),
		);
		$fields['aims_tags']        = array(
			'label' => __( 'AI tags', 'ai-media-search' ),
			'input' => 'html',
			'html'  => '<p class="aims-tags" data-id="' . esc_attr( (string) $post->ID ) . '">' . esc_html( implode( ', ', $payload['tags'] ) ) . '</p>',
		);
		$fields['aims_status']      = array(
			'label' => __( 'AI index', 'ai-media-search' ),
			'input' => 'html',
			'html'  => '<span class="aims-status-wrap" data-id="' . esc_attr( (string) $post->ID ) . '">' . self::status_html( $payload ) . '</span> '
				. '<button type="button" class="button button-small aims-regenerate" data-id="' . esc_attr( (string) $post->ID ) . '">'
				. esc_html__( 'Regenerate', 'ai-media-search' ) . '</button>',
		);
		return $fields;
	}

	/**
	 * @param array $post       Post data being saved.
	 * @param array $attachment Submitted field values for this attachment.
	 */
	public function save( $post, $attachment ) {
		if ( isset( $attachment['aims_description'] ) && isset( $post['ID'] ) ) {
			$id = (int) $post['ID'];
			update_post_meta( $id, Indexer::META_DESCRIPTION, sanitize_textarea_field( wp_unslash( (string) $attachment['aims_description'] ) ) );
			Indexer::rebuild_search_text( $id );
		}
		return $post;
	}

	public function column( $columns ) {
		$columns['aims_status'] = __( 'AI index', 'ai-media-search' );
		return $columns;
	}

	public function column_content( $column, $id ): void {
		if ( 'aims_status' !== $column ) {
			return;
		}
		$payload = Indexer::payload( (int) $id );
		echo '<span class="aims-status-wrap" data-id="' . esc_attr( (string) $id ) . '">' . self::status_html( $payload ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status_html escapes.
	}

	public function bulk_action( $actions ) {
		$actions['aims_index'] = __( 'Index with AI', 'ai-media-search' );
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'aims_index' !== $action ) {
			return $redirect;
		}
		$count = 0;
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( ! Image_Preparer::is_eligible_mime( (string) get_post_mime_type( $id ) ) ) {
				continue;
			}
			if ( Queue::schedule( $id, 5 ) ) {
				++$count;
			}
		}
		return add_query_arg( 'aims_queued', $count, $redirect );
	}

	public function bulk_notice(): void {
		if ( ! isset( $_GET['aims_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$count = (int) $_GET['aims_queued']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>'
			/* translators: %d: number of files */
			. esc_html( sprintf( _n( '%d file queued for AI indexing.', '%d files queued for AI indexing.', $count, 'ai-media-search' ), $count ) )
			. '</p></div>';
	}
}
