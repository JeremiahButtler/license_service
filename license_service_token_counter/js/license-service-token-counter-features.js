/**
 * @file
 * Client-side search/filter for the License Service Token Counter features page.
 *
 * Author: Jeremiah Buttler.
 */
(function (once) {
  'use strict';

  Drupal.behaviors.aiTokenCounterFeatures = {
    attach: function (context) {
      once('license-service-token-counter-features', '#license-service-token-counter-features-search', context).forEach(function (input) {
        input.addEventListener('input', function () {
          var query = input.value.toLowerCase().trim();
          var sections = document.querySelectorAll('#license-service-token-counter-features-list .license-service-token-counter-features-section');
          sections.forEach(function (section) {
            var items = section.querySelectorAll('.license-service-token-counter-feature-item');
            var anyVisible = false;
            items.forEach(function (item) {
              var name = (item.querySelector('.license-service-token-counter-feature-name') || {}).textContent || '';
              var desc = (item.querySelector('.license-service-token-counter-feature-desc') || {}).textContent || '';
              var matches = !query || (name + ' ' + desc).toLowerCase().indexOf(query) !== -1;
              item.style.display = matches ? '' : 'none';
              if (matches) {
                anyVisible = true;
              }
            });
            section.style.display = anyVisible ? '' : 'none';
          });
        });
      });
    }
  };

}(once));
