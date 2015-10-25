<?php
if ( class_exists( 'acf_Field' ) && !class_exists( 'acf_field_validated_field' ) ):
class acf_field_validated_field extends acf_field {	

	public static $SQUOT = '%%squot%%';
	public static $DQUOT = '%%dquot%%';

	function __construct(){

		// Maintain relationship fields
		add_filter( 'acf/update_value/type=relationship', array( $this, 'process_relationship_values'), 20, 3 );

		add_filter( 'acf/update_value', array( $this, 'process_array_values'), 20, 3 );

		add_action( 'admin_notices', array( $this, 'admin_notices') );

		parent::__construct();
	}

	public function process_array_values( $value, $post_id, $field ){
		if ( $field['type'] != 'validated_field' ){
			// These will be processed by the validated field code.
			// return $value;
		}

		global $post;
		if ( empty( $post_id ) ){
			$post_id = $post->ID;
		}

		$sorted_key = $this->get_field_name( $field, $post_id ) . "__sorted";

		// get rid of the old value
		delete_post_meta( $post_id, $sorted_key );

		// if it's an array, sort and save
		if ( is_array( $value ) ){
			if ( !empty( $value ) ){
				$sorted = $value;

				// these field types all store numeric IDs
				if ( isset($field['sub_field']) && in_array( $field['sub_field']['type'], array( 'post_object', 'page_link', 'relationship', 'taxonomy', 'user' ) ) ){
					$sorted = array_map( 'intval', $sorted );
					sort( $sorted, SORT_NUMERIC );	
				} else {
					sort( $sorted );
				}

				update_post_meta( $post_id, $sorted_key, $sorted );
			}
		}
		return $value;
	}

	public function process_relationship_values( $value, $post_id, $field ){

		if ( empty( $post_id ) ){
			global $post;
			$post_id = $post->ID;
		}

		$relation_key = $this->get_field_name( $field, $post_id ) . "__r";

		// delete existing relationship keys
		delete_post_meta( $post_id, $relation_key );

		$values = is_array( $value )? $value : array( $value );
		$post_ids = array();
		foreach ( $values as $the_value ) {
			// if filter is passing through a Post object, extract the ID
			$the_value = ( is_object( $the_value ) )? $the_value->ID : $the_value;

			$post_ids[] = $the_value;

			// add each ID to it's own meta key
			add_post_meta( $post_id, $relation_key, $the_value, false );
		}

		// Continue processing
		return $value;
	}

	protected function get_min(){
		// Use minified unless debug is on
		return ( !$this->debug )? '.min' : '';
	}

	public function admin_notices(){
		if ( !current_user_can( 'manage_options' ) ) return;

		// keep track of when this plugin started being used.
		if ( false == $install_date = get_option( 'acf_vf_install_date', false ) ){
			update_option( 'acf_vf_install_date', date('Y-m-d h:i:s') );
		}


	}

	public function ajax_admin_notices(){
		if ( !current_user_can( 'manage_options' ) || !isset( $_POST['notice'] ) ){
			json_encode( array( 'error' ) );
		} else {	
			update_option( "acf_vf_{$_POST['notice']}", 'yes' );
			echo json_encode( array( 'success' ) );
		}
		exit;
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
		global $currentpage, $pagenow, $post;

		// determine if this is a new post or an edit
		$is_new = $pagenow=='post-new.php';
		$post_type = get_post_type( $post );

		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );
		$sub_field = apply_filters( 'acf/load_field/type='.$sub_field['type'], $sub_field );

