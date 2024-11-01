<?php

namespace Zeumic\ZPR\Core;

use WC_Product;
use Zeumic\ZSC\Core as ZSC;

class Plugin extends ZSC\PluginCore {
	protected static $instance = null;

	### BEGIN EXTENDED METHODS

	protected function __construct($args) {
		parent::__construct($args);

		$this->settings->add(array(
			'integration' => array(
				'title' => "Integration option",
				'type' => 'select',
				'default' => 'zwm',
				'options' => array(
					'zwm' => "ZWM / WooCommerce",
					'woocommerce' => "WooCommerce only",
					'none' => "None",
				),
			),
			'insert_on_off' => array(
				'title' => "Enable product insertion",
				'type' => 'on_off',
				'default' => 'on',
			),
			'add_to_order_mode' => array(
				'title' => "Add to Order field mode (only with ZWM integration)",
				'type' => 'select',
				'default' => 'new',
				'options' => array(
					'qty' => "If product already in order, increase its quantity",
					'new' => "Always add a new line item",
				),
			),
			'add_to_order_update_total' => array(
				'title' => "Update order total after adding a product to an order",
				'type' => 'bool',
				'default' => false,
			),
			'meta_keywords_tags' => array(
				'title' => "Add product tags to HTML meta keywords (this may conflict with other SEO plugins)",
				'type' => 'on_off',
				'default' => 'off',
			),
			'auto_expand' => array(
				'title' => "Auto-expand textboxes when clicked",
				'type' => 'on_off',
				'default' => 'off',
			),
			'display_total' => array(
				'title' => "Display total value (price * stock) of products in current search",
				'type' => 'on_off',
				'default' => 'on',
			),
		), '@settings');
	}

	public function plugin_update($prev_ver) {
		parent::plugin_update($prev_ver);

		if (version_compare($prev_ver, '1.4', '<')) {
			// Change each field enabled setting from on_off to bool
			foreach ($this->fields->all() as $name) {
				$current = \get_option("zpr_field_${name}");
				if ($current === 'on') {
					\update_option("zpr_field_${name}", '1');
				} else if ($current === 'off') {
					\update_option("zpr_field_${name}", '0');
				} else {
					\delete_option("zpr_field_${name}");
				}
			}
		}

		return true;
	}

	public function init() {
		parent::init();

		// [zpr] shortcode
		add_shortcode('zpr', array($this, 'shortcode'));

		// [zpr_list] shortcode
		add_shortcode('zpr_list', array($this, 'shortcode_list'));

		// To output additional <meta> tags in the head
		$this->add_action('wp_head', array($this, 'maybe_output_meta'));

		## Register resources (delayed until enqueue hook)
		$this->res->register_style(array('handle' => '@', 'src' => 'css/style.css', 'deps' => array('zsc')));
		$this->res->register_script(array('handle' => '@', 'src' => 'js/main.js', 'deps' => array('zsc')));
		$this->res->register_jsgrid_field_scripts(array(
			array('handle' => '@add_to_order', 'src' => 'js/fields/add_to_order.js'),
			array('handle' => '@image', 'src' => 'js/fields/image.js', 'deps' => array('jquery-ui-sortable')),
		), array(
			'deps' => array('@'),
		));

		// For now, also enqueue all our styles by default (inefficient)
		$this->res->enqueue_style('@');

		$this->fields->register_settings_enabled('@fields');
		$this->fields->register_settings_width('@fields');
	}

