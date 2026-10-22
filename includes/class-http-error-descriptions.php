<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access.' );
}

class BackupPlus_HTTP_Error_Descriptions {


	/**
	 * Get HTTP response code description
	 *
	 * @param String $http_error_code The http error code
	 *
	 * @return String
	 */
	public static function get_http_status_code_description( $http_error_code ) {

		$http_error_code_descriptions = array(

			/*
			3xx redirection
			This class of status code indicates the client must take additional action to complete the request. Many of these status codes are used in URL redirection.
			A user agent may carry out the additional action with no user interaction only if the method used in the second request is GET or HEAD. A user agent may automatically redirect a request. A user agent should detect and intervene to prevent cyclical redirects.
			*/
			300 => __( 'Multiple Choices.', 'backupplus' ) . ' ' . __( 'Indicates multiple options for the resource from which the client may choose (via agent-driven content negotiation).', 'backupplus' ),
			301 => __( 'Moved Permanently.', 'backupplus' ) . ' ' . __( 'This and all future requests should be directed to the given URI.', 'backupplus' ),
			302 => __( 'Found (Previously "Moved temporarily").', 'backupplus' ) . ' ' . __( 'Tells the client to look at (browse to) another URL.', 'backupplus' ),
			303 => __( 'See Other.', 'backupplus' ) . ' ' . __( 'The response to the request can be found under another URI using the GET method.', 'backupplus' ) . ' ' . __( 'When received in response to a POST (or PUT/DELETE), the client should presume that the server has received the data and should issue a new GET request to the given URI', 'backupplus' ),
			304 => __( 'Not Modified.', 'backupplus' ) . ' ' . __( 'Indicates that the resource has not been modified since the version specified by the request headers If-Modified-Since or If-None-Match.', 'backupplus' ),
			305 => __( 'Use Proxy.', 'backupplus' ) . ' ' . __( 'The requested resource is available only through a proxy, the address for which is provided in the response.', 'backupplus' ),
			307 => __( 'Temporary Redirect.', 'backupplus' ) . ' ' . __( 'In this case, the request should be repeated with another URI; however, future requests should still use the original URI.', 'backupplus' ),
			308 => __( 'Permanent Redirect.', 'backupplus' ) . ' ' . __( 'This and all future requests should be directed to the given URI.', 'backupplus' ),

			/*
			4xx client errors
			This class of status code is intended for situations in which the error seems to have been caused by the client. Except when responding to a HEAD request, the server should include an entity containing an explanation of the error situation, and whether it is a temporary or permanent condition. These status codes are applicable to any request method. User agents should display any included entity to the user.
			*/
			400 => __( 'Bad Request.', 'backupplus' ) . ' ' . __( 'The server cannot or will not process the request due to an apparent client error (e.g., malformed request syntax, size too large, invalid request message framing, or deceptive request routing).', 'backupplus' ),
			401 => __( 'Unauthorized.', 'backupplus' ) . ' ' . __( 'Authentication is required and has failed or has not yet been provided.', 'backupplus' ),
			403 => __( 'Forbidden.', 'backupplus' ) . ' ' . __( 'The request contained valid data and was understood by the server, but the server is refusing action.', 'backupplus' ) . ' ' . __( 'This may be due to the user not having the necessary permissions for a resource or needing an account of some sort, or attempting a prohibited action (e.g. creating a duplicate record where only one is allowed).', 'backupplus' ),
			404 => __( 'Not Found.', 'backupplus' ) . ' ' . __( 'The requested resource could not be found but may be available in the future', 'backupplus' ) . ' ' . __( 'Subsequent requests by the client are permissible.', 'backupplus' ),
			405 => __( 'Method Not Allowed', 'backupplus' ) . ' ' . __( 'A request method is not supported for the requested resource; for example, a GET request on a form that requires data to be presented via POST, or a PUT request on a read-only resource.', 'backupplus' ),
			406 => __( 'Not Acceptable', 'backupplus' ) . ' ' . __( 'The requested resource is capable of generating only content not acceptable according to the Accept headers sent in the request.', 'backupplus' ),
			407 => __( 'Proxy Authentication Required', 'backupplus' ) . ' ' . __( 'The client must first authenticate itself with the proxy.', 'backupplus' ),
			408 => __( 'Request Timeout', 'backupplus' ) . ' ' . __( 'The server timed out waiting for the request', 'backupplus' ) . ' ' . __( 'The client MAY repeat the request without modifications at any later time.', 'backupplus' ),
			409 => __( 'Conflict', 'backupplus' ) . ' ' . __( 'Indicates that the request could not be processed because of conflict in the current state of the resource, such as an edit conflict between multiple simultaneous updates.', 'backupplus' ),
			410 => __( 'Gone', 'backupplus' ) . ' ' . __( 'Indicates that the resource requested is no longer available and will not be available again.', 'backupplus' ),
			411 => __( 'Length Required', 'backupplus' ) . ' ' . __( 'The request did not specify the length of its content, which is required by the requested resource.', 'backupplus' ),
			412 => __( 'Precondition Failed', 'backupplus' ) . ' ' . __( 'The server does not meet one of the preconditions that the requester put on the request header fields.', 'backupplus' ),
			413 => __( 'Payload Too Large', 'backupplus' ) . ' ' . __( 'The request is larger than the server is willing or able to process', 'backupplus' ) . ' ' . __( 'Previously called "Request Entity Too Large".', 'backupplus' ),
			414 => __( 'URI Too Long', 'backupplus' ) . ' ' . __( 'The URI provided was too long for the server to process', 'backupplus' ) . ' ' . __( 'Often the result of too much data being encoded as a query-string of a GET request, in which it should be converted to a POST request.', 'backupplus' ),
			415 => __( 'Unsupported Media Type', 'backupplus' ) . ' ' . __( 'The request entity has a media type which the server or resource does not support', 'backupplus' ) . ' ' . __( 'For example, the client uploads an image as image/svg+xml, but the server requires that images use a different format.', 'backupplus' ),
			416 => __( 'Range Not Satisfiable', 'backupplus' ) . ' ' . __( 'The client has asked for a portion of the file (byte serving), but the server cannot supply that portion', 'backupplus' ) . ' ' . __( 'For example, if the client asked for a part of the file that lies beyond the end of the file.', 'backupplus' ),
			417 => __( 'Expectation Failed', 'backupplus' ) . ' ' . __( 'The server cannot meet the requirements of the Expect request-header field.', 'backupplus' ),
			421 => __( 'Misdirected Request', 'backupplus' ) . ' ' . __( 'The request was directed at a server that is not able to produce a response (for example because of connection reuse).', 'backupplus' ),
			422 => __( 'Unprocessable Entity', 'backupplus' ) . ' ' . __( 'The request was well-formed but was unable to be followed due to semantic errors.', 'backupplus' ),
			423 => __( 'Locked', 'backupplus' ) . ' ' . __( 'The resource that is being accessed is locked.', 'backupplus' ),
			424 => __( 'Failed Dependency', 'backupplus' ) . ' ' . __( 'The request failed because it depended on another request and that request failed (e.g., a PROPPATCH).', 'backupplus' ),
			425 => __( 'Too Early', 'backupplus' ) . ' ' . __( 'Indicates that the server is unwilling to risk processing a request that might be replayed.', 'backupplus' ),
			426 => __( 'Upgrade Required', 'backupplus' ) . ' ' . __( 'The client should switch to a different protocol such as TLS/1.3, given in the Upgrade header field.', 'backupplus' ),
			428 => __( 'Precondition Required', 'backupplus' ) . ' ' . __( 'The origin server requires the request to be conditional', 'backupplus' ) . ' ' . __( 'Intended to prevent the \'lost update\' problem, where a client GETs a resource\'s state, modifies it, and PUTs it back to the server, when meanwhile a third party has modified the state on the server, leading to a conflict.', 'backupplus' ),
			429 => __( 'Too Many Requests', 'backupplus' ) . ' ' . __( 'The user has sent too many requests in a given amount of time', 'backupplus' ) . ' ' . __( 'Intended for use with rate-limiting schemes.', 'backupplus' ),
			431 => __( 'Request Header Fields Too Large', 'backupplus' ) . ' ' . __( 'The server is unwilling to process the request because either an individual header field, or all the header fields collectively, are too large.', 'backupplus' ),
			451 => __( 'Unavailable For Legal Reasons', 'backupplus' ) . ' ' . __( 'A server operator has received a legal demand to deny access to a resource or to a set of resources that includes the requested resource.', 'backupplus' ),

			/*
			5xx server errors
			Response status codes beginning with the digit "5" indicate cases in which the server is aware that it has encountered an error or is otherwise incapable of performing the request. Except when responding to a HEAD request, the server should include an entity containing an explanation of the error situation, and indicate whether it is a temporary or permanent condition. Likewise, user agents should display any included entity to the user. These response codes are applicable to any request method.
			*/
			500 => __( 'Internal Server Error', 'backupplus' ) . ' ' . __( 'A generic error message, given when an unexpected condition was encountered and no more specific message is suitable.', 'backupplus' ),
			501 => __( 'Not Implemented', 'backupplus' ) . ' ' . __( 'The server either does not recognize the request method, or it lacks the ability to fulfil the request', 'backupplus' ) . ' ' . __( 'Usually this implies future availability (e.g., a new feature of a web-service API).', 'backupplus' ),
			502 => __( 'Bad Gateway', 'backupplus' ) . ' ' . __( 'The server was acting as a gateway or proxy and received an invalid response from the upstream server.', 'backupplus' ),
			503 => __( 'Service Unavailable', 'backupplus' ) . ' ' . __( 'The server cannot handle the request (because it is overloaded or down for maintenance)', 'backupplus' ) . ' ' . __( 'Generally, this is a temporary state.', 'backupplus' ),
			504 => __( 'Gateway Timeout', 'backupplus' ) . ' ' . __( 'The server was acting as a gateway or proxy and did not receive a timely response from the upstream server.', 'backupplus' ),
			505 => __( 'HTTP Version Not Supported', 'backupplus' ) . ' ' . __( 'The server does not support the HTTP protocol version used in the request.', 'backupplus' ),
			506 => __( 'Variant Also Negotiates', 'backupplus' ) . ' ' . __( 'Transparent content negotiation for the request results in a circular reference.', 'backupplus' ),
			507 => __( 'Insufficient Storage', 'backupplus' ) . ' ' . __( 'The server is unable to store the representation needed to complete the request.', 'backupplus' ),
			508 => __( 'Loop Detected', 'backupplus' ) . ' ' . __( 'The server detected an infinite loop while processing the request.', 'backupplus' ),
			510 => __( 'Not Extended', 'backupplus' ) . ' ' . __( 'Further extensions to the request are required for the server to fulfil it.', 'backupplus' ),
			511 => __( 'Network Authentication Required', 'backupplus' ) . ' ' . __( 'The client needs to authenticate to gain network access', 'backupplus' ) . ' ' . __( 'Intended for use by intercepting proxies used to control access to the network (e.g., "captive portals" used to require agreement to Terms of Service before granting full Internet access via a Wi-Fi hotspot).', 'backupplus' ),
		);

		if ( isset( $http_error_code_descriptions[ $http_error_code ] ) ) {
			return $http_error_code . ' (' . $http_error_code_descriptions[ $http_error_code ] . ')';
		} else {
			return $http_error_code;
		}
	}
}
