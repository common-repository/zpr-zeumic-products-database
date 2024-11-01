<?php
/**
 * A library of mostly pure functions for dealing with products.
 */

namespace Zeumic\ZPR\Core;

use WC_Product;
use Zeumic\ZSC\Core as ZSC;

/**
 * @param WC_Product|int The product or product ID.
 * @return WC_Product|null
 */
function get_product($product) {
	if (!($product instanceof WC_Product)) {
		$product = \wc_get_product(intval($product));
	}
	if (!$product) {
		return null;
	}
	return $product;
}

/**
 * Get all categories and return them as an array(
 *		'value' => the category ID
 *		'text' => the category name
 *		'display' => HTML to display the category name as a link
 * )
 * @return array
 */
function get_all_categories() {
	$terms = \get_terms(array(
		'taxonomy' => 'product_cat',
		'hide_empty' => false,
	));
	$cats = array();
	foreach ($terms as &$term) {
		$id = $term->term_id;
		$name = $term->name;
		$link = \get_term_link($term);
		$cats[] = array('value' => $id, 'text' => $name, 'display' => "<a href=\"${link}\" target=\"_blank\">${name}</a>");
	}
	return $cats;
}

/**
 * Get WP default post statuses and ZPR custom ones, as a {slug} => {name} array.
 * @return array
 */
function get_all_statuses() {
	$statuses = \get_post_statuses();
	$statuses['zpr_template'] = __("Template");
	return $statuses;
}

/**
 * Add a product to an order, possibly incrementing qty.
 * 
 * @param WC_Product $product The product to add.
 * @param WC_Order $order The order or order ID to which to add the product.
 * @param array $args
 * @param float $args['discount'] The percentage discount to apply.
 * @param bool|null $args['inc_qty'] Default: false. If the product already exists in the order, whether to increment its qty rather than adding a new order item.
 * @param bool|null $args['update_total'] Default: false. Whether to update the order total afterwards.
 * @return bool Whether successful. If ajax_start() has been called, will exit on failure.
 */
function add_to_order(WC_Product $product, \WC_Order $order, $args = array()) {
	global $wpdb;
	$pr = $wpdb->prefix;

	$args = \wp_parse_args($args, array(
		'discount' => 0,
		'inc_qty' => false,
		'update_total' => false,
	));

	$discount = $args['discount'];
	if ($discount < 0) {
		$discount = 0;
	} else if ($discount > 100) {
		$discount = 100;
	}
	$inc_qty = boolval($args['inc_qty']);
	$update_total = boolval($args['update_total']);

	$price = $product->get_price();
	$price = round(floatval($price), 2);
	$discount_price = round($price * (100 - $discount) / 100, 2);

	if ($inc_qty) {
		// If setting is to update qty of existing order item if the product is already in the order, we need to figure out whether it is
		$order_items = $order->get_items();
		$order_item = null;
		foreach ($order_items as $candidate) {
			if (!($candidate instanceof \WC_Order_Item_Product)) {
				continue;
			}
			if ($candidate->get_product_id() === $product->get_id()) {
				$order_item = $candidate;
				break;
			}
		}
	}

	if (empty($order_item)) {
		// If we want to add a new order item ...
		$args = array();
		if ($discount !== 0) {
			$args['subtotal'] = $price;
			$args['total'] = $discount_price;
		}
		// Insert order item itself
		$order->add_product($product, 1, $args);
	} else {
		// Otherwise, just increase quantity and update price
		$order_item->set_quantity($order_item->get_quantity() + 1);
		$order_item->set_subtotal($order_item->get_subtotal() + $price);
		$order_item->set_total($order_item->get_total() + $discount_price);
	}

	$order->save();

	if ($update_total) {
		$order->calculate_totals();
	}

	return true;
}

/**
 * Whether a key refers to a valid, non-custom field.
 * @return bool
 */
function is_field($key) {
	if (!is_string($key)) {
		return false;
	}
	if ($key === 'price') {
		return true;
	}

	$plugin = Plugin::get_instance();
	return $plugin->fields->has($key);
}

/**
 * Get the value of a given field in a product, based on the field name, as it would be returned from load_data. Not yet implemented for all fields.
 * Also supports: price.
 * @param WC_Product $product
 * @param string $key The field name.
 * @return mixed The value of the field, or null if invalid product or key.
 */
