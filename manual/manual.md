# Manual de usuario — Sabrooski POS

## Introducción

Sabrooski POS agrega al TakePOS de Dolibarr la **Toma de orden** de la heladería y controla el inventario del camión por receta: carga, ventas, regalías y dañadas, cierre por conteo y reportes del turno. No reemplaza al TakePOS: se cobra, se imprime y se cierra caja igual que siempre.

El manual tiene dos partes:

| Parte | Para quién | Qué cubre |
| --- | --- | --- |
| 1. Operación diaria | Cajeros y encargados del camión | Carga, toma de orden, cobro, regalías y dañadas, cierre |
| 2. Administración | Administrador del sistema | Instalación, configuración, permisos, catálogo y recetas, migración, reportes |

**El día en una línea:** Entrada a Principal → Existencia inicial al camión → abrir caja del turno AM → ventas → cierre X → **Cambio de turno** (conteo y reponer) → abrir caja del turno PM → ventas → cierre Z → conteo final y **devolver todo a Principal** → reportes.

Hay **un solo camión** (un terminal del TakePOS y un almacén). Los turnos del día (AM y PM) son las **sesiones de caja de POSPlus**: un turno va desde que se abre la caja hasta su cierre X (o Z en la noche). Solo cambia el operador; el inventario sigue en el mismo camión.

Todo lo del módulo, salvo el botón del POS, está en el menú superior **SabrooskiVentas**.

## Parte 1 — Operación diaria

Antes de vender, el camión debe tener insumos cargados: Dolibarr no permite existencias negativas, así que sin carga el sistema no deja cobrar.

**En la mañana:** registra la producción en **Entrada a Principal**, carga el camión con **Existencia inicial** y después abre la caja del turno AM en el TakePOS.

### Cargar el camión

Menú **SabrooskiVentas → Carga del camión** (permiso "Registrar carga y cierre").

1. Elige el tipo de movimiento:
    - **Existencia inicial (Principal → móvil):** lo que el camión tiene al empezar.
    - **Reposición / carga (Principal → móvil):** lo que se le sube durante el turno. Entre turnos (con la caja cerrada) aparece la columna *Salió en el turno anterior*; lo normal es reponer desde **Cambio de turno**.
    - **Entrada a Principal (compras o conteo):** mercancía que llega al almacén principal.
2. Elige el móvil. La carga se registra a la hora actual: cuenta para el turno con la caja abierta o, si la caja está cerrada, para el turno que se abra después.
3. Escribe las cantidades en la columna de la derecha. La tabla muestra lo que hay en Principal para no cargar más de lo que existe.
4. Pulsa **Registrar carga** (o **Registrar entrada**). Abajo aparece la carga en **Últimas cargas**.

| Grupo | Unidad en que se escribe |
| --- | --- |
| Mix por sabor | **gramos**, sin punto ni coma (16,250 kg = `16250`). Una carga debe estar entre 100 g y 50.000 g por sabor; si no, no se registra nada y se avisa |
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
    - Los sabores dependen del **mix cargado en el camión** de esa caja: si un sabor sale **en gris** ("sin mix"), no hay mix para una porción y no se puede elegir; si sale con **⚠ quedan N**, se está agotando (alcanza para N porciones o menos; el límite se cambia en Setup → Ajustes → *Aviso de mix por agotarse*). Si el camión tiene más mix del que dice el sistema, regístralo con una **Reposición** o en el **Cierre**.
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

## Parte 1 — Un día en el camión, paso a paso

Hay un solo camión y dos turnos al día: **AM** (mañana) y **PM** (tarde). Cada turno empieza cuando se **abre la caja** en el TakePOS (POSPlus → Apertura de caja) y termina con su **cierre de caja**: el **Reporte X** al mediodía y el **Reporte Z** en la noche. Lo que se vende, regala o falta en el conteo queda a cargo del turno en que pasó. Solo cambian los operadores; el inventario no se mueve a otro camión.

Resumen del día:

| Momento | Quién | Dónde | Qué se hace |
| --- | --- | --- | --- |
| Mañana, antes de vender | Encargado | Carga del camión | Producción a **Entrada a Principal**; luego **Existencia inicial** al camión |
| Inicio del AM | Operador AM | TakePOS | **Abrir caja** (POSPlus) |
| Durante el AM | Operador AM | TakePOS | Vender con **Toma de orden**; registrar regalías y dañadas |
| Mediodía | Operador AM y encargado | TakePOS y Cambio de turno | **Cierre X** → **contar** el camión → ver diferencias → **reponer** lo que salió |
| Inicio del PM | Operador PM | TakePOS | **Abrir caja** |
| Durante el PM | Operador PM | TakePOS | Vender; registrar regalías y dañadas |
| Noche | Operador PM y encargado | TakePOS y Cambio de turno | **Cierre Z** → **conteo final** → **devolver todo a Principal** |

### Paso 1 — En la mañana: cargar Principal y el camión

1. Menú **SabrooskiVentas → Carga del camión**, tipo **Entrada a Principal (compras o conteo)**. Escribe lo producido: mix por sabor en **gramos** (16,250 kg = `16250`), congelados, conos, vasos, complementos. Pulsa **Registrar entrada**.
2. Cambia el tipo a **Existencia inicial (Principal → móvil)**, elige el camión y escribe lo que se sube. No se puede cargar más de lo que hay en Principal (columna *En Principal*). Pulsa **Registrar carga**.
3. Revisa que la carga aparezca abajo, en **Últimas cargas**.

Carga el camión **antes** de abrir la caja: así la existencia inicial cuenta para el turno AM.

### Paso 2 — Abrir el turno AM y vender

1. En el TakePOS, abre la caja con **POSPlus → Apertura de caja** (fondo de cada caja).
2. Vende con el botón **Toma de orden** (ver *Tomar una orden* y *Cobrar e imprimir*). Cada venta descuenta del camión su receta: vaso o cono, mix de los sabores elegidos, topping y sirope.
3. Si se regala o se daña algo, regístralo en **SabrooskiVentas → Regalías y dañadas** con el PIN del autorizador (ver más abajo).

### Paso 3 — Mediodía: cambio de turno

Menú **SabrooskiVentas → Cambio de turno**. La pantalla muestra los pasos y en cuál vas:

1. **Cerrar caja (X).** En el TakePOS, cierra la caja del AM con el **Reporte X** de POSPlus. Con la caja abierta, Cambio de turno no deja contar: así ya no entran ventas mientras se cuenta. Luego pulsa **Ya cerré la caja: continuar**.
2. **Contar el camión.** Pesa y cuenta todo lo que queda y escríbelo en **Conteo** (ver *Cómo contar*). Pulsa **Registrar conteo del Turno 1 (AM)**.
3. **Diferencias.** La pantalla muestra, producto por producto, lo que faltó o sobró (contado − sistema). Esas diferencias son del turno AM. Si te equivocaste al contar, corrígelo en **Cierre (conteo)**: se ajusta a la nueva cifra.
4. **Reponer.** En *Sigue otro turno: reponer desde Principal*, pulsa **Reponer lo que salió**: copia en *Reponer* lo que el AM vendió, regaló o mermó, sin pasar de lo que hay en Principal. Ajusta lo que haga falta y pulsa **Registrar reposición**. Así el PM empieza con el camión como empezó el AM.
5. **Abrir el PM.** El operador PM abre su caja en el TakePOS (POSPlus → Apertura de caja). Desde ahí, todo es del turno PM.

Ejemplo: el AM empezó con 100 vasos de Sundae, vendió 30 y regaló 2. El conteo da 68: sin diferencias. *Salió* = 32; se reponen 32 y el PM empieza con 100.

Las regalías y el conteo que se registran **después del cierre X y antes de abrir el PM** cuentan para el AM. La reposición cuenta para el PM.

### Paso 4 — Turno PM

Igual que el AM: vender con **Toma de orden** y registrar las regalías y dañadas.

### Paso 5 — Noche: cierre del día

Menú **SabrooskiVentas → Cambio de turno**:

1. **Cerrar caja (Z).** En el TakePOS, cierra el día con el **Reporte Z** de POSPlus.
2. **Conteo final.** Cuenta todo lo que queda y regístralo, igual que al mediodía.
3. **Diferencias** del turno PM.
4. **Devolver todo a Principal.** En *Cierre del día*, pulsa **Devolver todo a Principal** y confirma. Todo lo que queda pasa a Principal y el camión queda en 0. En los reportes, lo devuelto cuenta como el cierre del PM (lo contado), no como faltante.
5. Al día siguiente se empieza otra vez por el **Paso 1**.

