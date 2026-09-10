<?php
namespace AIMS\Tests;

use AIMS\Indexer;
use AIMS\Queue;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** PHP's time() cannot be stubbed, so match "at least now + delay". */
	private function at_least( int $delay ) {
		return \Mockery::on( function ( $timestamp ) use ( $delay ) { return $timestamp >= time() + $delay; } );
	}

	public function test_schedule_adds_single_event_once() {
		Functions\expect( 'wp_next_scheduled' )->once()->with( Queue::HOOK, array( 7 ) )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( $this->at_least( 10 ), Queue::HOOK, array( 7 ) )->andReturn( true );
		$this->assertTrue( Queue::schedule( 7 ) );
	}

	public function test_schedule_skips_when_already_queued() {
		Functions\when( 'wp_next_scheduled' )->justReturn( 2000 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->assertFalse( Queue::schedule( 7 ) );
	}

	public function test_on_add_attachment_respects_setting_and_mime() {
		Functions\when( 'get_option' )->justReturn( array( 'auto_index' => false ) );
		Functions\expect( 'wp_schedule_single_event' )->never();
		( new Queue() )->on_add_attachment( 1 );

		Functions\when( 'get_option' )->justReturn( array( 'auto_index' => true ) );
		Functions\when( 'get_post_mime_type' )->justReturn( 'video/mp4' );
		( new Queue() )->on_add_attachment( 2 );
	}

	public function test_on_add_attachment_schedules_eligible_upload() {
		Functions\when( 'get_option' )->justReturn( array( 'auto_index' => true ) );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( $this->at_least( 10 ), Queue::HOOK, array( 3 ) );
		( new Queue() )->on_add_attachment( 3 );
	}

	public function test_handle_retries_once_on_retryable_error() {
		$indexer = $this->createMock( Indexer::class );
		$indexer->method( 'index_attachment' )->willReturn( new \WP_Error( 'rate_limited', 'x' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\expect( 'update_post_meta' )->once()->with( 9, Indexer::META_RETRY, 1 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( $this->at_least( 300 ), Queue::HOOK, array( 9 ) );
		( new Queue( $indexer ) )->handle( 9 );
	}

	public function test_handle_does_not_retry_twice_or_on_non_retryable() {
		$indexer = $this->createMock( Indexer::class );
		$indexer->method( 'index_attachment' )->willReturn( new \WP_Error( 'rate_limited', 'x' ) );
		Functions\when( 'get_post_meta' )->justReturn( 1 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		( new Queue( $indexer ) )->handle( 9 );

		$indexer2 = $this->createMock( Indexer::class );
		$indexer2->method( 'index_attachment' )->willReturn( new \WP_Error( 'auth_error', 'x' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		( new Queue( $indexer2 ) )->handle( 10 );
	}
}
