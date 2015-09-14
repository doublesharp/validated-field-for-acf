<?php
if ( class_exists( 'acf_Field' ) && ! class_exists( 'acf_field_validated_field' ) ):
class acf_field_validated_field extends acf_field {
	// vars
	var $slug,
		$config,
		$settings,					// will hold info such as dir / path
		$defaults,					// will hold default field options
		$sub_defaults,				// will hold default sub field options
		$debug,						// if true, don't use minified and confirm form submit					
		$drafts,
		$frontend;

	/*
	*  __construct
	*
	*  Set name / label needed for actions / filters
	*
	*  @since	3.6
	*  @date	23/01/13
	*/
	function __construct(){
		// vars
		$this->slug 	= 'acf-validated-field';
		$this->name		= 'validated_field';
		$this->label 	= __( 'Validated Field', 'acf_vf' );
		$this->category	= __( 'Basic', 'acf' );
		$this->drafts	= $this->option_value( 'acf_vf_drafts' );
		$this->frontend = $this->option_value( 'acf_vf_frontend' );
		$this->frontend_css = $this->option_value( 'acf_vf_frontend_css' );
		$this->debug 	= $this->option_value( 'acf_vf_debug' );

		$this->defaults = array(
			'read_only' => 'no',
			'hidden' 	=> 'no',
			'mask'		=> '',
			'mask_autoclear' => true,
			'mask_placeholder' => '_',
			'function'	=> 'none',
			'pattern'	=> '',
			'message'	=>  __( 'Validation failed.', 'acf_vf' ),
			'unique'	=> 'non-unique',
			'unique_statuses' => apply_filters( 'acf_vf/unique_statuses', array( 'publish', 'future' ) ),
			'drafts'	=> false,
			'render_field' => true
		);

		$this->sub_defaults = array(
			'type'		=> '',
			'key'		=> '',
			'name'		=> '',
			'_name'		=> '',
			'id'		=> '',
			'value'		=> '',
			'field_group' => '',
			'readonly' 	=> '',
			'disabled' 	=> '',
		);

		$this->input_defaults = array(
			'id'		=> '',
			'value'		=> '',
		);

		// do not delete!
		parent::__construct();

		// settings
		$this->settings = array(
			'path'		=> apply_filters( 'acf/helpers/get_path', __FILE__ ),
			'dir'		=> apply_filters( 'acf/helpers/get_dir', __FILE__ ),
			'version'	=> ACF_VF_VERSION,
		);

		if ( is_admin() || $this->frontend ){ // admin actions

			// bug fix for acf with backslashes in the content.
			add_filter( 'content_save_pre', array( $this, 'fix_post_content' ) );
			add_filter( 'acf/get_valid_field', array( $this, 'fix_upgrade' ) );

			// override the default ajax actions to provide our own messages since they aren't filtered
			add_action( 'init', array( $this, 'add_acf_ajax_validation' ) );

			// validate validated_fields
			add_filter( "acf/validate_value/type=validated_field", array( $this, 'validate_field' ), 10, 4 );

			if ( ! is_admin() && $this->frontend ){
				// prevent CSS from loading on the front-end
				if ( ! $this->frontend_css ){
					add_action( 'acf/input/admin_enqueue_scripts',  array( $this, 'remove_acf_form_style' ) );
				}

				// add the post_ID to the acf[] form using jQuery
				add_action( 'wp_head', array( $this, 'set_post_id_to_acf_form' ) );

				// automatically called in admin_head, we need to hook to wp_head for front-end
				add_action( 'wp_head', array( $this, 'input_admin_enqueue_scripts' ), 1 );
			}
			if ( is_admin() ){

				// insert javascript into the header.
				add_action( 'admin_head', array( $this, 'admin_head' ) );

				// add the post_ID to the acf[] form
				add_action( 'edit_form_after_editor', array( $this, 'edit_form_after_editor' ) );

				// add the user_ID to the acf[] form
				add_action( 'personal_options', array( $this, 'personal_options' ) );

				// make sure plugins have loaded so we can modify the options
				add_action( 'admin_menu', array( $this, 'add_options_page' ) );

				add_filter( 'acf/prepare_field_for_export', array( $this, 'prepare_field_for_export' ) );
			}
		}
	}

	function fix_upgrade( $field ){
		// the $_POST will tell us if this is an upgrade
		$is_5_upgrade = 
			isset( $_POST['action'] ) && $_POST['action'] == 'acf/admin/data_upgrade' && 
			isset( $_POST['version'] ) && $_POST['version'] == '5.0.0';

		// if it is an upgrade recursively fix the field values
		if ( $is_5_upgrade ){
			$field = $this->do_recursive_slash_fix( $field );
		}

		return $field;
	}

	function fix_post_content( $content ){
		global $post;

		// are we saving a field group?
		$is_field_group = get_post_type() == 'acf-field-group';

		// are we saving a field group?
		$is_field = get_post_type() == 'acf-field';

		// are we upgrading to ACF 5?
		$is_5_upgrade = 
			isset( $_POST['action'] ) && $_POST['action'] == 'acf/admin/data_upgrade' && 
			isset( $_POST['version'] ) && $_POST['version'] == '5.0.0';

		// if we are, we need to check the values for single, but not double, backslashes and make them double
		if ( $is_field || $is_field_group || $is_5_upgrade ){
			$content = $this->do_slash_fix( $content );
		}

		return $content;
	}

	function do_slash_fix( $string ){
		if ( preg_match( '~(?<!\\\\)\\\\(?!\\\\)~', $string ) ){
			$string = str_replace('\\', '\\\\', $string );
		}
		if ( preg_match( '~\\\\\\\\"~', $string ) ){
			$string = str_replace('\\\\"', '\\"', $string );
		}
		return $string;
	}

	function do_recursive_slash_fix( $array ){
		// loop through all levels of the array
		foreach( $array as $key => &$value ){
			if ( is_array( $value ) ){
				// div deeper
				$value = $this->do_recursive_slash_fix( $value );
			} elseif ( is_string( $value ) ){
				// fix single backslashes to double
				$value = $this->do_slash_fix( $value );
			}
		}

		return $array;
	}

	function check_value( $value, $obj_or_array ){
		if ( is_array( $obj_or_array ) ){
			return in_array( $value, $obj_or_array );
		} else {
			return $value == $obj_or_array;
		}
	}

	function add_acf_ajax_validation(){
		global $acf;
		if ( version_compare( $acf->settings['version'], '5.2.6', '<' ) ){
			remove_all_actions( 'wp_ajax_acf/validate_save_post' );
			remove_all_actions( 'wp_ajax_nopriv_acf/validate_save_post' );
		}
		add_action( 'wp_ajax_acf/validate_save_post',			array( $this, 'ajax_validate_save_post') );
		add_action( 'wp_ajax_nopriv_acf/validate_save_post',	array( $this, 'ajax_validate_save_post') );
	}

