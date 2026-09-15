# Multi-conteo en Auditorías de Stock — Plan de trabajo

> **Estado:** propuesta para discutir (no implementado).
> **Objetivo:** traer a Inventoros las funcionalidades de **multi-conteo** que ya
> existen y están en producción en **TOMFIC**, adaptadas al stack de Inventoros
> (Laravel 13 + Inertia + Vue 3 + Postgres).
> **Rama propuesta:** `feat/audit-multi-round` (fork `mia-corral-developer/Inventoros`).

---

## 1. Contexto y motivación

Hoy una auditoría de stock en Inventoros solo permite **un conteo por producto**,
atribuido a **una sola persona**. No hay verificación cruzada ni forma de detectar
discrepancias entre contadores.

**El caso de uso que falta** (y que el dueño del negocio pidió): poner **3 personas a
contar el mismo inventario**, cada una a ciegas, y que el sistema **compare los
conteos** y marque dónde no coinciden — el patrón clásico de control de mermas
(recuento ciego ciego / blind count + ronda de desempate).

**TOMFIC ya resuelve esto** con rondas **C1 / C2 / C3**:

- Cada conteo se asigna a 3 usuarios (`usuarioC1`, `usuarioC2`, `usuarioC3`).
- Cada **captura** guarda `conteoId` + `productoId` + **ronda (C1/C2/C3)** + `cantidad`.
- El sistema **suma C1 y C2 por producto**; los productos donde **C1 ≠ C2** pasan a la
  ronda **C3** (desempate), donde solo se cuentan los productos divergentes.
- Las rondas se **cierran y reabren de forma atómica** (`close_count_round` /
  `reopen_count_round`, con `FOR UPDATE`) para que C1 y C2 no se sobrescriban.
- Capturas **offline** (localStorage + sync al reconectar) y export a Excel.

Este documento lleva ese diseño a Inventoros. **No se copia el código** (stack y modelo
de datos distintos): se **porta el patrón**.

**Referencia:** `github.com/Edwinc1987/tomfic` — `src/modules/ModCapturador.jsx`,
`supabase/close_count_round.sql`, `supabase/multi_inventario_plan.sql`.

---

## 2. Estado actual en Inventoros (punto de partida)

### Tablas existentes

| Tabla | Relevante para el multi-conteo |
|---|---|
| `stock_audits` | El "conteo": `audit_number`, `status` (draft/in_progress/completed/cancelled), `audit_type` (full/cycle/spot), `warehouse_location_id`, `created_by`. |
| `stock_audit_items` | **UN conteo por producto**: `product_id`, `product_variant_id`, `location_id`, `system_quantity`, `counted_quantity`, `discrepancy`, `status` (pending/counted/verified/adjusted), `counted_by`, `counted_at`. |
| `stock_adjustments` | El asiento que **mueve el stock real**: `type='recount'`, `reference` → auditoría. |

### Comportamiento problemático

- `StockAuditController::updateCount()` **sobrescribe** `counted_quantity` /
  `counted_by` / `counted_at`. No hay historial: el último conteo gana.
- `store()` genera **exactamente 1 ítem por producto** automáticamente. No hay UI para
  asignar contadores ni para que varias personas cuenten el mismo producto.
- `complete()` recorre los ítems contados, calcula la discrepancia y llama
  `StockAdjustment::adjust(..., type: 'recount', ...)` en transacción con
  `lockForUpdate`. **Esto se reutiliza tal cual** — el multi-conteo solo cambia *de
  dónde* sale el número final (`resolved_quantity`).

---

## 3. Diseño propuesto (adaptado a Laravel + Postgres)

### 3.1 Tablas nuevas

**`stock_audit_rounds`** — una fila por ronda de conteo.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `stock_audit_id` | bigint FK | → `stock_audits` |
| `round_number` | smallint | 1 = C1, 2 = C2, 3 = C3 (desempate), … |
| `label` | string(8) | `C1` / `C2` / `C3` (para UI y export) |
| `is_tiebreak` | boolean | true solo para la última ronda |
| `assigned_to` | bigint FK nullable | usuario contador (null = cualquiera con permiso) |
| `status` | enum | `open` / `closed` |
| `closed_at` | timestamp nullable | |
| `created_at` / `updated_at` | | |

- Único `(stock_audit_id, round_number)`.
- Reemplaza los flags `c1_cerrado/c2_cerrado/c3_cerrado` de TOMFIC por una fila limpia
  y generaliza a **N rondas** (TOMFIC tenía 3 fijas).

