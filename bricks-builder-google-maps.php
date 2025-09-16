<?php
defined( 'ABSPATH' ) or die( "you do not have access to this page!" );

function cmplz_bricks_googlemaps_script( $tags ) {
	$tags[] = array(
		'name' => 'google-maps',
		'category' => 'marketing',
		'placeholder' => 'google-maps',
		'urls' => array(
			'maps.googleapis.com',
			'infobox.min.js',
		),
		'enable_placeholder' => '1',
		'placeholder_class' => 'brxe-map real-map',
		'enable_dependency' => '1',
		'dependency' => [
			//'wait-for-this-script' => 'script-that-should-wait'
			'maps.googleapis.com' => 'infobox.min.js',
		],
	);
	return $tags;
}
add_filter( 'cmplz_known_script_tags', 'cmplz_bricks_googlemaps_script' );
