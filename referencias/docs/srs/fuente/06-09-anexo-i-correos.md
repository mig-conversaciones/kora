## Anexo I. Plantillas de correo

**Reglas comunes:**

- **Formato.** Versión HTML accesible (tabla de una columna, contraste AA, sin imágenes remotas) y versión de texto plano.
- **Remitente.** `CORREO_REMITENTE`, del dominio, con SPF, DKIM y DMARC configurados (RI-009).
- **Contenido.** Sin datos sensibles: nunca contraseñas, códigos ni identificaciones.
- **Enlaces.** Solo enlaces de un solo uso cuando corresponde, y siempre al dominio oficial.
- **Contexto.** Cada correo de seguridad indica qué hacer si el usuario no reconoce la acción.
- **Envío.** Por la cola (`correo.enviar` o `correo.sistema`); el destinatario va como ID y su dirección se resuelve al enviar.

| Plantilla (`vistas/correos/`) | Disparador | Requisito |
|---|---|---|
| `invitacion` | Alta de usuario | RAC-007 |
| `recuperacion` | Solicitud de recuperación | RAC-006 |
| `contrasena_cambiada` | Cambio o restablecimiento de contraseña | RAC-008 |
| `segundo_factor` | Activación, desactivación, restablecimiento de 2FA o uso de un código de respaldo | RAC-011, RAC-014 |
| `importacion_resultado` | Lote aplicado, con errores o fallido | RF-085 |
| `exportacion_lista` | Exportación completada | RF-080 |
| `aviso_mantenimiento` | Ventana programada, enviada 48 h antes | RNF-006 |
| `alerta_operativa` | Cola atascada, integridad, seguridad o certificado | RF-Q-016, RS-022, RAC-031 |

```text
ASUNTO: Te damos la bienvenida a Producción CAB
Hola {{nombre}}:
Se creó una cuenta para ti en Producción CAB, el sistema de gestión de la producción del Centro Agropecuario de Buga,
con el rol {{rol}}.
Activa tu cuenta y define tu contraseña en este enlace (válido por 72 horas y de un solo uso):
{{enlace_activacion}}
{{#requiere_2fa}}Por el rol asignado deberás configurar la verificación en dos pasos con una aplicación de
autenticación antes de ingresar.{{/requiere_2fa}}
Si no esperabas este correo, ignóralo: sin activación la cuenta no puede usarse.
— Producción CAB · SENA, Centro Agropecuario de Buga
```

```text
ASUNTO: Restablecimiento de contraseña de Producción CAB
Hola:
Recibimos una solicitud para restablecer la contraseña de la cuenta asociada a este correo.
Usa este enlace dentro de los próximos 30 minutos (solo funciona una vez):
{{enlace_recuperacion}}
Al restablecerla se cerrarán todas tus sesiones abiertas.
Si no hiciste esta solicitud, puedes ignorar este mensaje; tu contraseña actual sigue siendo válida.
Te recomendamos informar a la coordinación del centro si recibes estos correos sin haberlos pedido.
```

```text
ASUNTO: Tu contraseña de Producción CAB cambió
Hola {{nombre}}:
La contraseña de tu cuenta se cambió el {{fecha_hora_bogota}}.
Si fuiste tú, no necesitas hacer nada.
Si no reconoces este cambio, restablece tu contraseña de inmediato desde {{enlace_recuperar}} y avisa a la
coordinación del centro.
```

```text
ASUNTO: Cambio en la verificación en dos pasos de tu cuenta
Hola {{nombre}}:
El {{fecha_hora_bogota}} se registró este cambio en la verificación en dos pasos de tu cuenta: {{evento}}
(activación | desactivación | restablecimiento por el administrador | uso de un código de respaldo).
{{#codigos_restantes}}Te quedan {{codigos_restantes}} códigos de respaldo; puedes generar nuevos en «Mi cuenta → Seguridad».{{/codigos_restantes}}
Si no reconoces este cambio, contacta de inmediato a la coordinación del centro.
```

```text
ASUNTO: Importación {{fuente}}: {{estado}}
Hola {{nombre}}:
La importación «{{nombre_archivo}}» ({{fuente}}) terminó con estado: {{estado}}.
Filas nuevas: {{filas_nuevas}} · actualizadas: {{filas_actualizadas}} · sin cambios: {{filas_sin_cambios}} · con error: {{filas_error}}.
{{#filas_error}}Descarga el reporte de errores, corrige las filas y vuelve a cargar el archivo: {{enlace_lote}}{{/filas_error}}
{{^filas_error}}Puedes revisar el resultado y las cifras de control en: {{enlace_lote}}{{/filas_error}}
```

```text
ASUNTO: Tu exportación está lista
Hola {{nombre}}:
La exportación «{{tipo}}» en formato {{formato}} está lista. Descárgala desde Producción CAB antes del {{expira_bogota}}
(el archivo se elimina después de 24 horas): {{enlace_trabajo}}
El archivo contiene información de uso interno. No lo reenvíes fuera de la entidad.
```

```text
ASUNTO: Mantenimiento programado de Producción CAB — {{fecha_bogota}}
Hola:
Producción CAB estará en mantenimiento el {{fecha_bogota}} entre las {{hora_inicio}} y las {{hora_fin}} (hora de Colombia).
Durante ese tiempo no podrás registrar ni consultar información. Las importaciones y exportaciones pendientes se
procesarán al terminar.
Te recomendamos guardar tu trabajo antes del inicio. Gracias por tu comprensión.
```

```text
ASUNTO: [Producción CAB] Alerta operativa: {{tipo_alerta}}
Detectado: {{fecha_hora_bogota}}
Condición: {{descripcion}} (umbral: {{umbral}})
Acción sugerida: consultar el runbook {{runbook}} y el panel Sistema → Salud.
Código de correlación: {{correlacion}}
(Esta alerta no se repetirá durante {{silencio_minutos}} minutos para la misma condición.)
```
