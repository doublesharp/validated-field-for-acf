<?php
if ( class_exists( 'acf_Field' ) && !class_exists( 'acf_field_validated_field' ) ) :
	class acf_field_validated_field extends acf_field
	{
		// vars
		public $slug,
			$config,
			$settings,				// will hold info such as dir / path
			$defaults,				// will hold default field options
			$sub_defaults,			// will hold default sub field options
			$disabled,
			$debug,					// if true, don't use minified and confirm form submit				  
			$drafts,
			$is_frontend_css,
			$link_to_tab,
			$link_to_field_group,
			$confirm_row_removal;

		public static $SQUOT = '%%squot%%';
		public static $DQUOT = '%%dquot%%';
		public static $fields_with_id_values = array( 'post_object', 'page_link', 'relationship', 'taxonomy', 'user' );

		protected $validation_count;
		protected $min;

		function __construct()
		{
			// vars
			$this->slug 			= 'acf-validated-field';
			$this->name				= 'validated_field';
			$this->label 			= __( 'Validated Field', 'acf_vf' );
			$this->category			= __( 'Basic', 'acf' );

			// settings - use the field key to get the default value
			$this->enabled 			= $this->get_option( 'field_23d6q395ad4ds' );		// enabled
			$this->drafts			= $this->get_option( 'field_55d6bc95a04d4' );		// drafts
			$this->frontend_css 	= $this->get_option( 'field_55d6c123b3ae1' );		// is_frontend_css
			$this->debug 			= $this->get_option( 'field_55d6bc95a04d4' );		// debug
			$this->link_to_tab 		= $this->get_option( 'field_5606d0fdddb99' );		// link_to_tab
			$this->link_to_field_group = $this->get_option( 'field_5606d206ddb9a' );	// link_to_field_group_editor
			$this->confirm_row_removal = $this->get_option( 'field_960cdafeedb99' );	// confirm_row_removal

			// keep track of when this plugin started being used.
			if ( false == ( $install_date = get_option( 'acf_vf_install_date', false ) ) ) {
				update_option( 'acf_vf_install_date', date( 'Y-m-d h:i:s' ) );
			}

			$this->defaults = array( 
				'read_only' 		=> 'no',
				'hidden'			=> 'no',
				'mask'				=> '',
				'mask_autoclear' 	=> 'no',
				'mask_placeholder' 	=> '_',
				'function'			=> 'none',
				'pattern'			=> '',
				'message'			=>  __( 'Validation failed.', 'acf_vf' ),
				'unique'			=> 'non-unique',
				'unique_multi'		=> 'each_value',
				'unique_statuses' 	=> apply_filters( 'acf_vf/unique_statuses', 
					array( 'publish', 'future', 'draft', 'pending' ) 
				),
				'drafts'			=> 'yes'
			);

			$this->sub_defaults = array( 
				'type'		=> 'text',
				'key'		=> '',
				'name'		=> '',
				'_name'		=> '',
				'id'		=> '',
				'value'		=> '',
				'field_group' => '',
				'readonly' => '',
				'disabled' => '',
			);

			// Used in style and script enqueue
			$this->min = ( !$this->debug )? '.min' : '';

			// Handle deletes for validated fields by invoking action on sub_field
			add_action( "acf/delete_value/type=validated_field", array( $this, 'delete_value' ), 10, 3 );

			// Sort and store field values for later comparison
			add_filter( 'acf/update_value', array( $this, 'update_metadata_helpers' ), 20, 3 );
			// Remove helper fields when a value is deleted
			add_filter( 'acf/delete_value', array( $this, 'delete_metadata_helpers' ), 20, 3 );

			// Add admin notices, if the user can do anything about it
			if ( current_user_can( 'manage_plugins' ) || current_user_can( 'manage_options' ) ) {
				add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			}

			// Handle Repeaters with read only fields
			add_action( 'acf/render_field/type=repeater', array( $this, 'repeater_start' ), 1 );
			add_action( 'acf/render_field/type=repeater', array( $this, 'repeater_end' ), 999 );

			// Track validations
			add_filter( 'acf/validate_value/type=validated_field', array( $this, 'count_validation' ) );
			add_action( 'acf/validate_save_post', array( $this, 'validate_save_post' ) );

			parent::__construct();

			// Settings
			$this->settings = array( 
				'path'		=> apply_filters( 'acf/helpers/get_path', __FILE__ ),
				'dir'		=> apply_filters( 'acf/helpers/get_dir', __FILE__ ),
				'version'	=> ACF_VF_VERSION,
			);
		}

		/*
		* get_option()
		*
		* This function gets a Validated Field option value.
		*
		* @type function
		* 
		* @param  $key ( string ) - the option value key to load
		*
		* @return $value ( mixed ) - the option value
		*
		*/
		public function get_option( $key ){
			return  get_field( "{$key}", 'option' );
		}

		/*
		* count_validation()
		*
		* This function counts the number of times the validation is run.
		*
		* @type	filter
		* 
		* @param  $valid ( bool|string ) - the validity of the current field or an error message
		*
		* @return $valid ( bool|string ) - passes through the validation
		*
		*/
		function count_validation( $valid ){
			$this->validation_count++;
			return $valid;
		}

		/*
		* validate_save_post()
		*
		* This action is called when the validation is complete. It is used to save the validation count.
		*
		* @type	action
		* 
		*/
		function validate_save_post(){
			$validation_count = get_option( 'acf_vf_validation_count', 0 ) + $this->validation_count;
			update_option( 'acf_vf_validation_count', $validation_count );
		}

		/*
		* values_are_ids()
		*
		* This function will test if a field's value, or sub value if it is a Validated Field, contains Object IDs.
		*
		* @type	function
		* 
		* @param  $field ( array )- the current ACF field
		*
		* @return $in_array ( bool ) - the function returns true if the field value contains Object IDs or false if it does not.
		*
		*/
		public function values_are_ids( $field )
		{
			// we need to map down for validated fields
			$_field = ( $field['type'] == 'validated_field' )? $field['sub_field'] : $field;
			// these field type values are IDs
			$in_array = in_array( $_field['type'], self::$fields_with_id_values );
			// return the boolean
			return $in_array;
		}

		/*
		* update_metadata_helpers()
		*
		* This filter will test if a field's value is an array, and if so save a sorted version of it for comparison queries.
		*
		* @type	filter
		* 
		* @param  $value ( mixed )- the field value.
		* @param  $the_id ( int ) - the ID of the Object attached to this ACF Field and value.
		* @param  $field ( array ) - the current ACF field.
		*
		* @return $value ( mixed ) - does not modify the value, uses it to save a sorted key/value to the database.
		*
		*/
		public function update_metadata_helpers( $value, $the_id, $field )
		{

			if ( $field['type'] == 'repeater' ) {
				return $value;
			}

			// Copy the value so we can return an unmodified version
			$_value = $value;

			// Alias to the subfield if this is a validated field
			$_field = ( $field['type']=='validated_field' ) ? $field['sub_field'] : $field;

			$meta_key = $this->get_field_name( $field, $the_id );

			// Does the field type indicate that it has Object ID values?
			$values_are_ids = $this->values_are_ids( $_field );

			// Create the relationship key for the value Object type
			if ( $values_are_ids ) {
				// get the current field name and append the suffix
				if ( $_field['type'] == 'user' ) { 
					$related_key = "{$meta_key}__u";
				} elseif ( $_field['type'] == 'taxonomy' ) {
					$related_key = "{$meta_key}__t";
				} else {
					$related_key = "{$meta_key}__p";
				}

				if ( !is_array( $_value ) ){
					$_value = array( $_value );
				}
			} else {
				$related_key = "{$meta_key}__x";
			}

			// Append an "s" for the sorted keys
			$sorted_key = "{$related_key}s";

			// use this to determine what to delete/update
			$id_info = $this->get_id_info( $the_id );

			// delete existing relationship keys
			$this->delete_metadata_helpers( $the_id, $meta_key, $field );

			// if it's an array, sort and save
			if ( is_array( $_value ) && !empty( $_value ) ) {
				// these field types all store numeric IDs
				if ( $values_are_ids ) {
					$_value = array_map( 'intval', $_value );
					sort( $_value, SORT_NUMERIC );	
				} else {
					sort( $_value );
				}

				// Add the sorted values
				switch ( $id_info['type'] ) {
				case 'user':
					add_user_meta( $id_info['the_id'], $sorted_key, $_value, false );
					break;
				case 'option':
					add_option( $sorted_key, $_value );
					break;
				default:
					// might be multiple IDs for WPML
					foreach ( $id_info['the_id'] as $post_id ) {
						add_post_meta( $post_id, $sorted_key, $_value, false );
					}
					break;
				}

				// Process object relationships
				// we want to track single values too, just in case
				$_values = is_array( $_value )? $_value : array( $_value );
				// Loop to add a meta value for each Object ID
				foreach ( $_values as $obj_or_id ) {
					// if filter is passing through an object, extract the ID
					if ( $values_are_ids ) {
						if ( is_object( $obj_or_id ) ) {
							if ( isset( $obj_or_id->term_id ) ) {
								// taxonomy object
								$_id = $obj_or_id->term_id;		
							} else {
								// post and user object
								$_id = $obj_or_id->ID;
							}
						} else {
							// Single ID value
							$_id = ( int ) $obj_or_id;
						}
					} else {
						$_id = $obj_or_id;
					}

					switch ( $id_info['type'] ) {
					case 'user':
						add_user_meta( $id_info['the_id'], $related_key, $_id, false );
						break;
					case 'option':
						add_option( $related_key, $_id );
						break;
					default:
						// might be multiple IDs for WPML
						foreach ( $id_info['the_id'] as $post_id ) {
							add_post_meta( $post_id, $related_key, $_id, false );
						}
						break;
					}
				}
			}

			// the value has not been modified
			return $value;
		}

		/*
		* delete_metadata_helpers()
		*
		* This action is called when a field value is deleted. Loop through all possible suffixes to clean up the database.
		*
		* @type	action
		* 
		* @param  $the_id ( int|string ) - the ID of the Object attached to this ACF Field and value.
		* @param  $key ( string )- the current meta key.
		* @param  $field ( array ) - the current ACF field.
		*
		*/
		public function delete_metadata_helpers( $the_id, $key, $field )
		{
			// use this to determine what to delete/update
			$id_info = $this->get_id_info( $the_id );

			// there might be some orphaned data laying around, clean it up by running through all suffixes.
			foreach ( array( "__u", "__t", "__p", "__x" ) as $suffix ) {
				// delete relationship keys
				switch ( $id_info['type'] ) {
				case 'user':
					delete_user_meta( $id_info['the_id'], "{$key}{$suffix}" );
					delete_user_meta( $id_info['the_id'], "{$key}{$suffix}s" );
					break;
				case 'option':
					delete_option( "{$key}{$suffix}" );
					delete_option( "{$key}{$suffix}s" );
					break;
				default:
					// might be multiple IDs for WPML
					foreach ( $id_info['the_id'] as $post_id ) {
						delete_post_meta( $post_id, "{$key}{$suffix}" );
						delete_post_meta( $post_id, "{$key}{$suffix}s" );
					}
					break;
				}
			}
		}

		/*
		* maybe_sort_value()
		*
		* This function will test if a field's value is an array, and if so sort it. Fields with numeric types are converted to ints before sorting.
		*
		* @type	function
		* 
		* @param  $value - the field value ( from meta table )
		* @param  $field - the current ACF field
		*
		* @return $_value - the sorted value if it is an array, otherwise the unmodified value is returned.
		*
		*/
		protected function maybe_sort_value( $value, $field )
		{
			$_value = $value;
			if ( is_array( $_value ) ) {
				if ( $this->values_are_ids( $field ) ) {
					// convert strings to ints
					$_value = array_map( 'intval', $_value );
					// sort numerically
					sort( $_value, SORT_NUMERIC ); 
				} else {
					// generic sort
					sort( $_value );
				}
			}
			return $_value;
		}

		/*
		* admin_notices()
		*
		* Show messages to admins
		*
		* @type	action
		*
		*/
		public function admin_notices()
		{

			if ( current_user_can( 'manage_options' ) ) {

				if ( apply_filters( 'acf_vf/admin_notices/upgrade', false ) ){
					?>
					<div class="update-nag">
						<p><?php printf( __( 'Validated Field for Advanced Custom Fields needs to <a href="%1$s">upgrade your database</a> to function correctly!', 'acf_vf' ), apply_filters( 'acf_vf/admin/settings_url', '' ) . '#database-updates!' ); ?></p>
					</div>
					<?php
				}

			}
		}

		/*
		* ajax_admin_notices()
		*
		* Mark an admin notice as viewed so that it no longer appears.
		*
		* @type	ajax
		* 
		*/
		public function ajax_admin_notices()
		{
			if ( !current_user_can( 'manage_options' ) || !isset( $_POST['notice'] ) ) {
				json_encode( array( 'error' ) );
			} else {	
				update_option( "acf_vf_{$_POST['notice']}", 'yes' );
				echo json_encode( array( 'success' ) );
			}
			exit;
		}

		/*
		* repeater_start()
		*
		* Start an output buffer so that we can ( maybe ) modify the repeater field if any of its sub fields are read only
		*
		* @type	action
		* 
		* @param  $field - the current ACF field
		*
		*/
		public function repeater_start( $field )
		{
			// Buffer output
			ob_start();
		}

		/*
		* repeater_end()
		*
		* Capture the output of repeater fields and ( maybe ) modify them if they contain read only fields. Also see repeater_start().
		*
		* @type	action
		* 
		* @param  $field - the current ACF field
		*
		*/
		public function repeater_end( $field )
		{
			$contains_read_only = false;
			foreach ( $field['sub_fields'] as $sub_field ) {
				if ( $sub_field['type'] == 'validated_field' ) {
					if ( !$this->check_value( 'yes', $sub_field['hidden'] ) ) {
						if ( $this->check_value( 'yes', $sub_field['read_only'] ) ) {
							$contains_read_only = true;
							break;
						}
					}
				}
			}
		
			// get the contents from the buffere
			$contents = ob_get_contents();

			// modify as needed
			if ( $contains_read_only ) {
				$contents = preg_replace( '~( add-row|remove-row )~', '${1}-disabled disabled" disabled="disabled" title="Read only"', $contents );
			}

			// Stop buffering
			ob_end_clean();

			// Output the modified contents
			echo $contents;
		}


		/*
		* load_field()
		*
		* This filter is appied to the $field after it is loaded from the database
		*
		* @type	filter
		* @since	3.6
		* @date	23/01/13
		*
		* @param	$field - the field array holding all the field options
		*
		* @return	$field - the field array holding all the field options
		*/
		function load_field( $field )
		{
			global $currentpage, $pagenow, $post;

			// determine if this is a new post or an edit
			$is_new = $pagenow=='post-new.php';
			$post_type = get_post_type( $post );

			$field = $this->setup_field( $field );
			$sub_field = $this->setup_sub_field( $field );
			$sub_field = apply_filters( 'acf/load_field/type='.$sub_field['type'], $sub_field );

			// The relationship field gets settings from the sub_field so we need to return it since it effectively displays through this method.
			if ( 'relationship' == $sub_field['type'] && isset( $_POST['action'] ) ) {
				switch ( $_POST['action'] ) {
				case 'acf/fields/relationship/query_posts':
					// ACF4
					if ( $sub_field['taxonomy'][0] == 'all' ) {
						unset( $sub_field['taxonomy'] );
					}
					return $sub_field;
				 break;

				case 'acf/fields/relationship/query':
					// ACF5
					$sub_field['name'] = $sub_field['_name'];
					return $sub_field;
				 break;
				}
			}

			$field['sub_field'] = $sub_field;

			$field['render_field'] = apply_filters( 'acf_vf/render_field', true, $field, $is_new );
			if ( !$is_new && $post_type != 'acf-field-group' && $field['render_field'] === 'readonly' ) {
				$field['read_only'] = 'yes';
			}

			// this can be off if the permissions plugin is disabled
			$read_only_type = apply_filters( 'acf_vf/create_field/read_only/type', 'radio' );
			if ( $read_only_type == 'radio' && is_array( $field['read_only'] ) ) {
				// default to read only for everyone unless it's off ( better security )
				if ( $field['read_only'][0] == 'no' ) {
					$field['read_only'] = 'no';
				} else {
					$field['read_only'] = 'yes';
				}
			}

			// If the field is being loaded NOT on the field group editor, update the label if it is read only
			if ( !in_array( get_post_type(), array( 'acf', 'acf-field-group' ) ) ) {
				// Show icons for read-only fields
				if ( self::check_value( 'yes', $field['read_only'] ) ) {
					$field['label'] .= sprintf( ' ( <i class="fa fa-ban" style="color:red;" title="%1$s"><small><em> %1$s</em></small></i> )', __( 'Read only', 'acf_vf' ) );
				}
			}

			// Disable the mask if the sub field is anything but these field types...
			if ( !in_array( $sub_field['type'], array( 'text', 'url', 'password' ) ) ) {
				$field['mask'] = false;
			}

			// Just avoid using any type of quotes in the db values
			$field['pattern'] = str_replace( self::$SQUOT, "'", $field['pattern'] );
			$field['pattern'] = str_replace( self::$DQUOT, '"', $field['pattern'] );

			return $field;
		}

		/*
		* update_field()
		*
		* This filter is appied to the $field before it is saved to the database
		*
		* @type	filter
		* @since	3.6
		* @date	23/01/13
		*
		* @param	$field - the field array holding all the field options
		* @param	$post_id - the field group ID ( post_type = acf )
		*
		* @return	$field - the modified field
		*/
		function update_field( $field, $post_id=false )
		{
			$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );

			// Process filters that are subtype specific for v4/v5
			$sub_field = $post_id?
			apply_filters( 'acf/update_field/type='.$sub_field['type'], $sub_field, $post_id ) :
			apply_filters( 'acf/update_field/type='.$sub_field['type'], $sub_field );

			// Set the filters sub_field to the parent
			$field['sub_field'] = $sub_field;

			// Just avoid using any type of quotes in the db values
			$field['pattern'] = str_replace( "'", self::$SQUOT, $field['pattern'] );
			$field['pattern'] = str_replace( '"', self::$DQUOT, $field['pattern'] );

			return $field;
		}

		/*
		* load_value()
		*
		* This filter is appied to the $value after it is loaded from the db
		*
		* @type	filter
		* @since	3.6
		* @date	23/01/13
		*
		* @param	$value - the value found in the database
		* @param	$post_id - the $post_id from which the value was loaded from
		* @param	$field - the field array holding all the field options
		*
		* @return	$value - the value to be saved in te database
		*/
		function load_value( $value, $post_id, $field )
		{
			$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
			return apply_filters( 'acf/load_value/type='.$sub_field['type'], $value, $post_id, $sub_field );
		}

		/*
		* update_value()
		*
		* This filter is appied to the $value before it is updated in the db
		*
		* @type	filter
		* @since	3.6
		* @date	23/01/13
		*
		* @param	$value - the value which will be saved in the database
		* @param	$post_id - the $post_id of which the value will be saved
		* @param	$field - the field array holding all the field options
		*
		* @return	$value - the modified value
		*/
		function update_value( $value, $post_id, $field )
		{
			$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
			return apply_filters( 'acf/update_value/type='.$sub_field['type'], $value, $post_id, $sub_field );
		}

		/*
		* delete_value()
		*
		* This action is called when a value is deleted from the database
		*
		* @type	 action
		* @param	 $post_id - the post, user, or option id
		* @param	 $key - the meta key
		* @param	 $field - the field the value is being deleted from
		*
		*/
		function delete_value( $the_id, $key, $field )
		{
			$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
			do_action( "acf/delete_value/type={$sub_field['type']}", $the_id, $key, $sub_field );
		}

		/*
		* format_value()
		*
		* This filter is appied to the $value after it is loaded from the db and before it is passed to the create_field action
		*
		* @type	filter
		* @since	3.6
		* @date	23/01/13
		*
		* @param	$value	- the value which was loaded from the database
		* @param	$post_id - the $post_id from which the value was loaded
		* @param	$field	- the field array holding all the field options
		*
		* @return	$value	- the modified value
		*/
		function format_value( $value, $post_id, $field )
		{
			$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
			return apply_filters( "acf/format_value/type={$sub_field['type']}", $value, $post_id, $sub_field );
		}

		/*
		* format_value_for_api()
		*
		* This filter is appied to the $value after it is loaded from the db and before it is passed back to the api functions such as the_field
		*
		* @type	filter
		* @since	3.6
		* @date	23/01/13
		*
		* @param	$value	- the value which was loaded from the database
		* @param	$post_id - the $post_id from which the value was loaded
		* @param	$field	- the field array holding all the field options
		*
		* @return	$value	- the modified value
		*/
		function format_value_for_api( $value, $post_id, $field )
		{
			$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
			return apply_filters( "acf/format_value_for_api/type={$sub_field['type']}", $value, $post_id, $sub_field );
		}

		/*
		* field_group_admin_enqueue_scripts()
		*
		* This action is called in the admin_enqueue_scripts action on the edit screen where your field is edited.
		* Use this action to add css + javascript to assist your create_field_options() action.
		*
		* $info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_enqueue_scripts
		* @type	action
		* @since  3.6
		* @date	23/01/13
		*/
		public function field_group_admin_enqueue_scripts(){	  
			wp_deregister_style( 'font-awesome' );
			wp_enqueue_style( 'font-awesome', plugins_url( "../common/css/font-awesome/css/font-awesome{$this->min}.css", __FILE__ ), array(), '4.4.0', true ); 
			wp_enqueue_style( 'acf-validated-field-admin', plugins_url( "../common/css/admin.css", __FILE__ ), array( ), ACF_VF_VERSION );
			
			wp_enqueue_script( 'ace-editor', plugins_url( "../common/js/ace{$this->min}/ace.js", __FILE__ ), array(), '1.2' );
			wp_enqueue_script( 'ace-ext-language_tools', plugins_url( "../common/js/ace{$this->min}/ext-language_tools.js", __FILE__ ), array(), '1.2' );

			if ( $this->link_to_field_group ){
				wp_enqueue_script( 'acf-validated-field-link-to-field-group', plugins_url( "../common/js/link-to-field-group{$this->min}.js", __FILE__ ), array( 'jquery', 'acf-field-group' ), ACF_VF_VERSION, true );
			}
		}

		// UTIL FUNCTIONS

		/*
		* check_value()
		*
		* Checks for a value in an array, or just a comparison if it is not an array. 
		*
		* @type	function
		* @return $is_match ( bool ) - returns true if the $value is a match
		*/
		protected static function check_value( $value, $obj_or_array )
		{
			if ( is_array( $obj_or_array ) ) {
				return in_array( $value, $obj_or_array );
			} else {
				return $value == $obj_or_array;
			}
		}

		/*
		* get_unique_form_error()
		*
		* Generates an error message for a given field and value
		*
		* @type	function
		*
		* @param	$field	- the field array holding all the field options
		* @param	$value	- the value of the field
		*
		* @return $error ( string ) - the error message
		*/
		protected function get_unique_form_error( $field, $value )
		{
			switch ( $field['unique'] ){
			case 'global';
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on all posts.', 'acf_vf' ), $this->get_value_text( $value, $field ) );
			 break;
			case 'post_type':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on this post type.', 'acf_vf' ), $this->get_value_text( $value, $field ) );
			 break;
			case 'this_post':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on this post.', 'acf_vf' ), $this->get_value_text( $value, $field ) );
			 break;
			case 'post_key':
			case 'this_post_key':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for %2$s.', 'acf_vf' ), $this->get_value_text( $value, $field ), $field['label'] );
			 break;
			}
			return false;
		}

		/*
		* get_id_info()
		*
		* Computes information based on the ACF post_id
		*
		* @type	function
		*
		* @param	$the_id  - and ID that might be a Post, User, or Option
		*
		* @returns $info ( mixed ) - an array of info including a cleaned ID and type
		*/
		protected function get_id_info( $the_id )
		{
			$info = array();

			// are we editting a user?
			if ( strpos( $the_id, 'user_' ) === 0 ) { 
				$info['type'] = 'user'; 
				$info['the_id'] = ( int ) str_replace( 'user_', '', $the_id );
			} else
			// are we editting site options?
			if ( $the_id == 'options' ) { 
				$info['type'] = 'option'; 
			} else {
				// if it's not a user or options, it's a post
				$info['type'] = 'post';
				$info['the_id'] = $this->maybe_get_wpml_ids( $the_id );
			}

			return $info;
		}

		/*
		* is_value_unique()
		*
		* Generates a SQL query to determine if a field value is unique ( enough ) based on the selected options
		*
		* @type	function
		*
		* @param	$post_id  - and ID that might be a Post, User, or Option
		* @param	$field	- the field array holding all the field options
		* @param	$parent_field	- the parent field array if available
		* @param	$value	- the value of the field
		*
		* @returns $info ( mixed ) - an array of info including a cleaned ID and type
		*/
		protected function is_value_unique( $post_id, $field, $parent_field, $is_frontend, $value )
		{
			global $wpdb;

			$id_info = $this->get_id_info( $post_id );

			// are we editting a user?
			$is_user = $id_info['type'] == 'user';
			// are we editting site options?
			$is_options = $id_info['type'] == 'options';
			// if it's not a user or options, it's a post
			$is_post = $id_info['type'] == 'post';

			// for posts we can filter by post type
			if ( !$is_user && !$is_options ) {
				$post_type = get_post_type( $post_id );	
			}

			// alias to the subfield if this is a validated field
			$_field = ( $field['type']=='validated_field' ) ? $field['sub_field'] : $field;

			// We have to copy name to _name for ACF5
			$field_name = $this->get_field_name( $_field );

			// Repeaters and Flex Content keys are included in the sub_key
			$is_indexed = isset( $parent_field ) && in_array( $parent_field['type'], array( 'repeater', 'flexible_content' ) );

			// modify keys for options and repeater fields
			$meta_key = $is_options ? 'options_' : '';
			$meta_key.= $is_indexed ? $parent_field['name'] . '_%%_' : '';
			$meta_key.= $field_name;

			// Does the field type indicate that it has Object ID values?
			$values_are_ids = $this->values_are_ids( $_field );

			// Create the relationship key for the value Object type
			if ( $values_are_ids ) {
				// get the current field name and append the suffix
				if ( $_field['type'] == 'user' ) { 
					$meta_key_r = "{$meta_key}__u";
				} elseif ( $_field['type'] == 'taxonomy' ) {
					$meta_key_r = "{$meta_key}__t";
				} else {
					$meta_key_r = "{$meta_key}__p";
				}
			} else {
				$meta_key_r = "{$meta_key}__x";
			}

			// Append "s" for the sorted keys
			$meta_key_s = "{$meta_key_r}s";

			// we only want to validate posts with these statuses
			$status_in = "'" . implode( "','", $field['unique_statuses'] ) . "'";

			if ( $is_user ) {
				// USERS: set up queries for the user table
				$post_ids = array( (int ) str_replace( 'user_', '', $post_id ) );
				$table_id = 'user_id';
				$table_key = 'meta_key';
				$table_value = 'meta_value';
				$sql_prefix = <<<SQL
				SELECT m.umeta_id AS meta_id, m.{$table_id} AS {$table_id}, u.user_login AS title 
				FROM {$wpdb->usermeta} m 
				JOIN {$wpdb->users} u 
					ON u.ID = m.{$table_id}
SQL;
			} elseif ( $is_options ) {
				// OPTIONS: set up queries for the options table
				$table_id = 'option_id';
				$table_key = 'option_name';
				$table_value = 'option_value';
				$post_ids = array( $post_id );
				$sql_prefix = <<<SQL
				SELECT o.option_id AS meta_id, o.{$table_id} AS {$table_id}, o.option_name AS title 
				FROM {$wpdb->options} o
SQL;
			} else {
				// POSTS: set up queries for the posts table
				$post_ids = $this->maybe_get_wpml_ids( $post_id );
				$table_id = 'post_id';
				$table_key = 'meta_key';
				$table_value = 'meta_value';
				$sql_prefix = <<<SQL
				SELECT m.meta_id AS meta_id, m.{$table_id} AS {$table_id}, p.post_title AS title 
				FROM {$wpdb->postmeta} m 
				JOIN {$wpdb->posts} p 
					ON p.ID = m.{$table_id} 
					AND p.post_status IN ( $status_in )
SQL;
			}

			if ( $field['unique_multi'] == 'values' ){
				// sort the value if it's an array before we compare
				$_value = $this->maybe_sort_value( $value, $field );
			} else {
				$_value = $value;
			}

			if ( is_array( $_value ) ){
				switch ( $field['unique_multi'] ) {
					case 'exact_order':
						$sql_meta_keys = "{$table_key} LIKE '{$meta_key}'";
						$sql_meta_keys_not = "{$table_key} NOT LIKE '{$meta_key}'";
						break;
					case 'values':
						$sql_meta_keys = "( {$table_key} LIKE '{$meta_key}' OR {$table_key} LIKE '{$meta_key_s}' OR {$table_key} LIKE '{$meta_key_r}' )";
						$sql_meta_keys_not = "( {$table_key} NOT LIKE '{$meta_key}' AND {$table_key} NOT LIKE '{$meta_key_s}' AND {$table_key} NOT LIKE '{$meta_key_r}' )";
						break;
					case 'each_value':
						$sql_meta_keys = "( {$table_key} LIKE '{$meta_key}' OR {$table_key} LIKE '{$meta_key_r}' )";
						$sql_meta_keys_not = "( {$table_key} NOT LIKE '{$meta_key}' AND {$table_key} NOT LIKE '{$meta_key_r}' )";
						break;
				}
			} else {
				$sql_meta_keys = "{$table_key} LIKE '{$meta_key}'";
				$sql_meta_keys_not = "{$table_key} NOT LIKE '{$meta_key}'";
			}

			if ( $is_post && in_array( $field['unique'], array( 'global' ) ) ) {
				// POSTS: search all post types except ACF
				$this_sql = <<<SQL
				{$sql_prefix} 
					AND p.post_type != 'acf' 
				WHERE (	 
					{$table_id} NOT IN ( [IN_NOT_IN] )
					OR (
						{$table_id} IN ( [IN_NOT_IN] ) 
						AND {$sql_meta_keys_not}
					 ) 
				 ) AND (
					{$table_value} = %s
					OR {$table_value} IN ( [IN_NOT_IN_VALUES] )
				 )
SQL;
			} elseif ( $is_options && in_array( $field['unique'], array( 'global', 'post_type' ) ) ) {
				// OPTIONS: search options for dupe values in any key
				$this_sql = <<<SQL
				{$sql_prefix}
				WHERE 
				{$sql_meta_keys_not}
				AND (
					{$table_value} = %s
					OR {$table_value} IN ( [IN_NOT_IN_VALUES] )
				 )
SQL;
			} elseif ( $is_user && in_array( $field['unique'], array( 'global', 'post_type' ) ) ) {
				// USERS: search users for dupe values in any key
				$this_sql = <<<SQL
				{$sql_prefix}
				WHERE (	 
				{$table_id} NOT IN ( [IN_NOT_IN] )
				AND {$sql_meta_keys_not}
				AND (
					{$table_value} = %s
					OR {$table_value} IN ( [IN_NOT_IN_VALUES] )
				 )
SQL;
			} elseif ( $is_post && $field['unique'] == 'post_type' ) {
				// POSTS: prefix with the post_type, but search all keys for dupes
				$this_sql = <<<SQL
				{$sql_prefix} 
					AND p.post_type = '{$post_type}'
				WHERE (	 
					(
						{$table_id} NOT IN ( [IN_NOT_IN] )
						AND {$sql_meta_keys}
					 ) OR (
						{$table_id} IN ( [IN_NOT_IN] )
						AND {$sql_meta_keys_not}
					 ) 
				 ) AND {$table_value} = %s
SQL;
			} elseif ( $is_post && $field['unique'] == 'post_key' ) {
				// POSTS: prefix with the post_type, then search within this key for dupes
				$this_sql = <<<SQL
				{$sql_prefix} 
					AND p.post_type = '{$post_type}'
				WHERE 
				{$table_id} NOT IN ( [IN_NOT_IN] )
				AND {$sql_meta_keys}
				AND (
					{$table_value} = %s
					OR {$table_value} IN ( [IN_NOT_IN_VALUES] )
				 )
SQL;
			} elseif ( $is_options && in_array( $field['unique'], array( 'post_key', 'this_post' ) ) ) {
				// OPTIONS: search within this key for dupes. include "this_post" since there is no ID for options.
				$this_sql = <<<SQL
				{$sql_prefix}
				WHERE 
				{$sql_meta_keys}
				AND (
					{$table_value} = %s
					OR {$table_value} IN ( [IN_NOT_IN_VALUES] )
				 )
SQL;
			} elseif ( $is_user && in_array( $field['unique'], array( 'post_key' ) ) ) {
				// USERS: search within this key for dupes
				$this_sql = <<<SQL
				{$sql_prefix}
				WHERE	 
				{$table_id} NOT IN ( [IN_NOT_IN] ) 
				AND {$sql_meta_keys}
				AND (
					{$table_value} = %s
					OR {$table_value} IN ( [IN_NOT_IN_VALUES] )
				 )
SQL;
			} elseif ( ($is_post || $is_user ) && in_array( $field['unique'], array( 'this_post' ) )	) {
				// POSTS/USERS: search only this ID, but any key
				$this_sql = <<<SQL
				{$sql_prefix}
				WHERE
				{$table_id} IN ( [IN_NOT_IN] ) 
				AND {$sql_meta_keys_not}
				AND (
					{$table_value} = %s
					OR {$table_value} IN ( [IN_NOT_IN_VALUES] )
				 )
SQL;
			} elseif ( $field['unique'] == 'this_post_key' ) {
				// POSTS/USERS/OPTIONS: this will succeed and not run a query. this should have been detected in the input validation.
				return true;
			} else {
				// We missed a valid configuration value... or something
				return __( 'Unable to determine value uniqueness.', 'acf_vf' );
			}

			// Add a group by the table ID since we only want the parent ID once
			$this_sql = <<<SQL
				{$this_sql}
				GROUP BY {$table_id}
				ORDER BY NULL
SQL;

			// Bind variables to placeholders using the ( maybe ) serialized value
			$prepared_sql = $wpdb->prepare( $this_sql, maybe_serialize( $_value ) );

			// Update the [IN_NOT_IN] values
			$sql = $this->prepare_in_and_not_in( $prepared_sql, $post_ids );

			// Update the [IN_NOT_IN_VALUES] values ( for arrays of IDs )
			$sql = $this->prepare_in_and_not_in( $sql, is_array( $value )? $value : array( $value ), '[IN_NOT_IN_VALUES]' );

			// Execute the SQL
			$rows = $wpdb->get_results( $sql );
			if ( count( $rows ) ) {
				// We got some matches, but there might be more than one so we need to concatenate the collisions
				$conflicts = '';
				foreach ( $rows as $row ) {
					// the link will be based on the type and if this is a frontend or admin request
					if ( $is_user ) {
						$permalink = admin_url( "user-edit.php?user_id={$row->user_id}" );
					} elseif ( $is_options ) {
						$permalink = admin_url( "options.php#{$row->title}" );
					} elseif ( $is_frontend ) {
						$permalink = get_permalink( $row->{$table_id} );
					} else {
						$permalink = admin_url( "post.php?post={$row->{$table_id}}&action=edit" );
					}
					$title = empty( $row->title )? "#{$row->{$table_id}}" : $row->title;
					$conflicts.= "<a href='{$permalink}' class='acf_vf_conflict'>{$title}</a>";
					if ( $row !== end( $rows ) ) {
						$conflicts.= ', '; 
					}
				}

				// This does stuff like get the title/username/taxonomy from the ID depending on the field type
				$_value = $this->get_value_text( $value, $field );

				// This will fail in the validation with the conflict message/link.
				return sprintf( __( 'The value "%1$s" is already in use by %2$s.', 'acf_vf' ), $_value, $conflicts );
			}

			// No duplicates were detected.
			return true;
		}

		protected function maybe_get_wpml_ids( $post_id )
		{
			global $wpdb;

			if ( function_exists( 'icl_object_id' ) ) {

				$sql = "SELECT code FROM {$wpdb->prefix}icl_languages WHERE active = 1";

				// WPML compatibility, get code list of active languages
				$languages = $wpdb->get_results( $sql, ARRAY_A );

				$wpml_ids = array();
				foreach ( $languages as $lang ) {
					$wpml_ids[] = ( int ) icl_object_id( $post_id, $post_type, true, $lang['code'] );
				}

				$post_ids = array_unique( $wpml_ids );
			} else {
				$post_ids = array( (int ) $post_id );
			}
			return $post_ids;
		}

		// does prepare on the sql string using a variable number of parameters
		protected function prepare_in_and_not_in( $sql, $post_ids, $pattern='[IN_NOT_IN]' )
		{
			global $wpdb;

			$not_in_count = substr_count( $sql, $pattern );
			if ( $not_in_count > 0 ) {

				$digit_array = array_fill( 0, count( $post_ids ), '%d' );
				$digit_sql = implode( ', ', $digit_array );
				$escaped_sql = str_replace( '%', '%%', $sql );
				$args = array( str_replace( $pattern, $digit_sql, $escaped_sql ) );
				for ( $i=0; $i < substr_count( $sql, $pattern ); $i++ ) { 
					$args = array_merge( $args, $post_ids );
				}
				$sql = call_user_func_array( array( $wpdb, 'prepare' ), $args );
			}
			return trim( $sql );
		}

		// mostly converts int IDs to text values for error messages
		protected function get_value_text( $value, $field )
		{
			switch ( $field['sub_field']['type'] ) {
			case 'post_object':
			case 'page_link':
			case 'relationship':
				$these_post_ids = is_array( $value )? $value : array( $value );
				$posts = array();
				foreach ( $these_post_ids as $this_post_id ) {
					$this_post = get_post( $this_post_id );
					$post_titles[] = $this_post->post_title;
				}
				$_value = implode( ', ', $post_titles );
				break;
			case 'taxonomy':
				$tax_ids = is_array( $value )? $value : array( $value );
				$taxonomies = array();
				foreach ( $tax_ids as $tax_id ) {
					$term = get_term( $tax_id, $field['sub_field']['taxonomy'] );
					$terms[] = $term->name;
				}
				$_value = implode( ', ', $terms );
				break;
			case 'user':
				$user_ids = is_array( $value )? $value : array( $value );
				$users = array();
				foreach ( $user_ids as $user_id ) {
					$user = get_user_by( 'id', $user_id );
					$users[] = $user->user_login;
				}
				$_value = implode( ', ', $users );
				break;
			
			default:
				$_value = is_array( $value )? implode( ', ', $value ) : $value;
				break;
			}
			return $_value;
		}

		/**
		* We have to copy name to _name for ACF5
		*/
		protected function get_field_name( $field )
		{
			// We don't want to reprocess for relationship subs.
			if ( function_exists( 'acf_get_field' ) ) {
				$self =acf_get_field( $field['ID'] );
			} else {
				$self =get_field_object( $field['name'] );
			}

			if ( $self['type'] == 'validated_field' ) {
				if ( $self['type'] != $field['type'] ) {
					return $field['_name'];
				}
			}
			return $field['name'];
		}


	}
endif;