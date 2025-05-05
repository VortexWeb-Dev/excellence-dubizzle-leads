<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';


class LeadProcessor
{
    private const SOURCE = [
        "DUBIZZLE_CALL" => "15",
        "DUBIZZLE_EMAIL" => "16",
        "DUBIZZLE_WHATSAPP" => "17",
    ];

    private const PLATFORMS = ['dubizzle'];
    private const LEAD_TYPES = ['leads', 'call_logs', 'whatsapp_leads'];

    private $processedLeads;
    private $leadFile;
    private $authToken;
    private $timestamp;

    public function __construct($leadFile, $authToken, $timestamp)
    {
        $this->leadFile = $leadFile;
        $this->authToken = $authToken;
        $this->processedLeads = $this->getProcessedLeads();
        $this->timestamp = $timestamp;
    }

    public function processAllLeads()
    {
        $allLeads = $this->fetchAllLeads();

        foreach (self::PLATFORMS as $platform) {
            $this->processPlatformLeads($platform, $allLeads[$platform] ?? []);
        }
    }

    private function fetchAllLeads()
    {
        $allLeads = [];
        $encodedTimestamp = urlencode($this->timestamp);

        foreach (self::PLATFORMS as $platform) {
            foreach (self::LEAD_TYPES as $leadType) {
                $leads = $this->fetchLeads($leadType, $encodedTimestamp, $platform);
                $allLeads[$platform][$leadType] = is_array($leads) ? $leads : [];

                $this->logLeadCount($platform, $leadType, $allLeads[$platform][$leadType]);
            }
        }

        return $allLeads;
    }

    private function fetchLeads($leadType, $timestamp, $platform)
    {
        return fetchLeads($leadType, $timestamp, $this->authToken, $platform);
    }

    private function logLeadCount($platform, $leadType, $leads)
    {
        $formattedType = ucfirst(str_replace('_', ' ', $leadType));
        echo ucfirst($platform) . " {$formattedType}: " . count($leads) . "\n";
        logData("{$platform}_{$leadType}.log", json_encode($leads, JSON_PRETTY_PRINT));
    }

    private function processPlatformLeads($platform, $platformLeads)
    {
        foreach (self::LEAD_TYPES as $leadType) {
            if (!empty($platformLeads[$leadType])) {
                $formattedLeadType = str_replace('_', '', ucwords($leadType, '_'));
                $methodName = "process{$platform}{$formattedLeadType}";

                if (method_exists($this, $methodName)) {
                    $this->$methodName($platformLeads[$leadType]);
                } else {
                    logData('error.log', "Method not found: $methodName");
                    echo "Warning: Processing method for $methodName not implemented.\n";
                }
            }
        }
    }

