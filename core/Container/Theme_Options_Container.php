<?php

namespace Carbon_Fields\Container;

use Carbon_Fields\Datastore\Datastore;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;
use Carbon_Fields\Integration\Polylang;
use Carbon_Fields\Helper\Helper;

/**
 * Theme options container class.
 */
class Theme_Options_Container extends Container {
	protected $multilingual = true;

	/**
	 * Keep a container shared across languages when explicitly disabled.
	 */
	public function set_multilingual( $enabled ) {
		$this->multilingual = (bool) $enabled;
		if ( $this->get_datastore() instanceof \Carbon_Fields\Datastore\Theme_Options_Datastore ) {
			$this->get_datastore()->set_multilingual( $enabled );
		}
		return $this;
	}

	public function is_multilingual() {
		return $this->multilingual && $this->get_datastore() instanceof \Carbon_Fields\Datastore\Theme_Options_Datastore && Polylang::active();
	}

	public function set_datastore( \Carbon_Fields\Datastore\Datastore_Interface $datastore, $set_as_default = false ) {
		parent::set_datastore( $datastore, $set_as_default );
		if ( $this->get_datastore() instanceof \Carbon_Fields\Datastore\Theme_Options_Datastore ) {
			$this->get_datastore()->set_multilingual( $this->multilingual );
		}
		return $this;
	}

	protected function language_input_name() {
		return $this->get_id() . '_language';
	}

	protected function verified_nonce_in_request() {
		$input = Helper::input();
		$name = $this->get_nonce_name();
		return isset( $input[ $name ] ) && is_string( $input[ $name ] ) && parent::verified_nonce_in_request();
	}

	protected function verified_language_in_request() {
		$input = Helper::input();
		$name = $this->language_input_name();
		if ( ! $this->is_multilingual() ) {
			// A form opened before Polylang was disabled must not overwrite shared data.
			return ! isset( $input[ $name ] );
		}
		return isset( $input[ $name ], $input[ $name . '_nonce' ] ) &&
			is_string( $input[ $name ] ) && '' !== $input[ $name ] &&
			Polylang::valid_language( $input[ $name ] ) && is_string( $input[ $name . '_nonce' ] ) &&
			wp_verify_nonce( $input[ $name . '_nonce' ], $name . ':' . $input[ $name ] );
	}

	/**
	 * Expose Polylang's own toolbar selector on this settings page.
	 */
	public function admin_language_items( $items, $all_items = array() ) {
		return $this->is_multilingual() && $this->should_activate() && $all_items ? $all_items : $items;
	}

