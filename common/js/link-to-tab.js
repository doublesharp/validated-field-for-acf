(function($){
		if (typeof acf == 'undefined') return;

		// Click tab based on location hash
		if ( typeof acf.add_action != 'undefined' ){
			// ACF5
			acf.add_action('ready', processLocationHash);
		} else {
			// ACF4
			$(document).one('acf/fields/tab/refresh', processLocationHash);
		}

		// If there is a location hash, try to use it to click a tab
		function processLocationHash(){
			if (location.hash.length>1){	
				var hash = location.hash.substring(1);
				$('.acf-tab-wrap .acf-tab-button').each(function(i, button){ 
					if (hash==$(button).text().toLowerCase().replace(' ', '-')){
						$(button).trigger('click');

						//acf.unload.changed = 0;
						$(window).off('beforeunload');
						return false;
					}
				});
			}
		}
		// Add location hash when tab is clicked
		$(document).on('click', '.acf-tab-wrap .acf-tab-button', function($el){
			location.hash='#'+$(this).text().toLowerCase().replace(' ', '-');
		});
})(jQuery);