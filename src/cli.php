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

$cli->opt('from:f', 'The github repo to copy the labels from.', true)
    ->opt('to:t', 'The github repo to copy the labels to.', true)
    ->opt('token', 'The github access token. Uses the GITHUB_API_TOKEN if not specified.')
    ->opt('quiet:q', "Don't output verbose information.", false, 'boolean')

    ->command('labels')
    ->description('Copy the labels from one github repo to another.')
    ->opt('delete:d', 'Whether or not to delete extra labels.', false, 'boolean')
    ;

$args = $cli->parse($argv);

try {
    $sync = new \Vanilla\Github\GithubSync($args->getOpt('token', getenv('GITHUB_API_TOKEN')));
    $sync
        ->setFromRepo($args->getOpt('from'))
        ->setToRepo($args->getOpt('to'))
        ->setMessageLevel($args->getOpt('quiet') ? LOG_INFO : LOG_DEBUG);

    switch ($args->getCommand()) {
        case 'labels':
            $sync->syncLabels($args->getOpt('delete'));
            break;
    }
} catch (Exception $ex) {
    echo $cli->red($ex->getMessage());
    return $ex->getCode();
}