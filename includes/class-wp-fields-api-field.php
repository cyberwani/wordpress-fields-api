<?php
/**
 * Fields API Field Class
 *
 * Handles saving and sanitizing of fields.
 *
 * @package    WordPress
 * @subpackage Fields_API
 */
class WP_Fields_API_Field {

	/**
	 * @access public
	 * @var string
	 */
	public $id = '';

	/**
	 * @access public
	 * @var string
	 */
	public $object = '';

	/**
	 * Capability required to edit this field.
	 *
	 * @var string
	 */
	public $capability = '';

	/**
	 * Theme feature support for the field.
	 *
	 * @access public
	 * @var string|array
	 */
	public $theme_supports = '';

	/**
	 * Default value for field
	 *
	 * @var string
	 */
	public $default = '';

	/**
	 * Transport used for field
	 *
	 * @access public
	 * @var string
	 */
	public $transport = 'refresh';

	/**
	 * Whether or not the field is initially dirty when created.
	 *
	 * This is used to ensure that a field will be sent from the screen to the
	 * preview when loading the Fields API. Normally a field only is synced to
	 * the preview if it has been changed. This allows the field to be sent
	 * from the start.
	 *
	 * @access public
	 * @var bool
	 */
	public $dirty = false;

	/**
	 * Server-side sanitization callback for the field's value.
	 *
	 * @var callback
	 */
	public $sanitize_callback    = '';
	public $sanitize_js_callback = '';

	protected $id_data = array();

	/**
	 * Cached and sanitized $_POST value for the field.
	 *
	 * @access private
	 * @var mixed
	 */
	private $_post_value;

	/**
	 * Value used for preview
	 *
	 * @access protected
	 * @var mixed
	 */
	protected $_original_value;

	/**
	 * The ID for the current blog when the preview() method was called.
	 *
	 * @since 4.2.0
	 * @access protected
	 * @var int
	 */
	protected $_previewed_blog_id;

	/**
	 * Constructor.
	 *
	 * Parameters are not set to maintain PHP overloading compatibility (strict standards)
	 *
	 * @return WP_Fields_API_Field $field
	 */
	public function __construct() {

		call_user_func_array( array( $this, 'init' ), func_get_args() );

	}

	/**
	 * Secondary constructor; Any supplied $args override class property defaults.
	 *
	 * @param string $object
	 * @param string $id                    A specific ID of the field. Can be a
	 *                                      theme mod or option name.
	 * @param array  $args                  Field arguments.
	 *
	 * @return WP_Fields_API_Field $field
	 */
	public function init( $object, $id, $args = array() ) {

		$this->object = $object;

		$keys = array_keys( get_object_vars( $this ) );

		foreach ( $keys as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$this->$key = $args[ $key ];
			}
		}

		$this->id = $id;

		// Parse the ID for array keys.
		$this->id_data['keys'] = preg_split( '/\[/', str_replace( ']', '', $this->id ) );
		$this->id_data['base'] = array_shift( $this->id_data['keys'] );

		// Rebuild the ID.
		$this->id = $this->id_data['base'];

		if ( ! empty( $this->id_data['keys'] ) ) {
			$this->id .= '[' . implode( '][', $this->id_data['keys'] ) . ']';
		}

		if ( $this->sanitize_callback ) {
			add_filter( "fields_sanitize_{$this->object}_{$this->id}", $this->sanitize_callback, 10, 2 );
		}

