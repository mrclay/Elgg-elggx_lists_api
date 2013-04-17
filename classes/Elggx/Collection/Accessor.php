<?php

/**
 * Access or edit individual GUIDs in a collection.
 *
 * Use Elggx_Collection::getAccessor() to get one of these objects.
 *
 * @note entity_relationships does not have a priority column, so this implementation uses `id`
 *
 * @access private
 *
 * @package    Elgg.Core
 * @subpackage Collections
 */
class Elggx_Collection_Accessor {

	/**
	 * @var string
	 */
	protected $relationship_table;

	/**
	 * @var string
	 */
	protected $relationship_key;

	/**
	 * @var int
	 */
	protected $entity_guid;

	/**
	 * @var Elggx_Collection
	 */
	protected $coll;

	/**
	 * Constructor
	 *
	 * @param Elggx_Collection $collection
	 * @throws InvalidArgumentException
	 */
	public function __construct(Elggx_Collection $collection) {
		if ($collection->isDeleted()) {
			throw new InvalidArgumentException('Collection must not be already deleted');
		}
		$this->coll = $collection;
		$this->entity_guid = $collection->getEntityGuid();
		$this->relationship_key = $collection->getRelationshipKey();
		$this->relationship_table = elgg_get_config('dbprefix') . Elggx_Collection::TABLE_UNPREFIXED;
	}

