# Comprobadores

Se corren desde la carpeta `app/`:

```
php herramientas/revisar-vistas.php
```

## revisar-vistas.php

Comprueba que cada vista reciba las variables que usa.

**Por qué existe.** Ese error no rompe la compilación ni sale en `php -l`:
la página se abre y empieza a escupir `Undefined variable` encima del
diseño. Solo aparece entrando a esa pantalla exacta, y con cuarenta
pantallas eso significa que lo encuentra el usuario, no quien lo rompió.

Pasó dos veces en este proyecto: una edición buscó un texto que ya había
cambiado, falló en silencio, y la pantalla salió sin datos.

**Dos avisos son falsos positivos conocidos:**

- `mantenimiento/index.php usa $se` — es `$señales`, con ñ, que la
  expresión parte en dos.
- `panel/index.php usa $f` — es el parámetro de una función declarada
  dentro de la propia vista.

Si aparece cualquier otro, es real.


## revisar-rutas.php

```
php herramientas/revisar-rutas.php
```

Comprueba que cada ruta declare quién puede entrar.

**Por qué importa.** Una ruta que falta en la tabla de permisos no queda
cerrada: queda **abierta** a cualquiera con sesión. Es el fallo que no se
nota, porque la pantalla funciona perfecto — hasta que un cajero escribe
`/comisiones` en la barra y ve lo que gana todo el mundo.

El archivo trae una lista de excepciones a propósito: las públicas, la
raíz —que bifurca según el nivel del rol— y las de la cuenta propia, que
cualquiera puede usar **sobre sí mismo**. Pedir permiso ahí dejaría a un
cajero sin poder cambiar su contraseña.

Si agregas una ruta nueva de ese tipo, añádela a `$aproposito`. Si no
aparece ahí ni en la tabla, el comprobador la marca.
