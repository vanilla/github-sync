<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2015 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\Github;

use Garden\Cli\LogFormatter;
use Garden\Http\HttpClient;

class GithubClient extends HttpClient {
    /**
     * @var LogFormatter
     */
    protected $log;

    /**
     * {@inheritdoc}
     */
    public function request($method, $uri, $body, $headers = [], array $options = []) {
        $this->log->begin("$method $uri");

        try {
            $response = parent::request($method, $uri, $body, $headers, $options);
        } catch (\Exception $ex) {
            $this->log->endError($ex->getCode());
            throw $ex;
        }

        $this->log->endHttpStatus($response->getStatusCode());
        return $response;
    }

    /**
     * Get the log.
     *
     * @return LogFormatter Returns the log.
     */
    public function getLog() {
//        if (!isset($this->log)) {
//            $this->log = new LogFormatter();
//        }
        return $this->log;
    }

    /**
     * Set the log.
     *
     * @param LogFormatter $log
     * @return GithubClient Returns `$this` for fluent calls.
     */
    public function setLog($log) {
        $this->log = $log;
        return $this;
    }
}
