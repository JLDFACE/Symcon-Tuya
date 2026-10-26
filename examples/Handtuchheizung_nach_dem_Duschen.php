<?php
// Handtuchheizung Bad – läuft nur rund ums Duschen.
//
// Start:  Dusch-Szene geht an und außen < AUSSEN_MAX → Heizung sofort an,
//         zu jeder Tageszeit.
// Ende:   „Wasser aus" wird erkannt, dann TROCKNEN Minuten Trockenzeit. Die
//         letzten NACHLAUF Minuten übernimmt die Restwärme, die Heizung geht
//         also schon TROCKNEN − NACHLAUF Minuten nach „Wasser aus" aus.
// Wasser aus, erstes Signal gewinnt:
//   1. Warmwasserspeicher ist um ≥ WW_DROP gefallen und fällt seit WW_RUHE
//      Minuten nicht mehr (nur wenn die Wärmepumpe gerade nicht nachlädt).
//      Klar zu sehen bei langen Duschen, werktags morgens oft kaum.
//   2. Abluftfeuchte (Vallox) ist um ≥ HUM_RISE gestiegen und fällt wieder um
//      ≥ HUM_FALL – der Höchstwert liegt etwa beim Wasser-aus.
//   3. Dusch-Szene geht aus, oder DUSCHEN_MAX Minuten sind um – aber nur, wenn
//      Feuchte oder Speicher überhaupt reagiert haben. Sonst wurde nicht
//      geduscht (Szene nur kurz angetippt), die Heizung geht sofort aus.
// Steigt die Feuchte beim Trocknen über die bisherige Spitze (zweite Person,
// Pause unter der Dusche), gilt wieder „Duschen" und das Ende rückt nach hinten.
// Baden: Ein Bad zeigt sich in Feuchte und Speicher kaum (Wanne einmal voll,
// dann lange nichts). Schalter „Baden" startet die Heizung sofort für BADEN
// Minuten; steigt beim Aussteigen die Feuchte, verlängert sich der Lauf.
// Die Dusch-Szene greift während eines Bads nicht ein.
// Streng: Wird die Heizung außerhalb eines Laufs eingeschaltet (App, Gerät),
// schaltet dieses Skript sie sofort wieder aus.

const HEIZUNG     = 34356;   // Tuya Thermostat „Handtuchheizung Bad"
const POWER       = 26400;   //   …\Heizung
const SZENE       = 20909;   // KNX\Szenen\OG\Bad Duschen
const AUSSEN      = 41714;   // KNX\Heizung\Aussentemp.
const WW_IST      = 52802;   // Wärmepumpe\Warmwasser-Ist
const VENTIL      = 11484;   // Wärmepumpe\3-Wegeventil (1 = Speicher lädt)
const FEUCHTE     = 53162;   // Vallox\Luftfeuchte (Abluft)

const AUSSEN_MAX  = 22.0;    // °C
const SOLL        = 30;      // °C Solltemperatur während des Laufs (Gerätemaximum)
const TROCKNEN    = 60;      // min Trockenzeit ab „Wasser aus"
const NACHLAUF    = 20;      // min Restwärme nach dem Ausschalten
const DUSCHEN_MAX = 45;      // min ohne Erkennung → gilt als Wasser aus
const BADEN       = 90;      // min Laufzeit nach Druck auf „Baden"
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
$vBaden    = IPS_GetObjectIDByIdent('Baden', $self);

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

