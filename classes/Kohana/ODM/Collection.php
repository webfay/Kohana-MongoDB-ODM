<?php defined('SYSPATH') OR die('No direct script access.');
/**
 * The result of ODM_Collection
 *
 * This class has various methods to make development easier
 *
 * @package ODM
 */
class Kohana_ODM_Collection extends ArrayObject {

	/**
	 * Get the properties from the collection
	 *
	 * @param $property
	 * @return array
	 */
	public  function __get($property)
	{
		foreach ($this as $document)
		{
			$properties[] = $document->$property;
		}

		return count($this) ? $properties : array();
	}

	/**
		* Convert object ODM_Colletion to as_array
		* @param string $inc_field inc field for set keys of array
		* @return array
	*/

	public function as_array($inc_field = null)
	{
		$documents = [];

		if(!$inc_field)
		{
			foreach($this as $_documents)
			{
				$documents[] = $_documents->as_array();
			}
		}
		else
		{
			foreach($this as $_documents)
			{
				$document = $_documents->as_array();
				if(!isset($_documents->{$inc_field}))
				{
					// @TODO: fix architecture, that remove ODM->_get_collection_name() and ODM->_get_document
					throw new Kohana_Exception('Field '. $inc_field. ' is not exists in collection '. $_documents->_get_collection_name());
				}
				$documents[$document[$inc_field]] = $document;
			}
		}


		return $documents;
	}

	/**
		* Remove all loaded documents in collection
		* @return object
	*/

	public

	function remove()
	{
		foreach($this as $document)
		{
			$document->remove();
		}
		return $this;
	}

	/**
	 * Replace property with an object.
	 *
	 * For example, you could add users to their posts.
	 *
	 * If $posts->author was the user id you could do the following:
	 *
	 * <code>
	 * $posts->add($users, 'author')
	 * </code>
	 *
	 * You could now access the user using $posts->author such as $posts->author->username
	 *
	 * @param ODM_Collection $objects
	 * @param string $local_id
	 * @param string $foreign_id
	 * @param string $location the location to add object
	 */
	public function add($objects, $local_id, $foreign_id = '_id', $location = NULL)
	{
		foreach ($this as $document)
		{
			foreach ($objects as $object)
			{
				if ($object->$foreign_id == $document->$local_id)
				{
					$document->{ $location ?: $local_id } = $object;
				}
			}
		}
	}

}
