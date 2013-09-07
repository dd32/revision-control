jQuery(document).ready( function($) {
	var container = $( '.misc-pub-revision-control' );
	var revisions_container = $( '.misc-pub-revisions' );

	var toggle_options = function() {
		$( '#revisions-settings' ).toggle( 'slide' );
	};

	container.find( 'span.revisions-edit' ).insertBefore( revisions_container.find( 'a:last-child' ) );
	container.find( '#revisions-settings' ).appendTo( revisions_container );

	revisions_container.find( 'span.revisions-edit a' ).click( toggle_options );
	revisions_container.find( '#revisions-settings a.button' ).click( toggle_options );
	
	container.remove();
});