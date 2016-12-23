<?php

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed');

$tick = UPDRAFTPLUS_URL.'/images/updraft_tick.png';
$cross = UPDRAFTPLUS_URL.'/images/updraft_cross.png';
$freev = UPDRAFTPLUS_URL.'/images/updraft_freev.png';
$premv = UPDRAFTPLUS_URL.'/images/updraft_premv.png';

?>
<div>
	<h2>UpdraftPlus Premium</h2>
	<p>
		<span class="premium-upgrade-prompt"><?php _e('You are currently using the free version of UpdraftPlus from wordpress.org.', 'updraftplus');?> <a href="<?php echo apply_filters('updraftplus_com_link', "https://updraftplus.com/support/installing-updraftplus-premium-your-add-on/");?>"><br><?php echo __('If you have made a purchase from UpdraftPlus.Com, then follow this link to the instructions to install your purchase.', 'updraftplus').' '.__('The first step is to de-install the free version.', 'updraftplus')?></a></span>
		<ul class="updraft_premium_description_list">
			<li><a href="<?php echo apply_filters('updraftplus_com_link', "https://updraftplus.com/shop/updraftplus-premium/");?>"><strong><?php _e('Get UpdraftPlus Premium', 'updraftplus');?></strong></a></li>
			<li><a href="<?php echo apply_filters('updraftplus_com_link', "https://updraftplus.com/updraftplus-full-feature-list/");?>"><?php _e('Full feature list', 'updraftplus');?></a></li>
			<li><a href="<?php echo apply_filters('updraftplus_com_link', "https://updraftplus.com/faq-category/general-and-pre-sales-questions/");?>"><?php _e('Pre-sales FAQs', 'updraftplus');?></a></li>
			<li class="last"><a href="<?php echo apply_filters('updraftplus_com_link', "https://updraftplus.com/ask-a-pre-sales-question/");?>"><?php _e('Ask a pre-sales question', 'updraftplus');?></a> - <a href="<?php echo apply_filters('updraftplus_com_link', "https://updraftplus.com/support/");?>"><?php _e('Support', 'updraftplus');?></a></li>
		</ul> 
	</p>
</div>
<div>
	<table class="updraft_feat_table">
		<tr>
			<th class="updraft_feat_th" style="text-align:left;"></th>
			<th class="updraft_feat_th"><img src="<?php echo $freev;?>" height="120"></th>
			<th class="updraft_feat_th" style='background-color:#DF6926;'><a href="<?php echo apply_filters('updraftplus_com_link', "https://updraftplus.com/shop/updraftplus-premium/");?>"><img src="<?php echo $premv;?>"  height="120"></a></th>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Get it from', 'updraftplus');?></td>
			<td class="updraft_tick_cell" style="vertical-align:top; line-height: 120%; margin-top:6px; padding-top:6px;">WordPress.Org</td>
			<td class="updraft_tick_cell" style="padding: 6px; line-height: 120%;">
				UpdraftPlus.Com<br>
				<a href="<?php echo apply_filters('updraftplus_com_link', "https://updraftplus.com/shop/updraftplus-premium/");?>"><strong><?php _e('Buy It Now!', 'updraftplus');?></strong></a><br>
				</td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Backup WordPress files and database', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php echo sprintf(__('Translated into over %s languages', 'updraftplus'), 16);?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Restore from backup', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Backup to remote storage', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Dropbox, Google Drive, FTP, S3, Rackspace, Email', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('WebDAV, Copy.Com, SFTP/SCP, encrypted FTP', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Microsoft OneDrive, Microsoft Azure, Google Cloud Storage', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Free 1GB for UpdraftPlus Vault', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Backup extra files and databases', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Migrate / clone (i.e. copy) websites', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Basic email reporting', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Advanced reporting features', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Automatic backup when updating WP/plugins/themes', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Send backups to multiple remote destinations', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Database encryption', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Restore backups from other plugins', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('No advertising links on UpdraftPlus settings page', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Scheduled backups', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Fix backup time', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Network/Multisite support', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Lock settings access', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
		<tr>
			<td class="updraft_feature_cell"><?php _e('Personal support', 'updraftplus');?></td>
			<td class="updraft_tick_cell"><img src="<?php echo $cross;?>"></td>
			<td class="updraft_tick_cell"><img src="<?php echo $tick;?>"></td>
		</tr>
	</table>
</div>


