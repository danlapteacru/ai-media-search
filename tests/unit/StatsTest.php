<?php
namespace AIMS\Tests;

use AIMS\Stats;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class StatsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$GLOBALS['wpdb'] = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public $last_sql = '';
			public $rows     = array();
			public function prepare( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
				foreach ( $args as $a ) { $sql = preg_replace( '/%[sd]/', is_int( $a ) ? $a : "'" . $a . "'", $sql, 1 ); }
				return $sql;
			}
			public function get_results( $sql ) { $this->last_sql = $sql; return $this->rows; }
			public function get_col( $sql ) { $this->last_sql = $sql; return array( 3, 5 ); }
			public function get_var( $sql ) { $this->last_sql = $sql; return 7; }
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_counts_groups_statuses() {
		$GLOBALS['wpdb']->rows = array(
			(object) array( 'status' => 'none', 'n' => '4' ),
			(object) array( 'status' => 'pending', 'n' => '1' ),
			(object) array( 'status' => 'indexed', 'n' => '10' ),
			(object) array( 'status' => 'failed', 'n' => '2' ),
			(object) array( 'status' => 'skipped', 'n' => '3' ),
		);
		$c = Stats::counts();
		$this->assertSame( 20, $c['total'] );
		$this->assertSame( 10, $c['indexed'] );
		$this->assertSame( 5, $c['not_indexed'] );
		$this->assertSame( 2, $c['failed'] );
		$this->assertSame( 3, $c['skipped'] );
		$this->assertStringContainsString( "post_mime_type IN ('image/jpeg'", $GLOBALS['wpdb']->last_sql );
	}


	public function test_next_ids_and_remaining() {
		$this->assertSame( array( 3, 5 ), Stats::next_ids( 2, false ) );
		$this->assertStringContainsString( 'LIMIT 2', $GLOBALS['wpdb']->last_sql );
		$this->assertSame( 7, Stats::remaining_count( true ) );
		$this->assertStringContainsString( "m.meta_value = 'failed'", $GLOBALS['wpdb']->last_sql );
	}

	public function test_next_ids_with_cursor_adds_id_filter() {
		Stats::next_ids( 2, false, 41 );
		$this->assertStringContainsString( 'p.ID > 41', $GLOBALS['wpdb']->last_sql );
		$this->assertStringContainsString( 'LIMIT 2', $GLOBALS['wpdb']->last_sql );
	}

	public function test_next_ids_without_cursor_has_no_id_filter() {
		Stats::next_ids( 2, false );
		$this->assertStringContainsString( 'p.ID > 0', $GLOBALS['wpdb']->last_sql );
	}

	public function test_remaining_count_with_cursor_adds_id_filter() {
		Stats::remaining_count( true, 41 );
		$this->assertStringContainsString( 'p.ID > 41', $GLOBALS['wpdb']->last_sql );
	}
}
