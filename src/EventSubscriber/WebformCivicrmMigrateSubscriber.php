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
    return TRUE;
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
    return TRUE;
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
    return TRUE;
  }


  /**
   * Helper function to get all Webforms.
   *
   * @return array An Array of webforms
   */
  public static function getAllWebforms() {
    $webform_ids = \Drupal::entityQuery('webform')
                 ->execute();
    $webforms = [];

    foreach($webform_ids as $id => $title) {
      $webforms[] = Webform::load($id);
    }
    return $webforms;
  }

  /**
   * Helper function to get a setting from a settings array based on
   * key passed in.
   *
   * @param string $key in form civicrm_n_ent_m_id
   * @param array $array Settings array in format $array[entity][n][ent][m]
   * @param string $entity which type of entity to look for.
   */
  public static function getSettingsFromArrayByKey(string $key, array $array,$entity='contact') {
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
   * Helper function to get the extra data associated with a webform
   * component from the webform_component table.
   *
   * @param int $nid Node Id in D7
   * @param string $form_key Form Key of component - should be the same as in d7 as in d9.
   *
   * @returns array Unserialized data from d7 database.
   */
  public static function getWebformComponentExtraData(int $nid , string $form_key){
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
   * Helper function to get the Webform CiviCRM specific data from
   * webform_civicrm_forms table.
   *
   * @param int nid Node of webform to retrieve
   *
   * @returns array Unserialized data from d7 database.
   */
  public static function getWebformCiviCRMData(int $nid) {
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $query = $db->query("select nid, data, prefix_known, prefix_unknown, message, confirm_subscription, block_unknown_users, create_new_relationship, create_fieldsets, new_contact_source from {webform_civicrm_forms} where nid = " . $nid );

    if ($query->rowCount > 1) {
      throw new MigrateSkipRowException("Expected one row per nid in webform_civicrm_forms got many for nid" . var_export($nid, TRUE));
    }
    return unserialize($query->fetch()->data); // We only have one row per node.
  }


  /**
   * Helper function - wrapper for getWebformCiviCRMData - takes a row not nid.
   */
  public static function getWebformCiviCRMSettings($row) {
    $nid = WebformCivicrmMigrateSubscriber::getNid($row);
    return WebformCivicrmMigrateSubscriber::getWebformCiviCRMData($nid);
  }


  /**
   * Helper function - takes a row returns the nid.
   */
  public static function getNid($row) {
    $nid = $row->get('nid');
    if (!is_numeric($nid)) {
      throw new MigrateSkipRowException("Expected numeric nid got something that's not an in" . var_export($nid, TRUE));
    }
    return $nid;
  }


  /**
   * Helper function to detect and fix a munged key.
   */
  public static function fixKey(string $key) {
    return preg_replace('/^(civicrm_.*)_[0-9]+$/', '$1', $key);
  }


  public static function getChildren(array $element) {
    return array_filter(array_keys($element),
                        function($key) {
                          return ($key === '' || $key[0] !== '#');
                        }
    );
  }

  /**
   * Helper function to find the type of an element
   */
  public static function fixElementType(array $element,  int $nid) {
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $qry_str = "select type from {webform_component} where nid = :nid and form_key = :form_key";
    $query = $db->query($qry_str, [
      ':nid' => $nid,
      ':form_key' => $element['#form_key'],
    ]
    );
    if ($query->rowCount > 1) {
      throw new MigrateSkipRowException("Expected one row per nid in webform_civicrm_forms got many for nid" . var_export($nid, TRUE));
    }
    $result = $query->fetch();
    return $result->type;

  }

  /**
   * Takes an element of type CiviCRM Contact gets settings based on
   * form key and extra data and returns result.
   *
   * @param array $element Array that describes a Webform Component missing CiviCRM specific data.
   * @param array $d7_form_settings Settings data associated with this webform.
   * @param int $nid  The node we are migrating.
   */
  public function migrateWebformElementCiviCRMContact(array $element,array $d7_form_settings,int $nid) {
    // Extract the relevant settings data from $d7_form_settings based
    // on the $element['#form_key'].
    $settings = WebformCivicrmMigrateSubscriber::getSettingsFromArrayByKey($element['#form_key'], $d7_form_settings, 'contact');

    // Get the Extra data from the d7 database.  This is data in the
    // webform_civicrm specific table - some of which now gets saved
    // on the element.
    $extra = WebformCivicrmMigrateSubscriber::getWebformComponentExtraData($nid, $element['#form_key']);

    /* Adapted from Webform CiviCRM ->
       https://github.com/colemanw/webform_civicrm/blob/fe2258884c9c741aed65665cb3cc8bc73fd75248/src/Plugin/WebformElement/CivicrmContact.php#L31
       These have been ordered to hopefully reduce ordering changes
       when form is edited and saved - so include some things that we
       later override like contact type and sub type.

       We add defaults and override them if specific values are
       present in the source webform.

       Ideally this would be generated by calling a Webform CiviCRM
       method so if new defaults get added or changed we would pull
       this in automatically.
    */
    $defaults = [
      'type' => 'civicrm_contact',
      'title' => 'Existing Contact',
      'widget' =>  'autocomplete',
      'none_prompt' => '',
      'show_hidden_contact' => '',
      'results_display' => ['display_name' => 'display_name'],
      'no_autofill' => ['' => ''],
      'hide_fields' => ['' => ''],
      'default' => '',
      'contact_sub_type' => '',
      'allow_create' => 0,
      'contact_type' => 'individual',
      'name' => 'Existing Contact',
      'search_prompt' => '',
      // Below this line ordering hasn't been tested yet and are
      // ordered as per webform_civicrm.
      'show_hidden_contact' => 0,
      'hide_method' => 'hide',
      'no_hide_blank' => FALSE,
      'submit_disabled' => FALSE,
      'private' => FALSE,
      'default_contact_id' => '',
      'default_relationship_to' => '',
      'default_relationship' => '',
      'allow_url_autofill' => TRUE,
      'dupes_allowed' => FALSE,
      'filter_relationship_types' => ['' => ''],
      'filter_relationship_contact' => ['' => ''],
      'group' => ['' => ''],
      'tag' => ['' => ''],
      'check_permissions' => 1,
      'expose_list' => FALSE,
      'empty_option' => '',
    ];


    // Override defaults with values from extra if they exist.
    foreach($defaults as $key => $default) {
      $element['#' . $key] = $extra[$key] ?? $default;
    }
    // Check for any keys in extra that we don't have defaults for, or
    // that are arrays.
    foreach($extra as $key => $value) {
      if (empty($key)) {
        continue;
      }


      if (empty($defaults[$key]) && !is_array($value) ) {
        $element['#' . $key ] = $value;
      }

      // D8+ webform data is stored flat - not so on d7.
      if (is_array($value)) {
        foreach($value as $k => $v) {
          if (empty($k)) {
            continue;
          }
          // @todo investigate what is going on here but can't see where this is set in the UI.
          if ($k == 'results_display') {
            $element['#results_display'] = [$v => $v];
          }
          $element['#' . $k] = $v;
        }
      }
    }
    if (empty($element['#contact_sub_type'])) {
      unset($element['#contact_sub_type']);
    }
    return $element;
  }


  /**
   * Recursive function which does a depth first search through
   * $element and applies relevant CiviCRM Webform Migrations to
   * elements it finds. Stops when finds no children.
   *
   * This function should be called once for each element on the
   * webform.
   *
   * Nested elements are called via recursive call from their
   * parents.
   *
   * Non-CiviCRM elements are called - and checked for having
   * children which are CiviCRM elements, otherwise no changes are
   * made in that case.
   *
   * @param array $element - Array Webform
   * @param array $d7_form_settings - The form settings from the D7 db.
   * @param int $nid NID of webform on D7
   *
   * @return array
   *  The processed element.
   */
  public function migrateWebformElement(array $element, array $d7_form_settings, int $nid) {
    if (empty($element['#type'])) {
      $element['#type'] = WebformCivicrmMigrateSubscriber::fixElementType($element, $nid);
    }

    # Check for children and process them.
    if ( WebformElementHelper::hasChildren($element)) {

      # Children are saved alongside properties - properties have
      # leading '#' in key.
      $children = WebformCivicrmMigrateSubscriber::getChildren($element);
      # Children might have CiviCRM elements call this function on
      # each child. Note we also 'fix' any changes to CiviCRM keys
      # here.
      foreach($children as $key) {

        # Strip off key that was added by webform_migrate to "uniquify
        # the id" - we only want to do this if we have a civicrm with
        # appended _[0-9].
        $new_key = WebformCivicrmMigrateSubscriber::fixKey($key);
        # Copy child to new key, recurse and delete broken key
        # version.
        $child_element = $element[$key];
        $child_element['#form_key'] = $new_key;
        $element[$new_key] = $this->migrateWebformElement($child_element, $d7_form_settings, $nid);
        # Unset to remove the old element to prevent double ups.
        if ($new_key != $key) {
          unset($element[$key]);
        }
      }
    }
    # We are only acting on a CiviCRM Element.
    if (substr($element['#form_key'], 0, 7) != 'civicrm') {
      # Not a CiviCRM field. run away.
      return $element;
    }

    # We have a CiviCRM form element call relevant Function to
    # populate extra data.
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
   * Migrates a webform.
   *
   * @param \Drupal\migrate\Row $row
   *   The prepare-row event.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  public function migrateWebform(\Drupal\migrate\Row $row) {
    # Fields come in as a yaml string - convert to Array then
    $data = $this->getWebformCiviCRMSettings($row);

    // If no CiviCRM data then we can skip this form as it doesn't
    // have any CiviCRM components.
    if (empty($data)) {
      return;
    }

    $elements = WebformYaml::decode($row->get('elements'));
    $root_elements = WebformCivicrmMigrateSubscriber::getChildren($elements);

    foreach($root_elements as $key) {
      $element = $elements[$key];
      $element['#form_key'] = $key; # This should be populated - but isn't here yet?
      $elements[$key] = $this->migrateWebformElement($element, $data, WebformCivicrmMigrateSubscriber::getNid($row));
    }

    $row->setSourceProperty('elements', $elements);
  }

  public function migrateCiviCRMSettings($data, $default_settings) {
    $settings = $default_settings;
    $settings['number_of_contacts']  = count($data['contact']);

    if (!empty($data['contact'])) {
      foreach($data['contact'] as $c => &$contact_data) {
        if (!empty($contact_data['contact'])) {
          foreach($contact_data['contact'] as $d => &$contact_data_inner) {
            $settings[$c . '_' . 'contact_type'] = $contact_data_inner['contact_type'] ?? '';
            $settings[$c . '_' . 'webform_label'] = $contact_data_inner['webform_label'] ?? '';
            $settings['civicrm_' . $c . '_contact_' . $d .  '_contact_contact_sub_type' ] = [];
            if (!empty($contact_data_inner['contact_sub_type'])) {
              foreach($contact_data_inner['contact_sub_type'] as $k => $v ) {
                $settings['civicrm_' . $c . '_contact_' . $d . '_contact_contact_sub_type' ][strtolower($k)] = strtolower($v);
                # Lowercase because reasons!!
                $contact_data_inner['contact_sub_type'] = [
                  strtolower($k) => strtolower($v),
                ];
              }
            }
          }
        }
      }
    }
    $settings['data'] = $data;
    return $settings;
  }


  /**
   * Adds a CiviCRM handler to a webform based on source webform
   * settings.
   *
   * @param \Drupal\webform\Entity\Webform $webform
   * @param int nid
   */
  public function addCiviCRMHandler(Webform $webform, int $nid) {

    # First we confirm we have a CiviCRM Webform to migrate.
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

    # Get the data from the source webform.
    $webformData = WebformCivicrmMigrateSubscriber::getWebformCiviCRMData($nid);

    # Create a handler
    $manager = \Drupal::service('plugin.manager.webform.handler');
    $handler = $manager->createInstance('webform_civicrm');

    # Save a webform handler (so we get default options?)
    $handler->setWebform($webform);
    $handler->setHandlerId('webform_civicrm');
    $handler->setStatus(TRUE);
    $webform->addWebformHandler($handler);
    $webform->save();

    $handler = $webform->getHandlers('webform_civicrm');
    $config = $handler->getConfiguration();
    $config['webform_civicrm']['settings'] = $this->migrateCiviCRMSettings($webformData, $default_settings);

    // Ensure that required keys are set to default if not present in
    // source webform.
    $required_keys = [
      'block_unknown_users'=> '',
      'prefix_unknown' => '',
      'prefix_known' => '',
      'message' => '',
      'block_unknown_users' => '',
    ];
    foreach($required_keys as $required_key => $default ) {
      if (empty($config['webform_civicrm']['settings'][$required_key])) {
        $config['webform_civicrm']['settings'][$required_key] = '';
      }
    }
    $handler->setConfiguration($config);
    $webform->save();

  }

}
