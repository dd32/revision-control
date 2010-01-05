jQuery( function($) {

	$('a#revision-compare-label, a#revision-delete-label').bind('click',
	function () {
		$(this).parents('th').find('a').toggle();
		$(this).parents('table').find('.check-column input.toggle-type').toggle();
	});
	
	$('#revisions-delete').bind('click',
	function () {
		if ( !confirm('Are you sure you wish to delete the selected revisions?') )
			return;

		var checked = [];
		$('#revisionsdiv :checkbox').each(function(i, element) {
											   if ( $(element).is(':checked') ) {
												   checked[checked.length] = $(element).val();
												}
											});
		$.post('admin-post.php', {
			action: 'revision-control-delete',
			revisions: checked.join(','),
			_wpnonce: $('#revision-control-nonce').val()
			}, function (xml) {	

				var r = wpAjax.parseAjaxResponse(xml);

				var success = r.responses[0].data;
				if ( -1 == success ) {
					//Error - Failed to delete.
					
				} else {
					//Removed OK
					var revs = r.responses[0].supplemental.revisions.split(',');
					for( var i in revs ) {
						$('#revision-row-' + revs[i]).remove();
					}
				}
		});
	});
	$('#revisions-compare').bind('click', 
	function () {
		var left = $(this).parents('table').find('input.left:checked').val();
		var right = $(this).parents('table').find('input.right:checked').val();
		if ( undefined == left || undefined == right ) {
			alert("Please select both");
			return;
		}
		
		tb_show("Revision Compare", 'admin-post.php?action=revision-control-revision-compare&left=' + left + '&right=' + right + '&TB_iframe=true', false);
		
		this.blur();
		
//alert(left + ' - ' + right);
	});
	
	$('table.post-revisions a.unlock').bind('click', function() {
														return confirm('Warning: Unlocking this post will cause the latest revision to be published!\nContinue?');
																});
	
	/*$('table.post-revisions .check-column :radio').bind('click',
			function() {
				var inputs = $('table.post-revisions .check-column :radio');
				var left = $('table.post-revisions .check-column :radio .left');
				
				var current_id = $(this).attr('value');
				
				var i, checkCount = 0, side, leftchecked = false, rightchecked = true;
				for ( i = 0; i < inputs.length; i++ ) {
					checked = $(inputs[i]).attr('checked');
					side = $(inputs[i]).attr('name');
					checkCount += checked ? 1 : 0;
					if ( checked ) {
						if ( 'left' == side )
							leftchecked = true;
						else if ( 'right' == side )
							rightchecked = true;
						$(inputs[i]).removeClass('red');
					} else if ( 'left' == side && !leftchecked & !checked )
						$(inputs[i]).addClass('red');
					elseif ( 'left' == side && leftchecked & !checked )
						$(inputs[i]).removeClass('red');
					else if ( 'right' == side && !leftchecked )
						$(inputs[i]).toggleClass('invisible');*/
					/*if ( ! $(inputs[i]).attr('checked') && 
					( 'left' == side && 1 > checkCount || 'right' == side && 1 < checkCount && ( ! inputs[i-1] || ! $(inputs[i-1]).attr('checked') ) ) &&
					! ( inputs[i+1] && $(inputs[i+1]).attr('checked') && 'right' == $(inputs[i+1]).attr('name') ) )
						$(inputs[i]).toggle();		
					else if ( 'left' == side || 'right' == side )
						$(inputs[i]).toggle();
				}

			});*/


});