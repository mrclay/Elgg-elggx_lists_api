<?php
/**
 * Elgg rearrange items in a list
 */

$list_guid_name = strip_tags(get_input('list', '', false));
list($entity_guid, $name) = explode(',', $list_guid_name, 2);
$items_before = get_input('guids_before', array(), false);
$items_after = get_input('guids_after', array(), false);

// sanity check input
if ($entity_guid < 1 || !is_string($name) || !$name || !is_array($items_before) || !is_array($items_after)) {
	register_error(elgg_echo("elggx_lists:rearrange:invalid_input"));
	forward(REFERER);
}
$items_before = array_map('intval', $items_before);
$items_after = array_map('intval', $items_after);


$entity = get_entity($entity_guid);
if (!$entity) {
	register_error(elgg_echo("elggx_lists:could_not_load_container_entity"));
	forward(REFERER);
}

$list = elggx_get_list($entity, $name);

$has_permission = $list->can('rearrange_items', array(
	'items_before' => $items_before,
	'items_after' => $items_after,
));

if (!$has_permission) {
	register_error(elgg_echo("elggx_lists:not_permitted"));
	forward(REFERER);
}

if ($list->rearrange($items_before, $items_after)) {
	system_message(elgg_echo("elggx_lists:rearrange:success"));
	forward(REFERER);
}

register_error(elgg_echo("elggx_lists:rearrange:failed"));
forward(REFERER);
