# AVANCE — Módulo SabrooskiPOS: toma de pedidos sobre TakePOS nativo

> **Fecha:** 2026-08-31 / 2026-09-05
> **Estado:** funcional. La "toma de orden" está completa y validada en local:
> botón en la barra de TakePOS → popup con categorías/productos reales → modal de
> sabor/topping/sirope → resumen de la orden (carrito) → "Finalizar orden" cierra y
> vuelve al ticket para cobrar. El módulo es portable y a prueba de cambios.
>
> Para la instalación en producción ver el **README.md** del módulo.

---

## 1. Objetivo del proyecto

Personalizar el POS de la heladería Sabrooski (Dolibarr **TakePOS**) con una
pantalla de **"Toma de orden"** que permita:
- Ver las **categorías reales** cargadas en la BD (pestañas, SIN el botón "Todos").
- Al tocar un producto, elegir **sabor / topping / sirope** (con límites por producto).
- Agregar el producto personalizado al ticket nativo de TakePOS (el operador luego
  solo toca "Cobrar").

Restricción importante: **NO reescribir TakePOS** — solo se le engancha un botón y
un popup por hooks. El `takepos/index.php` debe quedar **100% intacto** (idéntico al
backup).

---

## 2. Decisión clave de arquitectura (aprendizaje de todo el día)

**Los productos NO se re-obtienen en PHP.** El POS nativo ya los pinta con su endpoint:

```
takepos/ajax/ajax.php?action=getProducts&token=...&thirdpartyid=<socid>&category=<catid>&tosell=1&limit=100&offset=0
```

Ese endpoint devuelve un array de objetos Product con `id`, `label`, `ref`,
`price_ttc_formated`, `price_formated`, `description`, y los extrafields
(`array_options.options_max_sabores`, `options_max_toppings_incluidos`,
`options_max_sirope`).

Por eso `picker/api.php` **NO lista productos base** (eso sería duplicar el POS).
`api.php` SOLO devuelve:
- **`getData`** → categorías hijas de la raíz + listas de sabores/toppings/siropes.
- **`getProduct`** → detalle de un producto para el modal (extrafields + precio).
- **`getInvoice`** → id de la factura provisional por ref, para anotar el detalle.

El JS (`picker.js`) pide los productos al **endpoint nativo** por categoría.

---

## 3. Estructura actual del módulo

```
custom/sabrooskipos/
├── core/modules/modSabrooskiPOS.class.php   ← descriptor v1.3 (hooks takeposfrontend)
├── class/actions_sabrooskipos.class.php     ← hook ActionButtons + addHtmlHeader
├── picker/
│   ├── index.php                            ← popup (contenedor), inyecta window.PICKER
│   └── api.php                              ← getData / getProduct / getInvoice
├── js/picker.js                             ← lógica: categorías, endpoint nativo, modal
├── css/picker.css                           ← estilos del popup (paleta Sabrooski)
├── langs/es_ES/sabrooskipos.lang            ← textos (TomaDeOrden, etc.)
├── admin/setup.php                          ← configuración de categorías (conservado)
└── sabrooskiposindex.php                    ← ya NO es el POS: redirige a /takepos
```

`picker.js` y `picker.css` usan prefijo `pk-` (linhas pk-cat-btn, pk-card, pk-chip,
pk-section-label, etc.) para no chocar con el resto.

---

## 4. Datos reales verificados en la BD (llx_const)

| Constante | Valor local | Significado |
|---|---|---|
| `TAKEPOS_ROOT_CATEGORY_ID` | 1 | Categoría raíz de productos del TakePOS |
| `SABROOSKIPOS_CATEGORY_FLAVORS` | *configurable* | Categoría de sabores (Setup → Sabrooski POS) |
| `SABROOSKIPOS_CATEGORY_TOPPINGS` | *configurable* | Categoría de toppings |
| `SABROOSKIPOS_CATEGORY_SYRUPS` | *configurable* | Categoría de siropes |
| `MAIN_MODULE_SABROOSKIPOS_HOOKS` | `["takeposfrontend"]` | Hook activo (se crea al ACTIVAR el módulo) |
| `MAIN_MODULE_SABROOSKIPOS` | 1 | Módulo activo |