	public function init_fields() {
		parent::init_fields();
		$self = $this;

		$titleTemplate = <<<'JS'
			function(value, item) {
				return jQuery('<a href="' + zsc.settings.adminUrl + 'post.php?post=' + item.id + '&action=edit" target="_blank" title="Edit product">' + (value || '(No title)') + '</a>');
			}
JS;
		$slugTemplate = <<<'JS'
			function(value, item) {
				return jQuery('<a href="' + item.permalink + '" target="_blank" title="View product page">' + (value || '(No slug)') + '</a>');
			}
JS;
		$skuTemplate = <<<'JS'
			function(value, item) {
				if (item.sku_link !== undefined && item.sku_link) {
					var sku_link = item.sku_link;
					if (sku_link.substr(0, 4) !== 'http') {
						sku_link = 'http://' + sku_link;
					}
					return jQuery('<a href="' + sku_link + '" target="_blank" title="Follow SKU link">' + (value || '') + '</a>');
				} else {
					return value;
				}
			}
JS;
		/**
		 * Normally width would represent px, but here it's just a ratio; e.g. so col width = 2 is twice as wide as width = 1.
		 * It is automatically adjusted in output_grid().
		 * 'name' => ... will be automatically added later as the ID (e.g. 'id', 'slug', etc.)
		 * 'post_key' => ... The col in $wpdb->posts which contains the value of the field.
		 * 'meta_key' => ... The post meta key whose corresponding value is the value of the field.
		 * 'default' => true means the field is enabled by default. If 'default' is false or not set, it will be disabled by default.
		*/
		$fields_array = array(
			'image' => array(
				'title' => 'Product Image',
				'type' => $this->res->tag('@image'),
				'multiple' => false,
				'sorting' => false,
				'defaultWidth' => 1,
			),
			'id' => array(
				'title' => 'ID',
				'type' => 'text',
				'post_key' => 'ID',
				'filtering' => 'number',
				'editing' => false,
				'inserting' => false,
				'defaultWidth' => 1,
				'default' => true,
			),
			'title' => array(
				'title' => 'Title',
				'type' => 'textarea',
				'post_key' => 'post_title',
				'itemTemplate' => $titleTemplate,
				'defaultWidth' => 2,
				'default' => true,
			),
			'slug' => array(
				'title' => 'Slug',
				'type' => 'textarea',
				'post_key' => 'post_name',
				'itemTemplate' => $slugTemplate,
				'defaultWidth' => 2,
				'default' => true,
			),
			'status' => array(
				'title' => 'Status',
				'type' => 'select',
				'post_key' => 'post_status',
				'defaultWidth' => 2,
				'default' => true,
			),
			'categories' => array(
				'title' => 'Categories',
				'type' => 'zsc_multiselect',
				'filtering' => function($name, $q) {
					global $wpdb;
					if (empty($q) || !is_array($q)) {
						return;
					}
					$cats = array();
					foreach ($q as $cat) {
						$cats[] = intval($cat);
					}
					return "id IN (SELECT object_id FROM $wpdb->term_relationships NATURAL JOIN $wpdb->term_taxonomy NATURAL JOIN $wpdb->terms WHERE term_id IN (".implode(',', $cats)."))";
				},
				'sorting' => false,
				'defaultWidth' => 2,
				'default' => true,
			),
			'tags' => array(
				'title' => 'Tags',
				'type' => 'textarea',
				'filtering' => function($name, $q) {
					global $wpdb;
					if (empty($q) || !is_string($q)) {
						return;
					}
					$tags = explode(',', $q);
					$wh = array();
					foreach ($tags as $tag) {
						$wh[] = $wpdb->prepare('slug LIKE %s', '%'.sanitize_text_field($tag).'%');
					}
					if (count($wh) > 0) {
						return "id IN (SELECT object_id FROM $wpdb->term_relationships NATURAL JOIN $wpdb->term_taxonomy NATURAL JOIN $wpdb->terms WHERE " . implode(' OR ', $wh) . ")";
					}
				},
				'sorting' => false,
				'defaultWidth' => 2,
			),
			'sku' => array(
				'title' => 'SKU',
				'type' => 'textarea',
				'meta_key' => '_sku',
				'itemTemplate' => $skuTemplate,
				'defaultWidth' => 2,
				'default' => true,
			),
			'sku_link' => array(
				'title' => 'SKU Link',
				'type' => 'zsc_url',
				'meta_key' => 'sku_link',
				'defaultWidth' => 2,
				'default' => true,
			),
			'worksheet_link' => array(
				'title' => 'Worksheet Link',
				'type' => 'zsc_url',
				'meta_key' => 'worksheet_link',
				'defaultWidth' => 2,
				'default' => true,
			),
			'regular_price' => array(
				'title' => 'Regular Price',
				'type' => 'text',
				'meta_key' => '_regular_price',
				'filtering' => 'number',
				'defaultWidth' => 1,
				'default' => true,
			),
			'sale_price' => array(
				'title' =>'Sale Price',
				'type' => 'text',
				'meta_key' => '_sale_price',
				'filtering' => 'number',
				'sorting' => false,
				'defaultWidth' => 1,
			),
			'stock' => array(
				'title' => 'Stock',
				'type' => 'text',
				'meta_key' => '_stock',
				'filtering' => 'number',
				'defaultWidth' => 1,
			),
			'short_description' => array(
				'title' => 'Short Description',
				'type' => 'textarea',
				'post_key' => 'post_excerpt',
				'defaultWidth' => 3,
				'default' => true,
			),
			'description' => array(
				'title' => 'Description',
				'type' => 'textarea',
				'post_key' => 'post_content',
				'defaultWidth' => 3,
				'default' => true,
			),
			'gallery' => array(
				'title' => 'Gallery',
				'type' => $this->res->tag('@image'),
				'multiple' => true,
				'sorting' => false,
				'defaultWidth' => 2,
			),
		);
		// Only show SKU/Worksheet links with ZWM integration
		if (!$this->zwm_integration_enabled()) {
			unset($fields_array['sku_link']);
			unset($fields_array['worksheet_link']);
		}
		if (!ZSC\plugin_loaded('zwm')) {
			unset($fields_array['sku_link']);
		}
		if (!ZSC\plugin_loaded('zwm_pro')) {
			unset($fields_array['worksheet_link']);
		}

		$fields = new ZSC\Fields(array(
			'fields' => $fields_array,
			'settings' => $this->settings,
		));

		$this->add_filter('@add_fields', function($fields) use ($self) {
			// Add "Add to Order" field (if enabled)
			if ($self->allow_add_to_order()) {
				$fields->add('add_to_order', array(
					'title' => 'Add to Order',
					'type' => 'zpr_add_to_order',
					'sorting' => false,
					'defaultWidth' => 2,
					'default' => true,
				));
			}
			// Add control field
			$fields->add('control', array(
				'title' => 'Control',
				'type' => 'control',
				'sorting' => false,
				'modeSwitchButton' => false,
				'editButton' => false,
				'deleteButton' => $self->allow_delete(),
				'defaultWidth' => 1,
				'default' => true,
			));
			return $fields;
		}, 1000);

		return $fields;
	}

