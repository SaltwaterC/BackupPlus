<? // phpcs:ignore Generic.PHP.DisallowShortOpenTag.Found, Internal.NoCodeFound -- This gets replaced when we build the script
	session_start();
	set_time_limit(0);
	
	define('ROOT', dirname(__FILE__) . '/');
	// We need this so that we can access UpdraftPlus from outside of WordPress
	define('DOING_CRON', true);

	$log = array();
