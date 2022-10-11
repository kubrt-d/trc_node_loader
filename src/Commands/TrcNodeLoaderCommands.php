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
class TrcNodeLoaderCommands extends DrushCommands {

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
  public function loadnodes( $options = ['option-name' => 'default']) {
    global $content_directories;
    $files = $this->getDirContents($content_directories['sync'] . '/entities/node');
    if (empty($files)) {
      $this->logger()->failure(dt('No nodes found'));  
    } else {
      foreach ($files as $yaml_file)  {
        $result = $this->importNodeFromYAML($yaml_file);
        if ($result == 1) {
          $this->logger()->success(dt('Imported as new ' . basename($yaml_file)));    
        } elseif  ($result == 2) {
          $this->logger()->success(dt('Imported as an update ' . basename($yaml_file)));    
        } else {
          $this->logger()->failure(dt('Failed to import ' . basename($yaml_file)));    
        }
      }
    }
  }

  private function importNodeFromYAML(string $yaml_file):bool {
    $yaml = Yaml::parse(file_get_contents($yaml_file));
    $node_data = $this->yamlToNodeData($yaml);
    $new_node = \Drupal::entityTypeManager()->getStorage('node')->create($node_data);
    if (array_key_exists('_translations',$yaml)) {
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

  private function yamlToNodeData ($yaml):array {
    $node_data = [];
    foreach ($yaml as $yaml_key=>$yaml_value) {
      $field_type = $this->inferFieldType($yaml_key,$yaml_value);
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

  private function dereference(array $refs):array {
    $out = [];
    foreach ($refs as $key=>$ref) {
      $dereferenced_reference = $ref;
      $target_type = $ref['target_type'];
      unset ($dereferenced_reference['target_type']);
      $target_uuid = $ref['target_uuid'];
      unset ($dereferenced_reference['target_uuid']);
      $dereferenced_reference['target_id'] = $this->uuidToID($target_type,$target_uuid);
      $out[$key] = $dereferenced_reference;
    }
    return $out;
  } 

  // Returns the entity ID or NULL
  private function uuidToID(string $target_type, string $target_uuid) {
    $entity = \Drupal::service('entity.repository')->loadEntityByUuid($target_type, $target_uuid);
    if ($entity) {
      return $entity->id();
    } else {
      $this->logger()->warning(dt("Can't derefrence uuid $target_uuid, type $target_type"));  
      return null;
    }
  }

  /* Try to guess what kind of field this is in order to reformat it correctly for node::create */
  private function inferFieldType($yaml_key,$yaml_value):string {
    if (substr( $yaml_key, 0, 1 ) === "_") return 'special';
    if (array_key_exists(0,$yaml_value) && array_key_exists('target_uuid',$yaml_value[0])) return 'entity_reference';
    if ($yaml_key == 'created' || $yaml_key == 'changed') return 'timestamp';
    return 'other';
  }

  private function getDirContents($path) {
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
  public function token($options = ['format' => 'table']) {
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
