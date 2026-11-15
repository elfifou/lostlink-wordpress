<?php
/**
 * Search box of the list tables.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * WP_List_Table::search_box() reading its parameters through Request.
 *
 * The core method prints $_REQUEST['s'], ['orderby'] and ['order'] as they
 * are sent: one sent as an array raises warnings. Here they are sanitized
 * and validated like any parameter of the plugin, and escaped on output.
 */
trait Search_Box {

	/**
	 * Displays the search box.
	 *
	 * @param string $text     The submit button label.
	 * @param string $input_id ID of the search input, without its "-search-input" suffix.
	 */
	public function search_box( $text, $input_id ) {
		$search = Request::search( 's', Request::ANY );
		if ( '' === $search && ! $this->has_items() ) {
			return;
		}

		$input_id .= '-search-input';
		foreach ( array( 'orderby', 'order' ) as $param ) {
			$value = Request::key( $param, Request::ANY );
			if ( null !== $value && '' !== $value ) {
				printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $param ), esc_attr( $value ) );
			}
		}
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
			<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php echo esc_attr( $search ); ?>" />
			<?php submit_button( $text, 'compact', '', false, array( 'id' => 'search-submit' ) ); ?>
		</p>
		<?php
	}
}
