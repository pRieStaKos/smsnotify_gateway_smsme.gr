<?php
/**
 * SMSMe.gr gateway for SMS Notify PRO.
 *
 * API documentation: http://wiki.smsme.gr/index.php?title=HTTP_GET_API
 *
 * Helper functions (smscut, unicode, getRemoteData, logger) are provided by SMS Notify.
 */
defined('_SMSNOTIFY') or die('Restricted access');
defined('SMSMEGR_API_BASE') or define('SMSMEGR_API_BASE', 'http://webservice.smsme.gr/');

/**
 * Gateway metadata shown in the SMS Notify admin area.
 *
 * @return array<string, mixed>
 */
function smsmegr_gatewaydetails(): array
{
    return [
        'name' => 'SMSMe.gr',
        'country' => 'Greece / International',
        'site' => 'https://www.smsme.gr',
        'pricelist' => 'https://smsme.gr/timokatalogos.aspx',
        'developer' => 'pRieStaKos | info@cubric.gr',
        'schedulesms' => true,  // gateway supports SMS scheduling
        'unicodesupport' => false, // gateway does not support Unicode SMS
        'params' => [
            'customsender' => [
                'type' => 'text',
                'value' => '',
                'name' => 'Originator',
                'description' => 'If you leave it empty it will get the global SenderID value from Settings',
            ],
        ],
    ];
}

