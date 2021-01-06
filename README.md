# neucore-plugin-mumble

The plugin needs the following environment variables:
- NEUCORE_PLUGIN_MUMBLE_DB_DSN=mysql:dbname=brave-mumble-sso;host=127.0.0.1
- NEUCORE_PLUGIN_MUMBLE_DB_USERNAME=username
- NEUCORE_PLUGIN_MUMBLE_DB_PASSWORD=password
- NEUCORE_PLUGIN_MUMBLE_CONFIG_FILE=/path/to/config.php

The file config.php needs to provide the following variable:
- $cfg_groups_to_tags

See https://github.com/bravecollective/mumble-sso/blob/master/webroot/config.php.dist

See also https://github.com/bravecollective/mumble-sso

Install for development:
```shell
composer install
```
