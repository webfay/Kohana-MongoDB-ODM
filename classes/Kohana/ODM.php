<?php defined('SYSPATH') OR die('No direct script access.');
/**
 * [Object Document Mapping][ref-odm] (ODM) is a method of abstracting
 * MongoDB access to standard PHP calls. Documents
 * are represented as model objects, with object properties
 * representing document fields.
 *
 * @package ODM
 */
class Kohana_ODM extends Model {


	/**
	 * The message filename used for validation errors.
	 * Defaults to ODM::$_object_name
	 * @var string
	 */
	protected $_errors_filename;

	/**
	 * The database instance
	 * @var MongoClient
	 */
	protected $_db;

	/**
	 * The name of the collection
	 * @var string
	 */
	protected $_collection_name;

	/**
	 * @var string
	 */
	protected $_object_name;

	/**
	 * @var array
	 */
	protected $_document = array();

	/**
	 * @var bool
	 */
	protected $_loaded = FALSE;

	/**
	 * Functions to run on the cursor
	 * @var array
	 */
	protected $_cursor_functions;

	/**
	 * @var array
	 */
	protected $_query = array();

	/**
	 * @var array
	 */
	protected $_update = array();

	/**
	 * @var array
	 */
	protected $_fields = array();

	/**
	 * Validation object created before saving/updating
	 * @var Validation
	 */
	protected $_validation;

	/**
	 * @var bool
	 */
	protected $_valid = FALSE;

	/**
	 * Database config group
	 * @var String
	 */
	protected $_db_group = 'default';

	/**
	 * @var array
	 */
	protected $_rules = array();

	/**
	 * @var array
	 */
	protected $_filters = array();

	/**
		* @var array
	*/
	private $_set_data_exception = [];

	const MODEL_CLASS_PREFIX = 'Model_';


	/**
	 * Constructs a new model
	 *
	 * @throws Exception
	 */
	public function __construct()
	{
		// Make sure schema exists
		if ( ! isset($this->_schema))
		{
			throw new Kohana_Exception('Please define the schema for :model.',
				array(':model' => get_class($this)));
		}

		// Set the object name
		$this->_object_name = strtolower(substr(get_class($this), 6));

		// Create collection name
		if ( ! $this->_collection_name)
		{
			// Split model name by it's underscores
			$object_name_parts = explode('_', $this->_object_name);

			// Pluralize last word
			$last_key = count($object_name_parts) -1;
			$object_name_parts[$last_key] = Inflector::plural($object_name_parts[$last_key]);
			$this->_collection_name = implode('_', $object_name_parts);
		}

		if ( ! $this->_errors_filename)
		{
			$this->_errors_filename = $this->_object_name;
		}

		// Default _id type
		if ( ! isset($this->_schema['_id']))
		{
			$this->_schema['_id'] = 'id';
		}
	}

	/**
	 * Get property from document
	 *
	 * @param $field_name
	 * @throws Exception
	 * @return mixed
	 */
	public function &__get($field_name)
	{
		// Get property from the loaded document
		$field =& $this->_follow_path($field_name, $this->_document);

		if ($field === $this->_document)
		{
			throw new Kohana_Exception(
				'The :property property does not exist in the :class object or cannot be accessed.',
				array(':property' => $field_name, ':class' => get_class($this)));
		}

		return $field;
	}

	/**
	 * @param  string $field
	 * @param  string $value
	 */
	public function __set($field, $value)
	{
		$this->set($field, $value);
	}

	/**
	 * @param string $property
	 */
	public function __unset($property)
	{
		unset($this->_document[$property], $this->$property);
		$this->_update['$unset'][$property] = '';
	}

	/**
	 * @param string $prop
	 * @return bool
	 */
	public function __isset($prop)
	{
		// get field
		$field =& $this->_follow_path($prop, $this->_document);

		return ($field !== $this->_document);
	}

	/**
	 * Creates and returns a new model.
	 *
	 * Model name must be passed with its' original casing, e.g.
	 *     $model = ODM::factory('User_Token');
	 *
	 * @param string $name
	 * @return  $this
	 */
	public static function factory($name = '')
	{
		if ($name)
		{
			$model_name = self::MODEL_CLASS_PREFIX .$name;
			$instance = new $model_name;
			return $instance;
		}

		return new static();
	}

