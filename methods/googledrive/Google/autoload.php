<?php

if (!defined('ABSPATH')) die('No direct access allowed');
/*
 * Copyright 2014 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// Modified by the BackupPlus project in 2026.

function backupplus_google_api_php_client_autoload($className) {

  // BackupPlus prefixes the classes of this copy, so that it never loads, or is replaced by, another plugin's copy.
  if (0 !== strpos($className, 'BackupPlus_Google_')) {
    return;
  }
  $classPath = explode('_', substr($className, strlen('BackupPlus_')));

  // Drop 'Google', and maximum class file path depth in this project is 3.
  $classPath = array_slice($classPath, 1, 2);

  $filePath = dirname(__FILE__) . '/' . implode('/', $classPath) . '.php';
  if (file_exists($filePath)) {
    require_once($filePath);
  }
}

if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
	// Use the 'prepend' parameter; if other tools have registered autoloaders for incompatible versions of the Google SDK, ours should still get loaded first (since we only register our autoloader late, immediately before using it).
	spl_autoload_register('backupplus_google_api_php_client_autoload', true, true);
} else {
	spl_autoload_register('backupplus_google_api_php_client_autoload');
}
