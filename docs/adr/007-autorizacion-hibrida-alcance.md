# ADR-007: Autorización híbrida y repositorios con alcance obligatorio (sin RLS nativo)

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RAC-023 a RAC-028, RDI-001 a RDI-008

## Contexto

MariaDB no tiene seguridad a nivel de fila. Los líderes de unidad solo deben ver su unidad, y la coordinación, todo el centro.

## Decisión

RBAC con permisos `recurso.accion` más ABAC por unidades asignadas. `AlcanceDatos` se construye solo en el servidor y `RepositorioConAlcance` antepone su condición a toda consulta de negocio; un alcance vacío produce `1 = 0`. El enrutador rechaza al arrancar cualquier ruta sin permiso que no sea pública (denegación por defecto, implementado desde la I0).

## Alternativas consideradas

- Vistas por unidad en la base: difíciles de mantener y de probar.
- Filtros manuales en cada consulta: propensos a omisiones.

## Consecuencias

- Matriz de autorización probada en la I2.
- Fuera del alcance se responde 404 para no revelar la existencia del recurso.

## Evolución

Si se migra a un motor con RLS, se puede reforzar con políticas en la base.
