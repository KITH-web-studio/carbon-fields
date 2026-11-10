<?php

use KITH_Carbon_Fields\Plugin_Updates;

require_once dirname( __DIR__, 3 ) . '/core/Plugin_Updates.php';

class PluginUpdatesTest extends WP_UnitTestCase {
	private $updater;
	private $file = 'kith-carbon-fields/kith-carbon-fields.php';
	private $release;
	private $requests;
	private $original_user;

	public function set_up() {
		parent::set_up();
		$this->updater = new Plugin_Updates( WP_PLUGIN_DIR . '/' . $this->file );
		$this->release = $this->release_fixture();
		$this->requests = 0;
		$this->original_user = get_current_user_id();
		delete_site_transient( Plugin_Updates::CACHE_KEY );
		add_filter( 'pre_http_request', array( $this, 'http_fixture' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'http_fixture' ), 10 );
		delete_site_transient( Plugin_Updates::CACHE_KEY );
		wp_set_current_user( $this->original_user );
		parent::tear_down();
	}

	public function http_fixture( $response, $args, $url ) {
		if ( Plugin_Updates::ENDPOINT !== $url ) {
			return $response;
		}
		++$this->requests;
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		if ( is_wp_error( $this->release ) ) {
			return $this->release;
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $this->release ) );
	}

	private function release_fixture() {
		return array(
			'tag_name' => 'v3.6.11.9',
			'draft' => false,
			'prerelease' => false,
			'body' => '<p>Release notes</p>',
			'assets' => array( array(
				'name' => 'kith-carbon-fields-3.6.11.9.zip',
				'state' => 'uploaded',
				'size' => 1024,
				'browser_download_url' => Plugin_Updates::REPOSITORY . '/releases/download/v3.6.11.9/kith-carbon-fields-3.6.11.9.zip',
			) ),
		);
	}

	public function test_stable_asset_is_cached_and_unrelated_plugins_are_untouched() {
		$previous = array( 'version' => '2.0' );
		$this->assertSame( $previous, $this->updater->update( $previous, array(), 'other/plugin.php' ) );
		$this->assertSame( 0, $this->requests );
		$update = $this->updater->update( false, array(), $this->file );
		$this->assertSame( '3.6.11.9', $update['version'] );
		$this->assertSame( $this->release['assets'][0]['browser_download_url'], $update['package'] );
		$this->assertArrayNotHasKey( 'autoupdate', $update );
		$this->assertSame( $update, $this->updater->update( false, array(), $this->file ) );
		$this->assertSame( 1, $this->requests );
		$this->assertGreaterThan( time() + 5 * HOUR_IN_SECONDS, (int) get_site_option( '_site_transient_timeout_' . Plugin_Updates::CACHE_KEY ) );
	}

	public function test_prereleases_drafts_missing_assets_and_foreign_downloads_are_ignored() {
		$variants = array();
		$release = $this->release_fixture();
		$release['prerelease'] = true;
		$variants[] = $release;
		$release = $this->release_fixture();
		$release['draft'] = true;
		$variants[] = $release;
		$release = $this->release_fixture();
		$release['tag_name'] = 'v3.6.11.9-rc.1';
		$variants[] = $release;
		$release = $this->release_fixture();
		$release['assets'] = array();
		$variants[] = $release;
		$release = $this->release_fixture();
		$release['assets'][0]['browser_download_url'] = 'https://example.test/other.zip';
		$variants[] = $release;
		$release = $this->release_fixture();
		$release['assets'][0]['name'] = 'source.zip';
		$variants[] = $release;
		$variants[] = null;
		foreach ( $variants as $variant ) {
			delete_site_transient( Plugin_Updates::CACHE_KEY );
			$this->release = $variant;
			$this->assertFalse( $this->updater->update( false, array(), $this->file ) );
		}
	}

	public function test_network_failures_are_cached_briefly_and_do_not_throw() {
		$this->release = new WP_Error( 'fixture_unavailable' );
		$this->assertFalse( $this->updater->update( false, array(), $this->file ) );
		$this->assertFalse( $this->updater->update( false, array(), $this->file ) );
		$this->assertSame( 1, $this->requests );
		$this->assertLessThanOrEqual( time() + 15 * MINUTE_IN_SECONDS, (int) get_site_option( '_site_transient_timeout_' . Plugin_Updates::CACHE_KEY ) );
	}

	public function test_legacy_core_compares_versions_and_preserves_other_updates() {
		$other = (object) array( 'new_version' => '2.0' );
		$state = (object) array(
			'checked' => array( $this->file => '3.6.11.8' ),
			'response' => array( 'other/plugin.php' => $other ),
			'no_update' => array(),
		);
		$state = $this->updater->legacy_update( $state );
		$this->assertSame( '3.6.11.9', $state->response[ $this->file ]->new_version );
		$this->assertSame( $other, $state->response['other/plugin.php'] );
		foreach ( array( '3.6.11.9', '3.6.11.10', '3.6.11.10-rc.1' ) as $installed ) {
			$state->checked[ $this->file ] = $installed;
			$state = $this->updater->legacy_update( $state );
			$this->assertArrayNotHasKey( $this->file, $state->response );
			$this->assertSame( '3.6.11.9', $state->no_update[ $this->file ]->version );
		}
	}

	public function test_plugin_information_escapes_release_notes() {
		$info = $this->updater->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'kith-carbon-fields' ) );
		$this->assertSame( '3.6.11.9', $info->version );
		$this->assertStringContainsString( '&lt;p&gt;Release notes&lt;/p&gt;', $info->sections['changelog'] );
		$this->assertSame( 'unchanged', $this->updater->plugin_information( 'unchanged', 'query_plugins', (object) array() ) );
	}

	public function test_manual_check_requires_the_update_screen_and_capability() {
		global $pagenow;
		$original_page = $pagenow;
		$original_get = $_GET;
		try {
			$pagenow = 'update-core.php';
			$_GET['force-check'] = '1';
			set_site_transient( Plugin_Updates::CACHE_KEY, array( 'release' => false ), HOUR_IN_SECONDS );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$this->updater->refresh_requested();
			$this->assertIsArray( get_site_transient( Plugin_Updates::CACHE_KEY ) );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->updater->refresh_requested();
			$this->assertFalse( get_site_transient( Plugin_Updates::CACHE_KEY ) );
		} finally {
			$pagenow = $original_page;
			$_GET = $original_get;
		}
	}

	public function test_source_directory_changes_are_scoped_to_this_plugin() {
		global $wp_filesystem;
		$original = $wp_filesystem;
		$filesystem = new Kith_Update_Filesystem_Fixture();
		$wp_filesystem = $filesystem;
		try {
			$updater = new Plugin_Updates( WP_PLUGIN_DIR . '/custom-carbon-fields/kith-carbon-fields.php' );
			$error = new WP_Error( 'fixture_bad_package' );
			$extra = array( 'plugin' => 'custom-carbon-fields/kith-carbon-fields.php' );
			$this->assertSame( $error, $updater->preserve_directory( $error, '/tmp/unpacked/', null, $extra ) );
			$this->assertSame( '/tmp/unpacked/kith-carbon-fields/', $updater->preserve_directory( '/tmp/unpacked/kith-carbon-fields/', '/tmp/unpacked/', null, array( 'plugin' => 'other/plugin.php' ) ) );
			$this->assertSame( array(), $filesystem->moves );
			$this->assertSame( '/tmp/unpacked/custom-carbon-fields/', $updater->preserve_directory( '/tmp/unpacked/kith-carbon-fields/', '/tmp/unpacked/', null, $extra ) );
			$this->assertSame( array( array( '/tmp/unpacked/kith-carbon-fields/', '/tmp/unpacked/custom-carbon-fields' ) ), $filesystem->moves );
			$filesystem->succeeds = false;
			$this->assertWPError( $updater->preserve_directory( '/tmp/unpacked/kith-carbon-fields/', '/tmp/unpacked/', null, $extra ) );
		} finally {
			$wp_filesystem = $original;
		}
	}
}

class Kith_Update_Filesystem_Fixture {
	public $moves = array();
	public $succeeds = true;
	public function move( $source, $destination ) {
		$this->moves[] = array( $source, $destination );
		return $this->succeeds;
	}
}