No repongas en la noche: después del cierre Z se devuelve todo.

### Cómo contar

1. Cuenta físicamente lo que queda y escríbelo en **Conteo**, en las mismas unidades que la carga: mix en **gramos**, sin punto ni coma (9,4 kg = `9400`). El topping y el sirope se **pesan todo junto** y se escriben en gramos en **Topping (granel)** y **Sirope (granel)**. Oreo y Pirulín se cuentan en unidades. La columna **Según sistema** sirve de referencia.
2. Deja vacío lo que no contaste: no se toca.

El sistema iguala la existencia al conteo. En Oreo y Pirulín la diferencia queda como "Consumo del turno (conteo)"; en lo demás (también topping y sirope, que ya bajaron al vender), como "Ajuste cierre (conteo)": es la merma, y se ve en el reporte de insumos.

**Si el conteo no cuadra con el sistema (ejemplo con el mix).** *Según sistema* dice **15250 g** de Mix de chocolate, pero al pesar hay **14950 g**:

1. Escribe en *Conteo* lo que pesaste: `14950`. No escribas lo que dice el sistema.
2. Al registrar, el sistema saca **300 g** del camión como "Ajuste cierre (conteo)" y la existencia queda en 14950 g. El faltante queda en el turno que cerró: el siguiente empieza con lo que de verdad hay.
3. En **ReportesVentas → Insumos del móvil** (con ese turno elegido), esos 300 g salen en **Diferencia** (consumo real menos consumo teórico de las ventas). Las ventas no cuentan como diferencia: cada venta ya descontó su receta al cobrarse.

Esos 300 g son la **merma del turno**. Las causas más comunes son:

- Porciones servidas más grandes que la receta (la más frecuente).
- Mix perdido o botado: limpieza o purga de la máquina, derrames, lo que queda en la tolva.
- Ventas que se sirvieron y no se marcaron en la caja.
- Regalías o dañadas sin registrar. Si se registran antes del conteo, salen en su columna y no cuentan como diferencia.
- Error al pesar.

Buenas prácticas:

- Pesa siempre en la misma balanza y sin el envase.
- Registra las regalías y dañadas **antes** del conteo.
- Una merma pequeña es normal en máquina. Si la diferencia pasa del margen que fije la administración (por ejemplo, 2–3 % de lo vendido), revísala con el encargado del turno.
- Mira la tendencia: si un turno tiene siempre diferencias altas, revisa el tamaño de las porciones o las ventas sin registrar.

Si el conteo es **mayor** que el sistema, el ajuste es una entrada y la diferencia sale negativa. Casi siempre es un error de pesaje o una carga sin registrar.

**Cierre (conteo)** (menú aparte) sirve para corregir un conteo o contar sin pasar por Cambio de turno. Elige el turno en el selector; si la caja de ese turno ya cerró, los ajustes se fechan en su cierre. Contar un turno anterior al actual solo lo puede hacer un administrador, porque el conteo se compara con la existencia de ahora.

### Regalías y dañadas

Menú **SabrooskiVentas → Regalías y dañadas**. Cada registro necesita el PIN de un autorizador. Se registran en el turno con la caja abierta; si la caja ya cerró (antes del conteo), en el turno que cerró.

1. Elige el móvil (y el turno, si corriges uno anterior).
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

### Cierre de caja

Se hace en el TakePOS con **POSPlus**: **Reporte X** al terminar cada turno (cuenta el dinero de ese turno) y **Reporte Z** al final del día (cierra el día de caja). Después del Z no se puede abrir otra caja ese día. El cierre de caja es lo que marca el fin de cada turno para el inventario y los reportes.

## Parte 2 — Administración

El módulo se instala como cualquier módulo externo de Dolibarr y se configura en **Inicio → Configuración → Módulos → Sabrooski POS**. Requiere Dolibarr con TakePOS activo. Los turnos usan las sesiones de caja de **POSPlus**; sin POSPlus, conteos y reportes funcionan por día completo.

