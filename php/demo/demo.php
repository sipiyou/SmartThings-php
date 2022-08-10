<?php

require ('../smartthings.php');

$dbgTxts = array("Kritisch","Info","Debug","LowLevel");

function writeToCustomLog($lName, $dbgTxt, $output) {
    printf ("%s => %s\n",$lName.$dbgTxt,$output);
}

function exec_debug ($thisTxtDbgLevel, $str) {
    global $debugLevel;
    global $dbgTxts;
    
    if ($thisTxtDbgLevel <= $debugLevel) {
        writeToCustomLog("LBS_ST_LBSID", $dbgTxts[$thisTxtDbgLevel], $str);
    }
}

$debugLevel = 1;

$access_key = 'INSERT YOUR TOKEN IN HERE'; // remove this comment and put in your token from cloud

$mySmartThings = new smartThingsCloud ($access_key);
$respData = $mySmartThings->getAllDevices();

if ($respData !== false) {
    foreach($respData['items'] as $i => $item) {
        $deviceID = $item['deviceId'];
        $deviceName = $item['label'];

        $currentDevice = '';

        exec_debug (1, "items:". sprintf ("id=%s, label=%s, deviceTypeName=%s\n", $item['deviceId'], $item['label'],$item['deviceTypeName']));
        
        if (preg_match ('/ocf\ air\ conditioner/i', $item['deviceTypeName'])) {
            $currentDevice = $allDevices['AirCon'][$deviceID] = new SamsungOCFAirConditioner ($deviceID, $mySmartThings);
            print "$deviceName\n";
            $currentDevice->getDeviceStatus();
            $currentDevice->processDeviceStatus();

            if ($deviceName == 'Klimaanlage Gast') {
                $currentDevice->setSwitch(1); // turn on unit
                $currentDevice->setCoolingPoint (18); // turn on unit
                // .. see smartthings.php::SamsungOCFAirConditioner for further supported functions
            }


        } else if (preg_match ('/ocf\ tv/i', $item['deviceTypeName'])) {
            $currentDevice = $allDevices['TV'][$deviceID] = new SamsungOCFTV ($deviceID, $mySmartThings);
            $currentDevice->getDeviceStatus();
            $currentDevice->processDeviceStatus();
        }
    }
} else {
    exec_debug(0,"Invalid token");
}
?>
