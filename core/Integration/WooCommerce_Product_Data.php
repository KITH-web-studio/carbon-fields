<?php

namespace Carbon_Fields\Integration;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container\Repository;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;
use Carbon_Fields\Field\Checkbox_Field;
use Carbon_Fields\Field\Select_Field;
use Carbon_Fields\Helper\Field_Validation;
use Carbon_Fields\Helper\Helper;

/**
 * Post meta fields inside the classic WooCommerce product and variation editor.
 */
final class WooCommerce_Product_Data {
	const INPUT = 'carbon_fields_woocommerce';
	const NATIVE_TABS = array(
		'general' => 'woocommerce_product_options_general_product_data',
		'inventory' => 'woocommerce_product_options_inventory_product_data',
		'shipping' => 'woocommerce_product_options_shipping_product_data',
		'linked_product' => 'woocommerce_product_options_related',
		'attribute' => 'woocommerce_product_options_attributes',
		'advanced' => 'woocommerce_product_options_advanced',
	);

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public static function product_placement( $tab, array $options, $title ) {
		$options = wp_parse_args( $options, array( 'label' => $title, 'priority' => 50, 'product_types' => array() ) );
		if ( ! is_string( $tab ) || ! Helper::is_valid_entity_id( $tab ) || 'variations' === $tab ||
			! is_string( $options['label'] ) || '' === trim( $options['label'] ) ||
			! is_int( $options['priority'] ) || ! is_array( $options['product_types'] ) ) {
			Incorrect_Syntax_Exception::raise( 'Invalid WooCommerce product tab. Use a tab ID, text label, integer priority and an array of product types.' );
			return null;
		}
		foreach ( $options['product_types'] as $type ) {
			if ( ! is_string( $type ) || ! Helper::is_valid_entity_id( $type ) ) {
				Incorrect_Syntax_Exception::raise( 'Use WooCommerce product type slugs in product_types.' );
				return null;
			}
		}
		return array(
			'tab' => $tab,
			'label' => $options['label'],
			'priority' => $options['priority'],
			'product_types' => array_unique( $options['product_types'] ),
		);
	}

	public static function post_type( array $placement ) {
		return isset( $placement['variation'] ) ? 'product_variation' : 'product';
	}

