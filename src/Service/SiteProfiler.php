<?php

declare(strict_types=1);

namespace Drupal\bolt_inspect\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Routing\RouteProviderInterface;

/**
 * Builds a complete site profile for the Bolt test runner.
 */
class SiteProfiler {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly RouteProviderInterface $routeProvider,
    private readonly MenuLinkTreeInterface $menuLinkTree,
  ) {}

  /**
   * Build the full site profile.
   *
   * @return array<string, mixed>
   */
  public function profile(): array {
    return [
      'boltInspectVersion' => $this->getModuleVersion(),
      'contentTypes' => $this->getContentTypes(),
      'paragraphBundles' => $this->getParagraphBundles(),
      'enabledModules' => $this->getEnabledModules(),
      'customModules' => $this->getCustomModules(),
      'routes' => $this->getRoutes(),
      'mediaTypes' => $this->getMediaTypes(),
      'menus' => $this->getMenus(),
      'representativeUrls' => $this->getRepresentativeUrls(),
    ];
  }

  /**
   * Get the bolt_inspect module version from info.yml.
   */
  private function getModuleVersion(): string {
    $info = $this->moduleExtensionList->getExtensionInfo('bolt_inspect');
    return $info['version'] ?? 'unknown';
  }

  /**
   * Get all content types with their field definitions.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getContentTypes(): array {
    $types = [];
    $nodeTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($nodeTypes as $nodeType) {
      $bundle = $nodeType->id();
      $fields = $this->getFieldDefinitions('node', $bundle);
      $types[] = [
        'id' => $bundle,
        'label' => $nodeType->label(),
        'fields' => $fields,
      ];
    }

    return $types;
  }

  /**
   * Get all paragraph bundles with their field definitions.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getParagraphBundles(): array {
    $bundles = [];

    if (!$this->entityTypeManager->hasDefinition('paragraphs_type')) {
      return $bundles;
    }

    $paragraphTypes = $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple();

    foreach ($paragraphTypes as $paragraphType) {
      $bundle = $paragraphType->id();
      $fields = $this->getFieldDefinitions('paragraph', $bundle);
      $bundles[] = [
        'id' => $bundle,
        'label' => $paragraphType->label(),
        'fields' => $fields,
      ];
    }

    return $bundles;
  }

  /**
   * Get field definitions for an entity type + bundle.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getFieldDefinitions(string $entityType, string $bundle): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityType, $bundle);
    $fields = [];

    foreach ($definitions as $fieldName => $definition) {
      // Skip base fields (nid, uuid, etc.) — only configurable fields.
      if (!$definition instanceof \Drupal\field\FieldConfigInterface) {
        continue;
      }

      $storage = $definition->getFieldStorageDefinition();
      $fields[] = [
        'name' => $fieldName,
        'label' => $definition->getLabel(),
        'type' => $definition->getType(),
        'required' => $definition->isRequired(),
        'cardinality' => $storage->getCardinality(),
        'settings' => $definition->getSettings(),
      ];
    }

    return $fields;
  }

  /**
   * Get accessible frontend routes.
   *
   * @return string[]
   */
  private function getRoutes(): array {
    $routes = [];

    foreach ($this->routeProvider->getAllRoutes() as $name => $route) {
      $path = $route->getPath();

      // Skip admin, system, internal, and utility routes.
      if (str_starts_with($path, '/admin')
        || str_starts_with($path, '/batch')
        || str_starts_with($path, '/devel')
        || str_starts_with($path, '/editor')
        || str_starts_with($path, '/entity_reference_autocomplete')
        || str_starts_with($path, '/machine_name')
        || str_starts_with($path, '/antibot')
        || str_starts_with($path, '/ckeditor')
        || str_starts_with($path, '/contextual')
        || str_starts_with($path, '/history')
        || str_starts_with($path, '/media')
        || str_starts_with($path, '/node/add')
        || str_starts_with($path, '/user')
        || $path === '/<current>'
        || $path === '/<front>'
        || $path === '/<nolink>'
        || $path === '/<none>'
        || str_contains($name, 'system.')
        || str_contains($name, 'entity.node.edit_form')
        || str_contains($name, 'entity.node.delete_form')
        || $route->getOption('_admin_route')
        || str_contains($path, '{')
      ) {
        continue;
      }

      // Only include GET-able routes.
      $methods = $route->getMethods();
      if (!empty($methods) && !in_array('GET', $methods, TRUE)) {
        continue;
      }

      $routes[] = $path;
    }

    return array_values(array_unique($routes));
  }

  /**
   * Get list of enabled modules.
   *
   * @return string[]
   */
  private function getEnabledModules(): array {
    $installed = $this->moduleExtensionList->getAllInstalledInfo();
    return array_keys($installed);
  }

  /**
   * Get custom modules with metadata for AI context.
   *
   * Detects modules in modules/custom/ and profiles/*/modules/custom/.
   * Returns name, description, path, and what the module provides
   * (hooks, services, plugins, config entities, etc.).
   *
   * @return array<int, array<string, mixed>>
   */
  private function getCustomModules(): array {
    $customModules = [];
    $installed = $this->moduleExtensionList->getAllInstalledInfo();
    $appRoot = \Drupal::root();

    foreach ($installed as $name => $info) {
      $extension = $this->moduleExtensionList->get($name);
      $path = $extension->getPath();

      // Detect custom modules by path.
      if (!str_contains($path, 'modules/custom/')) {
        continue;
      }

      // Skip bolt_inspect itself.
      if ($name === 'bolt_inspect') {
        continue;
      }

      $moduleData = [
        'name' => $name,
        'label' => $info['name'] ?? $name,
        'description' => $info['description'] ?? '',
        'path' => $path,
        'version' => $info['version'] ?? 'custom',
        'package' => $info['package'] ?? 'Custom',
      ];

      // Check for key files that indicate what the module does.
      $fullPath = $appRoot . '/' . $path;
      $provides = [];

      // Hook implementations (.module file).
      $moduleFile = $fullPath . '/' . $name . '.module';
      if (file_exists($moduleFile)) {
        $content = file_get_contents($moduleFile);
        // Find hook implementations.
        $hooks = [];
        if (preg_match_all('/function\s+' . preg_quote($name, '/') . '_(\w+)\s*\(/', $content, $matches)) {
          $hooks = $matches[1];
        }
        if (!empty($hooks)) {
          $provides[] = 'hooks: ' . implode(', ', array_slice($hooks, 0, 15));
        }
      }

      // Services (services.yml).
      $servicesFile = $fullPath . '/' . $name . '.services.yml';
      if (file_exists($servicesFile)) {
        $provides[] = 'services';
      }

      // Event subscribers, plugins, forms, controllers (check src/ directory).
      $srcDir = $fullPath . '/src';
      if (is_dir($srcDir)) {
        $srcTypes = [];
        if (is_dir($srcDir . '/Plugin')) $srcTypes[] = 'plugins';
        if (is_dir($srcDir . '/Form')) $srcTypes[] = 'forms';
        if (is_dir($srcDir . '/Controller')) $srcTypes[] = 'controllers';
        if (is_dir($srcDir . '/EventSubscriber')) $srcTypes[] = 'event_subscribers';
        if (is_dir($srcDir . '/Entity')) $srcTypes[] = 'entities';
        if (is_dir($srcDir . '/Commands') || is_dir($srcDir . '/Drush')) $srcTypes[] = 'drush_commands';
        if (!empty($srcTypes)) {
          $provides[] = 'src: ' . implode(', ', $srcTypes);
        }
      }

      // Config install/optional.
      if (is_dir($fullPath . '/config/install') || is_dir($fullPath . '/config/optional')) {
        $provides[] = 'config';
      }

      // Templates.
      if (is_dir($fullPath . '/templates')) {
        $provides[] = 'templates';
      }

      $moduleData['provides'] = $provides;
      $customModules[] = $moduleData;
    }

    return $customModules;
  }

  /**
   * Get media types.
   *
   * @return string[]
   */
  private function getMediaTypes(): array {
    if (!$this->entityTypeManager->hasDefinition('media_type')) {
      return [];
    }

    $mediaTypes = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    return array_map(fn($t) => $t->id(), array_values($mediaTypes));
  }

  /**
   * Get main menu structure (first level).
   *
   * @return array<int, array<string, mixed>>
   */
  private function getMenus(): array {
    $parameters = new MenuTreeParameters();
    $parameters->setMaxDepth(1);
    $tree = $this->menuLinkTree->load('main', $parameters);
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);

    $items = [];
    foreach ($tree as $element) {
      $link = $element->link;
      $url = $link->getUrlObject();
      try {
        $path = $url->toString();
      }
      catch (\Exception) {
        continue;
      }
      $items[] = [
        'title' => $link->getTitle(),
        'url' => $path,
      ];
    }

    return [
      [
        'name' => 'main',
        'items' => $items,
      ],
    ];
  }

  /**
   * Build representative URLs for browser-smoke and visual-regression.
   *
   * Includes: homepage, main menu items, one node per content type.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getRepresentativeUrls(): array {
    $urls = [];

    // Homepage.
    $urls[] = [
      'url' => '/',
      'source' => 'homepage',
      'label' => 'Homepage',
    ];

    // Main menu items.
    $menus = $this->getMenus();
    foreach ($menus as $menu) {
      foreach ($menu['items'] as $item) {
        $urls[] = [
          'url' => $item['url'],
          'source' => 'menu',
          'label' => $item['title'],
        ];
      }
    }

    // Views with page displays.
    if (\Drupal::moduleHandler()->moduleExists('views')) {
      $viewsUrls = $this->getViewsPageUrls();
      // Only add views URLs that aren't already covered by menu items.
      $existingPaths = array_map(fn($u) => $u['url'], $urls);
      foreach ($viewsUrls as $viewUrl) {
        if (!in_array($viewUrl['url'], $existingPaths, TRUE)) {
          $urls[] = $viewUrl;
        }
      }
    }

    // One node per content type (most recent published).
    $nodeTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($nodeTypes as $nodeType) {
      $bundle = $nodeType->id();
      $query = $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', $bundle)
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 1);
      $nids = $query->execute();

      if (!empty($nids)) {
        $nid = reset($nids);
        $node = $nodeStorage->load($nid);
        if ($node && $node->hasLinkTemplate('canonical')) {
          try {
            $url = $node->toUrl()->toString();
            $urls[] = [
              'url' => $url,
              'source' => 'content_type',
              'label' => $nodeType->label() . ': ' . $node->label(),
              'contentType' => $bundle,
            ];
          }
          catch (\Exception) {
            // Node may not have a routable path.
          }
        }
      }
    }

    return $urls;
  }

  /**
   * Get URLs for all enabled views with page displays.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getViewsPageUrls(): array {
    $urls = [];
    $views = \Drupal\views\Views::getEnabledViews();

    foreach ($views as $view) {
      foreach ($view->get('display') as $displayId => $display) {
        if (($display['display_plugin'] ?? '') !== 'page') {
          continue;
        }
        $path = $display['display_options']['path'] ?? NULL;
        if (!$path || str_contains($path, '%') || str_contains($path, '{')) {
          continue;
        }
        // Skip admin paths and Drupal defaults nobody visits directly.
        if (str_starts_with($path, 'admin')
          || $path === 'node'
          || $path === 'rss.xml'
          || $path === 'search'
        ) {
          continue;
        }

        $urls[] = [
          'url' => '/' . ltrim($path, '/'),
          'source' => 'view',
          'label' => 'View: ' . ($view->label() ?? $view->id()) . ' (' . ($display['display_title'] ?? $displayId) . ')',
          'viewId' => $view->id(),
          'displayId' => $displayId,
        ];
      }
    }

    return $urls;
  }

}
