<?php

if ( !class_exists( 'acf_vf_utils' ) ):
class acf_vf_utils{
	
	private static final $SQUOT = '%%squot%%';
	private static final $DQUOT = '%%dquot%%';

	static function get_unique_form_error( $unique, $field, $value ){
		switch ( $unique ){
			case 'global';
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on all posts.', 'acf_vf' ), $value );
				break;
			case 'post_type':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on this post type.', 'acf_vf' ), $value );
				break;
			case 'this_post':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on this post.', 'acf_vf' ), $value );
				break;
			case 'post_key':
			case 'this_post_key':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for %2$s.', 'acf_vf' ), $value, $field['label'] );
				break;
		}
		return false;
	}

	public static function is_value_unique( $unique, $post_id, $field, $parent_field, $index, $is_repeater, $value ){
		global $wpdb;

		// are we editting a user?
		$is_user = strpos( $post_id, 'user_' ) === 0;
		// are we editting site options?
		$is_options = $post_id == 'options';

		if ( !$is_user && !$is_options ){
			$post_type = get_post_type( $post_id );	
		}

		// the db name is modded for repeaters
		$this_key = $is_options ? 'options_' : '';
		$this_key.= $is_repeater ? 
			$parent_field['name'] . '_' . $index . '_' . $field['name'] : 
			$field['name'];

		// modify keys for options and repeater fields
		$meta_key = $is_options ? 'options_' : '';
		$meta_key.= $is_repeater ? 
			$parent_field['name'] . '_%_' . $field['name']:
			'';

		// we only want to validate posts with these statuses
		$status_in = "'" . implode( "','", $field['unique_statuses'] ) . "'";

		if ( $is_user ) {
			// set up queries for the user table
			$post_ids = array( (int) str_replace( 'user_', '', $post_id ) );
			$table_id = 'user_id';
			$table_key = 'meta_key';
			$table_value = 'meta_value';
			$sql_prefix = "SELECT m.umeta_id AS {$table_id}, m.{$table_id} AS {$table_id}, u.user_login AS title FROM {$wpdb->usermeta} m JOIN {$wpdb->users} u ON u.ID = m.{$table_id}";
		} elseif ( $is_options ) {
			$table_id = 'option_id';
			$table_key = 'option_name';
			$table_value = 'option_value';
			$post_ids = array( $post_id );
			$sql_prefix = "SELECT o.option_id AS {$table_id}, o.{$table_id} AS {$table_id}, o.option_name AS title FROM {$wpdb->options} o";
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
			$table_id = 'post_id';
			$table_key = 'meta_key';
			$table_value = 'meta_value';
			$sql_prefix = "SELECT m.meta_id AS {$table_id}, m.{$table_id} AS {$table_id}, p.post_title AS title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.{$table_id} AND p.post_status IN ($status_in)";
		}

		switch ( $unique ){
			case 'global': 
				// check to see if this value exists anywhere in the postmeta table
				if ( $is_user || $is_options ){
					$sql = false;
				} else {
					$sql = $wpdb->prepare( 
						"{$sql_prefix} AND {$table_id} NOT IN ([IN_NOT_IN]) WHERE ( {$table_value} = %s OR {$table_value} LIKE %s )",
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
				}
				break;
			case 'post_type':
				// check to see if this value exists in the postmeta table with this $post_id
				if ( $is_user ){
					$sql = $wpdb->prepare( 
						"{$sql_prefix} WHERE ( ( {$table_id} IN ([IN_NOT_IN]) AND {$table_key} != %s ) OR {$table_id} NOT IN ([IN_NOT_IN]) ) AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
						$this_key,
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
				} elseif ( $is_options ){
					$sql = $wpdb->prepare( 
						"{$sql_prefix} WHERE {$table_key} != %s AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
						$this_key,
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
				} else {
					$sql = $wpdb->prepare( 
						"{$sql_prefix} AND p.post_type = %s WHERE ( ( {$table_id} IN ([IN_NOT_IN]) AND {$table_key} != %s ) OR {$table_id} NOT IN ([IN_NOT_IN]) ) AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
						$post_type,
						$this_key,
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
				}
				break;
			case 'post_key':
				// check to see if this value exists in the postmeta table with both this $post_id and $meta_key
				if ( $is_user ){
					if ( $is_repeater ){
						$sql = $wpdb->prepare(
							"{$sql_prefix} WHERE ( ( {$table_id} NOT IN ([IN_NOT_IN]) AND {$table_key} != %s AND {$table_key} LIKE %s ) OR ( {$table_id} NOT IN ([IN_NOT_IN]) AND {$table_key} LIKE %s ) ) AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
							$this_key,
							$meta_key,
							$meta_key,
							$value,
							'%"' . $wpdb->esc_like( $value ) . '"%'
						);
					} else {			
						$sql = $wpdb->prepare( 
							"{$sql_prefix} AND {$table_id} NOT IN ([IN_NOT_IN]) WHERE {$table_key} = %s AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
							$field['name'],
							$value,
							'%"' . $wpdb->esc_like( $value ) . '"%'
						);
					}
				} elseif ( $is_options ){
					if ( $is_repeater ){
						$sql = $wpdb->prepare(
							"{$sql_prefix} WHERE {$table_key} != %s AND {$table_key} LIKE %s AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
							$this_key,
							$meta_key,
							$value,
							'%"' . $wpdb->esc_like( $value ) . '"%'
						);
					} else {			
						$sql = $wpdb->prepare( 
							"{$sql_prefix} WHERE {$table_key} = %s AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
							$field['name'],
							$value,
							'%"' . $wpdb->esc_like( $value ) . '"%'
						);
					}
				} else {
					if ( $is_repeater ){
						$sql = $wpdb->prepare(
							"{$sql_prefix} AND p.post_type = %s WHERE ( ( {$table_id} NOT IN ([IN_NOT_IN]) AND {$table_key} != %s AND {$table_key} LIKE %s ) OR ( {$table_id} NOT IN ([IN_NOT_IN]) AND {$table_key} LIKE %s ) ) AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
							$post_type,
							$this_key,
							$meta_key,
							$meta_key,
							$value,
							'%"' . $wpdb->esc_like( $value ) . '"%'
						);
					} else {	
						$sql = $wpdb->prepare( 
							"{$sql_prefix} AND p.post_type = %s AND {$table_id} NOT IN ([IN_NOT_IN]) WHERE {$table_key} = %s AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
							$post_type,
							$this_key,
							$value,
							'%"' . $wpdb->esc_like( $value ) . '"%'
						);
					}
				}
				break;
			case 'this_post':
				// check to see if this value exists in the postmeta table with this $post_id
				if ( $is_user || $is_options ){
					$sql = false;
				} else {
					$sql = $wpdb->prepare( 
						"{$sql_prefix} AND {$table_id} IN ([IN_NOT_IN]) AND {$table_key} != %s AND ( {$table_value} = %s OR {$table_value} LIKE %s )",
						$this_key,
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
				}
				break;
			case 'this_post_key':
				// check to see if this value exists in the postmeta table with both this $post_id and $meta_key
				if ( $is_user || $is_options ){
					$sql = false;
				} elseif ( $is_repeater ){
					$sql = $wpdb->prepare(
						"{$sql_prefix} WHERE {$table_id} IN ([IN_NOT_IN]) AND {$table_key} != %s AND {$table_key} LIKE %s AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
						$this_key,
						$meta_key,
						$meta_key,
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
				} else {
					$sql = $wpdb->prepare( 
						"{$sql_prefix} AND {$table_id} IN ([IN_NOT_IN]) WHERE {$table_key} = %s AND ( {$table_value} = %s OR {$table_value} LIKE %s )", 
						$field['name'],
						$value,
						'%"' . $wpdb->esc_like( $value ) . '"%'
					);
				}
				break;
			default:
				// no dice, set $sql to null
				$sql = null;
				break;
		}

		// Only run if we hit a condition above
		if ( !empty( $sql ) ){

			// Update the [IN_NOT_IN] values
			$sql = self::prepare_in_and_not_in( $sql, $post_ids );

			// Execute the SQL
			$rows = $wpdb->get_results( $sql );
			if ( count( $rows ) ){
				// We got some matches, but there might be more than one so we need to concatenate the collisions
				$conflicts = '';
				foreach ( $rows as $row ){
					if ( $is_user ){
						$permalink = admin_url( "user-edit.php?user_id={$row->user_id}" );
					} elseif ( $is_options ){
						$permalink = admin_url( "options.php#{$row->title}" );
					} elseif ( $frontend ){
						$permalink = get_permalink( $row->{$table_id} );
					} else {
						$permalink = admin_url( "post.php?post={$row->{$table_id}}&action=edit" );
					}
					$conflicts.= "<a href='{$permalink}' style='color:inherit;text-decoration:underline;'>{$row->title}</a>";
					if ( $row !== end( $rows ) ) $conflicts.= ', ';
				}
				return sprintf( __( 'The value "%1$s" is already in use by %2$s.', 'acf_vf' ), $value, $conflicts );
			}
		}
		return true;
	}

	private static function prepare_in_and_not_in( $sql, $post_ids ){
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

}
endif;