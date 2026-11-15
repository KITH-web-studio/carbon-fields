<?php

namespace Carbon_Fields\Field;

trait Media_Library_Scope {
	protected $media_library_scope = 'all';

	public function set_media_library_scope( $scope ) {
		if ( ! in_array( $scope, array( 'all', 'post' ), true ) ) {
			throw new \InvalidArgumentException( 'Media library scope must be all or post.' );
		}
		$this->media_library_scope = $scope;
		return $this;
	}
}
