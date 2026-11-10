<?php

namespace Carbon_Fields\REST_API;

use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Container\Repository as ContainerRepository;

/**
* Register custom routes for REST API
*/
class Router {

	/**
	 * Carbon Fields routes
	 *
	 * @var array
	 */
	protected $routes = array(
		'post_meta' => array(
			'path'                => '/posts/(?P<id>\d+)',
			'callback'            => 'get_post_meta',
			'permission_callback' => 'post_meta_permission',
			'methods'             => 'GET',
		),
		'term_meta' => array(
			'path'                => '/terms/(?P<id>\d+)',
			'callback'            => 'get_term_meta',
			'permission_callback' => 'term_meta_permission',
			'methods'             => 'GET',
		),
		'user_meta' => array(
			'path'                => '/users/(?P<id>\d+)',
			'callback'            => 'get_user_meta',
			'permission_callback' => 'user_meta_permission',
			'methods'             => 'GET',
		),
		'comment_meta' => array(
			'path'                => '/comments/(?P<id>\d+)',
			'callback'            => 'get_comment_meta',
			'permission_callback' => 'comment_meta_permission',
			'methods'             => 'GET',
		),
		'theme_options' => array(
			'path'                => '/options/',
			'callback'            => 'options_accessor',
			'permission_callback' => 'options_permission',
			'methods'             => array( 'GET', 'POST' ),
		),
		'association_data' => array(
			'path'                => '/association',
			'callback'            => 'get_association_data',
			'permission_callback' => 'association_permission',
			'methods'             => 'GET',
		),
		'association_options' => array(
			'path'                => '/association/options',
			'callback'            => 'get_association_options',
			'permission_callback' => 'association_permission',
			'methods'             => 'GET',
		),
		'attachment_data' => array(
			'path'                => '/attachment',
			'callback'            => 'get_attachment_data',
			'permission_callback' => 'attachment_data_permission',
			'methods'             => 'GET',
			'args'                => 'attachment_data_args_schema',
		),
		'block_renderer' => array(
			'path'                => '/block-renderer',
			'callback'            => 'block_renderer',
			'permission_callback' => 'block_renderer_permission',
			'methods'             => 'POST',
			'args'                => 'block_renderer_args_schema',
		)
	);

	/**
	 * Version of the API
	 *
	 * @see set_version()
	 * @see get_version()
	 * @var string
	 */
	protected $version = '1';

	/**
	 * Vendor slug for the API
	 *
	 * @see set_vendor()
	 * @see get_vendor()
	 * @var string
	 */
	protected $vendor = 'carbon-fields';

	/**
	 * ContainerRepository instance
	 *
	 * @var ContainerRepository
	 */
	protected $container_repository;

	/**
	 * @param ContainerRepository $container_repository
	 */
	public function __construct( ContainerRepository $container_repository ) {
		$this->container_repository = $container_repository;
	}

