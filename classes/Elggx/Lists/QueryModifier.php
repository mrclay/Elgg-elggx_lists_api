<?php

/**
 * Create a strategy for applying a list to a query
 *
 * By default the list is applied as a selector, meaning the query returns only items
 * in the list, and ordered with the latest added on top.
 *
 * @access private
 */
class Elggx_Lists_QueryModifier implements Elggx_QueryModifierInterface {

	const MODEL_STICKY = 'sticky';
	const MODEL_FILTER = 'filter';
	const MODEL_SELECTOR = 'selector';

	const DEFAULT_ORDER = 'e.time_created DESC';

	/**
	 * @var Elggx_Lists_List
	 */
	protected $list;

	/**
	 * @var string
	 */
	protected $join_column = 'e.guid';

	/**
	 * @var string
	 */
	protected $last_alias = '';

	/**
	 * @var int
	 */
	static protected $counter = 0;

	/**
	 * @var bool should the result set include list items?
	 */
	public $include_list = true;

	/**
	 * @var bool should the result set include non-list items?
	 */
	public $include_others = false;

	/**
	 * @var bool should the list be used such that recent additions are on top?
	 */
	public $is_reversed = true;

	/**
	 * @var bool should all the list items appear at the top?
	 */
	public $list_items_first = true;

	/**
	 * @var bool if true, getOptions() will add a SELECT column to track whether the entity
	 *           was in the list.
	 * @see getPresenceDetector()
	 */
	public $capture_list_presence = false;

	/**
	 * @param Elggx_Lists_List|null $list
	 *
	 * @todo decide if supporting a null list is actually useful
	 */
	public function __construct(Elggx_Lists_List $list = null) {
		$this->list = $list;
	}

	/**
	 * @return Elggx_Lists_List|null
	 */
	public function getList() {
		return $this->list;
	}

	/**
	 * Reset the list items table alias counter (call after each query to optimize
	 * use of the query cache)
	 */
	static public function resetCounter() {
		self::$counter = 0;
	}

	/**
	 * Get the next list items table alias
	 *
	 * @return string
	 */
	static public function getTableAlias() {
		self::$counter++;
		return "ci" . self::$counter;
	}

	/**
	 * @param string $model one of 'sticky', 'filter', 'selector'
	 *
	 * @return Elggx_Lists_QueryModifier
	 * @throws InvalidArgumentException
	 */
	public function setModel($model) {
		switch ($model) {
			case self::MODEL_FILTER:
				$this->include_others = true;
				$this->include_list = false;
				break;
			case self::MODEL_STICKY:
				$this->include_others = true;
				$this->include_list = true;
				$this->list_items_first = true;
				$this->is_reversed = true;
				break;
			case self::MODEL_SELECTOR:
				$this->include_others = false;
				$this->include_list = true;
				$this->is_reversed = true;
				break;
			default:
				throw new InvalidArgumentException("Invalid model: $model");
		}
		return $this;
	}

	/**
	 * @param array $options
	 *
	 * @return array
	 */
	public function getOptions(array $options = array()) {
		$table_alias = self::getTableAlias();
		$this->last_alias = $table_alias;

		if ($this->include_others) {
			if (!$this->list) {
				if ($this->capture_list_presence) {
					$options['selects'][] = "'' AS _in_list_$table_alias";
				}
				return $options;
			}
		} else {
			if (!$this->include_list || !$this->list) {
				// return none
				$options['wheres'][] = "(1 = 2)";
				return $options;
			}
		}
		$guid = $this->list->getEntityGuid();
		$key = $this->list->getRelationshipKey();

		if (empty($options['order_by'])) {
			$options['order_by'] = self::DEFAULT_ORDER;
		}

		$TABLE       = elgg_get_config('dbprefix') . Elggx_Lists_List::TABLE_UNPREFIXED;
		$ITEM        = Elggx_Lists_List::COL_ITEM;
		$ENTITY_GUID = Elggx_Lists_List::COL_ENTITY_GUID;
		$KEY         = Elggx_Lists_List::COL_KEY;
		$PRIORITY    = Elggx_Lists_List::COL_PRIORITY;

		$join = "JOIN $TABLE $table_alias "
			. "ON ({$this->join_column} = {$table_alias}.{$ITEM} "
			. "    AND {$table_alias}.{$ENTITY_GUID} = $guid "
			. "    AND {$table_alias}.{$KEY} = '$key') ";
		if ($this->include_others) {
			$join = "LEFT {$join}";
		}
		$options['joins'][] = $join;
		if ($this->include_list) {
			$order = "{$table_alias}.{$PRIORITY}";
			if ($this->list_items_first != $this->is_reversed) {
				$order = "- $order";
			}
			if ($this->list_items_first) {
				$order .= " DESC";
			}
			$options['order_by'] = "{$order}, {$options['order_by']}";
		} else {
			$options['wheres'][] = "({$table_alias}.{$ITEM} IS NULL)";
		}
		if ($this->capture_list_presence) {
			$options['selects'][] = "IF({$table_alias}.{$ITEM} IS NULL, '', '1') AS _in_list_$table_alias";
		}
		return $options;
	}

	/**
	 * Get a function that can determine if an item returned by the last query was in the
	 * list or not (boolean). If the function cannot determine list presence, it will return null.
	 *
	 * You must set capture_list_presence to true before calling getOptions()
	 *
	 * @see capture_list_presence
	 *
	 * @return Closure
	 * @throws RuntimeException
	 */
	public function getPresenceDetector() {
		if (!$this->last_alias) {
			throw new RuntimeException('A presence detector is not available until getOptions() has been '
				. 'called with the capture_list_presence property enabled.');
		}
		$last_alias = $this->last_alias;

		return function ($item) use ($last_alias) {
			if ($item instanceof ElggEntity) {
				return (bool)$item->getVolatileData("select:_in_list_$last_alias");
			}
			if (is_object($item) && isset($item->{"_in_list_$last_alias"})) {
				return (bool)$item->{"_in_list_$last_alias"};
			}
			if (is_array($item) && isset($item["_in_list_$last_alias"])) {
				return (bool)$item["_in_list_$last_alias"];
			}
			return null;
		};
	}
}
