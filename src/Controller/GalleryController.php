<?php

namespace Drupal\s3_gallery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Aws\S3\S3Client;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Access\AccessResult;

/**
 * Provides route responses for the S3 Gallery module.
 */
class GalleryController extends ControllerBase {

  /**
   * Returns the title for the gallery pages.
   *
   * @param string|null $prefix
   *   The prefix for the S3 objects.
   *
   * @return string
   *   The title for the gallery page.
   */
  public function getTitle($prefix = '') {
    if (empty($prefix)) {
      return 'Photo Gallery';
    }
    $date = date_create(substr($prefix, 0, 8));
    $title = substr($prefix, 8);
    // $month = substr($displayText, 0, 2);
    // $day = substr($displayText, 2, 2);
    // $placeholder = substr($displayText, 4); // Extract the rest of the string
    
    // Reformat to DD/MM {Placeholder}
    $displayText = date_format($date, "D j M Y") . " —" . $title;
    return $displayText;
  }

  /**
   * Returns the main gallery page.
   *
   * @return array
   *   A renderable array.
   */
  public function mainPage() {
    // Require user to be logged in
    if (\Drupal::currentUser()->isAnonymous()) {
      return [
        '#markup' => t('Access denied. Please log in to view this page.'),
        '#cache' => ['max-age' => 0],
      ];
    }
    try {
      // Retrieve AWS S3 configuration from settings.php
      $config = Settings::get('aws_s3');
      $s3 = new S3Client([
        'version' => 'latest',
        'region' => $config['region'],
        'credentials' => [
          'key'    => $config['key'],
          'secret' => $config['secret'],
        ],
      ]);

      $bucket = 'acdweb-storage';
      $prefix = 'photos/';

      // Print the current prefix

      // List objects in the specified prefix
      $contents = $s3->listObjectsV2([
        'Bucket' => $bucket,
        'Prefix' => $prefix,
        'Delimiter' => '/', // Ensure only direct children are listed
      ]);

      $output = "";

      $prefixes_by_year = [];

      if (isset($contents['CommonPrefixes'])) {
          foreach ($contents['CommonPrefixes'] as $commonPrefix) {
              $prefix = htmlspecialchars($commonPrefix['Prefix']);
              $year = substr($prefix, 7, 4); // Extract the year (first 4 symbols)
              if (!isset($prefixes_by_year[$year])) {
                  $prefixes_by_year[$year] = [];
              }
              $prefixes_by_year[$year][] = $prefix;
          }
      }

      krsort($prefixes_by_year); // sort by year

      foreach ($prefixes_by_year as $year => $prefixes) {
        usort($prefixes, function($a, $b) {
            $a_split = explode('/', trim($a, '/'));
            $b_split = explode('/', trim($b, '/'));
            array_shift($a_split); // remove the first entry
            array_shift($b_split); // remove the first entry
            return strcmp(implode('/', $b_split), implode('/', $a_split)); // Reverse order
        });
    
        $output .= "<h2>$year</h2>";
        $output .= "<ul>";
        foreach ($prefixes as $prefix) {
            $splitPrefix = explode('/', trim($prefix, '/'));
            array_shift($splitPrefix); // remove the first entry
            $url = "/photos/" . implode('/', $splitPrefix);
            $displayText = implode(' > ', $splitPrefix);
            // $displayText = substr($displayText, 4); // Remove the first 4 characters
            // Extract MM and DD
            $date = date_create(substr($displayText, 0, 8));
            $title = substr($displayText, 8);
            // $month = substr($displayText, 0, 2);
            // $day = substr($displayText, 2, 2);
            // $placeholder = substr($displayText, 4); // Extract the rest of the string
            
            // Reformat to DD/MM {Placeholder}
            $displayText = date_format($date, "D j M") . " —" . $title;
            $output .= "<li><a href=\"$url\">$displayText</a></li>";
        }
        $output .= "</ul>";
    }

      // Return the output as a renderable array
      return [
        '#markup' => $output,
      ];
    } catch (\Exception $e) {
      // Debugging information
      \Drupal::logger('s3_gallery')->error('Error: @error', ['@error' => $e->getMessage()]);
      return [
        '#markup' => "Error: " . $e->getMessage(),
      ];
    }
  }

