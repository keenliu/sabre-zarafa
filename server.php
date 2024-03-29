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

 	// Load configuration file
	define('BASE_PATH', dirname(__FILE__) . "/");

	// Change include path
	set_include_path(get_include_path() . PATH_SEPARATOR . "/usr/share/php/" . PATH_SEPARATOR . BASE_PATH . "lib/");

	// Logging and error handling
	include_once ("log4php/Logger.php");
	Logger::configure("log4php.xml");
	$log = Logger::getLogger("server");
	
	error_reporting(E_ALL);
	ini_set("display_errors", true);
	ini_set("html_errors", false);

	//Mapping PHP errors to exceptions
	function exception_error_handler($errno, $errstr, $errfile, $errline ) {
		global $log;
		$log->fatal("PHP error $errno in $errfile:$errline : $errstr");
		throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
	}
	set_error_handler("exception_error_handler");
	
	// Include Zarafa SabreDav Bridge
	include ("./ZarafaBridge.php");
	
	//Sabre DAV
	include("Sabre/autoload.php");
	
	// Custom classes to tie together SabreDav and Zarafa
	include "ZarafaAuthBackend.php";			// Authentification
	include "ZarafaCardDavBackend.php";			// CardDav
	include "ZarafaPrincipalsBackend.php";		// Principals
	
	function checkMapiError($msg) {
		global $log;
		if (mapi_last_hresult() != 0) {
			$log->warn("MAPI error $msg: " . get_mapi_error_name());
			exit;
		}
	}
	
	// Zarafa bridge
	$log->trace("Init bridge");
	$bridge = new Zarafa_Bridge();
	
	// Backends
	$log->trace("Loading backends");
	$authBackend      = new Zarafa_Auth_Basic_Backend($bridge);
	$principalBackend = new Zarafa_Principals_Backend($bridge);
	$carddavBackend   = new Zarafa_CardDav_Backend($bridge); 

	// Setting up the directory tree // 
	$nodes = array(
		new Sabre_DAVACL_PrincipalCollection($principalBackend),
		new Sabre_CardDAV_AddressBookRoot($principalBackend, $carddavBackend)
	);

	// The object tree needs in turn to be passed to the server class
	$log->trace("Starting server");
	$server = new Sabre_DAV_Server($nodes);
	$server->setBaseUri(CARDDAV_ROOT_URI);

	// Required plugins 
	$log->trace("Adding plugins");
	$server->addPlugin(new Sabre_DAV_Auth_Plugin($authBackend, SABRE_AUTH_REALM));
	$server->addPlugin(new Sabre_CardDAV_Plugin());
	$server->addPlugin(new Sabre_DAVACL_Plugin());

	// Optional plugins
	if (SABRE_DAV_BROWSER_PLUGIN) {
		// Do not allow POST
		$server->addPlugin(new Sabre_DAV_Browser_Plugin(false));
	}
	
	// Start server
	$log->trace("Server exec");
	$log->info("SabreDAV version " . Sabre_DAV_Version::VERSION . '-' . Sabre_DAV_Version::STABILITY);
	$log->info("Producer: " . VCARD_PRODUCT_ID );
	$log->info("Revision: " . (SABRE_ZARAFA_REV + 1) . ' - ' . SABRE_ZARAFA_DATE);
	$server->exec();	
	
?>