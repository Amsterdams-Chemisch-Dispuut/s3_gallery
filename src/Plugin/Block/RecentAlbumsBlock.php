<?php

namespace Drupal\s3_gallery\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Site\Settings;
use Aws\S3\S3Client;

/**
 * Provides a 'Recent Albums' Block.
 *
 * @Block(
 * id = "s3_gallery_recent_block",
 * admin_label = @Translation("S3 Gallery: Recent Activities (Top 4)"),
 * category = @Translation("S3 Gallery"),
 * )
 */
class RecentAlbumsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // 1. Check anonymous access if needed (optional, matches your controller)
    /* if (\Drupal::currentUser()->isAnonymous()) {
      return []; // Return empty if you don't want anonymous users to see it
    } 
    */

    try {
      // 2. Setup S3
      $config = Settings::get('aws_s3');
      $s3 = new S3Client([
        'version' => 'latest',
        'region'  => $config['region'],
        'credentials' => [
          'key'    => $config['key'],
          'secret' => $config['secret'],
        ],
      ]);
      $bucket = $config['bucket'];

      // 3. Get all album folders
      $contents = $s3->listObjectsV2([
        'Bucket' => $bucket,
        'Prefix' => 'photos/',
        'Delimiter' => '/',
      ]);

      $albums = [];

      if (isset($contents['CommonPrefixes'])) {
        // 4. Sort folders DESCENDING by name (Since name starts with YYYYMMDD, this sorts by date)
        // We use $b vs $a to get descending order
        usort($contents['CommonPrefixes'], function($a, $b) {
            return strcmp($b['Prefix'], $a['Prefix']);
        });

        // 5. Slice the top 4 most recent folders
        $recent_folders = array_slice($contents['CommonPrefixes'], 0, 4);

        foreach ($recent_folders as $folder) {
          $prefix = $folder['Prefix']; // e.g., photos/20231201_ActivityName/

          // Parse Title and Date
          $splitPrefix = explode('/', trim($prefix, '/'));
          array_shift($splitPrefix); // Remove 'photos'
          $url_path = implode('/', $splitPrefix); // e.g., 20231201_ActivityName
          
          $date_part = substr($url_path, 0, 8);
          $title_part = substr($url_path, 8);
          $date = date_create($date_part);
          $displayText = $date ? date_format($date, "D j M") . " — " . $title_part : $url_path;

          // 6. Fetch ONLY the preview image for this specific album
          $preview_image = '';
          $album_contents = $s3->listObjectsV2([
            'Bucket' => $bucket,
            'Prefix' => $prefix,
            'MaxKeys' => 5, // Just need one, grab a few to be safe
          ]);

          if (isset($album_contents['Contents'])) {
            foreach ($album_contents['Contents'] as $object) {
              // Ensure we don't grab the folder itself, just a file
              if (substr($object['Key'], -1) !== '/') {
                $preview_image = $s3->getObjectUrl($bucket, $object['Key']);
                break; // Found one, stop looking
              }
            }
          }

          $albums[] = [
            'url'     => "/photos/" . $url_path,
            'title'   => $displayText,
            'preview' => $preview_image,
          ];
        }
      }

      // 7. Render
      return [
        '#theme' => 'gallery-block',
        '#albums' => $albums,
        '#cache' => [
            'max-age' => 3600, // Cache for 1 hour so we don't hit S3 on every page load
        ],
      ];

    } catch (\Exception $e) {
      // Fail silently in a block, or log it
      \Drupal::logger('s3_gallery')->error('Recent Block Error: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }
}