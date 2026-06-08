<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;

/**
 * Dispatches subscription lifecycle notification emails.
 *
 * All outbound mail passes through this service. Every method:
 *   - No-ops silently when notification_enabled = FALSE in settings.
 *   - Loads the recipient User entity to get email address + preferred langcode.
 *   - Catches all send failures and logs them; never throws on mail failure.
 *   - Skips sends for users with no email address (blocked accounts, UID 0).
 *
 * Email key constants (used in hook_mail() and MailManager::mail()):
 *   - 'plan_deprecated'  — plan being discontinued; contains choose-plan link.
 *   - 'renewal_reminder' — subscription renewing soon.
 *   - 'plan_chosen'      — user's plan choice has been recorded.
 *   - 'payment_failing'  — payment declining; action required.
 *
 * Author: Jeremiah Buttler.
 */
class SubscriptionNotificationService {

  /**
   * Drupal mail module ID for all outgoing subscription emails.
   */
  const MODULE = 'license_service_subscriptions';

  /**
   * Constructs a SubscriptionNotificationService.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager (default langcode fallback).
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (user + plan + Commerce subscription loading).
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection (subscription state queries).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (reads notification_enabled, grace_window_days, etc.).
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\license_service_subscriptions\Service\SubscriptionChoiceTokenService $tokenService
   *   The token service (generates choose-plan tokens for deprecation notices).
   */
  public function __construct(
    protected readonly MailManagerInterface $mailManager,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly SubscriptionChoiceTokenService $tokenService,
  ) {}

  // --------------------------------------------------------------------------
  // Public API
  // --------------------------------------------------------------------------

  /**
   * Sends deprecation notices to all active/paused subscribers on a plan.
   *
   * Called from hook_entity_update() when a LicenseSubscriptionPlan's
   * active flag transitions TRUE → FALSE.
   *
   * Generates a single-use choose-plan token per subscriber and embeds the
   * token URL in the email. Tokens are stored in license_service_migration_intents.
   *
   * @param string $planId
   *   Machine name of the plan being deprecated.
   */
  public function sendDeprecationNoticesForPlan(string $planId): void {
    if (!$this->isEnabled()) {
      return;
    }

    $logger = $this->loggerFactory->get(self::MODULE);
    $config = $this->configFactory->get('license_service_subscriptions.settings');
    $graceDays = (int) ($config->get('grace_window_days') ?? 7);
    $planLabel = $this->getPlanLabel($planId);

    $rows = $this->database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['id', 'uid', 'commerce_subscription_id'])
      ->condition('plan_id', $planId)
      ->condition('state', ['active', 'paused'], 'IN')
      ->execute()
      ->fetchAll();

    foreach ($rows as $row) {
      $uid = (int) $row->uid;
      $subscriptionStateId = (int) $row->id;
      $commerceSubId = (int) $row->commerce_subscription_id;

      // Resolve effective_at from Commerce subscription renewal time.
      // Falls back to grace_window_days from now if Commerce entity unavailable.
      $effectiveAt = time() + ($graceDays * 86400);
      try {
        $commerceSub = $this->entityTypeManager
          ->getStorage('commerce_subscription')
          ->load($commerceSubId);
        if ($commerceSub !== NULL && method_exists($commerceSub, 'getNextRenewalTime')) {
          $renewalTime = (int) $commerceSub->getNextRenewalTime();
          if ($renewalTime > 0) {
            $effectiveAt = $renewalTime;
          }
        }
      }
      catch (\Exception) {
        // Non-fatal: use the fallback effective_at.
      }

      // Generate a single-use token for this subscriber.
      try {
        $rawToken = $this->tokenService->generate($uid, $subscriptionStateId, $effectiveAt);
      }
      catch (\Exception $e) {
        $logger->warning(
          'sendDeprecationNoticesForPlan: token generation failed for uid @uid / sub @sub: @msg',
          ['@uid' => $uid, '@sub' => $commerceSubId, '@msg' => $e->getMessage()],
        );
        continue;
      }

      // Build the absolute choose-plan URL.
      $choosePlanUrl = $this->buildChoosePlanUrl($rawToken);

      $this->sendToUser($uid, 'plan_deprecated', [
        'plan_id'          => $planId,
        'plan_label'       => $planLabel,
        'token'            => $rawToken,
        'choose_plan_url'  => $choosePlanUrl,
        'effective_at'     => $effectiveAt,
      ]);
    }