> **Nota de portabilidad:** los IDs de categoría del módulo se crean VACÍOS al activar.
> En esta BD se configuraron en Setup como `5` (sabores), `6` (toppings), `7` (siropes),
> pero cada instalación tiene sus propios IDs. Por eso **no** van fijos en el descriptor.
>
> La constante de hooks `MAIN_MODULE_SABROOSKIPOS_HOOKS` la genera Dolibarr al activar el
> módulo (no al editar archivos). Si el botón no aparece, **desactivar/reactivar** el
> módulo "Sabrooski POS" (o insertarla con el SQL indicado abajo).
>
> ```sql
> INSERT INTO llx_const (name, type, value, note, visible, entity)
> VALUES ('MAIN_MODULE_SABROOSKIPOS_HOOKS', 'chaine', '["takeposfrontend"]', '', '0', 1)
> ON DUPLICATE KEY UPDATE value = VALUES(value);
> ```

### Categorías reales de producto (hijas de la raíz 1 → son las pestañas)
| id | nombre | productos |
|---|---|---|
| 2 | Conos | 4 |
| 3 | Vasos | 1 |
| 4 | Sundaes | 1 |

### Sabores / toppings / siropes (referencia local)
- Sabores (cat 5): 8 productos
- Toppings (cat 6): 5 productos
- Siropes (cat 7): 4 productos

Productos base (tosell=1): Barquifull, Barquillooski, Cestooski, Maxifull,
Sundooski, Tinakids.

---

## 5. Aprendizajes técnicos críticos (para no repetir errores)

1. **`DOL_URL_ROOT` es RELATIVO en esta instalación** (`/sabrooski`, sin `http://`).
   Al pasar `DOL_URL_ROOT.'/custom/...'` a `top_htmlhead`, este lo re-prefija con
   `dol_buildpath` → genera `/sabrooski/sabrooski/custom/...` (doble prefijo → 404).
   - **Solución:** pasar rutas RELATIVAS (`/sabrooskipos/js/picker.js`) a `top_htmlhead`.
     `dol_buildpath` antepone `DOL_URL_ROOT` y el `/custom` si el archivo vive en custom/.
   - Solo el hook `addHtmlHeader` usa `DOL_URL_ROOT.'/custom/...'` porque NO pasa por
     `top_htmlhead` (el navegador resuelve la ruta y queda bien).

2. **Los módulos custom se sirven bajo `/custom/sabrooskipos/...`** (confirmado con curl:
   `http://localhost/sabrooski/custom/sabrooskipos/js/picker.js`=200; sin `/custom` =404).

3. **Hook real de botones de TakePOS:** `ActionButtons` en contexto **`takeposfrontend`**
   (NO `addMoreActionsButtons` como decía el .md original). El botón se agrega devolviendo
   `$this->results[] = array( array('title'=>..., 'action'=>...) )` (array de arrays),
   y devolviendo `0` para conservar los botones nativos.

4. **El JS del popup se carga al FINAL del `<body>`** (no en el `<head>`), porque
   `window.PICKER` se define en el body. `picker.js` lee `window.PICKER` en `init()`
   (que corre cuando ya está definido).

5. **`Categorie::getObjectsInCateg(..., '(o.tosell:=:1)')`** dio problemas en el picker
   (devolvía vacío). Por eso `api.php` usa **SQL directo** (join `categorie_product` +
   `product` + `product_extrafields`) para sabores/toppings y para el modal. Para los
   productos BASE se usa el endpoint nativo.

6. **`invoiceid` puede ser 0** en una venta nueva. El flujo de "agregar" hace `addline`
   → `api.php?action=getInvoice` (resuelve id por ref `(PROV-POS<term>-<place>)`) →
   `getInvoice` del ajax nativo → `addnote`. Así el detalle sabor/topping sienta bien.

