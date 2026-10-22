<?php
/** Current ACF integration. Field definitions retain their legacy names/settings. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Delegate native ACF behavior while retaining the wrapper's stored field identity. */
class acf_field_validated_field extends acf_field {
	public function initialize() {
		$this->name = 'validated_field';
		$this->label = __( 'Validated Field', 'acf_vf' );
		$this->category = 'basic';
		$this->description = __( 'A native ACF input with additional validation rules.', 'acf_vf' );
		$this->supports['escaping_html'] = true;
		$this->defaults = array(
			'sub_field' => array( 'type' => 'text' ), 'read_only' => false,
			'mask' => '', 'mask_autoclear' => true, 'mask_placeholder' => '_',
			'function' => 'none', 'pattern' => '', 'message' => __( 'Validation failed.', 'acf_vf' ),
			'unique' => 'non-unique', 'unique_statuses' => apply_filters( 'acf_vf/unique_statuses', array( 'publish', 'future' ) ), 'drafts' => true,
		);
		add_action( 'wp_ajax_acf_vf_sub_settings', array( $this, 'ajax_sub_settings' ) );
		add_action( 'admin_init', array( $this, 'register_options' ) );
		add_action( 'admin_menu', array( $this, 'settings_menu' ) );
		add_filter( 'acf/form-post/skip_save', array( $this, 'set_save_context' ), 10, 2 );
		add_filter( 'acf/pre_submit_form', array( $this, 'set_frontend_context' ) );
		$this->detect_frontend_context();
		add_action( 'init', array( $this, 'detect_frontend_context' ), 20 );
		add_action( 'acf/save_post', array( 'ACF_VF_Validation', 'begin_save' ), 1 );
		add_action( 'acf/save_post', array( 'ACF_VF_Validation', 'end_save' ), 999 );
		if ( file_exists( __DIR__ . '/class-acf-vf-rest.php' ) ) {
			require_once __DIR__ . '/class-acf-vf-rest.php';
			new ACF_VF_REST();
		}
	}
	public function set_save_context( $skip, $post_id ) {
		acf_set_form_data( 'vf_post_id', $post_id );
		acf_set_form_data( 'vf_screen', 'post' );
		return $skip;
	}
	public function set_frontend_context( $form ) {
		acf_set_form_data( 'vf_screen', 'acf_form' );
		return $form;
	}
	public function detect_frontend_context() {
		// Inspect without consuming the nonce: acf_form_head() verifies it and its signed form later.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		$nonce = $_POST['_acf_nonce'] ?? null;
		if ( is_string( $nonce ) && isset( $_POST['_acf_form'] ) && function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( wp_unslash( $nonce ), 'acf_form' ) ) {
			acf_set_form_data( 'vf_screen', 'acf_form' );
		}
	}

	/**
	 * Build the native definition while retaining the wrapper's key and meta name.
	 *
	 * Use this for ACF callbacks; prepare_field() adds HTML-only input names later.
	 *
	 * @param array $field Validated Field definition, with native settings in sub_field.
	 * @return array Native ACF field definition.
	 */
	public function native_field( $field ) {
		$sub = isset( $field['sub_field'] ) && is_array( $field['sub_field'] ) ? $field['sub_field'] : array();
		$type = ! empty( $sub['type'] ) ? $sub['type'] : 'text';
		if ( $type === $this->name || ! acf_get_field_type( $type ) ) { $type = 'text'; }
		$sub['type'] = $type;
		foreach ( array( 'ID', 'key', 'name', '_name', 'label', 'instructions', 'required', 'parent', 'prefix', 'id', 'value', 'wrapper', 'conditional_logic', '_prepare' ) as $key ) {
			if ( array_key_exists( $key, $field ) ) { $sub[$key] = $field[$key]; }
		}
		$native = acf_get_valid_field( $sub );
		// ACF validation derives _name from name, which is already an HTML name during rendering.
		foreach ( array( '_name', '_prepare' ) as $key ) {
			if ( array_key_exists( $key, $field ) ) { $native[$key] = $field[$key]; }
		}
		return $native;
	}

