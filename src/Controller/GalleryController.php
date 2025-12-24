<?php

namespace Drupal\s3_gallery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Aws\S3\S3Client;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides route responses for the S3 Gallery module.
 */
class GalleryController extends ControllerBase {

  /**
   * Returns the title for the gallery pages.
   */
  public function getTitle($prefix = '') {
    if (empty($prefix)) {
      return 'Fotoboek';
    }
    $date = date_create(substr($prefix, 0, 8));
    $title = substr($prefix, 8);
    $displayText = $date ? date_format($date, "D j M Y") . " — " . $title : $prefix;
    return $displayText;
  }

  /**
   * Main Page (Gallery Overview)
   * This now returns the 'gallery_home' theme hook.
   */
  public function mainPage() {
    if (\Drupal::currentUser()->isAnonymous()) {
      return [
        '#markup' => t('Access denied. Please <a href="https://acdweb.nl/user/login">log in</a> to view this page.'),
        '#cache' => ['max-age' => 0],
      ];
    }

    try {
      $config = Settings::get('aws_s3');
      $s3 = new S3Client([
        'version' => 'latest',
        'region' => $config['region'],
        'credentials' => [
          'key'    => $config['key'],
          'secret' => $config['secret'],
        ],
      ]);

      $bucket = $config['bucket'];
      $years_data = $this->homePage($s3, $bucket);

      return [
        '#theme' => 'gallery_home',
        '#years' => $years_data,
        '#attached' => [
          'library' => [
            's3_gallery/fslightbox',
          ],
        ],
      ];
    } catch (\Exception $e) {
      \Drupal::logger('s3_gallery')->error('Error: @error', ['@error' => $e->getMessage()]);
      return ['#markup' => "Error: " . $e->getMessage()];
    }
  }

  /**
   * Photo Page (Album View)
   */
  public function myPage($prefix = '') {
    if (\Drupal::currentUser()->isAnonymous()) {
      return [
        '#markup' => t('Access denied. Please log in to view this page.'),
        '#cache' => ['max-age' => 0],
      ];
    }

    try {
      $config = Settings::get('aws_s3');
      $s3 = new S3Client([
        'version' => 'latest',
        'region' => $config['region'],
        'credentials' => [
          'key'    => $config['key'],
          'secret' => $config['secret'],
        ],
      ]);

      $bucket = $config['bucket'];
      $full_prefix = 'photos/' . urldecode($prefix);

      // If we are at the root, show the main gallery overview
      if ($full_prefix == 'photos/') {
        return $this->mainPage();
      } 

      // Otherwise, show the specific album
      $images = $this->photoPage($s3, $bucket, $full_prefix);
      return [
        '#theme' => 'album',
        '#images' => $images,
        '#attached' => [
          'library' => [
            's3_gallery/fslightbox',
          ],
        ],
      ];
      
    } catch (\Exception $e) {
      \Drupal::logger('s3_gallery')->error('Error: @error', ['@error' => $e->getMessage()]);
      return ['#markup' => "Error: " . $e->getMessage()];
    }
  }

  /**
   * Helper: Build the data array for the Gallery Overview
   */
  private function homePage($s3, $bucket) { 
    $contents = $s3->listObjectsV2([
      'Bucket' => $bucket,
      'Prefix' => 'photos/',
      'Delimiter' => '/',
    ]);

    $prefixes_by_year = [];

    if (isset($contents['CommonPrefixes'])) {
      foreach ($contents['CommonPrefixes'] as $commonPrefix) {
        $prefix = $commonPrefix['Prefix'];
        $year = substr($prefix, 7, 4); // Extract YYYY from 'photos/YYYY...'
        
        if (!isset($prefixes_by_year[$year])) {
          $prefixes_by_year[$year] = [];
        }

        // Get a preview image from inside this folder
        $preview_image = '';
        $album_contents = $s3->listObjectsV2([
          'Bucket' => $bucket,
          'Prefix' => $prefix,
          'MaxKeys' => 5, 
        ]);

        if (isset($album_contents['Contents'])) {
          foreach ($album_contents['Contents'] as $object) {
            if (substr($object['Key'], -1) !== '/') {
              $preview_image = $s3->getObjectUrl($bucket, $object['Key']);
              break; 
            }
          }
        }

        $splitPrefix = explode('/', trim($prefix, '/'));
        array_shift($splitPrefix); // Remove 'photos'
        
        $url_path = implode('/', $splitPrefix);
        $rawText = $url_path;
        
        // Formatting the display title
        $date_part = substr($rawText, 0, 8);
        $title_part = substr($rawText, 8);
        $date = date_create($date_part);
        $displayText = $date ? date_format($date, "D j M") . " — " . $title_part : $rawText;

        $prefixes_by_year[$year][] = [
          'url' => "/photos/" . $url_path,
          'title' => $displayText,
          'preview' => $preview_image,
          'sort_key' => $rawText,
        ];
      }
    }

    krsort($prefixes_by_year); // Sort years descending

    // Sort albums within each year descending
    foreach ($prefixes_by_year as &$albums) {
      usort($albums, function($a, $b) {
        return strcmp($b['sort_key'], $a['sort_key']);
      });
    }

    return $prefixes_by_year;
  }

  /**
   * Helper: Get image URLs for a specific album
   */
  private function photoPage($s3, $bucket, $prefix) { 
    $images = [];
    $contents = $s3->listObjectsV2([
      'Bucket' => $bucket,
      'Prefix' => $prefix,
    ]);

    if (isset($contents['Contents'])) {
      foreach ($contents['Contents'] as $content) {
        $key = $content['Key'];
        if (substr($key, -1) !== '/') {
          $images[] = $s3->getObjectUrl($bucket, $key);
        }
      }
    }
    return $images;
  }
}