# CHANGELOG MODULE SABROOSKIPOS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.15

- **Mix en gramos:** Carga del camión (existencia inicial, reposición y entrada a Principal),
  Cierre, Insumos del móvil e Inventario de ventas muestran y piden el mix en **gramos** (antes
  en kg con factor 1000). No cambia ningún dato: el mix siempre se guardó en gramos y la receta,
  el traspaso, las regalías/dañadas y los costos siguen igual.
- Validación del mix: solo números enteros, sin punto ni coma (Dolibarr lee "16.250" como 16,25);
  en una carga, mínimo 100 g y máximo 50.000 g por sabor (Principal: solo el mínimo). Si algo no
  cuadra no se registra nada y se conserva lo escrito para corregirlo.
- Import del catálogo: una categoría que ya existe en otro nivel se mueve al padre del paquete.
  Se retiró la pestaña "Cargar catálogo" (redirige a Migración).

## 1.14

- **Traspaso de turno:** en Cierre, "Registrar cierre y traspasar a …" pasa todo lo que queda
  al camión del turno siguiente (AM → PM el mismo día, PM → AM del día siguiente) como
  existencia inicial. En Reposición, columna "Salió en el turno anterior" y botón para
  reponer solo eso. Los reportes cuentan lo traspasado como existencia al cierre.
- Insumos del móvil: la existencia se separa en **Existencia anterior** y **Carga inicial**
  (existencia inicial o traspaso), además de Reposición: la carga del día ya no queda escondida.
- Inventario de ventas: el mix en **kg** (como en Carga, Cierre e Insumos); cantidades con 3
  decimales cuando hacen falta (0,172 kg), también en el Excel; sin "-0".

## 1.13

- **Topping y sirope a granel:** un insumo en gramos para cada uno (`INS-TOPG`, `INS-SIRG`), sin
  variedades. Bajan al vender según los gramos del producto y de los adicionales Topping y
  Sirope (también en regalías y dañadas). Setup → Ajustes → **Pasar a granel** crea los
  productos y pasa la existencia de las variedades; Oreo y Pirulín siguen en unidades.
- Inventario de ventas: columna **Por receta** (lo que gastaron las ventas por receta); los
  servidos salen "(por receta)"; las salidas de tickets borrados siguen como diferencia.
- Toma de orden: los sabores mix dependen del mix del camión de la caja. Sin mix para una
  porción salen en gris ("sin mix", no se eligen); con pocas porciones, aviso "quedan N"
  (Setup → Ajustes → Aviso de mix por agotarse, 10 por defecto).
- Botones de Excel y de descarga del paquete con token CSRF (fallaban en producción).
- Categoría **Individual** (antes Golosinas) para lo que se vende suelto y baja del camión al
  venderse: golosinas, Oreo, Pirulín, **Agua mineral** (IND-001). `test/individual_cli.php`.
- Migración → **Reset de datos** (simular / borrar con confirmación): borra ventas, inventario,
  productos y categorías para cargar el catálogo de local en limpio. Conserva usuarios,
  terceros, cuentas, almacenes y configuración. `test/reset_datos_cli.php`.

## 1.11

- Migración → Importar: opción **Depurar**. Los productos del destino que no vienen en el
  paquete se borran si nunca se usaron; si tienen documentos quedan fuera de venta y sin
  categorías. Las categorías que sobran se borran si quedan vacías. El reporte lista cada uno.
- `test/migracion_paquete_cli.php import … --depurar`.

## 1.10

- Regalías y dañadas (SabrooskiVentas → Regalías y dañadas): las registra el encargado al
  cierre con el PIN de quien autoriza (permiso nuevo "Autorizar regalías y dañadas (PIN)",
  cada autorizador fija su PIN en "Mi PIN de autorización"). Proceso completo: un servido
  baja kit + mix de los sabores; una bandeja o golosina baja ella misma. Se pueden anular
  (también con PIN) y devuelven al móvil.
- Tabla `llx_sabrooskipos_baja` (producto, sabores, motivo, quién registró y autorizó, costo).
- Reportes: regalías/dañadas de servidos en Inventario de ventas; sirope/topping de las
  bajas en el teórico de Insumos; sección "Regalías y dañadas" con su costo en Costos y
  margen; detalle con motivo y autorizador en Observaciones.

## 1.9

- Sin MovilStock: la carga del camión hace el traslado Principal → móvil con movimientos
  de stock propios (código `CARGA-…`), revisando existencia en Principal.
- Almacén principal en Setup → Sabrooski POS → Configuración
  (`SABROOSKIPOS_ID_WAREHOUSE_MAIN`; al activar el módulo se copia el de MovilStock) y en
  el paquete de migración.