**`stock_audit_counts`** — la captura cruda, el corazón del port. **N conteos por
producto** (uno por ronda/persona).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `stock_audit_id` | bigint FK | |
| `stock_audit_item_id` | bigint FK | → `stock_audit_items` |
| `round_number` | smallint | coincide con `stock_audit_rounds` |
| `counted_quantity` | integer | lo que contó esa persona en esa ronda |
| `counted_by` | bigint FK | quién contó |
| `counted_at` | timestamp | |
| `notes` | text nullable | |

- Único `(stock_audit_item_id, round_number)` → **un valor por ronda**, pero **muchas
  filas por producto** (las distintas rondas/personas).

### 3.2 Columnas nuevas en tablas existentes

**`stock_audits`**

| Columna | Tipo | Default | Notas |
|---|---|---|---|
| `rounds_total` | smallint | 1 | nº de rondas de captura (sin contar desempate). v1 = 2. |
| `blind` | boolean | true | conteo ciego (cada uno no ve los otros). |
| `current_round` | smallint | 1 | ronda abierta/activa. |

> Retrocompatibilidad: `rounds_total = 1` ⇒ comportamiento idéntico al actual.

**`stock_audit_items`**

| Columna | Tipo | notas |
|---|---|---|
| `resolved_quantity` | integer nullable | valor final elegido tras resolver. |
| `resolution_method` | string nullable | `agreement` / `tiebreak` / `manual`. |

> `counted_quantity` pasa a ser el **resultado resuelto** (o se mantiene `resolved_quantity`
> y `counted_quantity` se conserva por compatibilidad — **decisión abierta, ver §7**).

### 3.3 Backend — `StockAuditRoundService`

Cuatro operaciones, calcadas de la lógica de TOMFIC pero en Laravel:

| Método | Qué hace | Clave |
|---|---|---|
| `recordCount($audit, $item, $round, $qty, $user)` | Guarda una captura en `stock_audit_counts` (upsert por `item+round`). | Valida: ronda **abierta**, auditoría `in_progress`, usuario **asignado** a esa ronda (o admin), y `blind` (no revela otros conteos). |
| `closeRound($audit, $round)` | Cierra una ronda. | **`lockForUpdate`** sobre la fila de la ronda → evita que dos cierres se pisen (el bug que TOMFIC arregló: *"cerrar una ronda ya no cierra las dos"*). |
| `reopenRound($audit, $round)` | Reabre una ronda cerrada. | `lockForUpdate`; recalcula resolución. |
| `resolve($audit)` | Calcula el **valor final por ítem**: si todas las rondas coinciden → ese valor (`agreement`); si difieren y hay ronda de desempate con conteo → **C3** (`tiebreak`); si sigue el conflicto → **lo decide el admin** (`manual`). | Se invoca desde `complete()`. |

**`complete()`** (existente) se extiende: en vez de leer `counted_quantity`, llama
`resolve()` y usa `resolved_quantity` para generar el `StockAdjustment`. **El asiento de
stock no cambia** — sigue siendo `type='recount'`, con `reference` = auditoría. Cero
riesgo para la contabilidad del inventario.

### 3.4 Reglas de resolución (propuesta)

```
Para cada ítem:
  valores = { ronda → cantidad }   (solo rondas contadas)
  si todas las rondas con conteo son iguales            → agreement (ese valor)
  si difieren y existe ronda de desempate con conteo    → tiebreak (valor de C3)
  si difieren y NO hay desempate / C3 no cuenta         → conflict  (requiere admin)
```

En `conflict`, la auditoría **no se puede completar** hasta que el admin resuelva
(elige un valor o marca "no contar").

---

## 4. Decisiones de diseño (recomendaciones)

| # | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| 1 | Nº de rondas | **N configurable** (`rounds_total`, default **2 + 1 desempate**) | 3 fijas como TOMFIC |
| 2 | Ciego / abierto | **Blind = ON por defecto** (es el punto) | flag por auditoría para abrir |
| 3 | Contadores | **Asignar cualquier usuario** a una ronda, sin darle `manage_stock_audits` completo → puede contar desde el móvil | solo usuarios con permiso de auditorías |
| 4 | Resolución de empate | **Automática por C3**, con **aprobación del admin obligatoria** solo si C3 también diverge | siempre pasa por aprobación del admin |
| 5 | Compatibilidad | `rounds_total=1` ⇒ auditorías viejas funcionan igual | forzar todas a ≥2 |

> **Puntos 1 y 4 son los que quiero debatir contigo** (ver §7).

---

## 5. Fases (cada una desplegable y verificable)

