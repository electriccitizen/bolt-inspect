<?php

declare(strict_types=1);

namespace Drupal\bolt_inspect\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Tracks test entities created by bolt-inspect:generate for cleanup.
 */
class TestEntityTracker {

  private const STATE_KEY = 'bolt_inspect.tracked_entities';

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Track a created entity for later cleanup.
   */
  public function track(string $entityType, int $id, string $label = ''): void {
    $tracked = $this->getTracked();
    $tracked[] = [
      'entity_type' => $entityType,
      'id' => $id,
      'label' => $label,
      'created' => time(),
    ];
    $this->state->set(self::STATE_KEY, $tracked);
  }

  /**
   * Get all tracked entities.
   *
   * @return array<int, array{entity_type: string, id: int, label: string, created: int}>
   */
  public function getTracked(): array {
    return $this->state->get(self::STATE_KEY, []);
  }

  /**
   * Check if any tracked entities exist.
   */
  public function hasTracked(): bool {
    return !empty($this->getTracked());
  }

  /**
   * Delete all tracked entities in reverse order and clear tracking state.
   *
   * @return array<string, int>
   *   Count of deleted entities per type.
   */
  public function cleanupAll(): array {
    $tracked = $this->getTracked();
    $counts = [];

    // Delete in reverse order (children before parents).
    foreach (array_reverse($tracked) as $entry) {
      $entityType = $entry['entity_type'];
      $id = $entry['id'];
      try {
        $storage = $this->entityTypeManager->getStorage($entityType);
        $entity = $storage->load($id);
        if ($entity) {
          $entity->delete();
          $counts[$entityType] = ($counts[$entityType] ?? 0) + 1;
        }
      }
      catch (\Exception $e) {
        // Entity API delete may fail if hooks (e.g. auto_entitylabel) interfere.
        // Ensure the entity has a valid title and retry.
        try {
          $entity = $storage->load($id);
          if ($entity && method_exists($entity, 'setTitle')) {
            $entity->setTitle('Bolt Cleanup');
          }
          if ($entity) {
            $entity->delete();
            $counts[$entityType] = ($counts[$entityType] ?? 0) + 1;
          }
        }
        catch (\Exception) {
          // Still failed — continue with others.
        }
      }
    }

    $this->state->delete(self::STATE_KEY);
    return $counts;
  }

}
