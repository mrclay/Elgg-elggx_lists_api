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
	 * @var int
	 */
	static protected $counter = 0;

	/**
	 * @var bool should the result set include list items?
	 */
	public $includeList = true;

	/**
	 * @var bool should the result set include non-list items?
	 */
	public $includeOthers = false;

	/**
	 * @var bool should the list be used such that recent additions are on top?
	 */
	public $isReversed = true;

	/**
	 * @var bool should all the list items appear at the top?
	 */
	public $listItemsFirst = true;

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
	 * @return int
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
		if (!in_array($model, array('sticky', 'filter', 'selector'))) {
			throw new InvalidArgumentException("Invalid model: $model");
		}
		switch ($model) {
			case self::MODEL_FILTER:
				$this->includeOthers = true;
				$this->includeList = false;
				break;
			case self::MODEL_STICKY:
				$this->includeOthers = true;
				$this->includeList = true;
				$this->listItemsFirst = true;
				$this->isReversed = true;
				break;
			case self::MODEL_SELECTOR:
				$this->includeOthers = false;
				$this->includeList = true;
				$this->isReversed = true;
				break;
		}
		return $this;
	}

	/**
	 * @param array $options
	 *
	 * @return array
	 */
	public function getOptions(array $options = array()) {
		if ($this->includeOthers) {
			if (!$this->list) {
				return $options;
			}
		} else {
			if (!$this->includeList || !$this->list) {
				// return none
				$options['wheres'][] = "(1 = 2)";
				return $options;
			}
		}
		$tableAlias = self::getTableAlias();
		$guid = $this->list->getEntityGuid();
		$key = $this->list->getRelationshipKey();

		if (empty($options['order_by'])) {
			$options['order_by'] = self::DEFAULT_ORDER;
		}

		$table = elgg_get_config('dbprefix') . Elggx_Lists_List::TABLE_UNPREFIXED;
		$col_item = Elggx_Lists_List::COL_ITEM;
		$col_entity_guid = Elggx_Lists_List::COL_ENTITY_GUID;
		$col_key = Elggx_Lists_List::COL_KEY;
		$col_priority = Elggx_Lists_List::COL_PRIORITY;

		$join = "JOIN $table $tableAlias "
			. "ON ({$this->join_column} = {$tableAlias}.{$col_item} "
			. "    AND {$tableAlias}.{$col_entity_guid} = $guid "
			. "    AND {$tableAlias}.{$col_key} = '$key') ";
		if ($this->includeOthers) {
			$join = "LEFT {$join}";
		}
		$options['joins'][] = $join;
		if ($this->includeList) {
			$order = "{$tableAlias}.{$col_priority}";
			if ($this->listItemsFirst != $this->isReversed) {
				$order = "- $order";
			}
			if ($this->listItemsFirst) {
				$order .= " DESC";
			}
			$options['order_by'] = "{$order}, {$options['order_by']}";
		} else {
			$options['wheres'][] = "({$tableAlias}.{$col_item} IS NULL)";
		}
		return $options;
	}
}
