<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<h2 class="nav-tab-wrapper">
<?php
foreach ( $main_tabs as $tab_slug => $tab_label ) {
	$tab_slug_as_attr = sanitize_title( $tab_slug );
	$tab_url          = add_query_arg( 'tab', $tab_slug, BackupPlus::get_current_clean_url() );
	?>
	<a class="backupplus-nav-tab 
	<?php
	if ( $tabflag == $tab_slug ) {
		echo 'nav-tab-active';}
	?>
	" id="backupplus-navtab-<?php echo esc_attr( $tab_slug_as_attr ); ?>" href="<?php echo esc_url( $tab_url ); ?>" ><?php echo esc_html( $tab_label ); ?></a>
	<?php
}
?>
</h2>
