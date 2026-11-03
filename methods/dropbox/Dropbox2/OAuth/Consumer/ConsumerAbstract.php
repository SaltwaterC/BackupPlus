<?php

/**
* Abstract OAuth consumer
* @author Ben Tadiar <ben@handcraftedbyben.co.uk>
* @link https://github.com/benthedesigner/dropbox
* @package Dropbox\OAuth
* @subpackage Consumer
*/

abstract class Dropbox_ConsumerAbstract
{
    // Dropbox web endpoint. v2 API has just dropped the 1/ suffix to the below.
    const WEB_URL = 'https://www.dropbox.com/';
    
    // Beware - the documentation in one place says oauth2/token/revoke, but that appears to be wrong
    const DEAUTHORISE_METHOD = '2/auth/token/revoke';
    
    /**
     * Signature method, either PLAINTEXT or HMAC-SHA1
     * @var string
     */
    private $sigMethod = 'PLAINTEXT';
    
    /**
     * Output file handle
     * @var null|resource
     */
    protected $outFile = null;
    
    /**
     * Input file handle
     * @var null|resource
     */
    protected $inFile = null;
    
    /**
     * Check the stored token, renewing its access token when it has expired. Authorization itself happens in
     * BackupPlus_Dropbox_PKCE, from the settings page.
     * @return void
     */
    protected function authenticate()
    {
        $access_token = $this->storage->get('access_token');
        // OAuth 1 tokens cannot be renewed: their upgrade endpoint is gone along with the API v1
        if (empty($access_token) || !isset($access_token->oauth_token) || !isset($access_token->token_type)) {
            /* translators: %s: Authentication service name (e.g., Dropbox) */
            throw new Dropbox_Exception(sprintf(__('You need to re-authenticate with %s, as your existing credentials are not working.', 'backupplus'), 'Dropbox')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The escaping should be happening when the exception is printed
        }
        if (isset($access_token->refresh_token) && isset($access_token->expires_in) && $access_token->expires_in < time()) {
            $this->refreshAccessToken();
        }
    }

    protected function deauthenticate()
    {
	    $url = BackupPlus_Dropbox_API::API_URL_V2 . self::DEAUTHORISE_METHOD;
	    $response = $this->fetch('POST', $url, '', array('api_v2' => true));
        $this->storage->delete();
    }
    
    /**
     * Renew the access token with the stored refresh token, directly with Dropbox
     *
     * @return void
     */
    public function refreshAccessToken() {
        global $backupplus;

        $access_token = $this->storage->get('access_token');
        if (empty($access_token->refresh_token)) {
            $backupplus->log('Failed to refresh access token: no refresh token is stored');
            return;
        }

        // Tokens authorized with PKCE record their app key; earlier tokens of a custom app need its key and secret
        $client_id = empty($access_token->client_id) ? $this->consumerKey : $access_token->client_id;
        $secret = empty($access_token->client_id) ? (string) $this->consumerSecret : '';

        try {
            $this->storage->set(BackupPlus_Dropbox_PKCE::refresh($access_token, $client_id, $secret), 'access_token');
            $backupplus->log('Successfully updated and refreshed the access token');
        } catch (Dropbox_Exception $e) {
            $backupplus->log('Failed to refresh access token: '.$e->getMessage());
        }
    }
    
    /**
     * Get the request/access token
     * This will return the access/request token depending on
     * which stage we are at in the OAuth flow, or a dummy object
     * if we have not yet started the authentication process
     * @return object stdClass
     */
    private function getToken()
    {
        if (!$token = $this->storage->get('access_token')) {
            if (!$token = $this->storage->get('request_token')) {
                $token = new stdClass();
                $token->oauth_token = null;
                $token->oauth_token_secret = null;
            }
        }
        return $token;
    }
    
    /**
     * Generate signed request URL
     * See inline comments for description
     * @link http://tools.ietf.org/html/rfc5849#section-3.4
     * @param string $method HTTP request method
     * @param string $url API endpoint to send the request to
     * @param string $call API call to send
     * @param array $additional Additional parameters as an associative array
     * @return array
     */
    protected function getSignedRequest($method, $url, $call, array $additional = array())
    {
        // Get the request/access token
        $token = $this->getToken();

        // Prepare the standard request parameters differently for OAuth1 and OAuth2; we still need OAuth1 to make the request to the upgrade token endpoint
        if (isset($token->token_type)) {
	        $params = array(
	        	'access_token' => $token->oauth_token,
	        );

            /*
                To keep this API backwards compatible with the API v1 endpoints all v2 endpoints will also send to this method a api_v2 parameter this will then return just the access token as the signed request is not needed for any calls.
             */

            if (isset($additional['api_v2']) && $additional['api_v2'] == true) {
				unset($additional['api_v2']);
				if (isset($additional['timeout'])) unset($additional['timeout']);
                if (isset($additional['content_download']) && $additional['content_download'] == true) {
                    unset($additional['content_download']);
                    $extra_headers = array();
                    if (isset($additional['headers'])) {
                        foreach ($additional['headers'] as $key => $header) {
                            $extra_headers[] = $header;
                        }
                        unset($additional['headers']);
                    }
                    $headers = array(
                        'Authorization: Bearer '.$params['access_token'],
                        'Content-Type:',
                        'Dropbox-API-Arg: '.json_encode($additional),
                    );

                    $headers = array_merge($headers, $extra_headers);
                    $additional = '';
                } else if (isset($additional['content_upload']) && $additional['content_upload'] == true) {
                    unset($additional['content_upload']);
                    $headers = array(
                        'Authorization: Bearer '.$params['access_token'],
                        'Content-Type: application/octet-stream',
                        'Dropbox-API-Arg: '.json_encode($additional),
                    );
                    $additional = '';
                } else {
                    $headers = array(
                        'Authorization: Bearer '.$params['access_token'],
                        'Content-Type: application/json',
                    );
                }
                return array(
                    'url' => $url . $call,
                    'postfields' => $additional,
                    'headers' => $headers,
                );
            }
        } else {
	        // Generate a random string for the request
	        $nonce = md5(microtime(true) . uniqid('', true));
	        $params = array(
	            'oauth_consumer_key' => $this->consumerKey,
	            'oauth_token' => $token->oauth_token,
	            'oauth_signature_method' => $this->sigMethod,
	            'oauth_version' => '1.0',
	            // Generate nonce and timestamp if signature method is HMAC-SHA1 
	            'oauth_timestamp' => ($this->sigMethod == 'HMAC-SHA1') ? time() : null,
	            'oauth_nonce' => ($this->sigMethod == 'HMAC-SHA1') ? $nonce : null,
	        );
	    }
    
        // Merge with the additional request parameters
        $params = array_merge($params, $additional);
        ksort($params);
    
        // URL encode each parameter to RFC3986 for use in the base string
        $encoded = array();
        foreach($params as $param => $value) {
            if ($value !== null) {
                // If the value is a file upload (prefixed with @), replace it with
                // the destination filename, the file path will be sent in POSTFIELDS
                if (isset($value[0]) && $value[0] === '@') $value = $params['filename'];
                # Prevent spurious PHP warning by only doing non-arrays
                if (!is_array($value)) $encoded[] = $this->encode($param) . '=' . $this->encode($value);
            } else {
                unset($params[$param]);
            }
        }
        
        // Build the first part of the string
        $base = $method . '&' . $this->encode($url . $call) . '&';
        
        // Re-encode the encoded parameter string and append to $base
        $base .= $this->encode(implode('&', $encoded));

        // Concatenate the secrets with an ampersand
        $key = $this->consumerSecret . '&' . $token->oauth_token_secret;
        
        // Get the signature string based on signature method
        $signature = $this->getSignature($base, $key);
        $params['oauth_signature'] = $signature;
        
        // Build the signed request URL
        $query = '?' . http_build_query($params, '', '&');
        
        return array(
            'url' => $url . $call . $query,
            'postfields' => $params,
        );
    }
    
    /**
     * Generate the oauth_signature for a request
     * @param string $base Signature base string, used by HMAC-SHA1
     * @param string $key Concatenated consumer and token secrets
     */
    private function getSignature($base, $key)
    {
        switch ($this->sigMethod) {
            case 'PLAINTEXT':
                $signature = $key;
                break;
            case 'HMAC-SHA1':
                $signature = base64_encode(hash_hmac('sha1', $base, $key, true));
                break;
        }
        
        return $signature;
    }
    
    /**
     * Set the OAuth signature method
     * @param string $method Either PLAINTEXT or HMAC-SHA1
     * @return void
     */
    public function setSignatureMethod($method)
    {
        $method = strtoupper($method);
        
        switch ($method) {
            case 'PLAINTEXT':
            case 'HMAC-SHA1':
                $this->sigMethod = $method;
                break;
            default:
                throw new Dropbox_Exception('Unsupported signature method ' . $method); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The escaping should be happening when the exception is printed
        }
    }
    
    /**
     * Set the output file
     * @param resource Resource to stream response data to
     * @return void
     */
    public function setOutFile($handle)
    {
        if (!is_resource($handle) || get_resource_type($handle) != 'stream') {
            throw new Dropbox_Exception('Outfile must be a stream resource');
        }
        $this->outFile = $handle;
    }
    
    /**
     * Set the input file
     * @param resource Resource to read data from
     * @return void
     */
    public function setInFile($handle) {
        $this->inFile = $handle;
    }
    
    /**
    * Parse response parameters for a token into an object
    * Dropbox returns tokens in the response parameters, and
    * not a JSON encoded object as per other API requests
    * @link http://oauth.net/core/1.0/#response_parameters
    * @param string $response
    * @return object stdClass
    */
    private function parseTokenString($response)
    {
        $parts = explode('&', $response);
        $token = new stdClass();
        foreach ($parts as $part) {
            list($k, $v) = explode('=', $part, 2);
            $k = strtolower($k);
            $token->$k = $v;
        }
        return $token;
    }
    
    /**
     * Encode a value to RFC3986
     * This is a convenience method to decode ~ symbols encoded
     * by rawurldecode. This will encode all characters except
     * the unreserved set, ALPHA, DIGIT, '-', '.', '_', '~'
     * @link http://tools.ietf.org/html/rfc5849#section-3.6
     * @param mixed $value
     */
    private function encode($value)
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }
}
