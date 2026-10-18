<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TuyaProtocol.php';

/**
 * Tuya-WLAN-Thermostat (Kategorie "wk"), lokal über Protokoll 3.5.
 * Getestet mit "Mini Thermostat" k5hu1er7silr7miw an einer Handtuchheizung.
 *
 * Datenpunkte: 1 Ein/Aus, 2 Modus, 16 Solltemperatur, 24 Isttemperatur,
 * 27 Temperaturkorrektur, 40 Kindersicherung. Alle übrigen DPs landen roh
 * (JSON) in der Variable "Datenpunkte".
 */
class TuyaThermostat extends IPSModule
{
    private const STATUS_OK          = 102;
    private const STATUS_INACTIVE    = 104;
    private const STATUS_NO_CONFIG   = 201;
    private const STATUS_UNREACHABLE = 202;

    private const DP_POWER      = '1';
    private const DP_MODE       = '2';
    private const DP_SETPOINT   = '16';
    private const DP_TEMP       = '24';
    private const DP_CORRECTION = '27';
    private const DP_CHILDLOCK  = '40';

    // Modus-Rohwert ↔ Profilwert
    private const MODES = ['comf' => 0, 'eco' => 1, 'auto' => 2, 'manual' => 3];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('LocalKey', '');
        $this->RegisterPropertyInteger('PollInterval', 30);