	function set_post_id_to_acf_form(){
		global $post;
		?>

		<script type="text/javascript">
		jQuery(document).ready(function(){
			jQuery('form.acf-form').append('<input type="hidden" name="acf[post_ID]" value="<?php echo $post->ID; ?>"/>');
			jQuery('form.acf-form').append('<input type="hidden" name="acf[frontend]" value="true"/>');
		});
		</script>

		<?php
	}

	function edit_form_after_editor( $post ){
		echo "<input type='hidden' name='acf[post_ID]' value='{$post->ID}'/>";
	}

	function personal_options( $user ){
		echo "<input type='hidden' name='acf[post_ID]' value='user_{$user->ID}'/>";
	}

	function prepare_field_for_export( $field ){
		if ( isset( $field['sub_field'] ) ){	
			remove_filter( 'acf/prepare_field_for_export', array( $this, 'prepare_field_for_export' ) );
			$field['sub_field'] = acf_prepare_field_for_export( $field['sub_field'] );
			add_filter( 'acf/prepare_field_for_export', array( $this, 'prepare_field_for_export' ) );
		}
		return $field;
	}

	function option_value( $key ){
		return get_field( $key, 'options' );
	}

	function admin_head(){
		global $typenow, $acf;

		$min = ( ! $this->debug )? '.min' : '';
		if ( $this->is_edit_page() && "acf-field-group" == $typenow ){
			wp_register_script( 'acf-validated-field-admin', plugins_url( "js/admin{$min}.js", __FILE__ ), array( 'jquery', 'acf-field-group' ), ACF_VF_VERSION );	
			wp_enqueue_style( 'acf-validated-field-admin', plugins_url( "css/admin.css", __FILE__ ), array(), ACF_VF_VERSION );	
		}
		wp_enqueue_script( array(
			'jquery',
			'acf-validated-field-admin',
		));	
		if ( version_compare( $acf->settings['version'], '5.2.6', '<' ) ){
			wp_enqueue_script( 'acf-validated-field-group', plugins_url( "js/field-group{$min}.js", __FILE__ ), array( 'jquery', 'acf-field-group' ), ACF_VF_VERSION );
		}

		global $field_level_drafts;
		$field_level_drafts = false;
		if ( !$this->drafts ){
			$fields = get_fields();

			if( $fields ){
				foreach( $fields as $field_name => $value )	{
					$field = get_field_object($field_name, false, array('load_value' => false));
					if ( $field['type'] == 'validated_field' && $field['drafts'] ){
						$field_level_drafts = true;
						break;
					}
				}
			}
		}

		?>
		<script>
		(function($){
			acf.add_action('ready', function(){
				<?php if ( $this->drafts || $field_level_drafts ): ?>
					$(document).off('click', '#save-post');
					$(document).on( 'click', '#save-post', function(e){
						e.$el = $(this);
						acf.validation.click_publish(e);
					});

				<?php endif; ?>
				$(document).off('click', 'input[type="submit"]');
				$(document).on( 'click', 'input[type="submit"]', function(e){
					e.$el = $(this);

					$post_status = get_post_status();
					
					if ( e.$el.val() == 'Publish' ){
						$post_status.val('publish');
					} else {
						if ( $post_status.val() != 'publish' && $('#original_post_status').val() == 'publish' ){
							acf.validation.click_ignore(e);
							return
						}
						$post_status.val($('select#post_status').val());
					}
					
					acf.validation.save_click = false;
					acf.validation.click_publish(e);
				});

				function get_post_status(){
					$form = $('form#post');
					$post_status = $form.find('input[name="acf[post_status]"]');
					if ( !$post_status.length ){
						$post_status = $('<input type="hidden" name="acf[post_status]"/>');
						$form.append($post_status);
					}
					return $post_status;
				}
			});
		})(jQuery);
		</script>
		<?php
	}

