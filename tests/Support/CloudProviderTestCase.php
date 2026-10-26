<?php
/** Base class for storage providers that talk to a service over HTTP, with the API contract every one of them must meet. */

namespace BackupPlus\Tests\Support;

use BackupPlus_BackupModule;

abstract class CloudProviderTestCase extends ProviderTestCase {

	/** Assertions equally applicable to any file storage provider: transfers, restores, deletion, failures, credentials and isolation. */
	protected function assert_storage_contract( string $id, $client, array $options, ?BackupPlus_BackupModule $provider = null ): void {
		$provider = $provider ?: $this->create_provider( $id );
		$provider->set_options( $options );
		$provider->set_storage( $client );
		$name     = 'backup_测试 +#%25.zip';
		$contents = "archive\0\xff\n" . str_repeat( 'data', 4096 );
		file_put_contents( $this->fixture . '/' . $name, $contents );
		$this->job->uploaded = array();
		$this->assertTrue( $provider->backup( array( 'plugins' => $name ) ), "$id must upload binary archives and mark completion" );
		$this->assertSame( array( $name ), $this->job->uploaded, "$id must upload binary archives and mark completion" );
		$this->assertTrue( in_array( 100, $this->job->progress ) || in_array( 100.0, $this->job->progress, true ), "$id must report upload progress" );
		$this->assertContains(
			array(
				'name' => $name,
				'size' => strlen( $contents ),
			),
			$provider->listfiles(),
			"$id must list names and integer sizes"
		);
		$this->assertSame( array(), $provider->listfiles( 'unmatched' ), "$id must honor listing matches" );
		file_put_contents( $this->fixture . '/' . $name, 'previous archive' );
		$this->assertTrue( $provider->download( $name ), "$id must restore the exact binary archive" );
		$this->assertSame( $contents, file_get_contents( $this->fixture . '/' . $name ), "$id must restore the exact binary archive" );
		file_put_contents( $this->fixture . '/' . $name, 'keep this archive' );
		$this->http->truncate = true;
		$this->assertFalse( $provider->download( $name ), "$id must preserve the local archive on truncated downloads" );
		$this->assertSame( 'keep this archive', file_get_contents( $this->fixture . '/' . $name ), "$id must preserve the local archive on truncated downloads" );
		$this->http->truncate = false;
		$this->assertFalse( $provider->download( 'backup_missing.zip' ), "$id must fail missing downloads" );
		$empty = 'backup_empty.zip';
		file_put_contents( $this->fixture . '/' . $empty, '' );
		$this->assertTrue( $provider->backup( array( $empty ) ), "$id must round-trip empty archives" );
		$this->assertTrue( $provider->download( $empty ), "$id must round-trip empty archives" );
		$this->assertSame( '', file_get_contents( $this->fixture . '/' . $empty ), "$id must round-trip empty archives" );
		foreach ( array( '../escape', '/absolute', 'nested/file', 'nested\\file', "bad\0name", "bad\nname" ) as $invalid ) {
			$this->assertFalse( $provider->backup( array( $invalid ) ), "$id operations must reject unsafe filenames" );
			$this->assertFalse( $provider->download( $invalid ), "$id operations must reject unsafe filenames" );
			$this->assertFalse( $provider->delete( $invalid ), "$id operations must reject unsafe filenames" );
		}
		$this->http->fail    = true;
		$this->job->uploaded = array();
		$this->assertFalse( $provider->backup( array( $name ) ), "$id must not mark failed uploads complete" );
		$this->assertSame( array(), $this->job->uploaded, "$id must not mark failed uploads complete" );
		$this->assertTrue( is_wp_error( $provider->listfiles() ), "$id must propagate service failures" );
		$this->assertFalse( $provider->download( $name ), "$id must propagate service failures" );
		$this->assertFalse( $provider->delete( $name ), "$id must propagate service failures" );
		$this->http->fail = false;
		$this->assertTrue( $provider->delete( array( $name, $empty ) ), "$id must delete arrays, strings and already missing files" );
		$this->assertTrue( $provider->delete( $name ), "$id must delete arrays, strings and already missing files" );
		$this->assertSame( array(), glob( $this->fixture . '/.backupplus-download-*' ), "$id must clean temporary downloads" );
		$template = $provider->get_configuration_template();
		foreach ( array_keys( $provider->get_default_options() ) as $field ) {
			$this->assertStringContainsString( 'data-backupplus_settings_test="' . $field . '"', $template, "$id must include $field in unsaved connection tests" );
		}
		$this->assertNotEmpty( $provider->get_template_properties()['input_test_label'], "$id must label its connection test" );
		$before = $provider->get_options();
		ob_start();
		$provider->credentials_test( $options );
		$output = ob_get_clean();
		$this->assertStringStartsWith( 'Success:', $output, "$id credential roundtrip must succeed: $output" );
		$this->assertTrue( $provider->is_connection_successful(), "$id credential roundtrip must succeed: $output" );
		$this->assertSame( $before, $provider->get_options(), "$id credential tests must preserve saved instance settings" );
		ob_start();
		$provider->credentials_test( array() );
		$output = ob_get_clean();
		$this->assertStringStartsWith( 'Failure:', $output, "$id credential failures must reset connection status" );
		$this->assertFalse( $provider->is_connection_successful(), "$id credential failures must reset connection status" );
		$provider->set_options( array_merge( $options, array( 'path' => 'another-site' ) ) );
		$this->assertEmpty( $provider->get_storage(), "$id must discard cached clients when changing instances" );
		foreach ( get_included_files() as $file ) {
			if ( 0 !== strpos( $file, BACKUPPLUS_DIR . '/methods/' ) ) {
				continue;
			}
			$relative = substr( $file, strlen( BACKUPPLUS_DIR . '/methods/' ) );
			$this->assertTrue( 'backup-module.php' === $relative || $id . '.php' === $relative || 0 === strpos( $relative, $id . '/' ), "$id must not load another provider: $relative" );
		}
	}
}
