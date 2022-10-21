<?php

namespace Drupal\trc_node_loader\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Class InspectController.
 */
class InspectController extends ControllerBase
{

  /**
   * Inspect.
   *
   * @return string
   *   Return Hello string.
   */


  public function inspect()
  {
    $groups = \Drupal::entityTypeManager()->getStorage('group')->loadByProperties(['status' => 1]);
    /** @var \Drupal\group\Entity\GroupInterface $group */
    foreach ($groups as $group_id => $group) {
      //$storage = \Drupal::entityTypeManager()->getStorage('group_content');
      /** @var \Drupal\group\Entity\Storage\GroupContentStorage $storage */
      $group_contents = $group->getContent();
      $group_uuid = $group->get('uuid')->getString();
      foreach ($group_contents as $c) {
        $type = $c->getGroupContentType()->getContentPlugin()->getBaseId();
        if ($type != 'group_node') continue;
        echo ($type) . "<br/>";
        $node = $c->getEntity();
        /** @var \Drupal\group\Entity\Storage\Node $node */
        $node_uuid = $node->get('uuid')->getString();
        echo "$group_uuid <=> $node_uuid <br />";
      }
    }
    
    die();
  }


  /* This was used to test loading node from yamls and saving it with translations 
   public function inspect() {
    $node = \Drupal::entityTypeManager()->getStorage('node')->load(1400);
    $yaml = Yaml::parse(file_get_contents('/home/marekt/trc/sync/entities/node/research_material/node.research_material.d16d8663-db3d-4248-b5fe-78dd6eacae51.yml'));
    dump($node);
    dump($yaml);
    $node_data = $this->yamlToNodeData($yaml);
    
    unset($node_data['uuid']);
    $node_data['title'][0]['value'] = 'Copy of ' . $node_data['title'][0]['value'];
    $new_node = \Drupal::entityTypeManager()->getStorage('node')->create($node_data);
    if (array_key_exists('_translations',$yaml)) {
      foreach ($yaml['_translations'] as $lang => $translation_data) {
        $node_translation_data = $this->yamlToNodeData($translation_data);
        $node_translation_data['title'][0]['value'] = 'Copy of ' . $node_translation_data['title'][0]['value'];
        unset($node_translation_data['uuid']);
        $new_node->addTranslation($lang, $node_translation_data);
      }
    }
    $return = $new_node->save();
    die("koko");
    return [
      '#type' => 'markup',
      '#markup' => $this->t('Implement method: inspect')
    ];
  }
  */

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

  // Returns the entity ID or 0
  private function uuidToID(string $target_type, string $target_uuid)
  {
    $entity_loaded_by_uuid = $entity = \Drupal::service('entity.repository')->loadEntityByUuid($target_type, $target_uuid);
    return $entity->id();
  }

  /* Try to guess what kind of field this is in order to reformat it correctly for node::create */
  private function inferFieldType($yaml_key, $yaml_value): string
  {
    if (substr($yaml_key, 0, 1) === "_") return 'special';
    if (array_key_exists(0, $yaml_value) && array_key_exists('target_uuid', $yaml_value[0])) return 'entity_reference';
    if ($yaml_key == 'created' || $yaml_key == 'changed') return 'timestamp';
    return 'other';
  }
}
