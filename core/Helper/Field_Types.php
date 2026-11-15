<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Complex_Row_Visibility_Field;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Field\Media_Gallery_Field;
use Carbon_Fields\Field\Structured_Field;
use Carbon_Fields\Field\Text_List_Field;

final class Field_Types {
	public static function generate( $container_id, $class, array $names = array() ) {
		Carbon_Fields::verify_fields_registered();
		$container = Carbon_Fields::resolve( 'container_repository' )->get_container( $container_id );
		if ( ! $container || 'post_meta' !== $container->type ) {
			return self::error( 'Select a registered post meta container by ID.' );
		}
		$declaration = self::class_declaration( $class );
		if ( is_wp_error( $declaration ) ) {
			return $declaration;
		}

		$data = array();
		foreach ( $container->get_fields() as $field ) {
			$data[ $field->get_base_name() ] = self::type( $field );
		}
		if ( $names ) {
			foreach ( $names as $name ) {
				if ( ! is_string( $name ) || ! isset( $data[ $name ] ) ) {
					return self::error( 'The selection contains an unknown root field.' );
				}
			}
			$data = array_intersect_key( $data, array_fill_keys( $names, true ) );
		}
		if ( ! $data ) {
			return self::error( 'The container has no selected fields.' );
		}
		list( $namespace, $name ) = $declaration;
		$fields = implode( ', ', array_map( static function( $key ) {
			return var_export( $key, true );
		}, array_keys( $data ) ) );
		$shape = self::shape( $data );
		$container_literal = var_export( $container_id, true );
		$template = <<<'PHP'
<?php
%s
/**
 * Generated from a Carbon Fields container.
 *
 * @phpstan-type Fields %s
 */
final class %s {
	private const NAMES = array( %s );
	private const CONTAINER_ID = %s;

	/** @return %s|array{} */
	public static function get( int $post_id, bool $preload_media = false ): array {
		/** @var Fields|array{} $values */
		$values = \carbon_get_post_meta_fields( $post_id, self::NAMES, array(
			'container_id' => self::CONTAINER_ID,
			'preload_media' => $preload_media,
		) );
		return $values;
	}

	/**
	 * @param int[] $post_ids
	 * @return array<int, %s>
	 */
	public static function get_many( array $post_ids, bool $preload_media = false ): array {
		/** @var array<int, Fields> $values */
		$values = \carbon_get_post_meta_batch( $post_ids, self::NAMES, array(
			'container_id' => self::CONTAINER_ID,
			'preload_media' => $preload_media,
		) );
		return $values;
	}
}

PHP;
		return sprintf( $template, $namespace, $shape, $name, $fields, $container_literal, $shape, $shape );
	}

	private static function class_declaration( $class ) {
		if ( ! is_string( $class ) || '' === $class ) {
			return self::error( 'Pass a valid PHP class name, optionally with its namespace.' );
		}
		$segments = explode( chr( 92 ), ltrim( $class, chr( 92 ) ) );
		foreach ( $segments as $part ) {
			if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/iD', $part ) ) {
				return self::error( 'Pass a valid PHP class name, optionally with its namespace.' );
			}
		}
		$name = array_pop( $segments );
		$namespace = $segments ? "\nnamespace " . implode( chr( 92 ), $segments ) . ";\n" : '';
		try {
			token_get_all( "<?php {$namespace} final class {$name} {}", TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return self::error( 'The PHP class name contains a reserved word.' );
		}
		if ( in_array( strtolower( $name ), array( 'self', 'parent', 'static', 'int', 'float', 'bool', 'string',
			'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'resource', 'numeric' ), true ) ) {
			return self::error( 'The PHP class name contains a reserved word.' );
		}
		return array( $namespace, $name );
	}

	private static function type( Field $field ) {
		if ( 0 !== strpos( get_class( $field ), 'Carbon_Fields\\Field\\' ) ) {
			return 'mixed';
		}
		if ( $field instanceof Complex_Field ) {
			$rows = array();
			foreach ( $field->get_group_names() as $group ) {
				$definition = $field->get_group_by_name( $group );
				$row = array( Complex_Field::TYPE_PROPERTY => var_export( $definition->get_name(), true ) );
				foreach ( $definition->get_fields() as $child ) {
					$row[ $child->get_base_name() ] = self::type( $child );
				}
				$rows[] = self::shape( $row );
			}
			return $rows ? 'array<int, ' . implode( '|', $rows ) . '>' : 'array{}';
		}
		if ( $field instanceof Media_Gallery_Field ) {
			return $field->has_item_details() ? 'list<array{id: int, caption: string, link: string}>' : 'array<int, int|string>';
		}
		if ( $field instanceof Text_List_Field ) {
			return $field->has_icons() ? 'list<array{text: string, icon_id: int}>' : 'list<string>';
		}
		$types = array(
			'checkbox' => 'bool', 'select' => 'scalar', 'radio' => 'scalar', 'button_group' => 'scalar',
			'text' => 'scalar|null', 'textarea' => 'scalar|null', 'hidden' => 'scalar|null',
			'file' => 'int|string|null', 'image' => 'int|string|null', 'taxonomy' => 'array<int, int>',
			'link' => "array{type: 'post'|'url', post_id: int, url: string, label: string, new_tab: bool}",
		);
		if ( $field instanceof Complex_Row_Visibility_Field ) {
			return 'bool';
		}
		return $types[ $field->get_type() ] ?? ( $field instanceof Structured_Field ? 'array<array-key, mixed>' : 'mixed' );
	}

	private static function shape( array $data ) {
		$result = array();
		foreach ( $data as $name => $type ) {
			$result[] = var_export( $name, true ) . ': ' . $type;
		}
		return 'array{' . implode( ', ', $result ) . '}';
	}

	public static function register_cli() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'carbon-fields generate-types', array( __CLASS__, 'cli' ), array(
				'shortdesc' => 'Generate a typed PHP reader for a Carbon Fields post meta container.',
				'synopsis' => '<container-id> <class-name> [--fields=<names>]',
			) );
		}
	}

	public static function cli( array $args, array $options ) {
		$names = isset( $options['fields'] ) ? array_map( 'trim', explode( ',', $options['fields'] ) ) : array();
		$result = self::generate( $args[0], $args[1], $names );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( rtrim( $result, "\n" ) );
	}

	private static function error( $message ) {
		return new \WP_Error( 'carbon_fields_generate_types', $message );
	}
}
