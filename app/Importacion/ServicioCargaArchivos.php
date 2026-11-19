<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Nucleo\ErrorValidacion;

/**
 * Carga segura de libros (RF-084, RS-011). Solo .xlsx de hasta 10 MB, con firma ZIP y estructura OOXML, sin macros, y
 * con protección contra bombas ZIP (100 MB descomprimidos o 500 entradas como máximo). El archivo se guarda con un
 * nombre aleatorio fuera de public/ y nunca se ejecuta.
 */
final class ServicioCargaArchivos
{
    public const TAMANO_MAXIMO = 10 * 1024 * 1024;
    public const DESCOMPRIMIDO_MAXIMO = 100 * 1024 * 1024;
    public const ENTRADAS_MAXIMAS = 500;
    public const FILAS_MAXIMAS = 20000;

    public function __construct(private readonly string $directorio, private readonly bool $verificarSubida = true)
    {
    }

    /**
     * Valida y guarda el archivo subido.
     *
     * @param array{nombre:string,ruta:string,tamano:int,error:int}|null $archivo
     * @return array{ruta:string,relativa:string,nombre:string,hash:string,tamano:int}
     */
    public function guardar(?array $archivo): array
    {
        $error = fn (string $mensaje) => new ErrorValidacion(['archivo' => $mensaje]);
        if ($archivo === null || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
            throw $error('Elige el libro de Excel (.xlsx) que vas a importar.');
        }
        if (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $archivo['tamano'] > self::TAMANO_MAXIMO) {
            throw $error('El archivo supera 10 MB. Divide el libro o elimina hojas que no se importan.');
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK || ($this->verificarSubida && !is_uploaded_file($archivo['ruta']))) {
            throw $error('No se recibió el archivo completo. Vuelve a intentarlo.');
        }
        $extension = strtolower(pathinfo($archivo['nombre'], PATHINFO_EXTENSION));
        if (in_array($extension, ['xlsm', 'xls'], true)) {
            throw $error('Los libros .xlsm y .xls no se aceptan. Guarda el libro como «Libro de Excel (.xlsx)».');
        }
        if ($extension !== 'xlsx') {
            throw $error('Solo se aceptan libros de Excel .xlsx.');
        }
        $this->verificarContenido($archivo['ruta']);
        $hash = (string) hash_file('sha256', $archivo['ruta']);
        $relativa = gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.xlsx';
        $destino = $this->directorio . '/' . $relativa;
        if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0750, true) && !is_dir(dirname($destino))) {
            throw new \RuntimeException('No se pudo crear el directorio de importaciones.');
        }
        $movido = $this->verificarSubida ? move_uploaded_file($archivo['ruta'], $destino) : rename($archivo['ruta'], $destino);
        if (!$movido) {
            throw new \RuntimeException('No se pudo guardar el archivo de importación.');
        }
        $nombre = mb_substr((string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', basename($archivo['nombre'])), 0, 255);
        return ['ruta' => $destino, 'relativa' => $relativa, 'nombre' => $nombre, 'hash' => $hash, 'tamano' => $archivo['tamano']];
    }

    public function rutaAbsoluta(string $relativa): string
    {
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.xlsx$#', $relativa) !== 1) {
            throw new \InvalidArgumentException('Ruta de importación no válida.');
        }
        return $this->directorio . '/' . $relativa;
    }

    /** Firma ZIP, estructura OOXML de Excel, sin macros y sin bomba ZIP. */
    public function verificarContenido(string $ruta): void
    {
        $error = fn (string $mensaje) => new ErrorValidacion(['archivo' => $mensaje]);
        $cabecera = (string) file_get_contents($ruta, false, null, 0, 4);
        if ($cabecera !== "PK\x03\x04") {
            throw $error('El archivo no es un libro de Excel válido (su contenido no corresponde a un .xlsx).');
        }
        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::RDONLY) !== true) {
            throw $error('El libro está dañado y no se puede abrir.');
        }
        try {
            if ($zip->numFiles > self::ENTRADAS_MAXIMAS) {
                throw $error('El libro tiene demasiadas partes internas; no se procesa por seguridad.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $estado = $zip->statIndex($i);
                if ($estado === false) {
                    throw $error('El libro está dañado y no se puede abrir.');
                }
                $total += (int) $estado['size'];
                if ($total > self::DESCOMPRIMIDO_MAXIMO) {
                    throw $error('El libro descomprimido supera 100 MB; no se procesa por seguridad.');
                }
                if (str_contains(strtolower((string) $estado['name']), 'vbaproject.bin')) {
                    throw $error('El libro contiene macros; guárdalo como .xlsx sin macros.');
                }
            }
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw $error('El archivo no es un libro de Excel válido (su contenido no corresponde a un .xlsx).');
            }
        } finally {
            $zip->close();
        }
    }
}
