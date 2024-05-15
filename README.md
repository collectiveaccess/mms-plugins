# mms-plugins

Update of old CollectiveAccess version 1.6 LHM-MMS plugins for compatibility with versions 2.0 & 1.8.

## Installing files

Place the LHM library directory at `lib/lhm` in the root of th CollectiveAccess installation. Place the contents of the `plugins/` directory in the `app/plugins` directory of your CollectiveAccess installation.

## `setup.php` constants

The following constants must be declared in your `setup.php` file:

`define("__MMS_INSTANCE_MEDIA_ROOT_DIR__", "/PATH/TO/COLLECTIVEACCESS/INSTALL/media/".__CA_APP_NAME__);`
`define("__MMS_INSTANCE_MEDIA_URL_ROOT__", "/media/".__CA_APP_NAME__);`

`define("__MMS_INSTANCE_ARCHIVE_ROOT_DIR__", "/PATH/TO/COLLECTIVEACCESS/INSTALL/archive/".__CA_APP_NAME__);`
`define("__MMS_INSTANCE_ARCHIVE_URL_ROOT__", "/archive/".__CA_APP_NAME__);`

where `/PATH/TO/COLLECTIVEACCESS/INSTALL` is replaced with the path the the root directory of the CollectiveAccess installation.

