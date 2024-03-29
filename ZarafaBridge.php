<?php
/*
 * Copyright 2011 - 2012 Guillaume Lapierre
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3, 
 * as published by the Free Software Foundation.
 *  
 * "Zarafa" is a registered trademark of Zarafa B.V. 
 *
 * This software use SabreDAV, an open source software distributed
 * with New BSD License. Please see <http://code.google.com/p/sabredav/>
 * for more information about SabreDAV
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *  
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Project page: <http://code.google.com/p/sabre-zarafa/>
 * 
 */

        // Load config and common
        include (BASE_PATH . "config.inc.php");
        include (BASE_PATH . "version.inc.php");
        include (BASE_PATH . "common.inc.php");

        // Logging
        include_once ("log4php/Logger.php");
        Logger::configure("log4php.xml");
        
        // PHP-MAPI
        require_once("mapi/mapi.util.php");
        require_once("mapi/mapicode.php");
        require_once("mapi/mapidefs.php");
        require_once("mapi/mapitags.php");
        require_once("mapi/mapiguid.php");
        
        // VObject for vcard
        include_once "Sabre/VObject/includes.php";
        
        // VObject to mapi properties
        require_once "vcard/IVCardParser.php";          // too many vcard formats :(
        include_once "vcard/VCardParser2.php";
        include_once "vcard/VCardParser3.php";
        include_once "vcard/VCardParser4.php";
        require_once "vcard/IVCardProducer.php";
        include_once "vcard/VCardProducer.php";
        
/**
 * This is main class for Sabre backends
 */
 
class Zarafa_Bridge {

        protected $session;
        protected $store;
        protected $rootFolder;
        protected $rootFolderId;
        protected $pubStore;
        protected $pubFolders;
        protected $extendedProperties;
        protected $connectedUser;
        protected $adressBooks;
        protected $pubFolderIds = array();
        private $logger;

        /**
         * Constructor
         */
        public function __construct() {
                // Stores a reference to Zarafa Auth Backend so as to get the session
                $this->logger = Logger::getLogger(__CLASS__);
        }
        
        /**
         * Connect to Zarafa and do some init
         * @param $user user login
         * @param $password user password
         */
        public function connect($user, $password) {
        
                $this->logger->debug("connect($user," . md5($password) . ")");
                $this->session = NULL;
                
                try {
                        $session = mapi_logon_zarafa($user, $password, ZARAFA_SERVER);
                } catch (Exception $e) {
                        $this->logger->debug("connection failed: " . get_mapi_error_name());
                        return false;
                }

                if ($session === FALSE) {
                        // Failed
                        return false;
                }

                $this->logger->trace("Connected to zarafa server - init bridge");
                $this->session = $session;

                // Find user store
                $storesTable = mapi_getmsgstorestable($session);
                $stores = mapi_table_queryallrows($storesTable, array(PR_ENTRYID, PR_MDB_PROVIDER));
                for($i = 0; $i < count($stores); $i++) {
                        switch ($stores[$i][PR_MDB_PROVIDER]) {
                                case ZARAFA_SERVICE_GUID:
                                        $storeEntryid = $stores[$i][PR_ENTRYID];
                                        break;

                                case ZARAFA_STORE_PUBLIC_GUID:
                                        $pubStoreEntryid = $stores[$i][PR_ENTRYID];
                                        break;
                        }
                }

                if (!isset($storeEntryid)) {
                        trigger_error("Default store not found", E_USER_ERROR);
                }

                $this->store = mapi_openmsgstore($this->session, $storeEntryid);
                $root = mapi_msgstore_openentry($this->store, null);
                $rootProps = mapi_getprops($root, array(PR_IPM_CONTACT_ENTRYID));

                // Store rootfolder
                $this->rootFolder   = mapi_msgstore_openentry($this->store, $rootProps[PR_IPM_CONTACT_ENTRYID]);
                $this->rootFolderId = $rootProps[PR_IPM_CONTACT_ENTRYID];

                if (isset($pubStoreEntryid)) {
                        $this->pubStore = mapi_openmsgstore($this->session, $pubStoreEntryid);

                        $pubFolder=mapi_msgstore_openentry($this->pubStore);
                        $h_table=mapi_folder_gethierarchytable($pubFolder , CONVENIENT_DEPTH);
                        // $subfolders = mapi_table_queryallrows($h_table, array(PR_ENTRYID, PR_DISPLAY_NAME));
                        $subfolders = mapi_table_queryallrows($h_table);

                        for ( $i = 0; $i < count($subfolders); $i++) {
                                $name = $subfolders[$i][PR_DISPLAY_NAME];
                                $entryid = $subfolders[$i][PR_ENTRYID];
                                $folder = mapi_msgstore_openentry($this->pubStore, $entryid);
                                $props = mapi_getprops($folder);

                                if (isset($props[PR_CONTAINER_CLASS_A]) && $props[PR_CONTAINER_CLASS_A] == "IPF.Contact") {
                                        $this->pubFolderIds[] = $entryid;
                                        $this->pubFolders[] = mapi_msgstore_openentry($this->pubStore, $entryid);
                                }
                        }
                }

                // Check for unicode
                $this->isUnicodeStore($this->store);

                // Load properties
                $this->initProperties();
                
                // Store username for principals
                $this->connectedUser = $user;
                
                // Set protected variable to NULL.
                $this->adressBooks = NULL;
                
                return true;
        }
        
