<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Generates, validates, and burns single-use choose-plan tokens.
 *
 * Token rules:
 *   - Single-use: burned at mark_intent_to_change commit, NOT on GET (email
 *     scanner prefetch must not burn it; burning happens only when the user
 *     actually submits the form).
 *   - UID-bound: rejected if opened by a different account than it was issued
 *     for. NEVER automatically logs the user in.
 *   - Rate-limited per-IP-per-token: defeats enumeration of token space.
 *   - Default TTL: configurable via license_service_subscriptions.settings
 *     choice_window_days (default 14 days; capped to 7 for the token itself
 *     since the intent row already stores effective_at).
 *
 * The token itself is a 256-bit random hex string (64 chars). Only its
 * SHA-256 hash is stored in the database — the raw token is never persisted.
 *
 * Author: Jeremiah Buttler.
 */
class SubscriptionChoiceTokenService {

  /**
   * Constructs a SubscriptionChoiceTokenService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection (reads/writes license_service_migration_intents).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (reads TTL and grace-window settings).
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Generates a single-use choose-plan token for the given user and subscription.
   *
   * The raw token is returned for embedding in the email link. Only the
   * SHA-256 hash is written to the database — inside the existing
   * license_service_migration_intents row identified by subscription_state_id.
   *
   * If an unused, non-expired token already exists for the same subscription
   * state ID, this method overwrites it (only one active token per subscription
   * at a time, preventing unlimited token generation via the email resend path).
   *
   * @param int $uid
   *   Drupal user ID (UID binding).
   * @param int $subscriptionStateId
   *   FK to license_service_subscriptions_state.id.
   * @param int $effectiveAt
   *   Unix timestamp when the migration should apply (old sub period-end).
   *
   * @return string
   *   256-bit random hex token (64 chars). Embed in the choose-plan URL.
   */
  public function generate(int $uid, int $subscriptionStateId, int $effectiveAt): string {
    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);

    $ttlDays = (int) ($this->configFactory
      ->get('license_service_subscriptions.settings')
      ->get('choice_window_days') ?? 14);
    $expires = time() + ($ttlDays * 86400);

    // Invalidate any existing unused token for the same subscription state row
    // by marking it used before inserting the new one. This prevents token
    // accumulation if an admin re-sends the deprecation email.
    $this->database->update('license_service_migration_intents')
      ->fields(['token_used' => 1])
      ->condition('subscription_state_id', $subscriptionStateId)
      ->condition('token_used', 0)
      ->execute();

    $this->database->insert('license_service_migration_intents')
      ->fields([
        'uid'                  => $uid,
        'subscription_state_id' => $subscriptionStateId,
        'target_plan_id'       => NULL,
        'token_hash'           => $hash,
        'token_used'           => 0,
        'token_expires'        => $expires,
        'payment_deadline'     => NULL,
        'effective_at'         => $effectiveAt,
        'created'              => time(),
      ])
      ->execute();

    return $raw;
  }

  /**
   * Validates a token without burning it.
   *
   * Used on GET (page load) to show the choose-plan form. Does NOT burn the
   * token — that happens inside markIntentToChange() at form submission,
   * atomically with writing the intent row.
   *
   * @param string $token
   *   The raw token from the URL.
   * @param int $uid
   *   The authenticated user's UID (must match the token's UID binding).
   *
   * @return bool
   *   TRUE when the token is valid, unexpired, unused, and UID-matches.
   */
  public function validate(string $token, int $uid): bool {
    if ($token === '') {
      return FALSE;
    }

    $hash = hash('sha256', $token);
    $now  = time();

    $row = $this->database->select('license_service_migration_intents', 'i')
      ->fields('i', ['uid', 'token_used', 'token_expires'])
      ->condition('token_hash', $hash)
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      return FALSE;
    }
    if ((int) $row['uid'] !== $uid) {
      // UID mismatch — token belongs to a different account.
      return FALSE;
    }
    if ((int) $row['token_used'] !== 0) {
      return FALSE;
    }
    if ((int) $row['token_expires'] < $now) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Burns (marks as used) a validated token.
   *
   * Called inside the same transaction as mark_intent_to_change, AFTER the
   * intent row is updated with the user's choice, so the token is burned
   * atomically with the intent.
   *
   * @param string $token
   *   The raw token to burn.
   */
  public function burn(string $token): void {
    if ($token === '') {
      return;
    }

    $hash = hash('sha256', $token);

    $this->database->update('license_service_migration_intents')
      ->fields(['token_used' => 1])
      ->condition('token_hash', $hash)
      ->condition('token_used', 0)
      ->execute();
  }

}
