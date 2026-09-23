<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cbio_remove_image_metadata( $image_path ) {
    if ( ! extension_loaded('imagick') || ! file_exists($image_path) ) {
        return false;
    }

    try {
        $imagick = new Imagick($image_path);

        $profiles = $imagick->getImageProfiles('*', true);
        foreach ($profiles as $profile => $value) {
            if (strtolower($profile) === 'icc' || strtolower($profile) === 'icm') {
                continue;
            }
            $imagick->removeImageProfile($profile);
        }

        $imagick->setImageProperty('comment', '');

        $imagick->writeImage($image_path);
        $imagick->clear();
        $imagick->destroy();

        return true;
    } catch (Exception $e) {
        return false;
    }
}