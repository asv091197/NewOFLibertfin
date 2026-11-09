# LibertyFin · reconstrucción

Rebanada vertical completa: fundación + la pantalla de Ventas conectada a
la base real. Corre contra tus datos tal cual.

## Instalar

1. Sube la carpeta completa **fuera** de `public_html`, por ejemplo en
   `/home/juanc141/libertyfin-app/`
2. Apunta el dominio (o un subdominio de pruebas) a `libertyfin-app/public/`
3. `cp config/config.php.ejemplo config/config.php` y llena las credenciales
4. Entra al sistema viejo para tener sesión, y luego abre `/ventas`

Si no puedes mover el DocumentRoot, sube `public/` como `app2/` dentro de
`public_html` y el resto a un nivel arriba.

## Qué demuestra

| | Antes | Ahora |
|---|---|---|
| `ventas_lista.php` | 2,662 líneas | controlador 69 + vista 143 |
| Regla de IVA | en 4 archivos | `src/Dominio/Iva.php` |
| Consultas de ventas | en 77 lugares | `src/Datos/VentaRepo.php` |
| `.env` alcanzable por URL | sí | imposible: vive fuera de `public/` |
| Filtro de fecha | `DATE(v.fecha)` | rango directo, 5× más rápido |

## La estructura

```
libertyfin-app/
├── public/          ← lo ÚNICO que ve la web
│   ├── index.php    ← punto de entrada único
│   ├── .htaccess    ← todo a index.php + gzip + caché
│   └── assets/css/
├── src/
│   ├── autoload.php
│   ├── Dominio/     ← las reglas. Sin SQL, sin HTML, sin sesión.
│   ├── Datos/       ← lo único que toca la base
│   ├── Http/        ← router y lectura de la petición
│   ├── Vista/       ← plantillas y widgets
│   └── Controlador/
└── config/          ← credenciales, fuera del alcance web
```

## Cómo se agrega una pantalla

Tres archivos, ninguno largo:

1. `src/Datos/XRepo.php` — las consultas
2. `src/Controlador/XControlador.php` — decide qué pedir y qué pasar a la vista
3. `src/Vista/plantillas/x/index.php` — el HTML

Y una línea en `public/index.php`:

```php
$r->get('/clientes', ['LibertyFin\Controlador\ClientesControlador', 'index']);
```

## Pantallas

| Ruta | Estado |
|---|---|
| `/` Panel | listo |
| `/ventas` | listo |
| `/caja` | listo |
| `/clientes` | listo |
| `/comisiones` | listo |
| `/servicios` | listo |
| `/ventas/{id}` detalle | listo |
| `/login` | listo |
| `/corte` corte de caja | listo |
| `/cobranza` | listo |
| `/gastos` | listo |
| `/reportes` | listo |
| `/ajustes` | listo · admin y soporte |
| `/mantenimiento` | listo · solo soporte |
| `/usuarios` | listo · pestaña de Ajustes |
| `/cuenta` | listo · 5 pestañas |
| `/facturacion` | pantalla lista · timbrado pendiente |

## Notas del esquema

Dos cosas de la base que no son obvias y cuestan caro si se ignoran:

**`productos` no tiene `sucursal_id`.** La relación va por la tabla
`producto_sucursal (producto_id, sucursal_id, stock, stock_minimo)`, y con
LEFT JOIN: un servicio sin renglón ahí sigue siendo vendible, solo que sin
stock propio. Un INNER JOIN lo desaparecería del catálogo.

**El precio de venta es `subprecio`, no `precio`.** Así lo lee el sistema
anterior. En el código nuevo va como
`COALESCE(NULLIF(p.subprecio,0), p.precio)`, con `precio` de respaldo por si
`subprecio` viene en cero.

## Modo oscuro

El tema se aplica en un script dentro del `<head>`, antes de que el navegador
pinte. Si se esperara al final del documento, la página aparecería en claro y
saltaría a oscuro: ese parpadeo blanco es lo que hace que un modo oscuro se
sienta barato.

Se guarda en `localStorage`. **Nunca se hereda del sistema operativo sin que
el usuario lo pida**: si alguien tiene el celular en oscuro y abre la app por
primera vez, la ve en claro.

El botón muestra el icono de lo que vas a OBTENER, no el del estado actual: en
claro se ve la luna.

## Corte de caja

Compara **efectivo contra efectivo**. Transferencias y tarjeta se muestran
aparte porque no pasan por el cajón: sumarlas al corte es la forma más común
de "cuadrar" una caja que en realidad no cuadra.

Si hay diferencia, la nota es obligatoria. Y se guarda **con signo**: positiva
si sobró, negativa si faltó. Guardar el valor absoluto esconde justo lo que
importa saber.

**El turno se lee de la base, no de la sesión.** Antes `$_SESSION['caja_id']`
solo se escribía al entrar a `/corte`, así que el cajero que iba derecho a
cobrar veía "no tienes caja abierta" teniéndola. Y lo grave no era el aviso:
las ventas se guardaban con `caja_id = NULL` y al cerrar **no aparecían en el
corte**. Se cuadraba contra un total al que le faltaban ventas reales. Ahora
lo resuelve `Servicio\Caja`, que pregunta una vez por petición y usa la sesión
como caché.

