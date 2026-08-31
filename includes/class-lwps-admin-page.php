<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Admin_Page {
	private static $hook = '';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'lock_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_lock' ), 40, 1 );
	}

	public static function lock_meta_box() {
		add_meta_box(
			'lwps-product-lock',
			__( 'Product synchronization', 'lux-woo-product-sync' ),
			array( __CLASS__, 'render_lock_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	public static function render_lock_meta_box( WP_Post $post ) {
		wp_nonce_field( 'lwps_save_product_lock', 'lwps_product_lock_nonce' );
		$locked = 'yes' === get_post_meta( $post->ID, '_lwps_local_lock', true );
		?>
		<label><input type="checkbox" name="lwps_local_lock" value="yes" <?php checked( $locked ); ?>> <?php esc_html_e( 'Protect local changes from bulk synchronization', 'lux-woo-product-sync' ); ?></label>
		<?php
	}

	public static function save_lock( $post_id ) {
		if ( ! isset( $_POST['lwps_product_lock_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lwps_product_lock_nonce'] ) ), 'lwps_save_product_lock' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['lwps_local_lock'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['lwps_local_lock'] ) ) ) {
			update_post_meta( $post_id, '_lwps_local_lock', 'yes' );
		} else {
			delete_post_meta( $post_id, '_lwps_local_lock' );
		}
	}

	public static function menu() {
		self::$hook = add_submenu_page(
			'woocommerce',
			__( 'Product synchronization', 'lux-woo-product-sync' ),
			__( 'Product sync', 'lux-woo-product-sync' ),
			'manage_woocommerce',
			'lwps-product-sync',
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'lwps-admin', LWPS_URL . 'assets/css/admin.css', array(), LWPS_VERSION );
		wp_enqueue_script( 'lwps-admin', LWPS_URL . 'assets/js/admin.js', array(), LWPS_VERSION, true );
		wp_localize_script(
			'lwps-admin',
			'LWPS_CONFIG',
			array(
				'restUrl' => esc_url_raw( rest_url( 'lwps/v1/admin' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'ajaxNonce' => wp_create_nonce( 'lwps_admin' ),
				'perPage' => 30,
			)
		);
	}

	public static function render() {
		?>
		<div class="wrap lwps" id="lwps-app">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Перенесення та оновлення товарів', 'lux-woo-product-sync' ); ?></h1>
			<header class="lwps-header">
				<div class="lwps-brandmark"><span class="dashicons dashicons-products"></span></div>
				<div>
					<div class="lwps-header-title" aria-hidden="true"><?php esc_html_e( 'Перенесення та оновлення товарів', 'lux-woo-product-sync' ); ?></div>
					<p><?php esc_html_e( 'Один сайт-донор → один сайт-одержувач', 'lux-woo-product-sync' ); ?></p>
				</div>
				<span class="lwps-support"><span class="dashicons dashicons-screenoptions"></span><?php esc_html_e( 'Варіативні товари', 'lux-woo-product-sync' ); ?></span>
			</header>

			<nav class="lwps-flow" aria-label="<?php esc_attr_e( 'Етапи синхронізації', 'lux-woo-product-sync' ); ?>">
				<button type="button" class="is-active" data-tab="connection" data-step="1" aria-current="step"><span class="dashicons dashicons-admin-links"></span><?php esc_html_e( 'Підключення', 'lux-woo-product-sync' ); ?></button><i>→</i>
				<button type="button" data-tab="catalog" data-step="2" disabled><span class="dashicons dashicons-search"></span><?php esc_html_e( 'Аналіз', 'lux-woo-product-sync' ); ?></button><i>→</i>
				<button type="button" data-tab="changes" data-step="3" disabled><span class="dashicons dashicons-list-view"></span><?php esc_html_e( 'Позиції та дія', 'lux-woo-product-sync' ); ?></button><i>→</i>
				<button type="button" data-tab="journal" data-step="4" disabled><span class="dashicons dashicons-media-text"></span><?php esc_html_e( 'Журнал', 'lux-woo-product-sync' ); ?></button>
			</nav>

			<div id="lwps-notice" class="lwps-notice" hidden></div>

			<section class="lwps-panel is-active" data-panel="connection">
				<div class="lwps-section-head">
					<div><span class="lwps-step">1</span><h2><?php esc_html_e( 'Підключення до донора', 'lux-woo-product-sync' ); ?></h2></div>
					<span id="lwps-connection-state" class="lwps-state is-muted"><?php esc_html_e( 'Не перевірено', 'lux-woo-product-sync' ); ?></span>
				</div>
				<form id="lwps-settings-form" class="lwps-form">
					<label><span><?php esc_html_e( 'URL сайту-донора', 'lux-woo-product-sync' ); ?></span><input type="url" name="donor_url" placeholder="https://donor.example.com" required></label>
					<label><span><?php esc_html_e( 'REST API Key', 'lux-woo-product-sync' ); ?></span><input type="text" name="consumer_key" autocomplete="off" placeholder="ck_••••••••••••••••"></label>
					<label><span><?php esc_html_e( 'REST API Secret', 'lux-woo-product-sync' ); ?></span><input type="password" name="consumer_secret" autocomplete="new-password" placeholder="cs_••••••••••••••••"></label>
					<div class="lwps-form-actions">
						<button type="submit" class="button button-primary" id="lwps-save-test"><span class="dashicons dashicons-saved"></span><?php esc_html_e( 'Зберегти й перевірити', 'lux-woo-product-sync' ); ?></button>
					</div>
				</form>
				<div class="lwps-step-footer">
					<span class="lwps-step-result" id="lwps-connection-result"><span class="dashicons dashicons-clock"></span><?php esc_html_e( 'Очікує перевірки', 'lux-woo-product-sync' ); ?></span>
					<button type="button" class="button button-primary" id="lwps-next-analysis" disabled><?php esc_html_e( 'Далі: Аналіз', 'lux-woo-product-sync' ); ?><span class="dashicons dashicons-arrow-right-alt2"></span></button>
				</div>
			</section>

			<section class="lwps-panel" data-panel="catalog">
				<div class="lwps-section-head">
					<div><span class="lwps-step">2</span><h2><?php esc_html_e( 'Аналіз каталогу', 'lux-woo-product-sync' ); ?></h2></div>
					<button class="button button-primary" id="lwps-analyze"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Запустити аналіз', 'lux-woo-product-sync' ); ?></button>
				</div>
				<div class="lwps-metrics" id="lwps-metrics"></div>
				<div class="lwps-analysis-progress" id="lwps-analysis-progress" hidden>
					<div class="lwps-analysis-live"><span id="lwps-analysis-live-label"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Аналіз триває', 'lux-woo-product-sync' ); ?></span><time id="lwps-analysis-elapsed">00:00</time></div>
					<div class="lwps-progress-line"><span></span></div>
					<div class="lwps-progress-caption"><small id="lwps-analysis-caption"><?php esc_html_e( 'Очікування', 'lux-woo-product-sync' ); ?></small><strong id="lwps-analysis-percent">0%</strong></div>
				</div>
				<div class="lwps-empty" id="lwps-analysis-empty"><span class="dashicons dashicons-search"></span><strong><?php esc_html_e( 'Каталог ще не проаналізовано', 'lux-woo-product-sync' ); ?></strong></div>
				<div class="lwps-step-footer">
					<span class="lwps-step-result" id="lwps-analysis-result"><span class="dashicons dashicons-clock"></span><?php esc_html_e( 'Аналіз ще не виконано', 'lux-woo-product-sync' ); ?></span>
					<button type="button" class="button button-primary" id="lwps-next-changes" disabled><?php esc_html_e( 'Далі: Позиції', 'lux-woo-product-sync' ); ?><span class="dashicons dashicons-arrow-right-alt2"></span></button>
				</div>
			</section>

			<section class="lwps-panel" data-panel="changes">
				<div class="lwps-section-head">
					<div><span class="lwps-step">3</span><h2><?php esc_html_e( 'Позиції та дія', 'lux-woo-product-sync' ); ?></h2></div>
					<div class="lwps-section-actions">
						<span class="lwps-state is-info" id="lwps-total-changes">0</span>
						<button type="button" class="button" id="lwps-download-db"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Скачати БД', 'lux-woo-product-sync' ); ?></button>
					</div>
				</div>
				<div class="lwps-toolbar">
					<div class="lwps-search"><span class="dashicons dashicons-search"></span><input type="search" id="lwps-search" placeholder="<?php esc_attr_e( 'Пошук товару за назвою', 'lux-woo-product-sync' ); ?>"></div>
					<select id="lwps-status-filter">
						<option value=""><?php esc_html_e( 'Усі позиції', 'lux-woo-product-sync' ); ?></option>
						<option value="new"><?php esc_html_e( 'Нові товари', 'lux-woo-product-sync' ); ?></option>
						<option value="variation_added"><?php esc_html_e( 'Є варіації для додавання', 'lux-woo-product-sync' ); ?></option>
						<option value="locked"><?php esc_html_e( 'Заблоковані', 'lux-woo-product-sync' ); ?></option>
					</select>
					<button type="button" class="button" id="lwps-select-page"><span class="dashicons dashicons-yes"></span><span id="lwps-select-page-label"><?php esc_html_e( 'Вибрати все на сторінці', 'lux-woo-product-sync' ); ?></span></button>
					<button type="button" class="button lwps-icon-command" id="lwps-refresh" title="<?php esc_attr_e( 'Оновити список', 'lux-woo-product-sync' ); ?>" aria-label="<?php esc_attr_e( 'Оновити список', 'lux-woo-product-sync' ); ?>"><span class="dashicons dashicons-update"></span></button>
				</div>
				<div class="lwps-table-wrap">
					<table class="lwps-table">
						<thead><tr><th><input type="checkbox" id="lwps-select-all" aria-label="<?php esc_attr_e( 'Вибрати товари на сторінці', 'lux-woo-product-sync' ); ?>"></th><th><?php esc_html_e( 'Батьківський товар', 'lux-woo-product-sync' ); ?></th><th><?php esc_html_e( 'Статус', 'lux-woo-product-sync' ); ?></th><th><?php esc_html_e( 'Варіації', 'lux-woo-product-sync' ); ?></th><th><?php esc_html_e( 'Дії', 'lux-woo-product-sync' ); ?></th></tr></thead>
						<tbody id="lwps-change-rows"></tbody>
					</table>
				</div>
				<div class="lwps-pagination" id="lwps-pagination"></div>

				<div class="lwps-actionbar">
					<strong><span id="lwps-selected-count">0</span> <?php esc_html_e( 'вибрано', 'lux-woo-product-sync' ); ?></strong>
					<label class="lwps-operation-field"><span><?php esc_html_e( 'Дія', 'lux-woo-product-sync' ); ?></span><select id="lwps-operation">
						<option value="import"><?php esc_html_e( 'Перенести нові товари', 'lux-woo-product-sync' ); ?></option>
						<option value="update_main"><?php esc_html_e( 'Оновити основні дані', 'lux-woo-product-sync' ); ?></option>
						<option value="update_variations"><?php esc_html_e( 'Оновити варіації', 'lux-woo-product-sync' ); ?></option>
						<option value="add_variations"><?php esc_html_e( 'Додати відсутні варіації', 'lux-woo-product-sync' ); ?></option>
						<option value="overwrite"><?php esc_html_e( 'Повністю перезаписати', 'lux-woo-product-sync' ); ?></option>
					</select></label>
					<label class="lwps-check"><input type="checkbox" id="lwps-delete-missing"><span><?php esc_html_e( 'Видалити зайві варіації', 'lux-woo-product-sync' ); ?></span></label>
					<label class="lwps-check" title="<?php esc_attr_e( 'Дозволити вибраній операції змінювати заблоковані товари', 'lux-woo-product-sync' ); ?>"><input type="checkbox" id="lwps-force-locked"><span><?php esc_html_e( 'Примусово опрацювати заблоковані', 'lux-woo-product-sync' ); ?></span></label>
					<div class="lwps-action-buttons">
						<button class="button" id="lwps-preview"><span class="dashicons dashicons-visibility"></span><?php esc_html_e( 'Перевірити вибрані', 'lux-woo-product-sync' ); ?></button>
						<button class="button button-primary" id="lwps-preview-all"><span class="dashicons dashicons-controls-forward"></span><?php esc_html_e( 'Опрацювати всі результати', 'lux-woo-product-sync' ); ?></button>
					</div>
				</div>
			</section>

			<section class="lwps-panel" data-panel="journal">
				<div class="lwps-section-head">
					<div><span class="lwps-step">4</span><h2><?php esc_html_e( 'Виконання та журнал', 'lux-woo-product-sync' ); ?></h2></div>
					<button type="button" class="button lwps-icon-command" id="lwps-jobs-refresh" title="<?php esc_attr_e( 'Оновити журнал', 'lux-woo-product-sync' ); ?>" aria-label="<?php esc_attr_e( 'Оновити журнал', 'lux-woo-product-sync' ); ?>"><span class="dashicons dashicons-update"></span></button>
				</div>
				<div class="lwps-journal-toolbar"><label for="lwps-job-select"><?php esc_html_e( 'Операція', 'lux-woo-product-sync' ); ?></label><select id="lwps-job-select"><option value=""><?php esc_html_e( 'Журнал порожній', 'lux-woo-product-sync' ); ?></option></select></div>
				<div class="lwps-job-detail" id="lwps-job-detail"><div class="lwps-empty"><span class="dashicons dashicons-media-text"></span><strong><?php esc_html_e( 'Журнал порожній', 'lux-woo-product-sync' ); ?></strong></div></div>
			</section>

			<div class="lwps-modal" id="lwps-preview-modal" hidden>
				<div class="lwps-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="lwps-preview-title">
					<button class="lwps-modal-close" aria-label="<?php esc_attr_e( 'Закрити', 'lux-woo-product-sync' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
					<div class="lwps-modal-head"><span class="dashicons dashicons-visibility"></span><div><h2 id="lwps-preview-title"><?php esc_html_e( 'Попередній перегляд', 'lux-woo-product-sync' ); ?></h2><p id="lwps-preview-scope"><?php esc_html_e( 'Підтвердження майбутньої операції', 'lux-woo-product-sync' ); ?></p></div></div>
					<div class="lwps-preview-summary" id="lwps-preview-summary"></div>
					<div class="lwps-modal-actions"><button class="button lwps-modal-close"><?php esc_html_e( 'Скасувати', 'lux-woo-product-sync' ); ?></button><button class="button button-primary" id="lwps-confirm"><span class="dashicons dashicons-controls-play"></span><?php esc_html_e( 'Підтвердити та запустити', 'lux-woo-product-sync' ); ?></button></div>
				</div>
			</div>
		</div>
		<?php
	}
}
