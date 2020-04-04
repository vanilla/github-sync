<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2015 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\Github;

use Garden\Cli\LogFormatter;
use Garden\Http\HttpClient;
use Garden\Http\HttpResponse;

class GithubClient extends HttpClient {
    public const ITERABLE = 'iterable';

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

    public function get($uri, array $query = [], array $headers = [], $options = []) {
        $options += [
            self::ITERABLE => true,
        ];

        $r = parent::get($uri, $query, $headers, $options);

        if ($options[self::ITERABLE]) {
            $r2 = clone $r;
            $r2->setBody($this->makeResult($r));
            return $r2;
        }
        return $r;
    }

    public function makeResult(HttpResponse $response): iterable {
        $clean = function (HttpResponse $response): iterable {
            $data = $response->getBody();

            if (!empty($data) && !isset($data[0]) && isset($data['items'])) {
                $data = $data['items'];
            }

            foreach ($data as &$row) {
                static::fixDates($row);
            }

            return $data;
        };

        $rows =  $clean($response);
        yield from $rows;

        while (1) {
            $pages = $this->parsePages($response->getHeader('Link'));

            if (empty($pages['next'])) {
                break;
            }

            $response = parent::get($pages['next'], [], $response->getRequest()->getHeaders());
            $rows =  $clean($response);
            yield from $rows;
        }

    }

    private function parsePages(string $link) {
        preg_match_all('`<([^>]+)>;\s*rel="([^"]+)"`', $link, $m, PREG_SET_ORDER);
        $result = [];
        foreach ($m as $r) {
            $result[$r[2]] = $r[1];
        }
        return $result;
    }

    /**
     * @param array $row
     */
    public static function fixDates(array &$row): void {
        foreach (['created_at', 'updated_at', 'closed_at', 'milestone_due_on'] as $field) {
            if (!array_key_exists($field, $row)) {
                // Do nothing.
            } elseif (empty($row[$field])) {
                $row[$field] = null;
            } else {
                $row[$field] = new \DateTimeImmutable($row[$field]);
            }
        }
        if (!empty($row['milestone'])) {
            foreach (['created_at', 'updated_at', 'due_on'] as $field) {
                if (empty($row['milestone'][$field])) {
                    $row['milestone'][$field] = null;
                } else {
                    $row['milestone'][$field] = new \DateTimeImmutable($row['milestone'][$field]);
                }
            }
        }
    }
}
