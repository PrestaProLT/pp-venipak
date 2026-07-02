<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Api;

class ShipmentXmlBuilder
{
    /**
     * Address fields shared by consignor / sender / consignee /
     * return_consignee / return_doc_consignee / receiver.
     */
    private const ADDRESS_FIELDS = [
        'name', 'company_code', 'country', 'state_code', 'city',
        'address', 'post_code', 'contact_person',
        'contact_tel', 'contact_email',
    ];

    /**
     * Top-level attribute fields (Postman: Shipment data import).
     */
    private const ATTRIBUTE_FIELDS = [
        'shipment_code', 'delivery_type', 'delivery_mode',
        'return_doc', 'doc_no',
        'cod', 'cod_type',
        'return_passport', 'check_id_card',
        'comment_door_code', 'comment_office_no', 'comment_warehous_no',
        'comment_call', 'comment_text',
        'insurance', 'four_hands', 'min_age',
        'return_service',
    ];

    /**
     * Pack fields (Postman: Shipment data import / pack block).
     */
    private const PACK_FIELDS = [
        'pack_no', 'doc_no',
        'weight', 'volume',
        'length', 'width', 'height',
        'description',
        'return_tare', 'split',
    ];

    /**
     * Allowed values for global_delivery (Postman: <global> block).
     */
    private const GLOBAL_DELIVERY_TYPES = ['global', 'express', 'economy_express', 'economy2'];

    /**
     * Allowed values for document doc_type (Postman: <global><document>).
     */
    private const GLOBAL_DOC_TYPES = ['certificate_of_origin', 'commercial_invoice', 'pro_forma_invoice'];

    private \DOMDocument $doc;
    private \DOMElement $manifest;
    private bool $hasInternational = false;
    private bool $hasGlobal = false;
    private int $shipmentCount = 0;

    public function __construct(string $manifestNumber, string $shopName)
    {
        $this->doc = new \DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        $description = $this->doc->createElement('description');
        $description->setAttribute('type', '1');
        $this->doc->appendChild($description);

        $this->manifest = $this->doc->createElement('manifest');
        $this->manifest->setAttribute('title', $manifestNumber);
        $this->manifest->setAttribute('name', $shopName);
        // Ask the API to echo Venipak shipment numbers and COD info in the response.
        $this->manifest->setAttribute('show_shipment_no', '1');
        $this->manifest->setAttribute('show_shipment_cod', '1');
        $description->appendChild($this->manifest);
    }

    /**
     * Append a shipment to the manifest. Supported keys in $shipmentData:
     *  - consignor, sender, consignee (required), return_consignee, receiver
     *  - attribute (associative; may contain `return_doc_consignee`,
     *    `international` and `global` sub-arrays)
     *  - packs (list of associative arrays)
     */
    public function addShipment(array $shipmentData): self
    {
        $shipment = $this->doc->createElement('shipment');

        // Optional address blocks first (consignor printed on label if set; sender = load place)
        foreach (['consignor', 'sender'] as $tag) {
            if (!empty($shipmentData[$tag])) {
                $shipment->appendChild($this->buildAddressElement($tag, $shipmentData[$tag]));
            }
        }

        // Required: consignee
        if (empty($shipmentData['consignee'])) {
            throw new \InvalidArgumentException('Shipment requires a consignee block.');
        }
        $shipment->appendChild($this->buildAddressElement('consignee', $shipmentData['consignee']));

        // Optional return / receiver blocks
        if (!empty($shipmentData['return_consignee'])) {
            $shipment->appendChild($this->buildAddressElement('return_consignee', $shipmentData['return_consignee']));
        }

        if (!empty($shipmentData['receiver'])) {
            $shipment->appendChild($this->buildAddressElement('receiver', $shipmentData['receiver']));
        }

        $shipment->appendChild($this->buildAttributeElement($shipmentData['attribute'] ?? []));

        if (empty($shipmentData['packs'])) {
            throw new \InvalidArgumentException('Shipment requires at least one pack.');
        }

        foreach ($shipmentData['packs'] as $packData) {
            $shipment->appendChild($this->buildPackElement($packData));
        }

        $this->manifest->appendChild($shipment);
        $this->shipmentCount++;

        // Spec rule: a manifest containing a <global> shipment is limited to ONE shipment
        // and cannot be mixed with other types. <international> XMLs cannot be mixed either.
        if ($this->hasGlobal && $this->shipmentCount > 1) {
            throw new \LogicException('Global shipments are limited to one shipment per manifest.');
        }

        if ($this->hasInternational && $this->hasGlobal) {
            throw new \LogicException('A manifest cannot mix international and global shipments.');
        }

        return $this;
    }

