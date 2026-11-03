<?php
/**
 * Admin pages.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin page (Tools > Lostlink) and handles its actions.
 *
 * One page with three tabs: the dashboard of broken URLs, the full referer
 * list of one URL, and the settings.
 */
final class Admin {

	/**
	 * Slug of the admin page.
	 */
	public const PAGE = 'lostlink';

	/**
	 * Nonce action of the CSV export.
	 */
	public const EXPORT_ACTION = 'lostlink_export';

	/**
	 * Action of the fix form, for admin-post.php and its nonce.
	 */
	public const FIX_ACTION = 'lostlink_fix';

	/**
	 * Action of the redirect edit form, for admin-post.php and its nonce.
	 */
	public const EDIT_ACTION = 'lostlink_edit_redirect';

	/**
	 * Action of the Redirection 404 log import, for admin-post.php and its nonce.
	 */
	public const IMPORT_ACTION = 'lostlink_import_redirection';

	/**
	 * Seconds an import request runs before handing back, to stay below the
	 * usual PHP and proxy timeouts. The next click resumes it.
	 */
	private const IMPORT_TIME_LIMIT = 20.0;

	/**
	 * Prefix of the per-user transient holding the notices shown after a redirect.
	 */
	private const NOTICES_TRANSIENT = 'lostlink_notices_';

	/**
	 * Number of referers per page on the referers tab.
	 */
	public const REFERERS_PER_PAGE = 100;

	/**
	 * Dashboard table, built on page load so its actions and screen options
	 * are handled before any output.
	 *
	 * @var Dashboard_Table|null
	 */
	private static ?Dashboard_Table $table = null;

	/**
	 * Redirects table, built on page load like the dashboard table.
	 *
	 * @var Redirects_Table|null
	 */
	private static ?Redirects_Table $redirects_table = null;

	/**
	 * Hooks the admin pages into WordPress.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( Settings_Page::class, 'register' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'export_csv' ) );
		add_action( 'admin_post_' . self::FIX_ACTION, array( self::class, 'handle_fix' ) );
		add_action( 'admin_post_' . self::EDIT_ACTION, array( self::class, 'handle_edit' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( self::class, 'handle_import' ) );
		add_filter( 'set-screen-option', array( self::class, 'save_screen_option' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( LOSTLINK_FILE ), array( self::class, 'add_action_links' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_policy_content' ) );
	}

	/**
	 * Suggests a paragraph for the site's privacy policy, in the privacy policy
	 * guide (Settings > Privacy).
	 */
	public static function add_privacy_policy_content(): void {
		$text = __( 'When you visit an address of this site that does not exist (a "404" error), this address, the date and the address of the page you came from, if your browser sends it, are recorded to find and fix the broken links. No IP address, browser identifier or cookie is recorded. The addresses of the pages you came from are deleted after 90 days without a new visit, unless the site settings say otherwise.', 'lostlink' );
		wp_add_privacy_policy_content( 'Lostlink', wp_kses_post( wpautop( $text, false ) ) );
	}

