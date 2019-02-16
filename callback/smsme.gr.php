<?php
include('../../callbackhelper.php');
defined('_SMSNOTIFY') or die('Restricted access');

$params["gateway"] = 'smsmegr'; #REQUIRED (gateway's filename)
$gatewayParams = getGetwaysParams($params["gateway"]);
if ($gatewayParams['sendcountries'] == 'none' || count($_REQUEST) < 2) return false; // Gateway is deactivated OR post DATA IS to small

#UpdateSMS Status byID
# 0 Not Delivered Failed/ Error (Permanent)
# 1 Delivered Successfully (Permanent)
# 2 Sent but Pending (Not Permanent) * 
# 3 Unknown (Not Permanent) *
switch ($_POST["status"]) {
    case '001':
        $NewStatusNumber = 3;
        break;
    case '002':
    case '008':
    case '011':
        $NewStatusNumber = 2;
        break;
    case '003':
    case '004':
        $NewStatusNumber = 1;
        break;
    case '005':
    case '006':
    case '007':
    case '009':
    case '010':
    case '012':
        $NewStatusNumber = 0;
        break;
    default:
        $NewStatusNumber = 3;
}
/*Any change to History SMS Log Table will not react with gateway*/
//delete none required fields from $params
$params["sms_id"] = $_REQUEST["apiMsgId"]; #REQUIRED (gateway's smsid)
$params["sms_status"] = $NewStatusNumber; # accepted values 0 to 3
$params["sms_cost"] = $_REQUEST["charge"]; #Update the sms cost in credits
//$params["sms_date"]='2012-04-12 21:06:00' #Update the dateTime of send action
//$params["sms_name"]=''; # Update the Sent Reason
//$params["scheduled"]='';  # Update the dateTime of Schedule date
$rs = updateHistory($params); //returns array('result','error')
if (!$rs['result']) echo $rs['error'];