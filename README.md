# Rollback_Auto_Update

* Contributors:      afragen, costdev
* Author:            WP Core Contributors
* License:           MIT
* Requires at least: 5.9
* Requires PHP:      5.6
* Stable tag:        main

Rollback protection from a plugin auto-update whose activation would result in a PHP fatal or error. This is part of the Rollback Update Failure feature project.

## Description
Rollback protection from a plugin auto-update whose activation would result in a PHP fatal or error. This is part of the Rollback Update Failure feature project.

The [Rollback Update Failure](https://wordpress.org/plugins/rollback-update-failure/) plugin must be installed and active.

## Testing
To test you must have a plugin that is capable of being updated **and** whose update contains a PHP fatal error or warning upon activation.

The simplest way to test is to download, install, and activate this plugin and [Git Updater](https://git-updater.com). Then install the test plugin, Fatal Plugin, via WP-CLI with the following command. 

`wp plugin install https://github.com/afragen/fatal-plugin/archive/refs/heads/main.zip` 

If you wish to download the zip directly, you will need to unzip, rename the containing folder to remove the `-main`, re-zip, and then install via the Upload Plugin screen.

Once installed you will need to change the version number to `0` and comment out the code. After doing this you can activate the plugin, but you can leave it disabled. Set it to _Enable auto-updates_ in the Plugins page.

If the test plugin is active and if the Rollback works, your site should not show a WSOD after the auto-update as the previous, commented plugin will still be the active plugin. You will still show a plugin update.
