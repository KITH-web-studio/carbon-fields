<?php

namespace Carbon_Fields\Admin;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Field\Select_Field;
use Carbon_Fields\Helper\Field_Validation;

final class Post_List_Editor {
	const QUICK_INPUT = 'carbon_fields_quick_edit';
	const BULK_INPUT = 'carbon_fields_bulk_edit';

	private $post_type;
	private $columns = array();
	private $quick = array();
	private $bulk = array();

	public function __construct( $post_type, array $columns ) {
		$this->post_type = $post_type;
		foreach ( $columns as $key => $column ) {
			if ( ! $column['standard_storage'] || ( ! $column['quick_edit'] && ! $column['bulk_edit'] ) ) {
				continue;
			}
			$field = $column['container']->get_root_field_by_name( $column['name'] );
			$type = $field->get_attribute( 'type' );
			$is_text = 'text' === $field->get_type() && in_array( $type, array( '', 'text', 'number' ), true );
			$is_select = $field instanceof Select_Field && 'select' === $field->get_type() && '' === $field->get_options_source() && ! $field->has_remote_options();
			$logic = $field->get_conditional_logic();
			if ( ( ! $is_text && ! $is_select ) || $field->get_attribute( 'readOnly' ) || ! empty( $logic['rules'] ) ) {
				continue;
			}
			foreach ( $column['container']->get_fields() as $sibling ) {
				if ( $sibling instanceof Select_Field && $sibling->get_options_source() === $column['name'] ) {
					continue 2;
				}
			}
			$column['field'] = $field;
			$column['input_type'] = $is_select ? 'select' : ( $type ?: 'text' );
			$this->columns[ $key ] = $column;
			if ( $column['quick_edit'] ) {
				$this->quick[ $key ] = $column;
			}
			if ( $column['bulk_edit'] ) {
				$this->bulk[ $key ] = $column;
			}
		}
	}

	public function attach() {
		if ( $this->quick || $this->bulk ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		}
		if ( $this->quick ) {
			add_action( 'quick_edit_custom_box', array( $this, 'render_quick' ), 10, 2 );
		}
		if ( $this->bulk ) {
			add_action( 'bulk_edit_custom_box', array( $this, 'render_bulk' ), 10, 2 );
			add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		}
		$this->prepare_bulk_edit();
	}

	public function enqueue() {
		wp_enqueue_script( 'carbon-fields-post-list', \Carbon_Fields\URL . '/core/Admin/assets/post-list.js', array( 'inline-edit-post' ), \Carbon_Fields\VERSION, true );
	}

	public function render_quick( $key, $post_type ) {
		if ( $post_type === $this->post_type && isset( $this->quick[ $key ] ) ) {
			$this->render_control( $key, $this->quick[ $key ], false );
		}
	}

	public function render_bulk( $key, $post_type ) {
		if ( $post_type === $this->post_type && isset( $this->bulk[ $key ] ) ) {
			$this->render_control( $key, $this->bulk[ $key ], true );
		}
	}

