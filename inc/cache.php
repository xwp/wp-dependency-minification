<?php
/**
 * @since 1.0
 */
class DepMin_Cache {

	/**
	 * @var DepMin_Cache_Interface
	 * @since 1.0
	 */
	private static $object;

	/**
	 * @return array
	 * @since 1.0
	 */
	public static function get_all() {
		return self::get_object()->get_all();
	}

	/**
	 * @return string
	 * @since 1.0
	 */
	public static function get_key( $key ) {
		return self::get_object()->get_key( $key );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public static function exists( $key ) {
		return self::get_object()->exists( $key );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public static function delete( $key ) {
		return self::get_object()->delete( $key );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public static function set( $key, $value ) {
		return self::get_object()->set( $key, $value );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public static function add( $key, $value ) {
		return self::get_object()->add( $key, $value );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public static function replace( $key, $value ) {
		return self::get_object()->replace( $key, $value );
	}

	/**
	 * @return mixed
	 * @since 1.0
	 */
	public static function get( $key, $default = false ) {
		return self::get_object()->get( $key, $default );
	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function set_object( $object ) {
		if ( $object instanceof DepMin_Cache_Interface ) {
			self::$object = $object;
		}
	}

	/**
	 * @return DepMin_Cache_Interface
	 * @since 1.0
	 */
	public static function get_object() {
		if ( is_null( self::$object ) ) {
			self::$object = new DepMin_Cache_Default();
		}

		return self::$object;
	}

}

/**
 * @since 1.0
 */
class DepMin_Cache_Default implements DepMin_Cache_Interface {

	/**
	 * @return array
	 * @since 1.0
	 */
	public function get_all() {

		global $wpdb;
		$list = array();

		foreach ( $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'depmin_cache_%'" ) as $key ) {
			if ( ( $value = get_option( $key ) ) ) {
				$list[ $key ] = $value;
			}
		}

		return $list;
	}

	/**
	 * @return string|bool
	 * @since 1.0
	 */
	public function get_key( $key ) {

		if ( empty( $key ) ) {
			return false;
		}

		if ( is_array( $key ) ) {
			$key = DepMin_hash_array( $key );

		} elseif ( is_object( $key ) ) {
			$key = spl_object_hash( $key );

		}

		return 'depmin_cache_' . trim( $key );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function set( $key, $value ) {

		$key = trim( $key );

		if ( empty( $key ) ) {
			return false;
		}

		if ( ! $this->exists( $key ) ) {
			return $this->add( $key, $value );

		} else {
			return $this->replace( $key, $value );

		}

	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function add( $key, $value ) {
		if ( empty( $key ) || $this->exists( $key ) ) {
			return false;
		}

		return add_option( $key, $value, '', 'no' );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function replace( $key, $value ) {
		if ( empty( $key ) || ! $this->exists( $key ) ) {
			return false;
		}

		return update_option( $key, $value );
	}

	/**
	 * @return mixed
	 * @since 1.0
	 */
	public function get( $key, $default = false ) {
		return get_option( $key, $default );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function exists( $key ) {
		return (bool) $this->get( $key );
	}

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function delete( $key ) {
		return delete_option( $key );
	}

}

/**
 * @since 1.0
 */
interface DepMin_Cache_Interface {

	/**
	 * @return array
	 * @since 1.0
	 */
	public function get_all();

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function exists( $key );

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function delete( $key );

	/**
	 * @return string
	 * @since 1.0
	 */
	public function get_key( $key );

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function set( $key, $value );

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function add( $key, $value );

	/**
	 * @return bool
	 * @since 1.0
	 */
	public function replace( $key, $value );

	/**
	 * @return mixed
	 * @since 1.0
	 */
	public function get( $key, $default = false );

}
