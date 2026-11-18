<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\GeneradorUlid;
use PHPMailer\PHPMailer\Exception as ExcepcionPhpMailer;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Envío de correos de plantilla (Anexo I; RI-009). Siempre se invoca desde la cola (EnviarCorreo), nunca en la
 * solicitud web. El destinatario viaja como id de usuario y su dirección se resuelve aquí, al enviar
 * (minimización de PII en la carga del trabajo). Los correos del sistema pueden ir al buzón de operaciones.
 *
 * Transportes: «smtp» (Hostinger, TLS implícito en 465 o STARTTLS en 587) y «archivo» (desarrollo: guarda el
 * mensaje .eml en almacenamiento/correos, porque MAMP no tiene servidor de correo).
 */
final class ServicioCorreo
{
    /**
     * @param array{transporte:string,host:string,puerto:int,cifrado:string,usuario:string,contrasena:string,remitente:string,nombre_remitente:string,buzon_operaciones:string,directorio_archivo:string,url_aplicacion:string} $config
     */
    public function __construct(private readonly BaseDatos $bd, private readonly array $config)
    {
    }

    /**
     * @param int|string $destinatario id de usuario, o «operaciones» para el buzón de alertas
     * @param array<string,mixed> $datos variables de la plantilla (sin secretos)
     * @return string Message-ID asignado (se guarda como punto de control para no reenviar, RF-Q-010)
     */
    public function enviarPlantilla(string $plantilla, int|string $destinatario, array $datos = []): string
    {
        [$correo, $nombre] = $this->resolverDestinatario($destinatario);
        $contenido = $this->renderizar($plantilla, $datos + ['nombre' => $nombre]);
        $mensajeId = '<' . strtolower(GeneradorUlid::generar()) . '@' . $this->dominio() . '>';

        $correoSalida = new PHPMailer(true);
        try {
            $correoSalida->CharSet = PHPMailer::CHARSET_UTF8;
            $correoSalida->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $correoSalida->setFrom($this->config['remitente'], $this->config['nombre_remitente']);
            $correoSalida->addAddress($correo, $nombre);
            $correoSalida->MessageID = $mensajeId;
            $correoSalida->Subject = $contenido['asunto'];
            $correoSalida->isHTML(true);
            $correoSalida->Body = $this->html($contenido['asunto'], $contenido['texto']);
            $correoSalida->AltBody = $contenido['texto'];
            if ($this->config['transporte'] === 'archivo') {
                $this->guardarEnArchivo($correoSalida, $mensajeId);
                return $mensajeId;
            }
            $correoSalida->isSMTP();
            $correoSalida->Host = $this->config['host'];
            $correoSalida->Port = $this->config['puerto'];
            $correoSalida->SMTPAuth = $this->config['usuario'] !== '';
            $correoSalida->Username = $this->config['usuario'];
            $correoSalida->Password = $this->config['contrasena'];
            $correoSalida->SMTPSecure = $this->config['cifrado'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $correoSalida->Timeout = 15;
            $correoSalida->send();
        } catch (ExcepcionPhpMailer $error) {
            // Dirección inválida: rechazo definitivo. Lo demás (red, autenticación, servidor) es transitorio.
            if (str_contains($error->getMessage(), 'Invalid address')) {
                throw new CorreoRechazado('Dirección de correo no válida.');
            }
            throw new RuntimeException('Fallo de entrega SMTP.', 0, $error);
        }
        return $mensajeId;
    }

    /**
     * @param array<string,mixed> $datos
     * @return array{asunto:string,texto:string}
     */
    public function renderizar(string $plantilla, array $datos): array
    {
        if (preg_match('/^[a-z_]+$/', $plantilla) !== 1 || !is_file($archivo = RAIZ . "/app/Vistas/correos/{$plantilla}.php")) {
            throw new CorreoRechazado("Plantilla de correo inexistente: {$plantilla}.");
        }
        $datos += ['url_aplicacion' => $this->config['url_aplicacion']];
        /** @var array{asunto:string,texto:string} $resultado */
        $resultado = (static function (string $__archivo, array $datos): array {
            return require $__archivo;
        })($archivo, $datos);
        return $resultado;
    }

    /** @return array{0:string,1:string} correo y nombre */
    private function resolverDestinatario(int|string $destinatario): array
    {
        if ($destinatario === 'operaciones') {
            if ($this->config['buzon_operaciones'] === '') {
                throw new CorreoRechazado('No hay buzón de operaciones configurado (CORREO_ALERTAS).');
            }
            return [$this->config['buzon_operaciones'], 'Operaciones KORA'];
        }
        $fila = $this->bd->fila(
            "SELECT correo, nombre FROM usuarios WHERE id = :id AND estado <> 'desactivado' AND anonimizado_en IS NULL",
            ['id' => (int) $destinatario]
        );
        if ($fila === null) {
            throw new CorreoRechazado('El destinatario no existe o está desactivado.');
        }
        return [(string) $fila['correo'], (string) $fila['nombre']];
    }

    /** Versión HTML accesible: una columna, contraste AA, sin imágenes remotas (Anexo I). */
    private function html(string $asunto, string $texto): string
    {
        $parrafos = array_map(
            static fn (string $p): string => '<p style="margin:0 0 16px">' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>',
            preg_split('/\R{2,}/', trim($texto)) ?: []
        );
        $titulo = htmlspecialchars($asunto, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="es-CO"><head><meta charset="utf-8"><title>' . $titulo . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f6f8fa">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fa"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #d6e0e6;border-radius:10px">'
            . '<tr><td style="background:#182b43;color:#ffffff;padding:16px 24px;font:700 20px/1.2 Arial,sans-serif;letter-spacing:2px;border-radius:10px 10px 0 0">KORA</td></tr>'
            . '<tr><td style="padding:24px;color:#162538;font:16px/1.5 Arial,sans-serif">' . implode('', $parrafos) . '</td></tr>'
            . '<tr><td style="padding:16px 24px;border-top:1px solid #d6e0e6;color:#526477;font:13px/1.4 Arial,sans-serif">KORA · Sistema de gestión productiva · SENA, Centro Agropecuario de Buga</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private function guardarEnArchivo(PHPMailer $correo, string $mensajeId): void
    {
        $correo->preSend();
        $directorio = $this->config['directorio_archivo'];
        if (!is_dir($directorio)) {
            mkdir($directorio, 0700, true);
        }
        $nombre = gmdate('Ymd\THis') . '-' . trim($mensajeId, '<>') . '.eml';
        $nombre = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre);
        file_put_contents($directorio . '/' . $nombre, $correo->getSentMIMEMessage(), LOCK_EX);
    }

    private function dominio(): string
    {
        $dominio = substr((string) strrchr($this->config['remitente'], '@'), 1);
        return $dominio !== '' ? $dominio : 'kora.local';
    }
}
