<?php
/** Google Drive behavior through the bundled SDK with a fake HTTP transport. */
// The bundled SDK has pre-PHP 8.4 nullable declarations. Keep other diagnostics
// enabled so missing shims and runtime warnings still fail the tests.
error_reporting(error_reporting() & ~E_DEPRECATED);
require __DIR__.'/fixtures/provider-test-bootstrap.php';
require BACKUPPLUS_DIR.'/includes/class-manipulation-functions.php';
require BACKUPPLUS_DIR.'/methods/googledrive/Google/autoload.php';
require BACKUPPLUS_DIR.'/methods/googledrive/google-extensions.php';

class Provider_Test_Google_IO extends UDP_Google_IO_Curl {
	public $requests = array();
	public $handler;
	public function makeRequest(UDP_Google_Http_Request $request) {
		$this->requests[] = $request;
		list($code, $headers, $body) = ($this->handler)($request);
		$request->setResponseHttpCode($code);
		$request->setResponseHeaders($headers);
		$request->setResponseBody($body);
		return $request;
	}
}

$provider = provider_create('googledrive');
provider_expect('no_settings' === $provider->listfiles()->get_error_code(), 'Google Drive listing must reject missing authentication');
provider_expect(is_wp_error($provider->backup(array('backup.zip'))) && false === $provider->download('backup.zip') && 'service_unavailable' === $provider->delete('backup.zip'), 'Google Drive operations must handle missing authentication');
$options = array('clientid' => 'test-client', 'secret' => 'test-secret', 'token' => 'test-refresh', 'parentid' => 'test-folder');
$provider->set_options($options);
$client = new UDP_Google_Client();
$client->setAccessToken(json_encode(array('access_token' => 'test-access', 'created' => time(), 'expires_in' => 3600)));
$io = new Provider_Test_Google_IO($client);
$client->setIo($io);
$service = new UDP_Google_Service_Drive($client);
$provider->set_storage($service);
// bootstrap normally sets both fields. Inject the client alongside the cached
// SDK service so the actual private upload and download engines are exercised.
$property = new ReflectionProperty($provider, 'client');
if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
$property->setValue($provider, $client);

$name = 'backup_binary.zip';
$contents = "Google Drive archive\0\xff";
$failure = null;
$upload = '';
$deleted = array();
$listing_pages = array();
$io->handler = function($request) use ($name, $contents, &$failure, &$upload, &$deleted, &$listing_pages) {
	$url = $request->getUrl();
	$method = $request->getRequestMethod();
	$headers = array('content-type' => 'application/json');
	if (false !== strpos($url, '/about')) return array(200, $headers, json_encode(array('quotaBytesTotal' => '10485760', 'quotaBytesUsed' => '0')));
	if ('POST' === $method && false !== strpos($url, '/upload/')) {
		$metadata = json_decode($request->getPostBody(), true);
		provider_expect($name === $metadata['title'] && 'test-folder' === $metadata['parents'][0]['id'], 'Google Drive uploads must use the archive name and configured parent folder');
		return array(200, array('location' => 'https://upload.example.test/session'), '');
	}
	if ('PUT' === $method && 'https://upload.example.test/session' === $url) {
		if ('upload' === $failure) throw new UDP_Google_IO_Exception('Upload unavailable');
		if (0 === strpos($request->getRequestHeader('content-range'), 'bytes */')) {
			return array(308, array('range' => 'bytes=0-'.(strlen($upload) - 1)), '');
		}
		provider_expect(0 === strpos($request->getRequestHeader('content-range'), 'bytes '.strlen($upload).'-'), 'Google Drive must upload from the server-reported offset');
		$upload .= $request->getPostBody();
		return array(200, $headers, json_encode(array('id' => 'file-id', 'title' => $name)));
	}
	if ('GET' === $method && 'https://download.example.test/file' === $url) {
		if ('download' === $failure) return array(403, $headers, '{}');
		$range = $request->getRequestHeader('range');
		if ($range && preg_match('/bytes=(\d+)-(\d+)/', $range, $matches)) {
			return array(206, array(), substr($contents, $matches[1], $matches[2] - $matches[1] + 1));
		}
		return array(200, array(), $contents);
	}
	if ('DELETE' === $method) {
		if ('delete' === $failure) return array(403, $headers, json_encode(array('error' => array('code' => 403, 'message' => 'Deletion denied'))));
		$deleted[] = basename(parse_url($url, PHP_URL_PATH));
		return array(204, array(), '');
	}
	if ('GET' === $method && false !== strpos($url, '/files')) {
		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		provider_expect(false !== strpos($query['q'], '"test-folder" in parents and trashed = false'), 'Google Drive listing must constrain the parent folder and exclude trash');
		$listing_pages[] = $query['pageToken'] ?? '';
		if (empty($query['pageToken'])) {
			return array(200, $headers, json_encode(array('items' => array(
				array('id' => 'file-id', 'title' => $name, 'fileSize' => (string) strlen($contents), 'downloadUrl' => 'https://download.example.test/file'),
				array('id' => 'unmatched', 'title' => 'unrelated.zip', 'fileSize' => '12'),
			), 'nextPageToken' => 'page-two')));
		}
		provider_expect('page-two' === $query['pageToken'], 'Google Drive must send the server pagination token');
		return array(200, $headers, json_encode(array('items' => array(array('id' => 'second-id', 'title' => 'backup_second.zip', 'fileSize' => '42')))));
	}
	throw new RuntimeException('Unexpected Google request: '.$method.' '.$url);
};

