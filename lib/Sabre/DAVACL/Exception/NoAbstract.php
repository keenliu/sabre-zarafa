<?php

/**
 * Sabre_DAVACL_Exception_NoAbstract
 *
 * @package Sabre
 * @subpackage DAVACL
 * @copyright Copyright (C) 2007-2012 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAVACL_Exception_NoAbstract extends Sabre_DAV_Exception_PreconditionFailed {

    /**
     * Adds in extra information in the xml response.
     *
     * This method adds the {DAV:}no-abstract element as defined in rfc3744
     *
     * @param Sabre_DAV_Server $server
     * @param DOMElement $errorNode
     * @return void
     */
    public function serialize(Sabre_DAV_Server $server,DOMElement $errorNode) {

        $doc = $errorNode->ownerDocument;

        $np = $doc->createElementNS('DAV:','d:no-abstract');
        $errorNode->appendChild($np);

    }

}
