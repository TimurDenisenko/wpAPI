<?php
if (!defined('ABSPATH')) {
	exit;
}


class Elko_Import {
	private $api;

	public function __construct(Elko_API $api) {
		$this->api = $api;
	}

	public function import_to_json(){
		$params['catalog'] = "CRA";
		$data = $this->api->get('Catalog/Products', $params);
		$productsKeys = ["name", "price", "quantity", "imagePath", "elkoCode"];
		$products = [];
		foreach ($data as $item) {
			$newItem = [];
			foreach ($productsKeys as $key) {
				if ($key != "elkoCode"){
					$newItem[$key] = $item[$key];
					continue;
				}
				$attributes = [];
				foreach($this->api->get('Catalog/Products/'.$item[$key].'/Description')[0]['description'] as $criteria){
					$critString = $criteria["criteria"];
					$value = $criteria["value"];
					$measurement = $criteria["measurement"];
					$attributes[$critString] = [$value, $measurement];
				}
				$newItem["attributes"] = $attributes;
				$gallery = [];
				foreach ($this->api->get('Catalog/MediaItems/'.$item['elkoCode'])[0]["mediaFiles"] as $image){
					if($image["sequence"]==1){
						continue;
					}
					$gallery[] = $image["link"];
				}
				$newItem["gallery"] = $gallery;
			}
			$products[] = $newItem;
		}
		$filePath = __DIR__ . '/elkojson.json';
		file_put_contents($filePath, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	}

	public function import_products_by_categoryid($limit, $skip_out, $skip_no_photo) {
		$params = [];
		$aliases = [];
		$content = json_decode(file_get_contents(__DIR__ . '/../assets/data.json'));
		foreach	($content as $value){
			foreach ($value->categories as $category){
				$aliases[$category][] = $value->alias;
			}
		}

		$products = [];
		foreach ($aliases as $key=>$value) {
			$params['catalog'] = $key;

			$data = $this->api->get('Catalog/Products', $params);
			
			foreach ($data as &$product){
				$product["categoryId"] = $key;
			}
			unset($product);
			$products = array_merge($products, $data);
		}
		shuffle($products);
		$imported_count = 0;
		foreach ($products as $item) {
			// Проверка флага "остановить"
			if (get_option('elko_stop_import', 0)) {
				break;
			}

			// skip out-of-stock?
			if ($skip_out && (isset($item['quantity']) ? (int)$item['quantity'] : 0) <= 0) {
				continue;
			}

			// skip no-photo?
			$imgs = $this->extract_images($item);
			if ($skip_no_photo && empty($imgs)) {
				if (empty($imgs)){
					continue;
				}
			}

			// нужен SKU (elkoCode)
			if (empty($item['elkoCode']) ? (string)$item['elkoCode'] : '') {
				continue;
			}

			// Импортируем
			if ($this->import_or_update_product($item, $imgs, json_encode($aliases[$item['categoryId']]))) {
				$imported_count++;
			}

			if ($imported_count >= $limit) {
				break;
			}
		}
		return $imported_count;
	}

	private function extract_images(array $item) {
		$res = [];
		if (!empty($item['imagePath'])) {
			$res[] = $item['imagePath'];
		}
		return array_filter(array_unique($res));
	}

	private function import_or_update_product(array $item, array $imgs, string $alias) {
		$sku = (string)$item['elkoCode'];
		$exist_id = wc_get_product_id_by_sku($sku);

		if ($exist_id) {
			$product = wc_get_product($exist_id);
			if (!$product) {
				$product = new WC_Product_Simple();
				$product->set_sku($sku);
			}
		} else {
			$product = new WC_Product_Simple();
			$product->set_sku($sku);
		}

		// Название
		$product->set_name(!empty($item['name']) ? $item['name'] : 'No Name');

		// Цена
		$product->set_regular_price(isset($item['price']) ? (float)$item['price'] : 0);

		// Остаток
		$qty = isset($item['quantity']) ? (int)$item['quantity'] : 0;
		$product->set_manage_stock(true);
		$product->set_stock_quantity($qty);
		$product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

		// Публикуем
		$product->set_status('publish');
		$pid = $product->save();

		// Привязать категорию (по catalogName)
		$this->assign_category($pid, $alias);
		$this->assign_DescAndAttr($product, $this->api->get('Catalog/Products/'.$item['elkoCode'].'/Description'));
		$this->assign_gallery($product, $this->api->get('Catalog/MediaItems/'.$item['elkoCode']));

		// Фото
		if (!empty($imgs)) {
			$this->set_product_images($pid, $imgs);
		}

		// Метка
		update_post_meta($pid, '_elko_imported', 1);

		return $pid;
	}

	private function assign_gallery($pid, $images){
		$gallery = $pid->get_gallery_image_ids();
		foreach ($images as $imageArray) {
			foreach ($imageArray["mediaFiles"] as $image){
				if($image["sequence"]==1){
					continue;
				}
				$image_url = $image["link"];
				$tmp = download_url($image_url);
				$file_array = array();
				$file_array['name'] = basename($image_url);
				$file_array['tmp_name'] = $tmp;
				$id = media_handle_sideload($file_array, 0);
				$gallery[] = $id;
				$pid->set_gallery_image_ids($gallery);
			}
		}
		$pid->save();
	}

	private function assign_DescAndAttr($product, $fullDesc){
		$attributes = [];
		foreach($fullDesc as $criterias){
			foreach($criterias['description'] as $item){
				$criteria = $item["criteria"];
				if ($criteria == "Category Code"){
					continue;
				}
				$value = $item["value"];
				if ($criteria == "Description"){
					$product -> set_description($value);
					continue;
				}
				$measurement = $item["measurement"];
				$attribute = new WC_Product_Attribute();
				$attribute->set_name($criteria); 
				$attribute->set_options([$value.' '.$measurement]);
				$attribute->set_visible(true);
				$attribute->set_variation(false);
				$attributes[] = $attribute;
			}
		}
		$product->set_attributes($attributes);
		$product->save();
	}

	private function assign_category($product_id, $alias) {
		$aliases = json_decode($alias);
		foreach ($aliases as $cat)
		{
			$term = get_term_by('name', $cat, 'product_cat');
			if (!$term) {
				$r = wp_insert_term($cat, 'product_cat');
				if (!is_wp_error($r) && !empty($r['term_id'])) {
					$term_id = $r['term_id'];
				} else {
					return;
				}
			} else {
				$term_id = $term->term_id;
			}
			wp_set_object_terms($product_id, (int)$term_id, 'product_cat', true);
		}
	}

	private function set_product_images($product_id, array $urls) {
		require_once ABSPATH.'wp-admin/includes/file.php';
		require_once ABSPATH.'wp-admin/includes/media.php';
		require_once ABSPATH.'wp-admin/includes/image.php';

		$gallery_ids = [];
		$i = 0;
		foreach ($urls as $u) {
			// Проверка «остановить»
			if (get_option('elko_stop_import', 0)) {
				break;
			}

			$tmp = download_url($u);
			if (is_wp_error($tmp)) {
				error_log('[ELKO Import] download_url error: '.$tmp->get_error_message());
				continue;
			}
			$fa = [
				'name'     => basename($u),
				'tmp_name' => $tmp
			];
			$aid = media_handle_sideload($fa, $product_id);
			if (is_wp_error($aid)) {
				@unlink($tmp);
				error_log('[ELKO Import] media_handle_sideload: '.$aid->get_error_message());
				continue;
			}

			if ($i === 0) {
				set_post_thumbnail($product_id, $aid);
			} else {
				$gallery_ids[] = $aid;
			}
			$i++;
		}

		if (!empty($gallery_ids)) {
			update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
		}
	}

	/**
	 * Удаляем товары, где _elko_imported=1
	 */
	public static function delete_elko_products() {
		$q = new \WP_Query([
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => '_elko_imported',
					'value' => '1'
				]
			]
		]);
		$cnt=0;
		if ($q->have_posts()) {
			foreach ($q->posts as $p) {
				wp_delete_post($p->ID, true);
				$cnt++;
			}
		}
		return $cnt;
	}
}