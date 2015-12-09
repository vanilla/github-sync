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

    public function syncMilestones($state = 'open', $autoClose = false) {
        $this->message("Synchronizing milestones from {$this->fromRepo} to {$this->toRepo}.");

        $fromMilestones = $this->getMilestones($this->getFromRepo(), $state);
        $toMilestones = $this->getMilestones($this->getToRepo(), 'all');

        // Add the new milestones.
        $addMilestones = array_udiff($fromMilestones, $toMilestones, function ($from, $to) use ($toMilestones) {
            $titleDiff = strcasecmp($to['title'], $from['title']);
            $title1 = $from['title'];
            $title2 = $to['title'];
            if ($titleDiff === 0 || empty($to['due_on']) || empty($from['due_on'])) {
                return $titleDiff;
            }

            // Milestones due on the same day are considered the same if there isn't another milestone of the same name.
            if ($to['due_on'] === $from['due_on'] &&
                !array_key_exists(strtolower($from['title']), $toMilestones)) {

                return 0;
            }

            return $titleDiff;
        });


        foreach ($addMilestones as $milestone) {
            $r = $this->api()->post(
                "/repos/{$this->toRepo}/milestones",
                ['title' => $milestone['title'], 'description' => $milestone['description'], 'due_on' => $milestone['due_on']]
            );

            $this->message("Add {$milestone['title']}.", $r->getStatusCode());
        }

        // Update the existing milestones.
        $updateMilestones = array_uintersect($toMilestones, $fromMilestones, function($to, $from) use ($toMilestones) {
            $toTitle = strtolower($to['title']);
            $fromTitle = strtolower($from['title']);
            $titleDiff = strcmp($toTitle, $fromTitle);

            if ($toTitle === $fromTitle || empty($to['due_on']) || empty($from['due_on'])) {
                return $titleDiff;
            } elseif ($to['due_on'] === $from['due_on'] &&
                !array_key_exists($fromTitle, $toMilestones)) {
                // The milestone has the same date and there isn't another one with the same name.
                return 0;
            } else {
                return $titleDiff;
            }
        });
        $updateMilestones = array_filter($updateMilestones, function($to) use ($fromMilestones) {
            if (array_key_exists(strtolower($to['title']), $fromMilestones)) {
                $from = $fromMilestones[strtolower($to['title'])];
            } else {
                $from = $this->findMilestone($to, $fromMilestones);
            }

            if (!$from) {
                // Something is wrong with our code.
                throw new \Exception("Oops. Something went wrong.", 500);
            }

            if ($from['title'] !== $to['title'] ||
                $from['description'] !== $to['description'] ||
                $from['due_on'] !== $to['due_on']) {

                return true;
            }
            return false;
        });

        foreach ($updateMilestones as $milestone) {
            $from = $this->findMilestone($milestone, $fromMilestones);

            if (empty($from)) {
                throw new \Exception("Oops. Something went wrong.", 500);
            }

            $r = $this->api()->patch(
                "/repos/{$this->toRepo}/milestones/{$milestone['number']}",
                [
                    'title' => $from['title'],
                    'description' => $from['description'],
                    'due_on' => $from['due_on']
                ]
            );

            $this->message("Update {$milestone['title']}.", $r->getStatusCode());
        }

        // Check for auto-closing milestones.
        if ($autoClose) {
            foreach ($toMilestones as $milestone) {
                if ($milestone['state'] !== 'open' ||
                    $milestone['open_issues'] > 0 ||
                    empty($milestone['due_on'])
                ) {

                    continue;
                }

                $dueOn = new \DateTime($milestone['due_on']);
                $diff = $dueOn->diff(new \DateTime);

                if ($diff->days > 0 && $diff->invert === 0) {
                    // The milestone is overdue and complete and can be closed.
                    $r = $this->api()->patch(
                        "/repos/{$this->toRepo}/milestones/{$milestone['number']}",
                        ['state' => 'closed']
                    );

                    $this->message("Close {$milestone['title']}.", $r->getStatusCode());
                }

            }
        }

        $this->message('Done.');
    }

    /**
     * Find a milestone by title or due date.
     *
     * @param array $milestone The milestone to search for.
     * @param array $arr The array of milestones to search.
     * @return array|null Returns a milestone or **null** if one isn't found.
     */
    private function findMilestone($milestone, $arr) {
        if (array_key_exists(strtolower($milestone['title']), $arr)) {
            return $arr[strtolower($milestone['title'])];
        }

        // Try and find a matching milestone.
        foreach ($arr as $row) {
            if ($row['due_on'] === $milestone['due_on']) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Get a list of milestones from the github API.
     *
     * @param string $repo The name of the repo to query.
     * @param string $state The state of the milestones. One of open, closed, or all.
     * @return array[array] Returns an array of milestones.
     */
    public function getMilestones($repo, $state = 'open') {
        $r = $this->api()->get("/repos/$repo/milestones", ['state' => $state]);
        $data = array_change_key_case(array_column($r->getBody(), null, 'title'));
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
