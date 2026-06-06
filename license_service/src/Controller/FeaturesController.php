<?php

namespace Drupal\license_service\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the searchable Features page from README.md ## Features.
 *
 * Reads the "## Features" section of the module's README.md at render time
 * and presents it as a searchable list in the Drupal admin UI.
 *
 * Author: Jeremiah Buttler
 */
class FeaturesController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * Assigns the injected handler to the property declared by ControllerBase
   * instead of redeclaring it. PHP forbids overriding an inherited untyped,
   * non-readonly property with a typed/readonly one — doing so is a fatal
   * error raised when the class is loaded during route discovery, which would
   * stop the whole module from installing.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(ModuleHandlerInterface $moduleHandler) {
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('module_handler'),
    );
  }

  /**
   * Renders the searchable features list from README.md.
   *
   * Parses the ## Features section and renders each bullet as a list item
   * with a client-side search/filter input.
   *
   * @return array
   *   Render array.
   */
  public function overview(): array {
    $items = $this->extractFeaturesFromReadme();

    $build = [];

    $build['search'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Search features'),
      '#attributes'  => [
        'id'          => 'license-service-feature-filter',
        'placeholder' => $this->t('Search features…'),
        'autocomplete' => 'off',
      ],
    ];

    $build['list'] = [
      '#theme'      => 'item_list',
      '#items'      => array_map(fn(string $item) => [
        '#markup'    => $item,
        '#wrapper_attributes' => ['class' => ['license-service-feature-item']],
      ], $items),
      '#attributes' => ['id' => 'license-service-feature-list'],
      '#empty'      => $this->t('No features found.'),
    ];

    $build['#attached']['html_head'][] = [
      [
        '#tag'        => 'script',
        '#value'      => $this->getFilterScript(),
        '#attributes' => ['type' => 'text/javascript'],
      ],
      'license_service_feature_filter',
    ];

    $build['#cache'] = ['max-age' => 0];

    return $build;
  }

  /**
   * Reads README.md and extracts bullet items from the ## Features section.
   *
   * @return string[]
   *   Plain-text feature strings (HTML-escaped at render time).
   */
  protected function extractFeaturesFromReadme(): array {
    $modulePath = $this->moduleHandler->getModule('license_service')->getPath();
    $readmePath = DRUPAL_ROOT . '/' . $modulePath . '/README.md';

    if (!file_exists($readmePath)) {
      return [$this->t('README.md not found.')->render()];
    }

    $content = file_get_contents($readmePath);
    if ($content === FALSE) {
      return [];
    }

    // Extract the ## Features section (stop at the next ## heading).
    if (!preg_match('/^## Features\s*\n(.*?)(?=^## |\z)/ms', $content, $matches)) {
      return [];
    }

    $section = $matches[1];
    $items   = [];

    foreach (explode("\n", $section) as $line) {
      $line = trim($line);
      // Match markdown bullets: "- text" or "* text".
      if (preg_match('/^[-*]\s+(.+)/', $line, $m)) {
        $items[] = htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      }
    }

    return $items;
  }

  /**
   * Returns inline JS that filters the feature list on keystroke.
   */
  protected function getFilterScript(): string {
    return <<<'JS'
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('license-service-feature-filter');
    var list  = document.getElementById('license-service-feature-list');
    if (!input || !list) { return; }
    input.addEventListener('input', function () {
      var q = input.value.toLowerCase();
      list.querySelectorAll('li').forEach(function (li) {
        li.style.display = li.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
      });
    });
  });
}());
JS;
  }

}
