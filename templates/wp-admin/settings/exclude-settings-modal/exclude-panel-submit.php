<?php
if (!defined('ABSPATH')) die('No direct access allowed');
?>
<br>
<input type="button" class="backupplus-exclude-submit button-primary" value="<?php echo esc_attr(empty($text_button) ? __('Add an exclusion rule', 'backupplus') : $text_button);?>" data-panel="<?php echo esc_attr($panel);?>"/>
