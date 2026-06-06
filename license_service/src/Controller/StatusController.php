<?php

namespace Drupal\license_service\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\license_service\LicenseClient;
use Drupal\license_service\LicenseManagerService;
use Drupal\license_service\SeatCapService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the live license status dashboard.
 *
 * Shows: licensed/unlicensed, tier, trial flag, expiry, offline grace state,
 * warnings (expiring soon, refresh failed), machine ID, server URL, seat usage,
 * and a link to the settings page for re-activation. The page is cache-tagged
 * 'license_service' so it invalidates immediately on any license change or cache clear.
 *
 * Author: Jeremiah Buttler
 */
class StatusController extends ControllerBase {

  public function __construct(
    protected readonly LicenseManagerService $licenseManager,
    protected readonly LicenseClient $licenseClient,
    protected readonly SeatCapService $seatCap,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('license_service.license_manager'),
      $container->get('license_service.license_client'),
      $container->get('license_service.seat_cap'),
    );
  }

  /**
   * Renders the license status overview page.
   *
   * Author: Jeremiah Buttler.
   *
   * @return array
   *   Render array.
   */
  public function overview(): array {
    $status   = $this->licenseManager->getStatus();
    $envelope = $this->licenseManager->getEnvelope();
    $seats    = $this->seatCap->getSeatUsageSummary();

    $build = [];

    // Warnings banner.
    if (!empty($status['warnings'])) {
      $items = array_map(static fn(string $w) => ['#markup' => htmlspecialchars($w, ENT_QUOTES | ENT_HTML5, 'UTF-8')], $status['warnings']);
      $build['warnings'] = [
        '#theme'      => 'item_list',
        '#items'      => $items,
        '#attributes' => ['class' => ['messages', 'messages--warning']],
      ];
    }

    // License state summary table.
    $rows = [];
    $rows[] = [$this->t('Status'), $status['licensed']
        ? ['#markup' => '<strong class="ok">' . $this->t('Licensed') . '</strong>']
        : ['#markup' => '<strong class="error">' . $this->t('Unlicensed') . '</strong>'],
    ];
    $rows[] = [$this->t('State'), $this->stateLabel($status['state'] ?? 'unlicensed')];
    $rows[] = [$this->t('Tier'), ucfirst($status['tier'] ?? 'free')];
    $rows[] = [$this->t('Trial'), $status['trial'] ? $this->t('Yes') : $this->t('No')];
    $rows[] = [$this->t('Expires'), $status['expires_at'] ?? $this->t('Never / Perpetual')];
    $rows[] = [$this->t('Offline'), $status['offline'] ? $this->t('Yes (grace window)') : $this->t('No')];
    $rows[] = [$this->t('Customer'), $status['customer'] ?? ''];
    $rows[] = [$this->t('Server'), $this->licenseClient->getServerUrl()];
    $rows[] = [$this->t('Machine ID'), $this->licenseClient->getMachineId()];

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [$this->t('Setting'), $this->t('Value')],
      '#rows'   => $rows,
    ];

    // Seat usage.
    $build['seats_title'] = ['#markup' => '<h3>' . $this->t('Seat usage') . '</h3>'];
    if ($seats['unlimited']) {
      $seatText = $this->t('Unlimited (no cap configured in license)');
    }
    else {
      $seatText = $this->t('@used / @cap premium seats used', [
        '@used' => $seats['used'],
        '@cap'  => $seats['cap'],
      ]);
    }
    $build['seats'] = ['#markup' => '<p>' . $seatText . '</p>'];

    // License features from the envelope.
    $build['envelope_title'] = ['#markup' => '<h3>' . $this->t('License capabilities') . '</h3>'];
    $featRows = [];
    $featRows[] = [$this->t('Allowed levels'), implode(', ', $envelope['allowed_levels'])];
    $featRows[] = [$this->t('Field gating'), $envelope['field_gating'] ? $this->t('Enabled') : $this->t('Disabled')];
    $featRows[] = [
      $this->t('Download gating'),
      $envelope['download_gating'] ? $this->t('Enabled') : $this->t('Disabled'),
    ];
    $featRows[] = [$this->t('Metered views'), $envelope['metered_views'] ? $this->t('Enabled') : $this->t('Disabled')];
    $featRows[] = [$this->t('Quotas'), $envelope['quotas'] ? $this->t('Enabled') : $this->t('Disabled')];
    $build['envelope'] = [
      '#type'   => 'table',
      '#header' => [$this->t('Feature'), $this->t('State')],
      '#rows'   => $featRows,
    ];

    // Actions.
    $build['actions'] = [
      '#type' => 'container',
    ];
    $build['actions']['settings_link'] = [
      '#type'  => 'link',
      '#title' => $this->t('Go to License Settings'),
      '#url'   => Url::fromRoute('license_service.settings'),
    ];

    $build['#cache'] = ['tags' => ['license_service']];

    return $build;
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Returns a human-readable label for a license state string.
   */
  protected function stateLabel(string $state): string {
    return match($state) {
      'active'      => (string) $this->t('Active'),
      'expired'     => (string) $this->t('Expired'),
      'revoked'     => (string) $this->t('Revoked'),
      'suspended'   => (string) $this->t('Suspended'),
      'grace'       => (string) $this->t('Offline grace window'),
      'pending'     => (string) $this->t('Awaiting payment'),
      'unlicensed'  => (string) $this->t('Unlicensed'),
      'invalid'     => (string) $this->t('Invalid token'),
      default       => $state,
    };
  }

}
