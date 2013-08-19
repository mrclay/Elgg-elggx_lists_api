<?php

/**
 * Create a strategy for applying a collection to a query
 *
 * By default the collection is applied as a selector, meaning the query returns only items
 * in the collection, and ordered chronologically w/r/t when the items were added to the collection.
 *
 * @access private
 */
class Elggx_Collections_QueryModifier implements Elggx_QueryModifierInterface {

	const MODEL_STICKY = 'sticky';
	const MODEL_FILTER = 'filter';
	const MODEL_SELECTOR = 'selector';

	const DEFAULT_ORDER = 'e.time_created DESC';

	/**
	 * @var Elggx_Collections_Collection
	 */
	protected $collection;

	/**
	 * @var string
	 */
	protected $join_column = 'e.guid';

	/**
	 * @var int
	 */
	static protected $counter = 0;

	/**
	 * @var bool should the result set include collection items?
	 */
	public $includeCollection = true;

	/**
	 * @var bool should the result set include non-collection items?
	 */
	public $includeOthers = false;

	/**
	 * @var bool should the collection be used such that recent additions are on top?
	 */
	public $isReversed = true;

	/**
	 * @var bool should all the collection items appear at the top?
	 */
	public $collectionItemsFirst = true;

	/**
	 * @param Elggx_Collections_Collection|null $collection
	 *
	 * @todo decide if supporting a null collection is actually useful
	 */
	public function __construct(Elggx_Collections_Collection $collection = null) {
        $this->collection = $collection;
    }

    /**
     * @return Elggx_Collections_Collection|null
     */
    public function getCollection() {
        return $this->collection;
    }

    /**
     * Reset the collection_items table alias counter (call after each query to optimize
     * use of the query cache)
     */
    static public function resetCounter() {
        self::$counter = 0;
    }

    /**
     * Get the next collection_items table alias
     * @return int
     */
    static public function getTableAlias() {
        self::$counter++;
        return "ci" . self::$counter;
    }

	/**
	 * @param string $model one of 'sticky', 'filter', 'selector'
	 * @return Elggx_Collections_QueryModifier
	 * @throws InvalidArgumentException
	 */
	public function setModel($model) {
		if (!in_array($model, array('sticky', 'filter', 'selector'))) {
			throw new InvalidArgumentException("Invalid model: $model");
		}
		switch ($model) {
			case self::MODEL_FILTER:
				$this->includeOthers = true;
				$this->includeCollection = false;
				break;
			case self::MODEL_STICKY:
				$this->includeOthers = true;
				$this->includeCollection = true;
				$this->collectionItemsFirst = true;
				$this->isReversed = true;
				break;
			case self::MODEL_SELECTOR:
				$this->includeOthers = false;
				$this->includeCollection = true;
				$this->isReversed = true;
				break;
		}
		return $this;
	}

	/**
	 * @param array $options
	 * @return array
	 */
	public function getOptions(array $options = array()) {
        if ($this->includeOthers) {
			if (!$this->collection) {
				return $options;
			}
		} else {
			// @todo decide if supporting a null collection is actually useful
			if (!$this->includeCollection || !$this->collection) {
				// return none
				$options['wheres'][] = "(1 = 2)";
				return $options;
			}
		}
		$tableAlias = self::getTableAlias();
		$guid = $this->collection->getEntityGuid();
		$key = $this->collection->getRelationshipKey();

		if (empty($options['order_by'])) {
            $options['order_by'] = self::DEFAULT_ORDER;
        }

		$table           = elgg_get_config('dbprefix') . Elggx_Collections_Collection::TABLE_UNPREFIXED;
		$col_item        = Elggx_Collections_Collection::COL_ITEM;
		$col_entity_guid = Elggx_Collections_Collection::COL_ENTITY_GUID;
		$col_key         = Elggx_Collections_Collection::COL_KEY;
		$col_priority    = Elggx_Collections_Collection::COL_PRIORITY;

        $join = "JOIN $table $tableAlias "
              . "ON ({$this->join_column} = {$tableAlias}.{$col_item} "
			  . "    AND {$tableAlias}.{$col_entity_guid} = $guid "
			  . "    AND {$tableAlias}.{$col_key} = '$key') ";
        if ($this->includeOthers) {
            $join = "LEFT {$join}";
        }
        $options['joins'][] = $join;
        if ($this->includeCollection) {
            $order = "{$tableAlias}.{$col_priority}";
            if ($this->collectionItemsFirst != $this->isReversed) {
                $order = "- $order";
            }
            if ($this->collectionItemsFirst) {
                $order .= " DESC";
            }
            $options['order_by'] = "{$order}, {$options['order_by']}";
        } else {
            $options['wheres'][] = "({$tableAlias}.{$col_item} IS NULL)";
        }
        return $options;
    }
}