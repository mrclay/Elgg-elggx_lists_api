<?php

/**
 * A named and ordered list of entities handy for modifying elgg_get_entities() queries.
 *
 * @note Use elggx_get_list() to access lists
 *
 * @access private
 */
class Elggx_Lists_List {

	const TABLE_UNPREFIXED = 'entity_relationships';
	const COL_PRIORITY = 'id';
	const COL_ITEM = 'guid_one';
	const COL_ENTITY_GUID = 'guid_two';
	const COL_KEY = 'relationship';
	const COL_TIME = 'time_created';
	const RELATIONSHIP_PREFIX = 'list:';

	/**
	 * @var int
	 */
	protected $entity_guid;

	/**
	 * @var string
	 */
	protected $name;

	/**
	 * @var string
	 */
	protected $relationship_key;

	/**
	 * @var string
	 */
	protected $relationship_table;

	/**
	 * @param int    $entity_guid
	 * @param string $name
	 *
	 * @access private
	 * @throws InvalidArgumentException
	 */
	public function __construct($entity_guid, $name) {
		$this->entity_guid = (int)$entity_guid;
		$this->name = $name;
		$this->relationship_key = self::createRelationshipKey($name);
		$this->relationship_table = elgg_get_config('dbprefix') . self::TABLE_UNPREFIXED;
	}

	/**
	 * @param string $name
	 *
	 * @return string
	 * @throws InvalidArgumentException
	 *
	 * @access private
	 */
	public static function createRelationshipKey($name) {
		$key = self::RELATIONSHIP_PREFIX . $name;
		if (strlen($key) > 50) {
			$max_length = 50 - strlen(self::RELATIONSHIP_PREFIX);
			throw new InvalidArgumentException("List names cannot be longer than $max_length chars.");
		}
		return $key;
	}

