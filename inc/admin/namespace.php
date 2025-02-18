<?php

namespace Smartcache\Admin;

use Altis\Cloud;
use Smartcache;

const MENU_SLUG = 'smartcache';

/**
 * Bootstrap function for all admin functions.
 *
 * @return void
 */
function bootstrap() : void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\register_admin_page' );
	add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );
	add_action( 'admin_init', __NAMESPACE__ . '\\check_on_invalidate_urls_submit' );

	require_once ABSPATH . '/wp-admin/includes/class-wp-list-table.php';
	require_once __DIR__ . '/class-log-list-table.php';
}

function register_admin_page() : void {
	add_submenu_page(
		'options-general.php',
		_x( 'Smartcache', 'settings page title', 'smartcache' ),
		_x( 'Smartcache', 'settings menu title', 'smartcache' ),
		'manage_options',
		MENU_SLUG,
		__NAMESPACE__ . '\\render_settings_page'
	);
}

function register_settings() {
	add_settings_section(
		'smartcache-quota',
		__( 'Quota', 'smartcache' ),
		__NAMESPACE__ . '\\render_quota_section',
		'smartcache'
	);
}

function render_quota_section() {
	$usage = Smartcache\get_invalidation_quota_usage();
	$quota = Smartcache\get_invalidation_quota();

	$is_warning = $usage >= ( 0.8 * $quota );
	$is_full = $usage >= $quota;
	$class = $is_warning ? 'warning' : ( $is_full ? 'error' : '' );
	?>
		<table class="form-table">
			<tr>
				<th scope="row">
					Monthly quota usage
				</th>
				<td>
					<meter
						class="usage-meter <?php echo sanitize_html_class( $class ); ?>"
						high="<?php echo esc_attr( 0.8 * $quota ); ?>"
						max="<?php echo esc_attr( $quota ); ?>"
						value="<?php echo esc_attr( $usage ); ?>"
						style="width: 20em;"
					>
						<?php printf( '%d / %d', $usage, $quota ); ?>
					</meter>

					<p>
						<?php
						printf(
							__( 'You have used %d of %d invalidation requests this month.', 'smartcache' ),
							$usage,
							$quota
						);
						?>
					</p>
				</td>
			</tr>
		</table>
	<?php
}

function render_settings_page() : void {
	settings_errors( 'smartcache' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'smartcache' );
			do_settings_sections( 'smartcache' );
			?>
		</form>

		<table class="form-table">
			<tr>
				<th scope="row">
					Invalidate all URLs
				</th>
				<td>
					<form method="post">
						<input
							name="smartcache_urls"
							type="hidden"
							value="*"
						/>
						<?php
						wp_nonce_field( 'smartcache.invalidate-urls' );
						submit_button( __( 'Invalidate entire cache', 'smartcache' ), 'delete' );
						?>
					</form>

					<p class="description">
						<?php
						_e( 'Invalidate the entire cache. Use this when performing major updates to the site, such as navigation changes or changing the theme.', 'smartcache' );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="smartcache_urls">
						Invalidate URLs
					</label>
				</th>
				<td>
					<form method="post">
						<textarea
							class="large-text code"
							id="smartcache_urls"
							rows="10"
							name="smartcache_urls"
						></textarea>
						<p class="description">
							Specify URLs to invalidate, one per line.
						</p>
						<p class="description">
							Use <code>*</code> as a wildcard, wildcards can only be at the end of a URL. A maximum of <?php echo esc_html( Cloud\PATHS_INVALIDATION_LIMIT ) ?> absolute URLs or <?php echo esc_html( Cloud\WILDCARD_INVALIDATION_LIMIT ) ?> wildcard URLs can be issued per request.
						</p>
						<p class="description">
							If you need to invalidate a lot of URLs, use a single broad wildcard instead of listing each URL.
						</p>
						<?php
						wp_nonce_field( 'smartcache.invalidate-urls' );
						submit_button( __( 'Invalidate URLs', 'smartcache' ) );
						?>
					</form>
				</td>
			</tr>
		</table>

		<h1>Log</h1>
		<?php
		$list_table = new Log_List_Table;
		$list_table->prepare_items();
		$list_table->display();
		?>
	</div>
	<?php
}

/**
 * Check if the invalidate URLs form was submitted.
 *
 * @return void
 */
function check_on_invalidate_urls_submit() {
	if ( ! isset( $_POST['smartcache_urls'] ) ) {
		return;
	}

	if ( ! check_admin_referer( 'smartcache.invalidate-urls' ) ) {
		add_settings_error( 'logcache', 'invalidated', __( 'Could not validate your request (invalid nonce). Try again.'), 'success' );
		return;
	}

	$urls = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( "\n", $_POST['smartcache_urls'] ) ) ) );
	$result = Smartcache\invalidate_urls( $urls );

	if ( $result === true ) {
		add_settings_error( 'logcache', 'invalidated', __( 'Invalidate request successful.'), 'success' );
	} else {
		add_settings_error( 'logcache', 'invalidated', __( 'There was a problem issuing the invalidation request.'), 'error' );
	}
}
