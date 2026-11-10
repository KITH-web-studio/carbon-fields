<?php

// Modified by KITH Web Studio on 2026-11-10 for exact transactional revision snapshots and cache invalidation.

namespace Carbon_Fields\Service;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Datastore\Post_Meta_Transaction;
use Carbon_Fields\Toolset\Key_Toolset;

class Revisions_Service extends Service {
	const CHANGE_KEY = 'carbon_fields_changed';
	const SNAPSHOT_SCOPE_KEY = '_carbon_fields:revision_scope';

	private $revisions_created_during_restore = array();

	protected function enabled() {
		add_filter( 'carbon_get_post_meta_post_id', array( $this, 'update_post_id_on_preview' ), 10, 3 );
		add_action( 'carbon_fields_post_meta_container_saved', array( $this, 'maybe_copy_meta_to_revision' ), 10, 2 );
		add_filter('_wp_post_revision_fields', array( $this, 'maybe_save_revision' ), 10, 2 );
		add_filter('_wp_post_revision_fields', array( $this, 'add_fields_to_revision' ), 10, 2 );
		add_action( '_wp_put_post_revision', array( $this, 'record_revision_created_during_restore' ), 10, 2 );
		add_action( 'wp_restore_post_revision', array( $this, 'restore_post_revision' ), 10, 2 );
		add_filter( 'wp_save_post_revision_check_for_changes', array( $this, 'check_for_changes' ), 10, 3 );
	}

	protected function disabled() {
		remove_filter( 'carbon_get_post_meta_post_id', array( $this, 'update_post_id_on_preview' ), 10 );
		remove_action( 'carbon_fields_post_meta_container_saved', array( $this, 'maybe_copy_meta_to_revision' ), 10 );
		remove_filter('_wp_post_revision_fields', array( $this, 'maybe_save_revision' ), 10 );
		remove_filter('_wp_post_revision_fields', array( $this, 'add_fields_to_revision' ), 10 );
		remove_action( '_wp_put_post_revision', array( $this, 'record_revision_created_during_restore' ), 10 );
		remove_action( 'wp_restore_post_revision', array( $this, 'restore_post_revision' ), 10 );
		$this->revisions_created_during_restore = array();
		remove_filter( 'wp_save_post_revision_check_for_changes', array( $this, 'check_for_changes' ), 10 );
	}

	public function check_for_changes( $return, $last_revision, $post ) {
		if ( empty( $_POST[ self::CHANGE_KEY ] ) ) {
			return $return;
		}

		return false;
	}

	public function update_post_id_on_preview( $id, $name, $container_id ) {
		if ( empty( $_GET['preview'] ) ) {
			return $id;
		}

		$post_type = get_post_type( $id );
		if ( $post_type === 'revision' ) {
			return $id;
		}

		$preview_id = $this->get_preview_id();
		if ( ! $preview_id || $preview_id !== intval( $id ) ) {
			return $id;
		}

		$nonce = ( ! empty( $_GET['preview_nonce'] ) ) ? $_GET['preview_nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'post_preview_' . $preview_id ) ) {
			return $id;
		}

		$revision = $this->get_latest_post_revision( $id );
		if ( empty( $revision ) ) {
			return $id;
		}

		return $revision->ID;
	}

	/**
	 * @param int $post_id
	 * @param \Carbon_Fields\Container\Post_Meta_Container $container
	 */
	public function maybe_copy_meta_to_revision( $post_id, $container ) {
		if ( ! $container || $container->get_revisions_disabled() ) {
			return;
		}

		if ( doing_action( 'save_post' ) && $this->get_restoring_revision_id() ) {
			return;
		}

		$revision = $this->get_latest_post_revision( $post_id );
		if ( empty( $revision ) ) {
			return;
		}

		return $this->copy_meta( $post_id, array( $revision->ID ), false );
	}

	public function maybe_save_revision( $fields, $post = null ) {
		$wp_preview = ( ! empty( $_POST['wp-preview'] ) ) ? $_POST['wp-preview'] : '';
		$in_preview = $wp_preview === 'dopreview';

		if ( ! $in_preview ) {
			return $fields;
		}

		if ( ! empty( $_POST[ self::CHANGE_KEY ] ) ) {
			$fields[ self::CHANGE_KEY ] = 2;
		}

		return $fields;
	}

