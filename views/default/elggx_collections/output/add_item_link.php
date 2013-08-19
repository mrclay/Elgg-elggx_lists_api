<?php

$coll = $vars['collection'];
/* @var Elggx_Collections_Collection $coll */

$vars['href'] = "action/collections/add_item?" . http_build_query(array(
	'coll_entity_guid' => $coll->getEntityGuid(),
	'coll_name' => $coll->getName(),
	'item_guid' => $vars['item_guid'],
));

if (empty($vars['text'])) {
	$vars['text'] = elgg_echo('collection:link:add_item');
}

$vars['is_action'] = true;

echo elgg_view('output/url', $vars);
