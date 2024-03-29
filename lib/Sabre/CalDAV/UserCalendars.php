<?php

/**
 * The UserCalenders class contains all calendars associated to one user
 *
 * @package Sabre
 * @subpackage CalDAV
 * @copyright Copyright (C) 2007-2012 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_CalDAV_UserCalendars implements Sabre_DAV_IExtendedCollection, Sabre_DAVACL_IACL {

    /**
     * Principal backend
     *
     * @var Sabre_DAVACL_IPrincipalBackend
     */
    protected $principalBackend;

    /**
     * CalDAV backend
     *
     * @var Sabre_CalDAV_Backend_Abstract
     */
    protected $caldavBackend;

    /**
     * Principal information
     *
     * @var array
     */
    protected $principalInfo;

    /**
     * Constructor
     *
     * @param Sabre_DAVACL_IPrincipalBackend $principalBackend
     * @param Sabre_CalDAV_Backend_Abstract $caldavBackend
     * @param mixed $userUri
     */
    public function __construct(Sabre_DAVACL_IPrincipalBackend $principalBackend, Sabre_CalDAV_Backend_Abstract $caldavBackend, $userUri) {

        $this->principalBackend = $principalBackend;
        $this->caldavBackend = $caldavBackend;
        $this->principalInfo = $principalBackend->getPrincipalByPath($userUri);

    }

    /**
     * Returns the name of this object
     *
     * @return string
     */
    public function getName() {

        list(,$name) = Sabre_DAV_URLUtil::splitPath($this->principalInfo['uri']);
        return $name;

    }

    /**
     * Updates the name of this object
     *
     * @param string $name
     * @return void
     */
    public function setName($name) {

        throw new Sabre_DAV_Exception_Forbidden();

    }

    /**
     * Deletes this object
     *
     * @return void
     */
    public function delete() {

        throw new Sabre_DAV_Exception_Forbidden();

    }

    /**
     * Returns the last modification date
     *
     * @return int
     */
    public function getLastModified() {

        return null;

    }

    /**
     * Creates a new file under this object.
     *
     * This is currently not allowed
     *
     * @param string $filename
     * @param resource $data
     * @return void
     */
    public function createFile($filename, $data=null) {

        throw new Sabre_DAV_Exception_MethodNotAllowed('Creating new files in this collection is not supported');

    }

    /**
     * Creates a new directory under this object.
     *
     * This is currently not allowed.
     *
     * @param string $filename
     * @return void
     */
    public function createDirectory($filename) {

        throw new Sabre_DAV_Exception_MethodNotAllowed('Creating new collections in this collection is not supported');

    }

    /**
     * Returns a single calendar, by name
     *
     * @param string $name
     * @todo needs optimizing
     * @return Sabre_CalDAV_Calendar
     */
    public function getChild($name) {

        foreach($this->getChildren() as $child) {
            if ($name==$child->getName())
                return $child;

        }
        throw new Sabre_DAV_Exception_NotFound('Calendar with name \'' . $name . '\' could not be found');

    }

    /**
     * Checks if a calendar exists.
     *
     * @param string $name
     * @todo needs optimizing
     * @return bool
     */
    public function childExists($name) {

        foreach($this->getChildren() as $child) {
            if ($name==$child->getName())
                return true;

        }
        return false;

    }

    /**
     * Returns a list of calendars
     *
     * @return array
     */
    public function getChildren() {

        $calendars = $this->caldavBackend->getCalendarsForUser($this->principalInfo['uri']);
        $objs = array();
        foreach($calendars as $calendar) {
            $objs[] = new Sabre_CalDAV_Calendar($this->principalBackend, $this->caldavBackend, $calendar);
        }
        $objs[] = new Sabre_CalDAV_Schedule_Outbox($this->principalInfo['uri']);
        return $objs;

    }

    /**
     * Creates a new calendar
     *
     * @param string $name
     * @param array $resourceType
     * @param array $properties
     * @return void
     */
    public function createExtendedCollection($name, array $resourceType, array $properties) {

        if (!in_array('{urn:ietf:params:xml:ns:caldav}calendar',$resourceType) || count($resourceType)!==2) {
            throw new Sabre_DAV_Exception_InvalidResourceType('Unknown resourceType for this collection');
        }
        $this->caldavBackend->createCalendar($this->principalInfo['uri'], $name, $properties);

    }

    /**
     * Returns the owner principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getOwner() {

        return $this->principalInfo['uri'];

    }

    /**
     * Returns a group principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getGroup() {

        return null;

    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
     *     currently the only supported privileges
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to
     *      be updated.
     *
     * @return array
     */
    public function getACL() {

        return array(
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'],
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'],
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-read',
                'protected' => true,
            ),

        );

    }

    /**
     * Updates the ACL
     *
     * This method will receive a list of new ACE's.
     *
     * @param array $acl
     * @return void
     */
    public function setACL(array $acl) {

        throw new Sabre_DAV_Exception_MethodNotAllowed('Changing ACL is not yet supported');

    }

    /**
     * Returns the list of supported privileges for this node.
     *
     * The returned data structure is a list of nested privileges.
     * See Sabre_DAVACL_Plugin::getDefaultSupportedPrivilegeSet for a simple
     * standard structure.
     *
     * If null is returned from this method, the default privilege set is used,
     * which is fine for most common usecases.
     *
     * @return array|null
     */
    public function getSupportedPrivilegeSet() {

        return null;

    }

}
