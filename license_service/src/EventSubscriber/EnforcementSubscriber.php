<?php

namespace Drupal\license_service\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\license_service\LicenseManagerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Optional site-wide enforcement when the site license is missing or expired.
 *
 * When enforcement_enabled is TRUE in config:
 * - warn_only mode: Drupal messenger warning on all admin pages.
 * - enforce mode: all users are downgraded to Free level; premium routes may
 *   be blocked. Admin routes and the license settings pages are NEVER blocked
 *   to prevent administrators from being locked out.
 *
 * Author: Jeremiah Buttler
 */
class EnforcementSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Admin routes exempt from enforcement (never blocked).
   */
  const EXEMPT_ROUTES = [
    'license_service.settings',
    'license_service.status',
    'license_service.role_levels',
    'license_service.content_rules',
    'license_service.features',
    'system.admin',
    'system.admin_config',
    'system.admin_config_security',
    'user.login',
    'user.logout',
    'user.pass',
  ];

  public function __construct(
    protected readonly LicenseManagerService $licenseManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly MessengerInterface $messenger,
    protected readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 20],
    ];
  }

  /**
   * Checks the license on each request and enforces when configured.
   *
   * Author: Jeremiah Buttler.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $config = $this->configFactory->get('license_service.settings');
    if (!$config->get('enforcement_enabled')) {
      return;
    }

    $status = $this->licenseManager->getStatus();
    if ($status['licensed']) {
      // Licensed — show expiry/warning messages to admins only.
      if (!empty($status['warnings']) && $this->currentUser->hasPermission('administer license gate')) {
        foreach ($status['warnings'] as $warning) {
          $this->messenger->addWarning($warning);
        }
      }
      return;
    }

    $mode = (string) ($config->get('enforcement_mode') ?? 'warn_only');

    // Admins always see a warning regardless of mode.
    if ($this->currentUser->hasPermission('administer license gate')) {
      $settingsUrl = Url::fromRoute('license_service.settings')->toString();
      $this->messenger->addWarning($this->t(
        'The site license is inactive or expired. <a href="@url">Check the license settings</a>.',
        ['@url' => $settingsUrl],
      ));
      return;
    }

    // Users with bypass permission are never blocked.
    if ($this->currentUser->hasPermission('bypass license gate')) {
      return;
    }

    if ($mode === 'warn_only') {
      // Warn-only: show message but do not block.
      if ($this->isAdminPath($event)) {
        $this->messenger->addWarning($this->t(
          'The site license is inactive. Some content or features may be restricted.'
        ));
      }
      return;
    }

    // Enforce mode: block access to non-exempt routes.
    $currentRoute = $this->routeMatch->getRouteName() ?? '';
    if ($this->isExemptRoute($currentRoute) || $this->isAdminRoute($currentRoute)) {
      return;
    }

    // Redirect non-admin users to the front page with a message.
    $this->messenger->addError($this->t(
      'This site requires an active license to access content.'
    ));
    $event->setResponse(new RedirectResponse(Url::fromRoute('<front>')->toString()));
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Returns TRUE if the route is in the exempt list.
   */
  protected function isExemptRoute(string $routeName): bool {
    return in_array($routeName, self::EXEMPT_ROUTES, TRUE);
  }

  /**
   * Returns TRUE if the route name is an admin-prefixed route.
   *
   * Matches routes starting with 'system.admin' or 'user.admin' or any other
   * admin-prefixed route — prevents admin lock-out.
   */
  protected function isAdminRoute(string $routeName): bool {
    foreach (['system.admin', 'user.admin', 'license_service.'] as $prefix) {
      if (str_starts_with($routeName, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE if the current request path starts with /admin.
   */
  protected function isAdminPath(RequestEvent $event): bool {
    $path = $event->getRequest()->getPathInfo();
    return str_starts_with($path, '/admin');
  }

}
