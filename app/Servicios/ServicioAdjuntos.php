<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorValidacion;
use RuntimeException;

/**
 * Soportes documentales en almacenamiento privado (RF-027, RS-011):
 *   - Solo PDF, PNG o JPG de hasta 5 MB. La extensión debe coincidir con la firma real del contenido (bytes
 *     mágicos): un .pdf que en realidad es un ejecutable se rechaza.
 *   - Se guardan con un nombre aleatorio fuera de public/, en subcarpetas por año y mes. El nombre original solo se
 *     conserva como dato, nunca como ruta.
 *   - La descarga la decide el controlador con la autorización del registro dueño; sin permiso responde 404.
 */
final class ServicioAdjuntos
{
    public const TAMANO_MAXIMO = 5 * 1024 * 1024;
    /** extensión => [tipo MIME, firma inicial] */
    private const TIPOS = [
        'pdf' => ['application/pdf', '%PDF-'],
        'png' => ['image/png', "\x89PNG\r\n\x1a\n"],
        'jpg' => ['image/jpeg', "\xFF\xD8\xFF"],
        'jpeg' => ['image/jpeg', "\xFF\xD8\xFF"],
    ];

    /** @param bool $verificarSubida false solo en pruebas: acepta archivos que no llegaron por HTTP */
    public function __construct(private readonly BaseDatos $bd, private readonly string $directorio, private readonly bool $verificarSubida = true)
    {
    }

    /**
     * Valida y guarda el archivo; devuelve el id de archivos_adjuntos. Debe llamarse dentro de la transacción del
     * registro dueño. Si la transacción falla, el archivo huérfano lo retira la limpieza periódica.
     *
     * @param array{nombre:string,ruta:string,tamano:int,error:int} $archivo
     */
    public function guardar(array $archivo, int $centroId, ?int $unidadId, string $entidad, int $entidadId, int $usuarioId): int
    {
        $tipo = $this->validar($archivo);
        $nombreOriginal = mb_substr(basename(str_replace('\\', '/', $archivo['nombre'])), 0, 255);
        $relativa = gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . ($tipo === 'image/jpeg' ? 'jpg' : ($tipo === 'image/png' ? 'png' : 'pdf'));
        $destino = $this->directorio . '/' . $relativa;
        if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0750, true) && !is_dir(dirname($destino))) {
            throw new RuntimeException('No se pudo crear el directorio de adjuntos.');
        }
        $movido = $this->verificarSubida ? move_uploaded_file($archivo['ruta'], $destino) : copy($archivo['ruta'], $destino);
        if (!$movido) {
            throw new RuntimeException('No se pudo guardar el adjunto.');
        }
        return $this->bd->insertar(
            'INSERT INTO archivos_adjuntos (centro_id, unidad_id, entidad, entidad_id, nombre_original, ruta_privada, tipo_mime, tamano, sha256, creado_por)
             VALUES (:centro, :unidad, :entidad, :entidad_id, :nombre, :ruta, :tipo, :tamano, :sha, :actor)',
            [
                'centro' => $centroId, 'unidad' => $unidadId, 'entidad' => $entidad, 'entidad_id' => $entidadId,
                'nombre' => $nombreOriginal, 'ruta' => $relativa, 'tipo' => $tipo, 'tamano' => (int) filesize($destino),
                'sha' => (string) hash_file('sha256', $destino), 'actor' => $usuarioId,
            ]
        );
    }

    /**
     * @param array{nombre:string,ruta:string,tamano:int,error:int} $archivo
     * @return string tipo MIME detectado
     */
    public function validar(array $archivo): string
    {
        if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE || $archivo['tamano'] > self::TAMANO_MAXIMO) {
            throw new ErrorValidacion(['soporte' => 'El soporte supera los 5 MB.']);
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK || !is_file($archivo['ruta']) || ($this->verificarSubida && !is_uploaded_file($archivo['ruta']))) {
            throw new ErrorValidacion(['soporte' => 'No se recibió el archivo; vuelve a adjuntarlo.']);
        }
        $extension = strtolower(pathinfo($archivo['nombre'], PATHINFO_EXTENSION));
        if (!isset(self::TIPOS[$extension])) {
            throw new ErrorValidacion(['soporte' => 'Solo se admiten soportes PDF, PNG o JPG.']);
        }
        [$tipo, $firma] = self::TIPOS[$extension];
        $inicio = (string) file_get_contents($archivo['ruta'], false, null, 0, 16);
        if (!str_starts_with($inicio, $firma)) {
            throw new ErrorValidacion(['soporte' => 'El contenido del archivo no corresponde a un ' . strtoupper($extension) . '. Exporta el soporte de nuevo.']);
        }
        if (filesize($archivo['ruta']) > self::TAMANO_MAXIMO) {
            throw new ErrorValidacion(['soporte' => 'El soporte supera los 5 MB.']);
        }
        return $tipo;
    }

    /** @return list<array<string,mixed>> */
    public function deEntidad(string $entidad, int $entidadId): array
    {
        return $this->bd->consultar(
            'SELECT id, nombre_original, tipo_mime, tamano, creado_en FROM archivos_adjuntos WHERE entidad = :e AND entidad_id = :id AND archivado_en IS NULL ORDER BY id',
            ['e' => $entidad, 'id' => $entidadId]
        );
    }

    /** @return array<string,mixed>|null */
    public function obtener(int $id): ?array
    {
        return $this->bd->fila('SELECT * FROM archivos_adjuntos WHERE id = :id AND archivado_en IS NULL', ['id' => $id]);
    }

    /** Ruta absoluta del adjunto, verificando que no escape del directorio privado. */
    public function rutaAbsoluta(string $relativa): ?string
    {
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(pdf|png|jpg)$#', $relativa) !== 1) {
            return null;
        }
        $ruta = $this->directorio . '/' . $relativa;
        return is_file($ruta) ? $ruta : null;
    }
}