	/**
	 * Add item(s) to the end of the collection. Already existing items are not added/moved.
	 *
	 * @param array|int|ElggEntity $new_items
	 * @return bool success
	 */
	public function push($new_items) {
		if (!$this->coll->canEdit()) {
			return false;
		}
		if (!$new_items) {
			return true;
		}
		$new_items = $this->castPositiveInt($this->castArray($new_items));

		// remove existing from new list
		$existing_items = $this->intersect($new_items);
		foreach ($existing_items as $i => $item) {
			$existing_items[$i] = $item->getValue();
		}
		$new_items = array_diff($new_items, $existing_items);

		foreach ($new_items as $i => $item) {
			$new_items[$i] = new Elggx_Collection_Item($item);
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
	 * @param int|ElggEntity $after_item
	 * @return bool success
	 */
	public function moveAfter($moving_item, $after_item) {
		if (!$this->coll->canEdit()) {
			return false;
		}
		$moving_item = $this->castPositiveInt($moving_item);
		$after_item = $this->castPositiveInt($after_item);
		if ($moving_item == $after_item) {
			return true;
		}

		$priorities = $this->getPriorities(array($moving_item, $after_item));
		if (count($priorities) < 2) {
			return false;
		}

		// get full list of rows that must change
		$where = "{PRIORITY} <= {$priorities[$after_item]} AND {PRIORITY} >= {$priorities[$moving_item]}";
		$items_moving = $this->fetchItems(true, $where);
		/* @var Elggx_Collection_Item[] $items_moving */
		if (!$items_moving) {
			// $item was probably below $before_item
			return false;
		}

		// Since ID is a key column in relationships, we can't have duplicate keys. The sane way to change IDs
		// is to delete the rows and reinsert them

		// build new list of rows to be inserted later
		$priorities = array_keys($items_moving);
		$items_moving = array_values($items_moving);

		// rearrange items, make priorities match old
		$tmp = array_shift($items_moving);
		array_push($items_moving, $tmp);
		foreach ($items_moving as $i => $item) {
			$item->setPriority($priorities[$i]);
		}

		// replace rows
		$this->remove($items_moving);
		return $this->insertItems($items_moving);
	}

	/**
	 * Move an item to just before another item
	 *
	 * @param int|ElggEntity $moving_item
	 * @param int|ElggEntity $before_item
	 * @return bool success
	 */
	public function moveBefore($moving_item, $before_item) {
		if (!$this->coll->canEdit()) {
			return false;
		}
		$moving_item = $this->castPositiveInt($moving_item);
		$before_item = $this->castPositiveInt($before_item);
		if ($moving_item == $before_item) {
			return true;
		}

		$priorities = $this->getPriorities(array($moving_item, $before_item));
		if (count($priorities) < 2) {
			return false;
		}

		// get full list of rows that must change
		$where = "{PRIORITY} >= {$priorities[$before_item]} AND {PRIORITY} <= {$priorities[$moving_item]}";
		$items_moving = $this->fetchItems(true, $where);
		/* @var Elggx_Collection_Item[] $items_moving */
		if (!$items_moving) {
			// $item was probably above $before_item
			return false;
		}

		// Since ID is a key column in relationships, we can't have duplicate keys. The sane way to change IDs
		// is to delete the rows and reinsert them

		// build new list of rows to be inserted later
		$priorities = array_keys($items_moving);
		$items_moving = array_values($items_moving);

		// rearrange items, make priorities match old
		$tmp = array_pop($items_moving);
		array_unshift($items_moving, $tmp);
		foreach ($items_moving as $i => $item) {
			$item->setPriority($priorities[$i]);
		}

		// replace rows
		$this->remove($items_moving);
		return $this->insertItems($items_moving);
	}

	/**
	 * Remove all items from the collection
	 *
	 * @return int|bool
	 */
	public function removeAll() {
		if (!$this->coll->canEdit()) {
			return false;
		}
		return delete_data($this->preprocessSql("
			DELETE FROM {TABLE}
			WHERE {IN_COLLECTION}
		"));
	}

	/**
	 * Remove item(s) from the collection
	 *
	 * @param array|int|ElggEntity|Elggx_Collection_Item $items
	 * @return int|bool
	 */
	public function remove($items) {
		if (!$this->coll->canEdit()) {
			return false;
		}
		if (!$items) {
			return true;
		}
		$items = $this->castPositiveInt($this->castArray($items));
		return delete_data($this->preprocessSql("
			DELETE FROM {TABLE}
			WHERE {IN_COLLECTION} AND {ITEM} IN (" . implode(',', $items) . ")
		"));
	}

	/**
	 * Remove item(s) from the beginning.
	 *
	 * @param int $num
	 * @return int|bool num rows removed
	 */
	public function removeFromBeginning($num = 1) {
		return $this->removeMultipleFrom($num, true);
	}

	/**
	 * Remove item(s) from the end.
	 *
	 * @param int $num
	 * @return int|bool num rows removed
	 */
	public function removeFromEnd($num = 1) {
		return $this->removeMultipleFrom($num, false);
	}

	/**
	 * Do any of the provided items appear in the collection?
	 *
	 * @param array|int|ElggEntity|Elggx_Collection_Item $items
	 * @return bool
	 */
	public function hasAnyOf($items) {
		return (bool) $this->intersect($items);
	}

	/**
	 * Do all of the provided items appear in the collection?
	 *
	 * @param array|int|ElggEntity|Elggx_Collection_Item $items
	 * @return bool
	 */
	public function hasAllOf($items) {
		if (!is_array($items)) {
			return $this->hasAnyOf($items);
		}
		return count($this->intersect($items)) === count($items);
	}

	/**
	 * Get the 0-indexed position of the item within the collection
	 *
	 * @param int|ElggEntity $item
	 * @return bool|int 0-indexed position of item in collection or false if not found
	 */
	public function indexOf($item) {
		$item = $this->castPositiveInt($item);
		$row = get_data_row($this->preprocessSql("
			SELECT COUNT(*) AS cnt
			FROM {TABLE}
			WHERE {IN_COLLECTION}
			  AND {PRIORITY} <=
				(SELECT {PRIORITY} FROM {TABLE}
				WHERE {IN_COLLECTION} AND {ITEM} = $item
				ORDER BY {PRIORITY}
				LIMIT 1)
			ORDER BY {PRIORITY}
		"));
		return ($row->cnt == 0) ? false : (int)$row->cnt - 1;
	}

	/**
	 * Get a sequence of GUIDs from the collection using the semantics of array_slice
	 *
	 * @param int $offset
	 * @param int|null $length
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
				return array_reverse($this->fetchValues(false, '', - $length));
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
						WHERE {IN_COLLECTION}
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
				return array_reverse($this->fetchValues(false, '', 0, - $offset));
			} elseif ($length > 0) {
				$sql_offset = -$offset;
				$rows = get_data($this->preprocessSql("
					SELECT {ITEM} FROM (
						SELECT {PRIORITY}, {ITEM} FROM {TABLE}
						WHERE {IN_COLLECTION}
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
						WHERE {IN_COLLECTION}
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
				$items[] = (int)$row->{Elggx_Collection::COL_ITEM};
			}
		}
		return $items;
	}

	/**
	 * Insert Elggx_Collection_Item objects into the collection
	 *
	 * @param Elggx_Collection_Item[] $items
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
	 * Return only items that also appear in the collection (and in the order they
	 * appear in the collection)
	 *
	 * @param array|int|ElggEntity $items
	 * @return Elggx_Collection_Item[]
	 */
	protected function intersect($items) {
		if (!$items) {
			return array();
		}
		$items = $this->castPositiveInt($this->castArray($items));
		return $this->fetchItems(true, '{ITEM} IN (' . implode(',', $items) . ')');
	}

	/**
	 * Get the id columns values of the given items
	 *
	 * @param int|ElggEntity|array $items one or more items
	 * @return int|bool|array for each item given, the ID will be returned, or false if the item is not found.
	 *                        If the given item was an array, an array will be returned with a key for each item
	 *
	 */
	protected function getPriorities($items) {
		$is_array = is_array($items);
		$items = $this->castPositiveInt($this->castArray($items));
		$rows = get_data($this->preprocessSql("
			SELECT {PRIORITY}, {ITEM} FROM {TABLE}
			WHERE {IN_COLLECTION} AND {ITEM} IN (" . implode(',', $items) . ")
		"));
		if (!$is_array) {
			return $rows ? $rows[0]->{Elggx_Collection::COL_PRIORITY} : false;
		}
		$ret = array();
		if ($rows) {
			foreach ($rows as $row) {
				$ret[$row->{Elggx_Collection::COL_ITEM}] = $row->{Elggx_Collection::COL_PRIORITY};
			}
		}
		return $ret;
	}

	/**
	 * Fetch Elggx_Collection_Item instances by query (or a count), with keys being the priorities
	 *
	 * @param bool $ascending
	 * @param string $where
	 * @param int $offset
	 * @param int|null $limit
	 * @param bool $count_only if true, return will be number of rows
	 * @return Elggx_Collection_Item[]|int|bool
	 */
	protected function fetchItems($ascending = true, $where = '', $offset = 0,
								  $limit = null, $count_only = false) {
		// Note1: This is the largest supported value for MySQL's LIMIT (2^64-1) which must be used
		// because MySQL doesn't support offset without limit: http://stackoverflow.com/a/271650/3779
		$mysql_no_limit = "18446744073709551615";

		$where_clause = "WHERE {IN_COLLECTION}";
		if (!empty($where)) {
			$where_clause .= " AND ($where)";
		}

		$asc_desc = $ascending ? '' : 'DESC';
		$order_by_clause = "ORDER BY {PRIORITY} $asc_desc";

		if ($offset == 0 && $limit === null) {
			$limit_clause = "";
		} elseif ($offset == 0) {
			$limit_clause = "LIMIT $limit";
		} else {
			// has offset
			if ($limit === null) {
				$limit_clause = "LIMIT $offset, $mysql_no_limit";
			} else {
				$limit_clause = "LIMIT $offset, $limit";
			}
		}

		$columns = '{PRIORITY}, {ITEM}, {TIME}';
		if ($count_only) {
			$columns = 'COUNT(*) AS cnt';
			$order_by_clause = '';
		}
		$rows = get_data($this->preprocessSql("
			SELECT $columns FROM {TABLE}
			$where_clause $order_by_clause $limit_clause
		"));
		if ($count_only) {
			return isset($rows[0]->cnt) ? (int)$rows[0]->cnt : false;
		}

		$items = array();
		if ($rows) {
			foreach ($rows as $row) {
				$items[$row->{Elggx_Collection::COL_PRIORITY}] = new Elggx_Collection_Item(
					$row->{Elggx_Collection::COL_ITEM},
					$row->{Elggx_Collection::COL_PRIORITY},
					$row->{Elggx_Collection::COL_TIME}
				);
			}
		}
		return $items;
	}

	/**
	 * Fetch array of item values by query (or a count)
	 *
	 * @param bool $ascending
	 * @param string $where
	 * @param int $offset
	 * @param int|null $limit
	 * @param bool $count_only if true, return will be number of rows
	 * @return array|int|bool keys will be 0-indexed
	 *
	 * @see fetchItems()
	 */
	protected function fetchValues($ascending = true, $where = '', $offset = 0,
								  $limit = null, $count_only = false) {
		$items = $this->fetchItems($ascending, $where, $offset, $limit, $count_only);
		if (is_array($items)) {
			/* @var Elggx_Collection_Item[] $items */
			$new_items = array();
			foreach ($items as $item) {
				$new_items[] = $item->getValue();
			}
			$items = $new_items;
		} elseif ($items instanceof Elggx_Collection_Item) {
			$items = $items->getValue();
		}
		return $items;
	}

	/**
	 * Remove several from the beginning/end
	 *
	 * @param int $num
	 * @param bool $from_beginning remove from the beginning of the collection?
	 * @return int|bool num rows removed
	 */
	protected function removeMultipleFrom($num, $from_beginning) {
		if (!$this->coll->canEdit()) {
			return false;
		}
		$num = (int)max($num, 0);
		$asc_desc = $from_beginning ? 'ASC' : 'DESC';
		return delete_data($this->preprocessSql("
			DELETE FROM {TABLE}
			WHERE {IN_COLLECTION}
			ORDER BY {PRIORITY} $asc_desc
			LIMIT $num
		"));
	}

	/**
	 * Cast a single value/entity to an int (or an array of values to an array of ints)
	 *
	 * @param mixed|array $i
	 * @return int|array
	 * @throws InvalidParameterException
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
					} elseif ($v instanceof Elggx_Collection_Item) {
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
	 * @return array
	 */
	protected function castArray($i) {
		return is_array($i) ? $i : array($i);
	}

	/**
	 * @param string $sql
	 * @return string
	 */
	protected function preprocessSql($sql) {
		return strtr($sql, array(
			'{TABLE}' => $this->relationship_table,
			'{PRIORITY}' => Elggx_Collection::COL_PRIORITY,
			'{ITEM}' => Elggx_Collection::COL_ITEM,
			'{KEY}' => Elggx_Collection::COL_KEY,
			'{TIME}' => Elggx_Collection::COL_TIME,
			'{ENTITY_GUID}' => Elggx_Collection::COL_ENTITY_GUID,
			'{IN_COLLECTION}' => "(" . Elggx_Collection::COL_ENTITY_GUID . " = $this->entity_guid "
				. "AND " . Elggx_Collection::COL_KEY . " = '$this->relationship_key')",
		));
	}

	/**
	 * Quote a string for MySQL
	 *
	 * @param string $val the string to quote e.g. "hello"
	 * @return string the quoted string e.g. "'hello'"
	 */
	protected function quote($val) {
		return "'" . sanitise_string($val) . "'";
	}

	/**
	 * Get an item by index (can be negative!)
	 *
	 * @param int $index
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
		return $this->db->deleteData($this->preprocessSql("
			DELETE FROM {TABLE}
			WHERE {IN_COLLECTION}
			  AND {PRIORITY} IN (" . implode(',', $priorities) . ")
		"));
	}*/
}