# Lista ASVS 5.0.0 nivel 2 — KORA 0.9.0

> Archivo generado por `php herramientas/generar-asvs.php` a partir del texto oficial (`docs/seguridad/fuentes/`) y del estado de `docs/seguridad/asvs-estado.csv`. No se edita a mano: se cambia el estado y se regenera. Una prueba unitaria (`ListaAsvsTest`) falla si falta un requisito o si este archivo no coincide con su fuente.

- **Estándar:** OWASP Application Security Verification Standard 5.0.0 (`docs/seguridad/fuentes/OWASP_ASVS_5.0.0_en.csv`, SHA-256 `98c8fe911b9edb40…`, licencia CC BY-SA 4.0). Los textos de los requisitos se citan en inglés, tal como los publica OWASP.
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

| Capítulo | Cumple | Parcial | Staging | Decisión | No aplica | Total |
|---|---|---|---|---|---|---|
| V1 Encoding and Sanitization | 18 | 0 | 0 | 0 | 9 | 27 |
| V2 Validation and Business Logic | 11 | 0 | 0 | 0 | 0 | 11 |
| V3 Web Frontend Security | 13 | 0 | 4 | 0 | 2 | 19 |
| V4 API and Web Service | 1 | 1 | 2 | 0 | 6 | 10 |
| V5 File Handling | 8 | 0 | 0 | 1 | 0 | 9 |
| V6 Authentication | 26 | 1 | 0 | 1 | 7 | 35 |
| V7 Session Management | 15 | 1 | 0 | 0 | 2 | 18 |
| V8 Authorization | 7 | 0 | 0 | 0 | 0 | 7 |
| V11 Cryptography | 12 | 1 | 0 | 0 | 1 | 14 |
| V12 Secure Communication | 1 | 1 | 4 | 0 | 3 | 9 |
| V13 Configuration | 9 | 0 | 2 | 2 | 0 | 13 |
| V14 Data Protection | 8 | 1 | 0 | 0 | 0 | 9 |
| V15 Secure Coding and Architecture | 10 | 2 | 1 | 0 | 0 | 13 |
| V16 Security Logging and Error Handling | 15 | 1 | 0 | 0 | 0 | 16 |
| **Total** | **154** | **9** | **13** | **4** | **30** | **210** |

- **V9, fuera de alcance:** No hay tokens autocontenidos (JWT): las sesiones son de referencia en la base (ADR-006).
- **V10, fuera de alcance:** No hay OAuth ni OIDC: la autenticación es propia (contraseña y TOTP).
- **V17, fuera de alcance:** No hay WebRTC.

## Decisiones pendientes de la contraparte

- **V5.4.3:** No hay antivirus: el hosting compartido no ofrece uno invocable desde PHP. Mitigación: tipos permitidos con firma, sin ejecución, descarga como adjunto con nosniff. Requiere aceptación del riesgo o un servicio de escaneo.
- **V6.3.3:** La 2FA es obligatoria para los roles privilegiados (superadministrador y administrador). Para el resto, la contraseña tiene 15 caracteres como mínimo. ASVS nivel 2 exige 2FA para todos. Para cumplir: UPDATE roles SET requiere_2fa = 1; requiere decisión de la contraparte por su impacto en los usuarios de campo.
- **V13.2.5:** El hosting compartido no permite una lista de salida en el servidor. Requiere aceptación del riesgo, mitigado por V13.2.4.
- **V13.3.1:** Los secretos viven en el .env (permisos 600, fuera de public_html) con huella vigilada (VerificadorIntegridad). Hostinger no ofrece un almacén de secretos. Requiere aceptación del riesgo.

## Requisitos por capítulo

### V1 Encoding and Sanitization

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V1.1.1 | 2 | Verify that input is decoded or unescaped into a canonical form only once, it is only decoded when encoded data in that form is expected, and that this is done before processing the input further, for example it is no… | Cumple | La entrada se normaliza una sola vez al recibirla (app/Nucleo/Entrada.php, app/Importacion/Normalizador.php), antes de validar. |
| V1.1.2 | 2 | Verify that the application performs output encoding and escaping either as a final step before being used by the interpreter for which it is intended or by the interpreter itself. | Cumple | El escape se hace en la plantilla, justo al imprimir (Vista::e con htmlspecialchars ENT_QUOTES \| ENT_SUBSTITUTE). |
| V1.2.1 | 1 | Verify that output encoding for an HTTP response, HTML document, or XML document is relevant for the context required, such as encoding the relevant characters for HTML elements, HTML attributes, HTML comments, CSS, o… | Cumple | Vista::e para HTML y atributos; JSON con json_encode; las cabeceras se arman con constantes de código. Sin HTML en comentarios, CSS ni XML generados con entrada. |
| V1.2.2 | 1 | Verify that when dynamically building URLs, untrusted data is encoded according to its context (e.g., URL encoding or base64url encoding for query or path parameters). Ensure that only safe URL protocols are permitted… | Cumple | Url::a y rawurlencode en parámetros; «volver» solo admite rutas internas (Autorizar::rutaInternaSegura). No se construyen URL con esquemas de la entrada. |
| V1.2.3 | 1 | Verify that output encoding or escaping is used when dynamically building JavaScript content (including JSON), to avoid changing the message or document structure (to avoid JavaScript and JSON injection). | Cumple | No hay JavaScript generado en el servidor: CSP sin 'unsafe-inline'; los datos viajan en atributos data-* escapados y respuestas json_encode. |
| V1.2.4 | 1 | Verify that data selection or database queries (e.g., SQL, HQL, NoSQL, Cypher) use parameterized queries, ORMs, entity frameworks, or are otherwise protected from SQL Injection and other database injection attacks. Th… | Cumple | PDO con sentencias preparadas y ATTR_EMULATE_PREPARES=false (config/base_datos.php). Los nombres de tabla interpolados salen de constantes de las fuentes, nunca de la entrada. ZAP activo sin inyección (trabajo «navegador»). |
| V1.2.5 | 1 | Verify that the application protects against OS command injection and that operating system calls use parameterized OS queries or use contextual command line output encoding. | Cumple | Único uso de procesos: mysqldump/mysql en ServicioRespaldo con proc_open y arreglo de argumentos (sin shell). Ningún argumento viene de un usuario web. |
| V1.2.6 | 2 | Verify that the application protects against LDAP injection vulnerabilities, or that specific security controls to prevent LDAP injection have been implemented. | No aplica | No hay LDAP. |
| V1.2.7 | 2 | Verify that the application is protected against XPath injection attacks by using query parameterization or precompiled queries. | No aplica | No se ejecutan consultas XPath; los .xlsx se leen con OpenSpout sin expresiones de la entrada. |
| V1.2.8 | 2 | Verify that LaTeX processors are configured securely (such as not using the "--shell-escape" flag) and an allowlist of commands is used to prevent LaTeX injection attacks. | No aplica | No hay procesadores LaTeX. |
| V1.2.9 | 2 | Verify that the application escapes special characters in regular expressions (typically using a backslash) to prevent them from being misinterpreted as metacharacters. | Cumple | No se construyen expresiones regulares con texto del usuario; todas son constantes del código. |
| V1.3.1 | 1 | Verify that all untrusted HTML input from WYSIWYG editors or similar is sanitized using a well-known and secure HTML sanitization library or framework feature. | No aplica | No hay editores WYSIWYG ni entrada HTML. |
| V1.3.2 | 1 | Verify that the application avoids the use of eval() or other dynamic code execution features such as Spring Expression Language (SpEL). Where there is no alternative, any user input being included must be sanitized b… | Cumple | Sin eval ni ejecución dinámica en PHP ni en JavaScript; CSP sin 'unsafe-eval' y Alpine en su compilación CSP. |
| V1.3.3 | 2 | Verify that data being passed to a potentially dangerous context is sanitized beforehand to enforce safety measures, such as only allowing characters which are safe for this context and trimming input which is too long. | Cumple | Validadores con listas de valores, rangos y longitudes máximas en cada campo (Entrada, Validadores de cada servicio). |
| V1.3.4 | 2 | Verify that user-supplied Scalable Vector Graphics (SVG) scriptable content is validated or sanitized to contain only tags and attributes (such as draw graphics) that are safe for the application, e.g., do not contain… | Cumple | Los adjuntos solo admiten PDF, PNG y JPEG con firma inicial verificada (ServicioAdjuntos::TIPOS); no se aceptan SVG. |
| V1.3.5 | 2 | Verify that the application sanitizes or disables user-supplied scriptable or expression template language content, such as Markdown, CSS or XSL stylesheets, BBCode, or similar. | No aplica | Los usuarios no envían Markdown, CSS, XSL ni plantillas. La ayuda en línea se escribe en el repositorio. |
| V1.3.6 | 2 | Verify that the application protects against Server-side Request Forgery (SSRF) attacks, by validating untrusted data against an allowlist of protocols, domains, paths and ports and sanitizing potentially dangerous ch… | Cumple | Sin solicitudes salientes a destinos de la entrada: solo el SMTP configurado y la comprobación del certificado del propio APP_URL (MonitorCertificado). |
| V1.3.7 | 2 | Verify that the application protects against template injection attacks by not allowing templates to be built based on untrusted input. Where there is no alternative, any untrusted input being included dynamically dur… | Cumple | Las plantillas son archivos PHP fijos; el nombre de la plantilla siempre sale del controlador. |
| V1.3.8 | 2 | Verify that the application appropriately sanitizes untrusted input before use in Java Naming and Directory Interface (JNDI) queries and that JNDI is configured securely to prevent JNDI injection attacks. | No aplica | No hay JNDI (no es Java). |
| V1.3.9 | 2 | Verify that the application sanitizes content before it is sent to memcache to prevent injection attacks. | No aplica | No hay memcache; la caché es de archivos con claves hash (app/Nucleo/Cache.php). |
| V1.3.10 | 2 | Verify that format strings which might resolve in an unexpected or malicious way when used are sanitized before being processed. | Cumple | Las cadenas de formato (sprintf, number_format) son constantes del código. |
| V1.3.11 | 2 | Verify that the application sanitizes user input before passing to mail systems to protect against SMTP or IMAP injection. | Cumple | Correo con PHPMailer, destinatario resuelto por id de usuario y asunto de plantilla fija (ServicioCorreo); PHPMailer rechaza CR/LF en cabeceras. |
| V1.4.1 | 2 | Verify that the application uses memory-safe string, safer memory copy and pointer arithmetic to detect or prevent stack, buffer, or heap overflows. | No aplica | PHP y JavaScript gestionan la memoria; no hay código nativo propio. |
| V1.4.2 | 2 | Verify that sign, range, and input validation techniques are used to prevent integer overflows. | Cumple | Enteros con rango (Entrada::entero) y montos en unidades enteras con control de desbordamiento (Entrada::multiplicar devuelve null si no cabe en DECIMAL(15,2)). |
| V1.4.3 | 2 | Verify that dynamically allocated memory and resources are released, and that references or pointers to freed memory are removed or set to null to prevent dangling pointers and use-after-free vulnerabilities. | No aplica | PHP y JavaScript gestionan la memoria; no hay código nativo propio. |
| V1.5.1 | 1 | Verify that the application configures XML parsers to use a restrictive configuration and that unsafe features such as resolving external entities are disabled to prevent XML eXternal Entity (XXE) attacks. | Cumple | Los .xlsx se leen con OpenSpout (XMLReader) y PHP 8 no resuelve entidades externas por defecto; además se controla el tamaño descomprimido (prueba «bomba» en ImportacionesTest). |
| V1.5.2 | 2 | Verify that deserialization of untrusted data enforces safe input handling, such as using an allowlist of object types or restricting client-defined object types, to prevent deserialization attacks. Deserialization me… | Cumple | No se usa unserialize con datos externos: JSON en caché, cola y sesiones (Cache.php, DespachadorCola). |

