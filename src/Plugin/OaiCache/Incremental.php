<?php

namespace Drupal\rest_oai_pmh\Plugin\OaiCache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\rest_oai_pmh\Plugin\OaiCacheBase;
use Drupal\views\Views;
use Psr\Container\ContainerInterface;

/**
 * Incremental cache clearing strategy.
 *
 * Doesn't rebuild the entire set of results when a single entity is changed.
 *
 * @OaiCache(
 *  id = "incremental_cache",
 *  label = @Translation("Incremental Cache Clearing Strategy")
 * )
 */
class Incremental extends OaiCacheBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The settings for the module.
   *
   * @var \Drupal\Core\Config\ConfigBase
   */
  protected ConfigBase $settings;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Constructor for the Incremental cache plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigBase $settings
   *   The settings for the module.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database, TimeInterface $time, EntityTypeManagerInterface $entity_type_manager, ConfigBase $settings, MessengerInterface $messenger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
    $this->settings = $settings;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('config.factory')->getEditable('rest_oai_pmh.settings'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function clearCache($entity, $op) {
    if ($op === 'delete') {
      parent::clearCache($entity, $op);
      return;
    }

    if ($entity->getEntityTypeId() === 'view') {
      $this->updateViews($entity);
      return;
    }

    if (!$this->validOaiEntity($entity)) {
      return;
    }

    // Remove any reference to the record first given it's being re-created.
    $this->database->delete('rest_oai_pmh_record')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', $entity->id())
      ->execute();
    $this->database->delete('rest_oai_pmh_member')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', $entity->id())
      ->execute();

    // Before rebuilding the record, ensure the entity is accessible by
    // the anonymous user.
    $anonymous_user = $this->entityTypeManager->getStorage('user')->load(0);
    if (!$entity->access('view', $anonymous_user)) {
      return;
    }

    // Ensure a set exists for this record given that this the mechanism on how
    // the module operates.
    if ($this->addToSet($entity)) {
      $this->upsertRecord($entity);
    }
  }

  /**
   * Updates view configuration and records depending on the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The view being updated.
   */
  protected function updateViews(EntityInterface $entity): void {
    $entity_id = $entity->id();
    $oai_view_displays = $this->settings->get('view_displays') ?: [];
    $in_config = FALSE;
    // Go through.
    foreach ($oai_view_displays as $view_display) {
      [$view_id, $display_id] = explode(':', $view_display);
      if ($view_id === $entity_id) {
        $in_config = TRUE;
        break;
      }
    }

    // If there is a display in OAI.
    if ($in_config) {
      $displays = [];
      foreach ($entity->get('display') as $view_display_id => $display) {
        $displays[] = $entity_id . ':' . $view_display_id;
      }
      $deleted_displays = array_diff($oai_view_displays, $displays);
      if (count($deleted_displays)) {
        foreach ($deleted_displays as $deleted_display) {
          rest_oai_pmh_remove_sets_by_display_id($deleted_display);
          unset($oai_view_displays[$deleted_display]);
        }
        $this->settings->set('view_displays', $oai_view_displays)->save();
      }
      // Message that the records may need to be rebuilt and point them to the
      // admin form as opposed to doing things inline in the view.
      $this->messenger->addStatus($this->t('The OAI-PMH records may need to be rebuilt. Please visit the <a href=":url">OAI-PMH settings</a> to rebuild the records if changes have occurred to the filters or display options.',
        [':url' => '/admin/config/services/rest/oai-pmh/queue']
      ));
    }
  }

  /**
   * Determines if an entity is exposed to OAI or not.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being potentially exposed to OAI.
   *
   * @return bool
   *   TRUE if it should have an OAI record; FALSE otherwise.
   */
  protected function validOaiEntity(EntityInterface $entity): bool {
    // Optimize by checking if the entity type is exposed to OAI if records
    // are already in the DB.
    $valid = rest_oai_pmh_is_valid_entity_type($entity->getEntityTypeId());
    if (!$valid) {
      // Look in the configured view to see if it's exposed.
      $view_displays = $this->settings->get('view_displays') ?: [];
      foreach ($view_displays as $view_display) {
        [$view_id, $display_id] = explode(':', $view_display);
        $view = Views::getView($view_id);
        if ($view !== null) {
          $view->setDisplay($display_id);
          // See if the entity type from $entity is used by the display in the
          // view.
          if ($view->getBaseEntityType()->id() === $entity->getEntityTypeId()) {
            $valid = TRUE;
            break;
          }
        }
      }
    }
    return $valid;
  }

  /**
   * Helper that upserts a record to the database.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being exposed to OAI.
   */
  protected function upsertRecord(EntityInterface $entity): void {
    // @note: Graciously adapated from @kayakr's plugin.
    $merge_keys = [
      'entity_type',
      'entity_id',
    ];
    $merge_values = [
      $entity->getEntityTypeId(),
      $entity->id(),
    ];
    // Get the changed/created values, if they exist.
    $changed = $entity->hasField('changed') ? $entity->changed->value : $this->time->getRequestTime();
    $created = $entity->hasField('created') ? $entity->created->value : $changed;
    // Upsert the record into our cache table.
    $this->database->merge('rest_oai_pmh_record')
      ->keys($merge_keys, $merge_values)
      ->fields([
        'created' => $created,
        'changed' => $changed,
      ])->execute();
  }

  /**
   * Adds an entity to the database tables representing membership.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being exposed to OAI.
   *
   * @return bool
   *   TRUE if a set was found for the record to be added to; FALSE otherwise.
   */
  protected function addToSet(EntityInterface $entity): bool {
    $valid_set = FALSE;
    $view_displays = $this->settings->get('view_displays') ?: [];

    foreach ($view_displays as $view_display) {
      [$view_id, $display_id] = explode(':', $view_display);
      $view = Views::getView($view_id);
      $view->setDisplay($display_id);
      $has_set = FALSE;
      foreach ($view->display_handler->getHandlers('argument') as $contextual_filter) {
        [
          $query,
          $set_entity_type,
          $set_entity_storage,
        ] = rest_oai_pmh_determine_set_inclusion($contextual_filter);
        if ($set_entity_type) {
          // Presence of a set_entity_type indicates that a contextual filter
          // is being used for the display.
          $has_set = TRUE;
          $entity_type = $contextual_filter->definition['entity_type'];
          $field = $contextual_filter->definition['field_name'];
          $column = $contextual_filter->definition['field'];
          $field_table = $entity_type . '__' . $field;

          $references = $this->database->select($field_table, 'e')
            ->fields('e', [$column])
            ->condition('entity_id', $entity->id())
            ->execute()
            ->fetchCol();
          // Presence of a reference isn't enough to satisfy being added to an
          // OAI set given the nature of configurable filters.
          if (!empty($references)) {
            foreach ($references as $reference) {
              // Pre-optimize by querying against the existing sets before
              // incurring a hit of executing the view.
              $set_existence = $this->database->select('rest_oai_pmh_set', 's')
                ->fields('s', ['set_id'])
                ->condition('set_id', $reference)
                ->execute()
                ->fetchField();
              if ($set_existence) {
                $this->upsertSetMembership($entity, $reference);
                $valid_set = TRUE;
              }
              else {
                // Database has a value for a set but one doesn't exist. Execute
                // the view to see if the set is valid with whatever filters
                // applied.
                $view->get_total_rows = TRUE;
                $view->getDisplay()
                  ->setOption('entity_reference_options', ['limit' => $view->getItemsPerPage()]);
                // Get the first set of results from the View with the set ID.
                $view->executeDisplay($display_id, [$reference]);
                if ($view->total_rows) {
                  $set = $set_entity_storage->load($reference);
                  if ($set) {
                    $set_id = "$set_entity_type:{$set->id()}";
                    $this->upsertSet($set_id, [
                      'set_id' => $set_id,
                      'entity_type' => $set_entity_type,
                      'label' => $set->label(),
                      'pager_limit' => $view->getItemsPerPage(),
                      'view_display' => $view_display,
                    ]);
                    $this->upsertSetMembership($entity, $set_id);
                    $valid_set = TRUE;
                  }
                }
              }
            }
          }
        }
      }
      if (!$has_set) {
        $display = $view->storage->get('display');
        // XXX: Ensure that this result would actually show up in the result
        // set of the configured view by limiting the result set to contain the
        // ID of the entity.
        $filters = $view->getDisplay()->getOption('filters');
        $id_filter = [
          'id' => $view->storage->get('base_field'),
          'table' => $view->storage->get('base_table'),
          'field' => $view->storage->get('base_field'),
          'value' => ['value' => $entity->id()],
          'operator' => '=',
        ];
        $filters['rest_oai_pmh_id'] = $id_filter;
        $view->getDisplay()->overrideOption('filters', $filters);
        $results = $view->executeDisplay($display_id);
        if (!empty($results)) {
          $limit = $display[$display_id]['display_options']['pager']['options']['items_per_page'] ?? 0;
          $this->upsertSet($view_display, [
            'set_id' => $view_display,
            'entity_type' => 'view',
            'label' => $display[$display_id]['display_title'],
            'pager_limit' => $limit,
            'view_display' => $view_display,
          ]);
          $this->upsertSetMembership($entity, $view_display);
          $valid_set = TRUE;
        }
      }
    }
    return $valid_set;
  }

  /**
   * Upserts a set membership relation to the database.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being updated.
   * @param string $set_id
   *   The ID of the set being related to.
   */
  protected function upsertSetMembership(EntityInterface $entity, string $set_id): void {
    $merge_keys = [
      'entity_type',
      'entity_id',
      'set_id',
    ];
    $merge_values = [
      $entity->getEntityTypeId(),
      $entity->id(),
      $set_id,
    ];
    $this->database->merge('rest_oai_pmh_member')
      ->keys($merge_keys, $merge_values)
      ->execute();
  }

  /**
   * Upserts a set record to the database.
   *
   * @param string $set_id
   *   The ID of the set being created.
   * @param array $args
   *   An array containing:
   *   - set_id: The ID of the set being created.
   *   - entity_type: The entity type being exposed to OAI.
   *   - label: The human-readable label for the set.
   *   - pager_limit: The limit of items to display per page.
   *   - view_display: The ID of the view display.
   */
  protected function upsertSet(string $set_id, array $args): void {
    $merge_keys = [
      'entity_type',
      'set_id',
    ];
    $merge_values = [
      $args['entity_type'],
      $set_id,
    ];
    $this->database->merge('rest_oai_pmh_set')
      ->keys($merge_keys, $merge_values)
      ->fields(
        [
          'label' => $args['label'],
          'pager_limit' => $args['pager_limit'],
          'view_display' => $args['view_display'],
        ]
      )->execute();
  }

}