	function add_options_page(){
		$page = acf_add_options_page( apply_filters( 'acf_vf/add_options_page', array(
			'page_title' 	=> 'Validated Field Settings',
			'menu_title' 	=> 'Validated Field',
			'menu_slug' 	=> 'validated-field-settings',
			'parent_slug'	=> 'edit.php?post_type=acf-field-group',
			'capability' 	=> 'edit_posts',
			'redirect' 		=> false, 
			'autoload'		=> true,
		) ) );

		acf_add_local_field_group( apply_filters( 'acf_vf/options_field_group', array(
			'key' => 'group_55d6baa806f00',
			'title' => 'Validated Field Settings',
			'fields' => array (
				array (
					'key' => 'field_55d6bd56d220f',
					'label' => 'General',
					'name' => '',
					'type' => 'tab',
					'instructions' => '',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array (
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'placement' => 'top',
					'endpoint' => 0,
				),
				array (
					'key' => 'field_55d6bc95a04d4',
					'label' => 'Debugging',
					'name' => 'acf_vf_debug',
					'type' => 'true_false',
					'instructions' => 'Check this box to turn on debugging for Validated Fields.',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array (
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'message' => 'Enable Debugging',
					'default_value' => 0,
				),
				array (
					'key' => 'field_55d6be22b0225',
					'label' => 'Draft Validation',
					'name' => 'acf_vf_drafts',
					'type' => 'true_false',
					'instructions' => 'Check this box to enable Draft validation globally, or uncheck to allow it to be set per field.',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array (
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'message' => 'Enable Draft Validation',
					'default_value' => 0,
				),
				array (
					'key' => 'field_55d6c0d4b3ae0',
					'label' => 'Frontend Validation',
					'name' => 'acf_vf_frontend',
					'type' => 'true_false',
					'instructions' => 'Check this box to turn on validation for front-end forms created with <code>acf_form()</code>.',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array (
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'message' => 'Enable Frontend Validation',
					'default_value' => 0,
				),
				array (
					'key' => 'field_55d6c123b3ae1',
					'label' => 'Admin CSS on Frontend',
					'name' => 'acf_vf_frontend_css',
					'type' => 'true_false',
					'instructions' => 'Uncheck this box to turn off "colors-fresh" admin theme enqueued by <code>acf_form_head()</code>.',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array (
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'message' => 'Enqueue Admin CSS on Frontend',
					'default_value' => 1,
				),
				array (
					'key' => 'field_55d6bd84d2210',
					'label' => 'Masked Input',
					'name' => '',
					'type' => 'tab',
					'instructions' => '',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array (
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'placement' => 'top',
					'endpoint' => 0,
				),
				array (
					'key' => 'field_55d6bd84d2210',
					'label' => 'Masked Input',
					'name' => '',
					'type' => 'tab',
					'instructions' => '',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array (
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'placement' => 'top',
					'endpoint' => 0,
				),
				array (
					'key' => 'field_55f1d1ec2c61c',
					'label' => 'Mask Patterns Upgrade',
					'name' => '',
					'type' => 'message',
					'instructions' => '',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array (
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'message' => 'This is a message about how you can upgrade to enable custom mask patterns.',
					'esc_html' => 0,
				),
			),
			'location' => array (
				array (
					array (
						'param' => 'options_page',
						'operator' => '==',
						'value' => 'validated-field-settings',
					),
				),
			),
			'menu_order' => 100,
			'position' => 'normal',
			'style' => 'seamless',
			'label_placement' => 'top',
			'instruction_placement' => 'label',
			'hide_on_screen' => '',
			'active' => 1,
			'description' => '',
		) ) );
	}

	function remove_acf_form_style(){
		wp_dequeue_style( array( 'colors-fresh' ) );
	}

	function setup_field( $field ){
		// setup booleans, for compatibility
		$field = acf_prepare_field( array_merge( $this->defaults, $field ) );

		// set up the sub_field
		$sub_field = isset( $field['sub_field'] )? 
			$field['sub_field'] :	// already set up
			array();				// create it

		// mask the sub field as the parent by giving it the same key values
		foreach( $field as $key => $value ){
			if ( in_array( $key, array( 'sub_field', 'type' ) + array_keys( $this->defaults ) ) )
				continue;
			$sub_field[$key] = $value;
		}

		// these fields need some special formatting
		$sub_field['_input'] = $field['prefix'].'['.$sub_field['key'].']';
		$sub_field['name'] = $sub_field['_input'];
		$sub_field['id'] = str_replace( '-acfcloneindex', '', str_replace( ']', '', str_replace( '[', '-', $sub_field['_input'] ) ) );

		// make sure all the defaults are set
		$field['sub_field'] = array_merge( $this->sub_defaults, $sub_field );
		foreach( $this->defaults as $key => $default ){
			unset($field['sub_field'][$key]);
		}
		return $field;
	}

	function setup_sub_field( $field ){
		return $field['sub_field'];	
	}

	/*
	*  get_post_statuses()
	*
	*  Get the various post statuses that have been registered
	*
	*  @type		function
	*
	*/
	function get_post_statuses() {
		global $wp_post_statuses;
		return $wp_post_statuses;
	}

	/*
	*  ajax_validate_save_post()
	*
	*  Override the default acf_input()->ajax_validate_save_post() to return a custom validation message
	*
	*  @type		function
	*
	*/
	function ajax_validate_save_post() {
		// validate
		if ( ! isset( $_POST['_acfnonce'] ) ) {
			// ignore validation, this form $_POST was not correctly configured
			die();
		}
		
		// success
		if ( acf_validate_save_post() ) {
			$json = array(
				'result'	=> 1,
				'message'	=> __( 'Validation successful', 'acf' ),
				'errors'	=> 0
			);
			
			die( json_encode( $json ) );
		}
		
		// fail
		$json = array(
			'result'	=> 0,
			'message'	=> __( 'Validation failed', 'acf' ),
			'errors'	=> acf_get_validation_errors()
		);

		// update message
		$i = count( $json['errors'] );
		$json['message'] .= '. ' . sprintf( _n( '1 field below is invalid.', '%s fields below are invalid.', $i, 'acf_vf' ), $i ) . ' ' . __( 'Please check your values and submit again.', 'acf_vf' );
		
		die( json_encode( $json ) );
	}

	function validate_field( $valid, $value, $field, $input ) {

		if ( ! $valid )
			return $valid;

		
		// the wrapped field
		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );
		
		if ( $field['required'] && empty( $value ) ){
			return $valid;									// let the required field handle it
		}

		// The new post status we stuck into the ACF request
		$post_status = isset( $_REQUEST['acf']['post_status'] )?
			$_REQUEST['acf']['post_status'] :
			false;

		// we aren't publishing and we don't want to validate drafts globally or for this field
		if ( !empty( $post_status ) && $post_status != 'publish' && !$this->drafts && !$field['drafts'] ){
			return $valid;									
		}

		// get ID of the submit post or cpt, allow null for options page
		$post_id = isset( $_POST['acf']['post_ID'] )? $_POST['acf']['post_ID'] : null;

		// the type of the submitted post
		$post_type = get_post_type( $post_id );				

		$frontend = isset( $_REQUEST['acf']['frontend'] )?
			$_REQUEST['acf']['frontend'] :
			false;

		if ( !empty( $field['parent'] ) ){
			$parent_field = acf_get_field( $field['parent'] );	
		}

		// if it's a repeater field, get the validated field so we can do meta queries...
		if ( $is_repeater = ( isset( $parent_field ) && 'repeater' == $parent_field['type'] ) ){
			$index = explode( '][', $input );
			$index = $index[1];
		}
		
		if ( is_array( $value ) ){
			$value = implode( ',', $value );
		}

		$function = $field['function'];						// what type of validation?
		$pattern = $field['pattern'];						// string to use for validation
		$message = $field['message'];						// failure message to return to the UI
		if ( ! empty( $function ) && ! empty( $pattern ) ){
			switch ( $function ){							// only run these checks if we have a pattern
				case 'regex':								// check for any matches to the regular expression
					$pattern_fltr = '/' . str_replace( "/", "\/", $pattern ) . '/';
					if ( ! preg_match( $pattern_fltr, $value ) ){
						$valid = false;						// return false if there are no matches
					}
					break;
				case 'sql':									// todo: sql checks?
					break;
				case 'php':									// this code is a little tricky, one bad eval() can break the lot. needs a nonce.
					$this_key = $field['name'];
					if ( $is_repeater ) $this_key .= '_' . $index . '_' . $sub_field['name'];

					// get the fields based on the keys and then index by the meta value for easy of use
					$input_fields = array();
					foreach ( $_POST['acf'] as $key => $val ){
						if ( false !== ( $input_field = get_field_object( $key, $post_id ) ) ){
							$meta_key = $input_field['name'];
							$input_fields[$meta_key] = array(
								'field'=>$input_field,
								'value'=>$val,
								'prev_val'=>get_post_meta( $post_id, $meta_key, true )
							);
						}
					}

					$message = $field['message'];			// the default message

					// not yet saved to the database, so this is the previous value still
					$prev_value = addslashes( get_post_meta( $post_id, $this_key, true ) );

					// unique function for this key
					$function_name = 'validate_' . $field['key'] . '_function';
					
					// it gets tricky but we are trying to account for an capture bad php code where possible
					$pattern = addcslashes( trim( $pattern ), '$' );
					if ( substr( $pattern, -1 ) != ';' ) $pattern.= ';';

					$value = addslashes( $value );

					// this must be left aligned as it contains an inner HEREDOC
					$php = <<<PHP
						if ( ! function_exists( '$function_name' ) ):
						function $function_name( \$args, &\$message ){
							extract( \$args );
							try {
								\$code = <<<INNERPHP
								$pattern return true;

INNERPHP;
// ^^^ no whitespace to the left!
								return @eval( \$code );
							} catch ( Exception \$e ){
								\$message = "Error: ".\$e->getMessage(); return false;
							}
						}
						endif; // function_exists
						\$valid = $function_name( array( 'post_id'=>'$post_id', 'post_type'=>'$post_type', 'this_key'=>'$this_key', 'value'=>'$value', 'prev_value'=>'$prev_value', 'inputs'=>\$input_fields ), \$message );
PHP;

					if ( true !== eval( $php ) ){			// run the eval() in the eval()
						$error = error_get_last();			// get the error from the eval() on failure
						// check to see if this is our error or not.
						if ( strpos( $error['file'], basename( __FILE__ ) ) && strpos( $error['file'], "eval()'d code" ) ){
							preg_match( '/eval\\(\\)\'d code\\((\d+)\\)/', $error['file'], $matches );
							return $message = __( 'PHP Error', 'acf_vf' ) . ': ' . $error['message'] . ', line ' . $matches[1] . '.';
						} 
					}
					// if a string is returned, return it as the error.
					if ( is_string( $valid ) ){
						return stripslashes( $valid );
					}
					break;
			}
		} elseif ( ! empty( $function ) && $function != 'none' ) {
			return __( "This field's validation is not properly configured.", 'acf_vf' );
		}
			
		$unique = $field['unique'];
		$field_is_unique = ! empty( $value ) && ! empty( $unique ) && $unique != 'non-unique';

		// validate the submitted values since there might be dupes in the form submit that aren't yet in the database
		if ( $field_is_unique ){
			$value_instances = 0;
			switch ( $unique ){
				case 'global';
				case 'post_type':
				case 'this_post':
					// no duplicates at all allowed, check the submitted values
					foreach ( $_REQUEST['acf'] as $key => $submitted ){
						if ( is_array( $submitted ) ){	
							foreach( $submitted as $row ){
								foreach( $row as $row_key => $row_value ){
									if ( $row_value == $value ){
										$value_instances++;
									}
								}
								if ( $value_instances>1 ){
									break;
								}
							}
						}
					}
					break;
				case 'post_key':
				case 'this_post_key':
					// only check the key for a repeater for duplicate submissions
					if ( $is_repeater ){
						foreach ( $_REQUEST['acf'] as $key => $submitted ){
							if ( is_array( $submitted ) ){	
								foreach( $submitted as $row ){
									foreach( $row as $row_key => $row_value ){
										if ( $row_key == $field['key'] && $row_value == $value ){
											$value_instances++;
											break;
										}
									}
									if ( $value_instances>1 ){
										break;
									}
								}
							}
						}
					}
					break;
			}

			// this value came up more than once, so we need to mark it as an error
			if ( $value_instances > 1 ){
				$message = __( 'The value', 'acf_vf' ) . " '$value' " . __( 'was submitted multiple times and should be unique', 'acf_vf' ) . ' ';
				switch ( $unique ){
					case 'global';
						$message .= __( 'globally for all custom fields.', 'acf_vf' );
						break;
					case 'post_type':
						$message .= __( 'for all fields on this post type.', 'acf_vf' );
						break;
					case 'this_post':
						$message .= __( 'for all fields on this post.', 'acf_vf' );
						break;
					case 'post_key':
					case 'this_post_key':
						$message .= __( 'for', 'acf_vf' ) . " {$field['label']}.";
						break;
				}
				return $message;
			}
		}

		// Unless we have a previous validation error, run the SQL queries to see if there are duplicate values
		if ( $field_is_unique ){
			global $wpdb;
			$status_in = "'" . implode( "','", $field['unique_statuses'] ) . "'";

			// are we editting a user?
			$is_user = strpos( $post_id, 'user_' ) === 0;

			if ( $is_user ) {
				// set up queries for the user table
				$post_ids = array( (int) str_replace( 'user_', '', $post_id ) );
				$table_key = 'user_id';
				$sql_prefix = "SELECT m.umeta_id AS meta_id, m.{$table_key} AS {$table_key}, u.user_login AS title FROM {$wpdb->usermeta} m JOIN {$wpdb->users} u ON u.ID = m.{$table_key}";
			} else {
				// set up queries for the posts table
				if ( function_exists( 'icl_object_id' ) ){
					// WPML compatibility, get code list of active languages
					$languages = $wpdb->get_results( "SELECT code FROM {$wpdb->prefix}icl_languages WHERE active = 1", ARRAY_A );
					$wpml_ids = array();
					foreach( $languages as $lang ){
						$wpml_ids[] = (int) icl_object_id( $post_id, $post_type, true, $lang['code'] );
					}
					$post_ids = array_unique( $wpml_ids );
				} else {
					$post_ids = array( (int) $post_id );
				}
				$table_key = 'post_id';
				$sql_prefix = "SELECT m.meta_id AS meta_id, m.{$table_key} AS {$table_key}, p.post_title AS title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.{$table_key} AND p.post_status IN ($status_in)";
			}

			switch ( $unique ){
				case 'global': 
					// check to see if this value exists anywhere in the postmeta table
					$sql = $wpdb->prepare( 
						"{$sql_prefix} AND {$table_key} NOT IN ([IN_NOT_IN]) WHERE ( meta_value = %s OR meta_value LIKE %s )",
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
					break;
				case 'post_type':
					// check to see if this value exists in the postmeta table with this $post_id
					if ( $is_user ){
						$sql = $wpdb->prepare( 
							"{$sql_prefix} WHERE ( ( {$table_key} IN ([IN_NOT_IN]) AND meta_key != %s ) OR {$table_key} NOT IN ([IN_NOT_IN]) ) AND ( meta_value = %s OR meta_value LIKE %s )", 
							$field['name'],
							$value,
							'%"' . $wpdb->esc_like( $value ) . '"%'
						);

					} else {
						$sql = $wpdb->prepare( 
							"{$sql_prefix} AND p.post_type = %s WHERE ( ( {$table_key} IN ([IN_NOT_IN]) AND meta_key != %s ) OR {$table_key} NOT IN ([IN_NOT_IN]) ) AND ( meta_value = %s OR meta_value LIKE %s )", 
							$post_type,
							$field['name'],
							$value,
							'%"' . $wpdb->esc_like( $value ) . '"%'
						);
					}
					break;
				case 'this_post':
					// check to see if this value exists in the postmeta table with this $post_id
					$this_key = $is_repeater ? 
						$parent_field['name'] . '_' . $index . '_' . $field['name'] :
						$field['name'];
					$sql = $wpdb->prepare( 
						"{$sql_prefix} AND {$table_key} IN ([IN_NOT_IN]) AND meta_key != %s AND ( meta_value = %s OR meta_value LIKE %s )",
						$this_key,
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
					break;
				case 'post_key':
				case 'this_post_key':
					// check to see if this value exists in the postmeta table with both this $post_id and $meta_key
					if ( $is_repeater ){
						$this_key = $parent_field['name'] . '_' . $index . '_' . $field['name'];
						$meta_key = $parent_field['name'] . '_%_' . $field['name'];
						if ( 'post_key' == $unique ){
							$sql = $wpdb->prepare(
								"{$sql_prefix} WHERE ( ( {$table_key} IN ([IN_NOT_IN]) AND meta_key != %s AND meta_key LIKE %s ) OR ( {$table_key} NOT IN ([IN_NOT_IN]) AND meta_key LIKE %s ) ) AND ( meta_value = %s OR meta_value LIKE %s )", 
								$this_key,
								$meta_key,
								$meta_key,
								$value,
								'%"' . $wpdb->esc_like( $value ) . '"%'
							);
						} else {
							$sql = $wpdb->prepare(
								"{$sql_prefix} WHERE {$table_key} IN ([IN_NOT_IN]) AND meta_key != %s AND meta_key LIKE %s AND ( meta_value = %s OR meta_value LIKE %s )", 
								$this_key,
								$meta_key,
								$meta_key,
								$value,
								'%"' . $wpdb->esc_like( $value ) . '"%'
							);
						}
					} else {
						if ( 'post_key' == $unique ){
							$sql = $wpdb->prepare( 
								"{$sql_prefix} AND p.post_type = %s AND post_id NOT IN ([IN_NOT_IN]) WHERE meta_key = %s AND ( meta_value = %s OR meta_value LIKE %s )", 
								$post_type,
								$field['name'],
								$value,
								'%"' . $wpdb->esc_like( $value ) . '"%'
							);
						} else {
							$sql = $wpdb->prepare( 
								"{$sql_prefix} AND {$table_key} IN ([IN_NOT_IN]) WHERE meta_key = %s AND ( meta_value = %s OR meta_value LIKE %s )", 
								$field['name'],
								$value,
								'%"' . $wpdb->esc_like( $value ) . '"%'
							);
						}
					}
					break;
				default:
					// no dice, set $sql to null
					$sql = null;
					break;
			}

			// Only run if we hit a condition above
			if ( ! empty( $sql ) ){

				// Update the [IN_NOT_IN] values
				$sql = $this->prepare_in_and_not_in( $sql, $post_ids );
				
				// Execute the SQL
				$rows = $wpdb->get_results( $sql );
				if ( count( $rows ) ){
					// We got some matches, but there might be more than one so we need to concatenate the collisions
					$conflicts = "";
					foreach ( $rows as $row ){
						$permalink = ( $frontend )? get_permalink( $row->post_id ) : 
							$is_user? "/wp-admin/user-edit.php?user_id={$row->user_id}" :
							"/wp-admin/post.php?post={$row->post_id}&action=edit";
						$conflicts.= "<a href='{$permalink}' style='color:inherit;text-decoration:underline;'>{$row->title}</a>";
						if ( $row !== end( $rows ) ) $conflicts.= ', ';
					}
					return __( 'The value', 'acf_vf' ) . " '$value' " . __( 'is already in use by', 'acf_vf' ) . " {$conflicts}.";
				}
			}
		}
		
		// ACF will use any message as an error
		if ( ! $valid ) return $message;

		return $valid;
	}

	private function prepare_in_and_not_in( $sql, $post_ids ){
		global $wpdb;
		$not_in_count = substr_count( $sql, '[IN_NOT_IN]' );
		if ( $not_in_count > 0 ){
			$args = array( str_replace( '[IN_NOT_IN]', implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) ), str_replace( '%', '%%', $sql ) ) );
			for ( $i=0; $i < substr_count( $sql, '[IN_NOT_IN]' ); $i++ ) { 
				$args = array_merge( $args, $post_ids );
			}
			$sql = call_user_func_array( array( $wpdb, 'prepare' ), $args );
		}
		return $sql;
	}

	/*
	*  render_field_settings()
	*
	*  Create extra settings for your field. These are visible when editing a field
	*
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field (array) the $field being edited
	*  @return	n/a
	*/
	
	function render_field_settings( $field ) {
		//return;
		// defaults?
		$field = $this->setup_field( $field );

		// key is needed in the field names to correctly save the data
		$key = $field['key'];
		$html_key = 'acf_fields-'.$field['ID'];

		$sub_field = $this->setup_sub_field( $field );
		$sub_field['prefix'] = "{$field['prefix']}[sub_field]";

		// remove types that don't jive well with this one
		$fields_names = apply_filters( 'acf/get_field_types', array() );
		unset( $fields_names[__( 'Layout', 'acf' )] );
		unset( $fields_names[__( 'Basic', 'acf' )][ 'validated_field' ] );

		$field_id = str_replace("-temp", "", $field['id'] );
		$field_key = $field['key'];

		// Validate Drafts
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Validate Drafts/Preview?', 'acf_vf' ),
			'instructions'	=> '',
			'type'			=> 'radio',
			'name'			=> 'drafts',
			'prefix'		=> $field['prefix'],
			'choices' 		=> array(
				true  	=> __( 'Yes', 'acf_vf' ),
				false 	=> __( 'No', 'acf_vf' ),
			),
			'layout'		=> 'horizontal',
		));

		// Show a message if drafts will always be validated
		if ( $this->drafts ){
			acf_render_field_setting( $field, array(
				'label'			=> '',
				'instructions'	=> '',
				'type'			=> 'message',
				'message'		=> '<em>'.
					__( 'Warning', 'acf_vf' ).
					': <code>Draft Validation</code> '.
					__( 'has been set to <code>true</code> which overrides field level configurations.', 'acf_vf' ).
					' <a href="'.admin_url('edit.php?post_type=acf-field-group&page=validated-field-settings').'#general" target="validated_field_settings">'.
					__( 'Click here', 'acf_vf' ).
					'</a> '.
					__( 'to update the Validated Field settings.', 'acf_vf' ).
					'</em>',
				'layout'		=> 'horizontal',
			));
		}

		?>
		</td></tr>
		<tr class="acf-field acf-sub_field" data-setting="validated_field" data-name="sub_field">
			<td class="acf-label">
				<label><?php _e( 'Validated Field', 'acf_vf' ); ?></label>
				<p class="description"></p>		
			</td>
			<td class="acf-input">
				<?php
				$atts = array(
					'id' => 'acfcloneindex',
					'class' => "field field_type-{$sub_field['type']}",
					'data-id'	=> $sub_field['id'],
					'data-key'	=> $sub_field['key'],
					'data-type'	=> $sub_field['type'],
				);

				$metas = array(
					'id'			=> $sub_field['id'],
					'key'			=> $sub_field['key'],
					'parent'		=> $sub_field['parent'],
					'save'			=> '',
				);

				?>
				<div <?php echo acf_esc_attr( $atts ); ?>>
					<div class="field-meta acf-hidden">
						<?php 

						// meta		
						foreach( $metas as $k => $v ) {
							acf_hidden_input(array( 'class' => "input-{$k}", 'name' => "{$sub_field['prefix']}[{$k}]", 'value' => $v ));
						}

						?>
					</div>

					<div class="sub-field-settings">			
						<table class="acf-table">
							<tbody>
							<?php 

							if ( ! isset( $sub_field['function'] ) || empty( $sub_field['function'] ) ){
								$sub_field['function'] = 'none';
							}

							// Validated Field Type
							acf_render_field_setting( $sub_field, array(
								'label'			=> __( 'Field Type', 'acf_vf' ),
								'instructions'	=> __( 'The underlying field type that you would like to validate.', 'acf_vf' ),
								'type'			=> 'select',
								'name'			=> 'type',
								'prefix'		=> $sub_field['prefix'],
								'choices' 		=> $fields_names,
								'required'		=> true
							), 'tr' );			

							// Render the Sub Field
							do_action( "acf/render_field_settings/type={$sub_field['type']}", $sub_field );

							?>
							<tr class="field_save acf-field" data-name="conditional_logic" style="display:none;">
								<td class="acf-label"></td>
								<td class="acf-input"></td>
							</tr>
							</tbody>
						</table>
					</div>
				</div>
			</td>
		</tr>
		<?php
		
		// Read only
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Read Only?', 'acf_vf' ),
			'instructions'	=> __( 'When a field is marked read only, it will be visible but uneditable. Read only fields are marked as ', 'acf_vf' ). '"Field Label <i class="fa fa-ban" style="color:red;" title="'. __( 'Read only', 'acf_vf' ) . '"></i>".',
			'type'			=> apply_filters( 'acf_vf/settings_read_only_type', 'radio' ),
			'name'			=> 'read_only',
			'layout'		=> 'horizontal', 
			'prefix'		=> $field['prefix'],
			'choices'		=> apply_filters( 'acf_vf/settings_role_choices', array(
				'no' 	=> __( 'No', 'acf_vf' ),
				'yes'	=> __( 'Yes', 'acf_vf' ),
			) ),
			'class'			=> 'read_only'
		));

		// 3rd party read only settings
		do_action( 'acf_vf/settings_readonly', $field );

		$mask_error = ( !empty( $field['mask'] ) && $sub_field['type'] == 'number' )? 
			'color:red;' : '';

		// Input Mask
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Input mask', 'acf_vf' ),
			'instructions'	=> __( 'Use &#39;a&#39; to match A-Za-z, &#39;9&#39; to match 0-9, and &#39;*&#39; to match any alphanumeric.', 'acf_vf' ) . 
								' <a href="http://digitalbush.com/projects/masked-input-plugin/" target="_new">' . 
								__( 'More info', 'acf_vf' ) . 
								'</a>.<br/><br/><strong style="' . $mask_error . '"><em>' . 
								__( 'Input masking is not compatible with the "number" field type!', 'acf_vf' ) .
								'</em></strong>',
			'type'			=> 'text',
			'name'			=> 'mask',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['mask'],
			'layout'		=> 'horizontal',
			'class'			=> 'input-mask'
		));

		// Input Mask
		acf_render_field_setting( $field, array(
			'label'			=> __('Input Mask: Autoclear', 'acf_vf' ),
			'instructions'	=> __( 'Clear values that do match the input mask, if provided.', 'acf_vf' ),
			'type'			=> 'radio',
			'name'			=> 'mask_autoclear',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['mask_autoclear'],
			'layout'		=> 'horizontal',
			'choices' => array(
				true  	=> __( 'Yes', 'acf_vf' ),
				false 	=> __( 'No', 'acf_vf' ),
			),
			'class'			=> 'mask-settings'
		));

		// Input Mask
		acf_render_field_setting( $field, array(
			'label'			=> __('Input Mask: Placeholder', 'acf_vf' ),
			'instructions'	=> __( 'Use this string or character as a placeholder for the input mask.', 'acf_vf' ),
			'type'			=> 'text',
			'name'			=> 'mask_placeholder',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['mask_placeholder'],
			'class'			=> 'mask-settings'
		));

		// Validation Function
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Validation: Function', 'acf_vf' ),
			'instructions'	=> __( 'How should the field be server side validated?', 'acf_vf' ),
			'type'			=> 'select',
			'name'			=> 'function',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['function'],
			'choices' => array(
				'none'	=> __( 'None', 'acf_vf' ),
				'regex' => __( 'Regular Expression', 'acf_vf' ),
				//'sql'	=> __( 'SQL Query', 'acf_vf' ),
				'php'	=> __( 'PHP Statement', 'acf_vf' ),
			),
			'layout'		=> 'horizontal',
			'optgroup' => true,
			'multiple' => '0',
			'class'			=> 'validated_select validation-function',
		));

