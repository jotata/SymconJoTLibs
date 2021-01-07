<?php

declare(strict_types=1);
/**
 * @Package:         libs
 * @File:            JoT_Traits.php
 * @Create Date:     09.07.2020 16:54:15
 * @Author:          Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:   01.01.2021 19:09:10
 * @Modified By:     Jonathan Tanner
 * @Copyright:       Copyright(c) 2020 by JoT Tanner
 * @License:         Creative Commons Attribution Non Commercial Share Alike 4.0
 *                   (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)
 */

/**
 * Allgemeine Konstanten
 */
const DEBUG_FORMAT_TEXT = 0;
const DEBUG_FORMAT_HEX = 1;

/**
 * Trait mit Hilfsfunktionen für Variablen-Profile.
 */
trait VariableProfile {
    use Translation;
    /**
     * Liest die JSON-Profil-Definition aus $JsonFile (UTF-8) und erstellt/aktualisiert die entsprechenden Profile.
     * @param string $JsonFile mit Profil-Definitionen gemäss Rückgabewert von IPS_GetVariableProfile.
     * @param array $ReplaceMap mit Key -> Values welche in JSON ersetzt werden sollen.
     * @access protected
     */
    protected function ConfigProfiles(string $JsonFile, $ReplaceMap = []) {
        $JSON = $this->GetJSONwithVariables($JsonFile, $ReplaceMap);
        $profiles = json_decode($JSON, true, 5);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo 'INSTANCE: ' . $this->InstanceID . ' ACTION: ConfigProfiles: Error in Profile-JSON (' . json_last_error_msg() . "). Please check \$ReplaceMap or File-Content of $JsonFile.";
            exit;
        }
        foreach ($profiles as $profile) {
            $this->MaintainProfile($profile);
        }
    }

    /**
     * Erstellt/Aktualisiert ein Variablen-Profil.
     * Erstellt den Profil-Namen immer mit Modul-Prefix.
     * Kann einfach zum Klonen eines vorhandenen Variablen-Profils verwendet werden.
     * @param mixed $Profile Array gemäss Rückgabewert von IPS_GetVariableProfile.
     * @access protected
     */
    protected function MaintainProfile(array $Profile) {
        $Name = $this->CheckProfileName($Profile['ProfileName']);
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, $Profile['ProfileType']);
        } else {
            $exProfile = IPS_GetVariableProfile($Name);
            if ($exProfile['ProfileType'] != $Profile['ProfileType']) {
                //Prüfe ob Profil ausserhalb des Modules genutzt wird
                $insts = IPS_GetInstanceListByModuleID(static::MODULEID);
                $vars = IPS_GetVariableList();
                foreach ($vars as $vId) {
                    $var = IPS_GetVariable($vId);
                    if ($var['VariableProfile'] == $Name || $var['VariableCustomProfile'] == $Name) {
                        if (IPS_GetObject($vId)['ObjectIdent'] == '' || array_search(IPS_GetParent($vId), $insts) === false) {//Variable ist keine Instanz-Variable ODER gehört nicht zu einer Instanz von diesem Modul
                            echo 'INSTANCE: ' . $this->InstanceID . ' ACTION: MaintainProfile: Variable profile type (' . $Profile['ProfileType'] . ") does not match for profile '$Name'. Can not move profile because it is used by other variable ($vId). Please fix manually!";
                            exit;
                        }
                    }
                }
                //Profil nur von diesem Modul verwendet - Löschen und neu erstellen
                IPS_DeleteVariableProfile($Name);
                IPS_CreateVariableProfile($Name, $Profile['ProfileType']);
            }
        }
        if (array_key_exists('Icon', $Profile)) {
            IPS_SetVariableProfileIcon($Name, $Profile['Icon']);
        }
        if (array_key_exists('Prefix', $Profile) && array_key_exists('Suffix', $Profile)) {
            IPS_SetVariableProfileText($Name, $Profile['Prefix'], $Profile['Suffix']);
        }
        if (array_key_exists('MinValue', $Profile) && array_key_exists('MaxValue', $Profile) && array_key_exists('StepSize', $Profile)) {
            IPS_SetVariableProfileValues($Name, $Profile['MinValue'], $Profile['MaxValue'], $Profile['StepSize']);
        }
        if ($Profile['ProfileType'] == VARIABLETYPE_FLOAT && array_key_exists('Digits', $Profile)) {
            IPS_SetVariableProfileDigits($Name, $Profile['Digits']);
        }
        if (array_key_exists('Associations', $Profile)) {
            foreach ($Profile['Associations'] as $Assoc) {
                IPS_SetVariableProfileAssociation($Name, $Assoc['Value'], $this->Translate($Assoc['Name']), $Assoc['Icon'], $Assoc['Color']);
            }
        }
    }

    /**
     * Überprüft ob der Profil-Name neu ist. Falls ja, wird sichergestellt, dass er den Modul-Prefix enthält.
     * Ist das Profil bereits vorhanden, wird der Name nicht verändert.
     * Dies erlaubt es, im Modul die Profile ohne Prefix anzugeben und trotzdem bei neuen Profilen den Prefix voranzustellen.
     * Damit wird sichergestellt, dass die Best Practice für Module eingehalten wird (https://gist.github.com/paresy/236bfbfcb26e6936eaae919b3cfdfc4f).
     * @param string $Name der zu prüfende Profil-Name.
     * @return string den Profil-Namen mit Modul-Prefix (falls nötig).
     * @access protected
     */
    protected function CheckProfileName(string $Name) {
        if ($Name !== '' && !IPS_VariableProfileExists($Name)) {
            $Prefix = 'JoT';
            if (!is_null(self::PREFIX)) {
                $Prefix = self::PREFIX;
            }
            if (substr($Name, strlen("$Prefix.")) !== "$Prefix.") {//Modul-Prefix zu Namen hinzufügen
                $Name = "$Prefix.$Name";
            }
        }
        return $Name;
    }

    /**
     * Überprüft ob der Profil-Name für den entsprechenden DatenTyp (falls angegeben) existiert.
     * @param string $Name der zu prüfende Profil-Name.
     * @param int optional $DataType des Profils.
     * @return bool true wenn vorhanden, sonst false.
     * @access protected
     */
    protected function VariableProfileExists(string $Name, int $DataType = -1) {
        if (IPS_VariableProfileExists($Name)) {
            if ($DataType >= 0) {
                if ($DataType >= 10) {//Selbstdefinierte Datentypen sollten immer das 10-fache der System-VariablenTypen sein.
                    $DataType = intval($DataType / 10);
                }
                $profiles = IPS_GetVariableProfileListByType($DataType);
                if (!in_array(strtolower($Name), array_map('strtolower', $profiles))) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}

/**
 * Funktionen zum Übersetzen von IPS-Konstanten.
 * @param $value = der zu übersetzende Wert
 * @return string der übersetzte Wert
 * @access private
 */
trait Translation {
    /**
     * Liest JSON aus $JsonFile (UTF-8) und ersetzt darin Variablen von $ReplaceMap.
     * @param string $JsonFile mit als JSON formatiertem Text.
     * @param array $ReplaceMap mit Key -> Values welche in JSON ersetzt werden sollen.
     * @return string mit ersetzten Variabeln im JSON.
     * @access protected
     */
    protected function GetJSONwithVariables(string $JsonFile, $ReplaceMap = []) {
        $JSON = file_get_contents($JsonFile);
        foreach ($ReplaceMap as $search => $replace) {
            if (is_string($replace)) {
                $replace = "\"$replace\"";
            }
            $JSON = str_replace("\"$search\"", $replace, $JSON);
        }
        return $JSON;
    }
    private function TranslateVarType($value) {
        switch ($value) {
            case -1:
                return $this->Translate('All');
            case VARIABLETYPE_BOOLEAN:
                return 'Boolean';
            case VARIABLETYPE_FLOAT:
                return 'Float';
            case VARIABLETYPE_INTEGER:
                return 'Integer';
            case VARIABLETYPE_STRING:
                return 'String';
        }
        return $value;
    }
    private function TranslateObjType($value) {
        //Modul-Bezeichnung
        if ((substr($value, 0, 1) . substr($value, -1)) == '{}') {//Modul-GUID
            if (IPS_ModuleExists($value)) {
                $m = IPS_GetModule($value);
                if ($m['Vendor'] != '') {
                    return $m['ModuleName'] . ' (' . $m['Vendor'] . ')';
                }
                return $m['ModuleName'];
            }
        } else {//ObjectType
            switch ($value) {
                case '':
                case -1:
                    return $this->Translate('All');
                case OBJECTTYPE_CATEGORY:
                    return $this->Translate('Category');
                case OBJECTTYPE_EVENT:
                    return $this->Translate('Event');
                case OBJECTTYPE_INSTANCE:
                    return $this->Translate('Instance');
                case OBJECTTYPE_LINK:
                    return $this->Translate('Link');
                case OBJECTTYPE_MEDIA:
                    return $this->Translate('Media');
                case OBJECTTYPE_SCRIPT:
                    return $this->Translate('Script');
                case OBJECTTYPE_VARIABLE:
                    return $this->Translate('Variable');
            }
        }
        return $value;
    }

    /**
     * Übersetzt einen Wert in einen lesbaren Boolean-String (false / true)
     * @param mixed $Value - Wert zur Umwandlung in Boolean-String
     * @param bool $Invert - Soll Boolean-Wert invertiert werden?
     * @return string true oder false
     * @access private
     */
    private function ConvertToBoolStr($Value, bool $Invert = false) {
        if (($Value == true && $Invert === false) || ($Value == false && $Invert === true)) {
            return 'true';
        } else {
            return 'false';
        }
    }

    /**
     * Übersetzt $Msg und fügt davor die InstanzID sowie die aufrufende Function hinzu
     * Gibt das Ganze mit echo aus, so dass es bei ausgeführten Scripts direkt angezeigt sonst im Log erscheint
     * @param string $Msg - Nachricht in Englisch (ist sprintf-kompatibel)
     * @param mixed (optional) ... $Args - Zusätzliche Parameter für sprintf können als weitere Argumente angegeben werden
     * @return string den Übersetzen String wie er per echo ausgegeben wurde (z.B. für Debug)
     * @access private
     */
    private function ThrowMessage(string $Msg) {
        $args = func_get_args();
        if (count($args) > 1) {
            $args[0] = $this->Translate($Msg);
            $Msg = call_user_func_array('sprintf', $args);
        } else {
            $Msg = $this->Translate($Msg);
        }
        $func = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        $fMsg = 'INSTANCE: ' . $this->InstanceID . " ACTION: $func: $Msg";
        $this->SendDebug($func, $Msg, 0);
        echo "$fMsg\r\n";
        return $fMsg;
    }
}

/**
 * Funktion zum Vertecken von public functions
 */
trait RequestAction {
    /**
     * IPS-Funktion IPS_RequestAction($InstanceID, $Ident, $Value).
     * Wird verwendet um aus dem WebFront Variablen zu "schalten" (offiziell) oder public functions zu verstecken (inoffiziell)
     * Benötigt die versteckte Function mehrere Parameter, müssen diese als JSON in $Value übergeben werden.
     * Idee: https://www.symcon.de/forum/threads/38463-RegisterTimer-zum-Aufruf-nicht-%C3%B6ffentlicher-Funktionen?p=370053#post370053
     * @param string $Ident - Function oder Variable zum aktualisieren / ausführen.
     * @param string $Value - Wert für die Variable oder Parameter für den Befehl
     * @access public
     */
    public function RequestAction($Ident, $Value) {
        if (method_exists($this, $Ident)) {
            $this->SendDebug('RequestAction', "Function: '$Ident' Parameter(s): '$Value'", 0);
            return $this->$Ident($Value); //versteckte public function aufrufen
        }
        parent::RequestAction($Ident, $Value); //offizielle Verwendung
    }
}

/**
 * Funktionen zum Ent-/Sperren bei paralellen Zugriffen.
 */
trait Semaphore {
    /**
     * Versucht eine Sperre zu setzen und wiederholt dies bei Misserfolg bis zu 100 mal.
     * @param string $Ident Ein String der die Sperre bezeichnet.
     * @return boolean True bei Erfolg, False bei Misserfolg.
     */
    private function Lock($Ident) {
        for ($i = 0; $i < 100; $i++) {
            if (IPS_SemaphoreEnter($Ident, 1)) {
                return true;
            } else {
                IPS_Sleep(mt_rand(1, 5));
            }
        }
        return false;
    }

    /**
     * Löscht eine Sperre.
     * @param string $ident Ein String der den Lock bezeichnet.
     */
    private function Unlock(string $Ident) {
        IPS_SemaphoreLeave($Ident);
    }
}