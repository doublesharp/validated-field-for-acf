<?php
if ( class_exists( 'acf_field_validated_field' ) && !class_exists( 'acf_field_validated_field_v4' ) ):
class acf_field_validated_field_v4 extends acf_field_validated_field {
	// vars
	var $slug,
		$config,
		$settings,					// will hold info such as dir / path
		$defaults,					// will hold default field options
		$sub_defaults,				// will hold default sub field options
		$debug,						// if true, don't use minified and confirm form submit					
		$drafts,
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
		$this->slug 	= 'acf-validated-field';
		$this->strbool 	= array( 'true' => true, 'false' => false );
		$this->name		= 'validated_field';
		$this->label 	= __( 'Validated Field', 'acf_vf' );
		$this->category	= __( 'Basic', 'acf' );
		$this->drafts	= $this->option_value( 'acf_vf_drafts' );
		$this->frontend_css = $this->option_value( 'acf_vf_frontend_css' );
		$this->debug 	= $this->option_value( 'acf_vf_debug' );
		$this->link_to_tab = $this->option_value( 'acf_vf_link_to_tab' );
		$this->link_to_field_group = $this->option_value( 'acf_vf_link_to_field_group_editor' );

		$this->defaults = array(
			'read_only' => false,
			'mask'		=> '',
			'mask_autoclear' => true,
			'mask_placeholder' => '_',
			'function'	=> 'none',
			'pattern'	=> '',
			'message'	=>  __( 'Validation failed.', 'acf_vf' ),
			'unique'	=> 'non-unique',
			'unique_statuses' => apply_filters( 'acf_vf/unique_statuses', array( 'publish', 'future', 'draft', 'pending' ) ),
			'drafts'	=> true
		);

		$this->sub_defaults = array(
			'type'		=> 'text',
			'key'		=> '',
			'name'		=> '',
			'_name'		=> '',
			'id'		=> '',
			'value'		=> '',
			'field_group' => '',
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

		add_action( 'wp_ajax_validate_fields', array( $this, 'ajax_validate_fields' ) );

		add_action( 'wp_head', array( $this, 'input_admin_head' ) );

		if ( !is_admin() ){
			if ( ! $this->frontend_css ){
				add_action( 'acf/input/admin_enqueue_scripts',  array( $this, 'remove_acf_form_style' ) );
			}

			add_action( 'wp_ajax_nopriv_validate_fields', array( $this, 'ajax_validate_fields' ) );
			// make sure the ajax url is set
			add_action( 'wp_head', array( $this, 'ajaxurl' ), 1 );
			add_action( 'wp_head', array( $this, 'input_admin_enqueue_scripts' ), 1 );

			add_action( 'wp_head', function(){ do_action('acf/input/admin_head'); } );
		}
		if ( is_admin() ){

			// if we are on the options page, call acf_form_head()
			global $pagenow;
			if ( in_array( $pagenow, array( 'edit.php', 'options.php' ) ) ){
				add_action( 'admin_init', 'acf_form_head', 0 );
			}

			// add admin options menu
			add_action( 'admin_menu', array( $this, 'admin_add_menu' ), 11 );

			// remove uneeded properties from subfield
			add_filter( 'acf/export/clean_fields', array( $this, 'prepare_field_for_export' ) );

			// creates ACF js parameters object
			add_action( 'admin_head', array( $this, 'admin_head' ), 0 );


			add_filter( 'acf_vf/options_field_group', array( $this, 'field_group_location' ) );
			register_field_group( acf_vf_options::get_field_group() );
		}
	}


	function field_group_location( $field_group ){
		$field_group['location'] = array(
			array(
				array(
					'param' => 'options_page',
					'operator' => '==',
					'value' => 'acf-validated-field',
					'order_no' => 0,
					'group_no' => 0,
				),
			),
		);
		return $field_group;
	}

	function option_value( $key ){
		return get_option( "options_{$key}" );
	}

	function ajaxurl(){
		?>
		<script type="text/javascript">var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';</script>
		<?php
	}

	function admin_add_menu(){
		$page = add_submenu_page( 'edit.php?post_type=acf', sprintf( __( 'Validated Field Settings %1$d', 'acf_vf' ), 4 ), sprintf( __( 'Validated Field Settings %1$d', 'acf_vf' ), 4 ), 'manage_options', $this->slug, array( &$this,'admin_settings_page' ) );
	}

