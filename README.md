# SABROOSKIPOS — Toma de pedidos sobre el TakePOS nativo de Dolibarr

Módulo custom para **Dolibarr** que añade un botón **"Toma de orden"** en la barra de
acciones del **TakePOS**. Al tocarlo abre un popup táctil donde el operador elige
**categoría → producto → sabor / topping / sirope** (con límites por producto), ve un
**resumen de la orden** ("Orden actual") y al terminar toca **"Finalizar orden"**, que
lo devuelve al ticket nativo de TakePOS para **cobrar e imprimir**.

**No modifica el core ni los archivos nativos de `takepos/`.** Todo entra por
*hooks* (`ActionButtons` + `addHtmlHeader`) y por endpoints propios del módulo. Esto
lo hace **portable** y **a prueba de actualizaciones** de Dolibarr.

---

## Requisitos

- **Dolibarr 19+** con el módulo **TakePOS** activado.
- El perfil que use el POS debe tener los permisos:
  - `TakePOS` → `run` (ya lo requiere el POS).
  - `Sabrooski POS` → `use` (se crea al activar el módulo).
- Productos y categorías cargados en Dolibarr (ver "Configuración del entorno").

---

## Instalación

### 1. Copiar la carpeta del módulo

Copia la carpeta completa `sabrooskipos/` dentro del directorio `custom/` de Dolibarr:

```
<dolibarr>/htdocs/custom/sabrooskipos/
```

> Verifica que exista y esté configurado `$dolibarr_main_document_root_alt` en
> `conf/conf.php` (apuntando al directorio `custom`). Si no tienes `custom`, créalo.

### 2. Activar el módulo

1. Entra a Dolibarr como **superadmin**.
2. Menú **Inicio → Configuración → Módulos**.
3. Busca **"Sabrooski POS"** y actívalo.
4. Al activar, Dolibarr crea automáticamente:
   - Los **extrafields** de producto: `max_sabores`, `max_toppings_incluidos`, `max_sirope`.
   - El permiso `Sabrooski POS / use`.
   - La constante de hooks `MAIN_MODULE_SABROOSKIPOS_HOOKS = ["takeposfrontend"]`.

> ⚠️ Si el botón "Toma de orden" no aparece, la constante de hooks no se generó.
> Solución: **desactiva y vuelve a activar** el módulo, o inserta la constante a mano:
>
> ```sql
> INSERT INTO llx_const (name, type, value, note, visible, entity)
> VALUES ('MAIN_MODULE_SABROOSKIPOS_HOOKS', 'chaine', '["takeposfrontend"]', '', '0', 1)
> ON DUPLICATE KEY UPDATE value = VALUES(value);
> ```

### 3. Configurar las categorías (IMPORTANTE)

Los IDs de categoría **no** van fijos en el módulo (cada instalación tiene los suyos).
Configúralos en la página de ajustes del módulo:

**Inicio → Configuración → Módulos → Sabrooski POS → Configurar** (Setup).

Ahí elegí:

- **Categoría de sabores** (`SABROOSKIPOS_CATEGORY_FLAVORS`)
- **Categoría de toppings** (`SABROOSKIPOS_CATEGORY_TOPPINGS`)
- **Categoría de siropes** (`SABROOSKIPOS_CATEGORY_SYRUPS`)

También verifica que **`TAKEPOS_ROOT_CATEGORY_ID`** (categoría raíz del TakePOS) esté
bien configurada, porque el popup lista las categorías hijas de esa raíz.

### 4. Asignar el permiso a los cajeros

En **Configuración → Usuarios/Grupos**, asigna al grupo del punto de venta el permiso:

- **Sabrooski POS → use**

---

## Configuración del entorno (datos reales)

El módulo **no trae datos de ejemplo**: lee la BD real de Dolibarr. Para que la
"toma de orden" funcione, la instalación debe tener:

- La **categoría raíz** del TakePOS configurada con sus categorías hijas (pestañas del popup).
- Los **productos base** (con `tosell = 1`) en esas categorías.
- Los productos de **sabor / topping / sirope** en sus respectivas categorías (las 3 de arriba).
- Los **extrafields** `max_sabores`, `max_toppings_incluidos`, `max_sirope` definidos
  en los productos base (se crean al activar el módulo).

Ejemplo de valores en los productos base:

| Extrafield | Descripción | Valor típico |
|---|---|---|
| `max_sabores` | Cuántos sabores se pueden elegir | `2` |
| `max_toppings_incluidos` | Toppings incluidos en el precio (los demás se cobran aparte) | `2` |
| `max_sirope` | Siropes incluidos | `1` |

Si un producto no tiene estos valores, el módulo asume `1 sabor / 0 toppings / 0 sirope`.

---

## Flujo de uso

1. En **TakePOS**, el botón **"Toma de orden"** aparece junto a Cliente/Descuento/etc.
2. Se abre el popup con las **categorías** (pestañas) y el **grid de productos**.
3. Al tocar un producto → **modal** con sabores, toppings, sirope, cantidad y adicionales.
4. **"Agregar a la orden"** añade el producto (con su detalle como descripción de la
   línea: `Sabor: X · Topping: Y · Sirope: Z`) y vuelve a la rejilla para seguir.
5. A la derecha, el panel **"Orden actual"** muestra el resumen: líneas, artículos,
   total, **Quitar** por línea y **Vaciar**.
6. **"Finalizar orden"** cierra el popup y recarga el ticket de TakePOS, listo para
   **cobrar e imprimir**.

---

## Estructura del módulo

```
custom/sabrooskipos/
├── core/modules/modSabrooskiPOS.class.php   ← descriptor del módulo (extrafields, permiso, hooks)
├── class/actions_sabrooskipos.class.php     ← hook ActionButtons (botón) + addHtmlHeader (JS del popup)
├── picker/
│   ├── index.php                            ← contenedor del popup (inyecta window.PICKER)
│   └── api.php                              ← getData / getProduct / getInvoice / getCart
├── ajax/ajax.php                            ← addItem / removeLine (líneas del carrito)
├── js/picker.js                             ← lógica del popup (categorías, modal, resumen)
├── css/picker.css                           ← estilos del popup
├── langs/es_ES/sabrooskipos.lang            ← traducciones ES
├── admin/setup.php                          ← configuración de categorías
└── README.md
```

---

## Portabilidad y actualizaciones de Dolibarr

- **No toca el core**: `takepos/index.php` y los demás archivos nativos quedan intactos.
- **Sin rutas absolutas**: usa `DOL_URL_ROOT`, `MAIN_DB_PREFIX` y rutas relativas.
- **Sin IDs de categoría fijos**: todo se lee de constantes configurables.
- **Sin datos de ejemplo**: lee la BD real.
- La única dependencia del núcleo son los hooks `ActionButtons` (en el contexto
  `takeposfrontend`) y `addHtmlHeader`. En una actualización menor de Dolibarr
  normalmente **no cambian**; si cambiaran, es un ajuste de 1 línea en
  `class/actions_sabrooskipos.class.php`.

---

## Solución de problemas

| Síntoma | Causa | Solución |
|---|---|---|
| No aparece el botón "Toma de orden" | Falta la constante de hooks | Desactivar/reactivar el módulo o insertar `MAIN_MODULE_SABROOSKIPOS_HOOKS` en BD |
| El popup sale vacío / sin pestañas | `TAKEPOS_ROOT_CATEGORY_ID` mal configurada | Configurar la categoría raíz en Setup |
| No se ven sabores/toppings/siropes | Categorías `SABROOSKIPOS_CATEGORY_*` vacías o sin productos | Configurar las 3 categorías en Setup |
| El popup muestra una versión vieja | Caché del navegador | Recargar con `Ctrl+F5` (el módulo ya fuerza `?v=...`) |
| Error TCPDF al cobrar | `$dolibarr_main_data_root` relativo en `conf.php` | Poner la ruta absoluta (o vacía) en `conf/conf.php` |
