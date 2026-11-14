<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

/**
 * Store a code snippet as text and edit it with WordPress's code editor.
 */
class Code_Field extends Textarea_Field {
	protected $rows = 10;

	const LANGUAGES = array(
		'css' => 'text/css',
		'html' => 'text/html',
		'javascript' => 'application/javascript',
		'json' => 'application/json',
		'text' => 'text/plain',
	);

	protected $language = 'css';

	/**
	 * Set the syntax highlighting language.
	 *
	 * @param string $language css, html, javascript, json, or text.
	 * @return static
	 */
	public function set_language( $language ) {
		if ( ! is_string( $language ) || ! isset( self::LANGUAGES[ $language ] ) ) {
			Incorrect_Syntax_Exception::raise( 'Unsupported code field language.' );
			return $this;
		}
		$this->language = $language;
		return $this;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['code_language'] = $this->language;
		return $data;
	}
}