	function admin_settings_page(){
		?>
		<div class="wrap">
		<h2><?php printf( __( 'Validated Field Settings for ACF %1$d', 'acf_vf' ), 4 ); ?></h2>
			<?php acf_form( array( 'post_id' => 'options' ) ); ?>
		</div>
    	<?php
	}

	function remove_acf_form_style(){
		wp_dequeue_style( array( 'colors-fresh' ) );
	}

	function setup_field( $field ){
		// setup booleans, for compatibility
		$field =  array_merge( $this->defaults, $field );

		$sub_field = isset( $field['sub_field'] )? 
			$field['sub_field'] :	// already set up
			array();				// create it
			
		// mask the sub field as the parent by giving it the same key values
		foreach( array( 'key', 'name', '_name', 'id', 'value', 'field_group' ) as $key ){
			$sub_field[$key] = isset( $field[$key] )? $field[$key] : '';
		}

		$field['sub_field'] = array_merge( $this->sub_defaults, $sub_field );

		return $field;
	}

	function setup_sub_field( $field ){
		return $field['sub_field'];
	}

	function prepare_field_for_export( $fields ){
		if( $fields ){
			foreach( $fields as $i => &$field ){
				if ( isset( $field['sub_field'] ) ){
					unset( 
						$field['sub_field']['id'], 
						$field['sub_field']['class'], 
						$field['sub_field']['order_no'], 
						$field['sub_field']['field_group'], 
						$field['sub_field']['_name'] 
					);
				}
			}
		}			
		return $fields;
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
	*  ajax_validate_fields()
	*
	*  Parse the input when a page is submitted to determine if it is valid or not.
	*
	*  @type		ajax action
	*
	*/
	function ajax_validate_fields() {
		$post_id = isset( $_REQUEST['post_id'] )?				// the submitted post_id
			$_REQUEST['post_id'] : 
			0;
		$post_type = get_post_type( $post_id );					// the type of the submitted post
		$is_frontend = isset( $_REQUEST['frontend'] )?
			$_REQUEST['frontend'] :
			false;

		$click_id =  isset( $_REQUEST['click_id'] )? 			// the ID of the clicked element, for drafts/publish
			$_REQUEST['click_id'] : 
			'publish';

		// the validated field inputs to process
		$inputs = ( isset( $_REQUEST['fields'] ) && is_array( $_REQUEST['fields'] ) )? 
			$_REQUEST['fields'] : 
			array();

		header( 'HTTP/1.1 200 OK' );							// be positive!
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		$return_fields = array();								// JSON response to the client
		foreach ( $inputs as $i=>$input ){						// loop through each field
			// input defaults
			$input = array_merge( $this->input_defaults, $input );
			
			// extract the field key
			preg_match( '/\\[([^\\]]*?)\\](\\[(\d*?)\\]\\[([^\\]]*?)\\])?/', $input['id'], $matches );
			$key = isset( $matches[1] )? $matches[1] : false;	// the key for this ACF
			$index = isset( $matches[3] )? $matches[3] : false;	// the field index, if it is a repeater
			$sub_key = isset( $matches[4] )? $matches[4] : false; // the key for the sub field, if it is a repeater

			// load the field config, set defaults
			$field = $this->setup_field( get_field_object( $key, $post_id ) );

			$field['render_field'] = apply_filters( 'acf_vf/render_field', true, $field, false );
			if ( $field['render_field'] === false || $field['render_field'] === "readonly" ){
				continue;
			}

			// if it's a repeater field, get the validated field so we can do meta queries...
			if ( $is_repeater = ( 'repeater' == $field['type'] && false !== $index ) ){
				foreach ( $field['sub_fields'] as $repeater ){
					$repeater = $this->setup_field( $repeater );
					$sub_field = $this->setup_sub_field( $repeater );
					if ( $sub_key == $sub_field['key'] ){
						$parent_field = $field;					// we are going to map down a level, but track the top level field
						$field = $repeater;						// the '$field' should be the Validated Field
						break;
					}
					$sub_field = false;							// in case it isn't the right one
				}
			} else {
				$sub_field = $this->setup_sub_field( $field );	// the wrapped field
			}

			if ( $field['type'] != 'validated_field' ){			// If this field was submitted for value comparison only
				continue;
			}

			$value = $input['value'];							// the submitted value

			if ( isset($field['required']) && $field['required'] && empty( $value ) ){
				continue;										// let the required field handle it
			}

			if ( $click_id != 'publish' && !$field['drafts'] ){
				continue;										// we aren't publishing and we don't want to validate drafts
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

						// it gets tricky but we are trying to account for an capture bad php code where possible
						$pattern = addcslashes( trim( $pattern ), '$' );
						if ( substr( $pattern, -1 ) != ';' ) $pattern.= ';';

						// not yet saved to the database, so this is the previous value still
						$prev_value = get_post_meta( $post_id, $this_key, true);

						// unique function for this key
						$function_name = 'validate_' . preg_replace( '~[\\[\\]]+~', '_', $input['id'] ) . 'function';

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
								$this->add_response( $return_fields, $input, sprintf( __( 'PHP Error: %1$s, line %2$d.', 'acf_vf' ), $error['message'], $matches[1] ) );		
								continue 2;
							} 
						}
						
						if ( is_string( $valid ) ){				// if a string is returned, return it as the error.
							$this->add_response( $return_fields, $input, $valid );		
							continue 2;
						} elseif ( !$valid ){
							return $message;
						}

						break;
				}
			} elseif ( ! empty( $function ) && $function != 'none' ) {
				$this->add_response( $return_fields, $input, __( "This field's validation is not properly configured.", 'acf_vf' ) );		
				continue;
			}	

