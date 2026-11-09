# Pruebas

Dos grupos, en `tests/`:

| Grupo | Qué prueba | Necesita |
|---|---|---|
| `Unidad/` | Dinero, IVA, comisiones, ticket, subfolios, permisos, router, ligas de pago, piezas de la API, que `docs/api` documente las mismas rutas y errores que el código, y que toda clase que el código nombra exista | Solo PHP |
| `Integracion/` | El esquema de una empresa nueva y sus migraciones, abonos (`RegistrarPago`), la API completa y el sistema levantado con un servidor web real (`php -S`): login, Ajustes → API, crear, usar y revocar una llave | PHP + MySQL |

Las de integración **borran y vuelven a crear** dos bases: `lf_prueba_principal`
y `lf_prueba_empresa`. Nunca apuntes las variables a un servidor con datos reales.

## Correrlas en tu equipo

Requisitos: PHP 8.1 o más nuevo con `pdo_mysql`, y Composer. Con Laragon ya
viene todo (`C:\laragon\bin\php\…` y `C:\laragon\bin\composer`).

```bash
cd app
composer install                    # instala PHPUnit en vendor/ (solo la primera vez)
vendor/bin/phpunit --testsuite unidad
```

Para las de integración, con el MySQL de Laragon encendido (usuario `root`
sin contraseña):

```bash
# Git Bash / Linux / macOS
LF_PRUEBAS_BD_HOST=127.0.0.1 vendor/bin/phpunit

# PowerShell
$env:LF_PRUEBAS_BD_HOST = "127.0.0.1"; vendor/bin/phpunit
```

| Variable | Por omisión |
|---|---|
| `LF_PRUEBAS_BD_HOST` | sin ella, las de integración se omiten |
| `LF_PRUEBAS_BD_PUERTO` | `3306` |
| `LF_PRUEBAS_BD_USUARIO` | `root` |
| `LF_PRUEBAS_BD_CLAVE` | vacía |
| `LF_PRUEBAS_DEPURAR` | `1` para ver la traza cuando el servidor de prueba contesta 500 |

## Las revisiones de `herramientas/`

También corren en el pipeline y salen con error si encuentran algo:

```bash
php herramientas/revisar-sintaxis.php   # php -l de cada archivo
php herramientas/revisar-rutas.php      # ninguna ruta queda abierta sin permiso
php herramientas/revisar-columnas.php   # los reportes leen columnas que existen
```

`composer revisar` corre las tres.

## En GitHub

`.github/workflows/revision.yml` corre todo esto en cada push y pull request:
sintaxis en PHP 8.0 a 8.4, las revisiones, y las pruebas en PHP 8.1 y 8.3
contra MySQL 8.0.

## Al agregar algo

- **Una regla de dinero** (abonos, comisiones, IVA): su prueba en `Unidad/`
  si no toca la base, en `Integracion/` si sí.
- **Una ruta nueva**: su permiso en la tabla de `public/index.php`; si no,
  `revisar-rutas` falla. En la API, su permiso en `Puerta::RUTAS` y su
  documentación en `docs/api/openapi.yaml` y `docs/api/README.md`; si no,
  `EspecificacionTest` falla. Lo mismo con un código de error nuevo.
- **Una migración**: `EsquemaTest` comprueba que una empresa nueva queda al día.
