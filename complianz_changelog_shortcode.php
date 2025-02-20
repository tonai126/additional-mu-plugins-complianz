<?php
defined( 'ABSPATH' ) or die( "you do not have access to this page!" );

function complianz_changelog_shortcode($atts) {

    $atts = shortcode_atts (array(
                'url' => ''
                ), $atts);

    if (empty($atts['url'])){

        return 'URL not found in the shortcode. Please insert a URL.';

    }

    $response = wp_remote_get($atts['url']);
 
    if (is_wp_error($response)) {
        return 'Error retrieving the changelog.';
    }
 
    $content = wp_remote_retrieve_body($response);
 
    if (empty($content)) {
        return 'No content found in the file.';
    }
 
    // Extract the Changelog section
    if (preg_match('/== Change log ==(.*?)==/s', $content, $matches)) {
        $changelog = trim($matches[1]);
    } else {
        return 'Changelog section not found.';
    }
 
    // Split the changelog into versions
    preg_match_all('/= (\d+\.\d+\.\d+) =\n(.*?)(?=\n= \d+\.\d+\.\d+ =|\Z)/s', $changelog, $entries, PREG_SET_ORDER);
 
    if (empty($entries)) {
        return 'No changelog entries found.';
    }
 
    // Build the <details> summary HTML
    $output = '<div class="complianz-changelog">';
    foreach ($entries as $entry) {
        $version = trim($entry[1]);
        $details = trim($entry[2]);
 
        // Remove any date lines
        //$details = preg_replace('/^\* [A-Za-z]+ \d{1,2}(st|nd|rd|th)?,? \d{4}\n/m', '', $details);
 
        // Convert newlines into list items
        $details = preg_replace('/\* (.+)/', '<li>$1</li>', trim($details));
 
        $output .= '
<details>
<summary>' . esc_html($version) . '</summary>
<ul>' . $details . '</ul>
</details>';
    }
 
    $output .= '</div>';
 
 
    return $output;
}
 
// Register shortcode
add_shortcode('complianz_changelog', 'complianz_changelog_shortcode');
