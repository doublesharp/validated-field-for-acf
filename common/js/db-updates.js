(function($){
	if (typeof acf == 'undefined') return;

	// Click tab based on location hash
	if ( typeof acf.add_action != 'undefined' ){
		// ACF5
		acf.add_action('ready', db_update_tab_formatting);
	} else {
		// ACF4
		$(document).one('acf/fields/tab/refresh', db_update_tab_formatting);
	}

	function db_update_tab_formatting(){
		$('.acf-tab-button').filter('[data-key="field_5617ec772774e"]').css({'color':'red'});
	};

	// Empty object, we are going to use this as our Queue
	var ajaxQueue = $({});
	$.ajaxQueue = function(ajaxOpts) {
		// hold the original complete function
		var oldComplete = ajaxOpts.complete;

		// queue our ajax request
		ajaxQueue.queue(function(next) {    

			// create a complete callback to fire the next event in the queue
			ajaxOpts.complete = function(){
				// fire the original complete if it was there
				if (oldComplete) oldComplete.apply(this, arguments);    
				next(); // run the next query in the queue
			};

			// run the query
			$.ajax(ajaxOpts);
		});
	};

	$(document).ready(function(){
		$('form#post input[type="submit"]').attr('disabled', true);

		$upgrades = $('#acf-vf-db-upgrades');
		$.ajax({
			url: ajaxurl,
			data: {
				action: 'acf_vf_get_upgrades'
			},
			type: 'POST',
			dataType: 'json',
			success: function(json){
				$ul = $('<ul style="list-style: disc; padding-left: 20px;"/>');
				$.each(json.upgrades, function(i, upgrade ){
					$ul.append('<li data-upgrade="'+upgrade.upgrade+'" id="upgrade-'+upgrade.upgrade+'">'+upgrade.label+'</li>');
				});

				$upgrades.append(json.message);
				$upgrades.append($ul);
				$upgrades.append('<br/><br/><input id="acf-vf-do-upgrades" type="button" class="button button-primary button-large" value="'+json.action+'"/>');
			}, 
			error: function (xhr, ajaxOptions, thrownError){
				console.log(xhr);	
				$upgrades.append('<div class="error">'+xhr.responseText+'</div>');
			}
		});

		$upgrades.on('click', '#acf-vf-do-upgrades', function(){
			$(this).attr('disabled', true);
			var success = true;
			$upgrades.find('li').each(function(i, $el){
				upgrade = $(this).data('upgrade');
				$.ajaxQueue({
					url: ajaxurl,
					data: {
						action: 'acf_vf_do_upgrade',
						upgrade: upgrade
					},
					type: 'POST',
					dataType: 'json',
					success: function(json){
						$ul = $('<ul style="padding-left: 20px; font-size:.8em;"/>');
						$.each(json.messages, function(i, messages ){
							$ul.append('<li>'+messages.text+'</li>');
						});
						$upgrade = $upgrades.find('#upgrade-'+json.id);
						$upgrade.append($ul);
					}, 
					error: function (xhr, ajaxOptions, thrownError){
						console.log(xhr);	
						$upgrades.append('<div class="error">'+xhr.responseText+'</div>');

						success = false;
						// Stop processing upgrades
						return false;
					}
				});

			});
				
			ajaxQueue.queue(function(next) {
				if ( success ){
					$upgrades.append('<br/><br/>' + vf_upgrade_l10n.upgrade_complete );
					setTimeout(function(){
						location.hash = 'general';
						location.reload();
					}, 1500 );
				}
			});

		});

	});

})(jQuery);