        /**
         * Get MAPI session 
         * @return MAPI session
         */
        public function getMapiSession() {
                $this->logger->trace("getMapiSession");
                return $this->session;
        }
        
        /**
         * Get user store
         * @return user store
         */
        public function getStore($addressBookId) {
          if (in_array($addressBookId, $this->pubFolderIds)) {
                $this->logger->trace("getStore for Public");
                return $this->pubStore;
          }
          else {
                $this->logger->trace("getStore for Private");
                return $this->store;
          }
        }
        
        /**
         * Get root folder
         * @return root folder
         */
        public function getRootFolder() {
                $this->logger->trace("getRootFolder");
                return $this->rootFolder;
        }
        
        /**
         * Get connected user login 
         * @return connected user
         */
        public function getConnectedUser() {
                $this->logger->trace("getConnectedUser");
                return $this->connectedUser;
        }
        
        public function getExtendedProperties() {
                $this->logger->trace("getExtendedProperties");
                return $this->extendedProperties;
        }
        
        /**
         * Get connected user email address
         * @return email address
         */
        public function getConnectedUserMailAddress() {
                $this->logger->trace("getConnectedUserMailAddress");
                $userInfo = mapi_zarafa_getuser_by_name($this->store, $this->connectedUser);
                
                $this->logger->debug("User email address: " . $userInfo["emailaddress"]);
                return $userInfo["emailaddress"];
        }
        
        /**
         * Get list of addressbooks
         */
        public function getAdressBooks() {
                $this->logger->trace("getAdressBooks");
                
                if ($this->adressBooks === NULL) {
                        $this->logger->debug("Building list of address books");
                        $this->adressBooks = array();
                        $this->buildAdressBooks($this->store, '', $this->rootFolder, $this->rootFolderId);

                        for ($i = 0;  $i < count($this->pubFolders);  $i++) {
                          $this->buildAdressBooks($this->pubStore, '', $this->pubFolders[$i], $this->pubFolderIds[$i]);
                        }
                }
                return $this->adressBooks;
        }
        
