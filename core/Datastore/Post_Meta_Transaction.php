<?php

namespace Carbon_Fields\Datastore;

/**
 * Runs changes to standard post metadata under a lock on the owning post.
 */
final class Post_Meta_Transaction {

	private static $active = false;
	private static $transactional_tables = array();
	private static $depth = 0;

	public static function is_active() {
		return self::$active;
	}

	/** Check whether a caller can own the full lifetime of a write transaction. */
	public static function can_start_transaction() {
		global $wpdb;
		if ( self::$depth || ! ( $wpdb->dbh instanceof \mysqli ) ) {
			return false;
		}
		$scope = self::probe_savepoint();
		if ( is_string( $scope ) && '' !== $scope ) {
			$wpdb->query( 'RELEASE SAVEPOINT ' . $scope );
		}
		return '' === $scope;
	}

	/**
	 * @param int[] $post_ids Posts and revisions participating in the operation.
	 * @param callable $write Returns true only after all required writes succeed.
	 * @return bool
	 */
	public static function run( $post_ids, $write ) {
		return self::execute( $post_ids, $write, true );
	}

	/**
	 * Own an outer transaction while normal field saves use nested savepoints.
	 * Each normal save retains its re-entrancy guard.
	 */
	public static function batch( array $post_ids, callable $write ) {
		if ( self::$depth > 0 ) { return false; }
		return self::execute( $post_ids, $write, false );
	}

	private static function execute( $post_ids, $write, $guard ) {
		global $wpdb;

		if ( self::$active || ! ( $wpdb->dbh instanceof \mysqli ) || ! self::supports_transactions() ) {
			return false;
		}

		$post_ids = array_unique( array_map( 'intval', $post_ids ) );
		sort( $post_ids, SORT_NUMERIC );
		if ( empty( $post_ids ) || $post_ids[0] < 1 ) {
			return false;
		}

		self::$active = $guard;
		++self::$depth;
		\Carbon_Fields\Helper\Field_Events::begin();
		\Carbon_Fields\Helper\Post_Facet_Cache::begin_write();
		$cache_was_suspended = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );
		// A reconnect can replay a write after the database has rolled back the transaction.
		$reconnect_retries = $wpdb->reconnect_retries;
		$wpdb->reconnect_retries = 0;

		$scope = false;
		$committed = false;
		try {
			$scope = self::begin();
			if ( false === $scope ) {
				return false;
			}

			$ids = implode( ',', $post_ids );
			$locked = $wpdb->query( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$ids}) ORDER BY ID FOR UPDATE" );
			if ( $locked !== count( $post_ids ) || true !== call_user_func( $write ) ) {
				return false;
			}

			$commit = '' === $scope ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $scope;
			$committed = false !== $wpdb->query( $commit );
			return $committed;
		} finally {
			try {
				if ( false !== $scope && ! $committed ) {
					if ( '' === $scope ) {
						$wpdb->query( 'ROLLBACK' );
					} elseif ( false !== $wpdb->query( 'ROLLBACK TO SAVEPOINT ' . $scope ) ) {
						$wpdb->query( 'RELEASE SAVEPOINT ' . $scope );
					}
				}
			} finally {
				$wpdb->reconnect_retries = $reconnect_retries;
				wp_suspend_cache_addition( $cache_was_suspended );
				self::$active = false;
				--self::$depth;
				foreach ( $post_ids as $post_id ) {
					wp_cache_delete( $post_id, 'post_meta' );
					if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
						wp_cache_delete( 'product-' . $post_id, 'products' );
					}
				}
				Post_Meta_Datastore::invalidate_storage_cache();
				if ( 0 === self::$depth ) {
					\Carbon_Fields\Helper\Field_Rules::release_locks();
				}
				\Carbon_Fields\Helper\Post_Facet_Cache::finish_write( $committed );
				\Carbon_Fields\Helper\Field_Events::finish( $committed );
			}
		}
	}

	private static function supports_transactions() {
		global $wpdb;

		$key = $wpdb->posts . ':' . $wpdb->postmeta;
		if ( ! isset( self::$transactional_tables[ $key ] ) ) {
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s) AND ENGINE = 'InnoDB'",
				$wpdb->posts,
				$wpdb->postmeta
			) );
			self::$transactional_tables[ $key ] = ( 2 === (int) $count );
		}
		return self::$transactional_tables[ $key ];
	}

	/**
	 * A savepoint survives the probe only when the caller already owns a transaction.
	 *
	 * @return string|false Empty string for a transaction owned here, or the caller's savepoint name.
	 */
	private static function begin() {
		global $wpdb;
		$savepoint = self::probe_savepoint();
		if ( '' !== $savepoint ) {
			return $savepoint;
		}
		return false === $wpdb->query( 'START TRANSACTION' ) ? false : '';
	}

	/** @return string|false Existing transaction savepoint, empty for autocommit, false on failure. */
	private static function probe_savepoint() {
		global $wpdb;
		$savepoint = uniqid( 'kith_cf_' );
		$was_suppressed = $wpdb->suppress_errors( true );
		try {
			if ( false === $wpdb->query( 'SAVEPOINT ' . $savepoint ) ) {
				return false;
			}
			try {
				if ( false !== $wpdb->query( 'ROLLBACK TO SAVEPOINT ' . $savepoint ) ) {
					return $savepoint;
				}
				$error_code = mysqli_errno( $wpdb->dbh );
			} catch ( \mysqli_sql_exception $exception ) {
				// Older WordPress can leave mysqli in strict reporting mode.
				if ( 1305 !== $exception->getCode() ) {
					throw $exception;
				}
				$error_code = 1305;
			}
			if ( 1305 !== $error_code ) {
				return false;
			}
		} finally {
			$wpdb->suppress_errors( $was_suppressed );
		}

		return '';
	}
}
