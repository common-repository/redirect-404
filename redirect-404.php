<?php
/*
 * Plugin Name: Redirect 404 Errors
 * Version: 1.6.3
 * Plugin URI: http://webd.uk/redirect-404/
 * Description: Automatically detects and redirects 404 errors to a static 404.php page using .htaccess
 * Author: webd.uk
 * Author URI: http://webd.uk
 */

if (!class_exists('r404_class')) {

	class r404_class {

		function r404_activate() {

            update_option('r404-active', 'false');

		}

		function r404_add_plugin_settings_link($links) {

			$settings_link = '<a href="' . $this->r404_home_root() . 'wp-admin/options-general.php?page=r404_settings">' . __('Settings', 'redirect-404') . '</a>';
			array_unshift( $links, $settings_link );
			return $links;

		}

		function r404_detect_404() {

            if (get_option('r404-active') != 'true' && get_option('r404-active') != 'false') {
                update_option('r404-active', 'true');
            }

			if (is_404() && get_option('r404-active') == 'true') {

				$found_404s = $this->r404_load_htaccess_404s();
                $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $white_list = array(
                    '',
                    '/',
                    parse_url(wp_login_url(), PHP_URL_PATH),
                    parse_url(wp_logout_url(), PHP_URL_PATH)
                );

				if (!in_array($request_uri, $white_list) && !in_array($this->r404_strip_and_create_404_url($request_uri),$found_404s) && strpos($request_uri, '%22') === false && strpos($request_uri, '"') === false) {

					array_push($found_404s, $this->r404_strip_and_create_404_url($request_uri));

					$this->r404_save_htaccess_404s($found_404s);

					$blogusers = get_users('role=Administrator');
					$admin_emails = array();

					foreach ($blogusers as $user) {

						if ($user->user_email != '') {

							array_push($admin_emails, $user->user_email);

							if (get_user_option('r404-no-emails',$user->ID) != 'true') {

								$this->r404_send_email($user->user_email, $found_404s);

							}

						}

					}

					if (get_bloginfo('admin_email') != "" && !in_array(get_bloginfo('admin_email'),$admin_emails)) {
						$this->r404_send_email(get_bloginfo('admin_email'), $found_404s);
					}

				}

			}
		}

		function r404_load_htaccess_404s() {

			$found_404s = array();

			if (file_exists($this->get_home_path() . '.htaccess')) {

				$markerdata = explode("\n", implode('', file($this->get_home_path() . '.htaccess')));
				$found = false;
				$blank_line = false;
				$newdata = '';

				foreach ($markerdata as $line) {

						if ($line == '# BEGIN Redirect404') {

							$found = true;

						}

						if ($line == '# END Redirect404') {

							$found = false;

						}

						if ($found && (substr($line, 0, strlen('RewriteRule ^')) === 'RewriteRule ^' || substr($line, 0, strlen('RewriteRule "^')) === 'RewriteRule "^')) {

							array_push($found_404s, $line . "\n");

						}

				}

			}

			foreach ($found_404s as &$found_404) {

				$found_404 = $this->r404_create_redirect_rule(preg_quote(urldecode($this->r404_deconstruct_rewrite_rule($found_404))));

			}

			return $found_404s;

		}

		function r404_save_htaccess_404s($found_404s) {

            $this->r404_maintenance_mode(true);

            $found_404s = array_unique($found_404s);

			if ((!file_exists($this->get_home_path() . '.htaccess') && is_writable($this->get_home_path())) || is_writable($this->get_home_path() . '.htaccess')) {

				if (file_exists($this->get_home_path() . '.htaccess')) {

					$markerdata = explode("\n", implode('', file($this->get_home_path() . '.htaccess')));

				} else {
					$markerdata = array();
				}

				$found = false;
				$written_404s = false;
				$blank_line = false;
				$newdata = '';

				foreach ($markerdata as $line) {

					if ($blank_line == true && $line == '') {

						$found = true;

					}

					if ($blank_line == true && $line != '') {

						$found = false;

					}

					if ($line != '') {

						$blank_line = false;

					} else {

						$blank_line = true;

					}

					if ($line == '# BEGIN Redirect404') {

						if (!$written_404s) {

							$newdata .= "# BEGIN Redirect404\n";
							$newdata .= "<IfModule mod_rewrite.c>\n";
							$newdata .= "RewriteEngine On\n";

							foreach ($found_404s as $found_404) {

								$newdata .= $found_404;

							}

							$newdata .= "</IfModule>\n";
							$newdata .= "# END Redirect404\n";
							$written_404s = true;

						}

						$found = true;

					}

					if (!$found) {

						$newdata .= "$line\n";

					}

					if ($line == '# END Redirect404') {

						$found = false;

					}

				}

				if (!$written_404s) {

					$newdata_insert = "# BEGIN Redirect404\n";
					$newdata_insert .= "<IfModule mod_rewrite.c>\n";
					$newdata_insert .= "RewriteEngine On\n";

					foreach ($found_404s as $found_404) {

						$newdata_insert .= $found_404;

					}

					$newdata_insert .= "</IfModule>\n";
					$newdata_insert .= "# END Redirect404\n\n";
					$newdata = $newdata_insert . $newdata;
					$written_404s = true;

				}

				$f = @fopen($this->get_home_path() . '.htaccess', 'w');
				fwrite($f, $newdata);

			}

            $this->r404_maintenance_mode();

		}

		function r404_strip_and_create_404_url($url) {

			$url = substr($url, 1);
			$url = preg_quote(urldecode($url));
			$url = $this->r404_create_redirect_rule($url);

			return $url;

		}

		function r404_create_redirect_rule($url) {

			$url = 'RewriteRule "^' . $url . '$" ' . str_replace((isset($_SERVER['HTTPS']) ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'],'',plugin_dir_url( __FILE__ )) . "404.php [L]\n";
			return $url;

		}

		/**
		 * Get the absolute filesystem path to the root of the WordPress installation
		 *
		 * @since 1.5.0
		 *
		 * @return string Full filesystem path to the root of the WordPress installation
		 */
		function get_home_path() {
			$home    = set_url_scheme( get_option( 'home' ), 'http' );
			$siteurl = set_url_scheme( get_option( 'siteurl' ), 'http' );
			if ( ! empty( $home ) && 0 !== strcasecmp( $home, $siteurl ) ) {
				$wp_path_rel_to_home = str_ireplace( $home, '', $siteurl ); /* $siteurl - $home */
				$pos = strripos( str_replace( '\\', '/', $_SERVER['SCRIPT_FILENAME'] ), trailingslashit( $wp_path_rel_to_home ) );
				$home_path = substr( $_SERVER['SCRIPT_FILENAME'], 0, $pos );
				$home_path = trailingslashit( $home_path );
			} else {
				$home_path = ABSPATH;
			}

			return str_replace( '\\', '/', $home_path );
		}

		function r404_send_email($recipient, $found_404s) {

			$message = __('A new 404 URL has been added to .htaccess:', 'redirect-404') . "\r\n\r\n";
			$message .= get_site_url(null, (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) . "\r\n\r\n";
			// $message .= __('Your .htaccess file now contains the following 404 URLs:', 'redirect-404') . "\r\n\r\n";

			// foreach ($found_404s as $found_404) {

			// 	$message .= $found_404 .= "\n";

			// }

			$message .= "\r\n";

			$message .= __('Contact us if you are having trouble with Wordpress http://webd.co.uk', 'redirect-404') . "\r\n\r\n";
			$message .= __('If you like our plugin please give it a good review on https://wordpress.org/support/plugin/redirect-404/reviews/', 'redirect-404') . "\r\n\r\n";

			if ( is_multisite() ) {

				$blogname = get_network()->site_name;

			} else {

				$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

			}

			$title = sprintf( __('[%s] 404 URL Added to .htaccess', 'redirect-404'), $blogname );

			if ( $message && !wp_mail( $recipient, wp_specialchars_decode( $title ), $message ) ) {
				wp_die( __('The email could not be sent.', 'redirect-404') . "<br />\n" . __('Possible reason: your host may have disabled the mail() function.', 'redirect-404') );
			}

		}

		function r404_uninstall() {

			if (is_writable($this->get_home_path() . '.htaccess')) {

				$markerdata = explode("\n", implode('', file($this->get_home_path() . '.htaccess')));
				$found = false;
				$blank_line = false;
				$newdata = '';

				foreach ($markerdata as $line) {

						if ($blank_line == true && $line == '') {

							$found = true;

						}

						if ($blank_line == true && $line != '') {

							$found = false;

						}

						if ($line != '') {

							$blank_line = false;

						} else {

							$blank_line = true;

						}

						if ($line == '# BEGIN Redirect404') {

							$found = true;

						}

						if (!$found) {

							$newdata .= "$line\n";

						}

						if ($line == '# END Redirect404') {

							$found = false;

						}

				}

				$f = @fopen($this->get_home_path() . '.htaccess', 'w');
				fwrite($f, $newdata);

			}

            wp_clear_scheduled_hook('daily_update_cloud_detected_404s');

		}

		function r404_settings_menu() {

		    add_options_page('404 Settings','404 Settings', 'manage_options', 'r404_settings', array($this, 'r404_settings_page'));

		}

        function r404_deconstruct_rewrite_rule($rewriterule) {

			$exploded_rewriterule  = explode(" ", $rewriterule, 2);

            if (substr($exploded_rewriterule[1], 0, 1) === '^') {

			    $url = substr($exploded_rewriterule[1],1);
                $exploded_rewriterule = explode(" ", $url, -2);
                $url = substr(implode(' ', $exploded_rewriterule),0,-1);

			    return $this->preg_unquote($url);

            } elseif (substr($exploded_rewriterule[1], 0, 2) === '"^') {

			    $url = substr($exploded_rewriterule[1],2);
                $exploded_rewriterule = explode(" ", $url, -2);
                $url = substr(implode(' ', $exploded_rewriterule),0,-2);

			    return urlencode($this->preg_unquote($url));

            } else {

                return false;

            }

        }

		function r404_settings_page() {

                if (get_option('r404-active') != 'true' && get_option('r404-active') != 'false') {
                    update_option('r404-active', 'true');
                }

				$current_user = wp_get_current_user();
				$found_404s = $this->r404_load_htaccess_404s();

    			if (isset($_POST['r404-no-emails']) && isset($_POST['form-type']) && $_POST['form-type'] == 'settings') {

					update_user_option( $current_user->ID, 'r404-no-emails', 'true' );
?>

    <div class="notice notice-success is-dismissible">
        <p>Turned off email notifications.</p>
    </div>

<?php
				} elseif (isset($_POST['form-type']) && $_POST['form-type'] == 'settings') {

					update_user_option( $current_user->ID, 'r404-no-emails', 'false' );

    			}

    			if (isset($_POST['r404-active']) && isset($_POST['form-type']) && $_POST['form-type'] == 'settings') {

					update_option('r404-active', 'true');
?>

    <div class="notice notice-success is-dismissible">
        <p>Automatic 404 URL detection active.</p>
    </div>

<?php
				} elseif (isset($_POST['form-type']) && $_POST['form-type'] == 'settings') {

					update_option('r404-active', 'false');

    			}

    			if (isset($_POST['r404-cron']) && isset($_POST['form-type']) && $_POST['form-type'] == 'settings') {

                    if (!wp_next_scheduled('daily_update_cloud_detected_404s')) {

                        wp_schedule_event(time(), 'daily', 'daily_update_cloud_detected_404s');

                    }

?>

    <div class="notice notice-success is-dismissible">
        <p>Daily update of cloud detected 404s active.</p>
    </div>

<?php

				} elseif (isset($_POST['form-type']) && $_POST['form-type'] == 'settings') {

                    wp_clear_scheduled_hook('daily_update_cloud_detected_404s');

    			}

			    if (isset($_POST['r404-new-urls']) && isset($_POST['form-type']) && $_POST['form-type'] == 'cloud') {

        			$new_urls = $_POST['r404-new-urls'];

						foreach ($new_urls AS &$new_url) {

							$new_url = $this->r404_create_redirect_rule(preg_quote(urldecode($new_url)));

						}

					$this->r404_save_htaccess_404s(array_unique(array_merge($found_404s,$new_urls)));
?>

    <div class="notice notice-success is-dismissible">
        <p>Redirect 404 URLs saved.</p>
    </div>

<?php
			    }

			    if (isset($_POST['r404-delete-urls']) && isset($_POST['form-type']) && $_POST['form-type'] == 'existing') {

        			$delete_urls = $_POST['r404-delete-urls'];

						foreach ($delete_urls AS &$delete_url) {

							$delete_url = $this->r404_create_redirect_rule(preg_quote(urldecode($delete_url)));

						}

					$this->r404_save_htaccess_404s(array_diff($found_404s,$delete_urls));
?>

    <div class="notice notice-success is-dismissible">
        <p>Redirect 404 URLs saved.</p>
    </div>

<?php
			    }

				$found_404s = $this->r404_load_htaccess_404s();
                $cloud_404s = $this->r404_import_cloud_404s();

?>

<div>
<h2>Redirect 404 URLs</h2>

<form action="<?php echo $_SERVER['REQUEST_URI'] ?>" method="post">
<input type="hidden" name="form-type" value="settings">
<?php wp_nonce_field('r404-settings') ?>

<h3>Settings</h3>
<p><input type="checkbox" id="r404-active" name="r404-active" value="true" <?php if (get_option('r404-active') == 'true') { echo ' checked'; } ?>> Activate automatic 404 URL detection and blocking</p>
<p><input type="checkbox" id="r404-no-emails" name="r404-no-emails" value="true" <?php if (get_user_option('r404-no-emails',$current_user->ID) == 'true') { echo ' checked'; } ?>> Do not send notification emails to <?php echo $current_user->user_email; ?></p>
<p><input type="checkbox" id="r404-cron" name="r404-cron" value="true" <?php if (wp_next_scheduled('daily_update_cloud_detected_404s')) { echo ' checked'; } ?>> Activate daily update of cloud detected 404s</p>

<?php submit_button(); ?>

</form>

<?php
				if ($cloud_404s) {

                    $new_404s = array_diff($cloud_404s, $found_404s);
?>

<form action="<?php echo $_SERVER['REQUEST_URI'] ?>" method="post">
<input type="hidden" name="form-type" value="cloud">
<?php wp_nonce_field('r404-settings') ?>
<h3>New Dynamic Cloud Detected 404 URLs</h3>
<p>Select 404 URLs below that you would like to add to your .htaccess file. Be <strong>very</strong> careful with this setting, make sure you aren't about to block your own Wordpress login URL.</p>

<?php

				    foreach ($new_404s as $new_404) {

				    	$url_404 = $this->r404_deconstruct_rewrite_rule($new_404);
				    	echo '<p><input type="checkbox" id="r404-new-urls" name="r404-new-urls[]" value="'.$url_404.'" checked="checked"> '.urldecode($url_404).'</p>';

				    }
?>

<?php submit_button(); ?>

</form>

<?php
				} else {
?>

<h3>New Dynamic Cloud Detected 404 URLs</h3>
<p class="attention">Unable to connect to the server ... your web host may have been blocked.</p>

<?php
				}
?>

<form action="<?php echo $_SERVER['REQUEST_URI'] ?>" method="post">
<input type="hidden" name="form-type" value="existing">
<?php wp_nonce_field('r404-settings') ?>
<h3>Existing 404 URLs</h3>
<p>Select 404 URLs that you'd like to delete from being redirected.</p>

<?php
				natcasesort($found_404s);

				foreach ($found_404s as $found_404) {

				    $url_404 = $this->r404_deconstruct_rewrite_rule($found_404);
					echo '<p><input type="checkbox" id="r404-delete-urls" name="r404-delete-urls[]" value="'.$url_404.'"> '.urldecode($url_404).'</p>';

				}
?>

<?php submit_button(); ?>
</form></div>

<?php
		}

        function r404_import_cloud_404s() {

            $http_url = 'http://webd.co.uk/wp-content/plugins/redirect-404-cloud-detection/export-dynamic-cloud-detected-404s-v2.php';
            $http_args = array(
                'timeout' => 15,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url( '/' )
			);
            $request = wp_remote_post( $http_url, $http_args );

			if (!isset($request->errors)) {

                $cloud_404s = array();

			    foreach (json_decode($request['body']) as $request_uris) {

                    $http_host = parse_url(get_site_url(), PHP_URL_HOST);
                    $request_uri = $request_uris->request_uri;
                    $request_uri = str_replace('!*http_host*!', $http_host, $request_uri);
                    $request_uri = str_replace('!*http_domain*!', strtok(str_replace('www.', '', $http_host), '.'), $request_uri);
                    $request_uri = str_replace('!*stylesheet*!', get_option('stylesheet'), $request_uri);

                    array_push($cloud_404s, $this->r404_create_redirect_rule(preg_quote(urldecode($request_uri))));

                }

                return $cloud_404s;

			} else {

                return false;

			}

        }

        function preg_unquote($str) {

            return strtr($str, array(
                '\\.'  => '.',
                '\\\\' => '\\',
                '\\+'  => '+',
                '\\*'  => '*',
                '\\?'  => '?',
                '\\['  => '[',
                '\\^'  => '^',
                '\\]'  => ']',
                '\\$'  => '$',
                '\\('  => '(',
                '\\)'  => ')',
                '\\{'  => '{',
                '\\}'  => '}',
                '\\='  => '=',
                '\\!'  => '!',
                '\\<'  => '<',
                '\\>'  => '>',
                '\\|'  => '|',
                '\\:'  => ':',
                '\\-'  => '-'
            ));

        }

        function r404_update_cloud_detected_404s() {

			$found_404s = $this->r404_load_htaccess_404s();
            $cloud_404s = $this->r404_import_cloud_404s();
            $new_404s = array_diff($cloud_404s, $found_404s);

            if ($cloud_404s && !empty($new_404s)) {

				$this->r404_save_htaccess_404s(array_unique(array_merge($found_404s,$cloud_404s)));

				foreach ($blogusers as $user) {

					if ($user->user_email != '') {

						array_push($admin_emails, $user->user_email);

						if (get_user_option('r404-no-emails',$user->ID) != 'true') {

							$this->r404_send_cron_email($user->user_email, array_diff($cloud_404s, $found_404s));

						}

					}

				}

				if (get_bloginfo('admin_email') != "" && !in_array(get_bloginfo('admin_email'),$admin_emails)) {

					$this->r404_send_cron_email(get_bloginfo('admin_email'), array_diff($cloud_404s, $found_404s));

				}

			}

        }

		function r404_send_cron_email($recipient, $found_404s) {

			$message = __('New dynamic cloud detected 404 rules:', 'redirect-404') . "\r\n\r\n";

			foreach ($found_404s as $found_404) {

				$message .= '/' . urldecode($this->r404_deconstruct_rewrite_rule($found_404)) . "\n";

			}

			$message .= "\r\n";

			$message .= __('Contact us if you are having trouble with Wordpress http://webd.co.uk', 'redirect-404') . "\r\n\r\n";
			$message .= __('If you like our plugin please give it a good review on https://wordpress.org/support/plugin/redirect-404/reviews/', 'redirect-404') . "\r\n\r\n";

			if ( is_multisite() ) {

				$blogname = get_network()->site_name;

			} else {

				$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

			}

			$title = sprintf( __('[%s] 404 URLs Updated', 'redirect-404'), $blogname );

			if ( $message && !wp_mail( $recipient, wp_specialchars_decode( $title ), $message ) ) {
				wp_die( __('The email could not be sent.', 'redirect-404') . "<br />\n" . __('Possible reason: your host may have disabled the mail() function.', 'redirect-404') );
			}

		}

		function r404_maintenance_mode($enable = false) {

            $file = $this->get_home_path() . '.maintenance';

    		if ($enable) {

    			if ((!file_exists($file) && is_writable($this->get_home_path())) || is_writable($file)) {

    				$f = @fopen($file, 'w');
    				fwrite($f, '<?php $upgrading = ' . time() . '; ?>');

    			}

    		} elseif (!$enable && file_exists($file) && is_writable($file)) {

                unlink($file);

    		}

		}

		function r404_home_root() {

			$home_root = parse_url(home_url());

			if (isset($home_root['path'])) {

				$home_root = trailingslashit($home_root['path']);

			} else {

				$home_root = '/';

			}

			return $home_root;

		}

	}

	$r404 = new r404_class();

	add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($r404, 'r404_add_plugin_settings_link'));

	add_action('template_redirect', array($r404, 'r404_detect_404'));

	register_activation_hook(__FILE__, array($r404, 'r404_activate'));

	register_deactivation_hook(__FILE__, array($r404, 'r404_uninstall'));

	add_action( 'admin_menu', array($r404, 'r404_settings_menu'));

    add_action('daily_update_cloud_detected_404s', array($r404, 'r404_update_cloud_detected_404s'));

}

?>