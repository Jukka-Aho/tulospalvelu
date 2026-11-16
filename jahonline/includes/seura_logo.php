<?php

function seuraLogoKansio(): string
{
    return dirname(__DIR__) . '/uploads/seurat';
}

function seuraLogoVarmista(PDO $pdo): void
{
    if (function_exists('tiliVarmistaSarake')) {
        tiliVarmistaSarake($pdo, 'seurat', 'logo', 'VARCHAR(255) NULL');
    }
    $kansio = seuraLogoKansio();
    if (!is_dir($kansio)) {
        mkdir($kansio, 0775, true);
    }
}

function seuraLogoKirjaimet(string $nimi): string
{
    $nimi = trim($nimi);
    if ($nimi === '') {
        return '?';
    }
    $osat = preg_split('/\s+/u', $nimi) ?: [];
    $osat = array_values(array_filter($osat, static fn($o) => $o !== ''));
    if (count($osat) >= 2) {
        return mb_strtoupper(mb_substr($osat[0], 0, 1) . mb_substr($osat[1], 0, 1));
    }

    return mb_strtoupper(mb_substr($nimi, 0, 2));
}

function seuraLogoTiedostoOk(string $tiedosto): bool
{
    return (bool) preg_match('/^[0-9]+-[a-zA-Z0-9]+\.(png|jpe?g|webp|gif)$/', $tiedosto);
}

function seuraLogoUrl(?array $seura): string
{
    if (!is_array($seura)) {
        return '';
    }
    $tied = basename(trim((string) ($seura['logo'] ?? '')));
    if ($tied === '' || !seuraLogoTiedostoOk($tied)) {
        return '';
    }
    $polku = seuraLogoKansio() . '/' . $tied;
    if (!is_file($polku)) {
        return '';
    }
    $v = filemtime($polku) ?: time();

    return '/uploads/seurat/' . rawurlencode($tied) . '?v=' . $v;
}

function seuraLogoHtml(array $seura, string $luokka = ''): void
{
    $url = seuraLogoUrl($seura);
    $cls = trim('seura-logo ' . $luokka);
    echo '<span class="' . htmlspecialchars($cls) . '" aria-hidden="true">';
    if ($url !== '') {
        echo '<img src="' . htmlspecialchars($url) . '" alt="">';
    } else {
        echo '<span class="seura-logo-kirjaimet">'
            . htmlspecialchars(seuraLogoKirjaimet((string) ($seura['nimi'] ?? '')))
            . '</span>';
    }
    echo '</span>';
}

