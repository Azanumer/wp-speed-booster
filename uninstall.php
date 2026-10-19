<?php
/**
 * Uninstall cleanup for WP Speed Booster.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wsb_settings' );
