<?php

namespace Carbon_Fields\Integration;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Service\Revisions_Service;

/**
 * Native initial copies plus transactional, field-level translation synchronization.
 */
final class Polylang_Post_Meta {
	public static function boot() {
		add_filter( 'pll_copy_post_metas', array( __CLASS__, 'copy_meta_keys' ), 99, 5 );
		add_action( 'pll_save_post', array( __CLASS__, 'synchronize_post' ), 99 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'prepare_new_post_editor' ), 99, 2 );
	}

	/**
	 * Polylang copies into the new auto-draft before meta boxes are rendered.
	 * There is no ?post= ID on that screen, so bind CF to the editor's post.
	 */
	public static function prepare_new_post_editor( $post_type, $post ) {
		global $pagenow;
		if ( 'post-new.php' !== $pagenow || ! Polylang::active() || ! ( $post instanceof \WP_Post ) ||
			! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		foreach ( Carbon_Fields::resolve( 'container_repository' )->get_containers( 'post_meta' ) as $container ) {
			if ( $container->is_valid_attach_for_object( $post->ID ) ) {
				$container->set_post_id( $post->ID );
			}
		}
	}

	private static function fields() {
		$fields = array();
		foreach ( Carbon_Fields::resolve( 'container_repository' )->get_containers( 'post_meta' ) as $container ) {
			foreach ( $container->get_fields() as $field ) {
				if ( $field->get_datastore() && get_class( $field->get_datastore() ) === Post_Meta_Datastore::class ) {
					$fields[ $field->get_name() ] = array( $field, $container );
				}
			}
		}
		return $fields;
	}

	private static function root_key( $key ) {
		return is_string( $key ) ? explode( '|', $key, 2 )[0] : '';
	}

	public static function copy_meta_keys( $keys, $sync = false, $from = 0, $to = 0, $language = '' ) {
		if ( ! Polylang::active() || ! $from || ! $to || wp_is_post_revision( $from ) || wp_is_post_revision( $to ) ) {
			return $keys;
		}
		$fields = self::fields();
		// CF sync runs after complete fields are collected, under locks on all translations.
		$keys = array_values( array_filter( (array) $keys, function( $key ) use ( $fields ) {
			return Revisions_Service::SNAPSHOT_SCOPE_KEY !== $key && ! isset( $fields[ self::root_key( $key ) ] );
		} ) );
		if ( $sync || ! current_user_can( 'edit_post', $from ) || ! current_user_can( 'edit_post', $to ) ) {
			return $keys;
		}
		$source = get_post_meta( $from );
		$existing = get_post_meta( $to );
		$occupied = array_fill_keys( array_map( array( __CLASS__, 'root_key' ), array_keys( $existing ) ), true );
		$copy_roots = array();
		foreach ( $fields as $root => $entry ) {
			list( $field, $container ) = $entry;
			if ( 'independent' !== $field->get_translation() && ! isset( $occupied[ $root ] ) &&
				$container->is_valid_attach_for_object( $from ) && $container->is_valid_attach_for_object( $to ) ) {
				$copy_roots[ $root ] = true;
			}
		}
		foreach ( array_keys( $source ) as $key ) {
			if ( isset( $copy_roots[ self::root_key( $key ) ] ) ) {
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * False means that the entire source write must fail, rather than sync partially.
	 */
	public static function synchronization_targets( $post_id, Field $field ) {
		if ( 'sync' !== $field->get_translation() || ! Polylang::active() ||
			! function_exists( 'pll_get_post_translations' ) || wp_is_post_revision( $post_id ) ) {
			return array();
		}
		$fields = self::fields();
		if ( ! isset( $fields[ $field->get_name() ] ) ) {
			return array();
		}
		$container = $fields[ $field->get_name() ][1];
		if ( ! $container->is_valid_attach_for_object( $post_id ) ) {
			return array();
		}
		$targets = array();
		foreach ( (array) pll_get_post_translations( $post_id ) as $target ) {
			$target = (int) $target;
			if ( $target === (int) $post_id || $target < 1 || wp_is_post_revision( $target ) ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_post', $target ) ) {
				return false;
			}
			if ( get_post_type( $target ) !== get_post_type( $post_id ) || ! $container->is_valid_attach_for_object( $target ) ) {
				continue;
			}
			$targets[] = $target;
		}
		return array_values( array_unique( $targets ) );
	}

	public static function synchronize_post( $post_id ) {
		if ( ! Polylang::active() || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$meta = get_post_meta( $post_id );
		$roots = array_fill_keys( array_map( array( __CLASS__, 'root_key' ), array_keys( $meta ) ), true );
		foreach ( self::fields() as $root => $entry ) {
			list( $field, $container ) = $entry;
			if ( 'sync' !== $field->get_translation() || ! isset( $roots[ $root ] ) || ! $container->is_valid_attach_for_object( $post_id ) ) {
				continue;
			}
			// Read raw keys with a private datastore. A shallow complex clone still
			// shares group definitions, so replacing its datastore mutates the form.
			$datastore = clone $field->get_datastore();
			$datastore->set_object_id( $post_id );
			if ( false === $datastore->synchronize_current_field( $field ) ) {
				do_action( 'carbon_fields_translation_sync_failed', $post_id, $field );
			}
		}
	}

	/**
	 * Include synchronized roots in the revision service's existing transaction.
	 * The map scopes each translation to its shared roots only.
	 */
	public static function revision_targets( $post_id, $roots ) {
		$targets = array();
		if ( ! Polylang::active() ) {
			return $targets;
		}
		foreach ( self::fields() as $root => $entry ) {
			if ( ! in_array( $root, $roots, true ) ) {
				continue;
			}
			$ids = self::synchronization_targets( $post_id, $entry[0] );
			if ( false === $ids ) {
				return false;
			}
			foreach ( $ids as $id ) {
				$targets[ $id ][] = $root;
			}
		}
		return $targets;
	}
}