	public function boot() {
		foreach ( array( 'product', 'product_variation' ) as $type ) {
			add_filter( 'woocommerce_rest_pre_insert_' . $type . '_object', array( $this, 'validate_rest' ), 20, 2 );
			add_filter( 'woocommerce_rest_prepare_' . $type . '_object', array( $this, 'filter_rest_response' ), 20, 2 );
		}
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panels' ) );
		foreach ( self::NATIVE_TABS as $tab => $hook ) {
			add_action( $hook, function() use ( $tab ) {
				$product = $GLOBALS['product_object'] ?? null;
				foreach ( $this->get_groups( $product )[ $tab ] ?? array() as $group ) {
					$this->render_group( $product, $group, false );
				}
			} );
		}
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation' ), 10, 3 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save' ) );
	}

	public function add_tabs( $tabs ) {
		foreach ( $this->get_groups( $GLOBALS['product_object'] ?? null ) as $tab => $groups ) {
			if ( isset( self::NATIVE_TABS[ $tab ] ) ) {
				continue;
			}
			$placement = reset( $groups )['placement'];
			$types = array();
			foreach ( $groups as $group ) {
				if ( ! $group['placement']['product_types'] ) {
					$types = array();
					break;
				}
				$types = array_merge( $types, $group['placement']['product_types'] );
			}
			$tabs[ 'cf_' . $tab ] = array(
				'label' => $placement['label'],
				'target' => $this->panel_id( $tab ),
				'class' => $this->type_classes( array_unique( $types ) ),
				'priority' => $placement['priority'],
			);
		}
		return $tabs;
	}

	public function render_panels() {
		$product = $GLOBALS['product_object'] ?? null;
		foreach ( $this->get_groups( $product ) as $tab => $groups ) {
			if ( isset( self::NATIVE_TABS[ $tab ] ) ) {
				continue;
			}
			printf( '<div id="%s" class="panel woocommerce_options_panel hidden">', esc_attr( $this->panel_id( $tab ) ) );
			foreach ( $groups as $group ) {
				$this->render_group( $product, $group, false );
			}
			echo '</div>';
		}
	}

	public function render_variation( $loop, $variation_data, $post ) {
		$product = wc_get_product( $post->ID );
		foreach ( $this->get_groups( $product )['variations'] ?? array() as $group ) {
			$this->render_group( $product, $group, true );
		}
	}

	private function panel_id( $tab ) {
		return 'cf_' . $tab . '_product_data';
	}

	private function type_classes( array $types ) {
		return array_map( function( $type ) {
			return 'show_if_' . $type;
		}, $types );
	}

	private function get_groups( $product ) {
		if ( ! ( $product instanceof \WC_Product ) || ! $product->get_id() ) {
			return array();
		}
		$id = $product->get_id();
		$type = get_post_type( $id );
		$parent_id = 'product_variation' === $type ? wp_get_post_parent_id( $id ) : $id;
		if ( ! $parent_id || 'product' !== get_post_type( $parent_id ) ||
			! current_user_can( 'edit_post', $id ) || ! current_user_can( 'edit_post', $parent_id ) ) {
			return array();
		}

		$groups = array();
		$containers = $this->repository->get_containers( 'post_meta' );
		$internal = $product->get_data_store()->get_internal_meta_keys();
		foreach ( $containers as $container ) {
			$placement = $container->get_woocommerce_placement();
			if ( ! $placement || ! $container->is_valid_attach_for_object( $id ) ) {
				continue;
			}
			$fields = array();
			foreach ( $container->get_fields() as $field ) {
				$name = $field->get_base_name();
				if ( $this->supports( $field ) && ! in_array( $field->get_name(), $internal, true ) &&
					$this->repository->allows_rest_field( $container, $id, $name ) ) {
					$fields[ $name ] = $field;
				}
			}
			if ( $fields ) {
				$tab = $placement['tab'] ?? 'variations';
				$groups[ $tab ][ $container->get_id() ] = array(
					'container' => $container,
					'fields' => $fields,
					'placement' => $placement,
				);
			}
		}
		return $groups;
	}

	private function supports( $field ) {
		$logic = $field->get_conditional_logic();
		return in_array( $field->get_type(), array( 'text', 'textarea', 'select', 'checkbox' ), true ) &&
			empty( $logic['rules'] ) && $field->is_simple_root_field() && $field->get_datastore() &&
			Post_Meta_Datastore::class === get_class( $field->get_datastore() ) &&
			! ( $field instanceof Select_Field && ( '' !== $field->get_options_source() || $field->has_remote_options() ) );
	}

	private function scoped_field( $field, $id ) {
		$clone = clone $field;
		$clone->set_datastore( clone $field->get_datastore(), $field->has_default_datastore() );
		$clone->get_datastore()->set_object_id( $id );
		return $clone;
	}

	private function token( $product_id, $container_id ) {
		return self::INPUT . ':' . $product_id . ':' . $container_id;
	}

	private function render_group( \WC_Product $product, array $group, $variation ) {
		$product_id = $product->get_id();
		$container = $group['container'];
		$prefix = self::INPUT . '[' . $product_id . '][' . $container->get_id() . ']';
		$classes = $this->type_classes( $group['placement']['product_types'] ?? array() );
		$classes[] = $variation ? 'form-row form-row-full cf-woo-variation-fields' : 'options_group';
		printf( '<div class="%s"><input type="hidden" name="%s[nonce]" value="%s">', esc_attr( implode( ' ', $classes ) ), esc_attr( $prefix ),
			esc_attr( wp_create_nonce( $this->token( $product_id, $container->get_id() ) ) )
		);
		if ( $variation ) {
			printf( '<p><strong>%s</strong></p>', esc_html( $container->title ) );
		}
		foreach ( $group['fields'] as $name => $field ) {
			$field = $this->scoped_field( $field, $product_id );
			$field->load();
			$args = array(
				'id' => 'cf-woo-' . $product_id . '-' . $container->get_id() . '-' . $name,
				'name' => $prefix . '[values][' . $name . ']',
				'label' => esc_html( $this->field_label( $field ) ),
				'description' => $field->get_help_text(),
				'desc_tip' => ! $variation,
				'value' => $field->get_formatted_value() ?? '',
				'wrapper_class' => $variation ? 'form-row form-row-full' : '',
				'custom_attributes' => $this->attributes( $field ),
			);
			switch ( $field->get_type() ) {
				case 'checkbox':
					$args['value'] = $args['value'] ? '1' : '';
					$args['cbvalue'] = '1';
					if ( ! $field->get_attribute( 'readOnly' ) ) {
						printf( '<input type="hidden" name="%s[checkboxes][%s]" value="1">', esc_attr( $prefix ), esc_attr( $name ) );
					}
					if ( $variation ) {
						// Variation options use a wrapping label and the native options spacing.
						printf( '<p class="form-row form-row-full options"><label for="%1$s">%2$s <input type="checkbox" class="checkbox" id="%1$s" name="%3$s" value="1" %4$s %5$s></label></p>',
							esc_attr( $args['id'] ), $args['label'], esc_attr( $args['name'] ),
							checked( $args['value'], '1', false ),
							disabled( (bool) $field->get_attribute( 'readOnly' ), true, false )
						);
					} else {
						woocommerce_wp_checkbox( $args );
					}
					break;
				case 'select':
					$args['options'] = $field->get_options();
					woocommerce_wp_select( $args );
					break;
				case 'textarea':
					woocommerce_wp_textarea_input( $args );
					break;
				default:
					$args['type'] = $this->text_type( $field );
					$args['placeholder'] = $field->get_attribute( 'placeholder' );
					woocommerce_wp_text_input( $args );
			}
		}
		$actions = $variation ? array() : \Carbon_Fields\Admin\Field_Actions::describe( $container, $product_id );
		if ( $actions ) {
			printf( '<div class="cf-woo-field-actions" data-cf-woo-field-actions="%s" data-field-prefix="%s" data-post-id="%d" data-container-id="%s"></div>',
				esc_attr( wp_json_encode( $actions ) ), esc_attr( $prefix ), $product_id, esc_attr( $container->get_id() ) );
		}
		printf( '<input type="hidden" name="%s[complete]" value="1"></div>', esc_attr( $prefix ) );
	}

	private function field_label( $field ) {
		return $field instanceof Checkbox_Field ? $field->get_option_label() : $field->get_label();
	}

	private function text_type( $field ) {
		$type = $field->get_attribute( 'type' );
		return in_array( $type, array( 'text', 'number', 'email', 'url', 'tel' ), true ) ? $type : 'text';
	}

	private function attributes( $field ) {
		$attributes = array();
		if ( $field->get_attribute( 'readOnly' ) ) {
			return array( 'disabled' => 'disabled' );
		}
		// Required is validated on the server because WooCommerce can hide entire panels.
		foreach ( array( 'min', 'max', 'step', 'maxLength', 'pattern' ) as $name ) {
			$value = $field->get_attribute( $name );
			if ( is_scalar( $value ) && '' !== $value ) {
				$attributes[ strtolower( $name ) ] = $value;
			}
		}
		if ( 'number' === $this->text_type( $field ) && ! isset( $attributes['step'] ) ) {
			$attributes['step'] = 'any';
		}
		return $attributes;
	}

	public function save( \WC_Product $product ) {
		$id = $product->get_id();
		$input = $_POST[ self::INPUT ][ $id ] ?? null;
		if ( ! is_array( $input ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) ) {
			return;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = $_POST['product_id'] ?? $_POST['post_ID'] ?? '';
			if ( ! is_string( $parent ) || ! ctype_digit( $parent ) || (int) $parent !== $product->get_parent_id() ||
				(int) $parent !== wp_get_post_parent_id( $id ) ) {
				return;
			}
		}

		$prepared = array();
		foreach ( $this->get_groups( $product ) as $groups ) {
			foreach ( $groups as $key => $group ) {
				$types = $group['placement']['product_types'] ?? array();
				if ( ! isset( $input[ $key ] ) || ( $types && ! in_array( $product->get_type(), $types, true ) ) ) {
					continue;
				}
				$fields = $this->prepare( $id, $group, wp_unslash( $input[ $key ] ) );
				if ( is_wp_error( $fields ) ) {
					$this->notice( $product, $fields->get_error_message() );
					return;
				}
				$prepared[] = array( $group['container'], $fields );
			}
		}
		foreach ( $prepared as $entry ) {
			list( $container, $fields ) = $entry;
			$saved = Carbon_Fields::service( 'revisions' )->track_container_save( $id, $container, function() use ( $fields ) {
				foreach ( $fields as $field ) {
					if ( false === $field->save() ) {
						return false;
					}
				}
				return true;
			} );
			if ( ! $saved ) {
				$this->notice( $product, __( 'Could not save the field.', 'carbon-fields' ) );
				return;
			}
			if ( $fields ) {
				do_action( 'carbon_fields_post_meta_container_saved', $id, $container );
			}
		}
	}

	private function prepare( $id, array $group, $input ) {
		$nonce = $input['nonce'] ?? '';
		$values = $input['values'] ?? array();
		$checkboxes = $input['checkboxes'] ?? array();
		if ( ! is_array( $input ) || ! is_string( $nonce ) || '1' !== ( $input['complete'] ?? '' ) ||
			! wp_verify_nonce( $nonce, $this->token( $id, $group['container']->get_id() ) ) ||
			! is_array( $values ) || ! is_array( $checkboxes ) ) {
			return new \WP_Error( 'carbon_fields_invalid_form', __( 'Check the form and try saving again.', 'carbon-fields' ) );
		}
		$changes = array();
		foreach ( $group['fields'] as $name => $field ) {
			if ( $field->get_attribute( 'readOnly' ) ) {
				continue;
			}
			$value = $values[ $name ] ?? ( $field instanceof Checkbox_Field && '1' === ( $checkboxes[ $name ] ?? '' ) ? '' : null );
			if ( ! $this->valid_value( $field, $value ) ) {
				return new \WP_Error( 'carbon_fields_invalid_value', sprintf( __( 'Check the value for “%s”.', 'carbon-fields' ), $this->field_label( $field ) ) );
			}
			$changes[ $name ] = $field instanceof Checkbox_Field && '1' === $value ? $field->get_option_value() : $value;
		}
		$fields = array();
		foreach ( $group['container']->get_fields() as $field ) {
			$fields[] = $this->scoped_field( $field, $id );
		}
		return Field_Validation::validate_rest( $fields, $changes );
	}

	private function valid_value( $field, $value ) {
		if ( ! is_string( $value ) || ! $field->is_valid_form_value( $value ) || ( $field->is_required() && '' === trim( $value ) ) ) {
			return false;
		}
		if ( $field instanceof Checkbox_Field ) {
			return in_array( $value, array( '1', '' ), true );
		}
		if ( $field instanceof Select_Field ) {
			return array_key_exists( $value, $field->get_options() );
		}
		$type = $this->text_type( $field );
		if ( 'number' === $type && '' !== $value ) {
			$min = $field->get_attribute( 'min' );
			$max = $field->get_attribute( 'max' );
			$step = $field->get_attribute( 'step' );
			if ( ! preg_match( '/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value ) || ! is_finite( (float) $value ) ||
				( is_numeric( $min ) && $value < $min ) || ( is_numeric( $max ) && $value > $max ) ) {
				return false;
			}
			if ( is_numeric( $step ) && $step > 0 ) {
				$steps = ( (float) $value - (float) $min ) / (float) $step;
				return is_finite( $steps ) && abs( $steps - round( $steps ) ) < 0.0000001;
			}
		}
		if ( 'email' === $type && '' !== $value ) {
			return (bool) is_email( $value );
		}
		if ( 'url' === $type && '' !== $value ) {
			return in_array( wp_parse_url( $value, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) && (bool) wp_parse_url( $value, PHP_URL_HOST );
		}
		$limit = $field->get_attribute( 'maxLength' );
		return ! is_numeric( $limit ) || $limit < 0 || preg_match_all( '/./us', $value ) <= $limit;
	}

	/**
	 * WooCommerce's meta_data API must enforce the same rules as the product editor.
	 */
	public function validate_rest( $product, $request ) {
		if ( is_wp_error( $product ) || ! ( $product instanceof \WC_Product ) || ! is_array( $request['meta_data'] ) ) {
			return $product;
		}
		$declarations = $this->rest_declarations( $product );
		$changes = array();
		foreach ( $request['meta_data'] as $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$key = $meta['key'] ?? '';
			$by_id = isset( $meta['id'] ) ? get_metadata_by_mid( 'post', (int) $meta['id'] ) : false;
			foreach ( array_unique( array( $key, $by_id ? $by_id->meta_key : '' ) ) as $name ) {
				if ( isset( $declarations[ $name ] ) ) {
					$changes[ $name ] = $meta['value'] ?? null;
				}
			}
		}
		if ( ! $changes ) {
			return $product;
		}
		$allowed = array();
		foreach ( $this->get_groups( $product ) as $groups ) {
			foreach ( $groups as $group ) {
				$types = $group['placement']['product_types'] ?? array();
				if ( $types && ! in_array( $product->get_type(), $types, true ) ) {
					continue;
				}
				$values = array();
				foreach ( $group['fields'] as $field ) {
					$key = $field->get_name();
					if ( ! array_key_exists( $key, $changes ) || $field->get_attribute( 'readOnly' ) ) {
						continue;
					}
					$value = $changes[ $key ];
					if ( $field instanceof Checkbox_Field ) {
						$value = true === $value || $field->get_option_value() === $value ? '1' : $value;
						$value = false === $value ? '' : $value;
					} elseif ( is_int( $value ) || is_float( $value ) ) {
						$value = (string) $value;
					}
					if ( ! $this->valid_value( $field, $value ) ) {
						return new \WP_Error( 'carbon_fields_invalid_value', __( 'Check the form and try saving again.', 'carbon-fields' ), array( 'status' => 422 ) );
					}
					$values[ $field->get_base_name() ] = $field instanceof Checkbox_Field && '1' === $value ? $field->get_option_value() : $value;
					$product->update_meta_data( $key, $values[ $field->get_base_name() ] );
					$allowed[ $key ] = true;
				}
				if ( $values ) {
					$fields = array();
					foreach ( $group['container']->get_fields() as $field ) {
						$fields[] = $this->scoped_field( $field, $product->get_id() );
					}
					$error = Field_Validation::validate_rest( $fields, $values );
					if ( is_wp_error( $error ) ) {
						return $error;
					}
				}
			}
		}
		if ( array_diff_key( $changes, $allowed ) ) {
			return new \WP_Error( 'carbon_fields_forbidden_field', __( 'You are not allowed to edit some of these settings.', 'carbon-fields' ), array( 'status' => 403 ) );
		}
		return $product;
	}

	public function filter_rest_response( $response, $product ) {
		if ( ! ( $product instanceof \WC_Product ) || ! ( $response instanceof \WP_REST_Response ) ) {
			return $response;
		}
		$declared = $this->rest_declarations( $product );
		if ( ! $declared ) {
			return $response;
		}
		$visible = array();
		foreach ( $this->get_groups( $product ) as $groups ) {
			foreach ( $groups as $group ) {
				foreach ( $group['fields'] as $field ) {
					$visible[ $field->get_name() ] = true;
				}
			}
		}
		$data = $response->get_data();
		if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
			$data['meta_data'] = array_values( array_filter( $data['meta_data'], function( $item ) use ( $declared, $visible ) {
				$key = $item instanceof \WC_Meta_Data ? $item->key : ( $item['key'] ?? '' );
				return ! isset( $declared[ $key ] ) || isset( $visible[ $key ] );
			} ) );
			$response->set_data( $data );
		}
		return $response;
	}

	private function rest_declarations( \WC_Product $product ) {
		$declared = array();
		foreach ( $this->repository->get_containers( 'post_meta' ) as $container ) {
			$placement = $container->get_woocommerce_placement();
			$type = $product->get_id() ? get_post_type( $product->get_id() ) : ( $product->is_type( 'variation' ) ? 'product_variation' : 'product' );
			if ( $placement && self::post_type( $placement ) === $type ) {
				foreach ( $container->get_fields() as $field ) {
					$declared[ $field->get_name() ] = true;
				}
			}
		}
		return $declared;
	}

	private function notice( \WC_Product $product, $message ) {
		if ( $product->is_type( 'variation' ) ) {
			/* translators: 1: Variation ID, 2: validation message. */
			$message = sprintf( __( 'Variation #%1$d: %2$s', 'carbon-fields' ), $product->get_id(), $message );
		}
		\WC_Admin_Meta_Boxes::add_error( esc_html( $message ) );
	}
}