    public function toString(): string
    {
        $xml = $this->doc->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Failed to generate XML string from DOMDocument.');
        }

        return $xml;
    }

    private function buildAddressElement(string $tagName, array $data): \DOMElement
    {
        $element = $this->doc->createElement($tagName);
        $this->appendKnownFields($element, $data, self::ADDRESS_FIELDS);

        return $element;
    }

    private function buildAttributeElement(array $data): \DOMElement
    {
        $attribute = $this->doc->createElement('attribute');

        // delivery_type defaults to nwd per Postman spec; return_doc is the only
        // other always-emitted flag (kept for backwards compatibility).
        $deliveryType = isset($data['delivery_type']) && $data['delivery_type'] !== ''
            ? (string) $data['delivery_type']
            : 'nwd';
        $this->appendChildText($attribute, 'delivery_type', $deliveryType);
        $this->appendChildText($attribute, 'return_doc', (string) ($data['return_doc'] ?? '0'));

        $this->appendKnownFields(
            $attribute,
            $data,
            array_diff(self::ATTRIBUTE_FIELDS, ['delivery_type', 'return_doc'])
        );

        // return_doc_consignee is required when return_doc=1 and the document
        // return address differs from the sender. The caller passes it through.
        if (!empty($data['return_doc_consignee'])) {
            $attribute->appendChild(
                $this->buildAddressElement('return_doc_consignee', $data['return_doc_consignee'])
            );
        }

        if (!empty($data['international'])) {
            $attribute->appendChild($this->buildInternationalElement($data['international']));
            $this->hasInternational = true;
        }

        if (!empty($data['global'])) {
            $attribute->appendChild($this->buildGlobalElement($data['global']));
            $this->hasGlobal = true;
        }

        return $attribute;
    }

    private function buildInternationalElement(array $data): \DOMElement
    {
        $element = $this->doc->createElement('international');

        if (empty($data['pickup_date'])) {
            throw new \InvalidArgumentException('international.pickup_date is required (YYYY-MM-DD).');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['pickup_date']) !== 1) {
            throw new \InvalidArgumentException('international.pickup_date must be in YYYY-MM-DD format.');
        }

        $this->appendChildText($element, 'pickup_date', (string) $data['pickup_date']);

        if (!empty($data['pickup_carrier'])) {
            $this->appendChildText($element, 'pickup_carrier', (string) $data['pickup_carrier']);
        }

        return $element;
    }

    private function buildGlobalElement(array $data): \DOMElement
    {
        $element = $this->doc->createElement('global');

        if (!empty($data['global_delivery'])) {
            $value = (string) $data['global_delivery'];
            if (!in_array($value, self::GLOBAL_DELIVERY_TYPES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'global.global_delivery must be one of: %s.',
                    implode(', ', self::GLOBAL_DELIVERY_TYPES)
                ));
            }
            $this->appendChildText($element, 'global_delivery', $value);
        }

        foreach (['shipment_description', 'value', 'eori'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $this->appendChildText($element, $field, (string) $data[$field]);
            }
        }

        if (!empty($data['documents']) && is_array($data['documents'])) {
            foreach ($data['documents'] as $document) {
                $element->appendChild($this->buildGlobalDocumentElement($document));
            }
        }

        return $element;
    }

    private function buildGlobalDocumentElement(array $document): \DOMElement
    {
        $element = $this->doc->createElement('document');

        if (!empty($document['doc_type'])) {
            $type = (string) $document['doc_type'];
            if (!in_array($type, self::GLOBAL_DOC_TYPES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'global.document.doc_type must be one of: %s.',
                    implode(', ', self::GLOBAL_DOC_TYPES)
                ));
            }
            $this->appendChildText($element, 'doc_type', $type);
        }

        foreach (['doc_name', 'doc_mime', 'doc_content'] as $field) {
            if (isset($document[$field]) && $document[$field] !== '') {
                $this->appendChildText($element, $field, (string) $document[$field]);
            }
        }

        return $element;
    }

    private function buildPackElement(array $data): \DOMElement
    {
        $pack = $this->doc->createElement('pack');
        $this->appendKnownFields($pack, $data, self::PACK_FIELDS);

        return $pack;
    }

    /**
     * Append every value from $data whose key is in $allowed and is not empty/null.
     */
    private function appendKnownFields(\DOMElement $parent, array $data, array $allowed): void
    {
        foreach ($allowed as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $this->appendChildText($parent, $field, (string) $data[$field]);
            }
        }
    }

    private function appendChildText(\DOMElement $parent, string $tag, string $value): void
    {
        $child = $this->doc->createElement($tag);
        $child->appendChild($this->doc->createTextNode($value));
        $parent->appendChild($child);
    }
}
