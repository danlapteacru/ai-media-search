<?php
namespace AIMS\Tests;

use AIMS\Search;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class SearchTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['wpdb'] = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function query( $post_type, string $s ) {
		return new class( $post_type, $s ) {
			private $vars;
			public function __construct( $post_type, $s ) { $this->vars = array( 'post_type' => $post_type, 's' => $s ); }
			public function get( $key ) { return $this->vars[ $key ] ?? ''; }
		};
	}

	public function test_applies_only_to_attachment_searches() {
		$this->assertTrue( Search::applies( $this->query( 'attachment', 'woman' ) ) );
		$this->assertTrue( Search::applies( $this->query( array( 'attachment' ), 'woman' ) ) );
		$this->assertFalse( Search::applies( $this->query( 'attachment', '  ' ) ) );
		$this->assertFalse( Search::applies( $this->query( 'post', 'woman' ) ) );
		$this->assertFalse( Search::applies( $this->query( array( 'post', 'attachment' ), 'woman' ) ) );
	}

	public function test_rewrite_single_term() {
		$in  = " AND (((wp_posts.post_title LIKE '{a1b2}woman{a1b2}') OR (wp_posts.post_excerpt LIKE '{a1b2}woman{a1b2}') OR (wp_posts.post_content LIKE '{a1b2}woman{a1b2}')))";
		$out = Search::rewrite( $in, 'wp_posts' );
		$this->assertStringContainsString( "(wp_posts.post_title LIKE '{a1b2}woman{a1b2}' OR aims_st.meta_value LIKE '{a1b2}woman{a1b2}')", $out );
		$this->assertSame( 1, substr_count( $out, 'aims_st.meta_value' ) );
	}

	public function test_rewrite_multi_term_keeps_and_structure() {
		$in  = " AND (((wp_posts.post_title LIKE '{x}red{x}') OR (wp_posts.post_content LIKE '{x}red{x}')) AND ((wp_posts.post_title LIKE '{x}car{x}') OR (wp_posts.post_content LIKE '{x}car{x}')))";
		$out = Search::rewrite( $in, 'wp_posts' );
		$this->assertSame( 2, substr_count( $out, 'aims_st.meta_value' ) );
		$this->assertStringContainsString( "AND ((wp_posts.post_title LIKE '{x}car{x}' OR aims_st.meta_value LIKE '{x}car{x}')", $out );
	}

	public function test_rewrite_handles_escaped_quote_in_term() {
		$in  = " AND (((wp_posts.post_title LIKE '{x}o\\'neil{x}') OR (wp_posts.post_content LIKE '{x}o\\'neil{x}')))";
		$out = Search::rewrite( $in, 'wp_posts' );
		$this->assertStringContainsString( "OR aims_st.meta_value LIKE '{x}o\\'neil{x}')", $out );
	}

	public function test_join_added_once_and_only_for_attachment_search() {
		$search = new Search();
		$join   = $search->join( '', $this->query( 'attachment', 'x' ) );
		$this->assertStringContainsString( "LEFT JOIN wp_postmeta AS aims_st ON (wp_posts.ID = aims_st.post_id AND aims_st.meta_key = '_aims_search_text')", $join );
		$this->assertSame( $join, $search->join( $join, $this->query( 'attachment', 'x' ) ), 'no double join' );
		$this->assertSame( '', $search->join( '', $this->query( 'post', 'x' ) ) );
	}

	public function test_search_filter_untouched_for_other_queries() {
		$search = new Search();
		$sql    = " AND ((wp_posts.post_title LIKE '{x}a{x}'))";
		$this->assertSame( $sql, $search->search( $sql, $this->query( 'post', 'a' ) ) );
	}
}