	/**
	 * @return int
	 */
	public function getEntityGuid() {
		return $this->entity_guid;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * @return string
	 *
	 * @access private
	 */
	public function getRelationshipKey() {
		return $this->relationship_key;
	}

	/**
	 * Does the current user have permission to edit this list using the built-in actions?
	 *
	 * @param string $capability  E.g. "add_item", "delete_item", "rearrange_items"
	 * @param array  $hook_params Parameters passed to the permission hook
	 *
	 * @return bool
	 */
	public function can($capability, array $hook_params = array()) {
		$hook_params['list'] = $this;
		$hook_params['user'] = elgg_get_logged_in_user_entity();
		return (bool)elgg_trigger_plugin_hook('elggx_lists:can', $capability, $hook_params, false);
	}

	/**
	 * Get a query modifier object to apply this list to an elgg_get_entities call.
	 *
	 * <code>
	 * $qm = elggx_get_list($user, 'blog_sticky')->getQueryModifier('sticky');
	 *
	 * elgg_list_entities($qm->getOptions(array(
	 *     'type' => 'object',
	 *     'subtype' => 'blog',
	 *     'owner_guid' => $user->guid,
	 * )));
	 * </code>
	 *
	 * @param string $model
	 *
	 * @return Elggx_Lists_QueryModifier
	 */
	public function getQueryModifier($model = '') {
		$qm = new Elggx_Lists_QueryModifier($this);
		if ($model) {
			$qm->setModel($model);
		}
		return $qm;
	}

	/**
	 * Add item(s) to the end of the list. Already existing items are not added/moved.
	 *
	 * @param array|int|ElggEntity $new_items
	 *
	 * @return bool success
	 */
	public function push($new_items) {
		if (!$new_items) {
			return true;
		}
		$new_items = $this->castPositiveInt($this->castArray($new_items));
		/* @var int[] $new_items */

		// remove existing from new list
		$existing_items = $this->intersect($new_items);
		foreach ($existing_items as $i => $item) {
			$existing_items[$i] = $item->getValue();
		}
		$new_items = array_diff($new_items, $existing_items);

		foreach ($new_items as $i => $item) {
			$new_items[$i] = new Elggx_Lists_Item($item);
		}
		return $this->insertItems($new_items);
	}

	/**
	 * Get number of items
	 *
	 * @return int|bool
	 */
	public function count() {
		return $this->fetchItems(true, '', 0, null, true);
	}

	/**
	 * Rearrange a set of GUIDs. E.g. for "saving" after a user has performed a set of
	 * drag/drop operations.
	 *
	 * @param int[] $items_before
	 * @param int[] $items_after
	 *
	 * @return bool
	 */
	public function rearrange(array $items_before, array $items_after) {
		// make sure args have same items and each unique and not empty
		$items_after = array_values($items_after);
		$items_before = array_values($items_before);
		$copy1 = $items_before;
		$copy2 = $items_after;
		sort($copy1);
		sort($copy2);
		if (!$copy1 || ($copy1 != $copy2) || ($copy1 != array_unique($copy1))) {
			return false;
		}
		// find which ones moving, map old/new positions
		$positions_by_value = array();
		foreach ($items_before as $i => $item) {
			if ($item != $items_after[$i]) {
				$positions_by_value['old'][$item] = $i;
				$positions_by_value['new'][$items_after[$i]] = $i;
			}
		}

		// fetch and change position of moving items
		$set = '(' . implode(',', array_keys($positions_by_value['old'])) . ')';
		$items = $this->fetchItems(true, "{ITEM} IN $set");
		/* @var Elggx_Lists_Item[] $items */

		// make map from position to priority
		$priority_by_position = array();
		foreach ($items as $item) {
			$priority_by_position[$positions_by_value['old'][$item->getValue()]] = $item->getPriority();
		}
		foreach ($items as $item) {
			// translate new position into new priority
			$new_position = $positions_by_value['new'][$item->getValue()];
			$new_priority = $priority_by_position[$new_position];
			$item->setPriority($new_priority);
		}

		// replace items
		$this->remove($items);
		$this->insertItems($items);
		return true;
	}

	/**
	 * Move an item to just after another item
	 *
	 * @param int|ElggEntity $moving_item
	 * @param int|ElggEntity $reference_item
	 *
	 * @return bool success
	 */
	public function moveAfter($moving_item, $reference_item) {
		return $this->move($moving_item, $reference_item, 'after');
	}

	/**
	 * Move an item to just before another item
	 *
	 * @param int|ElggEntity $moving_item
	 * @param int|ElggEntity $reference_item
	 *
	 * @return bool success
	 */
	public function moveBefore($moving_item, $reference_item) {
		return $this->move($moving_item, $reference_item, 'before');
	}

	/**
	 * Move an item to just before or after another item
	 *
	 * @param int|ElggEntity $moving_item
	 * @param int|ElggEntity $reference_item
	 * @param string         $position       Where to move to ("before" or "after" the reference)
	 *
	 * @return bool success
	 * @throws InvalidArgumentException
	 */
	protected function move($moving_item, $reference_item, $position) {
		if ($position !== 'after' && $position !== 'before') {
			throw new InvalidArgumentException('$position must be "before" or "after"');
		}

		$moving_item = $this->castPositiveInt($moving_item);
		$reference_item = $this->castPositiveInt($reference_item);
		if ($moving_item == $reference_item) {
			return true;
		}

		$priorities = $this->getPriorities(array($moving_item, $reference_item));
		if (count($priorities) < 2) {
			return false;
		}

		// get full list of rows that must change
		if ($position === 'before') {
			$where = "{PRIORITY} >= {$priorities[$reference_item]} AND {PRIORITY} <= {$priorities[$moving_item]}";
		} else {
			$where = "{PRIORITY} <= {$priorities[$reference_item]} AND {PRIORITY} >= {$priorities[$moving_item]}";
		}
		$items_moving = $this->fetchItems(true, $where);
		/* @var Elggx_Lists_Item[] $items_moving */
		if (!$items_moving) {
			return false;
		}

		// Since ID is a key column in relationships, we can't have duplicate keys. The sane way to change IDs
		// is to delete the rows and reinsert them

		// build new list of rows to be inserted later
		$priorities = array_keys($items_moving);
		$items_moving = array_values($items_moving);
		/* @var Elggx_Lists_Item[] $items_moving */

		// rearrange items, make priorities match old
		if ($position === 'before') {
			$tmp = array_pop($items_moving);
			array_unshift($items_moving, $tmp);
		} else {
			$tmp = array_shift($items_moving);
			array_push($items_moving, $tmp);
		}

		foreach ($items_moving as $i => $item) {
			$item->setPriority($priorities[$i]);
		}

		// replace rows
		$this->remove($items_moving);
		return $this->insertItems($items_moving);
	}

	/**
	 * Remove all items from the list
	 *
	 * @return int|bool
	 */
	public function removeAll() {
		return delete_data($this->preprocessSql("
			DELETE FROM {TABLE}
			WHERE {IN_LIST}
		"));
	}

	/**
	 * Remove item(s) from the list
	 *
	 * @param array|int|ElggEntity|Elggx_Lists_Item $items
	 *
	 * @return int|bool
	 */
	public function remove($items) {
		if (!$items) {
			return true;
		}
		$items = $this->castPositiveInt($this->castArray($items));
		/* @var int[] $items */
		return delete_data($this->preprocessSql("
			DELETE FROM {TABLE}
			WHERE {IN_LIST} AND {ITEM} IN (" . implode(',', $items) . ")
		"));
	}

	/**
	 * Remove item(s) from the beginning.
	 *
	 * @param int $num
	 *
	 * @return int|bool num rows removed
	 */
	public function removeFromBeginning($num = 1) {
		return $this->removeMultipleFrom($num, true);
	}

	/**
	 * Remove item(s) from the end.
	 *
	 * @param int $num
	 *
	 * @return int|bool num rows removed
	 */
	public function removeFromEnd($num = 1) {
		return $this->removeMultipleFrom($num, false);
	}

	/**
	 * Do any of the provided items appear in the list?
	 *
	 * @param array|int|ElggEntity|Elggx_Lists_Item $items
	 *
	 * @return bool
	 */
	public function hasAnyOf($items) {
		return $this->intersect($items, true) > 0;
	}

	/**
	 * Do all of the provided items appear in the list?
	 *
	 * @param array|int|ElggEntity|Elggx_Lists_Item $items
	 *
	 * @return bool
	 */
	public function hasAllOf($items) {
		if (!is_array($items)) {
			return $this->hasAnyOf($items);
		}
		return $this->intersect($items, true) === count($items);
	}

	/**
	 * Get the 0-indexed position of the item within the list
	 *
	 * @param int|ElggEntity $item
	 *
	 * @return bool|int 0-indexed position of item in list or false if not found
	 */
	public function indexOf($item) {
		$item = $this->castPositiveInt($item);
		$row = get_data_row($this->preprocessSql("
			SELECT COUNT(*) AS cnt
			FROM {TABLE}
			WHERE {IN_LIST}
			  AND {PRIORITY} <=
				(SELECT {PRIORITY} FROM {TABLE}
				WHERE {IN_LIST} AND {ITEM} = $item
				ORDER BY {PRIORITY}
				LIMIT 1)
			ORDER BY {PRIORITY}
		"));
		return ($row->cnt == 0) ? false : (int)$row->cnt - 1;
	}

	/**
	 * Get a sequence of GUIDs from the list using the semantics of array_slice
	 *
	 * @param int      $offset
	 * @param int|null $length
	 *
	 * @return array
	 */
	public function slice($offset = 0, $length = null) {
		// Note1: This is the largest supported value for MySQL's LIMIT (2^64-1) which must be used
		// because MySQL doesn't support offset without limit: http://stackoverflow.com/a/271650/3779
		$mysql_no_limit = "18446744073709551615";

		if ($length !== null) {
			if ($length == 0) {
				return array();
			}
			$length = (int)$length;
		}
		$offset = (int)$offset;
		if ($offset == 0) {
			if ($length === null) {
				return $this->fetchValues();
			} elseif ($length > 0) {
				return $this->fetchValues(true, '', 0, $length);
			} else {
				// length < 0
				return array_reverse($this->fetchValues(false, '', -$length));
			}
		} elseif ($offset > 0) {
			if ($length === null) {
				return $this->fetchValues(true, '', $offset);
			} elseif ($length > 0) {
				return $this->fetchValues(true, '', $offset, $length);
			} else {
				// length < 0
				$sql_length = -$length;
				$rows = get_data($this->preprocessSql("
					SELECT {ITEM} FROM (
						SELECT {PRIORITY}, {ITEM} FROM {TABLE}
						WHERE {IN_LIST}
						ORDER BY {PRIORITY} DESC
						LIMIT $sql_length, $mysql_no_limit
					) AS q1
					ORDER BY {PRIORITY}
					LIMIT $offset, $mysql_no_limit
				"));
			}
		} else {
			// offset < 0
			if ($length === null) {
				return array_reverse($this->fetchValues(false, '', 0, -$offset));
			} elseif ($length > 0) {
				$sql_offset = -$offset;
				$rows = get_data($this->preprocessSql("
					SELECT {ITEM} FROM (
						SELECT {PRIORITY}, {ITEM} FROM {TABLE}
						WHERE {IN_LIST}
						ORDER BY {PRIORITY} DESC
						LIMIT $sql_offset
					) AS q1
					ORDER BY {PRIORITY}
					LIMIT $length
				"));
			} else {
				// length < 0
				$sql_offset = -$offset;
				$sql_length = -$length;
				$rows = get_data($this->preprocessSql("
					SELECT {ITEM} FROM (
						SELECT {PRIORITY}, {ITEM} FROM {TABLE}
						WHERE {IN_LIST}
						ORDER BY {PRIORITY} DESC
						LIMIT $sql_offset
					) AS q1
					ORDER BY {PRIORITY} DESC
					LIMIT $sql_length, $mysql_no_limit
				"));
				if ($rows) {
					$rows = array_reverse($rows);
				}
			}
		}
		$items = array();
		if ($rows) {
			foreach ($rows as $row) {
				$items[] = (int)$row->{self::COL_ITEM};
			}
		}
		return $items;
	}

	/**
	 * Insert Elggx_Lists_Item objects into the list
	 *
	 * @param Elggx_Lists_Item[] $items
	 *
	 * @return bool
	 */
	protected function insertItems(array $items) {
		if (!$items) {
			return true;
		}
		$rows = array();
		$entity_guid = $this->quote($this->entity_guid);
		$key = $this->quote($this->relationship_key);

		foreach ($items as $item) {
			$value = $this->quote($item->getValue());
			$time = $this->quote($item->getTime());
			$priority = $item->getPriority();
			$priority = $priority ? $this->quote($priority) : 'null';
			$rows[] = "($priority, $value, $key, $entity_guid, $time)";
		}
		insert_data($this->preprocessSql("
			INSERT INTO {TABLE}
			({PRIORITY}, {ITEM}, {KEY}, {ENTITY_GUID}, {TIME})
			VALUES " . implode(', ', $rows) . "
		"));
		return true;
	}

	/**
	 * @param string $val
	 *
	 * @return string
	 */
	protected function quote($val) {
		return "'" . sanitize_string($val) . "'";
	}

	/**
	 * Return only items that also appear in the list (and in the order they
	 * appear in the list)
	 *
	 * @param array|int|ElggEntity $items
	 * @param bool                 $return_count
	 *
	 * @return Elggx_Lists_Item[]
	 *
	 * @access private
	 */
	protected function intersect($items, $return_count = false) {
		if (!$items) {
			return $return_count ? 0 : array();
		}
		$items = $this->castPositiveInt($this->castArray($items));
		/* @var int[] $items */
		return $this->fetchItems(true, '{ITEM} IN (' . implode(',', $items) . ')', 0, null, $return_count);
	}

	/**
	 * Get the id columns values of the given items
	 *
	 * @param int|ElggEntity|array $items one or more items
	 *
	 * @return int|bool|array for each item given, the ID will be returned, or false if the item is not found.
	 *                        If the given item was an array, an array will be returned with a key for each item
	 *
	 * @access                private
	 */
	protected function getPriorities($items) {
		$is_array = is_array($items);
		$items = $this->castPositiveInt($this->castArray($items));
		/* @var int[] $items */
		$rows = get_data($this->preprocessSql("
			SELECT {PRIORITY}, {ITEM} FROM {TABLE}
			WHERE {IN_LIST} AND {ITEM} IN (" . implode(',', $items) . ")
		"));
		if (!$is_array) {
			return $rows ? $rows[0]->{self::COL_PRIORITY} : false;
		}
		$ret = array();
		if ($rows) {
			foreach ($rows as $row) {
				$ret[$row->{self::COL_ITEM}] = $row->{self::COL_PRIORITY};
			}
		}
		return $ret;
	}

	/**
	 * Fetch Elggx_Lists_Item instances by query (or a count), with keys being the priorities
	 *
	 * @param bool     $ascending
	 * @param string   $where
	 * @param int      $offset
	 * @param int|null $limit
	 * @param bool     $return_count if true, return will be number of rows
	 *
	 * @return Elggx_Lists_Item[]|int
	 *
	 * @access private
	 */
	protected function fetchItems($ascending = true,
								  $where = '',
								  $offset = 0,
								  $limit = null,
								  $return_count = false) {
		$where_clause = "WHERE {IN_LIST}";
		if (!empty($where)) {
			$where_clause .= " AND ($where)";
		}

		$limit_clause = $this->getLimitClauseForFetch($limit, $offset);

		if ($return_count) {
			$columns = 'COUNT(*) AS cnt';
			$rows = get_data($this->preprocessSql("
				SELECT $columns FROM {TABLE}
				$where_clause $limit_clause
			"));
			return isset($rows[0]->cnt) ? (int)$rows[0]->cnt : 0;
		}

		$order_by_clause = "ORDER BY {PRIORITY}" . ($ascending ? '' : ' DESC');
		$columns = '{PRIORITY}, {ITEM}, {TIME}';
		$rows = get_data($this->preprocessSql("
			SELECT $columns FROM {TABLE}
			$where_clause $order_by_clause $limit_clause
		"));
		$items = array();
		foreach ($rows as $row) {
			$items[$row->{self::COL_PRIORITY}] = new Elggx_Lists_Item(
				$row->{self::COL_ITEM},
				$row->{self::COL_PRIORITY},
				$row->{self::COL_TIME}
			);
		}
		return $items;
	}

	/**
	 * @param int|null $limit
	 * @param int      $offset
	 *
	 * @return string
	 */
	protected function getLimitClauseForFetch($limit, $offset) {
		if ($offset == 0 && $limit === null) {
			return "";
		}
		if ($offset == 0) {
			return "LIMIT $limit";
		}
		// has offset
		if ($limit === null) {
			// Note1: This is the largest supported value for MySQL's LIMIT (2^64-1) which must be used
			// because MySQL doesn't support offset without limit: http://stackoverflow.com/a/271650/3779
			$mysql_no_limit = "18446744073709551615";

			return "LIMIT $offset, $mysql_no_limit";
		}
		return "LIMIT $offset, $limit";
	}

	/**
	 * Fetch array of item values by query (or a count)
	 *
	 * @param bool     $ascending
	 * @param string   $where
	 * @param int      $offset
	 * @param int|null $limit
	 * @param bool     $count_only if true, return will be number of rows
	 *
	 * @return array|int|bool keys will be 0-indexed
	 *
	 * @see    fetchItems()
	 *
	 * @access private
	 */
	protected function fetchValues($ascending = true,
								   $where = '',
								   $offset = 0,
								   $limit = null,
								   $count_only = false) {
		$items = $this->fetchItems($ascending, $where, $offset, $limit, $count_only);
		if (is_array($items)) {
			$new_items = array();
			/* @var Elggx_Lists_Item[] $items */
			foreach ($items as $item) {
				$new_items[] = $item->getValue();
			}
			$items = $new_items;
		} elseif ($items instanceof Elggx_Lists_Item) {
			$items = $items->getValue();
		}
		return $items;
	}

	/**
	 * Remove several from the beginning/end
	 *
	 * @param int  $num
	 * @param bool $from_beginning remove from the beginning of the list?
	 *
	 * @return int|bool num rows removed
	 *
	 * @access private
	 */
	protected function removeMultipleFrom($num, $from_beginning) {
		$num = (int)max($num, 0);
		$asc_desc = $from_beginning ? 'ASC' : 'DESC';
		return delete_data($this->preprocessSql("
			DELETE FROM {TABLE}
			WHERE {IN_LIST}
			ORDER BY {PRIORITY} $asc_desc
			LIMIT $num
		"));
	}

	/**
	 * Cast a single value/entity to an int (or an array of values to an array of ints)
	 *
	 * @param mixed|array $i
	 *
	 * @return int|array
	 * @throws InvalidParameterException
	 *
	 * @access private
	 */
	protected function castPositiveInt($i) {
		$is_array = is_array($i);
		if (!$is_array) {
			$i = array($i);
		}
		foreach ($i as $k => $v) {
			if (!is_int($v) || $v <= 0) {
				if (!is_numeric($v)) {
					if ($v instanceof ElggEntity) {
						$v = $v->getGUID();
					} elseif ($v instanceof Elggx_Lists_Item) {
						$v = $v->getValue();
					}
				}
				$v = (int)$v;
				if ($v < 1) {
					throw new InvalidParameterException(elgg_echo('InvalidParameterException:UnrecognisedValue'));
				}
				$i[$k] = $v;
			}
		}
		return $is_array ? $i : $i[0];
	}

	/**
	 * Cast to array without fear of breaking objects
	 *
	 * @param mixed
	 *
	 * @return array
	 *
	 * @access private
	 */
	protected function castArray($i) {
		return is_array($i) ? $i : array($i);
	}

	/**
	 * @param string $sql
	 *
	 * @return string
	 *
	 * @access private
	 */
	protected function preprocessSql($sql) {
		return strtr($sql, array(
			'{TABLE}' => $this->relationship_table,
			'{PRIORITY}' => self::COL_PRIORITY,
			'{ITEM}' => self::COL_ITEM,
			'{KEY}' => self::COL_KEY,
			'{TIME}' => self::COL_TIME,
			'{ENTITY_GUID}' => self::COL_ENTITY_GUID,
			'{IN_LIST}' => "(" . self::COL_ENTITY_GUID . " = $this->entity_guid "
			. "AND " . self::COL_KEY . " = '$this->relationship_key')",
		));
	}

	/**
	 * Get an item by index (can be negative!)
	 *
	 * @param int $index
	 *
	 * @return int|null
	 *
	 * @access private
	 */
	/*public function get($index) {
		$item = $this->fetchItems(true, '', $index, 1);
		return $item ? array_pop($item) : null;
	}*/

	/**
	 * Remove items by priority
	 *
	 * @param array $priorities
	 *
	 * @return int|bool
	 *
	 * @access private
	 */
	/*public function removeByPriority($priorities) {
		if (!$this->coll->canEdit()) {
			return false;
		}
		if (!$priorities) {
			return true;
		}
		$priorities = $this->castPositiveInt((array)$priorities);
		return delete_data($this->preprocessSql("
			DELETE FROM {TABLE}
			WHERE {IN_LIST}
			  AND {PRIORITY} IN (" . implode(',', $priorities) . ")
		"));
	}*/
}