  /**
   * Returns a gallery page.
   *
   * @param string|null $prefix
   *   The prefix for the S3 objects.
   *
   * @return array
   *   A renderable array.
   */
  public function myPage($prefix = '') {
    // Require user to be logged in
    if (\Drupal::currentUser()->isAnonymous()) {
      return [
        '#markup' => t('Access denied. Please log in to view this page.'),
        '#cache' => ['max-age' => 0],
      ];
    }
    try {
      // Retrieve AWS S3 configuration from settings.php
      $config = Settings::get('aws_s3');
      $s3 = new S3Client([
        'version' => 'latest',
        'region' => $config['region'],
        'credentials' => [
          'key'    => $config['key'],
          'secret' => $config['secret'],
        ],
      ]);

      $bucket = 'acdweb-storage';
      $prefix = 'photos/' . urldecode($prefix); // Ensure 'photos/' is prefixed and decode the prefix
      // $expires = '+10 minutes';

      if ($prefix == 'photos/') {
        $output = $this->homePage($s3, $bucket);
        return [
          '#markup' => $output,
          'css' => [
            'theme' => [
                'css/custom.css' => [],
            ],
          ],
          
        ];

      } else {
        $output = $this->photoPage($s3, $bucket, $prefix);
        return [
          '#theme' => 'album',
          '#images' => $output,
          '#attached' => [
            'library' => [
              's3_gallery/fslightbox',
            ],
          ],
        ];
        return [
          '#markup' => $output,
          'css' => [
            'theme' => [
                'css/custom.css' => [],
            ],
          ],
          
        ];
      }

      // Print the current prefi
      
      
    } catch (\Exception $e) {
      // Debugging information
      \Drupal::logger('s3_gallery')->error('Error: @error', ['@error' => $e->getMessage()]);
      return [
        '#markup' => "Error: " . $e->getMessage(),
      ];
    }
  }

  private function homePage($s3, $bucket) { 
    $contents = $s3->listObjectsV2([
      'Bucket' => $bucket,
      'Prefix' => 'photos/',
      'Delimiter' => '/',
    ]);
      $output = "";

      $prefixes_by_year = [];

      if (isset($contents['CommonPrefixes'])) {
          foreach ($contents['CommonPrefixes'] as $commonPrefix) {
              $prefix = htmlspecialchars($commonPrefix['Prefix']);
              $year = substr($prefix, 7, 4); // Extract the year (first 4 symbols)
              if (!isset($prefixes_by_year[$year])) {
                  $prefixes_by_year[$year] = [];
              }
              $prefixes_by_year[$year][] = $prefix;
          }
      }

      krsort($prefixes_by_year); // sort by year

      foreach ($prefixes_by_year as $year => $prefixes) {
        usort($prefixes, function($a, $b) {
            $a_split = explode('/', trim($a, '/'));
            $b_split = explode('/', trim($b, '/'));
            array_shift($a_split); // remove the first entry
            array_shift($b_split); // remove the first entry
            return strcmp(implode('/', $b_split), implode('/', $a_split)); // Reverse order
        });
    
        $output .= "<h2>$year</h2>";
        $output .= "<ul>";
        foreach ($prefixes as $prefix) {
            $splitPrefix = explode('/', trim($prefix, '/'));
            array_shift($splitPrefix); // remove the first entry
            $url = "/photos/" . implode('/', $splitPrefix);
            $displayText = implode(' > ', $splitPrefix);
            // $displayText = substr($displayText, 4); // Remove the first 4 characters
            // Extract MM and DD
            $date = date_create(substr($displayText, 0, 8));
            $title = substr($displayText, 8);
            // $month = substr($displayText, 0, 2);
            // $day = substr($displayText, 2, 2);
            // $placeholder = substr($displayText, 4); // Extract the rest of the string
            
            // Reformat to DD/MM {Placeholder}
            $displayText = date_format($date, "D j M") . " —" . $title;
            $output .= "<li><a href=\"$url\">$displayText</a></li>";
        }
        $output .= "</ul>";
    }
    return $output;
  }

  private function photoPage($s3, $bucket, $prefix) { 
    $images = [];
    $contents = $s3->listObjectsV2([
      'Bucket' => $bucket,
      'Prefix' => $prefix,
    ]);

    if (isset($contents['Contents'])) {
      foreach ($contents['Contents'] as $content) {
        $key = htmlspecialchars($content['Key']);
        $url = $s3->getObjectUrl($bucket, $key);
        if (substr($url, -1) !== '/') {
          $output[] = $url;
        }
      }
    }
    return $output;
  }

}