<?php

declare(strict_types=1);
/**
 * @Package:         libs
 * @File:            JoT_ModBus.php
 * @Create Date:     09.07.2020 16:54:15
 * @Author:          Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:   07.01.2021 13:12:19
 * @Modified By:     Jonathan Tanner
 * @Copyright:       Copyright(c) 2020 by JoT Tanner
 * @License:         Creative Commons Attribution Non Commercial Share Alike 4.0
 *                   (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)
 */
require_once __DIR__ . '/../libs/JoT_Traits.php';  //Bibliothek mit allgemeinen Definitionen & Traits

/**
 * JoTModBus ist die Basisklasse für erweiterte ModBus-Funktionalität.
 * Erweitert die Klasse IPSModule.
 */
class JoTModBus extends IPSModule {
    use VariableProfile;
    protected const VT_Boolean = VARIABLETYPE_BOOLEAN;
    protected const VT_Integer = VARIABLETYPE_INTEGER;
    protected const VT_UnsignedInteger = 10;
    protected const VT_SignedInteger = VARIABLETYPE_INTEGER;
    protected const VT_Float = VARIABLETYPE_FLOAT;
    protected const VT_Real = VARIABLETYPE_FLOAT;
    protected const VT_String = VARIABLETYPE_STRING;
    protected const MB_BigEndian = 0; //ABCDEFGH => ABCDEFGH
    protected const MB_BigEndian_ByteSwap = 1; //ABCD => BADC oder ABCDEFGH => BADCFEHG
    protected const MB_BigEndian_WordSwap = 2; //ABCD => CDAB oder ABCDEFGH => CDABGHEF
    protected const MB_LittleEndian = 3; //ABCD => DCBA oder ABCDEFGH => HGFEDCBA
    protected const MB_LittleEndian_ByteSwap = 4; //ABCD => CDAB oder ABCDEFGH => GHEFCDAB
    protected const MB_LittleEndian_WordSwap = 5; //ABCD => BADC oder ABCDEFG => FEHGBADC
    protected const FC_Read_Coil = 1; //HEX 0x01
    protected const FC_Read_DiscreteInput = 2; //HEX 0x02
    protected const FC_Read_HoldingRegisters = 3; //HEX 0x03
    protected const FC_Read_InputRegisters = 4; //HEX 0x04
    protected const FC_Write_SingleCoil = 5; //HEX 0x05
    protected const FC_Write_SingleHoldingRegister = 6; //HEX 0x06
    protected const FC_Write_MultipleCoils = 15; //HEX 0x0F
    protected const FC_Write_MultipleHoldingRegisters = 16; //HEX 0x10
    protected const STATUS_Ok_InstanceActive = 102;
    protected const STATUS_Error_RequestTimeout = 408;
    protected const STATUS_Error_PreconditionRequired = 428;
    private const PREFIX = 'JoTMB';
    private $CurrentAction = false; //Für Error-Handling bei Lese-/Schreibaktionen

    /**
     * Interne Funktion des SDK.
     * Initialisiert Connection.
     * @access public
     */
    public function Create() {
        parent::create();
        $this->ConnectParent('{A5F663AB-C400-4FE5-B207-4D67CC030564}'); //ModBus Gateway
    }

    /**
     * Interne Funktion des SDK.
     * Wird ausgeführt wenn die Konfigurations-Änderungen gespeichet werden.
     * @access public
     */
    public function ApplyChanges() {
        parent::ApplyChanges();
    }

    /**
     * PHP Error-Handler - wird aufgerufen wenn ModBus einen Fehler zurück gibt
     * @param int $errNr PHP-ErrorNummer
     * @param string $errMsg PHP-Fehlermeldung
     * @access private
     */
    public function ModBusErrorHandler(int $ErrLevel, string $ErrMsg) {
        $action = '';
        if (is_array($this->CurrentAction)) {
            $action = $this->CurrentAction['Action'] . ' ';
            $error = utf8_decode($ErrMsg);
            $function = $this->CurrentAction['Data']['Function'];
            $address = $this->CurrentAction['Data']['Address'];
            $quantity = $this->CurrentAction['Data']['Quantity'];
            $data = $this->CurrentAction['Data']['Data'];
            $ErrMsg = "MODBUS-MESSAGE: $error (Function: $function, Address: $address, Quantity: $quantity, Data: $data)";
        }
        $this->SendDebug("$action ERROR $ErrLevel", $ErrMsg, 0);
        if ($ErrLevel == 2) {//Zeitüberschreitung
            $this->LogMessage("INSTANCE: $this->InstanceID ACTION: $action ERROR $ErrLevel $ErrMsg", KL_ERROR);
            $this->SetStatus(self::STATUS_Error_RequestTimeout);
        }
    }

