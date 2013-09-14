jQuery(function($){
	
	// Admin page tabs
	var $tabs = $('.nav-tab-wrapper'),
	$panels = $('.nav-tab-content'),
	currentHash = window.location.hash;

	$tabs.on('click', 'a', function(e){
		var hash = $(this).attr('href').replace('#tab-', '#tab-content-'); // prevents page scrolling if hash is present
		$panels.hide().filter(hash).show();
		$tabs.find('a').removeClass('nav-tab-active').filter($(this)).addClass('nav-tab-active');
	});
	$tabs.find( currentHash ? 'a[href="'+currentHash+'"]' : ':first').trigger('click');

	// Fix input-inside-label glitch
	$panels.on('click', 'input[type=text]', function(e){
		e.preventDefault();
	})
	
});