**Turnos por sesión de caja (desde la v1.16).** Desde la fecha de la constante `SABROOSKIPOS_TURNOS_DESDE` (se fija sola al reactivar el módulo), reportes, conteos y regalías van por turno. Los días anteriores se siguen viendo como antes (día completo por terminal). Para pasar de dos camiones (Movil01AM y Movil01PM) a uno solo: cerrar la caja del Terminal 2, devolver a Principal lo que tenga Movil01PM, poner el número de terminales del TakePOS en 1 y cerrar (sin borrar) el almacén Movil01PM.

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
| Migración | Importar el paquete de catálogo y configuración; reactivar el módulo |

**Topping y sirope a granel** (al pie de Ajustes): el botón **Pasar a granel** crea **Topping (granel)** (`INS-TOPG`) y **Sirope (granel)** (`INS-SIRG`), suma en cada almacén lo que había de las variedades (Dandy, Maní, Sirope de chocolate…) y lo pasa al granel; las variedades quedan fuera de uso. Se hace una vez por base de datos; repetirlo no duplica nada.

El almacén de cada terminal (un terminal por camión, por ejemplo Terminal 1 → Movil01AM) y las formas de pago se fijan en la configuración del TakePOS, no aquí.

### Permisos

Se asignan en **Usuarios y grupos → usuario → Permisos → Sabrooski POS**.

| Permiso | Quién lo necesita | Qué habilita |
| --- | --- | --- |
| Usar Sabrooski POS | Cajeros | El botón Toma de orden |
| Ver ReportesVentas (planillas del turno) | Encargados, administración | Menú SabrooskiVentas y reportes (activo por defecto) |
| Registrar carga y cierre (conteo) del camión | Encargados | Carga del camión, Cambio de turno, Cierre (conteo), Regalías y dañadas |
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

### Crear un servido en camión (ejemplo: Barquilla Plus)

Un servido (barquilla, sundae, vaso…) no tiene existencia propia: al venderlo, el sistema descuenta sus insumos. Se arma en dos partes, y cada parte descuenta cosas distintas:

| Qué descuenta | Dónde se configura | Ejemplo en Barquilla Plus |
| --- | --- | --- |
| **Insumos que se cuentan por unidad** (cono, portacono, vaso, cucharilla, servilleta, domo) | Pestaña **Kits** del producto | 1 cono barquilla, 1 portacono, 1 servilleta |
| **Mix** (helado), repartido entre los sabores que elige el cliente | Campo **Gramos por porción** de la ficha | 180 g |
| **Sirope y topping a granel** | Campos **Gramos de sirope (receta)** y **Gramos de topping (receta)** | 10 g de sirope en el cono, 10 g de topping |
| **Pirulín u Oreo** | Campo **Pirulín/Oreo (receta, uds)** | 0 |

Antes de empezar, mira cómo está hecha la **Barquilla** normal (`PV-002`) y úsala de modelo. La Barquilla Plus del ejemplo lleva **el doble de mix** y **10 g de sirope dentro del cono**; lo demás queda igual:

| | Barquilla (receta real, `PV-002`) | Barquilla Plus (ejemplo, `PV-033`) |
| --- | --- | --- |
| Precio de venta (con IVA 16 %) | 1,15 $ | el que se defina (ej. 2,00 $) |
| Máx. sabores | 1 | 1 |
| Grupo de sabores | Mix (servidos) | Mix (servidos) |
| **Gramos por porción (mix)** | **90 g** | **180 g** (el doble) |
| **Gramos de sirope (receta)** | **4 g** | **10 g** (sirope en el cono) |
| Gramos de topping (receta) | 10 g | 10 g |
| Pirulín/Oreo (receta, uds) | 0 | 0 |
| Kit: Cono barquilla `INS-012` | 1 | 1 |
| Kit: Portacono `INS-013` | 1 | 1 |
| Kit: Servilletas `INS-031` | 1 | 1 |
| Categoría | Servido en camión | Servido en camión |

Costo de la receta, con los costos actuales:

