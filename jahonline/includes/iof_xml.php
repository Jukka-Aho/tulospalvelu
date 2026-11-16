<?php

/**
 * IOF XML 3.0 ResultList -vienti.
 *
 * @see https://orienteering.sport/iof/it/data-standard-3-0/
 */

require_once __DIR__ . '/helpers.php';

/** IOF Result/@Status arvot kilpailijan tilasta. */
function iofXmlStatus(string $status, bool $onAika = false): string
{
    $st = strtoupper(trim($status));
    return match ($st) {
        'DNS' => 'DidNotStart',
        'DNF' => 'DidNotFinish',
        'DSQ', 'DQ', 'DISQ' => 'Disqualified',
        'MP', 'MISSINGPUNCH' => 'MissingPunch',
        'OT', 'OVERTIME' => 'OverTime',
        'NC', 'NOTCOMPETING' => 'NotCompeting',
        'OK' => $onAika ? 'OK' : 'Active',
        default => $onAika ? 'OK' : 'Active',
    };
}

/** ResultList/@status kilpailun tilasta. */
function iofXmlRaceStatus(array $kilpailu): string
{
    $tila = (string) ($kilpailu['tila'] ?? '');
    return match ($tila) {
        'kaynnissa' => 'Live',
        'valmis' => 'Official',
        default => 'Unofficial',
    };
}

