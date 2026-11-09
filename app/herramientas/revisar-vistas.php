<?php
/**
 * Comprueba que cada vista reciba las variables que usa.
 *
 * El error que busca no rompe la compilación ni aparece en pruebas: la
 * página se abre y empieza a escupir "Undefined variable" encima del
 * diseño. Solo se descubre entrando a esa pantalla exacta.
 */
$mal = 0;
$ignorar = ['_SESSION','_POST','_GET','_SERVER','_FILES','_COOKIE','_ENV','GLOBALS','this'];

foreach (glob("src/Controlador/*.php") as $f) {
    $t = file_get_contents($f);
    // Hasta el cierre REAL de la llamada, no el primer ] que aparezca:
    // varios controladores mandan arreglos anidados.
    if (!preg_match_all('/Plantilla::pagina\(\x27([\w\/-]+)\x27,\s*\[(.*?)\n\s*\]\s*[,)]/s',
        $t, $m, PREG_SET_ORDER)) continue;

    foreach ($m as $x) {
        // Solo las claves del primer nivel
        $cuerpo = preg_replace('/\[[^\[\]]*\]/', '', $x[2]);
        for ($i = 0; $i < 4; $i++) $cuerpo = preg_replace('/\[[^\[\]]*\]/', '', $cuerpo);
        preg_match_all('/\x27(\w+)\x27\s*=>/', $x[2], $k);
        $manda = array_flip($k[1]);
        foreach (['titulo','icono','subtitulo','contenido','activo'] as $b) $manda[$b] = 1;

        $v = "src/Vista/plantillas/" . $x[1] . ".php";
        if (!is_file($v)) { printf("  %-28s LA VISTA NO EXISTE (%s)\n", $x[1], basename($f)); $mal++; continue; }

        $vt = file_get_contents($v);
        $def = [];
        preg_match_all('/\$(\w+)\s*=[^=]/', $vt, $d);           foreach ($d[1] as $z) $def[$z]=1;
        preg_match_all('/as\s+\$(\w+)(\s*=>\s*\$(\w+))?/', $vt, $d2, PREG_SET_ORDER);
        foreach ($d2 as $z) { $def[$z[1]]=1; if (!empty($z[3])) $def[$z[3]]=1; }
        preg_match_all('/list\(([^)]*)\)/', $vt, $d3);
        foreach ($d3[1] as $z) { preg_match_all('/\$(\w+)/', $z, $zz); foreach ($zz[1] as $w) $def[$w]=1; }
        preg_match_all('/function\s*\(([^)]*)\)(\s*use\s*\(([^)]*)\))?/', $vt, $d4, PREG_SET_ORDER);
        foreach ($d4 as $z) { preg_match_all('/\$(\w+)/', $z[1].($z[3]??''), $zz); foreach ($zz[1] as $w) $def[$w]=1; }

        preg_match_all('/\$(\w+)/', $vt, $u);
        foreach (array_unique($u[1]) as $var) {
            if (in_array($var, $ignorar, true) || ctype_digit($var)) continue;
            if (isset($manda[$var]) || isset($def[$var])) continue;
            printf("  %-28s usa \$%-14s · %s\n", $x[1].".php", $var, basename($f));
            $mal++;
        }
    }
}
echo $mal ? "\n  $mal por revisar\n" : "  todas las vistas reciben lo que usan\n";