| Concepto | Costo unitario | Barquilla | Barquilla Plus |
| --- | --- | --- | --- |
| Cono barquilla | 0,0563 $/ud | 0,0563 $ | 0,0563 $ |
| Portacono | 0,0300 $/ud | 0,0300 $ | 0,0300 $ |
| Servilletas | 0,0042 $/ud | 0,0042 $ | 0,0042 $ |
| Mix | 0,002044 $/g | 90 g = 0,1840 $ | 180 g = 0,3679 $ |
| Sirope (granel) | 0,0096 $/g | 4 g = 0,0384 $ | 10 g = 0,0960 $ |
| Topping (granel) | 0,0126 $/g | 10 g = 0,1260 $ | 10 g = 0,1260 $ |
| **Total** | | **0,4389 $** | **0,6804 $** |

La receta de la Barquilla viene de la hoja *Estructura de Costos Sistema.xlsx*. Los gramos de sirope y topping del Plus son un ejemplo: usa los de tu receta (pésala una vez).

Todos los insumos deben existir antes. Si el Plus usa un cono distinto, créalo primero como insumo (ver *Otros casos → Insumo nuevo*).

#### Paso 1 — Crear la ficha del producto

Menú **Productos → Nuevo producto**:

1. **Ref.:** la siguiente libre de venta. Hoy la última es `PV-032`, así que `PV-033`.
2. **Etiqueta:** `Barquilla Plus` (es lo que ve el cajero y lo que sale en el recibo).
3. **En venta:** Sí. **En compra:** No.
4. **Precio de venta:** el precio final al público **con IVA incluido** (por ejemplo `2,00`), base de precio **TTC**, y **Tasa IVA** 16 %. Sin precio, déjalo con **En venta: No**: un producto sin precio no debe salir en la caja.
5. **Gestion de stocks:** No (el servido no lleva existencia propia; se controlan sus insumos).
6. No pongas **Precio de costo**: el costo del servido se calcula solo con su receta.
7. Más abajo, en los campos del módulo, llena la receta:

| Campo | Qué es | Barquilla Plus |
| --- | --- | --- |
| Máx. sabores | Cuántos sabores puede elegir el cliente en la Toma de orden. Un sabor mezclado (Chocolate-Fresa) cuenta como uno | 1 |
| Grupo de sabores | **Mix (servidos)**: hace que la Toma de orden pida sabor y descuente mix | Mix (servidos) |
| Gramos por porción | **Gramos de mix por unidad vendida.** Es lo que baja del inventario de mix. Si el sabor es mitad y mitad, se reparte: 90 g de cada mix | 180 |
| Gramos de sirope (receta) | Gramos de **Sirope (granel)** que lleva (aquí, el sirope dentro del cono). 0 si no lleva | 10 |
| Gramos de topping (receta) | Gramos de **Topping (granel)** que lleva. 0 si no lleva | 10 |
| Pirulín/Oreo (receta, uds) | Unidades de Pirulín u Oreo que lleva. 0 si no lleva | 0 |
| Unidad de control | Déjalo vacío (es solo para insumos) | — |

8. Pulsa **Crear**.
9. En la ficha ya creada, pestaña **Categorías**, asígnale **Servido en camión**. Esa categoría es la pestaña de la Toma de orden donde aparecerá, y la que usan los reportes. Si no le pones categoría, no sale en la Toma de orden.

**Dónde se ponen los gramos de mix:** solo en **Gramos por porción**, con **Grupo de sabores = Mix (servidos)**. No se agrega el mix al kit: el sistema lo descuenta del mix del sabor que el cliente elige (Chocolate, Fresa, Chocolate-Fresa…), y por eso el mix no puede ir fijo en el kit.

#### Paso 2 — Armar el kit de componentes

En la ficha de **Barquilla Plus**, abre la pestaña **Kits**:

1. Abajo, en el buscador, escribe `INS-012` (Cono barquilla) y pulsa **Buscar**.
2. En el resultado, escribe la **Cant.** por unidad vendida: `1`.
3. Marca la casilla **Incrementar/Decrementar stock al cambiar su padre**. Sin esa marca el cono **no baja** del camión al vender.
4. Pulsa **Añadir**. El cono aparece arriba, en la lista de componentes del kit.
5. Repite con `INS-013` (Portacono) y `INS-031` (Servilletas), cantidad `1` cada uno y con la casilla marcada.

El kit de la Barquilla Plus queda así:

| Componente | Cant. | Incrementar/Decrementar stock |
| --- | --- | --- |
| INS-012 Cono barquilla | 1 | Sí |
| INS-013 Portacono | 1 | Sí |
| INS-031 Servilletas | 1 | Sí |

