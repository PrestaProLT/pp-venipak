<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Api;

class CourierInvitationXmlBuilder
{
    public function build(array $data): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $description = $doc->createElement('description');
        $description->setAttribute('type', '3');
        $doc->appendChild($description);

        $sender = $doc->createElement('sender');
        $this->appendAddressFields($doc, $sender, $data['sender']);
        $description->appendChild($sender);

        if (!empty($data['consignee'])) {
            $consignee = $doc->createElement('consignee');
            $this->appendAddressFields($doc, $consignee, $data['consignee']);
            $description->appendChild($consignee);
        }

        $this->appendTextElement($doc, $description, 'weight', (string) $data['weight']);
        $this->appendTextElement($doc, $description, 'volume', (string) $data['volume']);

        if (isset($data['pallets']) && $data['pallets'] !== '') {
            $this->appendTextElement($doc, $description, 'pallets', (string) $data['pallets']);
        }

        $dateParts = explode('-', (string) $data['date']);
        if (count($dateParts) !== 3) {
            throw new \InvalidArgumentException('Courier invitation date must be in Y-m-d format.');
        }
        $this->appendTextElement($doc, $description, 'date_y', $dateParts[0]);
        $this->appendTextElement($doc, $description, 'date_m', $dateParts[1]);
        $this->appendTextElement($doc, $description, 'date_d', $dateParts[2]);

        $this->appendTextElement($doc, $description, 'hour_from', (string) $data['hour_from']);
        $this->appendTextElement($doc, $description, 'min_from', (string) $data['min_from']);
        $this->appendTextElement($doc, $description, 'hour_to', (string) $data['hour_to']);
        $this->appendTextElement($doc, $description, 'min_to', (string) $data['min_to']);

        if (!empty($data['comment'])) {
            $this->appendTextElement($doc, $description, 'comment', mb_substr($data['comment'], 0, 50));
        }

        if (!empty($data['spp'])) {
            $this->appendTextElement($doc, $description, 'spp', mb_substr($data['spp'], 0, 40));
        }

        if (!empty($data['doc_no'])) {
            $this->appendTextElement($doc, $description, 'doc_no', mb_substr($data['doc_no'], 0, 16));
        }

        if (!empty($data['delivery_type'])) {
            $this->appendTextElement($doc, $description, 'delivery_type', (string) $data['delivery_type']);
        }

        if (!empty($data['delivery_type_details'])) {
            $this->appendTextElement($doc, $description, 'delivery_type_details', (string) $data['delivery_type_details']);
        }

        if (!empty($data['return_passport'])) {
            $this->appendTextElement($doc, $description, 'return_passport', '1');
        }

        $xml = $doc->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Failed to generate courier invitation XML.');
        }

        return $xml;
    }

    private function appendAddressFields(\DOMDocument $doc, \DOMElement $parent, array $data): void
    {
        $fields = [
            'name', 'company_code', 'country', 'city',
            'address', 'post_code', 'contact_person',
            'contact_tel', 'contact_email',
        ];

        foreach ($fields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $this->appendTextElement($doc, $parent, $field, (string) $data[$field]);
            }
        }
    }

    private function appendTextElement(\DOMDocument $doc, \DOMElement $parent, string $tag, string $value): void
    {
        $element = $doc->createElement($tag);
        $element->appendChild($doc->createTextNode($value));
        $parent->appendChild($element);
    }
}
