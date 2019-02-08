<?php
defined('_SMSNOTIFY') or die('Restricted access');
//--------------------------SMSME.gr API v0.1-----------------------------------------
function smsmegr_gatewaydetails()
{
    $details = array();
    $details["name"] = "SMSMe.gr Gateway";
    $details["country"] = "Greece / International";
    $details["site"] = "https://www.smsme.gr";
    $details["pricelist"] = "https://smsme.gr/timokatalogos.aspx";
    $details["developer"] = "pRieStaKos | info@cubric.gr";
    $details["schedulesms"] = true; /*set true if gateway supports sms scheduling*/
    $details["unicodesupport"] = false; /*set true if gateway supports Unicode sms*/
    $details["params"]["Originator"] = array("type" => "text", "value" => "", "name" => "Originator", "description" => "If you leave it empty it will get the global SenderID value from <<Settings>>");

    return $details;
}

function smsmegr_sendsms($params)
{
    $params["Username"] = urlencode($params["Username"]);
    $params["Password"] = urlencode($params["Password"]);
    $params["Originator"] = (!empty($params["Originator"])) ? trim($params["Originator"]) : trim($params["senderid"]);
    $params["Mobile"] = urlencode($params["Mobile"]); //Phone(s) seperated by commas

    /*DEBUG*/
    $paramsString = print_r($params, true);
    #echo $paramsString; //Debug via print to screen
    #mail('my@email.com','SMS Notify Debug',$paramsString); //Debug via e-mail

    //If your gateway supports SMS Scheduling EXAMPLE
    $smsDate = "";
    if ($params["smsDate"] != '') {
        $datetime = date('yyyy-mm-dd HH:mm:ss', $params["smsDate"]); // change date format
        $smsDate = "&smsDate=" . $datetime; // add param
    }
    $params["Body"] = smscut($params["message"], 160); //short sms body
    if ($params["unicode"] == "yes") $params["Body"] = unicode($params["Body"]); //unicode() converts ascci text to unicode data

    #EXAMPLE
    $url = "http://webservice.smsme.gr/SendSmsRequest.aspx?Username=" . urlencode($params["Username"]) . "&Password=" . urlencode($params["Password"]) . '&Body=' . urlencode($params["Body"]);
    $url .= "&Mobile=" . $params["Mobile"] . "&Originator=" . $params["Originator"];
    if (!empty($smsDate)) $url .= $smsDate; //if supported

    $data = getRemoteData($url); // Send request
    /*Returns
      $data["response"]; =>The response
      $data["error"]; =>Connections errors (rare)
    */

    //Communications with gateway API server error check
    if (!empty($data['error']) || empty($data["response"])) return array('error' => $data['error'], 'smsid' => time()); //stop because of communication error

    //example $data["response"]="OK,id12121212,20-08-2013"; or $data["response"]="ERROR:005";

    $values = array();
    $parts = explode(',', $data["response"]);
    if ($parts[0] == 'OK') {
        $values["smsid"] = $parts[1]; //// REQUIRED to able to get the status smsmegr_getsmsstatus()
    } else {
        $parts = explode(':', $data["response"]);
        if ($parts[0] == 'ERROR') {
            $values['error'] = smsmegr_errorcodes($parts[1]);
        } else {
            $values['error'] = $data["response"]; //Unknown reason-response
        }
    }
    logger('send.smsmegr', $url, $data, array_merge($values, $params), array($params["Username"], $params["Password"])); //ModuleLogging

    return $values;
}

function smsmegr_getSmsBalance($params)
{
    $url = "http://webservice.smsme.gr/login.aspx?Username=" . urlencode($params["Username"]) . "&Password=" . urlencode($params["Password"]);
    $data = getRemoteData($url);

    //Communications with gateway API server error check
    if (!empty($data['error'])) return array('error' => $data['error'], 'credits' => 0); //stop because of communication error

    //example $data["response"]="Credit:1200";
    $values = array();
    //$send = explode(":", $data["response"]);
    if (is_numeric($data["response"])) {
        $values["credits"] = $data["response"];
    } else {
        $values["error"] = $data["response"];
        $values["credits"] = 0;
    }
    return $values;
}

function smsmegr_getsmsstatus($params)
{
    $url = "http://webservice.smsme.gr/Reports.aspx?Username=" . urlencode($params["Username"]) . "&Password=" . urlencode($params["Password"]) . "&Mobile=" . $params["Mobile"];

    $Sdate = date('yyyy-mm-dd HH:mm:ss', $params["Sdate"]); // change start date format
    $Edate = date('yyyy-mm-dd HH:mm:ss', $params["Edate"]); // change end date format

    $url .= "&Sdate=" . $Sdate . "&Edate=" . $Edate;

    $data = getRemoteData($url);

    //$data["response"]; =>The response
    //$data["error"]; =>Connections errors (rare)

    //Communications with gateway API server error check
    if (!empty($data['error'])) return array('status' => 2, 'cost' => 0); //stop because of communication error,we don't pass the error because may it's temporary so we set it as Pending (status=2)

    //example $data["response"]="003,id12121212,2c,20-08-2013";
    $parts = explode(',', $data["response"]);

    /* ====== $error_code vars ====== */
    # 0 Not Delivered Failed/ Error (Permanent)
    # 1 Delivered Succesfully (Permanent)
    # 2 Sent but Pending (Not Permanent) *
    # 3 Unknown (Not Permanent) *

    switch ($parts[0]) {
        case '001':
            $error_code = 3; // Unknown
            break;
        case '002':
        case '008':
        case '011':
            $error_code = 2; //Pending
            break;
        case '003':
        case '004':
            $error_code = 1; //Success
            break;
        case '005':
        case '006':
        case '007':
        case '009':
        case '010':
        case '012':
            $error_code = 0; // Failed
            break;
        default:
            $error_code = 3;
    }

    $values = array();
    $values["status"] = $error_code;
    if ($error_code == 0) $values['error'] = smsmegr_errorcodes($parts[0]);
    $values["cost"] = str_replace('c', '', $parts[1]); // if cost is available pass the value here as number
    return $values;
}

//Helper Function!
function smsmegr_errorcodes($code)
{
    $error = '';
    if ($code == '005') {
        $error = 'No credits';
    } elseif ($code == '006') {
        $error = 'Invalid destination';
    }
    //etc
    return $error;
}