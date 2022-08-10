<?php

class generalHelpers {
    public function normalize (&$val, $div) {
        if ($val > 0)
            $val /= $div;
    }
   
    public function get01StateFromArray ($array, $key) {
        $ret = false;
        if (isset ($array[$key])) {
            if ($array[$key] === 1) {
                $ret = true;
            }
        }

        return ($ret);
    }

    public function getStateFromArray ($array, $key,$subkey = null) {
        $ret = '';
        if (isset($subkey)) {
            if (isset ($array[$key][$subkey]))
                $ret = $array[$key][$subkey];
             
        } else {
            if (isset ($array[$key]))
                $ret = $array[$key];
        }
        return ($ret);
    }

    public function getIntStateFromArray ($array, $key) {
        $ret = 0;
        if (isset ($array[$key]))
            $ret = intval ($array[$key]);
     
        return ($ret);
    }

    public function getAllValues ($path) {
        $val = $this->getStateFromArray ($path,'value');
        $unit = $this->getStateFromArray ($path, 'unit');
        $ts = $this->getStateFromArray ($path, 'timestamp');
        //print "value = $val, $unit : $ts\n";
        return array ($val, $unit, $ts);
    }

    public function getValues ($path) {
        $val = $this->getStateFromArray ($path,'value');
        //print "value = $val, $unit : $ts\n";
        return ($val);
    }
}

class smartThingsCloud {
    private $access_key;
    public $devicesUrl = "https://api.smartthings.com/v1/devices";
    public $deviceCommandUrl;
    public $deviceMainStatusUrl;

    public $lastError; // used if 'error' is returned
    public $lastResponse; // used if 'results' is returned

    public function __construct ($cloudToken) {
        $this->access_key = $cloudToken;

        $this->deviceCommandUrl = $this->devicesUrl."/%s/commands";
        $this->deviceMainStatusUrl = $this->devicesUrl."/%s/components/main/status";
    }
    
    public function requestData ($url, $method, $payload) {
        $fields_string = '';
    
        $ch = curl_init($url);
        
        exec_debug (3, "stCloud:Url=".$url);
        
        if (is_array($payload)) {
            $fields_string = http_build_query($payload);
        } else {
            $fields_string = $payload;
        }

        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array( 'Authorization: Bearer ' . $this->access_key ),
            CURLOPT_POSTFIELDS => $fields_string,
        )
        );

        if (!empty ($fields_string))
            exec_debug (3, "stCloud:Fields".json_encode($fields_string));
        
        $resp = curl_exec($ch);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        exec_debug (3, "stCloud:Resp ($httpCode)".$resp);
        
        if ($httpCode == 200) {
            $respData = json_decode($resp,true);
            return ($respData);
        } else {
            // 401 = unauthorized
            return (false);
        }
    }

    public function composeCmd ($capability, $command, $arguments = null) {
        $innerArray = array ("component" => "main",
                             "capability" => $capability,
                             "command" => $command,
        );

        if ($arguments !== null) {
            if (is_array ($arguments)) {
                $innerArray += array ("arguments" => $arguments);
            } else {
                $innerArray += array ("arguments" => array($arguments));
            }
        }
    
        $myArray = array ("commands" => array ($innerArray));
        return (json_encode($myArray, JSON_UNESCAPED_SLASHES));
    }
    
    public function getAllDevices () {
        return $this->requestData ($this->devicesUrl, "GET", "");
    }

    public function getDeviceStatusByDeviceID ($deviceID) {
        return $this->requestData ($this->devicesUrl."/$deviceID/status", "GET", "");
    }

    public function getDeviceHealthByDeviceID ($deviceID) {
        return $this->requestData ($this->devicesUrl."/$deviceID/health", "GET", "");
    }

    public function setDeviceCommand ($deviceID, $request) {
        $status = $this->requestData (sprintf ($this->deviceCommandUrl, $deviceID), "POST", $request);
        if (isset($status['error'])) {
            $this->lastError = $status['error'];
            return (false);
        }
        $this->lastResponse = $status['results'];
        return (true);
    }

    public function setDeviceCommandCompose ($deviceID, $capability, $command, $arguments = null) {
        // composeCmd + setDeviceCommand
        $innerArray = array ("component" => "main",
                             "capability" => $capability,
                             "command" => $command,
        );

        if ($arguments !== null) {
            if (is_array ($arguments)) {
                $innerArray += array ("arguments" => $arguments);
            } else {
                $innerArray += array ("arguments" => array($arguments));
            }
        }
    
        $myArray = array ("commands" => array ($innerArray));
        $request = json_encode($myArray, JSON_UNESCAPED_SLASHES);

        $status = $this->requestData (sprintf ($this->deviceCommandUrl, $deviceID), "POST", $request);
        exec_debug (1, "$deviceID:$capability:$command.result=".json_encode($status));
        
        if (isset($status['error'])) {
            $this->lastError = $status['error'];
            return (false);
        }
        $this->lastResponse = $status['results'];
        return (true);
    }
}
 