        /**
         * Build user list of adress books
         * Recursively find folders in Zarafa
         */
        private function buildAdressBooks($store, $prefix, $folder, $parentFolderId) {
                $this->logger->trace("buildAdressBooks");
                
                $folderProperties = mapi_getprops($folder);
                $currentFolderName = $this->to_charset($folderProperties[PR_DISPLAY_NAME]);
                
                // Compute CTag - issue 8: ctag should be the max of PR_LAST_MODIFICATION_TIME of contacts
                // of the folder.
                $this->logger->trace("Computing CTag for address book " . $folderProperties[PR_DISPLAY_NAME]);
                $ctag = $folderProperties[PR_LAST_MODIFICATION_TIME];
                
                $contactsTable = mapi_folder_getcontentstable($folder);
                $contacts      = mapi_table_queryallrows($contactsTable, array(PR_LAST_MODIFICATION_TIME));

                // Contact count
                $contactCount = mapi_table_getrowcount($contactsTable);
                $storedContactCount = isset($folderProperties[PR_CARDDAV_AB_CONTACT_COUNT]) ? $folderProperties[PR_CARDDAV_AB_CONTACT_COUNT] : 0;

                $this->logger->trace("Contact count: $contactCount");
                $this->logger->trace("Stored contact count: $storedContactCount");
                
                if ($contactCount <> $storedContactCount) {
                        $this->logger->trace("Contact count != stored contact count");
                        $ctag = time();
                        mapi_setprops($folder, array(PR_CARDDAV_AB_CONTACT_COUNT => $contactCount, PR_LAST_MODIFICATION_TIME => $ctag));
                        mapi_savechanges($folder);
                } else {
                        foreach ($contacts as $c) {
                                if ($c[PR_LAST_MODIFICATION_TIME] > $ctag) {
                                        $ctag = $c[PR_LAST_MODIFICATION_TIME];
                                        $this->logger->trace("Found new ctag: $ctag");
                                }
                        }
                }
                
                // Add address book
                $displayPrefix = "";

                if ($store === $this->pubStore) {
                  $displayPrefix = "Public ";
                }

                $this->adressBooks[$folderProperties[PR_ENTRYID]] = array(
                        'id'          => $folderProperties[PR_ENTRYID],
                        'displayname' => $displayPrefix . $folderProperties[PR_DISPLAY_NAME],
                        'prefix'      => $prefix,
                        'description' => (isset($folderProperties[805568542]) ? $folderProperties[805568542] : ''),
                        'ctag'        => $ctag,
                        'parentId'    => $parentFolderId
                );
                
                // Get subfolders
                $foldersTable = mapi_folder_gethierarchytable ($folder);
                $folders      = mapi_table_queryallrows($foldersTable);
                foreach ($folders as $f) {
                        $subFold = mapi_msgstore_openentry($store, $f[PR_ENTRYID]);
                        $this->buildAdressBooks ($store, $prefix . $currentFolderName . "/", $subFold, $folderProperties[PR_ENTRYID]);
                }
        }
        
        /**
         * Get properties from mapi
         * @param $entryId
         */
        public function getProperties($addressBookId, $entryId) {
                $this->logger->trace("getProperties(" . bin2hex($entryId) . ")");
                $mapiObject = mapi_msgstore_openentry($this->getStore($addressBookId), $entryId);
                $props = mapi_getprops($mapiObject);
                return $props;
        }
        
        /**
         * Convert an entryId to a human readable string
         */
        public function entryIdToStr($entryId) {
                return bin2hex($entryId);
        }
        
        /**
         * Convert a human readable string to an entryid
         */
        public function strToEntryId($str) {
                // Check if $str is a valid Zarafa entryID. If not returns 0
                if (!preg_match('/^[0-9a-zA-Z]*$/', $str)) {
                        return 0;
                } 
                
                return pack("H*", $str);
        }
        
        /**
         * Convert vcard data to an array of MAPI properties
         * @param $vcardData
         * @return array
         */
        public function vcardToMapiProperties($vcardData) {
                $this->logger->trace("vcardToMapiProperties");

                $this->logger->debug("VCARD:\n" . $vcardData);
                $vObject = Sabre_VObject_Reader::read($vcardData);

                // Extract version to call the correct parser
                $version = $vObject->version->value;
                $majorVersion = substr($version, 0, 1);
                
                $objectClass = "VCardParser$majorVersion";
                $this->logger->debug("Using $objectClass to parse vcard data");
                $parser = new $objectClass($this);
                
                $properties = array();
                $parser->vObjectToProperties($vObject, $properties);
                
                $dump = '';
                ob_start();
                print_r ($properties);
                $dump = ob_get_contents();
                ob_end_clean();
                
                $this->logger->debug("VCard properties:\n" . $dump);
                
                return $properties;
        }
        
