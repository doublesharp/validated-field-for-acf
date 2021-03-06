/*
	Advanced Custom Fields: Validated Field
	Justin Silver, http://justin.ag
	DoubleSharp, http://doublesharp.com
*/
var vf = {
	valid 		: false,
	$el 		: false,
	reclick		: false,
	debug 		: false,
	drafts		: true,
};

if ( typeof acf.o == 'undefined' ){
	acf.o = {};
}

(function($){
	$(document).ready(function(){
		// Make sure the errors are formatted by adding the acf_postbox class
		$('#profile-page').addClass('acf_postbox');
		$('form.acf-form input[type="submit"]').addClass('button button-primary button-large');

		// Trigger a custom event when the tabs are refreshed		
		var origRefresh = acf.fields.tab.refresh;
		acf.fields.tab.refresh = function(){
			result = origRefresh.apply(this, arguments);
			for (i=0; i<arguments.length;i++){
				$(arguments[i]).trigger('acf/fields/tab/refresh', [arguments]);
			}
		    return result;
		};

		// DOM elements we need to validate the value of
		inputSelector = 'input[type="text"], input[type="hidden"], textarea, select, input[type="checkbox"]:checked';

		// If a form value changes, mark the form as dirty
		$(document).on('change', inputSelector, function(){
			vf.valid = false;
		});

		// Clear out the errors as the fields are selected or changed
		$(document).on('change, keypress, focus', inputSelector, function(){
			$(this).closest('.field').removeClass('error');
		});

		// When a .button is clicked we need to track what was clicked
		$(document).on('click', 'form#post .button, form#post input[type=submit], form#your-profile input[type=submit], form.acf-form input[type=submit]', function(e){
			//e.preventDefault();
			vf.$el = $(this);
			// The default 'click' runs first and then calls 'submit' so we need to retrigger after we have tracked the '$el'
			if (vf.reclick){
				vf.reclick = false;
				vf.$el.trigger('click');
			}
		});
		
		// Intercept the form submission
		$(document).on('submit', 'form#post, form#your-profile, form.acf-form', function(){
			// remove error messages since we are going to revalidate
			$('.field_type-validated_field').find('.acf-error-message').remove();

			$(this).siblings('#acfvf_message, #message').remove();

			if ( ! acf.validation.status ){
				return false;
			}

			// If we don't have a '$el' this is probably a preview where WordPress calls 'click' first
			if (!vf.$el){
				// We need to let our click handler run, then start the whole thing over in our handler
				vf.reclick = true;
				return false;
			}

			// We mith have already checked the form and vf.valid is set and just want all the other 'submit' functions to run, otherwise check the validation
			return vf.valid || do_validation($(this), vf.$el);
		});

		// Validate the ACF Validated Fields
		function do_validation(formObj, clickObj){
			// default the form validation to false
			vf.valid = false;
			// we have to know what was clicked to retrigger
			if (!clickObj) return false;
			// validate non-"publish" clicks unless vf.drafts is set to false
			if (acf.o.post_id!='options'&&!vf.drafts&&clickObj.attr('id')!='publish') return true;
			// gather form fields and values to submit to the server
			var fields = [];

			// inspect each of the validated fields
			formObj.find('.field').not('[data-field_type="repeater"]').each(function(){
				var $el = $(this);

				// we want to show some of the hidden fields.
				if ( $el.is(':hidden') ){
					validate = false;

					// if this field is hidden by a tab group, allow validation
					if ( $el.hasClass('acf-tab_group-hide') ){						
						// vars
						var $tab_field = $el.prevAll('.field_type-tab:first'),
							$tab_group = $el.prevAll('.acf-tab-wrap:first');			
						
						// if the tab itself is hidden, bypass validation
						if ( !$tab_field.hasClass('acf-conditional_logic-hide') ){
							// activate this tab as it holds hidden required field!
							$tab = $tab_group.find('.acf-tab-button[data-key="' + $tab_field.attr('data-field_key') + '"]');
							validate = true;
						}
					}
					if (!validate){
						return;
					}
				}
				
				// if is hidden by conditional logic, ignore
				if ( $el.hasClass('acf-conditional_logic-hide') ){
					return;
				}
				
				// if field group is hidden, ignore
				if ( $el.closest('.postbox.acf-hidden').exists() ){
					return;	
				}
				
				var field = null;
				if ( $el.find('.acf_wysiwyg').exists() && typeof( tinyMCE ) == "object" ){
					// wysiwyg
					var id = $el.find('.wp-editor-area').attr('id'),
						editor = tinyMCE.get( id );
					fields.push({ 
						id: $el.find('.wp-editor-area').attr('name'),
						value: editor.getContent()
					});
				} else if ( $el.find('.acf_relationship, input[type="radio"], input[type="checkbox"]').exists() ) {
					// relationship / radio / checkbox
					sel = '.acf_relationship .relationship_right input, input[type="radio"]:checked, input[type="checkbox"]:checked';
					field = { id: $el.find('input[type="hidden"], ' + sel ).attr('name'), value: [] };
					$inputs = $el.find( sel );
					if ( $inputs.length ){	
						$inputs.each( function(){
							field.value.push( $( this ).val() );
						});
						fields.push(field);
					}
				} else {
					// text / textarea / select
					var text = $el.find('input[type="text"], input[type="email"], input[type="number"], input[type="hidden"], textarea, select');
					if ( text.exists() ){
						fields.push({ 
							id: text.attr('name'),
							value: text.val()
						});
					}
				}
			});

			$('.acf_postbox:hidden').remove();

			// if there are no fields, don't make an ajax call.
			if ( ! fields.length ){
				vf.valid = true;
				return true;
			} else {
				// send everything to the server to validate
				var prefix = '';
				if ( $('#profile-page').length ){
					post_id = $('#user_id').val();
					prefix = 'user_';
				} else if ( acf.o.post_id == 'options' ){
					post_id = 'options';
				} else {
					post_id = $(vf.frontend? 'input[name=post_id]' : '#post_ID').val();
				}
				$.ajax({
					url: ajaxurl,
					data: {
						action: 'validate_fields',
						post_id: prefix + post_id,
						click_id: clickObj.attr('id'),
						frontend: vf.frontend,
						fields: fields
					},
					type: 'POST',
					dataType: 'json',
					success: function(json){
						ajax_returned(json, formObj, clickObj);				
					}, error:function (xhr, ajaxOptions, thrownError){
						ajax_returned(fields, formObj, clickObj);
	 				}
				});

				// return false to block the 'submit', we will handle as necessary once we get a response from the server
				return false;
			}
			
			// Process the data returned by the server side validation
			function ajax_returned(fields, formObj, clickObj){
				// now we default to true since the response says if something is invalid
				vf.valid = true;
				// if we got a good response, iterate each response and if it's not valid, set an error message on it
				if (fields){
					for (var i=0; i<fields.length; i++){
						var fld = fields[i];
						if (!fld.valid){
							vf.valid = false;
							msg = $('<div/>').html(fld.message).text();
							input = $('[name="'+fld.id.replace('[', '\\[').replace(']', '\\]')+'"]');
							input.closest('.validated-field').append('<span class="acf-error-message"><i class="bit"></i>' + msg + '</span>');
							field = input.closest('.field');
							field.addClass('error');
							field.find('.widefat').css('width','100%');
						}
					}
				}
				
				// reset all the CSS
				$('#ajax-loading').attr('style','');
				$('.submitbox .spinner').hide();
				$('.submitbox .button').removeClass('button-primary-disabled').removeClass('disabled');
				if ( !vf.valid ){
					// if it wasn't valid, show all the errors
					formObj.before('<div id="acfvf_message" class="error"><p>'+vf_l10n.message+'</p></div>');
					formObj.find('.field_type-validated_field .acf-error-message').show();
				} else if ( vf.debug ){
					// it was valid, but we have debugging on which will confirm the submit
					vf.valid = confirm(vf_l10n.debug);
				} 
				// if everything is good, reclick which will now bypass the validation
				if (vf.valid) {
					clickObj.trigger('click');
				}
			}
		}
	});
})(jQuery);
