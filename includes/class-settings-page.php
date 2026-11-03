<?php
/**
 * Settings page.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab of the admin page, built with the Settings API.
 */
final class Settings_Page {

	/**
	 * Settings group, the option_page of the form.
	 */
	public const GROUP = 'lostlink';

	/**
	 * Field labels, keyed by setting.
	 *
	 * @var array<string, string>
	 */
	private static array $labels = array();

	/**
	 * Registers the setting, its sections and fields (admin_init).
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::DEFAULTS,
			)
		);
		// options.php checks this capability, manage_options by default.
		add_filter( 'option_page_capability_' . self::GROUP, array( Admin::class, 'settings_capability' ) );

		add_settings_section( 'redirects', __( 'Redirects', 'lostlink' ), '__return_null', Admin::PAGE );
		$redirection = new Redirection_Backend();
		self::add_field(
			'redirect_backend',
			'redirects',
			__( 'Create redirects in', 'lostlink' ),
			'select',
			__( 'Where the redirects fixing the 404s are created. Existing redirects stay where they are when this changes. With the Redirection plugin, they are created in its first group served by WordPress.', 'lostlink' ),
			array(
				'auto'        => __( 'Automatic: the Redirection plugin if it is set up, built-in otherwise', 'lostlink' ),
				'builtin'     => __( 'Lostlink (built in)', 'lostlink' ),
				'redirection' => $redirection->is_available()
					? $redirection->label()
					: __( 'Redirection plugin (not installed or not set up: built in is used meanwhile)', 'lostlink' ),
			)
		);

		add_settings_section( 'logging', __( '404 logging', 'lostlink' ), '__return_null', Admin::PAGE );
		self::add_field(
			'log_assets',
			'logging',
			__( 'Asset 404s', 'lostlink' ),
			'checkbox',
			__( 'Log the 404 errors of assets (CSS, JS, images, fonts). Enable this only to debug missing assets.', 'lostlink' )
		);
		self::add_field(
			'exclude_patterns',
			'logging',
			__( 'Exclusion patterns', 'lostlink' ),
			'textarea',
			sprintf(
				/* translators: 1: glob example, 2: regex example. */
				__( 'One pattern per line. A path matching any of them is never logged (unlike "Ignore" on the dashboard, which hides a URL already logged). Each line is either a path or glob, e.g. %1$s, or a regex delimited by #, ~ or %%, e.g. %2$s.', 'lostlink' ),
				'<code>/feed/*</code>',
				'<code>#^/\.env#</code>'
			)
		);
		self::add_field(
			'cached_404s',
			'logging',
			__( '404s served by a page cache', 'lostlink' ),
			'select',
			__( 'A page cache plugin can serve a 404 page without running WordPress, and so without this plugin. To count these hits, the 404 page then includes a small script that reports each view. Only visitors running JavaScript are counted this way, which leaves out most bots.', 'lostlink' ),
			array(
				'auto'   => Cache_Beacon::has_page_cache()
					? __( 'Automatic: yes, a page cache is installed', 'lostlink' )
					: __( 'Automatic: no, no page cache is installed', 'lostlink' ),
				'always' => __( 'Always (e.g. for a cache outside WordPress: Varnish, nginx, CDN)', 'lostlink' ),
				'never'  => __( 'Never', 'lostlink' ),
			)
		);

		add_settings_section( 'suggestions', __( 'Suggestions', 'lostlink' ), '__return_null', Admin::PAGE );
		self::add_field(
			'sitemap_url',
			'suggestions',
			__( 'Sitemap', 'lostlink' ),
			'text',
			sprintf(
				/* translators: 1: WordPress sitemap path, 2: SEO plugins sitemap path. */
				__( 'The published content is always suggested. To also suggest the pages it doesn\'t cover (custom routes, other software on the same domain), enter the sitemap listing them, e.g. %1$s (WordPress) or %2$s (most SEO plugins). Only the URLs of this site are kept. Leave empty to not use a sitemap.', 'lostlink' ),
				'<code>/wp-sitemap.xml</code>',
				'<code>/sitemap_index.xml</code>'
			)
		);

		add_settings_section(
			'priority',
			__( 'Priority thresholds', 'lostlink' ),
			static function (): void {
				echo '<p>' . esc_html__( 'The dashboard shows a Critical / High / Medium / Low priority on each broken URL, based on how often it is hit. The defaults suit a medium-traffic site: lower them on a low-traffic site so real problems stop hiding as Low, raise them on a high-traffic site so bot noise stops showing as Critical.', 'lostlink' ) . '</p>';
			},
			Admin::PAGE
		);
		self::add_field( 'priority_critical_count', 'priority', __( 'Critical: total hits', 'lostlink' ), 'number', __( 'A URL is Critical once hit more than this many times in total.', 'lostlink' ) );
		self::add_field( 'priority_critical_daily_count', 'priority', __( 'Critical: daily hits', 'lostlink' ), 'number', __( 'A URL is also Critical when hit more than this many times in a day, if last seen within the recency window below. Catches a URL that suddenly gets hammered.', 'lostlink' ) );
		self::add_field( 'priority_critical_recent_days', 'priority', __( 'Critical: recency window (days)', 'lostlink' ), 'number', __( 'How many days back count as recent for the daily hits rule above.', 'lostlink' ) );
		self::add_field( 'priority_high_week_count', 'priority', __( 'High: weekly hits', 'lostlink' ), 'number', __( 'A URL that is not Critical is High once hit more than this many times this week.', 'lostlink' ) );
		self::add_field( 'priority_medium_daily_count', 'priority', __( 'Medium: daily hits', 'lostlink' ), 'number', __( 'A URL that is not Critical or High is Medium once hit more than this many times today.', 'lostlink' ) );
		self::add_field( 'priority_medium_recent_days', 'priority', __( 'Medium: recency window (days)', 'lostlink' ), 'number', __( 'A URL last seen within this many days is at least Medium, so recently broken links are not buried as Low.', 'lostlink' ) );

		add_settings_section(
			'retention',
			__( 'Data retention', 'lostlink' ),
			static function (): void {
				/* translators: %d: number of days. */
				echo '<p>' . esc_html( sprintf( __( 'Broken URLs and referers come from anonymous traffic, so their number is capped. An hourly task removes what goes beyond these limits, and the daily history older than the %d days shown in the trend.', 'lostlink' ), Storage::HISTORY_DAYS ) ) . '</p>';
			},
			Admin::PAGE
		);
		self::add_field( 'row_limit', 'retention', __( 'Maximum number of broken URLs', 'lostlink' ), 'number', __( 'Beyond this number, the least recently seen URLs are deleted, with their referers and history. 0 for no limit.', 'lostlink' ) );
		self::add_field( 'referer_limit', 'retention', __( 'Maximum number of referers per URL', 'lostlink' ), 'number', __( 'Once reached, new referers of that URL are no longer recorded (its hits still are). 0 for no limit.', 'lostlink' ) );
		self::add_field( 'referer_retention_days', 'retention', __( 'Referer retention (days)', 'lostlink' ), 'number', __( 'Referers not seen for this many days are deleted, as referer URLs can contain personal data. 0 to keep them.', 'lostlink' ) );
	}

	/**
	 * Adds a settings field.
	 *
	 * @param string                $key         Setting key, see Settings::DEFAULTS.
	 * @param string                $section     Section ID.
	 * @param string                $label       Field label.
	 * @param string                $type        "checkbox", "text", "textarea", "number" or "select".
	 * @param string                $description Help text, may contain <code>.
	 * @param array<string, string> $options Options of a select, labels keyed by value.
	 */
	private static function add_field( string $key, string $section, string $label, string $type, string $description, array $options = array() ): void {
		self::$labels[ $key ] = $label;

		$args = array(
			'key'         => $key,
			'type'        => $type,
			'description' => $description,
			'options'     => $options,
		);
		// A checkbox has its own label, around it.
		if ( 'checkbox' !== $type ) {
			$args['label_for'] = 'lostlink-' . $key;
		}
		add_settings_field(
			$key,
			$label,
			array( self::class, 'render_field' ),
			Admin::PAGE,
			$section,
			$args
		);
	}

	/**
	 * Returns the label of a setting field, or its key if it has none.
	 *
	 * @param string $key Setting key.
	 */
	public static function label( string $key ): string {
		return self::$labels[ $key ] ?? $key;
	}

	/**
	 * Renders a settings field.
	 *
	 * @param array{key: string, type: string, description: string, options: array<string, string>} $args Field arguments, see add_field().
	 */
	public static function render_field( array $args ): void {
		$key   = $args['key'];
		$id    = 'lostlink-' . $key;
		$name  = Settings::OPTION . '[' . $key . ']';
		$value = Settings::all()[ $key ] ?? null;
		$help  = wp_kses( $args['description'], array( 'code' => array() ) );

		if ( 'checkbox' === $args['type'] ) {
			printf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( (bool) $value, true, false ),
				$help // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() above.
			);
			return;
		}

		if ( 'select' === $args['type'] ) {
			printf( '<select id="%1$s" name="%2$s" aria-describedby="%1$s-description">', esc_attr( $id ), esc_attr( $name ) );
			foreach ( $args['options'] as $option => $option_label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $option_label ) );
			}
			echo '</select>';
		} elseif ( 'text' === $args['type'] ) {
			printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text code" aria-describedby="%1$s-description" />', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
		} elseif ( 'textarea' === $args['type'] ) {
			printf( '<textarea id="%1$s" name="%2$s" rows="6" class="large-text code" aria-describedby="%1$s-description">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
		} else {
			printf(
				'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$d" step="1" class="small-text" aria-describedby="%1$s-description" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				absint( Settings::NUMBER_MINIMUMS[ $key ] ?? 0 )
			);
		}
		printf( '<p class="description" id="%s-description">%s</p>', esc_attr( $id ), $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() above.
	}

	/**
	 * Renders the settings tab.
	 */
	public static function render(): void {
		if ( ! current_user_can( Admin::settings_capability() ) ) {
			wp_admin_notice( esc_html__( 'Sorry, you are not allowed to change these settings.', 'lostlink' ), array( 'type' => 'error' ) );
			return;
		}

		// Unlike the Settings menu pages, a Tools page doesn't show them by itself.
		settings_errors();
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( self::GROUP );
			do_settings_sections( Admin::PAGE );
			submit_button();
			?>
		</form>
		<?php
	}
}
