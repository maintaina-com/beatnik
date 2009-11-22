<?php
/**
 * Beatnik_Driver:: defines an API implementing astorage backends for Beatnik.
 *
 * Copyright 2005-2007 Alkaloid Networks <http://www.alkaloid.net>
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Ben Klang <ben@alkaloid.net>
 * @package Beatnik
 */
class Beatnik_Driver {

    /**
     * Hash containing connection parameters.
     *
     * @var array $_params
     */
    var $_params = array();

    function Beatnik_Driver($params = array())
    {
        $this->_params = $params;
    }

    /**
     * Get any record types  available specifically in this driver.
     *
     * @return array Records available only to this driver
     */
    function getRecDriverTypes()
    {
        return array();
    }


    /**
     * Get any fields available specifically in this driver by record type.
     *
     * @param string $type Record type for which fields should be returned
     *
     * @return array Fields specific to this driver
     */
    function getRecDriverFields($type) {

        return array();
    }

    /**
     * Gets domains from driver for which the user has the specified
     * permission.
     *
     * @param int $perms  Permissions filter for domain result set
     *
     * @return array  Possibly empty array of domain information
     */
    function getDomains($perms = Horde_Perms::SHOW)
    {
        $domains = $this->_getDomains();
        if (is_a($domains, 'PEAR_Error')) {
            $GLOBALS['notification']->push($domains->getMessage() . ': ' . $domains->getDebugInfo(), 'horde.warning');
            return array();
        }

        if (!Horde_Auth::isAdmin() &&
            !$GLOBALS['perms']->hasPermission('beatnik:domains', Horde_Auth::getAuth(), $perms)) {
            foreach ($domains as $id => $domain) {
                if (!$GLOBALS['perms']->hasPermission('beatnik:domains:' . $domain['zonename'], Horde_Auth::getAuth(), $perms)) {
                    unset($domains[$id]);
                }
            }
        }

        if (empty($domains)) {
            $GLOBALS['notification']->push(_("You are not permitted to view any domains."), 'horde.warning');
            return array();
        }

        // Sort the resulting list by domain name
        // TODO: Allow sorting by other columns
        require_once 'Horde/Array.php';
        Horde_Array::arraySort($domains, 'zonename');

        return $domains;
    }

    /**
     * Return SOA for a single domain
     *
     * @param string $domain   Domain for which to return SOA information
     *
     * @return mixed           Array of SOA information for domain or PEAR_Error
     *                         on failure.
     */
    function getDomain($domainname)
    {
        $domains = $this->getDomains(Horde_Perms::SHOW | Horde_Perms::READ);

        foreach ($domains as $domain) {
            if ($domain['zonename'] == $domainname) {
                return $domain;
            }
        }
        return PEAR::raiseError(sprintf(_("Unable to read requested domain %s"), $domainname));
    }

    /**
     * Gets a specific record from the backend.  This method may be overridden
     * in specific backend drivers if there is a performance or other benefit
     * for doing so.
     *
     * @return array  Array of type and record information
     */
    function getRecord($id)
    {
        if ($id === null) {
            return false;
        }

        $zonedata = $this->getRecords($_SESSION['beatnik']['curdomain']['zonename']);
        // Search for the requested record id
        foreach ($zonedata as $type => $records) {
            foreach ($records as $record) {
                if ($record['id'] == $id) {
                    // Found the record we're looking for
                    break;
                }
                $type = false;
                $record = false;
            }
            if ($record) {
                // Record found in nested loop.  Break out of this one too.
                break;
            }
        }

        if (!$record) {
            // We may be editing the SOA.  See if it matches
            $record = $this->getDomain($_SESSION['beatnik']['curdomain']['zonename']);
            if ($record['id'] == $id) {
                $type = 'soa';
            } else {
                $GLOBALS['notification']->push(_("Unable to locate requested record."), 'horde.error');
                $type = false;
                $record = false;
            }
        }

        return array($type, $record);
    }

