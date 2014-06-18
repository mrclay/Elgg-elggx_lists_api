<?php
/**
 * Elgg Test lists API
 *
 * @todo separate these tests out more
 *
 * @package Elgg
 * @subpackage Test
 */
class ElggxListsTest extends ElggCoreUnitTest {

	/**
	 * @todo split this up to test capabilities
	 */
	public function testGetListHandlesEntitiesAndInts() {
		$site = elgg_get_site_entity();

		$list = elggx_get_list($site);
		$this->assertIsA($list, 'Elggx_Lists_List');
		$this->assertEqual($list->getEntityGuid(), $site->guid);

		$list = elggx_get_list($site->guid);
		$this->assertIsA($list, 'Elggx_Lists_List');
		$this->assertEqual($list->getEntityGuid(), $site->guid);
	}

	public function testEntityDeleteEmptiesLists() {
		$obj = new ElggObject();
		$obj->save();

		$name = 'testDeleteEmpties';

		$list = elggx_get_list($obj, $name);
		$list->push(elgg_get_site_entity());

		$this->assertEqual($list->count(), 1);

		$obj->delete();

		$this->assertEqual($list->count(), 0);
	}

	public function testReverseLookups() {
		$false_entity = 3;
		$searched_entity = 2;

		$test_lists = array();
		foreach (range(0, 9) as $i) {
			$list = elggx_get_list($i, "testReverseLookups{$i}");
			$list->push($false_entity);
			if ($i % 2 == 0) {
				$list->push($searched_entity);
			}
			$test_lists[] = $list;
		}
		/* @var Elggx_Lists_List[] $test_lists */

		$count = elggx_get_containing_lists($searched_entity, array('count' => true));
		$this->assertEqual($count, 5);


		$expected = array();
		foreach (range(0, 9, 2) as $i) {
			$expected["{$i}:testReverseLookups{$i}"] = true;
		}
		$found_lists = elggx_get_containing_lists($searched_entity);
		foreach ($found_lists as $list) {
			$key = $list->getEntityGuid() . ":" . $list->getName();
			unset($expected[$key]);
		}
		$this->assertEqual(count($expected), 0);

		$lists = elggx_get_containing_lists($searched_entity, array('limit' => 3, 'offset' => 0));
		$this->assertEqual(count($lists), 3);
		$lists = elggx_get_containing_lists($searched_entity, array('limit' => 3, 'offset' => 3));
		$this->assertEqual(count($lists), 2);
		$lists = elggx_get_containing_lists($searched_entity, array('limit' => 3, 'offset' => 6));
		$this->assertEqual(count($lists), 0);

		foreach ($test_lists as $list) {
			$list->removeAll();
		}
	}

	/**
	 * @todo split this up to test capabilities
	 */
	public function testItemAccess() {
		$user = elgg_get_logged_in_user_entity();
		$name = 'test_list';
		$list = elggx_get_list($user, $name);

		$this->assertEqual($list->count(), 0);

		$list->push(1);
		$this->assertEqual($list->count(), 1);
		$this->assertTrue($list->hasAnyOf(1));

		$list->push(array(2, 3, 1, $user));
		$this->assertEqual($list->count(), 4);
		$this->assertTrue($list->hasAnyOf($user));
		$this->assertTrue($list->hasAnyOf($user->guid));

		$this->assertEqual($list->indexOf($user), 3);
		$this->assertFalse($list->indexOf($user->guid + 5));

		$list->remove(array($user, 1));
		$this->assertEqual($list->count(), 2);

		$list->removeAll();
		$this->assertEqual($list->count(), 0);

		$list->push(range(1, 5));
		$this->assertEqual($list->count(), 5);

		$list->removeFromBeginning(3);
		$this->assertEqual($list->count(), 2);
		$this->assertTrue($list->hasAllOf(array(4, 5)));

		$list->removeFromEnd();
		$this->assertEqual($list->count(), 1);
		$this->assertTrue($list->hasAnyOf(4));

		$list->removeAll();
		$this->assertEqual($list->count(), 0);

		$list->push(range(1, 6));
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
			$returned = $list->slice($test[0], $test[1]);
			$this->assertEqual(
				$returned,
				$expected,
				"slice({$test[0]}, {$test[1]}) returned [" . implode(',', $returned) . "]");
		}

		$list->moveAfter(2, 4);
		$this->assertEqual($list->slice(), array(1, 3, 4, 2, 5, 6));

		$list->moveBefore(5, 4);
		$this->assertEqual($list->slice(), array(1, 3, 5, 4, 2, 6));

		$list->moveBefore(5, 1);
		$this->assertEqual($list->slice(), array(5, 1, 3, 4, 2, 6));

		$this->assertFalse($list->moveAfter(4, 1));

		$list->rearrange(array(3, 4, 2, 6), array(6, 4, 3, 2));
		$this->assertEqual($list->slice(), array(5, 1, 6, 4, 3, 2));

		$list->rearrange(array(5, 1, 6, 4, 3, 2), array(1, 5, 6, 4, 3, 2));
		$this->assertEqual($list->slice(), array(1, 5, 6, 4, 3, 2));

		$list->removeAll();
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
			// different orders depending on if we JOIN with the list table. To test
			// real world conditions (without forcing a custom sort), we use test objects
			// with distinct time_created.
			$obj->time_created = ($time + $i);
			$obj->save();
			$objs[] = $obj;
		}
		/* @var ElggObject[] $objs */

		$all_objs = $this->mapGuids($objs);

		$user = elgg_get_logged_in_user_entity();
		$name = 'testQueryModifier';
		$list = elggx_get_list($user, $name);

		$list_guids = array($all_objs[2], $all_objs[4]);
		$list->push($list_guids);


		// selector
		$mod = $list->getQueryModifier();
		$fetched_objs = elgg_get_entities($mod->getOptions(array(
			'type' => 'object',
			'subtype' => 'testQueryModifier',
		)));
		// selector returns most recent additions first by default
		$expected = array_reverse($list_guids);

		$computed = $this->mapGuids($fetched_objs);
		$this->assertEqual($expected, $computed);


		// missing list
		$mod = new Elggx_Lists_QueryModifier(null);
		$fetched_objs = elgg_get_entities($mod->getOptions(array(
			'type' => 'object',
			'subtype' => 'testQueryModifier',
		)));
		$expected = array();
		$computed = $this->mapGuids($fetched_objs);
		$this->assertEqual($expected, $computed);

		// sticky
		$mod = $list->getQueryModifier('sticky');
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
		$mod = new Elggx_Lists_QueryModifier(null);
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
		$mod = $list->getQueryModifier('filter');
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
		$mod = new Elggx_Lists_QueryModifier(null);
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

		// cleanup
		$list->removeAll();
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
