<?php

namespace KITH_Carbon_Fields;

/**
 * Connect the standalone plugin to its public GitHub release assets.
 */
final class Plugin_Updates {
	const REPOSITORY = 'https://github.com/KITH-web-studio/carbon-fields';
	const ENDPOINT = 'https://api.github.com/repos/KITH-web-studio/carbon-fields/releases/latest';
	const CACHE_KEY = 'kith_carbon_fields_github_release_v1';
	const SLUG = 'kith-carbon-fields';

	private $plugin_file;

	public function __construct( $plugin_file ) {
		$this->plugin_file = plugin_basename( $plugin_file );
	}

	public function register() {
		global $wp_version;
		add_filter( 'update_plugins_github.com', array( $this, 'update' ), 10, 3 );
		if ( version_compare( $wp_version, '5.8', '<' ) ) {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'legacy_update' ) );
		}
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'preserve_directory' ), 10, 4 );
		add_action( 'admin_init', array( $this, 'refresh_requested' ) );
	}

	public function update( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== $this->plugin_file ) {
			return $update;
		}
		$release = $this->latest_release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id' => self::REPOSITORY,
			'slug' => self::SLUG,
			'version' => $release['version'],
			'url' => $release['url'],
			'package' => $release['package'],
		);
	}

	public function legacy_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked[ $this->plugin_file ] ) ) {
			return $transient;
		}
		unset( $transient->response[ $this->plugin_file ], $transient->no_update[ $this->plugin_file ] );
		$update = $this->update( false, array(), $this->plugin_file );
		if ( ! $update ) {
			return $transient;
		}
		$update = (object) $update;
		$update->plugin = $this->plugin_file;
		$update->new_version = $update->version;
		if ( version_compare( $update->version, $transient->checked[ $this->plugin_file ], '>' ) ) {
			$transient->response[ $this->plugin_file ] = $update;
		} else {
			$transient->no_update[ $this->plugin_file ] = $update;
		}
		return $transient;
	}

	public function refresh_requested() {
		global $pagenow;
		if ( 'update-core.php' === $pagenow && ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	private function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && array_key_exists( 'release', $cached ) ) {
			return $cached['release'];
		}
		$response = wp_safe_remote_get( self::ENDPOINT, array(
			'timeout' => 5,
			'redirection' => 0,
			'limit_response_size' => 512 * 1024,
			'headers' => array(
				'Accept' => 'application/vnd.github+json',
				'User-Agent' => 'KITH-Carbon-Fields-Updater',
			),
		) );
		$release = false;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$release = $this->parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}
		set_site_transient( self::CACHE_KEY, array( 'release' => $release ), $release ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $release;
	}

	private function parse_release( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['draft'], $data['prerelease'], $data['tag_name'], $data['assets'] ) ||
			false !== $data['draft'] || false !== $data['prerelease'] ||
			! is_string( $data['tag_name'] ) || ! is_array( $data['assets'] ) ||
			! preg_match( '/^v?([0-9]+\\.[0-9]+\\.[0-9]+(?:\\.[0-9]+)?)$/D', $data['tag_name'], $match ) ) {
			return false;
		}
		$version = $match[1];
		$filename = self::SLUG . '-' . $version . '.zip';
		$release_url = self::REPOSITORY . '/releases/tag/' . rawurlencode( $data['tag_name'] );
		$package = self::REPOSITORY . '/releases/download/' . rawurlencode( $data['tag_name'] ) . '/' . $filename;
		foreach ( $data['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'], $asset['state'], $asset['size'], $asset['browser_download_url'] ) ||
				$asset['name'] !== $filename || $asset['state'] !== 'uploaded' || (int) $asset['size'] < 1 || $asset['browser_download_url'] !== $package ) {
				continue;
			}
			return array(
				'version' => $version,
				'package' => $package,
				'url' => $release_url,
				'body' => isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '',
			);
		}
		return false;
	}

	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name' => 'KITH Carbon Fields',
			'slug' => self::SLUG,
			'version' => $release['version'],
			'author' => 'KITH Web Studio',
			'homepage' => self::REPOSITORY,
			'download_link' => $release['package'],
			'external' => true,
			'sections' => array(
				'description' => 'A fork of Carbon Fields by htmlBurger, maintained and developed by KITH Web Studio.',
				'changelog' => wpautop( esc_html( $release['body'] ) ),
			),
		);
	}

	public function preserve_directory( $source, $remote_source, $upgrader, $extra ) {
		if ( ! is_string( $source ) || ! isset( $extra['plugin'] ) || $extra['plugin'] !== $this->plugin_file ) {
			return $source;
		}
		$directory = dirname( $this->plugin_file );
		if ( '.' === $directory || untrailingslashit( $source ) === untrailingslashit( $remote_source ) . '/' . $directory ) {
			return $source;
		}
		global $wp_filesystem;
		$destination = trailingslashit( $remote_source ) . $directory;
		if ( ! $wp_filesystem->move( $source, $destination ) ) {
			return new \WP_Error( 'kith_plugin_update_directory', __( 'Could not prepare the plugin update directory.', 'carbon-fields' ) );
		}
		return trailingslashit( $destination );
	}
}
