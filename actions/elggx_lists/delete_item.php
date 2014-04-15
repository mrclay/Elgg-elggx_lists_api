<?php
/**
 * Elgg remove item from list
 */

$list_guid_name = strip_tags(get_input('list', '', false));
list($entity_guid, $name) = explode(',', $list_guid_name, 2);
$item_guid = (int) get_input('item_guid', 0, false);

$entity = get_entity($entity_guid);
if (!$entity) {
	register_error(elgg_echo("elggx_lists:could_not_load_container_entity"));
	forward(REFERER);
}

$list = elggx_get_list($entity, $name);

if (!$list->can('delete_item', array('item_guid' => $item_guid))) {
	register_error(elgg_echo("elggx_lists:not_permitted"));
	forward(REFERER);
}

if (!$list->hasAnyOf($item_guid)) {
	system_message(elgg_echo("elggx_lists:del:not_in_list"));
	forward(REFERER);
}

$list->remove($item_guid);

system_message(elgg_echo("elggx_lists:del:item_removed"));
forward(REFERER);
