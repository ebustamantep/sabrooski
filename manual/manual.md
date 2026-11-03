# Manual de usuario — Sabrooski POS

## Introducción

Sabrooski POS agrega al TakePOS de Dolibarr la **Toma de orden** de la heladería y controla el inventario del camión por receta: carga, ventas, regalías y dañadas, cierre por conteo y reportes del turno. No reemplaza al TakePOS: se cobra, se imprime y se cierra caja igual que siempre.

El manual tiene dos partes:

| Parte | Para quién | Qué cubre |
| --- | --- | --- |
| 1. Operación diaria | Cajeros y encargados del camión | Carga, toma de orden, cobro, regalías y dañadas, cierre |
| 2. Administración | Administrador del sistema | Instalación, configuración, permisos, catálogo y recetas, migración, reportes |

**El turno en una línea:** Entrada a Principal → Carga del camión → Ventas con Toma de orden → Regalías y dañadas → Cierre (conteo) → Reportes.

Todo lo del módulo, salvo el botón del POS, está en el menú superior **SabrooskiVentas**.

## Parte 1 — Operación diaria

Antes de vender, el camión debe tener insumos cargados: Dolibarr no permite existencias negativas, así que sin carga el sistema no deja cobrar.

### Cargar el camión

Menú **SabrooskiVentas → Carga del camión** (permiso "Registrar carga y cierre").

1. Elige el tipo de movimiento:
    - **Existencia inicial (Principal → móvil):** lo que el camión tiene al empezar.
    - **Reposición / carga (Principal → móvil):** lo que se le sube durante el turno.
    - **Entrada a Principal (compras o conteo):** mercancía que llega al almacén principal.
2. Elige el móvil (por ejemplo, Movil01PM) y la fecha del turno.
3. Escribe las cantidades en la columna de la derecha. La tabla muestra lo que hay en Principal para no cargar más de lo que existe.
4. Pulsa **Registrar carga** (o **Registrar entrada**). Abajo aparece la carga en **Últimas cargas**.

| Grupo | Unidad en que se escribe |
| --- | --- |
| Mix por sabor | kg (10 = 10 kg) |
| Vasos, conos y envases | unidades |
| Siropes | gramos de **Sirope (granel)** |
| Toppings | gramos de **Topping (granel)**; Oreo y Pirulín aparte, en unidades |
| Individual y complementos, desechables, congelados | unidades |

Topping y sirope van **a granel**: un solo insumo en gramos para cada uno, sin variedades (todo el topping junto, todo el sirope junto). También en la **Entrada a Principal**.

### Tomar una orden

1. En el TakePOS, abre la mesa o ticket y pulsa el botón **Toma de orden** de la barra de acciones.
2. A la izquierda, elige la pestaña (Servido en camión, Especial domo, Bandejas 500 g, Sundae congelado, Empaquetado…) y toca el producto.
3. En la ventana del producto:
    - Elige los **sabores**, hasta el máximo del producto ("Elige hasta 2 sabores"). Dos sabores se reparten mitad y mitad.
    - Ajusta la **cantidad**.
    - En los servidos, suma **Topping** o **Sirope** con + / − ($0,25 c/u). El cliente elige cuál en la barra; el sistema solo registra cuántos.
4. Pulsa **Agregar a la orden**. La ventana vuelve a los productos para seguir agregando.
5. A la derecha, **Orden actual** muestra las líneas y el total. Usa **Quitar** en una línea o **Vaciar** para empezar de nuevo.
6. Pulsa **Finalizar orden**: se cierra la ventana y el ticket del TakePOS queda armado.

Los precios salen en Bs, destacados, y debajo en pequeño su equivalente en $. El monto en Bs es el precio en $ por la última tasa registrada en el widget de tasas del inicio; la tasa usada aparece al pie de **Orden actual**. Si cambias la tasa, cierra y vuelve a abrir la Toma de orden para verla actualizada.

### Cobrar e imprimir

El cobro es el del TakePOS: **Cobrar**, elegir la forma de pago (efectivo $, Efectivo Bs, pago móvil, punto) y confirmar. El recibo sale con el detalle de la orden, por ejemplo:

```
Barquilla (Chocolate, Fresa) 1 Topping 1 Sirope
Topping para Barquilla
Sirope para Barquilla
```