class SamsungOCFTV extends generalHelpers {
    public $hasUpdatedDeviceStatus;
    public $myDeviceID;
    public $isOnline;
    
    private $stCloud;

    public $jsonData;
    public $deviceStatus = array (
        "switch" => 0,
        "audioVolume" => 0,
        "tvChannel" => 0,
        "tvChannelName" => 0,
        "inputSource" => 0,
        "audioMute" => 0,
    );
    
    public function __construct ($deviceID, smartThingsCloud $stCloud) {
        $this->stCloud = $stCloud;
        $this->hasUpdatedDeviceStatus = false;
        $this->myDeviceID = $deviceID;
        //$this->getDeviceStatus();
    }

    public function getHealth() {
        $res = $this->stCloud->getDeviceHealthByDeviceID ($this->myDeviceID);
        if (isset ($res['state'])) {
            if ($res['state'] == 'ONLINE')
                return true;
        }
        return false;
    }
    
    public function getDeviceStatus () {
        $response = $this->stCloud->getDeviceStatusByDeviceID ($this->myDeviceID);

        if (isset($response['components']['main'])) {
            $this->hasUpdatedDeviceStatus = 1;
        } else {
            $this->hasUpdatedDeviceStatus = 0;
        }
        $this->jsonData = $response;

        return ($this->hasUpdatedDeviceStatus);
    }
    
    public function setSwitch ($onOff) {
        $val = ($onOff == 1) ? "on" : "off";
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "switch","$val");
        return $result;
    }

    public function setAudioVolume ($volume) {
        // input values = 0 .. 100%
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID,"audioVolume","setVolume",$volume);
        return $result;
    }

    public function processDeviceStatus () {
        $res = $this->jsonData;
 
        if (isset($res['components']['main'])) {

            $this->deviceStatus['disabledCapacities'] = $this->getValues ($res['components']['main']['custom.disabledCapabilities']['disabledCapabilities']);
            $this->deviceStatus['switch'] = $this->getValues ($res['components']['main']['switch']['switch']);
            $this->deviceStatus['audioVolume'] = $this->getValues ($res['components']['main']['audioVolume']['volume']);
            
            $this->deviceStatus['tvChannel'] = $this->getValues ($res['components']['main']['tvChannel']['tvChannel']);
            $this->deviceStatus['tvChannelName']     = $this->getValues ($res['components']['main']['tvChannel']['tvChannelName']);
            $this->deviceStatus['inputSource']  = $this->getValues ($res['components']['main']['mediaInputSource']['inputSource']);
            $this->deviceStatus['audioMute'] = $this->getValues ($res['components']['main']['audioMute']['mute']);
            
            //print_r ($res);
            
            exec_debug (1, json_encode($this->deviceStatus));

            return (true);
        }
        
        $this->hasUpdatedDeviceStatus = false;
        return (false);
    }
    
}