        $this->RegisterTimer('PollTimer', 0, 'TUYA_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();

        $this->RegisterVariableBoolean('Online', 'Online', $this->GetProfileName('Online'), 0);
        $this->RegisterVariableBoolean('Power', 'Heizung', '~Switch', 1);
        $this->RegisterVariableFloat('Setpoint', 'Solltemperatur', $this->GetProfileName('Setpoint'), 2);
        $this->RegisterVariableFloat('Temperature', 'Isttemperatur', '~Temperature', 3);
        $this->RegisterVariableInteger('Mode', 'Modus', $this->GetProfileName('Mode'), 4);
        $this->RegisterVariableInteger('Correction', 'Temperaturkorrektur', $this->GetProfileName('Correction'), 5);
        $this->RegisterVariableBoolean('ChildLock', 'Kindersicherung', '~Lock', 6);
        $this->RegisterVariableString('RawDps', 'Datenpunkte', '', 20);
        IPS_SetHidden($this->GetIDForIdent('RawDps'), true);

        foreach (['Power', 'Setpoint', 'Mode', 'Correction', 'ChildLock'] as $ident) {
            $this->EnableAction($ident);
        }

        if (!$this->IsConfigured()) {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetStatus($this->ReadPropertyString('Host') === '' ? self::STATUS_INACTIVE : self::STATUS_NO_CONFIG);
            return;
        }

        $interval = max(0, $this->ReadPropertyInteger('PollInterval'));
        $this->SetTimerInterval('PollTimer', $interval * 1000);
        $this->SetStatus(self::STATUS_OK);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Update();
        }
    }

    public function Destroy()
    {
        $prefix = 'TUYA.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $p) {
            if (strpos($p, $prefix) === 0) {
                @IPS_DeleteVariableProfile($p);
            }
        }
        parent::Destroy();
    }

    // ═══════════════════════════════════════════════════════════════
    //  PUBLIC API (Prefix-Funktionen)
    // ═══════════════════════════════════════════════════════════════

    /** Liest alle Datenpunkte vom Gerät. */
    public function Update(): bool
    {
        $dps = $this->Transact(function (TuyaProtocol $t) {
            return $t->query();
        });
        if ($dps === null) {
            return false;
        }
        $this->ApplyDps($dps, true);
        return true;
    }

    /** Setzt einen beliebigen Datenpunkt. $Value als JSON, z. B. 'true', '25', '"eco"'. */
    public function SetDP(int $DP, string $Value): bool
    {
        $decoded = json_decode($Value, true);
        if ($decoded === null && trim($Value) !== 'null') {
            $decoded = $Value;   // nackter String ohne Anführungszeichen
        }
        return $this->Control([(string)$DP => $decoded]);
    }

    public function SetPower(bool $Value): bool
    {
        return $this->Control([self::DP_POWER => $Value]);
    }

    public function SetSetpoint(float $Value): bool
    {
        return $this->Control([self::DP_SETPOINT => (int)round($Value)]);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
                $this->Control([self::DP_POWER => (bool)$Value]);
                break;
            case 'Setpoint':
                $this->Control([self::DP_SETPOINT => (int)round((float)$Value)]);
                break;
            case 'Mode':
                $raw = array_search((int)$Value, self::MODES, true);
                if ($raw !== false) {
                    $this->Control([self::DP_MODE => $raw]);
                }
                break;
            case 'Correction':
                $this->Control([self::DP_CORRECTION => (int)$Value]);
                break;
            case 'ChildLock':
                $this->Control([self::DP_CHILDLOCK => (bool)$Value]);
                break;
            default:
                throw new Exception('Unbekannter Ident ' . $Ident);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  INTERN
    // ═══════════════════════════════════════════════════════════════

    private function Control(array $dps): bool
    {
        $reported = $this->Transact(function (TuyaProtocol $t) use ($dps) {
            return $t->control($dps);
        });
        if ($reported === null) {
            return false;
        }
        // Meldet das Gerät nichts zurück, gilt der gesendete Wert
        $this->ApplyDps(array_replace($dps, $reported), false);
        return true;
    }

    /** Öffnet eine Sitzung, führt $fn aus, schließt wieder. null = Fehler. */
    private function Transact(callable $fn): ?array
    {
        if (!$this->IsConfigured()) {
            return null;
        }
        $lock = 'TUYA_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 5000)) {
            $this->SendDebug('Transact', 'Semaphore belegt', 0);
            return null;
        }
        $debug = function (string $label, string $text) {
            $this->SendDebug($label, $text, 0);
        };
        $t = null;
        try {
            $t = new TuyaProtocol($this->ReadPropertyString('Host'), $this->ReadPropertyString('LocalKey'), 3.0, $debug);
            $t->connect();
            $result = $fn($t);
            $this->SetDeviceStatus(self::STATUS_OK);
            return $result;
        } catch (Throwable $e) {
            $this->SendDebug('Fehler', $e->getMessage(), 0);
            $this->SetDeviceStatus(self::STATUS_UNREACHABLE);
            return null;
        } finally {
            if ($t !== null) {
                $t->close();
            }
            IPS_SemaphoreLeave($lock);
        }
    }

    private function ApplyDps(array $dps, bool $complete): void
    {
        if (array_key_exists(self::DP_POWER, $dps)) {
            $this->SetValueIfChanged('Power', (bool)$dps[self::DP_POWER]);
        }
        if (array_key_exists(self::DP_SETPOINT, $dps)) {
            $this->SetValueIfChanged('Setpoint', (float)$dps[self::DP_SETPOINT]);
        }
        if (array_key_exists(self::DP_TEMP, $dps)) {
            $this->SetValueIfChanged('Temperature', (float)$dps[self::DP_TEMP]);
        }
        if (array_key_exists(self::DP_MODE, $dps)) {
            $mode = (string)$dps[self::DP_MODE];
            if (!isset(self::MODES[$mode])) {
                $this->SendDebug('Modus', 'Unbekannter Modus "' . $mode . '"', 0);
            }
            $this->SetValueIfChanged('Mode', self::MODES[$mode] ?? -1);
        }
        if (array_key_exists(self::DP_CORRECTION, $dps)) {
            $this->SetValueIfChanged('Correction', (int)$dps[self::DP_CORRECTION]);
        }
        if (array_key_exists(self::DP_CHILDLOCK, $dps)) {
            $this->SetValueIfChanged('ChildLock', (bool)$dps[self::DP_CHILDLOCK]);
        }

        $raw = json_decode($this->GetValue('RawDps'), true);
        $raw = $complete || !is_array($raw) ? $dps : array_replace($raw, $dps);
        ksort($raw, SORT_NUMERIC);
        $this->SetValueIfChanged('RawDps', json_encode($raw));
    }

    private function IsConfigured(): bool
    {
        return $this->ReadPropertyString('Host') !== ''
            && strlen($this->ReadPropertyString('LocalKey')) === 16;
    }

    private function SetDeviceStatus(int $status): void
    {
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
        $this->SetValueIfChanged('Online', $status === self::STATUS_OK);
    }

    private function EnsureProfiles(): void
    {
        $p = $this->GetProfileName('Online');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($p, false, 'Offline', 'Close', 0xFF0000);
        IPS_SetVariableProfileAssociation($p, true, 'Online', 'Ok', 0x00CC00);
        IPS_SetVariableProfileIcon($p, 'Network');

        $p = $this->GetProfileName('Setpoint');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileValues($p, 7, 30, 1);
        IPS_SetVariableProfileDigits($p, 0);
        IPS_SetVariableProfileText($p, '', ' °C');
        IPS_SetVariableProfileIcon($p, 'Temperature');

        $p = $this->GetProfileName('Correction');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileValues($p, -5, 5, 1);
        IPS_SetVariableProfileText($p, '', ' K');
        IPS_SetVariableProfileIcon($p, 'Gauge');

        $p = $this->GetProfileName('Mode');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileValues($p, 0, 0, 0);
        IPS_SetVariableProfileAssociation($p, -1, 'Unbekannt', '', -1);
        IPS_SetVariableProfileAssociation($p, 0, 'Komfort', 'Sun', -1);
        IPS_SetVariableProfileAssociation($p, 1, 'Eco', 'Moon', -1);
        IPS_SetVariableProfileAssociation($p, 2, 'Auto', 'Clock', -1);
        IPS_SetVariableProfileAssociation($p, 3, 'Manuell', 'Hand', -1);
        IPS_SetVariableProfileIcon($p, 'Information');
    }

    private function GetProfileName(string $suffix): string
    {
        return 'TUYA.' . $this->InstanceID . '.' . $suffix;
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || $vid === 0) {
            return;
        }
        $old = GetValue($vid);
        if (is_float($old) || is_float($value)) {
            if (round((float)$old, 3) === round((float)$value, 3)) {
                return;
            }
        } elseif ($old === $value) {
            return;
        }
        $this->SetValue($ident, $value);
    }
}
