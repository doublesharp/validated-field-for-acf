<?php
if ( class_exists( 'acf_Field' ) && ! class_exists( 'acf_field_validated_field' ) ):
class acf_field_validated_field extends acf_field {
	//static final NL = "\n";
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
		$this->strbool 	= array( 'true' => true, 'false' => false );
		$this->config 	= array(
			'acf_vf_debug' => array(
				'type' 		=> 'checkbox',
				'default' 	=> 'false',
				'label'  	=> __( 'Enable Debug', 'acf_vf' ),
				'help' 		=> __( 'Check this box to turn on debugging for Validated Fields.', 'acf_vf' ),
			),
			'acf_vf_drafts' => array(
				'type' 		=> 'checkbox',
				'default' 	=> 'true',
				'label'  	=> __( 'Enable Draft Validation', 'acf_vf' ),
				'help' 		=> __( 'Check this box to enable Draft validation globally, or uncheck to allow it to be set per field.', 'acf_vf' ),
			),
			'acf_vf_frontend' => array(
				'type' 		=> 'checkbox',
				'default' 	=> 'true',
				'label'  	=> __( 'Enable Front-End Validation', 'acf_vf' ),
				'help'		=> __( 'Check this box to turn on validation for front-end forms created with', 'acf_vf' ) . ' <code>acf_form()</code>.',
			),
			'acf_vf_frontend_css' => array(
				'type' 		=> 'checkbox',
				'default' 	=> 'true',
				'label'  	=> __( 'Enqueue Admin CSS on Front-End', 'acf_vf' ),
				'help' 		=> __( 'Uncheck this box to turn off "colors-fresh" admin theme enqueued by', 'acf_vf' ) . ' <code>acf_form_head()</code>.',
			),
		);
		$this->name		= 'validated_field';
		$this->label 	= __( 'Validated Field', 'acf_vf' );
		$this->category	= __( 'Basic', 'acf' );
		$this->drafts	= $this->option_value( 'acf_vf_drafts' );
		$this->frontend = $this->option_value( 'acf_vf_frontend' );
		$this->frontend_css = $this->option_value( 'acf_vf_frontend_css' );
		$this->debug 	= $this->option_value( 'acf_vf_debug' );

		$this->defaults = array(
			'read_only' => false,
			'mask'		=> '',
			'function'	=> 'none',
			'pattern'	=> '',
			'message'	=>  __( 'Validation failed.', 'acf_vf' ),
			'unique'	=> 'non-unique',
			'unique_statuses' => apply_filters( 'acf_vf/unique_statuses', array( 'publish', 'future' ) ),
			'drafts'	=> true,
		);

