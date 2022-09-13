<?php

/*
  (c) 2022 Nima Ghassemi Nejad (sipiyou@hotmail.com)

  v 1.0  - initial release
    1.01 - minor bugfixes
    1.02 - SamsungOCFTV: support for setAudioMute, setTvChannel, setTvChannelUp, setTvChannelDown
    1.03 - several bugfixes
 */

class generalHelpers {
    public function external_dbg ($p1,$p2) {
        if (function_exists('exec_debug'))
            exec_debug ($p1,$p2);
    }
    
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
        $ret = -1;
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


class smartThingsCloud extends generalHelpers {
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

        $this->external_dbg (3, "stCloud:Url=".$url);
        
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
            $this->external_dbg (3, "stCloud:Fields".json_encode($fields_string));
        
        $resp = curl_exec($ch);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->external_dbg (3, "stCloud:Resp ($httpCode)".$resp);
        
        if ($httpCode == 200) {
            $respData = json_decode($resp,true);
            return ($respData);
        } else {
            // 401 = unauthorized | 409 etc.
            $this->lastError = $resp;
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
        $this->external_dbg (1, "$deviceID:$capability:$command.result=".json_encode($status));

        if ($status === false) {
            print "last error = $this->lastError\n";
            //$this->lastError = $status['error'];
            return (false);
        }
        $this->lastResponse = $status['results'];
        return (true);
    }
}

class smartThingsCloudHelper extends generalHelpers {
    public $stCloud;
    
    public $jsonData;
    public $hasUpdatedDeviceStatus;
    public $myDeviceID;
    public $isOnline;
    public $onOffArray = array ("off" => 0, "on" => 1);

    public function __construct ($deviceID, smartThingsCloud $stCloud) {
        $this->stCloud = $stCloud;
        $this->hasUpdatedDeviceStatus = false;
        $this->myDeviceID = $deviceID;
        //$this->getDeviceStatus();
    }
    
    public function loadJsonData ($json) {
        // load Json from string instead of getting it from cloud.
        $tmp = json_decode ($json, true);

        if (isset ($tmp[$this->myDeviceID])) {
            $this->jsonData = $tmp[$this->myDeviceID];
            
            $this->hasUpdatedDeviceStatus = true;            
            return true;
        }

        $this->hasUpdatedDeviceStatus = false;
        $this->external_dbg (1, "deviceID ".$this->myDeviceID." existiert nicht!");
        return false;
    }
    
    public function getDeviceStatus () {
        $response = $this->stCloud->getDeviceStatusByDeviceID ($this->myDeviceID);

        if (isset($response['components']['main'])) {
            $this->hasUpdatedDeviceStatus = true;
        } else {
            $this->hasUpdatedDeviceStatus = false;
        }
        $this->jsonData = $response;

        return ($this->hasUpdatedDeviceStatus);
    }

    public function getHealth() {
        $res = $this->stCloud->getDeviceHealthByDeviceID ($this->myDeviceID);
        if (isset ($res['state'])) {
            if ($res['state'] == 'ONLINE')
                return true;
        }
        return false;
    }
}

class SamsungOCFTV extends smartThingsCloudHelper {
    public $supportedSoundModes = array(); // use values in
    public $supportedPlaybackCommands = array();
    public $supportedMediaInputSources = array();
    
    public $deviceStatus = array (
        "switch" => 0,
        "audioVolume" => 0,
        "tvChannel" => 0,
        "tvChannelName" => 0,
        "audioMute" => 0,
        "soundMode" => 0,
        "playbackStatus" => 0,
        "mediaInputSource" => 0,
    );
        
    public function getSwitch () {
        return ($this->getIntStateFromArray ( $this->onOffArray, $this->deviceStatus['switch']));
    }

    public function setSwitch ($onOff) {
        $val = ($onOff == 1) ? "on" : "off";
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "switch","$val");
        return $result;
    }