	public function load_field( $field ) {
		$field['pattern'] = str_replace( array( '%%squot%%', '%%dquot%%' ), array( "'", '"' ), $field['pattern'] ?? '' );
		$native = $this->native_field( $field );
		$native = apply_filters( 'acf/load_field/type=' . $native['type'], $native );
		if ( ! $native ) { return false; }
		// Native query endpoints enforce their own field-type nonce and visibility checks.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		$type = $native['type'];
		if ( wp_doing_ajax() && in_array( $action, array( 'acf/fields/' . $type . '/query', 'acf/fields/' . $type . '/add_term' ), true ) ) {
			return $native;
		}
		foreach ( array( 'ID', 'key', 'name', '_name', 'label', 'instructions', 'required', 'parent', 'prefix', 'id', 'value', 'wrapper', 'conditional_logic', '_prepare', '_valid' ) as $key ) { unset( $native[$key] ); }
		$field['sub_field'] = $native;
		return $field;
	}

	public function update_field( $field ) {
		if ( isset( $field['sub_field'] ) && is_array( $field['sub_field'] ) ) {
			$native = $this->native_field( $field );
			$field['sub_field'] = apply_filters( 'acf/update_field/type=' . $native['type'], $native );
			foreach ( array( 'ID', 'key', 'name', '_name', 'id', 'prefix', 'value', 'parent', '_prepare', '_valid' ) as $key ) { unset( $field['sub_field'][$key] ); }
		}
		return $field;
	}

	public function prepare_field( $field ) {
		global $pagenow;
		$is_new = $pagenow === 'post-new.php';
		if ( ! apply_filters( 'acf_vf/render_field', true, $field, $is_new ) ) { return false; }
		$native = $this->native_field( $field );
		$native['data'] = array_merge( $native['data'] ?? array(), array(
			'vf' => 1, 'vf-mask' => $field['mask'] ?? '',
			'vf-placeholder' => $field['mask_placeholder'] ?? '_',
			'vf-autoclear' => ACF_VF_Validation::is_true( $field['mask_autoclear'] ?? true ) ? 1 : 0,
			'vf-readonly' => ACF_VF_Validation::is_true( $field['read_only'] ?? false ) ? 1 : 0,
		) );
		if ( $native['data']['vf-readonly'] ) {
			$native['readonly'] = 1;
			$native['instructions'] = trim( ( $field['instructions'] ?? '' ) . ' ' . __( 'Read only', 'acf_vf' ) );
		}
		return apply_filters( 'acf/prepare_field/type=' . $native['type'], $native );
	}