        /**
         * Retrieve vCard for a contact. If need be will "build" the vCard data
         * @see RFC6350 http://tools.ietf.org/html/rfc6350
         * @param $contactId contact EntryID
         * @return VCard 4 UTF-8 encoded content
         */
        public function getContactVCard($addressBookId, $contactId) {

                $this->logger->trace("getContactVCard(" . bin2hex($contactId) . ")");
        
                $contact = mapi_msgstore_openentry($this->getStore($addressBookId), $contactId);
                $contactProperties = $this->getProperties($addressBookId, $contactId);
                $p = $this->extendedProperties;

                $this->logger->trace("PR_CARDDAV_RAW_DATA: " . PR_CARDDAV_RAW_DATA);
                $this->logger->trace("PR_CARDDAV_RAW_DATA_GENERATION_TIME: " . PR_CARDDAV_RAW_DATA_GENERATION_TIME);
                $this->logger->trace("PR_CARDDAV_RAW_DATA_VERSION: " . PR_CARDDAV_RAW_DATA_VERSION);
                $this->logger->debug("CACHE VERSION: " . CACHE_VERSION);
                
                // dump properties
                $dump = print_r($contactProperties, true);
                $this->logger->trace("Contact properties:\n$dump");
                
                if (SAVE_RAW_VCARD && isset($contactProperties[PR_CARDDAV_RAW_DATA])) {
                        // Check if raw vCard is up-to-date
                        $vcardGenerationTime = $contactProperties[PR_CARDDAV_RAW_DATA_GENERATION_TIME];
                        $lastModifiedDate    = $contactProperties[$p['last_modification_time']];
                        
                        // Get cache version
                        $vcardCacheVersion = isset($contactProperties[PR_CARDDAV_RAW_DATA_VERSION]) ? $contactProperties[PR_CARDDAV_RAW_DATA_VERSION] : 'NONE';
                        $this->logger->trace("Saved vcard cache version: " . $vcardCacheVersion);
                        
                        if (($vcardGenerationTime >= $lastModifiedDate) && ($vcardCacheVersion == CACHE_VERSION)) {
                                $this->logger->debug("Using saved vcard");
                                return $contactProperties[PR_CARDDAV_RAW_DATA];
                        } else {
                                $this->logger->trace("Contact modified or new version of Sabre-Zarafa");
                        }
                } else {
                        if (SAVE_RAW_VCARD) {
                                $this->logger->trace("No saved raw vcard");
                        } else {
                                $this->logger->trace("Generation of vcards forced by config");
                        }
                }
        
                $producer = new VCardProducer($this, VCARD_VERSION);
                $vCard = new Sabre_VObject_Component('VCARD');

                // Produce VCard object
                $this->logger->trace("Producing vcard from contact properties");
                $producer->propertiesToVObject($contact, $vCard);
                
                // Serialize
                $vCardData = $vCard->serialize();
                $this->logger->debug("Produced VCard\n" . $vCardData);
                
                // Charset conversion?
                $targetCharset = (VCARD_CHARSET == '') ? $producer->getDefaultCharset() : VCARD_CHARSET;
                
                if ($targetCharset != 'utf-8') {
                        $this->logger->debug("Converting from UTF-8 to $targetCharset");
                        $vCardData = iconv("UTF-8", $targetCharset, $vCardData);
                }
                
                if (SAVE_RAW_VCARD) {
                        $this->logger->debug("Saving vcard to contact properties");
                        // Check if raw vCard is up-to-date
                        mapi_setprops($contact, array(
                                        PR_CARDDAV_RAW_DATA => $vCardData,
                                        PR_CARDDAV_RAW_DATA_VERSION => CACHE_VERSION,
                                        PR_CARDDAV_RAW_DATA_GENERATION_TIME => time()
                        ));

                        if (mapi_last_hresult() > 0) {
                                $this->logger->warn("Error setting contact properties: " . get_mapi_error_name());
                        } 

                        mapi_savechanges($contact);
                        
                        if (mapi_last_hresult() > 0) {
                                $this->logger->warn("Error saving vcard to contact: " . get_mapi_error_name());
                        } else {
                                $this->logger->trace("VCard successfully added to contact properties");
                        }
                }
                
                return $vCardData;
        }
        