		if ( $this->sanitize_js_callback ) {
			add_filter( "fields_sanitize_js_{$this->object}_{$this->id}", $this->sanitize_js_callback, 10, 2 );
		}

	}

	/**
	 * Return true if the current blog is not the same as the previewed blog.
	 *
	 * @since 4.2.0
	 * @access public
	 *
	 * @return bool|null Returns null if preview() has not been called yet.
	 */
	public function is_current_blog_previewed() {

		if ( ! isset( $this->_previewed_blog_id ) ) {
			return null;
		}

		return ( get_current_blog_id() === $this->_previewed_blog_id );

	}

	/**
	 * Handle previewing the field.
	 */
	public function preview() {

		if ( ! isset( $this->_original_value ) ) {
			$this->_original_value = $this->value();
		}

		if ( ! isset( $this->_previewed_blog_id ) ) {
			$this->_previewed_blog_id = get_current_blog_id();
		}

		switch ( $this->object ) {
			case 'customizer' :
			case 'theme_mod' :
				add_filter( 'theme_mod_' . $this->id_data['base'], array( $this, '_preview_filter' ) );
				break;

			case 'option' :
			case 'settings' :
				if ( empty( $this->id_data['keys'] ) ) {
					add_filter( 'pre_option_' . $this->id_data['base'], array( $this, '_preview_filter' ) );
				} else {
					add_filter( 'option_' . $this->id_data['base'], array( $this, '_preview_filter' ) );
					add_filter( 'default_option_' . $this->id_data['base'], array( $this, '_preview_filter' ) );
				}
				break;

			case 'post_type' :
			case 'post' :
				add_filter( 'get_post_metadata', array( $this, '_preview_filter' ) );
				break;

			case 'user' :
				add_filter( 'get_user_metadata', array( $this, '_preview_filter' ) );
				break;

			default :

				/**
				 * Fires when the {@see WP_Fields_API_Field::preview()} method is called for fields
				 * not handled as theme_mods or options.
				 *
				 * The dynamic portion of the hook name, `$this->id`, refers to the field ID.
				 *
				 *
				 * @param WP_Fields_API_Field $this {@see WP_Fields_API_Field} instance.
				 */
				do_action( "fields_preview_{$this->object}_{$this->id}", $this );

				/**
				 * Fires when the {@see WP_Fields_API_Field::preview()} method is called for fields
				 * not handled as theme_mods or options.
				 *
				 * The dynamic portion of the hook name, `$this->object`, refers to the field type.
				 *
				 *
				 * @param WP_Fields_API_Field $this {@see WP_Fields_API_Field} instance.
				 */
				do_action( "fields_preview_{$this->object}", $this );
		}

	}

	/**
	 * Callback function to filter the theme mods and options.
	 *
	 * If switch_to_blog() was called after the preview() method, and the current
	 * blog is now not the same blog, then this method does a no-op and returns
	 * the original value.
	 *
	 * @uses  WP_Fields_API_Field::multidimensional_replace()
	 *
	 * @param mixed $original Old value.
	 *
	 * @return mixed New or old value.
	 */
	public function _preview_filter( $original ) {

		/**
		 * @var $wp_fields WP_Fields_API
		 */
		global $wp_fields;

		if ( ! $this->is_current_blog_previewed() ) {
			return $original;
		}

		$undefined  = new stdClass(); // symbol hack
		$post_value = $wp_fields->post_value( $this, $undefined );

		if ( $undefined === $post_value ) {
			$value = $this->_original_value;
		} else {
			$value = $post_value;
		}

		return $this->multidimensional_replace( $original, $this->id_data['keys'], $value );

	}

	/**
	 * Check user capabilities and theme supports, and then save
	 * the value of the field.
	 *
	 * @return false|null False if cap check fails or value isn't set.
	 */
	final public function save() {

		$value = $this->post_value();

		if ( ! $this->check_capabilities() || ! isset( $value ) ) {
			return false;
		}

		/**
		 * Fires when the WP_Fields_API_Field::save() method is called.
		 *
		 * The dynamic portion of the hook name, `$this->id_data['base']` refers to
		 * the base slug of the field name.
		 *
		 *
		 * @param WP_Fields_API_Field $this {@see WP_Fields_API_Field} instance.
		 */
		do_action( 'fields_save_' . $this->object . '_' . $this->id_data['base'], $this );

		$this->update( $value );

	}

	/**
	 * Fetch and sanitize the $_POST value for the field.
	 *
	 * @param mixed $default A default value which is used as a fallback. Default is null.
	 *
	 * @return mixed The default value on failure, otherwise the sanitized value.
	 */
	public function post_value( $default = null ) {

		global $wp_fields;

		// Check for a cached value
		if ( isset( $this->_post_value ) ) {
			return $this->_post_value;
		}

		// Call the manager for the post value
		$result = $wp_fields->post_value( $this );

		if ( isset( $result ) ) {
			return $this->_post_value = $result;
		}

		return $default;

	}

	/**
	 * Sanitize an input.
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return mixed Null if an input isn't valid, otherwise the sanitized value.
	 */
	public function sanitize( $value ) {

		$value = wp_unslash( $value );

		/**
		 * Filter a Customize field value in un-slashed form.
		 *
		 * @param mixed                $value Value of the field.
		 * @param WP_Fields_API_Field $this  WP_Fields_API_Field instance.
		 */
		return apply_filters( "fields_sanitize_{$this->object}_{$this->id}", $value, $this );

	}

	/**
	 * Save the value of the field, using the related API.
	 *
	 * @param mixed $value The value to update.
	 *
	 * @return mixed The result of saving the value.
	 */
	protected function update( $value ) {

		switch ( $this->object ) {
			case 'customizer' :
			case 'theme_mod' :
				return $this->_update_theme_mod( $value );

			case 'option' :
			case 'settings' :
				return $this->_update_option( $value );

			case 'post_type' :
			case 'post' :
				return $this->_update_post_meta( $value );

			case 'user' :
				return $this->_update_user_meta( $value );

			default :

				/**
				 * Fires when the {@see WP_Fields_API_Field::update()} method is called for fields
				 * not handled as theme_mods or options.
				 *
				 * The dynamic portion of the hook name, `$this->object`, refers to the type of field.
				 *
				 *
				 * @param mixed                $value Value of the field.
				 * @param WP_Fields_API_Field $this  WP_Fields_API_Field instance.
				 */
				return do_action( 'fields_update_' . $this->object, $value, $this );
		}
	}

	/**
	 * Update the theme mod from the value of the parameter.
	 *
	 * @param mixed $value The value to update.
	 *
	 * @return null
	 */
	protected function _update_theme_mod( $value ) {

		// Handle non-array theme mod.
		if ( empty( $this->id_data['keys'] ) ) {
			set_theme_mod( $this->id_data['base'], $value );
		} else {
			// Handle array-based theme mod.
			$mods = get_theme_mod( $this->id_data['base'] );
			$mods = $this->multidimensional_replace( $mods, $this->id_data['keys'], $value );

			if ( isset( $mods ) ) {
				set_theme_mod( $this->id_data['base'], $mods );
			}
		}

		return null;

	}

	/**
	 * Update the option from the value of the field.
	 *
	 * @param mixed $value The value to update.
	 *
	 * @return bool|null The result of saving the value.
	 */
	protected function _update_option( $value ) {

		// Handle non-array option.
		if ( empty( $this->id_data['keys'] ) ) {
			return update_option( $this->id_data['base'], $value );
		}

		// Handle array-based options.
		$options = get_option( $this->id_data['base'] );
		$options = $this->multidimensional_replace( $options, $this->id_data['keys'], $value );

		if ( isset( $options ) ) {
			return update_option( $this->id_data['base'], $options );
		}

		return null;

	}

	/**
	 * Update the option from the value of the field.
	 *
	 * @param mixed $value The value to update.
	 *
	 * @return bool|null The result of saving the value.
	 */
	protected function _update_post_meta( $value ) {

		// Handle non-array option.
		if ( empty( $this->id_data['keys'] ) ) {
			return update_post_meta( 0, $this->id_data['base'], $value );
		}

		// Handle array-based keys.
		$keys = get_post_meta( 0, $this->id_data['base'] );
		$keys = $this->multidimensional_replace( $keys, $this->id_data['keys'], $value );

		if ( isset( $keys ) ) {
			return update_post_meta( 0, $this->id_data['base'], $keys );
		}

		return null;

	}

	/**
	 * Update the option from the value of the field.
	 *
	 * @param mixed $value The value to update.
	 *
	 * @return bool|null The result of saving the value.
	 */
	protected function _update_user_meta( $value ) {

		// Handle non-array option.
		if ( empty( $this->id_data['keys'] ) ) {
			return update_user_meta( 0, $this->id_data['base'], $value );
		}

		// Handle array-based options.
		$keys = get_user_meta( 0, $this->id_data['base'] );
		$keys = $this->multidimensional_replace( $keys, $this->id_data['keys'], $value );

		if ( isset( $keys ) ) {
			return update_user_meta( 0, $this->id_data['base'], $keys );
		}

		return null;

	}

	/**
	 * Fetch the value of the field.
	 *
	 * @return mixed The value.
	 */
	public function value() {

		// Get the callback that corresponds to the field type.
		switch ( $this->object ) {
			case 'customizer' :
			case 'theme_mod' :
				$function = 'get_theme_mod';
				break;

			case 'option' :
			case 'settings' :
				$function = 'get_option';
				break;

			case 'post_type' :
			case 'post' :
				$function = 'get_post_meta';
				break;

			case 'user' :
				$function = 'get_user_meta';
				break;

			default :

				/**
				 * Filter a Customize field value not handled as a theme_mod or option.
				 *
				 * The dynamic portion of the hook name, `$this->id_date['base']`, refers to
				 * the base slug of the field name.
				 *
				 * For fields handled as theme_mods or options, see those corresponding
				 * functions for available hooks.
				 *
				 *
				 * @param mixed $default The field default value. Default empty.
				 */
				return apply_filters( 'fields_value_' . $this->object . '_' . $this->id_data['base'], $this->default );
		}

		// Handle non-array value
		if ( empty( $this->id_data['keys'] ) ) {
			return $function( $this->id_data['base'], $this->default );
		}

		// Handle array-based value
		$values = $function( $this->id_data['base'] );

		return $this->multidimensional_get( $values, $this->id_data['keys'], $this->default );
	}

	/**
	 * Sanitize the field's value for use in JavaScript.
	 *
	 * @return mixed The requested escaped value.
	 */
	public function js_value() {

		$value = $this->value();

		/**
		 * Filter a Customize field value for use in JavaScript.
		 *
		 * The dynamic portion of the hook name, `$this->id`, refers to the field ID.
		 *
		 *
		 * @param mixed                $value The field value.
		 * @param WP_Fields_API_Field $this  {@see WP_Fields_API_Field} instance.
		 */
		$value = apply_filters( "fields_sanitize_js_{$this->object}_{$this->id}", $value, $this );

		if ( is_string( $value ) ) {
			return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}

		return $value;

	}

	/**
	 * Validate user capabilities whether the theme supports the field.
	 *
	 * @return bool False if theme doesn't support the section or user can't change section, otherwise true.
	 */
	public function check_capabilities() {

		if ( $this->capability && ! call_user_func_array( 'current_user_can', (array) $this->capability ) ) {
			return false;
		}

		if ( $this->theme_supports && ! call_user_func_array( 'current_theme_supports', (array) $this->theme_supports ) ) {
			return false;
		}

		return true;

	}

	/**
	 * Multidimensional helper function.
	 *
	 * @param      $root
	 * @param      $keys
	 * @param bool $create Default is false.
	 *
	 * @return null|array Keys are 'root', 'node', and 'key'.
	 */
	final protected function multidimensional( &$root, $keys, $create = false ) {

		if ( $create && empty( $root ) ) {
			$root = array();
		}

		if ( ! isset( $root ) || empty( $keys ) ) {
			return null;
		}

		$last = array_pop( $keys );
		$node = &$root;

		foreach ( $keys as $key ) {
			if ( $create && ! isset( $node[ $key ] ) ) {
				$node[ $key ] = array();
			}

			if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
				return null;
			}

			$node = &$node[ $key ];
		}

		if ( $create ) {
			if ( ! is_array( $node ) ) {
				// account for an array overriding a string or object value
				$node = array();
			}
			if ( ! isset( $node[ $last ] ) ) {
				$node[ $last ] = array();
			}
		}

		if ( ! isset( $node[ $last ] ) ) {
			return null;
		}

		return array(
			'root' => &$root,
			'node' => &$node,
			'key'  => $last,
		);

	}

	/**
	 * Will attempt to replace a specific value in a multidimensional array.
	 *
	 * @param       $root
	 * @param       $keys
	 * @param mixed $value The value to update.
	 *
	 * @return
	 */
	final protected function multidimensional_replace( $root, $keys, $value ) {

		if ( ! isset( $value ) ) {
			return $root;
		} elseif ( empty( $keys ) ) {
			// If there are no keys, we're replacing the root.
			return $value;
		}

		$result = $this->multidimensional( $root, $keys, true );

		if ( isset( $result ) ) {
			$result['node'][ $result['key'] ] = $value;
		}

		return $root;

	}

	/**
	 * Will attempt to fetch a specific value from a multidimensional array.
	 *
	 * @param       $root
	 * @param       $keys
	 * @param mixed $default A default value which is used as a fallback. Default is null.
	 *
	 * @return mixed The requested value or the default value.
	 */
	final protected function multidimensional_get( $root, $keys, $default = null ) {

		// If there are no keys, test the root.
		if ( empty( $keys ) ) {
			if ( isset( $root ) ) {
				return $root;
			}
		} else {
			$result = $this->multidimensional( $root, $keys );

			if ( isset( $result ) ) {
				return $result['node'][ $result['key'] ];
			}
		}

		return $default;

	}

	/**
	 * Will attempt to check if a specific value in a multidimensional array is set.
	 *
	 * @param $root
	 * @param $keys
	 *
	 * @return bool True if value is set, false if not.
	 */
	final protected function multidimensional_isset( $root, $keys ) {

		$result = $this->multidimensional_get( $root, $keys );

		return isset( $result );

	}
}

/**
 * A field that is used to filter a value, but will not save the results.
 *
 * Results should be properly handled using another field or callback.
 *
 * @package    WordPress
 * @subpackage Fields_API
 */
class WP_Fields_API_Filter_Field extends WP_Fields_API_Field {

	/**
	 * Update value
	 */
	public function update( $value ) {

		// Nothing to see here

	}
}