function get_field(WC_Product $product, $key) {
	$plugin = Plugin::get_instance();

	$value = null;
	if ($key === 'id') {
		$value = $product->get_id();
	} else if ($key === 'title') {
		$value = $product->get_name();
	} else if ($key === 'status') {
		$value = $product->get_status();
	} else if ($key === 'slug') {
		$value = $product->get_slug();
	} else if ($key === 'price') {
		$value = $product->get_price();
	} else if ($key === 'regular_price') {
		$value = $product->get_regular_price();
	} else if ($key === 'sale_price') {
		$value = $product->get_sale_price();
	} else if ($key === 'stock') {
		$value = $product->get_stock_quantity();
	} else if ($key === 'sku') {
		$value = $product->get_sku();
	} else if ($key === 'description') {
		$value = $product->get_description();
	} else if ($key === 'short_description') {
		$value = $product->get_short_description();
	} else if ($key === 'categories') {
		$value = $product->get_category_ids();
	} else if ($key === 'tags') {
		$tags = \wp_get_object_terms($product->get_id(), 'product_tag', array('fields' => 'slugs'));
		// For now, just send the tag slugs as a string (rather than IDs)
		$value = implode(', ', $tags);
	} else if ($plugin->fields->has($key, 'meta_key')) {
		$value = $product->get_meta($plugin->fields->get($key, 'meta_key'), true);
	}

	// Give extensions an opportunity to override the value returned here
	$value = $plugin->apply_filters('@product_get_field', $value, $product, $key);

	return $value;
}

/**
 * Set the value of a given field in a product, based on the field name.
 * @param WC_Product|int $product
 * @param string $key The field name.
 * @param mixed $input The new value for the field. Will be sanitized automatically.
 * @return WC_Product The updated product. Note you will have to call save() on this product after all updates are done.
 */
function set_field(WC_Product $product, $key, $input) {
	$plugin = Plugin::get_instance();

	if ($key === 'title') {
		if (!empty($input)) {
			$title = \sanitize_text_field($input);
			$product->set_name($title);
		}
	} else if ($key === 'status') {
		$stati = get_all_statuses();
		// Make sure it's a real status
		if (isset($stati[$input])) {
			$status = $input;
			$product->set_status($status);
		}
	} else if ($key === 'slug') {
		$slug = \sanitize_title($input);

		// If the slug field was emptied, we want to regenerate it from the title (either the new one if provided or the old one)
		if (empty($slug)) {
			$slug = \sanitize_title($product->get_name());
		}

		// Make sure it's unique
		//$slug = wp_unique_post_slug($slug, $id, $status, 'product', $old_post->post_parent);
		$product->set_slug($slug);

	} else if ($key === 'description') {
		$product->set_description($input);
	} else if ($key === 'short_description') {
		$product->set_short_description($input);
	} else if ($key === 'image') {
		$img = intval($input);
		if ($img === 0) {
			$product->set_image_id();
		} else {
			// Make sure the image exists
			if (\wp_get_attachment_image($img)) {
				$product->set_image_id($img);
			}
		}
	} else if ($key === 'gallery') {
		if (empty($input)) {
			$product->set_gallery_image_ids(array());
		} else {
			if (is_array($input)) {
				$imgs = array();
				foreach ($input as $img) {
					$img = intval($img);
					if ($img === 0)
						continue;
					// Make sure the image exists
					if (!\wp_get_attachment_image($img))
						continue;
					if (in_array($img, $imgs))
						continue;
					$imgs[] = $img;
				}
				$product->set_gallery_image_ids($imgs);
			}
		}
	} else if ($key === 'categories') {
		if (is_array($input)) {
			$product->set_category_ids($input);
		}
	} else if ($key === 'tags') {
		if (!is_array($input)) {
			$tags = explode(',', $input);
		}

		$tag_ids = array();
		foreach ($tags as $tag) {
			$tag = sanitize_text_field($tag);
			\wp_insert_term($tag, 'product_tag'); // Create the tag if it doesn't exist yet

			$tag_obj = get_term_by('slug', $tag, 'product_tag');

			if ($tag_obj) {
				$tag_ids[] = (int) $tag_obj->term_id;
			}
		}

		$product->set_tag_ids($tag_ids);
	} else if ($key === 'sku') {
		$sku = \sanitize_text_field($input);
		$product->set_sku($sku);
	} else if ($key === 'sku_link') {
		$product->update_meta_data('sku_link', \sanitize_text_field($input));
	} else if ($key === 'worksheet_link') {
		$product->update_meta_data('worksheet_link', \sanitize_text_field($input));
	} else if ($key === 'stock') {
		$product->set_stock_quantity($input);
	} else if ($key === 'regular_price') {
		$product->set_regular_price($input);
	} else if ($key === 'sale_price') {
		$product->set_sale_price($input);
	} else if ($plugin->fields->has($key, 'meta_key')) {
		$product->update_meta_data($plugin->fields->get($key, 'meta_key'), $input);
	}

	// Give extensions an opportunity
	$product = $plugin->apply_filters('@product_set_field', $product, $key, $input);

	return $product;
}