	/**
	 * Returns the capability needed to see the dashboard and act on the URLs.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to use the dashboard.
		 *
		 * @param string $capability Default "manage_options".
		 */
		// @phpstan-ignore cast.useless (A callback can return any type.)
		return (string) apply_filters( 'lostlink_capability', 'manage_options' );
	}

	/**
	 * Returns the capability needed to change the settings.
	 */
	public static function settings_capability(): string {
		/**
		 * Filters the capability needed to change the settings.
		 *
		 * @param string $capability Default "manage_options".
		 */
		// @phpstan-ignore cast.useless (A callback can return any type.)
		return (string) apply_filters( 'lostlink_settings_capability', 'manage_options' );
	}

	/**
	 * Returns the URL of the admin page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Adds the page under the Tools menu.
	 */
	public static function add_menu(): void {
		$hook = add_management_page(
			__( 'Lostlink', 'lostlink' ),
			__( 'Lostlink', 'lostlink' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'load' ) );
		}
	}

	/**
	 * Adds a "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function add_action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'tab' => 'settings' ) ) ), esc_html__( 'Settings', 'lostlink' ) ) );
		return $links;
	}

	/**
	 * Returns the current tab.
	 */
	public static function current_tab(): string {
		$tab = Request::key( 'tab' ) ?? '';
		return in_array( $tab, array( 'referers', 'fix', 'redirects', 'edit', 'settings' ), true ) ? $tab : 'dashboard';
	}

	/**
	 * Runs on page load, before any output: assets, screen options and actions.
	 */
	public static function load(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		if ( 'redirects' === self::current_tab() ) {
			self::$redirects_table = new Redirects_Table( new Redirect_Store() );
			self::handle_redirects_action( self::$redirects_table );
			return;
		}
		if ( 'dashboard' !== self::current_tab() ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => Dashboard_Table::PER_PAGE_OPTION,
			)
		);
		self::$table = new Dashboard_Table( new Storage() );
		self::handle_action( self::$table );
	}

	/**
	 * Saves the "URLs per page" screen option.
	 *
	 * @param mixed  $status Value to save, false to not save.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, string $option, $value ) {
		return Dashboard_Table::PER_PAGE_OPTION === $option ? min( 500, max( 1, is_numeric( $value ) ? (int) $value : 0 ) ) : $status;
	}

	/**
	 * Enqueues the admin stylesheet and script.
	 *
	 * The script opens the fix and edit pages in a dialog, and makes "Use"
	 * fill the target field; without it, both work as plain pages.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style( 'lostlink-admin', plugins_url( 'css/admin.css', LOSTLINK_FILE ), array(), LOSTLINK_VERSION );
		wp_enqueue_script(
			'lostlink-admin',
			plugins_url( 'js/admin.js', LOSTLINK_FILE ),
			array(),
			LOSTLINK_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			'lostlink-admin',
			'window.lostlink = ' . wp_json_encode(
				array(
					'close'   => __( 'Close', 'lostlink' ),
					'loading' => __( 'Loading…', 'lostlink' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Applies a row or bulk action, then redirects back to the dashboard.
	 *
	 * Row actions are links carrying the same nonce as the bulk action form.
	 *
	 * @param Dashboard_Table $table The dashboard table.
	 */
	private static function handle_action( Dashboard_Table $table ): void {
		$action = $table->current_action();
		if ( false === $action ) {
			return;
		}

		check_admin_referer( 'bulk-' . Dashboard_Table::PLURAL );

		$status = $table->get_status();
		$hashes = Request::keys( 'items', Request::ANY );
		$back   = array_filter(
			array(
				'status'  => $status,
				's'       => Request::search( 's', Request::ANY ),
				'orderby' => Request::key( 'orderby', Request::ANY ) ?? '',
				'order'   => Request::key( 'order', Request::ANY ) ?? '',
				'paged'   => Request::int( 'paged', Request::ANY ) ?? 0,
			),
			static fn( string|int $value ): bool => '' !== $value && 0 !== $value
		);

		if ( array() === $hashes || ! array_key_exists( $action, $table->get_bulk_actions() ) ) {
			wp_safe_redirect( self::url( $back + array( 'lostlink_notice' => 'none' ) ) );
			exit;
		}

		$storage = new Storage();
		$count   = match ( $action ) {
			'mark_fixed' => $storage->set_state( $hashes, 'fixed' ),
			'ignore'     => $storage->set_state( $hashes, 'ignored' ),
			'unignore'   => $storage->set_state( $hashes, 'active' ),
			'delete'     => $storage->delete_by_hashes( $hashes ),
			// Not reached: checked against the bulk actions above.
			default      => 0,
		};

		wp_safe_redirect(
			self::url(
				$back + array(
					'lostlink_notice' => $action,
					'lostlink_count'  => $count,
				)
			)
		);
		exit;
	}

	/**
	 * Renders the admin page.
	 */
	public static function render(): void {
		$tab  = self::current_tab();
		$tabs = array(
			'dashboard' => __( 'Broken URLs', 'lostlink' ),
			/* translators: %s: number of redirects. */
			'redirects' => sprintf( __( 'Redirects (%s)', 'lostlink' ), number_format_i18n( ( new Redirect_Store() )->count() ) ),
		);
		if ( current_user_can( self::settings_capability() ) ) {
			$tabs['settings'] = __( 'Settings', 'lostlink' );
		}
		// The referers and fix pages belong to the dashboard, the edit page to the redirects.
		$active = match ( $tab ) {
			'referers', 'fix' => 'dashboard',
			'edit'            => 'redirects',
			default           => $tab,
		};
		?>
		<div class="wrap lostlink">
			<h1><?php esc_html_e( 'Lostlink', 'lostlink' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( 'dashboard' === $key ? array() : array( 'tab' => $key ) ) ); ?>"
						class="nav-tab<?php echo $key === $active ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			self::render_flash_notices();
			if ( 'settings' === $tab ) {
				Settings_Page::render();
			} elseif ( 'referers' === $tab ) {
				self::render_referers();
			} elseif ( 'fix' === $tab ) {
				self::render_fix();
			} elseif ( 'redirects' === $tab ) {
				self::render_redirects();
			} elseif ( 'edit' === $tab ) {
				self::render_edit();
			} else {
				self::render_dashboard();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Queues a notice for the current user, shown on the next page displayed.
	 *
	 * Used by the actions that redirect after running, so their outcome and
	 * warnings survive the redirect.
	 *
	 * @param string $type    "success", "warning" or "error".
	 * @param string $message Translated message, plain text.
	 */
	public static function flash( string $type, string $message ): void {
		$key       = self::NOTICES_TRANSIENT . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Renders and clears the queued notices of the current user.
	 */
	private static function render_flash_notices(): void {
		$key     = self::NOTICES_TRANSIENT . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $key );
		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) || ! is_string( $notice['message'] ?? null ) ) {
				continue;
			}
			wp_admin_notice(
				esc_html( $notice['message'] ),
				array(
					'type'        => in_array( $notice['type'] ?? null, array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info',
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * Renders the fix tab: the form creating the redirect of one broken URL.
	 */
	private static function render_fix(): void {
		$hash = Request::key( 'path_hash' ) ?? '';
		// Only shown escaped in the form, validated when submitted:
		// sanitize_text_field() would strip the percent-encoded characters.
		$target = Request::text( 'target' ) ?? '';
		$code   = Request::int( 'status_code' ) ?? 301;

		$record = ( new Storage() )->get_by_hash( $hash );
		$back   = sprintf( '<p><a href="%s">&larr; %s</a></p>', esc_url( self::url() ), esc_html__( 'Back to the broken URLs', 'lostlink' ) );
		if ( null === $record || '1' === $record['fixed'] ) {
			wp_admin_notice( esc_html__( 'This broken URL is no longer in the list, or is already fixed.', 'lostlink' ), array( 'type' => 'error' ) );
			echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			return;
		}

		$backend = Redirect_Service::create_default()->backend();
		echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		?>
		<div class="lostlink-panel">
		<h2 class="lostlink-panel-title">
			<?php
			/* translators: %s: broken URL path. */
			echo esc_html( sprintf( __( 'Fix %s', 'lostlink' ), $record['path'] ) );
			?>
		</h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of hits, 2: date. */
					_n( '%1$s hit, last seen on %2$s.', '%1$s hits, last seen on %2$s.', (int) $record['count'], 'lostlink' ),
					number_format_i18n( (int) $record['count'] ),
					self::format_date( (int) $record['last_seen'] )
				)
			);
			?>
		</p>
		<?php
		self::render_suggestions(
			$record['path'],
			array(
				'tab'         => 'fix',
				'path_hash'   => $hash,
				'status_code' => $code,
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::FIX_ACTION ); ?>" />
			<input type="hidden" name="path_hash" value="<?php echo esc_attr( $hash ); ?>" />
			<?php
			wp_nonce_field( self::FIX_ACTION . '_' . $hash );
			self::render_redirect_fields( $target, $code );
			?>
			<p>
				<?php
				printf(
					/* translators: %s: name of the redirect backend, linked to its list of redirects. */
					esc_html__( 'The redirect will be created in: %s.', 'lostlink' ),
					sprintf( '<a href="%s">%s</a>', esc_url( $backend->manage_url() ), esc_html( $backend->label() ) )
				);
				?>
			</p>
			<?php submit_button( __( 'Create redirect', 'lostlink' ) ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Renders the target and status code fields of the fix and edit forms.
	 *
	 * @param string $target      Target, as entered: only escaped here.
	 * @param int    $status_code Selected status code.
	 */
	private static function render_redirect_fields( string $target, int $status_code ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lostlink-target"><?php esc_html_e( 'Redirect to', 'lostlink' ); ?></label></th>
				<td>
					<input type="text" id="lostlink-target" name="target" value="<?php echo esc_attr( $target ); ?>" class="large-text code" required
						placeholder="/new-page/" aria-describedby="lostlink-target-description" />
					<p class="description" id="lostlink-target-description">
						<?php
						if ( current_user_can( self::settings_capability() ) ) {
							esc_html_e( 'A path of this site starting with "/", or a full URL. URLs of this site are saved as paths.', 'lostlink' );
						} else {
							esc_html_e( 'A path of this site starting with "/", or a full URL of this site.', 'lostlink' );
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lostlink-status-code"><?php esc_html_e( 'Redirect type', 'lostlink' ); ?></label></th>
				<td>
					<select id="lostlink-status-code" name="status_code">
						<?php foreach ( self::status_code_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>"<?php selected( $status_code, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Renders the suggested targets of a broken URL, on the fix and edit tabs.
	 *
	 * "Use" reloads the page with the target filled in, the redirect is only
	 * saved by submitting the form.
	 *
	 * @param string               $path     The broken path.
	 * @param array<string, mixed> $use_args Query arguments of the page reloaded by "Use", without the target.
	 */
	private static function render_suggestions( string $path, array $use_args ): void {
		$suggestions = ( new Path_Suggester( new Path_Provider() ) )->suggest( $path );
		?>
		<h3><?php esc_html_e( 'Suggested targets', 'lostlink' ); ?></h3>
		<?php if ( array() === $suggestions ) : ?>
			<p>
				<?php
				/* translators: %d: minimum similarity, in percent. */
				echo esc_html( sprintf( __( 'No published content has a URL close enough to this one (at least %d%% similar). Enter the target below.', 'lostlink' ), Path_Suggester::MIN_SCORE ) );
				?>
			</p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped lostlink-suggestions">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Target', 'lostlink' ); ?></th>
					<th scope="col" class="lostlink-narrow"><?php esc_html_e( 'Similarity', 'lostlink' ); ?></th>
					<th scope="col" class="lostlink-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'lostlink' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $suggestions as $suggestion ) :
					$use_url = self::url( $use_args + array( 'target' => rawurlencode( $suggestion['path'] ) ) ) . '#lostlink-target';
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( '' !== $suggestion['title'] ? $suggestion['title'] : __( '(no title)', 'lostlink' ) ); ?></strong>
							<span class="lostlink-type"><?php echo esc_html( '' !== $suggestion['language'] ? $suggestion['type'] . ' · ' . Languages::name( $suggestion['language'] ) : $suggestion['type'] ); ?></span><br />
							<code class="lostlink-url"><?php echo esc_html( Hit_Recorder::decode_path( $suggestion['path'] ) ); ?></code>
						</td>
						<td>
							<span class="lostlink-score" style="--lostlink-score: <?php echo absint( $suggestion['score'] ); ?>%;"></span>
							<?php echo esc_html( number_format_i18n( $suggestion['score'] ) . '%' ); ?>
						</td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( home_url( $suggestion['path'] ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'View', 'lostlink' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lostlink' ); ?></span>
							</a>
							<a class="button button-small button-primary lostlink-use" href="<?php echo esc_url( $use_url ); ?>" data-target="<?php echo esc_attr( $suggestion['path'] ); ?>"><?php esc_html_e( 'Use', 'lostlink' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Returns the labels of the redirect status codes.
	 *
	 * @return array<int, string>
	 */
	public static function status_code_labels(): array {
		return array(
			301 => __( '301 Moved Permanently (recommended)', 'lostlink' ),
			302 => __( '302 Found (temporary)', 'lostlink' ),
			307 => __( '307 Temporary Redirect', 'lostlink' ),
			308 => __( '308 Permanent Redirect', 'lostlink' ),
		);
	}

	/**
	 * Creates the redirect submitted by the fix form (admin-post handler).
	 */
	public static function handle_fix(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to fix broken URLs.', 'lostlink' ), 403 );
		}

		$hash = Request::key( 'path_hash', Request::POST ) ?? '';
		check_admin_referer( self::FIX_ACTION . '_' . $hash );

		// Validated by Redirect_Service::normalize_target(): sanitizing it here
		// would silently change the URL.
		$target = Request::text( 'target', Request::POST ) ?? '';
		$code   = Request::int( 'status_code', Request::POST ) ?? 301;

		$result = Redirect_Service::create_default()->fix( $hash, $target, $code, current_user_can( self::settings_capability() ) );
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect(
				self::url(
					array(
						'tab'         => 'fix',
						'path_hash'   => $hash,
						'target'      => rawurlencode( $target ),
						'status_code' => $code,
					)
				)
			);
			exit;
		}

		$record = ( new Storage() )->get_by_hash( $hash );
		self::flash(
			'success',
			sprintf(
				/* translators: 1: source path, 2: target, 3: redirect backend name. */
				__( 'Redirect created from %1$s to %2$s in %3$s.', 'lostlink' ),
				null !== $record ? $record['path'] : '',
				$result['target'],
				$result['backend']->label()
			)
		);
		foreach ( $result['warnings'] as $warning ) {
			self::flash( 'warning', $warning );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Renders the redirects tab: the redirects of the built-in store.
	 */
	private static function render_redirects(): void {
		$service = Redirect_Service::create_default();
		$backend = $service->backend();
		if ( 'builtin' !== $backend->id() ) {
			wp_admin_notice(
				sprintf(
					/* translators: %s: name of the redirect backend, linked to its list of redirects. */
					esc_html__( 'New redirects are created in %s. This list only shows the redirects created by Lostlink itself, which keep working.', 'lostlink' ),
					sprintf( '<a href="%s">%s</a>', esc_url( $backend->manage_url() ), esc_html( $backend->label() ) )
				),
				array( 'type' => 'info' )
			);
		}

		$table = self::$redirects_table ?? new Redirects_Table( new Redirect_Store() );
		$table->prepare_items();
		?>
		<p><?php esc_html_e( 'These redirects only apply to URLs that return a 404: they never hide an existing page. Deleting one puts its broken URL back on the dashboard.', 'lostlink' ); ?></p>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="tab" value="redirects" />
			<?php
			$table->search_box( __( 'Search redirects', 'lostlink' ), 'lostlink-redirects-search' );
			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * Applies a row or bulk action of the redirects table, then redirects back.
	 *
	 * @param Redirects_Table $table The redirects table.
	 */
	private static function handle_redirects_action( Redirects_Table $table ): void {
		if ( 'delete' !== $table->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-' . Redirects_Table::PLURAL );

		$ids     = Request::ints( 'redirects', Request::ANY );
		$deleted = ( new Redirect_Store() )->delete( $ids );

		// The deleted redirects no longer fix their URL: back on the dashboard.
		$storage = new Storage();
		foreach ( $deleted as $redirect ) {
			$record = $storage->get_by_hash( $redirect['source_hash'] );
			if ( null !== $record && '1' === $record['fixed_redirect'] ) {
				$storage->set_state( array( $redirect['source_hash'] ), 'unfixed' );
			}
		}

		self::flash(
			array() !== $deleted ? 'success' : 'warning',
			array() !== $deleted
				/* translators: %s: number of redirects. */
				? sprintf( _n( '%s redirect deleted.', '%s redirects deleted.', count( $deleted ), 'lostlink' ), number_format_i18n( count( $deleted ) ) )
				: __( 'Select at least one redirect.', 'lostlink' )
		);
		wp_safe_redirect( self::url( array( 'tab' => 'redirects' ) ) );
		exit;
	}

	/**
	 * Renders the edit tab: the form changing the target of a built-in redirect.
	 */
	private static function render_edit(): void {
		$id       = Request::int( 'redirect' ) ?? 0;
		$redirect = ( new Redirect_Store() )->get( $id );
		$back     = sprintf( '<p><a href="%s">&larr; %s</a></p>', esc_url( self::url( array( 'tab' => 'redirects' ) ) ), esc_html__( 'Back to the redirects', 'lostlink' ) );
		if ( null === $redirect ) {
			wp_admin_notice( esc_html__( 'This redirect no longer exists.', 'lostlink' ), array( 'type' => 'error' ) );
			echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			return;
		}

		// As on the fix tab: shown escaped, validated when submitted.
		$target = Request::text( 'target' ) ?? $redirect['target'];
		$code   = Request::int( 'status_code' ) ?? (int) $redirect['status_code'];

		echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		?>
		<div class="lostlink-panel">
		<h2 class="lostlink-panel-title">
			<?php
			/* translators: %s: redirect source path. */
			echo esc_html( sprintf( __( 'Edit the redirect of %s', 'lostlink' ), $redirect['source'] ) );
			?>
		</h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: current redirect target, 2: date. */
					__( 'Currently redirected to %1$s, since %2$s.', 'lostlink' ),
					$redirect['target'],
					self::format_date( (int) $redirect['created_at'] )
				)
			);
			echo ' ';
			echo esc_html(
				(int) $redirect['hits'] > 0
					? sprintf(
						/* translators: 1: number of hits, 2: date. */
						_n( 'Followed %1$s time, last on %2$s.', 'Followed %1$s times, last on %2$s.', (int) $redirect['hits'], 'lostlink' ),
						number_format_i18n( (int) $redirect['hits'] ),
						self::format_date( (int) $redirect['last_hit'] )
					)
					: __( 'Never followed yet.', 'lostlink' )
			);
			?>
		</p>
		<?php
		self::render_suggestions(
			$redirect['source'],
			array(
				'tab'         => 'edit',
				'redirect'    => $id,
				'status_code' => $code,
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::EDIT_ACTION ); ?>" />
			<input type="hidden" name="redirect" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php
			wp_nonce_field( self::EDIT_ACTION . '_' . $id );
			self::render_redirect_fields( $target, $code );
			?>
			<p class="description"><?php esc_html_e( 'The source can\'t be changed: it is the broken URL this redirect fixes. Its hit count is kept.', 'lostlink' ); ?></p>
			<?php submit_button( __( 'Save redirect', 'lostlink' ) ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Saves the redirect submitted by the edit form (admin-post handler).
	 */
	public static function handle_edit(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit redirects.', 'lostlink' ), 403 );
		}

		$id = Request::int( 'redirect', Request::POST ) ?? 0;
		check_admin_referer( self::EDIT_ACTION . '_' . $id );

		// Validated by Redirect_Service::normalize_target(), see handle_fix().
		$target = Request::text( 'target', Request::POST ) ?? '';
		$code   = Request::int( 'status_code', Request::POST ) ?? 301;

		$result = Redirect_Service::create_default()->edit( $id, $target, $code, current_user_can( self::settings_capability() ) );
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect(
				self::url(
					array(
						'tab'         => 'edit',
						'redirect'    => $id,
						'target'      => rawurlencode( $target ),
						'status_code' => $code,
					)
				)
			);
			exit;
		}

		self::flash(
			'success',
			sprintf(
				/* translators: 1: source path, 2: target. */
				__( 'Redirect from %1$s to %2$s saved.', 'lostlink' ),
				$result['source'],
				$result['target']
			)
		);
		foreach ( $result['warnings'] as $warning ) {
			self::flash( 'warning', $warning );
		}
		wp_safe_redirect( self::url( array( 'tab' => 'redirects' ) ) );
		exit;
	}

	/**
	 * Renders the dashboard tab.
	 */
	private static function render_dashboard(): void {
		$table = self::$table ?? new Dashboard_Table( new Storage() );
		$table->prepare_items();

		self::render_notice();
		self::render_import_offer();
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="status" value="<?php echo esc_attr( $table->get_status() ); ?>" />
			<?php
			$table->views();
			$table->search_box( __( 'Search broken URLs', 'lostlink' ), 'lostlink-search' );
			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * Offers to import the 404s Redirection logged before this plugin, if any.
	 *
	 * Importing changes the counters of the dashboard: reserved to the users
	 * who can change the settings.
	 */
	private static function render_import_offer(): void {
		if ( ! current_user_can( self::settings_capability() ) ) {
			return;
		}
		$importer = Redirection_Importer::create_default();
		$pending  = $importer->count_pending();
		$range    = $pending > 0 ? $importer->pending_range() : null;
		if ( null === $range ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: number of 404 hits, 2: date, 3: date. */
			_n(
				'The Redirection plugin logged %1$s 404 hit between %2$s and %3$s, before Lostlink started recording. Import it to see it in this dashboard.',
				'The Redirection plugin logged %1$s 404 hits between %2$s and %3$s, before Lostlink started recording. Import them to see them in this dashboard.',
				$pending,
				'lostlink'
			),
			number_format_i18n( $pending ),
			self::format_date( $range[0] ),
			self::format_date( $range[1] )
		);
		?>
		<div class="notice notice-info inline">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<p><?php echo esc_html( $message ); ?></p>
				<p class="description"><?php esc_html_e( 'The exclusion rules apply, and the hits on URLs already fixed are not counted. Later hits were recorded by both plugins: they are not imported again.', 'lostlink' ); ?></p>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
				<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
				<p><?php submit_button( __( 'Import the Redirection 404 log', 'lostlink' ), 'secondary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Imports the Redirection 404 log (admin-post handler).
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( self::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import 404s.', 'lostlink' ), 403 );
		}
		check_admin_referer( self::IMPORT_ACTION );

		$result = Redirection_Importer::create_default()->import( self::IMPORT_TIME_LIMIT );
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect( self::url() );
			exit;
		}
		self::flash(
			'success',
			sprintf(
				/* translators: 1: number of log rows read, 2: number of hits counted. */
				__( '%1$s 404 hits read from the Redirection log, %2$s counted (the others are excluded or on fixed URLs).', 'lostlink' ),
				number_format_i18n( $result['read'] ),
				number_format_i18n( $result['imported'] )
			)
		);
		if ( $result['remaining'] > 0 ) {
			self::flash(
				'warning',
				sprintf(
					/* translators: %s: number of log rows. */
					_n( '%s hit is left to import: import again to continue.', '%s hits are left to import: import again to continue.', $result['remaining'], 'lostlink' ),
					number_format_i18n( $result['remaining'] )
				)
			);
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Renders the notice of the action just applied, if any.
	 */
	private static function render_notice(): void {
		$notice = Request::key( 'lostlink_notice' ) ?? '';
		$count  = Request::int( 'lostlink_count' ) ?? 0;

		if ( 'none' === $notice ) {
			wp_admin_notice( esc_html__( 'Select at least one URL and an action.', 'lostlink' ), array( 'type' => 'warning' ) );
			return;
		}

		$messages = array(
			/* translators: %s: number of URLs. */
			'mark_fixed' => _n( '%s URL marked as fixed.', '%s URLs marked as fixed.', $count, 'lostlink' ),
			/* translators: %s: number of URLs. */
			'ignore'     => _n( '%s URL ignored.', '%s URLs ignored.', $count, 'lostlink' ),
			/* translators: %s: number of URLs. */
			'unignore'   => _n( '%s URL restored.', '%s URLs restored.', $count, 'lostlink' ),
			/* translators: %s: number of URLs. */
			'delete'     => _n( '%s URL deleted.', '%s URLs deleted.', $count, 'lostlink' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			wp_admin_notice(
				esc_html( sprintf( $messages[ $notice ], number_format_i18n( $count ) ) ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * Renders the referers tab: all the referers of one broken URL.
	 */
	private static function render_referers(): void {
		$hash  = Request::key( 'path_hash' ) ?? '';
		$paged = max( 1, Request::int( 'paged' ) ?? 1 );

		$storage = new Storage();
		$record  = $storage->get_by_hash( $hash );
		$back    = sprintf( '<p><a href="%s">&larr; %s</a></p>', esc_url( self::url() ), esc_html__( 'Back to the broken URLs', 'lostlink' ) );

		if ( null === $record ) {
			wp_admin_notice( esc_html__( 'This broken URL no longer exists.', 'lostlink' ), array( 'type' => 'error' ) );
			echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			return;
		}

		$total    = $storage->count_referers( array( $hash ) )[ $hash ] ?? 0;
		$referers = $storage->get_referers( $hash, self::REFERERS_PER_PAGE, ( $paged - 1 ) * self::REFERERS_PER_PAGE );
		?>
		<?php echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
		<h2>
			<?php
			/* translators: %s: broken URL path. */
			echo esc_html( sprintf( __( 'Referers of %s', 'lostlink' ), $record['path'] ) );
			?>
		</h2>
		<p>
			<?php
			/* translators: %s: number of referers. */
			echo esc_html( sprintf( _n( '%s referer recorded.', '%s referers recorded.', $total, 'lostlink' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Referer', 'lostlink' ); ?></th>
					<th scope="col" class="lostlink-narrow"><?php esc_html_e( 'Hits', 'lostlink' ); ?></th>
					<th scope="col" class="lostlink-date"><?php esc_html_e( 'Last seen', 'lostlink' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $referers as $referer ) : ?>
					<tr>
						<td class="lostlink-url"><?php echo self::referer_link( $referer['referer'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in referer_link(). ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $referer['count'] ) ); ?></td>
						<td><?php echo esc_html( self::format_date( (int) $referer['last_seen'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $referers ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No referer recorded.', 'lostlink' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		$pages = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => (int) ceil( $total / self::REFERERS_PER_PAGE ),
			)
		);
		if ( null !== $pages ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $pages ) . '</div></div>';
		}
	}

	/**
	 * Returns a referer as a link opening in a new tab, or as plain text when
	 * it isn't a safe URL (a referer is attacker-controlled).
	 *
	 * @param string $referer The referer.
	 * @return string HTML, escaped.
	 */
	public static function referer_link( string $referer ): string {
		$url = esc_url( $referer, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return esc_html( $referer );
		}
		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a>', $url, esc_html( $referer ) );
	}

	/**
	 * Formats a timestamp in the site's date and time format and timezone.
	 *
	 * @param int $time Timestamp.
	 */
	public static function format_date( int $time ): string {
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		return (string) wp_date( ( is_string( $date_format ) ? $date_format : 'Y-m-d' ) . ' ' . ( is_string( $time_format ) ? $time_format : 'H:i' ), $time );
	}

	/**
	 * Streams the broken URLs as CSV, with the dashboard filters (admin-post handler).
	 */
	public static function export_csv(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export the broken URLs.', 'lostlink' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$args = array(
			'status'  => 'ignored' === Request::text( 'status' ) ? 'ignored' : 'active',
			'search'  => Request::search( 's' ),
			'orderby' => Request::key( 'orderby' ) ?? 'last_seen',
			'order'   => Request::key( 'order' ) ?? 'desc',
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lostlink-' . $args['status'] . '-' . wp_date( 'Y-m-d-His' ) . '.csv"' );

		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to the output.
		if ( false !== $handle ) {
			self::write_csv( $handle, new Storage(), $args, time() );
		}
		exit;
	}

	/**
	 * Writes the broken URLs matching the dashboard filters as CSV.
	 *
	 * Read in batches, so a large table never has to fit in memory.
	 *
	 * @param resource             $handle  Writable stream.
	 * @param Storage              $storage The storage.
	 * @param array<string, mixed> $args    Filters, see Storage::query_paths().
	 * @param int                  $now     Current timestamp, for the priority.
	 *
	 * @phpstan-param array{status?: string, search?: string, orderby?: string, order?: string} $args
	 */
	public static function write_csv( $handle, Storage $storage, array $args, int $now ): void {
		$settings = Settings::all();
		$batch    = 1000;

		fputcsv( $handle, array( 'path', 'count', 'daily_count', 'week_count', 'first_seen', 'last_seen', 'priority', 'ignored' ), ',', '"', '' );
		for ( $offset = 0; ; $offset += $batch ) {
			$rows = $storage->query_paths(
				$args + array(
					'limit'  => $batch,
					'offset' => $offset,
				)
			);
			foreach ( $rows as $row ) {
				fputcsv(
					$handle,
					array(
						$row['path'],
						$row['count'],
						$row['daily_count'],
						$row['week_count'],
						wp_date( 'Y-m-d H:i:s', (int) $row['first_seen'] ),
						wp_date( 'Y-m-d H:i:s', (int) $row['last_seen'] ),
						Priority::compute( $row, $now, $settings )->value,
						'1' === $row['ignored'] ? 'yes' : 'no',
					),
					',',
					'"',
					''
				);
			}
			if ( count( $rows ) < $batch ) {
				break;
			}
		}//end for
	}
}