### V2 Validation and Business Logic

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V2.1.1 | 1 | Verify that the application's documentation defines input validation rules for how to check the validity of data items against an expected structure. This could be common data formats such as credit card numbers, emai… | Cumple | Reglas de validación de cada dato en el SRS (capítulo 3) y en los validadores; formatos de cada fuente en las plantillas de importación. |
| V2.1.2 | 2 | Verify that the application's documentation defines how to validate the logical and contextual consistency of combined data items, such as checking that suburb and ZIP code match. | Cumple | Reglas combinadas documentadas en el SRS (p. ej. componente académico ≤ valor del gasto, cantidad ≤ saldo del lote). |
| V2.1.3 | 2 | Verify that expectations for business logic limits and validations are documented, including both per-user and globally across the application. | Cumple | Límites por usuario y globales en config/seguridad.php (limites) y en el SRS 4.7; topes de negocio en las reglas (ReglasOperacion, ReglasHato). |
| V2.2.1 | 1 | Verify that input is validated to enforce business or functional expectations for that input. This should either use positive validation against an allow list of values, patterns, and ranges, or be based on comparing … | Cumple | Validación positiva con listas de valores, patrones y rangos en el servidor. |
| V2.2.2 | 1 | Verify that the application is designed to enforce input validation at a trusted service layer. While client-side validation improves usability and should be encouraged, it must not be relied upon as a security control. | Cumple | Toda validación se repite en el servicio; los atributos HTML solo ayudan al usuario (formularios con novalidate). |
| V2.2.3 | 2 | Verify that the application ensures that combinations of related data items are reasonable according to the pre-defined rules. | Cumple | Combinaciones validadas en el servidor (ReglasOperacion, ReglasDependencias, ServicioGastos). |
| V2.3.1 | 1 | Verify that the application will only process business logic flows for the same user in the expected sequential step order and without skipping steps. | Cumple | Flujos con estados que no se pueden saltar: activación → 2FA, lote validado → aplicado, carga inicial verificada → firmada (CargaInicialTest). |
| V2.3.2 | 2 | Verify that business logic limits are implemented per the application's documentation to avoid business logic flaws being exploited. | Cumple | Límites de negocio aplicados: cupos de autoconsumo, saldo de lotes, periodos cerrados (OperacionProductivaTest, MaestrosFinanzasTest). |
| V2.3.3 | 2 | Verify that transactions are being used at the business logic level such that either a business logic operation succeeds in its entirety or it is rolled back to the previous correct state. | Cumple | Operaciones de negocio en transacciones (BaseDatos::transaccion); la importación aplica por bloques transaccionales con reversión. |
| V2.3.4 | 2 | Verify that business logic level locking mechanisms are used to ensure that limited quantity resources (such as theater seats or delivery slots) cannot be double-booked by manipulating the application's logic. | Cumple | Bloqueos SELECT … FOR UPDATE en la cola, el cálculo de calidad y la cadena de auditoría; claves de idempotencia para envíos repetidos (Idempotencia). |
| V2.4.1 | 2 | Verify that anti-automation controls are in place to protect against excessive calls to application functions that could lead to data exfiltration, garbage-data creation, quota exhaustion, rate-limit breaches, denial-… | Cumple | Límites de tasa por cuenta, IP y operación (ingreso, OTP, recuperación, importaciones, exportaciones, administración) en limites_tasa. |