        /**
         * Init properties to read contact data
         */
        protected function initProperties() {
                $this->logger->trace("initProperties");
                
                $properties = array();
                $properties["subject"] = PR_SUBJECT;
                $properties["icon_index"] = PR_ICON_INDEX;
                $properties["message_class"] = PR_MESSAGE_CLASS;
                $properties["display_name"] = PR_DISPLAY_NAME;
                $properties["given_name"] = PR_GIVEN_NAME;
                $properties["middle_name"] = PR_MIDDLE_NAME;
                $properties["surname"] = PR_SURNAME;
                $properties["home_telephone_number"] = PR_HOME_TELEPHONE_NUMBER;
                $properties["cellular_telephone_number"] = PR_CELLULAR_TELEPHONE_NUMBER;
                $properties["office_telephone_number"] = PR_OFFICE_TELEPHONE_NUMBER;
                $properties["business_fax_number"] = PR_BUSINESS_FAX_NUMBER;
                $properties["company_name"] = PR_COMPANY_NAME;
                $properties["title"] = PR_TITLE;
                $properties["department_name"] = PR_DEPARTMENT_NAME;
                $properties["office_location"] = PR_OFFICE_LOCATION;
                $properties["profession"] = PR_PROFESSION;
                $properties["manager_name"] = PR_MANAGER_NAME;
                $properties["assistant"] = PR_ASSISTANT;
                $properties["nickname"] = PR_NICKNAME;
                $properties["display_name_prefix"] = PR_DISPLAY_NAME_PREFIX;
                $properties["spouse_name"] = PR_SPOUSE_NAME;
                $properties["generation"] = PR_GENERATION;
                $properties["birthday"] = PR_BIRTHDAY;
                $properties["wedding_anniversary"] = PR_WEDDING_ANNIVERSARY;
                $properties["sensitivity"] = PR_SENSITIVITY;
                $properties["fileas"] = "PT_STRING8:PSETID_Address:0x8005";
                $properties["fileas_selection"] = "PT_LONG:PSETID_Address:0x8006";
                $properties["email_address_1"] = "PT_STRING8:PSETID_Address:0x8083";
                $properties["email_address_display_name_1"] = "PT_STRING8:PSETID_Address:0x8080";
                $properties["email_address_display_name_email_1"] = "PT_STRING8:PSETID_Address:0x8084";
                $properties["email_address_type_1"] = "PT_STRING8:PSETID_Address:0x8082";
                $properties["email_address_2"] = "PT_STRING8:PSETID_Address:0x8093";
                $properties["email_address_display_name_2"] = "PT_STRING8:PSETID_Address:0x8090";
                $properties["email_address_display_name_email_2"] = "PT_STRING8:PSETID_Address:0x8094";
                $properties["email_address_type_2"] = "PT_STRING8:PSETID_Address:0x8092";
                $properties["email_address_3"] = "PT_STRING8:PSETID_Address:0x80a3";
                $properties["email_address_display_name_3"] = "PT_STRING8:PSETID_Address:0x80a0";
                $properties["email_address_display_name_email_3"] = "PT_STRING8:PSETID_Address:0x80a4";
                $properties["email_address_type_3"] = "PT_STRING8:PSETID_Address:0x80a2";
                $properties["home_address"] = "PT_STRING8:PSETID_Address:0x801a";
                $properties["business_address"] = "PT_STRING8:PSETID_Address:0x801b";
                $properties["other_address"] = "PT_STRING8:PSETID_Address:0x801c";
                $properties["mailing_address"] = "PT_LONG:PSETID_Address:0x8022";
                $properties["im"] = "PT_STRING8:PSETID_Address:0x8062";
                $properties["webpage"] = "PT_STRING8:PSETID_Address:0x802b";
                $properties["business_home_page"] = PR_BUSINESS_HOME_PAGE;
                $properties["email_address_entryid_1"] = "PT_BINARY:PSETID_Address:0x8085";
                $properties["email_address_entryid_2"] = "PT_BINARY:PSETID_Address:0x8095";
                $properties["email_address_entryid_3"] = "PT_BINARY:PSETID_Address:0x80a5";
                $properties["address_book_mv"] = "PT_MV_LONG:PSETID_Address:0x8028";
                $properties["address_book_long"] = "PT_LONG:PSETID_Address:0x8029";
                $properties["oneoff_members"] = "PT_MV_BINARY:PSETID_Address:0x8054";
                $properties["members"] = "PT_MV_BINARY:PSETID_Address:0x8055";
                $properties["private"] = "PT_BOOLEAN:PSETID_Common:0x8506";
                $properties["contacts"] = "PT_MV_STRING8:PSETID_Common:0x853a";
                $properties["contacts_string"] = "PT_STRING8:PSETID_Common:0x8586";
                $properties["categories"] = "PT_MV_STRING8:PS_PUBLIC_STRINGS:Keywords";
                $properties["last_modification_time"] = PR_LAST_MODIFICATION_TIME;

                // Detailed contacts properties
                // Properties for phone numbers
                $properties["assistant_telephone_number"] = PR_ASSISTANT_TELEPHONE_NUMBER;
                $properties["business2_telephone_number"] = PR_BUSINESS2_TELEPHONE_NUMBER;
                $properties["callback_telephone_number"] = PR_CALLBACK_TELEPHONE_NUMBER;
                $properties["car_telephone_number"] = PR_CAR_TELEPHONE_NUMBER;
                $properties["company_telephone_number"] = PR_COMPANY_MAIN_PHONE_NUMBER;
                $properties["home2_telephone_number"] = PR_HOME2_TELEPHONE_NUMBER;
                $properties["home_fax_number"] = PR_HOME_FAX_NUMBER;
                $properties["isdn_number"] = PR_ISDN_NUMBER;
                $properties["other_telephone_number"] = PR_OTHER_TELEPHONE_NUMBER;
                $properties["pager_telephone_number"] = PR_PAGER_TELEPHONE_NUMBER;
                $properties["primary_fax_number"] = PR_PRIMARY_FAX_NUMBER;
                $properties["primary_telephone_number"] = PR_PRIMARY_TELEPHONE_NUMBER;
                $properties["radio_telephone_number"] = PR_RADIO_TELEPHONE_NUMBER;
                $properties["telex_telephone_number"] = PR_TELEX_NUMBER;
                $properties["ttytdd_telephone_number"] = PR_TTYTDD_PHONE_NUMBER;
                // Additional fax properties
                $properties["fax_1_address_type"] = "PT_STRING8:PSETID_Address:0x80B2";
                $properties["fax_1_email_address"] = "PT_STRING8:PSETID_Address:0x80B3";
                $properties["fax_1_original_display_name"] = "PT_STRING8:PSETID_Address:0x80B4";
                $properties["fax_1_original_entryid"] = "PT_BINARY:PSETID_Address:0x80B5";
                $properties["fax_2_address_type"] = "PT_STRING8:PSETID_Address:0x80C2";
                $properties["fax_2_email_address"] = "PT_STRING8:PSETID_Address:0x80C3";
                $properties["fax_2_original_display_name"] = "PT_STRING8:PSETID_Address:0x80C4";
                $properties["fax_2_original_entryid"] = "PT_BINARY:PSETID_Address:0x80C5";
                $properties["fax_3_address_type"] = "PT_STRING8:PSETID_Address:0x80D2";
                $properties["fax_3_email_address"] = "PT_STRING8:PSETID_Address:0x80D3";
                $properties["fax_3_original_display_name"] = "PT_STRING8:PSETID_Address:0x80D4";
                $properties["fax_3_original_entryid"] = "PT_BINARY:PSETID_Address:0x80D5";

                // Properties for addresses
                // Home address
                $properties["home_address_street"] = PR_HOME_ADDRESS_STREET;
                $properties["home_address_city"] = PR_HOME_ADDRESS_CITY;
                $properties["home_address_state"] = PR_HOME_ADDRESS_STATE_OR_PROVINCE;
                $properties["home_address_postal_code"] = PR_HOME_ADDRESS_POSTAL_CODE;
                $properties["home_address_country"] = PR_HOME_ADDRESS_COUNTRY;
                // Other address
                $properties["other_address_street"] = PR_OTHER_ADDRESS_STREET;
                $properties["other_address_city"] = PR_OTHER_ADDRESS_CITY;
                $properties["other_address_state"] = PR_OTHER_ADDRESS_STATE_OR_PROVINCE;
                $properties["other_address_postal_code"] = PR_OTHER_ADDRESS_POSTAL_CODE;
                $properties["other_address_country"] = PR_OTHER_ADDRESS_COUNTRY;
                // Business address
                $properties["business_address_street"] = "PT_STRING8:PSETID_Address:0x8045";
                $properties["business_address_city"] = "PT_STRING8:PSETID_Address:0x8046";
                $properties["business_address_state"] = "PT_STRING8:PSETID_Address:0x8047";
                $properties["business_address_postal_code"] = "PT_STRING8:PSETID_Address:0x8048";
                $properties["business_address_country"] = "PT_STRING8:PSETID_Address:0x8049";
                // Mailing address
                $properties["country"] = PR_COUNTRY;
                $properties["city"] = PR_LOCALITY;
                $properties["postal_address"] = PR_POSTAL_ADDRESS;
                $properties["postal_code"] = PR_POSTAL_CODE;
                $properties["state"] = PR_STATE_OR_PROVINCE;
                $properties["street"] = PR_STREET_ADDRESS;
                // Special Date such as birthday n anniversary appoitment's entryid is store
                $properties["birthday_eventid"] = "PT_BINARY:PSETID_Address:0x804D";
                $properties["anniversary_eventid"] = "PT_BINARY:PSETID_Address:0x804E";

                $properties["notes"] = PR_BODY;
                
                // Has contact picture
                $properties["has_picture"] = "PT_BOOLEAN:{00062004-0000-0000-C000-000000000046}:0x8015";
                
                // Custom properties needed for carddav functionnality
                $properties["carddav_uri"] = PR_CARDDAV_URI;
                $properties["carddav_rawdata"] = PR_CARDDAV_RAW_DATA;
                $properties["carddav_generation_time"] = PR_CARDDAV_RAW_DATA_GENERATION_TIME;
                $properties["contact_count"] = PR_CARDDAV_AB_CONTACT_COUNT;
                $properties["carddav_version"] = PR_CARDDAV_RAW_DATA_VERSION;
                
                // Ask Mapi to load those properties and store mapping.
                $this->extendedProperties = getPropIdsFromStrings($this->store, $properties);
                
                // Dump properties to debug
                $dump = print_r ($this->extendedProperties, true);
                $this->logger->trace("Properties init done:\n$dump");
        }
        
