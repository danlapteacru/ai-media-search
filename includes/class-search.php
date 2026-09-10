<?php
/**
 * Extends every attachment search to the AI search text meta.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Search {
	const ALIAS = 'aims_st';

	public function register(): void {
		add_filter( 'posts_join', array( $this, 'join' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'search' ), 10, 2 );
	}

	/**
	 * @param object $query WP_Query (or anything with get()).
	 */
	public static function applies( $query ): bool {
		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return false;
		}
		$post_type = $query->get( 'post_type' );
		if ( is_array( $post_type ) ) {
			if ( 1 !== count( $post_type ) || 'attachment' !== reset( $post_type ) ) {
				return false;
			}
		} elseif ( 'attachment' !== $post_type ) {
			return false;
		}
		return '' !== trim( (string) $query->get( 's' ) );
	}

	/**
	 * @param string $join  Existing JOIN clause.
	 * @param object $query WP_Query.
	 */
	public function join( $join, $query ) {
		$join = (string) $join;
		if ( ! self::applies( $query ) || false !== strpos( $join, self::ALIAS ) ) {
			return $join;
		}
		global $wpdb;
		$join .= " LEFT JOIN {$wpdb->postmeta} AS " . self::ALIAS . " ON ({$wpdb->posts}.ID = " . self::ALIAS . '.post_id AND ' . self::ALIAS . ".meta_key = '" . Indexer::META_SEARCH . "')";
		return $join;
	}

	/**
	 * @param string $search Existing search SQL.
	 * @param object $query  WP_Query.
	 */
	public function search( $search, $query ) {
		$search = (string) $search;
		if ( ! self::applies( $query ) ) {
			return $search;
		}
		global $wpdb;
		return self::rewrite( $search, $wpdb->posts );
	}

	/**
	 * Turn every "(posts.post_title LIKE 'term')" clause WordPress generated into
	 * "(posts.post_title LIKE 'term' OR aims_st.meta_value LIKE 'term')".
	 */
	public static function rewrite( string $search, string $posts_table ): string {
		$table   = preg_quote( $posts_table, '/' );
		$literal = "'(?:[^'\\\\]|\\\\.)*'";
		$pattern = '/\((' . $table . '\.post_title LIKE (' . $literal . '))\)/';
		$result  = preg_replace( $pattern, '($1 OR ' . self::ALIAS . '.meta_value LIKE $2)', $search );
		return null === $result ? $search : $result;
	}
}
