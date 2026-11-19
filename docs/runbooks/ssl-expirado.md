# Runbook: certificado SSL por vencer o expirado

- **Severidad típica:**
  - Media, con el aviso de 15 días;
  - Alta, con el certificado expirado: el navegador bloquea el sitio y HSTS impide saltarse el aviso.
- **Síntomas y alertas:**
  - notificación «Alerta operativa: certificado por vencer», que lanza el programador desde las 03:20 con `SSL_AVISO_DIAS=15` (ROB-014);
  - aviso del monitoreo externo (ROB-012);
  - errores TLS reportados por los usuarios.
- **Impacto:** todos los usuarios, en cuanto el certificado expira.
- **Responsable:** líder técnico. **Escalamiento:** titular de la cuenta (hPanel) y soporte de Hostinger.

## Diagnóstico

1. Ver los días que le quedan al certificado:

   ```sh
   echo | openssl s_client -servername DOMINIO -connect DOMINIO:443 2>/dev/null | openssl x509 -noout -dates -issuer
   ```

2. En hPanel → Seguridad → SSL, revisar:
   - si el certificado de Let's Encrypt está «Activo»;
   - la fecha de renovación;
   - si el dominio sigue apuntando al plan. Un cambio de DNS rompe la validación HTTP-01.
3. Comprobar que `/.well-known/acme-challenge/` no esté bloqueado. El `.htaccess` lo permite: la regla `location ~ /\.(?!well-known/)` y su equivalente en LiteSpeed.

## Mitigación inmediata

1. En hPanel, pulsar «Reinstalar» o «Renovar» en el certificado del dominio.
2. Si la renovación falla por DNS, corregir el registro A o AAAA y reintentar. La propagación puede tardar hasta 1 h.

## Resolución

- Si Let's Encrypt no emite, abrir un caso con el soporte de Hostinger y adjuntar la salida del paso 1.
- No desactivar HSTS: solo prolongaría el problema en los navegadores que ya lo recibieron.

## Verificación

- `openssl s_client` muestra un `notAfter` de más de 60 días.
- SSL Labs da nota A o mejor (ítem 3 del 4.8).
- `php current/cli/humo.php --url=https://DOMINIO` termina en verde.

## Comunicación

Si hubo caída, aviso a los usuarios con la hora de inicio y de fin.

## Después del incidente

Revisar por qué no llegó o no se atendió el aviso de 15 días: el buzón `CORREO_ALERTAS`, las notificaciones del superadministrador y el monitoreo externo.
