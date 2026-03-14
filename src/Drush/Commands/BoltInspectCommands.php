<?php

declare(strict_types=1);

namespace Drupal\bolt_inspect\Drush\Commands;

use Drupal\bolt_inspect\Service\ContentGenerator;
use Drupal\bolt_inspect\Service\SiteProfiler;
use Drupal\bolt_inspect\Service\TestEntityTracker;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Bolt site inspection and test content management.
 */
class BoltInspectCommands extends DrushCommands {

  public function __construct(
    private readonly SiteProfiler $profiler,
    private readonly ContentGenerator $generator,
    private readonly TestEntityTracker $tracker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Return full site structure as JSON.
   */
  #[CLI\Command(name: 'bolt-inspect:profile')]
  #[CLI\Usage(name: 'drush bolt-inspect:profile', description: 'Output site profile as JSON')]
  public function profile(): void {
    $profile = $this->profiler->profile();
    $this->output()->writeln(json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Generate test content — one node per content type with required fields.
   */
  #[CLI\Command(name: 'bolt-inspect:generate')]
  #[CLI\Usage(name: 'drush bolt-inspect:generate', description: 'Create test content for all content types')]
  public function generate(): void {
    if ($this->tracker->hasTracked()) {
      $this->logger()->notice('Test content already exists. Run bolt-inspect:cleanup first or use existing content.');
      $this->listTracked();
      return;
    }

    $this->logger()->notice('Generating test content...');
    $results = $this->generator->generateAll();

    $rows = [];
    $errors = 0;
    foreach ($results as $bundle => $result) {
      if ($result['status'] === 'created') {
        $rows[] = [$bundle, 'CREATED', $result['nid'], $result['label']];
      }
      else {
        $rows[] = [$bundle, 'ERROR', '', $result['error'] ?? 'Unknown error'];
        $errors++;
      }
    }

    $this->io()->table(['Content Type', 'Status', 'NID', 'Detail'], $rows);

    $total = count($results);
    $created = $total - $errors;
    $this->logger()->success("Generated {$created}/{$total} content types.");

    if ($errors > 0) {
      $this->logger()->warning("{$errors} content type(s) had errors.");
    }
  }

  /**
   * Remove all generated test content.
   */
  #[CLI\Command(name: 'bolt-inspect:cleanup')]
  #[CLI\Usage(name: 'drush bolt-inspect:cleanup', description: 'Remove all bolt test content')]
  public function cleanup(): void {
    if (!$this->tracker->hasTracked()) {
      $this->logger()->notice('No test content to clean up.');
      return;
    }

    $tracked = $this->tracker->getTracked();
    $count = count($tracked);
    $this->logger()->notice("Cleaning up {$count} tracked entities...");

    $counts = $this->tracker->cleanupAll();

    $rows = [];
    foreach ($counts as $type => $deleted) {
      $rows[] = [$type, $deleted];
    }
    $this->io()->table(['Entity Type', 'Deleted'], $rows);

    $total = array_sum($counts);
    $this->logger()->success("Cleaned up {$total} entities.");
  }

  /**
   * List all currently tracked test entities.
   */
  #[CLI\Command(name: 'bolt-inspect:list')]
  #[CLI\Usage(name: 'drush bolt-inspect:list', description: 'List tracked test entities')]
  public function listTracked(): void {
    $tracked = $this->tracker->getTracked();

    if (empty($tracked)) {
      $this->logger()->notice('No tracked test entities.');
      return;
    }

    $rows = [];
    foreach ($tracked as $entry) {
      $rows[] = [
        $entry['entity_type'],
        $entry['id'],
        $entry['label'],
        date('Y-m-d H:i:s', $entry['created']),
      ];
    }

    $this->io()->table(['Entity Type', 'ID', 'Label', 'Created'], $rows);
    $this->logger()->notice(count($tracked) . ' tracked entities.');
  }

  /**
   * Render test nodes and report status per content type.
   *
   * Loads each unpublished test node, renders it in isolation, and returns
   * a JSON array with status, nid, title, html_length, or error per bundle.
   */
  #[CLI\Command(name: 'bolt-inspect:render-check')]
  #[CLI\Usage(name: 'drush bolt-inspect:render-check', description: 'Render-check all generated test nodes')]
  public function renderCheck(): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $renderer = \Drupal::service('renderer');

    $nodeTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $results = [];

    foreach ($nodeTypes as $nodeType) {
      $bundle = $nodeType->id();

      // Find the test node for this bundle.
      $nodes = $nodeStorage->loadByProperties([
        'type' => $bundle,
        'status' => 0,
        'uid' => 1,
      ]);

      // Filter to bolt-generated nodes.
      $node = NULL;
      foreach ($nodes as $candidate) {
        $title = $candidate->label() ?? '';
        if (str_starts_with($title, 'Bolt Test:') || str_starts_with($title, 'Bolt test')) {
          $node = $candidate;
          break;
        }
      }

      if (!$node) {
        $results[] = [
          'bundle' => $bundle,
          'label' => $nodeType->label(),
          'status' => 'missing',
        ];
        continue;
      }

      try {
        $build = $viewBuilder->view($node, 'full');
        $html = $renderer->renderInIsolation($build);
        $len = strlen((string) $html);

        $results[] = [
          'bundle' => $bundle,
          'label' => $nodeType->label(),
          'status' => 'ok',
          'nid' => (int) $node->id(),
          'title' => $node->label(),
          'html_length' => $len,
        ];
      }
      catch (\Throwable $e) {
        $results[] = [
          'bundle' => $bundle,
          'label' => $nodeType->label(),
          'status' => 'error',
          'nid' => (int) $node->id(),
          'error' => $e->getMessage(),
        ];
      }
    }

    $this->output()->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

}
