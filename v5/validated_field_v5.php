<?php
if ( class_exists( 'acf_field_validated_field' ) && !class_exists( 'acf_field_validated_field_v5' ) ):
class acf_field_validated_field_v5 extends acf_field_validated_field {
	// vars
	var $slug,
		$config,
		$settings,					// will hold info such as dir / path
		$defaults,					// will hold default field options
		$sub_defaults,				// will hold default sub field options
		$debug,						// if true, don't use minified and confirm form submit					
		$drafts,
		$is_frontend_css,
		$link_to_tab,
		$link_to_field_group;

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
		$this->slug 			= 'acf-validated-field';
		$this->name				= 'validated_field';
		$this->label 			= __( 'Validated Field', 'acf_vf' );
		$this->category			= __( 'Basic', 'acf' );
		$this->drafts			= $this->option_value( 'acf_vf_drafts' );
		$this->is_frontend_css 	= $this->option_value( 'acf_vf_is_frontend_css' );
		$this->debug 			= $this->option_value( 'acf_vf_debug' );
		$this->link_to_tab 		= $this->option_value( 'acf_vf_link_to_tab' );
		$this->link_to_field_group = $this->option_value( 'acf_vf_link_to_field_group_editor' );

		$this->defaults = array(
			'read_only' 		=> 'no',
			'hidden' 			=> 'no',
			'mask'				=> '',
			'mask_autoclear' 	=> true,
			'mask_placeholder' 	=> '_',
			'function'			=> 'none',
			'pattern'			=> '',
			'message'			=>  __( 'Validation failed.', 'acf_vf' ),
			'unique'			=> 'non-unique',
			'unique_statuses' 	=> apply_filters( 'acf_vf/unique_statuses', 
				array( 'publish', 'future', 'draft', 'pending' ) 
			),
			'drafts'			=> false,
			'render_field' 		=> true
		);

		$this->sub_defaults = array(
			'type'				=> '',
			'key'				=> '',
			'name'				=> '',
			'_name'				=> '',
			'id'				=> '',
			'value'				=> '',
			'field_group' 		=> '',
			'readonly' 			=> '',
			'disabled' 			=> '',
			'message'			=> ''
		);

		$this->input_defaults = array(
			'id'				=> '',
			'value'				=> '',
		);

		// do not delete!
		parent::__construct();

		// settings
		$this->settings = array(
			'path'		=> apply_filters( 'acf/helpers/get_path', __FILE__ ),
			'dir'		=> apply_filters( 'acf/helpers/get_dir', __FILE__ ),
			'version'	=> ACF_VF_VERSION,
		);

		// COMMON, if needed

		// override the default ajax actions to provide our own messages since they aren't filtered
		add_action( 'init', array( $this, 'add_acf_ajax_validation' ) );

		// validate validated_fields
		add_filter( "acf/validate_value/type=validated_field", array( $this, 'validate_field' ), 10, 4 );

		// validate for all
		add_filter( "acf/update_value/type=validated_field", array( $this, 'validate_update_value' ), 10, 3 );


		add_filter( 'acf/input/admin_l10n', function( $admin_l10n ){
			$admin_l10n['validation_failed_1'] .= '.';
			$admin_l10n['validation_failed_2'] .= '.';
			return $admin_l10n;
		});

		add_action( 'acf/input/admin_head', function( ){
		}, 1, 2 );

		// sets the post ID and frontend variable to acf form
		add_action( 'acf/input/admin_head', array( $this, 'set_post_id_to_acf_form' ) );

		// FRONT END
		
		// prevent CSS from loading on the front-end
		if ( !is_admin() && !$this->is_frontend_css ){
			add_action( 'acf/input/admin_enqueue_scripts',  array( $this, 'remove_acf_form_style' ) );
		}

		add_action( 'acf/input/admin_head', array( $this, 'acf_vf_head' ) );

