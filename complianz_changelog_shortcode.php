<?php
defined( 'ABSPATH' ) or die( "you do not have access to this page!" );

if ( ! class_exists( 'CMPLZ_Changelog_Document' ) ) {

    final class CMPLZ_Changelog_Document {
        private static $instance = null;
        const TRANSIENT_PREFIX = 'cmplz_changelog_';
        const SHORTCODE        = 'complianz_changelog';

        private function __construct() {
            add_action( 'init', [ $this, 'register_shortcode' ] );
            // Ensure Elementor's TOC scans the already-shortcoded content
            add_filter( 'elementor/frontend/the_content', [ $this, 'run_shortcodes_early' ], 1 );
        }

        public static function instance() : self {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /** Register the shortcode */
        public function register_shortcode() : void {
            add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
        }

        /**
         * Shortcode callback.
         * Usage examples:
         *   [complianz_changelog url="https://example.com/changelog.txt"]                 -> show all versions
         *   [complianz_changelog url="https://example.com/changelog.txt" version="7.5.4"] -> show only that version
         */
        public function render_shortcode( $atts ) : string {
            $atts = shortcode_atts(
                [
                    'url'     => '',
                    'version' => '', // optional
                ],
                $atts,
                self::SHORTCODE
            );

            $url     = trim( (string) $atts['url'] );
            $version = trim( (string) $atts['version'] );

            if ( $url === '' ) {
                return $this->wrap_notice( __( 'URL not found in the shortcode. Please insert a URL.', 'complianz-gdpr' ) );
            }

            // Cache final HTML per URL + version (even if version is empty) for 24h
            $cache_key = $this->cache_key( $url, $version );
            $cached    = get_transient( $cache_key );
            if ( $cached ) {
                return $cached;
            }

            $content = $this->fetch_remote_body( $url );
            if ( is_wp_error( $content ) ) {
                return $this->wrap_notice( esc_html( $content->get_error_message() ) );
            }
            if ( $content === '' ) {
                return $this->wrap_notice( __( 'No content found in the file.', 'complianz-gdpr' ) );
            }

            // Extract requirements from the whole file (not restricted to the changelog section)
            $requirements = $this->extract_requirements( $content );

            $changelog_section = $this->extract_changelog_section( $content );
            if ( $changelog_section === '' ) {
                return $this->wrap_notice( __( 'Changelog section not found.', 'complianz-gdpr' ) );
            }

            // Extract all entries to determine the latest version
            $all_entries = $this->extract_entries( $changelog_section );
            if ( empty( $all_entries ) ) {
                return $this->wrap_notice( __( 'No changelog entries found.', 'complianz-gdpr' ) );
            }

            // Compute latest version from the full set
            $latest_version = $this->latest_version( $all_entries );

            // If "version" attribute is provided, filter to that exact version
            $entries = $all_entries;
            if ( $version !== '' ) {
                $entries = array_values(
                    array_filter(
                        $entries,
                        static function ( $e ) use ( $version ) {
                            return isset( $e['version'] ) && version_compare( $e['version'], $version, '==' );
                        }
                    )
                );
                if ( empty( $entries ) ) {
                    return $this->wrap_notice(
                        sprintf(
                            /* translators: %s: version string */
                            esc_html__( 'Requested version not found: %s', 'complianz-gdpr' ),
                            esc_html( $version )
                        )
                    );
                }
            }

            $html = $this->build_html( $entries, $requirements, $latest_version );
            set_transient( $cache_key, $html, DAY_IN_SECONDS );

            return $html;
        }

        /** Safe HTTP fetch for remote body */
        private function fetch_remote_body( string $url ) {
            $args     = [
                'timeout'     => 10,
                'redirection' => 3,
                'headers'     => [
                    'Accept' => 'text/plain,text/markdown,text/*;q=0.9,*/*;q=0.8',
                ],
            ];
            $response = wp_safe_remote_get( esc_url_raw( $url ), $args );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'cmplz_changelog_http_error', __( 'Error retrieving the changelog.', 'complianz-gdpr' ) );
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) {
                return new WP_Error( 'cmplz_changelog_http_status', sprintf( __( 'HTTP error: %d', 'complianz-gdpr' ), (int) $code ) );
            }

            $body = wp_remote_retrieve_body( $response );
            return is_string( $body ) ? $body : '';
        }

        /** Extract the "== Changelog ==" (or "== Change log ==" variants) section */
        private function extract_changelog_section( string $content ) : string {
            if ( preg_match( '/==\s*Change(?:\s+|)log\s*==(.*?)(?=\n==|\r\n==|$)/si', $content, $m ) ) {
                return trim( $m[1] );
            }
            return '';
        }

        /**
         * Parse entries of the form:
         *   = 7.5.4 =
         *   * Item one
         *   * Item two
         * Converts Markdown asterisk bullets into semantic <ul><li>…</li></ul>.
         */
        private function extract_entries( string $changelog ) : array {
            $entries = [];
            if ( preg_match_all(
                '/=\s*([0-9]+(?:\.[0-9]+)+)\s*=(.*?)(?=\n=\s*[0-9]+(?:\.[0-9]+)+\s*=|\r\n=\s*[0-9]+(?:\.[0-9]+)+\s*=|\Z)/s',
                $changelog,
                $matches,
                PREG_SET_ORDER
            ) ) {
                foreach ( $matches as $entry ) {
                    $ver = trim( $entry[1] );
                    $txt = trim( $entry[2] );

                    // Convert Markdown bullets to <li>
                    $txt = preg_replace( '/^\s*\*\s+(.+)$/m', '<li>$1</li>', $txt );

                    // Group consecutive <li>…</li> blocks under one <ul>
                    if ( preg_match( '/<li>/', $txt ) ) {
                        $txt = preg_replace_callback(
                            '/(?:\s*<li>.*?<\/li>\s*)+/s',
                            static function ( $m ) {
                                return '<ul class="cmplz-changelog-list">' . trim( $m[0] ) . '</ul>';
                            },
                            $txt
                        );
                    }

                    // Drop lines that are just dates like "* August 2nd, 2025"
                    $txt = preg_replace( '/^\s*\*\s+[A-Za-z]+\s+\d{1,2}[a-z]{0,2},\s+\d{4}\s*$/m', '', $txt );

                    // Compress excessive blank lines
                    $txt = preg_replace( '/(\R){3,}/', "\n\n", $txt );

                    $entries[] = [
                        'version' => $ver,
                        'details' => $txt,
                    ];
                }
            }
            return $entries;
        }

        /**
         * Extract requirements (WordPress, PHP, Tested up to) from the header area.
         * Looks for lines like:
         *   Requires at least: 5.9
         *   Requires PHP: 7.4
         *   Tested up to: 6.8
         *
         * @param string $content Raw changelog/readme content
         * @return array ['wp' => string, 'php' => string, 'tested' => string]
         */
        private function extract_requirements( string $content ) : array {
            $req = [
                'wp'     => '',
                'php'    => '',
                'tested' => '',
            ];

            // "Requires at least: 5.9"
            if ( preg_match( '/Requires at least:\s*([0-9\.]+)/i', $content, $m ) ) {
                $req['wp'] = $m[1];
            }

            // "Requires PHP: 7.4"
            if ( preg_match( '/Requires PHP:\s*([0-9\.]+)/i', $content, $m ) ) {
                $req['php'] = $m[1];
            }

            // "Tested up to: 6.8"
            if ( preg_match( '/Tested up to:\s*([0-9\.]+)/i', $content, $m ) ) {
                $req['tested'] = $m[1];
            }

            return $req;
        }

        /** Return the highest semantic version found among entries */
        private function latest_version( array $entries ) : string {
            $versions = array_column( $entries, 'version' );
            if ( empty( $versions ) ) return '';
            usort( $versions, 'version_compare' ); // ascending
            return end( $versions );
        }

        /**
         * Render:
         *  - A fixed, non-collapsible "Requirements" card (if any values found)
         *  - H2 per major (e.g., 7.5.x)
         *  - <details> per version with a "Latest" badge on the most recent version
         */
        private function build_html( array $entries, array $requirements = [], string $latest_version = '' ) : string {
            $out           = [];
            $current_major = '';
            $out[]         = '<div class="cmplz-changelog" style="position:relative;z-index:1;">';

            // Fixed Requirements card (only if at least one value exists)
            if ( ! empty( $requirements ) && ( $requirements['wp'] || $requirements['php'] || $requirements['tested'] ) ) {
                $out[] = '<div class="cmplz-changelog-requirements">';
                $out[] = '<h3>Requirements</h3>';
                $out[] = '<ul>';
                if ( $requirements['wp'] ) {
                    $out[] = '<li>Requires at least WordPress version: ' . esc_html( $requirements['wp'] ) . '</li>';
                }
                if ( $requirements['tested'] ) {
                    $out[] = '<li>Tested up to WordPress version: ' . esc_html( $requirements['tested'] ) . '</li>';
                }
                if ( $requirements['php'] ) {
                    $out[] = '<li>Requires PHP: ' . esc_html( $requirements['php'] ) . '</li>';
                }
                $out[] = '</ul>';
                $out[] = '</div>';
            }

            foreach ( $entries as $entry ) {
                $version = $entry['version'];
                $details = $entry['details'];

                // Group by major version (e.g., 7.5)
                if ( preg_match( '/^(\d+\.\d+)/', $version, $mm ) ) {
                    $major = $mm[1];
                } else {
                    $major = $version;
                }

                if ( $major !== $current_major ) {
                    $current_major = $major;
                    $heading_label = apply_filters(
                        'cmplz_changelog_major_heading',
                        sprintf( 'Complianz %s.x', $current_major ),
                        $current_major
                    );

                    $out[] = sprintf(
                        '<h2 id="cmplz-version-%1$s">%2$s</h2>',
                        esc_attr( str_replace( '.', '-', $current_major ) ),
                        esc_html( $heading_label )
                    );
                }

                // Sanitize allowed HTML in details
                $details_html = wp_kses(
                    $details,
                    [
                        'div'    => [ 'class' => [] ],
                        'ul'     => [ 'class' => [] ],
                        'li'     => [],
                        'br'     => [],
                        'em'     => [],
                        'strong' => [],
                        'code'   => [],
                        'a'      => [ 'href' => [], 'title' => [], 'rel' => [], 'target' => [] ],
                    ]
                );

                // Add "Latest" badge only to the globally latest version
                $badge = '';
                if ( $latest_version && version_compare( $version, $latest_version, '==' ) ) {
                    $badge = ' <span class="cmplz-changelog-latest">Latest</span>';
                }

                $out[] = sprintf(
                    '<details class="cmplz-changelog-entry"><summary><h4 class="cmplz-changelog-summary">%1$s%3$s</h4></summary><div class="cmplz-changelog-details">%2$s</div></details>',
                    esc_html( $version ),
                    $details_html,
                    $badge
                );
            }

            $out[] = '</div>';
            return implode( "\n", $out );
        }

        /** Run shortcodes early so other processors (like TOC) see the expanded content */
        public function run_shortcodes_early( $content ) {
            if ( is_string( $content ) && has_shortcode( $content, self::SHORTCODE ) ) {
                return do_shortcode( $content );
            }
            return $content;
        }

        /** Helpers */
        private function cache_key( string $url, string $version ) : string {
            return self::TRANSIENT_PREFIX . md5( $version . '|' . $url );
        }

        private function wrap_notice( string $text ) : string {
            $text = esc_html( $text );
            return '<div class="cmplz-changelog" style="position:relative;z-index:1;"><div class="cmplz-changelog-entry" role="status">' . $text . '</div></div>';
        }
    }

    // Bootstrap singleton
    CMPLZ_Changelog_Document::instance();
}

