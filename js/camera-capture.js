/**
 * @file
 * In-browser camera capture for the member headshot field.
 *
 * Adds a "Take a photo" button beside the field_member_photo file input. The
 * button opens a live camera preview (getUserMedia), captures a centre-square
 * headshot to a canvas, and injects it into the existing managed-file widget as
 * a JPEG — so Drupal's normal auto-upload and the crop UI run unchanged. When
 * the browser can't do camera capture, nothing is added and the plain file
 * upload (which already opens the camera on phones) is the fallback.
 */
(function (Drupal, once) {
  'use strict';

  var supported =
    navigator.mediaDevices &&
    typeof navigator.mediaDevices.getUserMedia === 'function' &&
    typeof DataTransfer !== 'undefined' &&
    typeof HTMLCanvasElement !== 'undefined' &&
    HTMLCanvasElement.prototype.toBlob;

  Drupal.behaviors.memberPhotoCamera = {
    attach: function (context) {
      if (!supported) {
        return;
      }
      var selector = 'input[type="file"][name^="files[field_member_photo"]';
      once('member-photo-camera', selector, context).forEach(function (fileInput) {
        initCamera(fileInput);
      });
    }
  };

  /**
   * Wires a camera capture UI to a single file input.
   */
  function initCamera(fileInput) {
    var openBtn = document.createElement('button');
    openBtn.type = 'button';
    openBtn.className = 'member-photo-camera__open button';
    openBtn.textContent = Drupal.t('📷 Take a photo');

    var panel = document.createElement('div');
    panel.className = 'member-photo-camera__panel';
    panel.hidden = true;

    var video = document.createElement('video');
    video.className = 'member-photo-camera__video';
    video.setAttribute('playsinline', '');
    video.setAttribute('muted', '');
    video.autoplay = true;
    video.muted = true;

    var actions = document.createElement('div');
    actions.className = 'member-photo-camera__actions';

    var captureBtn = document.createElement('button');
    captureBtn.type = 'button';
    captureBtn.className = 'member-photo-camera__capture button button--primary';
    captureBtn.textContent = Drupal.t('Capture');

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'member-photo-camera__cancel button';
    cancelBtn.textContent = Drupal.t('Cancel');

    var errorEl = document.createElement('p');
    errorEl.className = 'member-photo-camera__error';
    errorEl.setAttribute('role', 'alert');
    errorEl.hidden = true;

    actions.appendChild(captureBtn);
    actions.appendChild(cancelBtn);
    panel.appendChild(video);
    panel.appendChild(actions);

    fileInput.parentNode.insertBefore(errorEl, fileInput.nextSibling);
    fileInput.parentNode.insertBefore(panel, errorEl);
    fileInput.parentNode.insertBefore(openBtn, panel);

    var stream = null;

    function releaseCamera() {
      if (stream) {
        stream.getTracks().forEach(function (track) {
          track.stop();
        });
        stream = null;
      }
      video.srcObject = null;
    }

    function closePanel() {
      releaseCamera();
      panel.hidden = true;
      openBtn.hidden = false;
    }

    openBtn.addEventListener('click', function () {
      errorEl.hidden = true;
      openBtn.hidden = true;
      panel.hidden = false;
      navigator.mediaDevices
        .getUserMedia({
          video: {
            facingMode: 'user',
            width: { ideal: 1080 },
            height: { ideal: 1080 }
          },
          audio: false
        })
        .then(function (mediaStream) {
          stream = mediaStream;
          video.srcObject = mediaStream;
        })
        .catch(function () {
          panel.hidden = true;
          openBtn.hidden = false;
          errorEl.textContent = Drupal.t('Could not access the camera. You can still upload a photo file using the field above.');
          errorEl.hidden = false;
        });
    });

    cancelBtn.addEventListener('click', closePanel);

    captureBtn.addEventListener('click', function () {
      var vw = video.videoWidth;
      var vh = video.videoHeight;
      if (!stream || !vw || !vh) {
        return;
      }
      var size = Math.min(vw, vh);
      var canvas = document.createElement('canvas');
      canvas.width = size;
      canvas.height = size;
      // Centre-square crop so the headshot is framed like the badge photo.
      canvas
        .getContext('2d')
        .drawImage(video, (vw - size) / 2, (vh - size) / 2, size, size, 0, 0, size, size);
      canvas.toBlob(
        function (blob) {
          if (!blob) {
            return;
          }
          var file = new File([blob], 'headshot-' + new Date().getTime() + '.jpg', {
            type: 'image/jpeg'
          });
          var transfer = new DataTransfer();
          transfer.items.add(file);
          fileInput.files = transfer.files;
          closePanel();
          // Hand off to Drupal's managed-file auto-upload + crop UI.
          fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        },
        'image/jpeg',
        0.9
      );
    });
  }
})(Drupal, once);
