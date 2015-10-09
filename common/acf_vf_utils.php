<?php

if ( !class_exists( 'acf_vf_utils' ) ):
class acf_vf_utils{

	public static $SQUOT = '%%squot%%';
	public static $DQUOT = '%%dquot%%';

	static function get_unique_form_error( $unique, $field, $value ){
		switch ( $unique ){
			case 'global';
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on all posts.', 'acf_vf' ), is_array( $value )? implode( ',', $value ) : $value );
				break;
			case 'post_type':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on this post type.', 'acf_vf' ), is_array( $value )? implode( ',', $value ) : $value );
				break;
			case 'this_post':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for all fields on this post.', 'acf_vf' ), is_array( $value )? implode( ',', $value ) : $value );
				break;
			case 'post_key':
			case 'this_post_key':
				return sprintf( __( 'The value "%1$s" was submitted multiple times and should be unique for %2$s.', 'acf_vf' ), is_array( $value )? implode( ',', $value ) : $value, $field['label'] );
				break;
		}
		return false;
	}

	public static function is_value_unique( $unique, $post_id, $field, $parent_field, $index, $is_repeater, $is_flex, $is_frontend, $value ){
		global $wpdb;

		// are we editting a user?
		$is_user = strpos( $post_id, 'user_' ) === 0;
		// are we editting site options?
		$is_options = $post_id == 'options';
		// if it's not a user or options, it's a post
		$is_post = !$is_user && !$is_options;

		if ( !$is_user && !$is_options ){
			$post_type = get_post_type( $post_id );	
		}

		// prepend for options
		$this_key = $is_options ? 'options_' : '';
		// for repeaters and flex content add the parent and index
		$this_key.= $is_repeater || $is_flex ? $parent_field['name'] . '_' . $index . '_' : '';
		// the key for this field
		$this_key.= $field['name'];

		$this_key_sorted = "{$this_key}__sorted";
		$this_key_r = "{$this_key}__r";

		// modify keys for options and repeater fields
		$meta_key = $is_options ? 'options_' : '';
		$meta_key.= $is_repeater || $is_flex ? 
			$parent_field['name'] . '_%_':
			'';
		$meta_key.= $field['name'];

		// we only want to validate posts with these statuses
		$status_in = "'" . implode( "','", $field['unique_statuses'] ) . "'";

		if ( $is_user ) {
			// set up queries for the user table
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
			$table_id = 'option_id';
			$table_key = 'option_name';
			$table_value = 'option_value';
			$post_ids = array( $post_id );
			$sql_prefix = <<<SQL
						SELECT o.option_id AS meta_id, o.{$table_id} AS {$table_id}, o.option_name AS title 
						FROM {$wpdb->options} o
SQL;
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
			$sql_prefix = <<<SQL
						SELECT m.meta_id AS meta_id, m.{$table_id} AS {$table_id}, p.post_title AS title 
						FROM {$wpdb->postmeta} m 
						JOIN {$wpdb->posts} p 
							ON p.ID = m.{$table_id} AND p.post_status IN ($status_in)
SQL;
		}

		$values = is_array( $value )? $value : array( $value );
		
		// expect the post_id for relationship fields, so we need to compare the sorted value since it is serialized
		if ( $field['sub_field']['type'] == 'relationship' && is_array( $value ) ){
			$value = array_map( 'intval', $value );
			sort( $value, SORT_NUMERIC );
		}

		$sql_replacements = array(
			$this_key,
			$this_key_sorted,
			$this_key_r,
			$meta_key,
			maybe_serialize( $value )
		);

		if ( $is_post && in_array( $unique, array( 'global' ) ) ){
			$this_sql = <<<SQL
				{$sql_prefix} AND p.post_type != 'acf' 
				WHERE ( 	
					{$table_id} NOT IN ([IN_NOT_IN])
					OR ( 
						{$table_id} IN ([IN_NOT_IN]) 
						AND {$table_key} NOT IN ( %s, %s, %s ) 
						AND {$table_key} NOT LIKE %s
					) 
				) AND {$table_value} = %s
SQL;
		} elseif ( $is_options && in_array( $unique, array( 'global', 'post_type' ) ) ){
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE 
				{$table_key} NOT IN ( %s, %s, %s )
				AND {$table_key} NOT LIKE %s
				AND {$table_value} = %s
SQL;

		} elseif ( $is_user && in_array( $unique, array( 'global', 'post_type' ) ) ){
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE ( 	
					{$table_id} NOT IN ([IN_NOT_IN]) 
					AND {$table_key} NOT IN ( %s, %s, %s )
					AND {$table_key} NOT LIKE %s
				) AND {$table_value} = %s
SQL;
		} elseif ( $is_post && $unique == 'post_type' ){
			$sql_replacements = array_merge( array( $post_type ), $sql_replacements);

			$this_sql = <<<SQL
				{$sql_prefix} AND p.post_type = %s
				WHERE ( 	
					{$table_id} NOT IN ([IN_NOT_IN]) 
					OR ( 
						{$table_id} IN ([IN_NOT_IN]) 
						AND {$table_key} NOT IN ( %s, %s, %s ) 
						AND {$table_key} NOT LIKE %s 
					) 
				) AND {$table_value} = %s
SQL;
		} elseif ( $is_post && $unique == 'post_key' ){
			$sql_replacements = array_merge( array( $post_type ), $sql_replacements);

			$this_sql = <<<SQL
				{$sql_prefix} AND p.post_type = %s
				WHERE 	
				{$table_id} NOT IN ([IN_NOT_IN]) 
				AND ( 
					{$table_key} IN ( %s, %s, %s ) 
					OR {$table_key} LIKE %s
				)
				AND {$table_value} = %s
SQL;

		} elseif ( $is_options && in_array( $unique, array( 'post_key', 'this_post', 'this_post_key' ) ) ){
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE ( 
					{$table_key} NOT IN ( %s, %s, %s ) 
					AND {$table_key} LIKE %s
				)
				AND {$table_value} = %s
SQL;
		} elseif ( $is_user && in_array( $unique, array( 'post_key' ) ) ){
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE 	
				{$table_id} NOT IN ([IN_NOT_IN]) 
				AND ( 
					{$table_key} IN ( %s, %s, %s ) 
					OR {$table_key} LIKE %s
				)
				AND {$table_value} = %s
SQL;
		} elseif ( ( $is_post || $is_user ) && in_array( $unique, array( 'this_post', 'this_post_key' ) ) 	){
			$this_sql = <<<SQL
				{$sql_prefix}
				WHERE
				{$table_id} IN ([IN_NOT_IN]) 
				AND {$table_key} NOT IN ( %s, %s, %s ) 
				AND {$table_key} NOT LIKE %s
				AND {$table_value} = %s
SQL;
		} else {
			return __( 'Unable to determine value uniqueness.', 'acf_vf' );
		}

		$this_sql = <<<SQL
				{$this_sql}
				GROUP BY {$table_id}
SQL;

		// Bind variables to placeholders
		$prepared_sql = $wpdb->prepare( $this_sql, $sql_replacements );

		// Update the [IN_NOT_IN] values
		$sql = self::prepare_in_and_not_in( $prepared_sql, $post_ids );

		error_log( $sql );

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
				} elseif ( $is_frontend ){
					$permalink = get_permalink( $row->{$table_id} );
				} else {
					$permalink = admin_url( "post.php?post={$row->{$table_id}}&action=edit" );
				}
				$title = empty( $row->title )? "#{$row->{$table_id}}" : $row->title;
				$conflicts.= "<a href='{$permalink}' style='color:inherit;text-decoration:underline;'>{$title}</a>";
				if ( $row !== end( $rows ) ) $conflicts.= ', ';
			}

			return sprintf( __( 'The value "%1$s" is already in use by %2$s.', 'acf_vf' ), is_array( $value )? implode( ', ', $value ) : $value, $conflicts );
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
		return trim( $sql );
	}
}
endif;