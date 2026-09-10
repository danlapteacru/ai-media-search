<?php
/**
 * Top-level admin page with Dashboard and Settings tabs.
 *
 * @package AIMS
 */

namespace AIMS;

use AIMS\Providers\Registry;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {
	const SLUG     = 'ai-media-search';
	const PER_PAGE = 50;
	const FILTERS  = array( 'all', 'indexed', 'not_indexed', 'failed', 'skipped' );

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_for_media' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'AI Media Search', 'ai-media-search' ),
			__( 'AI Media Search', 'ai-media-search' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-search',
			81
		);
	}

	public function enqueue( string $hook ): void {
		$is_our_page  = 'toplevel_page_' . self::SLUG === $hook;
		$is_media     = 'upload.php' === $hook;
		$is_edit_att  = 'post.php' === $hook && 'attachment' === get_post_type( (int) ( $_GET['post'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $is_our_page || $is_media || $is_edit_att ) {
			$this->enqueue_for_media();
		}
	}

	public function enqueue_for_media(): void {
		if ( wp_script_is( 'aims-admin', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'aims-admin', AIMS_URL . 'assets/admin.css', array(), AIMS_VERSION );
		wp_enqueue_script( 'aims-admin', AIMS_URL . 'assets/admin.js', array(), AIMS_VERSION, true );
		wp_localize_script(
			'aims-admin',
			'aimsData',
			array(
				'restUrl'   => esc_url_raw( rest_url( Rest::NS . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'batchSize' => (int) Settings::get( 'batch_size' ),
				'hasKey'    => '' !== Settings::get_api_key( (string) Settings::get( 'provider' ) ),
				'i18n'      => array(
					'working'    => __( 'Working…', 'ai-media-search' ),
					'done'       => __( 'Done.', 'ai-media-search' ),
					'stopped'    => __( 'Stopped.', 'ai-media-search' ),
					'failed'     => __( 'Request failed.', 'ai-media-search' ),
					'regenerate' => __( 'Regenerate', 'ai-media-search' ),
					'index'      => __( 'Index', 'ai-media-search' ),
					'progress'   => /* translators: 1: done count, 2: total count */ __( '%1$s of %2$s', 'ai-media-search' ),
					'noSelection' => __( 'Select at least one file first.', 'ai-media-search' ),
				),
			)
		);
	}

	public static function filter_meta_query( string $filter ): array {
		switch ( $filter ) {
			case 'indexed':
			case 'failed':
			case 'skipped':
				return array( array( 'key' => Indexer::META_STATUS, 'value' => $filter ) );
			case 'not_indexed':
				return array(
					'relation' => 'OR',
					array( 'key' => Indexer::META_STATUS, 'compare' => 'NOT EXISTS' ),
					array( 'key' => Indexer::META_STATUS, 'value' => Indexer::STATUS_PENDING ),
				);
			default:
				return array();
		}
	}

	public static function truncate( string $text, int $length = 160 ): string {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $length ) ) . '…';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ai-media-search' ) );
		}
		$settings = Settings::all();
		$has_key  = '' !== Settings::get_api_key( $settings['provider'] );
		?>
		<div class="wrap aims-wrap">
			<h1><?php esc_html_e( 'AI Media Search', 'ai-media-search' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Describe your images with AI so the Media Library search finds them by what is in the picture.', 'ai-media-search' ); ?></p>

			<nav class="nav-tab-wrapper aims-tabs">
				<a href="#dashboard" class="nav-tab nav-tab-active aims-tab" data-tab="dashboard"><?php esc_html_e( 'Dashboard', 'ai-media-search' ); ?></a>
				<a href="#settings" class="nav-tab aims-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'ai-media-search' ); ?></a>
			</nav>

			<div id="aims-tab-dashboard" class="aims-tab-panel">
				<?php $this->render_dashboard( $has_key ); ?>
			</div>
			<div id="aims-tab-settings" class="aims-tab-panel" hidden>
				<?php $this->render_settings( $settings ); ?>
			</div>
		</div>
		<?php
	}

	private function render_dashboard( bool $has_key ): void {
		$filter = sanitize_key( (string) ( $_GET['filter'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = in_array( $filter, self::FILTERS, true ) ? $filter : 'all';
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts = Stats::counts();

		$cards = array(
			'all'         => array( __( 'All files', 'ai-media-search' ), $counts['total'] ),
			'indexed'     => array( __( 'Indexed', 'ai-media-search' ), $counts['indexed'] ),
			'not_indexed' => array( __( 'Not indexed', 'ai-media-search' ), $counts['not_indexed'] ),
			'failed'      => array( __( 'Failed', 'ai-media-search' ), $counts['failed'] ),
			'skipped'     => array( __( 'Skipped', 'ai-media-search' ), $counts['skipped'] ),
		);

		if ( ! $has_key ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Add an API key on the Settings tab before indexing.', 'ai-media-search' ) . '</p></div>';
		}

		echo '<div class="aims-cards">';
		foreach ( $cards as $key => list( $label, $count ) ) {
			$url   = add_query_arg( array( 'page' => self::SLUG, 'filter' => $key ), admin_url( 'admin.php' ) ) . '#dashboard';
			$class = 'aims-card aims-card-' . $key . ( $key === $filter ? ' is-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"><span class="aims-card-count">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="aims-card-label">' . esc_html( $label ) . '</span></a>';
		}
		echo '</div>';

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array_merge( Image_Preparer::IMAGE_MIMES, array( Image_Preparer::PDF_MIME ) ),
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => self::filter_meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		?>
		<table class="widefat striped aims-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="aims-select-all" /></td>
					<th><?php esc_html_e( 'File', 'ai-media-search' ); ?></th>
					<th><?php esc_html_e( 'AI description', 'ai-media-search' ); ?></th>
					<th><?php esc_html_e( 'Tags', 'ai-media-search' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ai-media-search' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ai-media-search' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $query->have_posts() ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No files match this filter.', 'ai-media-search' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $query->posts as $post ) :
				$id      = (int) $post->ID;
				$payload = Indexer::payload( $id );
				$button  = Indexer::STATUS_INDEXED === $payload['status'] ? __( 'Regenerate', 'ai-media-search' ) : __( 'Index', 'ai-media-search' );
				?>
				<tr class="aims-row" data-id="<?php echo esc_attr( (string) $id ); ?>">
					<th scope="row" class="check-column"><input type="checkbox" class="aims-select" value="<?php echo esc_attr( (string) $id ); ?>" /></th>
					<td class="aims-file">
						<?php echo wp_get_attachment_image( $id, array( 60, 60 ), true ); ?>
						<strong><?php echo esc_html( get_the_title( $id ) ); ?></strong><br />
						<span class="aims-muted"><?php echo esc_html( wp_basename( (string) get_attached_file( $id ) ) ); ?></span>
					</td>
					<td class="aims-description"><?php echo esc_html( self::truncate( $payload['description'] ) ); ?></td>
					<td class="aims-tags"><?php echo esc_html( implode( ', ', $payload['tags'] ) ); ?></td>
					<td class="aims-status-cell"><span class="aims-status-wrap" data-id="<?php echo esc_attr( (string) $id ); ?>"><?php echo Attachment_Fields::status_html( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></td>
					<td class="aims-actions">
						<button type="button" class="button button-small aims-index-one" data-id="<?php echo esc_attr( (string) $id ); ?>" <?php disabled( ! $has_key ); ?>><?php echo esc_html( $button ); ?></button>
						<a class="aims-edit" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'ai-media-search' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$links = paginate_links(
			array(
				'base'      => add_query_arg( array( 'page' => self::SLUG, 'filter' => $filter, 'paged' => '%#%' ), admin_url( 'admin.php' ) ) . '#dashboard',
				'format'    => '',
				'current'   => $paged,
				'total'     => (int) $query->max_num_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
		?>
		<div class="aims-controls">
			<button type="button" class="button button-primary" id="aims-index-selected" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Index selected', 'ai-media-search' ); ?></button>
			<button type="button" class="button" id="aims-index-all" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Index all not indexed', 'ai-media-search' ); ?></button>
			<label><input type="checkbox" id="aims-retry-failed" /> <?php esc_html_e( 'Also retry failed', 'ai-media-search' ); ?></label>
			<button type="button" class="button" id="aims-stop" disabled><?php esc_html_e( 'Stop', 'ai-media-search' ); ?></button>
			<span id="aims-progress-text" class="aims-muted"></span>
			<div class="aims-progress"><div class="aims-progress-bar" id="aims-progress-bar"></div></div>
			<ul id="aims-log" class="aims-log"></ul>
		</div>
		<?php
	}

	private function render_settings( array $settings ): void {
		?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Images and PDF previews are sent to the selected third-party API for analysis. Check the provider\'s terms and privacy policy before enabling.', 'ai-media-search' ); ?></p></div>
		<form method="post" action="options.php" class="aims-settings-form">
			<?php settings_fields( 'aims_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Provider', 'ai-media-search' ); ?></th>
					<td>
						<?php foreach ( Registry::labels() as $id => $label ) : ?>
							<label class="aims-provider-choice">
								<input type="radio" name="aims_settings[provider]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $settings['provider'], $id ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php foreach ( Registry::labels() as $id => $label ) : ?>
					<?php
					$key_set = '' !== $settings['api_keys'][ $id ];
					$model   = $settings['models'][ $id ];
					$known   = Registry::known_models( $id );
					if ( '' === $model && $known ) {
						$model = (string) array_key_first( $known );
					}
					$is_custom = 'custom' === $model || ( '' !== $model && ! isset( $known[ $model ] ) );
					?>
					<tr class="aims-provider-row" data-provider="<?php echo esc_attr( $id ); ?>">
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<p>
								<label for="aims-key-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'API key', 'ai-media-search' ); ?></label><br />
								<input type="password" class="regular-text" id="aims-key-<?php echo esc_attr( $id ); ?>" name="aims_settings[api_keys][<?php echo esc_attr( $id ); ?>]" value="" autocomplete="off"
									placeholder="<?php echo esc_attr( $key_set ? __( 'Saved. Paste a new key to replace it.', 'ai-media-search' ) : __( 'Paste your API key', 'ai-media-search' ) ); ?>" />
							</p>
							<p>
								<label for="aims-model-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Model', 'ai-media-search' ); ?></label><br />
								<select id="aims-model-<?php echo esc_attr( $id ); ?>" class="aims-model-select" name="aims_settings[models][<?php echo esc_attr( $id ); ?>]">
									<?php foreach ( $known as $model_id => $hint ) : ?>
										<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( ! $is_custom && $model === $model_id ); ?>><?php echo esc_html( $model_id . ' — ' . $hint ); ?></option>
									<?php endforeach; ?>
									<option value="custom" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Custom model ID…', 'ai-media-search' ); ?></option>
								</select>
								<input type="text" class="regular-text aims-custom-model" name="aims_settings[custom_models][<?php echo esc_attr( $id ); ?>]"
									value="<?php echo esc_attr( $is_custom && 'custom' !== $model ? $model : $settings['custom_models'][ $id ] ); ?>"
									placeholder="<?php esc_attr_e( 'exact model id', 'ai-media-search' ); ?>" <?php echo $is_custom ? '' : 'hidden'; ?> />
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="aims-language"><?php esc_html_e( 'Description language', 'ai-media-search' ); ?></label></th>
					<td><input type="text" id="aims-language" class="regular-text" name="aims_settings[language]" value="<?php echo esc_attr( $settings['language'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="aims-custom-prompt"><?php esc_html_e( 'Custom prompt', 'ai-media-search' ); ?></label></th>
					<td>
						<textarea id="aims-custom-prompt" class="large-text" rows="5" name="aims_settings[custom_prompt]"><?php echo esc_textarea( $settings['custom_prompt'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional extra guidance added to every request, for example product names or house style.', 'ai-media-search' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automation', 'ai-media-search' ); ?></th>
					<td>
						<label><input type="checkbox" name="aims_settings[auto_index]" value="1" <?php checked( $settings['auto_index'] ); ?> /> <?php esc_html_e( 'Describe new uploads automatically', 'ai-media-search' ); ?></label><br />
						<label><input type="checkbox" name="aims_settings[fill_alt]" value="1" <?php checked( $settings['fill_alt'] ); ?> /> <?php esc_html_e( 'Fill empty alt text with the AI alt sentence', 'ai-media-search' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aims-batch-size"><?php esc_html_e( 'Batch size', 'ai-media-search' ); ?></label></th>
					<td>
						<input type="number" id="aims-batch-size" min="1" max="10" name="aims_settings[batch_size]" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Files described per request when indexing from the dashboard.', 'ai-media-search' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<?php submit_button( __( 'Save settings', 'ai-media-search' ), 'primary', 'submit', false ); ?>
				<button type="button" class="button" id="aims-test"><?php esc_html_e( 'Test connection', 'ai-media-search' ); ?></button>
				<span id="aims-test-result" class="aims-muted"></span>
			</p>
		</form>
		<?php
	}
}
