# Github Sync

Synchronize information between github repos with style.

This little command line tool will copy issue labels and milestones from one github repo to another. This is useful if
you have many repos and you want to use the same labelling scheme amongst all of them.

## Instructions

This is a composer project so you'll need to do the following.

1. Run `composer install` to bring in the dependencies.
2. Call `bin/github-sync --help` for a list of command line options.

## Tips

To use this tool you'll need to generate an [personal access token](https://github.com/blog/1509-personal-api-tokens).
It will be much easier if you stick your access token into the `GITHUB_API_TOKEN` environment variable.