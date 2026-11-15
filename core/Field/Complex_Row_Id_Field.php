<?php

namespace Carbon_Fields\Field;

/**
 * Store row identity with the other children, including in revision snapshots.
 */
final class Complex_Row_Id_Field extends Hidden_Field {
	public function __construct() {
		parent::__construct( 'hidden', Complex_Field::ROW_ID_PROPERTY, '' );
		$this->set_hidden();
	}

	public function get_type() {
		return 'hidden';
	}

	public function to_json( $load ) {
		return parent::to_json( $load ) + array( 'complex_row_id' => true );
	}
}
