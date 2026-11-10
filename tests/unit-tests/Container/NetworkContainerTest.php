<?php
use Carbon_Fields\Container;
/** @group carbon-network */
class NetworkContainerTest extends WP_UnitTestCase {
	public function testUsesNetworkIdRatherThanBlogId() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite test database.' );
		}

		$network_id = (int) self::factory()->network->create();
		$this->assertGreaterThan( 0, $network_id );
		$this->assertNull( get_site( $network_id ) );

		$container = Container::make( 'network', 'KITH second network fixture' );
		$this->assertSame( get_current_network_id(), $container->get_site_id() );
		$container->set_site_id( $network_id );
		$this->assertSame( $network_id, $container->get_site_id() );
	}
}
