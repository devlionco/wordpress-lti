<?php
/*
 *  wordpress-lti - WordPress module to add LTI support
 *  Copyright (C) 2022  Simon Booth, Stephen P Vickers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License along
 *  with this program; if not, write to the Free Software Foundation, Inc.,
 *  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 *  Contact: s.p.booth@stir.ac.uk
 */

use ceLTIc\LTI\Tool;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\ResourceLink;
use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\Util;

global $wpdb;

// include the LTI library classes
require_once(dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');

require_once(dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'config.php');

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'WPTool.php');

// Set logging level
if (defined('LTI_LOG_LEVEL')) {
    Util::$logLevel = LTI_LOG_LEVEL;
}

// Set the default tool
$tool = apply_filters('lti-tool', null, null);
if (empty($tool)) {
    $tool = new LTI_WPTool(null);
}
Tool::$defaultTool = $tool;

$lti_db_connector = DataConnector::getDataConnector($wpdb->dbh, $wpdb->base_prefix);

/* -------------------------------------------------------------------
 * LTI_WP_User - a local smaller definition of the LTI_User that
 * simply captures what needed in WP to deal with the various states
 * that can occur when synchronising
 * ----------------------------------------------------------------- */

class LTI_WP_User
{

    /**
     * Use username only.
     */
    const ID_SCOPE_USERNAME = 'U';

    /**
     * Use email address only.
     */
    const ID_SCOPE_EMAIL = 'E';

    public $id;
    public $username;
    public $firstname;
    public $lastname;
    public $name;
    public $email;
    public $role;
    public $lti_user_id;
    public $reasons = array();

    public static function fromUserResult($user_result, $user_login, $options)
    {
        $user = new static();
        $user->id = null;
        $user->username = $user_login;
        $user->firstname = $user_result->firstname;
        $user->lastname = $user_result->lastname;
        $user->name = $user_result->fullname;
        $user->email = $user_result->email;
        $user->role = lti_user_role($user_result, $options);
        $user->lti_user_id = $user_result->ltiUserId;

        return $user;
    }

    public static function fromWPUser($wp_user)
    {
        $user = new static();
        $user->id = $wp_user->ID;
        $user->username = $wp_user->user_login;
        $user->firstname = $wp_user->first_name;
        $user->lastname = $wp_user->last_name;
        $user->name = $wp_user->display_name;
        $user->email = $wp_user->user_email;
        $user->role = array_shift($wp_user->roles);
        $user->lti_user_id = $wp_user->lti_user_id;

        return $user;
    }

    private function __construct()
    {

    }

}

/* -------------------------------------------------------------------
 * Delete a platform and any blogs associated with it
 *
 * Parameter
 *  $key - key for platform
  ------------------------------------------------------------------ */

function lti_delete($key)
{
    global $wpdb, $lti_db_connector;

    $platform = Platform::fromConsumerKey($key, $lti_db_connector);
    $platform->delete();

    // Now delete the blogs associated with this key. The WP function that lists all
    // blog is deprecated and so we'll do a direct DB access (look the other way)
    $search_str = '%/' . str_replace('.', '', $key) . '%';
    $sites = $wpdb->get_col($wpdb->prepare(
            "SELECT blog_id FROM {$wpdb->prefix}blogs WHERE path LIKE '%s'", $search_str));

    // Delete the blog
    foreach ($sites as $site) {
        wpmu_delete_blog($site, true);
    }
}

/* -------------------------------------------------------------------
 * Switch the enabled state for platform
 *
 * Parameter
 *  $key - key for platform
  ------------------------------------------------------------------ */

function lti_set_enable($key, $enable)
{
    global $lti_db_connector;

    $platform = Platform::fromConsumerKey($key, $lti_db_connector);
    $platform->enabled = $enable;
    $platform->save();
}

/* -------------------------------------------------------------------
 * Get whether a particular platform is enabled.
 *
 * Parameter
 *  $key - key for platform
  ------------------------------------------------------------------ */

function lti_get_enabled_state($key)
{
    global $lti_db_connector;

    $platform = Platform::fromConsumerKey($key, $lti_db_connector);

    return $platform->enabled;
}

/* -------------------------------------------------------------------
 * Check that the tables necessary for classes are present, if not
 * create.
  ------------------------------------------------------------------ */