    /**
     * Liest ModBus-Daten vom Gerät aus
     * @param int $Function - ModBus-Function (siehe const FC_Read_xyz)
     * @param int $Address - Start-Register
     * @param int $Quantity - Anzahl der zu lesenden Register
     * @param int/float $Factor - Multiplikationsfaktor für gelesenen Wert
     * @param int $MBType - Art der Daten-Übertragung (siehe const MB_xyz)
     * @param int $VarType - Daten-type der abgefragten Werte (siehe const VT_xyz)
     * @return mixed der angeforderte Wert oder NULL im Fehlerfall
     */
    protected function ReadModBus(int $Function, int $Address, int $Quantity, $Factor, int $MBType, int $VarType) {
        if ($Function > self::FC_Read_InputRegisters) {
            echo "Wrong Function ($Function) for Read. Please use WriteModBus."; //Wird nur für Programmierer auftauchen, daher so
            return null;
        }

        if ($this->CheckConnection() === true) {
            //Daten für ModBus-Gateway vorbereiten
            $sendData = [];
            $sendData['DataID'] = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'; //ModBus Gateway RX
            $sendData['Function'] = $Function;
            $sendData['Address'] = $Address;
            $sendData['Quantity'] = $Quantity;
            $sendData['Data'] = '';

            //Error-Handler setzen und Daten lesen
            set_error_handler([$this, 'ModBusErrorHandler']);
            $this->CurrentAction = ['Action' => 'ReadModBus', 'Data' => $sendData];
            $readData = $this->SendDataToParent(json_encode($sendData));
            restore_error_handler();
            $this->CurrentAction = false;

            //Daten auswerten
            if ($readData !== false) {//kein Fehler - empfangene Daten verarbeiten
                $readValue = substr($readData, 2); //Geräte-Adresse & Funktion aus der Antwort entfernen
                $this->SendDebug("ReadModBus FC $Function Addr $Address x $Quantity RAW", $readValue, 1);
                $value = $this->SwapValue($readValue, $MBType);
                $this->SendDebug("SwapValue Addr $Address MBType $MBType", $value, 1);
                $value = $this->ConvertMBtoPHP($value, $VarType);
                $this->SendDebug("ConvertMBtoPHP Addr $Address VarType $VarType", $value, 0);
                $value = $this->CalcFactor($value, $Factor);
                $this->SendDebug("CalcFactor Addr $Address Factor $Factor", $value, 0);
                if ($this->GetStatus() !== self::STATUS_Ok_InstanceActive) {
                    $this->SetStatus(self::STATUS_Ok_InstanceActive);
                }
                return $value;
            }
        }
        return null;
    }

    /**
     * Führt einen Swap gemäss $MBType für $Value durch
     * @param string $Value Wert für Swap
     * @param string $MBType ModBus Datenübertragungs-Typ
     * @return string umgekehrte Zeichenfolge von $value
     * @access protected
     */
    protected function SwapValue(string $Value, int $MBType) {
        switch ($MBType) {
            case self::MB_BigEndian: //ABCDEFGH => ABCDEFGH
                break;
            case self::MB_BigEndian_ByteSwap: //ABCD => BADC oder ABCDEFGH => BADCFEHG
                $Value = $this->ByteSwap($Value);
                break;
            case self::MB_BigEndian_WordSwap: //ABCD => CDAB oder ABCDEFGH => CDABGHEF
                $Value = $this->WordSwap($Value);
                break;
            case self::MB_LittleEndian: //ABCD => DCBA oder ABCDEFGH => HGFEDCBA
                $Value = $this->LittleEndian($Value);
                break;
            case self::MB_LittleEndian_ByteSwap: //ABCD => CDAB oder ABCDEFGH => GHEFCDAB
                $Value = $this->LittleEndian($Value);
                $Value = $this->ByteSwap($Value);
                break;
            case self::MB_LittleEndian_WordSwap: //ABCD => BADC oder ABCDEFG => FEHGBADC
                $Value = $this->LittleEndian($Value);
                $Value = $this->WordSwap($Value);
                break;
        }
        return $Value;
    }

    /**
     * Ermittelt den korrekten Variablen-Typ für eine Instanz-Variable basierend auf ModBus-DatenTyp & -Faktor
     * @param int $VarType ist der ModBus-DatenTyp
     * @param mixed $Factor ist der ModBus-Faktor
     * @return int IPS Variablen-Type
     * @access protected
     */
    protected function GetIPSVarType(int $VarType, $Factor) {
        if ($VarType == self::VT_UnsignedInteger) {//PHP kennt keine Unsigned Integer für Ziel-Variable, ModBus schon
            $VarType = VARIABLETYPE_INTEGER;
        }
        if ($VarType == self::VT_Integer && is_float($Factor)) {//Wenn Faktor Float ist muss Ziel-Variable ebenfalls Float sein
            $VarType = VARIABLETYPE_FLOAT;
        }
        return $VarType;
    }

    /**
     * Prüft ob alle notwendigen Verbindungs-Instanzen konfiguriert sind
     * @return boolen true, wenn alles i.O.
     */
    private function CheckConnection() {
        $status = $this->HasActiveParent();
        if ($status === false) {
            $this->SetStatus(self::STATUS_Error_PreconditionRequired);
        }
        return $status;
    }