Y el turno se busca **por usuario, no por usuario y sucursal exactos**. Una
persona trae un cajón, no uno por sucursal: a quien le cambiaban de sucursal
se le perdía el turno abierto con el dinero adentro, y quien no tenía sucursal
asignada abría con `sucursal_id = 0` y después su propio turno dejaba de
aparecer.

## Asignar comisiones

Se hace desde el detalle de la venta. Eliges colaborador y porcentaje, y ves
en vivo cuánto le tocaría antes de guardar. El porcentaje se sugiere solo con
el que esa persona suele cobrar.

**La base la calcula `Dominio\Comision`, no el formulario.** Esa es toda la
diferencia con `guardar_comision_producto.php` del sistema anterior, que tomaba
`venta_detalles.precio_unitario` tal cual y suponía que venía sin impuesto. Esa
suposición costó tres errores en la migración:

| Venta | Salió | Debía ser |
|---|---|---|
| Leopoldo · Francisco Flores 10% | $672.80 | $580.00 |
| Reyna · base con IVA | $579.98 | $499.98 |
| PW · base con IVA y gastos | $1,478.84 | $999.00 |

Aquí no puede pasar: el precio pasa por `Iva::quitar()` antes de tocar nada.

Reglas que impone el servicio:

- Nadie puede tener dos comisiones en la misma venta
- No se comisiona una venta cancelada
- Si los gastos se comen la utilidad, no deja asignar
- Quitar una comisión la cancela, no la borra, y resincroniza lo devengado

## Alta y edición de clientes y servicios

Un panel plegable arriba de cada listado, hecho con `<details>`: se abre y se
cierra sin una línea de JavaScript. Editar es el mismo formulario con los datos
cargados.

**Clientes.** Valida el RFC con su formato real, el correo, y guarda del
teléfono solo lo que sirve para marcar. Rechaza nombres repetidos: dos clientes
con el mismo nombre son casi siempre captura duplicada, que fue justo lo que
llenó julio de basura.

**Servicios.** El código no se puede repetir: si dos servicios lo comparten, es
imposible saber cuál se vendió. Y el costo no puede superar al precio, porque
eso daría una base comisionable negativa.

Un servicio no se borra nunca, se desactiva. Borrarlo dejaría ventas apuntando
a un producto que ya no existe.

Las validaciones baratas van antes de consultar la base: no tiene sentido ir a
buscar códigos duplicados para terminar rechazando un precio en cero.

## Usuarios y contraseñas

**La contraseña nunca sale del repositorio.** Ningún método la devuelve,
ninguna vista la recibe. Lo único que existe es cambiarla, y siempre pasa por
`password_hash()` con bcrypt.

**Dos caminos distintos, a propósito:**

- Un administrador **restablece** la de otro sin pedir la anterior: es para
  cuando alguien la olvidó.
- Cada quien **cambia la suya** desde `/cuenta`, y ahí sí hay que saber la
  actual. Al cambiarla se cierra la sesión: si alguien la cambió porque
  sospecha que se la sabían, dejar la sesión viva no serviría de nada.

**Las reglas son ocho caracteres y que no sea el nombre de usuario.**
Deliberadamente NO se exige mayúscula, número y símbolo. Esa regla produce
`Empresa2026!` pegado en un post-it, que es peor que una frase larga que la
persona sí recuerda.

**Un usuario no se borra, se desactiva.** Las ventas apuntan a quien las hizo;
borrarlo sería perder el rastro de quién cobró. Y no se puede desactivar al
único administrador activo: nadie podría volver a entrar a configurar.

Al dar de alta, la contraseña se escribe en claro a propósito: la pone el
administrador y se la dice a la persona. Después nadie puede volver a verla,
ni él.

## Cobranza

Contesta "a quién le hablo hoy", no "cuánto me deben". Por eso ordena por
antigüedad del último abono y no por monto: $18,000 parados dos meses son peor
noticia que $30,000 que abonaron ayer.

**Los días se cuentan desde el último abono, no desde la venta.** Una venta de
hace seis meses con un abono ayer está al corriente; una de hace dos meses sin
tocar, no.

Las cuatro tarjetas de arriba son filtros: tocas "Más de 60 días" y la lista se
reduce a eso. Y hay dos vistas: por venta, para cobrar; por cliente, para
sentarse a negociar con alguien que debe varias.

## Gastos

Dos clases que NO se mezclan:

- **De operación** · cuelgan de una venta y se restan de la utilidad antes de
  comisionar. Se capturan en Caja o en el detalle de la venta.
- **Generales** · renta, nómina, servicios. No pertenecen a ninguna venta y no
  tocan comisiones. Son los de esta pantalla.

Confundirlos repetiría un error que ya costó caro: un gasto general restado a
una venta le baja la comisión a alguien sin razón. Por eso la pantalla muestra
los dos totales por separado y nunca los suma en la misma cifra.

Solo se pueden borrar los generales. Uno de operación cambia la base de comisión
de su venta, y eso se toca desde el detalle de esa venta.

## La arquitectura no copia la del sistema anterior

