<?php

namespace Drupal\trc_node_loader\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;



/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class TrcNodeLoaderCommands extends DrushCommands
{
  /**
   * Exports nodes including special fields (like layout builder) 
   *
   * @param string $uuid
   *   An associative array of options whose values come from cli, aliases, config, etc.
   * @option option-name
   *   Description
   * @usage trc_node_loader-specialexport tgse 
   *   Usage description
   *
   * @command trc_node_loader:specialexport
   * @aliases tgse
   */
  public function specialexport($uuid='',$options = ['option-name' => 'default'])
  {
    $logger = $this->logger();
    /** @var Drush\Log\Logger $logger*/
    global $content_directories;
    if (!$uuid) {
      $logger->warning(dt('UUID must be provided '));
      return;
    }
    $node = reset(\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]));
    /** @var \Drupal\group\Entity\Node $node */
    if (!$bundle = @$node->bundle()) {
      $logger->warning(dt('Can\'t find node with UUID ' . $uuid));
      return;
    } 
    
    $serialized = serialize($node);
    
    if (!file_exists($content_directories['sync'] . '/special')) {
      mkdir($content_directories['sync'] . '/special');
    }
    file_put_contents($content_directories['sync'] . '/special/node.' . $bundle . '.' . $uuid . '.serialized', $serialized);
    $logger->success(dt('Exported node with UUID ' . $uuid));
  }

  /**
   * Exports nodes from the special directory
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   * @option option-name
   *   Description
   * @usage trc_node_loader-specialexport tgsi 
   *   Usage description
   *
   * @command trc_node_loader:specialexport
   * @aliases tgsi
   */
  public function specialimport($options = ['option-name' => 'default'])
  {
    $logger = $this->logger();
    /** @var Drush\Log\Logger $logger*/
    global $content_directories;

    if (!file_exists($content_directories['sync'] . '/special')) {
      $logger->warning(dt('Directory ' . $content_directories['sync'] . '/special does not exist ! Exiting.'));
      return;
    }
    $files = $this->getDirContents(($content_directories['sync'] . '/special'));
    foreach ($files as $file) {
      if (!strstr($file,'.serialized')) continue;
      $node=unserialize(file_get_contents($file));
      $node->save();
      $logger->success(dt('Saved node UUID ' . $node->uuid()));
    }
    
    return;
    $node = reset(\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]));
    /** @var \Drupal\group\Entity\Node $node */
    if (!$bundle = @$node->bundle()) {
      $logger->warning(dt('Can\'t find node with UUID ' . $uuid));
      return;
    } 
    
    $serialized = serialize($file_get_contents($yaml_file));
    
    if (!file_exists($content_directories['sync'] . '/special')) {
      mkdir($content_directories['sync'] . '/special');
    }
    file_put_contents($content_directories['sync'] . '/special/node.' . $bundle . '.' . $uuid . '.serialized', $serialized);
    $logger->success(dt('Exported node with UUID ' . $uuid));
  }

  /**
   * Exports node-group associations 
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   * @option option-name
   *   Description
   * @usage trc_node_loader-groupexport tge
   *   Usage description
   *
   * @command trc_node_loader:groupexport
   * @aliases tge
   */
  public function groupexport($options = ['option-name' => 'default'])
  {
    $logger = $this->logger();
    /** @var Drush\Log\Logger $logger*/
    global $content_directories;
    $files = $this->getDirContents($content_directories['sync'] . '/entities/node');
    $uuids = [];
    if (empty($files)) {
      $logger->failure(dt('No nodes found'));
      return;
    } else {
      foreach ($files as $yaml_file) {
        $node = $this->getNodeFromYAML($yaml_file);
        if (!$node) {
          $logger->failure(dt('Failed to import ' . basename($yaml_file)));
          continue;
        }
        $uuid = @$node['uuid'][0]['value'];
        if (!$uuid) {
          $logger->failure(dt('No UUID in ' . basename($yaml_file)));
          continue;
        }
        $uuids[] = $uuid;
        $logger->success(dt('Captured group associations for ' . $uuid));
      }
    }
    $out = [];
    $groups = \Drupal::entityTypeManager()->getStorage('group')->loadByProperties(['status' => 1]);
    /** @var \Drupal\group\Entity\GroupInterface $group */
    foreach ($groups as $group_id => $group) {
      /** @var \Drupal\group\Entity\Storage\GroupContentStorage $storage */
      $group_contents = $group->getContent();
      $group_uuid = $group->get('uuid')->getString();
      foreach ($group_contents as $c) {
        $type = $c->getGroupContentType()->getContentPlugin()->getBaseId();
        if ($type != 'group_node') continue;
        $node = $c->getEntity();
        /** @var \Drupal\group\Entity\Storage\Node $node */
        $node_uuid = $node->get('uuid')->getString();
        if (in_array($node_uuid, $uuids)) {
          $out[] = ['node_uuid' => $node_uuid, 'group_uuid' => $group_uuid];
        }
      }
    }
    $yaml = Yaml::dump($out);
    if (!file_exists($content_directories['sync'] . '/group')) {
      mkdir($content_directories['sync'] . '/group');
    }
    file_put_contents($content_directories['sync'] . '/group/node_group.yaml', $yaml);
  }

  /**
   * Imports node-group associations 
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   * @option option-name
   *   Description
   * @usage trc_node_loader-groupimport tgi
   *   Usage description
   *
   * @command trc_node_loader:groupimport
   * @aliases tgi
   */
  public function groupimport($options = ['option-name' => 'default'])
  {
    $plugin_manager = \Drupal::service('plugin.manager.group_content_enabler');
    $logger = $this->logger();
    /** @var Drush\Log\Logger $logger*/
    global $content_directories;
    $associations = $this->getNodeFromYAML($content_directories['sync'] .  '/group/node_group.yaml');
    if (!is_array($associations)) {
      $logger->warning(dt('Failed to read ' . $content_directories['sync'] .  '/group/node_group.yaml'));
      return;
    }
    $groups_content = [];
    /* Collate them together by group */
    foreach ($associations as $association) {
      $groups_content[$association['group_uuid']][] = $association['node_uuid'];
      $logger->success(dt('Importing group ' . $association['group_uuid'] . ' associations for node ' . $association['node_uuid']));
    }
    foreach ($groups_content as $group_uuid => $node_uuids) {
      /** @var \Drupal\group\Entity\Group $group */
      $group = reset(\Drupal::entityTypeManager()->getStorage('group')->loadByProperties(['uuid' => $group_uuid]));
      foreach ($node_uuids as $node_uuid) {
        $node = reset(\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $node_uuid]));
        /** @var \Drupal\Core\Entity\ContentEntityInterface $node */
        $node_type = $node->get('type')->getString();
        $group->addContent($node, 'group_node:' . $node_type);
      }
    }
    return;
  }

  /**
   * Command description here.
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   * @option option-name
   *   Description
   * @usage trc_node_loader-loadnodes tnl
   *   Usage description
   *
   * @command trc_node_loader:loadnodes
   * @aliases tnl
   */
  public function loadnodes($options = ['option-name' => 'default'])
  {
    global $content_directories;
    $logger = $this->logger();
    /** @var Drush\Log\Logger $logger*/
    $files = $this->getDirContents($content_directories['sync'] . '/entities/node');
    if (empty($files)) {
      $logger->failure(dt('No nodes found'));
    } else {
      foreach ($files as $yaml_file) {
        $result = $this->importNodeFromYAML($yaml_file);
        if ($result == 1) {
          $logger->success(dt('Imported as new ' . basename($yaml_file)));
        } elseif ($result == 2) {
          $logger->success(dt('Imported as an update ' . basename($yaml_file)));
        } else {
          $logger->failure(dt('Failed to import ' . basename($yaml_file)));
        }
      }
    }
  }

  private function getNodeFromYAML(string $yaml_file)
  {
    if (is_file($yaml_file)) {
      $yaml = Yaml::parse(file_get_contents($yaml_file));
      return $yaml;
    }
  }

  private function importNodeFromYAML(string $yaml_file): bool
  {
    $yaml = $this->getNodeFromYAML($yaml_file);
    $node_data = $this->yamlToNodeData($yaml);
    $new_node = \Drupal::entityTypeManager()->getStorage('node')->create($node_data);
    /** @var \Drupal\Core\Entity\ContentEntityBase $new_node  */
    if (array_key_exists('_translations', $yaml)) {
      foreach ($yaml['_translations'] as $lang => $translation_data) {
        $node_translation_data = $this->yamlToNodeData($translation_data);
        $new_node->addTranslation($lang, $node_translation_data);
      }
    }
    try {
      $new_node->save();
    } catch (Exception $e) {
      return false;
    }
    return $new_node->save();
  }

  private function yamlToNodeData($yaml): array
  {
    $node_data = [];
    foreach ($yaml as $yaml_key => $yaml_value) {
      $field_type = $this->inferFieldType($yaml_key, $yaml_value);
      switch ($field_type) {

        case 'special': {
            // Ignore the field
            break;
          }

        case 'entity_reference': {
            // Translate UUID to the actual ID and alter the value format 
            $value = $this->dereference($yaml_value);
            $node_data[$yaml_key] = $value;
            break;
          }

        case 'timestamp': {
            $node_data[$yaml_key] = strtotime($yaml_value[0]['value']);
            break;
          }

        case 'other': {
            // Do nothing 
            $node_data[$yaml_key] = $yaml_value;
          }
      }
    }
    return $node_data;
  }

  private function dereference(array $refs): array
  {
    $out = [];
    foreach ($refs as $key => $ref) {
      $dereferenced_reference = $ref;
      $target_type = $ref['target_type'];
      unset($dereferenced_reference['target_type']);
      $target_uuid = $ref['target_uuid'];
      unset($dereferenced_reference['target_uuid']);
      $dereferenced_reference['target_id'] = $this->uuidToID($target_type, $target_uuid);
      $out[$key] = $dereferenced_reference;
    }
    return $out;
  }

  // Returns the entity ID or NULL
  private function uuidToID(string $target_type, string $target_uuid)
  {
    $entity = \Drupal::service('entity.repository')->loadEntityByUuid($target_type, $target_uuid);
    if ($entity) {
      return $entity->id();
    } else {
      $this->logger()->warning(dt("Can't derefrence uuid $target_uuid, type $target_type"));
      return null;
    }
  }

  /* Try to guess what kind of field this is in order to reformat it correctly for node::create */
  private function inferFieldType($yaml_key, $yaml_value): string
  {
    if (substr($yaml_key, 0, 1) === "_") return 'special';
    if (array_key_exists(0, $yaml_value) && array_key_exists('target_uuid', $yaml_value[0])) return 'entity_reference';
    if ($yaml_key == 'created' || $yaml_key == 'changed') return 'timestamp';
    return 'other';
  }

  private function getDirContents($path)
  {
    $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

    $files = array();
    foreach ($rii as $file)
      if (!$file->isDir())
        $files[] = $file->getPathname();

    return $files;
  }

  /**
   * An example of the table output format.
   *
   * @param array $options An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @field-labels
   *   group: Group
   *   token: Token
   *   name: Name
   * @default-fields group,token,name
   *
   * @command trc_node_loader:token
   * @aliases token
   *
   * @filter-default-field name
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   */
  public function token($options = ['format' => 'table'])
  {
    $all = \Drupal::token()->getInfo();
    foreach ($all['tokens'] as $group => $tokens) {
      foreach ($tokens as $key => $token) {
        $rows[] = [
          'group' => $group,
          'token' => $key,
          'name' => $token['name'],
        ];
      }
    }
    return new RowsOfFields($rows);
  }
}
