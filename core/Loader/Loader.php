<?php

namespace Carbon_Fields\Loader;

use Carbon_Fields\Container\Repository as ContainerRepository;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;
use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Helper\Field_Validation;
use Carbon_Fields\Libraries\Sidebar_Manager\Sidebar_Manager;
use Carbon_Fields\Pimple\Container as PimpleContainer;
use Carbon_Fields\Service\Legacy_Storage_Service_v_1_5;
use Carbon_Fields\Service\Meta_Query_Service;
use Carbon_Fields\Service\REST_API_Service;

/**
 * Loader and main initialization
 */
class Loader {

	protected $sidebar_manager;

	protected $container_repository;

	public function __construct( Sidebar_Manager $sidebar_manager, ContainerRepository $container_repository ) {
		$this->sidebar_manager = $sidebar_manager;
		$this->container_repository = $container_repository;
	}

	/**
	 * Hook the main Carbon Fields initialization functionality.
	 */
	public function boot() {
		if ( ! defined( 'ABSPATH' ) ) {
			throw new \Exception( 'Carbon Fields cannot be booted outside of a WordPress environment.' );
		}

		if ( did_action( 'init' ) ) {
			throw new \Exception( 'Carbon Fields must be booted before the "init" WordPress action has fired.' );
		}

		include_once( dirname( dirname( __DIR__ ) ) . '/config.php' );
		include_once( \Carbon_Fields\DIR . '/core/functions.php' );
		include_once( \Carbon_Fields\DIR . '/core/developer-functions.php' );
		add_filter( 'posts_where', array( \Carbon_Fields\Helper\Complex_Row_Query::class, 'where' ), 10, 2 );
		// Compile exact root keys after the legacy service has rewritten user-supplied meta_query keys.
		add_action( 'pre_get_posts', array( \Carbon_Fields\Helper\Post_Field_Query::class, 'apply' ), 20 );
		add_filter( 'get_meta_sql', array( \Carbon_Fields\Helper\Post_Field_Query::class, 'filter_decimal_sql' ), 20, 6 );
		add_action( 'rest_api_init', array( new \Carbon_Fields\REST_API\Field_Options(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new \Carbon_Fields\Admin\Field_Actions(), 'register_routes' ) );

		add_action( 'after_setup_theme', array( $this, 'load_textdomain' ), 9999 );
		add_action( 'init', array( $this, 'trigger_fields_register' ), 0 );
		add_action( 'rest_api_init', array( $this, 'initialize_widgets' ) );
		add_action( 'carbon_fields_fields_registered', array( $this, 'initialize_containers' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_browser' ), 0 );
		add_action( 'admin_print_footer_scripts', array( $this, 'enqueue_assets' ), 9 );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_iframed_styles' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'initialize_ui' ), 9999 );
		add_action( 'edit_form_after_title', array( $this, 'add_carbon_fields_meta_box_contexts' ) );
		add_action( 'wp_ajax_carbon_fields_fetch_association_options', array( $this, 'fetch_association_options' ) );
		add_action( 'wp_ajax_carbon_fields_validate_fields', array( $this, 'validate_fields' ) );
		$post_list = new \Carbon_Fields\Admin\Post_List( $this->container_repository );
		add_action( 'load-edit.php', array( $post_list, 'attach' ) );
		add_action( 'wp_ajax_inline-save', array( $post_list, 'prepare_quick_edit' ), 0 );
		$woocommerce = new \Carbon_Fields\Integration\WooCommerce_Product_Data( $this->container_repository );
		add_action( 'woocommerce_init', array( $woocommerce, 'boot' ) );

		# Enable the legacy storage service
		\Carbon_Fields\Carbon_Fields::service( 'legacy_storage' )->enable();

		# Enable the meta query service
		\Carbon_Fields\Carbon_Fields::service( 'meta_query' )->enable();

		# Enable the REST API service
		\Carbon_Fields\Carbon_Fields::service( 'rest_api' )->enable();

		# Enable post meta revisions service
		\Carbon_Fields\Carbon_Fields::service( 'revisions' )->enable();

		\Carbon_Fields\Integration\Polylang_Post_Meta::boot();

		# Initialize sidebar manager
		$this->sidebar_manager->boot();
	}

	/**
	 * Load the plugin textdomain.
	 */
	public function load_textdomain() {
		$dir = \Carbon_Fields\DIR . '/languages/';
		$domain = 'carbon-fields';
		$domain_ui = 'carbon-fields-ui';
		$locale = determine_locale();
		$path = $dir . $domain . '-' . $locale . '.mo';
		$path_ui = $dir . $domain_ui . '-' . $locale . '.mo';
		load_textdomain( $domain, $path );
		load_textdomain( $domain_ui, $path_ui );
	}

	/**
	 * Load the ui textdomain
	 */
	public function get_ui_translations() {
		$domain = 'carbon-fields-ui';
		$translations = get_translations_for_domain( $domain );
		$headers = array_change_key_case( $translations->headers, CASE_LOWER );

		$locale = array(
			'' => array(
				'domain' => $domain,
				'lang'   => determine_locale(),
			),
		);

		if ( ! empty( $headers['plural-forms'] ) ) {
			$locale['']['plural_forms'] = $headers['plural-forms'];
		}

		// WordPress 6.5+ returns a list of entries rather than an array keyed by message.
		foreach ( $translations->entries as $entry ) {
			$locale[ $entry->key() ] = $entry->translations;
		}

		return $locale;
	}

	/**
	 * Register containers and fields.
	 */
	public function trigger_fields_register() {
		try {
			do_action( 'carbon_fields_register_fields' );
			do_action( 'carbon_fields_fields_registered' );
		} catch ( Incorrect_Syntax_Exception $e ) {
			$callback = '';
			foreach ( $e->getTrace() as $trace ) {
				$callback .= '<br/>' . ( isset( $trace['file'] ) ? $trace['file'] . ':' . $trace['line'] : $trace['function'] . '()' );
			}
			wp_die( '<h3>' . $e->getMessage() . '</h3><small>' . $callback . '</small>' );
		}
	}

	/**
	 * Initialize containers.
	 */
	public function initialize_containers() {
		$this->container_repository->initialize_containers();
	}

	/**
	 * Initialize the media browser.
	 */
	public function enqueue_media_browser() {
		wp_enqueue_media();
	}

	/**
	 * Returns the rendering context for the assets.
	 *
	 * @return string
	 */
	protected function get_assets_context() {
		$current_screen = get_current_screen();

		if ( is_null( $current_screen ) ) {
			return 'classic';
		}

		return $current_screen->is_block_editor() ? 'gutenberg' : 'classic';
	}

	/**
	 * Returns the suffix that should be applied to the assets.
	 *
	 * @return string
	 */
	protected function get_assets_suffix() {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
	}

	/**
	 * Registers and enqueues a style.
	 *
	 * @param  string $src
	 * @param  array  $deps
	 * @return void
	 */
	protected function enqueue_style( $src, $deps = array() ) {
		$suffix = $this->get_assets_suffix();
		$context = $this->get_assets_context();

		wp_enqueue_style(
			'carbon-fields-' . $src,
			\Carbon_Fields\URL . '/build/' . $context . '/' . $src . $suffix . '.css',
			$deps,
			\Carbon_Fields\VERSION
		);
	}

	/**
	 * Registers and enqueues a script.
	 *
	 * @param  string $src
	 * @param  array  $deps
	 * @return void
	 */
	protected function enqueue_script( $src, $deps = array() ) {
		$suffix = $this->get_assets_suffix();
		$context = $this->get_assets_context();

		wp_enqueue_script(
			'carbon-fields-' . $src,
			\Carbon_Fields\URL . '/build/' . $context . '/' . $src . $suffix . '.js',
			$deps,
			\Carbon_Fields\VERSION
		);
	}

	/**
	 * Enqueues the assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		global $pagenow;
		global $wp_version;

		$preloaded = $this->get_json_data();
		$code_editors = $this->enqueue_code_editors( $preloaded );

		$this->enqueue_style( 'core' );
		$this->enqueue_style( 'metaboxes' );

		$core_deps = array( 'carbon-fields-vendor' );
		if ( $code_editors ) {
			$core_deps[] = 'code-editor';
		}

		if ( $this->get_assets_context() === 'gutenberg' ) {
			$core_deps[] = 'wp-components';
		}

		$this->enqueue_script( 'vendor', array( 'wp-polyfill', 'jquery', 'wp-api-request' ) );
		$this->enqueue_script( 'core', $core_deps );
		$this->enqueue_script( 'metaboxes', array( 'carbon-fields-vendor', 'carbon-fields-core' ) );

		if ( $this->get_assets_context() === 'gutenberg' ) {
			$this->enqueue_style( 'blocks' );
			$this->enqueue_script( 'blocks', array( 'carbon-fields-vendor', 'carbon-fields-core' ) );
		}

		wp_add_inline_script( 'carbon-fields-vendor', 'window.cf = window.cf || {}', 'before' );
		wp_add_inline_script( 'carbon-fields-vendor', sprintf( 'window.cf.preloaded = %s', wp_json_encode( $preloaded ) ), 'before' );

		$revisions = \Carbon_Fields\Carbon_Fields::service( 'revisions' );

		wp_localize_script( 'carbon-fields-vendor', 'cf', apply_filters( 'carbon_fields_config', array(
			'config' => array(
				'locale' => $this->get_ui_translations(),
				'pagenow' => $pagenow,
				'compactInput' => \Carbon_Fields\COMPACT_INPUT,
				'compactInputKey' => \Carbon_Fields\COMPACT_INPUT_KEY,
				'revisionsInputKey' => $revisions::CHANGE_KEY,
				'sidebarNonce' => current_user_can( 'edit_theme_options' ) ? wp_create_nonce( Sidebar_Manager::NONCE_ACTION ) : '',
				'codeEditors' => $code_editors,
				'businessTimezones' => \Carbon_Fields\Field\Business_Hours_Field::editor_timezones(),
				'validationPostRoute' => isset( $GLOBALS['post'] ) && function_exists( 'rest_get_route_for_post' ) ? rest_get_route_for_post( $GLOBALS['post'] ) : '',
				'linkLanguage' => \Carbon_Fields\Integration\Polylang::current_language(),
				'fieldActionUrl' => rest_url( 'carbon-fields/v1/field-action' ),
				'wp_version' => $wp_version,
			)
		) ) );
	}

	/**
	 * Enqueue the WordPress code editor for languages declared on the current screen.
	 */
	protected function enqueue_code_editors( $preloaded ) {
		if ( ! function_exists( 'wp_enqueue_code_editor' ) ) {
			return array();
		}
		$editors = array();
		$scan = function( $fields ) use ( &$editors, &$scan ) {
			foreach ( $fields as $field ) {
				if ( 'code' === $field['type'] && isset( $field['code_language'] ) && 'text' !== $field['code_language'] && ! isset( $editors[ $field['code_language'] ] ) ) {
					$language = $field['code_language'];
					$editors[ $language ] = wp_enqueue_code_editor( array(
						'type' => \Carbon_Fields\Field\Code_Field::LANGUAGES[ $language ],
						'codemirror' => array( 'lint' => false ),
					) );
				}
				if ( 'complex' === $field['type'] ) {
					foreach ( $field['groups'] as $group ) {
						$scan( $group['fields'] );
					}
				}
			}
		};
		foreach ( array_merge( $preloaded['containers'], $preloaded['blocks'] ) as $container ) {
			$scan( $container['fields'] );
		}
		return array_filter( $editors );
	}

	/**
	 * Re-enqueues the field styles for the block editor's canvas iframe.
	 *
	 * @return void
	 */
	public function enqueue_iframed_styles() {
		if ( ! is_admin() ) {
			return;
		}

		$this->enqueue_style( 'core' );

		if ( $this->get_assets_context() === 'gutenberg' ) {
			$this->enqueue_style( 'blocks' );
		}

		wp_enqueue_style( 'dashicons' );
	}

	/**
	 * Trigger the initialization of the UI.
	 *
	 * @return void
	 */
	public function initialize_ui() {
		?>
			<script>
				if ( typeof cf.core.initialize === 'function' ) {
					cf.core.initialize();
				}
			</script>
		<?php
	}

	/**
	 * Add custom meta box contexts
	 */
	public function add_carbon_fields_meta_box_contexts() {
		global $post, $wp_meta_boxes;

		$context = 'carbon_fields_after_title';
		do_meta_boxes( get_current_screen(), $context, $post );
	}

	/**
	 * Retrieve containers and sidebars for use in the JS.
	 *
	 * @return array $carbon_data
	 */
	public function get_json_data() {
		$carbon_data = array(
			'blocks' => array(),
			'containers' => array(),
			'sidebars' => array(),
		);

		$containers = $this->container_repository->get_active_containers();

		foreach ( $containers as $container ) {
			$container_data = $container->to_json( true );

			if ( is_a($container, '\\Carbon_Fields\\Container\\Block_Container', true ) ) {
				$carbon_data['blocks'][] = $container_data;
			} else {
				$carbon_data['containers'][] = $container_data;
			}
		}

		$carbon_data['sidebars'] = Helper::get_active_sidebars();

		return $carbon_data;
	}

	/**
	 * Register widget containers for REST API
	 * in order to be able to interract with the containers
	 *
	 * @access public
	 * @return void
	 */
	public function initialize_widgets() {
		global $wp_registered_widgets;

		// Get used (active and inactive) widgets from Carbon Fields
		$carbon_fields_widgets_ids = array_filter( array_merge( ...array_values( wp_get_sidebars_widgets() ) ), function ( $widget_id ) {
			return substr( $widget_id, 0, 14 ) === 'carbon_fields_';
		} );

		foreach ( $carbon_fields_widgets_ids as $widget_id ) {
			if ( isset( $wp_registered_widgets[ $widget_id ] ) ) {
				$widget_class = $wp_registered_widgets[ $widget_id ]['callback'][0];

				$widget_class->_set( $wp_registered_widgets[ $widget_id ]['params'][0]['number'] );
				$widget_class->register_container();
			}
		}
	}

	/**
	 * Handle association field options fetch.
	 *
	 * @access public
	 *
	 * @return array
	 */
	public function fetch_association_options() {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
		$term = isset( $_GET['term'] ) && is_string( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( $page < 1 || $page > 1000 || strlen( $term ) > 240 ) {
			wp_send_json_error( __( 'Invalid association request.', 'carbon-fields' ), 400 );
		}

		$container_id = isset( $_GET['container_id'] ) && is_string( $_GET['container_id'] ) ? wp_unslash( $_GET['container_id'] ) : '';
		$field_name = isset( $_GET['field_name'] ) && is_string( $_GET['field_name'] ) ? wp_unslash( $_GET['field_name'] ) : '';

		/** @var \Carbon_Fields\Field\Association_Field $field */
		$field = $this->container_repository->get_editor_field_in_container( $field_name, $container_id );
		if ( ! ( $field instanceof \Carbon_Fields\Field\Association_Field ) ) {
			wp_send_json_error( __( 'Association field not found.', 'carbon-fields' ), 404 );
		}

		return wp_send_json_success( $field->get_options( array(
			'page' => $page,
			'term' => $term,
		) ) );
	}

	/**
	 * Check the submitted form without saving anything.
	 */
	public function validate_fields() {
		$input = Helper::input();
		$requested = isset( $input['_cf_validation'] ) && is_array( $input['_cf_validation'] ) ? array_filter( $input['_cf_validation'], 'is_string' ) : array();
		$post_id = isset( $input['post_ID'] ) && is_scalar( $input['post_ID'] ) ? absint( $input['post_ID'] ) : 0;
		$checked = array();
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			foreach ( $this->container_repository->get_containers() as $container ) {
				$id = $container->get_id();
				if ( ! in_array( $id, $requested, true ) || ! in_array( $container->type, array( 'post_meta', 'theme_options' ), true ) ||
					( ! Field_Validation::has_rules( $container->get_fields() ) && ! $container->is_wizard() ) ) {
					continue;
				}
				$error = 'post_meta' === $container->type ? $container->validate_editor_request( $post_id ) : $container->validate_editor_request();
				if ( is_wp_error( $error ) ) {
					wp_send_json_error( array(
						'containerId' => $id,
						'message' => $error->get_error_message(),
						'path' => $error->get_error_data()['path'] ?? array(),
						'conflictUrl' => $error->get_error_data()['conflict_url'] ?? '',
					), 422 );
				}
				$checked[] = $id;
			}
			if ( $checked && ! array_diff( $requested, $checked ) ) {
				wp_send_json_success();
			}
		}
		wp_send_json_error( array( 'message' => __( 'Check the form and try saving again.', 'carbon-fields' ) ), 400 );
	}
}