    private function processDubizzleLeads($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $assignedById = !empty($lead['property_reference']) ? getResponsiblePerson($lead['property_reference'], 'reference') : DEFAULT_ASSIGNED_USER_ID;
            $title = "Dubizzle - Email - " . ($lead['property_reference'] !== '' ? $lead['property_reference'] : 'No reference');

            $contactId = createContact([
                'NAME' => $lead['client_name'] ?? $title,
                'EMAIL' => [
                    [
                        'VALUE' => $lead['client_email'],
                        'VALUE_TYPE' => 'WORK',
                    ]
                ],
                'PHONE' => [
                    [
                        'VALUE' => $lead['client_phone'],
                        'VALUE_TYPE' => 'WORK',
                    ]
                ],
                'SOURCE_ID' => self::SOURCE['DUBIZZLE_EMAIL'],
                'ASSIGNED_BY_ID' => $assignedById
            ]);

            $fields = [
                'TITLE' => $title,
                // 'CATEGORY_ID' => SECONDARY_PIPELINE_ID,
                'ASSIGNED_BY_ID' => $assignedById,
                'SOURCE_ID' => self::SOURCE['DUBIZZLE_EMAIL'],
                'UF_CRM_1735998755830' => $lead['client_name'] ?? 'Unknown',
                'UF_CRM_1744002382899' => $lead['client_email'],
                // 'UF_CRM_1721198325274' => $lead['client_email'],
                'UF_CRM_1744002779032' => $lead['client_phone'],
                'UF_CRM_1735998202607' => $lead['property_reference'],
                'UF_CRM_1735997531807' => $lead['property_id'] !== '' ? generatePropertyLink($lead['property_id']) : '',
                'COMMENTS' => $lead['message'],
                'OPPORTUNITY' => getPropertyPrice($lead['property_reference']) ?? '',
                'CONTACT_ID' => $contactId,
                'UF_CRM_1744010539046' => $lead['lead_id'],
            ];

            $this->createLeadAndSave($fields, $lead['lead_id']);
        }
    }

    private function processDubizzleWhatsappLeads($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $assignedById = !empty($lead['listing_reference']) ? getResponsiblePerson($lead['listing_reference'], 'reference') : DEFAULT_ASSIGNED_USER_ID;
            $title = "Dubizzle - WhatsApp - " . ($lead['listing_reference'] !== "" ? $lead['listing_reference'] : 'No reference');

            $contactId = createContact([
                'NAME' => $lead['detail']['actor_name'] ?? $title,
                'PHONE' => [
                    [
                        'VALUE' => $lead['detail']['cell'],
                        'VALUE_TYPE' => 'WORK',
                    ]
                ],
                'SOURCE_ID' => self::SOURCE['DUBIZZLE_WHATSAPP'],
                'ASSIGNED_BY_ID' => $assignedById
            ]);

            $messageData = parseMessageAndLink($lead['detail']['message']);
            $fields = [
                'TITLE' => $title,
                // 'CATEGORY_ID' => SECONDARY_PIPELINE_ID,
                'ASSIGNED_BY_ID' => $assignedById,
                'SOURCE_ID' => self::SOURCE['DUBIZZLE_WHATSAPP'],
                'UF_CRM_1735998755830' => $lead['detail']['actor_name'] ?? 'Unknown',
                'UF_CRM_1744002779032' => $lead['detail']['cell'],
                'COMMENTS' => $messageData['message'],
                'UF_CRM_1735998202607' => $lead['listing_reference'],
                'UF_CRM_1735997531807' => $messageData['link'],
                'OPPORTUNITY' => getPropertyPrice($lead['listing_reference']) ?? '',
                'CONTACT_ID' => $contactId,
                'UF_CRM_1744010539046' => $lead['lead_id'],
            ];

            $this->createLeadAndSave($fields, $lead['lead_id']);
        }
    }

    private function processDubizzleCallLogs($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $fields = $this->prepareCallFields($lead, 'Dubizzle');
            $newLeadId = $this->createLeadAndSave($fields, $lead['lead_id']);

            if ($lead['call_recordingurl'] !== 'None' && $lead['call_recordingurl'] !== '') {
                $this->processCallRecording($lead, $fields, $newLeadId, 'Dubizzle');
            }
        }
    }

    private function prepareCallFields($lead, $platform)
    {
        $comments = $this->formatCallComments($lead);
        $SOURCE_ID = self::SOURCE['DUBIZZLE_CALL'];

        $assignedById = DEFAULT_ASSIGNED_USER_ID;

        if (!empty($lead['listing_reference'])) {
            $assignedById = getResponsiblePerson($lead['listing_reference'], 'reference');
        } elseif (!empty($lead['receiver_number'])) {
            $responsiblePerson = getResponsiblePerson($lead['receiver_number'], 'phone');
            $assignedById = ($responsiblePerson == '1945' || $responsiblePerson == null) ? DEFAULT_ASSIGNED_USER_ID : $responsiblePerson;
        }

        $title = "{$platform} - Call - " . ($lead['listing_reference'] !== '' ? $lead['listing_reference'] : 'No reference');

        $contactId = createContact([
            'NAME' => $lead['caller_number'] ?? $title,
            'PHONE' => [
                [
                    'VALUE' => $lead['caller_number'],
                    'VALUE_TYPE' => 'WORK',
                ]
            ],
            'SOURCE_ID' => $SOURCE_ID,
            'ASSIGNED_BY_ID' => $assignedById
        ]);

        return [
            'TITLE' => $title,
            // 'CATEGORY_ID' => SECONDARY_PIPELINE_ID,
            'ASSIGNED_BY_ID' =>  $assignedById,
            'SOURCE_ID' => $SOURCE_ID,
            'UF_CRM_1744002779032' => $lead['caller_number'] ?? 'Unknown',
            // 'UF_CRM_PHONE_WORK' => $lead['caller_number'],
            // 'UF_CRM_1736406984' => $lead['caller_number'],
            'COMMENTS' => $comments,
            'UF_CRM_1735998202607' => $lead['listing_reference'],
            'OPPORTUNITY' => getPropertyPrice($lead['listing_reference']) ?? '',
            'CONTACT_ID' => $contactId,
            'UF_CRM_1744010539046' => $lead['lead_id'],
        ];
    }

    private function formatCallComments($lead)
    {
        return "
            Receiver Number: {$lead['receiver_number']}
            Call Status: {$lead['call_status']}
            Call Duration: {$lead['call_total_duration']}
            Call Connected Duration: {$lead['call_connected_duration']}
            Call Recording URL: {$lead['call_recordingurl']}
        ";
    }

    private function processCallRecording($lead, $fields, $newLeadId, $platform)
    {
        $callRecordContent = @file_get_contents($lead['call_recordingurl']);
        if ($callRecordContent === false) {
            logData('error.log', "Failed to fetch call recording: {$lead['call_recordingurl']}");
            return;
        }

        $registerCall = $this->registerCall($lead, $fields, $newLeadId, $platform);
        $callId = $registerCall['CALL_ID'] ?? null;

        if ($callId) {
            $this->finishCallAndAttachRecord($callId, $fields, $lead, $callRecordContent);
        }
    }

    private function registerCall($lead, $fields, $newLeadId, $platform)
    {
        $registerCall = registerCall([
            'USER_PHONE_INNER' => $lead['receiver_number'],
            'USER_ID' => $fields['ASSIGNED_BY_ID'],
            'PHONE_NUMBER' => $lead['caller_number'],
            'CALL_START_DATE' => $lead['date'] . ' ' . $lead['time'],
            'CRM_CREATE' => false,
            'CRM_SOURCE' => $fields['SOURCE_ID'],
            'CRM_ENTITY_TYPE' => 'LEAD',
            'CRM_ENTITY_ID' => $newLeadId,
            'SHOW' => false,
            'TYPE' => 2,
            'LINE_NUMBER' => $platform . ' ' . $lead['receiver_number'],
        ]);

        logData('register_call.log', print_r($registerCall, true));
        return $registerCall;
    }

    private function finishCallAndAttachRecord($callId, $fields, $lead, $callRecordContent)
    {
        $finishCall = finishCall([
            'CALL_ID' => $callId,
            'USER_ID' => $fields['ASSIGNED_BY_ID'],
            'DURATION' => timeToSec($lead['call_connected_duration']),
            'STATUS_CODE' => 200,
        ]);

        $attachRecord = attachRecord([
            'CALL_ID' => $callId,
            'FILENAME' => $lead['lead_id'] . '|' . uniqid('call') . '.mp3',
            'FILE_CONTENT' => base64_encode($callRecordContent),
        ]);

        logData('finish_call.log', print_r($finishCall, true));
        logData('attach_record.log', print_r($attachRecord, true));
    }

    private function createLeadAndSave($fields, $leadId)
    {
        logData('fields.log', print_r($fields, true));

        $newLeadId = createBitrixDeal($fields);
        echo "New Lead Created: $newLeadId\n";

        $this->saveProcessedLead($leadId);
        return $newLeadId;
    }

    private function isProcessedLead($leadId)
    {
        if (in_array($leadId, $this->processedLeads)) {
            echo "Duplicate Lead Skipped: $leadId\n";
            return true;
        }
        return false;
    }

    private function getProcessedLeads()
    {
        return getProcessedLeads($this->leadFile);
    }

    private function saveProcessedLead($leadId)
    {
        saveProcessedLead($this->leadFile, $leadId);
        $this->processedLeads[] = $leadId;
    }
}

// Initialize and run the processor
try {
    $processor = new LeadProcessor(LEAD_FILE, AUTH_TOKEN, TIMESTAMP);
    $processor->processAllLeads();
} catch (Exception $e) {
    logData('error.log', "Error processing leads: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo "Error occurred while processing leads. Check error.log for details.\n";
}