El sistema viejo tenía 37 pantallas porque creció sobre la marcha: cada cosa
nueva era un archivo nuevo. Aquí se agrupa por lo que la gente hace, no por
cómo se fue construyendo.

| Sección | Absorbe del sistema anterior |
|---|---|
| Panel | dashboard |
| Caja | caja, checkout |
| Ventas | ventas_lista, detalle, ticket (botón, no pantalla) |
| Cobranza | cuentas_por_cobrar |
| Corte de caja | caja_apertura, caja_cierre, caja_historial, caja_resumen |
| Clientes | clientes, facturar_cliente |
| Comisiones | recalcular_comisiones |
| Gastos | gastos |
| Servicios | productos, promociones |
| Reportes | reportes |
| **Ajustes** | **configuracion, sucursales, comisiones_config, categorias, usuarios** |

**Ajustes** son cuatro pestañas que en el viejo eran cuatro pantallas de entre
700 y 1,500 líneas. Son tablas chicas que se tocan una vez al mes y siempre
juntas: darle pantalla completa a cada una solo obliga a navegar de más.

## Reportes

Contesta la pregunta que el sistema anterior no podía: **cuánto queda de
verdad.**

    Cobrado
      − gastos de operación
      − gastos generales
      − comisiones devengadas
    = queda

Parte del **cobrado**, no de lo facturado. Lo facturado y el saldo pendiente
aparecen abajo, en una nota aparte, con el porqué escrito: sumar lo facturado
como ingreso fue lo que hacía que el sistema mostrara $558,057.60 cuando lo
real eran $289,468.05.

Y el IVA se señala aparte cuando lo hay: no es ingreso, es del SAT.

**Los criterios de fecha no son iguales para todo, a propósito:**

| Concepto | Se mide por |
|---|---|
| Cobrado | `venta_pagos.fecha_pago` — cuándo entró el dinero |
| Gastos de operación | `ventas.fecha` — cuelgan de su venta |
| Gastos generales | `gastos.fecha` — cuándo se pagó |
| Comisiones | `ventas.fecha` — a qué venta pertenecen |

El margen por área es la cifra más útil de la pantalla: un área con mucho
cobrado y margen bajo está trabajando para pagar comisiones.

**El CSV sale con BOM y punto y coma.** Sin eso, Excel en español abre el
archivo con todo en una columna y rompe los acentos. Es un detalle tonto que
hace la diferencia entre un reporte que se usa y uno que no.

## Ticket

Vive en `/ventas/{id}/ticket` y trae su propio CSS: es la única pantalla que se
imprime, y cargar la hoja completa para tacharla al imprimir no tiene sentido.
Ancho de 80 mm, el de las térmicas.

Con `?auto=1` se manda a imprimir solo, para encadenarlo después de cobrar.

## Historial de cortes

Pestaña de Corte de caja. Cuenta cuántos cuadraron y acumula sobrantes y
faltantes por separado, que es lo que revela si alguien se está equivocando
siempre para el mismo lado.

La columna "cobrado" incluye transferencias y tarjeta; la de "contado" es solo
efectivo. Por eso casi nunca coinciden, y no tienen por qué.

## Datos de la empresa

Pestaña de Ajustes. Solo se editan contacto y datos fiscales, con lista blanca
de campos: el plan, el nombre de la base y sus credenciales NO se tocan desde
aquí. Cambiarlos dejaría a la empresa sin poder entrar, y eso es trabajo de
quien administra la plataforma.

## Zona horaria

**PHP y MySQL tienen que estar en la misma zona.** `public/index.php` fija la de
PHP y `Conexion` la de cada sesión de MySQL, con los valores de
`config/config.php`.

Si no coinciden, `date()` arma el folio con la hora de México y `NOW()` guarda
UTC: seis horas de diferencia. Casi siempre da igual, pero una venta de las
18:00 del último día del mes queda registrada en el mes siguiente.

Pasó de verdad. INITME Solutions, folio `20260831180937` — 31 de agosto a las
18:09 — quedó guardada el 1 de septiembre a las 00:09. Eso movió $2,900 de venta
y $825 de comisión al mes equivocado, y era la causa de que el sistema no
cuadrara contra el Excel.

Se pone la zona de la **sesión**, no la del servidor: así funciona aunque el
hosting esté en UTC y no se pueda cambiar.

## Proveedores

Viven dentro de Gastos, como pestaña. No tienen sección propia porque solo
existen para una cosa: saber a quién se le paga. Un proveedor sin gastos
asociados es un dato muerto.

Al registrar un gasto, el campo de proveedor sugiere los que ya existen sin
obligar a elegirlos. Y si se le cambia el nombre a un proveedor, los gastos
anteriores se actualizan solos: si no, quedarían apuntando a un nombre que ya
no existe.

## Roles y permisos

Cinco roles, una sola matriz en `Dominio\Permisos`. El router, el menú y los
controladores leen de ahí, así que no puede pasar lo del sistema anterior: que
el enlace esté escondido pero la URL siga funcionando si alguien la escribe.

| Rol | Para quién |
|---|---|
| **Administrador** | Dueño o gerente. Ve el dinero y configura. |
| **Supervisor** | Coordina la operación y ve reportes. No configura ni toca usuarios. |
| **Cajero** | Cobra, abre y cierra su caja. No ve comisiones de nadie. |
| **Vendedor** | Vende y da seguimiento a sus clientes. |
| **Soporte** | Mantenimiento y diagnóstico. Ve todo, no mueve dinero. |

