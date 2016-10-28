<?php defined('SYSPATH') OR die('No direct script access.');
/**
 * ODM Auth driver.
 *
 * @package ODM/Auth
 */
class Kohana_Auth_ODM extends Auth {

	/**
	 * Checks if a session is active.
	 *
	 * @param   mixed    $role Role name string, role ORM object, or array with role names
	 * @return  boolean
	 */
	public function logged_in($role = NULL)
	{
		// Get the user from the session
		$user = $this->get_user();

		if ( ! isset($user) OR ! $user->loaded())
			return FALSE;

		if ($user instanceof Model_User)
		{
			// If we don't have a roll no further checking is needed
			if ( ! $role)
			{
				return TRUE;
			}

			if (is_array($role))
			{
				for($i = 0; $i < count($role); $i++)
				{
					if ( ! in_array($role[$i], $user->roles))
						return FALSE;
				}
			}
			elseif ( ! is_object($role) AND ! in_array($role, $user->roles))
			{
				return FALSE;
			}

			return TRUE;
		}
	}

	/**
	 * Logs a user in.
	 *
	 * @param   string   $user
	 * @param   string   $password
	 * @param   boolean  $remember  enable autologin
	 * @return  boolean
	 */
	protected function _login($user, $password, $remember)
	{
		if ( ! is_object($user))
		{
			$usermail = $user;

			// Load the user
			$user = Model_User::factory()
				->logical('or')
				->where('username', '=', $usermail)
				->where('email', '=', $usermail)
				->find();
		}

		// If the passwords match, perform a login
		if ( ! isset($user->activation_code) AND $this->verify($password, $user->password))
		{
			if ($remember === TRUE)
			{
				// Token data
				$data = array(
					'user'       => $user->_id,
					'user_agent' => sha1(Request::$user_agent),
				);

				// Create a new autologin token
				$token = Model_User_Token::factory()
					->values($data)
					->save();

				// Set the autologin cookie
				Cookie::set('authautologin', $token->token, $this->_config['lifetime']);
			}

			// Finish the login
			$this->complete_login($user);

			return TRUE;
		}

		// Login failed
		return FALSE;
	}

	/**
	 * Forces a user to be logged in, without specifying a password.
	 *
	 * @param   mixed    $user                    username string, or user ODM object
	 * @param   boolean  $mark_session_as_forced  mark the session as forced
	 * @return  boolean
	 */
	public function force_login($user, $mark_session_as_forced = FALSE)
	{
		if ( ! is_object($user))
		{
			$username = $user;

			// Load the user
			$user = Model_User::factory();
			$user->where($user->unique_key($username), '=', $username)->find();
		}

		if ($mark_session_as_forced === TRUE)
		{
			// Mark the session as forced, to prevent users from changing account information
			$this->_session->set('auth_forced', TRUE);
		}

		// Run the standard completion
		$this->complete_login($user);
	}

	/**
	 * Logs a user in, based on the authautologin cookie.
	 *
	 * @return  mixed
	 */
	public function auto_login()
	{
		if ($token = Cookie::get('authautologin'))
		{
			// Load the token and user
			$token = ODM::factory('User_Token', array('token' => $token));

			if ( ! $token->loaded())
			{
				return FALSE;
			}

			// Load the user
			$user = Model_User::factory()
				->where('_id', '=', $token->user)
				->find();

			if ($user->loaded())
			{
				if ($token->user_agent === sha1(Request::$user_agent))
				{
					// Save the token to create a new unique token
					$token->save();

					// Set the new token
					Cookie::set('authautologin', $token->token);

					// Complete the login with the found data
					$this->complete_login($user);

					// Automatic login was successful
					return $user;
				}

				// Token is invalid
				$token->remove();
			}
		}

		return FALSE;
	}

	/**
	 * Gets the currently logged in user from the session (with auto_login check).
	 * Returns $default if no user is currently logged in.
	 *
	 * @param   mixed    $default to return in case user isn't logged in
	 * @return  mixed
	 */
	public function get_user($default = NULL)
	{
		$document = $this->_session->get($this->_config['session_key'], $default);
		$user = Model_User::factory()->load($document);

		if ( ! $user->loaded())
		{
			// check for "remembered" login
			if (($user = $this->auto_login()) === FALSE)
			{
				return $default;
			}
		}

		return $user;
	}

	/**
	 * Log a user out and remove any autologin cookies.
	 *
	 * @param   boolean  $destroy     completely destroy the session
	 * @param	boolean  $logout_all  remove all tokens for user
	 * @return  boolean
	 */
	public function logout($destroy = FALSE, $logout_all = FALSE)
	{
		// Set by force_login()
		$this->_session->delete('auth_forced');

		if ($token = Cookie::get('authautologin'))
		{
			// Delete the autologin cookie to prevent re-login
			Cookie::delete('authautologin');

			// Clear the autologin token from the database
			$token = ODM::factory('User_Token', array('token' => $token));

			if ($token->loaded() AND $logout_all)
			{
				// Delete all user tokens.
				$tokens = Model_User_Token::factory()
					->where('user','=',$token->user)
					->remove();
			}
			elseif ($token->loaded())
			{
				$token->remove();
			}
		}

		return parent::logout($destroy);
	}

	/**
	 * Get the stored password for a username.
	 *
	 * @param   mixed   $user  username string, or user ODM object
	 * @return  string
	 */
	public function password($user)
	{
		if ( ! is_object($user))
		{
			$username = $user;

			// Load the user
			$user = Model_User::factory();
			$user->opearion($user->unique_key($username), '=', $username)->find();
		}

		return $user->password;
	}

	/**
	 * Complete the login for a user by incrementing the logins and setting
	 * session data: user_id, username, roles.
	 *
	 * @param   object  $user  user ODM object
	 * @return  void
	 */
	protected function complete_login($user)
	{
		$user->complete_login();

		// Regenerate session_id
		$this->_session->regenerate();

		// Store username in session
		$this->_session->set($this->_config['session_key'], $user->as_array());

		return TRUE;
	}

	/**
	 * Compare password with original (hashed). Works for current (logged in) user
	 *
	 * @param   string  $password
	 * @return  boolean
	 */
	public function check_password($password)
	{
		$user = $this->get_user();

		if ( ! $user)
		{
			return FALSE;
		}

		return ($this->hash($password) === $user->password);
	}

	/**
	 * Hash password
	 *
	 * @param  string $str the password string
	 * @return string the password hash
	 */
	public function hash($str)
	{
		return password_hash($str, PASSWORD_DEFAULT);
	}

	/**
	 * Verify password
	 *
	 * @param  string $str  the password string
	 * @param  string $hash the password hash
	 * @return bool         true on success
	 */
	public function verify($str, $hash)
	{
		return password_verify($str, $hash);
	}

}
