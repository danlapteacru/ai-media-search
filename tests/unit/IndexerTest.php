<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use AIMS\Image_Preparer;
use AIMS\Indexer;
use AIMS\Providers\Provider_Interface;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class IndexerTest extends TestCase {
	/** @var array<int,array<string,mixed>> */
	private $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => false ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );

		$meta = &$this->meta;
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$meta ) { $meta[ $id ][ $key ] = $value; return true; } );
		Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) use ( &$meta ) { unset( $meta[ $id ][ $key ] ); return true; } );
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) use ( &$meta ) { return $meta[ $id ][ $key ] ?? ''; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function preparer( $return ): Image_Preparer {
		$p = $this->createMock( Image_Preparer::class );
		$p->method( 'prepare' )->willReturn( $return );
		return $p;
	}

	/**
	 * PHPUnit cannot configure static interface methods, so use a real class.
	 * The returned object exposes a public $calls counter.
	 */
	private function provider( $return ): Provider_Interface {
		return new class( $return ) implements Provider_Interface {
			public $calls = 0;
			private $return;
			public function __construct( $return ) { $this->return = $return; }
			public function describe( string $file_path, string $mime_type, string $instructions ) { ++$this->calls; return $this->return; }
			public function test_connection() { return true; }
			public static function get_id(): string { return 'claude'; }
			public static function get_label(): string { return 'Claude'; }
			public static function get_known_models(): array { return array( 'claude-opus-5' => 'x' ); }
		};
	}

	public function test_success_writes_all_meta() {
		$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
		$result   = new Description_Result( 'A Woman on a beach.', array( 'woman', 'beach' ), 'Woman on a beach.' );
		$provider = $this->provider( $result );
		Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'models' => array( 'claude' => 'claude-opus-5' ), 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => false ) );

		$indexer = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );
		$before  = time();
		$this->assertTrue( $indexer->index_attachment( 42 ) );

		$m = $this->meta[42];
		$this->assertSame( 'A Woman on a beach.', $m['_aims_description'] );
		$this->assertSame( array( 'woman', 'beach' ), $m['_aims_tags'] );
		$this->assertSame( 'Woman on a beach.', $m['_aims_alt'] );
		$this->assertSame( 'a woman on a beach. woman beach woman on a beach.', $m['_aims_search_text'] );
		$this->assertSame( 'indexed', $m['_aims_status'] );
		$this->assertGreaterThanOrEqual( $before, $m['_aims_indexed_at'] );
		$this->assertSame( 'claude:claude-opus-5', $m['_aims_provider'] );
		$this->assertArrayNotHasKey( '_aims_error', $m );
		$this->assertArrayNotHasKey( '_wp_attachment_image_alt', $m, 'fill_alt is off' );
	}

	public function test_fill_alt_only_when_empty_and_enabled() {
		Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => true ) );
		$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
		$provider = $this->provider( new Description_Result( 'd', array( 't' ), 'Generated alt' ) );
		$indexer  = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );

		$indexer->index_attachment( 1 );
		$this->assertSame( 'Generated alt', $this->meta[1]['_wp_attachment_image_alt'] );

		$this->meta[2]['_wp_attachment_image_alt'] = 'Existing';
		$indexer->index_attachment( 2 );
		$this->assertSame( 'Existing', $this->meta[2]['_wp_attachment_image_alt'] );
	}

	public function test_unsupported_and_no_preview_mark_skipped_without_calling_provider() {
		foreach ( array( 'unsupported', 'no_preview' ) as $code ) {
			$provider = $this->provider( null );
			$indexer  = new Indexer( $this->preparer( new \WP_Error( $code, 'nope' ) ), function () use ( $provider ) { return $provider; } );
			$result   = $indexer->index_attachment( 3 );
			$this->assertTrue( $result );
			$this->assertSame( 'skipped', $this->meta[3]['_aims_status'] );
			$this->assertSame( 'nope', $this->meta[3]['_aims_error'] );
			$this->assertSame( 0, $provider->calls );
		}
	}

	public function test_provider_error_marks_failed_and_returns_error() {
		$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
		$provider = $this->provider( new \WP_Error( 'rate_limited', 'slow down' ) );
		$indexer  = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );

		$result = $indexer->index_attachment( 4 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 'failed', $this->meta[4]['_aims_status'] );
		$this->assertSame( 'slow down', $this->meta[4]['_aims_error'] );
	}

	public function test_non_attachment_id_returns_not_found_without_writing_meta() {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$provider = $this->provider( null );
		$indexer  = new Indexer( $this->preparer( array() ), function () use ( $provider ) { return $provider; } );

		$result = $indexer->index_attachment( 99 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertArrayNotHasKey( 99, $this->meta );
		$this->assertSame( 0, $provider->calls );
	}

	public function test_lock_prevents_double_processing() {
		Functions\when( 'get_transient' )->justReturn( 1 );
		$provider = $this->provider( null );
		$indexer  = new Indexer( $this->preparer( array() ), function () use ( $provider ) { return $provider; } );
		$result   = $indexer->index_attachment( 5 );
		$this->assertSame( 'locked', $result->get_error_code() );
		$this->assertSame( 0, $provider->calls );
	}

	public function test_temporary_file_is_cleaned_up() {
		$preparer = $this->createMock( Image_Preparer::class );
		$preparer->method( 'prepare' )->willReturn( array( 'path' => '/tmp/t.jpg', 'mime' => 'image/jpeg', 'temporary' => true ) );
		$preparer->expects( $this->once() )->method( 'cleanup' );
		$provider = $this->provider( new Description_Result( 'd', array(), 'a' ) );
		( new Indexer( $preparer, function () use ( $provider ) { return $provider; } ) )->index_attachment( 6 );
	}

	public function test_build_search_text_is_lowercase_and_deduplicated_whitespace() {
		$this->assertSame( 'a red car. car red vehicle red car', Indexer::build_search_text( "A Red  Car.\n", array( 'car', 'Red', 'vehicle' ), ' Red Car ' ) );
	}

	public function test_payload_reads_meta() {
		$this->meta[8] = array( '_aims_description' => 'd', '_aims_tags' => array( 'x' ), '_aims_status' => 'indexed', '_aims_indexed_at' => 5 );
		$p = Indexer::payload( 8 );
		$this->assertSame( 8, $p['id'] );
		$this->assertSame( 'd', $p['description'] );
		$this->assertSame( array( 'x' ), $p['tags'] );
		$this->assertSame( 'indexed', $p['status'] );
		$this->assertSame( '', $p['error'] );
	}
}