**Los permisos se nombran por lo que la persona HACE, no por la pantalla.**
`cobrar` es cobrar, exista o no una sección llamada Caja. Mover algo de lugar no
obliga a repensar los permisos.

**Un permiso que no existe se niega.** Escribirlo mal debe cerrar la puerta, no
abrirla.

### Dos decisiones que vale la pena defender

**Soporte ve todo y no mueve nada.** Quien entra a arreglar un problema necesita
mirar, no cobrar. Si además pudiera cobrar, cancelar pagos o asignar comisiones,
no habría forma de saber si un descuadre lo causó la empresa o quien vino a
ayudar.

**Cajero no ve comisiones.** No es desconfianza: el importe que cobra alguien más
no es asunto suyo, y tenerlo a la vista en la pantalla donde atiende clientes es
una fuga de información que nadie pidió.

## Mantenimiento

Solo para soporte. Dos cosas:

**Revisión de la base** · las seis señales que suelen estar detrás de un número
que no cuadra: ventas sin área, fechas desfasadas, cobrado mayor al total,
comisiones sin dueño, ventas sin cliente, cajas sin cerrar. Cada una dice qué
problema concreto causa.

**Secciones apagables** · Cobranza, Corte, Comisiones, Gastos, Reportes y
Recargas se pueden ocultar por empresa. Panel, Caja, Ventas, Clientes y Ajustes
no: sin ellas no se puede trabajar y el usuario pensaría que el sistema se rompió.

Soporte sigue viendo las secciones apagadas, porque para eso entra.

La configuración vive en `sistema_config` dentro de la base de cada empresa.
**Las credenciales no van ahí**: una contraseña en una tabla la ve cualquiera con
acceso a la base, y todo respaldo se la lleva.

## Migraciones · una base por empresa

LibertyFin crea una base de datos por empresa, y en el sistema anterior el
esquema vive como 36 `CREATE TABLE` escritos dentro de `registroEmpresa.php`.

Eso significa que cada columna nueva hay que agregarla a mano en TODAS las bases
existentes, y además acordarse de meterla en ese archivo para las que nazcan
después. Nadie se acuerda, y el síntoma es el peor posible: **una empresa nueva
estrena el sistema y le falta una columna**, con un error que ningún otro
cliente tiene.

`Servicio\Migraciones` lo resuelve. Cada migración tiene número y es
**idempotente**: correrla dos veces no hace nada la segunda. La versión aplicada
se guarda en `sistema_config` de cada empresa.

**Se ejecutan solas al entrar**, si la base está atrasada. En una base al día el
costo es una consulta.

Y en Mantenimiento hay una tabla con la versión de cada empresa y un botón para
poner al día las que nadie ha abierto todavía.

| Versión | Qué trae |
|---|---|
| 1 | Tabla de configuración por empresa |
| 2 | Área del cliente |
| 3 | Foto de perfil e imagen del servicio |
| 4 | Índices de rendimiento |

**Al agregar una migración:** escribe el método `vN`, súbelo a
`Migraciones::VERSION` y descríbelo en `DESCRIPCIONES`. Nada más.

Los índices se aplican desde la versión 1 de cada empresa a propósito: en una
base recién creada no se notan, pero ponerlos desde el día uno evita descubrir
tarde que el listado recorre la tabla entera.

## Mi cuenta

Cinco pestañas. Las últimas cuatro solo las ve quien puede `editar.empresa`: un
cajero ve únicamente su perfil, porque lo fiscal y el alta de comercio
comprometen a la empresa entera.

| Pestaña | Para qué |
|---|---|
| **Mi perfil** | Foto y contraseña. De cada quien, sin permiso. |
| **Plan** | Qué plan tiene, cuándo vence, y los tres pasos que faltan para cobrar de verdad |
| **Datos fiscales** | RFC, CP, razón social y régimen SAT. Lo que el SAT pide para timbrar. |
| **Cobrar con tarjeta** | El alta de comercio: titular, representante legal, identificación y datos bancarios |
| **Documentos** | Cinco obligatorios y dos opcionales, con su estado de revisión |

**El aviso de documentación aparece en todas las pestañas**, no solo en la suya.
Es lo que bloquea poder cobrar de verdad, y esconderlo en una sola hace que se
olvide.

**La CLABE se valida a 18 dígitos exactos.** Una mal escrita manda el dinero a
otra cuenta o rebota el alta semanas después, cuando ya nadie recuerda qué se
capturó.

**Subir un documento de nuevo lo devuelve a "pendiente".** El archivo cambió, así
que la revisión previa ya no vale.

Las dos tablas (`datos_pago_comercio` y `documentos_comercio`) ya existían en el
esquema del sistema anterior: se usan tal cual. Si una empresa no las trae, se
crean solas.

Los documentos aceptan **PDF además de imagen**, y el PDF se valida por su firma
—los cuatro bytes `%PDF`— no por la extensión. El tope es de 10 MB y no de 3:
un estado de cuenta escaneado pasa fácil de 3 MB, y rechazarlo obliga a la gente
a comprimirlo, que es justo donde se rinden.