---

## 6. Endpoints nativos reutilizados (NO tocar)

- `takepos/ajax/ajax.php?action=getProducts` → productos de una categoría (listado).
- `takepos/ajax/ajax.php?action=getInvoice&id=X` → factura provisional JSON (para el id
  de la última línea).
- `takepos/invoice.php?action=addline&place=..&invoiceid=..&idproduct=..&qty=..` →
  agrega producto al ticket.
- `takepos/invoice.php?action=addnote` → anota detalle en la línea (POST idline, addnote).
- `sabrooskipos/picker/api.php?action=getCart` → resumen de la orden (líneas + totales).
- `sabrooskipos/ajax/ajax.php?action=removeLine` → quitar una línea del resumen.

---

## 7. Estado final y flujo de la toma de orden

El popup "Toma de orden" funciona así:

1. Se abre desde el botón "Toma de orden" en la barra de acciones de TakePOS.
2. **Columna izquierda**: categorías (pestañas) + grid de productos reales (mismo
   endpoint nativo que usa el POS).
3. Al tocar un producto → **modal** de sabores/toppings/siropes (con límites por
   extrafield) + cantidad + adicionales.
4. **"Agregar a la orden"** añade el producto (con su detalle en la descripción) y
   vuelve a la rejilla sin cerrar, para seguir agregando.
5. **Columna derecha → resumen "Orden actual"**: líneas (`cantidad × producto`,
   detalle, total), contador de artículos, total, botón **Vaciar** y **Quitar** por
   línea. Lee la factura provisional del TakePOS (fuente de verdad).
6. **"Finalizar orden"** cierra el popup y recarga el ticket de TakePOS para cobrar
   e imprimir.

La orden entera cae en la misma factura provisional `(PROV-POS<term>-<place>)`, así
que al finalizar el ticket nativo ya tiene todo armado.

---

## 8. Referencias

