<?php

declare(strict_types=1);

namespace Drupal\bolt_inspect\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\FieldConfigInterface;

/**
 * Generates test content — one node per content type with required fields.
 */
class ContentGenerator {

  /**
   * Max depth for nested paragraph creation.
   */
  private const MAX_PARAGRAPH_DEPTH = 3;

  /**
   * Fields to skip during generation (side effects or special handling).
   */
  private const SKIP_FIELDS = [
    'moderation_state',
    'metatag',
    'layout_builder__layout',
    'scheduler_publish_on',
    'scheduler_unpublish_on',
  ];

  /**
   * Cache of supporting entities (media, taxonomy terms, etc.).
   *
   * @var array<string, mixed>
   */
  private array $supportingEntities = [];

  /**
   * Cached text format to use for formatted text fields.
   */
  private ?string $textFormat = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly TestEntityTracker $tracker,
  ) {}

  /**
   * Generate test content for all content types.
   *
   * @return array<string, array{status: string, nid?: int, error?: string}>
   */
  public function generateAll(): array {
    $results = [];
    $nodeTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($nodeTypes as $nodeType) {
      $bundle = $nodeType->id();
      try {
        $node = $this->generateNode($bundle);
        $results[$bundle] = [
          'status' => 'created',
          'nid' => (int) $node->id(),
          'label' => $node->label(),
        ];
      }
      catch (\Exception $e) {
        $results[$bundle] = [
          'status' => 'error',
          'error' => $e->getMessage(),
        ];
      }
    }

    return $results;
  }

  /**
   * Generate a single test node for the given content type.
   *
   * @return \Drupal\node\NodeInterface
   */
  private function generateNode(string $bundle): \Drupal\node\NodeInterface {
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    $values = [
      'type' => $bundle,
      'title' => 'Bolt Test: ' . $bundle,
      'status' => 0, // Unpublished.
      'uid' => 1,
    ];

    // Detect auto_entitylabel — populate token source fields even if not required.
    $autoLabelFields = $this->getAutoLabelFields($bundle);

    foreach ($fields as $fieldName => $definition) {
      if (!$definition instanceof FieldConfigInterface) {
        continue;
      }
      if (in_array($fieldName, self::SKIP_FIELDS, TRUE)) {
        continue;
      }

      // Generate values for required fields, paragraph references (always),
      // and fields used by auto_entitylabel tokens.
      $isRequired = $definition->isRequired();
      $isParagraph = $definition->getType() === 'entity_reference_revisions';
      $isAutoLabelField = in_array($fieldName, $autoLabelFields, TRUE);

      if (!$isRequired && !$isParagraph && !$isAutoLabelField) {
        continue;
      }

      $value = $this->generateFieldValue($definition, 0);
      if ($value !== NULL) {
        $values[$fieldName] = $value;
      }
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->create($values);
    $node->save();

    $this->tracker->track('node', (int) $node->id(), $node->label());

    return $node;
  }

  /**
   * Get field names used in auto_entitylabel token patterns for a bundle.
   *
   * Extracts field_* references from the [node:field_name] token pattern.
   * These fields must be populated even if not required, so the auto-generated
   * title is meaningful and the entity can save without errors.
   *
   * @return string[]
   */
  private function getAutoLabelFields(string $bundle): array {
    try {
      $config = \Drupal::config('auto_entitylabel.settings.node.' . $bundle);
      $status = $config->get('status');
      if (!$status) {
        return [];
      }
      $pattern = $config->get('pattern') ?? '';
      // Extract field names from token patterns like [node:field_first_name].
      $fields = [];
      if (preg_match_all('/\[node:(field_\w+)\]/', $pattern, $matches)) {
        $fields = $matches[1];
      }
      return $fields;
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Generate a value for a field based on its type and settings.
   */
  private function generateFieldValue(FieldConfigInterface $definition, int $depth): mixed {
    $type = $definition->getType();
    $settings = $definition->getSettings();

    return match ($type) {
      'string', 'string_long' => $this->generateString($definition),
      'text', 'text_long', 'text_with_summary' => $this->generateTextFormatted(),
      'boolean' => ['value' => 1],
      'integer' => ['value' => 1],
      'decimal', 'float' => ['value' => 1.0],
      'email' => ['value' => 'bolt-test@example.com'],
      'telephone' => ['value' => '555-555-0100'],
      'link' => ['uri' => 'https://example.com', 'title' => 'Bolt Test Link'],
      'datetime' => ['value' => date('Y-m-d\TH:i:s')],
      'daterange' => [
        'value' => date('Y-m-d\TH:i:s'),
        'end_value' => date('Y-m-d\TH:i:s', strtotime('+1 hour')),
      ],
      'smartdate' => $this->generateSmartDate(),
      'list_string', 'list_integer', 'list_float' => $this->generateListValue($settings),
      'entity_reference' => $this->generateEntityReference($definition),
      'entity_reference_revisions' => $this->generateParagraphs($definition, $depth),
      'address' => $this->generateAddress(),
      'image' => $this->generateImage(),
      'file' => NULL, // Skip file uploads in MVP.
      'comment' => ['status' => 2], // 0=hidden, 1=closed, 2=open.
      'webform' => NULL, // Webform reference — skip, requires specific webform entity.
      default => $this->handleUnknownFieldType($type, $definition),
    };
  }

  /**
   * Handle an unrecognized field type — log a warning and attempt a fallback.
   */
  private function handleUnknownFieldType(string $type, FieldConfigInterface $definition): ?array {
    $fieldName = $definition->getName();
    $entityType = $definition->getTargetEntityTypeId();
    $bundle = $definition->getTargetBundle();
    $required = $definition->isRequired();

    \Drupal::logger('bolt_inspect')->warning('Unknown field type "@type" on @entity_type.@bundle.@field (required: @required). Content generation may be incomplete.', [
      '@type' => $type,
      '@entity_type' => $entityType,
      '@bundle' => $bundle,
      '@field' => $fieldName,
      '@required' => $required ? 'yes' : 'no',
    ]);

    return $required ? $this->generateFallback($type) : NULL;
  }

  private function generateString(FieldConfigInterface $definition): array {
    $maxLength = $definition->getFieldStorageDefinition()->getSetting('max_length') ?? 255;
    $value = 'Bolt test value';
    return ['value' => substr($value, 0, $maxLength)];
  }

  private function generateTextFormatted(): array {
    return [
      'value' => '<p>Bolt test content paragraph.</p>',
      'format' => $this->getTextFormat(),
    ];
  }

  /**
   * Detect the best available text format.
   */
  private function getTextFormat(): string {
    if ($this->textFormat !== NULL) {
      return $this->textFormat;
    }

    $preferred = ['full_html', 'basic_html', 'restricted_html', 'plain_text'];
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    $available = array_keys($formats);

    foreach ($preferred as $format) {
      if (in_array($format, $available, TRUE)) {
        $this->textFormat = $format;
        return $format;
      }
    }

    // Fallback to first available.
    $this->textFormat = !empty($available) ? reset($available) : 'plain_text';
    return $this->textFormat;
  }

  private function generateSmartDate(): array {
    $now = time();
    return [
      'value' => $now,
      'end_value' => $now + 3600,
      'duration' => 60,
    ];
  }

  private function generateListValue(array $settings): ?array {
    $allowed = $settings['allowed_values'] ?? [];
    if (empty($allowed)) {
      return NULL;
    }
    $keys = array_keys($allowed);
    return ['value' => reset($keys)];
  }

  private function generateEntityReference(FieldConfigInterface $definition): ?array {
    $settings = $definition->getSettings();
    $targetType = $settings['target_type'] ?? 'node';

    return match ($targetType) {
      'taxonomy_term' => $this->getOrCreateTaxonomyTerm($definition),
      'media' => $this->getOrCreateMedia($definition),
      'node' => $this->getExistingNodeReference($definition),
      'block_content' => $this->getOrCreateBlockContent(),
      default => NULL,
    };
  }

  private function getOrCreateTaxonomyTerm(FieldConfigInterface $definition): ?array {
    $settings = $definition->getSettings();
    $handlerSettings = $settings['handler_settings'] ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? [];

    if (empty($targetBundles)) {
      return NULL;
    }

    $vocabulary = reset($targetBundles);
    $cacheKey = 'taxonomy_term:' . $vocabulary;

    if (isset($this->supportingEntities[$cacheKey])) {
      return ['target_id' => $this->supportingEntities[$cacheKey]];
    }

    // Try to find an existing term first.
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary)
      ->range(0, 1)
      ->execute();

    if (!empty($existing)) {
      $tid = reset($existing);
      $this->supportingEntities[$cacheKey] = $tid;
      return ['target_id' => $tid];
    }

    // Create a term.
    $term = $storage->create([
      'vid' => $vocabulary,
      'name' => 'Bolt Test Term',
    ]);
    $term->save();
    $this->tracker->track('taxonomy_term', (int) $term->id(), $term->label());
    $this->supportingEntities[$cacheKey] = (int) $term->id();

    return ['target_id' => (int) $term->id()];
  }

  private function getOrCreateMedia(FieldConfigInterface $definition): ?array {
    $settings = $definition->getSettings();
    $handlerSettings = $settings['handler_settings'] ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? [];

    // Prefer image media if available.
    $bundle = 'image';
    if (!empty($targetBundles) && !isset($targetBundles['image'])) {
      $bundle = reset($targetBundles);
    }

    $cacheKey = 'media:' . $bundle;
    if (isset($this->supportingEntities[$cacheKey])) {
      return ['target_id' => $this->supportingEntities[$cacheKey]];
    }

    // Try to find existing media.
    if ($this->entityTypeManager->hasDefinition('media')) {
      $storage = $this->entityTypeManager->getStorage('media');
      $existing = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', $bundle)
        ->range(0, 1)
        ->execute();

      if (!empty($existing)) {
        $mid = reset($existing);
        $this->supportingEntities[$cacheKey] = $mid;
        return ['target_id' => $mid];
      }

      // Create media entity with a generated image.
      if ($bundle === 'image') {
        return $this->createImageMedia($cacheKey);
      }

      // For remote_video, use a placeholder.
      if ($bundle === 'remote_video') {
        return $this->createRemoteVideoMedia($cacheKey);
      }
    }

    return NULL;
  }

  private function createImageMedia(string $cacheKey): ?array {
    // Create a simple 1x1 PNG.
    $image = imagecreatetruecolor(200, 200);
    if (!$image) {
      return NULL;
    }
    $color = imagecolorallocate($image, 100, 149, 237);
    imagefill($image, 0, 0, $color);

    $directory = 'public://bolt-test';
    \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
    $filepath = $directory . '/bolt-test-image.png';
    imagepng($image, \Drupal::service('file_system')->realpath($filepath));
    imagedestroy($image);

    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $filepath,
      'filename' => 'bolt-test-image.png',
      'filemime' => 'image/png',
      'status' => 1,
    ]);
    $file->save();
    $this->tracker->track('file', (int) $file->id(), 'bolt-test-image.png');

    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => 'image',
      'name' => 'Bolt Test Image',
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => 'Bolt test image',
      ],
    ]);
    $media->save();
    $this->tracker->track('media', (int) $media->id(), 'Bolt Test Image');

    $this->supportingEntities[$cacheKey] = (int) $media->id();
    return ['target_id' => (int) $media->id()];
  }

  private function createRemoteVideoMedia(string $cacheKey): ?array {
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => 'remote_video',
      'name' => 'Bolt Test Video',
      'field_media_oembed_video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ]);
    try {
      $media->save();
      $this->tracker->track('media', (int) $media->id(), 'Bolt Test Video');
      $this->supportingEntities[$cacheKey] = (int) $media->id();
      return ['target_id' => (int) $media->id()];
    }
    catch (\Exception) {
      return NULL;
    }
  }

  private function getExistingNodeReference(FieldConfigInterface $definition): ?array {
    $settings = $definition->getSettings();
    $handlerSettings = $settings['handler_settings'] ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? [];

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 1);

    if (!empty($targetBundles)) {
      $query->condition('type', array_keys($targetBundles), 'IN');
    }

    $nids = $query->execute();
    if (!empty($nids)) {
      return ['target_id' => reset($nids)];
    }

    return NULL;
  }

  private function getOrCreateBlockContent(): ?array {
    if (!$this->entityTypeManager->hasDefinition('block_content')) {
      return NULL;
    }

    $cacheKey = 'block_content:basic';
    if (isset($this->supportingEntities[$cacheKey])) {
      return ['target_id' => $this->supportingEntities[$cacheKey]];
    }

    $storage = $this->entityTypeManager->getStorage('block_content');

    // Try existing.
    $existing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (!empty($existing)) {
      $id = reset($existing);
      $this->supportingEntities[$cacheKey] = $id;
      return ['target_id' => $id];
    }

    // Check if 'basic' block type exists.
    if ($this->entityTypeManager->hasDefinition('block_content_type')) {
      $types = $this->entityTypeManager->getStorage('block_content_type')->loadMultiple();
      if (empty($types)) {
        return NULL;
      }
      $blockType = reset($types);

      $block = $storage->create([
        'type' => $blockType->id(),
        'info' => 'Bolt Test Block',
        'body' => ['value' => '<p>Bolt test block.</p>', 'format' => 'full_html'],
      ]);
      $block->save();
      $this->tracker->track('block_content', (int) $block->id(), 'Bolt Test Block');
      $this->supportingEntities[$cacheKey] = (int) $block->id();
      return ['target_id' => (int) $block->id()];
    }

    return NULL;
  }

  /**
   * Generate paragraph entities for an entity_reference_revisions field.
   */
  private function generateParagraphs(FieldConfigInterface $definition, int $depth): ?array {
    if ($depth >= self::MAX_PARAGRAPH_DEPTH) {
      return NULL;
    }

    $settings = $definition->getSettings();
    $handlerSettings = $settings['handler_settings'] ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? [];

    if (empty($targetBundles)) {
      return NULL;
    }

    $paragraphs = [];
    foreach (array_keys($targetBundles) as $paragraphBundle) {
      $paragraph = $this->createParagraph($paragraphBundle, $depth);
      if ($paragraph) {
        $paragraphs[] = $paragraph;
      }
    }

    return !empty($paragraphs) ? $paragraphs : NULL;
  }

  /**
   * Create a single paragraph entity.
   */
  private function createParagraph(string $bundle, int $depth): ?array {
    $fields = $this->entityFieldManager->getFieldDefinitions('paragraph', $bundle);
    $values = [
      'type' => $bundle,
    ];

    foreach ($fields as $fieldName => $definition) {
      if (!$definition instanceof FieldConfigInterface) {
        continue;
      }
      if (in_array($fieldName, self::SKIP_FIELDS, TRUE)) {
        continue;
      }

      // For nested paragraphs at depth > 0, only create simple children.
      if ($definition->getType() === 'entity_reference_revisions' && $depth > 0) {
        // Pick only the first (simplest) bundle for nested paragraphs.
        $childSettings = $definition->getSettings();
        $childHandler = $childSettings['handler_settings'] ?? [];
        $childBundles = $childHandler['target_bundles'] ?? [];
        if (!empty($childBundles)) {
          // Find a simple bundle (text, horizontal_rule) or use the first one.
          $simpleBundles = array_intersect(array_keys($childBundles), ['text', 'horizontal_rule']);
          $childBundle = !empty($simpleBundles) ? reset($simpleBundles) : reset(array_keys($childBundles));
          $child = $this->createParagraph($childBundle, $depth + 1);
          if ($child) {
            $values[$fieldName] = [$child];
          }
        }
        continue;
      }

      $value = $this->generateFieldValue($definition, $depth + 1);
      if ($value !== NULL) {
        $values[$fieldName] = $value;
      }
    }

    try {
      // Verify the paragraph type exists before creating.
      $typeStorage = $this->entityTypeManager->getStorage('paragraphs_type');
      if (!$typeStorage->load($bundle)) {
        return NULL;
      }

      $storage = $this->entityTypeManager->getStorage('paragraph');
      $paragraph = $storage->create($values);
      $paragraph->save();
      $this->tracker->track('paragraph', (int) $paragraph->id(), 'paragraph:' . $bundle);

      return [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }
    catch (\Exception $e) {
      // Log but don't fail — some paragraphs may have complex requirements.
      \Drupal::logger('bolt_inspect')->warning('Could not create paragraph @bundle: @error', [
        '@bundle' => $bundle,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  private function generateAddress(): array {
    return [
      'country_code' => 'US',
      'administrative_area' => 'MT',
      'locality' => 'Missoula',
      'postal_code' => '59801',
      'address_line1' => '123 Bolt Test St',
    ];
  }

  private function generateImage(): ?array {
    // Reuse the media image creation approach but for direct image fields.
    $cacheKey = 'file:image';
    if (isset($this->supportingEntities[$cacheKey])) {
      return [
        'target_id' => $this->supportingEntities[$cacheKey],
        'alt' => 'Bolt test image',
      ];
    }

    $image = imagecreatetruecolor(200, 200);
    if (!$image) {
      return NULL;
    }
    $color = imagecolorallocate($image, 100, 149, 237);
    imagefill($image, 0, 0, $color);

    $directory = 'public://bolt-test';
    \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
    $filepath = $directory . '/bolt-test-direct-image.png';
    imagepng($image, \Drupal::service('file_system')->realpath($filepath));
    imagedestroy($image);

    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $filepath,
      'filename' => 'bolt-test-direct-image.png',
      'filemime' => 'image/png',
      'status' => 1,
    ]);
    $file->save();
    $this->tracker->track('file', (int) $file->id(), 'bolt-test-direct-image.png');
    $this->supportingEntities[$cacheKey] = (int) $file->id();

    return [
      'target_id' => (int) $file->id(),
      'alt' => 'Bolt test image',
    ];
  }

  private function generateFallback(string $type): ?array {
    // For unknown required field types, try a simple string value.
    // This may fail for some types but won't crash the generator.
    return ['value' => 'Bolt test'];
  }

}