Al validar el ticket, el inventario del móvil baja solo: los insumos fijos de la receta (vaso o cono, cucharilla, servilleta…), el mix de cada sabor elegido y los gramos de **topping y sirope** que lleva el producto, más los de cada Topping o Sirope adicional (15 g y 10 g). Oreo y Pirulín no se descuentan al vender; se cuentan en el cierre.

Las ventas a crédito o delivery se registran con pago diferido del TakePOS.

## Parte 1 — Cierre de turno

Al terminar el turno, el encargado hace tres cosas en este orden: registra regalías y dañadas, cuenta lo que queda en el camión y cierra la caja.

### 1. Regalías y dañadas

Menú **SabrooskiVentas → Regalías y dañadas**. Cada registro necesita el PIN de un autorizador.

1. Elige el móvil y la fecha del turno.
2. Llena el formulario:
    - **Tipo:** Regalía o Dañada.
    - **Producto:** de venta o insumo.
    - **Sabores**, si el producto lleva mix.
    - **Cantidad.**
    - **Motivo / a quién**, por ejemplo "Marketing (Naibelis)" o "se cayó al servir".
    - **PIN de quien autoriza:** lo teclea el autorizador.
3. Pulsa **Registrar**. La lista **Registradas en el turno** muestra el costo, quién autorizó y quién registró.

Un servido regalado o caído baja su receta completa (vaso o cono, cucharilla, mix de cada sabor…). Para corregir un error, pulsa **Anular** en la línea; pide PIN otra vez y devuelve los insumos al móvil.

> Cinco PIN errados bloquean el registro 10 minutos.

**Mi PIN de autorización** (solo autorizadores): cada uno fija aquí su PIN de 4 a 8 dígitos. Dos autorizadores no pueden tener el mismo. Para cambiarlo se pide el PIN actual.

### 2. Cierre (conteo)

Menú **SabrooskiVentas → Cierre (conteo)**.

1. Elige el móvil y la fecha del turno.
2. Cuenta físicamente lo que queda y escríbelo en **Conteo**, en las mismas unidades que la carga (mix en kg). El topping y el sirope se **pesan todo junto** y se escriben en gramos en **Topping (granel)** y **Sirope (granel)**. Oreo y Pirulín se cuentan en unidades. La columna **Según sistema** sirve de referencia.
3. Deja vacío lo que no contaste: no se toca.
4. Pulsa **Registrar cierre**.

El sistema iguala la existencia al conteo. En Oreo y Pirulín la diferencia queda como "Consumo del turno (conteo)"; en lo demás (también topping y sirope, que ya bajaron al vender), como "Ajuste cierre (conteo)": es la merma, y se ve en el reporte de insumos.

### 3. Cierre de caja

Se hace en el TakePOS como siempre (cierre de caja nativo, con los montos en $ y Bs). El módulo no lo cambia.

## Parte 2 — Administración

El módulo se instala como cualquier módulo externo de Dolibarr y se configura en **Inicio → Configuración → Módulos → Sabrooski POS**. Requiere Dolibarr con TakePOS activo.

### Instalar o actualizar

1. Respalda la base de datos.
2. **Configuración → Módulos → Instalar módulo externo** y sube el zip. El archivo debe llamarse exactamente `module_sabrooskipos-x.y.zip` (por ejemplo `module_sabrooskipos-1.12.zip`).
3. **Reactiva** el módulo: desactivar y activar, o el botón **Reactivar módulo (conserva permisos y configuración)** de la pestaña Migración. Esto crea tablas, permisos, menús y el disparador de recetas. Desactivar y activar a mano borra los permisos asignados; el botón los conserva.
4. Si cambió el catálogo, importa el paquete (ver *Exportar e importar el catálogo*).

### Pestañas de configuración

| Pestaña | Para qué sirve |
| --- | --- |
| Ajustes | IVA del catálogo (16% por defecto), **Almacén principal** (origen de la carga del camión), categorías ocultas en el popup |
| Terminal 1, Terminal 2… | Por cada caja: categorías de sabores mix, sabores de bandejas, toppings, siropes y adicionales; producto Adicional |
| Cargar catálogo | Carga inicial del catálogo desde el JSON del módulo (solo instalaciones nuevas) |
| Migración | Importar el paquete de catálogo y configuración; reactivar el módulo |

**Topping y sirope a granel** (al pie de Ajustes): el botón **Pasar a granel** crea **Topping (granel)** (`INS-TOPG`) y **Sirope (granel)** (`INS-SIRG`), suma en cada almacén lo que había de las variedades (Dandy, Maní, Sirope de chocolate…) y lo pasa al granel; las variedades quedan fuera de uso. Se hace una vez por base de datos; repetirlo no duplica nada.