    /**
     * Try to determinate if the autogenerated record
     * already exits.  This method may be overridden in the backend driver
     * for performance or other reasons.
     *
     * @param array $record record to check
     *
     * @return boolean if records exits or or not
     */
    function recordExists($record, $rectype)
    {
        $zonedata = $this->getRecords($_SESSION['beatnik']['curdomain']['zonename']);
        if (is_a($zonedata, 'PEAR_Error')) {
            $notification->push($zonedata, 'horde.error');
            header('Location:' . Horde::applicationUrl('listzones.php'));
            exit;
        }

        if (isset($zonedata[$rectype])) {
            foreach ($zonedata[$rectype] as $row) {
                // Prune empty values from $row to aid in comparison
                foreach ($row as $key => $value) {
                    if (empty($value)) {
                        unset($row[$key]);
                    }
                }

                $same_values = array_intersect_assoc($row, $record);
                if (count($same_values) == count($record)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Saves a record fo the configured driver and checks/sets needCommit()
     * Also first checks to ensure permission to save record is available.
     *
     * @param array $info  Data to be passed to backend driver for storage
     *
     * @return mixed  True on success, PEAR::Error on error
     */
    function saveRecord(&$info)
    {
        // Check to see if this is a new domain
        if ($info['rectype'] == 'soa' && $info['zonename'] != $_SESSION['beatnik']['curdomain']['zonename']) {
            // Make sure the user has permissions to add domains
            if (!Beatnik::hasPermission('beatnik:domains', Horde_Perms::EDIT)) {
                return PEAR::raiseError(_('You do not have permission to create new domains.'));
            }

            // Create a dummy old domain for comparison purposes
            $oldsoa['serial'] = 0;

        } else {
            $oldsoa =& $_SESSION['beatnik']['curdomain'];

            // Check for permissions to edit the record in question
            if ($info['rectype'] == 'soa') {
                $node = 'beatnik:domains:' . $info['zonename'];
                if (!Beatnik::hasPermission($node, Horde_Perms::EDIT, 1)) {
                    return PEAR::raiseError(_('You do not have permssion to edit the SOA of this zone.'));
                }
            } else {
                $node = 'beatnik:domains:' . $_SESSION['beatnik']['curdomain']['zonename'] . ':' . $info['id'];
                if (!Beatnik::hasPermission($node, Horde_Perms::EDIT, 2)) {
                    return PEAR::raiseError(_('You do not have permssion to edit this record.'));
                }
            }
        }

        // Save the changes to the backend
        // FIXME: Modify saveRecord() to return the new (possibly changed) ID of the
        // record and then use that ID to update permissions
        $return = $this->_saveRecord($info);

        $oldsoa =& $_SESSION['beatnik']['curdomain'];

        if (is_a($return, 'PEAR_Error')) {
            return $return;
        } else {
            if ($info['rectype'] == 'soa' &&
               ($oldsoa['serial'] < $info['serial'])) {
                // Clear the commit flag (if set)
                Beatnik::needCommit($oldsoa['zonename'], false);
            } else {
                Beatnik::needCommit($oldsoa['zonename'], true);
            }
        }

        // Check to see if an SOA was just added or updated.
        // If so, make it the current domain.
        if ($info['rectype'] == 'soa') {
            $_SESSION['beatnik']['curdomain'] = $this->getDomain($info['zonename']);
        }

        return true;
    }

    /**
     * Delete record from backend
     *
     * @access private
     *
     * @param array $info  Reference to array of record information for deletion
     *
     * @return boolean true on success, PEAR::raiseError on error
     */
    function deleteRecord(&$info)
    {
        $return = $this->_deleteRecord($info);

        $oldsoa =& $_SESSION['beatnik']['curdomain'];

        if (is_a($return, 'PEAR_Error')) {
            return $return;
        } else {
            // No need to commit if the whole zone is gone
            if ($info['rectype'] != 'soa') {
                Beatnik::needCommit($oldsoa['zonename'], true);
            }
        }
    }

    /**
     * Attempts to return a concrete Beatnik_Driver instance based on
     * $driver.
     *
     * @param string $driver  The type of the concrete Beatnik_Driver subclass
     *                        to return.  The class name is based on the storage
     *                        driver ($driver).  The code is dynamically
     *                        included.
     *
     * @param array  $params  (optional) A hash containing any additional
     *                        configuration or connection parameters a
     *                        subclass might need.
     *
     * @return mixed  The newly created concrete Beatnik_Driver instance, or
     *                false on an error.
     */
    function factory($driver = null, $params = null)
    {
        if ($driver === null) {
            $driver = $GLOBALS['conf']['storage']['driver'];
        }

        $driver = basename($driver);
        if (empty($driver) || ($driver == 'none')) {
            return new Horde_Lock();
        }

        if (is_null($params)) {
            // Since we have more than one backend that uses SQL make sure
            // all of them have a chance to inherit the site-wide config.
            $sqldrivers = array('sql', 'pdnsgsql');
            if (in_array($driver, $sqldrivers)) {
                $params = Horde::getDriverConfig('storage', 'sql');
            } else {
                $params = Horde::getDriverConfig('storage', $driver);
            }
        }

        require_once dirname(__FILE__) . '/Driver/' . $driver . '.php';
        $class = 'Beatnik_Driver_' . $driver;
        if (class_exists($class)) {
            return new $class($params);
        } else {
            return PEAR::raiseError(_('Driver not found.'));
        }
    }

}