    $logger->info(
      'Sent plan_deprecated notices for plan @plan to @count subscriber(s).',
      ['@plan' => $planId, '@count' => count($rows)],
    );
  }

  /**
   * Sends a renewal reminder for an active subscription.
   *
   * Called from hook_cron() for subscriptions renewing within renewal_reminder_days.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param string $planId
   *   LicenseSubscriptionPlan machine name.
   * @param int $renewalAt
   *   Unix timestamp of the next renewal date.
   */
  public function sendRenewalReminder(int $uid, string $planId, int $renewalAt): void {
    if (!$this->isEnabled()) {
      return;
    }

    $this->sendToUser($uid, 'renewal_reminder', [
      'plan_id'    => $planId,
      'plan_label' => $this->getPlanLabel($planId),
      'renewal_at' => $renewalAt,
    ]);
  }

  /**
   * Sends a plan-chosen confirmation after markIntentToChange() commits.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param string $fromPlanId
   *   The plan being deprecated.
   * @param string $toPlanId
   *   The plan the user chose.
   * @param int|null $paymentDeadline
   *   Unix timestamp of the checkout deadline for paid plans, or NULL for free.
   */
  public function sendPlanChosen(int $uid, string $fromPlanId, string $toPlanId, ?int $paymentDeadline): void {
    if (!$this->isEnabled()) {
      return;
    }

    $this->sendToUser($uid, 'plan_chosen', [
      'from_plan_id'    => $fromPlanId,
      'from_plan_label' => $this->getPlanLabel($fromPlanId),
      'to_plan_id'      => $toPlanId,
      'to_plan_label'   => $this->getPlanLabel($toPlanId),
      'payment_deadline' => $paymentDeadline,
    ]);
  }

  /**
   * Sends a payment-failing notice when a Commerce payment is declined.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param string $planId
   *   LicenseSubscriptionPlan machine name.
   * @param int $failedSince
   *   Unix timestamp of the first payment failure.
   */
  public function sendPaymentFailing(int $uid, string $planId, int $failedSince): void {
    if (!$this->isEnabled()) {
      return;
    }

    $config = $this->configFactory->get('license_service_subscriptions.settings');
    $graceDays = (int) ($config->get('renewal_reminder_days') ?? 7);
    $graceDeadline = $failedSince + ($graceDays * 86400);

    $this->sendToUser($uid, 'payment_failing', [
      'plan_id'       => $planId,
      'plan_label'    => $this->getPlanLabel($planId),
      'failed_since'  => $failedSince,
      'grace_deadline' => $graceDeadline,
    ]);
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Returns TRUE when notification emails are enabled in settings.
   *
   * @return bool
   *   TRUE when notification_enabled = TRUE (or unset — defaults open).
   */
  protected function isEnabled(): bool {
    return (bool) ($this->configFactory
      ->get('license_service_subscriptions.settings')
      ->get('notification_enabled') ?? TRUE);
  }

  /**
   * Returns the human-readable label for a plan.
   *
   * Falls back to `ucfirst($planId)` when the entity is unavailable.
   *
   * @param string $planId
   *   LicenseSubscriptionPlan machine name.
   *
   * @return string
   *   Human-readable plan label.
   */
  protected function getPlanLabel(string $planId): string {
    try {
      $plan = $this->entityTypeManager
        ->getStorage('license_subscription_plan')
        ->load($planId);
      if ($plan !== NULL) {
        return (string) $plan->label();
      }
    }
    catch (\Exception) {
    }
    return ucfirst($planId);
  }

  /**
   * Builds the absolute choose-plan URL for a raw token.
   *
   * @param string $rawToken
   *   The 64-char hex token string.
   *
   * @return string
   *   Absolute URL to the choose-plan form.
   */
  protected function buildChoosePlanUrl(string $rawToken): string {
    try {
      return Url::fromRoute(
        'license_service_subscriptions.choose_plan',
        ['token' => $rawToken],
        ['absolute' => TRUE],
      )->toString();
    }
    catch (\Exception) {
      return '/subscribe/choose/' . $rawToken;
    }
  }

  /**
   * Loads the recipient user and dispatches a typed email.
   *
   * Silently skips users with no email or UID 0. Catches and logs any
   * MailManager failure without propagating the exception.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param string $key
   *   Email key: plan_deprecated | renewal_reminder | plan_chosen | payment_failing.
   * @param array $params
   *   Template parameters passed to hook_mail().
   */
  protected function sendToUser(int $uid, string $key, array $params): void {
    $logger = $this->loggerFactory->get(self::MODULE);

    if ($uid === 0) {
      return;
    }

    try {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
    }
    catch (\Exception $e) {
      $logger->warning('sendToUser: could not load uid @uid: @msg', ['@uid' => $uid, '@msg' => $e->getMessage()]);
      return;
    }

    if ($user === NULL || !$user->getEmail()) {
      return;
    }

    $langcode = $user->getPreferredLangcode()
      ?: $this->languageManager->getDefaultLanguage()->getId();

    $params['user'] = $user;

    try {
      $result = $this->mailManager->mail(
        self::MODULE,
        $key,
        $user->getEmail(),
        $langcode,
        $params,
        NULL,
        TRUE,
      );

      if (empty($result['result'])) {
        $logger->warning(
          'Notification email "@key" to uid @uid failed to send (MailManager returned FALSE).',
          ['@key' => $key, '@uid' => $uid],
        );
      }
    }
    catch (\Exception $e) {
      $logger->error(
        'Notification email "@key" to uid @uid threw an exception: @msg',
        ['@key' => $key, '@uid' => $uid, '@msg' => $e->getMessage()],
      );
    }
  }

}
