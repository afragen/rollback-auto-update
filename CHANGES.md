[unreleased]

#### 0.9.2 / 2022-10-09
* move `send_update_result_email` so fires after `restart_updates`

#### 0.9.1 / 2022-09-05
* more updates to email text

#### 0.9.0 / 2022-09-04
* add `sleep(2)` to possibly prevent a race condition before each plugin and before restarts
* update email text

#### 0.8.0 / 2022-08-24
* large refactor into smaller chunks
* load in static hook
* don't check our own plugin, cause it will always have a PHP fatal for redeclaring the class
* refactor for restarting plugin update process after a fatal
* add function for sending successful update email
* refactor for inclusion into core

#### 0.6.0 / 2022-08-05
* update documentation
* now includes all type of error, exception, shutdown handling
* update some naming
* email site admin on auto-update failure
* add some error logging as the debug.log will fill with errors
* return early if `$hook_extra` not correctly populated
* clean up
* limit scope as much as possible

#### 0.5.0 / 2022-08-02
* converted original Gist to repo
