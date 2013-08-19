<?php
/**
 * Elgg add item to collection
 */

$entity_guid = (int)get_input('coll_entity_guid', 0, false);
$name = strip_tags(get_input('coll_name', ''));
$item_guid = (int)get_input('item_guid', 0, false);

$entity = get_entity($entity_guid);
if (!$entity) {
	register_error(elgg_echo("elggx_collections:could_not_load_container_entity"));
	forward(REFERER);
}

if (!elgg_entity_exists($item_guid)) {
	register_error(elgg_echo("elggx_collections:add:item_nonexistant"));
	forward(REFERER);
}

$coll = elggx_get_collection($entity, $name);

if (!$coll->can('add_item', array('item_guid' => $item_guid))) {
	register_error(elgg_echo("elggx_collections:not_permitted"));
	forward(REFERER);
}

if ($coll->hasAnyOf($item_guid)) {
	system_message(elgg_echo("elggx_collections:add:already_in_collection"));
	forward(REFERER);
}

$coll->push($item_guid);

system_message(elgg_echo("elggx_collections:add:item_added"));
forward(REFERER);
