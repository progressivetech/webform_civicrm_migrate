<?php

namespace Drupal\webform_civicrm_migrate\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;

use Drupal\migrate_plus\Event\MigrateEvents as MigratePlusEvents;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\MigrateSkipRowException;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use Drupal\webform\Entity\Webform;
use Drupal\webform\Utility\WebformYaml;
use Drupal\webform\Utility\WebformElementHelper;

/**
 * Webform CiviCRM Migrate event subscriber.
 */
class WebformCivicrmMigrateSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      MigratePlusEvents::PREPARE_ROW => ['onPrepareRow'],
      MigrateEvents::PRE_IMPORT => ['onPreImport'],
      MigrateEvents::POST_IMPORT => ['onPostImport'],
    ];
  }

  
  /**
   * Helper function to get all Webforms.
   */
  public static function getAllWebforms(){
    $webform_ids = \Drupal::entityQuery('webform')    
                 ->execute();
    $webforms = [];
    foreach($webform_ids as $id => $title) {
      $webforms[] = Webform::load($id);     
    }
    return $webforms;
  }

  /**
   * Gets a setting from a settings array based on key passed in.
   * 
   * @param string $key in form civicrm_n_ent_m_id
   * @param array $array Settings array in format $array[entity][n][ent][m]
   * @param string $entity which type of entity to look for.
   */
  private static function getSettingsFromArrayByKey(string $key, array $array,$entity='contact') {
    // Code copied/adapted from https://github.com/colemanw/webform_civicrm/blob/5f0ca53657c6110389f649022e38be8e81881557/src/Utils.php#L465
    $exp = explode('_', $key, 5);
    $customGroupFieldsetKey = '';
    if (count($exp) == 5) {
      [$lobo, $i, $ent, $n, $id] = $exp;
      if ($lobo != 'civicrm') {
        return FALSE;
      }
    }
    else {
      return FALSE;
    }
    return $array[$entity][$i][$ent][$n];
  }


  /**
   * Additional post processing for a Webform CiviCRM Contact Element.
   */
  private function migrateWebformElementCiviCRMContact($element, $d7_form_settings, $nid) {
    $settings = $this->getSettingsFromArrayByKey($element['#form_key'], $d7_form_settings, 'contact');
    $extra = WebformCivicrmMigrateSubscriber::getWebformComponentExtraData($nid, $element['#form_key']);

    // Adapted from Webform CiviCRM ->
    // https://github.com/colemanw/webform_civicrm/blob/fe2258884c9c741aed65665cb3cc8bc73fd75248/src/Plugin/WebformElement/CivicrmContact.php#L31
    $keys_to_copy = [
      'show_hidden_contact' => '',
      'results_display' => ['display_name' => 'display_name'],
      'widget' => '',
      'search_prompt' => '',
      'none_prompt' => '',
      'allow_create' => 0,
      'show_hidden_contact' => 0,
      'no_autofill' => [],
      'hide_fields' => [],
      'hide_method' => 'hide',
      'no_hide_blank' => FALSE,
      'submit_disabled' => FALSE,
      'private' => FALSE,
      'default' => '',
      'default_contact_id' => '',
      'default_relationship_to' => '',
      'default_relationship' => '',
      'allow_url_autofill' => TRUE,
      'dupes_allowed' => FALSE,
      'filter_relationship_types' => [],
      'filter_relationship_contact' => [],
      'group' => [],
      'tag' => [],
      'check_permissions' => 1,
      'expose_list' => FALSE,
      'empty_option' => '',
    ];
    
    foreach($keys_to_copy as $extra_key => $extra_default) {
      $element['#' . $extra_key] = $extra[$extra_key] ?? $extra_default;
    }
    
    if ($settings == FALSE || !isset($settings['contact_type'])) {
      throw new MigrateSkipRowException("Failed to find contact type from D7 Webform CiviCRM Settings");
    }
    $element['#contact_type'] = $settings['contact_type'];
    if (!empty($settings['contact_sub_type'])) {
      $element['#contact_sub_type'] = [];
      foreach($settings['contact_sub_type'] as $sub_type_key => $sub_type_val) {
        $element['#contact_sub_type'][strtolower($sub_type_key)] =  strtolower($sub_type_val);
      }
    }
    return $element;
  }

  /**
   * Gets the extra data associated with a webform component.
   *
   * @param int $nid Node Id in D7
   * @param string $form_key Form Key of component - should be the same as in d7 as in d9.
   *
   * @returns array Unserialized data from d7 database.
   */
  private static function getWebformComponentExtraData(int $nid , string $form_key){
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $qry_str = "select extra from {webform_component} where nid = :nid and form_key = :form_key";
    $query = $db->query($qry_str, [
      ':nid' => $nid,
      ':form_key' => $form_key
    ]
    );
    if ($query->rowCount > 1) {
      throw new MigrateSkipRowException("Expected one row per nid in webform_civicrm_forms got many for nid" . var_export($nid, TRUE));
    }
    $result = $query->fetch();
    return unserialize($result->extra); // We only have one row per node.  
  }


  /**
   * Get the Webform CiviCRM specific data from webform_civicrm_forms
   * table.
   *
   * @param int nid Node of webform to retrieve
   *
   * @returns array Unserialized data from d7 database.
   */
  private static function getWebformCiviCRMData(int $nid) {
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $query = $db->query("select nid, data, prefix_known, prefix_unknown, message, confirm_subscription, block_unknown_users, create_new_relationship, create_fieldsets, new_contact_source from {webform_civicrm_forms} where nid = " . $nid );
    
    if ($query->rowCount > 1) {
      throw new MigrateSkipRowException("Expected one row per nid in webform_civicrm_forms got many for nid" . var_export($nid, TRUE));
    }
    return unserialize($query->fetch()->data); // We only have one row per node.
  }

  private static function getWebformCiviCRMSettings($row) {
    $nid = WebformCivicrmMigrateSubscriber::getNid($row);
    return WebformCivicrmMigrateSubscriber::getWebformCiviCRMData($nid);    
  }

  private static function getNid($row) {
    $nid = $row->get('nid');
    if (!is_numeric($nid)) {
      throw new MigrateSkipRowException("Expected numeric nid got something that's not an in" . var_export($nid, TRUE));
    }
    return $nid;
  }
  
  /**
   * Recursive function which does a depth first search through
   * $element and applies relevant CiviCRM Webform Migrations to
   * elements it finds. Stops when finds no children.
   *
   * @param array $element - Array Webform 
   * @param array $d7_form_settings - The form settings from the D7 db.
   * @param int $nid NID of webform on D7
   *
   * @return array
   *  The processed element.
   */
  private function migrateWebformElement(array $element, array $d7_form_settings, int $nid) {
    // First we check for children and process them.
    if ( WebformElementHelper::hasChildren($element)) {
      # Deeper we must go
      foreach($element as $key => $child_element) {
        if ($key === '' || $key[0] !== '#') {
          $element[$key] = $this->migrateWebformElement($child_element, $d7_form_settings, $nid);
        }
      }
    }

    if (substr($element['#form_key'],0,7) != 'civicrm') {
      # Not a CiviCRM field. run away.
      return $element;
    }    

    switch ($element['#type']){
    case 'civicrm_contact':
      $element = $this->migrateWebformElementCiviCRMContact($element, $d7_form_settings, $nid);
      break;
    case 'fieldset':
      unset($element['#open']);
      break;
    default:
      break;
    }
    return $element;
  }

  /**
   * Ensure that CiviCRM is initiallised before we start if this is a
   * webform_civicrm_migration.
   *
   * @param Drupal\migrate\Event\MigrateImportEvent $row
   *
   */
  public function onPreImport(MigrateImportEvent $event) {
    // Migration object about to begin import.
    $migration = $event->getMigration();
    $migration_id = $migration->id();
    switch($migration_id) {
    case 'upgrade_d7_webform':
    case 'upgrade_d7_webform_submissions':
      \Drupal::service('civicrm')->initialize();
      break;
    }
  }

  /**
   * Catch onPostImport Event.
   *
   * @param Drupal\migrate\Event\MigrateImportEvent $row
   *   
   */
  public function onPostImport(MigrateImportEvent $event) {
    // Migration object about to begin import.
    $migration = $event->getMigration();
    $migration_id = $migration->id(); //
    $migration_map = $migration->getIdMap();
    switch($migration_id) {
    case 'upgrade_d7_webform':
      $webforms = WebformCivicrmMigrateSubscriber::getAllWebforms();
      foreach($webforms as $webform) {

        $webform_migration = $migration_map->getRowByDestination(['id' => $webform->get('id')]);
        if (empty($webform_migration)) {
          # no migration mapping found - probably not a migrated webform! hopefully.
          continue;
        }        
        $this->addCiviCRMHandler($webform, $webform_migration['sourceid1']);
      }        
    }
  }


  /**
   * React to a new row of type webform.
   *
   * @param \Drupal\migrate\Row $row
   *   The prepare-row event.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  public function migrateWebform(\Drupal\migrate\Row $row) {
    // Fields come in as a yaml string - convert to Array then
    $data = $this->getWebformCiviCRMSettings($row);

    $elements = WebformYaml::decode($row->get('elements'));
    foreach($elements as $key => $element) {
      $elements[$key] = $this->migrateWebformElement($element, $data, WebformCivicrmMigrateSubscriber::getNid($row));
    }
    var_export($elements);

    $row->setSourceProperty('elements', $elements);
 }

  public function migrateCiviCRMData($data) {
    $data['number_of_contacts']  = count($data['contact']);
    if (!empty($data['contact'])) {
      foreach($data['contact'] as $c => &$contact_data) {
        if (!empty($contact_data['contact'])) {
          foreach($contact_data['contact'] as $d => &$contact_data_inner) {            
            $data[$c . '_' . 'contact_type'] = $contact_data_inner['contact_type'] ?? '';
            $data[$c . '_' . 'webform_label'] = $contact_data_inner['webform_label'] ?? '';
            $data['civicrm_' . $c . '_contact_' . $d .  '_contact_contact_sub_type' ] = [];
            if (!empty($contact_data_inner['contact_sub_type'])) {
              foreach($contact_data_inner['contact_sub_type'] as $k => $v ) {
                # Lowercase because reasons!!
                $data['civicrm_' . $c . '_contact_' . $d . '_contact_contact_sub_type' ][strtolower($k)] = strtolower($v);
                $contact_data_inner['contact_sub_type'] = [
                  strtolower($k) => strtolower($v),
                ];
              }
            }
          }
        }
      }
    }
    return $data;
  }

  public function addCiviCRMHandler($webform, $nid) {
    $elements = (array) $webform->getElementsInitializedAndFlattened();
    $hasCivi = FALSE;
    foreach (array_keys($elements) as $element) {
      if (strpos($element, 'civicrm') !== false) {
        $hasCivi = TRUE;
        break;
      }
    }
    if (!$hasCivi) {
      return;
    }

    $webformData = WebformCivicrmMigrateSubscriber::getWebformCiviCRMData($nid);
    $manager = \Drupal::service('plugin.manager.webform.handler');
    $handler = $manager->createInstance('webform_civicrm');


    // Save a webform handler so we get default options.
    $handler->setWebform($webform);
    $handler->setHandlerId('webform_civicrm');
    $handler->setStatus(TRUE);
    $webform->addWebformHandler($handler);
    $webform->save();

    $handler = $webform->getHandlers('webform_civicrm');
    $config = $handler->getConfiguration();
    $config['webform_civicrm']['settings']['data'] = $this->migrateCiviCRMData($webformData);
    $required_keys = [
      'block_unknown_users',
      'prefix_unknown',
      'prefix_known',
      'message',
      'block_unknown_users'
    ];
    foreach($required_keys as $required_key ) {
      if (empty($config['webform_civicrm']['settings'][$required_key])) {
        $config['webform_civicrm']['settings'][$required_key] = '';
      }
    }
    $handler->setConfiguration($config);
    $webform->save();

  }

  /**
   * React to a new row.
   *
   * @param \Drupal\migrate_plus\Event\MigratePrepareRowEvent $event
   *   The prepare-row event.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  public function onPrepareRow(MigratePrepareRowEvent $event) {

    $migration = $event->getMigration();
    $row = $event->getRow();
    $migration_id = $migration->id();

    // First check migration ids for exact matches
    // - then we look for pattern matches
    switch($migration_id) {
    case 'upgrade_d7_webform':
      $this->migrateWebform($row);
      break;
    }
  }


}