			$unique = $field['unique'];
			$field_is_unique = !empty( $value ) && !empty( $unique ) && $unique != 'non-unique';

			if ( $field_is_unique ){
				$value_instances = 0;
				// sort the value if it's an array before we compare
				$_value = $this->maybe_sort_value( $value, $field );	
				switch ( $unique ){
					case 'global';
					case 'post_type':
					case 'this_post':
						// no duplicates at all allowed, check the submitted values
						foreach ( $_REQUEST['fields'] as $acf ){
							// sort the value if it's an array before we compare
							$_field_value = $this->maybe_sort_value( $acf['value'], $field );
							if ( $_field_value == $_value ){
								// increment until we have a dupe
								if ( ++$value_instances > 1 ){
									$message = $this->get_unique_form_error( $unique, $field, $value );
									$this->add_response( $return_fields, $input, $message );		
									continue 3;
								}
							}
						}
						break;
					case 'post_key':
					case 'this_post_key':
						// only check the key for a repeater for duplicate submissions
						if ( $is_repeater ){
							// submitted as "field[index][id/value]"
							foreach ( $_REQUEST['fields'] as $acf ){
								// extract field key
								$arr = explode( '][', preg_replace( '~^\[|\]$~', '', $input['id'] ) );
								$row_key = end( $arr );
								$arr = explode( '][', preg_replace( '~^\[|\]$~', '', $acf['id'] ) );
								$input_key = end( $arr );

								// sort the value if it's an array before we compare
								$_field_value = $this->maybe_sort_value( $acf['value'], $field );

								// check if we have the same value more than once
								if ( $row_key == $input_key && $_field_value == $_value ){
									// increment until we have a dupe
									if ( ++$value_instances > 1 ){
										$message = $this->get_unique_form_error( $unique, $field, $value );
										$this->add_response( $return_fields, $input, $message );		
										continue 3;
									}
								}
							}
						}
						break;
				}

				// Run the SQL queries to see if there are duplicate values
				if ( true !== ( $message = $this->is_value_unique( $unique, $post_id, $field, isset( $parent_field )? $parent_field : null, $index, $is_repeater, false, $is_frontend, $value ) ) ){
					$this->add_response( $return_fields, $input, $message );		
					continue;
				}
			}

