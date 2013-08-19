<?php
/**
 * Elgg Test collections API
 *
 * @todo separate these tests out more
 *
 * @package Elgg
 * @subpackage Test
 */
class ElggxCollectionsTest extends ElggCoreUnitTest {

	/**
	 * Called before each test method.
	 */
	public function setUp() {

	}

	/**
	 * Called after each test method.
	 */
	public function tearDown() {

	}

	/**
	 * @todo split this up to test capabilities
	 */
	public function testGetCollectionHandlesEntitiesAndInts() {
		$site = elgg_get_site_entity();

		$coll = elggx_get_collection($site);
		$this->assertIsA($coll, 'Elggx_Collections_Collection');
		$this->assertEqual($coll->getEntityGuid(), $site->guid);

		$coll = elggx_get_collection($site->guid);
		$this->assertIsA($coll, 'Elggx_Collections_Collection');
		$this->assertEqual($coll->getEntityGuid(), $site->guid);
	}

	public function testEntityDeleteEmptiesCollections() {
		$obj = new ElggObject();
		$obj->save();

		$name = 'testDeleteEmpties';

		$coll = elggx_get_collection($obj, $name);
		$coll->push(elgg_get_site_entity());

		$this->assertEqual($coll->count(), 1);

		$obj->delete();

		$this->assertEqual($coll->count(), 0);
	}

	public function testReverseLookups() {
		$false_entity = 3;
		$searched_entity = 2;

		$test_colls = array();
		foreach (range(0, 9) as $i) {
			$coll = elggx_get_collection($i, "testReverseLookups{$i}");
			$coll->push($false_entity);
			if ($i % 2 == 0) {
				$coll->push($searched_entity);
			}
			$test_colls[] = $coll;
		}
		/* @var Elggx_Collections_Collection[] $test_colls */

		$count = elggx_get_containing_collections($searched_entity, array('count' => true));
		$this->assertEqual($count, 5);


		$expected = array();
		foreach (range(0, 9, 2) as $i) {
			$expected["{$i}:testReverseLookups{$i}"] = true;
		}
		$found_colls = elggx_get_containing_collections($searched_entity);
		foreach ($found_colls as $coll) {
			$key = $coll->getEntityGuid() . ":" . $coll->getName();
			unset($expected[$key]);
		}
		$this->assertEqual(count($expected), 0);

		$colls = elggx_get_containing_collections($searched_entity, array('limit' => 3, 'offset' => 0));
		$this->assertEqual(count($colls), 3);
		$colls = elggx_get_containing_collections($searched_entity, array('limit' => 3, 'offset' => 3));
		$this->assertEqual(count($colls), 2);
		$colls = elggx_get_containing_collections($searched_entity, array('limit' => 3, 'offset' => 6));
		$this->assertEqual(count($colls), 0);

		foreach ($test_colls as $coll) {
			$coll->removeAll();
		}
	}

	/**
	 * @todo split this up to test capabilities
	 */
	public function testItemAccess() {
		$user = elgg_get_logged_in_user_entity();
		$name = 'test_collection';
		$coll = elggx_get_collection($user, $name);

		$this->assertEqual($coll->count(), 0);

		$coll->push(1);
		$this->assertEqual($coll->count(), 1);
		$this->assertTrue($coll->hasAnyOf(1));

		$coll->push(array(2, 3, 1, $user));
		$this->assertEqual($coll->count(), 4);
		$this->assertTrue($coll->hasAnyOf($user));
		$this->assertTrue($coll->hasAnyOf($user->guid));

		$this->assertEqual($coll->indexOf($user), 3);
		$this->assertFalse($coll->indexOf($user->guid + 5));

		$coll->remove(array($user, 1));
		$this->assertEqual($coll->count(), 2);

		$coll->removeAll();
		$this->assertEqual($coll->count(), 0);

		$coll->push(range(1, 5));
		$this->assertEqual($coll->count(), 5);

		$coll->removeFromBeginning(3);
		$this->assertEqual($coll->count(), 2);
		$this->assertTrue($coll->hasAllOf(array(4, 5)));

		$coll->removeFromEnd();
		$this->assertEqual($coll->count(), 1);
		$this->assertTrue($coll->hasAnyOf(4));

		$coll->removeAll();
		$this->assertEqual($coll->count(), 0);

		$coll->push(range(1, 6));
		$slice_tests = array(
			array(0, null,  range(1, 6)),
			array(0, 4,     range(1, 4)),
			array(0, -2,    range(1, 4)),
			array(2, null,  range(3, 6)),
			array(2, 2,     range(3, 4)),
			array(2, -2,    range(3, 4)),
			array(-3, null, range(4, 6)),
			array(-3, 1,    array(4)   ),
			array(-3, -1,   range(4, 5)),
		);
		foreach ($slice_tests as $test) {
			$expected = $test[2];
			$returned = $coll->slice($test[0], $test[1]);
			$this->assertEqual(
				$returned,
				$expected,
				"slice({$test[0]}, {$test[1]}) returned [" . implode(',', $returned) . "]");
		}

		$coll->moveAfter(2, 4);
		$this->assertEqual($coll->slice(), array(1, 3, 4, 2, 5, 6));

		$coll->moveBefore(5, 4);
		$this->assertEqual($coll->slice(), array(1, 3, 5, 4, 2, 6));

		$coll->moveBefore(5, 1);
		$this->assertEqual($coll->slice(), array(5, 1, 3, 4, 2, 6));

		$this->assertFalse($coll->moveAfter(4, 1));

		$coll->rearrange(array(3, 4, 2, 6), array(6, 4, 3, 2));
		$this->assertEqual($coll->slice(), array(5, 1, 6, 4, 3, 2));

		$coll->rearrange(array(5, 1, 6, 4, 3, 2), array(1, 5, 6, 4, 3, 2));
		$this->assertEqual($coll->slice(), array(1, 5, 6, 4, 3, 2));

		$coll->removeAll();
	}

