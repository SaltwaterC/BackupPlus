(function ($) {

	$(function() {
		/*
			Plugins page
			splash screen on activation
		*/

		$('.backupplus-welcome .close').on('click', function(e) {
			e.preventDefault();
			$(this).closest('.backupplus-welcome').remove();
		});

		/*
			BackupPlus page tour
		*/

		// if Shepherd is undefined, exit.
		if (!window.Shepherd) return;

		var button_classes = 'button button-primary';
		var plugins_page_tour = window.backupplus_plugins_page_tour = new Shepherd.Tour();
		var main_tour = window.backupplus_main_tour = new Shepherd.Tour();

		// Set up the defaults for each step
		main_tour.options.defaults = plugins_page_tour.options.defaults = {
			classes: 'shepherd-theme-arrows-plain-buttons shepherd-main-tour',
			showCancelLink: true,
			scrollTo: false,
			tetherOptions: {
				constraints: [
					{
						to: 'scrollParent',
						attachment: 'together',
						pin: false
					}
				]
			}
		};
		
		/*
			Plugins page
		*/

		plugins_page_tour.addStep('intro', {
			title: backupplus_tour_i18n.plugins_page.title,
			text: backupplus_tour_i18n.plugins_page.text,
			attachTo: '.js-backupplus-settings top',
			buttons: [
				{
					classes: button_classes,
					text: backupplus_tour_i18n.plugins_page.button.text,
					action: function() {
						window.location = backupplus_tour_i18n.plugins_page.button.url;
					}
				}
			],
			tetherOptions: {
				constraints: [
					{
						to: 'scrollParent',
						attachment: 'together',
						pin: false
					}
				],
				offset: '20px 0'
			},
			when: {
				show: function() {
					$('body').addClass('highlight-udp');
					var popup = $(this.el);
					// var target = $(this.tether.target);
					$('body, html').animate({
						scrollTop: popup.offset().top - 50
					}, 500, function() {
						window.scrollTo(0, popup.offset().top - 50);
					});
				},
				hide: function() {
					$('body').removeClass('highlight-udp');
				}
			}
		});

		/*
			Main Tour steps
		*/

		// 1. Your first backup
		main_tour.addStep('backup_now', {
			title: backupplus_tour_i18n.backup_now.title,
			text: backupplus_tour_i18n.backup_now.text,
			attachTo: '#backupplus-backupnow-button bottom',
			buttons: [
				{
					classes: 'udp-tour-end',
					text: backupplus_tour_i18n.end_tour,
					action: main_tour.cancel
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.next,
					action: function() {
						$('#backupplus-navtab-settings').trigger('click');
					}
				}
			]
		});

		// Manual backup options
		main_tour.addStep('backup_options', {
			title: backupplus_tour_i18n.backup_options.title,
			text: backupplus_tour_i18n.backup_options.text,
			classes: 'shepherd-theme-arrows-plain-buttons shepherd-main-tour super-index',
			attachTo: '#backupnow_includedb left',
			tetherOptions: {
				offset: '-15px 25px'
			},
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						$('#backupplus-backupnow-modal').dialog('close');
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.next,
					action: main_tour.next
				}
			]
		});

		// Backup Now button
		main_tour.addStep('backup_now_btn', {
			title: backupplus_tour_i18n.backup_now_btn.title,
			text: backupplus_tour_i18n.backup_now_btn.text,
			classes: 'shepherd-theme-arrows-plain-buttons shepherd-main-tour super-index',
			attachTo: '.js-tour-backup-now-button top',
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						main_tour.back();
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.backup_now_btn.btn_text,
					action: function() {
						$('#backupplus-backupnow-modal').dialog('close');
						$('#backupplus-navtab-settings').trigger('click');
					}
				}
			]
		});

		// Congratulations - Shows when a user clicks "backup now" in the modal
		main_tour.addStep('backup_now_btn_success', {
			title: backupplus_tour_i18n.backup_now_btn_success.title,
			text: backupplus_tour_i18n.backup_now_btn_success.text,
			attachTo: '#backupplus_activejobs_table top',
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						$('#backupplus-backupnow-button').trigger('click');
						main_tour.show('backup_now_btn');
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.backup_now_btn_success.btn_text,
					action: function() {
						$('#backupplus-navtab-settings').trigger('click');
					}
				}
			],
			when: {
				show: function() {
					setTimeout(function() {
						$(window).trigger('scroll');
					})
					
				}
			}
		})

		// Settings - timing
		main_tour.addStep('settings_timing', {
			title: backupplus_tour_i18n.settings_timing.title,
			text: backupplus_tour_i18n.settings_timing.text,
			attachTo: '.retain-files right',
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						$('#backupplus-navtab-backups').trigger('click');
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.next,
					action: main_tour.next
				}
			],
			tetherOptions: $.extend({}, main_tour.options.defaults.tetherOptions, {
				offset: '-33px -15px'
			}),
			when: {
				show: function() {
					scroll_to_popup();
				}
			}
		});

		// Settings - remote storage.
		main_tour.addStep('settings_remote_storage', {
			title: backupplus_tour_i18n.settings_remote_storage.title,
			text: backupplus_tour_i18n.settings_remote_storage.text,
			attachTo: '#remote-storage-holder top',
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						main_tour.back();
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.next,
					action: main_tour.next
				}
			],
			when: {
				show: function() {
					scroll_to_popup();
				}
			}
		});

		// Settings - additional options
		main_tour.addStep('settings_more', {
			title: backupplus_tour_i18n.settings_more.title,
			text: backupplus_tour_i18n.settings_more.text,
			attachTo: '.js-tour-settings-more top',
			scrollTo: false,
			tetherOptions: {},
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						main_tour.back();
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.next,
					action: main_tour.next
				}
			],
			when: {
				show: function() {
					scroll_to_popup();
				}
			}
		});

		// Auto backup.
		main_tour.addStep('auto_backup', {
			title: backupplus_tour_i18n.auto_backup.title,
			text: backupplus_tour_i18n.auto_backup.text,
			attachTo: '#backupplus_autobackup_default top',
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						main_tour.back();
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.next,
					action: main_tour.next
				}
			],
			showOn: function() {
				if (backupplus_tour_i18n.auto_backup.enabled) return true;
				return false;
			},
			when: {
				'before-show': function() {
					$('.enableexpertmode').trigger('click');
					$('#backupplus_autobackup_default').focus();
				},
				show: function() {
					scroll_to_popup();
				}
			}
		});

		// Save settings.
		main_tour.addStep('settings_save', {
			title: backupplus_tour_i18n.settings_save.title,
			text: backupplus_tour_i18n.settings_save.text,
			attachTo: '#backupplus-settings-save top',
			scrollTo: false,
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						main_tour.back();
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.next,
					action: main_tour.next
				}
			],
			when: {
				show: function() {
					scroll_to_popup();
				}
			}
		});

		// Saved settings.
		main_tour.addStep('settings_saved', {
			title: backupplus_tour_i18n.settings_saved.title,
			text: backupplus_tour_i18n.settings_saved.text,
			attachTo: '#backupplus-settings-save top',
			scrollTo: false,
			buttons: [
				{
					classes: 'udp-tour-back',
					text: backupplus_tour_i18n.back,
					action: function() {
						main_tour.show('settings_more');
					}
				},
				{
					classes: button_classes,
					text: backupplus_tour_i18n.end_tour,
					action: main_tour.cancel
				}
			],
			when: {
				show: function() {
					scroll_to_popup();
				}
			}
		});

		main_tour.steps.forEach(function(step) {
			step.once('show', function() {
				// Adds a Close label near the (x)
				var close_btn = $(this.el).find('header .shepherd-cancel-link');
				close_btn.attr('data-btntext', backupplus_tour_i18n.close);

				// opens the settings tab
				$(this.el).find('.js--go-to-settings').on('click', function(e) {
					e.preventDefault();
					$('#backupplus-navtab-settings').trigger('click');
				});
			})
		});
		
		// on Cancel
		main_tour.on('cancel', cancel_tour);
		plugins_page_tour.on('cancel', cancel_tour);

		/**
		 * Cancel tour
		 */
		function cancel_tour() {
			// The tour is either finished or [x] was clicked
			main_tour.canceled = true;
			var data = {
				current_step: this.getCurrentStep().id
			};

			if ('function' === typeof backupplus_send_command) {
				backupplus_send_command(
					'set_tour_status',
					data,
					function(response) {
						console.log('Successfully deactivated tour');
					},
					{ alert_on_error: false }
				);
			} else {
				jQuery.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'backupplus_ajax',
						subaction: 'set_tour_status',
						nonce: backupplus_tour_i18n.nonce,
						current_step: this.getCurrentStep().id
					}
				});
			}
		};

		/**
		 * Scroll to Popup
		 *
		 * @param {Object} step
		 */
		var scroll_to_popup = function(step) {
			main_tour.going_somewhere = false;
			if (!step) {
				step = main_tour.getCurrentStep();
			}
			var popup = $(step.el);
			var target = $(step.tether.target);
			$('body, html').animate({
				scrollTop: popup.offset().top - 50
			}, 500, function() {
				window.scrollTo(0, popup.offset().top - 50);
			});
			
		}

		// If $('#backupplus-backupnow-button'), start tour
		if ($('#backupplus-backupnow-button').length) {
			/*
				Setup other events
			*/

			// Backup now
			$('#backupplus-backupnow-button').on('click', function(e) {
				if (!main_tour.canceled) {
					main_tour.show('backup_options');
				}
			});

			// Click on status tab
			$('#backupplus-navtab-backups').on('click', function(e) {
				if (!main_tour.canceled) {
					main_tour.show('backup_now');
				}
			});

			// close backup backupnow modal
			$('#backupplus-backupnow-modal').on("dialogclose", function(event, ui) {
				if (!main_tour.canceled) {
					main_tour.show('backup_now');
				}
			});
			
			// Backup now - manual backup is starting
			$('.js-tour-backup-now-button').on('click', function(e) {
				if (!main_tour.canceled) {
					main_tour.show('backup_now_btn_success');
				}
			})

			// settings tab
			$('#backupplus-navtab-settings').on('click', function(e) {
				if (!main_tour.canceled && !main_tour.going_somewhere) {
					main_tour.show('settings_timing');
				}
			});

			// Tabs without guide
			$('#backupplus-navtab-expert').on('click', function(e) {
				if (!main_tour.canceled) {
					main_tour.hide();
				}
			});
			// start tour
			main_tour.start();

			// go back to first tab
			if (backupplus_tour_i18n.show_tab_on_load) {
				$(backupplus_tour_i18n.show_tab_on_load).trigger('click');
			} else {
				$('#backupplus-navtab-backups').trigger('click');
			}

		}

		// start plugins page tour
		if ($('.js-backupplus-settings').length) {
			plugins_page_tour.start();
		}
		
	});

})(jQuery);
