<?php
/**
 * Plugin Name: ELKO Import (CategoryId) + Stop Import + Show Categories
 * Description: Импорт товаров из ELKO (API v3.0) по CategoryId, плюс кнопка «Показать категории». Limit, skip out-of-stock, skip no-photo, остановка, удаление импортированных.
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
	exit;
}


add_action('admin_enqueue_scripts', function($hook) {
    // Проверяем, что мы на странице плагина
    if ($hook !== 'toplevel_page_elko-woo-import') {
        return;
    }

    // Подключаем JavaScript
    wp_enqueue_script(
        'elko-admin-js', // Уникальный идентификатор скрипта
        plugins_url('assets/admin.js', __FILE__), // Путь к вашему файлу admin.js
        ['jquery'], // Зависимость от jQuery
        '1.0', // Версия скрипта
        true // Подключение перед закрывающим тегом </body>
    );
});

function my_plugin_enqueue_scripts($hook_suffix) {
    // Проверяем, что мы на нужной странице плагина
    if ($hook_suffix === 'toplevel_page_my-plugin-slug') {
        wp_enqueue_script('my-plugin-script', plugin_dir_url(__FILE__) . 'js/reload.js', [], '1.0', true);
    }
}
add_action('admin_enqueue_scripts', 'my_plugin_enqueue_scripts');


require_once plugin_dir_path(__FILE__) . 'includes/class-elko-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-elko-import.php';

/**
 * Регистрируем меню "ELKO Import"
 */
add_action('admin_menu', function(){
	add_menu_page(
		'ELKO Import',
		'ELKO Import',
		'manage_options',
		'elko-woo-import',
		'elko_woo_import_admin_page',
		'dashicons-download'
	);
});

/**
 * Админ-страница плагина
 */
