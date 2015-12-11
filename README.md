# Github Sync

Synchronize information between github repos with style.

This little command line tool will copy issue labels and milestones from one github repo to another. This is useful if
you have many repos and you want to use the same labelling scheme amongst all of them.

## Installation

This is a great tool to install with composer globally.

1. Run `composer global require 'vanilla/github-sync'` to install the application.
2. If your global composer bin directory is in your path you can run the app with `github-sync`. There is help on the
command line so check it out.

## Tips

To use this tool you'll need to generate a [personal access token](https://github.com/blog/1509-personal-api-tokens).
It will be much easier if you stick your access token into the `GITHUB_API_TOKEN` environment variable.