**No agregues al kit** el mix, el sirope, el topping ni el Pirulín/Oreo: salen de los campos del Paso 1, y si también van en el kit se descuentan dos veces.

**Quitar o cambiar un insumo del kit.** La pestaña Kits no tiene botón de eliminar. En la lista de componentes del kit, pon la cantidad del insumo en **0** y pulsa **Guardar** (en la fila de totales, al pie de la lista): los que queden en 0 se borran. Con el mismo botón se cambian cantidades y la marca de descontar stock. Haz el cambio también en local: al importar el paquete, los kits de producción quedan igual que los de local.

#### Paso 3 — Qué baja del camión al venderla

Al cobrar **1 Barquilla Plus de Chocolate**, el camión baja:

| Insumo | Baja | De dónde sale |
| --- | --- | --- |
| Cono barquilla | 1 ud | Kit |
| Portacono | 1 ud | Kit |
| Servilletas | 1 ud | Kit |
| Mix de chocolate | 180 g | Gramos por porción |
| Sirope (granel) | 10 g | Gramos de sirope (receta) |
| Topping (granel) | 10 g | Gramos de topping (receta) |

Si el sabor es **Chocolate-Fresa**, baja **90 g de cada mix**. Si el cliente pide además un **Sirope** o **Topping** adicional (botones + de la Toma de orden), bajan 10 g de sirope o 15 g de topping más, y se cobran aparte.

El costo de la receta sale del **precio de costo** de cada insumo del kit y del mix; el sirope y el topping usan un costo por gramo fijo del módulo (`SABROOSKIPOS_COSTO_G_SIROPE` y `SABROOSKIPOS_COSTO_G_TOPPING`, por defecto 0,0096 y 0,0126 $/g). Con los costos actuales (cono 0,0563 $, portacono 0,03 $, servilleta 0,0042 $, mix 0,002044 $/g), la Barquilla Plus cuesta unos **0,68 $**, frente a 0,44 $ de la Barquilla normal.

#### Paso 4 — Comprobar

1. **Toma de orden:** abre la pestaña **Servido en camión** y verifica que sale *Barquilla Plus* y que pide sabor. Con el doble de mix, el aviso "quedan N" de cada sabor alcanza para la mitad de porciones que una barquilla normal.
2. **Venta de prueba** (en local): vende una, y en **Productos → Stock → Movimientos** (o en **ReportesVentas → Insumos del móvil**) revisa que bajaron 1 cono, 1 portacono, 1 servilleta, 180 g de mix y los gramos de sirope y topping.
3. **Costos y margen:** revisa que tenga costo. Si sale en 0, a algún insumo le falta el precio de costo.
4. **A producción:** si lo creaste en local, pásalo con el paquete de migración (ver *Exportar e importar el catálogo*): viajan el producto, su precio, sus campos de receta, su categoría y su kit.

**Otros casos:**

- **Producto individual** (se vende suelto y baja del camión al venderse: agua, golosina, Oreo…). Ejemplo, *Agua 500 ml*: **Productos → Nuevo producto** con **Ref.** `IND-002` (siguiente número libre), **Etiqueta** "Agua 500 ml", **En venta:** Sí, **En compra:** Sí, **Precio de venta** con IVA, **Tasa IVA**, **Gestion de stocks:** Sí, **Precio de costo** y **Unidad de control** `uds`. Deja vacíos Máx. sabores y Grupo de sabores, y no le pongas kit. En la pestaña **Categorías** asígnale **Individual**: así sale en esa pestaña del POS y de la Toma de orden, y en Carga del camión y Cierre (grupo *Individual y complementos*). Sin precio, déjalo con **En venta:** No.
- **Insumo nuevo** (vaso, desechable): **Nuevo producto** con **En venta:** No, **En compra:** Sí, **Gestion de stocks:** Sí, **Precio de costo** por unidad y **Unidad de control** (`uds` o `g`). Asígnale una categoría de insumos (Envases, Desechables, Complementos); sin ella no aparece en Carga del camión ni en Cierre. Topping y sirope no llevan variedades: todo va a Topping (granel) y Sirope (granel).
- **Congelado o empaquetado** (bandeja, sundae congelado): producto sin kit, con **Gestion de stocks:** Sí y categoría Bandejas 500 g, Sundae congelado o Empaquetado, para que entre en la carga y el cierre.
- **Mix nuevo** (por ejemplo, Mix de mora): **Nuevo producto** con **Ref.** `INS-054` (siguiente libre), **En venta:** No, **En compra:** Sí, **Gestion de stocks:** Sí, **Precio de costo** por gramo y **Unidad de control** `g`. Categoría **Mix**. Así sale en Carga del camión, en Cierre (en gramos) y en Insumos del móvil. Un mix solo no se vende: hace falta un sabor que lo use.
- **Sabor nuevo** (por ejemplo, Mora, o Mora-Fresa): **Nuevo producto** con **Ref.** `SM-07` (siguiente libre), **Etiqueta** con el nombre que verá el cajero, **En venta:** Sí, **En compra:** No, **Gestion de stocks:** No, precio 0. Categoría **Sabores mix**. En la pestaña **Kits** agrega el mix con la fracción que lleva: `1` si es un solo mix; `0.5` y `0.5` si es mitad y mitad. **No** marques *Incrementar/Decrementar stock* (el mix lo descuenta el módulo según los gramos del producto). El sabor aparece en la Toma de orden de todos los servidos, en gris hasta que se cargue su mix.

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

