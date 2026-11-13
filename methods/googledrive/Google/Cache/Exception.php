<?php
/*
 * Copyright 2013 Google Inc.
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

if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('BackupPlus_Google_Client')) {
  require_once dirname(__FILE__) . '/../autoload.php';
}

class BackupPlus_Google_Cache_Exception extends BackupPlus_Google_Exception
{
}
