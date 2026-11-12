<?php
/**
 * BPS-20: the Advanced Tools PHP information shows no secrets. Constants and phpinfo() rows whose name looks like a secret
 * are masked, sections that list the request environment are removed, credentials in URLs are masked, and the values of
 * secret constants and environment variables are masked wherever else they appear. Everything else is kept.
 *
 * scripts/check-admin-hardening.php checks the phpinfo command itself in WordPress.
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Diagnostics_Redaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class DiagnosticsRedactionTest extends TestCase {

	protected function setUp(): void {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
		require dirname( __DIR__, 3 ) . '/includes/class-diagnostics-redaction.php';
	}

	public static function names(): array {
		return array(
			'DB_PASSWORD'                 => array( 'DB_PASSWORD', true ),
			'WORDPRESS_DB_PASSWORD'       => array( 'WORDPRESS_DB_PASSWORD', true ),
			'AUTH_KEY'                    => array( 'AUTH_KEY', true ),
			'SECURE_AUTH_SALT'            => array( 'SECURE_AUTH_SALT', true ),
			'NONCE_SALT'                  => array( 'NONCE_SALT', true ),
			'BACKUPPLUS_CREDENTIAL_KEYS'  => array( 'BACKUPPLUS_CREDENTIAL_KEYS', true ),
			'AWS_SECRET_ACCESS_KEY'       => array( 'AWS_SECRET_ACCESS_KEY', true ),
			'GITHUB_TOKEN'                => array( 'GITHUB_TOKEN', true ),
			'PHP_AUTH_PW'                 => array( 'PHP_AUTH_PW', true ),
			'HTTP_AUTHORIZATION'          => array( 'HTTP_AUTHORIZATION', true ),
			'HTTP_COOKIE'                 => array( 'HTTP_COOKIE', true ),
			'Authorization header'        => array( 'Authorization', true ),
			'mysqli.default_pw'           => array( 'mysqli.default_pw', true ),
			'FTP_PASS'                    => array( 'FTP_PASS', true ),
			'REDIS_PASSPHRASE'            => array( 'REDIS_PASSPHRASE', true ),
			'newrelic.license'            => array( 'newrelic.license', true ),
			'DB_USER'                     => array( 'DB_USER', false ),
			'DB_HOST'                     => array( 'DB_HOST', false ),
			'PWD'                         => array( 'PWD', false ),
			'memory_limit'                => array( 'memory_limit', false ),
			'session.cookie_path'         => array( 'session.cookie_path', false ),
			'Tokenizer Support'           => array( 'Tokenizer Support', false ),
			'Password hashing algorithms' => array( 'Password hashing algorithms', false ),
			'OPENSSL_KEYTYPE_RSA'         => array( 'OPENSSL_KEYTYPE_RSA', false ),
		);
	}

	#[DataProvider( 'names' )]
	public function test_secret_names( string $name, bool $secret ): void {
		$this->assertSame( $secret, BackupPlus_Diagnostics_Redaction::is_secret_name( $name ) );
	}

	public function test_constants_are_masked_by_name(): void {
		define( 'BPS20_TEST_PASSWORD', 'constant-password-value' );
		define( 'BPS20_TEST_AUTH_KEY', 'constant-auth-key-value' );
		define( 'BPS20_TEST_LIST', array( 'api_key' => 'listed-api-key' ) );
		define( 'BPS20_TEST_PLAIN', 'plain-visible-value' );
		define( 'BPS20_TEST_KEY', 42 );
		$constants = BackupPlus_Diagnostics_Redaction::constants();
		$this->assertSame( '***', $constants['BPS20_TEST_PASSWORD'] );
		$this->assertSame( '***', $constants['BPS20_TEST_AUTH_KEY'] );
		$this->assertSame( '***', $constants['BPS20_TEST_LIST'] );
		$this->assertSame( 'plain-visible-value', $constants['BPS20_TEST_PLAIN'] );
		$this->assertSame( '42', $constants['BPS20_TEST_KEY'], 'Numbers are not secrets' );
		$this->assertSame( PHP_VERSION, $constants['PHP_VERSION'] );
		$names = array_keys( $constants );
		$this->assertSame( $names, array_values( array_unique( $names ) ) );
	}

	public function test_secret_entries_of_array_constants_are_masked(): void {
		define(
			'WP_REDIS_CONFIG',
			array(
				'token'    => 'object-cache-licence-token',
				'host'     => 'redis.internal',
				'servers'  => array( array( 'password' => 'nested-redis-password' ) ),
				'database' => 0,
			)
		);
		$text = BackupPlus_Diagnostics_Redaction::constants()['WP_REDIS_CONFIG'];
		$this->assertStringNotContainsString( 'object-cache-licence-token', $text );
		$this->assertStringNotContainsString( 'nested-redis-password', $text );
		$this->assertStringContainsString( 'redis.internal', $text, 'Other settings stay visible' );
		$values = BackupPlus_Diagnostics_Redaction::secret_values();
		$this->assertContains( 'object-cache-licence-token', $values, 'Secret entries are masked wherever else they appear' );
		$this->assertContains( 'nested-redis-password', $values );
		$this->assertNotContains( 'redis.internal', $values );
	}

	public function test_secret_values_come_from_the_site_and_environment(): void {
		define( 'BPS20_VALUES_PASSWORD', 'site-constant-password' );
		define( 'BPS20_VALUES_SHORT_PASSWORD', 'short' );
		putenv( 'BPS20_VALUES_TOKEN=environment-token-value' );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer server-authorization-value';
		$_SERVER['BPS20_VALUES_PLAIN'] = 'plain-server-value';
		$values                        = BackupPlus_Diagnostics_Redaction::secret_values();
		$this->assertContains( 'site-constant-password', $values );
		$this->assertContains( 'environment-token-value', $values );
		$this->assertContains( 'Bearer server-authorization-value', $values );
		$this->assertNotContains( 'short', $values, 'Short values are only masked by name' );
		$this->assertNotContains( 'plain-server-value', $values );
		$this->assertNotContains( PASSWORD_DEFAULT, $values, "PHP's own constants are masked by name only" );
	}

	public function test_html_phpinfo(): void {
		$html   = <<<'HTML'
<h1 class="p">PHP Version 8.5.0</h1>
<table>
<tr><td class="e">System </td><td class="v">Linux host </td></tr>
<tr><td class="e">Configure Command </td><td class="v">./configure --with-site-secret=html-leak-1234 </td></tr>
</table>
<h2><a name="module_apache2handler">apache2handler</a></h2>
<table>
<tr><td class="e">Server Root </td><td class="v">/etc/apache2 </td></tr>
</table>
<h2>Apache Environment</h2>
<table>
<tr class="h"><th>Variable</th><th>Value</th></tr>
<tr><td class="e">SITE_ENV_VALUE </td><td class="v">apache-environment-leak </td></tr>
</table>
<h2>HTTP Headers Information</h2>
<table>
<tr class="h"><th colspan="2">HTTP Request Headers</th></tr>
<tr><td class="e">Cookie </td><td class="v">wordpress_logged_in_abc=cookie-leak </td></tr>
</table>
<h2><a name="module_mysqli">mysqli</a></h2>
<table>
<tr class="h"><th>Directive</th><th>Local Value</th><th>Master Value</th></tr>
<tr><td class="e">mysqli.default_pw</td><td class="v">ini-password-leak</td><td class="v">ini-password-leak</td></tr>
<tr><td class="e">mysqli.default_user</td><td class="v">dbuser</td><td class="v">dbuser</td></tr>
</table>
<h2><a name="module_session">session</a></h2>
<table>
<tr><td class="e">session.save_path</td><td class="v">tcp://redis:6379?weight=1&amp;auth=redis-leak</td><td class="v">tcp://user:url-leak@redis:6379</td></tr>
<tr><td class="e">session.name</td><td class="v">PHPSESSID</td><td class="v">PHPSESSID</td></tr>
</table>
<h2>Environment</h2>
<table>
<tr><td class="e">BACKUPPLUS_CREDENTIAL_KEYS </td><td class="v">environment-key-leak </td></tr>
</table>
<h2>PHP Variables</h2>
<table>
<tr><td class="e">$_SERVER['HTTP_COOKIE']</td><td class="v">variables-cookie-leak</td></tr>
</table>
<table>
<tr><td class="e">Tokenizer Support </td><td class="v">enabled </td></tr>
</table>
HTML;
		$output = BackupPlus_Diagnostics_Redaction::phpinfo( $html, array( 'html-leak-1234' ) );
		foreach ( array( 'html-leak-1234', 'apache-environment-leak', 'cookie-leak', 'ini-password-leak', 'redis-leak', 'url-leak', 'environment-key-leak', 'variables-cookie-leak', 'Apache Environment', 'HTTP Headers Information', 'PHP Variables' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $output );
		}
		$this->assertStringContainsString( '<tr><td class="e">mysqli.default_pw</td><td class="v">***</td><td class="v">***</td></tr>', $output );
		$this->assertStringContainsString( '<td class="v">tcp://redis:6379?weight=1&amp;auth=***</td>', $output );
		$this->assertStringContainsString( 'tcp://user:***@redis:6379', $output );
		foreach ( array( 'PHP Version 8.5.0', 'Linux host', '/etc/apache2', 'dbuser', 'PHPSESSID', 'Tokenizer Support </td><td class="v">enabled', '--with-site-secret=***' ) as $kept ) {
			$this->assertStringContainsString( $kept, $output );
		}
	}

	public function test_text_phpinfo(): void {
		$text   = "PHP Version => 8.5.0\nmysqli.default_pw => text-password-leak => text-password-leak\nmysqli.default_user => dbuser => dbuser\nTokenizer Support => enabled\n";
		$output = BackupPlus_Diagnostics_Redaction::phpinfo( $text );
		$this->assertStringNotContainsString( 'text-password-leak', $output );
		$this->assertStringContainsString( "mysqli.default_pw => ***\n", $output );
		$this->assertStringContainsString( "mysqli.default_user => dbuser => dbuser\n", $output );
		$this->assertStringContainsString( "Tokenizer Support => enabled\n", $output );
	}

	public function test_values_are_masked_raw_and_escaped(): void {
		$value  = 'p&ss"w<o>rd\'1';
		$output = BackupPlus_Diagnostics_Redaction::mask_values( 'a ' . $value . ' b ' . htmlspecialchars( $value, ENT_QUOTES ) . ' c ' . htmlspecialchars( $value, ENT_NOQUOTES ) . ' d', array( $value, 'tiny' ) );
		$this->assertSame( 'a *** b *** c *** d', $output );
		$this->assertSame( 'a tiny b', BackupPlus_Diagnostics_Redaction::mask_values( 'a tiny b', array( 'tiny' ) ) );
	}
}
