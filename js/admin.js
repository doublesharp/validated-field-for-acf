/*
	Advanced Custom Fields: Validated Field
	Justin Silver, http://justin.ag
	DoubleSharp, http://doublesharp.com
*/
(function($){	
	acf.add_action('ready append open_field', function( $el ){
		if ($el.data('type') != 'validated_field' && $el.data('setting') != 'validated_field')
			return;

		var $field = ($el.data('type') == 'validated_field')? 
			$el :
			$el.closest('.acf-field');

		if ( $field.data('validation-setup') == 'true' )
			return;

		$field.find('textarea.editor').hide();

    	ace.require("ace/ext/language_tools");
		ace.config.loadModule('ace/snippets/snippets');
		ace.config.loadModule('ace/snippets/php');
		ace.config.loadModule("ace/ext/searchbox");

		var editor = ace.edit($field.find('.ace-editor').attr('id'));
		editor.setTheme("ace/theme/monokai");
		editor.getSession().setMode("ace/mode/text");
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
		$field.find('.ace-editor').data('editor', editor);

		$field.find('.validation-function').on('change', function(){
			$field.find('.validation-info div').hide(300);
			$field.find('.validation-info div.'+$(this).val()).show(300);

			$display = $(this).val()!='none';
			$field.find('.validation-settings').each(function(){
				$setting = ($(this).hasClass('acf-field'))? $(this) : $(this).closest('.acf-field');
				$toggle = ($display)? $setting.show(300) : $setting.hide(300);
			});
			var sPhp = '<'+'?'+'php';
			var editor = $field.find('.ace-editor').data('editor');
			var val = editor.getValue();
			if ($(this).val()=='none'){
				$field.filter('[data-name="pattern"], [data-name="message"]').hide(300);
			} else {
				if ($(this).val()=='php'){
					if (val.indexOf(sPhp)!=0){
						editor.setValue(sPhp +'\n' + val);
					}
					editor.getSession().setMode("ace/mode/php");
					$field.find('.ace-editor').css('height','420px');

					editor.setOptions({
						enableBasicAutocompletion: true,
						enableSnippets: true,
						enableLiveAutocompletion: true
					});
				} else {
					if (val.indexOf(sPhp)==0){
						editor.setValue(val.substr(val.indexOf('\n')+1));
					}
					editor.getSession().setMode("ace/mode/text");
					editor.setOptions({
						enableBasicAutocompletion: false,
						enableSnippets: false,
						enableLiveAutocompletion: false
					});
					$field.find('.ace-editor').css('height','18px');
				}
				editor.resize();
				editor.gotoLine(1, 1, false);
				$field.filter('[data-name="pattern"], [data-name="message"]').show(300);
			}
		});
		$field.find('.validation-function').trigger('change');

		$field.find('.input-mask').on('change keyup blur', function(){
			$display = $(this).val()!='';
			$field.find('.mask-settings').each(function(){
				$setting = ($(this).hasClass('acf-field'))? $(this) : $(this).closest('.acf-field');
				$toggle = ($display)? $setting.show(300) : $setting.hide(300);
			});
		});
		$field.find('.input-mask').trigger('change');

		$field.find('.validation-unique').on('change',function(){
			var $this = $(this);
			var unqa = $this.closest('.acf-field').siblings('tr[data-name="unique_statuses"]');
			if ($this.val()=='non-unique'||$this.val()=='') { unqa.hide(300); } else { unqa.show(300); }
		});
		$field.find('.validation-unique').trigger('change');

		$field.filter('.acf-sub_field').find('.field').each(function(){
			if ( $(this).attr('id') == 'acfcloneindex' ){
				$(this).find('select').trigger('change');
			}
		});

		$field.data('validation-setup', 'true');
	});

	acf.add_action('ready', function( $body ){
		$body.find('.acf-field-object').each(function(){
			setValidatedFieldLabel($(this));
		});
	});

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

})(jQuery);