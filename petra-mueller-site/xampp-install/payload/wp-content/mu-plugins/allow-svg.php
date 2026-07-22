<?php
add_filter('upload_mimes', function ($mimes) {
  $mimes['svg'] = 'image/svg+xml';
  return $mimes;
});
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
  $ext = pathinfo($filename, PATHINFO_EXTENSION);
  if (strtolower($ext) === 'svg') {
    $data['ext'] = 'svg';
    $data['type'] = 'image/svg+xml';
    $data['proper_filename'] = $filename;
  }
  return $data;
}, 10, 4);
