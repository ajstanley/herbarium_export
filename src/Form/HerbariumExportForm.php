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

    $form_state->setStorage(['built' => TRUE]);

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
    $this->build_csv();
    $filename = 'herbarium_export.csv';
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
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function build_csv() {
    $filename = 'herbarium_export.csv';
    $content_type = 'darwin_core_herbarium';
    $headers = ['Catalog Number', 'File URL'];
    $full_file = "public://export/{$filename}";
    $dest_dir = 'public://export/';

    if (!$this->fileSystem->prepareDirectory($dest_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, "The destination directory does not exist, could not be created, or is not writable");
    }
    $fp = fopen($full_file, 'w');
    fputcsv($fp, $headers);
    $service = array_key_first($this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => 'Service File',
        'vid' => 'islandora_media_use',
      ]));

    $nids = \Drupal::entityQuery('node')
      ->condition('type', $content_type)
      ->execute();
    $nodes = Node::loadMultiple($nids);
    foreach ($nodes as $node) {
      $media = $this->utils->getMedia($node);
      foreach ($media as $medium) {
        $type = $medium->get('field_media_use')->getValue();
        if ($type[0]['target_id'] == $service) {
          $fid = $medium->getSource()->getSourceFieldValue($medium);
          $file = File::load($fid);
          $uri = $file->createFileUrl(FALSE);
          $catalog_number = $node->get('field_catalognumber')->value;
          fputcsv($fp, [$catalog_number, $uri]);

        }
      }
    }
    fclose($fp);
  }

}
