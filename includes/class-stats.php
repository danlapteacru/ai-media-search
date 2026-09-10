<?php
/**
 * Dashboard counts and "what to index next" queries.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Stats {
	public static function mime_in_sql(): string {
		global $wpdb;
		$mimes = array_merge( Image_Preparer::IMAGE_MIMES, array( Image_Preparer::PDF_MIME ) );
		$parts = array();
		foreach ( $mimes as $mime ) {
			$parts[] = $wpdb->prepare( '%s', $mime );
		}
		return '(' . implode( ',', $parts ) . ')';
	}

	public static function status_where( bool $retry_failed, string $alias ): string {
		$where = "({$alias}.meta_value IS NULL OR {$alias}.meta_value = '" . Indexer::STATUS_PENDING . "'";
		if ( $retry_failed ) {
			$where .= " OR {$alias}.meta_value = '" . Indexer::STATUS_FAILED . "'";
		}
		return $where . ')';
	}

	private static function base_from(): string {
		global $wpdb;
		return "FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '" . Indexer::META_STATUS . "') "
			. "WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN " . self::mime_in_sql();
	}

	/**
	 * @return array{total:int,indexed:int,not_indexed:int,failed:int,skipped:int}
	 */
	public static function counts(): array {
		$cached = get_transient( Stats_Cache::KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT COALESCE(m.meta_value, 'none') AS status, COUNT(*) AS n " . self::base_from() . ' GROUP BY status' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$by = array();
		foreach ( (array) $rows as $row ) {
			$by[ (string) $row->status ] = (int) $row->n;
		}
		$counts = array(
			'total'       => array_sum( $by ),
			'indexed'     => $by[ Indexer::STATUS_INDEXED ] ?? 0,
			'not_indexed' => ( $by['none'] ?? 0 ) + ( $by[ Indexer::STATUS_PENDING ] ?? 0 ),
			'failed'      => $by[ Indexer::STATUS_FAILED ] ?? 0,
			'skipped'     => $by[ Indexer::STATUS_SKIPPED ] ?? 0,
		);
		set_transient( Stats_Cache::KEY, $counts, Stats_Cache::TTL );
		return $counts;
	}

	/**
	 * @return int[]
	 */
	public static function next_ids( int $limit, bool $retry_failed ): array {
		global $wpdb;
		$limit = max( 1, $limit );
		$ids   = $wpdb->get_col( 'SELECT p.ID ' . self::base_from() . ' AND ' . self::status_where( $retry_failed, 'm' ) . " ORDER BY p.ID ASC LIMIT {$limit}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $ids );
	}

	public static function remaining_count( bool $retry_failed ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . self::base_from() . ' AND ' . self::status_where( $retry_failed, 'm' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}