function seuraLogoTyylit(): string
{
    return <<<'CSS'
<style>
.seura-logo {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:3.4rem;
  height:3.4rem;
  border-radius:12px;
  overflow:hidden;
  background:color-mix(in srgb, var(--primary, #1b6b4a) 16%, var(--card, #fff));
  flex:0 0 auto;
}
.seura-logo img { width:100%; height:100%; object-fit:contain; background:#fff; }
.seura-logo-kirjaimet {
  font-weight:700;
  font-size:1.05rem;
  letter-spacing:.02em;
  color:var(--primary, #1b6b4a);
}
.seura-logo--iso { width:5.5rem; height:5.5rem; border-radius:16px; }
.seura-logo--iso .seura-logo-kirjaimet { font-size:1.6rem; }
.seura-kortti-lista {
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(16.5rem, 1fr));
  gap:0.85rem;
  margin:1rem 0 1.5rem;
}
a.seura-kortti {
  display:flex;
  align-items:center;
  gap:0.85rem;
  padding:0.9rem 1rem;
  border:1px solid var(--border, #d8dee6);
  border-radius:12px;
  background:var(--card, #fff);
  text-decoration:none;
  color:inherit;
  min-height:4.6rem;
}
a.seura-kortti:hover, a.seura-kortti:focus-visible {
  border-color:var(--primary, #1b6b4a);
  box-shadow:inset 3px 0 0 var(--primary, #1b6b4a);
}
.seura-kortti-teksti { display:flex; flex-direction:column; gap:0.2rem; min-width:0; }
.seura-kortti-nimi {
  font-size:1.15rem;
  line-height:1.25;
  font-weight:700;
}
.seura-kortti-meta { font-size:0.92rem; }
.seura-asetus-paa {
  display:flex;
  align-items:center;
  gap:1rem;
  margin:0 0 1rem;
}
.seura-asetus-paa h2 { margin:0; font-size:1.6rem; }
.seura-logo-lomake { margin:0 0 1.1rem; }
.seura-logo-lomake input[type=file] { max-width:100%; }
</style>
CSS;
}

function seuraLogoPoistaTiedosto(?string $tiedosto): void
{
    $tied = basename(trim((string) $tiedosto));
    if ($tied === '' || !seuraLogoTiedostoOk($tied)) {
        return;
    }
    $polku = seuraLogoKansio() . '/' . $tied;
    if (is_file($polku)) {
        @unlink($polku);
    }
}

function seuraLogoTallenna(PDO $pdo, int $seuraId, array $tiedosto): string
{
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    if (($tiedosto['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Valitse logotiedosto.');
    }
    if (($tiedosto['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logon lähetys epäonnistui.');
    }
    $tmp = (string) ($tiedosto['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Virheellinen tiedosto.');
    }
    $koko = (int) ($tiedosto['size'] ?? 0);
    if ($koko < 32 || $koko > 2 * 1024 * 1024) {
        throw new RuntimeException('Logon koko oltava alle 2 Mt.');
    }
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    $sallitut = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $sallitut, true)) {
        throw new RuntimeException('Logo oltava JPEG, PNG, WebP tai GIF.');
    }
    seuraLogoVarmista($pdo);
    $kansio = seuraLogoKansio();
    if (!is_dir($kansio) && !mkdir($kansio, 0775, true) && !is_dir($kansio)) {
        throw new RuntimeException('Logokansiota ei voitu luoda.');
    }
    $nimi = $seuraId . '-' . bin2hex(random_bytes(4)) . '.png';
    $kohde = $kansio . '/' . $nimi;
    $ok = false;
    if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
        $bin = file_get_contents($tmp);
        $im = $bin !== false ? @imagecreatefromstring($bin) : false;
        if ($im !== false) {
            imagesavealpha($im, true);
            $w = imagesx($im);
            $h = imagesy($im);
            $max = 512;
            if ($w > $max || $h > $max) {
                $nw = $w >= $h ? $max : (int) max(1, round($w * $max / $h));
                $nh = $h >= $w ? $max : (int) max(1, round($h * $max / $w));
                $skaala = imagescale($im, $nw, $nh);
                if ($skaala !== false) {
                    imagedestroy($im);
                    $im = $skaala;
                    imagesavealpha($im, true);
                }
            }
            $ok = imagepng($im, $kohde, 6);
            imagedestroy($im);
        }
    }
    if (!$ok) {
        $ext = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'png'));
        $nimi = $seuraId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $kohde = $kansio . '/' . $nimi;
        $ok = move_uploaded_file($tmp, $kohde);
    }
    if (!$ok || !is_file($kohde)) {
        throw new RuntimeException('Logon tallennus epäonnistui.');
    }
    $st = $pdo->prepare('SELECT logo FROM seurat WHERE id = ?');
    $st->execute([$seuraId]);
    $vanha = $st->fetchColumn();
    $pdo->prepare('UPDATE seurat SET logo = ? WHERE id = ?')->execute([$nimi, $seuraId]);
    if (is_string($vanha) && $vanha !== $nimi) {
        seuraLogoPoistaTiedosto($vanha);
    }

    return $nimi;
}

function seuraLogoPoista(PDO $pdo, int $seuraId): void
{
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    $st = $pdo->prepare('SELECT logo FROM seurat WHERE id = ?');
    $st->execute([$seuraId]);
    $vanha = $st->fetchColumn();
    $pdo->prepare('UPDATE seurat SET logo = NULL WHERE id = ?')->execute([$seuraId]);
    if (is_string($vanha)) {
        seuraLogoPoistaTiedosto($vanha);
    }
}

function seuraLogoLomakeHtml(array $seura, string $csrfKentta): void
{
    $sid = (int) ($seura['id'] ?? 0);
    $onLogo = seuraLogoUrl($seura) !== '';
    echo '<div class="seura-logo-lomake">';
    echo '<form method="post" enctype="multipart/form-data">';
    echo $csrfKentta;
    echo '<input type="hidden" name="toiminto" value="tallenna_logo">';
    echo '<input type="hidden" name="seura_id" value="' . $sid . '">';
    echo '<p><label>Seuran logo<br><input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required></label></p>';
    echo '<p class="muted">PNG, JPEG, WebP tai GIF, enintään 2 Mt. Näkyy seurojen hallinnassa.</p>';
    echo '<button type="submit">Tallenna logo</button>';
    echo '</form>';
    if ($onLogo) {
        echo '<form method="post" style="margin-top:0.5rem" onsubmit="return confirm(\'Poistetaanko logo?\');">';
        echo $csrfKentta;
        echo '<input type="hidden" name="toiminto" value="poista_logo">';
        echo '<input type="hidden" name="seura_id" value="' . $sid . '">';
        echo '<button type="submit" class="btn-secondary">Poista logo</button>';
        echo '</form>';
    }
    echo '</div>';
}
