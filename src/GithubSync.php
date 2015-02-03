<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2014 Vanilla Forums Inc.
 * @license MIT
 */

namespace Vanilla\Github;

use Garden\Http\HttpClient;

class GithubSync {
    /// Properties ///

    /*
     * @var HttpClient The api connection to github.
     */
    protected $api;

    /**
     * @var string
     */
    protected $accessToken;

    /**
     * @var string The base github url.
     */
    protected $baseUrl = 'https://api.github.com';

    /**
     * @var string The name of the repo to copy from.
     */
    protected $fromRepo;

    /**
     * @var string The name of the repo to copy to.
     */
    protected $toRepo;

    /// Methods ///


    public function __construct($accessToken = '') {
        $this->setAccessToken($accessToken);
    }

    /**
     * Gets the http
     * @return HttpClient
     */
    public function api() {
        if (!isset($this->api)) {
            $api = new HttpClient();
            $api->setBaseUrl('https://api.github.com')
                ->setDefaultHeader('Content-Type', 'application/json');
            $this->api = $api;
        }
        return $this->api;
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
        $this->api()->setDefaultHeader('Authorization', "token $accessToken");
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

    /**
     * Get all the labels from a given repo.
     *
     * @param string $repo The name of the github repo in the form `user/repo`.
     * @return array Returns an array of the labels in the repo.
     */
    public function getLabels($repo) {
        echo "GET /repos/$repo/labels\n";
        $r = $this->api()->get("/repos/$repo/labels", [], [], ['throw' => true]);

        $labels = $r->getBody();
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

    /**
     * Copy the labels from one repo to another.
     *
     * @param bool $delete Whether or not to delete labels in the destination that aren't in the source.
     */
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

            $r = $this->api()->post(
                "/repos/{$this->toRepo}/labels",
                ['name' => $label['name'], 'color' => $label['color']]
            );

            echo $r->getStatusCode()."\n";
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

            $r = $this->api()->patch(
                "/repos/{$this->toRepo}/labels/".rawurlencode($label['name']),
                ['name' => $newLabel['name'], 'color' => $newLabel['color']]

            );
            echo $r->getStatusCode()."\n";
        }

        // Get the labels to delete.
        $deleteLabels = array_diff_key($toLabels, $fromLabels);
        foreach ($deleteLabels as $label) {
            if ($delete) {
                echo "Deleting {$label['name']} ";

                $r = $this->api()->delete(
                    "/repos/{$this->toRepo}/labels/".rawurlencode($label['name'])
                );
                echo $r->getStatusCode()."\n";
            } else {
                echo "Not deleting {$label['name']}\n";
            }
        }

        echo "Done.\n";
    }
}
