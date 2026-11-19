<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Politicas\AlcanceDatos;
use App\Servicios\ServicioCatalogos;

/**
 * Contexto de un lote de importación en el trabajo de la cola: centro, actor, permisos y alcance releídos de la base
 * al empezar (RAC-024: los permisos se reevalúan antes de producir efectos), opciones elegidas al cargar y cachés de
 * los catálogos que consultan las fuentes.
 */
final class ContextoImportacion
{
    /** @var array<string,int> */
    private array $unidades = [];
    /** @var array<string,int> */
    private array $prefijos = [];
    /** @var array<string,array<string,mixed>|null> */
    private array $productos = [];
    /** @var array<string,int> ocurrencias de una clave base dentro del archivo (salidas) */
    public array $ocurrencias = [];
    /** @var array<string,mixed> memoria de la aplicación (por ejemplo, el corte del hato ya creado) */
    public array $memoria = [];

    /**
     * @param list<string> $permisos
     * @param array<string,mixed> $opciones
     */
    public function __construct(
        public readonly BaseDatos $bd,
        public readonly int $centroId,
        public readonly int $usuarioId,
        public readonly array $permisos,
        public readonly AlcanceDatos $alcance,
        public readonly array $opciones,
        public readonly int $loteId = 0,
    ) {
        foreach ($bd->consultar('SELECT id, codigo, prefijo_lote FROM unidades_productivas WHERE centro_id = :c', ['c' => $centroId]) as $u) {
            $this->unidades[(string) $u['codigo']] = (int) $u['id'];
            $this->prefijos[strtoupper((string) $u['prefijo_lote'])] = (int) $u['id'];
        }
    }

    /**
     * Contexto del actor de un lote, con sus permisos y unidades vigentes.
     *
     * @param array<string,mixed> $lote
     */
    public static function delLote(BaseDatos $bd, array $lote): self
    {
        $usuario = (int) $lote['creado_por'];
        $activo = (string) ($bd->valor('SELECT estado FROM usuarios WHERE id = :u', ['u' => $usuario]) ?? '') === 'activo';
        $permisos = !$activo ? [] : array_map('strval', array_column($bd->consultar(
            'SELECT DISTINCT p.codigo FROM usuarios_roles ur JOIN roles_permisos rp ON rp.rol_id = ur.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE ur.usuario_id = :u',
            ['u' => $usuario]
        ), 'codigo'));
        $centro = (int) (($bd->valor("SELECT COUNT(*) FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :u AND r.alcance IN ('centro', 'global')", ['u' => $usuario])) ?? 0) > 0;
        $unidades = array_map('intval', array_column($bd->consultar('SELECT unidad_id FROM usuarios_unidades WHERE usuario_id = :u', ['u' => $usuario]), 'unidad_id'));
        $resumen = json_decode((string) ($lote['resumen'] ?? '{}'), true);
        $opciones = is_array($resumen) && is_array($resumen['opciones'] ?? null) ? $resumen['opciones'] : [];
        return new self($bd, (int) $lote['centro_id'], $usuario, $permisos, AlcanceDatos::deUsuario((int) $lote['centro_id'], $unidades, $centro), $opciones, (int) $lote['id']);
    }

    public function tienePermiso(string $permiso): bool
    {
        return in_array($permiso, $this->permisos, true);
    }

    public function unidadId(?string $codigo): ?int
    {
        return $codigo === null ? null : ($this->unidades[$codigo] ?? null);
    }

    public function unidadPorPrefijo(string $prefijo): ?int
    {
        return $this->prefijos[strtoupper($prefijo)] ?? null;
    }

    public function codigoUnidad(int $id): ?string
    {
        $codigo = array_search($id, $this->unidades, true);
        return $codigo === false ? null : (string) $codigo;
    }

    /** @return array<string,mixed>|null producto activo por código SGAC */
    public function producto(?string $codigo): ?array
    {
        if ($codigo === null || $codigo === '') {
            return null;
        }
        if (!array_key_exists($codigo, $this->productos)) {
            $this->productos[$codigo] = $this->bd->fila(
                'SELECT id, codigo_sgac, nombre, medida FROM productos WHERE centro_id = :c AND codigo_sgac = :s AND archivado_en IS NULL',
                ['c' => $this->centroId, 's' => $codigo]
            );
        }
        return $this->productos[$codigo];
    }

    public function olvidarProducto(string $codigo): void
    {
        unset($this->productos[$codigo]);
    }

    /**
     * Producto homologado para un texto de la fuente (RF-019): tabla de homologación o nombre exacto del catálogo.
     * Un concepto genérico registrado sin producto devuelve null.
     */
    public function homologar(?string $texto): ?int
    {
        if ($texto === null) {
            return null;
        }
        $normalizado = ServicioCatalogos::normalizarConcepto($texto);
        $fila = $this->bd->fila('SELECT producto_id FROM homologaciones_producto WHERE centro_id = :c AND texto_normalizado = :t', ['c' => $this->centroId, 't' => $normalizado]);
        if ($fila !== null) {
            return $fila['producto_id'] === null ? null : (int) $fila['producto_id'];
        }
        foreach ($this->bd->consultar('SELECT id, nombre FROM productos WHERE centro_id = :c AND archivado_en IS NULL', ['c' => $this->centroId]) as $p) {
            if (ServicioCatalogos::normalizarConcepto((string) $p['nombre']) === $normalizado) {
                return (int) $p['id'];
            }
        }
        return $normalizado === 'leche' ? (int) ($this->bd->valor("SELECT id FROM productos WHERE centro_id = :c AND codigo_sgac = '5013170101' AND archivado_en IS NULL", ['c' => $this->centroId]) ?? 0) ?: null : null;
    }

    /** HMAC-SHA256 de un dato personal con la clave del servidor (RF-034). Null si no hay clave configurada. */
    public function hmacPii(string $valor): ?string
    {
        $clave = base64_decode(Entorno::texto('CLAVE_HMAC_PII', ''), true);
        return $valor === '' || $clave === false || $clave === '' ? null : hash_hmac('sha256', mb_strtolower($valor), $clave);
    }

    public function opcion(string $clave): mixed
    {
        return $this->opciones[$clave] ?? null;
    }
}
