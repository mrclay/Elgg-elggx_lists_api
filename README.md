**Note!** Rename this folder to `elggx_collections_api` in Elgg's mod directory.

## Collections API

This plugin provides an API (it does not directly provide functionality) for managing ordered sets of entities, and to efficiently use those sets in `elgg_get_entities()` queries. This allows efficient and pagination-compatible implementations of ordered favorites lists, "sticky" entities, hidden items, etc.

### The basics

The collection object (`Elggx_Collections_Collection`) is essentially an API for relating items to an entity (in fact it uses the relationships table under the hood), so all collections *exist*, they're just empty by default.

Each collection specifies an entity GUID and a string name. E.g.

```php
<?php

// A user's favorites collection
$coll = elggx_get_collection($user, 'favorites');

// default collection name is "".
$coll = elggx_get_collection($entity);
```

### Modifying a collection

The collection has several methods designed to manage items similarly to arrays. These methods accept ElggEntities or GUIDs but always return GUIDs.

Due to the storage model, the API discourages placing new items before others in the collection, but the `rearrange()` method (supported by the `rearrange_items` action) provides the most efficient way to achieve this.

### Using a collection in queries

A query modifier object is designed to decorate the `$options` array passed to `elgg_get_entities()`.

The object gives you a lot of flexibility in applying a collection to your queries, but it comes with three built-in models:

* **selector** (default) : fetch only collection items, with the latest added on top
* **sticky** : keep collection items at the top of the result set, with the latest collection items on top
* **filter** : remove collection items from the result set

E.g. Applying sticky items to a query:

```php
<?php

// get a query modifier object set to the sticky model
$qm = $coll->getQueryModifier('sticky');

// decorate $options
$options = $qm->getOptions($options);

elgg_list_entities($options);
```

### Finding collections

`elggx_get_containing_collections($entity, $options)` provides a way to find collections that contain a particular entity.

It returns an array of collection objects or an int if `$options['count']` is true. Like `elgg_get_entities`, it supports pagination with `$options['limit']` and `$options['offset']`.

### Access control

Like relationships, collections have no inherent access control. If you need this, tie your collection to an `ElggObject` and use access control on the object to determine if the user should be able to access/edit the collection.

### Using the built-in collections actions/views

Your plugin may choose to use the built-in collections actions for adding/removing/rearranging items. Since these are not aware of your business logic, you must specify permissions by handling the plugin hook "elggx_collections:can".

Your handler always will be passed the collection as `$params['collection']`, and the logged in user as `$params['user']`.

Available values for `$type`:

* "add_item" (includes `$params['item_guid']`)
* "delete_item" (includes `$params['item_guid']`)
* "rearrange_items" (includes `$params['items_before']` and `$params['items_after']`)

If the user should be allowed to alter the collection (in the action specified by `$type`), then your handler function should return `true`.

Also included are views `elggx_collections/output/(add|remove)_item_link` to write links to the above actions.

### UI for rearranging items (TODO)

JavaScript code to support the `rearrange_items` action (based on jQuery UI Sortable) has been copied from a working project, but must be refactored to function. It's located in views/default/js/elggx_collections.js

I hope to offer a view that supports rendering a sortable collection. The sorting mechanism should work across pagination boundaries, but obviously there's no way to drag across pages, so it may be wise to always present the list on one page when it must be sorted.

The backend reordering mechanism is designed to perform as few queries as possible, but this API will certainly have limitations.

### Setup

Install this plugin as `mod/elggx_collections_api`

### Support

Ask questions via https://groups.google.com/forum/#!forum/elgg-development or http://community.elgg.org/groups/profile/7/plugin-development