<?php
/**
 * Value object returned by a provider.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Description_Result {
	/** @var string */
	public $description;

	/** @var string[] */
	public $tags;

	/** @var string */
	public $alt;

	public function __construct( string $description, array $tags, string $alt ) {
		$this->description = $description;
		$this->tags        = array_values( $tags );
		$this->alt         = $alt;
	}

	public function to_array(): array {
		return array(
			'description' => $this->description,
			'tags'        => $this->tags,
			'alt'         => $this->alt,
		);
	}
}
