<?php

if (function_exists('elgg_create_collection')) {
	// have native support
	return;
}

elgg_register_event_handler('init', 'system', '_elggx_collections_init');

function _elggx_collections_init() {
	$actions_dir = dirname(__FILE__) . "/actions/collections";
	$actions = array(
		'add_item',
		'delete_item',
		'rearrange_items',
	);
	foreach ($actions as $action) {
		elgg_register_action("collections/$action", "$actions_dir/$action.php");
	}
}

function _elggx_collections_loader($class) {
	$class = ltrim($class, '\\');
	if (0 !== strpos($class, 'Elggx_')) {
		return;
	}
	$file = dirname(__FILE__) . '/classes/' . strtr(ltrim($class, '\\'), '_\\', '//') . '.php';
	is_readable($file) && (require $file);
}

if (!class_exists('Elggx_Collection')) {
	// we're in 1.8, will need autoloader
	spl_autoload_register('_elggx_collections_loader');
}

/**
 * @return Elggx_CollectionsService
 */
function _elggx_collections_service() {
	static $inst;
	if (!$inst) {
		$inst = new Elggx_CollectionsService();
	}
	return $inst;
}

/**
 * Create (or fetch an existing) named collection on an entity. Good for creating a collection
 * on demand for editing.
 *
 * @param ElggEntity $entity
 * @param string $name
 * @return Elggx_Collection|null null if user is not permitted to create
 */
function elgg_create_collection(ElggEntity $entity, $name = '__default') {
	return _elggx_collections_service()->create($entity, $name);
}

/**
 * Get a reference to a collection if it exists, and the current user can see (or can edit it)
 *
 * @param ElggEntity $entity
 * @param string $name
 * @return Elggx_Collection|null
 */
function elgg_get_collection(ElggEntity $entity, $name = '__default') {
	return _elggx_collections_service()->fetch($entity, $name);
}

/**
 * Does this collection exist? This does not imply the current user can access it.
 *
 * @param ElggEntity|int $entity entity or GUID
 * @param string $name
 * @return bool
 */
function elgg_collection_exists($entity, $name = '__default') {
	return _elggx_collections_service()->exists($entity, $name);
}

/**
 * Get a query modifier object to apply a collection to an elgg_get_entities call.
 *
 * <code>
 * $qm = elgg_get_collection_query_modifier($user, 'blog_sticky');
 * $qm->setModel('sticky');
 *
 * elgg_list_entities($qm->getOptions(array(
 *     'type' => 'object',
 *     'subtype' => 'blog',
 *     'owner_guid' => $user->guid,
 * )));
 * </code>
 *
 * @param ElggEntity $entity entity
 * @param string $name
 * @return Elggx_Collection_QueryModifier
 */
function elgg_get_collection_query_modifier(ElggEntity $entity, $name = '__default') {
	$coll = _elggx_collections_service()->fetch($entity, $name);
	return new Elggx_Collection_QueryModifier($coll);
}
