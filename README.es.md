# Paymos para CS-Cart

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Complemento oficial de Paymos para CS-Cart, Multi-Vendor y Ultimate. Registra un procesador de
pagos llamado Paymos: el cliente realiza el pedido, es redirigido a la página de pago alojada de
Paymos y paga en una stablecoin desde su propia billetera. El estado del pedido avanza mediante un
webhook firmado en cuanto la transferencia se confirma en la cadena.

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Vídeo demostrativo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- CS-Cart, Multi-Vendor o Ultimate de la línea 4.x. `addon.xml` declara el esquema de complemento
  `3.0` y se autoinstala en las ediciones Multi-Vendor y Ultimate
- PHP 7.4 o superior con las extensiones `curl`, `hash`, `json` y `openssl` — el mínimo que declara
  el SDK de Paymos para PHP incluido
- Una tienda sobre HTTPS; el flujo de conexión rechaza cualquier otra opción
- `crypt_key` de CS-Cart configurado, ya que las credenciales guardadas derivan su clave de ahí
- Una cuenta de Paymos con el proyecto al que debe facturar la tienda seleccionado en el panel

## Instalación

Descarga `paymos-cscart-<version>.zip` desde
[Releases](https://github.com/Paymos-labs/cscart/releases/latest) o el paquete desde el panel
**CMS integration** en el panel de Paymos, y súbelo en **Add-ons → Manage add-ons → + → Local**.

Del archivo salen tres árboles de directorios y los tres importan:

- `app/addons/paymos` — el complemento en sí, con el SDK de Paymos para PHP incluido dentro
- `design/backend/…` — la plantilla de ajustes del procesador
- `var/langs/…` — los catálogos de idioma. CS-Cart resuelve los archivos `.po` de un complemento
  desde la **raíz de la tienda**, nunca desde dentro del complemento, así que una subida que pierda
  `var/` mostrará cada etiqueta de administración como su clave sin traducir

La instalación crea `paymos_config`, `paymos_invoices` y `paymos_events`, e inserta `Paymos` en la
tabla de procesadores de pago. El archivo es idéntico para todos los comercios y no contiene clave
API, secreto API, ID de proyecto, secreto de webhook, token OAuth ni código de dispositivo.

## Conexión

1. Ten abierto el proyecto correcto en el panel de Paymos antes de empezar. El complemento toma ese
   proyecto y nunca te pide elegir otro.
2. En el panel de administración ve a **Administration → Payment methods**, añade o edita un método
   de pago y selecciona **Paymos** como procesador.
3. En los ajustes del procesador, pulsa **Connect Paymos** y aprueba la URL de la tienda y el
   proyecto en la pestaña que se abre. Si el navegador bloquea esa pestaña, el enlace de aprobación
   y el código aparecen junto al botón.
4. Guarda el método de pago.

Sandbox y Live quedan aprovisionados con esa única aprobación. Se reutiliza tu única clave Payment
activa —o se crea una si no existe— y se registra un webhook Invoice en:

```text
https://your-store.example/index.php?dispatch=payment_notification.notify&payment=paymos&mode=webhook
```

`mode=webhook` forma parte de la dirección, no es decoración: el complemento lo lee para distinguir
un callback de Paymos de un despacho normal de notificación de pago, ya que el propio `$mode` de
CS-Cart indica `notify` en esa ruta. Un webhook existente solo se reutiliza cuando coinciden URL de
callback, categoría y proyecto.

Las credenciales se escriben en `paymos_config` como un sobre AES-256-GCM con clave derivada de
`crypt_key` de la tienda y nunca se vuelven a imprimir en la página de administración.

## Estados de pedido

Cada estado se asigna al código de estado de CS-Cart que elijas en los ajustes del procesador. Los
valores predeterminados son los códigos integrados:

| Ajuste del procesador | Predeterminado | Cuándo se aplica |
|---|---|---|
| Order status while awaiting payment | `O` — Open | Se crea la factura y se envía al cliente a la página de pago alojada |
| Order status while the payment is confirming on-chain | `O` — Open | `invoice.confirming` |
| Order status when paid | `P` — Processed | `invoice.paid`, `invoice.paid_over` |
| Order status when the payment is underpaid | `F` — Failed | `invoice.underpaid` |
| Order status when the invoice is cancelled or expired | `D` — Declined | `invoice.expired`, `invoice.cancelled` |

Se acepta cualquier código definido por la tienda, incluidos estados que hayas creado tú mismo. El
ID de transacción guardado en el pedido es el hash en cadena de la transferencia confirmada, o el
ID de factura de Paymos cuando la carga útil no lleva transferencias — lo que siempre ocurre en
Sandbox.

Dos cosas no sucederán nunca. Un pedido que ya está en tu estado pagado configurado, o en el estado
integrado `C` (Complete) al que los comercios suelen avanzar pedidos pagados, no retrocede por un
`confirming`, `underpaid`, `expired` o `cancelled` tardío: el evento se omite y, si el registro de
depuración está activo, se anota. Además, un pedido cuyo total o moneda ya no coincide con la
factura nunca se marca como pagado.

## Multi-Vendor y Ultimate

Ambas ediciones reciben el mismo complemento y este se comporta igual en ambas: un procesador de
pagos a nivel de tienda cuya conexión pertenece a la tienda y cuyos ajustes pertenecen al método de
pago. Una tienda puede tener varios métodos de Paymos funcionando en paralelo con distintos modos o
códigos de estado.

Todos los pagos se liquidan en el saldo de comercio del proyecto de Paymos conectado. No existen
pagos divididos ni cuentas de subcomercio, así que el reparto de ingresos de un pedido entre
vendedores sigue gestionándose dentro de la propia contabilidad de vendedores de CS-Cart.

## Probar antes de pasar a producción

1. Configura **Mode** en Sandbox en el método de pago y realiza un pedido desde la tienda.
2. Abre esa factura en el panel de Paymos mientras el panel esté en Sandbox y usa **Pay Full**,
   **Pay 50%**, **Pay 150%** o **Cancel**. Emiten los mismos eventos que un pago real; nada toca
   una blockchain.
3. Comprueba el estado del pedido y el bloque de información de pago dentro del pedido.
4. Cambia **Mode** a Live. Ambos conjuntos de credenciales surgieron de la misma aprobación, así
   que no hace falta reconectar ni configurar nada por segunda vez.

## Webhooks

Las entregas son peticiones `POST` al despacho anterior. La cabecera `X-Webhook-Signature` es la
frontera de confianza — HMAC-SHA256 hexadecimal sobre `{timestamp}.{body}` — y una firma incorrecta
o una marca de tiempo caducada responde `401` sin cargar ningún pedido. Un `event_id` repetido
responde `200` y no hace nada. Antes de aplicar cualquier evento final, la factura se vuelve a leer
desde la Merchant API y se compara con la instantánea en `paymos_invoices`: entorno, proyecto, ID
de pedido externo e ID de factura deben coincidir todos.

Las actualizaciones de estado de facturas no tienen cron local de conciliación, por lo que el
reenvío de webhooks es la vía de recuperación para facturas. Un ciclo realiza 11 intentos en unas
16 horas, lo que absorbe una tienda brevemente caída. Un evento de factura que sobrepase esa
escalera puede reenviarse manualmente desde el panel de Paymos.

## Reembolsos

El complemento admite reembolsos nativos mediante la Merchant API de Paymos (`/v1/refunds`) en dos
modos:

- `full` — reembolsa el importe íntegro de la factura pagada;
- `overpayment` — reembolsa únicamente el exceso recibido en un pedido `invoice.paid_over`.

Los fondos regresan exclusivamente a la dirección de billetera `first_sender` verificada que Paymos
capturó cuando se pagó la factura. No se introduce manualmente ninguna dirección destinataria y no
se admiten importes parciales arbitrarios.

Para gestionar reembolsos en el panel de administración de CS-Cart, abre
`dispatch=paymos.refunds&order_id=<order_id>` (**Paymos refunds**, requiere permiso para editar
pedidos). La pantalla muestra los importes reembolsables y la dirección `first_sender` verificada,
ofrece las acciones **Refund in full** (`full`) y **Refund overpayment** (`overpayment`)
(`paymos.refund`) y permite consultar operaciones en curso (`paymos.refund_reconcile`). Los
reembolsos pendientes también se concilian mediante webhooks firmados y el script cron de consola
`php app/addons/paymos/cron/refunds.php`, actualizando el pedido solo cuando Paymos confirma
`completed`. El modo Sandbox permite simular resultados finales (`completed`, `failed`, `cancelled`).

## Resolución de problemas

**Las etiquetas de administración aparecen como claves sin traducir** como `paymos.mode`. El árbol
`var/langs` no sobrevivió a la subida. Vuelve a subir completo el archivo de lanzamiento; los
catálogos de idioma viven en la raíz de la tienda, no dentro del directorio del complemento.

**Los pedidos nunca salen del estado de espera de pago.** Comprueba que el callback registrado aún
incluye `mode=webhook`. Sin él, el complemento rechaza cada entrega como modo de callback no válido,
el servidor sigue reintentando y nada se completa. Volver a conectar registra de nuevo la dirección
correcta.

**Connect se niega a iniciarse.** La URL de la tienda es lo que se aprueba y contra lo que se
registra el webhook, y debe ser `https://`. Corrige la URL de la tienda y vuelve a conectar.

**Todo deja de funcionar tras cambiar la configuración.** El sobre de credenciales usa una clave
derivada de `crypt_key`. Cambiarla —o restaurar la base de datos en una tienda con otra distinta—
vuelve ilegible el sobre; pulsa **Connect Paymos** de nuevo para emitir uno nuevo.

## Enlaces

- Documentación: [paymos.io/es/docs/cms-cscart](https://paymos.io/es/docs/cms-cscart)
- Código fuente y lanzamientos: [Paymos-labs/cscart](https://github.com/Paymos-labs/cscart)
- Soporte: [support@paymos.io](mailto:support@paymos.io)
