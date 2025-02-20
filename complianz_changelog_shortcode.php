<?php
defined( 'ABSPATH' ) or die( "you do not have access to this page!" );

/**
* Shortcode to retrieve and display a changelog from a given URL.
*
* This function fetches a changelog from a specified URL and extracts version updates.
* The extracted content is displayed in a collapsible `<details>` HTML format, allowing
* users to expand and view different version changes.
*
* Usage example:
* [complianz_changelog url="https://example.com/changelog.txt"]
*
* @param array $atts Shortcode attributes.
*     - 'url' (string) The URL of the changelog file to fetch.
*
* @return string HTML formatted changelog content or an error message.
*/
 
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
 
    if (preg_match('/== Change log ==(.*?)==/s', $content, $matches)) {
        $changelog = trim($matches[1]);
    } else {
        return 'Changelog section not found.';
    }
 
    preg_match_all('/= (\d+\.\d+\.\d+) =\n(.*?)(?=\n= \d+\.\d+\.\d+ =|\Z)/s', $changelog, $entries, PREG_SET_ORDER);
 
    if (empty($entries)) {
        return 'No changelog entries found.';
    }

    $output = '<div class="complianz-changelog">';
    foreach ($entries as $entry) {
        $version = trim($entry[1]);
        $details = trim($entry[2]);
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
 
add_shortcode('complianz_changelog', 'complianz_changelog_shortcode');
