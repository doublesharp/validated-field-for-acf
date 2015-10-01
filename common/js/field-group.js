/*
	Advanced Custom Fields: Validated Field
	Justin Silver, http://justin.ag
	DoubleSharp, http://doublesharp.com

	Based on code in Advanced Custom Fields
*/
acf.field_group.submit = function(){
	// reference
	var _this = acf.field_group;
	
	// close / delete fields
	self.$fields.find('.acf-field-object').each(function(){
		
		// vars
		var save = self.get_field_meta( $(this), 'save'),
			ID = self.get_field_meta( $(this), 'ID'),
			open = $(this).hasClass('open');
		
		
		// clone
		if( ID == 'acfcloneindex' ) {
			
			$(this).remove();
			return;
			
		}
		
		
		// close
		if( open ) {
			
			self.close_field( $(this) );
			
		}
		
		
		// remove unnecessary inputs
		if( save == 'settings' ) {
			
			// allow all settings to save (new field, changed field)
			
		} else if( save == 'meta' ) {
			
			$(this).children('.settings').find('[name^="acf_fields[' + ID + ']"]').remove();
			
		} else {
			
			$(this).find('[name^="acf_fields[' + ID + ']"]').remove();
			
		}
		
	});
	
	
	// return
	return true;
}