<?php
/**
 * Plugin Name: Store Motion Layer
 * Description: Lightweight, accessible depth and motion enhancements for the storefront presentation.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;

add_action('wp_enqueue_scripts',function(){
	if(is_admin())return;
	$base=plugin_dir_url(__FILE__);
	wp_enqueue_style('store-motion-layer',$base.'assets/motion.css',array(),'1.0.0');
	wp_enqueue_script('store-motion-layer',$base.'assets/motion.js',array(),'1.0.0',array('in_footer'=>true,'strategy'=>'defer'));
},99);
