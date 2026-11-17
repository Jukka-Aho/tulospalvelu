<?php

/**
 * Kilpailun / tapahtuman poisto: vahvistus nimen kirjoittamalla.
 */

function kilpailuPoistoVahvistusTeksti(array $kilpailu): string
{
    $nimi = trim((string) ($kilpailu['nimi'] ?? ''));

    return $nimi !== '' ? (string) ($kilpailu['nimi'] ?? $nimi) : 'POISTA';
}

function kilpailuPoistoVahvistusOk(array $kilpailu, array $post): bool
{
    $odotettu = kilpailuPoistoVahvistusTeksti($kilpailu);
    $syote = (string) ($post['vahvista_nimi'] ?? '');

    return $odotettu !== '' && hash_equals($odotettu, $syote);
}

function kilpailuPoistaTietokannasta(PDO $pdo, int $id): void
{
    if ($id < 1) {
        throw new InvalidArgumentException('Kilpailu puuttuu.');
    }
    $pdo->beginTransaction();
    try {
        try {
            $idt = $pdo->prepare('SELECT id FROM radat WHERE kilpailu_id = ?');
            $idt->execute([$id]);
            $rataIdt = array_map('intval', $idt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($rataIdt !== []) {
                $ph = implode(',', array_fill(0, count($rataIdt), '?'));
                $pdo->prepare("DELETE FROM rata_rastit WHERE rata_id IN ($ph)")->execute($rataIdt);
            }
        } catch (Throwable $e) {
        }
        $taulut = [
            'ajanotto_audit',
            'ajanotto_laitteet',
            'ajanotto_peruutus',
            'emit_leimat',
            'gps_sessiot',
            'ilmo_arvonnat',
            'ilmo_arvonta_valitut',
            'pirila_kello',
            'pirila_tapahtumat',
            'pirila_yhteydet',
            'radat',
            'rastilippu_ilmoittautuneet',
            'tv_profiilit',
        ];
        foreach ($taulut as $taulu) {
            try {
                $pdo->prepare("DELETE FROM {$taulu} WHERE kilpailu_id = ?")->execute([$id]);
            } catch (Throwable $e) {
            }
        }
        $pdo->prepare('DELETE FROM kilpailut WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function kilpailuPoistaHallinnasta(PDO $pdo, int $id, array $post): void
{
    if (!function_exists('haeKilpailuHallintaan')) {
        throw new RuntimeException('Ei käyttöoikeutta');
    }
    $k = haeKilpailuHallintaan($pdo, $id);
    if (!$k) {
        throw new RuntimeException('Ei käyttöoikeutta');
    }
    if (!kilpailuPoistoVahvistusOk($k, $post)) {
        throw new RuntimeException('Nimi ei täsmännyt — kilpailua ei poistettu.');
    }
    kilpailuPoistaTietokannasta($pdo, $id);
    if (function_exists('valittuKilpailuId') && (int) valittuKilpailuId() === $id
        && function_exists('tyhjennaValittuKilpailu')) {
        tyhjennaValittuKilpailu();
    }
}

function kilpailuPoistoLomakeHtml(array $kilpailu, array $opts = []): string
{
    $id = (int) ($kilpailu['id'] ?? 0);
    $nimi = kilpailuPoistoVahvistusTeksti($kilpailu);
    $actionName = (string) ($opts['action_name'] ?? 'toiminto');
    $actionValue = (string) ($opts['action_value'] ?? 'poista_kilpailu');
    $idName = (string) ($opts['id_name'] ?? 'kilpailu_id');
    $btnClass = (string) ($opts['btn_class'] ?? 'btn-danger btn-small');
    $label = (string) ($opts['label'] ?? 'Poista');
    $kohde = (string) ($opts['kohde'] ?? 'kilpailun');
    $formClass = (string) ($opts['form_class'] ?? 'inline-form');
    $csrf = function_exists('csrfKentta') ? csrfKentta() : '';

    return '<form method="post" class="' . htmlspecialchars($formClass, ENT_QUOTES, 'UTF-8')
        . '" data-poista-kisa data-kisa-nimi="' . htmlspecialchars($nimi, ENT_QUOTES, 'UTF-8')
        . '" data-poista-kohde="' . htmlspecialchars($kohde, ENT_QUOTES, 'UTF-8') . '">'
        . $csrf
        . '<input type="hidden" name="' . htmlspecialchars($actionName, ENT_QUOTES, 'UTF-8')
        . '" value="' . htmlspecialchars($actionValue, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="' . htmlspecialchars($idName, ENT_QUOTES, 'UTF-8')
        . '" value="' . $id . '">'
        . '<input type="hidden" name="vahvista_nimi" value="">'
        . '<button type="submit" class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
        . '</form>';
}

function kilpailuPoistoVahvistusJs(): string
{
    return <<<'JS'
(function () {
    document.querySelectorAll('form[data-poista-kisa]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var nimi = form.getAttribute('data-kisa-nimi') || '';
            var kohde = form.getAttribute('data-poista-kohde') || 'kilpailun';
            if (!window.confirm(
                'Haluatko aivan varmasti poistaa ' + kohde + '?\n\n'
                + nimi
                + '\n\nKaikki sarjat, kilpailijat ja ajat poistuvat. Tätä ei voi perua.'
            )) {
                ev.preventDefault();
                return;
            }
            var syote = window.prompt(
                'Vahvista poisto kirjoittamalla nimi täsmälleen:\n\n' + nimi
            );
            if (syote !== nimi) {
                ev.preventDefault();
                if (syote !== null) {
                    window.alert('Nimi ei täsmännyt — poistamista ei tehty.');
                }
                return;
            }
            var kentta = form.querySelector('input[name="vahvista_nimi"]');
            if (kentta) {
                kentta.value = syote;
            }
        });
    });
})();
JS;
}

function kilpailuPoistoVahvistusScriptti(): void
{
    echo '<script>' . kilpailuPoistoVahvistusJs() . '</script>';
}