	/**
	 * @todo split this up to test capabilities
	 */
	public function testQueryModifier() {
		$time = time() - 20;
		$objs = array();
		foreach (range(0, 9) as $i) {
			$obj = new ElggObject();
			$obj->subtype = 'testQueryModifier';
			$obj->save();

			// Note: MySQL is non-deterministic when sorting by duplicate values.
			// So if we use a bunch of test objects with the same time_created, we'll get
			// different orders depending on if we JOIN with the collection table. To test
			// real world conditions, we use test objects with distinct time_created.
			$obj->time_created = ($time + $i);
			$obj->save();
			$objs[] = $obj;
		}
		/* @var ElggObject[] $objs */

		$all_objs = $this->mapGuids($objs);

		$user = elgg_get_logged_in_user_entity();
		$name = 'testQueryModifier';
		$coll = elggx_get_collection($user, $name);

		$coll_guids = array($all_objs[2], $all_objs[4]);
		$coll->push($coll_guids);

		// selector
		$mod = $coll->getQueryModifier();
		$fetched_objs = elgg_get_entities($mod->getOptions(array(
			'type' => 'object',
			'subtype' => 'testQueryModifier',
		)));
		// selector returns most recent additions first by default
		$expected = array_reverse($coll_guids);

		$computed = $this->mapGuids($fetched_objs);
		$this->assertEqual($expected, $computed);

		// missing collection
		$mod = new Elggx_Collections_QueryModifier(null);
		$fetched_objs = elgg_get_entities($mod->getOptions(array(
			'type' => 'object',
			'subtype' => 'testQueryModifier',
		)));
		$expected = array();
		$computed = $this->mapGuids($fetched_objs);
		$this->assertEqual($expected, $computed);

		// sticky
		$mod = $coll->getQueryModifier('sticky');
		$fetched_objs = elgg_get_entities($mod->getOptions(array(
			'type' => 'object',
			'subtype' => 'testQueryModifier',
			'limit' => 5,
		)));
		$expected = array(
			$all_objs[4],
			$all_objs[2],
			$all_objs[9],
			$all_objs[8],
			$all_objs[7],
		);
		$computed = $this->mapGuids($fetched_objs);
		$this->assertEqual($expected, $computed);

		// missing for sticky
		$mod = new Elggx_Collections_QueryModifier(null);
		$mod->setModel('sticky');
		$fetched_objs = elgg_get_entities($mod->getOptions(array(
			'type' => 'object',
			'subtype' => 'testQueryModifier',
			'limit' => 3,
		)));
		$expected = array(
			$all_objs[9],
			$all_objs[8],
			$all_objs[7],
		);
		$computed = $this->mapGuids($fetched_objs);
		$this->assertEqual($expected, $computed);

		// filter
		$mod = $coll->getQueryModifier('filter');
		$fetched_objs = elgg_get_entities($mod->getOptions(array(
			'type' => 'object',
			'subtype' => 'testQueryModifier',
			'limit' => 7,
		)));
		$expected = array(
			$all_objs[9],
			$all_objs[8],
			$all_objs[7],
			$all_objs[6],
			$all_objs[5],
			$all_objs[3],
			$all_objs[1],
		);
		$computed = $this->mapGuids($fetched_objs);
		$this->assertEqual($expected, $computed);

		// missing for filter
		$mod = new Elggx_Collections_QueryModifier(null);
		$mod->setModel('filter');
		$fetched_objs = elgg_get_entities($mod->getOptions(array(
			'type' => 'object',
			'subtype' => 'testQueryModifier',
			'limit' => 8,
		)));
		$expected = array(
			$all_objs[9],
			$all_objs[8],
			$all_objs[7],
			$all_objs[6],
			$all_objs[5],
			$all_objs[4],
			$all_objs[3],
			$all_objs[2],
		);
		$computed = $this->mapGuids($fetched_objs);
		$this->assertEqual($expected, $computed);

		$coll->removeAll();

		foreach ($objs as $obj) {
			$obj->delete();
		}
	}

	/**
	 * @param ElggEntity[] $entities
	 * @return int[]
	 */
	protected function mapGuids($entities) {
		foreach ($entities as $i => $entity) {
			$entities[$i] = $entity->guid;
		}
		return $entities;
	}
}
