<?php
/**
 * Fuel
 *
 * Fuel is a fast, lightweight, community driven PHP5 framework.
 *
 * @package		Fuel
 * @version		1.0
 * @author		Fuel Development Team
 * @license		MIT License
 * @copyright	2010 Dan Horrigan
 * @link		http://fuelphp.com
 */

namespace Fuel;

/**
 * The core of the framework.
 *
 * @package		Fuel
 * @subpackage	Core
 * @category	Core
 */
class Fuel {

	public static $initialized = false;

	public static $env;

	public static $bm = true;

	public static $locale;

	protected static $_paths = array();

	public static $packages = array();

	final private function __construct() { }

	/**
	 * Initializes the framework.  This can only be called once.
	 *
	 * @access	public
	 * @return	void
	 */
	public static function init($autoloaders)
	{
		if (static::$initialized)
		{
			throw new Exception("You can't initialize Fuel more than once.");
		}

		static::$_paths = array(APPPATH, COREPATH);

		// Add the core and optional application loader to the packages array
		static::$packages = $autoloaders;

		register_shutdown_function('Error::shutdown_handler');
		set_exception_handler('Error::exception_handler');
		set_error_handler('Error::error_handler');

		// Start up output buffering
		ob_start();

		Config::load('config');

		static::$bm = Config::get('benchmarking', true);
		static::$env = Config::get('environment');
		static::$locale = Config::get('locale');

		Route::$routes = Config::load('routes', true);

		//Load in the packages
		foreach (Config::get('packages', array()) as $package)
		{
			static::add_package($package);
		}

		if (Config::get('base_url') === false)
		{
			if (isset($_SERVER['SCRIPT_NAME']))
			{
				$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

				// Add a slash if it is missing
				substr($base_url, -1, 1) == '/' OR $base_url .= '/';

				Config::set('base_url', $base_url);
			}
		}

		// Set some server options
		setlocale(LC_ALL, static::$locale);

		// Set default timezone when given in config
		if (($timezone = Config::get('default_timezone', null)) != null)
		{
			date_default_timezone_set($timezone);
		}
		// ... or set it to UTC when none was set
		elseif ( ! ini_get('date.timezone'))
		{
			date_default_timezone_set('UTC');
		}

		// Clean input
		Security::clean_input();

		// Always load classes, config & language set in alwaysload.php config
		static::alwaysload();

		static::$initialized = true;
	}
	
	/**
	 * Cleans up Fuel execution, ends the output buffering, and outputs the
	 * buffer contents.
	 * 
	 * @access	public
	 * @return	void
	 */
	public static function finish()
	{
		// Grab the output buffer
		$output = ob_get_clean();

		$bm = Benchmark::app_total();

		// TODO: There is probably a better way of doing this, but this works for now.
		$output = \str_replace(
				array('{exec_time}', '{mem_usage}'),
				array(round($bm[0], 4), round($bm[1] / pow(1024, 2), 3)),
				$output
		);


		// Send the buffer to the browser.
		echo $output;
	}

	/**
	 * Finds a file in the given directory.  It allows for a cascading filesystem.
	 *
	 * @access	public
	 * @param	string	The directory to look in.
	 * @param	string	The name of the file
	 * @param	string	The file extension
	 * @return	string	The path to the file
	 */
	public static function find_file($directory, $file, $ext = '.php')
	{
		$path = $directory.DS.strtolower($file).$ext;

		$found = false;
		foreach (static::$_paths as $dir)
		{
			if (is_file($dir.$path))
			{
				$found = $dir.$path;
				break;
			}
		}
		return $found;
	}

	/**
	 * Loading in the given file
	 *
	 * @access	public
	 * @param	string	The path to the file
	 * @return	mixed	The results of the include
	 */
	public static function load($file)
	{
		return include $file;
	}

	/**
	 * Adds a package or multiple packages to the stack.
	 * 
	 * Examples:
	 * 
	 * static::add_package('foo');
	 * static::add_package(array('foo' => PKGPATH.'bar/foo/'));
	 * 
	 * @access	public
	 * @param	array|string	the package name or array of packages
	 * @return	void
	 */
	public static function add_package($package)
	{
		if ( ! is_array($package))
		{
			$package = array($package => PKGPATH.$package.DS);
		}
		foreach ($package as $name => $path)
		{
			if (array_key_exists($name, static::$packages))
			{
				continue;
			}
			static::$packages[$name] = static::load($path.'autoload.php');
		}

		// Put the APP autoloader back on top
		spl_autoload_unregister(array(static::$packages['app'], 'load'));
		spl_autoload_register(array(static::$packages['app'], 'load'), true, true);
	}

	/**
	 * Removes a package from the stack.
	 * 
	 * @access	public
	 * @param	string	the package name
	 * @return	void
	 */
	public static function remove_package($name)
	{
		spl_autoload_unregister(array(static::$packages[$name], 'load'));
		unset(static::$packages[$name]);
	}

	/**
	 * Always load classes, config & language files set in alwaysload.php config
	 */
	public static function alwaysload($array = null)
	{
		$array = is_null($array) ? require APPPATH.'config'.DS.'alwaysload.php' : $array;

		foreach ($array['classes'] as $class)
		{
			if ( ! class_exists($class))
			{
				throw new Exception('Always load class does not exist.');
			}
		}

		foreach ($array['config'] as $c_key => $config)
		{
			Config::load($config, (is_string($c_key) ? $c_key : true));
		}

		foreach ($array['language'] as $l_key => $lang)
		{
			Lang::load($lang, (is_string($l_key) ? $l_key : true));
		}
	}

	/**
	 * Cleans a file path so that it does not contain absolute file paths.
	 * 
	 * @access	public
	 * @param	string	the filepath
	 * @return	string
	 */
	public static function clean_path($path)
	{
		static $search = array(APPPATH, COREPATH, DOCROOT, '\\');
		static $replace = array('APPPATH/', 'COREPATH/', 'DOCROOT/', '/');
		return str_replace($search, $replace, $path);
	}
}

/* End of file core.php */