    /**
     * Vertauscht immer zwei Zeichen (8 Bit-Blöcke) in $Value (=>ByteSwap)
     * @param string $Value Original-Wert
     * @return string $Value mit vertauschten Zeichen
     * @access private
     */
    private function ByteSwap(string $Value) {
        $Chars = str_split($Value, 1);
        if (count($Chars) > 1) {
            $Value = '';
            $x = 0;
            while (($x + 1) < count($Chars)) {
                $Value = $Value . $Chars[$x + 1] . $Chars[$x];
                $x = $x + 2;
            }
        }
        return $Value;
    }

    /**
     * Vertauscht immer zwei Wörter (16 Bit-Blöcke) in $Value (=>WordSwap)
     * @param string $Value Original-Wert
     * @return string $Value mit vertauschten Wörtern
     * @access private
     */
    private function WordSwap(string $Value) {
        $Words = str_split($Value, 2); //Ein Word besteht aus zwei Zeichen à 8 Bit = 16 Bit (oder 1 ModBus Register)
        if (count($Words) > 1) {
            $Value = '';
            $x = 0;
            while (($x + 1) < count($Words)) {
                $Value = $Value . $Words[$x + 1] . $Words[$x];
                $x = $x + 2;
            }
        }
        return $Value;
    }

    /**
     * Kehrt $Value komplett um / rückwärts lesend (=>LittleEndian)
     * @param string $Value Original-Wert
     * @return string $Value mit umgekerter Zeichenfolge
     * @access private
     */
    private function LittleEndian(string $Value) {
        return strrev($Value);
    }

    /**
     * Konvertiert $Value in den entsprechenden PHP-DatenTyp
     * @param string $Value die ModBus-Daten
     * @param int $VarType der ModBus-Datentyp
     * @return mixed Konvertierte Daten oder null, wenn Konvertierung nicht möglich ist
     * @access private
     */
    private function ConvertMBtoPHP(string $Value, int $VarType) {
        switch ($VarType) {
            case self::VT_Boolean:
                return ord($Value) == 0x01;
            case self::VT_SignedInteger:
                if (((unpack('c', $Value)[1] >> 7) & 1) == 1) {//Wenn Wert negativ ist (höchstes Bit = 1)
                    $add = str_repeat('FF', PHP_INT_SIZE - strlen($Value)); //Auffüllen auf PHP_INT_SIZE (meistens 32- oder 64-Bit)...
                    $bin = base_convert($add, 16, 2) . base_convert(unpack($this->GetPackFormat($VarType, $Value), $Value)[1], 10, 2); //...und mit Original-Wert zusammensetzen (als Binär-String)
                    //Da bindec immer nach unsigned umrechnet, muss Wert entsprechende umgekehrt werden
                    for ($i = 0; $i < PHP_INT_SIZE * 8; $i++) {
                        $bin[$i] = strval(intval(!(bool) $bin[$i])); //flip 0 zu 1 und umgekehrt
                    }
                    return (bindec($bin) + 1) * -1; //positive Zahl wieder in Negative umwandeln
                }
                return intval(bin2hex($Value), 16);
            case self::VT_UnsignedInteger:
                return intval(bin2hex($Value), 16);
            case self::VT_Float:
                if (strlen($Value) < 2) {//String ist zu kurz für Float
                    return null;
                }
                return unpack($this->GetPackFormat($VarType), $Value)[1];
            case self::VT_String:
                return trim($Value);
            default:
                return null;
        }
    }

    /**
     * Ermittelt $format für die Funktion pack / unpack basierend auf der Länge von $Value und dem VariablenTyp $VarType.
     * Dabei wird immer davon ausgegangen, dass $Value als BigEndian vorhanden ist.
     * @param int $VarType der ModBus-Datentyp
     * @param string optional $Value die ModBus-Daten
     * @return mixed Format-String oder null, wenn Konvertierung nicht möglich ist
     * @access private
     */
    private function GetPackFormat(int $VarType, string $Value = '') {
        switch ($VarType) {
            case self::VT_SignedInteger:
                $format = [1 => 'C', 2 => 'n', 4 => 'N', 8 => 'J']; //8Bit Signed, 16-/32-/64Bit Signed BigEndian
                if (array_key_exists(strlen($Value), $format)) {
                    return $format[strlen($Value)];
                }
                return 'C*'; //8Bit Signed für alle Zeichen
            case self::VT_Float:
                return 'G'; //Gleitkommazahl BigEndian
            default:
                echo "Wrong VarType($VarType) for GetPackFormat."; //Wird nur für Programmierer auftauchen, daher so
                return null;
        }
    }

    /**
     * Multipliziert $Value mit $Factor, sofern $Value kein String oder $Factor nicht 0 ist
     * @param mixed $Value - Original-Wert
     * @param int|float $Factor - Multiplikationswert
     * @return mixed Ergebnis der Berechnung oder original $Value
     * @access private
     */
    private function CalcFactor($Value, $Factor) {
        if (is_string($Value) || $Factor == 0) {
            return $Value;
        }
        return $Value * $Factor;
    }
}