        /**
         * Generate a GUID using random numbers (version 4)
         * GUID are 128 bits long numbers 
         * returns string version {8-4-4-4-12}
         * Use uuid_create if php5-uuid extension is available
         */
        public function generateRandomGuid() {
                
                $this->logger->trace("generateRandomGuid");
                
                /*
                if (function_exists('uuid_create')) {
                        // Not yet tested :)
                        $this->logger->debug("Using uuid_create");
                        uuid_create($context);
                        uuid_make($context, UUID_MAKE_V4);
                        uuid_export($context, UUID_FMT_STR, $uuid);
                        return trim($uuid);
                }
                */
                
                $data1a = mt_rand(0, 0xFFFF);           // 32 bits - splited
                $data1b = mt_rand(0, 0xFFFF);
                $data2  = mt_rand(0, 0xFFFF);           // 16 bits
                $data3  = mt_rand(0, 0xFFF);            // 12 bits (last 4 bits is version generator)
                
                // data4 is 64 bits long 
                $data4a = mt_rand(0, 0xFFFF);
                $data4b = mt_rand(0, 0xFFFF);
                $data4c = mt_rand(0, 0xFFFF);
                $data4d = mt_rand(0, 0xFFFF);

                // Force variant 4 + standard for this GUID
                $data4a = ($data4a | 0x8000) & 0xBFFF;  // standard
                
                return sprintf("%04x%04x-%04x-%03x4-%04x-%04x%04x%04x", $data1a, $data1b, $data2, $data3, $data4a, $data4b, $data4c, $data4d);
        }