$files = $provider->listfiles();
provider_expect(array(array('name' => $name, 'size' => (string) strlen($contents)), array('name' => 'backup_second.zip', 'size' => '42')) === $files, 'Google Drive must collect every listing page and filter filenames');
provider_expect(array('', 'page-two') === $listing_pages, 'Google Drive listing must traverse both pages');
provider_expect(array() === $provider->listfiles('missing'), 'Google Drive must honor a custom filename match');
file_put_contents($fixture.'/'.$name, $contents);
$provider->backup(array($name));
provider_expect($contents === $upload && array($name) === $backupplus->uploaded, 'Google Drive must upload binary contents through its resumable engine and record success');
provider_expect(null === $provider->jobdata_get('resume_'.md5($fixture.'/'.$name)), 'Google Drive must clear completed upload state');
provider_expect(in_array(100.0, $backupplus->progress, true) && !$client->shouldDefer(), 'Google Drive must record progress and restore the SDK request mode');

$upload = substr($contents, 0, 5);
$backupplus->uploaded = array();
$resume_key = 'resume_'.md5($fixture.'/'.$name);
$provider->jobdata_set($resume_key, array('https://upload.example.test/session', 2));
$provider->backup(array($name));
provider_expect($contents === $upload && array($name) === $backupplus->uploaded && null === $provider->jobdata_get($resume_key), 'Google Drive must recover an interrupted upload using the server offset and clear its session');

// Exercise the restore engine with both a fresh file and an existing prefix.
unlink($fixture.'/'.$name);
provider_expect(true === $provider->download($name) && $contents === file_get_contents($fixture.'/'.$name), 'Google Drive must download the complete archive');
file_put_contents($fixture.'/'.$name, substr($contents, 0, 5));
clearstatcache();
provider_expect(true === $provider->download($name) && $contents === file_get_contents($fixture.'/'.$name), 'Google Drive must resume a partial archive');
$request_count = count($io->requests);
provider_expect(true === $provider->download($name) && count($io->requests) === $request_count + 2, 'An already downloaded Google Drive archive must need only metadata listing requests');
provider_expect(false === $provider->download('backup_missing.zip'), 'Google Drive must fail a missing archive');
$failure = 'download';
unlink($fixture.'/'.$name);
clearstatcache();
provider_expect(false === $provider->download($name), 'Google Drive must fail rejected HTTP downloads');
$failure = 'upload';
file_put_contents($fixture.'/'.$name, $contents);
$backupplus->uploaded = array();
$provider->backup(array($name));
provider_expect(array() === $backupplus->uploaded && !$client->shouldDefer(), 'Google Drive must not mark failed uploads complete and must reset deferred mode');
$failure = 'delete';
provider_expect('file_delete_error' === $provider->delete($name), 'Google Drive must report deletion failures');
$failure = null;
provider_expect(true === $provider->delete(array($name)) && array('file-id') === $deleted, 'Google Drive must delete only the requested file ID');
$provider->set_options(array_merge($options, array('parentid' => 'other-folder')));
provider_expect(empty($provider->get_storage()), 'Google Drive must clear its cached service when changing instances');
echo "PASS: Google Drive authentication, paginated listing, resumable uploads, restore downloads, deletion and failures\n";