/** Muotoile datetime IOF xs:dateTime (Europe/Helsinki offset). */
function iofXmlDateTime(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    try {
        $tz = new DateTimeZone('Europe/Helsinki');
        $dt = new DateTimeImmutable($raw, $tz);
        return $dt->setTimezone($tz)->format('Y-m-d\TH:i:sP');
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array{Family:string,Given:string} */
function iofXmlNimiOsat(array $rivi): array
{
    $etu = trim((string) ($rivi['etunimi'] ?? ''));
    $suku = trim((string) ($rivi['sukunimi'] ?? ''));
    if ($etu !== '' || $suku !== '') {
        return ['Family' => $suku, 'Given' => $etu];
    }
    // Näyttönimi on yleensä "Sukunimi Etunimi"
    $nimi = trim(kilpailijaNimiRivista($rivi));
    if ($nimi === '' || $nimi === 'Nimetön') {
        return ['Family' => '', 'Given' => ''];
    }
    $osat = preg_split('/\s+/u', $nimi, 2) ?: [];
    if (count($osat) === 1) {
        return ['Family' => $osat[0], 'Given' => ''];
    }
    return [
        'Family' => (string) ($osat[0] ?? ''),
        'Given' => (string) ($osat[1] ?? ''),
    ];
}

const IOF_XML_NS = 'http://www.orienteering.org/datastandard/3.0';

function iofXmlAppendText(DOMDocument $doc, DOMElement $parent, string $name, string $text): DOMElement
{
    $el = $doc->createElementNS(IOF_XML_NS, $name);
    $el->appendChild($doc->createTextNode($text));
    $parent->appendChild($el);
    return $el;
}

/**
 * Matka kilometreinä → metrit (IOF Course length).
 */
function iofXmlMatkaMetreiksi(?string $matka): ?int
{
    $norm = function_exists('normalisoiSarjaMatka') ? normalisoiSarjaMatka($matka) : null;
    if ($norm === null || $norm === '') {
        return null;
    }
    $km = (float) str_replace(',', '.', (string) $norm);
    if ($km <= 0) {
        return null;
    }
    return (int) round($km * 1000);
}

/**
 * Rakenna IOF 3.0 ResultList -dokumentti.
 *
 * @param array<string,array> $ryhmat ryhmitteleLiveTulokset()-tulos
 */
function iofXmlRakennaResultList(?PDO $pdo, array $kilpailu, array $ryhmat): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $ns = IOF_XML_NS;
    $root = $doc->createElementNS($ns, 'ResultList');
    $root->setAttributeNS(
        'http://www.w3.org/2001/XMLSchema-instance',
        'xsi:schemaLocation',
        $ns . ' https://raw.githubusercontent.com/international-orienteering-federation/datastandard/master/IOF.xsd'
    );
    $root->setAttribute('iofVersion', '3.0');
    $root->setAttribute('createTime', (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('Y-m-d\TH:i:sP'));
    $root->setAttribute('creator', 'JAHOnline');
    $root->setAttribute('status', iofXmlRaceStatus($kilpailu));
    $doc->appendChild($root);

    $event = $doc->createElementNS($ns, 'Event');
    iofXmlAppendText($doc, $event, 'Name', (string) ($kilpailu['nimi'] ?? 'Kilpailu'));
    if (!empty($kilpailu['id'])) {
        $idEl = $doc->createElementNS($ns, 'Id');
        $idEl->setAttribute('type', 'JAHOnline');
        $idEl->appendChild($doc->createTextNode((string) (int) $kilpailu['id']));
        $event->appendChild($idEl);
    }
    $paiva = trim((string) ($kilpailu['paiva'] ?? ''));
    if ($paiva !== '') {
        $start = $doc->createElementNS($ns, 'StartTime');
        iofXmlAppendText($doc, $start, 'Date', $paiva);
        $event->appendChild($start);
    }
    $paikka = trim((string) ($kilpailu['paikka'] ?? ''));
    if ($paikka !== '') {
        iofXmlAppendText($doc, $event, 'Venue', $paikka);
    }

    // Järjestävä seura
    $seuraNimi = '';
    if ($pdo instanceof PDO && !empty($kilpailu['seura_id'])) {
        try {
            $st = $pdo->prepare('SELECT nimi FROM seurat WHERE id = ? LIMIT 1');
            $st->execute([(int) $kilpailu['seura_id']]);
            $seuraNimi = trim((string) $st->fetchColumn());
        } catch (Throwable $e) {
            $seuraNimi = '';
        }
    }
    if ($seuraNimi !== '') {
        $org = $doc->createElementNS($ns, 'Organiser');
        iofXmlAppendText($doc, $org, 'Name', $seuraNimi);
        $event->appendChild($org);
    }
    $root->appendChild($event);

    $onViesti = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
    $sarjaLahtoCache = [];

    foreach ($ryhmat as $sarjaNimi => $data) {
        $classResult = $doc->createElementNS($ns, 'ClassResult');
        $class = $doc->createElementNS($ns, 'Class');
        iofXmlAppendText($doc, $class, 'Name', (string) $sarjaNimi);
        if (!empty($data['sarja_id'])) {
            $cid = $doc->createElementNS($ns, 'Id');
            $cid->setAttribute('type', 'JAHOnline');
            $cid->appendChild($doc->createTextNode((string) (int) $data['sarja_id']));
            $class->appendChild($cid);
        }
        $classResult->appendChild($class);

        $metres = iofXmlMatkaMetreiksi($data['matka'] ?? null);
        if ($metres !== null) {
            $course = $doc->createElementNS($ns, 'Course');
            iofXmlAppendText($doc, $course, 'Length', (string) $metres);
            $classResult->appendChild($course);
        }

        $sarjaId = (int) ($data['sarja_id'] ?? 0);
        if ($pdo instanceof PDO && $sarjaId > 0 && !array_key_exists($sarjaId, $sarjaLahtoCache)) {
            $sarjaLahtoCache[$sarjaId] = haeSarjanLahtoaika($pdo, $sarjaId);
        }
        $sarjaLahto = $sarjaId > 0 ? ($sarjaLahtoCache[$sarjaId] ?? null) : null;

        $sija = 0;
        foreach ($data['maalissa'] ?? [] as $r) {
            $sija++;
            if ($onViesti && !empty($r['_viesti'])) {
                $classResult->appendChild(iofXmlTeamResult($doc, $ns, $r, $sija, $sarjaLahto, true));
            } else {
                $classResult->appendChild(iofXmlPersonResult($doc, $ns, $r, $sija, $sarjaLahto, true));
            }
        }
        foreach ($data['maastossa'] ?? [] as $r) {
            if ($onViesti && !empty($r['_viesti'])) {
                $classResult->appendChild(iofXmlTeamResult($doc, $ns, $r, null, $sarjaLahto, false));
            } else {
                $classResult->appendChild(iofXmlPersonResult($doc, $ns, $r, null, $sarjaLahto, false));
            }
        }
        foreach ($data['ei_maaleissa'] ?? [] as $r) {
            if ($onViesti && !empty($r['_viesti'])) {
                $classResult->appendChild(iofXmlTeamResult($doc, $ns, $r, null, $sarjaLahto, false));
            } else {
                $classResult->appendChild(iofXmlPersonResult($doc, $ns, $r, null, $sarjaLahto, false));
            }
        }

        $root->appendChild($classResult);
    }

    return $doc;
}

function iofXmlPersonResult(
    DOMDocument $doc,
    string $ns,
    array $r,
    ?int $position,
    ?string $sarjaLahto,
    bool $finished
): DOMElement {
    $pr = $doc->createElementNS($ns, 'PersonResult');

    $person = $doc->createElementNS($ns, 'Person');
    $name = $doc->createElementNS($ns, 'Name');
    $osat = iofXmlNimiOsat($r);
    iofXmlAppendText($doc, $name, 'Family', $osat['Family']);
    iofXmlAppendText($doc, $name, 'Given', $osat['Given']);
    $person->appendChild($name);
    $pr->appendChild($person);

    $seura = trim((string) ($r['seura'] ?? ''));
    if ($seura !== '') {
        $org = $doc->createElementNS($ns, 'Organisation');
        iofXmlAppendText($doc, $org, 'Name', $seura);
        $pr->appendChild($org);
    }

    $result = $doc->createElementNS($ns, 'Result');
    $bib = trim((string) ($r['numero'] ?? ''));
    if ($bib !== '') {
        iofXmlAppendText($doc, $result, 'BibNumber', $bib);
    }

    $lahtoRaw = trim((string) ($r['pirila_lahto_at'] ?? ''));
    if ($lahtoRaw === '' && $sarjaLahto) {
        $lahtoRaw = $sarjaLahto;
    }
    $startIso = iofXmlDateTime($lahtoRaw);
    if ($startIso !== null) {
        iofXmlAppendText($doc, $result, 'StartTime', $startIso);
    }

    $aikaSec = $r['maali_aika_sec'] ?? null;
    $onAika = $aikaSec !== null && $aikaSec !== '';
    if ($onAika) {
        iofXmlAppendText($doc, $result, 'Time', (string) (int) $aikaSec);
        if (!empty($r['maaliaika'])) {
            $finishIso = iofXmlDateTime((string) $r['maaliaika']);
            if ($finishIso !== null) {
                iofXmlAppendText($doc, $result, 'FinishTime', $finishIso);
            }
        }
    }

    if ($position !== null && $position > 0 && $finished) {
        iofXmlAppendText($doc, $result, 'Position', (string) $position);
    }

    $status = iofXmlStatus((string) ($r['status'] ?? 'OK'), $onAika && $finished);
    iofXmlAppendText($doc, $result, 'Status', $status);

    $pr->appendChild($result);
    return $pr;
}

/**
 * Viestijoukkue → TeamResult (+ TeamMemberResult osuuksille jos saatavilla).
 */
function iofXmlTeamResult(
    DOMDocument $doc,
    string $ns,
    array $r,
    ?int $position,
    ?string $sarjaLahto,
    bool $finished
): DOMElement {
    $tr = $doc->createElementNS($ns, 'TeamResult');

    $joukkueNimi = trim((string) ($r['nimi'] ?? $r['joukkue'] ?? ''));
    if ($joukkueNimi !== '') {
        iofXmlAppendText($doc, $tr, 'Name', $joukkueNimi);
    }

    $seura = trim((string) ($r['seura'] ?? ''));
    if ($seura !== '') {
        $org = $doc->createElementNS($ns, 'Organisation');
        iofXmlAppendText($doc, $org, 'Name', $seura);
        $tr->appendChild($org);
    }

    $bib = trim((string) ($r['numero'] ?? ''));
    if ($bib !== '') {
        iofXmlAppendText($doc, $tr, 'BibNumber', $bib);
    }

    $result = $doc->createElementNS($ns, 'Result');
    $aikaSec = $r['maali_aika_sec'] ?? null;
    $onAika = $aikaSec !== null && $aikaSec !== '';
    if ($onAika) {
        iofXmlAppendText($doc, $result, 'Time', (string) (int) $aikaSec);
    }
    if ($position !== null && $position > 0 && $finished) {
        iofXmlAppendText($doc, $result, 'Position', (string) $position);
    }
    $status = iofXmlStatus((string) ($r['status'] ?? 'OK'), $onAika && $finished);
    iofXmlAppendText($doc, $result, 'Status', $status);
    $tr->appendChild($result);

    foreach ($r['osuudet'] ?? [] as $leg) {
        $tmr = $doc->createElementNS($ns, 'TeamMemberResult');
        $person = $doc->createElementNS($ns, 'Person');
        $name = $doc->createElementNS($ns, 'Name');
        $osat = iofXmlNimiOsat([
            'etunimi' => $leg['etunimi'] ?? null,
            'sukunimi' => $leg['sukunimi'] ?? null,
            'nimi' => $leg['nimi'] ?? '',
        ]);
        iofXmlAppendText($doc, $name, 'Family', $osat['Family']);
        iofXmlAppendText($doc, $name, 'Given', $osat['Given']);
        $person->appendChild($name);
        $tmr->appendChild($person);

        $legResult = $doc->createElementNS($ns, 'Result');
        $legNo = (int) ($leg['osuus'] ?? 0);
        if ($legNo > 0) {
            iofXmlAppendText($doc, $legResult, 'Leg', (string) $legNo);
        }
        $legSec = $leg['maali_aika_sec'] ?? null;
        $legOnAika = $legSec !== null && $legSec !== '';
        if ($legOnAika) {
            iofXmlAppendText($doc, $legResult, 'Time', (string) (int) $legSec);
        }
        if (isset($leg['sija']) && (int) $leg['sija'] > 0) {
            iofXmlAppendText($doc, $legResult, 'Position', (string) (int) $leg['sija']);
        }
        $legSt = iofXmlStatus((string) ($leg['status'] ?? 'OK'), $legOnAika);
        iofXmlAppendText($doc, $legResult, 'Status', $legSt);
        $tmr->appendChild($legResult);
        $tr->appendChild($tmr);
    }

    return $tr;
}

/**
 * Palauta ResultList XML merkkijonona.
 */
function iofXmlResultListString(?PDO $pdo, array $kilpailu, ?array $ryhmat = null): string
{
    $kilpailu_id = (int) ($kilpailu['id'] ?? 0);
    if ($ryhmat === null) {
        if (!$pdo instanceof PDO) {
            throw new InvalidArgumentException('PDO required when ryhmat omitted');
        }
        $onViesti = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
        $live = $onViesti
            ? haeLiveViestiTulokset($pdo, $kilpailu_id)
            : haeLiveTulokset($pdo, $kilpailu_id);
        $ryhmat = ryhmitteleLiveTulokset($live);
    }
    $doc = iofXmlRakennaResultList($pdo, $kilpailu, $ryhmat);
    return $doc->saveXML() ?: '';
}

/** Turvallinen tiedostonimi. */
function iofXmlTiedostonimi(array $kilpailu): string
{
    $nimi = trim((string) ($kilpailu['nimi'] ?? 'tulokset'));
    $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $nimi) ?: 'tulokset';
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'tulokset';
    }
    $id = (int) ($kilpailu['id'] ?? 0);
    return 'ResultList_' . $slug . ($id > 0 ? '_' . $id : '') . '_IOF30.xml';
}

/** Lähetä ResultList latauksena. */
function iofXmlLahetaLataus(PDO $pdo, array $kilpailu, ?array $ryhmat = null): void
{
    $xml = iofXmlResultListString($pdo, $kilpailu, $ryhmat);
    $filename = iofXmlTiedostonimi($kilpailu);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $xml;
    exit;
}