- Carga del camión: lista de las últimas cargas con enlace a sus movimientos.
- Popup: agregar a la orden en una sola petición (`action=addOrder`).

## 1.8

- Servidos por receta (`recetas_sabrooski.json`, de "Estructura de Costos Sistema.xlsx"):
  insumos fijos como kits nativos (se descuentan al validar el ticket) y mix por sabor
  descontado por el trigger `interface_99_modSabrooskiPOS_Recetas`. Sirope, topping y
  pirulín/oreo: consumo teórico contra conteo de cierre.
- Insumos con costo y unidad de control; productos nuevos (especiales Susy, Cocosette y
  Maní cubierto, Brownie con helado, Helado de coco, Barquillón congelado) fuera de venta
  hasta tener precio.
- Carga del camión (traslado Principal → móvil) y cierre por conteo.
- ReportesVentas: "Insumos del móvil" y "Costos y margen".
- Migración: el catálogo lleva kits, costos y constantes; extrafields antes de la
  transacción (Simular ya no guarda nada).
- Popup: "Quitar"/"Vaciar" del carrito vuelven a funcionar (`removeLine` en `picker/api.php`).

## 1.7

- Popup: los adicionales son solo **Topping** y **Sirope** genéricos con su cantidad
  ($0,25 c/u); el cliente elige el topping/sirope concreto en la barra.
- Catálogo: genéricos `PV-031 Topping` y `PV-032 Sirope` (categoría Adicional, sin stock
  propio). Los toppings/siropes reales y `PV-030 Adicional` quedan fuera de venta.
- Recibo (hook `TakeposReceipt`): cada línea muestra su detalle junto al producto, p. ej.
  "Barquilla (Chocolate, Fresa) 1 Topping 1 Sirope" y "Topping para Barquilla".

## 1.4

- Reconstruida la toma de pedidos sobre el TakePOS **nativo**.
- Los productos base se obtienen del endpoint **nativo** de TakePOS
  (`takepos/ajax/ajax.php?action=getProducts`), el mismo que pinta la rejilla del POS:
  se muestran las MISMAS categorías y MISMOS productos que en el POS nativo.
- `picker/api.php` ya NO lista productos base (solo categorías + sabores/toppings/siropes).
- Pestañas de categorías reales SIN el botón "Todos": Conos (2), Vasos (3), Sundaes (4).
- Modal de personalización (sabor/topping/sirope) con límites de los extrafields
  (`max_sabores`, `max_toppings_incluidos`, `max_sirope`).
- Al confirmar agrega la línea al ticket nativo (`takepos/invoice.php` addline/addnote).
- Se fijan las categorías reales: sabores=5, toppings=6, siropes=7.

## 1.3

- Se retira el botón "Toma de orden" y su popup (carpeta `picker/`, `js/picker.js`,
  `css/picker.css`) por no cumplir los requisitos en la versión instalada.
- El POS queda **100% nativo**: `/takepos/index.php` sin capa visual extra.
- Se elimina la declaración de hooks (`takeposfrontend`) del descriptor.
- Se fijan las **categorías reales** de la instalación: sabores=5, toppings=6, siropes=7.

## 1.2

- Se abandona la pantalla custom (`sabrooskiposindex.php`) como POS.
- El POS vuelve a ser el TakePOS nativo (`/takepos/index.php`), sin capa visual extra.
- Nuevo botón **"Toma de orden"** en la barra de acciones de TakePOS (hook `ActionButtons`,
  contexto `takeposfrontend`), que abre un popup de selección sabor/topping/sirope.
- Nueva carpeta `picker/`: `index.php` (popup), `api.php` (catálogo JSON real),
  y `js/picker.js` + `css/picker.css` (modal mobile-first).
- Al confirmar, la línea se agrega a la venta con los endpoints nativos de TakePOS
  (`invoice.php addline / addnote`) y la personalización queda como nota de línea.
- Se conservan los extrafields (`max_sabores`, `max_toppings_incluidos`, `max_sirope`)
  y la configuración de categorías (`admin/setup.php`).

## 1.1

- Añadido el modal de personalización de producto (sabor / topping / sirope) con
  límites leídos de los extrafields `max_sabores`, `max_toppings_incluidos` y `max_sirope`.
- Añadida la sección de **Adicionales** con contador +/- (cada unidad adicional se
  agrega como línea de venta propia de un producto real de Dolibarr).
- Nuevo endpoint `ajax/ajax.php` del módulo con `getModalData`, `getCartData` y `addItem`.
- El carrito ahora se lee desde `getCartData` (JSON) para mostrar la descripción
  personalizada y los adicionales como líneas separadas.
- Alta automática de los tres extrafields de producto al activar el módulo.
- Configuración en `admin/setup.php` para elegir las categorías de sabores, toppings y siropes.

## 1.0

Initial version
