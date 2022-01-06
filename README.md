# neucore-plugin-mumble

## Requirements

- A [Neucore](https://github.com/bravecollective/neucore) installation.

See https://github.com/bravecollective/mumble-sso for the authentication script that is used by Mumble.

## Install

- Create the database tables by importing create.sql.

The plugin needs the following environment variables:
- NEUCORE_PLUGIN_MUMBLE_DB_DSN=mysql:dbname=brave-mumble-sso;host=127.0.0.1
- NEUCORE_PLUGIN_MUMBLE_DB_USERNAME=username
- NEUCORE_PLUGIN_MUMBLE_DB_PASSWORD=password
- NEUCORE_PLUGIN_MUMBLE_BANNED_GROUP=18 # Optional Neucore group ID, members of this group will not be able to connect.

Create a new service on Neucore for this plugin, add the "groups to tags" configuration to the "Configuration Data"
text area, example:
```
alliance.diplo: Diplo
alliance.mil.fc.full: Full FC
```

Install for development:
```shell
composer install
```