	/**
	 * Set value to the document
	 *
	 * @param string $property
	 * @param string $value
	 * @param bool   $set used to stop multiple changes of a field in update query
	 * @throws Exception
	 * @return $this
	 */
	public function set($property, $value, $set = TRUE)
	{
		// get field
		$field =& $this->_follow_path($property, $this->_document, TRUE);

		if ($field !== $this->_document)
		{
			// Run any filters
			$this->_run_filter($property, $value);

			// Enforce data type
			$this->_enforce_type($property, $value);

			// Add to document
			$field = $value;

			// Generate an update query if object is loaded
			if ($set)
			{
				$this->_update['$set'][$property] = $value;
			}
		}
		elseif (property_exists($this, $property))
		{
			// Enforce data type
			$this->_enforce_type($property, $value);

			// Place ignore property in it's own array
			$this->$property = $value;
		}
		else
		{
			throw new Kohana_Exception(':property is not defined in the :model',
				array(':property' => $property, ':model' => get_class($this)));
		}

		return $this;
	}

	/**
	 * Unset a field. The same as unset(), but chainable
	 *
	 * @param string $field
	 * @return $this
	 */
	public function unset_field($field)
	{
		unset($this->$field);

		return $this;
	}

	/**
	 * Increment value
	 * uses $inc and starts at 0 if not set
	 *
	 * @param string $field
	 * @param int $value
	 * @return $this
	 */
	public function inc($field, $value = 1)
	{
		$this->$field += $value;
		$this->_update['$inc'][$field] = $value;

		return $this;
	}

	/**
	 * Append value to an array
	 *
	 * @param string $field
	 * @param int $value
	 * @return $this
	 */
	public function push($field, $value)
	{
		if ( ! isset($this->$field))
		{
			$this->$field = array();
		}

		$this->set($field, array_push($this->$field, $value), FALSE);
		$this->_update['$push'][$field] = $value;

		return $this;
	}

	/**
	 * Select the fields you want returned
	 *
	 * @return $this
	 */
	public function select()
	{
		$this->_fields = func_get_args();

		return $this;
	}

	/**
	 * Limit results
	 *
	 * @param  int|mixed  $limit
	 * @return $this
	 */
	public function limit($limit)
	{
		$this->_cursor_functions['limit'] = $limit;

		return $this;
	}

	/**
	 * Skip results
	 *
	 * @param  int  $skip
	 * @return $this
	 */
	public function skip($skip)
	{
		$this->_cursor_functions['skip'] = $skip;

		return $this;
	}

	/**
	 * And where
	 *
	 * @param $field
	 * @param $operation
	 * @param $value
	 * @return $this
	 */
	public function where($field, $operation, $value)
	{
		return $this->_where($field, $operation, $value, NULL);
	}

	/**
	 * Or where
	 *
	 * @param $field
	 * @param $operation
	 * @param $value
	 * @return $this
	 */
	public function or_where($field, $operation, $value)
	{
		return $this->_where($field, $operation, $value, '$or');
	}

	/**
	 * Not where
	 *
	 * @param $field
	 * @param $operation
	 * @param $value
	 * @return $this
	 */
	public function not_where($field, $operation, $value)
	{
		return $this->_where($field, $operation, $value, '$not');
	}

	/**
	 * Nor where
	 *
	 * @param $field
	 * @param $operation
	 * @param $value
	 * @return $this
	 */
	public function nor_where($field, $operation, $value)
	{
		return $this->_where($field, $operation, $value, '$not');
	}

	/**
	 * Sort results
	 *
	 * @param  string $field
	 * @param  int    $value to sort by
	 * @return $this
	 */
	public function sort($field, $value = 1)
	{
		$this->_cursor_functions['sort'][$field] = $value;

		return $this;
	}

	/**
	 * Set values from an array.  This method should be used
	 * for loading in post data, etc.
	 *
	 * @param  array $values   Array of property => val
	 * @return $this
	 */
	public function values($values)
	{
		foreach ($values as $property => $value)
		{
			$this->$property = $value;
		}

		return $this;
	}

	/**
	 * Send a command to the database
	 *
	 * [!!] not validated with _enforce_type
	 *
	 * @param  array $command
	 * @param  array $options
	 * @throws Exception
	 * @return mixed command type
	 */
	public function command(array $command, $options = array())
	{
		if ($this->_loaded)
		{
			throw new Kohana_Exception('Method find() cannot be called on loaded objects');
		}

		$result = $this->_profile(function() use ($options, $command)
		{
			return $this->_db->connection->command($command, $options);
		}, 'command', $command);

		return $result;
	}