	private function render_control( $key, array $column, $bulk ) {
		$field = $column['field'];
		$id = 'cf-' . ( $bulk ? 'bulk-' : 'quick-' ) . $key;
		$label = $column['label'];
		$name = $bulk ? self::BULK_INPUT . '[' . $key . ']' : self::QUICK_INPUT . '[' . $key . ']';
		printf( '<fieldset class="inline-edit-col-right"><div class="inline-edit-col cf-list-field" data-cf-list-field="%s"%s>', esc_attr( $key ), $bulk ? '' : ' hidden' );
		if ( $bulk ) {
			printf( '<label class="alignleft"><input type="checkbox" name="%s[apply]" value="1"> %s</label>',
				esc_attr( $name ), esc_html( sprintf( __( 'Change %s', 'carbon-fields' ), $label ) )
			);
			$name .= '[value]';
		}
		printf( '<label class="inline-edit-group"><span class="title">%s</span><span class="input-text-wrap">', esc_html( $label ) );
		$attributes = sprintf( ' id="%s" name="%s" data-cf-list-value disabled', esc_attr( $id ), esc_attr( $name ) );
		if ( $field->is_required() ) {
			$attributes .= ' required';
		}
		if ( 'select' === $column['input_type'] ) {
			echo '<select' . $attributes . '>';
			foreach ( $field->get_options() as $value => $title ) {
				printf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $title ) );
			}
			echo '</select>';
		} else {
			foreach ( array( 'maxLength', 'min', 'max', 'step', 'pattern', 'placeholder' ) as $attribute ) {
				$value = $field->get_attribute( $attribute );
				if ( is_scalar( $value ) && '' !== $value ) {
					$attributes .= sprintf( ' %s="%s"', esc_attr( strtolower( $attribute ) ), esc_attr( $value ) );
				}
			}
			if ( 'number' === $column['input_type'] && '' === $field->get_attribute( 'step' ) ) {
				$attributes .= ' step="any"';
			}
			printf( '<input type="%s"%s>', esc_attr( $column['input_type'] ), $attributes );
		}
		echo '</span></label></div></fieldset>';
	}

	public function render_row_value( $key, $post_id, $value ) {
		if ( isset( $this->quick[ $key ] ) && $this->can_edit( $this->quick[ $key ], $post_id ) ) {
			$value = wp_json_encode( (string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( false !== $value ) {
				printf( '<span hidden data-cf-inline-key="%s" data-cf-inline-value="%s"></span>', esc_attr( $key ), esc_attr( $value ) );
			}
		}
	}

	public function prepare_quick_edit( $post_id ) {
		if ( ! array_key_exists( self::QUICK_INPUT, $_POST ) || wp_check_post_lock( $post_id ) ) {
			return;
		}
		$input = wp_unslash( $_POST[ self::QUICK_INPUT ] );
		$prepared = $this->validate( $this->quick, $post_id, $input );
		if ( is_wp_error( $prepared ) ) {
			wp_die( esc_html( $prepared->get_error_message() ) );
		}
		$save = function( $saved_id ) use ( $post_id, $prepared, &$save ) {
			if ( $post_id === $saved_id ) {
				remove_action( 'save_post', $save );
				if ( ! $this->save_changes( $saved_id, $prepared ) ) {
					wp_die( esc_html__( 'Could not save the field.', 'carbon-fields' ) );
				}
			}
		};
		add_action( 'save_post', $save );
	}

	private function prepare_bulk_edit() {
		$nonce = $_POST['_wpnonce'] ?? '';
		$rows = $_POST['post'] ?? null;
		$input = $_POST[ self::BULK_INPUT ] ?? null;
		if ( ! is_array( $input ) || ! is_array( $rows ) || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'bulk-posts' ) ||
			! isset( $_POST['bulk_edit'] ) || ! empty( $_REQUEST['filter_action'] ) ||
			isset( $_REQUEST['delete_all'] ) || isset( $_REQUEST['delete_all2'] ) || 'edit' !== ( $_REQUEST['action'] ?? '' ) ) {
			return;
		}
		$changes = array();
		foreach ( wp_unslash( $input ) as $key => $data ) {
			if ( is_array( $data ) && '1' === ( $data['apply'] ?? '' ) ) {
				$changes[ $key ] = $data['value'] ?? null;
			}
		}
		if ( ! $changes ) {
			return;
		}
		$selected = array();
		foreach ( $rows as $id ) {
			if ( is_string( $id ) && ctype_digit( $id ) && (int) $id > 0 ) {
				$selected[ (int) $id ] = true;
			}
		}
		$ready = array();
		$updated = array();
		add_action( 'pre_post_update', function( $post_id ) use ( $selected, $changes, &$ready ) {
			if ( isset( $selected[ $post_id ] ) && ! isset( $ready[ $post_id ] ) && ! wp_check_post_lock( $post_id ) ) {
				$ready[ $post_id ] = $this->validate( $this->bulk, $post_id, $changes );
			}
		} );
		add_action( 'save_post', function( $post_id ) use ( &$ready, &$updated ) {
			if ( isset( $ready[ $post_id ] ) && ! is_wp_error( $ready[ $post_id ] ) ) {
				$prepared = $ready[ $post_id ];
				$ready[ $post_id ] = false;
				if ( $this->save_changes( $post_id, $prepared ) ) {
					$updated[ $post_id ] = true;
				}
			}
		} );
		add_filter( 'wp_redirect', function( $location ) use ( $selected, &$updated ) {
			if ( 'edit.php' !== basename( wp_parse_url( $location, PHP_URL_PATH ) ?: '' ) ) {
				return $location;
			}
			$skipped = array_diff_key( $selected, $updated );
			$visible = array_filter( array_keys( $skipped ), array( $this, 'can_edit_post' ) );
			return add_query_arg( array(
				'cf_bulk_updated' => count( $updated ),
				'cf_bulk_skipped' => count( $skipped ),
				'cf_bulk_posts' => implode( '-', array_slice( $visible, 0, 20 ) ),
			), $location );
		} );
	}

	public function bulk_notice() {
		$updated = $_GET['cf_bulk_updated'] ?? '';
		$skipped = $_GET['cf_bulk_skipped'] ?? '';
		if ( ! is_string( $updated ) || ! ctype_digit( $updated ) || ! is_string( $skipped ) || ! ctype_digit( $skipped ) ) {
			return;
		}
		/* translators: 1: Number of posts updated, 2: Number of posts skipped. */
		$message = sprintf( __( 'Carbon Fields bulk edit. Updated: %1$s. Skipped: %2$s.', 'carbon-fields' ), number_format_i18n( (int) $updated ), number_format_i18n( (int) $skipped ) );
		printf( '<div class="notice %s is-dismissible"><p>%s</p>', (int) $skipped ? 'notice-warning' : 'notice-success', esc_html( $message ) );
		$ids = $_GET['cf_bulk_posts'] ?? '';
		if ( (int) $skipped && is_string( $ids ) && strlen( $ids ) < 450 && preg_match( '/^[0-9]+(?:-[0-9]+)*$/D', $ids ) ) {
			$titles = array();
			foreach ( array_slice( explode( '-', $ids ), 0, 20 ) as $id ) {
				if ( $this->can_edit_post( (int) $id ) ) {
					$titles[] = get_the_title( (int) $id ) ?: sprintf( '#%d', $id );
				}
			}
			if ( $titles ) {
				printf( '<p>%s</p>', esc_html( sprintf( __( 'Skipped posts: %s', 'carbon-fields' ), implode( ', ', $titles ) ) ) );
			}
		}
		echo '</div>';
	}

	private function can_edit( array $column, $post_id ) {
		return $this->can_edit_post( $post_id ) && $column['container']->is_valid_attach_for_object( $post_id );
	}

	private function can_edit_post( $post_id ) {
		$post = get_post( $post_id );
		return $post && $post->post_type === $this->post_type &&
			! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) &&
			current_user_can( 'edit_post', $post_id );
	}

	private function validate( array $editable, $post_id, $input ) {
		$invalid = new \WP_Error( 'carbon_fields_invalid_form', __( 'Check the form and try saving again.', 'carbon-fields' ) );
		$groups = array();
		if ( ! is_array( $input ) ) {
			return $invalid;
		}
		foreach ( $input as $key => $value ) {
			$column = $editable[ $key ] ?? null;
			if ( ! $column || ! $this->can_edit( $column, $post_id ) ) {
				return new \WP_Error( 'carbon_fields_forbidden_field', __( 'You are not allowed to edit some of these settings.', 'carbon-fields' ) );
			}
			if ( ! $this->valid_value( $column, $value ) ) {
				return $invalid;
			}
			$container_id = $column['container']->get_id();
			$groups[ $container_id ]['container'] = $column['container'];
			$groups[ $container_id ]['changes'][ $column['name'] ] = $value;
			$groups[ $container_id ]['keys'][] = $key;
		}
		foreach ( $groups as &$group ) {
			$needs_siblings = false;
			foreach ( $group['keys'] as $key ) {
				if ( $editable[ $key ]['field']->has_validation_callback() ) {
					$needs_siblings = true;
					break;
				}
			}
			$fields = array();
			foreach ( $group['container']->get_fields() as $field ) {
				if ( ! $needs_siblings && ! array_key_exists( $field->get_base_name(), $group['changes'] ) ) {
					continue;
				}
				$field = clone $field;
				$field->set_datastore( clone $field->get_datastore(), $field->has_default_datastore() );
				$field->get_datastore()->set_object_id( $post_id );
				$fields[] = $field;
			}
			$group['fields'] = Field_Validation::validate_rest( $fields, $group['changes'] );
			if ( is_wp_error( $group['fields'] ) ) {
				return $group['fields'];
			}
		}
		return $groups;
	}

	private function valid_value( array $column, $value ) {
		$field = $column['field'];
		if ( ! is_string( $value ) || ! $field->is_valid_form_value( $value ) ||
			( $field->is_required() && '' === trim( $value ) ) ) {
			return false;
		}
		if ( 'select' === $column['input_type'] ) {
			return array_key_exists( $value, $field->get_options() );
		}
		if ( 'text' === $column['input_type'] ) {
			$limit = $field->get_attribute( 'maxLength' );
			return ! is_numeric( $limit ) || $limit < 0 || preg_match_all( '/./us', $value ) <= $limit;
		}
		if ( '' === $value ) {
			return true;
		}
		$min = $field->get_attribute( 'min' );
		$max = $field->get_attribute( 'max' );
		$step = $field->get_attribute( 'step' );
		if ( ! preg_match( '/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value ) ||
			! is_finite( (float) $value ) || ( is_numeric( $min ) && $value < $min ) || ( is_numeric( $max ) && $value > $max ) ) {
			return false;
		}
		if ( ! is_numeric( $step ) || $step <= 0 ) {
			return true;
		}
		$steps = ( (float) $value - (float) $min ) / (float) $step;
		return is_finite( $steps ) && abs( $steps - round( $steps ) ) < 0.0000001;
	}

	private function save_changes( $post_id, array $groups ) {
		foreach ( $groups as $group ) {
			foreach ( $group['keys'] as $key ) {
				if ( ! $this->can_edit( $this->columns[ $key ], $post_id ) ) {
					return false;
				}
			}
		}
		foreach ( $groups as $group ) {
			$container = $group['container'];
			$result = Carbon_Fields::service( 'revisions' )->track_container_save( $post_id, $container, function() use ( $group ) {
				foreach ( $group['fields'] as $field ) {
					if ( false === $field->save() ) {
						return false;
					}
				}
				return true;
			} );
			if ( ! $result ) {
				return false;
			}
			do_action( 'carbon_fields_post_meta_container_saved', $post_id, $container );
		}
		return true;
	}
}