Los almacenes de cada terminal (por ejemplo, Terminal 2 → Movil01PM) y las formas de pago se fijan en la configuración del TakePOS, no aquí.

### Permisos

Se asignan en **Usuarios y grupos → usuario → Permisos → Sabrooski POS**.

| Permiso | Quién lo necesita | Qué habilita |
| --- | --- | --- |
| Usar Sabrooski POS | Cajeros | El botón Toma de orden |
| Ver ReportesVentas (planillas del turno) | Encargados, administración | Menú SabrooskiVentas y reportes (activo por defecto) |
| Registrar carga y cierre (conteo) del camión | Encargados | Carga del camión, Cierre (conteo), Regalías y dañadas |
| Autorizar regalías y dañadas (PIN) | Supervisor o dueño | Mi PIN de autorización; su PIN vale para autorizar |

Después de dar el permiso de autorizar, cada autorizador debe entrar a **Mi PIN de autorización** y fijar su PIN.

Este manual se abre desde **SabrooskiVentas → Manual de usuario**. Su texto está en el archivo `custom/sabrooskipos/manual/manual.md` del módulo; si se edita ese archivo, la página muestra el cambio al recargar.

## Parte 2 — Catálogo y recetas

El catálogo se mantiene en el sistema de prueba (local) y se copia a producción con un paquete JSON. Así producción queda con los mismos productos, precios, recetas y configuración.

### Cómo está armado el catálogo

- **Categorías del TakePOS:** cada categoría hija de la raíz es una pestaña del popup. Las que no deben verse (por ejemplo, Adicional o Insumos) se ocultan en Ajustes.
- **Precios:** son el precio final con IVA 16% incluido.
- **Datos del producto que usa el popup:** máximo de sabores, grupo de sabores (mix para servidos, bandejas para empaquetado) y gramos por porción.
- **Congelados** (bandejas 500 g, sundae congelado): un producto por sabor, con existencia propia.
- **Servidos:** no tienen existencia propia; se controlan por receta.

### Recetas

Cada servido es un **kit** de Dolibarr con sus insumos fijos: vaso o cono, cucharilla, servilleta, domo… Al vender se descuenta el kit, y el módulo descuenta el mix según los sabores elegidos y el topping y sirope a granel según los gramos del producto (los adicionales Topping 15 g y Sirope 10 g).

El costo de cada insumo está en el **precio de costo** del producto y alimenta el reporte Costos y margen. Las recetas vienen de la hoja *Estructura de Costos Sistema.xlsx*. Para cambiar una receta, edita el kit del producto en Dolibarr (pestaña de subproductos), o pídelo al desarrollador para actualizar el archivo de recetas.

Un producto nuevo queda **fuera de venta hasta tener precio**.

### Crear un producto con receta (kit)

Un servido nuevo (por ejemplo, un vaso o un especial) se arma en dos pasos: la ficha del producto con sus datos de receta y, después, la pestaña **Kits** con sus insumos fijos. Todos los insumos deben existir antes.

**Paso 1 — Ficha del producto.** Menú **Productos → Nuevo producto**:

1. **Ref.** con la numeración de venta (por ejemplo `PV-033`) y **Etiqueta** con el nombre que verá el cajero.
2. **En venta:** Sí. **En compra:** No.
3. **Precio de venta** con IVA incluido y **Tasa IVA** 16%.
4. En los campos del módulo, más abajo en la misma ficha (ver tabla).
5. Pulsa **Crear**. En la ficha, pestaña **Categorías**, asígnale la pestaña del popup donde debe salir (Servido en camión, Especial domo…).

| Campo | Qué poner | Ejemplo |
| --- | --- | --- |
| Máx. sabores | Sabores que puede elegir el cliente | 2 |
| Grupo de sabores | Mix (servidos) | Mix (servidos) |
| Gramos por porción | Gramos de mix por unidad; se reparten entre los sabores elegidos | 82 |
| Gramos de sirope (receta) | Gramos de sirope que lleva; 0 si no lleva | 10 |
| Gramos de topping (receta) | Gramos de topping que lleva; 0 si no lleva | 15 |
| Pirulín/Oreo (receta, uds) | Unidades de Pirulín u Oreo; 0 si no lleva | 0 |
| Unidad de control | Vacío (solo para insumos) | — |

