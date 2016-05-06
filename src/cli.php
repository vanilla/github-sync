<?php
use Garden\Cli\Cli;

error_reporting(E_ALL); //E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);
ini_set('display_errors', 'on');
ini_set('track_errors', 1);

date_default_timezone_set('America/Montreal');

$paths = [
    __DIR__.'/../vendor/autoload.php', // locally
    __DIR__.'/../../../autoload.php' // dependency
];
foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

$cli = new Cli();

$cli->opt('token', 'The github access token. Uses the GITHUB_API_TOKEN if not specified.')
    ->opt('quiet:q', "Don't output verbose information.", false, 'boolean')

    ->command('labels')
    ->description('Copy the labels from one github repo to another.')
    ->opt('from:f', 'The github repo to copy the labels from.', true)
    ->opt('to:t', 'The github repo to copy the labels to.', true)
    ->opt('delete:d', 'Whether or not to delete extra labels.', false, 'boolean')

    ->command('milestones')
    ->description('Copy milestones from one github repo to another.')
    ->opt('from:f', 'The github repo to copy the labels from.', true)
    ->opt('to:t', 'The github repo to copy the labels to.', true)
    ->opt('status:s', 'The milestone status. One of open, closed, all. Defaults to open.')
    ->opt('autoclose', 'Whether or not to close milestones that are overdue and don\'t have any items.', false, 'boolean')

    ->command('overdue')
    ->description('Label issues from an past due milestones as overdue.')
    ->opt('repo:r', 'The github repo to inspect.', true)
    ;

$args = $cli->parse($argv);

try {
    $sync = new \Vanilla\Github\GithubSync($args->getOpt('token', getenv('GITHUB_API_TOKEN')));
    $sync
        ->setFromRepo($args->getOpt('from'))
        ->setToRepo($args->getOpt('to'))
        ->setMessageLevel($args->getOpt('quiet') ? 1 : 3);

    switch ($args->getCommand()) {
        case 'labels':
            $sync->syncLabels($args->getOpt('delete'));
            break;
        case 'milestones':
            $sync->syncMilestones($args->getOpt('status', 'open'), $args->getOpt('autoclose', false));
            break;
        case 'overdue':
            $sync->setFromRepo($args->getOpt('repo'));
            $sync->labelOverdue();
            break;
    }
} catch (Exception $ex) {
    echo $cli->red($ex->getMessage());
    return $ex->getCode();
}