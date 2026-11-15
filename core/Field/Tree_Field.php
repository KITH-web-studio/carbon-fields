<?php

namespace Carbon_Fields\Field;

class Tree_Field extends Structured_Field {
	protected $max_depth = 10;

	public function set_max_depth( $depth ) {
		$this->max_depth = max( 1, min( 100, (int) $depth ) );
		return $this;
	}

	public function validate_data( $value ) {
		if ( ! $this->valid_list( $value ) ) {
			return false;
		}
		$nodes = array();
		foreach ( $value as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,80}$/D', $node['id'] ) ||
				isset( $nodes[ $node['id'] ] ) || ! is_string( $node['label'] ?? null ) ||
				( null !== ( $node['parent'] ?? null ) && ! is_string( $node['parent'] ) ) ) {
				return false;
			}
			$nodes[ $node['id'] ] = $node;
		}
		foreach ( $nodes as $id => $node ) {
			$visited = array( $id => true );
			$parent = $node['parent'] ?? null;
			while ( null !== $parent ) {
				if ( ! isset( $nodes[ $parent ] ) || isset( $visited[ $parent ] ) || count( $visited ) >= $this->max_depth ) {
					return false;
				}
				$visited[ $parent ] = true;
				$parent = $nodes[ $parent ]['parent'] ?? null;
			}
		}
		return true;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['max_depth'] = $this->max_depth;
		return $data;
	}
}