        /**
         * Check if store supports UTF-8 (zarafa 7+)
         * @param $store
         */
        public function isUnicodeStore($store) {
                $this->logger->trace("Testing store for unicode");
                $supportmask = mapi_getprops($store, array(PR_STORE_SUPPORT_MASK));
                if (isset($supportmask[PR_STORE_SUPPORT_MASK]) && ($supportmask[PR_STORE_SUPPORT_MASK] & STORE_UNICODE_OK)) {
                        define('STORE_SUPPORTS_UNICODE', true);
                        //setlocale to UTF-8 in order to support properties containing Unicode characters
                        setlocale(LC_CTYPE, "en_US.UTF-8");
                }
        }

        /**
         * Assign a contact picture to a contact
         * @param entryId contact entry id
         * @param contactPicture must be a valid jpeg file. If contactPicture is NULL will remove contact picture from contact if exists
         */
        public function setContactPicture(&$contact, $contactPicture) {
                $this->logger->trace("setContactPicture");
                
                // Find if contact picture is already set
                $contactAttachment = -1;
                $hasattachProp = mapi_getprops($contact, array(PR_HASATTACH));
                if ($hasattachProp) {
                        $attachmentTable = mapi_message_getattachmenttable($contact);
                        $attachments = mapi_table_queryallrows($attachmentTable, array(PR_ATTACH_NUM, PR_ATTACH_SIZE, PR_ATTACH_LONG_FILENAME, PR_ATTACH_FILENAME, PR_ATTACHMENT_HIDDEN, PR_DISPLAY_NAME, PR_ATTACH_METHOD, PR_ATTACH_CONTENT_ID, PR_ATTACH_MIME_TAG, PR_ATTACHMENT_CONTACTPHOTO, PR_EC_WA_ATTACHMENT_HIDDEN_OVERRIDE));
                        foreach ($attachments as $attachmentRow) {
                                if (isset($attachmentRow[PR_ATTACHMENT_CONTACTPHOTO]) && $attachmentRow[PR_ATTACHMENT_CONTACTPHOTO]) {
                                        $contactAttachment = $attachmentRow[PR_ATTACH_NUM];
                                        break;
                                }
                        }
                }
                
                // Remove existing attachment if necessary
                if ($contactAttachment != -1) {
                        $this->logger->trace("removing existing contact picture");
                        $attach = mapi_message_deleteattach($contact, $contactAttachment);
                }
                
                if ($contactPicture !== NULL) {
                        $this->logger->debug("Saving contact picture as attachment");

                        // Create attachment
                        $attach = mapi_message_createattach($contact);
                        
                        // Update contact attachment properties
                        $properties = array(
                                PR_ATTACH_SIZE => strlen($contactPicture),
                                PR_ATTACH_LONG_FILENAME => 'ContactPicture.jpg',
                                PR_ATTACHMENT_HIDDEN => false,
                                PR_DISPLAY_NAME => 'ContactPicture.jpg',
                                PR_ATTACH_METHOD => ATTACH_BY_VALUE,
                                PR_ATTACH_MIME_TAG => 'image/jpeg',
                                PR_ATTACHMENT_CONTACTPHOTO =>  true,
                                PR_ATTACH_DATA_BIN => $contactPicture,
                                PR_ATTACHMENT_FLAGS => 1,
                                PR_ATTACH_EXTENSION_A => '.jpg',
                                PR_ATTACH_NUM => 1
                        );
                        mapi_setprops($attach, $properties);
                        mapi_savechanges($attach);
                }       
                        
                // Test
                if (mapi_last_hresult() > 0) {
                        $this->logger->warn("Error saving contact picture: " . get_mapi_error_name());
                } else {
                        $this->logger->trace("contact picture done");
                }
        }
        
        /**
         * Convert string to UTF-8
         * you need to check unicode store to ensure valid values
         * @param $string string to convert
         */
        public function to_charset($string)     {
                //Zarafa 7 supports unicode chars, convert properties to utf-8 if it's another encoding
                if (defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) {
                        return $string;
                }

                return utf8_encode($string);

        }
}

?>