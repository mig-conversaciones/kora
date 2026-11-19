# Lista ASVS 5.0.0 nivel 2 — KORA {{VERSION}}

> Archivo generado por `php herramientas/generar-asvs.php` a partir del texto oficial (`docs/seguridad/fuentes/`) y del estado de `docs/seguridad/asvs-estado.csv`. No se edita a mano: se cambia el estado y se regenera. Una prueba unitaria (`ListaAsvsTest`) falla si falta un requisito o si este archivo no coincide con su fuente.

- **Estándar:** OWASP Application Security Verification Standard 5.0.0 ({{FUENTE}}, licencia CC BY-SA 4.0). Los textos de los requisitos se citan en inglés, tal como los publica OWASP.
- **Alcance (RS-001):** niveles 1 y 2 de los capítulos V1 a V8 y V11 a V16. V9 (tokens autocontenidos), V10 (OAuth y OIDC) y V17 (WebRTC) no aplican: la v1 no usa JWT, OAuth ni WebRTC.
- **Uso (RGO-009):** antes de cada versión de producción se revisa el estado de cada requisito, se revisan los hallazgos de SAST y DAST, y firma el responsable de seguridad. No se despliega ninguna versión sin la lista firmada.

## Estados

| Estado | Significado |
|---|---|
| Cumple | Implementado, con su evidencia en el código, las pruebas o la documentación |
| Parcial | Implementado en parte; la evidencia dice qué falta |
| Staging | Depende del servidor de Hostinger; se verifica en staging (SRS 4.8) |
| Decisión | Brecha frente al nivel 2 que requiere aceptación del riesgo o un cambio que decide la contraparte |
| No aplica | La funcionalidad no existe en la v1, con su justificación |

## Resumen

{{RESUMEN}}

## Decisiones pendientes de la contraparte

{{DECISIONES}}

## Requisitos por capítulo

{{CAPITULOS}}

## Inventario criptográfico

Responde a V11.1.2. Se revisa en cada versión junto con esta lista.

| Uso | Algoritmo | Biblioteca | Claves y dónde viven |
|---|---|---|---|
| Contraseñas | Argon2id (64 MiB, 3 iteraciones, 1 hilo) | `password_hash` de PHP | Sin clave; sal por contraseña |
| Datos personales y secretos TOTP | XChaCha20-Poly1305 IETF (AEAD), con contexto como datos asociados | libsodium (`Cifrador`) | `CLAVES_CIFRADO`: llavero con versiones, 256 bits, en el `.env` |
| Búsqueda de datos personales sin descifrar | HMAC-SHA-256 | `hash_hmac` | `CLAVE_HMAC_PII`, en el `.env` |
| Códigos de respaldo de 2FA | HMAC-SHA-256 | `hash_hmac` | `CLAVE_HMAC_CODIGOS`, en el `.env` |
| TOTP (RFC 6238) | HMAC-SHA-1, 6 dígitos, 30 s | `hash_hmac` | Secreto de 160 bits por usuario, cifrado con `Cifrador` |
| Tokens de sesión, invitación y recuperación | 256 bits aleatorios; en la base solo su SHA-256 | `random_bytes`, `hash` | Sin clave |
| Copias de seguridad | XChaCha20-Poly1305 por bloques de 64 KiB (secretstream), clave por archivo sellada con `crypto_box_seal` (X25519) | libsodium (`SobreCifrado`) | Llave pública del custodio en el `.env`; la privada, fuera del servidor |
| Cadena de auditoría, manifiestos e integridad | SHA-256 | `hash` | Sin clave |
| Tokens del formulario de importación | HMAC-SHA-256 | `hash_hmac` | Derivada del llavero de la aplicación |
| Transporte | TLS 1.2 o superior | LiteSpeed (Hostinger), PHPMailer | Certificado de Let's Encrypt |

## Inventario de registros

Responde a V16.1.1.

| Registro | Dónde | Formato | Qué contiene | Retención |
|---|---|---|---|---|
| Auditoría | Tabla `eventos_auditoria`, encadenada en `cadena_auditoria` | Filas estructuradas, exportables por lotes firmados | Actor, rol, centro, unidad, acción, recurso, resultado, IP tratada (HMAC), request_id, valores antes y después sin datos personales | Según RAU (5 años) |
| Aplicación | `almacenamiento/registros/*.log` | JSON por líneas | Eventos técnicos, errores con request_id, sin secretos | 90 días |
| Errores de PHP | `error_log` del servidor | Texto del servidor | Fallos de arranque, solo con el tipo de error | Del proveedor |
| Cola | Tablas `trabajos` y `trabajos_fallidos` | Filas estructuradas | Tipo, estado, intentos y último error, sin datos personales | 30 días para los completados |
| Copias y despliegues | Tablas `respaldos` y `despliegues` | Filas estructuradas | Identificador, cifras de control, huellas y responsable | Permanente |

## Hallazgos de SAST y DAST de esta versión

{{HALLAZGOS}}

## Firma (RGO-009)

| Rol | Nombre | Fecha | Firma |
|---|---|---|---|
| Responsable de seguridad | | | |
| Líder técnico | | | |

La firma declara que se revisó el estado de cada requisito y los hallazgos de SAST y DAST de la versión, y que no quedan hallazgos altos abiertos (RS-016).
