<?php

namespace Drupal\license_service\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the License Service Terms & Conditions page from TERMS.md.
 *
 * Author: Jeremiah Buttler.
 */
class TermsController extends ControllerBase {

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
    return new static($container->get('module_handler'));
  }

  /**
   * Renders the Terms & Conditions page.
   *
   * @return array
   *   Render array.
   */
  public function overview(): array {
    $modulePath = $this->moduleHandler->getModule('license_service')->getPath();
    $termsPath  = DRUPAL_ROOT . '/' . $modulePath . '/TERMS.md';

    $build = [];

    if (!file_exists($termsPath)) {
      $build['content'] = ['#markup' => '<p>' . $this->t('Terms file not found.') . '</p>'];
      return $build;
    }

    $raw  = file_get_contents($termsPath);
    $html = $this->markdownToHtml((string) $raw);

    $build['terms'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['license-service-terms']],
      'content'     => ['#markup' => $html],
    ];

    $build['#cache'] = ['max-age' => 3600];

    return $build;
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Converts a minimal subset of Markdown to safe HTML.
   *
   * Handles headings, bold, horizontal rules, paragraphs, and lists.
   * All input is HTML-escaped before inline formatting is applied so
   * no raw HTML from the TERMS.md file reaches the browser unescaped.
   */
  protected function markdownToHtml(string $markdown): string {
    $lines  = explode("\n", $markdown);
    $html   = '';
    $inList = FALSE;

    foreach ($lines as $line) {
      $raw = rtrim($line);

      // Horizontal rule.
      if (preg_match('/^---+$/', $raw)) {
        if ($inList) {
          $html .= '</ul>';
          $inList = FALSE;
        }
        $html .= '<hr>';
        continue;
      }

      // Headings.
      if (preg_match('/^(#{1,4})\s+(.+)/', $raw, $m)) {
        if ($inList) {
          $html .= '</ul>';
          $inList = FALSE;
        }
        $level = strlen($m[1]);
        $text  = $this->inlineFormat(htmlspecialchars($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html .= "<h{$level}>{$text}</h{$level}>";
        continue;
      }

      // Blockquote.
      if (str_starts_with($raw, '> ')) {
        if ($inList) {
          $html .= '</ul>';
          $inList = FALSE;
        }
        $text = $this->inlineFormat(htmlspecialchars(substr($raw, 2), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html .= "<blockquote><em>{$text}</em></blockquote>";
        continue;
      }

      // List items.
      if (preg_match('/^[-*]\s+(.+)/', $raw, $m)) {
        if (!$inList) {
          $html .= '<ul>';
          $inList = TRUE;
        }
        $text = $this->inlineFormat(htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html .= "<li>{$text}</li>";
        continue;
      }

      // Blank line ends lists and paragraphs.
      if ($raw === '') {
        if ($inList) {
          $html .= '</ul>';
          $inList = FALSE;
        }
        continue;
      }

      // Regular paragraph line.
      if ($inList) {
        $html .= '</ul>';
        $inList = FALSE;
      }
      $text = $this->inlineFormat(htmlspecialchars($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      $html .= "<p>{$text}</p>";
    }

    if ($inList) {
      $html .= '</ul>';
    }

    return $html;
  }

  /**
   * Applies **bold** and `code` inline formatting to pre-escaped HTML text.
   */
  protected function inlineFormat(string $text): string {
    // **bold**
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // `code`
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
    // [label](url) links — only allow https:// to avoid javascript: etc.
    $text = preg_replace_callback(
      '/\[([^\]]+)\]\((https:\/\/[^)]+)\)/',
      static fn(array $m) => '<a href="' . htmlspecialchars($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
      $text,
    );
    return $text;
  }

}
