<?php

namespace Carbon_Fields\Container;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Datastore\Datastore;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;
use Carbon_Fields\Integration\Polylang;
use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Helper\Field_Validation;

/**
 * Theme options container class.
 */
class Theme_Options_Container extends Container {
	use \Carbon_Fields\Helper\Conditional_Logic;
	const FORM_NONCE_PREFIX = 'cf1:';
	protected $multilingual = true;
	protected $page_capability = null;
	protected $default_access_condition = null;

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

	protected function get_nonce_value() {
		return wp_create_nonce( $this->editor_nonce_action() );
	}

	protected function verified_nonce_in_request() {
		$input = Helper::input();
		$name = $this->get_nonce_name();
		$nonce = $input[ $name ] ?? '';
		if ( ! is_string( $nonce ) ) {
			return false;
		}
		$action = $this->editor_nonce_action();
		if ( $this->is_protected_editor_form( $input ) ) {
			$nonce = substr( $nonce, strlen( self::FORM_NONCE_PREFIX ) );
			$action .= ':' . self::FORM_NONCE_PREFIX;
		}
		return wp_verify_nonce( $nonce, $action );
	}

	private function is_protected_editor_form( array $input ) {
		$nonce = $input[ $this->get_nonce_name() ] ?? null;
		return 'theme_options' === $this->type && is_string( $nonce ) && 0 === strpos( $nonce, self::FORM_NONCE_PREFIX );
	}

	private function get_editor_form_input() {
		$input = Helper::input();
		if ( ! $this->is_protected_editor_form( $input ) ) {
			return $input;
		}
		$name = $this->get_nonce_name();
		$mode = $input[ $name . '_mode' ] ?? null;
		$complete = $input[ $name . '_complete' ] ?? null;
		$error = new \WP_Error( 'carbon_fields_incomplete_form', __( 'Carbon Fields received an incomplete form. Reload the editor and try again.', 'carbon-fields' ) );
		if ( $mode !== $complete || ! in_array( $mode, array( 'native', 'packed' ), true ) ) {
			return $error;
		}
		if ( 'native' === $mode ) {
			return $input;
		}
		$raw = $input[ $name . '_payload' ] ?? null;
		$payload = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$values = $payload['fields'] ?? null;
		if ( ! is_array( $values ) ) {
			return $error;
		}
		$denied = $this->restricted_option_names();
		if ( $this->has_restricted_input( $values, $denied ) ) {
			return $this->restricted_settings_error();
		}
		$fields = $this->get_editable_fields( $denied );
		$expected = array_fill_keys( $this->editor_field_names( $fields ), true );
		if ( array_diff_key( $values, $expected ) || array_diff_key( $expected, $values ) ) {
			return $error;
		}
		$language = $this->is_multilingual() ? ( $input[ $this->language_input_name() ] ?? null ) : null;
		if ( null !== $language && ! Polylang::valid_language( $language ) ) {
			return $error;
		}
		return Polylang::with_language( $language, function() use ( $fields, $values, $input, $error ) {
			foreach ( $fields as $field ) {
				$value = $values[ $field->get_name() ];
				if ( null !== $value && ! $field->is_valid_form_value( $value ) ) {
					return $error;
				}
			}
			return array_replace( $input, $values );
		} );
	}

