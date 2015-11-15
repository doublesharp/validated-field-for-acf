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

		// Click tab based on the hash changing
		$(window).on('hashchange', processLocationHash);

		// We want to track hash changes when a tab is clicked, but not click it again
		var ignoreHashChange = false;

		// If there is a location hash, try to use it to click a tab
		function processLocationHash(){
			if (!ignoreHashChange && location.hash.length>1){
				clickTabByName(location.hash.slice(1));
			}
		}

		// Look for all the tabs and click the first name that matches the hash
		function clickTabByName(name){
			$('.acf-tab-wrap .acf-tab-button').each(function(i, button){ 
				if (name==$(button).text().toLowerCase().replace(' ', '-')){
					$(button).trigger('click');

					//acf.unload.changed = 0;
					$(window).off('beforeunload');
					return false;
				}
			});
		}

		// Add location hash when tab is clicked
		$(document).on('click', '.acf-tab-wrap .acf-tab-button', function($el){
			ignoreHashChange = true;
			location.hash='#'+$(this).text().toLowerCase().replace(' ', '-');
			ignoreHashChange = false;
		});

})(jQuery);

