**Note!** Rename this folder to `elggx_collections_api` in Elgg's mod directory.

## Collections API

(TODO)


### Using the built-in collections actions

To use these, you must specify permissions for collections. You do this by handling the plugin hook "elggx_collections:can". Your handler will be passed the collection, as `$params['collection']`, and the logged in user as `$params['user']`.

Available values for `$type`:

* "add_item" (includes `$params['item_guid']`)
* "delete_item" (includes `$params['item_guid']`)
* "rearrange_items" (includes `$params['items_before']` and `$params['items_after']`)

If the user should be allowed to alter the collection in the manner specified by `$type`, then your handler function should return `true`.


### Setup

Install this plugin as `path/to/Elgg/mod/elggx_collections_api`

