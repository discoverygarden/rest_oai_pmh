<?php

namespace Drupal\rest_oai_pmh\Form;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\rest_oai_pmh\Utility\ConsumeBatch;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rebuild the OAI cache.
 */
class OaiPmhQueueForm extends FormBase {

  use DependencySerializationTrait;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The queue manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected QueueWorkerManagerInterface $queueManager;

/**
   * The logger for the module.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(QueueFactory $queue, QueueWorkerManagerInterface $queue_manager, LoggerInterface $logger) {
    $this->queueFactory = $queue;
    $this->queueManager = $queue_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('logger.channel.rest_oai_pmh'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'oai_pmh_queue_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Submitting this form will rebuild your OAI-PMH entries.<br>This will automatically be done on cron, but you can perform it manually here.'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rebuild OAI-PMH'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    rest_oai_pmh_cache_views();
    $consume_batch = new ConsumeBatch($this->queueFactory, $this->queueManager, $this->logger);
    $batch = [
      'operations' => [
        [
          [$consume_batch, 'rebuildBatchOperation'],
          [],
        ],
      ],
      'finished' => 'rest_oai_pmh_batch_finished',
      'title' => $this->t('Processing OAI rebuild from queue.'),
      'init_message' => $this->t('OAI rebuild from queue is starting.'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('OAI rebuild has encountered an error.'),
    ];

    batch_set($batch);
  }

}