		?>
		<tr class="acf-field validation-settings" data-setting="validated_field" data-name="pattern" id="field_option_<?php echo $html_key; ?>_validation">
			<td class="acf-label">
				<label><?php _e( 'Validation: Pattern', 'acf_vf' ); ?></label>
				<p class="description">	
				<small>
				<div class="validation-info">
					<div class='validation-type regex'>
						<?php _e( 'Pattern match the input using', 'acf_vf' ); ?> <a href="http://php.net/manual/en/function.preg-match.php" target="_new">PHP preg_match()</a>.
						<br />
					</div>
					<div class='validation-type php'>
						<ul>
							<li><?php _e( 'Use any PHP code and return true for success or false for failure. If nothing is returned it will evaluate to true.', 'acf_vf' ); ?></li>
							<li><?php _e( 'Available variables', 'acf_vf' ); ?>:
							<ul>
								<li><code>$post_id = $post->ID</code></li>
								<li><code>$post_type = $post->post_type</code></li>
								<li><code>$name = meta_key</code></li>
								<li><code>$value = form value</code></li>
								<li><code>$prev_value = db value</code></li>
								<li><code>$inputs = array(<blockquote>'field'=>?,<br/>'value'=>?,<br/>'prev_value'=>?<br/></blockquote>)</code></li>
								<li><code>&amp;$message = error message</code></li>
							</ul>
							</li>
							<li><?php _e( 'Example', 'acf_vf' ); ?>: 
							<small><code><pre>if ( $value == "123" ){
  return '123 is not valid!';
}</pre></code></small></li>
						</ul>
					</div>
					<div class='validation-type sql'>
						<?php _e( 'SQL', 'acf_vf' ); ?>.
						<br />
					</div>
				</div> 
				</small>
				</p>		
			</td>
			<td class="acf-input">
				<?php