- Planteamiento original: `takepos/sabrooski-pos-modulo-dolibarr.md`
- Estructura/diseño: `takepos/sabrooski-pos-estructura.md`
- Maqueta visual: `takepos/sabrooski-pos-demo.html`
- Backup del POS nativo: `C:\laragon\www\sabrooski_backup\takepos_20260829\`

---

## 9. Ajuste al menú real (v1.5)

El menú definitivo de Sabrooski queda documentado en `sabrooski_productos.json`
(derivado de `productos sabrooski.xlsx`). El módulo se alineó a él:

- **Categorías de venta:** Servido en camión, Especial domo, Empaquetado y Adicional
  (esta última oculta como pestaña vía `SABROOSKIPOS_HIDDEN_CATEGORIES`).
- **Sabores:** dos listas — `mix` (6, servidos/especiales) y `bandejas` (14, empaquetado) —
  elegidas según el extrafield `grupo_sabores` del producto.
- **Adicional:** un único producto a precio plano ($0.25) con stepper en el modal; cada
  unidad se agrega como una línea de venta propia.
- **Gramos por porción:** extrafield `gramos_porcion`, informativo en la descripción/ticket.
- **Toppings/sirope:** ya no son seleccionables (ingredientes fijos de la receta).

Piezas modificadas:
- `core/modules/modSabrooskiPOS.class.php` — v1.5; extrafields `grupo_sabores` y
  `gramos_porcion`; constantes `SABROOSKIPOS_CATEGORY_FLAVORS_BANDEJAS` y
  `SABROOSKIPOS_PRODUCT_ADICIONAL`.
- `admin/setup.php` — configuración por terminal de sabores mix/bandejas + Producto Adicional.
- `admin/cargar_catalogo.php` — carga idempotente del catálogo desde el JSON (nueva).
- `picker/api.php` y `js/picker.js` — modal según grupo de sabores + stepper Adicional.
- `lib/sabrooskipos.lib.php` — pestaña "Cargar catálogo" en el Setup.

---

## 10. Continuación: carga del catálogo + correcciones (2026-10-08)

Se ejecutó la carga real del catálogo y se corrigieron bugs encontrados:

- **IVA 16%:** `SABROOSKIPOS_TVA_TX` (nueva constante, default `16`). Los `precio_usd`
  del JSON se guardan como **precio final TTC** con IVA 16% (verificado: `price_ttc`
  = precio del JSON, `tva_tx` = 16). Configurable en **Setup → (pestaña global)**.
- **Carga ejecutada** (idempotente): categorías nuevas `8 Servido en camión`,
  `9 Especial domo`, `10 Empaquetado`, `11 Adicional`, `12 Sabores mix`,
  `13 Sabores bandejas`; **36 productos** (`16 PV-*` + `6 SM-*` + `14 SB-*`).
- **Bug corregido — definiciones de extrafields:** las llamadas a `addExtraField()`
  estaban desalineadas (faltaba el argumento `$entity=''`), por lo que
  `grupo_sabores` y `gramos_porcion` creaban la columna pero **no** la definición en
  `llx_extrafields` (y `insertExtraFields()` los descartaba). Corregido en
  `lib/sabrooskipos.lib.php` y en `core/modules/modSabrooskiPOS.class.php`.
- **`EnsureProduct` ahora actualiza extrafields de productos existentes** al re-ejecutar,
  para que la carga converja (antes solo creaba y saltaba los existentes).
- **Runner CLI nuevo:** `test/cargar_catalogo_cli.php` (usa la MISMA función
  `sabrooskiposLoadCatalogo()` que la página admin). Permite cargar/verificar sin navegador:
  `php custom/sabrooskipos/test/cargar_catalogo_cli.php`.
- **Constantes fijadas:** `SABROOSKIPOS_CATEGORY_FLAVORS=12`,
  `SABROOSKIPOS_CATEGORY_FLAVORS_BANDEJAS=13`, `SABROOSKIPOS_PRODUCT_ADICIONAL=59`,
  `SABROOSKIPOS_HIDDEN_CATEGORIES=11,2,3,4` (oculta Adicional + legacy Conos/Vasos/Sundaes).

**Pendiente:** prueba funcional en TakePOS (popup) y decisión sobre las categorías legacy
2/3/4, que siguen visibles en el **grid nativo** del TakePOS (el popup ya las oculta).

---

## 11. Adicionales como lista + fixes del popup (2026-10-08)

- **Adicionales = lista seleccionable:** la sección "Adicionales" del modal ahora
  lista **varios** adicionales, cada uno con `+ / −` y su propio precio; se suman como
  líneas aparte.
  - Fuente (sin duplicados): categorías **Topping** (`SABROOSKIPOS_CATEGORY_TOPPINGS`),
    **Sirope** (`SABROOSKIPOS_CATEGORY_SYRUPS`) y **Adicional**
    (`SABROOSKIPOS_CATEGORY_ADICIONAL`). Hoy: 5 toppings + 4 siropes + 1 genérico = 10.
  - Se reincorporaron los campos Toppings/Sirope en el Setup por terminal.
  - La lista se muestra en **dos columnas** (una sola en pantallas angostas ≤520px).
- **Total del modal:** `lineTotalPreview` ahora suma producto × cantidad **+ los
  adicionales seleccionados** (antes solo contaba el producto base).
- **Fix "Unexpected token '<'":** al renombrar `pickerGetAdicional()` quedaron llamadas
  al nombre viejo (fatal PHP → HTML en vez de JSON). Corregido:
  `pickerAdicionalOptions()` devuelve la lista en `adicionals`.
- **Cache-buster por hash de contenido** (md5) en `picker.js`/`picker.css`
  (`picker/index.php`), para no servir versiones intermedias cacheadas.
- **Categorías legacy 2/3/4 eliminadas**; 4 productos viejos usados en documentos
  (BARQ-110, BARQ-160, CEST-120, SUND-170) quedaron sin categoría (no aparecen en el POS).

**Pendiente:** prueba funcional del popup en TakePOS.
