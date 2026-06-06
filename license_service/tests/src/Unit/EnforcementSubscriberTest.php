<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\license_service\EventSubscriber\EnforcementSubscriber;
use Drupal\license_service\LicenseManagerService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for EnforcementSubscriber.
 *
 * Covers: sub-request pass-through, disabled enforcement, licensed-site pass,
 * warn-only mode, enforce mode route exemptions, enforce mode redirect, and
 * the known redirect-loop bug on the front page.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\EventSubscriber\EnforcementSubscriber
 *
 * Author: Jeremiah Buttler
 */
class EnforcementSubscriberTest extends TestCase {

  // --------------------------------------------------------------------------
  // Setup
  // --------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Wires the two Drupal global-container services that EnforcementSubscriber
   * touches at runtime: string_translation (via StringTranslationTrait::t())
   * and url_generator (via Url::fromRoute()->toString()).
   */
  protected function setUp(): void {
    parent::setUp();

    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translate')->willReturnArgument(0);
    $translator->method('translateString')->willReturnCallback(
      fn($string) => (string) $string
    );

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturn('/placeholder');
    $urlGenerator->method('generate')->willReturn('/placeholder');

    $container = new ContainerBuilder();
    $container->set('string_translation', $translator);
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);
  }

  // --------------------------------------------------------------------------
  // Helpers
  // --------------------------------------------------------------------------

  /**
   * Builds an EnforcementSubscriber with all dependencies as mocks.
   *
   * @param bool   $enforcementEnabled Config: enforcement_enabled.
   * @param string $enforcementMode    Config: enforcement_mode ('warn_only'|'enforce').
   * @param bool   $licensed           Whether licenseManager reports a valid license.
   * @param bool   $isAdmin            Whether the current user has 'administer license gate'.
   * @param bool   $hasBypass          Whether the current user has 'bypass license gate'.
   * @param string $currentRoute       The route name returned by routeMatch.
   * @param array  $licenseWarnings    Warnings array from licenseManager::getStatus().
   */
  private function buildSubscriber(
    bool $enforcementEnabled = TRUE,
    string $enforcementMode = 'warn_only',
    bool $licensed = FALSE,
    bool $isAdmin = FALSE,
    bool $hasBypass = FALSE,
    string $currentRoute = 'some.frontend.route',
    array $licenseWarnings = [],
  ): EnforcementSubscriber {
    $settings = $this->createMock(Config::class);
    $settings->method('get')->willReturnMap([
      ['enforcement_enabled', $enforcementEnabled],
      ['enforcement_mode',    $enforcementMode],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('license_service.settings')->willReturn($settings);

    $licenseManager = $this->createMock(LicenseManagerService::class);
    $licenseManager->method('getStatus')->willReturn([
      'licensed'  => $licensed,
      'warnings'  => $licenseWarnings,
    ]);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('hasPermission')->willReturnMap([
      ['administer license gate', $isAdmin],
      ['bypass license gate',     $hasBypass],
    ]);

    $messenger = $this->createMock(MessengerInterface::class);

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn($currentRoute);

    return new EnforcementSubscriber(
      $licenseManager,
      $configFactory,
      $currentUser,
      $messenger,
      $routeMatch,
    );
  }

  /**
   * Builds a main RequestEvent for the given path.
   */
  private function buildMainRequest(string $path = '/some-page'): RequestEvent {
    $kernel  = $this->createMock(HttpKernelInterface::class);
    $request = Request::create($path);
    return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
  }

  /**
   * Builds a sub-request event (not a main request).
   */
  private function buildSubRequest(): RequestEvent {
    $kernel  = $this->createMock(HttpKernelInterface::class);
    $request = Request::create('/sub');
    return new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);
  }

  // --------------------------------------------------------------------------
  // getSubscribedEvents
  // --------------------------------------------------------------------------

  /**
   * @covers ::getSubscribedEvents
   */
  public function testSubscribesToKernelRequest(): void {
    $events = EnforcementSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
  }

  // --------------------------------------------------------------------------
  // Short-circuit guards
  // --------------------------------------------------------------------------

  /**
   * @covers ::onRequest
   */
  public function testSubRequestIsIgnored(): void {
    $svc   = $this->buildSubscriber(enforcementEnabled: TRUE, licensed: FALSE, enforcementMode: 'enforce');
    $event = $this->buildSubRequest();

    $svc->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testDisabledEnforcementPassesThrough(): void {
    $svc   = $this->buildSubscriber(enforcementEnabled: FALSE, licensed: FALSE, enforcementMode: 'enforce');
    $event = $this->buildMainRequest();

    $svc->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testLicensedSiteNeverBlocks(): void {
    $svc   = $this->buildSubscriber(enforcementEnabled: TRUE, licensed: TRUE, enforcementMode: 'enforce');
    $event = $this->buildMainRequest();

    $svc->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  // --------------------------------------------------------------------------
  // Admin / bypass paths
  // --------------------------------------------------------------------------

  /**
   * @covers ::onRequest
   */
  public function testAdminUserGetsWarningButNoRedirect(): void {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $settings = $this->createMock(Config::class);
    $settings->method('get')->willReturnMap([
      ['enforcement_enabled', TRUE],
      ['enforcement_mode',    'enforce'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);

    $licenseManager = $this->createMock(LicenseManagerService::class);
    $licenseManager->method('getStatus')->willReturn(['licensed' => FALSE, 'warnings' => []]);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('hasPermission')->willReturnMap([
      ['administer license gate', TRUE],
      ['bypass license gate',     FALSE],
    ]);

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn('node.view');

    $svc = new EnforcementSubscriber(
      $licenseManager, $configFactory, $currentUser, $messenger, $routeMatch,
    );

    $event = $this->buildMainRequest();
    $svc->onRequest($event);

    $this->assertNull($event->getResponse(), 'Admin users must not be redirected even in enforce mode.');
  }

  /**
   * @covers ::onRequest
   */
  public function testBypassPermissionPreventsRedirect(): void {
    $svc   = $this->buildSubscriber(
      enforcementEnabled: TRUE,
      enforcementMode: 'enforce',
      licensed: FALSE,
      isAdmin: FALSE,
      hasBypass: TRUE,
      currentRoute: 'node.view',
    );
    $event = $this->buildMainRequest();

    $svc->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  // --------------------------------------------------------------------------
  // Warn-only mode
  // --------------------------------------------------------------------------

  /**
   * @covers ::onRequest
   */
  public function testWarnOnlyNeverRedirects(): void {
    $svc   = $this->buildSubscriber(
      enforcementEnabled: TRUE,
      enforcementMode: 'warn_only',
      licensed: FALSE,
      currentRoute: 'node.view',
    );
    $event = $this->buildMainRequest('/some-page');

    $svc->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  // --------------------------------------------------------------------------
  // Enforce mode — exempt & admin routes never redirected
  // --------------------------------------------------------------------------

  /**
   * @covers ::onRequest
   */
  public function testEnforceModeExemptRoutesAreNotRedirected(): void {
    foreach (EnforcementSubscriber::EXEMPT_ROUTES as $route) {
      $svc   = $this->buildSubscriber(enforcementEnabled: TRUE, enforcementMode: 'enforce', licensed: FALSE, currentRoute: $route);
      $event = $this->buildMainRequest();

      $svc->onRequest($event);

      $this->assertNull($event->getResponse(), "Route '$route' should be exempt but was redirected.");
    }
  }

  /**
   * @covers ::onRequest
   */
  public function testEnforceModeAdminPrefixRoutesAreNotRedirected(): void {
    $adminRoutes = ['system.admin_content', 'user.admin_index', 'license_service.status'];
    foreach ($adminRoutes as $route) {
      $svc   = $this->buildSubscriber(enforcementEnabled: TRUE, enforcementMode: 'enforce', licensed: FALSE, currentRoute: $route);
      $event = $this->buildMainRequest('/admin/content');

      $svc->onRequest($event);

      $this->assertNull($event->getResponse(), "Admin route '$route' should not be blocked.");
    }
  }

  // --------------------------------------------------------------------------
  // Enforce mode — regular routes are redirected
  // --------------------------------------------------------------------------

  /**
   * @covers ::onRequest
   */
  public function testEnforceModeRegularRouteRedirects(): void {
    $svc   = $this->buildSubscriber(
      enforcementEnabled: TRUE,
      enforcementMode: 'enforce',
      licensed: FALSE,
      currentRoute: 'node.view',
    );
    $event = $this->buildMainRequest('/article/42');

    $svc->onRequest($event);

    $response = $event->getResponse();
    $this->assertInstanceOf(RedirectResponse::class, $response);
  }

  // --------------------------------------------------------------------------
  // Known bug: enforce-mode redirect on the front page causes an infinite loop.
  //
  // '<front>' is NOT in EXEMPT_ROUTES and isAdminRoute() returns FALSE for it,
  // so the subscriber unconditionally redirects any non-admin, non-bypass user
  // whose route is '<front>' back to '<front>' on every request.
  //
  // This test documents the bug.  It currently FAILS (assertNull fails because a
  // redirect IS set).  The fix is to add '<front>' to EXEMPT_ROUTES or add an
  // explicit guard for the front page.
  //
  // @todo Fix redirect loop: add '<front>' to EXEMPT_ROUTES in EnforcementSubscriber.
  // --------------------------------------------------------------------------

  /**
   * @covers ::onRequest
   */
  public function testEnforceModeDoesNotRedirectFrontPageToItself(): void {
    $svc   = $this->buildSubscriber(
      enforcementEnabled: TRUE,
      enforcementMode: 'enforce',
      licensed: FALSE,
      currentRoute: '<front>',
    );
    $event = $this->buildMainRequest('/');

    $svc->onRequest($event);

    // Expected: no redirect (front page should be exempt to avoid infinite loop).
    // Currently FAILS because '<front>' is not in EXEMPT_ROUTES.
    $this->assertNull(
      $event->getResponse(),
      'The front page must not redirect to itself in enforce mode (infinite loop bug).'
    );
  }

}