class SamsungOCFAirConditioner extends generalHelpers {
    public $hasUpdatedDeviceStatus;
    public $myDeviceID;
    public $isOnline;
    
    private $stCloud;

    public $jsonData;
    
    public $deviceStatus = array (
        "switch" => 0,
        "audioVolume" => 0,
        "acOptionalMode" => 0, // supportedAcOptionalMode : [0] => off,[1] => sleep [2] => speed [3] => windFree [4] => windFreeSleep
        "humidity"=> 0, // value in %
        "mode"=> 0,
        "fanMode"=>  0,
        "fanOscillationMode"=> 0,
        "temperatureMeasurement"=> 0,
        "thermostatCoolingSetpoint" => 0,
        "autoCleaningMode" => 0,
        "otnDUID" => 0,
        "softwareUpdate" => 0,
        "doNotDisturbMode" => 0,
        "spiMode" => 0,
        "rssi" => 0,
        "dustFilterUsage" => 0, // value in %
        "dustFilterStatus" => 0,
        "dustFilterCapacity" => 0, // value in hours
        "disabledCapacities" => 0,
    );

    public function __construct ($deviceID, smartThingsCloud $stCloud) {
        $this->stCloud = $stCloud;
        $this->hasUpdatedDeviceStatus = false;
        $this->myDeviceID = $deviceID;
        //$this->getDeviceStatus();
    }

    public function getHealth() {
        $res = $this->stCloud->getDeviceHealthByDeviceID ($this->myDeviceID);
        if (isset ($res['state'])) {
            if ($res['state'] == 'ONLINE')
                return true;
        }
        return false;
    }
    
    public function getDeviceStatus () {
        $response = $this->stCloud->getDeviceStatusByDeviceID ($this->myDeviceID);

        if (isset($response['components']['main'])) {
            $this->hasUpdatedDeviceStatus = 1;
        } else {
            $this->hasUpdatedDeviceStatus = 0;
        }
        $this->jsonData = $response;

        return ($this->hasUpdatedDeviceStatus);
    }
    
    public function processDeviceStatus () {
        $res = $this->jsonData;
 
        if (isset($res['components']['main'])) {

            $this->deviceStatus['disabledCapacities'] = $this->getValues ($res['components']['main']['custom.disabledCapabilities']['disabledCapabilities']);

            //print_r ($res['components']['main']);
            /*
              foreach ($res['components']['main'] as $key => $element) {
              if (in_array ($key, $this->deviceStatus['disabledCapacities'])) {
              continue;
              //print "is disabled >>";
              }
              print "$key\n";
              //print_r ($element);
              }
            */
            $this->deviceStatus['humidity'] = $this->getValues ($res['components']['main']['relativeHumidityMeasurement']['humidity']);
            $this->deviceStatus['mode']     = $this->getValues ($res['components']['main']['airConditionerMode']['airConditionerMode']);
            $this->deviceStatus['fanMode']  = $this->getValues ($res['components']['main']['airConditionerFanMode']['fanMode']);
            $this->deviceStatus['fanOscillationMode'] = $this->getValues ($res['components']['main']['fanOscillationMode']['fanOscillationMode']);
            $this->deviceStatus['temperatureMeasurement']= $this->getValues ($res['components']['main']['temperatureMeasurement']['temperature']);
            $this->deviceStatus['thermostatCoolingSetpoint'] = $this->getValues ($res['components']['main']['thermostatCoolingSetpoint']['coolingSetpoint']);
            $this->deviceStatus['audioVolume'] = $this->getValues ($res['components']['main']['audioVolume']['volume']);
            $this->deviceStatus['autoCleaningMode'] = $this->getValues ($res['components']['main']['custom.autoCleaningMode']['autoCleaningMode']);
            $this->deviceStatus['otnDUID'] = $this->getValues ($res['components']['main']['samsungce.softwareUpdate']['otnDUID']);
            $this->deviceStatus['softwareUpdate'] = $this->getValues ($res['components']['main']['samsungce.softwareUpdate']['newVersionAvailable']);
            $this->deviceStatus['doNotDisturbMode'] = $this->getValues ($res['components']['main']['custom.doNotDisturbMode']['doNotDisturb']);
            $this->deviceStatus['switch'] = $this->getValues ($res['components']['main']['switch']['switch']);
            $this->deviceStatus['spiMode'] = $this->getValues ($res['components']['main']['custom.spiMode']['spiMode']);
            $this->deviceStatus['dustFilterUsage'] = $this->getValues ($res['components']['main']['custom.dustFilter']['dustFilterUsage']);
            $this->deviceStatus['dustFilterStatus'] = $this->getValues ($res['components']['main']['custom.dustFilter']['dustFilterStatus']);
            $this->deviceStatus['dustFilterCapacity'] = $this->getValues ($res['components']['main']['custom.dustFilter']['dustFilterCapacity']);
            $this->deviceStatus['acOptionalMode'] = $this->getValues ($res['components']['main']['custom.airConditionerOptionalMode']['acOptionalMode']);

            //$this->deviceStatus['rssi'] = $this->getStateFromArray ($res['components']['main']['execute']['data']['value']['payload']['x.com.samsung.rm.rssi'],"0");
            
            exec_debug (1, json_encode($this->deviceStatus));

            return (true);
        }
        
        $this->hasUpdatedDeviceStatus = false;
        return (false);
    }

