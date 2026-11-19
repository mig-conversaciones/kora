# Runbook: despliegue y reversión

- **Severidad típica:** Media (operación planificada). Alta si fallan las pruebas de humo.
- **Disparador:** cada versión aprobada (DoD, RGO-003) y la lista ASVS firmada (RGO-009).
- **Impacto:** sin mantenimiento, ninguno visible; el cambio de `current` es atómico. Con mantenimiento, la aplicación responde 503 con el aviso hasta que termina.
- **Responsable:** líder técnico. **Escalamiento:** titular de la cuenta de Hostinger, por el canal del proyecto.

Requisitos: ROB-001 a ROB-006, ROB-013, RDL-002, RDL-004. Decisiones: [ADR-011](../adr/011-despliegue-versiones.md) y [ADR-021](../adr/021-operacion-respaldos-despliegue.md).

## Estructura en el servidor

```text
$RAIZ_APP = /home/USUARIO/domains/DOMINIO/kora
├── releases/vX.Y.Z/   código de cada versión (se conservan las 5 últimas)
├── current -> releases/vX.Y.Z
├── compartido/.env               (permisos 600)
├── compartido/almacenamiento/
└── respaldos/                    (RESPALDO_DIRECTORIO)
```

`public_html` apunta a `current/public` o usa la alternativa B (4.8.1).

## Diagnóstico previo

1. **CI en verde.** En el commit que se despliega pasan los trabajos *PHP*, *Activos* y *Despliegue, reversión y restauración*.
2. **Migraciones.** Revisar las de la versión:
   - si alguna es incompatible hacia atrás (la versión N−1 no funciona sobre el esquema N), se despliega `--con-mantenimiento` (ROB-006);
   - si son *expand/contract*, sin mantenimiento.
3. **Copias.** Hay una copia diaria de menos de 26 h: lo indica `php current/cli/respaldo.php listar`, o el detalle de `/health/ready` con sesión de superadministrador.

## Despliegue

1. **Empaquetar en la CI o en un Linux con el repositorio:**

   ```sh
   scripts/empaquetar.sh vX.Y.Z
   ```

   Sube `kora-vX.Y.Z.tar.gz` al servidor por SFTP o SSH.
2. **Desplegar en el servidor:**

   ```sh
   export RAIZ_APP=$HOME/domains/DOMINIO/kora
   bash $RAIZ_APP/current/scripts/desplegar.sh vX.Y.Z ~/kora-vX.Y.Z.tar.gz [--con-mantenimiento]
   ```

   Pasos que ejecuta:
   1. Verifica el manifiesto.
   2. Enlaza el `.env` y el almacenamiento.
   3. Corre `verificar_entorno`.
   4. Con `--con-mantenimiento`: activa el mantenimiento y espera el drenaje, hasta 15 min.
   5. Hace la copia `predespliegue` y aplica las migraciones.
   6. Cambia `current` con `ln -sfn` y `mv -T`.
   7. Vacía la caché y corre `cli/humo.php`.
   8. Registra el despliegue (`sistema.desplegar`).
   9. Si usó mantenimiento, lo desactiva y comprueba la salida (RF-MAN-016).
3. **OPcache.** Tras el cambio de `current`, LiteSpeed puede servir rutas reales en caché hasta unos 120 s (RNF-PERF-014). Verificar con `php current/cli/humo.php --url=https://DOMINIO` y `cat current/VERSION` que se sirve la versión nueva; si una página muestra comportamiento viejo pasados 2 min, reiniciar los procesos PHP desde hPanel.

## Reversión

1. **Solo los archivos.** Es el caso normal: las migraciones son compatibles.

   ```sh
   bash $RAIZ_APP/current/scripts/rollback.sh [--version vX.Y.W]
   ```

   Activa el mantenimiento, cambia `current` a la versión anterior, vacía la caché, corre las pruebas de humo, registra `sistema.revertir` y desactiva el mantenimiento. Objetivo: 10 minutos o menos (ROB-004); la CI lo ensaya en cada push.
2. **Con la base.** Es el último recurso, con aceptación explícita de la pérdida (D-14). Requiere la llave del custodio:

   ```sh
   bash $RAIZ_APP/current/scripts/rollback.sh --con-bd kora-predespliegue-AAAAMMDDTHHMMSSZ-xxxx --identidad /ruta/custodio.par --acepto-perdida
   ```

   Se pierde lo escrito después de esa copia. Hay que comunicarlo antes a la contraparte. Al terminar, se borra `custodio.par` del servidor.

## Verificación

- `readlink $RAIZ_APP/current` apunta a la versión esperada.
- `php current/cli/humo.php` termina en verde.
- `/health/ready` con el token responde 200.
- `php current/cli/mantenimiento.php estado` responde «inactivo».
- La tabla `despliegues` y la auditoría (`sistema.desplegar` o `sistema.revertir`) registran la versión, el commit, la copia previa y la duración (ROB-013).

## Comunicación

- **Con mantenimiento:** aviso a los usuarios con al menos 24 h de anticipación (plantilla del [runbook de mantenimiento](mantenimiento.md)).
- **Al terminar:** nota en el canal del proyecto con la versión, la duración y las incidencias, y la entrada en el CHANGELOG.

## Después del incidente

Si hubo reversión:

- registrar la causa raíz en la bitácora;
- agregar una prueba que la habría detectado;
- actualizar este runbook.
