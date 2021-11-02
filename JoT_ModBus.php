<?php

declare(strict_types=1);
/**
 * @Package:         libs
 * @File:            JoT_ModBus.php
 * @Create Date:     09.07.2020 16:54:15
 * @Author:          Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:   02.11.2021 16:12:36
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
    use Translation;
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
        $this->RegisterAttributeInteger('SystemEndianness', $this->GetSystemEndianness());
        $this->RegisterPropertyBoolean('WriteMode', false); //User muss bestätigen, dass er schreiben will
        $this->RegisterAttributeInteger('WriteSocketID', 0); //Temporär bis DatenFlow in IPS gefixt ist
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
        //Fehler-Ausgabe darf nicht mit $this->ThrowMessage (oder echo) gemacht werden, da sonst Konfigurations-Form nicht mehr geladen werden kann
        $action = '';
        if (is_array($this->CurrentAction)) {
            $action = $this->CurrentAction['Action'];
            $error = utf8_decode($ErrMsg);
            $function = $this->CurrentAction['Data']['Function'];
            $address = $this->CurrentAction['Data']['Address'];
            $quantity = $this->CurrentAction['Data']['Quantity'];
            $data = bin2hex($this->CurrentAction['Data']['Data']);
            $ErrMsg = "MODBUS-MESSAGE: $error (Function: $function, Address: $address, Quantity: $quantity, Data: $data) ";
        }
        $this->SendDebug("$action ERROR $ErrLevel", $ErrMsg, 0);
        if ($ErrLevel == 2) {//Zeitüberschreitung
            $this->LogMessage("INSTANCE: $this->InstanceID ACTION: $action ERROR $ErrLevel $ErrMsg", KL_ERROR);
            $this->SetStatus(self::STATUS_Error_RequestTimeout);
        }
    }

    /**
     * Prüft ob der ModBus-Server einen Fehler meldet (wird das ev. bereits vom ModBus-GW gemacht?)
     * @param string $Response - die Antwort vom Server
     * @return mixed false wenn alles i.O. oder String mit Fehler
     */
    protected function CheckModBusException(string $Response) {
        $result = unpack('CFunction/CErrNr', $Response);
        if ($result['Function'] < 128) { //Kein Fehler - Im Fehlerfall wird in der Antwort das erste Bit auf 1 gesetzt (=> Function +128)
            return false;
        }
        $errNames = array('ILLEGAL FUNCTION', 'ILLEGAL DATA ADDRESS', 'ILLEGAL DATA VALUE', 'SERVER DEVICE FAILURE', 'ACKNOWLEDGE', 'SERVER DEVICE BUSY', 'MEMORY PARITY ERROR', 'GATEWAY PATH UNAVAILABLE');
        if ($result['ErrNr'] >= count($errNames)) {
            return $result['ErrNr'] . ' - UNKNOWN ERROR';
        } 
        return $result['ErrNr'] . ' - ' . $errNames[$result['ErrNr'] - 1];
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
        if (!in_array($Function, [self::FC_Read_Coil, self::FC_Read_DiscreteInput, self::FC_Read_HoldingRegisters, self::FC_Read_InputRegisters])) {
            echo "Wrong Function ($Function) for Read. Please use WriteModBus."; //Wird nur für Programmierer auftauchen, daher so
            return null;
        }

        if ($this->CheckConnection() === true) {
            //Daten für ModBus-Gateway vorbereiten
            $sendData = [];
            $sendData['DataID'] = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'; //ModBus Gateway RX/TX
            $sendData['Function'] = $Function;
            $sendData['Address'] = $Address;
            $sendData['Quantity'] = $Quantity;
            $sendData['Data'] = '';

            //Error-Handler setzen und Daten lesen
            set_error_handler([$this, 'ModBusErrorHandler']);
            $this->CurrentAction = ['Action' => 'ReadModBus', 'Data' => $sendData];
            $response = $this->SendDataToParent(json_encode($sendData));
            restore_error_handler();
            $this->CurrentAction = false;

            //Daten auswerten
            if ($response !== false) {//kein Fehler seitens IPS - empfangene Daten verarbeiten
                //Auf ModBus-Fehler prüfen
                $error = $this->CheckModBusException($response);
                if ($error === false) { //alles i.O.
                    $readValue = substr($response, 2); //Function & Anzahl Daten-Bytes aus der Antwort entfernen
                    $this->SendDebug("ReadModBus FC $Function Addr $Address x $Quantity RX RAW", $readValue, 1);
                    $value = $this->SwapValue($readValue, $MBType);
                    $this->SendDebug("SwapValue Addr $Address MBType " . $this->TranslateMBType($MBType), $value, 1);
                    $value = $this->ConvertMBtoPHP($value, $VarType, $Quantity);
                    $this->SendDebug("ConvertMBtoPHP Addr $Address VarType " . $this->TranslateVarType($VarType), $value, 0);
                    $value = $this->CalcFactor($value, $Factor);
                    $this->SendDebug("CalcFactor Addr $Address Factor (*) $Factor", $value, 0);
                    if ($this->GetStatus() !== self::STATUS_Ok_InstanceActive) {
                        $this->SetStatus(self::STATUS_Ok_InstanceActive);
                    }
                    return $value;
                } else { //ModBus-Fehler
                    $msg = $this->ThrowMessage('Error while reading data: %s', "$error (Function: $Function, Address: $Address, Quantity: $Quantity)");
                    $this->LogMessage($msg, KL_ERROR);
                }
            }
        }
        return null;
    }

    /**
     * Schreibt ModBus-Daten auf das Gerät zurück
     * @param mixed $Value - denr zu schreibende Wert
     * @param int $Function - ModBus-Function (siehe const FC_Write_xyz)
     * @param int $Address - Start-Register
     * @param int $Quantity - Anzahl der zu lesenden Register
     * @param int/float $Factor - Divisionsfaktor für den zu schreibenden Wert
     * @param int $MBType - Art der Daten-Übertragung (siehe const MB_xyz)
     * @param int $VarType - Daten-Typ des zu schreibenden Wertes (siehe const VT_xyz)
     * @return mixed true bei Erfolg oder false bei Fehler
     */
    protected function WriteModBus($Value, int $Function, int $Address, int $Quantity, $Factor, int $MBType, int $VarType) {
        //Kontrolliert mit https://www.scadacore.com/tools/programming-calculators/online-hex-converter/
        if (!in_array($Function, [self::FC_Write_SingleCoil, self::FC_Write_MultipleCoils, self::FC_Write_SingleHoldingRegister, self::FC_Write_MultipleHoldingRegisters])) {
            echo "Wrong Function ($Function) for Write. Please use ReadModBus."; //Wird nur für Programmierer auftauchen, daher so
            return false;
        }
        if ($this->ReadPropertyBoolean('WriteMode') !== true) { //User muss aktiv bestätigt haben, dass er schreiben will
            $msg = $this->ThrowMessage('WriteMode is disabled in instance configuration.');
            $this->LogMessage($msg, KL_ERROR);
            return false;
        }
        if ($this->CheckConnection() === true) {
            //Wert umrechnen
            $this->SendDebug("WriteModBus FC $Function Addr $Address", $Value, 0);
            $Value = $this->CalcFactor($Value, $Factor, true);
            $this->SendDebug("CalcFactor Addr $Address Factor (/) $Factor", $Value, 0);
            $Value = $this->ConvertPHPtoMB($Value, $VarType, $Quantity);
            $this->SendDebug("ConvertPHPtoMB Addr $Address x $Quantity VarType " . $this->TranslateVarType($VarType), $Value, 1);
            $Value = $this->SwapValue($Value, $MBType);
            $this->SendDebug("SwapValue Addr $Address MBType " . $this->TranslateMBType($MBType), $Value, 1);
            $this->SendDebug("WriteModBus FC $Function Addr $Address x $Quantity TX RAW", $Value, 1);

            //Daten für ModBus-Gateway vorbereiten
            $sendData = [];
            $sendData['DataID'] = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'; //ModBus Gateway RX/TX
            $sendData['Function'] = $Function;
            $sendData['Address'] = $Address;
            $sendData['Quantity'] = $Quantity;
            //$sendData['Data'] = $Value;
            $sendData['Data'] = utf8_encode($Value);

            //Error-Handler setzen und Daten schreiben
            //Daten werden nicht via DatenFlow geschrieben, da dafür $sendData['Data'] UTF-8 codiert werden müsste,
            //was bei Bytes mit Werten > 127 dazu führt, dass die gesendeten Daten verändert werden.
            //Details siehe https://www.symcon.de/forum/threads/41294-Modbus-TCP-BOOL-Wert-senden?p=447472#post447472
            //Bis dieses Problem im Datenfluss von IPS behoben ist, spielen wir den ModBus-Gateway selber und schicken die Daten danach direkt an den Client-Socket.
            //Sollte ab IPS 5.6 nicht mehr nötig sein :-)
            set_error_handler([$this, 'ModBusErrorHandler']);
            $this->CurrentAction = ['Action' => 'WriteModBus', 'Data' => $sendData];
            $response = $this->SendDataToParent(json_encode($sendData));
            //$response = $this->SendDataViaClientSocket($sendData);
            restore_error_handler();
            $this->CurrentAction = false;

            //Daten auswerten
            if ($response !== false) { //kein Fehler seitens IPS
                if ($this->GetStatus() !== self::STATUS_Ok_InstanceActive) {
                    $this->SetStatus(self::STATUS_Ok_InstanceActive);
                }
                //Auf ModBus-Fehler prüfen
                $error = $this->CheckModBusException($response);
                if ($error === false) { //alles i.O.
                    return true;
                } else { //ModBus-Fehler
                    $msg = $this->ThrowMessage('Error while writing data: %s', "$error (Function: $Function, Address: $Address, Quantity: $Quantity, Data: " . bin2hex($Value) . ')');
                    $this->LogMessage($msg, KL_ERROR);
                }
            }
        }
        return false;
    }

    /**
     * Erstellt mit Infos aus dem ModBus-Gateway der Instanz und $Data die ModBus TCP-Daten
     * und sendet diese direkt an die Client-Socket Instanz.
     * Grund: siehe https://www.symcon.de/forum/threads/41294-Modbus-TCP-BOOL-Wert-senden?p=447472#post447472
     * Sollte ab IPS 5.6 nicht mehr nötig sein :-)
     * @param array $Data - mit Keys Address, Function, Quantity, Data
     * @return mixed true bei Erfolg oder false bei Fehler
     * @access protected
     */
    protected function SendDataViaClientSocket($Data) {
        $mbGWID = IPS_GetInstance($this->InstanceID)['ConnectionID']; //ID des ModBus-Gateways
        $mbCSID = IPS_GetInstance($mbGWID)['ConnectionID']; //ID des ClientSockets
        $mbGW = json_decode(IPS_GetConfiguration($mbGWID), true); //Konfiguration vom ModBus-Gateway holen
        if ($mbGW['GatewayMode'] !== 0) {
            $this->ThrowMessage('Writing to ModBus is only possible over ModBus TCP at the moment :-(');
            exit;
        }

        //Damit kein FlowHandler-Error betreffend TransactionID im Systemlog auftaucht, senden wir über einen eigenen ClientSocket
        $mbWSID = $this->ReadAttributeInteger('WriteSocketID');
        if ($mbWSID === 0 || @IPS_GetObject($mbWSID) === false) {
            $mbWSID = IPS_CreateInstance('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'); //ClientSocket
            IPS_SetName($mbWSID, self::PREFIX . '_WriteSocket for #' . $this->InstanceID);
            $this->WriteAttributeInteger('WriteSocketID', $mbWSID);
        }
        $csConfig = IPS_GetConfiguration($mbCSID);
        if (IPS_GetConfiguration($mbWSID) !== $csConfig) {
            IPS_SetConfiguration($mbWSID, $csConfig); //Konfiguration vom ClientSocket der Instantz zu WriteSocket übernehmen
            IPS_ApplyChanges($mbWSID);
        }

        //Infos zu ModBus TCP Paketen: https://ipc2u.com/articles/knowledge-base/detailed-description-of-the-modbus-tcp-protocol-with-command-examples/
        $txID = rand($Data['Address'], 0xFFFF); //2Bytes - Zufällige TransactionID generieren (kommt bei Antwort zur Identifikation wieder zurück)

        //FunctionCode 'Funktion + Adresse (+ Anzahl Register/Coils + Anzahl Daten-Bytes)' in Byte-Folge BigEndian erstellen
        if ($Data['Function'] == self::FC_Write_SingleCoil || $Data['Function'] == self::FC_Write_SingleHoldingRegister) {
            $fc = pack('Cn', $Data['Function']/*1Byte*/, $Data['Address']/*2Bytes*/);
        } elseif ($Data['Function'] == self::FC_Write_MultipleCoils || $Data['Function'] == self::FC_Write_MultipleHoldingRegisters) {
            $fc = pack('CnnC', $Data['Function']/*1Byte*/, $Data['Address']/*2Bytes*/, $Data['Quantity']/*2Bytes*/, strlen($Data['Data'])/*1Byte*/);
        }

        $txLen = 1 + strlen($fc . $Data['Data']); //Nachrichten-Länge = 1 Byte für UnitID + Anzahl Bytes FunctionCode + Anzahl Bytes Daten
        $header = pack('nnnC', $txID/*2Bytes*/, 0x0000/*ModBus Protocol Identifier*/, $txLen/*2Bytes*/, $mbGW['DeviceID']/*1Byte*/); //ModBus Application Header mit Byte-Folge BigEndian erstellen
        $sendData = $header . $fc . $Data['Data']; //TCP-Daten zusammensetzen

        if (IPS_GetInstance($mbWSID)['InstanceStatus'] === 102) { //Instanz ist aktiv
            return CSCK_SendText($mbWSID, $sendData);
        }
        $this->ThrowMessage('WriteSocket-Instance #%1$u not ready.');
        return false;
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
     * Ermittelt die Endianness des aktuellen Systems
     * @access private
     * @return int self::MB_BigEndian oder self::MB_LittleEndian
     */
    private function GetSystemEndianness() {
        $bigEndian = pack('n', 255);
        $sysEndian = pack('S', 255);
        if ($bigEndian === $sysEndian) {
            return self::MB_BigEndian;
        }
        return self::MB_LittleEndian;
    }

    /**
     * Prüft ob alle notwendigen Verbindungs-Instanzen konfiguriert sind
     * @return boolen true, wenn alles i.O.
     */
    private function CheckConnection() {
        $status = $this->HasActiveParent();
        if ($status === false) {
            $this->SetStatus(self::STATUS_Error_PreconditionRequired);
            $this->ThrowMessage('No connection to ModBus-Device.');
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
     * @param string $Value die ModBus-Daten als BigEndian
     * @param int $VarType der ModBus-Datentyp
     * @param int $Quantity Anzahl ModBus-Register à 16Bit
     * @return mixed Konvertierte Daten oder null, wenn Konvertierung nicht möglich ist
     * @access private
     */
    private function ConvertMBtoPHP(string $Value, int $VarType, int $Quantity) {
        $cBytes = ($Quantity * 16 / 8); //1 Register (Quantity) entspricht 16Bit / 8 = Anzahl Bytes
        if (strlen($Value) !== $cBytes) {
            $this->ThrowMessage('Length of returned data (%1$u) does not match lenght of requested data (%2$u). Please check configuration.', strlen($Value), $cBytes);
            return null;
        }
        $pf = $this->GetPackFormat($VarType, $Quantity);
        switch ($VarType) {
            case self::VT_Boolean:
                return boolval(unpack($pf, $Value)[1]);
            case self::VT_SignedInteger:
                //Daten für unpack bei sInt sind maschinenabhängig => $Value kommt als BigEndian und muss daher ev. noch in LittleEndian konvertiert werden
                if ($this->ReadAttributeInteger('SystemEndianness') === self::MB_LittleEndian) {
                    $Value = $this->LittleEndian($Value);
                }
                return unpack($pf, $Value)[1];
            case self::VT_UnsignedInteger:
            case self::VT_Float:
                return unpack($pf, $Value)[1];
            case self::VT_String:
                return trim($Value);
            default:
                $this->ThrowMessage('Unknown VarType (%s). Stopping.', $VarType);
                return null;
        }
    }

    /**
     * Konvertiert $Value in einen HEX-String passend zu $VarType und $Quantity
     * @param mixed $Value die PHP-Daten
     * @param int $VarType der ModBus-Datentyp
     * @param int $Quantity Anzahl ModBus-Register à 16Bit
     * @return string Konvertierte Daten
     * @access private
     */
    private function ConvertPHPtoMB($Value, int $VarType, int $Quantity) {
        //Kontrolliert mit http://www.binaryconvert.com
        $cBytes = ($Quantity * 16 / 8); //1 Register (Quantity) entspricht 16Bit / 8 = Anzahl Bytes
        $pf = $this->GetPackFormat($VarType, $Quantity);
        $bin = pack($pf, $Value); //Wert in Binär-String konvertieren
        $min = 0;
        switch ($VarType) { //gültige Wertebereiche ermitteln
            case self::VT_Boolean:
                $max = 1;
                break;
            case self::VT_UnsignedInteger:
                $max = unpack($pf, str_pad(chr(0xFF), $cBytes, chr(0xFF), STR_PAD_RIGHT))[1];
                break;
            case self::VT_Float:
                $min = unpack($pf, str_pad(chr(0xFF) . chr(0x7F), $cBytes, chr(0xFF), STR_PAD_RIGHT))[1];
                $max = unpack($pf, str_pad(chr(0x7F) . chr(0x7F), $cBytes, chr(0xFF), STR_PAD_RIGHT))[1];
                break;
            case self::VT_SignedInteger:
                $min = str_pad(chr(0x80), $cBytes, chr(0x00), STR_PAD_RIGHT);
                $max = str_pad(chr(0x7F), $cBytes, chr(0xFF), STR_PAD_RIGHT);
                //Rückgabe von pack für sInt ist maschinenabhängig => $bin muss daher ev. noch in BigEndian konvertiert werden
                if ($this->ReadAttributeInteger('SystemEndianness') === self::MB_LittleEndian) {
                    $bin = strrev($bin); //Convert to BigEndian
                    $min = strrev($min);
                    $max = strrev($max);
                }
                $min = unpack($pf, $min)[1];
                $max = unpack($pf, $max)[1];
                break;
            case self::VT_String:
                $max = $cBytes;
                break;
            default:
                $this->ThrowMessage('Unknown VarType (%s). Stopping.', $VarType);
                exit;
        }
        //Gültigkeit der Daten mit $Value überprüfen, da pack $bin bereits auf die max. mögliche Länge getrimmt hat
        if (($VarType === self::VT_String && strlen($bin) > $max) || ($VarType !== self::VT_String && ($Value < $min || $Value > $max))) {
            $this->ThrowMessage('Value \'%1$s\' is out of range (min: %2$s max: %3$s) for VarType \'%4$s\' with Quantity \'%5$u\'. Stopping.', $Value, $min, $max, $this->TranslateVarType($VarType), $Quantity);
            exit;
        }
        return str_pad($bin, $cBytes, chr(0x00), STR_PAD_LEFT); //auf ganze Anzahl angegebene Register auffüllen (falls nötig)
    }

    /**
     * Ermittelt $format für die Funktion pack / unpack basierend auf der Länge von $Value und dem VariablenTyp $VarType.
     * Dabei wird immer davon ausgegangen, dass $Value als BigEndian vorhanden ist.
     * @param int $VarType der ModBus-Datentyp
     * @param string optional $Value die ModBus-Daten
     * @return mixed Format-String oder null, wenn Konvertierung nicht möglich ist
     * @access private
     */
    private function GetPackFormat(int $VarType, int $Quantity) {
        $cBytes = $Quantity * 16 / 8; //1 Register (Quantity) entspricht 16Bit / 8 = Anzahl Bytes
        switch ($VarType) {
            case self::VT_Boolean:
            case self::VT_UnsignedInteger:
                $format = [1 => 'C', 2 => 'n', 4 => 'N', 8 => 'J']; //8Bit Unsigned, 16-/32-/64Bit Unsigned BigEndian
                break;
            case self::VT_SignedInteger:
                $format = [1 => 'c', 2 => 's', 4 => 'l', 8 => 'q']; //8Bit Signed, 16-/32-/64Bit Signed Maschinenabhängig (muss ev. in BigEndian konertiert werden)
                break;
            case self::VT_Float:
                $format = [4 => 'G', 8 => 'E']; //32-/64Bit Gleitkommazahl BigEndian
                break;
            case self::VT_String:
                $format = [$cBytes => 'a*']; //mit NUL gefüllte Zeichenkette
                break;
            default:
                $this->ThrowMessage('Unknown VarType (%s). Stopping.', $VarType);
                exit;
        }
        if (array_key_exists($cBytes, $format)) {
            return $format[$cBytes];
        }
        $this->ThrowMessage('Invalid Quantity (%1$u) for VarType \'%2$s\'. Stopping.', $Quantity, $this->TranslateVarType($VarType));
        exit;
    }

    /**
     * Multipliziert $Value mit $Factor, sofern $Value kein String oder $Factor nicht 0 ist
     * @param mixed $Value - Original-Wert
     * @param int|float $Factor - Multiplikations-/Dididierungs-Wert
     * @param bool $Div (optional) false = multiplizieren, true = dividieren
     * @return mixed Ergebnis der Berechnung oder original $Value
     * @access private
     */
    private function CalcFactor($Value, $Factor, $Div = false) {
        if (is_string($Value) || $Factor === 0) {
            return $Value;
        } elseif ($Div) {
            return $Value / $Factor;
        }
        return $Value * $Factor;
    }

    /**
     * Übersetzt $MBTyp in lesbaren String.
     * @param int $MBType gemäss Konstanten self::MB_TYPE_xx
     * @return string mit entsprechendem Text.
     * @access private
     */
    private function TranslateMBType(int $MBType) {
        switch ($MBType) {
            case self::MB_BigEndian:
                return 'BigEndian (ABCDEFGH)';
            case self::MB_BigEndian_ByteSwap:
                return 'BigEndian BS (BADCFEHG)';
            case self::MB_BigEndian_WordSwap:
                return 'BigEndian WS (EFGHABCD)';
            case self::MB_LittleEndian:
                return 'LittleEndian (HGFEDCBA)';
            case self::MB_LittleEndian_ByteSwap:
                return 'LittleEndian BS (GHEFCDAB)';
            case self::MB_LittleEndian_WordSwap:
                return 'LittleEndian WS (DCBAHGFE)';
        }
    }
}
