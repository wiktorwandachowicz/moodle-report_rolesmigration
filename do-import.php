<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Performs the import from an Roles XML generated by the export roles script
 * @package   moodlerolesmigration
 * @copyright 2011 NCSU DELTA | <http://delta.ncsu.edu> and others
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($roles_in_file = roles_migration_get_incoming_roles()) {
    foreach ($roles_in_file as $role) {
        if (!isset($actions[$role->shortname])) {
            echo '<p>', get_string('role_ignored', 'local_rolesmigration', $role), '</p>';
            continue;
        }

        switch ($actions[$role->shortname]) {
            case 'skip':
                echo '<p>', get_string('role_ignored', 'local_rolesmigration', $role), '</p>';
                break;
            case 'create':
                if (!array_key_exists($role->shortname, $roles['create'])) {
                    print_error('new_shortname_undefined');
                }
                $textlib = textlib_get_instance();
                $new_role_shortname = $textlib->specialtoascii($roles['create'][$role->shortname]['shortname']);
                $new_role_shortname = $textlib->strtolower(clean_param($new_role_shortname, PARAM_ALPHANUMEXT));
                $new_role_name = $roles['create'][$role->shortname]['name'];

                // Code to make new role name/short name if same role name or shortname exists
                $fullname = $new_role_name;
                $shortname = $new_role_shortname;
                $currentfullname = "";
                $currentshortname = "";
                $counter = 0;

                do {
                    if ($counter) {
                        $suffixfull = " ".get_string("copyasnoun")." ".$counter;
                        $suffixshort = "_".$counter;
                    } else {
                        $suffixfull = "";
                        $suffixshort = "";
                    }
                    $currentfullname = $fullname.$suffixfull;
                    // Limit the size of shortname - database column accepts <= 100 chars
                    $currentshortname = substr($shortname, 0, 100 - strlen($suffixshort)).$suffixshort;
                    $coursefull  = $DB->get_record("role", array("name" => $currentfullname));
                    $courseshort = $DB->get_record("role", array("shortname" => $currentshortname));
                    $counter++;
                } while ($coursefull || $courseshort);

                // done finding a unique name
                $role_id = create_role($currentfullname, $currentshortname, $role->description, $role->archetype);

                // restore value of 'id' field for the role, if possible
                if ($counter == 1) {
                    // continue only if value of exported 'id' is lower than current 'id' of newly created role
                    // (avoid future PK autoincrement conflicts)
                    if ($role->id < $role_id) {
                        // now check if no role with given 'id' already exists
                        $found_id = $DB->get_field("role", "id", array("id" => $role->id));
                        if (empty($found_id)) {
                            $sql = "UPDATE {role} SET id = :xmlid WHERE id = :curid";
                            $params = array('xmlid' => $role->id, 'curid'=> $role_id);
                            // update the role 'id' in DB
                            $DB->execute($sql, $params);
                            // update $role_id variable
                            $role_id = $role->id;
                        }
                    }
                }

                // Loop through incoming capabilities
                foreach ($role->capabilities as $capability) {
                    // Build capability object from incoming role info
                    $roleinfo = new stdClass();
                    $roleinfo = (object)$capability;
                    // Overwrite incoming context ID with current site's context ID
                    $roleinfo->contextid = $contextid;
                    // Add incoming capability name to object
                    $roleinfo->capability = $capability->capability;
                    // Set role_id of the incoming capabiliity to use the id of the role we just created
                    $roleinfo->roleid = $role_id;
                    // Insert the capability into the DB
                    $DB->insert_record('role_capabilities', $roleinfo);
                }

                // Loop through incoming context levels
                foreach ($role->contextlevels as $contextlvl) {
                    // Build context level object from incoming role info
                    $roleinfo = new stdClass();
                    $roleinfo = (object)$contextlvl;
                    // Add incoming context level to object
                    $roleinfo->contextlevel = $contextlvl->contextlevel;
                    // Set role_id of the incoming context level to use the id of the role we just created
                    $roleinfo->roleid = $role_id;
                    // Insert the context level into the DB
                    $DB->insert_record('role_context_levels', $roleinfo);
                }

                // Prep values for string and send to screen
                $r = new stdClass();
                $r->newshort = $currentshortname;
                $r->newname = $currentfullname;
                $r->newid = $role_id;
                $r->oldshort = $role->shortname;
                $r->oldname = $role->name;
                $r->oldid = $role->id;
                echo '<p>', get_string('new_role_created', 'local_rolesmigration', $r), '</p>';
                break;

            case 'replace':
                // If the current role is not in the array of incoming roles to replace print error
                if (!array_key_exists($role->shortname, $roles['replace'])) {
                    print_error('shortname_to_replace_undefined');
                }

                // Set var with role we're going to update with incoming capabilities
                $existing_role = $roles['replace'][$role->shortname];

                // Grab the DB record for the role we're going to update based on above var just set
                if (!$role_record = $DB->get_record('role', array('shortname' => $existing_role))) {
                    print_error('shortname_to_replace_undefined');
                }

                // ID or existing role
                $role_id = $role_record->id;

                // Grab existing capabilities for the role that we're about to update
                $role_capabilities = $DB->get_records_select('role_capabilities', "contextid = ? AND roleid = ?",
                                        array($contextid, $role_id), 'id', 'capability,id,contextid,roleid,permission');

                // Loop through incoming capabilities and build DB object for each one
                foreach ($role->capabilities as $capability) {
                    $roleinfo = new stdClass();
                    $roleinfo = (object)$capability;

                    // Overwrite incoming contextid with current site's context ID
                    $roleinfo->contextid = $contextid;
                    // Set capability to incoming value
                    $roleinfo->capability = $capability->capability;
                    // Overwrite incoming capability's role_id with ID of current site's role
                    $roleinfo->roleid = $role_id;
                    // If existing role doesn't have incoming capability, insert the record
                    if (!isset($role_capabilities[$roleinfo->capability])) {
                        $DB->insert_record('role_capabilities', $roleinfo);
                    } elseif ($role_capabilities[$roleinfo->capability]->permission != $roleinfo->permission) {
                        // If the incoming capability exists, but the permission is different, update the existing record
                        $roleinfo->id = $role_capabilities[$roleinfo->capability]->id;
                        $DB->update_record('role_capabilities', $roleinfo);
                    }
                    // Remove the inserted or updated incoming capability from the array existing capabilities
                    unset($role_capabilities[$roleinfo->capability]);
                }

                // Loop through and delete the remaining array of existing capabilities (not found in incoming capabilities)
                foreach ($role_capabilities as $delete_capability) {
                    $to_delete[] = $delete_capability->id;
                }
                if (!empty($to_delete) && is_array($to_delete)) {
                    $DB->delete_records_list('role_capabilities', 'id', $to_delete);
                }


                // Grab existing context levels for the role that we're about to update
                $role_contextlevels = $DB->get_records_select('role_context_levels', "roleid = ?",
                                        array($role_id), 'contextlevel');
                //echo "<pre>"; print_r($role_contextlevels); echo "</pre>";
                $to_delete = array();

                // Loop through incoming context levels and build DB object for each one
                foreach ($role->contextlevels as $key => $contextlvl) {
                    $roleinfo = new stdClass();
                    $roleinfo = (object)$contextlvl;

                    // Set context level to incoming value
                    $roleinfo->contextlevel = $contextlvl->contextlevel;
                    // Overwrite incoming capability's role_id with ID of current site's role
                    $roleinfo->roleid = $role_id;
                    // If existing role doesn't have incoming context level, insert the record
                    $found_id = 0;
                    foreach ($role_contextlevels as $lvl) {
                        if ($lvl->contextlevel == $roleinfo->contextlevel) {
                            $found_id = $lvl->id;
                            break;
                        }
                    }
                    //echo "found: [$key] $roleinfo->contextlevel <pre>"; print_r($role_contextlevels); echo "</pre>";
                    if (!$found_id) {
                        $DB->insert_record('role_context_levels', $roleinfo);
                    }
                    // Remove the inserted incoming context level from the array existing context levels
                    unset($role_contextlevels[$found_id]);
                }

                // Loop through and delete the remaining array of existing context levels (not found in incoming context levels)
                foreach ($role_contextlevels as $delete_contextlvl) {
                    $to_delete[] = $delete_contextlvl->id;
                }
                if (!empty($to_delete) && is_array($to_delete)) {
                    $DB->delete_records_list('role_context_levels', 'id', $to_delete);
                }

                // Prep values for string and send to screen
                $r = new stdClass();
                $r->new = $existing_role;
                $r->replaced = $role->shortname;
                echo '<p>', get_string('role_replaced', 'local_rolesmigration', $r), '</p>';
                break;

            default:
                $a = new stdClass();
                $a->action = $actions[$role->shortname];
                $a->shortname = $role->shortname;
                echo '<p>', get_string('unknown_import_action', 'local_rolesmigration', $a), '</p>';
        }
    }
}
