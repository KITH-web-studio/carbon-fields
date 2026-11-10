<?php

namespace Carbon_Fields\Datastore;

/**
 * Runs changes to standard post metadata under a lock on the owning post.
 */
final class Post_Meta_Transaction {

	private static $active = false;
	private static $transactional_tables = array();

	public static function is_active() {
		return self::$active;
	}

	/**
	 * @param int[] $post_ids Posts and revisions participating in the operation.
	 * @param callable $write Returns true only after all required writes succeed.
	 * @return bool
	 */
	public static function run( $post_ids, $write ) {
		global $wpdb;

		if ( self::$active || ! ( $wpdb->dbh instanceof \mysqli ) || ! self::supports_transactions() ) {
			return false;
		}

		$post_ids = array_unique( array_map( 'intval', $post_ids ) );
		sort( $post_ids, SORT_NUMERIC );
		if ( empty( $post_ids ) || $post_ids[0] < 1 ) {
			return false;
		}

		self::$active = true;
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
				foreach ( $post_ids as $post_id ) {
					wp_cache_delete( $post_id, 'post_meta' );
				}
				Post_Meta_Datastore::invalidate_storage_cache();
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

		$savepoint = uniqid( 'kith_cf_' );
		$was_suppressed = $wpdb->suppress_errors( true );
		try {
			if ( false === $wpdb->query( 'SAVEPOINT ' . $savepoint ) ) {
				return false;
			}
			if ( false !== $wpdb->query( 'ROLLBACK TO SAVEPOINT ' . $savepoint ) ) {
				return $savepoint;
			}
			if ( 1305 !== mysqli_errno( $wpdb->dbh ) ) {
				return false;
			}
		} finally {
			$wpdb->suppress_errors( $was_suppressed );
		}

		return false === $wpdb->query( 'START TRANSACTION' ) ? false : '';
	}
}