### Fase 1 — Datos + backend core (sin UI) ✅ desplegable, invisible
- Migraciones: `stock_audit_rounds`, `stock_audit_counts`, columnas nuevas.
- Modelos Eloquent + relaciones.
- `StockAuditRoundService` (`recordCount` / `closeRound` / `reopenRound` / `resolve`).
- Extender `complete()` para usar `resolve()`; mantener `rounds_total=1` compatible.
- Tests (Pest): cierre atómico, resolución agreement/tiebreak/conflict, compat con 1 ronda.

### Fase 2 — Admin: crear auditoría con N rondas + asignar contadores
- `Create`/`Edit` de auditorías: elegir `rounds_total`, blind sí/no, asignar usuario por ronda.
- i18n `en`/`es` de los campos nuevos.

### Fase 3 — Vista de varianza (admin)
- Tabla **producto × ronda**: los N conteos lado a lado, **divergencias resaltadas**,
  columna de desempate, badge de método (`agreement`/`tiebreak`/`manual`).
- Acción de **resolución manual** para los `conflict`.

### Fase 4 — Vista capturador (móvil)
- Página de conteo **por ronda + ciego**: solo muestra lo asignado a esa persona.
- **Reusa `BarcodeScannerModal`** que Inventoros ya tiene.
- Botones cerrar/reabrir ronda (con las validaciones del servicio).

### Fase 5 — Reportes / export
- Desglose por ronda en el reporte de captura.
- Export Excel/CSV con columnas C1/C2/C3 y método de resolución.
- i18n completo.

---

## 6. Fuera de alcance (v1)

- **Offline-first real** (TOMFIC lo tiene con service worker + cola de sync). Inventoros
  es **Inertia server-driven**; el offline es un proyecto aparte. → **Fase futura**.
- **Barcode scan en el backend**: entra en Fase 4 (el componente ya existe).
- **Múltiples inventarios activos simultáneos** (pendiente aparte de TOMFIC, no aplica aquí).

---

## 7. Preguntas abiertas (a debatir)

1. **¿N rondas configurables o fijamos 3 como TOMFIC?**
   - N configurable = más flexible, un poco más de UI. Default 2 + desempate.
   - 3 fijas = más simple, igual que TOMFIC, pero rígido.
2. **¿La resolución de empate (C3) es automática o siempre pasa por aprobación del admin?**
   - Automática con C3 = menos fricción, pero el admin pierde control.
   - Aprobación obligatoria = más control, más trabajo manual.
3. **¿`counted_quantity` sobrevive o se reemplaza por `resolved_quantity`?**
   - Mantener ambos = compat total, un poco redundante.
   - Reemplazar = limpio, pero hay que migrar/actualizar todo lo que lo lee.
4. **¿Quién puede cerrar una ronda?** ¿Solo el contador asignado, o cualquier admin?
5. **¿Los contadores ven su propio conteo previo al recontar?** (relacionado con blind).

---

## 8. Verificación (cómo sabremos que funciona)

- **Tests Pest** del servicio: cierre atómico (dos cierres concurrentes), resolución en
  los 3 casos, retrocompat `rounds_total=1`.
- **Auditoría de prueba** con 3 usuarios y 1 producto divergente a propósito:
  - C1 = 10, C2 = 12 → el producto entra a **C3**.
  - C3 = 12 → `resolved = 12`, método `tiebreak`.
  - `complete()` genera **1 `StockAdjustment`** con la diferencia correcta.
- **Regresión:** una auditoría vieja (1 ronda) se completa igual que antes.
- Verificación **en navegador real** (el HTML de curl no muestra datos: Inertia renderiza
  en cliente).

---

## 9. Anexo — trazabilidad con TOMFIC

| Concepto TOMFIC | Equivalente propuesto en Inventoros |
|---|---|
| `conteos` | `stock_audits` |
| `usuarioC1 / usuarioC2 / usuarioC3` | `stock_audit_rounds.assigned_to` (N rondas) |
| `capturas { conteoId, productoId, ronda, cantidad }` | `stock_audit_counts` |
| flags `c1_cerrado/c2_cerrado/c3_cerrado` + `rondas_cerradas` | `stock_audit_rounds.status` (`open`/`closed`) |
| `close_count_round` / `reopen_count_round` (RPC `FOR UPDATE`) | `StockAuditRoundService::closeRound/reopenRound` (`lockForUpdate`) |
| `prodsC3 = C1 ≠ C2` | `resolve()` → ítems divergentes a ronda de desempate |
| capturas offline (localStorage) | **fuera de alcance v1** |
