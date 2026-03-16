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

        $this->appendTextElement($doc, $description, 'weight', (string) $data['weight']);
        $this->appendTextElement($doc, $description, 'volume', (string) $data['volume']);

        $dateParts = explode('-', $data['date']);
        $this->appendTextElement($doc, $description, 'year', $dateParts[0]);
        $this->appendTextElement($doc, $description, 'month', $dateParts[1]);
        $this->appendTextElement($doc, $description, 'day', $dateParts[2]);

        $this->appendTextElement($doc, $description, 'hour_from', (string) $data['hour_from']);
        $this->appendTextElement($doc, $description, 'min_from', (string) $data['min_from']);
        $this->appendTextElement($doc, $description, 'hour_to', (string) $data['hour_to']);
        $this->appendTextElement($doc, $description, 'min_to', (string) $data['min_to']);

        if (!empty($data['comment'])) {
            $comment = mb_substr($data['comment'], 0, 50);
            $this->appendTextElement($doc, $description, 'comment', $comment);
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