    public function setDisplayLight ($onOff) {
        // Die Funktion dreht die Werte um, damit es richtig abgebildet wird. Intern bei Sasmsung vertauscht
        $val = ($onOff == 0) ? "Light_On" : "Light_Off";
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "execute","execute",array ("mode/vs/0", array("x.com.samsung.da.options" => array ("$val"))));
        return $result;
    }

    public function setSwitch ($onOff) {
        $val = ($onOff == 1) ? "on" : "off";
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "switch","$val");
        return $result;
    }

    public function setCoolingPoint ($temp) {
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "thermostatCoolingSetpoint","setCoolingSetpoint",$temp);
        return $result;
    }

    public function setAudioVolume ($volume) {
        // input values = 0 / 100
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID,"audioVolume","setVolume",$volume);
        return $result;
    }
    
    public function setAirConditionerMode ($mode) {
        $modes = array ("auto","cool","dry","fan","heat","wind");

        $val = "auto";

        if (isset ($modes[$mode]))
            $val = $modes[$mode];

        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "airConditionerMode","setAirConditionerMode","$val");
        return $result;
    }
    
    public function setAirConditionerFanMode ($mode) {
        $mode = array ("auto","low","medium","high","turbo");

        $val = "auto";
        
        if (isset ($mode[$mode]))
            $val = $mode[$mode];
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "airConditionerFanMode","setFanMode","$val");
        return $result;
    }

    public function setFanOscillationMode ($mode) {
        $modes = array ("fixed","all","vertical","horizontal");
        
        $val = "fixed";
        
        if (isset ($modes[$mode]))
            $val = $modes[$mode];

        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "fanOscillationMode","setFanOscillationMode","$val");
        return $result;
    }

    public function setAutoCleaning ($onOff) {
        $val = ($onOff == 1) ? "on" : "off";
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID,"custom.autoCleaningMode","setAutoCleaningMode","$val");
        return $result;
    }

    public function setAirConditionerOptionalMode ($mode) {
        $modes = array ("off","sleep","speed","windFree","windFreeSleep");
        
        $val = "off";
        
        if (isset ($modes[$mode]))
            $val = $modes[$mode];
                                                
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "custom.airConditionerOptionalMode","setAcOptionalMode","$val");
        return $result;
    }

    public function checkForFirmwareUpdate () {
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "execute","checkForFirmwareUpdate");
    }
}

?>