    public function getAudioVolume() {
        return ($this->deviceStatus['audioVolume']);
    }
    
    public function setAudioVolume ($volume) {
        // input values = 0 .. 100%
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID,"audioVolume","setVolume",intval($volume));        
        return $result;
    }

    public function getAudioMute () {
        // unmuted = 0, muted = 1
        return ($this->getIntStateFromArray ( array("unmuted","muted"), $this->deviceStatus['audioMute']));
    }
    
    public function setAudioMute ($mute) {
        $val = ($mute == 1) ? "mute" : "unmute";
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "audioMute","$val");
        return $result;
    }

    public function getTvChannel() {
        return ($this->deviceStatus['tvChannel']);
    }
    
    public function setTvChannel ($channel) {
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "tvChannel","setTvChannel","$channel");
        return $result;
    }

    public function getTvChannelName() {
        return ($this->deviceStatus['tvChannelName']);
    }
    
    public function setTvChannelName ($cname) {
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "tvChannel","setTvChannelName","$cname");
        return $result;
    }
    
    public function setTvChannelUp() {
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "tvChannel","channelUp");
        return $result;
    }

    public function setTvChannelDown() {
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "tvChannel","channelDown");
        return $result;
    }

    public function getSoundMode ($returnResultAsInteger = 0) {
        if ($returnResultAsInteger) {
            return ($this->getIntStateFromArray (array_flip ($this->supportedSoundModes), $this->deviceStatus['soundMode']));
        } else {
            return ($this->deviceStatus['soundMode']);
        }
    }
    
    public function setSoundMode ($mode) {
        // TBD. This function results "success" but nothing happens. API not properly implemented ?!
        $val = "";
        if (is_numeric ($mode)) {
            $mode = intval($mode);
            if (isset ($this->supportedSoundModes[$mode]))
                $val = $this->supportedSoundModes[$mode];
        } else {
            $val = $mode;
        }
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "custom.soundmode","setSoundMode","$val");
        return $result;
    }
    
    public function getPlaybackStatus ($returnResultAsInteger = 0) {
        if ($returnResultAsInteger) {
            return ($this->getIntStateFromArray (array_flip ($this->$supportedPlaybackCommands), $this->deviceStatus['playbackStatus']));
        } else {
            return ($this->deviceStatus['playbackStatus']);
        }
    }

    public function setPlaybackStatus ($mode) {
        // Seems only to work if tv outputs mediaplayback status. On my tv this option is outputting empty string.
        $val = "";
        if (is_numeric ($mode)) {
            $mode = intval($mode);
            if (isset ($this->supportedPlaybackCommands[$mode]))
                $val = $this->supportedPlaybackCommands[$mode];
        } else {
            $val = $mode;
        }
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "mediaPlayback","setPlaybackStatus","$val");
        return $result;
    }

    public function getMediaInputSource ($returnResultAsInteger = 0) {
        if ($returnResultAsInteger) {
            return ($this->getIntStateFromArray (array_flip ($this->supportedMediaInputSources), $this->deviceStatus['mediaInputSource']));
        } else {
            return ($this->deviceStatus['mediaInputSource']);
        }
    }
    
    public function setMediaInputSource ($source) {
        // Seems only to work if tv outputs mediaplayback status. On my tv this option is outputting empty string.
        $val = "";
        if (is_numeric ($source)) {
            $source = intval($source);
            if (isset ($this->supportedMediaInputSources[$source]))
                $val = $this->supportedMediaInputSources[$source];
        } else {
            $val = $source;
        }
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "mediaInputSource","setInputSource","$val");
        return $result;
    }

    
    public function processDeviceStatus () {
        $res = $this->jsonData;

        //print_r ($res);
        if (isset($res['components']['main'])) {

            $this->deviceStatus['soundMode'] = $this->getValues ($res['components']['main']['custom.soundmode']['soundMode']);
            $this->supportedSoundModes = $this->getValues($res['components']['main']['custom.soundmode']['supportedSoundModes']);

            $this->deviceStatus['disabledCapacities'] = $this->getValues ($res['components']['main']['custom.disabledCapabilities']['disabledCapabilities']);
            $this->deviceStatus['switch'] = $this->getValues ($res['components']['main']['switch']['switch']);
            $this->deviceStatus['audioVolume'] = $this->getValues ($res['components']['main']['audioVolume']['volume']);
            
            $this->deviceStatus['tvChannel'] = $this->getValues ($res['components']['main']['tvChannel']['tvChannel']);
            $this->deviceStatus['tvChannelName']     = $this->getValues ($res['components']['main']['tvChannel']['tvChannelName']);
            $this->deviceStatus['audioMute'] = $this->getValues ($res['components']['main']['audioMute']['mute']);

            $this->supportedPlaybackCommands = $this->getValues($res['components']['main']['mediaPlayback']['supportedPlaybackCommands']);
            $this->deviceStatus['playbackStatus'] = $this->getValues ($res['components']['main']['mediaPlayback']['playbackStatus']);

            $this->deviceStatus['mediaInputSource'] = $this->getValues ($res['components']['main']['mediaInputSource']['inputSource']);
            $this->supportedMediaInputSources = $this->getValues($res['components']['main']['mediaInputSource']['supportedInputSources']);

            //print_r ($this->deviceStatus);
            
            $this->external_dbg (1, json_encode($this->deviceStatus));

            return (true);
        }
        
        $this->hasUpdatedDeviceStatus = false;
        return (false);
    }
    
}

