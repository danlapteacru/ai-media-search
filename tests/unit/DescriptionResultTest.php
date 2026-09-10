<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use PHPUnit\Framework\TestCase;

class DescriptionResultTest extends TestCase {
	public function test_holds_values_and_exports_array() {
		$result = new Description_Result( 'A woman on a beach.', array( 'woman', 'beach' ), 'Woman standing on a beach.' );

		$this->assertSame( 'A woman on a beach.', $result->description );
		$this->assertSame( array( 'woman', 'beach' ), $result->tags );
		$this->assertSame( 'Woman standing on a beach.', $result->alt );
		$this->assertSame(
			array(
				'description' => 'A woman on a beach.',
				'tags'        => array( 'woman', 'beach' ),
				'alt'         => 'Woman standing on a beach.',
			),
			$result->to_array()
		);
	}
}
