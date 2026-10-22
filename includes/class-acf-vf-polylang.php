<?php
/** Opt-in field copy policies using free Polylang's public metadata hooks. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ACF_VF_Polylang {
	private $pending_fields = array();
	private $metadata_changes = array();

	public function __construct() {
		add_filter( 'pll_copy_post_metas', array( $this, 'copy_metas' ), 20, 5 );
		add_filter( 'pll_translate_post_meta', array( $this, 'translate_meta' ), 20, 5 );
		add_action( 'acf/render_field_advanced_settings/type=validated_field', array( $this, 'render_settings' ) );
		add_filter( 'acf/update_value/type=validated_field', array( $this, 'remember_field' ), 1, 3 );
		add_action( 'added_post_meta', array( $this, 'remember_change' ), 0, 4 );
		add_action( 'update_post_meta', array( $this, 'remember_change' ), 0, 4 );
		add_action( 'delete_post_meta', array( $this, 'remember_change' ), 0, 4 );
		add_action( 'added_post_meta', array( $this, 'forget_change' ), 9999, 4 );
		add_action( 'updated_post_meta', array( $this, 'forget_change' ), 9999, 4 );
		add_action( 'deleted_post_meta', array( $this, 'forget_change' ), 9999, 4 );
	}

	public function remember_change( $meta_id, $post_id, $name, $value ) {
		if ( ! $this->enabled() || ! $this->field_for_meta( $name, $post_id, 0 ) ) { return; }
		$this->metadata_changes[$post_id][$name] = array(
			'after' => $value,
			'operation' => current_action(), 'targets' => array(),
		);
	}

	public function forget_change( $meta_id, $post_id, $name, $value ) {
		unset( $this->metadata_changes[$post_id][$name] );
	}

	public function remember_field( $value, $post_id, $field ) {
		$decoded = acf_decode_post_id( $post_id );
		if ( $this->enabled() && $decoded['type'] === 'post' && (int)$decoded['id'] > 0 ) {
			// ACF writes the public value before its _field reference on the first save.
			$this->pending_fields[(int)$decoded['id']][$field['name']] = $field;
		}
		return $value;
	}

	private function enabled() {
		// Pro owns its ACF copy workflows. Do not register a competing writer.
		return function_exists( 'pll_get_post_translations' ) && ! defined( 'POLYLANG_PRO' );
	}

	private function mode( $field ) {
		$mode = $field['vf_translation'] ?? '';
		return in_array( $mode, array( 'ignore', 'copy_once', 'translate', 'sync' ), true ) ? $mode : '';
	}

	private function field_for_meta( $name, $from, $to ) {
		if ( ! is_string( $name ) || $name === '' || $name[0] === '_' ) { return false; }
		$key = get_post_meta( $from, '_' . $name, true ) ?: get_post_meta( $to, '_' . $name, true );
		$field = $this->pending_fields[$from][$name] ?? null;
		if ( ! $field && is_string( $key ) && acf_is_field_key( $key ) ) { $field = acf_get_field( $key ); }
		if ( ! $field || $field['type'] !== 'validated_field' || $field['name'] !== $name ) { return false; }
		// Layout descendants need coordinated structural copying, not isolated leaf rules.
		if ( ! empty( $field['parent'] ) && acf_get_field( $field['parent'] ) ) { return false; }
		return $field;
	}

	public function copy_metas( $metas, $sync, $from = 0, $to = 0, $lang = '' ) {
		if ( ! $this->enabled() || ! $from || ! $to ) { return $metas; }
		$names = array_unique( array_merge( array_keys( get_post_meta( $from ) ), array_keys( get_post_meta( $to ) ) ) );
		$can_copy = current_user_can( 'edit_post', $from ) && current_user_can( 'edit_post', $to );
		foreach ( $names as $name ) {
			if ( $name === '' ) { continue; }
			$name = $name[0] === '_' ? substr( $name, 1 ) : $name;
			$field = $this->field_for_meta( $name, $from, $to );
			$mode = $field ? $this->mode( $field ) : '';
			if ( $mode === '' ) { continue; }
			$pair = array( $name, '_' . $name );
			$metas = array_values( array_diff( $metas, $pair ) );
			if ( ! $can_copy || $mode === 'ignore' || ( $sync && $mode !== 'sync' ) ) { continue; }
			if ( ! $sync && $mode !== 'sync' && metadata_exists( 'post', $to, $name ) ) { continue; }
			$metas = array_merge( $metas, $pair );
		}
		return array_values( array_unique( $metas ) );
	}

	public function translate_meta( $value, $name, $lang, $from = 0, $to = 0 ) {
		if ( ! $this->enabled() || ! $from || ! $to ) { return $value; }
		$field = $this->field_for_meta( $name, $from, $to );
		if ( ! $field || ! in_array( $this->mode( $field ), array( 'copy_once', 'translate', 'sync' ), true ) ) { return $value; }
		$type = $field['sub_field']['type'] ?? 'text';
		$lookup = null;
		if ( in_array( $type, array( 'relationship', 'post_object', 'page_link', 'image', 'file', 'gallery' ), true ) && function_exists( 'pll_get_post' ) ) {
			$lookup = 'pll_get_post';
		} elseif ( $type === 'taxonomy' && function_exists( 'pll_get_term' ) ) {
			$lookup = 'pll_get_term';
		}
		if ( ! $lookup ) { return $value; }
		// Polylang reuses each translated value for the next language in its loop.
		// Restore the source input, while preserving its separate previous-value lookup.
		if ( isset( $this->metadata_changes[$from][$name] ) ) {
			$change = &$this->metadata_changes[$from][$name];
			if ( $change['operation'] === 'update_post_meta' ) {
				// Polylang 3.9 requests previous, then new value separately for each target.
				$phase = $change['targets'][$to] ?? 0;
				if ( $phase % 2 !== 0 ) { $value = $change['after']; }
				$change['targets'][$to] = $phase + 1;
			} else {
				$value = $change['after'];
			}
			unset( $change );
		}
		$map = static function( $id ) use ( $lookup, $lang ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) { return $id; }
			return $lookup( (int) $id, $lang ) ?: $id;
		};
		return is_array( $value ) ? array_map( $map, $value ) : $map( $value );
	}

	public function render_settings( $field ) {
		if ( ! $this->enabled() || ( ! empty( $field['parent'] ) && acf_get_field( $field['parent'] ) ) ) { return; }
		acf_render_field_setting( $field, array(
			'label' => __( 'Polylang translation', 'acf_vf' ), 'name' => 'vf_translation', 'type' => 'select',
			'value' => $this->mode( $field ),
			'instructions' => __( 'For top-level fields with free Polylang. Manual translation copies the initial value for editing. Synchronize requires permission to edit both records. Polylang Pro manages its own translation settings.', 'acf_vf' ),
			'choices' => array(
				'' => __( 'Use existing Polylang behavior', 'acf_vf' ),
				'ignore' => __( 'Ignore when copying', 'acf_vf' ),
				'copy_once' => __( 'Copy once', 'acf_vf' ),
				'translate' => __( 'Copy for manual translation', 'acf_vf' ),
				'sync' => __( 'Synchronize across translations', 'acf_vf' ),
			),
		) );
	}
}
