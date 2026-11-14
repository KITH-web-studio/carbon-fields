<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Datastore\Taxonomy_Datastore;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

/**
 * Edit a post's native WordPress term assignments.
 */
class Taxonomy_Field extends Association_Field {
	protected $association_layout = 'multiselect';
	protected $types = array();

	private $taxonomy = '';
	private $submitted = null;
	private $valid_value = true;

	public function __construct( $type, $name, $label ) {
		parent::__construct( $type, $name, $label );
		$this->set_datastore( new Taxonomy_Datastore() );
	}

	public function set_taxonomy( $taxonomy ) {
		if ( ! is_string( $taxonomy ) || '' === $taxonomy || sanitize_key( $taxonomy ) !== $taxonomy ) {
			Incorrect_Syntax_Exception::raise( 'A taxonomy field requires a registered taxonomy name.' );
			return $this;
		}
		$this->taxonomy = $taxonomy;
		$this->types = array( array( 'type' => 'term', 'taxonomy' => $taxonomy ) );
		return $this;
	}

	public function get_taxonomy() {
		return $this->taxonomy;
	}

	public function can_assign_terms() {
		$taxonomy = get_taxonomy( $this->taxonomy );
		return $taxonomy && current_user_can( $taxonomy->cap->assign_terms );
	}

	public function can_read_option( $id, $type, $subtype ) {
		if ( 'term' !== $type || $this->taxonomy !== $subtype || ! $this->can_assign_terms() ) {
			return false;
		}
		return get_term( (int) $id, $this->taxonomy ) instanceof \WP_Term;
	}

	public function set_value_from_input( $input ) {
		if ( ! array_key_exists( $this->get_name(), $input ) ) {
			$this->load();
			$this->submitted = false;
			return $this;
		}
		parent::set_value_from_input( $input );
		$this->submitted = true;
		return $this;
	}

	public function set_value( $value ) {
		$this->submitted = null;
		$this->valid_value = is_array( $value );
		$terms = array();
		foreach ( (array) $value as $entry ) {
			if ( '' === $entry ) {
				continue;
			}
			if ( is_array( $entry ) && ( $entry['type'] ?? '' ) === 'term' && ( $entry['subtype'] ?? '' ) === $this->taxonomy ) {
				$entry = $entry['id'] ?? 0;
			}
			if ( is_string( $entry ) && 0 === strpos( $entry, 'term:' . $this->taxonomy . ':' ) ) {
				$entry = substr( $entry, strlen( $this->taxonomy ) + 6 );
			}
			if ( ( ! is_int( $entry ) && ! is_string( $entry ) ) || ! ctype_digit( (string) $entry ) ||
				(int) $entry < 1 || (string) (int) $entry !== (string) $entry ) {
				$this->valid_value = false;
				continue;
			}
			$terms[ (int) $entry ] = 'term:' . $this->taxonomy . ':' . (int) $entry;
		}
		return parent::set_value( array_values( $terms ) );
	}

	public function get_formatted_value() {
		return array_map( 'intval', array_column( $this->get_value(), 'id' ) );
	}

	public function save() {
		if ( false === $this->submitted || ( $this->submitted && wp_is_post_revision( $this->get_datastore()->get_object_id() ) ) ) {
			return true;
		}
		$count = count( $this->get_formatted_value() );
		return $this->valid_value && 'post_meta' === $this->get_context() && ! $this->get_hierarchy() &&
			$this->get_datastore() instanceof Taxonomy_Datastore && ( ! $this->is_required() || $count > 0 ) &&
			$count >= $this->get_min() && ( $this->get_max() < 0 || $count <= $this->get_max() ) &&
			$this->get_datastore()->save( $this );
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['taxonomy_disabled'] = ! $this->can_assign_terms();
		if ( $data['taxonomy_disabled'] ) {
			$data['value'] = array();
			$data['required'] = false;
		}
		return $data;
	}

	public function get_options( $args = array() ) {
		return $this->can_assign_terms() ? parent::get_options( $args ) : array( 'options' => array(), 'total_options' => 0 );
	}
}
