(function($){
	if (typeof acf == 'undefined') return;


	/**
	* Simply compares two string version values.
	* 
	* Example:
	* versionCompare('1.1', '1.2') => -1
	* versionCompare('1.1', '1.1') =>  0
	* versionCompare('1.2', '1.1') =>  1
	* versionCompare('2.23.3', '2.22.3') => 1
	* 
	* Returns:
	* -1 = left is LOWER than right
	*  0 = they are equal
	*  1 = left is GREATER = right is LOWER
	*  And FALSE if one of input versions are not valid
	*
	* @function
	* @param {String} left  Version #1
	* @param {String} right Version #2
	* @return {Integer|Boolean}
	* @author Alexey Bass (albass)
	* @since 2011-07-14
	*/
	versionCompare = function(left, right) {
		if (typeof left + typeof right != 'stringstring')
			return false;

		var a = left.split('.')
		,   b = right.split('.')
		,   i = 0, len = Math.max(a.length, b.length);

		for (; i < len; i++) {
			if ((a[i] && !b[i] && parseInt(a[i]) > 0) || (parseInt(a[i]) > parseInt(b[i]))) {
				return 1;
			} else if ((b[i] && !a[i] && parseInt(b[i]) > 0) || (parseInt(a[i]) < parseInt(b[i]))) {
				return -1;
			}
		}

		return 0;
	}

	// Open fields in Field Group Editor when page is refreshed
	$(document).ready(function(){

		// Functionality is the same, selectors are different between ACF versions
		selectors = ( versionCompare('5.0.0', acf.version)>0 ) ? 
			{ // ACF 4
				'row_options_edit_field': '.row_options .acf_edit_field',
				'field': '.field',
				'open': 'form_open',
				'edit_field': '.acf_edit_field'
			} :
			{ // ACF 5
				'row_options_edit_field': '.row-options .edit-field',
				'field': '.acf-field-object',
				'open': 'open',
				'edit_field': '.edit-field'
			};

		// Check for the location hash and process if present
		if (location.hash.length>1){
			var hash = location.hash.substring(1);
			var arr = jQuery.grep(hash.split(';'), function(a){
				return a.trim() != "";
			});
			if (arr.length){
				$(selectors['row_options_edit_field']).each(function(i, button){
					var $field = $(this).closest(selectors['field']);
					var field_hash = $field.data('key');
					if ($.inArray(field_hash, arr)>=0){
						$(button).trigger('click');
					}
				});
				acf.unload.changed = 0;
				$(window).off('beforeunload');
			}
		}
		// Add open tabs to the location hash
		$(document).on('click', selectors['edit_field'], function($el){
			var hash = (location.hash.length>1? location.hash.substring(1) : '').split(';');
			var $field = $(this).closest(selectors['field']);
			var field_hash = $field.data('key');
			// make sure the hash is clean
			for (i=0; i<hash.length; i++){
				if (hash[i]==field_hash){
					hash.splice(i, 1);
				}
			}
			if ($field.hasClass(selectors['open'])){
				hash.push(field_hash);
			}
			hash = jQuery.grep(hash, function(a){
				return a.trim() != "";
			});
			location.hash='#'+hash.join(';');
		});
		
	});
})(jQuery);