## Revisión de documentos

Antes los documentos se subían y quedaban en "pendiente" para siempre, porque no
existía dónde revisarlos. Ahora Mantenimiento trae la bandeja con los de **todas
las empresas**, lo más viejo primero, con su antigüedad en horas o días.

Si soporte tuviera que entrar empresa por empresa a buscarlos, nadie los
revisaría. Por eso la bandeja es transversal.

**Rechazar sin motivo está prohibido**, y se pide al menos una frase. Lo que se
escriba es lo único que el negocio tiene para saber qué corregir: un "rechazado"
a secas garantiza que vuelvan a subir lo mismo.

## Facturación

La pantalla solo existe si hay credenciales de Facturapi, igual que Recargas con
Emida.

**Y antes de dejar timbrar, comprueba lo que falta:** datos fiscales completos y
documentación aprobada. Se revisa al abrir y no al enviar, porque descubrirlo con
la factura a medias es peor.

También distingue qué venta se puede facturar: una **sin cliente** no, porque el
CFDI necesita el RFC de quien recibe; una **sin liquidar** tampoco, porque el SAT
timbra el pago, no la promesa.

El timbrado en sí queda marcado y sin implementar hasta tener cuenta con la que
probarlo contra el SAT.

## Guía de primer uso

Señala la sección real del menú e ilustra al lado cómo se ve esa pantalla **con
datos**.

No lleva al usuario a cada sección de verdad a propósito: corre en el primer
ingreso, cuando la empresa no tiene una sola venta ni un servicio. Ventas sale
vacía, Reportes en ceros. **Enseñar pantallas vacías no enseña el sistema:
enseña que el sistema está vacío.**

Los pasos se filtran por permiso: a un cajero no se le explica Comisiones.

**La marca vive en `usuarios.guia_vista_en`, no en localStorage.** localStorage
es por navegador: dos personas de la misma empresa en la misma computadora
compartirían el estado y la segunda nunca la vería; y la misma persona desde su
celular la volvería a ver.

Es DATETIME y no un booleano: saber cuándo la vio permite responder "¿esta
empresa se atoró el primer día?" sin otra columna después.

Se puede volver a ver desde Mi cuenta.

## Personalización

**Color de la marca.** Se elige en Ajustes → Empresa y cambia el sistema entero.
Solo se guarda ese dato: los hovers, los fondos tenues y los anillos de foco se
calculan con `color-mix()`. Se ve al instante mientras se elige, antes de
guardar.

El color entra al HTML dentro de una etiqueta `<style>`, así que se valida contra
`/^#[0-9a-fA-F]{6}$/` antes de escribirlo. Cualquier otra cosa se descarta.

**Logotipo de la empresa**, **foto de perfil** y **imagen del servicio**. Las
tres pasan por `Servicio\Archivos`, con estas defensas en orden de importancia:

1. El tipo se deduce del **contenido** con `getimagesize()`, no del nombre ni del
   Content-Type: los dos los escribe quien sube el archivo.
2. El nombre se descarta entero y se genera uno aleatorio con la extensión del
   tipo real.
3. `public/assets/subidas/.htaccess` apaga PHP en esa carpeta. Si algo se cuela,
   queda inerte.
4. Tope de 3 MB y de 4000 píxeles por lado.

La personalización se lee al entrar y vive en la sesión: el armazón la pinta en
cada página, y consultarla cada vez sería una consulta más por petición para un
dato que casi nunca cambia.

`14_fotos.sql` agrega la columna de foto en usuarios. Hasta que se corra, el
sistema funciona igual y no guarda fotos de perfil. La del servicio no necesita
migración: `productos.imagen` ya existe.

## Notas de maqueta

**La app NO carga Bootstrap.** `libertyfin.css` se escribió como capa encima de
él y aquí se sostiene solo, así que la sección 23 del archivo trae lo que
Bootstrap aportaba: el reset de listas, la paginación en flex, el botón como
inline-flex.

Si alguien vuelve a meter Bootstrap, esa sección se puede recortar. Mientras no
esté, no se toca.

**La paginación es un parcial único**, `parciales/paginacion.php`. Diez filas por
página, definidas en `Peticion::POR_PAGINA`. Con veinticinco la tabla crecía
tanto que la columna de al lado quedaba corta.

**Columnas de igual altura**: `.lf-split` con `align-items:stretch` y las
tarjetas creciendo hasta llenar su columna.

**El marcador del panel dice "al corriente", no "salud".** Mide el porcentaje
cobrado castigado por la parte del saldo que lleva más de 30 días sin abono, y
la pantalla ahora lo explica debajo. El nombre viejo no decía nada.

**El precio del servicio es editable en Caja.** Antes se releía de la base "para
que nadie se cobre un servicio de $26,000 en $1", pero estos servicios se
cotizan por caso y el catálogo tiene varios en cero a propósito. Ahora se acepta
el precio capturado, se valida que no sea negativo ni absurdo, y queda
registrado con el usuario que lo puso: el control es el rastro, no el candado.
Un ticket que suma cero sí se rechaza, porque eso casi siempre es un dedazo.