class SamsungOCFAirConditioner extends smartThingsCloudHelper {
    // these arrays are generated from json data
    public $supportedAirConditionerModes = array(); // use values in 
    public $supportedAcOptionalMode = array();
    public $supportedAcFanModes = array();
    public $supportedFanOscillationModes = array();
    
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
            $this->supportedAirConditionerModes = $res['components']['main']['airConditionerMode']['supportedAcModes']['value'];
            $this->deviceStatus['mode']     = $this->getValues ($res['components']['main']['airConditionerMode']['airConditionerMode']);

            $this->supportedAcFanModes = $res['components']['main']['airConditionerFanMode']['supportedAcFanModes']['value'];
            $this->deviceStatus['fanMode']  = $this->getValues ($res['components']['main']['airConditionerFanMode']['fanMode']);

            $this->supportedFanOscillationModes = $res['components']['main']['fanOscillationMode']['supportedFanOscillationModes']['value'];
            $this->deviceStatus['fanOscillationMode'] = $this->getValues ($res['components']['main']['fanOscillationMode']['fanOscillationMode']);
            
            $this->deviceStatus['temperatureMeasurement']= $this->getValues ($res['components']['main']['temperatureMeasurement']['temperature']);
            $this->deviceStatus['thermostatCoolingSetpoint'] = $this->getValues ($res['components']['main']['thermostatCoolingSetpoint']['coolingSetpoint']);
            $this->deviceStatus['audioVolume'] = $this->getValues ($res['components']['main']['audioVolume']['volume']);
            $this->deviceStatus['autoCleaningMode'] = strtolower($this->getValues ($res['components']['main']['custom.autoCleaningMode']['autoCleaningMode']));
            $this->deviceStatus['otnDUID'] = $this->getValues ($res['components']['main']['samsungce.softwareUpdate']['otnDUID']);
            $this->deviceStatus['softwareUpdate'] = $this->getValues ($res['components']['main']['samsungce.softwareUpdate']['newVersionAvailable']);
            $this->deviceStatus['doNotDisturbMode'] = strtolower($this->getValues ($res['components']['main']['custom.doNotDisturbMode']['doNotDisturb']));
            $this->deviceStatus['switch'] = strtolower($this->getValues ($res['components']['main']['switch']['switch']));
            $this->deviceStatus['spiMode'] = strtolower($this->getValues ($res['components']['main']['custom.spiMode']['spiMode']));
            $this->deviceStatus['dustFilterUsage'] = $this->getValues ($res['components']['main']['custom.dustFilter']['dustFilterUsage']);
            $this->deviceStatus['dustFilterStatus'] = strtolower($this->getValues ($res['components']['main']['custom.dustFilter']['dustFilterStatus']));
            $this->deviceStatus['dustFilterCapacity'] = $this->getValues ($res['components']['main']['custom.dustFilter']['dustFilterCapacity']);