	public function add_fields_to_revision( $fields, $post = null ) {
		// When Gutenberg is saving the post
		// this function isn't defined yet.
		// Also we don't need it at all because the metaboxes
		// are saved by separate request.
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $fields;
		}

		$current_screen = get_current_screen();

		$is_revision_screen = $current_screen && $current_screen->id === 'revision';
		$is_doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
		$is_revision_action = ! empty( $_POST['action'] ) && $_POST['action'] === 'get-revision-diffs';
		$is_revision_diff_ajax = $is_doing_ajax && $is_revision_action;
		$is_restoring = ! empty( $_GET['action'] ) && $_GET['action'] === 'restore';

		// exit early if not on the revision screens or if a restore revision is in progress
		if ( $is_restoring || ( ! $is_revision_screen && ! $is_revision_diff_ajax ) ) {
			return $fields;
		}

		if ( $post && ! empty( $post['ID'] ) ) {
			$post_id = $post['ID'];
		} else {
			global $post;
			$post_id = $post->ID;
		}

		$revisioned_fields = $this->get_revisioned_fields();
		$fields = array_merge( $fields, $revisioned_fields );
		// this hook is used when displaying the field values
		foreach ( $revisioned_fields as $name => $label ) {
			add_filter( "_wp_post_revision_field_{$name}", array( $this, 'update_revision_field_value' ), 10, 4 );
		}

