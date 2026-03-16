<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Api;

class ShipmentXmlBuilder
{
    private \DOMDocument $doc;
    private \DOMElement $manifest;

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
        $description->appendChild($this->manifest);
    }

    public function addShipment(array $shipmentData): self
    {
        $shipment = $this->doc->createElement('shipment');

        if (!empty($shipmentData['consignor'])) {
            $consignor = $this->buildAddressElement('consignor', $shipmentData['consignor']);
            $shipment->appendChild($consignor);
        }

        if (!empty($shipmentData['return_consignee'])) {
            $returnConsignee = $this->buildAddressElement('return_consignee', $shipmentData['return_consignee']);
            $shipment->appendChild($returnConsignee);
        }

        $consignee = $this->buildAddressElement('consignee', $shipmentData['consignee']);
        $shipment->appendChild($consignee);

        $attribute = $this->buildAttributeElement($shipmentData['attribute']);
        $shipment->appendChild($attribute);

        foreach ($shipmentData['packs'] as $packData) {
            $pack = $this->buildPackElement($packData);
            $shipment->appendChild($pack);
        }

        $this->manifest->appendChild($shipment);

        return $this;
    }

    public function toString(): string
    {
        if (!$this->doc->schemaValidate === false) {
            // DOMDocument will set internal errors if structure is invalid
        }

        $xml = $this->doc->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Failed to generate XML string from DOMDocument.');
        }

        return $xml;
    }

    private function buildAddressElement(string $tagName, array $data): \DOMElement
    {
        $element = $this->doc->createElement($tagName);

        $fields = [
            'name', 'company_code', 'country', 'city',
            'address', 'post_code', 'contact_person',
            'contact_tel', 'contact_email',
        ];

        foreach ($fields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $child = $this->doc->createElement($field);
                $child->appendChild($this->doc->createTextNode((string) $data[$field]));
                $element->appendChild($child);
            }
        }

        return $element;
    }

    private function buildAttributeElement(array $data): \DOMElement
    {
        $attribute = $this->doc->createElement('attribute');

        $requiredFields = ['delivery_type', 'return_doc'];
        $optionalFields = [
            'comment_door_code', 'comment_office_no', 'comment_warehous_no',
            'comment_call', 'cod', 'cod_type', 'return_service',
        ];

        foreach ($requiredFields as $field) {
            $child = $this->doc->createElement($field);
            $child->appendChild($this->doc->createTextNode((string) ($data[$field] ?? '')));
            $attribute->appendChild($child);
        }

        foreach ($optionalFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $child = $this->doc->createElement($field);
                $child->appendChild($this->doc->createTextNode((string) $data[$field]));
                $attribute->appendChild($child);
            }
        }

        return $attribute;
    }

    private function buildPackElement(array $data): \DOMElement
    {
        $pack = $this->doc->createElement('pack');

        $fields = ['pack_no', 'doc_no', 'weight', 'volume'];

        foreach ($fields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $child = $this->doc->createElement($field);
                $child->appendChild($this->doc->createTextNode((string) $data[$field]));
                $pack->appendChild($child);
            }
        }

        return $pack;
    }
}
