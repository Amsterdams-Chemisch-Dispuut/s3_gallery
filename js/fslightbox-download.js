(function (Drupal) {
  'use strict';

  function getCurrentSource(instance) {
    if (!instance || !instance.props || !Array.isArray(instance.props.sources)) {
      return null;
    }

    var index = instance.stageIndexes && typeof instance.stageIndexes.current === 'number'
      ? instance.stageIndexes.current
      : 0;

    var source = instance.props.sources[index];
    return typeof source === 'string' && source.length ? source : null;
  }

  function getCurrentDownloadUrl(instance) {
    if (!instance || !instance.elements || !Array.isArray(instance.elements.a)) {
      return getCurrentSource(instance);
    }

    var index = instance.stageIndexes && typeof instance.stageIndexes.current === 'number'
      ? instance.stageIndexes.current
      : 0;

    var activeLink = instance.elements.a[index];
    if (activeLink) {
      var downloadUrl = activeLink.getAttribute('data-download-url');
      if (downloadUrl) {
        return downloadUrl;
      }
    }

    return getCurrentSource(instance);
  }

  function ensureDownloadButton(instance) {
    var container = instance && instance.elements ? instance.elements.container : null;
    if (!container) {
      return;
    }

    var toolbar = container.querySelector('.fslightbox-toolbar');
    if (!toolbar) {
      return;
    }

    var button = toolbar.querySelector('.s3-gallery-download-btn');
    if (!button) {
      button = document.createElement('a');
      button.className = 'fslightbox-toolbar-button fslightbox-flex-centered s3-gallery-download-btn';
      button.title = 'Download image';
      button.setAttribute('aria-label', 'Download image');
      button.setAttribute('target', '_blank');
      button.setAttribute('rel', 'noopener noreferrer');

      button.innerHTML =
        '<svg width="20px" height="20px" viewBox="0 0 24 24" aria-hidden="true">' +
        '<path class="fslightbox-svg-path" d="M12 3a1 1 0 0 1 1 1v8.59l2.3-2.29a1 1 0 1 1 1.4 1.41l-4 3.99a1 1 0 0 1-1.4 0l-4-3.99a1 1 0 0 1 1.4-1.41L11 12.59V4a1 1 0 0 1 1-1Zm-7 14a1 1 0 0 1 1 1v1h12v-1a1 1 0 1 1 2 0v2a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1Z"></path>' +
        '</svg>';

      toolbar.insertBefore(button, toolbar.firstChild);
    }

    var source = getCurrentDownloadUrl(instance);
    if (!source) {
      button.style.display = 'none';
      button.removeAttribute('href');
      button.removeAttribute('download');
      return;
    }

    button.style.display = 'flex';
    button.href = source;
    button.setAttribute('download', 'image');
  }

  function attachDownloadHook(instance) {
    if (!instance || instance.__s3GalleryDownloadHooked) {
      return;
    }

    instance.__s3GalleryDownloadHooked = true;

    var previousOnOpen = instance.props.onOpen;
    var previousOnShow = instance.props.onShow;

    instance.props.onOpen = function () {
      if (typeof previousOnOpen === 'function') {
        previousOnOpen();
      }
      ensureDownloadButton(instance);
    };

    instance.props.onShow = function () {
      if (typeof previousOnShow === 'function') {
        previousOnShow();
      }
      ensureDownloadButton(instance);
    };
  }

  function wireFsLightboxInstances() {
    if (window.fsLightboxInstances) {
      Object.keys(window.fsLightboxInstances).forEach(function (key) {
        attachDownloadHook(window.fsLightboxInstances[key]);
      });
    }

    if (window.fsLightbox) {
      attachDownloadHook(window.fsLightbox);
    }
  }

  Drupal.behaviors.s3GalleryFsLightboxDownload = {
    attach: function () {
      wireFsLightboxInstances();
    }
  };
})(Drupal);
