/**
 * @todo rewrite this for Elgg 1.8 and add generic view that supports viewing a collection add removing/reordering
 */

define('elggx_collections', function (require) {
	var elgg = require('elgg'),
		$ = require('jquery');

	$(function () {

		// handle re-ordering and saving collection items
		$('.xcollections-listing').each(function () {
			var $listing = $(this),
				coll = $listing.data('coll'),
				$reorder = $('.xcollections-reorder[data-coll="' + coll + '"]'),
				$save = $('.xcollections-reorder-save[data-coll="' + coll + '"]'),
				isActive = false,
				isAjaxWaiting = false,
				isSortableReady = false,
				lastOrdering,
				newOrdering;

			// find list of elements which have switched position (before and after are returned)
			function getChanged(arr1, arr2) {
				var i = 0, l = arr1.length, out1 = [], out2 = [];

				// after first change, start writing the output arrays
				for (; i < l; i++) {
					if (!out1.length) {
						// wait for first change
						if (arr1[i] == arr2[i]) {
							continue;
						}
					}
					out1.push(arr1[i].replace(/\D+/, ''));
					out2.push(arr2[i].replace(/\D+/, ''));
				}
				return [out1, out2];
			}

			// save re-ordered collection items (used below)
			function save(changed) {
				var data = {
					coll_entity_guid: coll.split(',')[0],
					coll_name: coll.split(',')[1],
					guids_before: changed[0],
					guids_after: changed[1]
				};

				isAjaxWaiting = true;

				elgg.action('collections/rearrange_items', {
					data: data,
					success: function () {
						$save.hide();
						isAjaxWaiting = false;
						isActive = false;
					}
				});
			}

			// manages the reordering process, displaying the save button, saving, etc.
			$reorder.click(function (e) {
				var changed, hasMasonry = $listing.data('hasMasonry'), sortableOpts;

				e.preventDefault();
				if (!isAjaxWaiting) {
					if (isActive) {
						newOrdering = $listing.sortable('toArray');
						$listing.sortable('disable');
						changed = getChanged(lastOrdering, newOrdering);
						$listing.removeClass('being-reordered');
						if (changed[0].length) {
							return save(changed);
						} else {
							$save.hide();
						}
					} else {
						// show, fix the width to the natural width (keeps container from resizing during drag)
						$save.css({ display: 'block'});
						$save.width('');
						$save.width($save.width());

						$listing.addClass('being-reordered');
						if (isSortableReady) {
							$listing.sortable('enable');
						} else {
							sortableOpts = {
								items: '> .xcollections-item',
								opacity: 0.5
							};
							if (hasMasonry) {
								// Masonry does not play well with Sortable. While interacting with sortable
								// elements, remove the classes so Masonry doesn't interact with them
								sortableOpts.start = function (e, ui) {
									ui.item[0].className = ui.item[0].className.replace(/\bmasonry-item\b/, 'tmp-masonry-item');
									$listing.masonry('reload');
								};
								sortableOpts.change = function () {
									$listing.masonry('reload');
								};
								sortableOpts.stop = function (e, ui) {
									ui.item[0].className = ui.item[0].className.replace(/\btmp-masonry-item\b/, 'masonry-item');
									$listing.masonry('reload');
								};
							}
							$listing.sortable(sortableOpts);
							isSortableReady = true;
						}
						lastOrdering = $listing.sortable('toArray');
					}
					isActive = !isActive;
				}
			});
		});
	});
});