				// Pattern
				acf_render_field( array(
					'label'			=> __( 'Pattern', 'acf_vf' ),
					'instructions'	=> '',
					'type'			=> 'textarea',
					'name'			=> 'pattern',
					'prefix'		=> $field['prefix'],
					'value'			=> $field['pattern'],
					'layout'		=> 'horizontal',
					'class'			=> 'editor',
				));

				?>
				<div id="<?php echo $field_id; ?>-editor" class='ace-editor' style="height:200px;"><?php echo $field['pattern']; ?></div>
			</td>
		</tr>
		<?php

		// Error Message
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Validation: Error Message', 'acf_vf' ),
			'instructions'	=> __( 'The default error message that is returned to the client.', 'acf_vf' ),
			'type'			=> 'text',
			'name'			=> 'message',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['message'],
			'layout'		=> 'horizontal',
			'class'			=> 'validation-settings'
		));

		// Validation Function
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Unique Value?', 'acf_vf' ),
			'instructions'	=> __( "Make sure this value is unique for...", 'acf_vf' ),
			'type'			=> 'select',
			'name'			=> 'unique',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['unique'],
			'choices' 		=> array(
				'non-unique'	=> __( 'Non-Unique Value', 'acf_vf' ),
				'global'		=> __( 'Unique Globally', 'acf_vf' ),
				'post_type'		=> __( 'Unique For Post Type', 'acf_vf' ),
				'post_key'		=> __( 'Unique For Post Type', 'acf_vf' ) . ' + ' . __( 'Field/Meta Key', 'acf_vf' ),
				'this_post'		=> __( 'Unique For Post', 'acf_vf' ),
				'this_post_key'	=> __( 'Unique For Post', 'acf_vf' ) . ' + ' . __( 'Field/Meta Key', 'acf_vf' ),
			),
			'layout'		=> 'horizontal',
			'optgroup' 		=> false,
			'multiple' 		=> '0',
			'class'			=> 'validated_select validation-unique',
		));

		// Unique Status
		$statuses = $this->get_post_statuses();
		$choices = array();
		foreach ( $statuses as $value => $status ) {
			$choices[$value] = $status->label;
		}
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Unique Value: Apply to...?', 'acf_vf' ),
			'instructions'	=> __( "Make sure this value is unique for the checked post statuses.", 'acf_vf' ),
			'type'			=> 'checkbox',
			'name'			=> 'unique_statuses',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['unique_statuses'],
			'choices' 		=> $choices,
			'class'			=> 'unique_statuses'
		));
	}

	/*
	*  render_field()
	*
	*  Create the HTML interface for your field
	*
	*  @param	$field (array) the $field being rendered
	*
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field (array) the $field being edited
	*  @return	n/a
	*/
	
	function render_field( $field ) {
		global $post, $pagenow;

		// set up field properties
		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );

		// determine if this is a new post or an edit
		$is_new = $pagenow=='post-new.php';

		// filter to determine if this field should be rendered or not
		if ( false === $field['render_field'] ): 
			// if it is not rendered, hide the label with CSS
		?>
			<style>div[data-key="<?php echo $sub_field['key']; ?>"] { display: none; }</style>
		<?php
		// if it is shown either render it normally or as read-only
		else : 
			?>
			<div class="validated-field">
				<?php
				// for read only we need to buffer the output so that we can modify it
				if ( $this->check_value( 'yes', $field['read_only'] ) ){
					?>
					<p>
					<?php 

					// Buffer output
					ob_start();

					// Render the subfield
					echo do_action( 'acf/render_field/type='.$sub_field['type'], $sub_field );

					// Try to make the field readonly
					$contents = ob_get_contents();
					$contents = preg_replace("~<(input|textarea|select)~", "<\${1} disabled=true read_only", $contents );
					$contents = preg_replace("~acf-hidden~", "acf-hidden acf-vf-readonly", $contents );

					// Stop buffering
					ob_end_clean();

					// Return our (hopefully) readonly input.
					echo $contents;

					?>
					</p>
					<?php
				} else {
					// wrapper for other fields, especially relationship
					echo "<div class='acf-field acf-field-{$sub_field['type']} field_type-{$sub_field['type']}' data-type='{$sub_field['type']}' data-key='{$sub_field['key']}'><div class='acf-input'>";
					echo do_action( 'acf/render_field/type='.$sub_field['type'], $sub_field );
					echo "</div></div>";
				}
				?>
			</div>
			<?php
			// check to see if we need to mask the input
			if ( ! empty( $field['mask'] ) && ( $is_new || $this->check_value( 'no', $field['read_only'] ) ) ) {
				// we have to use $sub_field['key'] since new repeater fields don't have a unique ID
				?>
				<script type="text/javascript">
					(function($){
						$(function(){
							$("div[data-key='<?php echo $sub_field['key']; ?>'] input").each( function(){
								$(this).mask("<?php echo $field['mask']?>", {
									autoclear: <?php echo isset( $field['mask_autoclear'] ) && empty( $field['mask_autoclear'] )? 'false' : 'true'; ?>,
									placeholder: '<?php echo isset( $field['mask_placeholder'] )? $field['mask_placeholder'] : '_'; ?>',
									completed: function(){ console.log(jQuery(this)); }
								});
							});
						});
					})(jQuery);
				</script>
				<?php
			}
		endif;
	}

	/*
	*  input_admin_enqueue_scripts()
	*
	*  This action is called in the admin_enqueue_scripts action on the edit screen where your field is created.
	*  Use this action to add css + javascript to assist your create_field() action.
	*
	*  $info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_enqueue_scripts
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function input_admin_enqueue_scripts(){
		// register acf scripts
		$min = ( ! $this->debug )? '.min' : '';
		wp_register_script( 'jquery-masking', plugins_url( "js/jquery.maskedinput{$min}.js", __FILE__ ), array( 'jquery' ), ACF_VF_VERSION, true );
		
		// enqueue scripts
		wp_enqueue_script( array(
			'jquery',
			'jquery-ui-core',
			'jquery-ui-tabs',
			'jquery-masking'
		));
	}

	/*
	*  input_admin_footer()
	*
	*  This action is called in the wp_head/admin_head action on the edit screen where your field is created.
	*  Use this action to add css and javascript to assist your create_field() action.
	*
	*  @type	action (admin_footer)
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	n/a
	*  @return	n/a
	*/
	function input_admin_footer(){
		wp_deregister_style( 'font-awesome' );
		wp_enqueue_style( 'font-awesome', plugins_url( 'css/font-awesome/css/font-awesome.min.css', __FILE__ ), array(), '4.2.0' ); 
		wp_enqueue_style( 'acf-validated_field', plugins_url( 'css/input.css', __FILE__ ), array(), ACF_VF_VERSION ); 
	}

	/*
	*  field_group_admin_enqueue_scripts()
	*
	*  This action is called in the admin_enqueue_scripts action on the edit screen where your field is edited.
	*  Use this action to add css + javascript to assist your create_field_options() action.
	*
	*  $info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_enqueue_scripts
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function field_group_admin_enqueue_scripts(){
		wp_deregister_style( 'font-awesome' );
		wp_enqueue_style( 'font-awesome', plugins_url( 'css/font-awesome/css/font-awesome.min.css', __FILE__ ), array(), '4.2.0' ); 
		
		wp_enqueue_script( 'ace-editor', plugins_url( 'js/ace/ace.js', __FILE__ ), array(), '1.1.7' );
		wp_enqueue_script( 'ace-ext-language_tools', plugins_url( 'js/ace/ext-language_tools.js', __FILE__ ), array(), '1.1.7' );
	}

	/*
	*  load_value()
	*
	*  This filter is appied to the $value after it is loaded from the db
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$value - the value found in the database
	*  @param	$post_id - the $post_id from which the value was loaded from
	*  @param	$field - the field array holding all the field options
	*
	*  @return	$value - the value to be saved in te database
	*/
	function load_value( $value, $post_id, $field ){
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		return apply_filters( 'acf/load_value/type='.$sub_field['type'], $value, $post_id, $sub_field );
	}

	/*
	*  update_value()
	*
	*  This filter is appied to the $value before it is updated in the db
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$value - the value which will be saved in the database
	*  @param	$post_id - the $post_id of which the value will be saved
	*  @param	$field - the field array holding all the field options
	*
	*  @return	$value - the modified value
	*/
	function update_value( $value, $post_id, $field ){
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		return apply_filters( 'acf/update_value/type='.$sub_field['type'], $value, $post_id, $sub_field );
	}

	/*
	*  format_value()
	*
	*  This filter is appied to the $value after it is loaded from the db and before it is passed to the create_field action
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$value	- the value which was loaded from the database
	*  @param	$post_id - the $post_id from which the value was loaded
	*  @param	$field	- the field array holding all the field options
	*
	*  @return	$value	- the modified value
	*/
	function format_value( $value, $post_id, $field ){
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		return apply_filters( 'acf/format_value/type='.$sub_field['type'], $value, $post_id, $sub_field );
	}

	/*
	*  format_value_for_api()
	*
	*  This filter is appied to the $value after it is loaded from the db and before it is passed back to the api functions such as the_field
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$value	- the value which was loaded from the database
	*  @param	$post_id - the $post_id from which the value was loaded
	*  @param	$field	- the field array holding all the field options
	*
	*  @return	$value	- the modified value
	*/
	function format_value_for_api( $value, $post_id, $field ){
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		return apply_filters( 'acf/format_value_for_api/type='.$sub_field['type'], $value, $post_id, $sub_field );
	}

	/*
	*  load_field()
	*
	*  This filter is appied to the $field after it is loaded from the database
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field - the field array holding all the field options
	*
	*  @return	$field - the field array holding all the field options
	*/
	function load_field( $field ){
		global $post, $pagenow;

		// determine if this is a new post or an edit
		$is_new = $pagenow=='post-new.php';
		$post_type = get_post_type( $post );

		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );
		$sub_field = apply_filters( 'acf/load_field/type='.$sub_field['type'], $sub_field );

		// The relationship field gets settings from the sub_field so we need to return it since it effectively displays through this method.
		if ( 'relationship' == $sub_field['type'] && isset( $_POST['action'] ) && $_POST['action'] == 'acf/fields/relationship/query' ){
			// the name is the key, so use _name
			$sub_field['name'] = $sub_field['_name'];
			return $sub_field;
		}

		$field['sub_field'] = $sub_field;

		$field['render_field'] = apply_filters( 'acf_vf/render_field', true, $field, $is_new );
		if ( !$is_new && $post_type != 'acf-field-group' && $field['render_field'] === 'readonly' ){
			$field['read_only'] = 'yes';
		}

		// this can be off if the permissions plugin is disabled
		$read_only_type = apply_filters( 'acf_vf/settings_read_only_type', 'radio' );
		if ( $read_only_type == 'radio' && is_array( $field['read_only'] ) ){
			// default to read only for everyone unless it's off (better security)
			if ( $field['read_only'][0] == 'no' ){
				$field['read_only'] = 'no';
			} else {
				$field['read_only'] = 'yes';
			}
		}

		// Show icons for read-only fields
		if ( $this->check_value( 'yes', $field['read_only'] ) && get_post_type() != 'acf-field-group' ){
			$field['label'] .= ' (<i class="fa fa-ban" style="color:red;" title="'. __( 'Read only', 'acf_vf' ) . '"></i> <small><em>Read Only</em></small>)';
		}

		// Just avoid using any type of quotes in the db values
		$field['pattern'] = str_replace( "%%squot%%", "'", $field['pattern'] );
		$field['pattern'] = str_replace( "%%dquot%%", '"', $field['pattern'] );

		return $field;
	}

	/*
	*  update_field()
	*
	*  This filter is appied to the $field before it is saved to the database
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field - the field array holding all the field options
	*
	*  @return	$field - the modified field
	*/
	function update_field( $field ){
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		$sub_field = apply_filters( 'acf/update_field/type='.$sub_field['type'], $sub_field );
		$field['sub_field'] = $sub_field;

		// Just avoid using any type of quotes in the db values
		$field['pattern'] = str_replace( "'", "%%squot%%", $field['pattern'] );
		$field['pattern'] = str_replace( '"', "%%dquot%%", $field['pattern'] );

		return $field;
	}

	/**
	* is_edit_page 
	* function to check if the current page is a post edit page
	* 
	* @author Ohad Raz <admin@bainternet.info>
	* 
	* @param  string  $new_edit what page to check for accepts new - new post page ,edit - edit post page, null for either
	* @return boolean
	*/
	function is_edit_page( $new_edit=null ){
		global $pagenow;

		//make sure we are on the backend
		if ( !is_admin() ) return false;

		// check specific page based on edit type
		if ( $new_edit == 'new' ) //check for new post page
			return in_array( $pagenow, array( 'post-new.php' ) );
		elseif ( $new_edit == 'edit' )
			return in_array( $pagenow, array( 'post.php',  ) );
		else //check for either new or edit
			return in_array( $pagenow, array( 'post.php', 'post-new.php' ) );
	}
}

new acf_field_validated_field();
endif;
