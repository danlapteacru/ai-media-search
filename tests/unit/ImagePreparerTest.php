<?php
namespace AIMS\Tests;

use AIMS\Image_Preparer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ImagePreparerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'path_join' )->alias( function ( $a, $b ) { return rtrim( $a, '/' ) . '/' . $b; } );
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		Functions\when( 'wp_rand' )->justReturn( 1234 );
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_eligible_mimes() {
		$this->assertTrue( Image_Preparer::is_eligible_mime( 'image/jpeg' ) );
		$this->assertTrue( Image_Preparer::is_eligible_mime( 'image/webp' ) );
		$this->assertTrue( Image_Preparer::is_eligible_mime( 'application/pdf' ) );
		$this->assertFalse( Image_Preparer::is_eligible_mime( 'image/svg+xml' ) );
		$this->assertFalse( Image_Preparer::is_eligible_mime( 'video/mp4' ) );
	}

	public function test_choose_size_prefers_smallest_size_over_min_edge() {
		$meta = array(
			'width'  => 4000,
			'height' => 3000,
			'sizes'  => array(
				'thumbnail'    => array( 'width' => 150, 'height' => 150 ),
				'medium'       => array( 'width' => 300, 'height' => 225 ),
				'medium_large' => array( 'width' => 768, 'height' => 576 ),
				'large'        => array( 'width' => 1024, 'height' => 768 ),
				'1536x1536'    => array( 'width' => 1536, 'height' => 1152 ),
			),
		);
		$this->assertSame( 'large', Image_Preparer::choose_size( $meta ) );
	}

	public function test_choose_size_uses_full_when_original_is_small_enough() {
		$meta = array( 'width' => 1200, 'height' => 800, 'sizes' => array( 'thumbnail' => array( 'width' => 150, 'height' => 150 ) ) );
		$this->assertSame( 'full', Image_Preparer::choose_size( $meta ) );
	}

	public function test_choose_size_returns_null_when_resize_needed() {
		$meta = array( 'width' => 5000, 'height' => 5000, 'sizes' => array( 'thumbnail' => array( 'width' => 150, 'height' => 150 ) ) );
		$this->assertNull( Image_Preparer::choose_size( $meta ) );
	}

	public function test_choose_size_small_image_with_no_sizes_uses_full() {
		$this->assertSame( 'full', Image_Preparer::choose_size( array( 'width' => 300, 'height' => 200 ) ) );
	}

	public function test_prepare_rejects_unsupported_mime() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'video/mp4' );
		$result = ( new Image_Preparer() )->prepare( 5 );
		$this->assertSame( 'unsupported', $result->get_error_code() );
	}

	public function test_prepare_no_file_when_attached_file_missing() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		Functions\when( 'get_attached_file' )->justReturn( '' );
		Functions\expect( 'wp_get_image_editor' )->never();

		$result = ( new Image_Preparer() )->prepare( 11 );
		$this->assertSame( 'no_file', $result->get_error_code() );
	}

	public function test_prepare_image_returns_chosen_size_path() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/photo.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'width'  => 4000,
				'height' => 3000,
				'sizes'  => array( 'large' => array( 'file' => 'photo-1024x768.jpg', 'width' => 1024, 'height' => 768, 'mime-type' => 'image/jpeg' ) ),
			)
		);
		$result = ( new Image_Preparer() )->prepare( 5 );
		$this->assertSame( '/uploads/2026/09/photo-1024x768.jpg', $result['path'] );
		$this->assertSame( 'image/jpeg', $result['mime'] );
		$this->assertFalse( $result['temporary'] );
	}

	public function test_prepare_pdf_uses_generated_preview() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/brochure.pdf' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'full'  => array( 'file' => 'brochure-pdf.jpg', 'width' => 1058, 'height' => 1497, 'mime-type' => 'image/jpeg' ),
					'large' => array( 'file' => 'brochure-pdf-724x1024.jpg', 'width' => 724, 'height' => 1024, 'mime-type' => 'image/jpeg' ),
				),
			)
		);
		$result = ( new Image_Preparer() )->prepare( 7 );
		$this->assertSame( '/uploads/2026/09/brochure-pdf-724x1024.jpg', $result['path'] );
		$this->assertSame( 'image/jpeg', $result['mime'] );
	}

	public function test_prepare_pdf_without_preview_is_no_preview() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/brochure.pdf' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array() );
		$result = ( new Image_Preparer() )->prepare( 7 );
		$this->assertSame( 'no_preview', $result->get_error_code() );
	}

	public function test_prepare_resizes_when_no_size_fits() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000, 'sizes' => array() ) );

		$editor = new class() {
			public $resized;
			public function resize( $w, $h, $crop ) { $this->resized = array( $w, $h, $crop ); return true; }
			public function save( $dest ) { return array( 'path' => $dest, 'mime-type' => 'image/png' ); }
		};
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$result = ( new Image_Preparer() )->prepare( 9 );
		$this->assertTrue( $result['temporary'] );
		$this->assertSame( 'image/png', $result['mime'] );
		$this->assertStringContainsString( 'aims-9', $result['path'] );
		$this->assertSame( array( 1600, 1600, false ), $editor->resized );
	}

	public function test_prepare_resize_failure() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000 ) );
		Functions\when( 'wp_get_image_editor' )->justReturn( new \WP_Error( 'image_no_editor', 'no editor' ) );
		$result = ( new Image_Preparer() )->prepare( 9 );
		$this->assertSame( 'resize_failed', $result->get_error_code() );
	}

	public function test_prepare_resize_failure_when_editor_resize_fails() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000, 'sizes' => array() ) );

		$editor = new class() {
			public function resize( $w, $h, $crop ) { return new \WP_Error( 'image_resize_error', 'Could not resize image.' ); }
			public function save( $dest ) { return array( 'path' => $dest, 'mime-type' => 'image/png' ); }
		};
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$result = ( new Image_Preparer() )->prepare( 9 );
		$this->assertSame( 'resize_failed', $result->get_error_code() );
		$this->assertSame( 'Could not resize image.', $result->get_error_message() );
	}

	public function test_prepare_resize_failure_when_editor_save_fails() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000, 'sizes' => array() ) );

		$editor = new class() {
			public function resize( $w, $h, $crop ) { return true; }
			public function save( $dest ) { return new \WP_Error( 'image_save_error', 'Could not save image.' ); }
		};
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$result = ( new Image_Preparer() )->prepare( 9 );
		$this->assertSame( 'resize_failed', $result->get_error_code() );
		$this->assertSame( 'Could not save the resized copy.', $result->get_error_message() );
	}

	public function test_prepare_resize_failure_when_saved_path_missing() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000, 'sizes' => array() ) );

		$editor = new class() {
			public function resize( $w, $h, $crop ) { return true; }
			public function save( $dest ) { return array( 'mime-type' => 'image/png' ); }
		};
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$result = ( new Image_Preparer() )->prepare( 9 );
		$this->assertSame( 'resize_failed', $result->get_error_code() );
	}

	public function test_cleanup_removes_only_temporary_files() {
		$tmp = tempnam( sys_get_temp_dir(), 'aims' );
		( new Image_Preparer() )->cleanup( array( 'path' => $tmp, 'mime' => 'image/png', 'temporary' => true ) );
		$this->assertFileDoesNotExist( $tmp );

		$keep = tempnam( sys_get_temp_dir(), 'aims' );
		( new Image_Preparer() )->cleanup( array( 'path' => $keep, 'mime' => 'image/png', 'temporary' => false ) );
		$this->assertFileExists( $keep );
		unlink( $keep );
	}
}
