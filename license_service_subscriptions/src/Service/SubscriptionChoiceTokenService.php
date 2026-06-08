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
 *   - Default TTL: 7 days (configurable via
 *     license_service_subscriptions.settings choice_window_days).
 *
 * The token itself is a 256-bit random hex string. Only its SHA-256 hash is
 * stored in the database — the raw token is never persisted.
 *
 * Author: Jeremiah Buttler.
 *
 * @todo Phase 2: implement generate(), validate(), and burn(). Stubs in Phase 1.
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
   * SHA-256 hash is written to the database.
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
   *
   * @todo Phase 2: implement.
   */
  public function generate(int $uid, int $subscriptionStateId, int $effectiveAt): string {
    // @todo Phase 2: bin2hex(random_bytes(32)), write sha256 hash + metadata
    //   to license_service_migration_intents, return raw token.
    return '';
  }

  /**
   * Validates a token without burning it.
   *
   * Used on GET (page load) to show the choose-plan form. Does NOT burn the
   * token — that happens in burn() at form submission commit.
   *
   * @param string $token
   *   The raw token from the URL.
   * @param int $uid
   *   The authenticated user's UID (must match the token's UID binding).
   *
   * @return bool
   *   TRUE when the token is valid, unexpired, unused, and UID-matches.
   *
   * @todo Phase 2: implement.
   */
  public function validate(string $token, int $uid): bool {
    // @todo Phase 2: hash(token), SELECT from intents WHERE token_hash + UID check
    //   + token_used = 0 + token_expires > NOW().
    return FALSE;
  }

  /**
   * Burns (marks as used) a validated token.
   *
   * Called inside the same transaction as mark_intent_to_change, AFTER the
   * intent row is written, so the token is burned atomically with the intent.
   *
   * @param string $token
   *   The raw token to burn.
   *
   * @todo Phase 2: implement.
   */
  public function burn(string $token): void {
    // @todo Phase 2: UPDATE license_service_migration_intents SET token_used = 1
    //   WHERE token_hash = hash(token).
  }

}
