<?php
/**
 * Counting of the 404s served by a page cache.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the 404 pages a page cache serves without running WordPress.
 *
 * A page cache plugin (or Varnish, nginx, a CDN) can store a 404 page and
 * serve it again without loading WordPress: Hit_Recorder never sees these
 * hits. The 404 page then includes a small script reporting each view to a
 * REST route, with the request URI and the time the page was generated,
 * signed so only the 404s WordPress really served can be reported.
 *
 * The view of the request that generated the page was already recorded by
 * Hit_Recorder: a report received within RENDER_WINDOW seconds of the
 * generation comes from it and is not counted again. Later reports come
 * from cached copies. Nothing is stored per page generated: bots hit 404s
 * far more than visitors and never run the script.
 *
 * Only visitors running JavaScript are counted this way, and a cached copy
 * viewed within RENDER_WINDOW of its generation is missed.
 */
class Cache_Beacon {

	/**
	 * REST namespace and route of the reports.
	 */
	public const REST_NAMESPACE = 'lostlink/v1';
	public const REST_ROUTE     = '/cached-hit';

	/**
	 * Seconds after the generation of a page during which a report is the
	 * view of the request that generated it.
	 */
	public const RENDER_WINDOW = 30;

	/**
	 * Age after which a report is ignored, in seconds: no page cache keeps a
	 * 404 page that long.
	 */
	public const MAX_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Hit_Recorder $recorder Records the reported hits.
	 */
	public function __construct( protected Hit_Recorder $recorder ) {}

	/**
	 * Hooks the script and the REST route into WordPress.
	 */
	public function register(): void {
		add_action( 'wp_footer', array( $this, 'print_script' ) );
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Whether the 404 pages include the script, see the "cached_404s" setting.
	 *
	 * "auto" includes it when a page cache plugin is installed.
	 */
	public static function is_enabled(): bool {
		return match ( Settings::all()['cached_404s'] ) {
			'auto'   => self::has_page_cache(),
			'always' => true,
			'never'  => false,
		};
	}

	/**
	 * Whether a page cache plugin is installed: they all set WP_CACHE, which
	 * makes WordPress load their advanced-cache.php.
	 */
	public static function has_page_cache(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * Returns the signature of a report.
	 *
	 * @param string $uri  Request URI of the 404 page.
	 * @param int    $time Time the page was generated.
	 */
	public static function sign( string $uri, int $time ): string {
		return hash_hmac( 'sha256', $time . '|' . $uri, wp_salt( 'auth' ) );
	}

	/**
	 * Prints the reporting script on the 404 pages that are recorded (wp_footer).
	 */
	public function print_script(): void {
		if ( ! is_404() || ! self::is_enabled() ) {
			return;
		}

		$uri = Request::server( 'REQUEST_URI' ) ?? '/';
		if ( ! $this->recorder->should_record( Hit_Recorder::decode_path( Hit_Recorder::get_raw_path( $uri ) ) ) ) {
			return;
		}

		$time = time();
		$data = array(
			'uri'  => $uri,
			'time' => $time,
			'sig'  => self::sign( $uri, $time ),
		);
		// sendBeacon() with form data: no CORS preflight, and the request
		// survives the visitor leaving the page.
		wp_print_inline_script_tag(
			sprintf(
				'(function(){if(!navigator.sendBeacon){return;}var d=new URLSearchParams(%s);d.append("referer",document.referrer);navigator.sendBeacon(%s,d);})();',
				wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ),
				wp_json_encode( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
			),
			array( 'id' => 'lostlink-cache-beacon' )
		);
	}

	/**
	 * Registers the REST route receiving the reports (rest_api_init).
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_report' ),
				// Public: sent by anonymous visitors. The signature restricts
				// it to the 404s WordPress served.
				'permission_callback' => '__return_true',
				'args'                => array(
					'uri'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'time'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					'sig'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'referer' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Counts a reported view of a cached 404 page.
	 *
	 * Always answers 204, so a report gives no information back.
	 *
	 * @param \WP_REST_Request $request The report.
	 */
	public function handle_report( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var array{uri: string, time: int, sig: string, referer: string} $params Typed by the route arguments. */
		$params = $request->get_params();
		$this->report( $params['uri'], $params['time'], $params['sig'], $params['referer'], time() );
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Counts a reported view if it is valid and comes from a cached copy.
	 *
	 * Not reopening the URLs fixed by a redirect: a cache can keep serving
	 * the 404 page of a URL fixed since.
	 *
	 * @param string $uri     Request URI of the 404 page.
	 * @param int    $time    Time the page was generated.
	 * @param string $sig     Signature, see sign().
	 * @param string $referer The page the visitor came from, if any.
	 * @param int    $now     Current timestamp.
	 * @return bool Whether the hit was counted.
	 */
	public function report( string $uri, int $time, string $sig, string $referer, int $now ): bool {
		if ( ! self::is_enabled() || ! hash_equals( self::sign( $uri, $time ), $sig ) ) {
			return false;
		}
		$age = $now - $time;
		if ( $age < self::RENDER_WINDOW || $age > self::MAX_AGE ) {
			return false;
		}

		return $this->recorder->record( $uri, '' !== $referer ? $referer : null, $now );
	}
}
