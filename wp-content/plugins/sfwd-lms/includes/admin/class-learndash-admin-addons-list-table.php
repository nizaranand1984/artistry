<?php 
if ( !class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}
if ( !class_exists( 'WP_Plugin_Install_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-plugin-install-list-table.php' );
}

if ( ( class_exists( 'WP_Plugin_Install_List_Table' ) ) && ( !class_exists('Learndash_Admin_Addons_List_Table') ) ) {
	class Learndash_Admin_Addons_List_Table extends WP_Plugin_Install_List_Table {

		var $filters 	= array();
		var $per_page 	= 10;
		var $columns 	= array();
		var $addon_updater = null;
		var $group_id	= 0;

		var $tabs 		= array();
		var $current_tab= 'learndash';

	    function __construct() {
	        global $status, $page;

	        //Set parent defaults
	        parent::__construct( 
				array(
	            	'singular'  => 	'addon',     	//singular name of the listed records
	            	'plural'    => 	'addons',    	//plural name of the listed records
	            	'ajax'      => 	true        	//does this table support ajax?
	        	) 
			);
			
			$this->tabs = array(
				'learndash' 	=> array(
					'label' => 	__( 'LearnDash', 'learndash' ),
					'url'	=>	add_query_arg('tab', 'learndash' )
				),
			    'third-party' => array(
					'label'	=>	__( 'Third Party', 'learndash' ),
					'url'	=>	add_query_arg('tab', 'third-party' ),
				)
			);

			if ( ( isset( $_GET['tab'] ) ) && ( !empty( $_GET['tab'] ) ) ) {
				$current_tab = esc_attr( $_GET['tab'] );
				if ( isset( $this->tabs[$current_tab] ) )
					$this->current_tab = $current_tab;
			}
		}
		
		function prepare_items() {
			if ( $this->current_tab == 'learndash' ) {
				$this->prepare_items_learndash();
			} else if ( $this->current_tab == 'third-party' ) {
				$this->prepare_items_third_party();
			 } else {
				$this->items = apply_filters('learndash_addon_tab_items_'. $this->current_tab, array() );
			}
		}

		public function prepare_items_learndash() {
			$this->addon_updater = new LearnDash_Addon_Updater();
			$this->items = $this->addon_updater->get_addon_plugins();
		}

		public function prepare_items_third_party() {
			include( ABSPATH . 'wp-admin/includes/plugin-install.php' );

			$paged = $this->get_pagenum();

			$per_page = 30;

			$installed_plugins = $this->get_installed_plugins();

			$args = array(
				'page' => $paged,
				'per_page' => $per_page,
				'fields' => array(
					'last_updated' => true,
					'icons' => true,
					'active_installs' => true
				),
				// Send the locale and installed plugin slugs to the API so it can provide context-sensitive results.
				'locale' => get_user_locale(),
				'installed_plugins' => array_keys( $installed_plugins ),
			);

			//$args['search'] = 'LearnDash';
			$args['tag'] = sanitize_title_with_dashes( 'LearnDash' );
			
			$api = plugins_api( 'query_plugins', $args );

			if ( is_wp_error( $api ) ) {
				$this->error = $api;
				return;
			}

			$this->items = $api->plugins;

			if ( $this->orderby ) {
				uasort( $this->items, array( $this, 'order_callback' ) );
			}

			$this->set_pagination_args( array(
				'total_items' => $api->info['results'],
				'per_page' => $args['per_page'],
			) );

			if ( isset( $api->info['groups'] ) ) {
				$this->groups = $api->info['groups'];
			}

			if ( $installed_plugins ) {
				$js_plugins = array_fill_keys(
					array( 'all', 'search', 'active', 'inactive', 'recently_activated', 'mustuse', 'dropins' ),
					array()
				);

				$js_plugins['all'] = array_values( wp_list_pluck( $installed_plugins, 'plugin' ) );
				$upgrade_plugins   = wp_filter_object_list( $installed_plugins, array( 'upgrade' => true ), 'and', 'plugin' );

				if ( $upgrade_plugins ) {
					$js_plugins['upgrade'] = array_values( $upgrade_plugins );
				}

				wp_localize_script( 'updates', '_wpUpdatesItemCounts', array(
					'plugins' => $js_plugins,
					'totals'  => wp_get_update_data(),
				) );
			}
		}
		
		public function display_rows() {
			if ( $this->current_tab == 'learndash' ) {
				$this->display_rows_learndash();
			} else if ( $this->current_tab == 'third-party' ) {
				parent::display_rows();
			 } else {
				do_action('learndash_addon_display_rows_'. $this->current_tab );
			}
		}
		
		public function display_rows_learndash() {
			$plugins_allowedtags = array(
				'a' => array( 'href' => array(),'title' => array(), 'target' => array() ),
				'abbr' => array( 'title' => array() ),'acronym' => array( 'title' => array() ),
				'code' => array(), 'pre' => array(), 'em' => array(),'strong' => array(),
				'ul' => array(), 'ol' => array(), 'li' => array(), 'p' => array(), 'br' => array()
			);

			$group = null;

			foreach ( (array) $this->items as $plugin ) {
				if ( is_object( $plugin ) ) {
					$plugin = (array) $plugin;
				}

				$title = wp_kses( $plugin['name'], $plugins_allowedtags );

				// Remove any HTML from the description.
				$description = strip_tags( $plugin['short_description'] );
				$version = wp_kses( $plugin['version'], $plugins_allowedtags );

				$name = strip_tags( $title . ' ' . $version );

				$author = wp_kses( $plugin['author'], $plugins_allowedtags );
				if ( ! empty( $author ) ) {
					$author = ' <cite>' . sprintf( __( 'By %s' ), $author ) . '</cite>';
				}

				$action_links = array();

				if ( current_user_can( 'install_plugins' ) || current_user_can( 'update_plugins' ) ) {
					if ( isset( $plugin['plugin_status'] ) ) {
						$status = $plugin['plugin_status'];
					
						switch ( $status['status'] ) {
							case 'install':
								if ( $status['url'] ) {
									/* translators: 1: Plugin name and version. */
									$action_links[] = '<a class="install-now button" data-slug="' . esc_attr( $plugin['slug'] ) . '" href="' . esc_url( $status['url'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Install %s now' ), $name ) ) . '" data-name="' . esc_attr( $name ) . '">' . __( 'Install Now' ) . '</a>';
								}
								break;
				
							case 'update_available':
								if ( $status['url'] ) {
									/* translators: 1: Plugin name and version */
									$action_links[] = '<a class="update-now button aria-button-if-js" data-plugin="' . esc_attr( $status['file'] ) . '" data-slug="' . esc_attr( $plugin['slug'] ) . '" href="' . esc_url( $status['url'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Update %s now' ), $name ) ) . '" data-name="' . esc_attr( $name ) . '">' . __( 'Update Now' ) . '</a>';
								}
								break;
				
							case 'latest_installed':
							case 'newer_installed':
								if ( is_plugin_active( $status['file'] ) ) {
									$action_links[] = '<button type="button" class="button button-disabled" disabled="disabled">' . _x( 'Active', 'plugin' ) . '</button>';
								} elseif ( current_user_can( 'activate_plugin', $status['file'] ) ) {
									$button_text  = __( 'Activate' );
									/* translators: %s: Plugin name */
									$button_label = _x( 'Activate %s', 'plugin' );
									$activate_url = add_query_arg( array(
										'_wpnonce'    => wp_create_nonce( 'activate-plugin_' . $status['file'] ),
										'action'      => 'activate',
										'plugin'      => $status['file'],
									), network_admin_url( 'plugins.php' ) );
				
									if ( is_network_admin() ) {
										$button_text  = __( 'Network Activate' );
										/* translators: %s: Plugin name */
										$button_label = _x( 'Network Activate %s', 'plugin' );
										$activate_url = add_query_arg( array( 'networkwide' => 1 ), $activate_url );
									}
				
									$action_links[] = sprintf(
										'<a href="%1$s" class="button activate-now" aria-label="%2$s">%3$s</a>',
										esc_url( $activate_url ),
										esc_attr( sprintf( $button_label, $plugin['name'] ) ),
										$button_text
									);
								} else {
									$action_links[] = '<button type="button" class="button button-disabled" disabled="disabled">' . _x( 'Installed', 'plugin' ) . '</button>';
								}
								break;
						}
					}
				}

				$details_link   = self_admin_url( 'plugin-install.php?tab=plugin-information&amp;plugin=' . $plugin['slug'] .
									'&amp;TB_iframe=true&amp;width=600&amp;height=550' );

				/* translators: 1: Plugin name and version. */
				$action_links[] = '<a href="' . esc_url( $details_link ) . '" class="thickbox open-plugin-details-modal" aria-label="' . esc_attr( sprintf( __( 'More information about %s' ), $name ) ) . '" data-title="' . esc_attr( $name ) . '">' . __( 'More Details' ) . '</a>';

				
				if ( !empty( $plugin['icons']['svg'] ) ) {
					$plugin_icon_url = $plugin['icons']['svg'];
				} elseif ( !empty( $plugin['icons']['2x'] ) ) {
					$plugin_icon_url = $plugin['icons']['2x'];
				} elseif ( !empty( $plugin['icons']['1x'] ) ) {
					$plugin_icon_url = $plugin['icons']['1x'];
				} else {
					$plugin_icon_url = $plugin['icons']['default'];
				}
				
				$last_updated_timestamp = strtotime( $plugin['last_updated'] );
			?>
			<div class="plugin-card plugin-card-<?php echo sanitize_html_class( $plugin['slug'] ); ?>">
				<div class="plugin-card-top">
					<div class="name column-name">
						<h3>
							<a href="<?php echo esc_url( $details_link ); ?>" class="thickbox open-plugin-details-modal">
							<?php echo $title; ?>
							<img src="<?php echo esc_attr( $plugin_icon_url ) ?>" class="plugin-icon" alt="">
							</a>
						</h3>
					</div>
					<div class="action-links">
						<?php
							if ( $action_links ) {
								echo '<ul class="plugin-action-buttons"><li>' . implode( '</li><li>', $action_links ) . '</li></ul>';
							}
						?>
					</div>
					<div class="desc column-description">
						<p><?php echo $description; ?></p>
						<p class="authors"><?php echo $author; ?></p>
					</div>
				</div>
				<?php
				if ( ( isset( $plugin['upgrade_notice']['content'][$plugin['version']] ) ) && ( !empty( $plugin['upgrade_notice']['content'][$plugin['version']] ) ) ) {
					if ( ( isset( $plugin['plugin_status']['status'] ) ) && ( $plugin['plugin_status']['status'] == 'update_available' ) ) {
						?><div class="plugin-card-upgrade-notice"><span style="display: block; background-color: #d54e21; padding: 10px; color: #f9f9f9; margin-top: 10px"><?php echo esc_html( $plugin['upgrade_notice']['content'][$plugin['version']] ); ?></span></div><?php
					}
				}
				?>
				<div class="plugin-card-bottom">
					<?php /* ?>
					<div class="vers column-rating">
						<?php wp_star_rating( array( 'rating' => $plugin['rating'], 'type' => 'percent', 'number' => $plugin['num_ratings'] ) ); ?>
						<span class="num-ratings" aria-hidden="true">(<?php echo number_format_i18n( $plugin['num_ratings'] ); ?>)</span>
					</div>
					<?php */ ?>
					<div class="column-updated">
						<strong><?php _e( 'Last Updated:' ); ?></strong> <?php printf( __( '%s ago' ), human_time_diff( $last_updated_timestamp ) ); ?>
					</div>
					<?php /* ?>
					<div class="column-downloaded">
						<?php
						if ( $plugin['active_installs'] >= 1000000 ) {
							$active_installs_text = _x( '1+ Million', 'Active plugin installations' );
						} elseif ( 0 == $plugin['active_installs'] ) {
							$active_installs_text = _x( 'Less Than 10', 'Active plugin installations' );
						} else {
							$active_installs_text = number_format_i18n( $plugin['active_installs'] ) . '+';
						}
						printf( __( '%s Active Installations' ), $active_installs_text );
						?>
					</div>
					<?php */ ?>
					<div class="column-compatibility">
						<?php
						$wp_version = get_bloginfo( 'version' );

						if ( ! empty( $plugin['tested'] ) && version_compare( substr( $wp_version, 0, strlen( $plugin['tested'] ) ), $plugin['tested'], '>' ) ) {
							echo '<span class="compatibility-untested">' . __( 'Untested with your version of WordPress' ) . '</span>';
						} elseif ( ! empty( $plugin['requires'] ) && version_compare( substr( $wp_version, 0, strlen( $plugin['requires'] ) ), $plugin['requires'], '<' ) ) {
							echo '<span class="compatibility-incompatible">' . __( '<strong>Incompatible</strong> with your version of WordPress' ) . '</span>';
						} else {
							echo '<span class="compatibility-compatible">' . __( '<strong>Compatible</strong> with your version of WordPress' ) . '</span>';
						}
						?>
					</div>
				</div>
			</div>
			<?php
			}

			// Close off the group divs of the last one
			if ( ! empty( $group ) ) {
				echo '</div></div>';
			}
		}

		protected function display_tablenav( $which ) {
		}
		
		/**
		 *
		 * @global array $tabs
		 * @global string $tab
		 *
		 * @return array
		 */
		protected function get_views() {
			$display_tabs = array();

			$this->tabs = apply_filters('learndash_addon_tabs', $this->tabs );
			
			foreach ( (array) $this->tabs as $action => $tab_set ) {
				$current_link_attributes = ( $action === $this->current_tab ) ? ' class="current" aria-current="page"' : '';
				$new_tab = ( ( isset( $tab_set['new_tab'] ) ) && ( $tab_set['new_tab'] == true ) ) ? ' target="_blank" ' : ''; 
 				$display_tabs['plugin-install-'. $action] = '<a href="'. $tab_set['url'] .'" '. $current_link_attributes .' '. $new_tab .'>'. $tab_set['label'] .'</a>';
			}

			return $display_tabs;
		}
		
		/**
		 * Override parent views so we can use the filter bar display.
		 */
		public function views() {
			$views = $this->get_views();

			/** This filter is documented in wp-admin/inclues/class-wp-list-table.php */
			$views = apply_filters( "views_{$this->screen->id}", $views );

			$this->screen->render_screen_reader_content( 'heading_views' );
			?>
			<div class="wp-filter">
				<ul class="filter-links">
					<?php
					if ( ! empty( $views ) ) {
						foreach ( $views as $class => $view ) {
							$views[ $class ] = "\t<li class='$class'>$view";
						}
						echo implode( " </li>\n", $views ) . "</li>\n";
					}
					?>
				</ul>

				<?php //$this->install_search_form(); ?>
			</div>
			<?php
		}

		/*
		function install_search_form( $deprecated = true ) {
			$type = isset( $_REQUEST['type'] ) ? wp_unslash( $_REQUEST['type'] ) : 'term';
			$term = isset( $_REQUEST['s'] ) ? wp_unslash( $_REQUEST['s'] ) : '';
			?><form class="search-form search-plugins" method="get">
				<input type="hidden" name="tab" value="search" />
				<label class="screen-reader-text" for="typeselector"><?php _e( 'Search plugins by:' ); ?></label>
				<select name="type" id="typeselector">
					<option value="term"<?php selected( 'term', $type ); ?>><?php _e( 'Keyword' ); ?></option>
					<option value="tag"<?php selected( 'tag', $type ); ?>><?php _ex( 'Tag', 'Plugin Installer' ); ?></option>
				</select>
				<label><span class="screen-reader-text"><?php _e( 'Search Plugins' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $term ) ?>" class="wp-filter-search" placeholder="<?php esc_attr_e( 'Search plugins...' ); ?>" />
				</label>
				<?php submit_button( __( 'Search Plugins' ), 'hide-if-js', false, false, array( 'id' => 'search-submit' ) ); ?>
			</form><?php
		}
		*/
	}
}