		$this->sub_defaults = array(
			'type'		=> '',
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

		if ( is_admin() || $this->frontend ){ // admin actions
			add_action( $this->frontend? 'wp_head' : 'admin_head', array( &$this, 'input_admin_head' ) );
			if ( ! is_admin() && $this->frontend ){
				if ( ! $this->frontend_css ){
					add_action( 'acf/input/admin_enqueue_scripts',  array( &$this, 'remove_acf_form_style' ) );
				}

				add_action( 'wp_head', array( &$this, 'set_post_id_to_acf_form' ) );
				add_action( 'wp_head', array( &$this, 'input_admin_enqueue_scripts' ), 1 );
			}
			if ( is_admin() ){
				add_action( 'admin_init', array( &$this, 'admin_register_settings' ) );
				add_action( 'admin_menu', array( &$this, 'admin_add_menu' ), 11 );
				add_action( 'admin_head', array( &$this, 'admin_head' ) );
				// add the post_ID to the acf[] form
				add_action( 'edit_form_after_editor', array( $this, 'edit_form_after_editor' ) );
			}

			if ( is_admin() || $this->frontend ){
				// validate validated_fields
				add_filter( "acf/validate_value/type=validated_field", array( $this, 'validate_field' ), 10, 4 );
			}
		}


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

	function option_value( $key ){
		return ( false !== $option = get_option( $key ) )?
			$option == $this->config[$key]['default'] :
			$this->strbool[$this->config[$key]['default']];
	}

	function admin_head(){
		$min = ( ! $this->debug )? '.min' : '';
		wp_register_script( 'acf-validated-field-admin', plugins_url( "js/admin{$min}.js", __FILE__ ), array( 'jquery', 'acf-field-group' ), $this->settings['version'] );
		wp_enqueue_script( array(
			'jquery',
			'acf-validated-field-admin',
		));	
	}

	function admin_add_menu(){
		$page = add_submenu_page( 'edit.php?post_type=acf-field-group', __( 'Validated Field Settings', 'acf_vf' ), __( 'Validated Field Settings', 'acf_vf' ), 'manage_options', $this->slug, array( &$this,'admin_settings_page' ) );		
	}

	function admin_register_settings(){
		foreach ( $this->config as $key => $value ) {
			register_setting( $this->slug, $key );
		}
	}

	function admin_settings_page(){
		?>
		<div class="wrap">
		<h2>Validated Field Settings</h2>
		<form method="post" action="options.php">
		    <?php settings_fields( $this->slug ); ?>
		    <?php do_settings_sections( $this->slug ); ?>
			<table class="form-table">
			<?php foreach ( $this->config as $key => $value ) { ?>
				<tr valign="top">
					<th scope="row"><?php echo $value['label']; ?></th>
					<td>
						<input type="checkbox" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo $value['default']; ?>" <?php if ( $this->option_value( $key ) ) echo 'checked'; ?>/>
						<small><em><?php echo $value['help']; ?></em></small>
					</td>
				</tr>
			<?php } ?>
			</table>
		    <?php submit_button(); ?>
		</form>
		</div>
    	<?php
	}

	function remove_acf_form_style(){
		wp_dequeue_style( array( 'colors-fresh' ) );
	}

	function setup_field( $field ){
		// setup booleans, for compatibility
		$field['read_only'] = ( false == $field['read_only'] || 'false' === $field['read_only'] )? false : true;
		$field['drafts'] = ( false == $field['drafts'] || 'false' === $field['drafts'] )? false : true;

		return acf_prepare_field( array_merge( $this->defaults, $field ) );
	}

	function setup_sub_field( $field ){
		$sub_field = isset( $field['sub_field'] )? 
			$field['sub_field'] :	// already set up
			array();				// create it
		// mask the sub field as the parent by giving it the same key values
		foreach( array( 'key', 'name', '_name', 'id', 'value', 'field_group' ) as $key ){
			$sub_field[$key] = isset( $field[$key] )? $field[$key] : '';
		}
		$sub_field['key'] = $field['key'];
		$sub_field['prefix'] = 'acf';
		// make sure all the defaults are set
		return array_merge( $this->sub_defaults, $sub_field );
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

	function validate_field( $valid, $value, $field, $input ) {
		if ( ! $valid )
			return $valid;

		$post_id = $_POST['acf']['post_ID'];

		$post_type = get_post_type( $post_id );				// the type of the submitted post
		$frontend = isset( $_REQUEST['acf']['frontend'] )?
			$_REQUEST['acf']['frontend'] :
			false;

		// if it's a repeater field, get the validated field so we can do meta queries...
		if ( $is_repeater = ( 'repeater' == $field['type'] && $index ) ){
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
			// the wrapped field
			$sub_field = $this->setup_sub_field( $field );
		}

		//$value = $input['value'];							// the submitted value
		if ( $field['required'] && empty( $value ) ){
			return $valid;										// let the required field handle it
		}

		if ( $click_id != 'publish' && !$field['drafts'] ){
			return $valid;										// we aren't publishing and we don't want to validate drafts
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
					if ( $is_repeater ) $this_key .= '_' . $index . '_' . $sub_sub_field['name'];

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

					// the default message
					$message = $field['message'];

					// not yet saved to the database, so this is the previous value still
					$prev_value = get_post_meta( $post_id, $this_key, true );

					// unique function for this key
					$function_name = 'validate_' . $field['key'] . '_function';
					
					// it gets tricky but we are trying to account for an capture bad php code where possible
					$pattern = addcslashes( trim( $pattern ), "'" );
					if ( substr( $pattern, -1 ) != ';' ) $pattern.= ';';

					$value = addslashes( $value );
					$prev_value = addslashes( $prev_value );

					$php = <<<PHP
if ( ! function_exists( '$function_name' ) ):
function $function_name( \$args, &\$message ){
	extract( \$args );
	try {
		\$code = '$pattern return true;';
		return eval( \$code );
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
						if ( strpos( $error['file'], "validated_field_v5.php" ) && strpos( $error['file'], "eval()'d code" ) ){
							preg_match( '/eval\\(\\)\'d code\\((\d+)\\)/', $error['file'], $matches );
							$message = __( 'PHP Error', 'acf_vf' ) . ': ' . $error['message'] . ', line ' . $matches[1] . '.';
							$valid = false;
						} 
					}
					break;
			}
		} elseif ( ! empty( $function ) && $function != 'none' ) {
			$message = __( 'This field\'s validation is not properly configured.', 'acf_vf' );
			$valid = false;
		}
			
		$unique = $field['unique'];
		if ( $valid && ! empty( $value ) && ! empty( $unique ) && $unique != 'non-unique' ){
			global $wpdb;
			$status_in = "'" . implode( "','", $field['unique_statuses'] ) . "'";

			// WPML compatibility, get code list of active languages
			if ( function_exists( 'icl_object_id' ) ){
				$languages = $wpdb->get_results( "SELECT code FROM {$wpdb->prefix}icl_languages WHERE active = 1", ARRAY_A );
				$wpml_ids = array();
				foreach( $languages as $lang ){
					$wpml_ids[] = (int) icl_object_id( $post_id, $post_type, true, $lang['code'] );
				}
				$post_ids = array_unique( $wpml_ids );
			} else {
				$post_ids = array( (int) $post_id );
			}

			$sql_prefix = "SELECT pm.meta_id AS meta_id, pm.post_id AS post_id, p.post_title AS post_title FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status IN ($status_in)";
			switch ( $unique ){
				case 'global': 
					// check to see if this value exists anywhere in the postmeta table
					$sql = $wpdb->prepare( 
						"{$sql_prefix} AND post_id NOT IN ([NOT_IN]) WHERE ( meta_value = %s OR meta_value LIKE %s )",
						$value,
						'%"' . like_escape( $value ) . '"%'
					);
					break;
				case 'post_type':
					// check to see if this value exists in the postmeta table with this $post_id
					$sql = $wpdb->prepare( 
						"{$sql_prefix} AND p.post_type = %s AND post_id NOT IN ([NOT_IN]) WHERE ( meta_value = %s OR meta_value LIKE %s )", 
						$post_type,
						$value,
						'%"' . like_escape( $value ) . '"%'
					);
					break;
				case 'post_key':
					// check to see if this value exists in the postmeta table with both this $post_id and $meta_key
					if ( $is_repeater ){
						$this_key = $parent_field['name'] . '_' . $index . '_' . $field['name'];
						$meta_key = $parent_field['name'] . '_%_' . $field['name'];
						$sql = $wpdb->prepare(
							"{$sql_prefix} AND p.post_type = %s WHERE ( ( post_id NOT IN ([NOT_IN]) AND meta_key != %s AND meta_key LIKE %s ) OR ( post_id NOT IN ([NOT_IN]) AND meta_key LIKE %s ) ) AND ( meta_value = %s OR meta_value LIKE %s )", 
							$post_type,
							$this_key,
							$meta_key,
							$meta_key,
							$value,
							'%"' . like_escape( $value ) . '"%'
						);
					} else {
						$sql = $wpdb->prepare( 
							"{$sql_prefix} AND p.post_type = %s AND post_id NOT IN ([NOT_IN]) WHERE meta_key = %s AND ( meta_value = %s OR meta_value LIKE %s )", 
							$post_type,
							$field['name'],
							$value,
							'%"' . like_escape( $value ) . '"%'
						);
					}
					break;
				default:
					// no dice, set $sql to null
					$sql = null;
					break;
			}

			// Only run if we hit a condition above
			if ( ! empty( $sql ) ){

				// Update the [NOT_IN] values
				$sql = $this->prepare_not_in( $sql, $post_ids );

				// Execute the SQL
				$rows = $wpdb->get_results( $sql );
				if ( count( $rows ) ){
					// We got some matches, but there might be more than one so we need to concatenate the collisions
					$conflicts = "";
					foreach ( $rows as $row ){
						$permalink = ( $frontend )? get_permalink( $row->post_id ) : "/wp-admin/post.php?post={$row->post_id}&action=edit";
						$conflicts.= "<a href='{$permalink}' style='color:inherit;text-decoration:underline;'>{$row->post_title}</a>";
						if ( $row !== end( $rows ) ) $conflicts.= ', ';
					}
					$message = __( 'The value', 'acf_vf' ) . " '$value' " . __( 'is already in use by', 'acf_vf' ) . " {$conflicts}.";
					$valid = false;
				}
			}
		}
		
		// ACF will use any message as an error
		if ( ! $valid ) $valid = $message;

		return $valid;
	}

	private function prepare_not_in( $sql, $post_ids ){
		global $wpdb;
		$not_in_count = substr_count( $sql, '[NOT_IN]' );
		if ( $not_in_count > 0 ){
			$args = array( str_replace( '[NOT_IN]', implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) ), str_replace( '%', '%%', $sql ) ) );
			for ( $i=0; $i < substr_count( $sql, '[NOT_IN]' ); $i++ ) { 
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

		// layout
		acf_render_field_setting( $field, array(
			'label'			=> __('Read Only?','acf_vf'),
			'instructions'	=> '',
			'type'			=> 'radio',
			'name'			=> 'read_only',
			'layout'		=> 'horizontal', 
			'prefix'		=> $field['prefix'],
			//'value'			=> ( empty( $field['read_only'] ) || 'false' === $field['read_only'] )? 'false' : 'true',
			'choices'		=> array(
				'' => __( 'No', 'acf_vf' ),
				'1'	=> __( 'Yes', 'acf_vf' ),
			)
		));

		// Validate Drafts
		acf_render_field_setting( $field, array(
			'label'			=> __('Validate Drafts/Preview?', 'acf_vf'),
			'instructions'	=> '',
			'type'			=> 'radio',
			'name'			=> 'drafts',
			'prefix'		=> $field['prefix'],
			//'value'			=> ( false == $field['drafts'] || 'false' === $field['drafts'] )? 'false' : 'true',
			'choices' => array(
				'1'	=> __( 'Yes', 'acf_vf' ),
				'' => __( 'No', 'acf_vf' ),
			),
			'layout'		=> 'horizontal',
		));

		if ( false && ! $this->drafts ){
			echo '<em>';
			_e( 'Warning', 'acf_vf' );
			echo ': <code>ACF_VF_DRAFTS</code> ';
			_e( 'has been set to <code>false</code> which overrides field level configurations', 'acf_vf' );
			echo '.</em>';
		}

		?>
		<tr class="acf-field" data-setting="validated_field" data-name="sub_field">
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

							if ( ! isset( $sub_field['type'] ) || empty( $sub_field['type'] ) ){
								$sub_field['type'] = 'text';
							}


							if ( ! isset( $sub_field['function'] ) || empty( $sub_field['function'] ) ){
								$sub_field['function'] = 'none';
							}

							// Validated Field Type
							acf_render_field_setting( $sub_field, array(
								'label'			=> __('Field Type', 'acf_vf'),
								'instructions'	=> '',
								'type'			=> 'select',
								'name'			=> 'type',
								'prefix'		=> $sub_field['prefix'],
								'choices' 		=> $fields_names,
								'required'		=> true
							), 'tr' );			

							// Render the Sub Field
							acf_render_field_settings( $sub_field );

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

		// Input Mask
		acf_render_field_setting( $field, array(
			'label'			=> __('Input mask', 'acf_vf'),
			'instructions'	=> __( 'Use &#39;a&#39; to match A-Za-z, &#39;9&#39; to match 0-9, and &#39;*&#39; to match any alphanumeric.', 'acf_vf' ) . ' <a href="http://digitalbush.com/projects/masked-input-plugin/" target="_new">' . __( 'More info.', 'acf_vf' ) . '</a>.',
			'type'			=> 'text',
			'name'			=> 'mask',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['mask'],
			'layout'		=> 'horizontal',
		));

		// Validation Function
		acf_render_field_setting( $field, array(
			'label'			=> __('Validation Function', 'acf_vf'),
			'instructions'	=> __( "How should the field be server side validated?", 'acf_vf' ),
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
				<label><?php _e( 'Pattern', 'acf_vf' ); ?></label>
				<p class="description">	
				<small>
				<div class="validation-info">
					<div class='validation-type regex'>
						<?php _e( 'Pattern match the input using', 'acf_vf' ); ?> <a href="http://php.net/manual/en/function.preg-match.php" target="_new">PHP preg_match()</a>.
						<br />
					</div>
					<div class='validation-type php'>
						<ul>
							<li><?php _e( "Use any PHP code and return true or false. If nothing is returned it will evaluate to true.", 'acf_vf' ); ?></li>
							<li><?php _e( 'Available variables', 'acf_vf' ); ?> - <code>$post_id</code>, <code>$post_type</code>, <code>$name</code>, <code>$value</code>, <code>$prev_value</code>, <code>$inputs</code>, <code>&amp;$message</code>.</li>
							<li><code>$inputs</code> is an array() with the keys 'field', 'value', and 'prev_value'.</li>
							<li><code>&amp;$message</code> (<?php _e('is returned to the UI.', 'acf_vf' ); ?>).</li>
							<li><?php _e( 'Example', 'acf_vf' ); ?>: 
							<small><code><pre>if ( empty( $value ) ){
  $message = 'required!'; 
  return false;
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
					'label'			=> __('Pattern', 'acf_vf'),
					'instructions'	=> '',
					'type'			=> 'textarea',
					'name'			=> 'pattern',
					'prefix'		=> $field['prefix'],
					'value'			=> $field['pattern'],
					'layout'		=> 'horizontal',
					'class'			=> 'editor',
				));

				?>
				<div id="<?php echo $html_key; ?>-editor" class='ace-editor' style="height:200px;"><?php echo $field['pattern']; ?></div>
			</td>
		</tr>
		<?php

		// Error Message
		acf_render_field_setting( $field, array(
			'label'			=> __('Error Message', 'acf_vf'),
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
				'post_key'		=> __( 'Unique For Post Type', 'acf_vf' ) . ' -&gt; ' . __( 'Key', 'acf_vf' ),
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
			'label'			=> __( 'Apply to...?', 'acf_vf' ),
			'instructions'	=> __( "Make sure this value is unique for the checked post statuses.", 'acf_vf' ),
			'type'			=> 'checkbox',
			'name'			=> 'unique_statuses',
			'prefix'		=> $field['prefix'],
			'value'			=> $field['unique_statuses'],
			'choices' 		=> $choices,
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

		$is_new = $pagenow=='post-new.php';

		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );

		?>
		<div class="validated-field">
			<?php
			if ( $field['read_only'] ){

				?>
				<p>
				<?php 

				// Buffer output
				ob_start();

				// Render the subfield
				acf_render_field_wrap( $sub_field );

				// Try to make the field readonly
				$contents = ob_get_contents();
				$contents = preg_replace("~<(input|textarea|select)~", "<\${1} disabled=true read_only", $contents );

				// Stop buffering
				ob_end_clean();

				// Return our (hopefully) readonly input.
				echo $contents;

				?>
				</p>
				<?php

			} else {
				acf_render_field_wrap( $sub_field );
			}
			?>
		</div>
		<?php
		if ( ! empty( $field['mask'] ) && ( $is_new || ( isset( $field['read_only'] ) && ! $field['read_only'] ) ) ) { 

			?>
			<script type="text/javascript">
				jQuery(function($){
				   $('[name="<?php echo str_replace('[', '\\\\[', str_replace(']', '\\\\]', $field['name'])); ?>"]').mask('<?php echo $field['mask']?>');
				});
			</script>
			<?php
			
		}
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
		wp_register_script( 'acf-validated-field-input', plugins_url() . "/validated-field-for-acf/js/input{$min}.js", array('acf-validated-field'), $this->settings['version'] );
		wp_register_script( 'jquery-masking', plugins_url() . "/validated-field-for-acf/js/jquery.maskedinput{$min}.js", array( 'jquery' ), $this->settings['version']);
		wp_register_script( 'sh-core', plugins_url() . '/validated-field-for-acf/js/shCore.js', array( 'acf-input' ), $this->settings['version'] );
		wp_register_script( 'sh-autoloader', plugins_url() . '/validated-field-for-acf/js/shAutoloader.js', array( 'sh-core' ), $this->settings['version']);
		
		// enqueue scripts
		wp_enqueue_script( array(
			'jquery',
			'jquery-ui-core',
			'jquery-ui-tabs',
			'jquery-masking',
			'acf-validated-field',
			'acf-validated-field-input',
		));

		if ( $this->debug ){ 
			add_action( $this->frontend? 'wp_head' : 'admin_head', array( &$this, 'debug_head' ), 20 );
		}

		if ( ! $this->drafts ){ 
			add_action( $this->frontend? 'wp_head' : 'admin_head', array( &$this, 'drafts_head' ), 20 );
		}

		if ( $this->frontend && ! is_admin() ){
			add_action( 'wp_head', array( &$this, 'frontend_head' ), 20 );
		}
	}

	function debug_head(){
		// set debugging for javascript
		echo '<script type="text/javascript">vf.debug=true;</script>';
	}

	function drafts_head(){
		// don't validate drafts for anything
		echo '<script type="text/javascript">vf.drafts=false;</script>';
	}

	function frontend_head(){
		// indicate that this is validating the front end
		echo '<script type="text/javascript">//vf.frontend=true;</script>';
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
		wp_enqueue_style( 'font-awesome', '//netdna.bootstrapcdn.com/font-awesome/4.0.3/css/font-awesome.min.css', array(), $this->settings['version'] );
		wp_enqueue_style( 'acf-validated_field', plugins_url() . '/validated-field-for-acf/css/input.css', array( 'acf-input' ), $this->settings['version'] ); 

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
		wp_enqueue_script( 'ace-editor', '//cdnjs.cloudflare.com/ajax/libs/ace/1.1.3/ace.js', array(), $this->settings['version'] );
	}

	/*
	*  field_group_admin_head()
	*
	*  This action is called in the admin_head action on the edit screen where your field is edited.
	*  Use this action to add css and javascript to assist your create_field_options() action.
	*
	*  @info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_head
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function field_group_admin_head(){ }

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
		global $currentpage;
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		$sub_field = apply_filters( 'acf/load_field/type='.$sub_field['type'], $sub_field );
		$field['sub_field'] = $sub_field;
		if ( $field['read_only'] && $currentpage == 'edit.php' ){
			$field['label'] = $field['label'].' <i class="fa fa-link" title="'. __('Read only', 'acf_vf' ) . '"></i>';
		}
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
	*  @param	$post_id - the field group ID (post_type = acf)
	*
	*  @return	$field - the modified field
	*/
	function update_field( $field ){
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		$sub_field = apply_filters( 'acf/update_field/type='.$sub_field['type'], $sub_field, $post_id );
		$field['sub_field'] = $sub_field;
		return $field;
	}
}

new acf_field_validated_field();
endif;
