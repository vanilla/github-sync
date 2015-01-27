<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2014 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\Github;

use Unirest\Request;
use Unirest\Response;


class GithubSync {
    /// Properties ///

    protected $accessToken;

    protected $baseUrl = 'https://api.github.com';

    protected $fromRepo;

    protected $toRepo;

    /// Methods ///


    public function __construct($accessToken = '') {
        $this->setAccessToken($accessToken);
        Request::jsonOpts(true);
        Request::defaultHeader('Content-Type', 'application/json');
    }

    /**
     * @return mixed
     */
    public function getAccessToken() {
        return $this->accessToken;
    }

    /**
     * @param mixed $accessToken
     * @return GithubSync
     */
    public function setAccessToken($accessToken) {
        $this->accessToken = $accessToken;
        Request::defaultHeader('Authorization', "token $accessToken");
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFromRepo() {
        return $this->fromRepo;
    }

    /**
     * @param mixed $fromRepo
     * @return GithubSync
     */
    public function setFromRepo($fromRepo) {
        $this->fromRepo = $fromRepo;
        return $this;
    }

    protected function getData(Response $response) {
        if (preg_match('`^2\d{2}`', $response->code)) {
            return $response->body;
        } else {
            throw new \Exception($response->body['message'], $response->code);
        }
    }

    public function getLabels($repo) {
        $labels = Request::get("{$this->baseUrl}/repos/$repo/labels");
        $labels = $this->getData($labels);
        $labels = array_column($labels, null, 'name');
        $labels = array_change_key_case($labels);
        return $labels;
    }

    /**
     * @return mixed
     */
    public function getToRepo() {
        return $this->toRepo;
    }

    /**
     * @param mixed $toRepo
     * @return GithubSync
     */
    public function setToRepo($toRepo) {
        $this->toRepo = $toRepo;
        return $this;
    }

    public function syncLabels($delete = false) {
        echo "Synchronizing labels from {$this->fromRepo} to {$this->toRepo}...\n";
        $fromLabels = $this->getLabels($this->getFromRepo());
        $toLabels = $this->getLabels($this->getToRepo());

        // Get the labels that need to be added.
        $addLabels = array_udiff($fromLabels, $toLabels, function($from, $to) {
            return strcasecmp($to['name'], $from['name']);
        });
        foreach ($addLabels as $label) {
            echo "Adding {$label['name']} ";
            $r = Request::post(
                "{$this->baseUrl}/repos/{$this->toRepo}/labels",
                [],
                json_encode(['name' => $label['name'], 'color' => $label['color']])
            );
            echo $r->code."\n";
        }

        // Get the labels that need to be updated.
        $updateLabels = array_intersect_key($toLabels, $fromLabels);
        $updateLabels = array_filter($updateLabels, function($to) use ($fromLabels) {
            $from = $fromLabels[strtolower($to['name'])];

            if ($from['name'] !== $to['name'] || $from['color'] !== $to['color']) {
                return true;
            }
            return false;
        });

        foreach ($updateLabels as $label) {
            echo "Updating {$label['name']} ";
            $newLabel = $fromLabels[strtolower($label['name'])];

            $r = Request::patch(
                "{$this->baseUrl}/repos/{$this->toRepo}/labels/".rawurlencode($label['name']),
                [],
                json_encode(['name' => $newLabel['name'], 'color' => $newLabel['color']])
            );
            echo $r->code."\n";
        }

        // Get the labels to delete.
        $deleteLabels = array_diff_key($toLabels, $fromLabels);
        foreach ($deleteLabels as $label) {
            if ($delete) {
                echo "Deleting {$label['name']} ";


                $r = Request::delete(
                    "{$this->baseUrl}/repos/{$this->toRepo}/labels/".rawurlencode($label['name'])
                );

                echo $r->code."\n";
            } else {
                echo "Not deleting {$label['name']}\n";
            }
        }

        echo "Done.\n";
    }
}
