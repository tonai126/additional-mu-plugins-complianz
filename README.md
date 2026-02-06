# Additional MU-Plugins for Complianz

A collection of custom Must-Use Plugins (MU-Plugins) designed to extend and customize the functionality of the Complianz GDPR/Cookie Consent plugin for WordPress.

## Plugins Included

### 1. bricks-builder-google-maps.php
Integration for managing Google Maps in Bricks Builder while respecting cookie consent requirements.

### 2. cmplz_reorder_plugin.php
Reorders the position/priority of Complianz plugins in the WordPress loading sequence.

### 3. complianz_changelog_shortcode.php
Provides a shortcode to display the Complianz changelog on your WordPress site.

### 4. delay_cookiebanner_on_first_scroll.php
Delays the display of the cookie banner until the user's first scroll action, improving initial page load experience.

### 5. delay_cookiebanner_on_first_scroll_mobile.php
Mobile-specific version of the banner delay functionality, optimized for touch devices.

## Installation

1. Download the desired files from this repository
2. Upload them via FTP to your WordPress site's `/wp-content/mu-plugins/` folder
3. If the `mu-plugins` folder doesn't exist, create it manually
4. The plugins will activate automatically (they won't appear in the WordPress plugins dashboard)
5. For some plugins, you may need to regenerate the banner via Complianz → Wizard → Finish

## Important Notes

- MU-Plugins cannot be deactivated from the WordPress dashboard
- Some plugins may require customization before use
- After installation, clear all caches and test in incognito/private mode
- If errors occur, simply remove the file from the mu-plugins folder
- Always backup your site before adding new MU-Plugins

## Usage Requirements

- WordPress installation with wp-content directory access
- Complianz GDPR plugin installed and activated
- FTP access or file manager access to your hosting

## Support

For issues related to Complianz core functionality, visit [Complianz Support](https://complianz.io/support/)

For questions about these specific MU-Plugins, please open an issue in this repository.

## License

These plugins are provided as-is for use with Complianz. Please check individual file headers for specific license information.
