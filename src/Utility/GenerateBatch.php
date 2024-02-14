<?php

namespace Drupal\rest_oai_pmh\Utility;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\Plugin\views\ViewsHandlerInterface;
use Drupal\views\Views;

/**
 * Batch used for generating jobs for the queue to be consumed.
 */
class GenerateBatch {

  use StringTranslationTrait;

  /**
   * The queue being populated.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected QueueInterface $queue;

  /**
   * Constructs a new GenerateBatch object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   The queue factory.
   * @param string $queue_name
   *   The name of the queue to populate.
   */
  public function __construct(QueueFactory $queue, string $queue_name = 'rest_oai_pmh_views_cache') {
    $this->queue = $queue->get($queue_name);
  }

  /**
   * Batch to invoke from an individual view display.
   *
   * @param array $view_displays
   *   The view displays to generate from.
   */
  public function generateFromDisplayBatch($view_displays) {
    $batch = [
      'operations' => [
        [
          [$this, 'generateFromDisplayBatchOperation'],
          [$view_displays],
        ],
      ],
      'title' => 'Processing OAI rebuild: generating from display',
      'init_message' => 'OAI rebuild: generating from display is starting.',
      'progress_message' => 'Processed @current out of @total.',
      'error_message' => 'OAI rebuild has encountered an error.',
    ];
    batch_set($batch);
  }

  /**
   * Implements callback_batch_operation() to generate jobs a single display.
   *
   * @param array $view_displays
   *   The view displays to generate from.
   * @param \DrushBatchContext|array $context
   *   The batch context.
   */
  public function generateFromDisplayBatchOperation($view_displays, \DrushBatchContext|array &$context) {
    $sandbox = &$context['sandbox'];
    if (!isset($sandbox['displays'])) {
      $sandbox['displays'] = $view_displays;
      if (empty($sandbox['displays'])) {
        $context['message'] = $this->t('No records to process.');
        $context['finished'] = 1;
        return;
      }
    }
    $view_display = array_shift($sandbox['displays']);
    $this->findSetEntitiesBatch($view_display);
    $context['finished'] = empty($sandbox['displays']);
  }

  /**
   * Batch to find set entities for a single view display.
   *
   * @param string $view_display
   *   The view display being used.
   */
  public function findSetEntitiesBatch($view_display) {
    [$view_id, $display_id] = explode(':', $view_display);
    $view = Views::getView($view_id);
    $view->setDisplay($display_id);
    $operations = [];
    // Each view display can have multiple handlers, build up operations
    // for each.
    foreach ($view->display_handler->getHandlers('argument') as $contextual_filter) {
      $operations[] = [
        [$this, 'findSetEntitiesBatchOperation'],
        [$view_display, $contextual_filter],
      ];
    }
    // Add an operation to handle the case where there are no contextual filters
    // set for the display.
    $operations[] = [
      [$this, 'setlessEntitiesBatchOperation'],
      [$view_display],
    ];
    $batch = [
      'operations' => $operations,
      'title' => 'Processing OAI rebuild: finding set entities',
      'init_message' => 'OAI rebuild: finding set entities is starting.',
      'progress_message' => 'Processed @current out of @total.',
      'error_message' => 'OAI rebuild has encountered an error.',
    ];
    batch_set($batch);
  }

