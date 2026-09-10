<?php
namespace AIMS\Tests;

use AIMS\Rest;
use PHPUnit\Framework\TestCase;

class RestTest extends TestCase {
	public function test_normalize_ids() {
		$this->assertSame( array( 3, 7, 9 ), Rest::normalize_ids( array( '3', 7, '7', 'x', 0, -2, '9' ) ) );
		$this->assertSame( array(), Rest::normalize_ids( 'nope' ) );
		$this->assertSame( array( 1 ), Rest::normalize_ids( '1' ) );
	}
}
