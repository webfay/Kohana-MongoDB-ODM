<?php defined('SYSPATH') OR die('No direct script access.');
/**
 * ODM Validation exceptions.
 *
 * @package ODM
 */
class Kohana_ODM_Validation_Exception extends Kohana_Exception {

	/**
	 * Array of validation objects
	 * @var array
	 */
	protected $_objects = array();

	/**
	 * The alias of the main ODM model this exception was created for
	 * @var string
	 */
	protected $_alias = NULL;

	/**
	 * Constructs a new exception for the specified model
	 *
	 * @param string     $alias    The alias to use when looking for error messages
	 * @param Validation $object   The Validation object of the model
	 * @param string     $message  The error message
	 * @param array      $values   The array of values for the error message
	 * @param integer    $code     The error code for the exception
	 * @param Exception  $previous
	 */
	public function __construct($alias, Validation $object, $message = '', array $values = NULL,
		$code = 0, Exception $previous = NULL)
	{
		if (empty($message))
		{
			$message = 'Failed to validate the ODM object. Place the object in a try
				catch block for more information.';
		}

		$this->_alias = $alias;
		$this->_objects['_object'] = $object;

		parent::__construct($message, $values, $code, $previous);
	}

	/**
	 * Adds a Validation object to this exception
	 *
	 *     // The following will add a validation object for a profile model
	 *     // inside the exception for a user model.
	 *     $e->add_object('profile', $validation);
	 *     // The errors array will now look something like this
	 *     // array
	 *     // (
	 *     //   'username' => 'This field is required',
	 *     //   'profile'  => array
	 *     //   (
	 *     //     'first_name' => 'This field is required',
	 *     //   ),
	 *     // );
	 *
	 * @param  string     $alias    The relationship alias from the model
	 * @param  Validation $object   The Validation object to merge
	 * @return ODM_Validation_Exception
	 */
	public function add_object($alias, Validation $object)
	{

		$this->_objects[$alias]['_object'] = $object;

		return $this;
	}

	/**
	 * Merges an ODM_Validation_Exception object into the current exception
	 * Useful when you want to combine errors into one array
	 *
	 * @param  ODM_Validation_Exception $object   The exception to merge
	 * @return ODM_Validation_Exception
	 */
	public function merge(ODM_Validation_Exception $object)
	{
		$alias = $object->alias();

		$this->_objects[$alias] = $object->objects();

		return $this;
	}

	/**
	 * Returns a merged array of the errors from all the Validation objects in this exception
	 *
	 *     // Will load Model_User errors from messages/ODM-validation/user.php
	 *     $e->errors('ODM-validation');
	 *
	 * @param   string  $directory Directory to load error messages from
	 * @param   mixed   $translate Translate the message
	 * @return  array
	 * @see generate_errors()
	 */
	public function errors($directory = NULL, $translate = TRUE)
	{
		return $this->generate_errors($this->_alias, $this->_objects, $directory, $translate);
	}

	/**
	 * Recursive method to fetch all the errors in this exception
	 *
	 * @param  string $alias     Alias to use for messages file
	 * @param  array  $array     Array of Validation objects to get errors from
	 * @param  string $directory Directory to load error messages from
	 * @param  mixed  $translate Translate the message
	 * @return array
	 */
	protected function generate_errors($alias, array $array, $directory, $translate)
	{
		$errors = array();

		foreach ($array as $key => $object)
		{
			if (is_array($object))
			{
				$errors[$key] = ($key === '_external')
					// Search for errors in $alias/_external.php
					? $this->generate_errors($alias.'/'.$key, $object, $directory, $translate)
					// Regular models get their own file not nested within $alias
					: $this->generate_errors($key, $object, $directory, $translate);
			}
			elseif ($object instanceof Validation)
			{
				if ($directory === NULL)
				{
					// Return the raw errors
					$file = NULL;
				}
				else
				{
					$file = trim($directory.'/'.$alias, '/');
				}

				// Merge in this array of errors
				$errors += $object->errors($file, $translate);
			}
		}

		return $errors;
	}

	/**
	 * Returns the protected _objects property from this exception
	 *
	 * @return array
	 */
	public function objects()
	{
		return $this->_objects;
	}

	/**
	 * Returns the protected _alias property from this exception
	 *
	 * @return string
	 */
	public function alias()
	{
		return $this->_alias;
	}
}
