<?php

/*
 * `arc build-status` command to show revisions and build statuses.
 *
 * Prints:
 * current branch (*), branch name, review status, build status, diffID: title
 */
class ArcanistBuildStatusWorkflow extends ArcanistWorkflow {
  public function getWorkflowName() {
    return 'build-status';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **build-status**
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, hg
          Lists open Differential Revisions and their buildable status.
EOTEXT
      );
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  private function loadCommitInfo() {
    $repository_api = $this->getRepositoryAPI();
    $branches = $repository_api->getAllBranches();
    if (!$branches) {
      throw new ArcanistUsageException(
        pht('No branches in this working copy.'));
    }

    $revisionToBranch = array();
    foreach ($branches as $branch) {
      $text = $branch['text'];

      try {
        $message = ArcanistDifferentialCommitMessage::newFromRawCorpus($text);
        $id = $message->getRevisionID();

        $branch['revisionID'] = $id;
        if ($id) {
            $revisionToBranch[$id] = $branch;
        }
      } catch (ArcanistUsageException $ex) {
        // In case of invalid commit message which fails the parsing,
        // do nothing.
        $branch['revisionID'] = null;
      }
    }

    if ($repository_api instanceof ArcanistMercurialAPI) {
      $futures = array();
      foreach ($branches as $branch) {
        $futures[$branch['name']] = $repository_api->execFutureLocal(
          'log -l 1 --template %s -r %s',
          "{node}\1{date|hgdate}\1{p1node}\1{desc|firstline}\1{desc}",
          hgsprintf('%s', $branch['name']));
      }

      $futures = id(new FutureIterator($futures))
        ->limit(16);
      foreach ($futures as $name => $future) {
        list($info) = $future->resolvex();

        $fields = explode("\1", trim($info), 5);
        list($hash, $epoch, $tree, $desc, $text) = $fields;

        $branches[$name] += array(
          'hash' => $hash,
          'desc' => $desc,
          'tree' => $tree,
          'epoch' => (int)$epoch,
          'text' => $text,
        );
      }
    }

    return $revisionToBranch;
  }

  private function loadActiveRevisions() {
    $revisions = $this->getConduit()->callMethodSynchronous(
      'differential.revision.search',
      array(
        'queryKey' => 'authored',
        'constraints' => array(
          'statuses' => array('needs-review', 'accepted', 'changes-planned', 'needs-revision'),
        ),
      ));
    if (!$revisions) {
      echo pht('You have no open Differential revisions.')."\n";
      return 0;
    }
    $revisions = ipull($revisions['data'], null, 'id');
    return $revisions;
  }

  private function loadBuildables(array $diffPHIDs) {
    $buildables = $this->getConduit()->callMethodSynchronous(
      'harbormaster.buildable.search',
      array(
        'constraints' => array(
          'objectPHIDs' => array_values($diffPHIDs),
        ),
      ));
    if (!$buildables) {
        echo pht('Unable to find corresponding diff buildables.')."\n";
        return 0;
    }

    $diffToBuildable = array();
    foreach ($buildables['data'] as $buildable) {
      $objectPHID = $buildable['fields']['objectPHID'];
      $diffToBuildable[$objectPHID] = $buildable;
    }
    return $diffToBuildable;
  }

  /*
   * Extracts diffPHIDs for a list of revisions into revisionID => diffPHID
   */
  private function getDiffPHIDs(array $revisions) {
    $diffPHIDs = array();
    foreach ($revisions as $revision) {
        $diffPHID = idxv($revision, array('fields', 'diffPHID'));
        $diffPHIDs[$revision['id']] = $diffPHID;
    }
    return $diffPHIDs;
  }

  private function printBuildStatuses($branches, $revisions, $buildables) {
    static $color_map = array(
      'preparing' => 'yellow',
      'building'  => 'blue',
      'passed'    => 'green',
      'failed'    => 'red',
    );

    static $ssort_map = array(
      'Closed'          => 1,
      'No Revision'     => 2,
      'Needs Review'    => 3,
      'Needs Revision'  => 4,
      'Accepted'        => 5,
    );

    $out = array();
    foreach ($revisions as $revision_id => $revision) {
      $desc = 'D'.$revision_id.': '.$revision['fields']['title'];
      $status = $revision['fields']['status']['name'];
      $status_color = $revision['fields']['status']['color.ansi'];
      $diffPHID = $revision['fields']['diffPHID'];
      $build_status = $buildables[$diffPHID]['fields']['buildableStatus']['value'];
      $build_color = idx($color_map, $build_status, array('default', 'default'));
      $branch = $branches[$revision_id];
      $epoch = $branch['epoch'];

      $out[] = array(
        'name'         => $branch['name'],
        'current'      => $branch['current'],
        'status'       => $status,
        'desc'         => $desc,
        'revision'     => $revision_id,
        'status_color' => $status_color,
        'build_status' => $build_status,
        'build_color' => $build_color,
        'esort'        => $epoch,
      );
    }

    if (!$out) {
      // All of the revisions are closed or abandoned.
      return;
    }

    $out = isort($out, 'esort');

    $table = id(new PhutilConsoleTable())
      ->setShowHeader(false)
      ->addColumn('current',      array('title' => ''))
      ->addColumn('name',         array('title' => pht('Name')))
      ->addColumn('status',       array('title' => pht('Status')))
      ->addColumn('build_status', array('title' => pht('Build Status')))
      ->addColumn('descr',        array('title' => pht('Description')));
    foreach ($out as $line) {
      $table->addRow(array(
        'current'      => $line['current'] ? '*' : '',
        'name'         => tsprintf('**%s**', $line['name']),
        'status'       => tsprintf(
          "<fg:{$line['status_color']}>%s</fg>", $line['status']),
        'build_status' => tsprintf(
          "  <bg:{$line['build_color']}>** %s **</bg>", $line['build_status']),
        'descr'        => $line['desc'],
      ));
    }

    $table->draw();
  }

  public function run() {
    $branches = $this->loadCommitInfo();
    $revisions = $this->loadActiveRevisions();
    $diffPHIDs = $this->getDiffPHIDs($revisions);
    $buildables = $this->loadBuildables($diffPHIDs);
    $this->printBuildStatuses($branches, $revisions, $buildables);
  }
}
