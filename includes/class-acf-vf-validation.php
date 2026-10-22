<?php

/** Extra validation for native ACF fields wrapped by Validated Field. */
class ACF_VF_Validation {

	private static $submitted_rows = array();

	private static $deleted_rows = array();

	private static $save_post_id = null;
	private static $save_values = array();
	private static $save_entries = array();
	private static $capturing_save = false;
	private static $save_stack = array();
	private static $save_screen = false;

	public static function is_true( $value ) {
		return in_array( $value, array( true, 1, '1', 'true', 'yes', 'on' ), true );
	}

	public static function context() {
		$authoritative = acf_get_form_data( 'vf_post_id' );
		if ( $authoritative !== null && $authoritative !== false ) {
			return $authoritative;
		}
		foreach ( array( '_acf_post_id', 'post_ID', 'post_id' ) as $key ) {
			if ( isset( $_POST[$key] ) && is_scalar( $_POST[$key] ) && $_POST[$key] !== '' ) {
				return (string) $_POST[$key] === '0' ? 0 : acf_get_valid_post_id( wp_unslash( $_POST[$key] ) );
			}
		}
		$id = acf_get_form_data( 'post_id' );
		return $id === 0 || $id === '0' ? 0 : acf_get_valid_post_id( $id ?: 0 );
	}

	private static function previous( $id, $name ) {
		$saving = self::$save_post_id !== null && (string) self::$save_post_id === (string) $id;
		if ( $saving && array_key_exists( $name, self::$save_values ) ) {
			return self::$save_values[$name];
		}
		$value = acf_get_metadata( $id, $name );
		if ( $saving && self::$capturing_save ) {
			self::$save_values[$name] = $value;
		}
		return $value;
	}

	/** Capture original row values before ACF starts overwriting reordered destinations. */
	public static function begin_save( $post_id ) {
		if ( self::$save_post_id !== null ) {
			self::$save_stack[] = array( 'post_id' => self::$save_post_id, 'values' => self::$save_values, 'entries' => self::$save_entries, 'rows' => self::$submitted_rows, 'deleted' => self::$deleted_rows, 'screen' => self::$save_screen );
		}
		self::$save_values = array();
		self::$save_entries = array();
		self::$save_screen = acf_get_form_data( 'vf_screen' );
		self::$save_post_id = $post_id;
		acf_set_form_data( 'vf_post_id', $post_id );
		self::$capturing_save = true;
		try {
			self::$save_entries = self::submitted_entries( null, $post_id );
			foreach ( self::$save_entries as $entry ) {
				self::previous( $post_id, $entry['previous_name'] );
				self::previous( $post_id, $entry['name'] );
				self::previous( $post_id, $entry['save_name'] );
			}
			foreach ( isset( $_POST['acf'] ) ? (array) $_POST['acf'] : array() as $key => $value ) {
				$field = acf_get_field( $key );
				if ( $field ) {
					self::previous( $post_id, $field['name'] );
				}
			}
		} finally {
			self::$capturing_save = false;
		}
	}

	/** Release one save's snapshot so the next save reads fresh metadata. */
	public static function end_save( $post_id = null ) {
		if ( self::$save_stack ) {
			$state = array_pop( self::$save_stack );
			self::$save_post_id = $state['post_id'];
			self::$save_values = $state['values'];
			self::$save_entries = $state['entries'];
			self::$submitted_rows = $state['rows'];
			self::$deleted_rows = $state['deleted'];
			self::$save_screen = $state['screen'];
			self::$capturing_save = false;
			acf_set_form_data( 'vf_post_id', self::$save_post_id );
			acf_set_form_data( 'vf_screen', self::$save_screen );
			return;
		}
		self::$save_post_id = null;
		self::$save_screen = false;
		self::$save_values = array();
		self::$save_entries = array();
		self::$capturing_save = false;
		acf_set_form_data( 'vf_post_id', false );
		acf_set_form_data( 'vf_screen', false );
	}
	/** Flatten the submitted field tree using registered ACF definitions, never field-name guesses. */

	public static function submitted_entries( $submitted = null, $post_id = null ) {
		if ( null === $submitted ) {
			$submitted = isset( $_POST['acf'] ) ? wp_unslash( $_POST['acf'] ) : array();
		}
		$entries = array();
		self::$submitted_rows = array();
		self::$deleted_rows = array();
		self::walk( (array)$submitted, '', 'acf', array(), $entries, $post_id, '', '' );
		return $entries;
	}