		// add the post_ID and frontend to the acf[] form using jQuery
		add_action( 'acf/input/admin_head', array( $this, 'set_post_id_to_acf_form' ) );

		// add the post_ID to the acf[] form
		add_action( 'edit_form_after_editor', array( $this, 'edit_form_after_editor' ) );

		// add the user_ID to the acf[] form
		add_action( 'personal_options', array( $this, 'personal_options' ) );

		// make sure plugins have loaded so we can modify the options
		add_action( 'admin_menu', array( $this, 'add_options_page' ) );

		add_filter( 'acf/prepare_field_for_export', array( $this, 'prepare_field_for_export' ) );	

		// bug fix for acf with backslashes in the content.
		add_filter( 'content_save_pre', array( $this, 'fix_post_content' ) );
		add_filter( 'acf/get_valid_field', array( $this, 'fix_upgrade' ) );
	}

	function acf_vf_head(){
		global $is_validate_drafts;


		if ( $this->link_to_tab ){
			$min = $this->get_min();
			wp_enqueue_script( 'acf-validated-field-link-to-tab', plugins_url( "../common/js/link-to-tab{$min}.js", __FILE__ ), array( 'jquery' ), ACF_VF_VERSION );
		}

		// Do we have any fields requesting draft validation
		$is_validate_drafts = $this->drafts;
		if ( !$is_validate_drafts ){
			$fields = get_fields();

			if( $fields ){
				foreach( $fields as $field_name => $value )	{
					$field = get_field_object($field_name, false, array('load_value' => false));
					if ( $field['type'] == 'validated_field' && $field['drafts'] ){
						$is_validate_drafts = true;
						break;
					}
				}
			}
		}

		?>
		<script>
		(function($){
			if ( typeof acf != 'undefined' ){
				acf.version = '<?php echo acf()->settings['version']; ?>';
				acf.add_action('ready', function(){
					<?php if ( $is_validate_drafts ): ?>
						$(document).off('click', '#save-post');
						$(document).on( 'click', '#save-post', function(e){
							e.$el = $(this);
							acf.validation.click_publish(e);
						});

					<?php endif; ?>
					// intercept click to add post_status to the form
					$(document).off('click', 'input[type="submit"]');
					$(document).on( 'click', 'input[type="submit"]', function(e){
						e.$el = $(this);

						$post_status = get_post_status();
						
						// if we click publish, then set to publish
						if ( e.$el.val() == '<?php _e( 'Publish' ); ?>' ){
							$post_status.val('publish');
						} else {
							// otherwise set to the selected status
							$post_status.val($('select#post_status').val());
							// if it's published but we are setting to something else just save it
							if ( $post_status.val() != 'publish' && $('#original_post_status').val() == 'publish' ){
								acf.validation.click_ignore(e);
								return
							}
						}
						
						// with our elements inserted, publish away
						acf.validation.click_publish(e);
					});

					$(document).on( 'click', '.acf_vf_conflict', function(e){
						location.href = $(this).attr('href');
					});

					// get the acf_vf post status element, or create it if needed
					function get_post_status(){
						$form = $('form#post');
						$post_status = $form.find('input[name="acf[acf_vf][post_status]"]');
						if ( !$post_status.length ){
							$post_status = $('<input type="hidden" name="acf[acf_vf][post_status]"/>');
							$form.append($post_status);
						}
						return $post_status;
					}
				});
			}
		})(jQuery);
		</script>
		<?php
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
		global $acf_vf;
		?>

		<script type="text/javascript">
		(function($){
			$(document).ready(function(){
				$form = $('form.acf-form, form#post');
				if ( $form.length ){
					$form.append('<input type="hidden" name="acf[acf_vf][post_ID]" value="' + acf.o.post_id + '"/>');
					$form.append('<input type="hidden" name="acf[acf_vf][is_frontend]" value="<?php echo !is_admin()? '1' : '0' ?>"/>');
				}
			});
		})(jQuery);
		</script>

		<?php
	}

	function edit_form_after_editor( $post ){
		echo "<input type='hidden' name='acf[acf_vf][post_ID]' value='{$post->ID}'/>";
	}

	function personal_options( $user ){
		// for users the ID is prepending with "user_"
		echo "<input type='hidden' name='acf[acf_vf][post_ID]' value='user_{$user->ID}'/>";
	}

	function prepare_field_for_export( $field ){
		if ( isset( $field['sub_field'] ) ){	
			remove_filter( 'acf/prepare_field_for_export', array( $this, 'prepare_field_for_export' ) );
			$field['sub_field'] = acf_prepare_field_for_export( $field['sub_field'] );
			add_filter( 'acf/prepare_field_for_export', array( $this, 'prepare_field_for_export' ) );
		}
		return $field;
	}

	function validate_update_value( $value, $post_id, $field ){
		if ( function_exists( 'get_the_field' ) && !acf_validate_value( $value, $field, '' ) ){
			// if it's not value set the new value to the current value
			$value = get_the_field( $field['name'], $post_id );
			// acf_get_validation_errors() if we want to do something with it
		}
		return $value;
	}

	function option_value( $key ){
		return get_field( $key, 'options' );
	}

	function add_options_page(){
		$page = acf_add_options_page( apply_filters( 'acf_vf/add_options_page', array(
			'page_title' 	=> sprintf( __( 'Validated Field Settings for ACF %1$d', 'acf_vf' ), 5 ),
			'menu_title' 	=> sprintf( __( 'Validated Field Settings %1$d', 'acf_vf' ), 5 ),
			'menu_slug' 	=> 'validated-field-settings',
			'parent_slug'	=> 'edit.php?post_type=acf-field-group',
			'capability' 	=> 'edit_posts',
			'redirect' 		=> false, 
			'autoload'		=> true,
		) ) );

		add_filter( 'acf_vf/options_field_group', array( $this, 'field_group_location' ) );
		acf_add_local_field_group( acf_vf_options::get_field_group() );
	}

	function field_group_location( $field_group ){
		$field_group['location'] = array(
			array(
				array(
					'param' => 'options_page',
					'operator' => '==',
					'value' => 'validated-field-settings',
				),
			),
		);
		return $field_group;
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
		$sub_field['_name'] = $field['name'];
		$sub_field['name'] = $sub_field['_input'];
		$sub_field['id'] = str_replace( '-acfcloneindex', '', str_replace( ']', '', str_replace( '[', '-', $sub_field['_input'] ) ) );

		// mask the sub field as the parent by giving it the same key values
		foreach( array( 'key', 'name', '_name', 'id', 'value', 'field_group' ) as $key ){
		//	$sub_field[$key] = isset( $field[$key] )? $field[$key] : '';
		}

		// make sure all the defaults are set
		$field['sub_field'] = array_merge( $this->sub_defaults, $sub_field );
		foreach( $this->defaults as $key => $default ){
			if ( !isset( $this->sub_defaults[$key]))
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
		if ( !isset( $_POST['_acfnonce'] ) ) {
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
		$json['message'] .= '. ' . sprintf( _n( '1 field below is invalid.', '%s fields below are invalid.', $i, 'acf_vf' ), $i ) 
						 . ' ' 
						 . __( 'Please check your values and submit again.', 'acf_vf' );
		
		die( json_encode( $json ) );
	}

	function validate_field( $valid, $value, $field, $input ) {
		global $acf_vf_indexes, $acf_vf_request;

		// if the validation has failed previously no need to proceed
		if ( !$valid )
			return $valid;

		// we need values in this array
		if ( !isset( $acf_vf_request ) ){		
			if ( isset( $_REQUEST['acf']['acf_vf'] ) ){
				// Grab the keys we added and remove them from the $_REQUEST
				$acf_vf_request = $_REQUEST['acf']['acf_vf'];
				unset( $_REQUEST['acf']['acf_vf'] );
			} elseif ( null !== ( $post = get_post() ) ){
				// This look slike a post, get the ID and current status
				$acf_vf_request = array(
					'post_ID' => $post->ID,
					'post_status' => $post->post_status,
				);
			} else {
				// This might be a user update
				global $profileuser;
				if ( !empty( $profileuser ) ){
					$acf_vf_request = array(
						'post_ID' => 'user_' . $profileuser->ID
					);
				}
			}
		}
		
		// the wrapped field
		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );
		
		if ( $field['render_field'] === false || $field['render_field'] === "readonly" ){
			return $valid;
		}

		if ( $field['required'] && empty( $value ) ){
			return $valid;									// let the required field handle it
		}

		// The new post status we stuck into the ACF request
		$post_status = isset( $acf_vf_request['post_status'] )?
			$acf_vf_request['post_status'] :
			false;

		// we aren't publishing and we don't want to validate drafts globally or for this field
		if ( !empty( $post_status ) && $post_status != 'publish' && !$this->drafts && !$field['drafts'] ){
			return $valid;									
		}

		// get ID of the submit post or cpt, allow null for options page
		$post_id = isset( $acf_vf_request['post_ID'] )? 
			$acf_vf_request['post_ID'] : 
			null;

		$is_frontend = isset( $acf_vf_request['is_frontend'] )? 
			(boolean) $acf_vf_request['is_frontend'] : 
			false;

		// the type of the submitted post
		$post_type = get_post_type( $post_id );				

		$parent_field = !empty( $field['parent'] ) ? 
			acf_get_field( $field['parent'] ) :
			false;

		$is_repeater = isset( $parent_field ) && 'repeater' == $parent_field['type'];
		$is_flex = isset( $parent_field ) && 'flexible_content' == $parent_field['type'];

		// track the field index based on the field name
		if ( !isset( $acf_vf_indexes ) || !is_array( $acf_vf_indexes ) ){
			$acf_vf_indexes = array();
		}
		// initialize or increment the index
		$index = isset( $acf_vf_indexes[$field['name']] ) ?
			$acf_vf_indexes[$field['name']]+1 :
			0;

		// cache the current value
		$acf_vf_indexes[$field['name']] = $index;

		// treat arrays as strings for the purposes of our string based validation
		if ( is_array( $value ) ){
			$str_value = implode( ',', $value );
		}

		$function = $field['function'];						// what type of validation?
		$pattern = $field['pattern'];						// string to use for validation
		$message = $field['message'];						// failure message to return to the UI
		if ( !empty( $function ) && !empty( $pattern ) ){
			switch ( $function ){							// only run these checks if we have a pattern
				case 'regex':								// check for any matches to the regular expression
					$pattern_fltr = '/' . str_replace( "/", "\/", $pattern ) . '/';
					if ( !preg_match( $pattern_fltr, $value ) ){
						$valid = false;						// return false if there are no matches
					}
					break;
				case 'sql':									// todo: sql checks?
					break;
				case 'php':									// this code is a little tricky, one bad eval() can break the lot. needs a nonce.
					//$this_key = $field['name'];
					//if ( $is_repeater ) $this_key .= '_' . $index . '_' . $sub_field['name'];

					$this_key = $this->get_field_name( $field );

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

					// it gets tricky but we are trying to account for an capture bad php code where possible
					$pattern = addcslashes( trim( $pattern ), '$' );
					if ( substr( $pattern, -1 ) != ';' ) $pattern.= ';';

					// not yet saved to the database, so this is the previous value still
					$prev_value = get_post_meta( $post_id, $this_key, true);

					// unique function for this key
					$function_name = 'validate_' . $field['key'] . '_function';
					
					// this must be left aligned as it contains an inner HEREDOC
					$php = <<<PHP
						if ( !function_exists( '$function_name' ) ):
						function $function_name( \$args, &\$message ){
							extract( \$args );
							try {
								\$code = <<<INNERPHP
								$pattern return true;

INNERPHP;
// ^^^ no whitespace to the left!
								return @eval( \$code );
							} catch ( Exception \$e ){
								return "Error: ".\$e->getMessage();
							}
						}
						endif; // function_exists
						\$valid = $function_name( 
							array( 
								'post_id'=>\$post_id, 
								'post_type'=>\$post_type, 
								'name'=>\$this_key, 	// 1.x
								'meta_key'=>\$this_key, // 2.x+
								'value'=>\$value, 
								'prev_value'=>\$prev_value, 
								'inputs'=>\$input_fields 
							), 
							\$message 
						);
PHP;

					if ( true !== eval( $php ) ){			// run the eval() in the eval()
						$error = error_get_last();			// get the error from the eval() on failure
						// check to see if this is our error or not.
						if ( strpos( $error['file'], basename( __FILE__ ) ) && strpos( $error['file'], "eval()'d code" ) ){
							preg_match( '/eval\\(\\)\'d code\\((\d+)\\)/', $error['file'], $matches );
							return sprintf( __( 'PHP Error: %1$s, line %2$d..', 'acf_vf' ), $error['message'], $matches[1] );
						} 
					}
					// if a string is returned, return it as the error.
					if ( is_string( $valid ) ){
						return stripslashes( $valid );
					} elseif ( !$valid ){
						return $message;
					}
					break;
			}
		} elseif ( !empty( $function ) && $function != 'none' ) {
			return __( "This field's validation is not properly configured.", 'acf_vf' );
		}
			
		$unique = $field['unique'];
		$field_is_unique = !empty( $value ) && !empty( $unique ) && $unique != 'non-unique';

		// validate the submitted values since there might be dupes in the form submit that aren't yet in the database
		if ( $field_is_unique ){
			$value_instances = 0;
			// sort the value if it's an array before we compare
			$_value = $this->maybe_sort_value( $value, $field );		
			switch ( $unique ){
				case 'global';
				case 'post_type':
				case 'this_post':
					// no duplicates at all allowed, check the submitted values
					foreach ( $_REQUEST['acf'] as $key => $form_field ){
						if ( is_array( $form_field ) ){
							foreach( $form_field as $row ){
								if ( is_array( $row ) ){
									foreach( $row as $field_key => $field_value ){
										// sort the value if it's an array before we compare
										$_field_value = $this->maybe_sort_value( $field_value, $field );
										if ( $_field_value == $_value && ++$value_instances > 1 ){
											return $this->get_unique_form_error( $unique, $field, $value );
										}
									}
								}								
							}
						} else {
							if ( $form_field == $_value && ++$value_instances > 1 ){
								return $this->get_unique_form_error( $unique, $field, $value );
							}	
						}
					}
					break;
				case 'post_key':
				case 'this_post_key':
					// only check the key for a repeater/flex for duplicate submissions
					if ( $is_repeater || $is_flex ){
						foreach ( $_REQUEST['acf'] as $key => $form_value ){
							if ( is_array( $form_value ) ){	
								foreach( $form_value as $id => $row ){
									if ( is_array( $row ) ){
										foreach( $row as $field_key => $field_value ){
											// sort the value if it's an array before we compare
											$_field_value = $this->maybe_sort_value( $field_value, $field );
											if ( $field_key == $field['key'] && $_field_value == $_value ){
												if ( ++$value_instances > 1 ){
													return $this->get_unique_form_error( $unique, $field, $value );
												}
											}
										}
									}
								}
							}
						}
					}
					break;
			}
			// Run the SQL queries to see if there are duplicate values
			if ( true !== ( $message = $this->is_value_unique( $unique, $post_id, $field, $parent_field, $index, $is_repeater, $is_flex, $is_frontend, $value ) ) ){
				return $message;
			}
		}

		return $valid;
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
		if ( $this->drafts ){

		} else {

		}
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Validate Drafts/Preview?', 'acf_vf' ),
			// Show a message if drafts will always be validated
			'message'	=> $this->drafts ?
				sprintf( __( '<em><a href="%1$s"><code>Validated Field Settings: Draft Validation</code></a> has been set to <code>true</code> which overrides field level configurations.</em>', 'acf_vf' ), admin_url('edit.php?post_type=acf-field-group&page=validated-field-settings')."#general" ) :
				'',
			'type'			=> $this->drafts ? 'message' : 'radio',
			'name'			=> 'drafts',
			'prefix'		=> $field['prefix'],
			'choices' 		=> array(
				true  	=> __( 'Yes', 'acf_vf' ),
				false 	=> __( 'No', 'acf_vf' ),
			),
			'layout'		=> 'horizontal',
			'class'			=> 'drafts'
		));
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

							if ( !isset( $sub_field['function'] ) || empty( $sub_field['function'] ) ){
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
								'required'		=> true,
								'class'			=> 'field-type'
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
			'instructions'	=> sprintf( __( 'When a field is marked read only, it will be visible but uneditable. Read only fields are marked as "Field Label %1$s".', 'acf_vf' ), sprintf( '<i class="fa fa-ban" style="color:red;" title="%1$s"></i>', __( 'Read only', 'acf_vf' ) ) ),
			'type'			=> apply_filters( 'acf_vf/create_field/read_only/type', 'radio' ),
			'name'			=> 'read_only',
			'layout'		=> 'horizontal', 
			'prefix'		=> $field['prefix'],
			'choices'		=> apply_filters( 'acf_vf/create_field/read_only/choices', array(
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
			'instructions'	=> sprintf( __( 'Use &#39;a&#39; to match A-Za-z, &#39;9&#39; to match 0-9, and &#39;*&#39; to match any alphanumeric. <a href="%1$s" target="_new">More info</a>.', 'acf_vf'), 'http://digitalbush.com/projects/masked-input-plugin/' ),
			'type'			=> 'text',
			'name'			=> 'mask',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['mask'],
			'layout'		=> 'horizontal',
			'class'			=> 'input-mask'
		));

		// Input Mask
		acf_render_field_setting( $field, array(
			'label'			=> __( 'Input Mask: Autoclear', 'acf_vf' ),
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
			'label'			=> __( 'Input Mask: Placeholder', 'acf_vf' ),
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
								<li><code>$meta_key = meta_key</code></li>
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
				'post_type'		=> __( 'Unique For Post Type/User/Option', 'acf_vf' ),
				'post_key'		=> __( 'Unique For Post Type/User/Option + Field/Meta Key', 'acf_vf' ),
				'this_post'		=> __( 'Unique For Post/User', 'acf_vf' ),
				'this_post_key'	=> __( 'Unique For Post/User + Field/Meta Key', 'acf_vf' ),
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
		global $pagenow;

		// set up field properties
		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );

		// determine if this is a new post or an edit
		$is_new = $pagenow=='post-new.php';

		// filter to determine if this field should be rendered or not
		if ( false === $field['render_field'] ): 
			// if it is not rendered, hide the label with CSS
		?>
			<style>[data-key="<?php echo $sub_field['key']; ?>"] { display: none; }</style>
		<?php
		// if it is shown either render it normally or as read-only
		else : 
			?>
			<div class="validated-field">
				<?php
				// wrapper for other fields, especially relationship
				$html = <<<HTML
					<div data-key='{$sub_field['key']}'
						 data-type='{$sub_field['type']}' 
						 class="acf-field acf-field-{$sub_field['type']} field_type-{$sub_field['type']}" >
					<div class="acf-input">
HTML;
				// Buffer output
				ob_start();

				// Render the subfield
				do_action( 'acf/render_field/type='.$sub_field['type'], $sub_field );
				$contents = ob_get_contents();

				// for read only we need to buffer the output so that we can modify it
				if ( $this->check_value( 'yes', $field['read_only'] ) ){
					// Try to make the field readonly
					$contents = preg_replace("~<(input|textarea|select)~", "<\${1} disabled=true read_only", $contents );
					$contents = preg_replace("~(acf-hidden|insert-media add_media|wp-switch-editor)~", "\${1} disabled acf-vf-readonly", $contents );
				}

				// Add our (maybe) readonly input.
				$html .= $contents;

				// Stop buffering
				ob_end_clean();

				$html .= "</div></div>";

				echo $html;
				?>
			</div>
			<?php
			// check to see if we need to mask the input
			if ( !empty( $field['mask'] ) && ( $is_new || $this->check_value( 'no', $field['read_only'] ) ) ) {
				// we have to use $sub_field['key'] since new repeater fields don't have a unique ID
				?>
				<script type="text/javascript">
					(function($){
						$(function(){
							$("div[data-key='<?php echo $sub_field['key']; ?>'] input").each( function(){
								$(this).mask("<?php echo $field['mask']?>", {
									autoclear: <?php echo isset( $field['mask_autoclear'] ) && empty( $field['mask_autoclear'] )? 'false' : 'true'; ?>,
									placeholder: '<?php echo isset( $field['mask_placeholder'] )? $field['mask_placeholder'] : '_'; ?>',
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
		$min = $this->get_min();

		wp_register_script( 'jquery-masking', plugins_url( "../common/js/jquery.maskedinput{$min}.js", __FILE__ ), array( 'jquery' ), ACF_VF_VERSION, true );

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
		$min = $this->get_min();
		wp_deregister_style( 'font-awesome' );
		wp_enqueue_style( 'font-awesome', plugins_url( "../common/css/font-awesome/css/font-awesome{$min}.css", __FILE__ ), array(), '4.4.0' ); 
		wp_enqueue_style( 'acf-validated_field', plugins_url( '../common/css/input.css', __FILE__ ), array(), ACF_VF_VERSION ); 
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
		global $acf;

		// Use minified unless debug is on
		$min = $this->get_min();

		wp_deregister_style( 'font-awesome' );
		wp_enqueue_style( 'font-awesome', plugins_url( "../common/css/font-awesome/css/font-awesome{$min}.css", __FILE__ ), array(), '4.4.0', true ); 
		
		wp_enqueue_script( 'ace-editor', plugins_url( "../common/js/ace{$min}/ace.js", __FILE__ ), array(), '1.2' );
		wp_enqueue_script( 'ace-ext-language_tools', plugins_url( "../common/js/ace{$min}/ext-language_tools.js", __FILE__ ), array(), '1.2' );

		if ( $this->link_to_field_group ){
			wp_enqueue_script( 'acf-validated-field-link-to-field-group', plugins_url( "../common/js/link-to-field-group{$min}.js", __FILE__ ), array( 'jquery', 'acf-field-group' ), ACF_VF_VERSION, true );
		}

		wp_register_script( 'acf-validated-field-admin', plugins_url( "../common/js/admin{$min}.js", __FILE__ ), array( 'jquery', 'acf-field-group', 'ace-editor' ), ACF_VF_VERSION );	
		wp_enqueue_style( 'acf-validated-field-admin', plugins_url( "../common/css/admin.css", __FILE__ ), array(), ACF_VF_VERSION );	
		wp_enqueue_script( array(
			'jquery',
			'acf-validated-field-admin',
		));	
		if ( version_compare( $acf->settings['version'], '5.2.6', '<' ) ){
			wp_enqueue_script( 'acf-validated-field-group', plugins_url( "../common/js/field-group{$min}.js", __FILE__ ), array( 'jquery', 'acf-field-group' ), ACF_VF_VERSION );
		}

		add_action( is_admin()? 'admin_head' : 'wp_head', array( $this, 'acf_vf_head' ) );
	}
}
global $acf_vf;
$acf_vf = new acf_field_validated_field_v5();
endif;
