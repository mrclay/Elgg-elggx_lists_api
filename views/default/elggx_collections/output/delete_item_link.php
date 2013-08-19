<?php

$coll = $vars['collection'];
/* @var Elggx_Collections_Collection $coll */

$vars['href'] = "action/collections/delete_item?" . http_build_query(array(
	'coll_entity_guid' => $coll->getEntityGuid(),
	'coll_name' => $coll->getName(),
	'item_guid' => $vars['item_guid'],
));

if (empty($vars['text'])) {
	$vars['text'] = elgg_echo('elggx_collections:link:delete_item');
}

$vars['is_action'] = true;

echo elgg_view('output/confirmlink', $vars);
