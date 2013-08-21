<?php

if (!function_exists('elgg_get_version')) {
	require_once __DIR__ . '/start-19-autoloader.php';
}

/**
 * Create an API for a named collection on an entity.
 *
 * @param ElggEntity|int $entity
 * @param string         $name
 *
 * @return Elggx_Collections_Collection
 */
function elggx_get_collection($entity, $name = '') {
	if ($entity instanceof ElggEntity) {
		$entity = $entity->guid;
	}
	return new Elggx_Collections_Collection($entity, $name);
}

/**
 * Get all collections containing an entity
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
 * @return Elggx_Collections_Collection[]|int
 */
function elggx_get_containing_collections($entity, array $options = array()) {
	if ($entity instanceof ElggEntity) {
		$entity = $entity->guid;
	}

	$entity = (int)$entity;

	$relationship_prefix = Elggx_Collections_Collection::RELATIONSHIP_PREFIX;
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
		'{TABLE}' => elgg_get_config('dbprefix') . Elggx_Collections_Collection::TABLE_UNPREFIXED,
		'{PRIORITY}' => Elggx_Collections_Collection::COL_PRIORITY,
		'{ITEM}' => Elggx_Collections_Collection::COL_ITEM,
		'{KEY}' => Elggx_Collections_Collection::COL_KEY,
		'{TIME}' => Elggx_Collections_Collection::COL_TIME,
		'{ENTITY_GUID}' => Elggx_Collections_Collection::COL_ENTITY_GUID,
	));

	if ($options['count']) {
		$row = get_data_row($sql);
		return $row ? $row->cnt : 0;
	} else {
		$colls = array();
		foreach ((array)get_data($sql) as $row) {
			$colls[] = new Elggx_Collections_Collection($row->coll_entity_guid, $row->coll_name);
		}
		return $colls;
	}
}

/**
 * Runs unit tests for collections and query modifiers
 *
 * @param string $hook   unit_test
 * @param string $type   system
 * @param mixed  $value  Array of tests
 * @param mixed  $params Params
 *
 * @return array
 * @access private
 */
function _elggx_collections_test($hook, $type, $value, $params) {
	$value[] = __DIR__ . '/tests/ElggxCollectionsTest.php';
	return $value;
}

/**
 * Entities init function; establishes the default entity page handler
 *
 * @access private
 */
function _elggx_collections_init() {
	elgg_register_plugin_hook_handler('unit_test', 'system', '_elggx_collections_test');

	foreach (array('add_item', 'remove_item', 'rearrange_items') as $action) {
		elgg_register_action(
			"elggx_collections/$action",
			dirname(__FILE__) . "/actions/elggx_collections/$action.php"
		);
	}
}

elgg_register_event_handler('init', 'system', '_elggx_collections_init');
