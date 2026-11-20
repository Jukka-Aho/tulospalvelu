<?php

function fail($msg)
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

$sarjat = file_get_contents(__DIR__ . '/../admin/sarjat.php');
str_contains($sarjat, 'name="jarjestys"') || fail('jarjestys kentta');
str_contains($sarjat, 'ilmoittautumislomakkeessa') || fail('jarjestys ohje listat');
str_contains($sarjat, 'Pienin numero ensin') || fail('jarjestys ohje pienin ensin');
str_contains($sarjat, 'nollat tulevat ensin') || fail('jarjestys ohje nollat');
str_contains($sarjat, '$seuraavaJarjestys') || fail('uuden sarjan jarjestys');
str_contains($sarjat, 'max(0, (int) ($_POST[\'jarjestys\'] ?? 0))') || fail('jarjestys int');

$help = file_get_contents(__DIR__ . '/../includes/helpers.php');
str_contains($help, 's.id AS sarja_id') || fail('ilmoittautuneet sarja_id');
str_contains($help, "\$key = \$sid > 0 ? ('s' . \$sid) : \$sarja") || fail('ilmoittautuneet ryhmä id');
str_contains($help, "'nimi' => \$sarja") || fail('ilmoittautuneet ryhmä nimi');

$lista = file_get_contents(__DIR__ . '/../public/ilmoittautuneet.php');
str_contains($lista, "\$data['nimi']") || fail('lista kayttaa ryhmän nimeä');

echo "ok: sarja jarjestys\n";
