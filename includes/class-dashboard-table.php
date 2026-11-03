<?php
/**
 * Dashboard of the broken URLs.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists the broken URLs: sortable, searchable, paginated, with row and bulk actions.
 *
 * Two views: the active URLs, and the ignored ones. URLs marked as fixed
 * are not listed.
 *
 * @phpstan-import-type Path_Row from Storage
 * @phpstan-import-type Values from Settings
 */
class Dashboard_Table extends \WP_List_Table {

	/**
	 * Plural name, also used in the bulk action nonce ("bulk-{plural}").
	 */
	public const PLURAL = 'lostlink-urls';

	/**
	 * User option storing the number of URLs per page.
	 */
	public const PER_PAGE_OPTION = 'lostlink_per_page';

	/**
	 * Number of referers shown inline for each URL.
	 */
	public const INLINE_REFERERS = 5;

	/**
	 * Referer counts of the listed URLs, keyed by path hash.
	 *
	 * @var array<string, int>
	 */
	protected array $referer_counts = array();

	/**
	 * Daily history of the listed URLs, keyed by path hash then by day.
	 *
	 * @var array<string, array<string, int>>
	 */
	protected array $history = array();

	/**
	 * Settings, read once for all the rows.
	 *
	 * @var Values
	 */
	protected array $settings;

	/**
	 * Timestamp the priorities and trends are computed at.
	 *
	 * @var int
	 */
	protected int $now;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage The hits storage.
	 */
	public function __construct( protected Storage $storage ) {
		parent::__construct(
			array(
				'singular' => 'lostlink-url',
				'plural'   => self::PLURAL,
				'ajax'     => false,
				'screen'   => 'tools_page_' . Admin::PAGE,
			)
		);
		$this->now      = time();
		$this->settings = Settings::all();
	}

	/**
	 * Returns the current view: "active" or "ignored".
	 */
	public function get_status(): string {
		return 'ignored' === Request::text( 'status', Request::ANY ) ? 'ignored' : 'active';
	}