	private static function walk( $values, $prefix, $input, $parts, &$entries, $post_id, $previous_prefix, $save_prefix ) {
		foreach ( $values as $key => $value ) {
			$field = acf_get_field( $key );
			if ( !$field ) {
				continue;
			}
			$local = isset( $field['_name'] ) ? $field['_name'] : $field['name'];
			$name = $prefix . $local;
			$previous_name = $previous_prefix . $local;
			$save_name = $save_prefix . $local;
			$path = $input . '[' . $key . ']';
			$shape = array_merge( $parts, array( preg_quote( $local, '~' ) ) );
			$type = $field['type'];
			if ( $type === 'group' && is_array( $value ) ) {
				self::walk( $value, $name . '_', $path, $shape, $entries, $post_id, $previous_name . '_', $save_name . '_' );
				continue;
			}
			if ( in_array( $type, array( 'repeater', 'flexible_content' ), true ) && is_array( $value ) ) {
				$row_index = 0;
				$row_count = 0;
				foreach ( $value as $row_key => $row_value ) {
					if ( is_array( $row_value ) && $row_key !== 'acfcloneindex' ) {
						$row_count++;
					}
				}
				// Pagination sends original row IDs and only the visible/changed rows.
				$paged = false;
				if ( $type === 'repeater' && !empty( $field['pagination'] ) ) {
					foreach ( $value as $row_key => $row_value ) {
						if ( preg_match( '/^row-[0-9]+$/D', (string) $row_key ) || ( is_array( $row_value ) && ( isset( $row_value['acf_changed'] ) || isset( $row_value['acf_reordered'] ) || isset( $row_value['acf_deleted'] ) ) ) ) {
							$paged = true;
							break;
						}
					}
				}
				if ( !$paged ) {
					self::$submitted_rows['~^' . preg_quote( $name, '~' ) . '_([0-9]+)_~'] = $row_count;
				}
				$old_count = (int) self::previous( $post_id, $previous_name );
				$new_index = $paged ? $old_count : 0;
				$save_rows = $paged ? self::paginated_save_rows( $value, $old_count ) : array();
				foreach ( $value as $row => $children ) {
					if ( !is_array( $children ) || $row === 'acfcloneindex' ) {
						continue;
					}
					$original = preg_match( '/^row-([0-9]+)$/D', (string) $row, $row_match );
					$storage_index = $paged ? ( $original ? (int) $row_match[1] : $new_index++ ) : $row_index;
					if ( $paged && isset( $children['acf_deleted'] ) ) {
						if ( $original ) {
							self::$deleted_rows[] = '~^' . preg_quote( $name . '_' . $storage_index . '_', '~' ) . '~';
						}
						continue;
					}
					$previous_index = $original ? (int) $row_match[1] : $storage_index;
					$save_index = $paged && isset( $save_rows[(string) $row] ) ? $save_rows[(string) $row] : $storage_index;
					self::walk( $children, $name . '_' . $storage_index . '_', $path . '[' . $row . ']', array_merge( $shape, array( '[0-9]+' ) ), $entries, $post_id, $previous_name . '_' . $previous_index . '_', $save_name . '_' . $save_index . '_' );
					$row_index++;
				}
				continue;
			}
			$entries[$path] = array( 'input' => $path, 'field' => $field, 'name' => $name, 'previous_name' => $previous_name, 'save_name' => $save_name, 'value' => $value, 'shape' => '~^' . implode( '_', $shape ) . '$~D' );
		}
	}

	/** Reproduce ACF's paginated row ordering without writing metadata. */
	private static function paginated_save_rows( $submitted, $old_count ) {
		$rows = array();
		for ( $index = 0; $index < $old_count; $index++ ) {
			$rows[$index] = 'row-' . $index;
		}
		$moved = array();
		$inserted = array();
		$appended = array();
		foreach ( $submitted as $key => $row ) {
			if ( !is_array( $row ) || $key === 'acfcloneindex' ) {
				continue;
			}
			$is_original = preg_match( '/^row-([0-9]+)$/D', (string) $key, $matches );
			if ( $is_original ) {
				$original = (int) $matches[1];
				if ( isset( $row['acf_deleted'] ) ) {
					$rows[$original] = null;
				} elseif ( isset( $row['acf_reordered'] ) ) {
					$moved[(int) $row['acf_reordered']][] = (string) $key;
					unset( $rows[$original] );
				}
			} elseif ( isset( $row['acf_reordered'] ) && (int) $row['acf_reordered'] <= $old_count ) {
				$inserted[(int) $row['acf_reordered']][] = (string) $key;
			} else {
				$appended[] = (string) $key;
			}
		}
		ksort( $moved );
		foreach ( $moved as $target => $values ) {
			array_splice( $rows, $target, 0, $values );
		}
		krsort( $inserted );
		foreach ( $inserted as $target => $values ) {
			array_splice( $rows, $target, 0, $values );
		}
		$rows = array_merge( $rows, $appended );
		$mapping = array();
		$index = 0;
		foreach ( $rows as $key ) {
			if ( $key !== null ) {
				$mapping[$key] = $index++;
			}
		}
		return $mapping;
	}