**El área del cliente necesita `12_area_cliente.sql`.** Hasta que se corra, el
sistema funciona igual y simplemente no guarda ese campo: `ClienteRepo` pregunta
una vez si la columna existe. Preferible a reventar con "Unknown column" en una
instalación sin migrar.

## Integraciones

Siete servicios externos, todos con el mismo patrón: **las credenciales viven en
`config/integraciones.php`**, fuera de `public/` y fuera del repositorio.

| Servicio | Para qué |
|---|---|
| Emida | recargas telefónicas |
| Facturapi | timbrado CFDI |
| SPEI | ligas de pago por transferencia |
| Domiciliación | cargos recurrentes |
| PayPal | ligas de pago con tarjeta |
| SMTP | correos |
| cPanel | alta de bases para empresas nuevas |

**Una integración sin credenciales queda apagada sola.** Su sección no aparece en
el menú y sus rutas ni siquiera se registran: responden 404. No hay que
desactivar nada a mano, y una empresa que vende servicios legales no ve
"Recargas" en su menú.

La pestaña de Integraciones en Ajustes dice cuáles están listas y qué campo falta
en cada una. **Nunca muestra las credenciales**, ni siquiera parcialmente.

Por qué existe este archivo: en el sistema anterior las de Emida estaban escritas
dentro de `EmidaServicios/inicio.php`, en claro. Un archivo de código con
contraseñas dentro termina en el repositorio, en el respaldo y en el correo de
quien lo compartió. **Esas credenciales hay que rotarlas.**

## Recargas

La pantalla y el flujo están armados. Las llamadas a la API de Emida quedan
marcadas y sin implementar hasta que haya cuenta con la que probarlas: escribir
una integración SOAP a ciegas, sin poder ejecutarla una sola vez, produce código
que parece listo y no lo está.

Cuando llegue la cuenta, lo que falta es `RecargasControlador::consultar()` y
`::vender()`. Todo lo demás —pantalla, validación, ruta condicionada, registro
como venta— ya está.

## Lo que queda fuera, y por qué

**Promociones.** No es una tabla, son cinco: `promociones`,
`promociones_aplicables`, `promociones_sucursales`, `promociones_combo` y
`promociones_obtener`. Es un motor de promociones de tienda — combos, 2x1,
"compra X y llévate Y" — segmentado por categoría y sucursal.

Esta empresa vende servicios con precio negociado por cliente, y en toda la
migración no apareció una sola venta con promoción aplicada. Escribir en esas
cinco tablas sin entender el motor completo es la forma rápida de romper algo.
Queda pendiente de decidir si se usa.

Aviso sobre Emida: los proxies del sistema anterior llaman a
`http://104.248.179.142` **sin cifrar**. Si por ahí viajan credenciales o datos
de transacción, van en claro. Si se reactiva, que sea sobre HTTPS.

**Inventario.** Solo tiene sentido si llegan a vender producto físico. Hoy el
catálogo son servicios y el stock nunca se mueve.

**Facturación CFDI.** Necesita definir el PAC y el flujo de timbrado.

La capa SaaS (empresas, planes, suscripciones, distribuidores) es otro sistema
y va al final.

- Comisión por producto cuando la venta tiene varios

## Ingreso

Ya no depende del sistema anterior: tiene su propio login.

**El índice de usuarios.** El login viejo abría una conexión a la base de
CADA empresa buscando al usuario; con cinco empresas son cinco conexiones
por intento, incluso fallido. Aquí se guarda en la base principal una tabla
`usuarios_indice` que dice en qué empresa vive cada quien: la primera vez se
busca recorriendo, de ahí en adelante es una consulta. Si el índice queda
viejo o no se pudo crear, vuelve a recorrer y nadie se queda fuera.

**Mismo mensaje y mismo tiempo** si el usuario no existe o si la contraseña
está mal. Distinguirlos regala una lista de usuarios válidos, y la diferencia
de milisegundos también.

**Cinco intentos y quince minutos** de espera.

**La sesión se regenera al entrar**, y la cookie va `httponly` y `samesite=Lax`.

**La IP no echa a nadie.** En México cambia sola al saltar de wifi a datos;
se anota en el log y se sigue. El navegador sí tiene que ser el mismo.

**Un solo portero.** `public/index.php` decide en una línea quién pasa, en vez
de repetir la comprobación al inicio de cada archivo.

## El detalle de venta

Es donde aterriza todo lo que se corrigió a mano durante la migración:
pagos, gastos, IVA y comisiones en una sola pantalla.

Dos reglas que el sistema anterior no respetaba siempre:

**No se puede abonar más que el saldo.** `RegistrarPago::abonar()` lo impide.
Eso fue lo que dejó a Izol Nieto con un pago de $864.69 sobre una venta de
$745.42.

**Después de tocar un pago se resincronizan las comisiones, siempre.**
`SincronizarComisiones` recalcula desde el dominio, no desde una copia de la
fórmula. Cancelar un pago devuelve las comisiones a su sitio solo.

Los pagos cancelados no se borran: quedan tachados, con su motivo y la fecha.

## Las dos métricas de comisión

La pantalla de Comisiones separa dos cifras que no son la misma:

- **Total generado** · todo lo que la venta produjo, tenga dueño o no
- **Por pagar** · solo lo que se le puede depositar a una persona

La diferencia son los renglones a nombre de **POR ASIGNAR**: el Excel los
cuenta porque la venta sí los generó, pero nadie confirmó quién vendió.

Cuando aparece el nombre, se asigna desde la misma pantalla con un desplegable.
Solo cambia el dueño: el monto, la base y el devengado ya están calculados y
no se recalculan. Requiere rol de administrador.

## Seguridad de la caja

Tres cosas que el `caja.php` viejo no hacía:

**Los precios se releen de la base.** El formulario solo manda `{id, cantidad}`.
Si el precio viniera del navegador, cualquiera con las herramientas de
desarrollo podría cobrarse un servicio de $26,000 en $1.

**Token contra envíos falsificados.** Sin el token de sesión, el POST se rechaza.

**El anticipo no puede superar el total.** `Ticket::validarAnticipo()` lo impide.
Eso es exactamente lo que dejó a Izol Nieto con un pago de $864.69 sobre una
venta de $745.42.

## Nota sobre el puntaje de salud del panel

No es el porcentaje cobrado. Son dos cosas: cuánto se ha cobrado y qué tan
parado está lo que falta. Una empresa al 70% cobrado con todo fresco está
mejor que otra al 85% con la mitad sin moverse hace tres meses.

    salud = %cobrado x (1 - rancio x 0.35)

donde `rancio` es la fracción del saldo que lleva más de 30 días sin un solo
abono. El factor 0.35 es una decisión, no una verdad: castiga el saldo viejo
sin que domine la cifra. Si quieres otro peso, está en
`PanelControlador::salud()`, una sola línea.

## Cobros en línea · Paga de Todo

Son **cuatro formas de cobrar** y conviene tener claro que no se parecen:

| Forma | Quién la cobra | Entra al cajón | Cómo nos enteramos |
|---|---|---|---|
| Efectivo | el cajero, ahí mismo | sí | ya está cobrado |
| Tarjeta | el cliente, en una liga | no | consultando, y por aviso |
| SPEI | el cliente, desde su banco | no | solo por aviso |
| Efectivo en tienda | la tienda (OXXO y demás) | no | solo por aviso |

Las tres de en línea **registran la venta con saldo, no como pagada**. El abono
entra cuando el proveedor confirma, ni un minuto antes. Darla por cobrada al
generar la referencia haría que el corte mintiera todos los días: diría que
entraron $4,000 que nadie ha pagado.

### Cada forma es un servicio distinto

No se elige con un código: se elige con la dirección a la que se pega.

    /Service/GenerarLigaIndi            tarjeta
    /Service/GenerarClabeIndi           SPEI
    /Service/GenerarReferenciaIndi      efectivo en tienda

`PaymentTypes` **no** selecciona el método. `41` y `401` son la misma cosa
—"Contado"—: 401 en Sandbox y 41 en producción. Solo lo recibe la liga.

Y los contratos no son intercambiables:

- **SPEI no lleva monto.** Devuelve una CLABE que acepta lo que el cliente
  deposite. Mandarle `Amount` lo hace rechazar.
- **SPEI pide `Account`,** no `Reference`.
- **La referencia lleva 15 dígitos.** Con 13 contesta el código 22,
  "El formato de la referencia es incorrecto".

### Paga de Todo, no Paga la Escuela

Son dos plataformas del mismo proveedor y la documentación viene mezclada,
pero no son intercambiables:

|  | Paga la Escuela | Paga de Todo |
|---|---|---|
| host | pagalaescuela.mx | **pagadetodo.mx** |
| identificador | SchoolID | **BusinessID** |

Con el host de una y las credenciales de la otra contesta "El ID de la escuela
es obligatorio" aunque todo lo demás esté bien. Ajustes → Integraciones avisa
si la configuración quedó apuntando al lado equivocado.

### La integración tiene dos direcciones

**Nosotros → ellos.** Generar la liga, la CLABE o la referencia. Eso es
`Servicio\LigaPago`.

**Ellos → nosotros.** "Este cliente está pagando, ¿lo autorizo?", "ya pagó,
aquí está el folio", "hubo un problema, cancélalo". Eso es
`Controlador\PagosEntrantesControlador`, y sin esa mitad **SPEI y efectivo en
tienda no se aplican nunca**: el proveedor no tiene servicio para
consultarlos. Solo la tarjeta se puede preguntar
(`ConsultarEstatusLigaIndi`).

Las direcciones que hay que dar de alta en el panel del Sandbox, pestaña
**EndPoint**, con tu secreto pegado:

    Comercios (efectivo en tienda)
      Consultar referencia  https://TU-DOMINIO/pagadetodo/consulta-referencia?k=SECRETO
      Pagar referencia      https://TU-DOMINIO/pagadetodo/pago-referencia?k=SECRETO
      Cancelar pago         https://TU-DOMINIO/pagadetodo/cancela-pago?k=SECRETO

    Pago por SPEI
      Consultar clabe       https://TU-DOMINIO/pagadetodo/consulta-clabe?k=SECRETO
      Pagar clabe           https://TU-DOMINIO/pagadetodo/pago-clabe?k=SECRETO
      Cancelar pago         https://TU-DOMINIO/pagadetodo/cancela-pago?k=SECRETO

    Pago en línea (tarjeta)
      Pagar liga            https://TU-DOMINIO/pagadetodo/pago-liga?k=SECRETO

