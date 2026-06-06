/**
 * @file
 * Real-time token usage polling for License Service Token Counter usage blocks.
 *
 * Finds all rendered usage blocks on the page (identified by the
 * .license-service-token-counter-usage-block selector and their data-atc-* attributes),
 * then polls the JSON endpoint at the configured interval and updates the
 * token count and provider/model breakdown table in-place without a page reload.
 *
 * Reads from drupalSettings.aiTokenCounter:
 *   usageEndpoint — absolute URL of the usage JSON route
 *   pollInterval  — milliseconds between polls (default: 30000)
 *
 * Uses Drupal.behaviors + core/once (no jQuery).
 *
 * Author: Jeremiah Buttler.
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Formats an integer as a locale-aware number string (e.g. "1,234,567").
   */
  function formatTokens(n) {
    return Number(n).toLocaleString();
  }

  /**
   * Re-renders the breakdown table body inside a block element.
   *
   * Builds rows via createElement + textContent (never innerHTML) so that
   * provider and model id strings received from the server cannot inject markup.
   *
   * Per-provider breakdown: counting tokens per AI call source — display
   * feature only, no cost math. Author: Jeremiah Buttler.
   *
   * @param {HTMLElement} el - The .license-service-token-counter-usage-block element.
   * @param {Array} breakdown - Provider breakdown from the usage API response.
   */
  function renderBreakdown(el, breakdown) {
    var tbody = el.querySelector('[data-atc-breakdown]');
    if (!tbody) { return; }

    // Remove existing rows before redrawing.
    while (tbody.firstChild) {
      tbody.removeChild(tbody.firstChild);
    }

    if (!breakdown || !breakdown.length) {
      var emptyRow = document.createElement('tr');
      emptyRow.className = 'atc-breakdown-empty';
      var emptyCell = document.createElement('td');
      emptyCell.setAttribute('colspan', '3');
      emptyCell.textContent = Drupal.t('No usage yet.');
      emptyRow.appendChild(emptyCell);
      tbody.appendChild(emptyRow);
      return;
    }

    breakdown.forEach(function (entry) {
      // Provider subtotal row.
      var provRow = document.createElement('tr');
      provRow.className = 'atc-breakdown-provider';
      var provNameCell = document.createElement('td');
      provNameCell.setAttribute('colspan', '2');
      provNameCell.textContent = entry.provider || '—';
      var provTotalCell = document.createElement('td');
      provTotalCell.textContent = formatTokens(entry.total);
      provRow.appendChild(provNameCell);
      provRow.appendChild(provTotalCell);
      tbody.appendChild(provRow);

      // One row per model beneath the provider.
      (entry.models || []).forEach(function (modelEntry) {
        var row = document.createElement('tr');
        row.className = 'atc-breakdown-model';
        var indentCell = document.createElement('td');
        var modelCell = document.createElement('td');
        modelCell.className = 'atc-breakdown-model-name';
        modelCell.textContent = modelEntry.model || '—';
        var tokensCell = document.createElement('td');
        tokensCell.textContent = formatTokens(modelEntry.tokens);
        row.appendChild(indentCell);
        row.appendChild(modelCell);
        row.appendChild(tokensCell);
        tbody.appendChild(row);
      });
    });
  }

  /**
   * Updates the estimated-cost line inside a block element.
   *
   * Looks for a [data-atc-cost] element and refreshes its text content from
   * the cost object returned by the usage API. Created via createElement so
   * cost amounts received from the server cannot inject markup.
   *
   * Cost display in usage blocks — display feature only. Author: Jeremiah Buttler.
   *
   * @param {HTMLElement} el - The .license-service-token-counter-usage-block element.
   * @param {{amount: number, currency: string}|undefined} cost - Cost data.
   */
  function renderCost(el, cost) {
    var costLine = el.querySelector('[data-atc-cost]');
    if (!costLine || !cost) { return; }

    // Clear existing content.
    while (costLine.firstChild) {
      costLine.removeChild(costLine.firstChild);
    }

    var valueEl = document.createElement('span');
    valueEl.className = 'atc-cost-value';
    valueEl.textContent = Number(cost.amount).toFixed(4);
    costLine.appendChild(valueEl);

    if (cost.currency) {
      var currencyEl = document.createElement('span');
      currencyEl.className = 'atc-cost-currency';
      currencyEl.textContent = ' ' + cost.currency;
      costLine.appendChild(currencyEl);
    }

    var labelEl = document.createElement('span');
    labelEl.className = 'atc-cost-label';
    labelEl.textContent = ' ' + Drupal.t('est. cost');
    costLine.appendChild(labelEl);
  }

  /**
   * Fetches the current token count for one block element and updates its DOM.
   *
   * @param {HTMLElement} el - The .license-service-token-counter-usage-block element.
   * @param {string} endpoint - Base URL of the usage JSON endpoint.
   */
  function pollBlock(el, endpoint) {
    var scope  = el.dataset.atcScope  || 'site';
    var period = el.dataset.atcPeriod || 'lifetime';
    var uid    = el.dataset.atcUid    || '';

    var url = endpoint + '?scope=' + encodeURIComponent(scope) +
              '&period=' + encodeURIComponent(period);
    if (uid !== '') {
      url += '&uid=' + encodeURIComponent(uid);
    }

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) { return; }
        return res.json();
      })
      .then(function (data) {
        if (!data || typeof data.tokens === 'undefined') { return; }
        var countEl = el.querySelector('.atc-count');
        if (countEl) {
          countEl.textContent = formatTokens(data.tokens);
        }
        if (data.breakdown !== undefined) {
          renderBreakdown(el, data.breakdown);
        }
        if (data.cost !== undefined) {
          renderCost(el, data.cost);
        }
      })
      .catch(function () {
        // Silently ignore network errors — stale counts are acceptable.
      });
  }

  /**
   * Sets up polling for all usage blocks found in the given context.
   */
  Drupal.behaviors.aiTokenCounterUsage = {
    attach: function (context) {
      var settings = drupalSettings.aiTokenCounter || {};
      var endpoint = settings.usageEndpoint || '';
      var interval = settings.pollInterval  || 30000;

      if (!endpoint) { return; }

      once('atc-usage-poll', '.license-service-token-counter-usage-block', context)
        .forEach(function (el) {
          // Initial poll so the count is fresh immediately after the page loads.
          pollBlock(el, endpoint);
          // Then keep polling on the configured interval. The timer id is kept on
          // the element so detach() can clear it; otherwise a block removed via
          // AJAX would leave an orphaned timer polling a detached element forever.
          el.atcTimer = setInterval(function () {
            pollBlock(el, endpoint);
          }, interval);
        });
    },
    detach: function (context) {
      if (!context || typeof context.querySelectorAll !== 'function') { return; }
      context.querySelectorAll('.license-service-token-counter-usage-block').forEach(function (el) {
        if (el.atcTimer) {
          clearInterval(el.atcTimer);
          el.atcTimer = null;
        }
      });
    }
  };

}(Drupal, once));
