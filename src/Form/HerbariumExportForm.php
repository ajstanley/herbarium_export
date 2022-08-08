<?php

namespace Drupal\herbarium_export\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HerbariumExportForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * Islandora utils.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected $utils;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->fileSystem = $container->get('file_system');
    $instance->utils = $container->get('islandora.utils');
    $instance->pathfinder = $container->get('extension.list.module');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'herbarium_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = [];
    $vid = 'institutional_collection';
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($vid);
    foreach ($terms as $term) {
      $options[$term->tid] = $term->name;
      $term_data[] = [
        'id' => $term->tid,
        'name' => $term->name,
      ];
    }


    $form_state->setStorage(['built' => TRUE]);
    $form_state->setStorage(['options' => $options]);
    $form['collection'] = [
      '#type' => 'select',
      '#title' => $this->t('Collection'),
      '#description' => $this->t('Enter collection'),
      '#options' => $options,
      '#weight' => '0',
    ];


    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Download CSV',

    ];
    return $form;
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $collection = $form_state->getValue('collection');
    $options = $form_state->getStorage('options');
    $name = $options['options'][$collection];
    $filename = "{$name}_herbarium_export.csv";
    $this->build_csv($collection, $filename);
    $path = "public://export/{$filename}";
    $headers = [
      'Content-Type' => 'text/csv',
      'Content-Description' => 'File Download',
      'Content-Disposition' => 'attachment; filename=' . $filename,
    ];

    $form_state->setRedirect('herbarium_routing.csv_export_form');
    $form_state->setResponse(new BinaryFileResponse($path, 200, $headers, TRUE));
  }

  /**
   * Builds and saves csv
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function build_csv($collection, $filename) {
    $content_type = 'darwin_core_herbarium';
    $headers = ['catalognumber', 'originalurl', 'url', 'thumbnail'];
    $full_file = "public://export/{$filename}";
    $dest_dir = 'public://export/';

    if (!$this->fileSystem->prepareDirectory($dest_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, "The destination directory does not exist, could not be created, or is not writable");
    }

    // Get tids for media use.
    $original = array_key_first($this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => 'Original File',
        'vid' => 'islandora_media_use',
      ]));
    $service = array_key_first($this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => 'Service File',
        'vid' => 'islandora_media_use',
      ]));
    $thumbnail = array_key_first($this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => 'Thumbnail Image',
        'vid' => 'islandora_media_use',
      ]));

    // Open file
    $fp = fopen($full_file, 'w');
    fputcsv($fp, $headers);

    $nids = \Drupal::entityQuery('node')
      ->condition('type', $content_type)
      ->condition('field_institutional_collection', $collection)
      ->range(0, 1000)
      ->execute();
    $chunked_nodes = \array_chunk($nids);
    foreach ($chunked_nodes as $nids) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $catalog_number = $node->get('field_catalognumber')->value;
        $media = $this->utils->getMedia($node);
        foreach ($media as $medium) {
          $type = $medium->get('field_media_use')->getValue();
          if ($type[0]['target_id'] == $original) {
            $fid = $medium->getSource()->getSourceFieldValue($medium);
            $file = File::load($fid);
            $original_uri = $file->createFileUrl(FALSE);
          }
          if ($type[0]['target_id'] == $service) {
            $fid = $medium->getSource()->getSourceFieldValue($medium);
            $file = File::load($fid);
            $service_uri = $file->createFileUrl(FALSE);
          }
          if ($type[0]['target_id'] == $thumbnail) {
            $fid = $medium->getSource()->getSourceFieldValue($medium);
            $file = File::load($fid);
            $thumbnail_uri = $file->createFileUrl(FALSE);
          }
        }
        if ($original_uri || $service_uri || $thumbnail_uri) {
          fputcsv($fp, [
            $catalog_number,
            $original_uri,
            $service_uri,
            $thumbnail_uri,
          ]);
        }
      }
    }
    fclose($fp);
  }


}