**Paso 2 — Pestaña Kits.** En la ficha del producto, abre **Kits**:

1. Busca cada insumo fijo (vaso o cono, cucharilla, servilleta, domo…) y escribe la **Cant.** por unidad vendida.
2. Marca **Incrementar/Decrementar stock al cambiar su padre** en cada insumo. Sin esa marca, el insumo no baja al vender.
3. Pulsa **Añadir**.
4. Deja **Gestion de stocks** en No: el servido no lleva existencia propia, se controlan sus insumos.

No agregues al kit el mix, el sirope, el topping ni el Pirulín/Oreo. Esos salen de los campos del paso 1, y si también van en el kit se descuentan dos veces.

**Comprobar:** abre la Toma de orden y verifica que el producto sale en su pestaña y pide sabores. Luego revisa en **Costos y margen** que tenga costo; si sale en 0, a algún insumo le falta el precio de costo. Si el producto se creó en local, pasa a producción con el paquete de migración (los kits viajan en él).

**Otros casos:**

- **Producto individual** (se vende suelto y baja del camión al venderse: agua, golosina, Oreo…). Ejemplo, *Agua 500 ml*: **Productos → Nuevo producto** con **Ref.** `IND-002` (siguiente número libre), **Etiqueta** "Agua 500 ml", **En venta:** Sí, **En compra:** Sí, **Precio de venta** con IVA, **Tasa IVA**, **Gestion de stocks:** Sí, **Precio de costo** y **Unidad de control** `uds`. Deja vacíos Máx. sabores y Grupo de sabores, y no le pongas kit. En la pestaña **Categorías** asígnale **Individual**: así sale en esa pestaña del POS y de la Toma de orden, y en Carga del camión y Cierre (grupo *Individual y complementos*). Sin precio, déjalo con **En venta:** No.
- **Insumo nuevo** (vaso, desechable): **Nuevo producto** con **En venta:** No, **En compra:** Sí, **Gestion de stocks:** Sí, **Precio de costo** por unidad y **Unidad de control** (`uds` o `g`). Asígnale una categoría de insumos (Envases, Desechables, Complementos); sin ella no aparece en Carga del camión ni en Cierre. Topping y sirope no llevan variedades: todo va a Topping (granel) y Sirope (granel).
- **Congelado o empaquetado** (bandeja, sundae congelado): producto sin kit, con **Gestion de stocks:** Sí y categoría Bandejas 500 g, Sundae congelado o Empaquetado, para que entre en la carga y el cierre.
- **Sabor o mix nuevo:** el reparto del mix y la lista de mixes de la carga están en el archivo de recetas del módulo. Pídelo al desarrollador.

### Exportar e importar el catálogo

**En local (exportar):** abre `custom/sabrooskipos/admin/migracion_export.php`, deja **Incluir turnos** sin marcar y descarga el archivo `sabrooskipos_catalogo_config_AAAAMMDD.json`.

**En producción (importar):**

1. Respalda la base de datos.
2. **Configuración → Sabrooski POS → Migración** y sube el JSON.
3. Marca **Depurar** si producción debe quedar exactamente con los productos de local. Los productos que sobran se borran si nunca se vendieron; si se vendieron, quedan fuera de venta y sin categoría.
4. Pulsa **Simular (no guarda)** y revisa el reporte, sobre todo la sección *Depuración*.
5. Si todo está bien, pulsa **Importar**.

No viajan en el paquete: número de terminales, usuarios, datos de la empresa ni existencias. Las existencias se cargan con **Carga del camión**.

### Empezar en limpio (reset de datos)

Para dejar producción sin las pruebas y con el catálogo de local, sin inventario. **Borra** todos los tickets y facturas de cliente con sus pagos y sus líneas de banco, los cierres de caja, las regalías y dañadas, los movimientos y existencias de stock, todos los productos y todas las categorías de productos, y la configuración por terminal del popup (vuelve a la general). **Conserva** usuarios y permisos, terceros, cuentas bancarias, formas de pago, almacenes y la configuración general. No se puede deshacer, salvo restaurando el respaldo.