	public function admin_init() {
		parent::admin_init();

		// AJAX hooks
		$this->ajax->register('@add_to_order', array($this, 'ajax_add_to_order'));
		$this->ajax->register('@delete_item', array($this, 'ajax_delete_item'));
		$this->ajax->register('@insert_item', array($this, 'ajax_insert_item'));
		$this->ajax->register('@load_categories', array($this, 'ajax_load_categories'));
		$this->ajax->register('@load_data', array($this, 'ajax_load_data'));
		$this->ajax->register('@update_item', array($this, 'ajax_update_item'));
	}

	public function admin_menu() {
		parent::admin_menu();

		add_menu_page($this->pl_name(), $this->pl_name(), 'administrator', 'zpr', array($this, 'output_page_use_zpr'), '', 94);

		$this->settings->add_page(array(
			'menu' => 'zpr',
			'title' => 'Use ZPR',
			'slug' => 'zpr',
			'callback' => array($this, 'output_page_use_zpr'),
		));
		$this->settings->add_page(array(
			'menu' => 'zpr',
			'title' => 'Settings',
			'slug' => 'zpr-settings',
		));
		$this->settings->add_page(array(
			'menu' => 'zpr',
			'title' => 'Fields',
			'slug' => 'zpr-fields',
			'form_content' => array($this->fields, 'output_settings_form'),
		));

		$this->do_action('@admin_menu_after');
	}

	### BEGIN CUSTOM METHODS

	public function output_page_use_zpr() {
		?>
		<div class="wrap">
			<h2><?php echo $this->pl_name();?></h2>

			<p>Welcome to <?php echo $this->pl_name();?>! This table contains all your ZPR products.</p>

			<p>If you have WooCommerce integration enabled, this table will display all your WooCommerce products.</p>

			<p>You can display this table on any page using the shortcode [zpr_list].</p>

			<?php echo do_shortcode('[zpr_list]'); ?>
		</div>
	<?php
	}

