<?php
namespace AIMS\Tests;

use AIMS\Attachment_Fields;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class AttachmentFieldsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->alias( function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } );
		Functions\when( 'esc_attr' )->alias( function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } );
		Functions\when( 'wp_date' )->justReturn( '2026-09-10 12:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d H:i' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_status_label() {
		$this->assertSame( 'Indexed', Attachment_Fields::status_label( 'indexed' ) );
		$this->assertSame( 'Not indexed', Attachment_Fields::status_label( '' ) );
		$this->assertSame( 'Failed', Attachment_Fields::status_label( 'failed' ) );
	}

	public function test_status_html_escapes_error() {
		$html = Attachment_Fields::status_html( array( 'status' => 'failed', 'error' => '<b>boom</b>', 'indexed_at' => 0 ) );
		$this->assertStringContainsString( 'aims-status-failed', $html );
		$this->assertStringContainsString( '&lt;b&gt;boom&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>boom', $html );
	}

	public function test_fields_added_only_for_eligible_mimes() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$post = (object) array( 'ID' => 4, 'post_mime_type' => 'video/mp4' );
		$this->assertSame( array( 'x' => 1 ), ( new Attachment_Fields() )->fields( array( 'x' => 1 ), $post ) );

		$post   = (object) array( 'ID' => 4, 'post_mime_type' => 'image/jpeg' );
		$fields = ( new Attachment_Fields() )->fields( array(), $post );
		$this->assertSame( 'textarea', $fields['aims_description']['input'] );
		$this->assertSame( 'html', $fields['aims_status']['input'] );
		$this->assertStringContainsString( 'data-id="4"', $fields['aims_status']['html'] );
		$this->assertStringContainsString( 'aims-regenerate', $fields['aims_status']['html'] );
	}

	public function test_save_updates_description_and_rebuilds_search_text() {
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$calls = array();
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$calls ) { $calls[ $key ] = $value; return true; } );

		( new Attachment_Fields() )->save( array( 'ID' => 5 ), array( 'aims_description' => ' Fixed text ' ) );
		$this->assertSame( 'Fixed text', $calls['_aims_description'] );
		$this->assertArrayHasKey( '_aims_search_text', $calls );
	}

	public function test_handle_bulk_schedules_each_id() {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->twice()->andReturn( true );
		Functions\when( 'add_query_arg' )->alias( function ( $k, $v, $url ) { return $url . '?' . $k . '=' . $v; } );
		$redirect = ( new Attachment_Fields() )->handle_bulk( 'upload.php', 'aims_index', array( 1, 2 ) );
		$this->assertSame( 'upload.php?aims_queued=2', $redirect );
		$this->assertSame( 'upload.php', ( new Attachment_Fields() )->handle_bulk( 'upload.php', 'other', array( 1 ) ) );
	}
}
