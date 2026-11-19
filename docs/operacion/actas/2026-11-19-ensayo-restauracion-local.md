# Acta de prueba de restauración (RDL-005)

| Campo | Valor |
|---|---|
| Fecha (UTC) | 2026-11-19 10:55 |
| Entorno | local (ensayo: no sustituye la prueba trimestral en staging) · base `kora_carga` · 5.7.24 · PHP 8.3.36 |
| Versión de KORA | 0.8.0 · migración 014_indices_listados |
| Copia | `kora-manual-20261119T105341Z-2432` · 66,0 MB cifrados · 29,9 s |
| Base aislada | `kora_restauracion` (vaciada antes de restaurar) |
| Tiempo de restauración | 38,3 s, frente al RTO de 4 h: cumple |
| Filas comparadas | 62.207 en 12 tablas, por centro, con sus sumas de control |
| Auditoría | 825.014 eventos; cadena íntegra, recalculada evento por evento |
| Huella de las cifras | origen `eab97cadae9c8c8a…` · restaurada `eab97cadae9c8c8a…` |
| Cálculo de las cifras | 10,6 s |
| Resultado | **Conforme**: conteos, sumas, migración y cadena coinciden. |

## Diferencias

Ninguna.

## Acciones correctivas

Ninguna.

## Firmas

- Ejecutó: ______________________ · Revisó (contraparte): ______________________