/**
 * Stylesheet printed in the footer as a <style> block
 * (kept inline/forced as requested)
 */
add_action( 'wp_footer', function () {
    ?>
    <style>
        .cmplz-changelog{ clear: both; width: 100%; margin: 20px 0; }
        .cmplz-changelog h2{ margin-top: 30px !important; margin-bottom: 15px !important; font-size: 1.50rem !important;}

        /* Card */
        .cmplz-changelog-entry{
            margin-bottom:12px;
            border:1px solid #e6e6e9;
            border-radius:10px;
            background:#fff;
            overflow:hidden;
            box-shadow:0 1px 0 rgba(16,24,40,.02);
        }

        /* Card header */
        .cmplz-changelog-entry > summary{
            display:flex;
            align-items:center;
            gap:8px;
            padding:12px 14px;
            cursor:pointer;
            font-weight:600;
            list-style:none;
            user-select:none;
        }

        /* Custom arrow */
        .cmplz-changelog-entry > summary::-webkit-details-marker{ display:none; }
        .cmplz-changelog-entry > summary::before{
            content:"▸";
            display:inline-block;
            transform-origin:center;
            transition:transform .2s ease;
            font-size:12px;
            line-height:1;
        }
        .cmplz-changelog-entry[open] > summary::before{ transform:rotate(90deg); }

        /* Version number */
        .cmplz-changelog h4.cmplz-changelog-summary{
            font-size:1rem;
            font-weight:600;
            margin:0;
            display:inline;
        }

        /* Hover/Focus */
        .cmplz-changelog-entry > summary:hover{ background:#f9fafb; }
        .cmplz-changelog-entry > summary:focus-visible{
            outline:2px solid #4f46e5;
            outline-offset:2px;
        }

        /* Card body */
        .cmplz-changelog .cmplz-changelog-details{
            padding:12px 14px 14px;
            border-top:1px solid #eee;
        }
        .cmplz-changelog .cmplz-changelog-list{ margin:0; padding: 0 0 0 18px; }
        .cmplz-changelog .cmplz-changelog-list li{ padding:5px 0; line-height:1.6; }

        /* Latest badge */
        .cmplz-changelog-latest{
            background:#007bbc;
            color:#fff;
            font-size:0.90rem;
            padding:2px 6px;
            border-radius:6px;
            margin-left:8px;
            vertical-align:middle;
            line-height:1.2;
        }

        /* Requirements card */
        .cmplz-changelog-requirements{
            border:1px solid #e6e6e9;
            border-radius:10px;
            background:#f9fafb;
            padding:16px 18px;
            margin-bottom:20px;
        }
        .cmplz-changelog-requirements h3{
            margin-top:0;
            margin-bottom:10px;
            font-size:1.25rem;
            font-weight:600;
        }
        .cmplz-changelog-requirements ul{
            margin:0;
            padding-left:18px;
        }
        .cmplz-changelog-requirements li{
            margin:4px 0;
        }
    </style>
    <?php
}, 999 );