### V3 Web Frontend Security

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V3.2.1 | 1 | Verify that security controls are in place to prevent browsers from rendering content or functionality in HTTP responses in an incorrect context (e.g., when an API, a user-uploaded file or other resource is requested … | Cumple | Descargas con Content-Disposition: attachment y nosniff; CORP same-origin, X-Frame-Options DENY y CSP en todas las respuestas. |
| V3.2.2 | 1 | Verify that content intended to be displayed as text, rather than rendered as HTML, is handled using safe rendering functions (such as createTextNode or textContent) to prevent unintended execution of content such as … | Cumple | El JavaScript de retroalimentación usa textContent y createElement (recursos/js/retroalimentacion-ui.js); no hay innerHTML con datos. |
| V3.3.1 | 1 | Verify that cookies have the 'Secure' attribute set, and if the '\__Host-' prefix is not used for the cookie name, the '__Secure-' prefix must be used for the cookie name. | Staging | Con HTTPS la cookie es __Host-kora_sesion con Secure (config/seguridad.php). En local (HTTP) no se puede usar el prefijo. Verificar en staging (4.8, ítem 3). |
| V3.3.2 | 2 | Verify that each cookie's 'SameSite' attribute value is set according to the purpose of the cookie, to limit exposure to user interface redress attacks and browser-based request forgery attacks, commonly known as cros… | Cumple | Cookie de sesión SameSite=Lax, más token sincronizador CSRF y validación de Origin en toda escritura (VerificarCsrf). |
| V3.3.3 | 2 | Verify that cookies have the '__Host-' prefix for the cookie name unless they are explicitly designed to be shared with other hosts. | Staging | Prefijo __Host- en HTTPS (config/seguridad.php). Verificar en staging. |
| V3.3.4 | 2 | Verify that if the value of a cookie is not meant to be accessible to client-side scripts (such as a session token), the cookie must have the 'HttpOnly' attribute set and the same value (e. g. session token) must only… | Cumple | Cookie de sesión HttpOnly; el identificador de sesión no viaja en el cuerpo ni en la URL. |
| V3.4.1 | 1 | Verify that a Strict-Transport-Security header field is included on all responses to enforce an HTTP Strict Transport Security (HSTS) policy. A maximum age of at least 1 year must be defined, and for L2 and up, the po… | Staging | HSTS de 1 año con includeSubDomains cuando APP_URL es https (CabecerasSeguridad y public/.htaccess). Verificar en staging con SSL Labs. |
| V3.4.2 | 1 | Verify that the Cross-Origin Resource Sharing (CORS) Access-Control-Allow-Origin header field is a fixed value by the application, or if the Origin HTTP request header field value is used, it is validated against an a… | Cumple | La aplicación no emite cabeceras CORS: solo mismo origen. |
| V3.4.3 | 2 | Verify that HTTP responses include a Content-Security-Policy response header field which defines directives to ensure the browser only loads and executes trusted content or resources, in order to limit execution of ma… | Cumple | CSP estricta con nonce por respuesta, object-src 'none', base-uri y form-action 'self' (CabecerasSeguridad); axe y E2E sin violaciones de CSP. |
| V3.4.4 | 2 | Verify that all HTTP responses contain an 'X-Content-Type-Options: nosniff' header field. This instructs browsers not to use content sniffing and MIME type guessing for the given response, and to require the response'… | Cumple | nosniff en las respuestas de la aplicación (CabecerasSeguridad) y en los estáticos (public/.htaccess). |
| V3.4.5 | 2 | Verify that the application sets a referrer policy to prevent leakage of technically sensitive data to third-party services via the 'Referer' HTTP request header field. This can be done using the Referrer-Policy HTTP … | Cumple | Referrer-Policy strict-origin-when-cross-origin en la aplicación y en los estáticos. |
| V3.4.6 | 2 | Verify that the web application uses the frame-ancestors directive of the Content-Security-Policy header field for every HTTP response to ensure that it cannot be embedded by default and that embedding of specific res… | Cumple | CSP frame-ancestors 'none' en todas las respuestas, más X-Frame-Options DENY. |
| V3.5.1 | 1 | Verify that, if the application does not rely on the CORS preflight mechanism to prevent disallowed cross-origin requests to use sensitive functionality, these requests are validated to ensure they originate from the … | Cumple | Escrituras con token CSRF y validación de Origin (RS-005); no se depende del preflight de CORS. |
| V3.5.2 | 1 | Verify that, if the application relies on the CORS preflight mechanism to prevent disallowed cross-origin use of sensitive functionality, it is not possible to call the functionality with a request which does not trig… | No aplica | No se usa el preflight de CORS como control (ver V3.5.1). |
| V3.5.3 | 1 | Verify that HTTP requests to sensitive functionality use appropriate HTTP methods such as POST, PUT, PATCH, or DELETE, and not methods defined by the HTTP specification as "safe" such as HEAD, OPTIONS, or GET. Alterna… | Cumple | Toda operación que cambia datos es POST; las rutas GET solo consultan o descargan. |
| V3.5.4 | 2 | Verify that separate applications are hosted on different hostnames to leverage the restrictions provided by same-origin policy, including how documents or scripts loaded by one origin can interact with resources from… | Staging | KORA se publica en su propio subdominio, sin otras aplicaciones en el mismo origen (4.8, ítem 2). |
| V3.5.5 | 2 | Verify that messages received by the postMessage interface are discarded if the origin of the message is not trusted, or if the syntax of the message is invalid. | No aplica | No se usa postMessage. |
| V3.7.1 | 2 | Verify that the application only uses client-side technologies which are still supported and considered secure. Examples of technologies which do not meet this requirement include NSAPI plugins, Flash, Shockwave, Acti… | Cumple | HTMX 2 y Alpine 3 (CSP), sin complementos de navegador. |
| V3.7.2 | 2 | Verify that the application will only automatically redirect the user to a different hostname or domain (which is not controlled by the application) where the destination appears on an allowlist. | Cumple | No hay redirecciones a otros dominios; «volver» solo admite rutas internas. |

### V4 API and Web Service

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V4.1.1 | 1 | Verify that every HTTP response with a message body contains a Content-Type header field that matches the actual content of the response, including the charset parameter to specify safe character encoding (e.g., UTF-8… | Cumple | Respuesta fija Content-Type con charset UTF-8 (app/Nucleo/Respuesta.php); los estáticos los tipa el servidor. |
| V4.1.2 | 2 | Verify that only user-facing endpoints (intended for manual web-browser access) automatically redirect from HTTP to HTTPS, while other services or endpoints do not implement transparent redirects. This is to avoid a s… | Parcial | public/.htaccess redirige todo a HTTPS, incluidos /health/live y /health/ready. Pendiente: excluir los extremos de monitoreo de la redirección y responder 403 por HTTP. |
| V4.1.3 | 2 | Verify that any HTTP header field used by the application and set by an intermediary layer, such as a load balancer, a web proxy, or a backend-for-frontend service, cannot be overridden by the end-user. Example header… | Staging | La aplicación usa REMOTE_ADDR e ignora X-Forwarded-For. Verificar detrás del proxy de Hostinger (4.8, ítem 16). |
| V4.2.1 | 2 | Verify that all application components (including load balancers, firewalls, and application servers) determine boundaries of incoming HTTP messages using the appropriate mechanism for the HTTP version to prevent HTTP… | Staging | Depende de LiteSpeed y del proxy de Hostinger; se verifica con el proveedor (4.8, ítem 16). |
| V4.3.1 | 2 | Verify that a query allowlist, depth limiting, amount limiting, or query cost analysis is used to prevent GraphQL or data layer expression Denial of Service (DoS) as a result of expensive, nested queries. | No aplica | No hay GraphQL. |
| V4.3.2 | 2 | Verify that GraphQL introspection queries are disabled in the production environment unless the GraphQL API is meant to be used by other parties. | No aplica | No hay GraphQL. |
| V4.4.1 | 1 | Verify that WebSocket over TLS (WSS) is used for all WebSocket connections. | No aplica | No hay WebSocket. |
| V4.4.2 | 2 | Verify that, during the initial HTTP WebSocket handshake, the Origin header field is checked against a list of origins allowed for the application. | No aplica | No hay WebSocket. |
| V4.4.3 | 2 | Verify that, if the application's standard session management cannot be used, dedicated tokens are being used for this, which comply with the relevant Session Management security requirements. | No aplica | No hay WebSocket. |
| V4.4.4 | 2 | Verify that dedicated WebSocket session management tokens are initially obtained or validated through the previously authenticated HTTPS session when transitioning an existing HTTPS session to a WebSocket channel. | No aplica | No hay WebSocket. |

### V5 File Handling

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V5.1.1 | 2 | Verify that the documentation defines the permitted file types, expected file extensions, and maximum size (including unpacked size) for each upload feature. Additionally, ensure that the documentation specifies how f… | Cumple | Tipos, extensiones y tamaños documentados: libros .xlsx de hasta 10 MB y 20.000 filas (RF-083); adjuntos PDF, PNG o JPEG de hasta 5 MB (RF-025). |
| V5.2.1 | 1 | Verify that the application will only accept files of a size which it can process without causing a loss of performance or a denial of service attack. | Cumple | Límites de tamaño antes de procesar (ServicioCargaArchivos, ServicioAdjuntos::TAMANO_MAXIMO). |
| V5.2.2 | 1 | Verify that when the application accepts a file, either on its own or within an archive such as a zip file, it checks if the file extension matches an expected file extension and validates that the contents correspond… | Cumple | Extensión y firma del contenido verificadas (ServicioCargaArchivos, ServicioAdjuntos); un PDF renombrado a .xlsx se rechaza (ImportacionesTest). |
| V5.2.3 | 2 | Verify that the application checks compressed files (e.g., zip, gz, docx, odt) against maximum allowed uncompressed size and against maximum number of files before uncompressing the file. | Cumple | Tamaño descomprimido y número de entradas del .xlsx controlados antes de leerlo (prueba «bomba» en ImportacionesTest). |
| V5.3.1 | 1 | Verify that files uploaded or generated by untrusted input and stored in a public folder, are not executed as server-side program code when accessed directly with an HTTP request. | Cumple | Los archivos se guardan en almacenamiento/, fuera de public/ y con .htaccess que niega todo. |
| V5.3.2 | 1 | Verify that when the application creates file paths for file operations, instead of user-submitted filenames, it uses internally generated or trusted data, or if user-submitted filenames or file metadata must be used,… | Cumple | Rutas internas con ULID; el nombre original solo se guarda como dato. |
| V5.4.1 | 2 | Verify that the application validates or ignores user-submitted filenames, including in a JSON, JSONP, or URL parameter and specifies a filename in the Content-Disposition header field in the response. | Cumple | El nombre del archivo del usuario se ignora para la ruta y se sanea en Content-Disposition. |
| V5.4.2 | 2 | Verify that file names served (e.g., in HTTP response header fields or email attachments) are encoded or sanitized (e.g., following RFC 6266) to preserve document structure and prevent injection attacks. | Cumple | Content-Disposition con filename saneado y filename* codificado (RFC 6266) en ControladorImportaciones y adjuntos. |
| V5.4.3 | 2 | Verify that files obtained from untrusted sources are scanned by antivirus scanners to prevent serving of known malicious content. | Decisión | No hay antivirus: el hosting compartido no ofrece uno invocable desde PHP. Mitigación: tipos permitidos con firma, sin ejecución, descarga como adjunto con nosniff. Requiere aceptación del riesgo o un servicio de escaneo. |

### V6 Authentication

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V6.1.1 | 1 | Verify that application documentation defines how controls such as rate limiting, anti-automation, and adaptive response, are used to defend against attacks such as credential stuffing and password brute force. The do… | Cumple | Controles contra relleno de credenciales y fuerza bruta documentados en el SRS 4.7 y configurados en config/seguridad.php (limites). |
| V6.1.2 | 2 | Verify that a list of context-specific words is documented in order to prevent their use in passwords. The list could include permutations of organization names, product names, system identifiers, project codenames, d… | Cumple | Lista de palabras de contexto documentada: config/seguridad.php (palabras_contexto). |
| V6.1.3 | 2 | Verify that, if the application includes multiple authentication pathways, these are all documented together with the security controls and authentication strength which must be consistently enforced across them. | Cumple | Vías de autenticación documentadas en el SRS 4.7 y en ADR-006: contraseña + TOTP, activación por invitación, recuperación por correo y reautenticación, todas con límites de tasa. |
| V6.2.1 | 1 | Verify that user set passwords are at least 8 characters in length although a minimum of 15 characters is strongly recommended. | Cumple | Mínimo de 15 caracteres con un solo factor y 8 con 2FA (config/seguridad.php; ServicioContrasenas). |
| V6.2.2 | 1 | Verify that users can change their password. | Cumple | Cambio de contraseña en Mi cuenta (ServicioCuentas::cambiarContrasena). |
| V6.2.3 | 1 | Verify that password change functionality requires the user's current and new password. | Cumple | El cambio exige la contraseña actual (RAC-008). |
| V6.2.4 | 1 | Verify that passwords submitted during account registration or password change are checked against an available set of, at least, the top 3000 passwords which match the application's password policy, e.g. minimum length. | Cumple | Se comparan con una lista de 46.484 contraseñas comunes (recursos/listas/contrasenas-comunes.txt). |
| V6.2.5 | 1 | Verify that passwords of any composition can be used, without rules limiting the type of characters permitted. There must be no requirement for a minimum number of upper or lower case characters, numbers, or special c… | Cumple | Sin reglas de composición. |
| V6.2.6 | 1 | Verify that password input fields use type=password to mask the entry. Applications may allow the user to temporarily view the entire masked password, or the last typed character of the password. | Cumple | Campos type=password. |
| V6.2.7 | 1 | Verify that "paste" functionality, browser password helpers, and external password managers are permitted. | Cumple | Se permite pegar y los gestores de contraseñas (autocomplete current-password/new-password). |
| V6.2.8 | 1 | Verify that the application verifies the user's password exactly as received from the user, without any modifications such as truncation or case transformation. | Cumple | La contraseña se verifica tal cual; una más larga que el máximo se rechaza, no se trunca. |
| V6.2.9 | 2 | Verify that passwords of at least 64 characters are permitted. | Cumple | Se admiten hasta 128 caracteres. |
| V6.2.10 | 2 | Verify that a user's password stays valid until it is discovered to be compromised or the user rotates it. The application must not require periodic credential rotation. | Cumple | No hay rotación periódica obligatoria. |
| V6.2.11 | 2 | Verify that the documented list of context specific words is used to prevent easy to guess passwords being created. | Cumple | Las palabras de contexto y el nombre o correo del usuario se rechazan (ServicioContrasenas). |
| V6.2.12 | 2 | Verify that passwords submitted during account registration or password changes are checked against a set of breached passwords. | Parcial | La lista local proviene de filtraciones públicas, pero no se consulta un servicio de contraseñas filtradas (sin salida a internet desde el servidor). Pendiente: decidir si se integra una consulta k-anónima. |
| V6.3.1 | 1 | Verify that controls to prevent attacks such as credential stuffing and password brute force are implemented according to the application's security documentation. | Cumple | Límites por cuenta (5 en 15 min) y por IP (20 en 15 min) con bloqueo temporal (IdentidadTest). |
| V6.3.2 | 1 | Verify that default user accounts (e.g., "root", "admin", or "sa") are not present in the application or are disabled. | Cumple | No hay cuentas por defecto: el primer superadministrador se crea por SSH con invitación (cli/usuarios.php). |
| V6.3.3 | 2 | Verify that either a multi-factor authentication mechanism or a combination of single-factor authentication mechanisms, must be used in order to access the application. For L3, one of the factors must be a hardware-ba… | Decisión | La 2FA es obligatoria para los roles privilegiados (superadministrador y administrador). Para el resto, la contraseña tiene 15 caracteres como mínimo. ASVS nivel 2 exige 2FA para todos. Para cumplir: UPDATE roles SET requiere_2fa = 1; requiere decisión de la contraparte por su impacto en los usuarios de campo. |
| V6.3.4 | 2 | Verify that, if the application includes multiple authentication pathways, there are no undocumented pathways and that security controls and authentication strength are enforced consistently. | Cumple | Denegación por defecto: toda ruta declara permiso, «autenticada» o «publica» (Enrutador); no hay vías ocultas. |
| V6.4.1 | 1 | Verify that system generated initial passwords or activation codes are securely randomly generated, follow the existing password policy, and expire after a short period of time or after they are initially used. These … | Cumple | Tokens de invitación y recuperación aleatorios, de un solo uso, con vigencia de 72 h y 30 min, guardados como hash (ServicioTokensCuenta). |
| V6.4.2 | 1 | Verify that password hints or knowledge-based authentication (so-called "secret questions") are not present. | Cumple | No hay pistas ni preguntas secretas. |
| V6.4.3 | 2 | Verify that a secure process for resetting a forgotten password is implemented, that does not bypass any enabled multi-factor authentication mechanisms. | Cumple | La recuperación solo cambia la contraseña y cierra todas las sesiones (IdentidadTest); el secreto TOTP no se toca, así que el siguiente ingreso sigue pidiendo el segundo factor (ServicioCuentas). |
| V6.4.4 | 2 | Verify that if a multi-factor authentication factor is lost, evidence of identity proofing is performed at the same level as during enrollment. | Cumple | El restablecimiento de la 2FA lo hace el titular por SSH con verificación de identidad y motivo auditado (cli/usuarios.php restablecer-2fa; runbook de incidente de seguridad). |
| V6.5.1 | 2 | Verify that lookup secrets, out-of-band authentication requests or codes, and time-based one-time passwords (TOTPs) are only successfully usable once. | Cumple | Cada paso TOTP se acepta una sola vez (totp_ultimo_paso) y cada código de respaldo se marca como usado. |
| V6.5.2 | 2 | Verify that, when being stored in the application's backend, lookup secrets with less than 112 bits of entropy (19 random alphanumeric characters or 34 random digits) are hashed with an approved password storage hashi… | Cumple | Los códigos de respaldo se guardan con hash (ServicioCodigosRespaldo). |
| V6.5.3 | 2 | Verify that lookup secrets, out-of-band authentication code, and time-based one-time password seeds, are generated using a Cryptographically Secure Pseudorandom Number Generator (CSPRNG) to avoid predictable values. | Cumple | Semillas TOTP y códigos con random_bytes. |
| V6.5.4 | 2 | Verify that lookup secrets and out-of-band authentication codes have a minimum of 20 bits of entropy (typically 4 random alphanumeric characters or 6 random digits is sufficient). | Cumple | Códigos de respaldo de 10 caracteres alfanuméricos; TOTP de 6 dígitos. |
| V6.5.5 | 2 | Verify that out-of-band authentication requests, codes, or tokens, as well as time-based one-time passwords (TOTPs) have a defined lifetime. Out of band requests must have a maximum lifetime of 10 minutes and for TOTP… | Cumple | TOTP de 30 s con ventana de 1 paso; no hay códigos fuera de banda. |
| V6.6.1 | 2 | Verify that authentication mechanisms using the Public Switched Telephone Network (PSTN) to deliver One-time Passwords (OTPs) via phone or SMS are offered only when the phone number has previously been validated, alte… | No aplica | No hay OTP por SMS ni por teléfono. |
| V6.6.2 | 2 | Verify that out-of-band authentication requests, codes, or tokens are bound to the original authentication request for which they were generated and are not usable for a previous or subsequent one. | No aplica | No hay autenticación fuera de banda. |
| V6.6.3 | 2 | Verify that a code based out-of-band authentication mechanism is protected against brute force attacks by using rate limiting. Consider also using a code with at least 64 bits of entropy. | No aplica | No hay autenticación fuera de banda. |
| V6.8.1 | 2 | Verify that, if the application supports multiple identity providers (IdPs), the user's identity cannot be spoofed via another supported identity provider (eg. by using the same user identifier). The standard mitigati… | No aplica | No hay proveedores de identidad externos. |
| V6.8.2 | 2 | Verify that the presence and integrity of digital signatures on authentication assertions (for example on JWTs or SAML assertions) are always validated, rejecting any assertions that are unsigned or have invalid signa… | No aplica | No hay aserciones firmadas (JWT o SAML). |
| V6.8.3 | 2 | Verify that SAML assertions are uniquely processed and used only once within the validity period to prevent replay attacks. | No aplica | No hay SAML. |
| V6.8.4 | 2 | Verify that, if an application uses a separate Identity Provider (IdP) and expects specific authentication strength, methods, or recentness for specific functions, the application verifies this using the information r… | No aplica | No hay proveedores de identidad externos. |

### V7 Session Management

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V7.1.1 | 2 | Verify that the user's session inactivity timeout and absolute maximum session lifetime are documented, are appropriate in combination with other controls, and that the documentation includes justification for any dev… | Cumple | Inactividad de 30 min (15 para privilegiados) y vida máxima de 12 h (8 para privilegiados), justificadas en el SRS (RAC-017) y configuradas en config/seguridad.php. |
| V7.1.2 | 2 | Verify that the documentation defines how many concurrent (parallel) sessions are allowed for one account as well as the intended behaviors and actions to be taken when the maximum number of active sessions is reached. | Cumple | Sin límite de sesiones simultáneas: cada usuario las ve y las cierra en Mi cuenta (RAC-020). Documentado en esta lista. |
| V7.1.3 | 2 | Verify that all systems that create and manage user sessions as part of a federated identity management ecosystem (such as SSO systems) are documented along with controls to coordinate session lifetimes, termination, … | No aplica | No hay inicio de sesión federado. |
| V7.2.1 | 1 | Verify that the application performs all session token verification using a trusted, backend service. | Cumple | Sesiones del lado del servidor en la tabla sesiones (app/Nucleo/Sesion.php). |
| V7.2.2 | 1 | Verify that the application uses either self-contained or reference tokens that are dynamically generated for session management, i.e. not using static API secrets and keys. | Cumple | Tokens de referencia generados por sesión. |
| V7.2.3 | 1 | Verify that if reference tokens are used to represent user sessions, they are unique and generated using a cryptographically secure pseudo-random number generator (CSPRNG) and possess at least 128 bits of entropy. | Cumple | 256 bits de random_bytes; en la base solo se guarda su SHA-256. |
| V7.2.4 | 1 | Verify that the application generates a new session token on user authentication, including re-authentication, and terminates the current session token. | Cumple | Nuevo identificador al ingresar, al elevar a 2FA y al reautenticar; el anterior queda revocado (Sesion::regenerar). |
| V7.3.1 | 2 | Verify that there is an inactivity timeout such that re-authentication is enforced according to risk analysis and documented security decisions. | Cumple | Inactividad aplicada en cada solicitud, con aviso dos minutos antes. |
| V7.3.2 | 2 | Verify that there is an absolute maximum session lifetime such that re-authentication is enforced according to risk analysis and documented security decisions. | Cumple | Vida máxima absoluta aplicada en cada solicitud. |
| V7.4.1 | 1 | Verify that when session termination is triggered (such as logout or expiration), the application disallows any further use of the session. For reference tokens or stateful sessions, this means invalidating the sessio… | Cumple | Al salir o vencer, la sesión queda revocada en la base y no se puede reusar; al salir se envía Clear-Site-Data. |
| V7.4.2 | 1 | Verify that the application terminates all active sessions when a user account is disabled or deleted (such as an employee leaving the company). | Cumple | Desactivar una cuenta cierra todas sus sesiones (ServicioUsuarios). |
| V7.4.3 | 2 | Verify that the application gives the option to terminate all other active sessions after a successful change or removal of any authentication factor (including password change via reset or recovery and, if present, a… | Cumple | Al cambiar la contraseña se ofrece cerrar las demás sesiones (ServicioCuentas::cambiarContrasena); la recuperación las cierra todas. |
| V7.4.4 | 2 | Verify that all pages that require authentication have easy and visible access to logout functionality. | Cumple | «Salir» en el menú de perfil de todas las páginas autenticadas. |
| V7.4.5 | 2 | Verify that application administrators are able to terminate active sessions for an individual user or for all users. | Parcial | Un administrador cierra las sesiones de un usuario desactivando la cuenta; cerrar las de todos se hace por SQL (runbook de incidente de seguridad). Pendiente: acción explícita en la interfaz. |
| V7.5.1 | 2 | Verify that the application requires full re-authentication before allowing modifications to sensitive account attributes which may affect authentication such as email address, phone number, MFA configuration, or othe… | Cumple | Cambios de contraseña, 2FA y códigos exigen reautenticación de los últimos 5 min (RAC-026). |
| V7.5.2 | 2 | Verify that users are able to view and (having authenticated again with at least one factor) terminate any or all currently active sessions. | Cumple | Mi cuenta lista las sesiones activas y permite cerrar las demás con reautenticación. |
| V7.6.1 | 2 | Verify that session lifetime and termination between Relying Parties (RPs) and Identity Providers (IdPs) behave as documented, requiring re-authentication as necessary such as when the maximum time between IdP authent… | No aplica | No hay proveedores de identidad. |
| V7.6.2 | 2 | Verify that creation of a session requires either the user's consent or an explicit action, preventing the creation of new application sessions without user interaction. | Cumple | Las sesiones solo se crean con un ingreso explícito. |

### V8 Authorization

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V8.1.1 | 1 | Verify that authorization documentation defines rules for restricting function-level and data-specific access based on consumer permissions and resource attributes. | Cumple | Matriz de autorización por función y por alcance de datos (SRS 3.10, RAC-025; bd/semillas/001). |
| V8.1.2 | 2 | Verify that authorization documentation defines rules for field-level access restrictions (both read and write) based on consumer permissions and resource attributes. Note that these rules might depend on other attrib… | Cumple | Reglas por campo documentadas: la identificación del pagador no se guarda en claro ni se muestra (RF-034); los campos sensibles de las fuentes no se copian (FuenteImportacion::camposSensibles). |
| V8.2.1 | 1 | Verify that the application ensures that function-level access is restricted to consumers with explicit permissions. | Cumple | Cada ruta exige un permiso explícito (Enrutador, Autorizar); sin permiso, 403 auditado. |
| V8.2.2 | 1 | Verify that the application ensures that data-specific access is restricted to consumers with explicit permissions to specific data items to mitigate insecure direct object reference (IDOR) and broken object level aut… | Cumple | Alcance por centro y unidad en cada consulta (AlcanceDatos); 404 para recursos ajenos; pruebas de aislamiento entre unidades y centros. |
| V8.2.3 | 2 | Verify that the application ensures that field-level access is restricted to consumers with explicit permissions to specific fields to mitigate broken object property level authorization (BOPLA). | Cumple | Los formularios solo aceptan los campos del rol; campos sensibles cifrados o con HMAC. |
| V8.3.1 | 1 | Verify that the application enforces authorization rules at a trusted service layer and doesn't rely on controls that an untrusted consumer could manipulate, such as client-side JavaScript. | Cumple | La autorización se decide en el servidor; el menú oculto es solo presentación. |
| V8.4.1 | 2 | Verify that multi-tenant applications use cross-tenant controls to ensure consumer operations will never affect tenants with which they do not have permissions to interact. | Cumple | Cada centro es un inquilino: todas las consultas filtran por centro_id y hay pruebas con un segundo centro (RendimientoOperacionTest). |

### V11 Cryptography

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V11.1.1 | 2 | Verify that there is a documented policy for management of cryptographic keys and a cryptographic key lifecycle that follows a key management standard such as NIST SP 800-57. This should include ensuring that keys are… | Parcial | Ciclo de vida de las claves en el runbook de rotación y el ADR de cifrado (llavero con versiones). Pendiente: el recifrado automático (seguridad.recifrar), sin el cual la clave anterior debe quedarse en el llavero. |
| V11.1.2 | 2 | Verify that a cryptographic inventory is performed, maintained, regularly updated, and includes all cryptographic keys, algorithms, and certificates used by the application. It must also document where keys can and ca… | Cumple | Inventario criptográfico en la sección «Inventario criptográfico» de docs/seguridad/asvs.md. |
| V11.2.1 | 2 | Verify that industry-validated implementations (including libraries and hardware-accelerated implementations) are used for cryptographic operations. | Cumple | libsodium, OpenSSL y password_hash de PHP; sin criptografía propia. |
| V11.2.2 | 2 | Verify that the application is designed with crypto agility such that random number, authenticated encryption, MAC, or hashing algorithms, key lengths, rounds, ciphers and modes can be reconfigured, upgraded, or swapp… | Cumple | Llavero con versión en cada sobre cifrado: se puede cambiar de clave o de algoritmo sin perder lo anterior. |
| V11.2.3 | 2 | Verify that all cryptographic primitives utilize a minimum of 128-bits of security based on the algorithm, key size, and configuration. For example, a 256-bit ECC key provides roughly 128 bits of security where RSA re… | Cumple | Todas las primitivas ofrecen 128 bits de seguridad o más (claves de 256 bits). |
| V11.3.1 | 1 | Verify that insecure block modes (e.g., ECB) and weak padding schemes (e.g., PKCS#1 v1.5) are not used. | Cumple | Sin ECB ni rellenos débiles. |
| V11.3.2 | 1 | Verify that only approved ciphers and modes such as AES with GCM are used. | Cumple | Cifrado autenticado de libsodium (XChaCha20-Poly1305). |
| V11.3.3 | 2 | Verify that encrypted data is protected against unauthorized modification preferably by using an approved authenticated encryption method or by combining an approved encryption method with an approved MAC algorithm. | Cumple | Cifrado autenticado: cualquier alteración se detecta al descifrar (prueba de sobre alterado en RendimientoOperacionTest). |
| V11.4.1 | 1 | Verify that only approved hash functions are used for general cryptographic use cases, including digital signatures, HMAC, KDF, and random bit generation. Disallowed hash functions, such as MD5, must not be used for a… | Cumple | SHA-256 y HMAC-SHA-256. Excepción documentada: TOTP usa HMAC-SHA-1 por compatibilidad con las aplicaciones de autenticación (RFC 6238), uso que no exige resistencia a colisiones. |
| V11.4.2 | 2 | Verify that passwords are stored using an approved, computationally intensive, key derivation function (also known as a "password hashing function"), with parameter settings configured based on current guidance. The s… | Cumple | Argon2id con 64 MiB de memoria y 3 iteraciones (config/seguridad.php). |
| V11.4.3 | 2 | Verify that hash functions used in digital signatures, as part of data authentication or data integrity are collision resistant and have appropriate bit-lengths. If collision resistance is required, the output length … | Cumple | SHA-256 en la cadena de auditoría y en los manifiestos de integridad. |
| V11.4.4 | 2 | Verify that the application uses approved key derivation functions with key stretching parameters when deriving secret keys from passwords. The parameters in use must balance security and performance to prevent brute-… | No aplica | No se derivan claves a partir de contraseñas. |
| V11.5.1 | 2 | Verify that all random numbers and strings which are intended to be non-guessable must be generated using a cryptographically secure pseudo-random number generator (CSPRNG) and have at least 128 bits of entropy. Note … | Cumple | random_bytes y random_int para tokens, sesiones, códigos y claves. |
| V11.6.1 | 2 | Verify that only approved cryptographic algorithms and modes of operation are used for key generation and seeding, and digital signature generation and verification. Key generation algorithms must not generate insecur… | Cumple | Claves con sodium_crypto_*_keygen y crypto_box_seal para las copias. |

### V12 Secure Communication

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V12.1.1 | 1 | Verify that only the latest recommended versions of the TLS protocol are enabled, such as TLS 1.2 and TLS 1.3. The latest version of the TLS protocol must be the preferred option. | Staging | TLS de Hostinger; verificar TLS 1.2 y 1.3 con SSL Labs (4.8, ítem 3). |
| V12.1.2 | 2 | Verify that only recommended cipher suites are enabled, with the strongest cipher suites set as preferred. L3 applications must only support cipher suites which provide forward secrecy. | Staging | Suites de cifrado del proveedor; verificar con SSL Labs. |
| V12.1.3 | 2 | Verify that the application validates that mTLS client certificates are trusted before using the certificate identity for authentication or authorization. | No aplica | No hay certificados de cliente (mTLS). |
| V12.2.1 | 1 | Verify that TLS is used for all connectivity between a client and external facing, HTTP-based services, and does not fall back to insecure or unencrypted communications. | Staging | HTTPS forzado con HSTS (public/.htaccess, CabecerasSeguridad); verificar en staging. |
| V12.2.2 | 1 | Verify that external facing services use publicly trusted TLS certificates. | Staging | Certificado de Let's Encrypt con renovación automática (4.8, ítem 3), vigilado por MonitorCertificado. |
| V12.3.1 | 2 | Verify that an encrypted protocol such as TLS is used for all inbound and outbound connections to and from the application, including monitoring systems, management tools, remote access and SSH, middleware, databases,… | Parcial | SMTP con STARTTLS y rsync por SSH. La base está en el mismo servidor (127.0.0.1, sin red). Pendiente: confirmar el cifrado del SMTP institucional en staging. |
| V12.3.2 | 2 | Verify that TLS clients validate certificates received before communicating with a TLS server. | Cumple | PHPMailer valida la cadena y el nombre del servidor SMTP. MonitorCertificado desactiva la verificación a propósito, para poder leer la fecha de un certificado vencido, y no envía datos por esa conexión. |
| V12.3.3 | 2 | Verify that TLS or another appropriate transport encryption mechanism used for all connectivity between internal, HTTP-based services within the application, and does not fall back to insecure or unencrypted communica… | No aplica | No hay servicios HTTP internos entre componentes. |
| V12.3.4 | 2 | Verify that TLS connections between internal services use trusted certificates. Where internally generated or self-signed certificates are used, the consuming service must be configured to only trust specific internal… | No aplica | No hay servicios internos con certificados propios. |

### V13 Configuration

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V13.1.1 | 2 | Verify that all communication needs for the application are documented. This must include external services which the application relies upon and cases where an end user might be able to provide an external location t… | Cumple | Comunicaciones documentadas en el SRS 4.8 y en esta lista: base de datos local, SMTP, monitoreo externo y destino rsync de las copias. |
| V13.2.1 | 2 | Verify that communications between backend application components that don't support the application's standard user session mechanism, including APIs, middleware, and data layers, are authenticated. Authentication mu… | Cumple | La base exige usuario y contraseña propios; el monitoreo usa TOKEN_MONITOREO. |
| V13.2.2 | 2 | Verify that communications between backend application components, including local or operating system services, APIs, middleware, and data layers, are performed with accounts assigned the least necessary privileges. | Cumple | kora_app con privilegios limitados a su base (RBD-012). |
| V13.2.3 | 2 | Verify that if a credential has to be used for service authentication, the credential being used by the consumer is not a default credential (e.g., root/root or admin/admin). | Staging | Sin credenciales por defecto en el .env de producción; verificar al crear las bases de Hostinger (4.8, ítem 7). |
| V13.2.4 | 2 | Verify that an allowlist is used to define the external resources or systems with which the application is permitted to communicate (e.g., for outbound requests, data loads, or file access). This allowlist can be impl… | Cumple | Los destinos externos son fijos en el .env (SMTP, RESPALDO_DESTINO_EXTERNO); el código no abre otros. |
| V13.2.5 | 2 | Verify that the web or application server is configured with an allowlist of resources or systems to which the server can send requests or load data or files from. | Decisión | El hosting compartido no permite una lista de salida en el servidor. Requiere aceptación del riesgo, mitigado por V13.2.4. |
| V13.3.1 | 2 | Verify that a secrets management solution, such as a key vault, is used to securely create, store, control access to, and destroy backend secrets. These could include passwords, key material, integrations with databas… | Decisión | Los secretos viven en el .env (permisos 600, fuera de public_html) con huella vigilada (VerificadorIntegridad). Hostinger no ofrece un almacén de secretos. Requiere aceptación del riesgo. |
| V13.3.2 | 2 | Verify that access to secret assets adheres to the principle of least privilege. | Cumple | Solo el titular de la cuenta accede por SSH al .env; la aplicación lee únicamente lo que necesita. |
| V13.4.1 | 1 | Verify that the application is deployed either without any source control metadata, including the .git or .svn folders, or in a way that these folders are inaccessible both externally and to the application itself. | Cumple | El paquete se arma con git archive, sin .git (scripts/empaquetar.sh; la CI lo verifica). |
| V13.4.2 | 2 | Verify that debug modes are disabled for all components in production environments to prevent exposure of debugging features and information leakage. | Cumple | APP_DEBUG desactivado y errores genéricos en producción; verificar_entorno lo exige. |
| V13.4.3 | 2 | Verify that web servers do not expose directory listings to clients unless explicitly intended. | Cumple | Options -Indexes en .htaccess y public/.htaccess. |
| V13.4.4 | 2 | Verify that using the HTTP TRACE method is not supported in production environments, to avoid potential information leakage. | Staging | TRACE depende de LiteSpeed; verificar en staging (ZAP). |
| V13.4.5 | 2 | Verify that documentation (such as for internal APIs) and monitoring endpoints are not exposed unless explicitly intended. | Cumple | La documentación no se despliega; /health/ready solo da detalle con token o a un superadministrador. |

### V14 Data Protection

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V14.1.1 | 2 | Verify that all sensitive data created and processed by the application has been identified and classified into protection levels. This includes data that is only encoded and therefore easily decoded, such as Base64 s… | Cumple | Datos sensibles identificados: personales (Ley 1581), credenciales, identificación del pagador y claves (SRS 4.7, RS-013, RF-034). |
| V14.1.2 | 2 | Verify that all sensitive data protection levels have a documented set of protection requirements. This must include (but not be limited to) requirements related to general encryption, integrity verification, retentio… | Cumple | Protección por tipo definida: cifrado o HMAC de datos personales, sin registro en logs, retención y acceso por rol (SRS 4.7, RDL-007). |
| V14.2.1 | 1 | Verify that sensitive data is only sent to the server in the HTTP message body or header fields, and that the URL and query string do not contain sensitive information, such as an API key or session token. | Parcial | Las sesiones y las contraseñas nunca viajan en la URL. Los enlaces de invitación y de recuperación llevan su token en la consulta: son de un solo uso, vencen en 72 h y 30 min y salen con Referrer-Policy estricta. Pendiente: decidir si se pasan a un fragmento. |
| V14.2.2 | 2 | Verify that the application prevents sensitive data from being cached in server components, such as load balancers and application caches, or ensures that the data is securely purged after use. | Cumple | Cache-Control: no-store en las respuestas autenticadas; la caché de indicadores está fuera de public y no guarda datos personales. |
| V14.2.3 | 2 | Verify that defined sensitive data is not sent to untrusted parties (e.g., user trackers) to prevent unwanted collection of data outside of the application's control. | Cumple | Sin rastreadores ni CDN: fuentes, scripts y estilos se sirven del propio dominio. |
| V14.2.4 | 2 | Verify that controls around sensitive data related to encryption, integrity verification, retention, how the data is to be logged, access controls around sensitive data in logs, privacy and privacy-enhancing technolog… | Cumple | Controles de cifrado, integridad, retención y registro de los datos sensibles en el SRS 4.7 y en los runbooks. |
| V14.3.1 | 1 | Verify that authenticated data is cleared from client storage, such as the browser DOM, after the client or session is terminated. The 'Clear-Site-Data' HTTP response header field may be able to help with this but the… | Cumple | Al salir se envía Clear-Site-Data (caché y almacenamiento) y la sesión se revoca en el servidor. |
| V14.3.2 | 2 | Verify that the application sets sufficient anti-caching HTTP response header fields (i.e., Cache-Control: no-store) so that sensitive data is not cached in browsers. | Cumple | Cache-Control: no-store en las páginas autenticadas (ControladorBase, Respuesta). |
| V14.3.3 | 2 | Verify that data stored in browser storage (such as localStorage, sessionStorage, IndexedDB, or cookies) does not contain sensitive data, with the exception of session tokens. | Cumple | El almacenamiento del navegador solo guarda preferencias de presentación. |

### V15 Secure Coding and Architecture

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V15.1.1 | 1 | Verify that application documentation defines risk based remediation time frames for 3rd party component versions with vulnerabilities and for updating libraries in general, to minimize the risk from these components. | Cumple | Plazos de actualización de dependencias definidos en RS-015; composer audit y npm audit en cada push. |
| V15.1.2 | 2 | Verify that an inventory catalog, such as software bill of materials (SBOM), is maintained of all third-party libraries in use, including verifying that components come from pre-defined, trusted, and continually maint… | Parcial | Inventario en composer.lock y package-lock.json, con versiones exactas. Pendiente: generar un SBOM (CycloneDX) por versión. |
| V15.1.3 | 2 | Verify that the application documentation identifies functionality which is time-consuming or resource-demanding. This must include how to prevent a loss of availability due to overusing this functionality and how to … | Cumple | Funciones costosas documentadas (importaciones, exportaciones, tableros) y enviadas a la cola o a la caché (docs/rendimiento). |
| V15.2.1 | 1 | Verify that the application only contains components which have not breached the documented update and remediation time frames. | Parcial | Las dependencias de ejecución no tienen avisos. Las de desarrollo (sharp, esbuild, eslint) tienen avisos y no se despliegan. Pendiente: actualizarlas en la I8 o en la siguiente versión. |
| V15.2.2 | 2 | Verify that the application has implemented defenses against loss of availability due to functionality which is time-consuming or resource-demanding, based on the documented security decisions and strategies for this. | Cumple | Cola durable con presupuesto de tiempo, límites de filas y de tasa, y caché con protección de estampida. |
| V15.2.3 | 2 | Verify that the production environment only includes functionality that is required for the application to function, and does not expose extraneous functionality such as test code, sample snippets, and development fun… | Cumple | El paquete no lleva pruebas, documentación ni herramientas. Las demostraciones de la vitrina (cola y respuesta lenta) responden 404 en producción. |
| V15.3.1 | 1 | Verify that the application only returns the required subset of fields from a data object. For example, it should not return an entire data object, as some individual fields should not be accessible to users. | Cumple | Las vistas y el JSON de salud devuelven solo los campos necesarios. |
| V15.3.2 | 2 | Verify that where the application backend makes calls to external URLs, it is configured to not follow redirects unless it is intended functionality. | Cumple | La única llamada saliente (certificado) es una conexión TLS directa, sin redirecciones. |
| V15.3.3 | 2 | Verify that the application has countermeasures to protect against mass assignment attacks by limiting allowed fields per controller and action, e.g., it is not possible to insert or update a field value when it was n… | Cumple | Los servicios leen campos explícitos del cuerpo; nunca se asigna el cuerpo completo a una entidad. |
| V15.3.4 | 2 | Verify that all proxying and middleware components transfer the user's original IP address correctly using trusted data fields that cannot be manipulated by the end user, and the application and web server use this co… | Staging | Se usa REMOTE_ADDR; verificar la IP real detrás del proxy de Hostinger (4.8, ítem 16). |
| V15.3.5 | 2 | Verify that the application explicitly ensures that variables are of the correct type and performs strict equality and comparator operations. This is to avoid type juggling or type confusion vulnerabilities caused by … | Cumple | declare(strict_types=1), PHPStan nivel 6, comparaciones estrictas y hash_equals. |
| V15.3.6 | 2 | Verify that JavaScript code is written in a way that prevents prototype pollution, for example, by using Set() or Map() instead of object literals. | Cumple | El JavaScript propio no mezcla objetos con datos externos; las respuestas son HTML del servidor (HTMX). |
| V15.3.7 | 2 | Verify that the application has defenses against HTTP parameter pollution attacks, particularly if the application framework makes no distinction about the source of request parameters (query string, body parameters, … | Cumple | Consulta y cuerpo se leen por separado (Solicitud); no se usa $_REQUEST. |

### V16 Security Logging and Error Handling

| Requisito | Nivel | Texto oficial | Estado | Evidencia o justificación |
|---|---|---|---|---|
| V16.1.1 | 2 | Verify that an inventory exists documenting the logging performed at each layer of the application's technology stack, what events are being logged, log formats, where that logging is stored, how it is used, how acces… | Cumple | Inventario de registros en la sección «Inventario de registros» de docs/seguridad/asvs.md. |
| V16.2.1 | 2 | Verify that each log entry includes necessary metadata (such as when, where, who, what) that would allow for a detailed investigation of the timeline when an event happens. | Cumple | Cada evento de auditoría lleva cuándo, quién (actor y rol), dónde (IP tratada, centro y unidad), qué (acción y recurso) y request_id. |
| V16.2.2 | 2 | Verify that time sources for all logging components are synchronized, and that timestamps in security event metadata use UTC or include an explicit time zone offset. UTC is recommended to ensure consistency across dis… | Cumple | Marcas en UTC (UTC_TIMESTAMP(3)); la sesión de la base corre en +00:00 (verificar_entorno). |
| V16.2.3 | 2 | Verify that the application only stores or broadcasts logs to the files and services that are documented in the log inventory. | Cumple | Solo se escribe en eventos_auditoria y en almacenamiento/registros (JSON por líneas). |
| V16.2.4 | 2 | Verify that logs can be read and correlated by the log processor that is in use, preferably by using a common logging format. | Cumple | Formato JSON por líneas y tabla estructurada, exportable por lotes firmados (RAU-005). |
| V16.2.5 | 2 | Verify that when logging sensitive data, the application enforces logging based on the data's protection level. For example, it may not be allowed to log certain data, such as credentials or payment details. Other dat… | Cumple | No se registran contraseñas, tokens ni datos personales en claro; la IP se trunca y se guarda con HMAC. |
| V16.3.1 | 2 | Verify that all authentication operations are logged, including successful and unsuccessful attempts. Additional metadata, such as the type of authentication or factors used, should also be collected. | Cumple | Ingresos correctos y fallidos, OTP, reautenticación y salida auditados (sesion.*, 2fa.*). |
| V16.3.2 | 2 | Verify that failed authorization attempts are logged. For L3, this must include logging all authorization decisions, including logging when sensitive data is accessed (without logging the sensitive data itself). | Cumple | Toda denegación de autorización se audita con resultado «denegado» (Autorizar). |
| V16.3.3 | 2 | Verify that the application logs the security events that are defined in the documentation and also logs attempts to bypass the security controls, such as input validation, business logic, and anti-automation. | Cumple | Se auditan los intentos de saltar controles: CSRF (seguridad.csrf) y accesos denegados. Los bloqueos por límite de tasa quedan en limites_tasa y los revisa el monitor de seguridad (RAC-031). |
| V16.3.4 | 2 | Verify that the application logs unexpected errors and security control failures such as backend TLS failures. | Cumple | Errores inesperados registrados por ManejadorErrores; circuitos abiertos y fallos de correo en la cola. |
| V16.4.1 | 2 | Verify that all logging components appropriately encode data to prevent log injection. | Cumple | Registros en JSON codificado: los saltos de línea de la entrada no pueden inyectar entradas. |
| V16.4.2 | 2 | Verify that logs are protected from unauthorized access and cannot be modified. | Cumple | Cadena de hash de la auditoría con verificación (cli/auditoria.php verificar); los registros están fuera de public. |
| V16.4.3 | 2 | Verify that logs are securely transmitted to a logically separate system for analysis, detection, alerting, and escalation. The aim is to ensure that if the application is breached, the logs are not compromised. | Parcial | Los lotes de auditoría se exportan firmados y la copia cifrada sale de Hostinger. Pendiente: envío continuo a un sistema separado de alertas. |
| V16.5.1 | 2 | Verify that a generic message is returned to the consumer when an unexpected or security-sensitive error occurs, ensuring no exposure of sensitive internal system data such as stack traces, queries, secret keys, and t… | Cumple | Páginas de error genéricas con request_id, sin trazas (ManejadorErrores). |
| V16.5.2 | 2 | Verify that the application continues to operate securely when external resource access fails, for example, by using patterns such as circuit breakers or graceful degradation. | Cumple | Cortacircuitos para SMTP e IA, degradación de la caché y modo mantenimiento si falla la base. |
| V16.5.3 | 2 | Verify that the application fails gracefully and securely, including when an exception occurs, preventing fail-open conditions such as processing a transaction despite errors resulting from validation logic. | Cumple | Fallo seguro: transacciones con reversión, autorización que niega ante errores y cola con reintentos idempotentes. |

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

- **SAST:** PHPStan nivel 6 sin errores, PSR-12 y ESLint (`no-eval`, `no-alert`, `no-console`) en cada push (trabajo «PHP» y «Activos»).
- **Secretos:** gitleaks sobre todo el historial en cada push (trabajo «Secretos»).
- **Dependencias:** `composer audit` y `npm audit --omit=dev` sin avisos altos; avisos solo en herramientas de desarrollo (V15.2.1).
- **DAST:** OWASP ZAP baseline sin sesión y escaneo completo autenticado en cada push contra el servidor de la CI (trabajo «Navegador, accesibilidad y ZAP»). El resumen y los informes quedan en el trabajo; un riesgo alto lo hace fallar. El escaneo autenticado en staging, antes de producción, sigue pendiente (RS-016).

## Firma (RGO-009)

| Rol | Nombre | Fecha | Firma |
|---|---|---|---|
| Responsable de seguridad | | | |
| Líder técnico | | | |

La firma declara que se revisó el estado de cada requisito y los hallazgos de SAST y DAST de la versión, y que no quedan hallazgos altos abiertos (RS-016).
