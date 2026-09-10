<?php
namespace AIMS\Tests;

use AIMS\Admin_Page;
use PHPUnit\Framework\TestCase;

class AdminPageTest extends TestCase {
	public function test_filter_meta_query() {
		$this->assertSame( array(), Admin_Page::filter_meta_query( 'all' ) );
		$this->assertSame( array(), Admin_Page::filter_meta_query( 'bogus' ) );
		$this->assertSame(
			array( array( 'key' => '_aims_status', 'value' => 'indexed' ) ),
			Admin_Page::filter_meta_query( 'indexed' )
		);
		$not = Admin_Page::filter_meta_query( 'not_indexed' );
		$this->assertSame( 'OR', $not['relation'] );
		$this->assertSame( 'NOT EXISTS', $not[0]['compare'] );
		$this->assertSame( 'pending', $not[1]['value'] );
	}

	public function test_truncate() {
		$this->assertSame( 'short', Admin_Page::truncate( 'short' ) );
		$long = str_repeat( 'a', 200 );
		$this->assertSame( str_repeat( 'a', 160 ) . '…', Admin_Page::truncate( $long ) );
	}
}
