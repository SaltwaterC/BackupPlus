<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/**
 * Discovers provider entry points and owns all storage module construction.
 * Only files with a BackupPlus Provider header are registered; helper files are
 * never advertised as destinations. Implementations are loaded on first use.
 */
class BackupPlus_Storage_Provider_Factory {

	private static $providers;
	private static $objects = array();
	private static $errors = array();

	/** Register installed providers from their file headers, without executing them. */
	private static function discover() {
		if (null !== self::$providers) return;
		self::$providers = array();
		foreach (glob(BACKUPPLUS_DIR.'/methods/*.php') ?: array() as $file) {
			if (!is_readable($file)) continue;
			$headers = get_file_data($file, array('name' => 'BackupPlus Provider', 'id' => 'Provider ID', 'class' => 'Provider Class'));
			if (empty($headers['name']) || empty($headers['id']) || empty($headers['class'])) continue;
			if (!preg_match('/^[a-z0-9]+$/', $headers['id']) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_\\\\]*$/', $headers['class'])) continue;
			if (isset(self::$providers[$headers['id']])) continue;
			$headers['file'] = $file;
			self::$providers[$headers['id']] = $headers;
		}
		// Sort by display name, keeping the IDs associated with each provider.
		uasort(self::$providers, function($left, $right) {
			$order = strnatcasecmp($left['name'], $right['name']);
			return $order ? $order : strcmp($left['id'], $right['id']);
		});
	}

	/** @return Array Installed provider IDs mapped to their display names. */
	public static function get_methods() {
		self::discover();
		$methods = array();
		foreach (self::$providers as $id => $provider) {
			if (is_readable($provider['file']) && !isset(self::$errors[$id])) $methods[$id] = $provider['name'];
		}
		return $methods;
	}

	/** @return Boolean Whether an installed provider is registered. */
	public static function has($id) {
		$methods = self::get_methods();
		return is_string($id) && isset($methods[$id]);
	}

	/**
	 * @param String  $id     Provider ID.
	 * @param Boolean $shared Return the cached object, or create a fresh one.
	 * @return BackupPlus_BackupModule|WP_Error
	 */
	public static function create($id, $shared = true) {
		if (is_string($id) && isset(self::$errors[$id])) return self::$errors[$id];
		if (!is_string($id) || !self::has($id)) return new WP_Error('no_such_storage_class', 'The specified storage method was not found', $id);
		if ($shared && isset(self::$objects[$id])) return self::$objects[$id];
		$provider = self::$providers[$id];
		try {
			require_once BACKUPPLUS_DIR.'/methods/backup-module.php';
			require_once $provider['file'];
			$class = $provider['class'];
			if (!class_exists($class) || !is_subclass_of($class, 'BackupPlus_BackupModule') || !(new ReflectionClass($class))->isInstantiable()) {
				throw new UnexpectedValueException('Provider class must be a concrete BackupPlus_BackupModule');
			}
			$object = new $class();
			if ($object->get_id() !== $id) throw new UnexpectedValueException('Provider class ID does not match its registration');
			if ($shared) self::$objects[$id] = $object;
			return $object;
		} catch (Throwable $error) {
			// A broken optional provider must not prevent other destinations loading.
			self::$errors[$id] = new WP_Error('storage_provider_unavailable', $error->getMessage(), $id);
			return self::$errors[$id];
		}
	}
}
