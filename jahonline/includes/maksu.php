<?php

/**
 * Ilmoittautumismaksu tilisiirtona: saaja, IBAN ja suomalainen viitenumero.
 */

function maksuVarmista(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $lisaa = static function (PDO $pdo, string $table, string $col, string $ddl): void {
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($col));
            if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                return;
            }
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $ddl);
        } catch (Throwable $e) {
        }
    };

    $lisaa($pdo, 'kilpailut', 'ilmo_saaja', 'ilmo_saaja VARCHAR(140) NULL');
    $lisaa($pdo, 'kilpailut', 'ilmo_iban', 'ilmo_iban VARCHAR(34) NULL');
    $lisaa($pdo, 'kilpailut', 'maksu_sarjoittain', 'maksu_sarjoittain TINYINT NOT NULL DEFAULT 0');
    $lisaa($pdo, 'sarjat', 'ennakko_maksu_eur', 'ennakko_maksu_eur DECIMAL(8,2) NULL');
    $lisaa($pdo, 'sarjat', 'paikanpaa_maksu_eur', 'paikanpaa_maksu_eur DECIMAL(8,2) NULL');
    $lisaa($pdo, 'kilpailijat', 'maksu_viite', 'maksu_viite VARCHAR(20) NULL');
}

function suomalainenViite(int $perus): string
{
    $base = (string) max(1, $perus);
    $painot = [7, 3, 1];
    $summa = 0;
    $i = 0;
    for ($p = strlen($base) - 1; $p >= 0; $p--) {
        $summa += (int) $base[$p] * $painot[$i % 3];
        $i++;
    }
    $tarkiste = (10 - ($summa % 10)) % 10;

    return $base . (string) $tarkiste;
}

function muotoileViite(string $viite): string
{
    $viite = preg_replace('/\s+/', '', $viite) ?? '';
    if ($viite === '') {
        return '';
    }
    $osat = [];
    while (strlen($viite) > 5) {
        array_unshift($osat, substr($viite, -5));
        $viite = substr($viite, 0, -5);
    }
    array_unshift($osat, $viite);

    return implode(' ', $osat);
}

function normalisoiIban(string $raw): ?string
{
    $iban = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
    if ($iban === '') {
        return null;
    }
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
        return null;
    }
    $siirto = substr($iban, 4) . substr($iban, 0, 4);
    $num = '';
    $len = strlen($siirto);
    for ($i = 0; $i < $len; $i++) {
        $c = $siirto[$i];
        $num .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
    }
    $mod = 0;
    foreach (str_split($num, 7) as $chunk) {
        $mod = (int) (($mod . $chunk) % 97);
    }

    return $mod === 1 ? $iban : null;
}

function muotoileIban(string $iban): string
{
    $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

    return trim(chunk_split($iban, 4, ' '));
}

function maksuTallennaViite(PDO $pdo, int $kilpailijaId): string
{
    $viite = suomalainenViite($kilpailijaId);
    $pdo->prepare('UPDATE kilpailijat SET maksu_viite = ? WHERE id = ?')->execute([$viite, $kilpailijaId]);

    return $viite;
}

/**
 * @param array<string,mixed> $kilpailu
 */
function maksuOhjeHtml(array $kilpailu, float $eur, ?string $viite, string $nimi): void
{
    $saaja = trim((string) ($kilpailu['ilmo_saaja'] ?? ''));
    $iban = trim((string) ($kilpailu['ilmo_iban'] ?? ''));
    $viiteNaytto = $viite ? muotoileViite($viite) : '';
    $ibanNaytto = $iban !== '' ? muotoileIban($iban) : '';
    ?>
    <div class="lb-maksuohje">
        <h3>Maksu tilisiirrolla</h3>
        <dl class="maksu-tiedot">
            <div>
                <dt>Summa</dt>
                <dd><strong><?= htmlspecialchars(formatEuro($eur)) ?></strong></dd>
            </div>
            <?php if ($saaja !== ''): ?>
            <div>
                <dt>Saaja</dt>
                <dd><?= htmlspecialchars($saaja) ?></dd>
            </div>
            <?php endif; ?>
            <?php if ($ibanNaytto !== ''): ?>
            <div>
                <dt>Tilinumero</dt>
                <dd>
                    <span class="maksu-arvo" id="maksu-iban"><?= htmlspecialchars($ibanNaytto) ?></span>
                    <button type="button" class="btn-small btn-secondary" data-kopioi="maksu-iban">Kopioi</button>
                </dd>
            </div>
            <?php endif; ?>
            <?php if ($viiteNaytto !== ''): ?>
            <div>
                <dt>Viitenumero</dt>
                <dd>
                    <span class="maksu-arvo" id="maksu-viite"><?= htmlspecialchars($viiteNaytto) ?></span>
                    <button type="button" class="btn-small btn-secondary" data-kopioi="maksu-viite">Kopioi</button>
                </dd>
            </div>
            <?php endif; ?>
        </dl>
        <p class="lb-muted">
            Käytä viitenumeroa. Jos pankki ei ota viitettä, kirjoita viestiksi
            <strong><?= htmlspecialchars($nimi) ?></strong>.
            Järjestäjä merkitsee maksun saapuneeksi.
        </p>
        <?php if ($ibanNaytto === ''): ?>
            <p class="lb-muted">Tilinumeroa ei ole vielä asetettu. Käytä järjestäjän maksuohjetta.</p>
        <?php endif; ?>
        <?php if (!empty($kilpailu['ilmo_maksuohje'])): ?>
            <pre class="maksuohje"><?= htmlspecialchars((string) $kilpailu['ilmo_maksuohje']) ?></pre>
        <?php endif; ?>
    </div>
    <script>
    document.querySelectorAll('[data-kopioi]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.getAttribute('data-kopioi'));
            var teksti = el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
            if (!teksti || !navigator.clipboard) return;
            navigator.clipboard.writeText(teksti.replace(/ /g, '')).then(function () {
                btn.textContent = 'Kopioitu';
            });
        });
    });
    </script>
    <?php
}

