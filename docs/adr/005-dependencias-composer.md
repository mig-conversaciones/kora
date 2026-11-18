# ADR-005: Dependencias de Composer permitidas

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RNF-011, RS-015, D-35, D-37

## Contexto

Cada dependencia amplía la cadena de suministro. El MVC es nativo, sin framework.

## Decisión

En producción solo se permiten: openspout/openspout 4.x (XLSX en streaming), phpmailer/phpmailer 6.9.x, dompdf/dompdf 3.x (solo en el trabajador) y chillerlan/php-qrcode 5.x. En desarrollo: phpunit/phpunit 11, phpstan/phpstan 2 y friendsofphp/php-cs-fixer 3. TOTP se implementa sin librería (D-37). En la I0 no se instala ninguna dependencia de producción: cada una entra en la iteración que la usa.

## Alternativas consideradas

- Framework completo (Laravel, Symfony): más superficie y una arquitectura distinta a la definida en el SRS.

## Consecuencias

- `composer audit` y `npm audit` en cada PR.
- Agregar una dependencia requiere actualizar este ADR.

## Evolución

Si una dependencia queda sin mantenimiento o tiene una vulnerabilidad sin corrección, se reemplaza o se internaliza.
