<?php
/**
 * Plugin Name: WP Rocket Cache Status Monitor
 * Description: Dashboard for tracking WP Rocket's preload status (pending, in-progress, completed, failed): content-type filter, pagination, direct actions (reload/remove a URL, reload everything), root-cause diagnostics (HTTP check + cron health), and a classification cache for performance. Generic, with no project-specific dependencies.
 * Version: 1.2.0
 * Author: Marcio Yamashita
 * Text Domain: wprsm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Block direct access.
}

class WPRSM_Status_Monitor {

	const CAPABILITY      = 'manage_options';
	const PAGE_SLUG        = 'wprsm-cache-status';
	const PER_PAGE          = 50;
	// Max rows read from the DB per page load. Type classification happens in PHP,
	// so this cap avoids processing huge tables all at once.
	const FETCH_CAP         = 5000;
	// How long (seconds) a URL can stay pending/in-progress before being flagged
	// as "probably stuck".
	const STUCK_THRESHOLD   = 900; // 15 minutes.
	// Per-URL type classification cache (avoids re-running url_to_postid/regex on every load).
	const TYPE_CACHE_KEY    = 'wprsm_url_type_cache';
	const TYPE_CACHE_TTL    = 12 * HOUR_IN_SECONDS;
	const TYPE_CACHE_MAX    = 20000;
	// Cron hook WP Rocket uses to process the preload queue.
	const PRELOAD_CRON_HOOK = 'rocket_preload_process_pending';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_wprsm_reload_url', array( $this, 'handle_reload_url' ) );
		add_action( 'admin_post_wprsm_remove_url', array( $this, 'handle_remove_url' ) );
		add_action( 'admin_post_wprsm_reload_all', array( $this, 'handle_reload_all' ) );
		add_action( 'admin_post_wprsm_run_cron_now', array( $this, 'handle_run_cron_now' ) );
		add_action( 'admin_post_wprsm_generate_cache', array( $this, 'handle_generate_cache' ) );
	}

	/**
	 * Registers the admin page, nested under the WP Rocket menu if it exists,
	 * otherwise creates its own top-level menu.
	 */
	public function register_admin_page() {
		if ( menu_page_url( 'wprocket', false ) ) {
			add_submenu_page(
				'wprocket',
				__( 'Cache Status', 'wprsm' ),
				__( 'Cache Status', 'wprsm' ),
				self::CAPABILITY,
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		} else {
			add_menu_page(
				__( 'Cache Status (WP Rocket)', 'wprsm' ),
				__( 'Cache Status', 'wprsm' ),
				self::CAPABILITY,
				self::PAGE_SLUG,
				array( $this, 'render_page' ),
				'dashicons-performance',
				80
			);
		}
	}

	/**
	 * Returns the name of the WP Rocket preload table.
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wpr_rocket_cache';
	}

	/**
	 * Checks whether the table exists.
	 */
	private function table_exists() {
		global $wpdb;
		$table = $this->get_table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * Builds the current URL (with all filters/pagination) to use as the "come back here"
	 * target after an action (reload/remove/reload all).
	 */
	private function current_filtered_url() {
		$base_url = menu_page_url( self::PAGE_SLUG, false );
		$args     = array();

		foreach ( array( 'wprsm_status', 'wprsm_type', 'wprsm_paged', 'wprsm_orderby', 'wprsm_order' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		return $args ? add_query_arg( $args, $base_url ) : $base_url;
	}

	// -----------------------------------------------------------------
	// DIRECT ACTIONS
	// -----------------------------------------------------------------

	/**
	 * Puts a single URL back in the preload queue (status goes back to "pending").
	 */
	public function handle_reload_url() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wprsm' ) );
		}

		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		check_admin_referer( 'wprsm_reload_url_' . $id );

		global $wpdb;
		$table = $this->get_table_name();

		if ( $id && $this->table_exists() ) {
			$wpdb->update( // phpcs:ignore
				$table,
				array(
					'status'   => 'pending',
					'modified' => current_time( 'mysql' ),
				),
				array( 'id' => $id )
			);
		}

		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		wp_safe_redirect( add_query_arg( 'wprsm_notice', 'reloaded', $redirect ) );
		exit;
	}

	/**
	 * Removes a URL from the preload queue (deletes the row).
	 */
	public function handle_remove_url() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wprsm' ) );
		}

		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		check_admin_referer( 'wprsm_remove_url_' . $id );

		global $wpdb;
		$table = $this->get_table_name();

		if ( $id && $this->table_exists() ) {
			$wpdb->delete( $table, array( 'id' => $id ) ); // phpcs:ignore
		}

		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		wp_safe_redirect( add_query_arg( 'wprsm_notice', 'removed', $redirect ) );
		exit;
	}

	/**
	 * Clears and reloads the whole site cache, using WP Rocket's native function
	 * when available (the same one used by the "Clear and preload cache" admin bar button).
	 * Fallback: marks "completed" rows as "pending" to force reprocessing.
	 */
	public function handle_reload_all() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wprsm' ) );
		}

		check_admin_referer( 'wprsm_reload_all' );

		global $wpdb;
		$table  = $this->get_table_name();
		$notice = 'reload_all_fallback';

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$notice = 'reload_all_ok';
		} elseif ( $this->table_exists() ) {
			$wpdb->query( "UPDATE {$table} SET status = 'pending' WHERE status = 'completed'" ); // phpcs:ignore
		}

		// The whole list is about to change status, so the type cache stays valid,
		// but it doesn't hurt to make sure the next read doesn't land on an empty page.
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		$redirect = remove_query_arg( 'wprsm_paged', $redirect );
		wp_safe_redirect( add_query_arg( 'wprsm_notice', $notice, $redirect ) );
		exit;
	}

	/**
	 * Forces the preload cron event to run immediately, WITHIN the current admin
	 * request — doesn't depend on wp-cron.php or a loopback request, so it works
	 * even with DISABLE_WP_CRON active and a real server cron configured.
	 * Uses the same args the event was scheduled with, when they exist.
	 */
	public function handle_run_cron_now() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wprsm' ) );
		}

		check_admin_referer( 'wprsm_run_cron_now' );

		$hook  = self::PRELOAD_CRON_HOOK;
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( $hook ) : false;

		if ( $event && ! empty( $event->args ) ) {
			do_action_ref_array( $hook, $event->args );
		} else {
			do_action( $hook );
		}

		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		wp_safe_redirect( add_query_arg( 'wprsm_notice', 'cron_run_now', $redirect ) );
		exit;
	}

	/**
	 * Generates the cache for ONE specific URL right now, without waiting for its
	 * turn in the preload queue. Works by making a real HTTP request to the URL —
	 * that's exactly how WP Rocket generates the static HTML (during the request
	 * itself, via the output buffer), so this has the same effect as a real visitor
	 * hitting the page, but on demand.
	 * Accepts 'id' (a queue row) or a direct 'url' (e.g. the home page, even if it's
	 * not highlighted in the pending list).
	 */
	public function handle_generate_cache() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wprsm' ) );
		}

		$id  = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		$url = '';

		if ( $id ) {
			check_admin_referer( 'wprsm_generate_cache_' . $id );

			global $wpdb;
			$table = $this->get_table_name();
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
			if ( $row ) {
				$url = $row->url;
			}
		} else {
			check_admin_referer( 'wprsm_generate_cache_url' );
			$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		}

		$user_key = 'wprsm_notice_' . get_current_user_id();

		if ( ! $url ) {
			set_transient( $user_key, array( 'type' => 'error', 'message' => __( 'No valid URL was provided.', 'wprsm' ) ), 60 );
		} else {
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$url_host  = wp_parse_url( $url, PHP_URL_HOST );

			if ( ! $url_host || strtolower( $url_host ) !== strtolower( (string) $site_host ) ) {
				set_transient( $user_key, array(
					'type'    => 'error',
					/* translators: %s: expected host. */
					'message' => sprintf( __( 'The URL must belong to this site (%s).', 'wprsm' ), $site_host ),
				), 60 );
			} else {
				// Use the same user agent WP Rocket's real preload uses (as documented by
				// WP Rocket itself) — some cache/hosting exclusion rules can behave
				// differently depending on the user agent.
				$response = wp_remote_get(
					$url,
					array(
						'timeout'     => 30,
						'blocking'    => true,
						'sslverify'   => false,
						'redirection' => 3,
						'headers'     => array( 'Cache-Control' => 'no-cache' ),
						'user-agent'  => 'WP Rocket/Preload',
					)
				);

				if ( is_wp_error( $response ) ) {
					set_transient( $user_key, array(
						/* translators: 1: URL, 2: error message. */
						'type'    => 'error',
						'message' => sprintf( __( 'Failed to reach %1$s: %2$s', 'wprsm' ), $url, $response->get_error_message() ),
					), 60 );
				} else {
					$code = wp_remote_retrieve_response_code( $response );

					// Real proof: does a cache file exist on disk, and is it fresh?
					$cache_file    = $this->cache_file_path( $url );
					$file_exists   = file_exists( $cache_file );
					$file_is_fresh = $file_exists && ( time() - filemtime( $cache_file ) ) < 120;

					if ( $code >= 400 ) {
						$msg  = sprintf( __( 'The URL responded with HTTP %d — it should not have been cached.', 'wprsm' ), $code );
						$type = 'error';
					} elseif ( $file_is_fresh ) {
						$msg  = sprintf(
							/* translators: 1: URL, 2: file generation date/time. */
							__( 'Confirmed: cache file generated just now for %1$s (%2$s).', 'wprsm' ),
							$url,
							wp_date( 'd/m/Y H:i:s', filemtime( $cache_file ) )
						);
						$type = 'success';
					} elseif ( $file_exists ) {
						$msg  = sprintf(
							/* translators: 1: HTTP code, 2: existing file date/time. */
							__( 'The request returned HTTP %1$d, but the cache file on disk is not recent (last generated: %2$s) — this request may not be what generated it.', 'wprsm' ),
							$code,
							wp_date( 'd/m/Y H:i:s', filemtime( $cache_file ) )
						);
						$type = 'warning';
					} else {
						$msg = sprintf(
							/* translators: 1: HTTP code, 2: expected cache file path. */
							__( 'The request returned HTTP %1$d, but NO cache file was found at %2$s. Likely causes: the URL is excluded from WP Rocket\'s cache, WP_CACHE is not enabled, or there is an edge cache from your hosting/CDN in front of WordPress responding before it does.', 'wprsm' ),
							$code,
							str_replace( ABSPATH, '', $cache_file )
						);
						$type = 'warning';
					}

					set_transient( $user_key, array( 'type' => $type, 'message' => $msg ), 60 );
				}
			}
		}

		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Expected path of the static cache file WP Rocket generates for a URL, following
	 * its default structure: wp-content/cache/wp-rocket/{host}/{path}/index.html.
	 * This is the real source of truth — much more reliable than trusting just the
	 * request's HTTP 200 or the status stored in the wpr_rocket_cache table.
	 */
	private function cache_file_path( $url ) {
		$parsed = wp_parse_url( $url );
		$host   = ! empty( $parsed['host'] ) ? $parsed['host'] : wp_parse_url( home_url(), PHP_URL_HOST );
		$path   = ! empty( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';

		$dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/wp-rocket/' . $host . ( $path ? '/' . $path : '' );

		return trailingslashit( $dir ) . 'index.html';
	}

	/**
	 * Detects managed hosts known to automatically disable WP Rocket's disk-based
	 * page caching (officially documented by WP Rocket itself: Kinsta, WP Engine,
	 * Pressable, Flywheel, SpinupWP, WordPress.com, among others).
	 * Detection relies only on generic environment signals — nothing project-specific.
	 * Confidently covers the two hosts with a publicly documented detection method;
	 * for the rest of the list, we don't risk "guessing" and just show a generic note.
	 */
	private function detect_managed_host() {
		$result = array(
			'detected' => false,
			'host'     => '',
		);

		if ( function_exists( 'is_wpe' ) && is_wpe() ) {
			$result['detected'] = true;
			$result['host']     = 'WP Engine';
		} elseif ( is_dir( trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins/kinsta-mu-plugins' ) || class_exists( 'Kinsta\\Cache_Purge\\Cache_Purge' ) ) {
			$result['detected'] = true;
			$result['host']     = 'Kinsta';
		}

		return $result;
	}

	// -----------------------------------------------------------------
	// TYPE CLASSIFICATION (with cache for performance)
	// -----------------------------------------------------------------

	/**
	 * Possible content types. Internal key => displayed label.
	 * Generic: doesn't depend on any project's specific post types or taxonomies,
	 * since it queries whatever is registered on the site at runtime.
	 */
	private function type_labels() {
		return array(
			'home'     => __( 'Home', 'wprsm' ),
			'page'     => __( 'Page', 'wprsm' ),
			'single'   => __( 'Single', 'wprsm' ),
			'taxonomy' => __( 'Taxonomy', 'wprsm' ),
			'archive'  => __( 'Archive', 'wprsm' ),
			'other'    => __( 'Other', 'wprsm' ),
		);
	}

	/**
	 * Classifies the URL using the post types and taxonomies registered on the site
	 * (nothing hardcoded), with a fallback based on common URL patterns.
	 * Returns the type KEY (see type_labels()).
	 */
	private function classify_url_raw( $url ) {
		$home_url = trailingslashit( home_url() );
		$path     = trailingslashit( $url );

		if ( $path === $home_url ) {
			return 'home';
		}

		// 1) Singular content (post, page, or any custom post type).
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			return ( 'page' === get_post_type( $post_id ) ) ? 'page' : 'single';
		}

		$parsed    = wp_parse_url( $url );
		$path_only = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';
		$segments  = $path_only ? explode( '/', $path_only ) : array();

		// 2) Taxonomy archive (category, tag, or any public custom taxonomy).
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$slug = ! empty( $tax->rewrite['slug'] ) ? trim( $tax->rewrite['slug'], '/' ) : $tax->name;
			if ( $slug && in_array( $slug, $segments, true ) ) {
				return 'taxonomy';
			}
		}

		// 3) Custom post type archive with its own archive page (has_archive).
		foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'objects' ) as $pt ) {
			$slug = is_string( $pt->has_archive )
				? trim( $pt->has_archive, '/' )
				: ( ! empty( $pt->rewrite['slug'] ) ? trim( $pt->rewrite['slug'], '/' ) : $pt->name );
			if ( $slug && in_array( $slug, $segments, true ) ) {
				return 'archive';
			}
		}

		// 4) Author or date archive (native WP patterns).
		if ( in_array( 'author', $segments, true ) ) {
			return 'archive';
		}
		if ( ! empty( $segments[0] ) && preg_match( '/^\d{4}$/', $segments[0] ) ) {
			return 'archive';
		}

		return 'other';
	}

	/**
	 * Classifies a batch of URLs using a persistent (transient) cache per URL.
	 * Avoids reprocessing url_to_postid()/regex for URLs already seen in previous
	 * page loads — only new URLs, or ones that fell out of the cache (TTL), are
	 * recalculated. Does at most 1 transient read and 1 transient write per page
	 * load, regardless of how many rows exist.
	 *
	 * @param string[] $urls List of URLs to classify.
	 * @return array URL => type key.
	 */
	private function classify_urls_cached( array $urls ) {
		$cache   = get_transient( self::TYPE_CACHE_KEY );
		$cache   = is_array( $cache ) ? $cache : array();
		$result  = array();
		$changed = false;

		foreach ( $urls as $url ) {
			if ( isset( $cache[ $url ] ) ) {
				$result[ $url ] = $cache[ $url ];
				continue;
			}

			$type           = $this->classify_url_raw( $url );
			$result[ $url ] = $type;

			if ( count( $cache ) < self::TYPE_CACHE_MAX ) {
				$cache[ $url ] = $type;
				$changed       = true;
			}
		}

		if ( $changed ) {
			set_transient( self::TYPE_CACHE_KEY, $cache, self::TYPE_CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Returns the label and color for each status.
	 */
	private function status_meta( $status ) {
		$map = array(
			'pending'     => array( 'label' => __( 'Pending', 'wprsm' ), 'color' => '#8c8f94' ),
			'in-progress' => array( 'label' => __( 'In progress', 'wprsm' ), 'color' => '#2271b1' ),
			'completed'   => array( 'label' => __( 'Completed', 'wprsm' ), 'color' => '#00a32a' ),
			'failed'      => array( 'label' => __( 'Failed', 'wprsm' ), 'color' => '#d63638' ),
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : array( 'label' => ucfirst( $status ), 'color' => '#646970' );
	}

	// -----------------------------------------------------------------
	// ROOT-CAUSE DIAGNOSTICS
	// -----------------------------------------------------------------

	/**
	 * Checks the health of the preload cron: whether there's a next scheduled run,
	 * and whether the "visit-driven" WP-Cron is disabled (which requires a real
	 * server cron to be configured).
	 */
	private function cron_health() {
		return array(
			'next_scheduled'   => wp_next_scheduled( self::PRELOAD_CRON_HOOK ),
			'disable_wp_cron'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
		);
	}

	/**
	 * Does an HTTP HEAD check (without following redirects) on a specific URL, to
	 * help diagnose why it failed: 404, 403, redirect, timeout, etc.
	 */
	private function diagnose_url( $url ) {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'summary' => sprintf(
					/* translators: %s: error message. */
					__( 'No response (%s) — likely a timeout or network block.', 'wprsm' ),
					$response->get_error_message()
				),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			return array(
				'ok'      => false,
				'summary' => sprintf(
					/* translators: 1: HTTP code, 2: redirect target URL. */
					__( 'Redirect %1$d → %2$s', 'wprsm' ),
					$code,
					$location ? $location : __( '(no Location header)', 'wprsm' )
				),
			);
		}

		if ( $code >= 400 ) {
			return array(
				'ok'      => false,
				'summary' => sprintf(
					/* translators: %d: HTTP code. */
					__( 'HTTP error %d — the URL may be unavailable, blocked, or no longer exist.', 'wprsm' ),
					$code
				),
			);
		}

		return array(
			'ok'      => true,
			'summary' => sprintf(
				/* translators: %d: HTTP code. */
				__( 'HTTP %d — the URL responded normally. If preload still marks it as failed, the issue may be in processing (generation timeout), not access.', 'wprsm' ),
				$code
			),
		);
	}

	// -----------------------------------------------------------------
	// RENDER
	// -----------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wprsm' ) );
		}

		global $wpdb;
		$table = $this->get_table_name();

		echo '<div class="wrap"><h1>' . esc_html__( 'Cache Status — WP Rocket Preload', 'wprsm' ) . '</h1>';

		if ( ! $this->table_exists() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Rocket preload table not found. Check that the plugin is active and that Preload has run at least once.', 'wprsm' ) . '</p></div></div>';
			return;
		}

		// Action notices (reload/remove/reload all).
		$notice = isset( $_GET['wprsm_notice'] ) ? sanitize_key( wp_unslash( $_GET['wprsm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice_messages = array(
			'reloaded'            => array( 'success', __( 'URL added back to the queue (status is now "Pending").', 'wprsm' ) ),
			'removed'             => array( 'success', __( 'URL removed from the preload queue.', 'wprsm' ) ),
			'reload_all_ok'       => array( 'success', __( 'Cache cleared and preload restarted for the whole site.', 'wprsm' ) ),
			'reload_all_fallback' => array( 'warning', __( "WP Rocket's native function was not found; completed URLs were marked as pending for reprocessing on the next cron run.", 'wprsm' ) ),
			'cron_run_now'        => array( 'success', __( 'Preload event triggered now, within this request. Refresh the page in a few seconds to see the latest status.', 'wprsm' ) ),
		);
		if ( $notice && isset( $notice_messages[ $notice ] ) ) {
			list( $type, $msg ) = $notice_messages[ $notice ];
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		$dynamic_notice_key = 'wprsm_notice_' . get_current_user_id();
		$dynamic_notice      = get_transient( $dynamic_notice_key );
		if ( $dynamic_notice && is_array( $dynamic_notice ) ) {
			delete_transient( $dynamic_notice_key );
			echo '<div class="notice notice-' . esc_attr( $dynamic_notice['type'] ) . ' is-dismissible"><p>' . esc_html( $dynamic_notice['message'] ) . '</p></div>';
		}

		// --- Managed-host / staging notice (page caching auto-disabled) ---
		$managed_host = $this->detect_managed_host();
		$is_staging   = function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type();

		if ( $managed_host['detected'] || $is_staging ) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Hosting compatibility notice:', 'wprsm' ) . '</strong></p><ul style="list-style:disc; margin-left:20px;">';

			if ( $managed_host['detected'] ) {
				echo '<li>' . sprintf(
					/* translators: %s: detected host name. */
					esc_html__( 'This site is running on %s. This type of managed hosting automatically disables WP Rocket\'s disk-based page caching (including Preload) to avoid conflicting with its own cache — this is expected behavior, documented by WP Rocket itself, not an error in this plugin.', 'wprsm' ),
					esc_html( $managed_host['host'] )
				) . '</li>';
				echo '<li>' . esc_html__( 'The wp-content/cache/wp-rocket/ folder and the preload queue below tend to stay empty or "pending" forever on this type of hosting — check the real cache status through your provider\'s dashboard or its own HTTP response header (e.g. x-kinsta-cache on Kinsta).', 'wprsm' ) . '</li>';
			}

			if ( $is_staging ) {
				echo '<li>' . esc_html__( 'This environment is flagged as "staging" (wp_get_environment_type()). Many hosting providers fully disable page caching on staging environments by default, to avoid stale content during testing.', 'wprsm' ) . '</li>';
			}

			echo '<li>' . esc_html__( 'Other managed hosts with this same behavior (not automatically detected here): Pressable, Flywheel, SpinupWP, WordPress.com, DreamPress, Savvii, and others. Worth checking your provider\'s documentation if the behavior here looks off.', 'wprsm' ) . '</li>';
			echo '</ul></div>';
		}

		// --- Root-cause diagnostics: cron health ---
		$pending_like = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','in-progress')" ); // phpcs:ignore
		$cron         = $this->cron_health();

		if ( $pending_like > 0 && ! $cron['next_scheduled'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Possible cron issue:', 'wprsm' ) . '</strong> ' . esc_html__( "there are pending/in-progress URLs, but I couldn't find the next scheduled run of the preload event. Processing may be stuck.", 'wprsm' ) . '</p></div>';
		}
		if ( $cron['disable_wp_cron'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'DISABLE_WP_CRON is active on this site. Preload will only advance via a real server cron (or the button below, which runs the event immediately within this request).', 'wprsm' ) . '</p>';
			if ( $cron['next_scheduled'] ) {
				echo '<p>' . sprintf(
					/* translators: %s: next scheduled run date/time in the site's timezone. */
					esc_html__( 'Next scheduled run (waiting for the real server cron to fire): %s', 'wprsm' ),
					esc_html( wp_date( 'd/m/Y H:i:s', $cron['next_scheduled'] ) )
				) . '</p>';
			}
			echo '</div>';
		}

		// --- Action: force the preload cron to run now, without depending on wp-cron.php ---
		echo '<div style="margin:0 0 16px;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="wprsm_run_cron_now">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $this->current_filtered_url() ) . '">';
		wp_nonce_field( 'wprsm_run_cron_now' );
		submit_button( __( 'Force preload cron to run now', 'wprsm' ), 'secondary', 'submit', false );
		echo '</form>';
		echo ' <span class="description">' . esc_html__( 'Runs the event immediately within this request, without depending on wp-cron.php or the server cron.', 'wprsm' ) . '</span>';
		echo '</div>';

		// Filters via GET (read-only, doesn't change data).
		$status_filter = isset( $_GET['wprsm_status'] ) ? sanitize_key( wp_unslash( $_GET['wprsm_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter   = isset( $_GET['wprsm_type'] ) ? sanitize_key( wp_unslash( $_GET['wprsm_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged         = isset( $_GET['wprsm_paged'] ) ? max( 1, intval( wp_unslash( $_GET['wprsm_paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby       = isset( $_GET['wprsm_orderby'] ) ? sanitize_key( wp_unslash( $_GET['wprsm_orderby'] ) ) : 'last_accessed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order         = ( isset( $_GET['wprsm_order'] ) && 'asc' === $_GET['wprsm_order'] ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$base_url = esc_url( menu_page_url( self::PAGE_SLUG, false ) );

		// --- Action: generate cache for a specific URL now, with priority, skipping the queue ---
		echo '<div style="margin:0 0 16px; padding:12px 16px; background:#fff; border:1px solid #dcdcde;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Generate cache for a specific URL now', 'wprsm' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Makes a real request to the URL right now, generating its cache on the spot — without waiting its turn in the preload queue. Useful for prioritizing the homepage or any specific page during testing.', 'wprsm' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
		echo '<input type="hidden" name="action" value="wprsm_generate_cache">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $this->current_filtered_url() ) . '">';
		wp_nonce_field( 'wprsm_generate_cache_url' );
		echo '<input type="url" name="url" placeholder="' . esc_attr( home_url( '/' ) ) . '" value="' . esc_attr( home_url( '/' ) ) . '" style="min-width:360px;" required>';
		submit_button( __( 'Generate cache now', 'wprsm' ), 'primary', 'submit', false );
		echo '</form>';
		echo '</div>';

		// --- Action: reload everything ---
		echo '<div style="margin:16px 0;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Clear and reload the cache for the whole site?', 'wprsm' ) ) . '\');">';
		echo '<input type="hidden" name="action" value="wprsm_reload_all">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $this->current_filtered_url() ) . '">';
		wp_nonce_field( 'wprsm_reload_all' );
		submit_button( __( 'Clear and reload cache for the whole site', 'wprsm' ), 'primary', 'submit', false );
		echo '</form>';
		echo '</div>';

		// --- Status summary (real count via SQL, fast, doesn't depend on FETCH_CAP) ---
		$status_counts = $wpdb->get_results( "SELECT status, COUNT(*) as total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore

		echo '<h2>' . esc_html__( 'Status summary', 'wprsm' ) . '</h2>';
		echo '<div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px;">';

		$clear_status_link = esc_url( remove_query_arg( array( 'wprsm_status', 'wprsm_paged' ), add_query_arg( array(), $base_url ) ) );
		echo '<a href="' . $clear_status_link . '" class="button">' . esc_html__( 'View all statuses', 'wprsm' ) . '</a>';

		if ( $status_counts ) {
			foreach ( $status_counts as $row ) {
				$meta = $this->status_meta( $row['status'] );
				$link = esc_url( add_query_arg( array( 'wprsm_status' => $row['status'], 'wprsm_paged' => false ), $base_url ) );
				echo '<a href="' . $link . '" style="text-decoration:none;">';
				echo '<div style="border-left:4px solid ' . esc_attr( $meta['color'] ) . '; background:#fff; padding:10px 16px; min-width:140px; box-shadow:0 1px 1px rgba(0,0,0,.04);">';
				echo '<strong style="font-size:20px;">' . intval( $row['total'] ) . '</strong><br>';
				echo '<span style="color:' . esc_attr( $meta['color'] ) . '; font-weight:600;">' . esc_html( $meta['label'] ) . '</span>';
				echo '</div></a>';
			}
		} else {
			echo '<p>' . esc_html__( 'No preload records found yet.', 'wprsm' ) . '</p>';
		}
		echo '</div>';

		// Status legend.
		echo '<h3>' . esc_html__( 'Status legend', 'wprsm' ) . '</h3>';
		echo '<ul style="list-style:none; padding:0; display:flex; gap:20px; flex-wrap:wrap; margin-bottom:24px;">';
		foreach ( array( 'pending', 'in-progress', 'completed', 'failed' ) as $status_key ) {
			$meta = $this->status_meta( $status_key );
			echo '<li><span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:' . esc_attr( $meta['color'] ) . '; margin-right:6px;"></span>' . esc_html( $meta['label'] ) . '</li>';
		}
		echo '<li><span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#fff; border:2px solid #dba617; margin-right:6px;"></span>' . esc_html__( 'Stuck for more than 15 min (possibly stalled)', 'wprsm' ) . '</li>';
		echo '</ul>';

		// --- Point-in-time diagnosis of a single URL (if requested) ---
		if ( isset( $_GET['wprsm_check_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$check_id = intval( $_GET['wprsm_check_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $check_id && check_admin_referer( 'wprsm_check_' . $check_id, 'wprsm_check_nonce' ) ) {
				$check_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $check_id ) ); // phpcs:ignore
				if ( $check_row ) {
					$diag = $this->diagnose_url( $check_row->url );
					$box_class = $diag['ok'] ? 'notice-info' : 'notice-error';
					echo '<div class="notice ' . esc_attr( $box_class ) . '"><p><strong>' . esc_html__( 'Diagnosis', 'wprsm' ) . ':</strong> ' . esc_html( $check_row->url ) . '<br>' . esc_html( $diag['summary'] ) . '</p></div>';
				}
			}
		}

		// --- Fetch rows (respecting the status filter), up to the FETCH_CAP ceiling ---
		if ( $status_filter ) {
			$fetched = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY last_accessed DESC LIMIT %d", $status_filter, self::FETCH_CAP ) ); // phpcs:ignore
		} else {
			$fetched = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_accessed DESC LIMIT %d", self::FETCH_CAP ) ); // phpcs:ignore
		}

		$fetched_count = is_array( $fetched ) ? count( $fetched ) : 0;

		// Classify all URLs at once, using the persistent cache (performance).
		$urls_to_classify = wp_list_pluck( (array) $fetched, 'url' );
		$type_map         = $this->classify_urls_cached( $urls_to_classify );

		$type_labels = $this->type_labels();
		$type_counts = array_fill_keys( array_keys( $type_labels ), 0 );
		$classified  = array();
		$now_ts      = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		foreach ( (array) $fetched as $row ) {
			$type = isset( $type_map[ $row->url ] ) ? $type_map[ $row->url ] : 'other';
			$type_counts[ $type ]++;

			$ref_time  = ! empty( $row->last_accessed ) ? strtotime( $row->last_accessed ) : false;
			$is_stuck  = in_array( $row->status, array( 'pending', 'in-progress' ), true )
				&& $ref_time
				&& ( $now_ts - $ref_time ) > self::STUCK_THRESHOLD;

			$classified[] = array(
				'row'      => $row,
				'type'     => $type,
				'is_stuck' => $is_stuck,
			);
		}

		// Apply the type filter, if any.
		if ( $type_filter && isset( $type_labels[ $type_filter ] ) ) {
			$classified = array_values( array_filter( $classified, function( $item ) use ( $type_filter ) {
				return $item['type'] === $type_filter;
			} ) );
		}

		// --- Sorting ---
		$sortable = array( 'url', 'type', 'status', 'last_accessed' );
		if ( in_array( $orderby, $sortable, true ) ) {
			usort( $classified, function( $a, $b ) use ( $orderby, $order ) {
				if ( 'type' === $orderby ) {
					$va = $a['type'];
					$vb = $b['type'];
				} else {
					$va = $a['row']->{$orderby};
					$vb = $b['row']->{$orderby};
				}
				$cmp = strcmp( (string) $va, (string) $vb );
				return ( 'asc' === $order ) ? $cmp : -$cmp;
			} );
		}

		// --- Type filter (chips) ---
		echo '<h2>' . esc_html__( 'Filter by content type', 'wprsm' ) . '</h2>';
		echo '<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">';

		$clear_type_link = esc_url( remove_query_arg( array( 'wprsm_type', 'wprsm_paged' ), add_query_arg( array(), $base_url . ( $status_filter ? '&wprsm_status=' . rawurlencode( $status_filter ) : '' ) ) ) );
		$all_types_class = $type_filter ? 'button' : 'button button-primary';
		echo '<a href="' . $clear_type_link . '" class="' . esc_attr( $all_types_class ) . '">' . esc_html__( 'All types', 'wprsm' ) . '</a>';

		foreach ( $type_labels as $key => $label ) {
			$link_args = array( 'wprsm_type' => $key, 'wprsm_paged' => false );
			if ( $status_filter ) {
				$link_args['wprsm_status'] = $status_filter;
			}
			$link       = esc_url( add_query_arg( $link_args, $base_url ) );
			$is_current = ( $type_filter === $key );
			$btn_class  = $is_current ? 'button button-primary' : 'button';
			echo '<a href="' . $link . '" class="' . esc_attr( $btn_class ) . '">' . esc_html( $label ) . ' (' . intval( $type_counts[ $key ] ) . ')</a>';
		}
		echo '</div>';

		if ( $fetched_count >= self::FETCH_CAP ) {
			echo '<div class="notice notice-warning"><p>' . sprintf(
				/* translators: %d: max number of rows processed. */
				esc_html__( 'Showing the %d most recent records for this status filter. Narrow by status or type to see a different slice.', 'wprsm' ),
				intval( self::FETCH_CAP )
			) . '</p></div>';
		}

		// --- Pagination ---
		$total_filtered = count( $classified );
		$total_pages    = max( 1, (int) ceil( $total_filtered / self::PER_PAGE ) );
		$paged          = min( $paged, $total_pages );
		$offset         = ( $paged - 1 ) * self::PER_PAGE;
		$page_items     = array_slice( $classified, $offset, self::PER_PAGE );

		echo '<h2>' . esc_html__( 'URLs', 'wprsm' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: 1: total items in the current filter, 2: current page, 3: total pages. */
			esc_html__( '%1$d URL(s) in this filter — page %2$d of %3$d.', 'wprsm' ),
			intval( $total_filtered ),
			intval( $paged ),
			intval( $total_pages )
		) . '</p>';

		if ( ! $page_items ) {
			echo '<p>' . esc_html__( 'No URLs found for this filter.', 'wprsm' ) . '</p></div>';
			return;
		}

		// Helper to build a column's sort link.
		$sort_link = function( $column, $label ) use ( $base_url, $status_filter, $type_filter, $orderby, $order ) {
			$next_order = ( $orderby === $column && 'asc' === $order ) ? 'desc' : 'asc';
			$args       = array( 'wprsm_orderby' => $column, 'wprsm_order' => $next_order );
			if ( $status_filter ) {
				$args['wprsm_status'] = $status_filter;
			}
			if ( $type_filter ) {
				$args['wprsm_type'] = $type_filter;
			}
			$arrow = '';
			if ( $orderby === $column ) {
				$arrow = ( 'asc' === $order ) ? ' &uarr;' : ' &darr;';
			}
			return '<a href="' . esc_url( add_query_arg( $args, $base_url ) ) . '">' . esc_html( $label ) . $arrow . '</a>';
		};

		$redirect_to = esc_attr( $this->current_filtered_url() );

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . $sort_link( 'url', __( 'URL', 'wprsm' ) ) . '</th>'; // phpcs:ignore
		echo '<th>' . $sort_link( 'type', __( 'Type', 'wprsm' ) ) . '</th>'; // phpcs:ignore
		echo '<th>' . $sort_link( 'status', __( 'Status', 'wprsm' ) ) . '</th>'; // phpcs:ignore
		echo '<th>' . $sort_link( 'last_accessed', __( 'Last updated', 'wprsm' ) ) . '</th>'; // phpcs:ignore
		echo '<th>' . esc_html__( 'Actions', 'wprsm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $page_items as $item ) {
			$row        = $item['row'];
			$meta       = $this->status_meta( $row->status );
			$type_label = isset( $type_labels[ $item['type'] ] ) ? $type_labels[ $item['type'] ] : $item['type'];
			$row_style  = $item['is_stuck'] ? ' style="background:#fff8e5;"' : '';

			echo '<tr' . $row_style . '>'; // phpcs:ignore
			echo '<td><a href="' . esc_url( $row->url ) . '" target="_blank" rel="noopener">' . esc_html( $row->url ) . '</a></td>';
			echo '<td>' . esc_html( $type_label ) . '</td>';
			echo '<td><span style="color:' . esc_attr( $meta['color'] ) . '; font-weight:600;">&#9679; ' . esc_html( $meta['label'] );
			if ( $item['is_stuck'] ) {
				echo ' <span style="color:#dba617;" title="' . esc_attr__( 'Stuck for more than 15 minutes', 'wprsm' ) . '">&#9888;</span>';
			}
			echo '</span></td>';
			echo '<td>' . esc_html( isset( $row->last_accessed ) ? $row->last_accessed : '—' ) . '</td>';

			echo '<td style="white-space:nowrap;">';

			// Diagnose cause (mostly useful for failed rows, but kept available everywhere).
			$check_url = wp_nonce_url(
				add_query_arg( 'wprsm_check_id', $row->id, $this->current_filtered_url() ),
				'wprsm_check_' . $row->id,
				'wprsm_check_nonce'
			);
			echo '<a href="' . esc_url( $check_url ) . '" class="button button-small">' . esc_html__( 'Diagnose', 'wprsm' ) . '</a> ';

			// Generate cache now (priority, skips the queue).
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="wprsm_generate_cache">';
			echo '<input type="hidden" name="id" value="' . intval( $row->id ) . '">';
			echo '<input type="hidden" name="redirect_to" value="' . $redirect_to . '">'; // phpcs:ignore
			wp_nonce_field( 'wprsm_generate_cache_' . $row->id );
			echo '<button type="submit" class="button button-small button-primary">' . esc_html__( 'Generate cache now', 'wprsm' ) . '</button>';
			echo '</form> ';

			// Reload a single URL.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="wprsm_reload_url">';
			echo '<input type="hidden" name="id" value="' . intval( $row->id ) . '">';
			echo '<input type="hidden" name="redirect_to" value="' . $redirect_to . '">'; // phpcs:ignore
			wp_nonce_field( 'wprsm_reload_url_' . $row->id );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Reload', 'wprsm' ) . '</button>';
			echo '</form> ';

			// Remove from the queue.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Remove this URL from the preload queue?', 'wprsm' ) ) . '\');">';
			echo '<input type="hidden" name="action" value="wprsm_remove_url">';
			echo '<input type="hidden" name="id" value="' . intval( $row->id ) . '">';
			echo '<input type="hidden" name="redirect_to" value="' . $redirect_to . '">'; // phpcs:ignore
			wp_nonce_field( 'wprsm_remove_url_' . $row->id );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Remove', 'wprsm' ) . '</button>';
			echo '</form>';

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Pagination links.
		if ( $total_pages > 1 ) {
			echo '<div style="margin-top:16px; display:flex; gap:8px; align-items:center;">';

			$pag_base_args = array();
			if ( $status_filter ) {
				$pag_base_args['wprsm_status'] = $status_filter;
			}
			if ( $type_filter ) {
				$pag_base_args['wprsm_type'] = $type_filter;
			}
			if ( 'last_accessed' !== $orderby ) {
				$pag_base_args['wprsm_orderby'] = $orderby;
				$pag_base_args['wprsm_order']   = $order;
			}

			if ( $paged > 1 ) {
				$prev_link = esc_url( add_query_arg( array_merge( $pag_base_args, array( 'wprsm_paged' => $paged - 1 ) ), $base_url ) );
				echo '<a href="' . $prev_link . '" class="button">&laquo; ' . esc_html__( 'Previous', 'wprsm' ) . '</a>';
			}

			echo '<span>' . sprintf(
				/* translators: 1: current page, 2: total pages. */
				esc_html__( 'Page %1$d of %2$d', 'wprsm' ),
				intval( $paged ),
				intval( $total_pages )
			) . '</span>';

			if ( $paged < $total_pages ) {
				$next_link = esc_url( add_query_arg( array_merge( $pag_base_args, array( 'wprsm_paged' => $paged + 1 ) ), $base_url ) );
				echo '<a href="' . $next_link . '" class="button">' . esc_html__( 'Next', 'wprsm' ) . ' &raquo;</a>';
			}

			echo '</div>';
		}

		echo '</div>';
	}
}

new WPRSM_Status_Monitor();