	/**
	 * Set or get the query
	 *
	 * [!!] not validated with _enforce_type
	 *
	 * @param mixed $query
	 * @return $this|array
	 */
	public function query($query = FALSE)
	{
		if ($query === FALSE)
		{
			$this->_query = $query;

			return $this;
		}

		return $this->_query;
	}

	/**
	 * Save document to the database.
	 * Update the existing document, otherwise insert the document.
	 *
	 * @param Validation $extra_validation
	 * @return $this
	 */
	public function save(Validation $extra_validation = NULL)
	{
		return $this
			->beforeSave()
			->check($extra_validation)
			->_profile(function()
		{
			$this->_db->{$this->_collection_name}->save($this->_document);
		}, 'save', $this->_query);
	}

	/**
		* throws exception if some type not match schema
		* @return $this
	*/
	private function beforeSave()
	{
		if(sizeof($this->_set_data_exception))
			throw new Kohana_Exception(
				$this->_set_data_exception['text'],
				$this->_set_data_exception['details']
			);
		return $this;
	}

	/**
	 * Insert document to the database.
	 *
	 * @param Validation $extra_validation
	 * @return $this
	 */
	public function insert(Validation $extra_validation = NULL)
	{
		return $this
			->beforeSave()
			->check($extra_validation)
			->_profile(function()
		{
			$this->_db->{$this->_collection_name}->insert($this->_document);
		}, 'insert', $this->_query);
	}

	/**
	 * Remove document from the database
	 *
	 * @return $this
	 */
	public function remove()
	{

		if ($this->loaded())
		{
			// Remove current document
			$this->_profile(function()
			{

				$this->_db->{$this->_collection_name}->remove($this->_document);
			}, 'Remove', $this->_document);

			$this->_document = array();
			$this->_loaded = FALSE;
		}
		else
		{
			// Remove documents in query
			$this->_profile(function()
			{
				$this->_db->{$this->_collection_name}->remove($this->_query);
			}, 'remove', $this->_query);
		}

		$this->clear();

		return $this;
	}

	/**
	 * Remove all documents found
	 *
	 * @return $this
	 */
	public function remove_all()
	{
		// Remove documents in query
		$this->_profile(function()
		{
			$this->_db->{$this->_collection_name}->remove($this->_query);
		}, 'Remove all', $this->_query);

		$this->clear();

		return $this;
	}

	/**
	 * Update document
	 *
	 * @param Validation $extra_validation
	 */
	public function update(Validation $extra_validation = NULL)
	{
		$this->_update($extra_validation, FALSE);
	}

	/**
	 * Update document
	 *
	 * @param Validation $extra_validation
	 */
	public function update_all(Validation $extra_validation = NULL)
	{
		$this->_update($extra_validation, TRUE);
	}

	/**
	 * Find documents in the collection
	 *
	 * @return $this
	 */
	public function find()
	{
		$cursor = $this->_cursor_functions($this->_find());

		return $this->load($cursor->limit(-1)->getNext());
	}

	/**
	 * Get auto_increment like MySql
	 * @param string $Id name column where save increment
	 * @return int increment Id
	 */
	public function sId()
	{
		$this->_connect();

		$rs = $this->_db->{'Ids'}->findAndModify(
		     array('_id' => $this->_collection_name),
		     array('$inc' => array("seq" => 1)),
		     null,
		     array(
		        "new" => true,
						"upsert" => true,
		    )
		);

		return (int)$rs['seq'];
	}

	/**
	 * Find documents in the collection
	 *
	 * @return ODM_Collection
	 */
	public function find_all()
	{
		$cursor = $this->_cursor_functions($this->_find());
		$result = array();

		foreach ($cursor as $document)
		{
			$result[] = ODM::factory($this->_object_name)->load($document);
		}
		return new ODM_Collection($result);
	}

	/**
	 * Count documents in the collection
	 *
	 * @return int
	 */
	public function count()
	{
		$result = $this->_profile(function()
		{
			return $this->_db->{$this->_collection_name}->count($this->_query);
		}, 'count', $this->_query);

		$this->clear();

		return $result;
	}

	/**
	 * Find if the object is loaded
	 *
	 * @return bool
	 */
	public function loaded()
	{
		return $this->_loaded;
	}

	/**
	 * Clear object's queries
	 *
	 * @return $this
	 */
	public function clear()
	{
		$this->_query = array();
		$this->_fields = array();
		$this->_update = array();

		return $this;
	}

