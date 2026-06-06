<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service_token_counter_engine\Entity\PricingTableInterface;

/**
 * Add/edit form for pricing_table config entities.
 *
 * Presents a dynamic, AJAX-powered table of rate rows. Each row captures the
 * model identifier (with '*' wildcard support) and input, output, cached, and
 * reasoning costs per the table's token unit. Rows can be added and removed
 * without a full page reload. On save, the rows are written back to the
 * entity's 'rates' field.
 *
 * Author: Jeremiah Buttler.
 */
final class PricingTableForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\license_service_token_counter_engine\Entity\PricingTableInterface $entity */
    $entity = $this->entity;

    $form['label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Label'),
      '#default_value' => $entity->label(),
      '#required'      => TRUE,
      '#maxlength'     => 255,
    ];

    $form['id'] = [
      '#type'          => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name'  => [
        'exists' => ['\Drupal\license_service_token_counter_engine\Entity\PricingTable', 'load'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['status'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enabled'),
      '#default_value' => $entity->status(),
    ];

    $form['provider'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Provider ID'),
      '#description'   => $this->t('The drupal/ai provider machine name (e.g. <code>openai</code>, <code>anthropic</code>). Use <code>*</code> as a wildcard fallback for any provider.'),
      '#default_value' => $entity->getProvider(),
      '#required'      => TRUE,
      '#maxlength'     => 128,
    ];

    $form['unit'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Tokens per unit'),
      '#description'   => $this->t('The number of tokens the rates below apply to. Use <code>1000000</code> for per-million-token pricing.'),
      '#default_value' => $entity->getUnit(),
      '#required'      => TRUE,
      '#min'           => 1,
    ];

    $form['weight'] = [
      '#type'          => 'weight',
      '#title'         => $this->t('Weight'),
      '#description'   => $this->t('Lower-weight tables are checked first when resolving a rate. The first matching provider/model entry wins.'),
      '#default_value' => $entity->getWeight(),
    ];

    // Initialise row state: form_state takes precedence (set by AJAX callbacks)
    // so in-progress edits survive add/remove clicks.
    $rows = $form_state->get('rows');
    if ($rows === NULL) {
      $rows = $entity->getRates();
      if (empty($rows)) {
        $rows = [['model' => '', 'input' => 0.0, 'output' => 0.0, 'cached' => NULL, 'reasoning' => NULL]];
      }
      $form_state->set('rows', $rows);
    }

    $form['rates_wrapper'] = [
      '#type'   => 'container',
      '#prefix' => '<div id="rates-wrapper">',
      '#suffix' => '</div>',
      '#tree'   => TRUE,
    ];

    $form['rates_wrapper']['description'] = [
      '#markup' => '<p>' . $this->t('Rates are in the display currency, per the token unit defined above. Leave <em>Cached</em> or <em>Reasoning</em> empty to fall back to the <em>Input</em> or <em>Output</em> rate respectively.') . '</p>',
    ];

    $form['rates_wrapper']['table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('Model (use * for any)'),
        $this->t('Input'),
        $this->t('Output'),
        $this->t('Cached'),
        $this->t('Reasoning'),
        $this->t('Actions'),
      ],
      '#empty'  => $this->t('No rate rows yet — add one below.'),
    ];

    foreach ($rows as $i => $rate) {
      $row = &$form['rates_wrapper']['table'][$i];

      $row['model'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Model'),
        '#title_display' => 'invisible',
        '#default_value' => (string) ($rate['model'] ?? ''),
        '#placeholder'   => '*',
        '#size'          => 30,
      ];

      $row['input'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Input'),
        '#title_display' => 'invisible',
        '#default_value' => is_numeric($rate['input'] ?? NULL) ? (float) $rate['input'] : 0.0,
        '#step'          => 'any',
        '#min'           => 0,
        '#size'          => 10,
      ];

      $row['output'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Output'),
        '#title_display' => 'invisible',
        '#default_value' => is_numeric($rate['output'] ?? NULL) ? (float) $rate['output'] : 0.0,
        '#step'          => 'any',
        '#min'           => 0,
        '#size'          => 10,
      ];

      $row['cached'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Cached'),
        '#title_display' => 'invisible',
        '#default_value' => is_numeric($rate['cached'] ?? NULL) ? (float) $rate['cached'] : '',
        '#step'          => 'any',
        '#min'           => 0,
        '#size'          => 10,
        '#attributes'    => ['placeholder' => $this->t('optional')->render()],
      ];

      $row['reasoning'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Reasoning'),
        '#title_display' => 'invisible',
        '#default_value' => is_numeric($rate['reasoning'] ?? NULL) ? (float) $rate['reasoning'] : '',
        '#step'          => 'any',
        '#min'           => 0,
        '#size'          => 10,
        '#attributes'    => ['placeholder' => $this->t('optional')->render()],
      ];

      $row['remove'] = [
        '#type'                   => 'submit',
        '#value'                  => $this->t('Remove'),
        '#name'                   => 'remove_row_' . $i,
        '#submit'                 => ['::removeRowCallback'],
        '#ajax'                   => [
          'callback' => '::ajaxRatesWrapper',
          'wrapper'  => 'rates-wrapper',
        ],
        '#limit_validation_errors' => [],
        '#row_index'              => $i,
        '#attributes'             => ['class' => ['button--danger', 'button--small']],
      ];
    }

    $form['rates_wrapper']['add_row'] = [
      '#type'                   => 'submit',
      '#value'                  => $this->t('Add rate row'),
      '#submit'                 => ['::addRowCallback'],
      '#ajax'                   => [
        'callback' => '::ajaxRatesWrapper',
        'wrapper'  => 'rates-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * AJAX callback: returns the rates table wrapper for re-render.
   */
  public function ajaxRatesWrapper(array &$form, FormStateInterface $form_state): array {
    return $form['rates_wrapper'];
  }

  /**
   * Submit callback: appends an empty rate row to the table.
   */
  public function addRowCallback(array &$form, FormStateInterface $form_state): void {
    $rows   = $this->currentRowsFromState($form_state);
    $rows[] = ['model' => '', 'input' => 0.0, 'output' => 0.0, 'cached' => NULL, 'reasoning' => NULL];
    $form_state->set('rows', $rows);
    $form_state->setRebuild();
  }

  /**
   * Submit callback: removes the row corresponding to the clicked Remove button.
   */
  public function removeRowCallback(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $index   = (int) ($trigger['#row_index'] ?? -1);
    $rows    = $this->currentRowsFromState($form_state);

    if ($index >= 0 && array_key_exists($index, $rows)) {
      array_splice($rows, $index, 1);
    }

    // Always keep at least one empty row so the table stays visible.
    if (empty($rows)) {
      $rows = [['model' => '', 'input' => 0.0, 'output' => 0.0, 'cached' => NULL, 'reasoning' => NULL]];
    }

    $form_state->set('rows', $rows);
    $form_state->setRebuild();
  }

  /**
   * Reads the current row values from the submitted form state.
   *
   * Captures in-progress edits from the table widget so they survive
   * add/remove clicks without requiring a full form submission.
   *
   * @return list<array{model:string,input:float|int|string,output:float|int|string,cached:mixed,reasoning:mixed}>
   *   Current rate rows as extracted from the submitted form state.
   */
  private function currentRowsFromState(FormStateInterface $form_state): array {
    $table = $form_state->getValue(['rates_wrapper', 'table']) ?? [];
    $rows  = [];
    foreach ($table as $cols) {
      $rows[] = [
        'model'     => (string) ($cols['model'] ?? ''),
        'input'     => $cols['input'] ?? 0.0,
        'output'    => $cols['output'] ?? 0.0,
        'cached'    => $cols['cached'] ?? NULL,
        'reasoning' => $cols['reasoning'] ?? NULL,
      ];
    }
    // Fall back to stored rows if the table widget had no values (initial GET).
    return $rows ?: ($form_state->get('rows') ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Normalise and store the rate rows as a clean array on the form state so
    // save() can read them regardless of how the entity field is named.
    $table = $form_state->getValue(['rates_wrapper', 'table']) ?? [];
    $rates = [];
    foreach ($table as $cols) {
      $model = trim((string) ($cols['model'] ?? ''));
      if ($model === '') {
        $model = PricingTableInterface::MODEL_WILDCARD;
      }
      $cached    = $cols['cached'] ?? '';
      $reasoning = $cols['reasoning'] ?? '';
      $rates[]   = [
        'model'     => $model,
        'input'     => is_numeric($cols['input'] ?? NULL) ? (float) $cols['input'] : 0.0,
        'output'    => is_numeric($cols['output'] ?? NULL) ? (float) $cols['output'] : 0.0,
        'cached'    => ($cached !== '' && is_numeric($cached)) ? (float) $cached : NULL,
        'reasoning' => ($reasoning !== '' && is_numeric($reasoning)) ? (float) $reasoning : NULL,
      ];
    }
    $form_state->setValue('rates', $rates);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\license_service_token_counter_engine\Entity\PricingTableInterface $entity */
    $entity = $this->entity;
    $entity->set('rates', $form_state->getValue('rates') ?? []);
    $status = parent::save($form, $form_state);

    $this->messenger()->addStatus(
      $status === SAVED_NEW
        ? $this->t('Pricing table %label has been created.', ['%label' => $entity->label()])
        : $this->t('Pricing table %label has been updated.', ['%label' => $entity->label()])
    );

    $form_state->setRedirect('entity.pricing_table.collection');
    return $status;
  }

}