			// Mark the validation as successful
			$this->add_response( $return_fields, $input, true );		
			continue;
		}
		
		// Send the results back to the browser as JSON
		die( version_compare( phpversion(), '5.3', '>=' )? 
			json_encode( $return_fields, $this->debug? JSON_PRETTY_PRINT : 0 ) :
			json_encode( $return_fields ) );
	}

	private function add_response( &$return_fields, $input, $valid=false ){
		if ( is_string( $valid ) ){
			$message = $valid;
			$valid = false;
		}
		$result = array(
			'id' => $input['id'],
			'valid' => $valid
		);
		if ( !$valid ){
			$result['message'] = ( true === $valid ) ? '' : ! empty( $message )? htmlentities( $message, ENT_NOQUOTES, 'UTF-8' ) : __( 'Validation failed.', 'acf_vf' );
		}

		$return_fields[] = $result;
	}

	private function prepare_in_not_in( $sql, $post_ids ){
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
	*  create_options()
	*
	*  Create extra options for your field. This is rendered when editing a field.
	*  The value of $field['name'] can be used (like below) to save extra data to the $field
	*
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field	- an array holding all the field's data
	*/
	function create_options( $field ){
		// defaults?
		$field = $this->setup_field( $field );

		// key is needed in the field names to correctly save the data
		$key = $field['name'];
		$html_key = preg_replace( '~[\\[\\]]+~', '_', $key );
		$sub_field = $this->setup_sub_field( $field );
		$sub_field['name'] = $key . '][sub_field';

		// get all of the registered fields for the sub type drop down
		$fields_names = apply_filters( 'acf/registered_fields', array() );

		// remove types that don't jive well with this one
		unset( $fields_names[__( 'Layout', 'acf' )] );
		unset( $fields_names[__( 'Basic', 'acf' )][ 'validated_field' ] );

		?>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_drafts" id="field_option_<?php echo $html_key; ?>_drafts">
			<td class="label"><label><?php _e( 'Validate Drafts/Preview?', 'acf_vf' ); ?> </label>
			</td>
			<td><?php 
			if ( $this->drafts ){
				printf( __( '<em><code>Draft Validation</code>has been set to <code>true</code> which overrides field level configurations. <a href="%1$s">Click here</a> to update the Validated Field settings.</em>', 'acf_vf' ), admin_url('edit.php?post_type=acf&page=acf-validated-field')."#general" );
			} else {
				do_action( 'acf/create_field', array(
					'type'	=> 'radio',
					'name'	=> 'fields['.$key.'][drafts]',
					'value'	=> ( false == $field['drafts'] || 'false' === $field['drafts'] )? 'no' : 'yes',
					'choices' => array(
						'yes'	=> __( 'Yes', 'acf_vf' ),
						'no' => __( 'No', 'acf_vf' ),
					),
					'class' => 'drafts horizontal'
				));
			}

			?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?>">
			<td class="label"><label><?php _e( 'Validated Field', 'acf_vf' ); ?> </label>
			<script type="text/javascript">
			</script>
			</td>
			<td>
				<div class="sub-field">
					<div class="fields">
						<div class="field sub_field acf-sub_field">
							<div class="field_form">
								<table class="acf_input widefat">
									<tbody>
										<tr class="field_type">
											<td class="label"><label><span class="required">*</span> <?php _e( 'Field Type', 'acf' ); ?>
											</label></td>
											<td><?php
											// Create the drop down of field types
											do_action( 'acf/create_field', array(
												'type'		=> 'select',
												'name'		=> 'fields[' . $key . '][sub_field][type]',
												'value'		=> $sub_field['type'],
												'class'		=> 'type',
												'choices' 	=> $fields_names,
												'class'		=> 'field-type'
											));

											// Create the default sub field settings
											do_action( 'acf/create_field_options', $sub_field );
											?>
											</td>
										</tr>
										<tr class="field_save">
											<td class="label">
											</td>
											<td></td>
										</tr>
									</tbody>
								</table>
							</div>
							<!-- End Form -->
						</div>
					</div>
				</div>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_readonly" id="field_option_<?php echo $html_key; ?>_readonly">
			<td class="label"><label><?php _e( 'Read Only?', 'acf_vf' ); ?> </label>
			</td>
			<td><?php
			do_action( 'acf/create_field', array(
				'type'	=> apply_filters( 'acf_vf/create_field/read_only/type', 'radio' ),
				'name'	=> 'fields['.$key.'][read_only]',
				'value'	=> apply_filters( 'acf_vf/create_field/read_only/value', ( empty( $field['read_only'] ) || $field['read_only'] == 'false' )? 'no' : $field['read_only'] ),
				'choices' => apply_filters( 'acf_vf/create_field/read_only/choices', array(
					'no' 	=> __( 'No', 'acf_vf' ),
					'yes'	=> __( 'Yes', 'acf_vf' ),
				) ),
				'class'			=> 'read_only horizontal'
			));
			?>
			</td>
		</tr>
		<?php
			// 3rd party read only settings
			do_action( 'acf_vf/settings_readonly', $field );
		?>
		<tr class="field_option field_option_<?php echo $this->name; ?> non_read_only">
			<td class="label"><label><?php _e( 'Input Mask', 'acf_vf' ); ?></label></td>
			<td><?php _e( 'Use &#39;a&#39; to match A-Za-z, &#39;9&#39; to match 0-9, and &#39;*&#39; to match any alphanumeric.', 'acf_vf' ); ?> 
				<a href="http://digitalbush.com/projects/masked-input-plugin/" target="_new"><?php _e( 'More info', 'acf_vf' ); ?></a>.
				<?php 
				do_action( 'acf/create_field', 
					array(
						'type'	=> 'text',
						'name'	=> 'fields[' . $key . '][mask]',
						'value'	=> $field['mask'],
						'class'	=> 'input-mask'
					)
				);
				?><br />
				<label for="">Autoclear invalid values: </label>
				<?php 
				do_action( 'acf/create_field', 
					array(
						'type'	=> 'radio',
						'name'	=> 'fields[' . $key . '][mask_autoclear]',
						'value'	=> $field['mask_autoclear'],
						'layout'	=>	'horizontal',
						'choices' => array(
							true => 'Yes',
							false => 'No'
						),
						'class'	=> 'mask-settings'
					)
				);
				?><br />
				<label for="">Input mask placeholder: </label>
				<?php 
				do_action( 'acf/create_field', 
					array(
						'type'	=> 'text',
						'name'	=> 'fields[' . $key . '][mask_placeholder]',
						'value'	=> $field['mask_placeholder'],
						'class'	=> 'mask-settings'
					)
				);
				?><br />
				<strong><em><?php _e( 'Input masking is not compatible with the "number" field type!', 'acf_vf' ); ?><em></strong>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> non_read_only">
			<td class="label"><label><?php _e( 'Validation: Function', 'acf_vf' ); ?></label></td>
			<td><?php _e( "How should the field be server side validated?", 'acf_vf' ); ?><br />
				<?php 
				do_action( 'acf/create_field', 
					array(
						'type'	=> 'select',
						'name'	=> 'fields[' . $key . '][function]',
						'value'	=> $field['function'],
						'choices' => array(
							'none'	=> __( 'None', 'acf_vf' ),
							'regex' => __( 'Regular Expression', 'acf_vf' ),
							//'sql'	=> __( 'SQL Query', 'acf_vf' ),
							'php'	=> __( 'PHP Statement', 'acf_vf' ),
						),
						'optgroup' => true,
						'multiple' => '0',
						'class' => 'validated_select',
					)
				);
				?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_validation non_read_only" id="field_option_<?php echo $html_key; ?>_validation">
			<td class="label"><label><?php _e( 'Validation: Pattern', 'acf_vf' ); ?></label>
			</td>
			<td>
				<div id="validated-<?php echo $html_key; ?>-info">
					<div class='validation-type regex'>
						<?php _e( 'Pattern match the input using', 'acf_vf' ); ?> <a href="http://php.net/manual/en/function.preg-match.php" target="_new">PHP preg_match()</a>.
						<br />
					</div>
					<div class='validation-type php'>
						<ul>
							<li><?php _e( "Use any PHP code and return true or false. If nothing is returned it will evaluate to true.", 'acf_vf' ); ?></li>
							<li><?php _e( 'Available variables', 'acf_vf' ); ?> - <b>$post_id</b>, <b>$post_type</b>, <b>$meta_key</b>, <b>$value</b>, <b>$prev_value</b>, <b>&amp;$message</b> (<?php _e('returned to UI', 'acf_vf' ); ?>).</li>
							<li><?php _e( 'Example', 'acf_vf' ); ?>: <code>if ( empty( $value ) || $value == "xxx" ){  return "{$value} is not allowed"; }</code></li>
						</ul>
					</div>
					<div class='validation-type sql'>
						<?php _e( 'SQL', 'acf_vf' ); ?>.
						<br />
					</div>
				</div> 
				<?php
				do_action( 'acf/create_field', array(
					'type'	=> 'textarea',
					'name'	=> 'fields['.$key.'][pattern]',
					'value'	=> $field['pattern'],
					'class' => 'editor'					 
				)); 
				?>
				<div id="acf-field-<?php echo $html_key; ?>_editor" style="height:200px;"><?php echo $field['pattern']; ?></div>

			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_message non_read_only" id="field_option_<?php echo $html_key; ?>_message">
			<td class="label"><label><?php _e( 'Validation: Error Message', 'acf_vf' ); ?></label>
			</td>
			<td><?php 
			do_action( 'acf/create_field', 
				array(
					'type'	=> 'text',
					'name'	=> 'fields['.$key.'][message]',
					'value'	=> $field['message'],
				)
			); 
			?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> non_read_only">
			<td class="label"><label><?php _e( 'Unique Value?', 'acf_vf' ); ?> </label>
			</td>
			<td>
			<div id="validated-<?php echo $html_key; ?>-unique">
			<p><?php _e( 'Make sure this value is unique for...', 'acf_vf' ); ?><br/>
			<?php 

			do_action( 'acf/create_field', 
				array(
					'type'	=> 'select',
					'name'	=> 'fields[' . $key . '][unique]',
					'value'	=> $field['unique'],
					'choices' => array(
						'non-unique'	=> __( 'Non-Unique Value', 'acf_vf' ),
						'global'		=> __( 'Unique Globally', 'acf_vf' ),
						'post_type'		=> __( 'Unique For Post Type/User/Option', 'acf_vf' ),
						'post_key'		=> __( 'Unique For Post Type/User/Option + Field/Meta Key ', 'acf_vf' ),
						'this_post'		=> __( 'Unique For Post/User', 'acf_vf' ),
						'this_post_key'	=> __( 'Unique For Post/User + Field/Meta Key', 'acf_vf' ),
					),
					'optgroup'	=> false,
					'multiple'	=> '0',
					'class'		=> 'validated-select',
				)
			);
			?>
			</p>
			<div class="unique_statuses">
			<p><?php _e( 'Unique Value: Apply to...?', 'acf_vf'); ?><br/>
			<?php
			$statuses = $this->get_post_statuses();
			$choices = array();
			foreach ( $statuses as $value => $status ) {
				$choices[$value] = $status->label;
			}

			do_action( 'acf/create_field', 
				array(
					'type'	=> 'checkbox',
					'name'	=> 'fields['.$key.'][unique_statuses]',
					'value'	=> $field['unique_statuses'],
					'choices' => $choices,
				)
			); 
			?></p>
			</div>
			</div>
			<script type="text/javascript">
			jQuery(document).ready(function(){
				jQuery("#acf-field-<?php echo $html_key; ?>_pattern").hide();

		    	ace.require("ace/ext/language_tools");
				ace.config.loadModule('ace/snippets/snippets');
				ace.config.loadModule('ace/snippets/php');
				ace.config.loadModule("ace/ext/searchbox");

				var editor = ace.edit("acf-field-<?php echo $html_key; ?>_editor");
				editor.$blockScrolling = Infinity;
				editor.setTheme("ace/theme/monokai");
				editor.getSession().setMode("ace/mode/text");
				editor.getSession().on('change', function(e){
					var val = editor.getValue();
					var func = jQuery('#acf-field-<?php echo $html_key; ?>_function').val();
					if (func=='php'){
						val = val.substr(val.indexOf('\n')+1);
					} else if (func=='regex'){
						if (val.indexOf('\n')>0){
							editor.setValue(val.trim().split('\n')[0]);
						}
					}
					jQuery("#acf-field-<?php echo $html_key; ?>_pattern").val(val);
				});
				jQuery("#acf-field-<?php echo $html_key; ?>_editor").data('editor', editor);

				jQuery('#acf-field-<?php echo $html_key; ?>_function').on('change',function(){
					jQuery('#validated-<?php echo $html_key; ?>-info div').hide(300);
					jQuery('#validated-<?php echo $html_key; ?>-info div.'+jQuery(this).val()).show(300);
					if (jQuery(this).val()!='none'){
						jQuery('#validated-<?php echo $html_key; ?>-info .field_option_<?php echo $this->name; ?>_validation').show();
					} else {
						jQuery('#validated-<?php echo $html_key; ?>-info .field_option_<?php echo $this->name; ?>_validation').hide();
					}
					var sPhp = '<'+'?'+'php';
					var editor = jQuery('#acf-field-<?php echo $html_key; ?>_editor').data('editor');
					var val = editor.getValue();
					if (jQuery(this).val()=='none'){
						jQuery('#field_option_<?php echo $html_key; ?>_validation, #field_option_<?php echo $html_key; ?>_message').hide(300);
					} else {
						if (jQuery(this).val()=='php'){
							if (val.indexOf(sPhp)!=0){
								editor.setValue(sPhp +'\n' + val);
							}
							editor.getSession().setMode("ace/mode/php");
							jQuery("#acf-field-<?php echo $html_key; ?>_editor").css('height','420px');

							editor.setOptions({
								enableBasicAutocompletion: true,
								enableSnippets: true,
								enableLiveAutocompletion: true
							});
						} else {
							if (val.indexOf(sPhp)==0){
								editor.setValue(val.substr(val.indexOf('\n')+1));
							}
							editor.getSession().setMode("ace/mode/text");
							jQuery("#acf-field-<?php echo $html_key; ?>_editor").css('height','18px');
							editor.setOptions({
								enableBasicAutocompletion: false,
								enableSnippets: false,
								enableLiveAutocompletion: false
							});
						}
						editor.resize();
						editor.gotoLine(1, 1, false);
						jQuery('#field_option_<?php echo $html_key; ?>_validation, #field_option_<?php echo $html_key; ?>_message').show(300);
					}
				});

				jQuery('#acf-field-<?php echo $html_key; ?>_unique').on('change',function(){
					var unqa = jQuery('#validated-<?php echo $html_key; ?>-unique .unique_statuses');
					var val = jQuery(this).val();
					if (val=='non-unique'||val=='') { unqa.hide(300); } else { unqa.show(300); }
				});
				
				jQuery('#acf-field-<?php echo $html_key; ?>_sub_field_type').on('change', function(){
					$el = jQuery(this).closest('.sub-field').closest('.field');
					setValidatedFieldLabel( $el );
				});

				function setValidatedFieldLabel($el){
					$types = $el.find('.field_type select option:selected');
					if ($types.first().val() == 'validated_field'){
						$el.find('.field_meta .field_type').text('Validated: ' + $types.last().text());
					}
				}

				// update ui
				jQuery('#acf-field-<?php echo $html_key; ?>_function').trigger('change');
				jQuery('#acf-field-<?php echo $html_key; ?>_unique').trigger('change');
				jQuery('#acf-field-<?php echo $html_key; ?>_sub_field_type').trigger('change');
			});
			</script>
			</td>
		</tr>
		<?php
	}

	/*
	*  create_field()
	*
	*  Create the HTML interface for your field
	*
	*  @param	$field - an array holding all the field's data
	*
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function create_field( $field ){
		global $post, $pagenow;

		// set up field properties
		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );

		// determine if this is a new post or an edit
		$is_new = $pagenow=='post-new.php';

		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );

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
				ob_start();
				do_action( 'acf/create_field', $sub_field ); 
				$contents = ob_get_contents();
				if ( self::check_value( 'yes', $field['read_only'] ) ){
					$contents = preg_replace("~<(input|textarea|select)~", "<\${1} disabled=true readonly", $contents );
					$contents = preg_replace("~acf-hidden~", "acf-hidden acf-vf-readonly", $contents );
				}
				ob_end_clean();
				echo $contents;
				?>
			</div>
			<?php
			if ( !empty( $field['mask'] ) && ( $is_new || self::check_value( 'no', $field['read_only'] ) ) ){ ?>
				<script type="text/javascript">
					jQuery(function($){
						$(function(){
							$('div[data-field_key="<?php echo $field['key']; ?>"] input').each( function(){
								$(this).mask("<?php echo $field['mask']?>", {
									autoclear: <?php echo isset( $field['mask_autoclear'] ) && empty( $field['mask_autoclear'] )? 'false' : 'true'; ?>,
									placeholder: '<?php echo isset( $field['mask_placeholder'] )? $field['mask_placeholder'] : '_'; ?>'
								});
							});
						});
					});
				</script>
			<?php
			}
			if ( !$this->drafts && $field['drafts'] == 'yes' ){ 
				$this->drafts_head();
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

		wp_register_script( 'acf-validated-field', plugins_url( "js/input{$min}.js", __FILE__ ), array( 'jquery' ), $this->settings['version'], true );
		wp_register_script( 'jquery-masking', plugins_url( "../common/js/jquery.maskedinput{$min}.js", __FILE__ ), array( 'jquery' ), $this->settings['version'], true );
		
		// translations
		wp_localize_script( 'acf-validated-field', 'vf_l10n', array(
			'message' => __( 'Validation Failed. See errors below.', 'acf_vf' ),
			'debug' => __( 'The fields are valid, do you want to submit the form?', 'acf_vf' ),
		) );

		// enqueue scripts
		wp_enqueue_script( array(
			'jquery',
			'jquery-ui-core',
			'jquery-ui-tabs',
			'jquery-masking',
			'acf-validated-field',
		) );

		if ( $this->debug ){ 
			add_action( 'wp_head', array( &$this, 'debug_head' ), 20 );
		}

		if ( $this->drafts ){ 
			add_action( 'wp_head', array( &$this, 'drafts_head' ), 20 );
		}
		
		if ( !is_admin() ){
			add_action( 'wp_head', array( &$this, 'frontend_head' ), 20 );
		}

		if ( $this->link_to_tab ){
			wp_enqueue_script( 'acf-validated-field-link-to-tab', plugins_url( "../common/js/link-to-tab{$min}.js", __FILE__ ), array( 'jquery' ), ACF_VF_VERSION );
		}
	}

	function admin_head(){
		$o = array(
			'post_id'				=> 'options',
			'nonce'					=> wp_create_nonce( 'acf_nonce' ),
			'admin_url'				=> admin_url(),
			'ajaxurl'				=> admin_url( 'admin-ajax.php' ),
			'validation'			=> 0,
		);
		
		// l10n
		$l10n = apply_filters( 'acf/input/admin_l10n', array(
			'core' => array(
				'expand_details' => __("Expand Details",'acf'),
				'collapse_details' => __("Collapse Details",'acf')
			),
			'validation' => array(
				'error' => __("Validation Failed. One or more fields below are required.",'acf')
			)
		));

		?>
		<script type="text/javascript">
		(function($) {
			if ( typeof acf == 'undefined' ) acf = {};
			acf.o = <?php echo json_encode( $o ); ?>;
			acf.l10n = <?php echo json_encode( $l10n ); ?>;
		})(jQuery);	
		</script>
		<?php
	}

	function debug_head(){
		// set debugging for javascript
		echo '<script type="text/javascript">jQuery(document).ready(function(){ vf.debug=true; });</script>';
	}

	function drafts_head(){
		// don't validate drafts for anything
		echo '<script type="text/javascript">jQuery(document).ready(function(){ vf.drafts=true; });</script>';
	}

	function frontend_head(){
		// indicate that this is validating the front end
		echo '<script type="text/javascript">jQuery(document).ready(function(){ vf.frontend=true; });</script>';
	}

	/*
	*  input_admin_head()
	*
	*  This action is called in the admin_head action on the edit screen where your field is created.
	*  Use this action to add css and javascript to assist your create_field() action.
	*
	*  @info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_head
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function input_admin_head(){
		// register acf scripts
		$min = $this->get_min();
		wp_enqueue_style( 'font-awesome', plugins_url( "../common/css/font-awesome/css/font-awesome{$min}.css", __FILE__ ), array(), '4.4.0', true ); 
		wp_enqueue_style( 'acf-validated_field', plugins_url( '../common/css/input.css', __FILE__ ), array( 'acf-input' ), ACF_VF_VERSION, true ); 

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
		// register acf scripts
		$min = $this->get_min();	
		
		wp_deregister_style( 'font-awesome' );
		wp_enqueue_style( 'font-awesome', plugins_url( "../common/css/font-awesome/css/font-awesome{$min}.css", __FILE__ ), array(), '4.4.0', true ); 
		
		wp_enqueue_script( 'ace-editor', plugins_url( "../common/js/ace{$min}/ace.js", __FILE__ ), array(), '1.2' );
		wp_enqueue_script( 'ace-ext-language_tools', plugins_url( "../common/js/ace{$min}/ext-language_tools.js", __FILE__ ), array(), '1.2' );

		if ( $this->link_to_field_group ){
			wp_enqueue_script( 'acf-validated-field-link-to-field-group', plugins_url( "../common/js/link-to-field-group{$min}.js", __FILE__ ), array( 'jquery', 'acf-field-group' ), ACF_VF_VERSION, true );
		}
	}
}

global $acf_vf;
$acf_vf = new acf_field_validated_field_v4();
endif;
