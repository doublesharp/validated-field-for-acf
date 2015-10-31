/*
	Advanced Custom Fields: Validated Field
	Justin Silver, http://justin.ag
	DoubleSharp, http://doublesharp.com
*/
(function($){	
	acf.add_action('ready append open_field', function( $el ){

		// Only execute for validated field types
		if ($el.data('type') != 'validated_field' && $el.data('setting') != 'validated_field')
			return;

		// Get the parent level field
		var $field = ($el.data('type') == 'validated_field')? 
			$el :
			$el.closest('.acf-field');

		if ( $field.data('validation-setup') == 'true' )
			return;

		// Hide the editor, since we want to use ACE.js
		$field.find('textarea.editor').hide();

		// Import ACE.js and PHP syntax
    	ace.require("ace/ext/language_tools");
		ace.config.loadModule('ace/snippets/snippets');
		ace.config.loadModule('ace/snippets/php');
		ace.config.loadModule("ace/ext/searchbox");

		// Create ACE.js and assign to the editor textarea
		var editor = ace.edit($field.find('.ace-editor').attr('id'));
		editor.setTheme("ace/theme/monokai");
		editor.getSession().setMode("ace/mode/text");

		// When the IDE content changes, update textarea (chop php tags)
		editor.getSession().on('change', function(e){
			var val = editor.getValue();
			var func = $field.find('.validation-function').val();
			if (func=='php'){
				val = val.substr(val.indexOf('\n')+1);
			} else if (func=='regex'){
				if (val.indexOf('\n')>0){
					editor.setValue(val.trim().split('\n')[0]);
				}
			}
			$field.find('textarea.editor').val(val);
		});

		// Cache the editor so we can find it
		$field.find('.ace-editor').data('editor', editor);

		// Validation function changes
		$field.find('.validation-function').on('change', function(){
			$field.find('.validation-info div').hide(300);
			$field.find('.validation-info div.'+$(this).val()).show(300);

			// Show things it's not none
			$display = $(this).val()!='none';
			$field.find('.validation-settings').each(function(){
				$setting = ($(this).hasClass('acf-field'))? $(this) : $(this).closest('.acf-field');
				$toggle = ($display)? $setting.show(300) : $setting.hide(300);
			});
			// Leading tag for PHP
			var sPhp = '<'+'?'+'php';
			var editor = $field.find('.ace-editor').data('editor');
			var val = editor.getValue();
			if ($(this).val()=='none'){
				// Hide the pattern and message
				$field.filter('[data-name="pattern"], [data-name="message"]').hide(300);
			} else {
				if ($(this).val()=='php'){
					// Prepend the PHP open tag
					if (val.indexOf(sPhp)!=0){
						editor.setValue(sPhp +'\n' + val);
					}
					// Set to PHP mode, set options
					editor.getSession().setMode("ace/mode/php");
					editor.setOptions({
						enableBasicAutocompletion: true,
						enableSnippets: true,
						enableLiveAutocompletion: true
					});
					// Resize editor window
					$field.find('.ace-editor').css('height','420px');
				} else {
					// If the string starts with the PHP open tag, remove it
					if (val.indexOf(sPhp)==0){
						editor.setValue(val.substr(val.indexOf('\n')+1));
					}
					// Set to Text mode for regex, set options
					editor.getSession().setMode("ace/mode/text");
					editor.setOptions({
						enableBasicAutocompletion: false,
						enableSnippets: false,
						enableLiveAutocompletion: false
					});
					// Resize the editor window
					$field.find('.ace-editor').css('height','18px');
				}
				// Trrigger an editor resize to fill container
				editor.resize();
				// Go to the first line
				editor.gotoLine(1, 1, false);
				// Show the fields
				$field.filter('[data-name="pattern"], [data-name="message"]').show(300);
			}
		});
		// Initial setup
		$field.find('.validation-function').trigger('change');

		// When we change the input mask check to see if we should show sub options
		$field.find('.input-mask').on('change keyup blur', function(){

			$type = $field.find('tr[data-name="type"] select option:selected').last();
			$mask = $field.find('.input-mask').closest('.acf-field');
			$display = false;
			// Input masking is only valid on a few field types
			if ($.inArray($type.val(), ['text', 'url', 'password'])==-1){
				$mask.hide(300);
			} else {
				$mask.show(300);
				$display = $(this).val()!='';
			}

			$field.find('.mask-settings').each(function(){
				$setting = ($(this).hasClass('acf-field'))? $(this) : $(this).closest('.acf-field');
				$toggle = ($display)? $setting.show(300) : $setting.hide(300);
			});

		});
		// Initial setup
		$field.find('.input-mask').trigger('change');

		// Uniqueness changes
		$field.find('.validation-unique').on('change',function(){
			var $this = $(this);
			var unqa = $this.closest('.acf-field').siblings('tr[data-name*="unique_"]');
			if ($this.val()=='non-unique'||$this.val()=='') { unqa.hide(300); } else { unqa.show(300); }
		});
		// Initial setup
		$field.find('.validation-unique').trigger('change');

		// Repeaters need setup too
		$field.filter('.acf-sub_field').find('.field').each(function(){
			if ( $(this).attr('id') == 'acfcloneindex' ){
				$(this).find('select').trigger('change');
			}
		});

		// Ready to go
		$field.data('validation-setup', 'true');
	});

	// Set the field label when the page loads
	acf.add_action('ready', function( $body ){
		$body.find('.acf-field-object').each(function(){
			setValidatedFieldLabel($(this));
		});
	});

	// Also set the field label when the sub type changes.
	acf.add_action('change_field_type', function( $el ){
		if ($el.data('type') != 'validated_field')
			return;
		setValidatedFieldLabel( $el );
	});

	function setValidatedFieldLabel($el){
		$types = $el.find('tr[data-name="type"] select option:selected');
		if ($types.first().val() == 'validated_field'){
			$el.find('.li-field-type').text('Validated: ' + $types.last().text());
		}
	}

	// Hide the input mask for some sub field types
	acf.add_action('change_field_type', function( $el ){
		if ($el.data('type') != 'validated_field')
			return;
		// Get the parent level field
		var $field = ($el.data('type') == 'validated_field')? 
			$el :
			$el.closest('.acf-field');

		$field.find('.input-mask').trigger('change');

		//var sub_type = $field.find('tr[data-name="type"] select option:selected').last().val();
		var types = $field.find('tr[data-name="type"] select option:selected');
		$field.find('tr[data-name="type"]').each(function(i, tr){
			$(this).siblings().each(function(j, el){
				var setting = $(this).data('setting');
				if (typeof setting != 'undefined'&&setting!=$(types[i]).val()){
					$(this).hide();
				}
			});
		});
	});

})(jQuery);