	public function render_field( $field ) {
		$native = $this->native_field( $field );
		do_action( 'acf/render_field/type=' . $native['type'], $native );
	}
	public function load_value( $value, $post_id, $field ) {
		$native = $this->native_field( $field );
		if ( null === $value && isset( $native['default_value'] ) ) { $value = $native['default_value']; }
		return apply_filters( 'acf/load_value/type=' . $native['type'], $value, $post_id, $native );
	}
	public function update_value( $value, $post_id, $field ) {
		$native = $this->native_field( $field );
		if ( doing_action( 'acf/save_post' ) ) {
			// Native paginated repeaters also write untouched rows from their stored snapshot.
			$entry = ACF_VF_Validation::resolve_save_entry( $field, $value, $post_id );
			if ( $entry ) {
				$valid = apply_filters( 'acf/validate_value/type=' . $native['type'], true, $value, $native, $entry['input'] );
				$valid = ACF_VF_Validation::validate( $valid, $value, $this->validation_field( $field ), $entry['input'], $post_id );
				if ( true !== $valid ) { return $entry['previous_value']; }
			}
		}
		return apply_filters( 'acf/update_value/type=' . $native['type'], $value, $post_id, $native );
	}
	public function delete_value( $post_id, $key, $field ) {
		$native = $this->native_field( $field );
		do_action( 'acf/delete_value/type=' . $native['type'], $post_id, $key, $native );
	}
	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		$native = $this->native_field( $field );
		$value = apply_filters( 'acf/format_value/type=' . $native['type'], $value, $post_id, $native, $escape_html );
		if ( $escape_html && ! acf_field_type_supports( $native['type'], 'escaping_html' ) ) {
			$value = is_array( $value ) ? map_deep( $value, 'acf_esc_html' ) : acf_esc_html( $value );
		}
		return $value;
	}
	public function validate_value( $valid, $value, $field, $input ) {
		if ( true !== $valid ) { return $valid; }
		$native = $this->native_field( $field );
		$valid = apply_filters( 'acf/validate_value/type=' . $native['type'], $valid, $value, $native, $input );
		return ACF_VF_Validation::validate( $valid, $value, $this->validation_field( $field ), $input );
	}
	private function validation_field( $field ) {
		if ( acf_get_form_data( 'vf_rest_validation' ) ) { return $field; }
		$screen = acf_get_form_data( 'vf_screen' );
		if ( ! $screen && wp_doing_ajax() ) {
			$screen = isset( $_POST['_acf_screen'] ) && is_string( $_POST['_acf_screen'] ) ? $_POST['_acf_screen'] : '';
		}
		if ( 'acf_form' === $screen && ! ACF_VF_Validation::is_true( get_option( 'acf_vf_frontend', true ) ) ) {
			$field['function'] = 'none';
			$field['unique'] = 'non-unique';
		}
		return $field;
	}
	public function validate_rest_value( $valid, $value, $field ) {
		$native = $this->native_field( $field );
		return apply_filters( 'acf/validate_rest_value/type=' . $native['type'], $valid, $value, $native );
	}
	public function get_rest_schema( array $field ) {
		$native = $this->native_field( $field );
		return acf_get_field_type( $native['type'] )->get_rest_schema( $native );
	}
	public function format_value_for_rest( $value, $post_id, array $field ) {
		$native = $this->native_field( $field );
		return acf_get_field_type( $native['type'] )->format_value_for_rest( $value, $post_id, $native );
	}
	public function get_rest_links( $value, $post_id, array $field ) {
		$native = $this->native_field( $field );
		return acf_get_field_type( $native['type'] )->get_rest_links( $value, $post_id, $native );
	}

	public function render_field_settings( $field ) {
		$choices = acf_get_grouped_field_types();
		foreach ( $choices as $group => &$types ) {
			foreach ( array( 'validated_field', 'tab', 'message', 'accordion', 'clone', 'group', 'repeater', 'flexible_content' ) as $type ) { unset( $types[$type] ); }
		}
		unset( $types );
		acf_render_field_setting( $field, array( 'label' => __( 'Input type', 'acf_vf' ), 'type' => 'select', 'name' => 'sub_field_type', 'value' => $field['sub_field']['type'] ?? 'text', 'choices' => $choices, 'wrapper' => array( 'class' => 'vf-type-setting' ) ) );
		echo '<div class="vf-native-settings" data-prefix="' . esc_attr( $field['prefix'] ) . '" data-key="' . esc_attr( $field['key'] ) . '">';
		$this->render_native_settings( $field );
		echo '</div>';
	}
	public function render_native_settings( $field ) {
		$native = $this->native_field( $field );
		$native['prefix'] = $field['prefix'] . '[sub_field]';
		acf_hidden_input( array( 'name' => $native['prefix'] . '[type]', 'value' => $native['type'] ) );
		foreach ( array( 'render_field_settings', 'render_field_general_settings', 'render_field_validation_settings', 'render_field_presentation_settings', 'render_field_advanced_settings' ) as $method ) {
			$instance = acf_get_field_type( $native['type'] );
			if ( is_callable( array( $instance, $method ) ) ) { $instance->$method( $native ); }
		}
	}
	public function render_field_validation_settings( $field ) {
		acf_render_field_setting( $field, array( 'label' => __( 'Validator', 'acf_vf' ), 'name' => 'function', 'type' => 'select', 'choices' => array( 'none' => __( 'None', 'acf_vf' ), 'regex' => __( 'Regular expression', 'acf_vf' ), 'php' => __( 'PHP', 'acf_vf' ) ) ) );
		acf_render_field_setting( $field, array( 'label' => __( 'Validation rule', 'acf_vf' ), 'name' => 'pattern', 'type' => 'textarea', 'rows' => 6, 'instructions' => __( 'Regex without delimiters. PHP is trusted administrator code. Variables: $value, $prev_value, $inputs, $post_id, $post_type, $this_key, $name, $message. Return false or an error message to reject.', 'acf_vf' ), 'wrapper' => array( 'class' => 'vf-rule-setting' ) ) );
		acf_render_field_setting( $field, array( 'label' => __( 'Error message', 'acf_vf' ), 'name' => 'message', 'type' => 'text' ) );
		acf_render_field_setting( $field, array( 'label' => __( 'Unique value', 'acf_vf' ), 'name' => 'unique', 'type' => 'select', 'choices' => array( 'non-unique' => __( 'Not unique', 'acf_vf' ), 'global' => __( 'All fields and posts', 'acf_vf' ), 'post_type' => __( 'All fields of this post type', 'acf_vf' ), 'post_key' => __( 'This field across this post type', 'acf_vf' ), 'this_post' => __( 'All fields in this record', 'acf_vf' ), 'this_post_key' => __( 'This field in this record', 'acf_vf' ) ) ) );
		acf_render_field_setting( $field, array( 'label' => __( 'Unique post statuses', 'acf_vf' ), 'name' => 'unique_statuses', 'type' => 'checkbox', 'choices' => get_post_statuses() ) );
		acf_render_field_setting( $field, array( 'label' => __( 'Validate drafts', 'acf_vf' ), 'name' => 'drafts', 'type' => 'true_false', 'ui' => 1, 'instructions' => __( 'Global draft validation, when enabled, takes precedence.', 'acf_vf' ) ) );
	}
	public function render_field_presentation_settings( $field ) {
		acf_render_field_setting( $field, array( 'label' => __( 'Read only', 'acf_vf' ), 'name' => 'read_only', 'type' => 'true_false', 'ui' => 1 ) );
		acf_render_field_setting( $field, array( 'label' => __( 'Input mask', 'acf_vf' ), 'name' => 'mask', 'type' => 'text', 'instructions' => __( '9: digit, a: letter, *: letter or digit. For text, email, URL and telephone inputs.', 'acf_vf' ) ) );
		acf_render_field_setting( $field, array( 'label' => __( 'Mask placeholder', 'acf_vf' ), 'name' => 'mask_placeholder', 'type' => 'text' ) );
		acf_render_field_setting( $field, array( 'label' => __( 'Clear incomplete mask', 'acf_vf' ), 'name' => 'mask_autoclear', 'type' => 'true_false', 'ui' => 1 ) );
	}
	public function ajax_sub_settings() {
		check_ajax_referer( 'acf-vf-settings', 'nonce' );
		if ( ! current_user_can( acf_get_setting( 'capability' ) ) ) { wp_send_json_error( null, 403 ); }
		$type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
		$prefix = wp_unslash( $_POST['prefix'] ?? '' );
		$key = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
		if ( ! is_string( $prefix ) || ! preg_match( '/^acf_fields\[[a-zA-Z0-9_-]+\]$/D', $prefix ) || ! acf_get_field_type( $type ) || in_array( $type, array( 'validated_field', 'group', 'repeater', 'flexible_content', 'clone', 'tab', 'message', 'accordion' ), true ) ) { wp_send_json_error( null, 400 ); }
		$field = acf_get_field( $key );
		if ( ! $field || $field['type'] !== 'validated_field' ) { $field = acf_get_valid_field( array( 'type' => 'validated_field', 'key' => $key, 'name' => '', 'sub_field' => array( 'type' => $type ) ) ); }
		if ( ( $field['sub_field']['type'] ?? '' ) !== $type ) { $field['sub_field'] = array( 'type' => $type ); }
		$field['prefix'] = $prefix;
		ob_start(); $this->render_native_settings( $field ); $html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	public function input_admin_enqueue_scripts() {
		wp_enqueue_script( 'acf-vf-mask', plugins_url( 'js/jquery.maskedinput.js', ACF_VF_PLUGIN_FILE ), array( 'jquery' ), ACF_VF_VERSION, true );
		wp_enqueue_script( 'acf-vf-input', plugins_url( 'js/input.js', ACF_VF_PLUGIN_FILE ), array( 'acf-input', 'acf-vf-mask' ), ACF_VF_VERSION, true );
		if ( ! is_admin() && ! ACF_VF_Validation::is_true( get_option( 'acf_vf_frontend_css', true ) ) ) { wp_dequeue_style( 'colors' ); }
	}
	public function field_group_admin_enqueue_scripts() {
		$editor = wp_enqueue_code_editor( array( 'type' => 'text/x-php' ) );
		wp_enqueue_script( 'acf-vf-admin', plugins_url( 'js/admin.js', ACF_VF_PLUGIN_FILE ), array( 'acf-field-group', 'jquery' ), ACF_VF_VERSION, true );
		wp_localize_script( 'acf-vf-admin', 'acfVfSettings', array( 'nonce' => wp_create_nonce( 'acf-vf-settings' ), 'editor' => $editor ) );
	}
	public function register_options() {
		foreach ( array( 'acf_vf_debug', 'acf_vf_drafts', 'acf_vf_frontend', 'acf_vf_frontend_css' ) as $name ) {
			register_setting( 'acf_vf', $name, array( 'type' => 'boolean', 'sanitize_callback' => array( 'ACF_VF_Validation', 'is_true' ) ) );
		}
	}
	public function settings_menu() { add_options_page( __( 'Validated Field', 'acf_vf' ), __( 'Validated Field', 'acf_vf' ), 'manage_options', 'acf-validated-field', array( $this, 'settings_page' ) ); }
	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap"><h1>' . esc_html__( 'Validated Field', 'acf_vf' ) . '</h1><form action="options.php" method="post">';
		settings_fields( 'acf_vf' );
		foreach ( array( 'acf_vf_drafts' => __( 'Validate drafts globally', 'acf_vf' ), 'acf_vf_frontend' => __( 'Validate acf_form() submissions', 'acf_vf' ), 'acf_vf_frontend_css' => __( 'Load ACF form admin colors', 'acf_vf' ), 'acf_vf_debug' => __( 'Debug mode (legacy setting)', 'acf_vf' ) ) as $name => $label ) {
			echo '<p><label><input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ACF_VF_Validation::is_true( get_option( $name, $name !== 'acf_vf_debug' ) ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		submit_button(); echo '</form>';
		$this->render_pro_promotion();
		echo '</div>';
	}
	private function render_pro_promotion(): void {
		if ( defined( 'ACF_VF_PRO_VERSION' ) ) { return; }
		$url = esc_url( (string) ACF_VF_PRO_UPGRADE_URL, array( 'http', 'https' ) );
		if ( $url === '' ) { return; }
		echo '<section class="card acf-vf-pro-card" aria-labelledby="acf-vf-pro-title"><h2 id="acf-vf-pro-title">' . esc_html__( 'Build more with Validated Field Pro', 'acf_vf' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add calculations, workflow automation and advanced data tools.', 'acf_vf' ) . '</p><ul class="ul-disc">';
		foreach ( array( __( 'Formulas and rollups', 'acf_vf' ), __( 'Automation and approval workflows', 'acf_vf' ), __( 'Saved views and bulk editing', 'acf_vf' ), __( 'Imports and configuration tools', 'acf_vf' ) ) as $feature ) {
			echo '<li>' . esc_html( $feature ) . '</li>';
		}
		echo '</ul><p><a class="button" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Explore Pro', 'acf_vf' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'acf_vf' ) . '</span></a></p></section>';
	}
}
