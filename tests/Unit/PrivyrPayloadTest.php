<?php

namespace Tests\Unit;

use Tallcms\Privyr\Support\PrivyrPayload;
use Tests\TestCase;

class PrivyrPayloadTest extends TestCase
{
    public function test_promotes_recognised_fields_and_labels_the_rest(): void
    {
        $formData = [
            ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'value' => 'Jane Doe'],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'jane@example.com'],
            ['name' => 'phone', 'label' => 'Phone (SG preferred)', 'type' => 'tel', 'value' => '92716890'],
            ['name' => 'unit_type', 'label' => 'Preferred unit type', 'type' => 'select', 'value' => 'Still deciding'],
            ['name' => 'message', 'label' => 'Anything specific?', 'type' => 'textarea', 'value' => 'test'],
        ];

        $payload = PrivyrPayload::fromSubmissionData('Jane Doe', 'jane@example.com', $formData, 'https://acme.test/contact');

        // Recognised attributes promoted to top-level keys.
        $this->assertSame('Jane Doe', $payload['name']);
        $this->assertSame('jane@example.com', $payload['email']);
        $this->assertSame('92716890', $payload['phone']);
        $this->assertSame('test', $payload['message']);
        $this->assertSame('https://acme.test/contact', $payload['page_url']);

        // Remaining field promoted as a tidy labelled line.
        $this->assertSame('Still deciding', $payload['Preferred unit type']);

        // No raw fields array, and name/email/phone/message aren't duplicated.
        $this->assertArrayNotHasKey('fields', $payload);
        $this->assertArrayNotHasKey('Full name', $payload);
        $this->assertArrayNotHasKey('Phone (SG preferred)', $payload);
        $this->assertArrayNotHasKey('Anything specific?', $payload);
    }

    public function test_detects_phone_by_name_when_no_tel_type(): void
    {
        $formData = [
            ['name' => 'mobile_number', 'label' => 'Mobile', 'type' => 'text', 'value' => '99998888'],
        ];

        $payload = PrivyrPayload::fromSubmissionData(null, null, $formData, null);

        $this->assertSame('99998888', $payload['phone']);
        // Consumed as phone — not also emitted as a labelled line.
        $this->assertArrayNotHasKey('Mobile', $payload);
    }

    public function test_omits_phone_message_and_empty_values(): void
    {
        $formData = [
            ['name' => 'fax', 'label' => 'Fax', 'type' => 'text', 'value' => ''],
        ];

        $payload = PrivyrPayload::fromSubmissionData('No Phone', 'np@example.com', $formData, null);

        $this->assertArrayNotHasKey('phone', $payload);
        $this->assertArrayNotHasKey('message', $payload);
        $this->assertArrayNotHasKey('page_url', $payload);
        $this->assertArrayNotHasKey('Fax', $payload); // empty value skipped
    }

    public function test_duplicate_labels_do_not_collide(): void
    {
        $formData = [
            ['name' => 'ref_a', 'label' => 'Reference', 'type' => 'text', 'value' => 'A'],
            ['name' => 'ref_b', 'label' => 'Reference', 'type' => 'text', 'value' => 'B'],
        ];

        $payload = PrivyrPayload::fromSubmissionData(null, null, $formData, null);

        $this->assertSame('A', $payload['Reference']);
        $this->assertSame('B', $payload['Reference (2)']);
    }
}
