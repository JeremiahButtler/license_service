<?php

declare(strict_types=1);

namespace Drupal\license_service_token_limits\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects the legacy token-limits route to the TokenLimit entity collection.
 *
 * Author: Jeremiah Buttler.
 */
class TokenLimitsRedirectController extends ControllerBase {

  /**
   * Redirects to the TokenLimit entity collection admin page.
   */
  public function redirect(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('entity.token_limit.collection')->toString(),
      302,
    );
  }

}
