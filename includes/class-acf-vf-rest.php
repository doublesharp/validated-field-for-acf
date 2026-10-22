<?php

/** Validate wrapped fields after REST permissions and before any controller writes. */
class ACF_VF_REST {

	public function __construct() {
		// A single callback also covers multiple field instances initialized by ACF.
		if ( !has_filter( 'rest_dispatch_request', array( __CLASS__, 'validate_request' ) ) ) {
			add_filter( 'rest_dispatch_request', array( __CLASS__, 'validate_request' ), 10, 4 );
		}
	}

	public static function validate_request( $result, $request, $route, $handler ) {
		if ( null !== $result || !in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) || !class_exists( 'ACF_Rest_Request' ) || !acf_get_setting( 'rest_api_enabled' ) ) {
			return $result;
		}
		$data = $request->get_param( 'acf' );
		if ( !is_array( $data ) || !$data ) {
			return $result;
		}
		$context = new ACF_Rest_Request();
		$context->parse_request( $request );
		if ( !$context->object_type ) {
			return $result;
		}
		$object_id = $context->get_url_param( 'child_id' ) ?: $context->get_url_param( 'id' );
		if ( !$object_id && $context->object_type === 'user' && substr( $request->get_route(), -3 ) === '/me' ) {
			$object_id = get_current_user_id();
		}
		$post_id = $context->object_type === 'post' ? (int)$object_id : $context->object_type . '_' . (int)$object_id;
		$args = array();
		switch ( $context->object_type ) {
			case 'post': $args = array( 'post_id' => (int)$object_id, 'post_type' => $context->object_sub_type );
				break;
			case 'user': $args = array( 'user_id' => (int)$object_id, 'rest' => true );
				break;
			case 'term': $args = array( 'taxonomy' => $context->object_sub_type );
				break;
			case 'comment': $comment = get_comment( $object_id );
				$args = array( 'comment' => $comment ? get_post_type( $comment->comment_post_ID ) : 'post' );
				break;
			default:
				return $result;
		}
		if ( $context->object_type === 'post' ) {
			$args['post_status'] = $request->get_param( 'status' ) ?: ( $object_id ? get_post_status( $object_id ) : 'draft' );
			if ( $request->has_param( 'parent' ) ) {
				$args['page_parent'] = (int) $request->get_param( 'parent' );
			}
			if ( $request->has_param( 'template' ) ) {
				$args['page_template'] = $request->get_param( 'template' );
			}
		}
		$groups = acf_get_field_groups( $args );
		$fields = array();
		$scope = isset( $data['_acf_field_group_scope'] ) ? (array)$data['_acf_field_group_scope'] : array();
		foreach ( $groups as $group ) {
			if ( empty( $group['show_in_rest'] ) || ( $scope && !in_array( $group['key'], $scope, true ) ) ) {
				continue;
			}
			$available = array_filter( (array)acf_get_fields( $group ), static function ( $field ) {
				$type = acf_get_field_type( $field['type'] );
				return $type && !empty( $type->show_in_rest );
			} );
			$resource = array( 'type' => $context->object_type, 'sub_type' => $context->object_sub_type, 'id' => $object_id );
			$available = (array)apply_filters( 'acf/rest/get_fields', $available, $resource, $request->get_method() );
			$fields = array_merge( $available, $fields );
		}
		$map = isset( $data['_acf_field_key_map'] ) ? (array)$data['_acf_field_key_map'] : array();
		$submitted = self::convert( $data, $fields, $map );
		if ( !$submitted ) {
			return $result;
		}
		$old_post = $_POST;
		$old_request = $_REQUEST;
		$form = acf_get_store( 'form' )->get();
		$errors = acf()->validation->errors;
		try {
			$_POST = array( 'acf' => wp_slash( $submitted ), '_acf_post_id' => $post_id, 'post_ID' => $post_id, 'post_type' => $context->object_sub_type );
			$status = $request->get_param( 'status' );
			if ( $status ) {
				$_POST['post_status'] = $status;
			} elseif ( $object_id && $context->object_type === 'post' ) {
				$_POST['post_status'] = get_post_status( $object_id );
			} else {
				$_POST['post_status'] = 'draft';
			}
			$_REQUEST = $_POST;
			acf_set_form_data( 'post_id', $post_id );
			acf_set_form_data( 'vf_post_id', $post_id );
			acf_set_form_data( 'vf_rest_validation', true );
			acf_set_form_data( 'post_type', $context->object_type === 'post' ? $context->object_sub_type : false );
			acf_reset_validation_errors();
			foreach ( $submitted as $key => $value ) {
				$field = acf_search_fields( $key, $fields );
				if ( $field && self::has_wrapped( $field ) ) {
					acf_validate_value( $value, $field, 'acf[' . $key . ']' );
				}
			}
			$validation_errors = acf_get_validation_errors();
			if ( $validation_errors ) {
				return new WP_Error( 'acf_vf_validation_failed', __( 'ACF field validation failed.', 'acf_vf' ), array( 'status' => 400, 'errors' => $validation_errors ) );
			}
		} finally {
			$_POST = $old_post;
			$_REQUEST = $old_request;
			acf_get_store( 'form' )->reset();
			foreach ( (array)$form as $key => $value ) {
				acf_set_form_data( $key, $value );
			}
			acf()->validation->errors = $errors;
		}
		return $result;
	}

	private static function has_wrapped( $field ) {
		if ( $field['type'] === 'validated_field' ) {
			return true;
		}
		foreach ( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array() as $child ) {
			if ( self::has_wrapped( $child ) ) {
				return true;
			}
		}
		foreach ( isset( $field['layouts'] ) ? $field['layouts'] : array() as $layout ) {
			foreach ( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array() as $child ) {
				if ( self::has_wrapped( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}
	/** Convert names using only the route's allowed fields and each selected layout. */

	private static function convert( $values, $fields, $map = array() ) {
		$out = array();
		foreach ( $values as $name => $value ) {
			if ( strpos( $name, '_acf_' ) === 0 ) {
				continue;
			}
			$selector = isset( $map[$name] ) ? $map[$name] : $name;
			$field = acf_search_fields( $selector, $fields );
			if ( !$field ) {
				continue;
			}
			if ( $field['type'] === 'group' && is_array( $value ) ) {
				$value = self::convert( $value, isset( $field['sub_fields'] ) ? $field['sub_fields'] : acf_get_fields( $field ) );
			} elseif ( in_array( $field['type'], array( 'repeater', 'flexible_content' ), true ) && is_array( $value ) ) {
				$rows = array();
				foreach ( $value as $row ) {
					if ( !is_array( $row ) ) {
						$rows[] = $row;
						continue;
					}
					$children = isset( $field['sub_fields'] ) ? $field['sub_fields'] : array();
					$layout_name = isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : null;
					if ( $field['type'] === 'flexible_content' ) {
						foreach ( (array)$field['layouts'] as $layout ) {
							if ( $layout['name'] === $layout_name ) {
								$children = $layout['sub_fields'];
								break;
							}
						}
					}
					$converted = self::convert( $row, $children );
					if ( $layout_name !== null ) {
						$converted['acf_fc_layout'] = $layout_name;
					}
					$rows[] = $converted;
				}
				$value = $rows;
			}
			$out[$field['key']] = $value;
		}
		return $out;
	}
}