	public function render_language_fields() {
		if ( ! $this->is_multilingual() ) {
			return;
		}
		$language = Polylang::current_language();
		$token = '' === $language ? 'all' : $language;
		$name = $this->language_input_name();
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $token ) . '">';
		$slugs = Polylang::languages();
		$names = pll_languages_list( array( 'fields' => 'name', 'hide_empty' => false ) );
		$labels = array( 'all' => __( 'Shared values', 'carbon-fields' ) );
		foreach ( $slugs as $index => $slug ) {
			$labels[ $slug ] = isset( $names[ $index ] ) ? $names[ $index ] : $slug;
		}
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings language', 'carbon-fields' ) . '">';
		foreach ( $labels as $slug => $label ) {
			$url = add_query_arg( array( 'page' => $this->get_page_file(), 'lang' => $slug ), remove_query_arg( 'settings-updated' ) );
			echo '<a class="nav-tab' . ( $slug === $token ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $slug === $token ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		$description = 'all' === $token ?
			__( 'Shared values are used by languages without their own saved values.', 'carbon-fields' ) :
			__( 'Untranslated fields use shared values. Saving changes only the selected language.', 'carbon-fields' );
		echo '</nav><p class="description">' . esc_html( $description ) . '</p>';
	}

	public function render_language_complete() {
		if ( $this->is_multilingual() ) {
			$language = Polylang::current_language();
			$token = '' === $language ? 'all' : $language;
			$name = $this->language_input_name();
			// Place the language nonce after all field inputs to reject truncated forms.
			wp_nonce_field( $name . ':' . $token, $name . '_nonce', false );
		}
	}

	/**
	 * Array of registered page slugs to verify uniqueness with
	 *
	 * @var array
	 */
	protected static $registered_pages = array();

	/**
	 * Array of container settings
	 *
	 * @var array
	 */
	public $settings = array(
		'parent' => '',
		'file' => '',
		'icon' => '',
		'menu_position' => null,
		'menu_title' => null,
	);

	/**
	 * {@inheritDoc}
	 */
	public function __construct( $id, $title, $type, $condition_collection, $condition_translator ) {
		parent::__construct( $id, $title, $type, $condition_collection, $condition_translator );

		if ( ! $this->get_datastore() ) {
			$this->set_datastore( Datastore::make( 'theme_options' ), $this->has_default_datastore() );
		}

		if ( apply_filters( 'carbon_fields_' . $type . '_container_admin_only_access', true, $title, $this ) ) {
			$this->where( 'current_user_capability', '=', 'manage_options' );
		}
	}

	/**
	 * Sanitize a title to a filename
	 *
	 * @param  string $title
	 * @param  string $extension
	 * @return string
	 */
	protected function title_to_filename( $title, $extension ) {
		$title = sanitize_file_name( $title );
		$title = strtolower( $title );
		$title = remove_accents( $title );
		$title = preg_replace( array(
			'~\s+~',
			'~[^\w\d-]+~u',
			'~-+~',
		), array(
			'-',
			'-',
			'-',
		), $title );
		return $title . $extension;
	}

	/**
	 * Attach container as a theme options page/subpage.
	 */
	public function init() {
		$registered = $this->register_page();
		if ( $registered ) {
			add_action( 'admin_menu', array( $this, '_attach' ) );
			add_filter( 'pll_admin_languages_filter', array( $this, 'admin_language_items' ), 10, 2 );
			add_action( $this->get_id() . '_before_fields', array( $this, 'render_language_fields' ) );
			add_action( $this->get_id() . '_after_fields', array( $this, 'render_language_complete' ) );
		}
	}

	/**
	 * Checks whether the current save request is valid
	 *
	 * @return bool
	 */
	public function is_valid_save() {
		if ( ! $this->verified_nonce_in_request() ) {
			return false;
		}

		if ( ! $this->verified_language_in_request() ) {
			$this->errors[] = esc_html__( 'The settings language could not be verified. Reload the page and try again.', 'carbon-fields' );
			return false;
		}
		return $this->is_valid_attach_for_object( null );
	}

	/**
	 * Perform save operation after successful is_valid_save() check.
	 * The call is propagated to all fields in the container.
	 *
	 * @param mixed $user_data
	 */
	public function save( $user_data = null ) {
		if ( ! $this->verified_language_in_request() ) {
			return new \WP_Error( 'carbon_fields_invalid_language', __( 'The settings language could not be verified. Reload the page and try again.', 'carbon-fields' ) );
		}
		$input = Helper::input();
		$language = $this->is_multilingual() ? $input[ $this->language_input_name() ] : null;
		Polylang::with_language( $language, function() use ( $user_data ) {
			try {
				parent::save( $user_data );
			} catch ( Incorrect_Syntax_Exception $e ) {
				$this->errors[] = $e->getMessage();
			}
			do_action( 'carbon_fields_' . $this->type . '_container_saved', $user_data, $this );
		} );

		if ( ! headers_sent() ) {
			$args = array( 'settings-updated' => 'true' );
			if ( null !== $language ) {
				$args['lang'] = $language;
			}
			wp_safe_redirect( add_query_arg( $args ) );
		}
	}

	/**
	 * Get environment array for page request (in admin)
	 *
	 * @return array
	 */
	protected function get_environment_for_request() {
		return array();
	}

	/**
	 * Perform checks whether the container should be attached during the current request
	 *
	 * @return bool True if the container is allowed to be attached
	 */
	public function is_valid_attach_for_request() {
		return $this->static_conditions_pass();
	}

	/**
	 * Get environment array for object id
	 *
	 * @return array
	 */
	protected function get_environment_for_object( $object_id ) {
		return array();
	}

	/**
	 * Check container attachment rules against object id
	 *
	 * @param int $object_id
	 * @return bool
	 */
	public function is_valid_attach_for_object( $object_id = null ) {
		return $this->all_conditions_pass( intval( $object_id ) );
	}

	/**
	 * Add theme options container pages.
	 * Hook the container saving action.
	 */
	public function attach() {
		// use the "read" capability because conditions will handle actual access and save capability checking
		// before the attach() method is called

		// Add menu page
		if ( ! $this->settings['parent'] ) {
			add_menu_page(
				$this->title,
				$this->settings['menu_title'] ? $this->settings['menu_title'] : $this->title,
				'read',
				$this->get_page_file(),
				array( $this, 'render' ),
				$this->settings['icon'],
				$this->settings['menu_position']
			);
		}

		add_submenu_page(
			$this->settings['parent'],
			$this->title,
			$this->settings['menu_title'] ? $this->settings['menu_title'] : $this->title,
			'read',
			$this->get_page_file(),
			array( $this, 'render' )
		);

		$page_hook = get_plugin_page_hookname( $this->get_page_file(), '' );
		add_action( 'load-' . $page_hook, array( $this, '_save' ) );
	}

	/**
	 * Whether this container is currently viewed.
	 *
	 * @return boolean
	 */
	public function should_activate() {
		$input = stripslashes_deep( $_GET );
		$request_page = isset( $input['page'] ) ? $input['page'] : '';
		if ( ! empty( $request_page ) && $request_page === $this->get_page_file() ) {
			return true;
		}

		return false;
	}

	/**
	 * Output the container markup
	 */
	public function render() {
		$input = stripslashes_deep( $_GET );
		$request_settings_updated = isset( $input['settings-updated'] ) ? $input['settings-updated'] : '';
		if ( $request_settings_updated === 'true' ) {
			$this->notifications[] = __( 'Settings saved.', 'carbon-fields' );
		}

		include \Carbon_Fields\DIR . '/templates/Container/' . $this->type . '.php';
	}

	/**
	 * Register the page while making sure it is unique.
	 *
	 * @return boolean
	 */
	protected function register_page() {
		$file = $this->get_page_file();
		$parent = $this->settings['parent'];

		if ( ! $parent ) {
			// Register top level page
			if ( isset( static::$registered_pages[ $file ] ) ) {
				Incorrect_Syntax_Exception::raise( 'Page "' . $file . '" already registered' );
				return false;
			}

			static::$registered_pages[ $file ] = array();
			return true;
		}

		// Register sub-page
		if ( ! isset( static::$registered_pages[ $parent ] ) ) {
			static::$registered_pages[ $parent ] = array();
		}

		if ( in_array( $file, static::$registered_pages[ $parent ] ) ) {
			Incorrect_Syntax_Exception::raise( 'Page "' . $file . '" with parent "' . $parent . '" is already registered. Please set a name for the container.' );
			return false;
		}

		static::$registered_pages[ $parent ][] = $file;
		return true;
	}

	/**
	 * Change the parent theme options page of this container
	 *
	 * @param  string|Theme_Options_Container $parent
	 * @return Container                      $this
	 */
	public function set_page_parent( $parent ) {
		if ( is_a( $parent, static::class ) ) {
			$this->settings['parent'] = $parent->get_page_file();
			return $this;
		}

		$this->settings['parent'] = $parent;
		return $this;
	}

	/**
	 * Get the theme options file name of this container.
	 *
	 * @return string
	 */
	public function get_page_file() {
		if ( ! empty( $this->settings['file'] ) ) {
			return $this->settings['file'];
		}
		return $this->title_to_filename( 'crb_' . $this->get_id(), '.php' );
	}

	/**
	 * Set the theme options file name of this container.
	 *
	 * @param  string    $file
	 * @return Container $this
	 */
	public function set_page_file( $file ) {
		$this->settings['file'] = $file;
		return $this;
	}

	/**
	 * Set the title of this container in the administration menu.
	 *
	 * @param  string    $title
	 * @return Container $this
	 */
	public function set_page_menu_title( $title ) {
		$this->settings['menu_title'] = $title;
		return $this;
	}

	/**
	 * Alias of the set_page_menu_position() method for backwards compatibility
	 *
	 * @param  integer   $position
	 * @return Container $this
	 */
	public function set_page_position( $position ) {
		return $this->set_page_menu_position( $position );
	}

	/**
	 * Set the page position of this container in the administration menu.
	 *
	 * @param  integer   $position
	 * @return Container $this
	 */
	public function set_page_menu_position( $position ) {
		$this->settings['menu_position'] = $position;
		return $this;
	}

	/**
	 * Set the icon of this theme options page.
	 * Applicable only for parent theme option pages.
	 *
	 * @param  string    $icon
	 * @return Container $this
	 */
	public function set_icon( $icon ) {
		$this->settings['icon'] = $icon;
		return $this;
	}
}