	/**
	 * Get a property, but pass through HTML::chars() first
	 *
	 * @param $path
	 * @return mixed HTML::chars() parsed string
	 */
	public function safe($path)
	{
		$document = $this->as_array();
		$value = $this->_follow_path($path, $document);

		// HTML::chars() every element of the array
		if (is_array($value))
		{
			if ($value == $this->as_array())
			{
				return '';
			}

			array_walk_recursive($value, function($item)
			{
				return HTML::chars($item);
			});

			return $value;
		}

		return HTML::chars($value);
	}

	/**
	 * Same as __get though this returns FALSE on fail
	 *
	 * @param  $field_name
	 * @return bool|mixed
	 */
	public function get($field_name)
	{
		// Get property from the loaded document
		$field =& $this->_follow_path($field_name, $this->_document);

		if ($field !== $this->_document)
		{
			return $field;
		}

		return FALSE;
	}

	/**
	 * Get validation
	 *
	 * @return Validation
	 */
	public function validation()
	{
		if ( ! isset($this->_validation))
		{
			// Initialize the validation object
			$this->_validation();
		}

		return $this->_validation;
	}

	/**
	 * Validates the current model's data
	 *
	 * @param  Validation $extra_validation Validation object
	 * @throws ODM_Validation_Exception
	 * @return $this
	 */
	public function check(Validation $extra_validation = NULL)
	{
		// Determine if any external validation failed
		$extra_errors = ($extra_validation AND ! $extra_validation->check());

		// Always build a new validation object
		$this->_validation();

		$array = $this->_validation;

		if (($this->_valid = $array->check()) === FALSE OR $extra_errors)
		{
			$exception = new ODM_Validation_Exception($this->_errors_filename, $array);
			if ($extra_errors)
			{
				// Merge any possible errors from the external object
				$exception->add_object('_external', $extra_validation);
			}
			// throw $exception;
		}

		return $this;
	}

	public function getValidationErrors()
	{
		return $this->_validation;
	}

	/**
	 * Load a document into the model and mark object as loaded
	 *
	 * [!!] not validated with _enforce_type (use values() instead)
	 *
	 * @param array|bool $document
	 * @return $this
	 */
	public function load($document)
	{
		if ($document)
		{
			$this->_document = array_merge($this->_document, $document);
			$this->_loaded = TRUE;
		}

		return $this;
	}

	/**
	 * Comparison Operators
	 *
	 * @param  string $field     field value
	 * @param  string $operation the operator
	 * @param  mixed  $value
	 * @param  string $logical
	 * @return $this
	 */
	protected function _where($field, $operation, $value, $logical)
	{
		// Enforce data types
		settype($field, 'string');
		settype($operation, 'string');

		$query = array();

		// These operations will be enforced on their own
		$no_enforce = array('in', 'size', 'exists', 'regex');

		if ( ! in_array($operation, $no_enforce))
		{
			$this->_enforce_type($field, $value);
		}

		// Build query
		switch ($operation)
		{
			case 'size':
				$query[$field]['$size'] = (int) $value;
			break;
			case 'in':
				foreach ($value as $_value)
				{
					$this->_enforce_type($field, $_value);
				}

				$query[$field]['$in'] = $value;
			break;
			case '=':
				$query[$field] = $value;
			break;
			case '<':
				$query[$field]['$lt'] = $value;
			break;
			case '>':
				$query[$field]['$gt'] = $value;
			break;
			case '>=':
				$query[$field]['$gte'] = $value;
			break;
			case '<=':
				$query[$field]['$lte'] = $value;
			break;
			case '!=':
				$query[$field]['$ne'] = $value;
			break;
			case '!':
				$query[$field]['$nin'] = $value;
			break;
			case 'near':
				$query[$field]['$near'] = $value;
			break;
			case 'exists':
				$query[$field]['$exists'] = (bool) $value;
			break;
			case 'regex':
				$query[$field] = new MongoRegex( (string) $value);
			break;
		}

		if ($logical)
		{
			$query = array($logical => array($query));
		}

		$this->_query = array_merge_recursive($this->_query, $query);

		return $this;
	}

	/**
	 * Find data from the database
	 *
	 * @throws Exception if not loaded
	 * @return MongoCursor used to iterate through the results of a database query
	 */
	protected function _find()
	{
		if ($this->_loaded)
		{
			throw new Kohana_Exception('Method find() cannot be called on loaded objects');
		}

		$cursor = $this->_profile(function()
		{
			return $this->_db->{$this->_collection_name}->find($this->_query, $this->_fields);
		}, 'find', $this->_query);

		$this->clear();

		return $cursor;
	}