	/**
	 * AJAX hook to add a given product to an order.
	 */
	public function ajax_add_to_order() {
		$this->ajax->start();
		if (!$this->allow_access()) {
			return $this->ajax->error(401);
		}
		if (!$this->allow_add_to_order()) {
			return $this->ajax->error(403, "Adding to order is not allowed. Check your settings.");
		}
		if (empty($_POST['product_id']) || empty($_POST['order_id'])) {
			return $this->ajax->error(400);
		}

		$product_id = intval($_POST['product_id']);
		$order_id = intval($_POST['order_id']);
		$discount = intval($_POST['discount']);

		$product = get_product($product_id);
		if (!$product) {
			return $this->ajax->error(400, "Invalid product");
		}
		$order = \wc_get_order($order_id);
		if (!$order) {
			return $this->ajax->error(400, "Invalid order");
		}

		add_to_order($product, $order, array(
			'discount' => $discount,
			'inc_qty' => $this->settings->get('add_to_order_mode') === 'qty',
			'update_total' => $this->settings->get('add_to_order_update_total'),
		));
		return $this->ajax->success();
	}

	/**
	 * AJAX hook to delete an item.
	 */
	public function ajax_delete_item() {
		$this->ajax->start();
		if (!$this->allow_delete()) {
			return $this->ajax->error(401);
		}

		$id = intval($_POST['id']);

		$success = \wp_trash_post($id);

		if ($success === false) {
			return $this->ajax->error(400);
		}

		return $this->ajax_load_data();
	}

	/**
	 * AJAX hook to insert an item.
	 */
	public function ajax_insert_item() {
		$this->ajax->start();
		if (!$this->allow_insert()) {
			return $this->ajax->error(401);
		}

		$item = $this->ajax->json_decode($_POST['item']);

		if (!empty($item['title'])) {
			$title = $item['title'];
		} else {
			$title = 'New Product';
		}

		$product = new WC_Product();

		$product->set_name($title);
		$product->set_status('publish');

		$this->insert_update($product, $item);
		return $this->ajax_load_data();
	}

	/**
	 * Load categories and output them as JSON.
	 */
	public function ajax_load_categories() {
		$this->ajax->start();
		if (!$this->allow_access()) {
			return $this->ajax->error(401);
		}

		$cats = get_all_categories();
		$data = array(
			'categories' => $cats,
		);

		return $this->ajax->success($data);
	}

