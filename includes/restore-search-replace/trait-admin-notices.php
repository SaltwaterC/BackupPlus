<?php

/** The search and replace tool, URL change warnings and the notice about old site references. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restore_Search_Replace_Admin_Notices {

	public function adminaction_searchreplace( $options = array() ) {

		global $backupplus_restorer;

		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			echo esc_html(
				/* translators: %s: PHP version */
				sprintf( __( "The search and replace feature isn't suitable for PHP %s users.", 'backupplus' ), PHP_VERSION ) . ' ' .
				__( 'To take advantage of this feature, please upgrade your PHP version to at least 5.3.', 'backupplus' )
			) . '<br>';
			echo '<a href="' . esc_url( BackupPlus_Options::admin_page_url() . '?page=backupplus' ) . '">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
			return;
		}

		$options = wp_parse_args(
			$options,
			array(
				'show_return_link' => true,
				'show_heading'     => true,
			)
		);

		$keyword_to_search        = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'search', '' );
		$keyword_replacement_text = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'replace', '' );

		if ( ! empty( $options['show_heading'] ) ) {
			echo '<h2>' . esc_html__( 'Search / replace database', 'backupplus' ) . '</h2>';
		}
		echo '<strong>' . esc_html__( 'Search for', 'backupplus' ) . ':</strong> ' . esc_html( stripslashes( $keyword_to_search ) ) . '<br>';
		echo '<strong>' . esc_html__( 'Replace with', 'backupplus' ) . ':</strong> ' . esc_html( stripslashes( $keyword_replacement_text ) ) . '<br>';
		$pagesize           = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'pagesize' );
		$this->page_size    = ( empty( $pagesize ) || ! is_numeric( $pagesize ) ) ? 5000 : (int) $pagesize;
		$post_which_tables  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'whichtables', '' );
		$this->which_tables = empty( $post_which_tables ) ? '' : explode( ',', ( stripslashes( $post_which_tables ) ) );
		if ( empty( $keyword_to_search ) ) {
			echo sprintf(
				/* translators: %s: Missing parameter name */
				esc_html__( 'Failure: No %s was given.', 'backupplus' ),
				esc_html__( 'search term', 'backupplus' )
			) . '<br>';

			if ( ! empty( $options['show_return_link'] ) ) {
				echo '<a href="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
			}

			return;
		}

		if ( empty( $backupplus_restorer ) || ! is_a( $backupplus_restorer, 'BackupPlus_Restorer' ) ) {
			// Needed for the BackupPlus_WPDB class and BackupPlus_Restorer::sql_exec() method
			backupplus_try_include_file( 'restorer.php', 'include_once' );
			$backupplus_restorer = new BackupPlus_Restorer( null, null, true );
			add_filter( 'backupplus_logline', array( $backupplus_restorer, 'backupplus_logline' ), 10, 5 );
			$backupplus_restorer->search_replace_obj->backupplus_restore_db_pre();
		}
		$this->backupplus_restore_db_pre();
		$this->tables_replaced = array();
		$this->backupplus_restored_db_dosearchreplace( stripslashes( $keyword_to_search ), stripslashes( $keyword_replacement_text ), $this->base_prefix, false );
		if ( ! empty( $options['show_return_link'] ) ) {
			echo '<a href="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus">' . esc_html__( 'Return to BackupPlus Configuration', 'backupplus' ) . '</a>';
		}
	}

	public function debugtools_dashboard() {
		global $backupplus_admin;
		?>
		<div class="advanced_tools search_replace">
			<h3><?php esc_html_e( 'Search / replace database', 'backupplus' ); ?></h3>
			<?php if ( version_compare( PHP_VERSION, '5.3', '<' ) ) { ?>
				<p><em>
					<?php
					echo esc_html(
						/* translators: %s: PHP version */
						sprintf( __( 'This feature isn\'t suitable for PHP %s users.', 'backupplus' ), PHP_VERSION ) . ' ' .
						__( 'To take advantage of this feature, please upgrade your PHP version to at least 5.3.', 'backupplus' )
					);
					?>
				</em></p>
			<?php } else { ?>
				<p><em><?php esc_html_e( 'This can easily destroy your site; so, use it with care!', 'backupplus' ); ?></em></p>
				<form id="search_replace_form" method="post" onsubmit="return(confirm('<?php echo esc_js( __( 'A search/replace cannot be undone - are you sure you want to do this?', 'backupplus' ) ); ?>'))">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>">
				<input type="hidden" name="action" value="backupplus_broadcastaction">
				<input type="hidden" name="subaction" value="backupplus_adminaction_searchreplace">
				<table>
				<?php
					$backupplus_admin->settings_debugrow( '<label for="search">' . esc_html__( 'Search for', 'backupplus' ) . '</label>:', '<input id="search" type="text" name="search" value="" style="width:380px;">' );
					$backupplus_admin->settings_debugrow( '<label for="replace">' . esc_html__( 'Replace with', 'backupplus' ) . '</label>:', '<input id="replace" type="text" name="replace" value="" style="width:380px;">' );
					$backupplus_admin->settings_debugrow( '<label for="pagesize">' . esc_html__( 'Rows per batch', 'backupplus' ) . '</label>:', '<input id="pagesize" type="number" min="1" step="1" name="pagesize" value="5000" style="width:380px;">' );
					$backupplus_admin->settings_debugrow( '<label for="whichtables">' . esc_html__( 'These tables only', 'backupplus' ) . '</label>:', '<input id="whichtables" type="text" name="whichtables" title="' . esc_attr__( 'Enter a comma-separated list; otherwise, leave blank for all tables.', 'backupplus' ) . '" value="" style="width:380px;">' );
					$backupplus_admin->settings_debugrow( '', '<input class="button-primary search_and_replace" type="submit" value="' . esc_attr__( 'Go', 'backupplus' ) . '">' );
				?>
				</table>
			</form>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * WordPress filter backupplus_dbscan_urlchange
	 *
		 * @param String $output          - the unfiltered migration warning
	 * @param String $old_siteurl     - the old site URL
	 * @param Array  $restore_options - restoration options
	 *
	 * @return String - filtered
	 */
	public function dbscan_urlchange( $output, $old_siteurl, $restore_options ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		/* translators: %s: Old site URL */
		$msg = sprintf( __( 'This looks like a migration (the backup is from a site with a different address/URL, %s).', 'backupplus' ), htmlspecialchars( $old_siteurl ) );
		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			/* translators: %s: PHP version */
			$msg .= ' ' . sprintf( __( 'However, the search and replace feature is not suitable for the PHP version (%s) your server is running on.', 'backupplus' ), PHP_VERSION );
			$msg .= ' ' . __( 'This restoration can search and replace your database if you upgrade your PHP version to at least 5.3.', 'backupplus' );
		}
		return $msg;
	}

	/**
	 * WordPress filter backupplus_https_to_http_additional_warning
	 *
	 * @param String $output - Filter input
	 *
	 * @return String - filtered
	 */
	public function https_to_http_additional_warning( $output ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		$msg = ' ' . __( 'This restoration will work if you still have an SSL certificate (i.e. can use https) to access the site.', 'backupplus' );
		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			$msg .= ' ' . __( 'Otherwise, you will want to upgrade your PHP version to at least 5.3 and take advantage of the search and replace feature.', 'backupplus' );
		} else {
			$msg .= ' ' . __( 'Otherwise, you will want to use below search and replace to search/replace the site address so that the site can be visited without https.', 'backupplus' );
		}
		return $msg;
	}

	/**
	 * WordPress filter backupplus_http_to_https_additional_warning
	 *
	 * @param String $output - Filter input
	 *
	 * @return String - filtered
	 */
	public function http_to_https_additional_warning( $output ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		$msg = ' ' . __( 'As long as your web hosting allows http (i.e. non-SSL access) or will forward requests to https (which is almost always the case), this is no problem.', 'backupplus' );
		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			$msg .= ' ' . __( 'If that is not yet set up, then you should set it up, or upgrade your PHP version to at least 5.3 in order to access the search and replace feature, allowing for the automatic replacement of non-HTTPS links.', 'backupplus' );
		} else {
			$msg .= ' ' . __( 'If that is not yet set up, then you should set it up, or use below search and replace so that the non-https links are automatically replaced.', 'backupplus' );
		}
		return $msg;
	}

	/**
	 * WordPress filter backupplus_dbscan_urlchange_www_append_warning
	 *
	 * @param String $output - the unfiltered output (free plugin gives empty string)
	 *
	 * @return String - filtered
	 */
	public function dbscan_urlchange_www_append_warning( $output ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			/* translators: %s: PHP version */
			return sprintf( __( "This restoration can't use the search and replace feature as it's not suitable for the PHP version (%s) your server is running on.", 'backupplus' ), PHP_VERSION ) . ' ' .
			__( 'To take advantage of the feature, please upgrade your PHP version to at least 5.3.', 'backupplus' );
		} else {
			return __( 'you will want to use the search and replace option below to update the site address in the database.', 'backupplus' );
		}
	}

	/**
	 * Displays admin notice if .htaccess have any old migrated site reference.
	 */
	public function migration_admin_notices() {

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return;
		}

		$backupplus_migrated_site_domain = get_site_option( 'backupplus_migrated_site_domain', false );
		if ( $backupplus_migrated_site_domain ) {
			$htaccess_file_path                   = ABSPATH . '.htaccess';
			$htaccess_file_reference_line_num_arr = array();
			if ( file_exists( $htaccess_file_path ) && is_file( $htaccess_file_path ) ) {
				$current_site_domain = rtrim( str_ireplace( array( 'http://', 'https://' ), '', get_home_url() ), '/' );
				$htaccess_file_lines = file( $htaccess_file_path );
				if ( false !== $htaccess_file_lines ) {
					foreach ( $htaccess_file_lines as $num => $line ) {
						$migrated_site_domain_pos = stripos( $line, $backupplus_migrated_site_domain );
						if ( false !== $migrated_site_domain_pos && stripos( $line, $current_site_domain ) !== $migrated_site_domain_pos ) {
							$htaccess_file_reference_line_num_arr[] = $num + 1;
						}
					}
				}
			}
			$count_old_site_references = count( $htaccess_file_reference_line_num_arr );
			if ( $count_old_site_references > 0 ) {
				?>
				<div class="notice error backupplus-migration-notice is-dismissible"><p>
						<?php
						printf(
							'<strong>' . esc_html__( 'Warning', 'backupplus' ) . ':</strong> ' . esc_html(
							/* translators: %s: Line number(s) in .htaccess file */
								_n( 'Your .htaccess has an old site reference on line number %s. You should remove it manually.', 'Your .htaccess has an old site references on line numbers %s. You should remove them manually.', $count_old_site_references, 'backupplus' )
							),
							esc_html( implode( ', ', $htaccess_file_reference_line_num_arr ) )
						);
						?>
					</p>
				</div>
				<?php
				add_action( 'admin_footer', array( $this, 'dismiss_notice_for_old_site_references' ) );
			} else {
				delete_site_option( 'backupplus_migrated_site_domain' );
			}
		}
	}

	/**
	 * Add js for dismiss migration old site references notice
	 *
	 * @return void
	 */
	public function dismiss_notice_for_old_site_references() {
		global $pagenow;
		$page = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'page' );
		if ( BackupPlus_Options::admin_page() != $pagenow || 'backupplus' != $page ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $pagenow is a WP global variable and has been early defined
			$GLOBALS['backupplus_admin']->admin_enqueue_scripts();
			?>
			<script>
			var backupplus_credentialtest_nonce='<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>';
			</script>		
			<?php
		}
		?>
		<script>
		jQuery(function($) {
			$('.backupplus-migration-notice').on('click', '.notice-dismiss', function() {
				backupplus_send_command('dismiss_migration_notice_for_old_site_reference');
			});
		});
		</script>
		<?php
	}
}
