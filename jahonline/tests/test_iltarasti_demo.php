<?php

function fail($msg)
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

$src = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
str_contains($src, 'function iltarastiAsetuksetUrl') || fail('iltarastiAsetuksetUrl');
str_contains($src, 'testikisa = ?, julkinen = ?') || fail('tallenna demo+julkinen');

$aset = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($aset, 'name="testikisa"') || fail('asetukset checkbox');
str_contains($aset, 'Demo-kisa') || fail('asetukset demo-otsikko');
str_contains($aset, '!empty($_POST[\'testikisa\'])') || fail('asetukset tallentaa demon');
str_contains($aset, "\$_GET['kilpailu_id']") || fail('asetukset kilpailu_id');

$hub = file_get_contents(__DIR__ . '/../public/omat_tapahtumat.php');
str_contains($hub, 'iltarastiAsetuksetUrl') || fail('hub avaa asetuksiin');
str_contains($hub, 'iltarastiTulospalveluUrl') && fail('hub ei enää tulospalveluun');
str_contains($hub, 'demoKisaBadgeHtml') || fail('hub demo-merkintä');
str_contains($hub, 'badge-demo') || fail('hub demo-badge');

$help = file_get_contents(__DIR__ . '/../includes/helpers.php');
str_contains($help, 'function demoKisaBadgeHtml') || fail('demoKisaBadgeHtml');
str_contains($help, 'badge badge-demo') || fail('demo badge luokka');

$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'demoKisaBadgeHtml') || fail('hallinta valikko demo');
str_contains($nav, '.badge.badge-demo') || fail('hallinta valikko demo-tyyli');

$hallinta = file_get_contents(__DIR__ . '/../admin/index.php');
str_contains($hallinta, 'demoKisaBadgeHtml') || fail('hallinta kisa-lista demo');

$acl = file_get_contents(__DIR__ . '/../includes/acl.php');
str_contains($acl, "empty(\$kilpailu['testikisa'])") || fail('acl testikisa ei julkinen');

$index = file_get_contents(__DIR__ . '/../public/index.php');
str_contains($index, 'COALESCE(k.testikisa, 0) = 0') || fail('etusivu piilottaa demon');

echo "ok: iltarasti demo\n";