/**
 * Send one or more SMS messages.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function smsmegr_sendsms(array $params): array
{
    $customSender = trim((string)($params['customsender'] ?? ''));
    $senderId = $customSender !== '' ? $customSender : trim((string)($params['senderid'] ?? ''));

    $isUnicode = ($params['unicode'] ?? '') === 'yes';
    $message = (string)($params['message'] ?? '');

    if (($params['longsms'] ?? '') !== 'yes') {
        $message = smscut($message, 160);
    }

    if ($isUnicode) {
        $message = unicode($message);
    }

    $query = [
        'Username' => (string)($params['username'] ?? ''),
        'Password' => (string)($params['password'] ?? ''),
        'Originator' => $senderId,
        'Mobile' => (string)($params['to'] ?? ''), // phone(s) separated by commas
        'Body' => $message,
    ];

    $scheduledAt = smsmegr_schedule_datetime($params);
    if ($scheduledAt !== null) {
        $query['smsDate'] = $scheduledAt; // format: yyyy-mm-dd HH:mm:ss
    }

    if ($isUnicode) {
        $query['unicode'] = '1';
    }

    $url = SMSMEGR_API_BASE . 'SendBulkSmsRequest.aspx?' . http_build_query($query);

    // $data['response'] => the gateway response, $data['error'] => connection errors (rare)
    $data = getRemoteData($url);

    if (!empty($data['error']) || empty($data['response'])) {
        return ['error' => $data['error'] ?? 'Empty response', 'smsid' => time()];
    }

    // Successful response: "OK: 1 12345:306912345678" followed by one line per recipient.
    // Error responses (plain text): BAD USER, ERROR PASSWORD, ERROR USERNAME, ERROR ORIGINATOR,
    // ERROR Mobile, ERROR BODY, NOT ENOUGH CREDITS, EXCEPTION ERROR, WRONG NUMBER, NO CREDITS,
    // BAD ORIGINATOR (originator over 11 characters), BAD DATE (smsDate not yyyy-mm-dd HH:mm:ss).
    $response = (string)$data['response'];

    if (!str_starts_with($response, 'OK')) {
        $values = [
            'smsid' => 'err_' . time(),
            'error' => $response,
        ];
    } else {
        $values = ['smsid' => [], 'error' => []];
        $index = 0;

        foreach (explode("\n", $response) as $lineNumber => $line) {
            $line = trim($line);

            if ($lineNumber === 0 || $line === '' || !str_contains($line, ':')) {
                continue; // skip the "OK: ..." header and blank lines
            }

            [$smsId] = explode(':', $line, 2);
            $values['smsid'][$index] = $smsId;
            $values['error'][$index] = null;
            $index++;
        }
    }

    logger(
        'send.smsmegr',
        $url,
        $data,
        ['RETURN' => $values, 'PARAMS' => $params, 'API' => $data],
        [$params['username'] ?? '', $params['password'] ?? '']
    );

    return $values;
}

/**
 * Account balance in euro.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function smsmegr_getSmsBalance(array $params): array
{
    $url = SMSMEGR_API_BASE . 'login.aspx?' . http_build_query([
        'Username' => (string)($params['username'] ?? ''),
        'Password' => (string)($params['password'] ?? ''),
    ]);

    $data = getRemoteData($url);

    if (!empty($data['error'])) {
        return ['error' => $data['error'], 'credits' => 0];
    }

    // Balance with 3 decimals using a comma separator (e.g. "0,932"),
    // otherwise an error string: BAD USER, ERROR PASSWORD, ERROR USERNAME.
    $credits = str_replace(',', '.', (string)($data['response'] ?? ''));

    if (!is_numeric($credits)) {
        return ['credits' => 0, 'error' => $data['response'] ?? ''];
    }

    return ['credits' => (float)$credits];
}

/**
 * Delivery status of a previously sent message.
 *
 * Status codes: 0 = not delivered (permanent), 1 = delivered (permanent),
 * 2 = sent but pending (not permanent), 3 = unknown (not permanent).
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function smsmegr_getsmsstatus(array $params): array
{
    $smsId = (string)($params['smsid'] ?? '');

    if (str_starts_with($smsId, 'err_')) {
        return ['status' => 0];
    }

    $url = SMSMEGR_API_BASE . 'Reports.aspx?' . http_build_query([
        'Username' => (string)($params['username'] ?? ''),
        'Password' => (string)($params['password'] ?? ''),
        'Sdate' => date('Y-m-d H:i:s', strtotime('-1 day')),
        'Edate' => date('Y-m-d H:i:s'),
    ]);

    $data = getRemoteData($url);

    if (!empty($data['error'])) {
        // Communication errors may be temporary, so report the message as pending.
        return ['status' => 2, 'cost' => 0];
    }

    $xml = @simplexml_load_string((string)($data['response'] ?? ''));

    if ($xml === false || !isset($xml->Report)) {
        return ['status' => 3];
    }

    $mobiles = array_map('trim', explode(',', (string)($params['mobile'] ?? '')));

    foreach ($xml->Report as $report) {
        $mobile = (string)$report->Mobile;
        $reportId = (string)$report->ReportID;

        if (!in_array($mobile, $mobiles, true) && $reportId !== $smsId) {
            continue;
        }

        return [
            'smsid' => $reportId,
            'status' => smsmegr_status_code((string)$report->ReportStatus),
            'cost' => (float)$report->Cost,
        ];
    }

    return ['status' => 3]; // no matching report yet; 3 is not permanent, so it is polled again
}

/**
 * Build the scheduled send date accepted by the gateway, or null when not scheduled.
 *
 * @param array<string, mixed> $params
 */
function smsmegr_schedule_datetime(array $params): ?string
{
    $date = trim((string)($params['schedule_date'] ?? ''));
    $time = trim((string)($params['schedule_time'] ?? ''));

    if ($date === '' || $time === '') {
        return null;
    }

    $timestamp = strtotime($date . ' ' . $time);

    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

/**
 * Map a gateway report status to an SMS Notify status code.
 *
 * SMS Notify groups every gateway status into four values: 0 and 1 are permanent,
 * 2 and 3 are not permanent and get polled again.
 */
function smsmegr_status_code(string $reportStatus): int
{
    return match ($reportStatus) {
        'Delivered' => 1,
        'Fail', 'Expired' => 0,
        'Waiting', 'Waiting for delivery' => 2,
        default => 3, // unknown status, polled again
    };
}