            $this->supportedAcOptionalMode = $res['components']['main']['custom.airConditionerOptionalMode']['supportedAcOptionalMode']['value'];
            $this->deviceStatus['acOptionalMode'] = $this->getValues ($res['components']['main']['custom.airConditionerOptionalMode']['acOptionalMode']);

            //$this->deviceStatus['rssi'] = $this->getStateFromArray ($res['components']['main']['execute']['data']['value']['payload']['x.com.samsung.rm.rssi'],"0");
            
            $this->external_dbg (1, json_encode($this->deviceStatus));

            return (true);
        }
        
        $this->hasUpdatedDeviceStatus = false;
        return (false);
    }

    public function getHumidity() {
        return $this->deviceStatus['humidity'];
    }

    public function getRoomTemperature() {
        return $this->deviceStatus['temperatureMeasurement'];
    }

    public function getDustFilterUsage() {
        // value in %. Example: FilterCapacity = 500 [hours].
        // 100%  = 500 hours [dustFilterCapacity]
        // 16%   = 80 hours have been passed.
        return $this->deviceStatus['dustFilterUsage'];
    }

    public function dustFilterCapacity() {
        // value is in hours
        return $this->deviceStatus['dustFilterCapacity'];
    }

    public function dustFilterStatusText() {
        return $this->deviceStatus['dustFilterStatus'];
    }

    public function setDisplayLight ($onOff) {
        // Swap on/off. setDisplayLight (0) turs the display off.
        $val = ($onOff == 0) ? "Light_On" : "Light_Off";
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "execute","execute",array ("mode/vs/0", array("x.com.samsung.da.options" => array ("$val"))));
        return $result;
    }

    public function getDoNotDisturb() {
        return ($this->getIntStateFromArray ( $this->onOffArray, $this->deviceStatus['doNotDisturbMode']));
    }

    public function setSuperPlasmaIon ($onOff) {
        $val = ($onOff == 0) ? "Spi_Off" : "Spi_On";
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "execute","execute",array ("mode/vs/0", array("x.com.samsung.da.options" => array ("$val"))));
        return $result;
    }

    public function getSuperPlasmaIon() {
        return ($this->getIntStateFromArray ( $this->onOffArray, $this->deviceStatus['spiMode']));
    }
    
    public function getSwitch () {
        return ($this->getIntStateFromArray ( $this->onOffArray, $this->deviceStatus['switch']));
    }

    public function setSwitch ($onOff) {
        $val = ($onOff == 1) ? "on" : "off";
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "switch","$val");
        return $result;
    }

    public function getCoolingPoint() {
        return ( $this->deviceStatus['thermostatCoolingSetpoint']);
    }
        
    public function setCoolingPoint ($temp) {
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "thermostatCoolingSetpoint","setCoolingSetpoint",intval($temp));
        return $result;
    }

    public function getAudioVolume() {
        return ($this->deviceStatus['audioVolume']);
    }
    
    public function setAudioVolume ($volume) {
        // input values = 0 / 100
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID,"audioVolume","setVolume",intval($volume));
        return $result;
    }

    public function getAirConditionerMode($returnResultAsInteger = 0) {
        //$modes = array ("auto"=>0,"cool" =>1,"dry" =>2,"fan" =>3,"heat"=>4,"wind"=>5);
        if ($returnResultAsInteger) {
            return ($this->getIntStateFromArray (array_flip ($this->supportedAirConditionerModes), $this->deviceStatus['mode']));
        } else {
            return ($this->deviceStatus['mode']);
        }
    }
    
    public function setAirConditionerMode ($mode) {
        //$modes = array ("auto","cool","dry","fan","heat","wind");

        if (is_numeric ($mode)) {
            $val = "auto";

            if (isset ($this->supportedAirConditionerModes[$mode]))
                $val = $this->supportedAirConditionerModes[$mode];
        } else {
            $val = $mode;
        }
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "airConditionerMode","setAirConditionerMode","$val");
        return $result;
    }

    public function getAirConditionerFanMode($returnResultAsInteger = 0) {
        //$modes = array ("auto"=>0,"low"=>1,"medium"=>2,"high"=>3,"turbo"=>4);
        
        if ($returnResultAsInteger) {
            return ($this->getIntStateFromArray ( array_flip($this->supportedAcFanModes), $this->deviceStatus['fanMode']));
        } else {
            return ($this->deviceStatus['fanMode']);
        }
    }
    
    public function setAirConditionerFanMode ($mode) {
        //$mode = array ("auto","low","medium","high","turbo");

        if (is_numeric ($mode)) {
            $val = "auto";
            
            if (isset ($this->supportedAcFanModes[$mode]))
                $val = $this->supportedAcFanModes[$mode];
        } else {
            $val = $mode;
        }
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "airConditionerFanMode","setFanMode","$val");
        return $result;
    }

    public function getFanOscillationMode ($returnResultAsInteger = 0) {
        //$modes = array ("fixed"=>0,"all"=>1,"vertical"=>2,"horizontal"=>3);
        if ($returnResultAsInteger) {
            return ($this->getIntStateFromArray ( array_flip ($this->supportedFanOscillationModes), $this->deviceStatus['fanOscillationMode']));
        } else {
            return ($this->deviceStatus['fanOscillationMode']);
        }
    }
    
    public function setFanOscillationMode ($mode) {
        //$modes = array ("fixed","all","vertical","horizontal");

        if (is_numeric ($mode)) {
            $val = "fixed";
            
            if (isset ($this->supportedFanOscillationModes[$mode]))
                $val = $this->supportedFanOscillationModes[$mode];
        } else {
            $val = $mode;
        }
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "fanOscillationMode","setFanOscillationMode","$val");
        return $result;
    }

    public function getAutoCleaning () {
        return ($this->getIntStateFromArray ( $this->onOffArray, $this->deviceStatus['autoCleaningMode']));
    }
    
    public function setAutoCleaning ($onOff) {
        $val = ($onOff == 1) ? "on" : "off";
        
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID,"custom.autoCleaningMode","setAutoCleaningMode","$val");
        return $result;
    }

    public function getAirConditionerOptionalMode ($returnResultAsInteger = 0) {
        //$modes = array ("off"=>0,"sleep"=>1,"speed"=>2,"windFree"=>3,"windFreeSleep"=>4);
        if ($returnResultAsInteger) {
            return ($this->getIntStateFromArray ( array_flip ($this->supportedAcOptionalMode), $this->deviceStatus['acOptionalMode']));
        } else {
            return ($this->deviceStatus['acOptionalMode']);
        }
    }
        
    public function setAirConditionerOptionalMode ($mode) {
        //$modes = array ("off","sleep","speed","windFree","windFreeSleep");

        if (is_numeric ($mode)) {
            $val = "off";
            
            if (isset ($this->supportedAcOptionalMode[$mode]))
                $val = $this->supportedAcOptionalMode[$mode];
        } else {
            $val = $mode;
        }
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "custom.airConditionerOptionalMode","setAcOptionalMode","$val");
        return $result;
    }

    public function checkForFirmwareUpdate () {
        // does not work. TBD
        $result = $this->stCloud->setDeviceCommandCompose ($this->myDeviceID, "execute","checkForFirmwareUpdate");
    }
}

?>
