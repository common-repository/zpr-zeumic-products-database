<?php
/**
 * Plugin Name: ZPR Zeumic Products Database
 * Plugin URI: http://www.zeumic.com.au
 * Description: ZPR Free [Core]; Zeumic Products Database is a free product editor plugin that can work in tandem with WooCommerce and ZWM Zeumic Work Management to help you run your business. It provides a full list of products for easy and fast editing with abilities to further manage with integration.
 * Version: 1.8.1
 * Author: Zeumic
 * Author URI: http://www.zeumic.com.au
 * WC requires at least: 3.0.0
 * WC tested up to: 7
 * @package ZPR Zeumic Products Database
 * @author Zeumic
* */

global $zsc_dir;
$zsc_dir = __DIR__.'/zsc/';
require $zsc_dir . 'zsc-zeumic-suite-common.php';

add_filter('zsc_register_plugins', 'zpr_register_plugin');

function zpr_register_plugin($plugins) {
	$plugins->register('zpr', array(
		'file' => __FILE__,
		'require' => __DIR__.'/load.php', 
		'class' => 'Zeumic\\ZPR\\Core\\Plugin',
		'semver' => 'minor',
		'deps' => array(
			'wc' => '?7',
			'zsc' => '11.0',
			'zwm' => '?1',
		),
	));
}
