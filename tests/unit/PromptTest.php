<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use AIMS\Prompt;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class PromptTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_schema_requires_all_fields_and_forbids_extras() {
		$schema = Prompt::schema();
		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array( 'description', 'tags', 'alt' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 'array', $schema['properties']['tags']['type'] );
		$this->assertSame( 'string', $schema['properties']['tags']['items']['type'] );
	}

	public function test_instructions_include_language_and_custom_prompt() {
		$text = Prompt::instructions( 'German', 'Prefer product names from our catalogue.' );
		$this->assertStringContainsString( 'German', $text );
		$this->assertStringContainsString( 'Additional guidance from the site owner:', $text );
		$this->assertStringContainsString( 'Prefer product names from our catalogue.', $text );
	}

	public function test_instructions_omit_custom_heading_when_empty() {
		$text = Prompt::instructions( 'English', '   ' );
		$this->assertStringNotContainsString( 'Additional guidance', $text );
	}

	public function test_parse_good_json() {
		$json   = '{"description":"A woman walks on a beach at sunset.","tags":["Woman","beach"," Sunset ","woman"],"alt":"Woman walking on a beach at sunset."}';
		$result = Prompt::parse( $json );
		$this->assertInstanceOf( Description_Result::class, $result );
		$this->assertSame( 'A woman walks on a beach at sunset.', $result->description );
		$this->assertSame( array( 'woman', 'beach', 'sunset' ), $result->tags );
		$this->assertSame( 'Woman walking on a beach at sunset.', $result->alt );
	}

	public function test_parse_strips_code_fences() {
		$json   = "```json\n{\"description\":\"A red car.\",\"tags\":[\"car\",\"red\"],\"alt\":\"Red car.\"}\n```";
		$result = Prompt::parse( $json );
		$this->assertInstanceOf( Description_Result::class, $result );
		$this->assertSame( 'A red car.', $result->description );
	}

	public function test_parse_rejects_missing_field() {
		$result = Prompt::parse( '{"description":"x","tags":["a"]}' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_response', $result->get_error_code() );
	}

	public function test_parse_rejects_malformed_json() {
		$result = Prompt::parse( 'not json' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_response', $result->get_error_code() );
	}

	public function test_parse_rejects_empty_description() {
		$result = Prompt::parse( '{"description":"  ","tags":["a"],"alt":"b"}' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_parse_drops_non_string_tags_and_caps_at_30() {
		$tags = array_map( 'strval', range( 1, 40 ) );
		$tags[] = 12;
		$json   = wp_json_encode_stub( array( 'description' => 'd', 'tags' => $tags, 'alt' => 'a' ) );
		$result = Prompt::parse( $json );
		$this->assertCount( 30, $result->tags );
	}
}

function wp_json_encode_stub( $data ) {
	return json_encode( $data );
}
