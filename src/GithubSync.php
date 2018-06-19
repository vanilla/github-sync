<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2014 Vanilla Forums Inc.
 * @license MIT
 */

namespace Vanilla\Github;

use Garden\Cli\LogFormatter;
use Garden\Http\HttpClient;

class GithubSync {
    const DELETE_FORCE = 'delete';
    const DELETE_PRUNE = 'prune';

    /// Properties ///

    /*
     * @var GithubClient The api connection to github.
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

    /**
     * @var LogFormatter
     */
    protected $log;

    /// Methods ///


    public function __construct($accessToken = '') {
        $this->log = new LogFormatter();
        $this->setAccessToken($accessToken);
    }

    /**
     * Gets the http
     * @return HttpClient
     */
    public function api() {
        if (!isset($this->api)) {
            $api = new GithubClient();
            $api->setBaseUrl('https://api.github.com')
                ->setDefaultHeader('Content-Type', 'application/json')
                ->setDefaultHeader('Accept', 'application/vnd.github.symmetra-preview+json')
                ->setLog($this->log);

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
        $r = $this->api()->get("/repos/$repo/labels", ['per_page' => 100], [], ['throw' => true]);

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
     * @param string $delete Whether or not to delete labels in the destination that aren't in the source. This is one
     * of the **DELETE_*** constants.
     */
    public function syncLabels($delete = '') {
        $this->log->begin("Synchronizing labels from {$this->fromRepo} to {$this->toRepo}");
        $fromLabels = $this->getLabels($this->getFromRepo());
        $toLabels = $this->getLabels($this->getToRepo());

        // Get the labels that need to be added.
        $addLabels = array_udiff($fromLabels, $toLabels, function($from, $to) {
            return strcasecmp($to['name'], $from['name']);
        });
        foreach ($addLabels as $label) {
            $this->log->begin("Add {$label['name']}");

            $r = $this->api()->post(
                "/repos/{$this->toRepo}/labels",
                [
                    'name' => $label['name'],
                    'color' => $label['color'],
                    'description' => $label['description'],
                ]
            );

            $this->log->endHttpStatus($r->getStatusCode(), true);
        }

        // Get the labels that need to be updated.
        $updateLabels = array_intersect_key($toLabels, $fromLabels);
        $updateLabels = array_filter($updateLabels, function($to) use ($fromLabels) {
            $from = $fromLabels[strtolower($to['name'])];

            if ($from['name'] !== $to['name'] || $from['color'] !== $to['color'] || $from['description'] !== $to['description']) {
                return true;
            }
            return false;
        });

        foreach ($updateLabels as $label) {
            $newLabel = $fromLabels[strtolower($label['name'])];

            $this->log->begin("Update {$label['name']}");

            $r = $this->api()->patch(
                "/repos/{$this->toRepo}/labels/".rawurlencode($label['name']),
                [
                    'name' => $newLabel['name'],
                    'color' => $newLabel['color'],
                    'description' => $newLabel['description'],
                ]
            );

            $this->log->endHttpStatus($r->getStatusCode(), true);
        }

        // Get the labels to delete.
        $deleteLabels = array_diff_key($toLabels, $fromLabels);
        foreach ($deleteLabels as $label) {
            if ($delete === self::DELETE_PRUNE) {
                // Only delete the label if it has no open issues.
                $issues = $this->api()->get(
                    "/repos/{$this->toRepo}/issues",
                    ['labels' => $label['name'], 'per_page' => 1]
                );

                if (count($issues->getBody()) > 0) {
                    $this->log->message("The \"{$label['name']}\" label is in use and won't be deleted.");
                    continue;
                }
            }

            if ($delete !== '') {
                $this->log->begin("Delete {$label['name']}");
                $r = $this->api()->delete(
                    "/repos/{$this->toRepo}/labels/".rawurlencode($label['name'])
                );
                $this->log->endHttpStatus($r->getStatusCode(), true);
            } else {
                $this->log->message("Not deleting {$label['name']}");
            }
        }

        $this->log->end("Done");
    }

    public function syncMilestones($state = 'open', $autoClose = false) {
        $this->log->begin("Synchronizing milestones from {$this->fromRepo} to {$this->toRepo}");

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
            $this->log->begin("Add {$milestone['title']}");

            $r = $this->api()->post(
                "/repos/{$this->toRepo}/milestones",
                ['title' => $milestone['title'], 'description' => $milestone['description'], 'due_on' => $milestone['due_on']]
            );

            $this->log->endHttpStatus($r->getStatusCode(), true);
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

            $this->log->begin("Update {$milestone['title']}");

            $r = $this->api()->patch(
                "/repos/{$this->toRepo}/milestones/{$milestone['number']}",
                [
                    'title' => $from['title'],
                    'description' => $from['description'],
                    'due_on' => $from['due_on']
                ]
            );

            $this->log->endHttpStatus($r->getStatusCode(), true);
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
                    $this->log->begin("Close {$milestone['title']}");

                    // The milestone is overdue and complete and can be closed.
                    $r = $this->api()->patch(
                        "/repos/{$this->toRepo}/milestones/{$milestone['number']}",
                        ['state' => 'closed']
                    );

                    $this->log->endHttpStatus($r->getStatusCode(), true);
                }

            }
        }

        $this->log->end('Done');
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
        return $this->log->getMaxLevel();
    }

    /**
     * Set the messageLevel.
     *
     * @param int $messageLevel
     * @return GithubSync Returns `$this` for fluent calls.
     */
    public function setMessageLevel($messageLevel) {
        $this->log->setMaxLevel($messageLevel);
        return $this;
    }

    /**
     * Label open issues from past due milestones as overdue.
     *
     * @param string $label The name of the label to apply to the issues when overdue.
     */
    public function labelOverdue($label = 'Overdue') {
        $this->log->begin("Marking issues overdue on {$this->fromRepo}");

        // Check to see if the label exists.
        $labelResponse = $this->api()->get("/repos/{$this->fromRepo}/labels/".rawurlencode($label));
        if (!$labelResponse->isSuccessful()) {
            $this->log->endError("Could not find label: $label");
            return;
        }

        $milestones = $this->getMilestones($this->getFromRepo());
        $now = new \DateTime();

        foreach ($milestones as $milestone) {
            $due = new \DateTime($milestone['due_on']);
            if ($due > $now || $milestone['open_issues'] <= 0) {
                continue;
            }

            // The milestone has open issues so get them.
            $issues = $this->api()->get("/repos/{$this->fromRepo}/issues", ['milestone' => $milestone['number']]);
            if (!$issues->isSuccessful()) {
                $this->log->error($issues['message']);
                continue;
            }
            foreach ($issues->getBody() as $issue) {
                if (in_array($label, array_column($issue['labels'], 'name'))) {
                    continue;
                }

                $r = $this->api()->post(
                    "/repos/{$this->fromRepo}/issues/{$issue['number']}/labels",
                    [$label]
                );
                if ($r->isSuccessful()) {
                    $this->log->message("#{$issue['number']} {$issue['title']}: $label", true);
                }
            }
        }

        $this->log->end('Done');
    }
}
