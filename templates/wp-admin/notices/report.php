<?php
if (!defined('ABSPATH')) die('No direct access allowed');
?>
<div style="max-width: 700px; border: 1px solid; border-radius: 4px; font-size:110%; line-height: 110%; padding:8px; margin: 6px 0 12px; clear:left;">
<strong><?php
	if (!empty($prefix)) echo esc_html($prefix).' ';
	echo esc_html($title);
?>
<?php
	echo wp_kses_post($text);

	if (isset($discount_code)) echo ' <b>'.esc_html($discount_code).'</b>';
	
	if (!empty($button_link) && (!empty($button_meta) || !empty($button_text))) {
?>
<a class="backupplus_notice_link" href="<?php echo esc_attr(apply_filters('backupplus_com_link', $button_link));?>"><?php
global $backupplus_admin;
$backupplus_admin->include_template(
	'wp-admin/notices/button-label.php',
	false,
	array(
		'button_meta' => isset($button_meta) ? $button_meta : '',
		'button_text' => isset($button_text) ? $button_text : ''
	)
);
?>
	<?php }
?>
</div>
