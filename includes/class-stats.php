<?php
/**
 * Dashboard counts and "what to index next" queries.
 *
 * Every query here is a single prepared statement. The eligible mime list is
 * five values (four image types plus PDF), so the IN clauses carry exactly
 * five placeholders; keep that in sync with Image_Preparer::IMAGE_MIMES.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Stats {
	/**
	 * @return string[] The five eligible mime types.
	 */
	private static function mimes(): array {
		return array_merge( Image_Preparer::IMAGE_MIMES, array( Image_Preparer::PDF_MIME ) );
	}

	/**
	 * The second status that counts as pending work. When failed files are not
	 * being retried it repeats "pending" so the SQL shape stays fixed.
	 */
	private static function second_status( bool $retry_failed ): string {
		return $retry_failed ? Indexer::STATUS_FAILED : Indexer::STATUS_PENDING;
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
		$mimes = self::mimes();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Aggregate count over post meta; the result is cached in a transient and cleared by the indexer.
			$wpdb->prepare(
				"SELECT COALESCE(m.meta_value, 'none') AS status, COUNT(*) AS n
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = %s)
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type IN (%s, %s, %s, %s, %s)
				GROUP BY status",
				Indexer::META_STATUS,
				$mimes[0],
				$mimes[1],
				$mimes[2],
				$mimes[3],
				$mimes[4]
			)
		);

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
	 * Next attachment IDs that still need indexing, after the given cursor.
	 *
	 * @return int[]
	 */
	public static function next_ids( int $limit, bool $retry_failed, int $after_id = 0 ): array {
		global $wpdb;
		$mimes  = self::mimes();
		$second = self::second_status( $retry_failed );
		$ids    = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cursor-paged selection of unindexed attachments; not cacheable because every batch changes it.
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = %s)
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type IN (%s, %s, %s, %s, %s)
					AND (m.meta_value IS NULL OR m.meta_value = %s OR m.meta_value = %s)
					AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d",
				Indexer::META_STATUS,
				$mimes[0],
				$mimes[1],
				$mimes[2],
				$mimes[3],
				$mimes[4],
				Indexer::STATUS_PENDING,
				$second,
				max( 0, $after_id ),
				max( 1, $limit )
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many attachments still need indexing after the given cursor.
	 */
	public static function remaining_count( bool $retry_failed, int $after_id = 0 ): int {
		global $wpdb;
		$mimes  = self::mimes();
		$second = self::second_status( $retry_failed );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Same selection as next_ids(); read once per batch.
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = %s)
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type IN (%s, %s, %s, %s, %s)
					AND (m.meta_value IS NULL OR m.meta_value = %s OR m.meta_value = %s)
					AND p.ID > %d",
				Indexer::META_STATUS,
				$mimes[0],
				$mimes[1],
				$mimes[2],
				$mimes[3],
				$mimes[4],
				Indexer::STATUS_PENDING,
				$second,
				max( 0, $after_id )
			)
		);
	}
}