/**
 * Get meta associated with a product's field.
 * ZPR allows products to store extra information about their fields, including custom fields.
 * Not fully tested for all cases.
 * 
 * Examples:
 * get_field_meta($product) == ['google_link' => ['validation' => 'url'], 'custom' => ['maxlength' => 160], 'other' => ['a' => 1, 'b' => 2]]
 * get_field_meta($product, 'google_link') == ['validation' => 'url']
 * get_field_meta($product, 'google_link', 'validation') == 'url'
 * get_field_meta($product, null, ['maxlength' => true, 'a' => true]) == ['custom' => ['maxlength' => 160], 'other' => ['a' => 1]]
 * get_field_meta($product, null, 'maxlength') == ['custom' => 160]
 * 
 * @param WC_Product|int $product
 * @param string|array $fields Name of the field or fields to retrieve meta about. If not provided, will retrieve all stored meta.
 * @param string|array $meta_keys Meta key or keys to get for the given field.
 * @param bool $check_templates If true, will also include relevant meta from templates.
 * @return mixed
 */
function get_field_meta(WC_Product $product, $check_templates = false, $fields = true, $meta_keys = true) {
	// [$field => [$meta_key => $meta_value, ...], ...]
	$meta = $product->get_meta('_zpr_field_meta', true);
	if (!$meta) {
		$meta = array(); // Could be '', which would make it a string rather than array
	}

	$meta = ZSC\array_match_structure($meta, $fields);

	if (is_array($fields)) {
		// [$field => [$meta_key => $meta_value, ...], ...]
		foreach ($meta as $field => &$field_meta) {
			$field_meta = ZSC\array_match_structure($field_meta, $meta_keys);
		}
	} else {
		// [$meta_key => $meta_value, ...]
		$meta = ZSC\array_match_structure($meta, $meta_keys);
	}

	// Possibly add template
	if ($check_templates) {
		$template_id = $product->get_parent_id();
		$template = get_product($template_id);
		if ($template) {
			$template_meta = get_field_meta($template, true, $fields, $meta_keys);
			// Merge the template meta into the product's meta, using them as "default values"
			$meta = ZSC\array_deep_merge($meta, $template_meta);
		}
	}
	return $meta;
}

/**
 * Set a meta of a given field in a product.
 * @param WC_Product $product
 */
function set_field_meta(WC_Product $product, array $all_meta) {
	if (empty($all_meta)) {
		$product->delete_meta_data('_zpr_field_meta');
	} else {
		$product->update_meta_data('_zpr_field_meta', $all_meta);
	}
	return $product;
}

/**
 * Get the value of a given field in a product, based on the field name, as a string/HTML which could be displayed to the user. Not yet implemented for all fields.
 * @param WC_Product $product
 * @param string $key The field name.
 * @return string|null The value of the field as a string/HTML, or null if invalid product or key.
 */
function output_field(WC_Product $product, $key) {
	$plugin = Plugin::get_instance();

	$output = null;
	if ($key === 'categories') {
		$cats = \wp_get_object_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
		if (is_array($cats)) {
			$output = implode(', ', $cats);
		}
	} else {
		$output = get_field($product, $key);
		if ($output !== null) {
			if (is_array($output)) {
				$output = array_map('strval', $output);
				$output = implode(', ', $output);
			} else {
				$output = strval($output);
			}

			if ($key === 'description' || $key === 'short_description') {
				$output = \do_shortcode($output);
			}
		}
	}

	// Give extensions an opportunity to override the value returned here
	$output = $plugin->apply_filters('@product_output_field', $output, $product, $key);

	return $output;
}