	protected function editor_nonce_action() {
		$name = $this->get_nonce_name();
		$denied = array_intersect_key( $this->restricted_option_names(), array_flip( $this->field_base_names( $this->fields ) ) );
		if ( ! $denied ) {
			return $name;
		}
		// A form opened under different permissions cannot clear newly editable fields.
		ksort( $denied );
		return $name . ':' . hash( 'sha256', implode( ',', array_keys( $denied ) ) );
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
	 * Optional introduction shown under the settings page title.
	 *
	 * @var string
	 */
	protected $page_description = '';

	/**
	 * Set a short, plain-text description for this settings page.
	 *
	 * @param string $description
	 * @return static
	 */
	public function set_page_description( $description ) {
		$this->page_description = $description;
		return $this;
	}

	/**
	 * Opt into the settings panel layout.
	 *
	 * @param string $layout 'panel' or 'default'
	 * @return static
	 */
	public function set_page_layout( $layout ) {
		$this->settings['page_layout'] = 'panel' === $layout ? 'panel' : 'default';
		return $this;
	}

	protected function uses_settings_panel() {
		return 'theme_options' === $this->type && isset( $this->settings['page_layout'] ) && 'panel' === $this->settings['page_layout'];
	}

	/**
	 * Replace the default manage_options requirement for this page.
	 * Additional container conditions still apply.
	 */
	public function set_page_capability( $capability ) {
		if ( ! $this->valid_capability_name( $capability ) ) {
			Incorrect_Syntax_Exception::raise( 'The settings page requires a valid WordPress capability name.' );
			return $this;
		}
		if ( $this->default_access_condition ) {
			$this->condition_collection->remove_fulfillable( $this->default_access_condition );
			$this->default_access_condition = null;
		}
		$this->page_capability = $capability;
		return $this;
	}

	protected function valid_capability_name( $value ) {
		return is_string( $value ) && preg_match( '/^[a-z][a-z0-9_-]*$/D', $value );
	}

	/**
	 * Add a section with a stable URL identifier. A subsection can reference an earlier top-level section.
	 *
	 * @param string $id
	 * @param string $title
	 * @param array $fields
	 * @param array $args Optional description, Dashicons icon, parent section ID, conditional logic and capability.
	 * @return static
	 */
	public function add_section( $id, $title, $fields, $args = array() ) {
		$sections = isset( $this->settings['panel_sections'] ) ? $this->settings['panel_sections'] : array();
		$args = wp_parse_args( $args, array( 'description' => '', 'icon' => '', 'parent' => '', 'conditional_logic' => array(), 'capability' => null ) );
		if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $id ) || isset( $sections[ $id ] ) ) {
			Incorrect_Syntax_Exception::raise( 'Settings panel sections need unique lowercase IDs.' );
			return $this;
		}
		if ( $args['parent'] && ( ! isset( $sections[ $args['parent'] ] ) || $sections[ $args['parent'] ]['parent'] ) ) {
			Incorrect_Syntax_Exception::raise( 'A settings panel subsection must reference a previously declared top-level section.' );
			return $this;
		}
		if ( null !== $args['capability'] && ! $this->valid_capability_name( $args['capability'] ) ) {
			Incorrect_Syntax_Exception::raise( 'A settings section capability must be a valid WordPress capability name.' );
			return $this;
		}

