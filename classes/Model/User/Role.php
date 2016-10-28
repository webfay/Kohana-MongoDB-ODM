<?php defined('SYSPATH') OR die('No direct script access.');
/**
 * Default auth role
 *
 * @package    ODM/Auth
 */
class Model_Auth_Role extends ODM {

	public function rules()
	{
		return array(
			'name' => array(
				array('not_empty'),
				array('min_length', array(':value', 4)),
				array('max_length', array(':value', 32)),
			),
			'description' => array(
				array('max_length', array(':value', 255)),
			)
		);
	}

}
