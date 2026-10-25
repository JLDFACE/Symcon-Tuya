<?php
// Handtuchheizung Bad – läuft nur nach dem Duschen.
//
// Start:  Dusch-Szene geht an und außen < AUSSEN_MAX. Morgens (VON–BIS) heizt
//         sie sofort vor, sonst startet sie erst bei „Wasser aus" zum Trocknen.
// Ende:   „Wasser aus" wird erkannt, dann TROCKNEN Minuten Trockenzeit. Die
//         letzten NACHLAUF Minuten übernimmt die Restwärme, die Heizung geht
//         also schon TROCKNEN − NACHLAUF Minuten nach „Wasser aus" aus.
// Wasser aus, erstes Signal gewinnt:
//   1. Warmwasserspeicher ist um ≥ WW_DROP gefallen und fällt seit WW_RUHE
//      Minuten nicht mehr (nur wenn die Wärmepumpe gerade nicht nachlädt).
//      Klar zu sehen bei langen Duschen, werktags morgens oft kaum.
//   2. Abluftfeuchte (Vallox) ist um ≥ HUM_RISE gestiegen und fällt wieder um
//      ≥ HUM_FALL – der Höchstwert liegt etwa beim Wasser-aus.
//   3. Dusch-Szene geht aus, oder DUSCHEN_MAX Minuten sind um.
// Streng: Wird die Heizung außerhalb eines Laufs eingeschaltet (App, Gerät),
// schaltet dieses Skript sie sofort wieder aus.

const HEIZUNG     = 34356;   // Tuya Thermostat „Handtuchheizung Bad"
const POWER       = 26400;   //   …\Heizung
const SZENE       = 20909;   // KNX\Szenen\OG\Bad Duschen
const AUSSEN      = 41714;   // KNX\Heizung\Aussentemp.
const WW_IST      = 52802;   // Wärmepumpe\Warmwasser-Ist
const VENTIL      = 11484;   // Wärmepumpe\3-Wegeventil (1 = Speicher lädt)
const FEUCHTE     = 53162;   // Vallox\Luftfeuchte (Abluft)

const VON         = '05:00';
const BIS         = '11:30';
const AUSSEN_MAX  = 22.0;    // °C
const SOLL        = 30;      // °C Solltemperatur während des Laufs (Gerätemaximum)
const TROCKNEN    = 60;      // min Trockenzeit ab „Wasser aus"
const NACHLAUF    = 20;      // min Restwärme nach dem Ausschalten
const DUSCHEN_MAX = 45;      // min ohne Erkennung → gilt als Wasser aus
const LAUF_MAX    = 120;     // min Sicherheitsgrenze für den ganzen Lauf
const WW_DROP     = 0.5;     // K
const WW_RUHE     = 4;       // min
const HUM_RISE    = 3.0;     // %-Punkte
const HUM_FALL    = 1.0;     // %-Punkte
const TAKT        = 60;      // s Prüfintervall während eines Laufs

const PHASE_AUS = 0, PHASE_DUSCHEN = 1, PHASE_TROCKNEN = 2;
const NICHT_ERREICHT = ' – Heizung reagiert nicht, neuer Versuch im nächsten Takt';

$self      = $_IPS['SELF'];
$vAktiv    = IPS_GetObjectIDByIdent('Aktiv', $self);
$vPhase    = IPS_GetObjectIDByIdent('Phase', $self);
$vAusUm    = IPS_GetObjectIDByIdent('AusUm', $self);
$vStatus   = IPS_GetObjectIDByIdent('Status', $self);
$vZustand  = IPS_GetObjectIDByIdent('Zustand', $self);

$sender = $_IPS['SENDER'];
$now = time();
$z = json_decode(GetValueString($vZustand), true) ?: [];
$phase = GetValueInteger($vPhase);

// Schalter „Automatik" aus der Visu
if (($sender == 'WebFront' || $sender == 'Action') && ($_IPS['VARIABLE'] ?? 0) == $vAktiv) {
    SetValueBoolean($vAktiv, (bool)$_IPS['VALUE']);
    if (!$_IPS['VALUE'] && $phase != PHASE_AUS) {
        beenden('Automatik ausgeschaltet');
    }
    return;
}

// Strenge Regel: Heizung läuft nur in einem Lauf
if ($sender == 'Variable' && $_IPS['VARIABLE'] == POWER) {
    if ($_IPS['VALUE'] && $phase == PHASE_AUS && GetValueBoolean($vAktiv)) {
        TUYA_SetPower(HEIZUNG, false);
        status('Manuell eingeschaltet – sofort wieder aus (' . date('H:i') . ')');
    } elseif (!$_IPS['VALUE'] && $phase != PHASE_AUS) {
        beenden('Von Hand ausgeschaltet');   // jemand will sie aus haben: respektieren
    }
    return;
}

// Dusch-Szene
if ($sender == 'Variable' && $_IPS['VARIABLE'] == SZENE) {
    if ($_IPS['VALUE']) {
        if (!GetValueBoolean($vAktiv)) {
            return;
        }
        $aussen = GetValueFloat(AUSSEN);
        $uhr = date('H:i');
        if ($aussen >= AUSSEN_MAX) {
            status(sprintf('Duschen um %s – außen %.1f °C, Heizung bleibt aus', $uhr, $aussen));
            return;
        }
        starten($uhr >= VON && $uhr < BIS);
    } elseif ($phase == PHASE_DUSCHEN && empty($z['wasserAus'])) {
        wasserAus($now, 'Szene beendet');
    }
    return;
}

