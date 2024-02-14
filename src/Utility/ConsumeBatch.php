<?php

namespace Drupal\rest_oai_pmh\Utility;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Constructs a new ConsumeBatch object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue worker manager.
   * @param string $queue_name
   *   The name of the queue to consume from.
   * @param string $queue_worker
   *   The queue worker plugin ID to consume with.
   */
  public function __construct(QueueFactory $queue, QueueWorkerManagerInterface $queue_manager, string $queue_name = 'rest_oai_pmh_views_cache_cron', string $queue_worker = 'rest_oai_pmh_views_cache_cron') {
    $this->queue = $queue->get($queue_name);
    $this->queueWorker = $queue_manager->createInstance($queue_worker);
  }

  /**
   * Implements callback_batch_operation() to rebuild the entire OAI result set.
   *
   * @param \DrushBatchContext|array $context
   *   The batch context.
   */
  public function rebuildBatchOperation(\DrushBatchContext|array &$context) {
    $sandbox =& $context['sandbox'];

    if (!isset($sandbox['total'])) {
      $sandbox['total'] = $this->queue->numberOfItems();
      $sandbox['offset'] = 10;
      $sandbox['completed'] = 0;
      if ($sandbox['total'] === 0) {
        $context['message'] = $this->t('No records to process.');
        $context['finished'] = 1;
        return;
      }
    }
    // If there's less than the offset left use the remaining items instead.
    $offset = min($sandbox['offset'], $sandbox['total'] - $sandbox['completed']);
    for ($i = 0; $i < $offset; $i++) {
      try {
        $item = $this->queue->claimItem();
        $this->queueWorker->processItem($item->data);
        $this->queue->deleteItem($item);
      }
      catch (SuspendQueueException $e) {
        $this->queue->releaseItem($item);
        watchdog_exception('rest_oai_pmh', $e);
      }
      catch (\Exception $e) {
        watchdog_exception('rest_oai_pmh', $e);
      }
    }
    $sandbox['completed'] += $offset;
    $context['finished'] = $sandbox['completed'] / $sandbox['total'];
  }

}
