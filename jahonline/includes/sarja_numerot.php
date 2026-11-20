<?php

/**
 * Juoksukisan sarjakohtaiset kilpailunumerovälit ja paikan päällä -ilmo.
 */

function sarjaNumerotVarmista(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $ok = true;
    $lisaa = static function (PDO $pdo, string $table, string $col, string $ddl): void {
        if (function_exists('tiliVarmistaSarake')) {
            tiliVarmistaSarake($pdo, $table, $col, $ddl);
            return;
        }
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($col));
            if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                return;
            }
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` ' . $ddl);
        } catch (Throwable $e) {
        }
    };
    $lisaa($pdo, 'sarjat', 'numero_valit', 'VARCHAR(200) NULL');
    $lisaa($pdo, 'kilpailut', 'paikanpaa_ilmo', 'TINYINT(1) NOT NULL DEFAULT 0');
    $lisaa($pdo, 'kilpailut', 'paikanpaa_maksu_eur', 'DECIMAL(8,2) NULL');
    $lisaa($pdo, 'kilpailut', 'paikanpaa_token', 'VARCHAR(64) NULL');
    $lisaa($pdo, 'kilpailijat', 'ilmo_lahde', 'VARCHAR(20) NULL');
    try {
        $st = $pdo->query("
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'kilpailut'
              AND INDEX_NAME = 'uq_kilpailut_paikanpaa_token'
            LIMIT 1
        ");
        if ($st && !$st->fetchColumn()) {
            $pdo->exec('CREATE UNIQUE INDEX uq_kilpailut_paikanpaa_token ON kilpailut (paikanpaa_token)');
        }
    } catch (Throwable $e) {
    }
}

/**
 * @return list<array{0:int,1:int}>
 */
function sarjaParsiNumeroValit(string $raw): array
{
    $raw = trim(str_replace(["\n", ';'], ',', $raw));
    $raw = str_replace(['–', '—'], '-', $raw);
    if ($raw === '') {
        return [];
    }
    $ulos = [];
    $naky = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $osa) {
        $osa = trim($osa);
        if ($osa === '') {
            continue;
        }
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $osa, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
        } elseif (preg_match('/^(\d+)$/', $osa, $m)) {
            $a = (int) $m[1];
            $b = $a;
        } else {
            continue;
        }
        if ($a < 1) {
            $a = 1;
        }
        if ($b < $a) {
            $tmp = $a;
            $a = $b;
            $b = $tmp;
        }
        $max = function_exists('pirilaMaxKilpailunumero') ? pirilaMaxKilpailunumero() : 9979;
        if ($b > $max) {
            $b = $max;
        }
        if ($a > $max) {
            continue;
        }
        $avain = $a . '-' . $b;
        if (isset($naky[$avain])) {
            continue;
        }
        $naky[$avain] = true;
        $ulos[] = [$a, $b];
    }

    return $ulos;
}

/** @param list<array{0:int,1:int}> $valit */
function sarjaMuotoileNumeroValit(array $valit): string
{
    $osat = [];
    foreach ($valit as $v) {
        $a = (int) ($v[0] ?? 0);
        $b = (int) ($v[1] ?? 0);
        if ($a < 1) {
            continue;
        }
        $osat[] = $a === $b ? (string) $a : ($a . '–' . $b);
    }

    return implode(', ', $osat);
}

function sarjaNumeroValitTeksti($raw): string
{
    return sarjaMuotoileNumeroValit(sarjaParsiNumeroValit((string) ($raw ?? '')));
}

/**
 * @return list<array{0:int,1:int}>
 */
function sarjaHaeNumeroValit(PDO $pdo, int $sarjaId): array
{
    if ($sarjaId < 1) {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT numero_valit FROM sarjat WHERE id = ?');
        $st->execute([$sarjaId]);
        $raw = $st->fetchColumn();
    } catch (Throwable $e) {
        return [];
    }

    return sarjaParsiNumeroValit((string) ($raw ?? ''));
}

/** Onko numero jossain sarjan väleissä. */
function sarjaNumeroKuuluuValeihin(array $valit, int $numero): bool
{
    if ($numero < 1) {
        return false;
    }
    foreach ($valit as $v) {
        if ($numero >= (int) $v[0] && $numero <= (int) $v[1]) {
            return true;
        }
    }

    return false;
}

/**
 * Seuraava vapaa numero väleissä. Aukot (käyttämättömät) ohitetaan.
 *
 * @param list<array{0:int,1:int}> $valit
 * @param array<int,bool> $kaytossa
 */
function sarjaEtsiVapaaNumero(array $valit, array $kaytossa): int
{
    $lkm = 0;
    foreach ($valit as $v) {
        $a = (int) $v[0];
        $b = (int) $v[1];
        for ($n = $a; $n <= $b; $n++) {
            $lkm++;
            if ($lkm > 20000) {
                return 0;
            }
            if (!isset($kaytossa[$n])) {
                return $n;
            }
        }
    }

    return 0;
}

/**
 * Seuraava vapaa numero sarjan väleissä. Välit saavat olla aukkoisia.
 * Varatut numerot katsotaan koko kisasta (numero on yksilöllinen).
 */
function seuraavaSarjanNumero(PDO $pdo, int $kilpailuId, int $sarjaId): int
{
    if ($kilpailuId < 1 || $sarjaId < 1) {
        return 0;
    }
    $valit = sarjaHaeNumeroValit($pdo, $sarjaId);
    if ($valit === []) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT numero FROM kilpailijat WHERE kilpailu_id = ? AND numero > 0');
    $stmt->execute([$kilpailuId]);
    $kaytossa = [];
    while (($n = $stmt->fetchColumn()) !== false) {
        $kaytossa[(int) $n] = true;
    }

    return sarjaEtsiVapaaNumero($valit, $kaytossa);
}

/**
 * @return array{ok:bool,numero:int,message:string}
 */
function sarjaAnnaNumeroKilpailijalle(PDO $pdo, int $kilpailuId, int $kilpailijaId, ?int $pakotettu = null): array
{
    if ($kilpailuId < 1 || $kilpailijaId < 1) {
        return ['ok' => false, 'numero' => 0, 'message' => 'Kilpailija puuttuu.'];
    }
    $lukko = 'jah-nro-' . $kilpailuId;
    $sain = true;
    try {
        $lk = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lk->execute([$lukko]);
        $sain = (int) $lk->fetchColumn() === 1;
    } catch (Throwable $e) {
        $sain = true;
    }
    if (!$sain) {
        return ['ok' => false, 'numero' => 0, 'message' => 'Numeronanto on jo käynnissä. Yritä uudelleen.'];
    }
    try {
        $st = $pdo->prepare('SELECT id, sarja_id, numero FROM kilpailijat WHERE id = ? AND kilpailu_id = ?');
        $st->execute([$kilpailijaId, $kilpailuId]);
        $rivi = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rivi) {
            return ['ok' => false, 'numero' => 0, 'message' => 'Kilpailijaa ei löydy.'];
        }
        $nyt = (int) ($rivi['numero'] ?? 0);
        if ($nyt > 0 && ($pakotettu === null || $pakotettu === $nyt)) {
            return ['ok' => true, 'numero' => $nyt, 'message' => 'Numero on jo ' . $nyt . '.'];
        }
        $numero = $pakotettu !== null && $pakotettu > 0
            ? $pakotettu
            : seuraavaSarjanNumero($pdo, $kilpailuId, (int) $rivi['sarja_id']);
        if ($numero < 1) {
            return ['ok' => false, 'numero' => 0, 'message' => 'Sarjalla ei ole vapaata numeroa. Tarkista numerovälit.'];
        }
        if (function_exists('kilpailijaNumeroVarattu') && kilpailijaNumeroVarattu($pdo, $kilpailuId, $numero, $kilpailijaId)) {
            return ['ok' => false, 'numero' => 0, 'message' => 'Numero ' . $numero . ' on jo käytössä.'];
        }
        $pdo->prepare('UPDATE kilpailijat SET numero = ?, keskhyl = COALESCE(NULLIF(keskhyl, \'\'), \'N\') WHERE id = ? AND kilpailu_id = ?')
            ->execute([$numero, $kilpailijaId, $kilpailuId]);

        return ['ok' => true, 'numero' => $numero, 'message' => 'Numero ' . $numero . '.'];
    } finally {
        try {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lukko]);
        } catch (Throwable $e) {
        }
    }
}

function kisaPaikanpaaIlmo(array $kilpailu): bool
{
    return !empty($kilpailu['paikanpaa_ilmo']);
}

function paikanpaaTokenKelpaa(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
}

function paikanpaaUusiToken(): string
{
    return bin2hex(random_bytes(16));
}

function paikanpaaLueTokenPyynnossa(): string
{
    $t = strtolower(trim((string) ($_GET['t'] ?? '')));
    if (paikanpaaTokenKelpaa($t)) {
        return $t;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('#^/ilmo/([a-f0-9]{32})(?:[/?]|$)#', $uri, $m)) {
        return $m[1];
    }

    return '';
}

function paikanpaaKioskiPolku(string $token): string
{
    return '/ilmo/' . $token;
}

function paikanpaaIlmoHref(PDO $pdo, array &$kilpailu): string
{
    $tok = paikanpaaVarmistaToken($pdo, $kilpailu);
    if ($tok === '') {
        return '';
    }

    return paikanpaaKioskiPolku($tok);
}

function paikanpaaKioskiUrl(string $token): string
{
    return 'https://jahonline.com' . paikanpaaKioskiPolku($token);
}

function paikanpaaVarmistaToken(PDO $pdo, array &$kilpailu): string
{
    sarjaNumerotVarmista($pdo);
    $kid = (int) ($kilpailu['id'] ?? 0);
    if ($kid < 1) {
        return '';
    }
    $token = strtolower(trim((string) ($kilpailu['paikanpaa_token'] ?? '')));
    if (paikanpaaTokenKelpaa($token)) {
        return $token;
    }
    for ($i = 0; $i < 6; $i++) {
        $token = paikanpaaUusiToken();
        try {
            $pdo->prepare('UPDATE kilpailut SET paikanpaa_token = ? WHERE id = ? AND (paikanpaa_token IS NULL OR paikanpaa_token = \'\')')
                ->execute([$token, $kid]);
            $st = $pdo->prepare('SELECT paikanpaa_token FROM kilpailut WHERE id = ?');
            $st->execute([$kid]);
            $token = strtolower(trim((string) $st->fetchColumn()));
            if (paikanpaaTokenKelpaa($token)) {
                $kilpailu['paikanpaa_token'] = $token;

                return $token;
            }
        } catch (Throwable $e) {
        }
    }

    return '';
}

function paikanpaaUudistaToken(PDO $pdo, array &$kilpailu): string
{
    sarjaNumerotVarmista($pdo);
    $kid = (int) ($kilpailu['id'] ?? 0);
    if ($kid < 1) {
        return '';
    }
    for ($i = 0; $i < 6; $i++) {
        $token = paikanpaaUusiToken();
        try {
            $pdo->prepare('UPDATE kilpailut SET paikanpaa_token = ? WHERE id = ?')->execute([$token, $kid]);
            $kilpailu['paikanpaa_token'] = $token;

            return $token;
        } catch (Throwable $e) {
        }
    }

    return '';
}

/** @return array<string,mixed>|null */
function paikanpaaHaeKilpailuTokenilla(PDO $pdo, string $token): ?array
{
    if (!paikanpaaTokenKelpaa($token)) {
        return null;
    }
    sarjaNumerotVarmista($pdo);
    $st = $pdo->prepare('SELECT * FROM kilpailut WHERE paikanpaa_token = ? LIMIT 1');
    $st->execute([$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    return $r ?: null;
}

/**
 * @param array{nimi:string,etunimi:string,sukunimi:string} $nimet
 * @return array<string,mixed>
 */
function ilmoTallennaKilpailija(
    PDO $pdo,
    array $kilpailu,
    int $sarjaId,
    array $nimet,
    ?string $seura,
    string $lahde,
    bool $annaNumero
): array {
    $kilpailuId = (int) ($kilpailu['id'] ?? 0);
    $lahde = $lahde === 'paikanpaa' ? 'paikanpaa' : 'verkko';
    $sarjaRivi = null;
    if ($sarjaId > 0) {
        $stS = $pdo->prepare('SELECT * FROM sarjat WHERE id = ? AND kilpailu_id = ?');
        $stS->execute([$sarjaId, $kilpailuId]);
        $sarjaRivi = $stS->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $maksuEur = $lahde === 'paikanpaa'
        ? (function_exists('paikanpaaMaksuEurIlmoittautuvalle')
            ? paikanpaaMaksuEurIlmoittautuvalle($kilpailu, $sarjaRivi)
            : (function_exists('paikanpaaMaksuEur') ? paikanpaaMaksuEur($kilpailu) : null))
        : (function_exists('ilmoMaksuEurIlmoittautuvalle')
            ? ilmoMaksuEurIlmoittautuvalle($kilpailu, $sarjaRivi)
            : (function_exists('ilmoMaksuEur') ? ilmoMaksuEur($kilpailu) : null));
    $maksuTila = $maksuEur !== null ? 'odottaa' : 'ei_vaadita';
    $numero = null;
    $keskhyl = $lahde === 'paikanpaa' ? 'N' : null;
    if ($annaNumero) {
        $numero = seuraavaSarjanNumero($pdo, $kilpailuId, $sarjaId);
        if ($numero <= 0 && function_exists('seuraavaKilpailijaNumero')) {
            $numero = seuraavaKilpailijaNumero($pdo, $kilpailuId);
        }
        if ($numero <= 0) {
            throw new RuntimeException('Ei vapaita kilpailijanumeroita tälle sarjalle.');
        }
    }
    $stmt = $pdo->prepare('
        INSERT INTO kilpailijat
            (kilpailu_id, sarja_id, numero, nimi, etunimi, sukunimi, seura, status,
             keskhyl, maksu_tila, maksu_eur, ilmoittautunut_at, ilmo_lahde)
        VALUES (?, ?, ?, ?, ?, ?, ?, \'OK\', ?, ?, ?, NOW(), ?)
    ');
    $stmt->execute([
        $kilpailuId,
        $sarjaId,
        $numero,
        $nimet['nimi'],
        $nimet['etunimi'],
        $nimet['sukunimi'],
        $seura,
        $keskhyl,
        $maksuTila,
        $maksuEur,
        $lahde,
    ]);
    $id = (int) $pdo->lastInsertId();
    $viite = ($maksuEur !== null && $lahde !== 'paikanpaa' && function_exists('maksuTallennaViite'))
        ? maksuTallennaViite($pdo, $id)
        : null;

    return [
        'id' => $id,
        'numero' => $numero,
        'maksu_tila' => $maksuTila,
        'maksu_eur' => $maksuEur,
        'maksu_viite' => $viite,
        'nimi' => $nimet['nimi'],
        'lahde' => $lahde,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function paikanpaaHaeIlmot(PDO $pdo, int $kilpailuId): array
{
    $st = $pdo->prepare('
        SELECT k.id, k.etunimi, k.sukunimi, k.nimi, k.seura, k.numero, k.sarja_id,
               k.maksu_tila, k.maksu_eur, k.maksu_viite, k.ilmoittautunut_at, k.ilmo_lahde,
               s.nimi AS sarja, s.numero_valit
        FROM kilpailijat k
        JOIN sarjat s ON s.id = k.sarja_id
        WHERE k.kilpailu_id = ?
          AND (k.numero IS NULL OR k.numero < 1)
        ORDER BY k.ilmoittautunut_at IS NULL, k.ilmoittautunut_at ASC, k.id ASC
    ');
    $st->execute([$kilpailuId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