	/** Find the submitted source for a concrete native save destination. */
	public static function resolve_save_entry( $field, $value, $post_id ) {
		$entries = self::$save_post_id !== null && (string) self::$save_post_id === (string) $post_id ? self::$save_entries : self::submitted_entries( null, $post_id );
		foreach ( $entries as $entry ) {
			if ( $entry['save_name'] === $field['name'] && $entry['field']['key'] === $field['key'] ) {
				$entry['previous_value'] = self::previous( $post_id, $entry['previous_name'] );
				return $entry;
			}
		}
		return null;
	}

	private static function atoms( $value ) {
		$out = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$out = array_merge( $out, self::atoms( $item ) );
			}
		} elseif ( is_scalar( $value ) && (string)$value !== '' ) {
			$out[] = (string)$value;
		}
		return $out;
	}

	private static function comparable( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[$key] = self::comparable( $item );
			}
			return $value;
		}
		return $value === null || $value === false ? '' : ( is_scalar( $value ) ? (string)$value : $value );
	}

	private static function overlaps( $a, $b ) {
		return (bool)array_intersect( self::atoms( $a ), self::atoms( $b ) );
	}

	/** Keep existing PCRE escapes intact and only escape a chosen delimiter when necessary. */
	private static function regex_pattern( $pattern ) {
		foreach ( array( '~', '#', '%', '!', '/', '@', ';' ) as $delimiter ) {
			if ( strpos( $pattern, $delimiter ) === false ) {
				return $delimiter . $pattern . $delimiter;
			}
		}
		$escaped = '';
		$backslashes = 0;
		for ( $index = 0; $index < strlen( $pattern ); $index++ ) {
			$character = $pattern[$index];
			if ( $character === '~' && $backslashes % 2 === 0 ) {
				$escaped .= '\\';
			}
			$escaped .= $character;
			$backslashes = $character === '\\' ? $backslashes + 1 : 0;
		}
		return '~' . $escaped . '~';
	}

	public static function validate( $valid, $value, $field, $input, $post_id = null ) {
		if ( true !== $valid ) {
			return $valid;
		}
		$post_id = null === $post_id ? self::context() : ( $post_id === 0 ? 0 : acf_get_valid_post_id( $post_id ) );
		$entries = self::submitted_entries( null, $post_id );
		$entry = isset( $entries[$input] ) ? $entries[$input] : null;
		$name = $entry ? $entry['name'] : $field['name'];
		$shape = $entry ? $entry['shape'] : '~^' . preg_quote( $name, '~' ) . '$~D';
		$previous = self::previous( $post_id, $entry ? $entry['previous_name'] : $name );
		$readonly_previous = $previous;
		if ( null === $readonly_previous && isset( $field['sub_field']['default_value'] ) ) {
			$readonly_previous = $field['sub_field']['default_value'];
		}
		if ( self::is_true( isset( $field['read_only'] ) ? $field['read_only'] : false ) && self::comparable( $value ) !== self::comparable( $readonly_previous ) ) {
			return __( 'This field is read only.', 'acf_vf' );
		}
		$status = isset( $_POST['post_status'] ) && is_scalar( $_POST['post_status'] ) ? sanitize_key( $_POST['post_status'] ) : get_post_status( $post_id );
		$actual_save = self::$save_post_id !== null && (string) self::$save_post_id === (string) $post_id;
		$server_id = acf_get_form_data( 'vf_post_id' );
		$server_context = $server_id !== false && $server_id !== null && (string) $server_id === (string) $post_id && !acf_get_form_data( 'vf_rest_validation' );
		if ( $actual_save || $server_context ) {
			$status = get_post_status( $post_id ) ?: $status;
		} elseif ( isset( $_POST['publish'] ) ) {
			$status = 'publish';
		}
		$global = get_option( 'acf_vf_drafts', true );
		if ( in_array( $status, array( 'draft', 'auto-draft', 'pending' ), true ) && !self::is_true( $global ) && !self::is_true( isset( $field['drafts'] ) ? $field['drafts'] : true ) ) {
			return true;
		}
		if ( $value === '' || $value === null || $value === false || $value === array() ) {
			return true;
		}
		$message = !empty( $field['message'] ) ? $field['message'] : __( 'The value is not valid.', 'acf_vf' );
		$function = isset( $field['function'] ) ? $field['function'] : 'none';
		$pattern = str_replace( array( '%%squot%%', '%%dquot%%' ), array( "'", '"' ), isset( $field['pattern'] ) ? $field['pattern'] : '' );
		if ( $function !== 'none' && $function !== '' ) {
			if ( $pattern === '' ) {
				return __( 'This field\'s validation is not properly configured.', 'acf_vf' );
			}
			if ( $function === 'regex' ) {
				$subject = is_array( $value ) ? implode( ',', self::atoms( $value ) ) : (string)$value;
				if ( 1 !== @preg_match( self::regex_pattern( $pattern ), $subject ) ) {
					return $message;
				}
			} elseif ( $function === 'php' ) {
				$inputs = array();
				// Keep the legacy top-level aggregate entries alongside resolved leaves.
				$submitted = isset( $_POST['acf'] ) ? (array) wp_unslash( $_POST['acf'] ) : array();
				foreach ( $submitted as $key => $submitted_value ) {
					$input_field = acf_get_field( $key );
					if ( !$input_field ) {
						continue;
					}
					$input_name = $input_field['name'];
					$prev = self::previous( $post_id, $input_name );
					$inputs[$input_name] = array( 'field' => $input_field, 'value' => $submitted_value, 'prev_value' => $prev, 'prev_val' => $prev );
				}
				foreach ( $entries as $item ) {
					$prev = self::previous( $post_id, $item['previous_name'] );
					$inputs[$item['name']] = array( 'field' => $item['field'], 'value' => $item['value'], 'prev_value' => $prev, 'prev_val' => $prev );
				}
				try {
					$run = static function ( $code, $value, $prev_value, $inputs, $post_id, $post_type, $this_key, $name, &$message ) {
						return eval( $code . "\n;return true;" );
					};
					$result = $run( $pattern, $value, $previous, $inputs, $post_id, ( get_post_type( $post_id ) ?: acf_get_form_data( 'post_type' ) ), $name, $name, $message );
					if ( is_string( $result ) ) {
						return $result !== '' ? $result : $message;
					}
					if ( !$result ) {
						return $message;
					}
				} catch ( Throwable $error ) {
					return __( 'PHP validation could not be completed.', 'acf_vf' );
				}
			}
		}
		$scope = isset( $field['unique'] ) ? $field['unique'] : 'non-unique';
		if ( !in_array( $scope, array( 'global', 'post_type', 'post_key', 'this_post', 'this_post_key' ), true ) ) {
			return true;
		}
		$duplicate = __( 'This value must be unique.', 'acf_vf' );
		$key_scope = in_array( $scope, array( 'post_key', 'this_post_key' ), true );
		$atoms = self::atoms( $value );
		if ( count( $atoms ) !== count( array_unique( $atoms ) ) ) {
			return $duplicate;
		}
		foreach ( $entries as $path => $other ) {
			if ( $path === $input || ( $key_scope && $other['shape'] !== $shape ) ) {
				continue;
			}
			if ( self::overlaps( $value, $other['value'] ) ) {
				return $duplicate;
			}
		}
		return self::stored_duplicate( $value, $post_id, $name, $shape, $scope, $field, $entries ) ? $duplicate : true;
	}
	/** Post scopes use post statuses; user scopes stay in usermeta and options stay in their named namespace. */

	private static function stored_duplicate( $value, $post_id, $name, $shape, $scope, $field, $entries ) {
		global $wpdb;
		$decoded = acf_decode_post_id( $post_id );
		$kind = $decoded['type'];
		$id = $decoded['id'];
		$key_scope = in_array( $scope, array( 'post_key', 'this_post_key' ), true );
		$this_only = in_array( $scope, array( 'this_post', 'this_post_key' ), true );
		$args = array();
		$where = array();
		$join = '';
		if ( $kind === 'post' ) {
			$table = $wpdb->postmeta;
			$pk = 'meta_id';
			$owner = 'post_id';
			$key = 'meta_key';
			$val = 'meta_value';
			$join = " JOIN {$wpdb->posts} p ON p.ID=m.post_id";
			$statuses = isset( $field['unique_statuses'] ) ? (array)$field['unique_statuses'] : array( 'publish', 'future' );
			$statuses = array_values( array_filter( $statuses, 'is_string' ) );
			if ( !$statuses ) {
				return false;
			}
			$where[] = 'p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$args = $statuses;
			if ( in_array( $scope, array( 'post_type', 'post_key' ), true ) ) {
				$where[] = 'p.post_type=%s';
				$args[] = get_post_type( $id ) ?: acf_get_form_data( 'post_type' );
			}
			// Translations may share identifiers; the current record's other values still count.
			if ( !$this_only && (int)$id > 0 && function_exists( 'pll_get_post_translations' ) ) {
				$siblings = array();
				foreach ( (array) pll_get_post_translations( (int)$id ) as $translation_id ) {
					$translation_id = absint( $translation_id );
					if ( $translation_id && $translation_id !== (int)$id && get_post_type( $translation_id ) === get_post_type( $id ) ) {
						$siblings[$translation_id] = $translation_id;
					}
				}
				if ( $siblings ) {
					$where[] = 'm.post_id NOT IN (' . implode( ',', array_fill( 0, count( $siblings ), '%d' ) ) . ')';
					$args = array_merge( $args, array_values( $siblings ) );
				}
			}
		} elseif ( $kind === 'user' ) {
			$table = $wpdb->usermeta;
			$pk = 'umeta_id';
			$owner = 'user_id';
			$key = 'meta_key';
			$val = 'meta_value';
		} elseif ( $kind === 'option' ) {
			$table = $wpdb->options;
			$pk = 'option_id';
			$owner = null;
			$key = 'option_name';
			$val = 'option_value';
			$where[] = 'm.option_name LIKE %s';
			$args[] = $wpdb->esc_like( $id . '_' ) . '%';
		} else {
			return false;
		}
		if ( $this_only && $owner ) {
			$where[] = "m.$owner=%s";
			$args[] = $id;
		}
		$candidates = array();
		foreach ( self::atoms( $value ) as $atom ) {
			$candidates[] = "m.$val=%s";
			$args[] = $atom;
			foreach ( array( 's:' . strlen( $atom ) . ':"' . $atom . '";', 'i:' . $atom . ';' ) as $token ) {
				$candidates[] = "m.$val LIKE %s";
				$args[] = '%' . $wpdb->esc_like( $token ) . '%';
			}
		}
		if ( !$candidates ) {
			return false;
		}
		$where[] = '(' . implode( ' OR ', $candidates ) . ')';
		$replaced = array( $name => true );
		foreach ( $entries as $item ) {
			$replaced[$item['name']] = true;
		}
		$last = 0;
		do {
			$sql = "SELECT m.$pk AS cursor_id," . ( $owner ? "m.$owner" : "0" ) . " AS owner_id,m.$key AS meta_name,m.$val AS stored FROM $table m$join WHERE " . implode( ' AND ', $where ) . " AND m.$pk>%d ORDER BY m.$pk LIMIT 250";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $last ) ) ) );
			foreach ( $rows as $row ) {
				$last = (int)$row->cursor_id;
				$meta = $row->meta_name;
				if ( $kind === 'option' ) {
					$meta = substr( $meta, strlen( $id . '_' ) );
				}
				if ( $meta === '' || $meta[0] === '_' ) {
					continue;
				}
				if ( $key_scope && !preg_match( $shape, $meta ) ) {
					continue;
				}
				if ( !$owner || (string)$row->owner_id === (string)$id ) {
					if ( isset( $replaced[$meta] ) ) {
						continue;
					}
					$removed = false;
					foreach ( self::$submitted_rows as $row_pattern => $count ) {
						if ( preg_match( $row_pattern, $meta, $matches ) && (int)$matches[1] >= $count ) {
							$removed = true;
							break;
						}
					}
					foreach ( self::$deleted_rows as $row_pattern ) {
						if ( preg_match( $row_pattern, $meta ) ) {
							$removed = true;
							break;
						}
					}
					if ( $removed ) {
						continue;
					}
				}
				if ( self::overlaps( $value, maybe_unserialize( $row->stored ) ) ) {
					return true;
				}
			}
		} while ( count( $rows ) === 250 );
		return false;
	}
}