function lti_create_db()
{
    global $wpdb;

    $prefix = $wpdb->prefix;
    $charset_collate = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '{$prefix}" . DataConnector::PLATFORM_TABLE_NAME . "'") !== $prefix . DataConnector::PLATFORM_TABLE_NAME) {
        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . ' (' .
            'consumer_pk int(11) NOT NULL AUTO_INCREMENT, ' .
            'name varchar(50) NOT NULL, ' .
            'consumer_key varchar(256) DEFAULT NULL, ' .
            'secret varchar(1024) DEFAULT NULL, ' .
            'platform_id varchar(255) DEFAULT NULL, ' .
            'client_id varchar(255) DEFAULT NULL, ' .
            'deployment_id varchar(255) DEFAULT NULL, ' .
            'public_key text DEFAULT NULL, ' .
            'lti_version varchar(10) DEFAULT NULL, ' .
            'signature_method varchar(15) DEFAULT NULL, ' .
            'consumer_name varchar(255) DEFAULT NULL, ' .
            'consumer_version varchar(255) DEFAULT NULL, ' .
            'consumer_guid varchar(1024) DEFAULT NULL, ' .
            'profile text DEFAULT NULL, ' .
            'tool_proxy text DEFAULT NULL, ' .
            'settings text DEFAULT NULL, ' .
            'protected tinyint(1) NOT NULL, ' .
            'enabled tinyint(1) NOT NULL, ' .
            'enable_from datetime DEFAULT NULL, ' .
            'enable_until datetime DEFAULT NULL, ' .
            'last_access date DEFAULT NULL, ' .
            'created datetime NOT NULL, ' .
            'updated datetime NOT NULL, ' .
            'PRIMARY KEY (consumer_pk)' .
            ') ENGINE=InnoDB ' . $charset_collate;
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "ALTER TABLE {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . ' ' .
            "ADD UNIQUE INDEX {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . '_' .
            'consumer_key_UNIQUE (consumer_key ASC)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "ALTER TABLE {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . ' ' .
            "ADD UNIQUE INDEX {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . '_' .
            'platform_UNIQUE (platform_id ASC, client_id ASC, deployment_id ASC)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}" . DataConnector::NONCE_TABLE_NAME . ' (' .
            'consumer_pk int(11) NOT NULL, ' .
            'value varchar(50) NOT NULL, ' .
            'expires datetime NOT NULL, ' .
            'PRIMARY KEY (consumer_pk, value)' .
            ') ENGINE=InnoDB ' . $charset_collate;
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "ALTER TABLE {$prefix}" . DataConnector::NONCE_TABLE_NAME . ' ' .
            "ADD CONSTRAINT {$prefix}" . DataConnector::NONCE_TABLE_NAME . '_' .
            DataConnector::PLATFORM_TABLE_NAME . '_FK1 FOREIGN KEY (consumer_pk) ' .
            "REFERENCES {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . ' (consumer_pk)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}" . DataConnector::ACCESS_TOKEN_TABLE_NAME . ' (' .
            'consumer_pk int(11) NOT NULL, ' .
            'scopes text NOT NULL, ' .
            'token varchar(2000) NOT NULL, ' .
            'expires datetime NOT NULL, ' .
            'created datetime NOT NULL, ' .
            'updated datetime NOT NULL, ' .
            'PRIMARY KEY (consumer_pk)' .
            ') ENGINE=InnoDB ' . $charset_collate;
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "ALTER TABLE {$prefix}" . DataConnector::ACCESS_TOKEN_TABLE_NAME . ' ' .
            "ADD CONSTRAINT {$prefix}" . DataConnector::ACCESS_TOKEN_TABLE_NAME . '_' .
            DataConnector::PLATFORM_TABLE_NAME . '_FK1 FOREIGN KEY (consumer_pk) ' .
            "REFERENCES {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . ' (consumer_pk)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}" . DataConnector::CONTEXT_TABLE_NAME . ' (' .
            'context_pk int(11) NOT NULL AUTO_INCREMENT, ' .
            'consumer_pk int(11) NOT NULL, ' .
            'lti_context_id varchar(255) NOT NULL, ' .
            'title varchar(255) DEFAULT NULL, ' .
            'type varchar(50) DEFAULT NULL, ' .
            'settings text DEFAULT NULL, ' .
            'created datetime NOT NULL, ' .
            'updated datetime NOT NULL, ' .
            'PRIMARY KEY (context_pk)' .
            ') ENGINE=InnoDB ' . $charset_collate;
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "ALTER TABLE {$prefix}" . DataConnector::CONTEXT_TABLE_NAME . ' ' .
            "ADD CONSTRAINT {$prefix}" . DataConnector::CONTEXT_TABLE_NAME . '_' .
            DataConnector::PLATFORM_TABLE_NAME . '_FK1 FOREIGN KEY (consumer_pk) ' .
            "REFERENCES {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . ' (consumer_pk)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "ALTER TABLE {$prefix}" . DataConnector::CONTEXT_TABLE_NAME . ' ' .
            "ADD INDEX {$prefix}" . DataConnector::CONTEXT_TABLE_NAME . '_' .
            'consumer_id_IDX (consumer_pk ASC)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' (' .
            'resource_link_pk int(11) AUTO_INCREMENT, ' .
            'context_pk int(11) DEFAULT NULL, ' .
            'consumer_pk int(11) DEFAULT NULL, ' .
            'title varchar(255) DEFAULT NULL, ' .
            'lti_resource_link_id varchar(255) NOT NULL, ' .
            'settings text, ' .
            'primary_resource_link_pk int(11) DEFAULT NULL, ' .
            'share_approved tinyint(1) DEFAULT NULL, ' .
            'created datetime NOT NULL, ' .
            'updated datetime NOT NULL, ' .
            'PRIMARY KEY (resource_link_pk)' .
            ') ENGINE=InnoDB ' . $charset_collate;
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "ALTER TABLE {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' ' .
            "ADD CONSTRAINT {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . '_' .
            DataConnector::PLATFORM_TABLE_NAME . '_FK1 FOREIGN KEY (consumer_pk) ' .
            "REFERENCES {$prefix}" . DataConnector::PLATFORM_TABLE_NAME . ' (consumer_pk)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "ALTER TABLE {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' ' .
            "ADD CONSTRAINT {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . '_' .
            DataConnector::CONTEXT_TABLE_NAME . '_FK1 FOREIGN KEY (context_pk) ' .
            "REFERENCES {$prefix}" . DataConnector::CONTEXT_TABLE_NAME . ' (context_pk)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }
        $sql = "ALTER TABLE {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' ' .
            "ADD CONSTRAINT {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . '_' .
            DataConnector::RESOURCE_LINK_TABLE_NAME . '_FK1 FOREIGN KEY (primary_resource_link_pk) ' .
            "REFERENCES {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' (resource_link_pk)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }
        $sql = "ALTER TABLE {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' ' .
            "ADD INDEX {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . '_' .
            'consumer_pk_IDX (consumer_pk ASC)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }
        $sql = "ALTER TABLE {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' ' .
            "ADD INDEX {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . '_' .
            'context_pk_IDX (context_pk ASC)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}" . DataConnector::USER_RESULT_TABLE_NAME . ' (' .
            'user_result_pk int(11) AUTO_INCREMENT, ' .
            'resource_link_pk int(11) NOT NULL, ' .
            'lti_user_id varchar(255) NOT NULL, ' .
            'lti_result_sourcedid varchar(1024) NOT NULL, ' .
            'created datetime NOT NULL, ' .
            'updated datetime NOT NULL, ' .
            'PRIMARY KEY (user_result_pk)' .
            ') ENGINE=InnoDB ' . $charset_collate;
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }
        $sql = "ALTER TABLE {$prefix}" . DataConnector::USER_RESULT_TABLE_NAME . ' ' .
            "ADD CONSTRAINT {$prefix}" . DataConnector::USER_RESULT_TABLE_NAME . '_' .
            DataConnector::RESOURCE_LINK_TABLE_NAME . '_FK1 FOREIGN KEY (resource_link_pk) ' .
            "REFERENCES {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' (resource_link_pk)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }
        $sql = "ALTER TABLE {$prefix}" . DataConnector::USER_RESULT_TABLE_NAME . ' ' .
            "ADD INDEX {$prefix}" . DataConnector::USER_RESULT_TABLE_NAME . '_' .
            'resource_link_pk_IDX (resource_link_pk ASC)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}" . DataConnector::RESOURCE_LINK_SHARE_KEY_TABLE_NAME . ' (' .
            'share_key_id varchar(32) NOT NULL, ' .
            'resource_link_pk int(11) NOT NULL, ' .
            'auto_approve tinyint(1) NOT NULL, ' .
            'expires datetime NOT NULL, ' .
            'PRIMARY KEY (share_key_id)' .
            ') ENGINE=InnoDB ' . $charset_collate;
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }
        $sql = "ALTER TABLE {$prefix}" . DataConnector::RESOURCE_LINK_SHARE_KEY_TABLE_NAME . ' ' .
            "ADD CONSTRAINT {$prefix}" . DataConnector::RESOURCE_LINK_SHARE_KEY_TABLE_NAME . '_' .
            DataConnector::RESOURCE_LINK_TABLE_NAME . '_FK1 FOREIGN KEY (resource_link_pk) ' .
            "REFERENCES {$prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' (resource_link_pk)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }
        $sql = "ALTER TABLE {$prefix}" . DataConnector::RESOURCE_LINK_SHARE_KEY_TABLE_NAME . ' ' .
            "ADD INDEX {$prefix}" . DataConnector::RESOURCE_LINK_SHARE_KEY_TABLE_NAME . '_' .
            'resource_link_pk_IDX (resource_link_pk ASC)';
        if (!$wpdb->query($sql)) {
            error_log($wpdb->print_error());
        }
    }

    return true;
}

/* -------------------------------------------------------------------
 * The function is run when the Synchronisation page header is
 * generated --- see lti.php and sync_admin_header
 *
 * Parameters
 *  $with_deletions - whether to run the update with deletions
  ------------------------------------------------------------------ */

function lti_update($with_deletions)
{
    global $blog_id, $lti_db_connector, $lti_session;

    // Get the platform
    $platform = Platform::fromConsumerKey($lti_session['key'], $lti_db_connector);
    $resource_link = ResourceLink::fromPlatform($platform, $lti_session['resourceid']);

    $errors = array();

    // New users
    $users = $lti_session['sync']['new'];
    foreach ($users as $user) {
        $user_data = array(
            'user_login' => $user->username,
            'user_nicename' => $user->username,
            'user_pass' => wp_generate_password(),
            'first_name' => $user->firstname,
            'last_name' => $user->lastname,
            'display_name' => $user->name
        );
        if (lti_do_save_email()) {
            $user_data['user_email'] = $user->email;
        }
        $result = wp_insert_user($user_data);
        if (is_wp_error($result)) {
            $errors[] = $user->username . ': ' . $result->get_error_message();
        } else {
            $user->id = $result;
            $lti_session['sync']['add'][] = $user;
        }
    }

    // Add users to blog
    $users = $lti_session['sync']['add'];
    foreach ($users as $user) {
        if (is_multisite()) {
            $result = add_user_to_blog($blog_id, $user->id, $user->role);
            if (is_wp_error($result)) {
                $errors[] = $user->username . ': ' . $result->get_error_message();
            }
        } else {
            $user->set_role($user->role);
        }
        // Save LTI user ID
        update_user_meta($user->id, 'lti_platform_key', $platform->getKey());
        update_user_meta($user->id, 'lti_user_id', $user->lti_user_id);
    }

    // Changed users
    $users = $lti_session['sync']['change'];
    foreach ($users as $user) {
        if (in_array(LTI_User_List_Table::REASON_CHANGE_NAME, $user->reasons) ||
            in_array(LTI_User_List_Table::REASON_CHANGE_EMAIL, $user->reasons)) {
            $user_data = array
                ('ID' => $user->id,
                'first_name' => $user->firstname,
                'last_name' => $user->lastname,
                'display_name' => $user->name);
            if (lti_do_save_email()) {
                $user_data['user_email'] = $user->email;
            }
            $result = wp_update_user($user_data);
            if (is_wp_error($result)) {
                $errors[] = $user->username . ': ' . $result->get_error_message();
            }
        }
        if (in_array(LTI_User_List_Table::REASON_CHANGE_ROLE, $user->reasons)) {
            $wpuser = new WP_User($user->id, '', $blog_id);
            $wpuser->set_role($user->role);
        }
        if (in_array(LTI_User_List_Table::REASON_CHANGE_ID, $user->reasons)) {
            update_user_meta($user->id, 'lti_platform_key', $platform->getKey());
            update_user_meta($user->id, 'lti_user_id', $user->lti_user_id);
        }
    }

    // Remove users from blog but not WP as could be members of other blogs.
    if ($with_deletions) {
        $users = $lti_session['sync']['delete'];
        foreach ($users as $user) {
            $result = remove_user_from_blog($user->id, $blog_id);
            if (is_wp_error($result)) {
                $errors[] = $user->username . ': ' . $result->get_error_message();
            }
        }
    }

    if (empty($errors)) {
        add_action('admin_notices', 'lti_update_success');
    } else {
        $lti_session['sync']['errors'] = $errors;
        add_action('admin_notices', 'lti_update_error');
    }

    if ($resource_link->hasSettingService()) {
        $resource_link->doSettingService(ResourceLink::EXT_WRITE, date('d-M-Y H:i'));
    }

    lti_set_session($lti_session);
}

/* -------------------------------------------------------------------
 * Switch the enabled state for a share
 *
 * Parameter
 *  $id - the particular instance
  ------------------------------------------------------------------ */

function lti_set_share($id, $action)
{
    global $lti_db_connector;

    $context = ResourceLink::fromRecordId($id, $lti_db_connector);

    $context->shareApproved = $action;
    $context->save();
}

/* -------------------------------------------------------------------
 * Delete a share
 *
 * Parameter
 *  $id - the particular instance
  ------------------------------------------------------------------ */

function lti_delete_share($id)
{
    global $lti_db_connector;

    $context = ResourceLink::fromRecordId($id, $lti_db_connector);

    $context->delete();
}

/* -------------------------------------------------------------------
 * Strip slashes from $_POST
  ------------------------------------------------------------------ */

function lti_strip_magic_quotes()
{
    foreach ($_POST as $k => $v) {
        $_POST[$k] = stripslashes($v);
    }
}

/* -------------------------------------------------------------------
 * Extract the username scope from the consumer key/GUID
  ------------------------------------------------------------------ */

function lti_get_scope($guid)
{
    $scope = substr($guid, 2, 1);
    if (is_numeric($scope)) {
        $scope = intval($scope);
    }

    return $scope;
}

/* -------------------------------------------------------------------
 * Get the WordPress user login for a user based on the scope set for the platform
  ------------------------------------------------------------------ */

function lti_get_user_login($guid, $lti_user)
{
    $scope_userid = lti_get_scope($guid);
    switch ($scope_userid) {
        case LTI_WP_User::ID_SCOPE_USERNAME:
            $user_login = $lti_user->username;
            break;
        case LTI_WP_User::ID_SCOPE_EMAIL:
            $user_login = $lti_user->email;
            break;
        default:
            $user_login = $lti_user->getId($scope_userid);
            break;
    }
    // Sanitize username stripping out unsafe characters
    $user_login = sanitize_user($user_login);

    return $user_login;
}

/* -------------------------------------------------------------------
 * Generate the consumer key GUID
  ------------------------------------------------------------------ */

function lti_get_guid()
{
    $lti_scope = 3;

    if (isset($_GET['lti_scope'])) {
        $lti_scope = $_GET['lti_scope'];
    }

    $str = strtoupper(Util::getRandomString(6));
    return 'WP' . $lti_scope . '-' . $str;
}

/* -------------------------------------------------------------------
 * Generate the key for the WordPress transient used to store the plugin session variables
  ------------------------------------------------------------------ */

function lti_session_key()
{
    global $lti_session;

    $key = null;
    $save = false;
    $token = wp_get_session_token();
    if (isset($lti_session['_session_token'])) {
        if (empty($token) || ($token !== $lti_session['_session_token'])) {
            delete_site_transient("lti_{$token}");
            $token = $lti_session['_session_token'];
        } else {
            unset($lti_session['_session_token']);
            $save = true;
        }
    }
    if (!empty($token)) {
        $key = "lti_{$token}";
        if ($save) {
            lti_set_session($lti_session, $key);
        }
    }

    return $key;
}

/* -------------------------------------------------------------------
 * Retrieve the plugin session variables from a WordPress transient
  ------------------------------------------------------------------ */

function lti_get_session()
{
    $data = array();
    $key = lti_session_key();
    if (!empty($key)) {
        $data = get_site_transient($key);
        if ($data === false) {
            $data = array();
        }
    }

    return $data;
}

/* -------------------------------------------------------------------
 * Save the plugin session variables in a WordPress transient
  ------------------------------------------------------------------ */

function lti_set_session($data, $key = null)
{
    global $lti_session;

    if (empty($key)) {
        $key = lti_session_key();
    }
    if (!empty($key)) {
        set_site_transient($key, $data);
    }
}

/* -------------------------------------------------------------------
 * Clear the plugin session variables
  ------------------------------------------------------------------ */

function lti_reset_session($force = false)
{
    global $lti_session;

    $data = array();
    // Keep the return URL to enable its domain is allowed for redirects
    if (!$force && isset($lti_session['return_url'])) {
        $data['return_url'] = $lti_session['return_url'];
        lti_set_session($data);
    } else {
        $key = lti_session_key();
        if (!empty($key)) {
            delete_site_transient($key);
        }
        $lti_session = array();
    }
}

/* -------------------------------------------------------------------
 * Get the current option settings
  ------------------------------------------------------------------ */

function lti_get_options()
{
    $default_options = array('uninstalldb' => '0', 'uninstallblogs' => '0', 'adduser' => '0', 'mysites' => '0', 'scope' => LTI_ID_SCOPE_DEFAULT,
        'saveemail' => '0', 'homepage' => '', 'role_staff' => 'administrator', 'role_student' => 'author', 'role_other' => 'subscriber');
    if (is_multisite()) {
        $options = get_site_option('lti_choices');
    } else {
        $default_options['role_staff'] = 'editor';
        $options = get_option('lti_choices');
    }
    if ($options === false) {
        $options = get_option('lti_options');  // Check in deprecated location
        if ($options === false) {  // If no options set defaults
            $options = $default_options;
        } else {
            delete_option('lti_options');
        }
        if (is_multisite()) {
            add_site_option('lti_choices', $options);
        } else {
            add_option('lti_choices', $options);
        }
    }
    $options = array_merge($default_options, $options);

    return $options;
}

/* -------------------------------------------------------------------
 * Check if a user has a specified role
  ------------------------------------------------------------------ */

function lti_user_has_role($user, $role)
{
    return in_array($role, $user->roles);
}

/* -------------------------------------------------------------------
 * Get role based on user type
  ------------------------------------------------------------------ */

function lti_user_role($lti_user, $options)
{
    $role = $options['role_other'];
    if ($lti_user->isLearner()) {
        $role = $options['role_student'];
    } elseif ($lti_user->isStaff()) {
        $role = $options['role_staff'];
    }

    return $role;
}

/* -------------------------------------------------------------------
 * Check if user email addresses should be saved
  ------------------------------------------------------------------ */

function lti_do_save_email($key = null)
{
    global $lti_session;

    $saveemail = false;
    $options = lti_get_options();
    if (!empty($options['saveemail'])) {
        if (empty($key)) {
            $key = $lti_session['userkey'];
        }
        if (!empty($key)) {
            $scope = lti_get_scope($key);
            $saveemail = ($scope === Tool::ID_SCOPE_GLOBAL) || ($scope === Tool::ID_SCOPE_ID_ONLY) || ($scope = LTI_WP_User::ID_SCOPE_EMAIL);
        }
    }

    return $saveemail;
}

/* -------------------------------------------------------------------
 * Display success message on completion of user sync
  ------------------------------------------------------------------ */

function lti_update_success()
{
    $message = __('Updating of LTI users completed successfully.', 'lti-text');
    echo <<< EOD
    <div class="notice notice-success is-dismissible">
        <p>{$message}</p>
    </div>

EOD;
}

/* -------------------------------------------------------------------
 * Display error message on completion of user sync
  ------------------------------------------------------------------ */

function lti_update_error()
{
    global $lti_session;

    $message = __('An error occurred when synchronising users:', 'lti-text') . '<br>&nbsp;&nbsp;&nbsp;' . implode('<br>&nbsp;&nbsp;&nbsp;',
            $lti_session['sync']['errors']);
    echo <<< EOD
    <div class="notice notice-error is-dismissible">
        <p>{$message}</p>
    </div>

EOD;
}

?>