  /**
   * Implements callback_batch_operation() to find sets per display and filter.
   *
   * @param string $view_display
   *   The view display being used.
   * @param \Drupal\views\Plugin\views\ViewsHandlerInterface $contextual_filter
   *   The contextual filter being used.
   * @param \DrushBatchContext|array $context
   *   The batch context.
   */
  public function findSetEntitiesBatchOperation($view_display, ViewsHandlerInterface $contextual_filter, \DrushBatchContext|array &$context) {
    $sandbox =& $context['sandbox'];
    if (!isset($sandbox['total'])) {
      $context['results']['has_sets'] = FALSE;
      [
        $set_entity_type,
        $set_entity_storage,
        $query,
      ] = rest_oai_pmh_determine_set_inclusion($contextual_filter);
      $sandbox['query'] = $query;
      if (!$set_entity_type) {
        $context['message'] = $this->t('No sets found to process.');
        $context['finished'] = 1;
        return;
      }
      $total = $query->countQuery()->execute()->fetchField();
      if ($total === 0) {
        $context['message'] = $this->t('Set has no records to process.');
        $context['finished'] = 1;
        return;
      }
      $sandbox['total'] = $total;
      $sandbox['offset'] = 10;
      $sandbox['completed'] = 0;
    }
    $offset = min($sandbox['offset'], $sandbox['total'] - $sandbox['completed']);
    // Add the offset and limit to the query.
    foreach ($sandbox['query']->range($sandbox['completed'], $offset)->execute()->fetchCol() as $id) {
      $entity = $set_entity_storage->load($id);
      if ($entity) {
        [$view_id, $display_id] = explode(':', $view_display);
        $context['results']['has_sets'] = TRUE;
        $data = [
          'view_id' => $view_id,
          'display_id' => $display_id,
          'arguments' => [$entity->id()],
          'set_entity_type' => $set_entity_type,
          'set_id' => $set_entity_type . ':' . $entity->id(),
          'set_label' => $entity->label(),
          'view_display' => $view_display,
        ];
        $this->createItemsBatch($data);
      }
    }
    $sandbox['completed'] += $offset;
    $context['finished'] = $sandbox['completed'] / $sandbox['total'];
  }

  /**
   * Implements callback_batch_operation() to find items not belonging to a set.
   *
   * @param string $view_display
   *   The view display being used.
   * @param \DrushBatchContext|array $context
   *   The batch context.
   */
  public function setLessEntitiesBatchOperation($view_display, \DrushBatchContext|array &$context) {
    if (isset($context['results']['has_sets']) && $context['results']['has_sets'] === TRUE) {
      $context['message'] = $this->t('View display has sets, nothing to process.');
      $context['finished'] = 1;
      return;
    }
    [$view_id, $display_id] = explode(':', $view_display);
    $view = Views::getView($view_id);
    $display = $view->get('display');
    $data = [
      'view_id' => $view_id,
      'display_id' => $display_id,
      'arguments' => [],
      'set_entity_type' => 'view',
      'set_id' => $view_display,
      'set_label' => $display[$display_id]['display_title'],
      'view_display' => $view_display,
    ];
    $this->createItemsBatch($data);
    $context['finished'] = 1;
  }

  /**
   * Batch that creates items in a queue for processing.
   *
   * @param array $data
   *   The payload data for the job in the queue.
   */
  public function createItemsBatch(array $data) {
    $batch = [
      'operations' => [
        [
          [$this, 'createItemsBatchOperation'],
          [$data],
        ],
      ],
      'title' => 'Processing OAI rebuild: items creation',
      'init_message' => 'OAI rebuild: items creation is starting.',
      'progress_message' => 'Processed @current out of @total.',
      'error_message' => 'OAI rebuild has encountered an error.',
    ];
    batch_set($batch);
  }

  /**
   * Implements callback_batch_operation() to create items for a single display.
   *
   * @param array $data
   * *   The payload data for the job in the queue.
   * @param \DrushBatchContext|array $context
   *   The batch context.
   */
  public function createItemsBatchOperation(array $data, \DrushBatchContext|array &$context) {
    $sandbox =& $context['sandbox'];
    if (!isset($sandbox['total'])) {
      $view = Views::getView($data['view_id']);
      $view->setDisplay($data['display_id']);
      $view->get_total_rows = TRUE;
      $view->getDisplay()->setOption('entity_reference_options', ['limit' => $view->getItemsPerPage()]);
      // Get the first set of results from the View.
      $view->executeDisplay($data['display_id'], $data['arguments']);
      // After we executed the View, see how many items were returned
      // use this to page through all results.
      $sandbox['total'] = $view->total_rows;
      if ($sandbox['total'] === 0) {
        $context['message'] = $this->t('No records to process.');
        $context['finished'] = 1;
        return;
      }
      $sandbox['offset'] = 0;
      $sandbox['limit'] = $view->getItemsPerPage();
      $sandbox['completed'] = 0;
    }
    $data['offset'] = $sandbox['offset'];
    // Queue the information we found to be processed by the queue.
    $this->queue->createItem($data);
    $sandbox['offset'] += $sandbox['limit'];
    // If there's less than the offset left use the remaining items instead.
    $sandbox['completed'] += min($sandbox['offset'], $sandbox['total'] - $sandbox['completed']);
    $context['finished'] = $sandbox['completed'] / $sandbox['total'];
  }

}