El secreto va en `config/integraciones.php` como `secreto_webhook`. Genéralo
con `php -r "echo bin2hex(random_bytes(24));"`. **Sin él los avisos se
rechazan**, porque esas direcciones no pueden pedir usuario y contraseña
—quien llama es un servidor del proveedor, sin sesión— y lo único que las
separa de cualquiera con un navegador es ese secreto.

### Sandbox y producción no se mezclan

Paga de Todo usa **la misma dirección** para los dos ambientes
(`pagadetodo.mx/Pagadetodo/Service/...`): lo que hace que un cobro sea de
prueba o de verdad son las credenciales. Por eso:

- **Con `'sandbox' => true` solo se usan `usuario_prueba` / `clave_prueba`**
  (y `integracion_id_prueba` / `negocio_id_prueba` si existen). Si faltan, o
  son las mismas de producción, no se genera ningún cobro. Antes caía a las
  de producción y generaba CLABEs reales con la pantalla diciendo "Modo de
  pruebas".
- **En tarjeta, Sandbox manda `PaymentTypes` 401** (`payment_types_prueba`) y
  producción 41 (`payment_types`). La documentación dice que 401 solo existe
  en el Sandbox.
- **Los avisos NO se rechazan por ambiente.** Se intentó (un secreto por
  ambiente y comparar con `lf_ligas_pago.pruebas`) y rebotó depósitos
  reales: Paga de Todo registra un solo juego de direcciones por cuenta
  (pestaña EndPoint), así que pagos reales y del emulador llegan por la
  misma puerta. `secreto_webhook_prueba` quedó solo como un segundo
  secreto aceptado.
- **En una liga de prueba no entra ningún pago.** El Sandbox del proveedor
  genera CLABEs que aceptan transferencias reales, con un aviso idéntico al
  del emulador. Como no se distinguen, en una liga con
  `lf_ligas_pago.pruebas = 1` se rechaza todo pago (código 40) y el
  proveedor le regresa el dinero al cliente. Para probar con el emulador:
  `aceptar_pagos_prueba => true`, y de vuelta a `false` al terminar.

### Las direcciones del sistema anterior

La cuenta de **producción** de Paga de Todo seguía avisando a las rutas del
sistema viejo (`/Service/ConsultaClabe`, `/Service/PagoClabe`,
`/Service/CancelaPago`, `/Service/PagoLinea`). Sin ellas el aviso caía al
login, el proveedor no recibía su JSON y **rebotaba el SPEI sin dejar nada en
el registro**.

Ahora se atienden, con el mismo código que `/pagadetodo/...`. Como no llevan
secreto, solo pasan las llamadas desde las IPs de Paga de Todo
(`PagosEntrantesControlador::IPS_PROVEEDOR`, más `ips_proveedor` en
`config/integraciones.php`). Las demás se rechazan y quedan en el registro
con su IP. Cuando el proveedor cambie las direcciones de producción a
`/pagadetodo/SECRETO/...`, estas rutas se pueden quitar.

`php app/bin/revisar-pagos.php` avisa si algo de esto quedó a medias.

### Tres reglas que no se negocian en los avisos

1. **Responder siempre HTTP 200** con el JSON que la documentación pide. Un
   500 hace que el proveedor dé el pago por fallido y lo cancele, con el
   cliente ya pagado y el ticket en la mano. Por eso `index.php` intercepta
   cualquier excepción en `/pagadetodo/*` y contesta el código 50.
2. **Idempotencia.** El proveedor reintenta cuando no le contestamos a tiempo.
   El mismo número de transacción no puede abonar dos veces; se guarda en
   `lf_ligas_pago.transaccion` y se revisa antes de abonar.
3. **Los montos viajan en centavos** y como entero. Salvo el aviso de la liga,
   que los manda en pesos y con comas ("1,156.00"). Es del proveedor, no un
   descuido nuestro.

### Cómo se sabe de qué empresa es un pago

Hay una base por empresa, pero las credenciales del proveedor son unas para
toda la instalación. Cuando llega "pagaron la referencia 7744330000…" no trae
sesión ni empresa: solo el número.

`lf_ruta_cobros`, en la base **principal**, apunta qué referencia vive en qué
base. Una fila por cobro, dos columnas, búsqueda directa. Sin eso habría que
abrir las treinta bases por cada aviso, y el proveedor espera respuesta en
segundos.

No guarda dinero: es un índice. Si se perdiera se reconstruye solo, porque
cuando una referencia no está apuntada se recorren las bases y se apunta al
encontrarla.

### Lo que se cobra es el saldo, no el monto del cobro

Entre que se genera la referencia y que el cliente llega al OXXO pueden pasar
tres días, y en ese rato pudo haber abonado en el mostrador. Por eso la
consulta devuelve el **saldo de la venta**, releído en ese momento, y no el
monto con el que se generó. Cobrarle el total otra vez obliga a devolverle.