	/**
	 * Boot up functionality
	 */
	public function boot() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 15 );
	}

	/**
	 * Set routes
	 *
	 * @param array $routes
	 */
	public function set_routes( $routes ) {
		$this->routes = $routes;
	}

	/**
	 * Return routes
	 *
	 * @return array
	 */
	public function get_routes() {
		return $this->routes;
	}

	/**
	 * Set version
	 *
	 * @param string $version
	 */
	public function set_version( $version ) {
		$this->version = $version;
	}

	/**
	 * Return version
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Set vendor
	 *
	 * @param string $vendor
	 */
	public function set_vendor( $vendor ) {
		$this->vendor = $vendor;
	}

	/**
	 * Return vendor
	 *
	 * @return string
	 */
	public function get_vendor() {
		return $this->vendor;
	}

	/**
	 * Check access to the editor's media metadata endpoint.
	 *
	 * @return bool
	 */
	public function attachment_data_permission() {
		return is_user_logged_in();
	}

	public function post_meta_permission( $request ) {
		$id = (int) $request['id'];
		return $id > 0 && $this->can_read_post( get_post( $id ) );
	}

	public function term_meta_permission( $request ) {
		$term = get_term( (int) $request['id'] );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		$taxonomy = get_taxonomy( $term->taxonomy );
		return $taxonomy && ( $taxonomy->publicly_queryable || current_user_can( $taxonomy->cap->manage_terms ) );
	}

	public function user_meta_permission( $request ) {
		$id = (int) $request['id'];
		return $id > 0 && ( get_current_user_id() === $id || current_user_can( 'edit_user', $id ) );
	}

	public function comment_meta_permission( $request ) {
		$id = (int) $request['id'];
		$comment = $id > 0 ? get_comment( $id ) : null;
		return $comment && $comment->comment_post_ID && $this->can_read_post( get_post( $comment->comment_post_ID ) ) &&
			( '1' === (string) $comment->comment_approved || current_user_can( 'edit_comment', $comment->comment_ID ) );
	}

	public function association_permission() {
		return is_user_logged_in();
	}

	private function can_read_post( $post ) {
		if ( ! $post || ( $post->post_password !== '' && ! current_user_can( 'edit_post', $post->ID ) ) ) {
			return false;
		}

		$type = get_post_type_object( $post->post_type );
		$status = get_post_status_object( get_post_status( $post ) );
		return ( $type && is_post_type_viewable( $type ) && $status && $status->public ) ||
			current_user_can( 'read_post', $post->ID );
	}


	/**
	 * Register custom routes
	 *
	 * @see  register_route()
	 */
	public function register_routes() {
		foreach ( $this->routes as $route ) {
			$this->register_route( $route );
		}
	}

	/**
	 * Register a custom REST route
	 *
	 * @param  array $route
	 */
	protected function register_route( $route ) {
		register_rest_route( $this->get_vendor() . '/v' . $this->get_version(), $route['path'], array(
			'methods'             => $route['methods'],
			'permission_callback' => array( $this, $route['permission_callback'] ),
			'callback'            => array( $this, $route['callback'] ),
			'args'                => isset( $route['args'] ) ? call_user_func( array( $this, $route['args'] ) ) : array(),
		) );
	}

	/**
	 * Proxy method for handling get/set for theme options
	 *
	 * @param  \WP_REST_Request $request
	 * @return array|\WP_REST_Response
	 */
	public function options_accessor( $request ) {
		$language = \Carbon_Fields\Integration\Polylang::active() ? $request->get_param( 'lang' ) : null;
		if ( null !== $language && ! \Carbon_Fields\Integration\Polylang::valid_language( $language ) ) {
			return new \WP_Error( 'carbon_fields_invalid_language', __( 'Unknown settings language.', 'carbon-fields' ), array( 'status' => 400 ) );
		}
		return \Carbon_Fields\Integration\Polylang::with_language( $language, function() use ( $request ) {
			return 'POST' === $request->get_method() ? $this->set_options( $request ) : $this->get_options();
		} );
	}

	/**
	 * Proxy method for handling theme options permissions
	 *
	 * @param  \WP_REST_Request $request
	 * @return bool
	 */
	public function options_permission( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Wrapper method used for retrieving data from Data_Manager
	 *
	 * @param  string $container_type
	 * @param  string $object_id
	 * @return array
	 */
	protected function get_all_field_values( $container_type, $object_id = null ) {
		$object_id = ( $object_id !== '' ) ? $object_id : null;

		$containers = $this->container_repository->get_containers( $container_type );
		$fields = array();
		foreach ( $containers as $container ) {
			$fields = array_merge( $fields, $container->get_fields() );
		}

		$values = array();
		foreach ( $fields as $field ) {
			if ( ! $field->get_visible_in_rest_api() ) {
				continue;
			}

			$value = Helper::get_value( $object_id, $container_type, '', $field->get_base_name() );

			if ( apply_filters( 'carbon_fields_rest_api_return_attachments_as_urls', false, $value, $field, $object_id ) ) {
				$attachments_class = [
					"Carbon_Fields\Field\Media_Gallery_Field",
					"Carbon_Fields\Field\File_Field",
					"Carbon_Fields\Field\Image_Field"
				];

				if ( in_array( get_class( $field ), $attachments_class ) ) {
					$value = Helper::get_attachments_urls($value);
				}
			}

			$values[ $field->get_base_name() ] = $value;
		}

		return $values;
	}

	/**
	 * Get Carbon Fields post meta values
	 *
	 * @param  array $data
	 * @return array
	 */
	public function get_post_meta( $data ) {
		$carbon_data = $this->get_all_field_values( 'post_meta', $data['id'] );
		return array( 'carbon_fields' => $carbon_data );
	}

	/**
	 * Get Carbon Fields user meta values
	 *
	 * @param  array $data
	 * @return array
	 */
	public function get_user_meta( $data ) {
		$carbon_data = $this->get_all_field_values( 'user_meta', $data['id'] );
		return array( 'carbon_fields' => $carbon_data );
	}

	/**
	 * Get Carbon Fields term meta values
	 *
	 * @param  array $data
	 * @return array
	 */
	public function get_term_meta( $data ) {
		$carbon_data = $this->get_all_field_values( 'term_meta', $data['id'] );
		return array( 'carbon_fields' => $carbon_data );
	}

	/**
	 * Get Carbon Fields comment meta values
	 *
	 * @param  array $data
	 * @return array
	 */
	public function get_comment_meta( $data ) {
		$carbon_data = $this->get_all_field_values( 'comment_meta', $data['id'] );
		return array( 'carbon_fields' => $carbon_data );
	}

	/**
	 * Get Carbon Fields association selected options.
	 *
	 * @access public
	 *
	 * @return array
	 */
	public function get_association_data( $request ) {
		$field = $this->get_association_field( $request );
		if ( is_wp_error( $field ) ) {
			return $field;
		}

		$options = $request->get_param( 'options' );
		if ( null === $options || '' === $options ) {
			return array();
		}
		if ( ! is_string( $options ) ) {
			return $this->invalid_association_request();
		}

		$return_value = array();
		foreach ( explode( ';', $options ) as $option ) {
			$parts = explode( ':', $option );
			if ( count( $parts ) !== 3 || ! ctype_digit( $parts[0] ) || (int) $parts[0] < 1 ||
				$parts[1] === '' || sanitize_key( $parts[1] ) !== $parts[1] ||
				sanitize_key( $parts[2] ) !== $parts[2] ) {
				return $this->invalid_association_request();
			}

			list( $id, $type, $subtype ) = $parts;
			$id = (int) $id;
			if ( ( 'post' === $type && ! get_post_type_object( $subtype ) ) ||
				( 'term' === $type && ! get_taxonomy( $subtype ) ) ) {
				return $this->invalid_association_request();
			}
			if ( 'post' === $type && ( $post = get_post( $id ) ) && ! $this->can_read_post( $post ) ) {
				continue;
			}

			$return_value[] = array(
				'type'       => $type,
				'subtype'    => $subtype,
				'thumbnail'  => $field->get_thumbnail_by_type( $id, $type, $subtype ),
				'id'         => $id,
				'title'      => $field->get_title_by_type( $id, $type, $subtype ),
				'label'      => $field->get_item_label( $id, $type, $subtype ),
				'is_trashed' => 'post' === $type && 'trash' === get_post_status( $id ),
			);
		}

		return $return_value;
	}

	/**
	 * Get Carbon Fields association options data.
	 */
	public function get_association_options( $request ) {
		$field = $this->get_association_field( $request );
		if ( is_wp_error( $field ) ) {
			return $field;
		}

		$page = $request->get_param( 'page' );
		$term = $request->get_param( 'term' );
		if ( ( null !== $term && ! is_string( $term ) ) ||
			( null !== $page && ( ( ! is_int( $page ) && ! is_string( $page ) ) ||
				! ctype_digit( (string) $page ) || (int) $page < 1 || (int) $page > 1000000 ) ) ) {
			return $this->invalid_association_request();
		}

		return $field->get_options( array(
			'page' => null === $page ? 1 : (int) $page,
			'term' => null === $term ? '' : sanitize_text_field( $term ),
		) );
	}

	private function get_association_field( $request ) {
		$container_id = $request->get_param( 'container_id' );
		$field_id = $request->get_param( 'field_id' );
		if ( ! is_string( $container_id ) || ! is_string( $field_id ) || '' === $container_id || '' === $field_id ||
			sanitize_text_field( $container_id ) !== $container_id || sanitize_text_field( $field_id ) !== $field_id ) {
			return $this->invalid_association_request();
		}

		$field = Helper::get_field( null, $container_id, $field_id );
		if ( ! ( $field instanceof \Carbon_Fields\Field\Association_Field ) ) {
			return new \WP_Error( 'carbon_fields_association_not_found', __( 'Association field not found.', 'carbon-fields' ), array( 'status' => 404 ) );
		}

		return $field;
	}

	private function invalid_association_request() {
		return new \WP_Error( 'carbon_fields_invalid_association', __( 'Invalid association request.', 'carbon-fields' ), array( 'status' => 400 ) );
	}


	/**
	 * Get attachment data by given ID or URL.
	 *
	 * @return array
	 */
	public function get_attachment_data( $request ) {
		$type = $request->get_param( 'type' );
		$value = $request->get_param( 'value' );
		if ( 'url' === $type ) {
			$value = Helper::sanitize_media_url( $value );
		} elseif ( is_string( $value ) && ctype_digit( $value ) ) {
			$value = (int) $value;
		} else {
			$value = false;
		}
		if ( ! $value ) {
			return new \WP_Error( 'carbon_fields_invalid_attachment', __( 'Invalid attachment reference.', 'carbon-fields' ), array( 'status' => 400 ) );
		}

		return Helper::get_attachment_metadata( $value, $type, function( $attachment ) {
			return $this->can_read_post( $attachment ) &&
				( ! $attachment->post_parent || $this->can_read_post( get_post( $attachment->post_parent ) ) );
		} );
	}

	/**
	 * Retrieve Carbon theme options
	 *
	 * @return array
	 */
	protected function get_options() {
		$carbon_data = $this->get_all_field_values( 'theme_options' );
		return array( 'carbon_fields' => $carbon_data );
	}

	/**
	 * Set Carbon theme options
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	protected function set_options( $request ) {
		$options = $request->get_params();
		if ( \Carbon_Fields\Integration\Polylang::active() ) {
			unset( $options['lang'] );
		}

		if ( empty( $options ) ) {
			return new \WP_REST_Response( __( 'No option names provided', 'carbon-fields' ) );
		}

		foreach ( $options as $key => $value ) {
			try {
				Helper::set_value( null, 'Theme_Options', '', $key, $value );
			} catch ( \Exception $e ) {
				return new \WP_REST_Response( wp_strip_all_tags( $e->getMessage() ) );
			}
		}

		return new \WP_REST_Response( __( 'Theme Options updated.', 'carbon-fields' ), 200 );
	}

	/**
	 * Checks if a given request has access to read blocks.
	 *
	 * @see https://github.com/WordPress/WordPress/blob/master/wp-includes/rest-api/endpoints/class-wp-rest-block-renderer-controller.php#L78-L116
	 *
	 * @param  \WP_REST_Request
	 * @return true|\WP_Error
	 */
	public function block_renderer_permission( $request ) {
		global $post;

		$post_id = isset( $request['post_id'] ) ? intval( $request['post_id'] ) : 0;

		if ( 0 < $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				return new \WP_Error(
					'block_cannot_read',
					__( 'Sorry, you are not allowed to read blocks of this post.', 'carbon-fields' ),
					array(
						'status' => rest_authorization_required_code(),
					)
				);
			}
		} else {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new \WP_Error(
					'block_cannot_read',
					__( 'Sorry, you are not allowed to read blocks as this user.', 'carbon-fields' ),
					array(
						'status' => rest_authorization_required_code(),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Returns the schema of the accepted arguments.
	 *
	 * @return array
	 */
	public function attachment_data_args_schema() {
		return array(
			'type'       => array(
				'type'        => 'string',
				'required'    => true,
				'enum'        => array( 'id', 'url' ),
				'description' => __( 'The requested type: ID or URL.', 'carbon-fields' ),
			),
			'value'    => array(
				'type'        => 'string',
				'required'    => true,
				'description' => __( 'The ID / URL of the attachment', 'carbon-fields' ),
			),
		);
	}

	/**
	 * Returns the schema of the accepted arguments.
	 *
	 * @see https://github.com/WordPress/WordPress/blob/master/wp-includes/rest-api/endpoints/class-wp-rest-block-renderer-controller.php#L56-L71
	 *
	 * @return array
	 */
	public function block_renderer_args_schema() {
		return array(
			'name'       => array(
				'type'        => 'string',
				'required'    => true,
				'description' => __( 'The name of the block.', 'carbon-fields' ),
			),
			'content'    => array(
				'type'        => 'string',
				'required'    => true,
				'description' => __( 'The content of the block.', 'carbon-fields' ),
			),
			'post_id'    => array(
				'type'        => 'integer',
				'description' => __( 'ID of the post context.', 'carbon-fields' ),
			),
		);
	}

	/**
	 * Returns block output from block's registered render_callback.
	 *
	 * @see https://github.com/WordPress/WordPress/blob/master/wp-includes/rest-api/endpoints/class-wp-rest-block-renderer-controller.php#L118-L154
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function block_renderer( $request ) {
		global $post;

		$post_id = isset( $request['post_id'] ) ? intval( $request['post_id'] ) : 0;

		if ( 0 < $post_id ) {
			$post = get_post( $post_id );

			// Set up postdata since this will be needed if post_id was set.
			setup_postdata( $post );
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		$block    = $registry->get_registered( $request['name'] );

		if ( null === $block ) {
			return new \WP_Error(
				'block_invalid',
				__( 'Invalid block.' ),
				array(
					'status' => 404,
				)
			);
		}

		$data = array(
			'rendered' => do_blocks( $request['content'] ),
		);

		return rest_ensure_response( $data );
	}
}