// Schalter „Baden": ein Bad zeigt sich weder in Feuchte noch Speicher
// verlässlich, deshalb startet man den Lauf von Hand
if (($sender == 'WebFront' || $sender == 'Action') && ($_IPS['VARIABLE'] ?? 0) == $vBaden) {
    if ($_IPS['VALUE']) {
        baden();
    } elseif ($phase != PHASE_AUS) {
        beenden('Baden beendet');
    } else {
        SetValueBoolean($vBaden, false);
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
    if (GetValueBoolean($vBaden)) {
        return;   // ein laufendes Bad lässt sich von der Szene nicht stören
    }
    if ($_IPS['VALUE']) {
        if (!GetValueBoolean($vAktiv)) {
            return;
        }
        $aussen = GetValueFloat(AUSSEN);
        if ($aussen >= AUSSEN_MAX) {
            status(sprintf('Duschen um %s – außen %.1f °C, Heizung bleibt aus', date('H:i'), $aussen));
            return;
        }
        starten();
    } elseif ($phase == PHASE_DUSCHEN && empty($z['wasserAus'])) {
        erkennen($now);   // letzter Stand der Signale, kann selbst „Wasser aus" melden
        if (GetValueInteger($vPhase) == PHASE_DUSCHEN) {
            if (geduscht()) {
                wasserAus($now, 'Szene beendet');
            } else {
                beenden('Kein Duschen erkannt (Feuchte und Speicher unverändert)');
            }
        }
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
        if (GetValueInteger($vPhase) == PHASE_AUS) {
            return;
        }
    } elseif (!weiterGeduscht($now) && $now >= GetValueInteger($vAusUm)) {
        beenden('Trocknen fertig, Restwärme läuft nach');
        return;
    }
    // Während eines Laufs muss die Heizung an sein. Ist sie es nicht (Befehl
    // verloren, Gerät kurz weg), wird nachgeschaltet. Von Hand aus beendet den
    // Lauf über POWER.
    if (!GetValueBoolean(POWER)) {
        status(einschalten() ? 'Heizung nachgeschaltet (' . date('H:i') . ')' : 'Heizung reagiert weiterhin nicht (' . date('H:i') . ')');
    }
}

// ─────────────────────────────────────────────────────────────

function starten(): void
{
    global $self, $vPhase, $vAusUm, $now, $z;
    $ww = GetValueFloat(WW_IST);
    $hum = GetValueFloat(FEUCHTE);
    $z = [
        'start' => $now, 'wasserAus' => 0,
        'wwMin' => $ww, 'wwStart' => $ww, 'wwMinSeit' => $now,
        'humStart' => $hum, 'humMax' => $hum, 'humMaxSeit' => $now,
    ];
    speichern($z);
    SetValueInteger($vPhase, PHASE_DUSCHEN);   // vor dem Einschalten, sonst greift die strenge Regel
    SetValueInteger($vAusUm, 0);
    IPS_SetScriptTimer($self, TAKT);
    $ok = einschalten();
    status(sprintf('Duschen seit %s – Heizung an (außen %.1f °C)', date('H:i'), GetValueFloat(AUSSEN)) . ($ok ? '' : NICHT_ERREICHT));
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
        if (geduscht()) {
            wasserAus($now, DUSCHEN_MAX . ' min ohne Erkennung');
        } else {
            beenden('Kein Duschen erkannt nach ' . DUSCHEN_MAX . ' min');
        }
    }
}

/** Gab es überhaupt ein Dusch-Signal? Kurzes Antippen der Szene hinterlässt keins. */
function geduscht(): bool
{
    global $z;
    return $z['humMax'] - $z['humStart'] >= HUM_RISE || $z['wwStart'] - $z['wwMin'] >= WW_DROP;
}

/**
 * Steigt die Feuchte beim Trocknen über die bisherige Spitze, wird weiter
 * geduscht (zweite Spitze, zweite Person): zurück auf Duschen, „Wasser aus"
 * kommt dann mit der neuen Spitze und schiebt das Ende nach hinten.
 */
function weiterGeduscht(int $now): bool
{
    global $z, $vPhase;
    $hum = GetValueFloat(FEUCHTE);
    if ($hum < $z['humMax'] + 1) {
        return false;
    }
    $z['humMax'] = $hum;
    $z['humMaxSeit'] = $now;
    speichern($z);
    SetValueInteger($vPhase, PHASE_DUSCHEN);
    status(sprintf('Feuchte steigt wieder auf %.0f %% – es wird weiter geduscht (%s)', $hum, date('H:i')));
    return true;
}

function wasserAus(int $ts, string $grund): void
{
    global $z, $vPhase, $vAusUm;
    $z['wasserAus'] = $ts;
    speichern($z);
    // ein späteres „Wasser aus" verlängert, verkürzt aber nie (wichtig beim Baden)
    $ausUm = max($ts + (TROCKNEN - NACHLAUF) * 60, GetValueInteger($vAusUm));
    SetValueInteger($vPhase, PHASE_TROCKNEN);
    SetValueInteger($vAusUm, $ausUm);
    status(sprintf('Wasser aus %s (%s) – trocknet, Heizung aus um %s', date('H:i', $ts), $grund, date('H:i', $ausUm)));
}

function baden(): void
{
    global $self, $vPhase, $vAusUm, $vBaden, $now, $z;
    $ww = GetValueFloat(WW_IST);
    $hum = GetValueFloat(FEUCHTE);
    // wie ein Lauf, dessen „Wasser aus" jetzt ist – Feuchte beim Aussteigen verlängert
    $z = [
        'start' => $now, 'wasserAus' => $now, 'baden' => true,
        'wwMin' => $ww, 'wwStart' => $ww, 'wwMinSeit' => $now,
        'humStart' => $hum, 'humMax' => $hum, 'humMaxSeit' => $now,
    ];
    speichern($z);
    SetValueBoolean($vBaden, true);
    SetValueInteger($vPhase, PHASE_TROCKNEN);   // vor dem Einschalten, sonst greift die strenge Regel
    SetValueInteger($vAusUm, $now + BADEN * 60);
    IPS_SetScriptTimer($self, TAKT);
    $ok = einschalten();
    status(sprintf('Baden seit %s – Heizung aus um %s', date('H:i'), date('H:i', $now + BADEN * 60)) . ($ok ? '' : NICHT_ERREICHT));
}

function beenden(string $grund): void
{
    global $self, $vPhase, $vAusUm, $vBaden;
    SetValueInteger($vPhase, PHASE_AUS);       // vor dem Ausschalten
    SetValueInteger($vAusUm, 0);
    SetValueBoolean($vBaden, false);
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