1. En local, exporta el paquete de catálogo **sin turnos** (ver arriba).
2. En producción, **respalda la base de datos**.
3. **Configuración → Sabrooski POS → Migración**, sección **Reset de datos**: pulsa **Simular reset (no borra)** y revisa cuánto borraría.
4. Escribe **BORRAR** en *Confirmación* y pulsa **Borrar datos**.
5. En la misma página, sube el paquete, **Simular** y luego **Importar**.
6. Revisa la Toma de orden (sabores y pestañas) y carga el camión con **Carga del camión**: primero **Entrada a Principal** y después **Existencia inicial**.

No hace falta **Pasar a granel**: el catálogo de local ya trae Topping (granel) y Sirope (granel).

## Parte 2 — Reportes

Los seis reportes están en el menú izquierdo de **SabrooskiVentas**. Se filtran por fecha y terminal, y cada uno se descarga en Excel (.xlsx); también se pueden bajar las cuatro planillas del turno juntas.

| Reporte | Qué muestra | Para qué sirve |
| --- | --- | --- |
| Camión congelados | Congelados por sabor, con conos "+N conos" | Cuadrar los congelados del camión |
| Pago móvil y puntos | Pagos móviles y transacciones de punto por lote, crédito y débito | Cuadrar contra el banco y los cierres de lote |
| Especificaciones de ventas | Ventas del turno, resumen de cierre, ventas a crédito y observaciones (descuentos, regalías, dañadas) | La planilla principal del turno |
| Inventario de ventas | Por producto: existencia, reposición, vendido, regalías, crédito, dañadas, lo gastado por receta y cierre | Ver qué se movió y dónde hay diferencias |
| Insumos del móvil | Por insumo: consumo real (existencia + carga − regalías/dañadas − cierre) frente al teórico de las ventas | Detectar faltantes o porciones mal servidas |
| Costos y margen | Ventas del turno, costo por receta, margen y regalías/dañadas con su costo | Ver la ganancia real del turno |

**Cómo leer Insumos del móvil:** si el consumo real es mayor que el teórico, salió más insumo del que justifican las ventas: porciones grandes, regalías sin registrar o faltante. En topping y sirope a granel, el consumo real (existencia + carga − peso del cierre) se compara con los gramos que llevan los productos vendidos más los adicionales: la diferencia es la merma (o lo que se sirvió de más). Oreo y Pirulín se comparan en total del turno.

**Regalías y dañadas** aparecen netas: las anuladas restan.

## Preguntas frecuentes y solución de problemas

| Problema | Causa probable | Qué hacer |
| --- | --- | --- |
| No aparece el botón Toma de orden | El módulo se actualizó sin reactivar, o falta el permiso | Reactivar el módulo; dar "Usar Sabrooski POS" al usuario |
| No deja cobrar por falta de existencia | El móvil no tiene el insumo cargado | Registrar la carga del insumo en Carga del camión |
| La carga no se registra | No hay suficiente existencia en Principal | Registrar primero la Entrada a Principal |
| Una pestaña no aparece en el popup | La categoría está en "Categorías ocultas" o no cuelga de la raíz del TakePOS | Revisar Ajustes y el árbol de categorías |
| No salen sabores al tocar un producto | Las categorías de sabores de esa terminal no están configuradas | Configuración → Sabrooski POS → pestaña de la terminal |
| "PIN incorrecto" o registro bloqueado | PIN equivocado; cinco intentos bloquean 10 min | Esperar y que el autorizador lo teclee de nuevo |
| No veo el menú Carga del camión | Falta el permiso de carga y cierre | Asignar "Registrar carga y cierre (conteo) del camión" |
| Un producto nuevo no aparece | Está fuera de venta hasta tener precio | Ponerle precio y marcarlo En venta |
| El costo de un producto sale en 0 | El producto o sus insumos no tienen precio de costo | Cargar el precio de costo en la ficha del producto |
| El sabor no sale en el ticket de la pantalla | El módulo no se reactivó después de instalar la 1.12 | Reactivar el módulo (activa la opción del TakePOS que muestra el detalle bajo cada producto) |
| El sabor no sale en el ticket de la impresora térmica | La plantilla del recibo imprime solo el nombre del producto | En la plantilla del recibo, cambiar `{dol_print_object_lines}` por `{dol_print_object_lines_with_notes}` |
| Me equivoqué en una regalía | — | Anularla con PIN y registrarla de nuevo |

**¿Se puede corregir un cierre?** Sí: vuelve a registrar el conteo del mismo móvil y fecha; el sistema ajusta a la nueva cifra.

**¿Quién ve el PIN?** El PIN no se muestra en pantalla, pero se teclea en el equipo del encargado.
