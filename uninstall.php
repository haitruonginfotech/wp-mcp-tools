<?php
/**
 * Removes data kept by the optional add-ons added in v0.11 to v0.14 (access levels, activity log, undo snapshots,
 * file-editing unlock). OAuth clients and tokens are kept, as before.
 *
 * @package WPMCPTools
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'wpmcp_access', 'wpmcp_activity', 'wpmcp_undo', 'wpmcp_unlock_until' ) as $option ) {
	delete_option( $option );
}