// Takt
if ($sender == 'TimerEvent') {
    if ($phase == PHASE_AUS) {
        IPS_SetScriptTimer($self, 0);
        return;
    }
    if ($now - $z['start'] >= LAUF_MAX * 60) {
        beenden('Sicherheitsgrenze ' . LAUF_MAX . ' min erreicht');
        return;
    }
    if ($phase == PHASE_DUSCHEN) {
        erkennen($now);
    } elseif ($now >= GetValueInteger($vAusUm)) {
        beenden('Trocknen fertig, Restwärme läuft nach');
        return;
    }
    // Hätte die Heizung an sein müssen und ist es nicht (Befehl verloren, Gerät
    // kurz weg), wird nachgeschaltet. Von Hand aus beendet den Lauf über POWER.
    $sollAn = GetValueInteger($vPhase) == PHASE_TROCKNEN || !empty($z['vorheizen']);
    if ($sollAn && !GetValueBoolean(POWER)) {
        status(einschalten() ? 'Heizung nachgeschaltet (' . date('H:i') . ')' : 'Heizung reagiert weiterhin nicht (' . date('H:i') . ')');
    }
}

// ─────────────────────────────────────────────────────────────

function starten(bool $vorheizen): void
{
    global $self, $vPhase, $vAusUm, $now, $z;
    $ww = GetValueFloat(WW_IST);
    $hum = GetValueFloat(FEUCHTE);
    $z = [
        'start' => $now, 'wasserAus' => 0, 'vorheizen' => $vorheizen,
        'wwMin' => $ww, 'wwStart' => $ww, 'wwMinSeit' => $now,
        'humStart' => $hum, 'humMax' => $hum, 'humMaxSeit' => $now,
    ];
    speichern($z);
    SetValueInteger($vPhase, PHASE_DUSCHEN);   // vor dem Einschalten, sonst greift die strenge Regel
    SetValueInteger($vAusUm, 0);
    IPS_SetScriptTimer($self, TAKT);
    if ($vorheizen) {
        $ok = einschalten();
        status(sprintf('Vorheizen seit %s (außen %.1f °C)', date('H:i'), GetValueFloat(AUSSEN)) . ($ok ? '' : NICHT_ERREICHT));
    } else {
        status(sprintf('Duschen seit %s – Heizung startet bei „Wasser aus"', date('H:i')));
    }
}

function einschalten(): bool
{
    // das Gerät stellt beim Ausschalten selbst auf 7 °C, daher Soll immer mitsetzen
    return TUYA_SetPower(HEIZUNG, true) && TUYA_SetSetpoint(HEIZUNG, SOLL);
}

function erkennen(int $now): void
{
    global $z;
    $ww = GetValueFloat(WW_IST);
    $hum = GetValueFloat(FEUCHTE);
    $laedt = GetValueInteger(VENTIL) == 1;

    if ($ww < $z['wwMin'] - 0.05) {
        $z['wwMin'] = $ww;
        $z['wwMinSeit'] = $now;
    }
    if ($hum > $z['humMax'] + 0.05) {
        $z['humMax'] = $hum;
        $z['humMaxSeit'] = $now;
    }
    speichern($z);

    if (!$laedt && $z['wwStart'] - $z['wwMin'] >= WW_DROP && $now - $z['wwMinSeit'] >= WW_RUHE * 60) {
        wasserAus($z['wwMinSeit'], sprintf('Warmwasser %.1f → %.1f °C', $z['wwStart'], $z['wwMin']));
    } elseif ($z['humMax'] - $z['humStart'] >= HUM_RISE && $z['humMax'] - $hum >= HUM_FALL) {
        wasserAus($z['humMaxSeit'], sprintf('Feuchte-Spitze %.0f %%', $z['humMax']));
    } elseif ($now - $z['start'] >= DUSCHEN_MAX * 60) {
        wasserAus($now, DUSCHEN_MAX . ' min ohne Erkennung');
    }
}

function wasserAus(int $ts, string $grund): void
{
    global $z, $vPhase, $vAusUm;
    $z['wasserAus'] = $ts;
    speichern($z);
    $ausUm = $ts + (TROCKNEN - NACHLAUF) * 60;
    SetValueInteger($vPhase, PHASE_TROCKNEN);
    SetValueInteger($vAusUm, $ausUm);
    $ok = empty($z['vorheizen']) ? einschalten() : true;
    status(sprintf('Wasser aus %s (%s) – trocknet, Heizung aus um %s', date('H:i', $ts), $grund, date('H:i', $ausUm)) . ($ok ? '' : NICHT_ERREICHT));
}

function beenden(string $grund): void
{
    global $self, $vPhase, $vAusUm;
    SetValueInteger($vPhase, PHASE_AUS);       // vor dem Ausschalten
    SetValueInteger($vAusUm, 0);
    IPS_SetScriptTimer($self, 0);
    if (GetValueBoolean(POWER)) {
        TUYA_SetPower(HEIZUNG, false);
    }
    status($grund . ' (' . date('H:i') . ')');
}

function speichern(array $z): void
{
    global $vZustand;
    SetValueString($vZustand, json_encode($z));
}

function status(string $text): void
{
    global $vStatus;
    SetValueString($vStatus, $text);
    IPS_LogMessage('Handtuchheizung', $text);
}