Los seis reportes están en el menú izquierdo de **SabrooskiVentas**. Se filtran por **fecha**, **terminal** y **turno** (Turno 1 (AM), Turno 2 (PM) o **Día completo**), y cada uno se descarga en Excel (.xlsx); también se pueden bajar las cuatro planillas del turno juntas. El selector *Turnos recientes* salta directo a uno.

Un turno abarca desde el cierre de caja del turno anterior hasta su propio cierre X o Z: incluye la carga de la mañana (en el AM), las ventas, las regalías y el conteo hecho con la caja ya cerrada. Un ticket cuenta en el turno en que se cobró. Si la caja del turno sigue abierta, el reporte avisa que los datos son parciales; si no tiene conteo de cierre, avisa que el cierre es lo que dice el sistema.

| Reporte | Qué muestra | Para qué sirve |
| --- | --- | --- |
| Camión congelados | Congelados por sabor, con conos "+N conos" | Cuadrar los congelados del camión |
| Pago móvil y puntos | Pagos móviles y transacciones de punto por lote, crédito y débito | Cuadrar contra el banco y los cierres de lote |
| Especificaciones de ventas | Ventas del turno, resumen de cierre, ventas a crédito y observaciones (descuentos, regalías, dañadas) | La planilla principal del turno |
| Inventario de ventas | Por producto: existencia, reposición, vendido, regalías, crédito, dañadas, lo gastado por receta y cierre | Ver qué se movió y dónde hay diferencias |
| Insumos del móvil | Por insumo: existencia anterior (en el PM, lo que dejó el AM después de su conteo), **carga inicial** (existencia inicial de la mañana), reposición, consumo real (todo eso − regalías/dañadas − cierre) frente al teórico de las ventas | Detectar faltantes o porciones mal servidas |
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
| Cambio de turno no deja contar | La caja del turno sigue abierta | Cerrarla con el Reporte X (o Z) de POSPlus y pulsar *Ya cerré la caja: continuar* |
| Conté antes de cerrar la caja | El conteo quedó a la hora en que se hizo, dentro del turno | Si luego hubo ventas, volver a contar después del cierre X en Cambio de turno |
| La existencia inicial salió en el turno de ayer | La caja de ayer quedó abierta | Cerrar esa caja en POSPlus antes de cargar; Carga del camión avisa cuando pasa |
| El reporte del turno no muestra un ticket | El ticket se cobró en otro turno | Verlo en el turno en que se cobró, o en *Día completo* |
| Quedó mercancía en el camión después de devolver | Se movió algo después de la devolución | Contarla en Cierre (conteo) o devolverla con un traslado |

**¿Se puede corregir un cierre?** Sí: en **Cierre (conteo)** elige el turno y vuelve a registrar el conteo; el sistema ajusta a la nueva cifra. Hazlo antes de que avance el turno siguiente, porque el conteo se compara con la existencia de ahora.

**¿Quién ve el PIN?** El PIN no se muestra en pantalla, pero se teclea en el equipo del encargado.