	/**
	 * Update document in the database
	 *
	 * @param Validation $extra_validation
	 * @param bool       $multiple
	 */
	protected function _update(Validation $extra_validation = NULL, $multiple)
	{
		// Require model validation before saving
		if ( ! $this->_valid OR $extra_validation)
		{
			$this->check($extra_validation);
		}

		// Add _id to query
		if (isset($this->_document['_id']) AND ! isset($this->_query['_id']))
		{
			$this->_query['_id'] = $this->_document['_id'];
		}

		$this->_profile(function() use ($multiple)
			{
				$this->_db->{$this->_collection_name}->update(
					$this->_query,
					$this->_update,
					array('multiple' => $multiple)
				);
			},
			$multiple ? 'update_all' : 'update',
			array('query' => $this->_query, 'update' => $this->_update));

		$this->clear();
	}

	/**
	 * Profile function
	 *
	 * @param callback $function
	 * @param string   $method
	 * @param array    $query
	 * @return mixed
	 */
	protected function _profile($function, $method = NULL, $query)
	{
		// Connect to database
		$this->_connect();

		if (Kohana::$profiling)
		{
			// Benchmark this query for the current instance
			$name = get_class($this);
			$benchmark = Profiler::start('Database', "$name::$method ".print_r($query, TRUE));
		}

		$result = $function();

		if (isset($benchmark))
		{
			Profiler::stop($benchmark);
		}

		return $result;
	}

	/**
	 * Initializes validation rules, and labels
	 */
	protected function _validation()
	{
		// Build the validation object with its rules
		$this->_validation = Validation::factory($this->_document);

		foreach ($this->_rules as $field => $rules)
		{
			$this->_validation->rules($field, $rules);
		}

		foreach ($this->_document as $field => $label)
		{
			$this->_validation->label($field, $label);
		}
	}

	/**
	 * Enforce the data to a specific variable type defined with $this->_schema.
	 * If the data is the wrong type, it will attempt to change it, otherwise it
	 * will throw an error.
	 *
	 * Set _schema to FALSE to disable this method.
	 *
	 * @link http://bit.ly/1aJjXP4
	 * @param  string $path
	 * @param  mixed  $value
	 * @throws Exception
	 */
	protected function _enforce_type($path, &$value)
	{
		// Don't enforce
		if ($this->_schema === FALSE)
		{
			return;
		}

		// Get field type
		$schema = $this->_follow_path($path, $this->_schema);

		if ($schema == $this->_schema)
		{
			throw new Kohana_Exception(
				':path could not be found in the :model object',
				array(':path' => $path, ':model' => get_class($this))
			);
		}

		// Value is array
		if (is_array($schema) AND is_array($value))
		{
			// Parse all values in the array
			foreach ($value as $value_field => &$value_value)
			{
				foreach ($schema as $schema_field => $schema_type)
				{
					// Value found, enforce it's type
					if ($value_field == $schema_field)
					{
						if (is_array($value_value) AND is_array($schema_type))
						{
							// Dive deeper into the array
							$this->_enforce_type($path.'.'.$value_field, $value_value);
						}
						else
						{
							$this->_check_type($value_value, $schema_type, $schema_field);
						}
						continue 2;
					}
				}

				if (isset($schema_field, $schema_type) AND $schema_field == '_keys')
				{
					$this->_check_type($value[$value_field], $schema_type, $path.'.'.$value_field);
				}
				else
				{
					throw new Kohana_Exception(
						':field could not be found in the :model schema',
						array(':field' => $path, ':model' => get_class($this))
					);
				}
			}
		}
		elseif (isset($schema['_keys']))
		{
			$this->_check_type($value, $schema['_keys'], $path);
		}
		else
		{
			$this->_check_type($value, $schema, $path);
		}
	}

	/**
	 * Check value with type
	 *
	 * @param  mixed  $value
	 * @param  string $type
	 * @param         $field
	 * @throws Exception
	 * @return bool
	 */
	protected function _check_type(&$value, $type, $field)
	{
		// Enforce MongoDate
		if (is_object($value))
		{
			if ($type == 'date' AND get_class($value) == 'MongoDate')
			{
				return;
			}


			// Enforce MongoId
			if ($type == 'id' AND get_class($value) == 'MongoId')
			{
				return;
			}
		}

		if($type == 'array' && is_array($value))
		return 1;


		// Enforce string (int is ok as it can be converted)
		if ($type == 'string' AND (is_string($value) OR is_int($value) OR is_null($value)))
		{
			settype($value, 'string');

			return;
		}

		// Enforce integer
		if ($type == 'int' AND (is_string($value) OR is_int($value)))
		{
			settype($value, 'int');

			return;
		}

		// Enforce bool
		if ($type == 'bool' AND is_bool($value))
		{
			return;
		}

		$this->_set_data_exception =
		[
			'text' => 	':field is not of type :type as specified in the :model schema',
			'details' =>
			[
				':field' => $field,
				':type'  => $type,
				':model' => get_class($this)
			]
		];


		return ;
	}

