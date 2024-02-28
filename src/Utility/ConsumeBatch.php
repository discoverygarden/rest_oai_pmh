<?php

namespace Drupal\rest_oai_pmh\Utility;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use Psr\Log\LoggerInterface;

/**
 * Batch used for invoking and running queue workers.
 */
class ConsumeBatch {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The queue being consumed.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected QueueInterface $queue;

  /**
   * The queue worker.
   *
   * @var \Drupal\Core\Queue\QueueWorkerInterface
   */
  protected QueueWorkerInterface $queueWorker;

  /**
   * The logger for the module.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new ConsumeBatch object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue worker manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger for the module.
   * @param string $queue_name
   *   The name of the queue to consume from.
   * @param string $queue_worker
   *   The queue worker plugin ID to consume with.
   */
  public function __construct(QueueFactory $queue, QueueWorkerManagerInterface $queue_manager, LoggerInterface $logger, string $queue_name = 'rest_oai_pmh_views_cache_cron', string $queue_worker = 'rest_oai_pmh_views_cache_cron') {
    $this->queue = $queue->get($queue_name);
    $this->queueWorker = $queue_manager->createInstance($queue_worker);
    $this->logger = $logger;
  }

  /**
   * Implements callback_batch_operation() to rebuild the entire OAI result set.
   *
   * @param \DrushBatchContext|array $context
   *   The batch context.
   */
  public function rebuildBatchOperation(&$context) {
    $sandbox =& $context['sandbox'];

    if (!isset($sandbox['total'])) {
      $sandbox['total'] = $this->queue->numberOfItems();
      if ($sandbox['total'] === 0) {
        $context['message'] = $this->t('No records to process.');
        $context['finished'] = 1;
        return;
      }
      $sandbox['completed'] = 0;
    }
    // Can't rely on the number of items in the queue being entirely accurate so
    // arbitrarily process ten per iteration until the queue is exhausted.
    $limit = 10;
    for ($i = 0; $i < $limit; $i++) {
      try {
        $item = $this->queue->claimItem();
        if (!$item) {
          $context['message'] = $this->t('Queue exhausted');
          $context['finished'] = 1;
          return;
        }
        $this->queueWorker->processItem($item->data);
        $context['message'] = $this->t('Processed @set_id from @view_id:@display_id with offset @offset (@current/@total).',
          [
            '@set_id' => $item->data['set_id'],
            '@view_id' => $item->data['view_id'],
            '@display_id' => $item->data['display_id'],
            '@offset' => $item->data['offset'],
            '@current' => $sandbox['completed'],
            '@total' => $sandbox['total'],
          ]
        );
        $this->queue->deleteItem($item);
      }
      catch (SuspendQueueException $e) {
        $this->queue->releaseItem($item);
        Error::logException($this->logger, $e);
      }
      catch (\Exception $e) {
        Error::logException($this->logger, $e);
      }
      finally {
        $sandbox['completed']++;
      }
    }
    $context['finished'] = $sandbox['completed'] / $sandbox['total'];
  }

}