function seuraMaksuOnKirjattu(array $rivi): bool
{
    $tila = (string) ($rivi['maksu_tila'] ?? 'ei_vaadita');
    $viite = trim((string) ($rivi['maksu_viite'] ?? ''));
    $eur = $rivi['maksu_eur'] ?? null;

    return $tila === 'odottaa' || $tila === 'maksettu' || $viite !== '' || $eur !== null;
}

/**
 * Seuran kilpailujen ilmoittautumismaksut. Ei erillistä maksutaulua:
 * tiedot ovat kilpailijat.maksu_tila / maksu_eur / maksu_viite.
 *
 * @return list<array<string,mixed>>
 */
function seuraHaeMaksut(PDO $pdo, int $seuraId): array
{
    if ($seuraId < 1) {
        return [];
    }
    $st = $pdo->prepare("
        SELECT k.id, k.etunimi, k.sukunimi, k.nimi, k.seura, k.maksu_tila, k.maksu_eur, k.maksu_viite,
               k.ilmoittautunut_at, t.id AS kilpailu_id, t.nimi AS kilpailu_nimi
        FROM kilpailijat k
        INNER JOIN kilpailut t ON t.id = k.kilpailu_id
        WHERE t.seura_id = ?
          AND (
            k.maksu_tila IN ('odottaa', 'maksettu')
            OR k.maksu_eur IS NOT NULL
            OR (k.maksu_viite IS NOT NULL AND k.maksu_viite <> '')
          )
        ORDER BY k.ilmoittautunut_at IS NULL, k.ilmoittautunut_at DESC, k.id DESC
    ");
    $st->execute([$seuraId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function seuraPoistaMaksu(PDO $pdo, int $seuraId, int $kilpailijaId): bool
{
    if ($seuraId < 1 || $kilpailijaId < 1) {
        return false;
    }
    $st = $pdo->prepare("
        UPDATE kilpailijat k
        INNER JOIN kilpailut t ON t.id = k.kilpailu_id
        SET k.maksu_tila = 'ei_vaadita', k.maksu_eur = NULL, k.maksu_viite = NULL
        WHERE k.id = ?
          AND t.seura_id = ?
          AND (
            k.maksu_tila IN ('odottaa', 'maksettu')
            OR k.maksu_eur IS NOT NULL
            OR (k.maksu_viite IS NOT NULL AND k.maksu_viite <> '')
          )
    ");
    $st->execute([$kilpailijaId, $seuraId]);

    return $st->rowCount() > 0;
}

function seuraPoistaKaikkiMaksut(PDO $pdo, int $seuraId): int
{
    if ($seuraId < 1) {
        return 0;
    }
    $st = $pdo->prepare("
        UPDATE kilpailijat k
        INNER JOIN kilpailut t ON t.id = k.kilpailu_id
        SET k.maksu_tila = 'ei_vaadita', k.maksu_eur = NULL, k.maksu_viite = NULL
        WHERE t.seura_id = ?
          AND (
            k.maksu_tila IN ('odottaa', 'maksettu')
            OR k.maksu_eur IS NOT NULL
            OR (k.maksu_viite IS NOT NULL AND k.maksu_viite <> '')
          )
    ");
    $st->execute([$seuraId]);

    return $st->rowCount();
}