	/**
	 * Follow a path
	 *
	 * @param  string  $path   the path to follow
	 * @param  array   $steps  the steps in the path (The path target)
	 * @param  bool    $create create path if none exists
	 * @return mixed           The end of the path
	 */
	protected function &_follow_path($path, &$steps, $create = FALSE)
	{
		$path = explode('.', $path);

		// Traverse the steps until we get to the last step (end of path)
		foreach ($path as $step)
		{
			// It's an array
			if (is_array($steps) AND array_key_exists($step, $steps))
			{
				$steps =& $steps[$step];
			}

			// It's an object
			elseif (is_object($steps) AND property_exists($steps, $step))
			{
				$steps =& $steps->$step;
			}
			elseif ($create)
			{
				$steps[$step] = '';
				$steps =& $steps[$step];
			}
		}

		return $steps;
	}

	/**
	 * Run any cursor functions such as limit
	 *
	 * @param MongoCursor
	 * @return MongoCursor
	 */
	protected function _cursor_functions($cursor)
	{
		if ($this->_cursor_functions)
		{
			foreach ($this->_cursor_functions as $function => $param)
			{
				$cursor->{$function}($param);
			}
		}

		return $cursor;
	}

	/**
	 * Filters a value for a specific column
	 *
	 * @param  string $field The column name
	 * @param  string $value The value to filter
	 * @return string
	 */
	protected function _run_filter($field, &$value)
	{
		$filters = $this->_filters;

		// Get the filters for this column
		$wildcards = empty($filters[TRUE]) ? array() : $filters[TRUE];

		// Merge in the wildcards
		$filters = empty($filters[$field]) ? $wildcards : array_merge($wildcards, $filters[$field]);

		// Bind the field name and model so they can be used in the filter method
		$_bound = array(
			':field' => $field,
			':model' => $this,
		);

		foreach ($filters as $array)
		{
			// Value needs to be bound inside the loop so we are always using the
			// version that was modified by the filters that already ran
			$_bound[':value'] = $value;

			// Filters are defined as array($filter, $params)
			$filter = $array[0];
			$params = Arr::get($array, 1, [':value']);

			foreach ($params as $key => $param)
			{
				if (is_string($param) AND array_key_exists($param, $_bound))
				{
					// Replace with bound value
					$params[$key] = $_bound[$param];
				}
			}

			if (is_array($filter) OR ! is_string($filter))
			{
				// This is either a callback as an array or a lambda
				$value = call_user_func_array($filter, $params);
			}
			elseif (strpos($filter, '::') === FALSE)
			{
				// Use a function call
				$function = new ReflectionFunction($filter);

				// Call $function($this[$field], $param, ...) with Reflection
				$value = $function->invokeArgs($params);
			}
			else
			{
				// Split the class and method of the rule
				list($class, $method) = explode('::', $filter, 2);

				// Use a static method call
				$method = new ReflectionMethod($class, $method);

				// Call $Class::$method($this[$field], $param, ...) with Reflection
				$value = $method->invokeArgs(NULL, $params);
			}
		}
	}

	/**
		* Return collection name
		* @return string
	*/

	public function _get_collection_name()
	{
		return $this->_collection_name;
	}

	/**
	 * Prepares the model database connection
	 */
	protected function _connect()
	{
		if ( ! $this->_db)
		{
			$config = Kohana::$config->load('database')->{$this->_db_group};
			$client = new MongoClient($config['server']);
			$this->_db = $client->selectDB($config['database']);
		}
	}

	/**
		* @return array object as array
	*/

	public function as_array()
	{
		return $this->_document;
	}

	/**
		* @return array current model collection schema
	*/
	public function get_schema()
	{
		return $this->_schema;
	}

	/**
		* @return string name of current model
	*/
	public function get_name()
	{
		return str_replace(self::MODEL_CLASS_PREFIX, '', get_class($this));
	}

}