		// The relationship field gets settings from the sub_field so we need to return it since it effectively displays through this method.
		if ( 'relationship' == $sub_field['type'] && isset( $_POST['action'] ) ){
			switch ( $_POST['action'] ) {
				case 'acf/fields/relationship/query_posts':
					// ACF4
					if ( $sub_field['taxonomy'][0] == 'all' ){
						unset( $sub_field['taxonomy']);
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
		if ( !$is_new && $post_type != 'acf-field-group' && $field['render_field'] === 'readonly' ){
			$field['read_only'] = 'yes';
		}

		// this can be off if the permissions plugin is disabled
		$read_only_type = apply_filters( 'acf_vf/create_field/read_only/type', 'radio' );
		if ( $read_only_type == 'radio' && is_array( $field['read_only'] ) ){
			// default to read only for everyone unless it's off (better security)
			if ( $field['read_only'][0] == 'no' ){
				$field['read_only'] = 'no';
			} else {
				$field['read_only'] = 'yes';
			}
		}

		if ( !in_array( get_post_type(), array( 'acf', 'acf-field-group') ) ){
			// Show icons for read-only fields
			if ( self::check_value( 'yes', $field['read_only'] ) ){
				$field['label'] .= sprintf( ' (<i class="fa fa-ban" style="color:red;" title="%1$s"><small><em> %1$s</em></small></i>)', __( 'Read only', 'acf_vf' ) );
			}
		}

		// Disable the mask if the sub field is anything but...
		if ( !in_array( $sub_field['type'], array( 'text', 'url', 'password' ) ) ){
			$field['mask'] = false;
		}

		// Just avoid using any type of quotes in the db values
		$field['pattern'] = str_replace( self::$SQUOT, "'", $field['pattern'] );
		$field['pattern'] = str_replace( self::$DQUOT, '"', $field['pattern'] );

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
	function update_field( $field, $post_id=false ){
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

	// UTIL FUNCTIONS

	protected static function check_value( $value, $obj_or_array ){
		if ( is_array( $obj_or_array ) ){
			return in_array( $value, $obj_or_array );
		} else {
			return $value == $obj_or_array;
		}
	}


	protected function get_unique_form_error( $unique, $field, $value ){
		switch ( $unique ){
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

	protected function is_value_unique( $unique, $post_id, $field, $parent_field, $index, $is_repeater, $is_flex, $is_frontend, $value ){
		global $wpdb;

		// are we editting a user?
		$is_user = strpos( $post_id, 'user_' ) === 0;
		// are we editting site options?
		$is_options = $post_id == 'options';
		// if it's not a user or options, it's a post
		$is_post = !$is_user && !$is_options;

		// for posts we can filter by post type
		if ( !$is_user && !$is_options ){
			$post_type = get_post_type( $post_id );	
		}

		// We have to copy name to _name for ACF5
		$field_name = $this->get_field_name( $field );

		// prepend for options
		$this_key = $is_options ? 'options_' : '';
		// for repeaters and flex content add the parent and index
		$this_key.= $is_repeater || $is_flex ? $parent_field['name'] . '_' . $index . '_' : '';
		// the key for this field
		$this_key.= $field_name;

		$this_key_sorted = "{$this_key}__sorted";
		$this_key_r = "{$this_key}__r";

		// modify keys for options and repeater fields
		$meta_key = $is_options ? 'options_' : '';
		$meta_key.= $is_repeater || $is_flex ? 
			$parent_field['name'] . '_%_':
			'';
		$meta_key.= $field_name;

		$meta_key_sorted = "{$meta_key}__sorted";
		$meta_key_r = "{$meta_key}__r";

		// we only want to validate posts with these statuses
		$status_in = "'" . implode( "','", $field['unique_statuses'] ) . "'";

		if ( $is_user ) {
			// USERS: set up queries for the user table
			$post_ids = array( (int) str_replace( 'user_', '', $post_id ) );
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
			$table_id = 'post_id';
			$table_key = 'meta_key';
			$table_value = 'meta_value';
			$sql_prefix = <<<SQL
				SELECT m.meta_id AS meta_id, m.{$table_id} AS {$table_id}, p.post_title AS title 
				FROM {$wpdb->postmeta} m 
				JOIN {$wpdb->posts} p 
					ON p.ID = m.{$table_id} AND p.post_status IN ($status_in)
SQL;
		}
		
		// expect the post_id for relationship fields, so we need to compare the sorted value since it is serialized
		if ( $field['sub_field']['type'] == 'relationship' && is_array( $value ) ){
			$value = array_map( 'intval', $value );
			sort( $value, SORT_NUMERIC );
		} elseif ( is_array( $value ) ){
			sort( $value );
		}

		$sql_replacements = array(
			$meta_key,
			$meta_key_sorted,
			$meta_key_r,
			maybe_serialize( $value )
		);

		if ( $is_post && in_array( $unique, array( 'global' ) ) ){
			// POSTS: search all post types except ACF
			$this_sql = <<<SQL
				{$sql_prefix} AND p.post_type != 'acf' 
				WHERE ( 	
					{$table_id} NOT IN ([IN_NOT_IN])
					OR ( 
						{$table_id} IN ([IN_NOT_IN]) 
						AND {$table_key} NOT LIKE %s
						AND {$table_key} NOT LIKE %s
						AND {$table_key} NOT LIKE %s
					) 
				) AND {$table_value} = %s
SQL;
		} elseif ( $is_options && in_array( $unique, array( 'global', 'post_type' ) ) ){
			// OPTIONS: search options for dupe values in any key
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE 
				{$table_key} NOT LIKE %s
				AND {$table_key} NOT LIKE %s
				AND {$table_key} NOT LIKE %s
				AND {$table_value} = %s
SQL;
		} elseif ( $is_user && in_array( $unique, array( 'global', 'post_type' ) ) ){
			// USERS: search users for dupe values in any key
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE ( 	
					{$table_id} NOT IN ([IN_NOT_IN]) 
					AND {$table_key} NOT LIKE %s
					AND {$table_key} NOT LIKE %s
					AND {$table_key} NOT LIKE %s
				) AND {$table_value} = %s
SQL;
		} elseif ( $is_post && $unique == 'post_type' ){
			// POSTS: prefix with the post_type, but search all keys for dupes
			$sql_replacements = array_merge( array( $post_type ), $sql_replacements);
			$this_sql = <<<SQL
				{$sql_prefix} AND p.post_type = %s
				WHERE ( 	
					{$table_id} NOT IN ([IN_NOT_IN]) 
					OR ( 
						{$table_id} IN ([IN_NOT_IN]) 
						AND {$table_key} NOT LIKE %s
						AND {$table_key} NOT LIKE %s
						AND {$table_key} NOT LIKE %s
					) 
				) AND {$table_value} = %s
SQL;
		} elseif ( $is_post && $unique == 'post_key' ){
			// POSTS: prefix with the post_type, then search within this key for dupes
			$sql_replacements = array_merge( array( $post_type ), $sql_replacements);
			
			$this_sql = <<<SQL
				{$sql_prefix} AND p.post_type = %s
				WHERE 
				{$table_id} NOT IN ([IN_NOT_IN])
				AND ( 
					{$table_key} LIKE %s 
					OR {$table_key} LIKE %s 
					OR {$table_key} LIKE %s 
				)
				AND ( 
					{$table_value} = %s
					OR {$table_value} IN ([IN_NOT_IN_VALUES])
				)
SQL;
		} elseif ( $is_options && in_array( $unique, array( 'post_key', 'this_post' ) ) ){
			// OPTIONS: search within this key for dupes. include "this_post" since there is no ID for options.
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE ( 
					{$table_key} LIKE %s 
					OR {$table_key} LIKE %s 
					OR {$table_key} LIKE %s 
				)
				AND ( 
					{$table_value} = %s
					OR {$table_value} IN ([IN_NOT_IN_VALUES])
				)
SQL;
		} elseif ( $is_user && in_array( $unique, array( 'post_key' ) ) ){
			// USERS: search within this key for dupes
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE 	
				{$table_id} NOT IN ([IN_NOT_IN]) 
				AND ( 
					{$table_key} LIKE %s 
					OR {$table_key} LIKE %s 
					OR {$table_key} LIKE %s 
				)
				AND ( 
					{$table_value} = %s
					OR {$table_value} IN ([IN_NOT_IN_VALUES])
				)
SQL;
		} elseif ( ( $is_post || $is_user ) && in_array( $unique, array( 'this_post' ) ) 	){
			// POSTS/USERS: search only this ID, but any key
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE
				{$table_id} IN ([IN_NOT_IN]) 
				AND {$table_key} NOT LIKE %s
				AND {$table_key} NOT LIKE %s
				AND {$table_key} NOT LIKE %s
				AND {$table_value} = %s
SQL;
		} elseif ( $unique == 'this_post_key' ){
			// POSTS/USERS/OPTIONS: this will succeed and not run a query. this should have been detected in the input validation.
			return true;
		} else {
			// We missed a valid configuration value
			return __( 'Unable to determine value uniqueness.', 'acf_vf' );
		}

		// Add a group by the table ID since we only want the parent ID once
		$this_sql = <<<SQL
				{$this_sql}
				GROUP BY {$table_id}
				ORDER BY NULL
SQL;

		// Bind variables to placeholders
		$prepared_sql = $wpdb->prepare( $this_sql, $sql_replacements );

		// Update the [IN_NOT_IN] values
		$sql = $this->prepare_in_and_not_in( $prepared_sql, $post_ids );

		$values = is_array( $value )? $value : array( $value );
		$sql = $this->prepare_in_and_not_in( $sql, $values, '[IN_NOT_IN_VALUES]' );

		error_log( $sql );

		// Execute the SQL
		$rows = $wpdb->get_results( $sql );
		if ( count( $rows ) ){
			// We got some matches, but there might be more than one so we need to concatenate the collisions
			$conflicts = '';
			foreach ( $rows as $row ){
				// the link will be based on the type and if this is a frontend or admin request
				if ( $is_user ){
					$permalink = admin_url( "user-edit.php?user_id={$row->user_id}" );
				} elseif ( $is_options ){
					$permalink = admin_url( "options.php#{$row->title}" );
				} elseif ( $is_frontend ){
					$permalink = get_permalink( $row->{$table_id} );
				} else {
					$permalink = admin_url( "post.php?post={$row->{$table_id}}&action=edit" );
				}
				$title = empty( $row->title )? "#{$row->{$table_id}}" : $row->title;
				$conflicts.= "<a href='{$permalink}' style='color:inherit;text-decoration:underline;' class='acf_vf_conflict'>{$title}</a>";
				if ( $row !== end( $rows ) ) $conflicts.= ', ';
			}

			// This does stuff like get the title/username/taxonomy from the ID depending on the field type
			$_value = $this->get_value_text( $value, $field );

			// This will fail in the validation with the conflict message/link.
			return sprintf( __( 'The value "%1$s" is already in use by %2$s.', 'acf_vf' ), $_value, $conflicts );
		}

		// No duplicates were detected.
		return true;
	}

	// does prepare on the sql string using a variable number of parameters
	protected function prepare_in_and_not_in( $sql, $post_ids, $pattern='[IN_NOT_IN]' ){
		global $wpdb;

		$not_in_count = substr_count( $sql, $pattern );
		if ( $not_in_count > 0 ){
			$args = array( str_replace( $pattern, implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) ), str_replace( '%', '%%', $sql ) ) );
			for ( $i=0; $i < substr_count( $sql, $pattern ); $i++ ) { 
				$args = array_merge( $args, $post_ids );
			}
			$sql = call_user_func_array( array( $wpdb, 'prepare' ), $args );
		}
		return trim( $sql );
	}

	// mostly converts int IDs to text values for error messages
	protected function get_value_text( $value, $field ){
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

	// We have to copy name to _name for ACF5
	protected function get_field_name( $field ){
		// We don't want to reprocess for relationship subs.
		$self = function_exists( 'acf_get_field' )? acf_get_field( $field['ID'] ) : get_field_object( $field['name'] );

		return ( $self['type'] == 'validated_field' && $self['type'] != $field['type'] )?
			$field['_name'] :
			$field['name'];
	}


}
endif;