		return $fields;
	}

	/**
	 * @param mixed $value
	 * @param string $field_name
	 * @param \WP_Post $post
	 * @param bool $direction
	 *
	 * @return int|mixed
	 */
	public function update_revision_field_value( $value, $field_name, $post = null, $direction = false ) {
		if ( empty( $post ) ) {
			return $value;
		}

		$value = carbon_get_post_meta( $post->ID, substr( $field_name, 1 ) );
		if ( is_array( $value ) ) {
			$value = count( $value );
		}
		return $value;
	}

	/**
	 * Keep the ID emitted by WordPress while it updates the post for this restore.
	 *
	 * @param int $revision_id Newly created revision.
	 * @param int|null $post_id Parent post, supplied by WordPress 6.4 and later.
	 */
	public function record_revision_created_during_restore( $revision_id, $post_id = null ) {
		$source_id = $this->get_restoring_revision_id();
		if ( ! $source_id ) {
			return;
		}

		$post_id = $post_id ? (int) $post_id : (int) wp_is_post_revision( $revision_id );
		if ( $post_id && (int) wp_is_post_revision( $source_id ) === $post_id ) {
			$this->revisions_created_during_restore[ $source_id ] = (int) $revision_id;
		}
	}

	/**
	 * WordPress signals a restore after wp_update_post has already saved its revision.
	 * Identify the source from the direct caller of that update.
	 *
	 * @return int
	 */
	private function get_restoring_revision_id() {
		$trace = debug_backtrace();
		foreach ( $trace as $index => $frame ) {
			if ( 'wp_update_post' !== $frame['function'] || isset( $frame['class'] ) ) {
				continue;
			}
			$caller = isset( $trace[ $index + 1 ] ) ? $trace[ $index + 1 ] : array();
			if ( ! isset( $caller['function'], $caller['args'][0] ) ||
				'wp_restore_post_revision' !== $caller['function'] || isset( $caller['class'] ) ) {
				return 0;
			}
			$source = $caller['args'][0];
			if ( $source instanceof \WP_Post ) {
				return (int) $source->ID;
			}
			if ( is_array( $source ) ) {
				return isset( $source['ID'] ) ? (int) $source['ID'] : 0;
			}
			return (int) $source;
		}
		return 0;
	}

	public function restore_post_revision( $post_id, $revision_id ) {
		$targets = array( (int) $post_id );
		if ( isset( $this->revisions_created_during_restore[ $revision_id ] ) ) {
			$created_id = $this->revisions_created_during_restore[ $revision_id ];
			unset( $this->revisions_created_during_restore[ $revision_id ] );
			if ( $created_id !== (int) $revision_id && (int) wp_is_post_revision( $created_id ) === (int) $post_id ) {
				$targets[] = $created_id;
			}
		}

		$restored = $this->copy_meta( $revision_id, $targets, true );
		if ( ! $restored && doing_action( 'wp_restore_post_revision' ) ) {
			wp_die( esc_html__( 'Carbon Fields could not restore the field values. Please try again.', 'carbon-fields' ), '', array( 'response' => 500, 'back_link' => true ) );
		}
		return $restored;
	}

	protected function get_revisioned_fields() {
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$containers = $repository->get_containers( 'post_meta' );
		$containers = array_filter( $containers, function( $container ) {
			/** @var \Carbon_Fields\Container\Post_Meta_Container $container */
			return !$container->get_revisions_disabled();
		} );
		$fields = array();
		foreach ( $containers as $container ) {
			/** @var \Carbon_Fields\Container\Post_Meta_Container $container */
			foreach ( $container->get_fields() as $field ) {
				$fields[ $field->get_name() ] = $field->get_label();
			}
		}

		return $fields;
	}

	protected function get_latest_post_revision( $post_id ) {
		$revisions = wp_get_post_revisions( $post_id );
		$revision = array_shift($revisions);

		return $revision;
	}

	protected function get_preview_id() {
		if( isset( $_GET['preview_id'] ) ) {
			return intval( $_GET['preview_id'] );
		}

		if( isset( $_GET['p'] ) ) {
			return intval( $_GET['p'] );
		}

		if( isset( $_GET['page_id'] ) ) {
			return intval( $_GET['page_id'] );
		}

		return 0;
	}

	protected function copy_meta( $from_id, $targets, $restoring ) {
		$from_id = (int) $from_id;
		$patterns = $this->get_snapshot_patterns();
		if ( empty( $patterns ) ) {
			return true;
		}

		$post_ids = array_merge( array( $from_id ), $targets );
		return Post_Meta_Transaction::run( $post_ids, function() use ( $from_id, $targets, $restoring, $post_ids, $patterns ) {
			$meta = $this->read_snapshot_rows( $post_ids, $patterns );
			if ( false === $meta ) {
				return false;
			}

			$source = isset( $meta[ $from_id ] ) ? $meta[ $from_id ] : array();
			$scope = $restoring ? $this->get_snapshot_scope( $source ) : array_keys( $patterns );
			$scope = array_intersect( $scope, array_keys( $patterns ) );
			if ( empty( $scope ) ) {
				return true;
			}

			foreach ( $targets as $index => $target ) {
				$current = isset( $meta[ $target ] ) ? $meta[ $target ] : array();
				$wanted = $this->collect_snapshot_keys( $source, $scope );
				$existing = $this->collect_snapshot_keys( $current, $scope );

				// Only revisions carry the manifest, including scopes that contained no stored keys.
				if ( ! $restoring || 0 !== $index ) {
					$manifest = $restoring ? array_merge( $scope, $this->get_snapshot_scope( $current ) ) : $scope;
					$manifest = array_values( array_unique( $manifest ) );
					sort( $manifest, SORT_STRING );
					$wanted[ self::SNAPSHOT_SCOPE_KEY ] = array( array( 'value' => wp_json_encode( $manifest ) ) );
					if ( isset( $current[ self::SNAPSHOT_SCOPE_KEY ] ) ) {
						$existing += $current[ self::SNAPSHOT_SCOPE_KEY ];
					}
				}

				if ( ! $this->apply_snapshot( $target, $existing, $wanted ) ) {
					return false;
				}
			}
			return true;
		} );
	}

	/**
	 * Scope revisions to storage keys of registered, revision-enabled post fields.
	 */
	private function get_snapshot_patterns() {
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$key_toolset = Carbon_Fields::resolve( 'key_toolset' );
		$scopes = array();
		foreach ( $repository->get_containers( 'post_meta' ) as $container ) {
			if ( $container->get_revisions_disabled() ) {
				continue;
			}
			foreach ( $container->get_fields() as $field ) {
				if ( ! ( $field->get_datastore() instanceof Post_Meta_Datastore ) ) {
					continue;
				}
				$base = $field->get_base_name();
				$root = Key_Toolset::KEY_PREFIX . $base;
				$patterns = $key_toolset->get_storage_key_getter_patterns( $field->is_simple_root_field(), array( $base ) );
				$scopes[ $root ] = isset( $scopes[ $root ] ) ? $scopes[ $root ] + $patterns : $patterns;
			}
		}
		return $scopes;
	}

	/**
	 * Read source and destination rows in meta ID order, preserving duplicates and raw values.
	 */
	private function read_snapshot_rows( $post_ids, $scopes ) {
		global $wpdb;

		$patterns = array( self::SNAPSHOT_SCOPE_KEY => Key_Toolset::PATTERN_COMPARISON_EQUAL );
		foreach ( $scopes as $scope ) {
			$patterns += $scope;
		}
		$ids = implode( ',', array_map( 'intval', $post_ids ) );
		$where = Carbon_Fields::resolve( 'key_toolset' )->storage_key_patterns_to_sql( 'meta_key', $patterns );
		if ( false === $wpdb->query( "SELECT post_id, meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
			WHERE post_id IN ({$ids}) AND {$where} ORDER BY meta_id FOR UPDATE" ) ) {
			return false;
		}

		$result = array();
		foreach ( $wpdb->last_result as $row ) {
			$key = $row->meta_key;
			if ( self::SNAPSHOT_SCOPE_KEY === $key ) {
				$root = $key;
			} else {
				$segments = explode( Key_Toolset::SEGMENT_GLUE, $key, 2 );
				$root = $segments[0];
				if ( ! isset( $scopes[ $root ] ) ) {
					continue;
				}
				// The SQL matcher can be case-insensitive. Respect the exact field ownership here.
				$pattern = isset( $segments[1] ) ? $root . Key_Toolset::SEGMENT_GLUE : $root;
				if ( ! isset( $scopes[ $root ][ $pattern ] ) ) {
					continue;
				}
			}
			$result[ $row->post_id ][ $root ][ $key ][] = array( 'id' => (int) $row->meta_id, 'value' => $row->meta_value );
		}
		return $result;
	}

	private function get_snapshot_scope( $snapshot ) {
		if ( isset( $snapshot[ self::SNAPSHOT_SCOPE_KEY ][ self::SNAPSHOT_SCOPE_KEY ][0] ) ) {
			$marker = $snapshot[ self::SNAPSHOT_SCOPE_KEY ][ self::SNAPSHOT_SCOPE_KEY ][0];
			$scope = json_decode( $marker['value'], true );
			if ( is_array( $scope ) ) {
				return array_values( array_filter( $scope, 'is_string' ) );
			}
		}

		// A revision made before manifests can only claim fields for which it has data.
		unset( $snapshot[ self::SNAPSHOT_SCOPE_KEY ] );
		return array_keys( $snapshot );
	}

	private function collect_snapshot_keys( $snapshot, $scope ) {
		$result = array();
		foreach ( $scope as $root ) {
			if ( isset( $snapshot[ $root ] ) ) {
				$result += $snapshot[ $root ];
			}
		}
		return $result;
	}

	private function apply_snapshot( $post_id, $current, $desired ) {
		global $wpdb;

		$deletions = array();
		$insertions = array();
		foreach ( array_keys( $current + $desired ) as $key ) {
			$old = isset( $current[ $key ] ) ? $current[ $key ] : array();
			$new = isset( $desired[ $key ] ) ? $desired[ $key ] : array();
			if ( array_column( $old, 'value' ) === array_column( $new, 'value' ) ) {
				continue;
			}

			foreach ( $old as $row ) {
				$deletions[] = $row['id'];
			}
			foreach ( $new as $row ) {
				$insertions[] = null === $row['value']
					? $wpdb->prepare( '(%d, %s, NULL)', $post_id, $key )
					: $wpdb->prepare( '(%d, %s, %s)', $post_id, $key, $row['value'] );
			}
		}

		foreach ( array_chunk( $deletions, 250 ) as $chunk ) {
			$ids = implode( ',', $chunk );
			if ( false === $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id = {$post_id} AND meta_id IN ({$ids})" ) ) {
				return false;
			}
		}
		foreach ( array_chunk( $insertions, 250 ) as $chunk ) {
			$values = implode( ',', $chunk );
			if ( false === $wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES {$values}" ) ) {
				return false;
			}
		}
		return true;
	}
}
