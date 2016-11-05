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
		* @return array
	*/

	public function as_array()
	{
		$documents = [];
		foreach($this as $_documents)
		{
			$documents[] = $_documents->_get_document();
		}
		return $documents;
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
