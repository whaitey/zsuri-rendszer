# Zsuri Rendszer WordPress Plugin

A WordPress plugin for managing jury voting and evaluation systems.

## Features

- **Jury Rating System**: Allows jury members to rate posts based on custom criteria
- **People Jury Sorting**: Enables jury members to sort and rank people/contestants
- **Custom Links Management**: Set custom URLs for posts in specific categories
- **Category Management**: Configure which categories are available for jury evaluation
- **User Management**: Assign jury members to specific categories and types
- **Export Functionality**: Export ratings and results to CSV format

## Installation

1. Upload the `jury-system.php` file to your `/wp-content/plugins/zsuri-rendszer/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin through the 'Zsűri rendszer' admin menu

## Auto-Updates

This plugin includes automatic update functionality using the Plugin Update Checker library. Updates will be available through the WordPress admin interface when new versions are released on GitHub.

### Update Process

1. New versions are automatically detected
2. Updates appear in WordPress Admin → Plugins → Available Updates
3. One-click update process through WordPress admin

## Configuration

### Setting Up Categories

1. Go to **Zsűri rendszer → Kategóriák**
2. Select which categories should be available for project and people jury evaluation
3. Configure link disable settings if needed

### Managing Custom Links

1. Go to **Zsűri rendszer → Linkek**
2. Select a category
3. Set custom URLs for posts in that category
4. These custom links will be used instead of the original WordPress links

### Assigning Jury Members

1. Go to **Zsűri rendszer → Zsűri tagok**
2. Select users with the 'zsuri' role
3. Assign them to specific categories and jury types (project/people)

## Shortcodes

- `[jury_to_rate]` - Shows posts that need to be rated
- `[jury_rated]` - Shows already rated posts
- `[people_jury_sort]` - Shows people jury sorting interface
- `[jury_info_project]` - Shows project jury information
- `[jury_info_people]` - Shows people jury information

## Version History

- **1.3.0**: Added Plugin Update Checker for automatic GitHub updates
- **1.2.1**: Fixed plugin update recognition
- **1.2.0**: Added custom links management feature
- **1.1.0**: Added link disable functionality for specific categories
- **1.0.0**: Initial release

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## Support

For support and updates, visit the GitHub repository: https://github.com/whaitey/zsuri-rendszer 