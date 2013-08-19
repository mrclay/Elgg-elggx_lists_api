<?php
/**
 * Elgg remove item from collection
 */

$entity_guid = (int) get_input('coll_entity_guid', 0, false);
$name = strip_tags(get_input('coll_name', ''));
$item_guid = (int) get_input('item_guid', 0, false);

$entity = get_entity($entity_guid);
if (!$entity) {
	register_error(elgg_echo("collection:could_not_load_container_entity"));
	forward(REFERER);
}

$coll = elggx_get_collection($entity, $name);

if (!$coll->can('delete_item', array('item_guid' => $item_guid))) {
	register_error(elgg_echo("collection:not_permitted"));
	forward(REFERER);
}

if (!$coll->hasAnyOf($item_guid)) {
	system_message(elgg_echo("collection:del:not_in_collection"));
	forward(REFERER);
}

$coll->remove($item_guid);

system_message(elgg_echo("collection:del:item_removed"));
forward(REFERER);
