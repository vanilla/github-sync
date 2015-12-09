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
     * @var int The level at which messages will be output.
     */
    protected $messageLevel = LOG_DEBUG;

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
        $this->message("GET /repos/$repo/labels", LOG_DEBUG);
        $r = $this->api()->get("/repos/$repo/labels", [], [], ['throw' => true]);

        $labels = $r->getBody();
        $labels = array_column($labels, null, 'name');
        $labels = array_change_key_case($labels);
        return $labels;
    }

    /**
     * Echo a message in a standard format.
     *
     * @param string $message The message to echo.
     * @param int $level The level of the message or an HTTP status code.
     */
    public function message($message, $level = LOG_INFO) {
        if ($level >= 200 && $level < 400) {
            $message .= " $level";
            $level = LOG_DEBUG;
        } elseif ($level >= 400) {
            $message .= " $level";
            $level = LOG_ERR;
        }

        if ($level > $this->messageLevel) {
            return;
        }

        echo '['.date('Y-m-d H:m:s').'] '.$message."\n";
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
        $this->message("Synchronizing labels from {$this->fromRepo} to {$this->toRepo}.");
        $fromLabels = $this->getLabels($this->getFromRepo());
        $toLabels = $this->getLabels($this->getToRepo());

        // Get the labels that need to be added.
        $addLabels = array_udiff($fromLabels, $toLabels, function($from, $to) {
            return strcasecmp($to['name'], $from['name']);
        });
        foreach ($addLabels as $label) {
            $r = $this->api()->post(
                "/repos/{$this->toRepo}/labels",
                ['name' => $label['name'], 'color' => $label['color']]
            );

            $this->message("Add {$label['name']}", $r->getStatusCode());
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
            $newLabel = $fromLabels[strtolower($label['name'])];

            $r = $this->api()->patch(
                "/repos/{$this->toRepo}/labels/".rawurlencode($label['name']),
                ['name' => $newLabel['name'], 'color' => $newLabel['color']]

            );

            $this->message("Update {$label['name']}", $r->getStatusCode());
        }

        // Get the labels to delete.
        $deleteLabels = array_diff_key($toLabels, $fromLabels);
        foreach ($deleteLabels as $label) {
            if ($delete) {
                $r = $this->api()->delete(
                    "/repos/{$this->toRepo}/labels/".rawurlencode($label['name'])
                );

                $this->message("Delete {$label['name']}", $r->getStatusCode());
            } else {
                $this->message("Not deleting {$label['name']}", LOG_DEBUG);
            }
        }

        $this->message("Done.");
    }

    public function syncMilestones($state = 'open') {
        $this->message("Synchronizing milestones from {$this->fromRepo} to {$this->toRepo}.");

        $fromMilestones = $this->getMilestones($this->getFromRepo(), $state);
        $toMilestones = $this->getMilestones($this->getToRepo(), 'all');

        // Add the new milestones.
        $addMilestones = array_diff_ukey($fromMilestones, $toMilestones, function ($from, $to) {
            return strcasecmp($to, $from);
        });

        foreach ($addMilestones as $milestone) {
            $r = $this->api()->post(
                "/repos/{$this->toRepo}/milestones",
                ['title' => $milestone['title'], 'description' => $milestone['description'], 'due_on' => $milestone['due_on']]
            );

            $this->message("Add {$milestone['title']}.", $r->getStatusCode());
        }

        // Update the existing milestones.
        $updateMilestones = array_intersect_key($toMilestones, $fromMilestones);
        $updateMilestones = array_filter($updateMilestones, function($to) use ($fromMilestones) {
            $from = $fromMilestones[strtolower($to['title'])];

            if ($from['title'] !== $to['title'] ||
                $from['description'] !== $to['description'] ||
                $from['due_on'] !== $to['due_on']) {

                return true;
            }
            return false;
        });

        foreach ($updateMilestones as $milestone) {
            $r = $this->api()->patch(
                "/repos/{$this->toRepo}/milestones/{$milestone['number']}",
                ['description' => $milestone['description'], 'due_on' => $milestone['description']]
            );

            $this->message("Update {$milestone['title']}.", $r->getStatusCode());
        }

        $this->message('Done.');
    }

    public function getMilestones($repo, $state = 'open') {
        $r = $this->api()->get("/repos/$repo/milestones", ['state' => $state]);
        $data = array_column($r->getBody(), null, 'title');
        return $data;
    }

    /**
     * Get the messageLevel.
     *
     * @return int Returns the messageLevel.
     */
    public function getMessageLevel() {
        return $this->messageLevel;
    }

    /**
     * Set the messageLevel.
     *
     * @param int $messageLevel
     * @return GithubSync Returns `$this` for fluent calls.
     */
    public function setMessageLevel($messageLevel) {
        $this->messageLevel = $messageLevel;
        return $this;
    }
}
