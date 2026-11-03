<?php
/**
 * List of the built-in redirects.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists the redirects of the built-in store: sortable, searchable,
 * paginated, with edit and delete row actions and a delete bulk action.
 *
 * @phpstan-import-type Redirect_Row from Redirect_Store
 */
class Redirects_Table extends \WP_List_Table {

	/**
	 * Plural name, also used in the bulk action nonce ("bulk-{plural}").
	 */
	public const PLURAL = 'lostlink-redirects';

	/**
	 * Number of redirects per page.
	 */
	public const PER_PAGE = 50;

	/**
	 * Constructor.
	 *
	 * @param Redirect_Store $store The redirects table.
	 */
	public function __construct( protected Redirect_Store $store ) {
		parent::__construct(
			array(
				'singular' => 'lostlink-redirect',
				'plural'   => self::PLURAL,
				'ajax'     => false,
				'screen'   => 'tools_page_' . Admin::PAGE,
			)
		);
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
			'cb'         => '<input type="checkbox" />',
			'source'     => __( 'Source', 'lostlink' ),
			'target'     => __( 'Target', 'lostlink' ),
			'status'     => __( 'Type', 'lostlink' ),
			'hits'       => __( 'Hits', 'lostlink' ),
			'last_hit'   => __( 'Last hit', 'lostlink' ),
			'created_at' => __( 'Created', 'lostlink' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'source'     => array( 'source', false ),
			'target'     => array( 'target', false ),
			'hits'       => array( 'hits', true ),
			'last_hit'   => array( 'last_hit', true ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_default_primary_column_name(): string {
		return 'source';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array( 'delete' => __( 'Delete', 'lostlink' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$args = array(
			'search'  => $this->get_search(),
			'orderby' => Request::key( 'orderby' ) ?? 'created_at',
			'order'   => Request::key( 'order' ) ?? 'desc',
		);

		$this->items = $this->store->query(
			$args + array(
				'limit'  => self::PER_PAGE,
				'offset' => ( $this->get_pagenum() - 1 ) * self::PER_PAGE,
			)
		);
		$this->set_pagination_args(
			array(
				'total_items' => $this->store->count( $args ),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		if ( '' !== $this->get_search() ) {
			esc_html_e( 'No redirect matches your search.', 'lostlink' );
		} else {
			esc_html_e( 'No redirect yet. Use "Fix" on a broken URL to create one.', 'lostlink' );
		}
	}

	/**
	 * Renders the checkbox column.
	 *
	 * @param Redirect_Row $item A redirect.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="lostlink-redirect-%1$d">%2$s</label><input type="checkbox" id="lostlink-redirect-%1$d" name="redirects[]" value="%1$d" />',
			(int) $item['id'],
			/* translators: %s: path of the row, a broken URL or a redirect source. */
			esc_html( sprintf( __( 'Select %s', 'lostlink' ), $item['source'] ) )
		);
	}

	/**
	 * Renders the source column, with the edit and delete row actions.
	 *
	 * @param array $item A redirect.
	 *
	 * @phpstan-param Redirect_Row $item
	 */
	protected function column_source( array $item ): string {
		$delete = wp_nonce_url(
			Admin::url(
				array(
					'tab'         => 'redirects',
					'action'      => 'delete',
					'redirects[]' => (int) $item['id'],
				)
			),
			'bulk-' . self::PLURAL
		);

		return sprintf(
			'<strong class="lostlink-url"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></strong>%s',
			esc_url( home_url( $item['source'] ) ),
			esc_html( $item['source'] ),
			$this->row_actions(
				array(
					'edit'   => sprintf(
						'<a href="%s" class="lostlink-modal">%s</a>',
						esc_url(
							Admin::url(
								array(
									'tab'      => 'edit',
									'redirect' => (int) $item['id'],
								)
							)
						),
						esc_html__( 'Edit', 'lostlink' )
					),
					'delete' => sprintf(
						'<a href="%s" onclick="return confirm(%s);">%s</a>',
						esc_url( $delete ),
						esc_attr( (string) wp_json_encode( __( 'Delete this redirect? Its URL will return a 404 again.', 'lostlink' ) ) ),
						esc_html__( 'Delete', 'lostlink' )
					),
				)
			)
		);
	}

	/**
	 * Renders the target column.
	 *
	 * @param array $item A redirect.
	 *
	 * @phpstan-param Redirect_Row $item
	 */
	protected function column_target( array $item ): string {
		return sprintf(
			'<span class="lostlink-url"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></span>',
			esc_url( Redirect_Store::location( $item['target'] ) ),
			esc_html( $item['target'] )
		);
	}

	/**
	 * Renders the status code column.
	 *
	 * @param array $item A redirect.
	 *
	 * @phpstan-param Redirect_Row $item
	 */
	protected function column_status( array $item ): string {
		return esc_html( $item['status_code'] );
	}

	/**
	 * Renders the date columns.
	 *
	 * @param Redirect_Row $item        A redirect.
	 * @param string       $column_name Column name.
	 */
	protected function column_default( $item, $column_name ): string {
		if ( 'hits' === $column_name ) {
			return esc_html( number_format_i18n( (int) $item['hits'] ) );
		}
		$time = (int) ( 'created_at' === $column_name ? $item['created_at'] : $item['last_hit'] );
		if ( 0 === $time ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Never', 'lostlink' ) . '</span>';
		}
		return esc_html( Admin::format_date( $time ) );
	}
}
