# SMSMe.gr Gateway for SMS Notify PRO

A gateway driver that lets [SMS Notify PRO](https://www.web-expert.gr/en/whmcs-addons-modules/item/44-whmcs-sms-notify) send WHMCS notifications through [SMSMe.gr](https://www.smsme.gr), a Greek bulk SMS provider with international routing.

## Features

| Capability | Supported |
|---|---|
| Bulk send (multiple recipients per request) | Yes |
| Scheduled SMS | Yes |
| Custom originator (per-gateway sender ID) | Yes |
| Delivery reports | Yes |
| Balance check | Yes |
| Long / concatenated SMS | Yes |
| Unicode (Greek) SMS | No |

Messages are truncated to 160 characters unless long SMS is enabled in SMS Notify.

## Requirements

- PHP 8.0 or newer (uses `match`, `str_contains`, `str_starts_with`)
- SMS Notify PRO installed in WHMCS
- An SMSMe.gr account with API access — see the [price list](https://smsme.gr/timokatalogos.aspx)
- Outbound HTTP to `webservice.smsme.gr`

## Installation

1. Copy `smsmegr.php` into the SMS Notify providers folder of your WHMCS installation:

   ```
   modules/addons/sms_notify/smsproviders/smsmegr.php
   ```

2. Open the SMS Notify addon settings in the WHMCS admin area and select **SMSMe.gr** as the gateway.
3. Fill in the gateway settings:
   - **Username** / **Password** — your SMSMe.gr account credentials
   - **Originator** — the sender ID for this gateway. Leave empty to fall back to the global SenderID from SMS Notify settings. Maximum 11 characters; longer values are rejected by the gateway with `BAD ORIGINATOR`.
4. Save, then use the balance check to confirm the credentials work.

The filename is significant: SMS Notify derives the function prefix from it, which is why every function in this file is named `smsmegr_*`. Renaming the file requires renaming the functions to match.

## API endpoints used

All calls are HTTP GET against `http://webservice.smsme.gr/`.
Reference: [SMSMe.gr HTTP GET API](http://wiki.smsme.gr/index.php?title=HTTP_GET_API)

| Function | Endpoint | Purpose |
|---|---|---|
| `smsmegr_sendsms()` | `SendBulkSmsRequest.aspx` | Send one or more messages |
| `smsmegr_getSmsBalance()` | `login.aspx` | Fetch remaining account credit |
| `smsmegr_getsmsstatus()` | `Reports.aspx` | Poll delivery reports (last 24 hours) |

### Scheduling

When both a schedule date and time are set, an `smsDate` parameter is sent in `yyyy-mm-dd HH:mm:ss` format. Any other format is rejected by the gateway with `BAD DATE`.

### Balance

The balance endpoint returns euros with three decimals using a comma as the decimal separator (for example `0,932`). The driver normalises this to a float.

## Delivery status mapping

`smsmegr_getsmsstatus()` polls the report feed for the previous 24 hours and matches on recipient number or report ID.

SMS Notify groups every gateway's statuses into four values. `0` and `1` are permanent; `2` and `3` are not, and are polled again until they resolve.

| Gateway `ReportStatus` | SMS Notify status |
|---|---|
| `Delivered` | 1 — delivered successfully (permanent) |
| `Fail`, `Expired` | 0 — not delivered / failed (permanent) |
| `Waiting`, `Waiting for delivery` | 2 — sent but pending (not permanent) |
| anything else | 3 — unknown (not permanent) |

Messages that failed at submission time are stored with an `err_` prefixed ID and reported as failed without contacting the API. A message with no matching report yet — and an unparseable report feed — is reported as unknown, so it gets polled again. Temporary connection errors are reported as pending for the same reason.

## Gateway error responses

The send endpoint returns plain text. Anything that does not contain `OK` is treated as an error and surfaced in the SMS Notify log verbatim:

- `BAD USER` — no account matches the username and password
- `ERROR USERNAME` / `ERROR PASSWORD` — credential field missing
- `ERROR ORIGINATOR` / `BAD ORIGINATOR` — sender missing, or longer than 11 characters
- `ERROR Mobile` / `WRONG NUMBER` — recipient missing or malformed
- `ERROR BODY` — empty message body
- `NO CREDITS` / `NOT ENOUGH CREDITS` — insufficient account balance
- `BAD DATE` — `smsDate` not in `yyyy-mm-dd HH:mm:ss` format
- `EXCEPTION ERROR` — transient gateway failure, retry

## Logging

Every send is written to the SMS Notify module log under `send.smsmegr`, with the username and password masked from the stored request.

## Writing your own gateway

SMS Notify gateways are plain PHP files in `modules/addons/sms_notify/smsproviders/`, each exposing a small set of functions prefixed with the filename. References:

- [WHMCS SMS Notify — How to add your own SMS gateway](https://www.web-expert.gr/clients/index.php?rp=/knowledgebase/12/WHMCS-SMS-Notify---How-to-add-your-own-SMS-gateway.html)
- The **Development Kit 3.x+ Series** from the web-expert.gr client area, containing `mygateway.php` and the SMS Notify Developer Guide
- The bundled gateways, such as `vsms.php` and `clickatell.php` — all integrations ship unlocked

### Gateway functions

| Function | Returns | Required |
|---|---|---|
| `_gatewaydetails()` | array of gateway info and custom settings fields | Yes |
| `_sendsms($params)` | `smsid` / `error` | Yes |
| `_getSmsBalance($params)` | `credits` / `error` | No |
| `_getsmsstatus($params)` | `status` code / `error` | No |

Unsupported features should be omitted entirely rather than stubbed — delete the function from the file.

`_sendsms()` may report on several recipients at once: both `smsid` and `error` accept an array, indexed in the same order, which is what this driver returns for a bulk send.

### Helper functions provided by SMS Notify

```php
getRemoteData($url, $fields = null, $headers = array(), $noCURL = false)
// $url only performs a GET; $fields (string or array) switches it to POST;
// $headers takes e.g. array('Content-Type: application/json')
// Returns: array with 'response' and 'error'

unicode($str, $mode = '')
// Returns a Unicode string. $mode: 'UTF-16', 'UCS-2BE', 'UCS-4BE', or empty for the default

smscut($str, $length)
// Returns a shortened string, using mb_substr when available

logger($action, $requeststring, $responsedata, $processeddata, $vars = array())
// Same parameters as the WHMCS logModuleCall(), minus the leading $module.
// $vars lists values to mask in the stored log
```

### Callbacks

SMS Notify supports push delivery reports through a matching file in the `callback/` folder. SMSMe.gr is polled instead, so this gateway ships no callback file.

## Credits

Developed by pRieStaKos — <info@cubric.gr>

Special thanks to [Stergios Zgouletas](https://github.com/zstergios) — [web-expert.gr](https://www.web-expert.gr)