		$field_count = count( $this->fields );
		$this->add_fields( $fields );
		if ( $fields && count( $this->fields ) === $field_count ) {
			return $this;
		}
		$this->settings['panel_sections'][ $id ] = array(
			'id' => $id,
			'title' => $title,
			'description' => $args['description'],
			'icon' => sanitize_html_class( $args['icon'] ),
			'parent' => $args['parent'],
			'conditional_logic' => $this->parse_conditional_rules( $args['conditional_logic'] ),
			'capability' => $args['capability'],
			'fields' => array_map( function( $field ) {
				return $field->get_name();
			}, array_values( $fields ) ),
		);
		return $this;
	}

	public function insert_fields_relative( $anchor, array $fields, $position ) {
		$result = parent::insert_fields_relative( $anchor, $fields, $position );
		if ( true !== $result || empty( $this->settings['panel_sections'] ) ) {
			return $result;
		}
		$anchor_name = $this->get_root_field_by_name( $anchor )->get_name();
		foreach ( $this->settings['panel_sections'] as &$section ) {
			$index = array_search( $anchor_name, $section['fields'], true );
			if ( false !== $index ) {
				array_splice( $section['fields'], $index + ( 'after' === $position ? 1 : 0 ), 0, array_map( static function( $field ) {
					return $field->get_name();
				}, $fields ) );
			}
		}
		unset( $section );
		return true;
	}

	protected function section_is_allowed( array $section, array $sections ) {
		return ( ! isset( $section['capability'] ) || current_user_can( $section['capability'] ) ) &&
			( ! $section['parent'] || $this->section_is_allowed( $sections[ $section['parent'] ], $sections ) );
	}

	/**
	 * Root option keys denied by this declaration. Theme options share storage across pages.
	 */
	public function get_restricted_option_names() {
		$sections = $this->settings['panel_sections'] ?? array();
		$allow_scripts = current_user_can( 'unfiltered_html' );
		if ( ! $this->page_capability && ! $sections && $allow_scripts ) {
			return array();
		}
		if ( $this->page_capability && ! current_user_can( $this->page_capability ) ) {
			return $this->field_base_names( $this->fields );
		}
		$denied = array();
		foreach ( $sections as $section ) {
			if ( ! $this->section_is_allowed( $section, $sections ) ) {
				foreach ( $section['fields'] as $name ) {
					$denied[ $name ] = true;
				}
			}
		}
		$names = array();
		foreach ( $this->fields as $field ) {
			if ( isset( $denied[ $field->get_name() ] ) || ( ! $allow_scripts && $this->contains_scripts( $field ) ) ) {
				$names[] = $field->get_base_name();
			}
		}
		return $names;
	}

	protected function contains_scripts( \Carbon_Fields\Field\Field $field ) {
		if ( $field instanceof \Carbon_Fields\Field\Scripts_Field ) {
			return true;
		}
		if ( $field instanceof \Carbon_Fields\Field\Complex_Field ) {
			foreach ( $field->get_fields() as $child ) {
				if ( $this->contains_scripts( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}

	protected function field_base_names( array $fields ) {
		return array_map( function( $field ) {
			return $field->get_base_name();
		}, $fields );
	}

	protected function restricted_option_names() {
		return 'theme_options' === $this->type
			? Carbon_Fields::resolve( 'container_repository' )->get_restricted_theme_options()
			: array_fill_keys( $this->get_restricted_option_names(), true );
	}

	protected function get_editable_fields( array $denied ) {
		return array_filter( $this->get_fields(), function( $field ) use ( $denied ) {
			return ! isset( $denied[ $field->get_base_name() ] );
		} );
	}

	protected function editable_sections( array $field_names ) {
		$sections = $this->settings['panel_sections'] ?? array();
		$editable = array();
		foreach ( $sections as $section ) {
			if ( $this->section_is_allowed( $section, $sections ) ) {
				unset( $section['capability'] );
				$section['fields'] = array_values( array_intersect( $section['fields'], $field_names ) );
				$editable[] = $section;
			}
		}
		return $editable;
	}

	protected function editor_field_names( array $fields ) {
		return array_map( function( $field ) {
			return $field->get_name();
		}, $fields );
	}

	public function to_json( $load ) {
		$denied = $this->restricted_option_names();
		$settings = $this->settings;
		if ( 'theme_options' === $this->type ) {
			$settings['packed_nonce'] = wp_create_nonce( $this->editor_nonce_action() . ':' . self::FORM_NONCE_PREFIX );
		}
		$fields = $this->get_editable_fields( $denied );
		$field_names = $this->editor_field_names( $fields );
		if ( $denied && isset( $settings['tabs'] ) ) {
			$settings['tabs'] = array_filter( array_map( function( $names ) use ( $field_names ) {
				return array_values( array_intersect( $names, $field_names ) );
			}, $settings['tabs'] ) );
		}
		unset( $settings['panel_sections'] );
		if ( $this->uses_settings_panel() ) {
			$settings['panel_sections'] = $this->editable_sections( $field_names );
		}
		if ( isset( $settings['sidebar_sections'] ) ) {
			$settings['sidebar_sections'] = $this->get_sidebar_sections();
		}
		return $this->editor_json( $load, $fields, $settings );
	}

	/**
	 * Add a named panel to the settings page sidebar.
	 *
	 * @param string $title
	 * @param array $fields
	 * @return static
	 */
	public function add_sidebar_section( $title, $fields ) {
		if ( empty( $fields ) ) {
			return $this;
		}

		$field_count = count( $this->fields );
		$this->add_fields( $fields );
		if ( count( $this->fields ) === $field_count ) {
			return $this;
		}

		$this->settings['sidebar_sections'][] = array(
			'title' => $title,
			'fields' => array_map( function( $field ) {
				return $field->get_name();
			}, array_values( $fields ) ),
		);

		return $this;
	}

	protected function get_sidebar_sections() {
		$sections = $this->settings['sidebar_sections'] ?? array();
		if ( ! $sections ) {
			return $sections;
		}
		$fields = $this->get_editable_fields( $this->restricted_option_names() );
		$names = $this->editor_field_names( $fields );
		foreach ( $sections as $index => $section ) {
			$section['fields'] = array_values( array_intersect( $section['fields'], $names ) );
			$sections[ $index ] = $section;
		}
		return array_values( array_filter( $sections, function( $section ) {
			return ! empty( $section['fields'] );
		} ) );
	}

	protected function requires_complete_editor_form() {
		return $this->uses_settings_panel() || ! empty( $this->settings['sidebar_sections'] );
	}

	/**
	 * Keep sidebar fields out of the default tab.
	 */
	protected function get_untabbed_fields() {
		$fields = parent::get_untabbed_fields();
		$sidebar_fields = array();
		$sections = $this->settings['sidebar_sections'] ?? array();
		if ( $this->uses_settings_panel() && isset( $this->settings['panel_sections'] ) ) {
			$sections = array_merge( $sections, $this->settings['panel_sections'] );
		}
		foreach ( $sections as $section ) {
			$sidebar_fields = array_merge( $sidebar_fields, $section['fields'] );
		}
		return array_filter( $fields, function( $field ) use ( $sidebar_fields ) {
			return ! in_array( $field->get_name(), $sidebar_fields, true );
		} );
	}

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
			$conditions = $this->condition_collection->get_fulfillables();
			$this->default_access_condition = $conditions ? end( $conditions )['fulfillable'] : null;
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
			if ( $this->uses_settings_panel() || $this->get_sidebar_sections() ) {
				add_action( $this->get_id() . '_after_sidebar', array( $this, 'render_language_complete' ), PHP_INT_MAX );
			} else {
				add_action( $this->get_id() . '_after_fields', array( $this, 'render_language_complete' ) );
			}
		}
	}

	/**
	 * Checks whether the current save request is valid
	 *
	 * @return bool
	 */
	public function is_valid_save() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $this->is_valid_attach_for_object( null ) ) {
			return false;
		}
		if ( ! $this->verified_nonce_in_request() ) {
			return false;
		}

		if ( $this->requires_complete_editor_form() ) {
			$input = Helper::input();
			$complete = $this->get_id() . '_options_complete';
			if ( ! isset( $input[ $complete ] ) || '1' !== $input[ $complete ] ) {
				return false;
			}
		}

		if ( ! $this->verified_language_in_request() ) {
			$this->errors[] = esc_html__( 'The settings language could not be verified. Reload the page and try again.', 'carbon-fields' );
			return false;
		}
		$input = $this->get_editor_form_input();
		if ( is_wp_error( $input ) ) {
			$this->errors[] = esc_html( $input->get_error_message() );
			return false;
		}
		$denied = $this->restricted_option_names();
		if ( $this->has_restricted_input( $input, $denied ) ) {
			$this->errors[] = esc_html( $this->restricted_settings_error()->get_error_message() );
			return false;
		}
		foreach ( $this->get_editable_fields( $denied ) as $field ) {
			$name = $field->get_name();
			if ( $field instanceof \Carbon_Fields\Field\Icon_Field && isset( $input[ $name ] ) && ! $field->is_valid_form_value( $input[ $name ] ) ) {
				$this->errors[] = esc_html( $field->get_label() . ' · ' . __( 'Choose an icon from the available options.', 'carbon-fields' ) );
				return false;
			}
			if ( isset( $input[ $name ] ) &&
				( $field instanceof \Carbon_Fields\Field\Number_With_Unit_Field || $field instanceof \Carbon_Fields\Field\Typography_Field || $field instanceof \Carbon_Fields\Field\Link_Field ) &&
				! $field->is_valid_form_value( $input[ $name ] ) ) {
				$message = $field instanceof \Carbon_Fields\Field\Link_Field
					? __( 'Check the link and try again.', 'carbon-fields' )
					: __( 'Check the number, unit, or font settings and try again.', 'carbon-fields' );
				$this->errors[] = esc_html( $field->get_label() . ' · ' . $message );
				return false;
			}
		}
		return true;
	}

	protected function restricted_settings_error() {
		return new \WP_Error( 'carbon_fields_forbidden_settings', __( 'You are not allowed to edit some of these settings.', 'carbon-fields' ), array( 'status' => 403 ) );
	}

	protected function has_restricted_input( array $input, array $denied ) {
		foreach ( $this->fields as $field ) {
			if ( isset( $denied[ $field->get_base_name() ] ) && array_key_exists( $field->get_name(), $input ) ) {
				return true;
			}
		}
		return false;
	}

	public function validate_editor_request() {
		if ( ! $this->is_valid_attach_for_object( null ) || ! $this->_is_valid_save() ) {
			$message = $this->errors ? html_entity_decode( $this->errors[0], ENT_QUOTES, 'UTF-8' )
				: __( 'Check the form and try saving again.', 'carbon-fields' );
			return new \WP_Error( 'carbon_fields_invalid_form', $message );
		}

		$input = $this->get_editor_form_input();
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$language = $this->is_multilingual() ? $input[ $this->language_input_name() ] : null;
		return Polylang::with_language( $language, function() use ( $input ) {
			return $this->validate_editor_input( $input, true );
		} );
	}

	protected function validate_editor_input( array $input, $step = false ) {
		$fields = $this->get_editable_fields( $this->restricted_option_names() );
		if ( $step ) {
			$fields = $this->editor_step_fields( $fields );
		}
		$sections = $this->uses_settings_panel() ? $this->editable_sections( $this->editor_field_names( $fields ) ) : array();
		return Field_Validation::validate( $fields, $input, $sections );
	}

	public function prepare_rest_options( array $changes, array $denied ) {
		$fields = $this->get_editable_fields( $denied );
		$changes = array_intersect_key( $changes, array_flip( $this->field_base_names( $fields ) ) );
		if ( ! $changes ) {
			return array();
		}
		$sections = $this->uses_settings_panel() ? $this->editable_sections( $this->editor_field_names( $fields ) ) : array();
		return Field_Validation::validate_rest( $fields, $changes, $sections );
	}

	/**
	 * Perform save operation after successful is_valid_save() check.
	 * The call is propagated to all fields in the container.
	 *
	 * @param mixed $user_data
	 */
	public function save( $user_data = null ) {
		$input = $this->get_editor_form_input();
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$denied = $this->restricted_option_names();
		if ( ( $this->page_capability && ! current_user_can( $this->page_capability ) ) || $this->has_restricted_input( $input, $denied ) ) {
			return $this->restricted_settings_error();
		}
		if ( ! $this->verified_language_in_request() ) {
			return new \WP_Error( 'carbon_fields_invalid_language', __( 'The settings language could not be verified. Reload the page and try again.', 'carbon-fields' ) );
		}
		$language = $this->is_multilingual() ? $input[ $this->language_input_name() ] : null;
		$error = Polylang::with_language( $language, function() use ( $user_data, $input, $denied ) {
			if ( 'theme_options' === $this->type ) {
				$error = $this->validate_editor_input( $input );
				if ( $error ) {
					return $error;
				}
			}
			try {
				$this->save_editor_fields( $this->get_editable_fields( $denied ), $input );
			} catch ( Incorrect_Syntax_Exception $e ) {
				$this->errors[] = $e->getMessage();
			}
			do_action( 'carbon_fields_' . $this->type . '_container_saved', $user_data, $this );
		} );
		if ( is_wp_error( $error ) ) {
			$this->errors[] = esc_html( $error->get_error_message() );
			return $error;
		}

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
		if ( ( $this->page_capability && ! current_user_can( $this->page_capability ) ) || ! $this->static_conditions_pass() ) {
			return false;
		}
		return ! $this->fields || (bool) $this->get_editable_fields( $this->restricted_option_names() );
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
		return ( ! $this->page_capability || current_user_can( $this->page_capability ) ) && $this->all_conditions_pass( intval( $object_id ) );
	}

	/**
	 * Add theme options container pages.
	 * Hook the container saving action.
	 */
	public function attach() {
		$capability = $this->page_capability ?? 'read';

		// Add menu page
		if ( ! $this->settings['parent'] ) {
			add_menu_page(
				$this->title,
				$this->settings['menu_title'] ? $this->settings['menu_title'] : $this->title,
				$capability,
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
			$capability,
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
		if ( $this->page_capability && ! current_user_can( $this->page_capability ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), '', array( 'response' => 403 ) );
		}
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