	/**
	 * Returns the search string.
	 */
	protected function get_search(): string {
		return Request::search( 's', Request::ANY );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox" />',
			'path'        => __( 'Broken URL', 'lostlink' ),
			'last_seen'   => __( 'Last seen', 'lostlink' ),
			'count'       => __( 'Total hits', 'lostlink' ),
			'daily_count' => __( 'Today', 'lostlink' ),
			'week_count'  => __( 'This week', 'lostlink' ),
			'referers'    => __( 'Referers', 'lostlink' ),
			'priority'    => __( 'Priority', 'lostlink' ),
			'trend'       => __( 'Trend', 'lostlink' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'path'        => array( 'path', false ),
			'last_seen'   => array( 'last_seen', true ),
			'count'       => array( 'count', true ),
			'daily_count' => array( 'daily_count', true ),
			'week_count'  => array( 'week_count', true ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_default_primary_column_name(): string {
		return 'path';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Public: Admin checks a submitted action against this list.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		if ( 'ignored' === $this->get_status() ) {
			return array(
				'unignore' => __( 'Restore', 'lostlink' ),
				'delete'   => __( 'Delete', 'lostlink' ),
			);
		}

		return array(
			'mark_fixed' => __( 'Mark as fixed', 'lostlink' ),
			'ignore'     => __( 'Ignore', 'lostlink' ),
			'delete'     => __( 'Delete', 'lostlink' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$views = array();
		foreach (
			array(
				'active'  => __( 'Active', 'lostlink' ),
				'ignored' => __( 'Ignored', 'lostlink' ),
			) as $status => $label
		) {
			$count            = $this->storage->count_paths( array( 'status' => $status ) );
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( Admin::url( 'active' === $status ? array() : array( 'status' => $status ) ) ),
				$status === $this->get_status() ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		return $views;
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$orderby = Request::key( 'orderby' ) ?? 'last_seen';
		$order   = Request::key( 'order' ) ?? 'desc';

		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );
		$args     = array(
			'status' => $this->get_status(),
			'search' => $this->get_search(),
		);
		$total    = $this->storage->count_paths( $args );

		$items       = $this->storage->query_paths(
			$args + array(
				'orderby' => $orderby,
				'order'   => $order,
				'limit'   => $per_page,
				'offset'  => ( $this->get_pagenum() - 1 ) * $per_page,
			)
		);
		$this->items = $items;
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);

		$hashes               = array_column( $items, 'path_hash' );
		$this->referer_counts = $this->storage->count_referers( $hashes );
		$this->history        = $this->storage->get_daily_history(
			$hashes,
			Storage::local_date( $this->now )->modify( '-' . ( Storage::HISTORY_DAYS - 1 ) . ' days' )->format( 'Y-m-d' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		if ( '' !== $this->get_search() ) {
			esc_html_e( 'No broken URL matches your search.', 'lostlink' );
		} elseif ( 'ignored' === $this->get_status() ) {
			esc_html_e( 'No ignored URL.', 'lostlink' );
		} else {
			esc_html_e( 'No broken URL detected yet. The 404 errors of your site will appear here as visitors and bots hit them.', 'lostlink' );
		}
	}

	/**
	 * Adds the CSV export link next to the bulk actions.
	 *
	 * @param string $which "top" or "bottom".
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || ! $this->has_items() ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'  => Admin::EXPORT_ACTION,
						'status'  => $this->get_status(),
						's'       => $this->get_search(),
						'orderby' => Request::key( 'orderby' ) ?? '',
						'order'   => Request::key( 'order' ) ?? '',
					),
					static fn( string $value ): bool => '' !== $value
				),
				admin_url( 'admin-post.php' )
			),
			Admin::EXPORT_ACTION
		);
		printf( '<div class="alignleft actions"><a class="button" href="%s">%s</a></div>', esc_url( $url ), esc_html__( 'Export CSV', 'lostlink' ) );
	}

	/**
	 * Renders the checkbox column.
	 *
	 * @param Path_Row $item A record of the paths table.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="lostlink-%1$s">%2$s</label><input type="checkbox" id="lostlink-%1$s" name="items[]" value="%1$s" />',
			esc_attr( $item['path_hash'] ),
			/* translators: %s: path of the row, a broken URL or a redirect source. */
			esc_html( sprintf( __( 'Select %s', 'lostlink' ), $item['path'] ) )
		);
	}

	/**
	 * Renders the path column, with the row actions.
	 *
	 * @param array $item A record of the paths table.
	 *
	 * @phpstan-param Path_Row $item
	 */
	protected function column_path( array $item ): string {
		$status = $this->get_status();
		$link   = static function ( string $action, string $label, string $hash, string $confirm = '' ) use ( $status ): string {
			$url = wp_nonce_url(
				Admin::url(
					array(
						'action'  => $action,
						'items[]' => $hash,
						'status'  => $status,
					)
				),
				'bulk-' . self::PLURAL
			);
			return sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				'' === $confirm ? '' : ' onclick="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ');"',
				esc_html( $label )
			);
		};

		$hash = $item['path_hash'];
		if ( 'ignored' === $this->get_status() ) {
			$actions = array(
				'unignore' => $link( 'unignore', __( 'Restore', 'lostlink' ), $hash ),
			);
		} else {
			$actions = array(
				'fix'        => sprintf(
					'<a href="%s" class="lostlink-modal">%s</a>',
					esc_url(
						Admin::url(
							array(
								'tab'       => 'fix',
								'path_hash' => $hash,
							)
						)
					),
					esc_html__( 'Fix with a redirect', 'lostlink' )
				),
				'mark_fixed' => $link( 'mark_fixed', __( 'Mark as fixed', 'lostlink' ), $hash, __( 'Mark this URL as fixed? Its hits will no longer be counted.', 'lostlink' ) ),
				'ignore'     => $link( 'ignore', __( 'Ignore', 'lostlink' ), $hash ),
			);
		}//end if
		$actions['delete'] = $link( 'delete', __( 'Delete', 'lostlink' ), $hash, __( 'Delete this URL with its referers and history?', 'lostlink' ) );

		return sprintf(
			'<strong class="lostlink-url"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></strong>%s',
			esc_url( home_url( $item['path'] ) ),
			esc_html( $item['path'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Renders the last seen column.
	 *
	 * @param array $item A record of the paths table.
	 *
	 * @phpstan-param Path_Row $item
	 */
	protected function column_last_seen( array $item ): string {
		return sprintf(
			'<time datetime="%s" title="%s">%s</time>',
			esc_attr( (string) wp_date( 'c', (int) $item['last_seen'] ) ),
			/* translators: %s: date and time. */
			esc_attr( sprintf( __( 'First seen: %s', 'lostlink' ), Admin::format_date( (int) $item['first_seen'] ) ) ),
			esc_html( Admin::format_date( (int) $item['last_seen'] ) )
		);
	}

	/**
	 * Renders the hit count columns.
	 *
	 * @param Path_Row $item        A record of the paths table.
	 * @param string   $column_name Column name.
	 */
	protected function column_default( $item, $column_name ): string {
		$count = match ( $column_name ) {
			'count'       => $item['count'],
			'daily_count' => $item['daily_count'],
			'week_count'  => $item['week_count'],
			default       => 0,
		};
		return esc_html( number_format_i18n( (int) $count ) );
	}

	/**
	 * Renders the referers column: the most frequent ones, behind a disclosure.
	 *
	 * @param array $item A record of the paths table.
	 *
	 * @phpstan-param Path_Row $item
	 */
	protected function column_referers( array $item ): string {
		$hash  = $item['path_hash'];
		$total = $this->referer_counts[ $hash ] ?? 0;
		if ( 0 === $total ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'None', 'lostlink' ) . '</span>';
		}

		$list = '';
		foreach ( $this->storage->get_referers( $hash, self::INLINE_REFERERS ) as $referer ) {
			$list .= sprintf(
				'<li>%s <span class="count">(%s)</span></li>',
				Admin::referer_link( $referer['referer'] ),
				esc_html( number_format_i18n( (int) $referer['count'] ) )
			);
		}
		if ( $total > self::INLINE_REFERERS ) {
			$list .= sprintf(
				'<li><a href="%s">%s</a></li>',
				esc_url(
					Admin::url(
						array(
							'tab'       => 'referers',
							'path_hash' => $hash,
						)
					)
				),
				esc_html__( 'See all', 'lostlink' )
			);
		}

		return sprintf(
			'<details class="lostlink-referers"><summary>%s</summary><ul>%s</ul></details>',
			/* translators: %s: number of referers. */
			esc_html( sprintf( _n( '%s referer', '%s referers', $total, 'lostlink' ), number_format_i18n( $total ) ) ),
			$list
		);
	}

	/**
	 * Renders the priority column.
	 *
	 * @param array $item A record of the paths table.
	 *
	 * @phpstan-param Path_Row $item
	 */
	protected function column_priority( array $item ): string {
		$priority = Priority::compute( $item, $this->now, $this->settings );
		return sprintf(
			'<span class="lostlink-badge lostlink-badge--%s">%s</span>',
			esc_attr( $priority->value ),
			esc_html( $priority->label() )
		);
	}

	/**
	 * Renders the trend column.
	 *
	 * @param array $item A record of the paths table.
	 *
	 * @phpstan-param Path_Row $item
	 */
	protected function column_trend( array $item ): string {
		return Sparkline::svg( $this->history[ $item['path_hash'] ] ?? array(), $this->now );
	}
}
