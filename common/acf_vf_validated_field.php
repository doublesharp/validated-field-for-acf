<?php
if ( class_exists( 'acf_Field' ) && !class_exists( 'acf_field_validated_field' ) ):
class acf_field_validated_field extends acf_field {	
	function __construct(){

		// Maintain relationship fields
		add_filter( 'acf/format_value/type=relationship', array( 'acf_field_validated_field', 'process_relationship_values'), 20, 3 );

		parent::__construct();

	}

	public static function process_relationship_values( $value, $post_id, $field ){ 
		$relation_key = $field['name'].'__r';
		$sorted_key = $field['name'].'__sorted';

		// delete existing relationship keys
		delete_post_meta( $post_id, $relation_key );
		delete_post_meta( $post_id, $sorted_key );

		$values = is_array( $value )? $value : array( $value );
		$post_ids = array();
		foreach ( $values as $the_value ) {
			// if filter is passing through a Post object, extract the ID
			$the_value = ( is_object( $the_value ) )? $the_value->ID : $the_value;

			$post_ids[] = $the_value;

			// add each ID to it's own meta key
			add_post_meta( $post_id, $relation_key, $the_value, false );
		}

		// sort the IDs numerically and save
		sort( $post_ids, SORT_NUMERIC );
		update_post_meta( $post_id, $sorted_key, $post_ids );

		// Continue processing
		return $value;
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
		$field['pattern'] = str_replace( acf_vf_utils::$SQUOT, "'", $field['pattern'] );
		$field['pattern'] = str_replace( acf_vf_utils::$DQUOT, '"', $field['pattern'] );

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
		$field['pattern'] = str_replace( "'", acf_vf_utils::$SQUOT, $field['pattern'] );
		$field['pattern'] = str_replace( '"', acf_vf_utils::$DQUOT, $field['pattern'] );

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

}
endif;