function elko_woo_import_admin_page() {
	
	// 1. Сохранение настроек
	if (isset($_POST['elko_save_settings'])) {
		check_admin_referer('elko_save_settings_action');

		$token         = sanitize_text_field($_POST['elko_api_token'] ?? '');
		$limit         = (int)($_POST['elko_import_limit'] ?? 10);
		$skip_out      = !empty($_POST['elko_skip_out_of_stock']) ? 1 : 0;
		$skip_no_photo = !empty($_POST['elko_skip_no_photo'])     ? 1 : 0;

		update_option('elko_api_token',         $token);
		update_option('elko_import_limit',      $limit);
		update_option('elko_skip_out_of_stock', $skip_out);
		update_option('elko_skip_no_photo',     $skip_no_photo);

		echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';
	}

	// 2. Запуск импорта
	if (isset($_POST['elko_run_import'])) {
		check_admin_referer('elko_run_import_action');

		// Сбрасываем флаг «остановить»
		update_option('elko_stop_import', 0);

		$token = get_option('elko_api_token', '');
		if (!$token) {
			echo '<div class="notice notice-error"><p>Сначала укажите токен!</p></div>';
		} else {
			$limit  = (int)get_option('elko_import_limit', 10);
			$skip_o = (bool)get_option('elko_skip_out_of_stock', 0);
			$skip_p = (bool)get_option('elko_skip_no_photo', 0);

			$api    = new Elko_API($token);
			$import = new Elko_Import($api);

			//$import->import_to_json();
			$count = $import->import_products_by_categoryid($limit, $skip_o, $skip_p);

			// Проверяем флаг
			$stop_flag = get_option('elko_stop_import', 0);
			if ($stop_flag) {
				echo "<div class='notice notice-warning'><p>Импорт остановлен вручную. Успело загрузиться: $count товаров.</p></div>";
			} else {
				echo "<div class='notice notice-success'><p>Импортировано товаров: $count.</p></div>";
			}
		}
	}

	// 3. Установка флага «остановить»
	if (isset($_POST['elko_stop_import_btn'])) {
		check_admin_referer('elko_stop_import_action');
		update_option('elko_stop_import', 1);
		echo '<div class="notice notice-warning"><p>Установлен флаг «остановить». Если импорт ещё идёт, он прервётся.</p></div>';
	}

	// 4. Снятие флага «остановить»
	if (isset($_POST['elko_clear_stop_import_btn'])) {
		check_admin_referer('elko_clear_stop_import_action');
		update_option('elko_stop_import', 0);
		echo '<div class="notice notice-success"><p>Флаг остановки снят. Можно снова запустить импорт.</p></div>';
	}

	// 5. Удаление импортированных
	if (isset($_POST['elko_delete_imported'])) {
		check_admin_referer('elko_delete_imported_action');
		$del_count = Elko_Import::delete_elko_products();
		echo "<div class='notice notice-success'><p>Удалено товаров: $del_count</p></div>";
	}

	// 6. Показать категории (запрос к /Catalog/Categories)
	$categories_output = '';
	if (isset($_POST['elko_show_categories'])) {
		check_admin_referer('elko_show_categories_action');

		$token = get_option('elko_api_token','');
		if (empty($token)) {
			$categories_output = '<div class="notice notice-error"><p>Сначала укажите и сохраните API Token.</p></div>';
		} else {
			$api = new Elko_API($token);
			$cats_data = $api->get_categories(); // наш метод из class-elko-api.php
			if (!is_array($cats_data)) {
				$categories_output = '<div class="notice notice-error"><p>Не удалось получить список категорий. Смотрите логи.</p></div>';
			} else {
				// Выведем JSON-структуру
				// При желании можно сделать красивую HTML-таблицу
				$categories_output = '<pre style="background:#f5f5f5; padding:10px; border:1px solid #ccc;">'
									 . esc_html(print_r($cats_data, true))
									 . '</pre>';
			}
		}
	}

	// Получаем текущие настройки
	$saved_token     = get_option('elko_api_token', '');
	$saved_limit     = get_option('elko_import_limit', 10);
	$saved_skip_out  = get_option('elko_skip_out_of_stock', 0);
	$saved_skip_no   = get_option('elko_skip_no_photo', 0);
	$stop_flag       = get_option('elko_stop_import', 0);

	?>
	<div class="wrap">
		<h1>ELKO Import (CategoryId + Show Categories)</h1>
		<form method="post">
			<?php wp_nonce_field('elko_save_settings_action'); ?>
			<table class="form-table">
				<tr>
					<th>API Token</th>
					<td>
						<input type="text" name="elko_api_token"
							   value="<?php echo esc_attr($saved_token); ?>"
							   size="60" />
					</td>
				</tr>
				<tr>
					<th>Сколько товаров импортировать (Limit)</th>
					<td>
						<input type="number" name="elko_import_limit" min="1" max="999999"
							   value="<?php echo (int)$saved_limit; ?>" />
					</td>
				</tr>
				<tr>
					<th>Пропускать товары без остатка?</th>
					<td>
						<input type="checkbox" name="elko_skip_out_of_stock" value="1"
							<?php checked($saved_skip_out, 1); ?> />
						<label>Да, если quantity=0</label>
					</td>
				</tr>
				<tr>
					<th>Пропускать товары без фото?</th>
					<td>
						<input type="checkbox" name="elko_skip_no_photo" value="1"
							<?php checked($saved_skip_no, 1); ?> />
						<label>Да, если нет картинок</label>
					</td>
				</tr>
			</table>
			<p>
				<button class="button button-primary" name="elko_save_settings">Сохранить настройки</button>
			</p>
		</form>

		<hr />
		<h2>Узнать список категорий</h2>
		<form method="post">
			<?php wp_nonce_field('elko_show_categories_action'); ?>
			<p>Нажмите, чтобы запросить <code>/Catalog/Categories</code> и увидеть их ID/названия.</p>
			<button class="button button-secondary" name="elko_show_categories">
				Показать категории
			</button>
		</form>
		<?php
		// Если нажали "Показать категории" — выведем
		if (!empty($categories_output)) {
			echo '<div style="margin-top:15px; height: 200px; overflow-y: auto;">'.$categories_output.'</div>';
		}
		?>

		<hr />
		<h2>Словарь категорий</h2>
		<p class="description">
			Укажите alias (например, "electronics") и категории через запятую (например, "TV, Mobile, Laptop").
		</p>
		<table id="category-dictionary-table" style="width:100%; border-collapse: collapse; margin-top: 20px;">
			<thead>
				<tr>
					<th style="border:1px solid #ccc; padding:5px;">Alias</th>
					<th style="border:1px solid #ccc; padding:5px;">Categories (comma-separated)</th>
					<th style="border:1px solid #ccc; padding:5px;">Actions</th>
				</tr>
			</thead>
			<tbody>
				<!-- Строки будут добавляться сюда -->
			</tbody>
		</table>
		<button type="button" id="add-dictionary-row" class="button" style="margin-top: 10px;">Add Row</button>
		<p>
			<button class="button button-primary" id="elko_save_aliases" name="elko_save_aliases">Сохранить категории</button>
		</p>

		<hr />
		<h2>Импорт товаров</h2>
		<form method="post">
			<?php wp_nonce_field('elko_run_import_action'); ?>
			<p>Запуск импорта. Если вы указали CategoryId, загрузим только из неё. Если пусто — все.</p>
			<button class="button button-primary" name="elko_run_import">
				Запустить импорт
			</button>
		</form>

		<hr />
		<h2>Остановить импорт</h2>
		<form method="post" style="display:inline-block;">
			<?php wp_nonce_field('elko_stop_import_action'); ?>
			<button class="button button-secondary" name="elko_stop_import_btn"
					<?php disabled($stop_flag, 1); ?>>
				Остановить
			</button>
		</form>
		<form method="post" style="display:inline-block;margin-left:10px;">
			<?php wp_nonce_field('elko_clear_stop_import_action'); ?>
			<button class="button button-secondary" name="elko_clear_stop_import_btn"
					<?php disabled(!$stop_flag, 1); ?>>
				Разблокировать
			</button>
		</form>

		<hr />
		<h2>Удалить импортированные товары</h2>
		<form method="post" onsubmit="return confirm('Точно удалить все импортированные товары?');">
			<?php wp_nonce_field('elko_delete_imported_action'); ?>
			<button class="button button-danger" name="elko_delete_imported">
				Удалить импортированные
			</button>
		</form>
	</div>
	<?php
}