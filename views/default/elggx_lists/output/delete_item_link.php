<?php
/**
 * Draw a link that would remove an item from a list.
 *
 * @uses $vars['list'] : the Elggx_Lists_List object you want to affect
 * @uses $vars['item_guid'] : the GUID you want to remove
 *
 * The rest of the $vars are passed to the output/url view.
 */

$list = $vars['list'];
/* @var Elggx_Lists_List $list */

$vars['href'] = "action/elggx_lists/delete_item?" . http_build_query(array(
	'list' => "{$list->getEntityGuid()},{$list->getName()}",
	'item_guid' => $vars['item_guid'],
));

if (empty($vars['text'])) {
	$vars['text'] = elgg_echo('elggx_lists:link:delete_item');
}

$vars['is_action'] = true;

echo elgg_view('output/confirmlink', $vars);
