# GitHub Sync

Copy issue labels and milestones from one GitHub repo to another. This is useful if
you have many repos and you want to use the same labelling scheme amongst all of them
or keep milestone data synced for tools like ZenHub.

## Installation

This is a great tool to install globally with Composer.

1. Run `composer global require 'vanilla/github-sync'` to install the application.
2. If your global composer bin directory is in your path you can run the app with `github-sync`. For help, add `-h`.
3. Generate a [personal access token](https://github.com/blog/1509-personal-api-tokens) and add it to your `~/.bashrc` file as `export GITHUB_API_TOKEN=xxxxx`.

## Update

1. Tag the repo with a new version number (format: `v1.1.1`).
2. Do a [new release](https://github.com/vanilla/github-sync/releases) with that tag, named the same way.
3. Update [Packagist](https://packagist.org/packages/vanilla/github-sync) or wait for it to sync.
4. Reinstall using the global `composer` command above.

## Usage

* `labels [-f] [-t] [-d]`       Copy the labels from one GitHub repo to another. Set a 'from' repo and 'to' repo. The `delete` option will remove any labels from the 'to' repo that don't exist on the 'from' repo.
* `milestones [-f] [-t] [-s] [--autoclose]`   Copy milestones from one GitHub repo to another. Set a 'from' repo, 'to' repo, and/or a 'status' to select (one of `open`, `closed`, `all`). The `autoclose` option will close milestones past their due date or with zero items.
