<?php

if (!function_exists('elgg_get_version')) {
	require_once __DIR__ . '/start-19-autoloader.php';
}

/**
 * Create an API for a named list on an entity.
 *
 * @param ElggEntity|int $entity
 * @param string         $name
 *
 * @return Elggx_Lists_List
 */
function elggx_get_list($entity, $name = '') {
	if ($entity instanceof ElggEntity) {
		$entity = $entity->guid;
	}
	return new Elggx_Lists_List($entity, $name);
}

/**
 * Get all lists containing an entity
 *
 * @param ElggEntity|int $entity
 * @param array $options Array in format:
 *
 * 	limit => null (50)|INT SQL limit clause (0 means no limit)
 *
 * 	offset => null (0)|INT SQL offset clause
 *
 * 	count => true|false return a count instead of entities
 *
 * @return Elggx_Lists_List[]|int
 */
function elggx_get_containing_lists($entity, array $options = array()) {
	if ($entity instanceof ElggEntity) {
		$entity = $entity->guid;
	}

	$entity = (int)$entity;

	$relationship_prefix = Elggx_Lists_List::RELATIONSHIP_PREFIX;
	$len_relationship_prefix = strlen($relationship_prefix);

	$relationship_prefix_escaped = "'" . sanitize_string($relationship_prefix) .  "'";

	$options = array_merge(array(
		'limit' => 50,
		'offset' => 0,
		'count' => false,
	), $options);

	if ($options['count']) {
		$select_values = "COUNT(*) AS cnt";
		$order_by_expression = "";
		$limit_expression = "";
	} else {
		$select_values = "SUBSTRING({KEY}, 1 + $len_relationship_prefix) AS coll_name, {ENTITY_GUID} AS coll_entity_guid";
		$order_by_expression = "ORDER BY {TIME} DESC, {KEY}";

		if ($options['limit']) {
			$limit = sanitise_int($options['limit'], false);
			$offset = sanitise_int($options['offset'], false);
			$limit_expression = "LIMIT $offset, $limit";
		}
	}

	$sql = "
		SELECT $select_values
		FROM {TABLE}
		WHERE {ITEM} = $entity
		  AND LEFT({KEY}, $len_relationship_prefix) = $relationship_prefix_escaped
		$order_by_expression
		$limit_expression
	";

	$sql = strtr($sql, array(
		'{TABLE}' => elgg_get_config('dbprefix') . Elggx_Lists_List::TABLE_UNPREFIXED,
		'{PRIORITY}' => Elggx_Lists_List::COL_PRIORITY,
		'{ITEM}' => Elggx_Lists_List::COL_ITEM,
		'{KEY}' => Elggx_Lists_List::COL_KEY,
		'{TIME}' => Elggx_Lists_List::COL_TIME,
		'{ENTITY_GUID}' => Elggx_Lists_List::COL_ENTITY_GUID,
	));

	if ($options['count']) {
		$row = get_data_row($sql);
		return $row ? $row->cnt : 0;
	} else {
		$colls = array();
		foreach ((array)get_data($sql) as $row) {
			$colls[] = new Elggx_Lists_List($row->coll_entity_guid, $row->coll_name);
		}
		return $colls;
	}
}

/**
 * Runs unit tests for lists and query modifiers
 *
 * @param string $hook   unit_test
 * @param string $type   system
 * @param mixed  $value  Array of tests
 * @param mixed  $params Params
 *
 * @return array
 * @access private
 */
function _elggx_lists_test($hook, $type, $value, $params) {
	$value[] = __DIR__ . '/tests/ElggxListsTest.php';
	return $value;
}

/**
 * Entities init function; establishes the default entity page handler
 *
 * @access private
 */
function _elggx_lists_init() {
	elgg_register_plugin_hook_handler('unit_test', 'system', '_elggx_lists_test');

	foreach (array('add_item', 'remove_item', 'rearrange_items') as $action) {
		elgg_register_action(
			"elggx_lists/$action",
			dirname(__FILE__) . "/actions/elggx_lists/$action.php"
		);
	}
}

elgg_register_event_handler('init', 'system', '_elggx_lists_init');