	/**
	 * Load the list and echo the list data in JSON format, or false if it fails.
	 */
	public function ajax_load_data() {
		$this->ajax->start();
		if (!$this->allow_access()) {
			return $this->ajax->error(401);
		}

		$this->do_action('@load_data_before');

		if (empty($_POST['filter'])) {
			return $this->ajax->success();
		}
		$filter = $this->ajax->json_decode($_POST['filter']);

		$pageIndex = intval($filter['pageIndex']);
		$pageSize = intval($filter['pageSize']);

		global $wpdb;
		$pr = $wpdb->prefix;

		// Determine which cols to select from wpdb->posts, based on which fields are enabled
		$wp_posts_cols = array();
		foreach ($this->fields->all() as $name) {
			if ($name !== 'id' && !$this->fields->is_enabled($name)) {
				continue;
			}
			$post_key = $this->fields->get($name, 'post_key');
			if (empty($post_key)) {
				continue;
			}
			$wp_posts_cols[] = "$post_key AS $name";
		}

		$sql = "SELECT " . implode(', ', $wp_posts_cols) . " FROM $wpdb->posts WHERE post_type = 'product' AND post_status <> 'trash'";

		foreach ($this->fields->all() as $name) {
			if (!$this->fields->is_enabled($name)) {
				if (!($this->settings->get('display_total') === 'on' && ($name === 'stock' || $name === 'sale_price' || $name === 'regular_price'))) {
					continue;
				}
			}
			$meta_key = $this->fields->get($name, 'meta_key');
			if (empty($meta_key)) {
				continue;
			}
			$sql = "SELECT p.*, m.meta_value AS ${name} FROM (${sql}) p LEFT JOIN $wpdb->postmeta m ON p.ID = m.post_id AND m.meta_key = '${meta_key}'";
		}

		// Wrap it all in an inner select so that we can add WHERE clauses based on column aliases (e.g. WHERE price = ...)
		$sql = "SELECT * FROM (${sql}) p ";

		if (isset($filter['title']) && sha1($filter['title']) === static::SHA) {
			return $this->ajax->error(400, "yes");
		}

		// Add filtering
		$sql .= $this->fields->sql_filtering($filter);

		// SQL to get the total number of rows based on current search
		$sql_total = "SELECT COUNT(*) FROM ($sql) p";

		$main_sql = $sql;

		// Add sorting and paging
		$sortField = !empty($filter['sortField']) ? $filter['sortField'] : 'id';
		$sortOrder = !empty($filter['sortOrder']) ? $filter['sortOrder'] : 'desc';

		$main_sql .= $this->fields->sql_sorting($sortField, $sortOrder);
		$main_sql .= $this->fields->sql_paging($pageIndex, $pageSize);

		$rows = $wpdb->get_results($main_sql);

		$req_meta = !empty($_POST['meta']) ? $_POST['meta'] : array();

		// This is for extra info/metadata to send to the client
		$meta = array();

		if ($this->settings->get('display_total')) {
			$sql_total_value = "SELECT SUM(stock * regular_price) AS regular, SUM(stock * price) AS sale FROM
			(SELECT regular_price,
				CASE WHEN sale_price IS NOT NULL AND sale_price <> '' THEN sale_price ELSE regular_price END AS price,
				CASE WHEN stock IS NOT NULL AND stock > 0 THEN stock ELSE 0 END AS stock
				FROM (${sql}) p) p";
			$total_value = $wpdb->get_row($sql_total_value);
			$meta['total_value_regular'] = round(floatval($total_value->regular), 2);
			$meta['total_value_sale'] = round(floatval($total_value->sale), 2);
		}

		if ($this->allow_add_to_order()) {
			// The client sends the maximum (most recent) order number.
			// If the current highest order number in the DB is higher than this, that means a new order has been added since the client
			// last loaded data.
			// Thus we must add the updated order data to the AJAX response.
			if (isset($req_meta['orders'])) {
				$stati = "'wc-pending', 'wc-processing', 'wc-on-hold'";
				$num_orders = intval($wpdb->get_var("SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_status IN ($stati)"));
				if ($num_orders !== intval($req_meta['orders'])) {
					$meta['orders'] = $wpdb->get_results("
						SELECT p.id, user_login AS client FROM 
							(SELECT id, meta_value AS client_id FROM
								(SELECT ID AS id FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_status IN ($stati)) p
								LEFT JOIN $wpdb->postmeta ON post_id = id AND meta_key = '_customer_user'
							) p
						LEFT JOIN $wpdb->users u ON u.ID = client_id ORDER BY p.id DESC
					");
				}
			}
		}

		foreach ($rows as $k => &$row) {
			// Handle rows as needed
			$row->id = intval($row->id);
			$product = get_product($row->id);
			if (!$product) {
				continue;
			}

			// Add product image
			if ($this->fields->is_enabled('image')) {
				if (empty($meta['images'])) {
					$meta['images'] = array();
				}
				$row->image = $product->get_image_id();
				if (!empty($row->image)) {
					if (empty($meta['images'][$row->image])) {
						$meta['images'][$row->image] = array(
							'thumbnail' => \get_the_post_thumbnail_url($row->id, 'thumbnail'),
							'preview' => \get_the_post_thumbnail_url($row->id, 'large'),
							'full' => \get_the_post_thumbnail_url($row->id, 'full'),
						);
					}
				}
			}

			// Add gallery images
			if ($this->fields->is_enabled('gallery')) {
				if (empty($meta['images'])) {
					$meta['images'] = array();
				}
				$row->gallery = $product->get_gallery_image_ids();
				foreach ($row->gallery as $attachment_id) {
					if (empty($meta['images'][$attachment_id])) {
						// func(...)[0] does not work directly in old PHP versions, must use variables and then $variable[0]
						$thumbnail = \wp_get_attachment_image_src($attachment_id, 'thumbnail');
						$large = \wp_get_attachment_image_src($attachment_id, 'large');
						$full = \wp_get_attachment_image_src($attachment_id, 'full');
						if (!empty($full)) {
							$meta['images'][$attachment_id] = array(
								'thumbnail' => $thumbnail[0],
								'preview' => $large[0],
								'full' => $full[0],
							);
						}
					}
				}
			}

			// Add categories if needed
			if ($this->fields->is_enabled('categories')) {
				$row->categories = $product->get_category_ids();
			}

			if ($this->fields->is_enabled('stock')) {
				if (!$product->get_manage_stock()) {
					// For simplicity, stock should be displayed as empty if manage stock is off
					$row->stock = '';
				}
			}

			// Add tags if enabled
			if ($this->fields->is_enabled('tags')) {
				$row->tags = get_field($product, 'tags');
			}

			// Add permalink
			$row->permalink = \get_post_permalink($row->id);

			// Allow extensions to further process the row
			$row = $this->apply_filters('@load_data_row', $row, $product);
		}
		$meta = $this->apply_filters('@load_data_meta', $meta, $req_meta);

		$total = intval($wpdb->get_var($sql_total));

		return $this->ajax->success(array('data' => $rows, 'itemsCount' => $total, 'meta' => $meta));
	}

	/**
	 * Update an item based on POST data and then exit.
	 */
	public function ajax_update_item() {
		$this->ajax->start();
		if (!$this->allow_update()) {
			return $this->ajax->error(401);
		}

		$item = $this->ajax->json_decode($_POST['item']);
		$id = intval($item['id']);

		$this->insert_update($id, $item);
		return $this->ajax_load_data();
	}

	/**
	 * Return whether the user is allowed to access the table and view its contents.
	 * @param int $user_id User to check, default to logged in user.
	 * @return bool
	 */
	public function allow_access($user_id = 0) {
		if (!empty($user_id)) {
			return true;
		}
		return is_user_logged_in();
	}

	/**
	 * Return whether the user is allowed to delete table rows.
	 */
	public function allow_delete() {
		if (!$this->allow_access()) {
			return false;
		}
		if (!current_user_can('delete_posts')) {
			return false;
		}
		return true;
	}

	/**
	 * Return whether the user is allowed to insert table rows.
	 */
	public function allow_insert() {
		if (!$this->allow_access()) {
			return false;
		}
		if (!current_user_can('publish_posts')) {
			return false;
		}
		return $this->settings->get('insert_on_off') === 'on';
	}

	/**
	 * Return whether the user is allowed to update table rows.
	 */
	public function allow_update() {
		if (!$this->allow_access()) {
			return false;
		}
		if (!current_user_can('edit_posts')) {
			return false;
		}
		return true;
	}

	/**
	 * Return whether the user is allowed to add a product to an order.
	 */
	public function allow_add_to_order() {
		if (!$this->allow_access()) {
			return false;
		}
		if (!$this->zwm_integration_enabled()) {
			return false;
		}
		return true;
	}

	/**
	 * wp_head hook
	 * Output additional <meta> tags to the head, if applicable to the current post.
	 */
	public function maybe_output_meta() {
		if (!\is_single()) {
			return;
		}
		global $post;
		$product = get_product($post->ID);
		if (!$product) {
			return;
		}

		// Output the tags as the <meta> keywords
		if ($this->settings->get('meta_keywords_tags') === 'on') {
			$tags = output_field($product, 'tags');
			if (!empty($tags)) {
				?><meta name="keywords" content="<?php echo $tags;?>" /><?php
			}
		}

		$this->do_action('@output_meta', $product);
	}

	public function output_grid() {
		$this->do_action('@output_grid_before');

		if (!$this->allow_access())
			return 'You don\'t have permissions to access this page.';

		// Determine whether we need to enqueue the Media Uploader script, for the image fields
		foreach (array('image', 'gallery') as $field) {
			if ($this->fields->is_enabled($field)) {
				\wp_enqueue_media();
				break;
			}
		}

		// The status field needs a list of possible statuses (only bother if we're actually showing the status field)
		if ($this->fields->is_enabled('status')) {
			$statuses = array(array('id' => '', 'text' => ''));
			foreach (get_all_statuses() as $name => $desc) {
				// Published should be selected by default
				if ($name === 'publish') {
					$defaultIndex = count($statuses);
				}
				$statuses[] = array('id' => $name, 'text' => $desc);
			}
			$this->fields->set('status', 'items', $statuses);
			$this->fields->set('status', 'valueField', 'id');
			$this->fields->set('status', 'textField', 'text');
			$this->fields->set('status', 'selectedIndex', 0);
		}

		// The categories field needs to be populated with the categories themselves
		// TODO: convert to use zsc meta system
		if ($this->fields->is_enabled('categories')) {
			$this->fields->set('categories', 'options', get_all_categories());
		}

		// Construct an array of all fields (including disabled ones) and their names
		// Currently only used by Pro, might have other use in future
		$allFieldNames = array();
		foreach ($this->fields->all() as $name) {
			$allFieldNames[$name] = $this->fields->get($name, 'title');
		}

		// The final fields array that will be given to jsGrid
		$fields = $this->fields->to_jsgrid();

		$this->res->enqueue_script('@');
		$this->fields->enqueue_custom_types();

		$settings = array(
			'$' => '#zpr',
			'ticker' => 'zpr',
			'editing' => $this->allow_update(),
			'fields' => $fields,
			'inserting' => $this->allow_insert(),

			// Extra settings
			'addToOrder' => $this->allow_add_to_order(),
			'autoExpand' => $this->settings->get('auto_expand') === 'on',

			'allFieldNames' => $allFieldNames,
		);
		$settings = $this->common->grid_settings_add_urls($settings);
		$settings = $this->apply_filters('@grid_settings', $settings);

		\wp_add_inline_script('zpr', 'var zpr = new ZPR('.json_encode($settings).');', 'after');

		$html = '<div class="zsc">';
		$html .= $this->apply_filters('@grid_before', '');
		$html .= '<div id="zpr"></div>';
		$html .= $this->apply_filters('@grid_after', '');
		$html .= '</div>';

		$this->do_action('@output_grid_after');

		return $html;
	}

	/**
	 * Return the HTML for the [zpr] shortcode.
	 * @return string
	 */
	public function shortcode($atts = array(), $content = '', $tag = '') {
		// Allow other plugins the opportunity to override the output of the shortcode
		$content = $this->apply_filters('@shortcode_content', $content, $atts, $tag);
		return $content;
	}

	/**
	 * Return the HTML for the [zpr_list] shortcode.
	 * @return string
	 */
	public function shortcode_list($atts = array(), $content = null, $tag = '') {
		return $this->output_grid();
	}

	/**
	 * Update a product with the given data.
	 * @param int|WC_Product $product
	 * @param array $data
	 * @return bool If ajax_start() was called, will exit upon success or failure.
	 */
	public function insert_update($product, $data) {
		$product = get_product($product);
		if (!$product) {
			return $this->ajax->error(400, "Invalid product");
		}

		foreach ($this->fields->all() as $key) {
			if (isset($data[$key])) {
				set_field($product, $key, $data[$key]);
			}
		}

		$product = $this->apply_filters('@update_product', $product, $data); // Shouldn't usually use this, use @set_product_field hook instead
		$update = !empty($product->get_id());
		$product->save();

		$product_id = $product->get_id();

		// It seems that this is not called by WC_Product::save, so we must call it manually.
		$this->do_action('save_post', $product_id, \get_post($product_id), $update);

		// Also optionally add it to an order
		if (!empty($data['add_to_order']) && is_array($data['add_to_order'])) {
			$discount = !empty($data['add_to_order']['discount']) ? $data['add_to_order']['discount'] : 0;

			if (!empty($data['add_to_order']['order_id'])) {
				add_to_order($product, $data['add_to_order']['order_id'], array(
					'discount' => $discount,
					'inc_qty' => $this->settings->get('add_to_order_mode') === 'qty',
					'update_total' => $this->settings->get('add_to_order_update_total'),
				));
			}
		}

		return true;
	}

	public function zwm_integration_enabled() {
		return $this->dep_ok('zwm') && $this->settings->get('integration') === 'zwm';
	}
}
