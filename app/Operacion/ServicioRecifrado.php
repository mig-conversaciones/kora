<?php

declare(strict_types=1);

namespace App\Operacion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Cifrador;
use App\Servicios\ServicioNotificacionesCuenta;

/**
 * Recifrado tras una rotación de claves (RS-013, D-15; ASVS V11.1.1). Con el llavero «v2:…,v1:…», todo lo cifrado con
 * una clave anterior se descifra con ella y se vuelve a cifrar con la vigente (v2), con su mismo contexto. Cuando no
 * queda nada pendiente, la clave anterior se puede retirar del llavero.
 *
 * Lo que se recifra:
 *   - el secreto TOTP de cada usuario (usuarios.totp_secreto_cifrado);
 *   - los datos de los correos de la cola que aún pueden ejecutarse o reintentarse (pendientes y fallidos);
 *   - los lotes de auditoría exportados (almacenamiento/exportaciones/auditoria).
 * Los trabajos reservados no se tocan: terminan con la clave anterior, que sigue en el llavero hasta el final.
 * Cada cambio es condicional al valor leído, así que una ejecución concurrente o repetida no pisa nada.
 */
final class ServicioRecifrado
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly Cifrador $cifrador,
        private readonly string $directorioLotes,
    ) {
    }

    /** @return array{totp:int,correos:int,lotes_auditoria:int,total:int} lo que sigue cifrado con una clave anterior */
    public function pendientes(): array
    {
        $totp = 0;
        foreach ($this->bd->consultar('SELECT totp_secreto_cifrado AS s FROM usuarios WHERE totp_secreto_cifrado IS NOT NULL') as $f) {
            $totp += $this->cifrador->necesitaRecifrado((string) $f['s']) ? 1 : 0;
        }
        $correos = 0;
        foreach ($this->cargasCorreo() as $c) {
            $correos += $this->cifrador->necesitaRecifrado($c['sobre']) ? 1 : 0;
        }
        $lotes = 0;
        foreach ($this->lotes() as $archivo) {
            $lotes += $this->cifrador->necesitaRecifrado((string) file_get_contents($archivo)) ? 1 : 0;
        }
        return ['totp' => $totp, 'correos' => $correos, 'lotes_auditoria' => $lotes, 'total' => $totp + $correos + $lotes];
    }

    /**
     * Recifra hasta $limite elementos. $quedaTiempo permite cortar a tiempo dentro del presupuesto de la cola.
     *
     * @param (callable():bool)|null $quedaTiempo
     * @return array{totp:int,correos:int,lotes_auditoria:int,pendientes:int}
     */
    public function recifrar(int $limite = 500, ?callable $quedaTiempo = null): array
    {
        $hechos = ['totp' => 0, 'correos' => 0, 'lotes_auditoria' => 0];
        $seguir = static fn (): bool => $quedaTiempo === null || $quedaTiempo();
        $usados = 0;

        foreach ($this->bd->consultar('SELECT id, totp_secreto_cifrado AS s FROM usuarios WHERE totp_secreto_cifrado IS NOT NULL ORDER BY id') as $f) {
            if ($usados >= $limite || !$seguir()) {
                break;
            }
            $sobre = (string) $f['s'];
            if (!$this->cifrador->necesitaRecifrado($sobre)) {
                continue;
            }
            $contexto = 'usuarios.totp:' . $f['id'];
            $nuevo = $this->cifrador->cifrar($this->cifrador->descifrar($sobre, $contexto), $contexto);
            $hechos['totp'] += $this->bd->ejecutar('UPDATE usuarios SET totp_secreto_cifrado = :n WHERE id = :id AND totp_secreto_cifrado = :v', ['n' => $nuevo, 'id' => $f['id'], 'v' => $sobre]);
            $usados++;
        }

        foreach ($this->cargasCorreo() as $c) {
            if ($usados >= $limite || !$seguir()) {
                break;
            }
            if (!$this->cifrador->necesitaRecifrado($c['sobre'])) {
                continue;
            }
            $carga = $c['carga'];
            $carga['datos_cifrados'] = $this->cifrador->cifrar($this->cifrador->descifrar($c['sobre'], ServicioNotificacionesCuenta::CONTEXTO_CIFRADO), ServicioNotificacionesCuenta::CONTEXTO_CIFRADO);
            $estado = $c['tabla'] === 'trabajos' ? " AND estado IN ('pendiente','fallido')" : '';
            // Se relee con bloqueo: si el trabajo cambió de estado o de carga desde la lectura, no se toca.
            $hechos['correos'] += (int) $this->bd->transaccion(function (BaseDatos $bd) use ($c, $carga, $estado): int {
                $actual = $bd->valor("SELECT carga FROM {$c['tabla']} WHERE id = :id{$estado} FOR UPDATE", ['id' => $c['id']]);
                $leida = is_string($actual) ? json_decode($actual, true) : null;
                if (!is_array($leida) || ($leida['datos_cifrados'] ?? null) !== $c['sobre']) {
                    return 0;
                }
                return $bd->ejecutar("UPDATE {$c['tabla']} SET carga = :n WHERE id = :id", ['n' => json_encode($carga, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $c['id']]);
            });
            $usados++;
        }

        foreach ($this->lotes() as $archivo) {
            if ($usados >= $limite || !$seguir()) {
                break;
            }
            $sobre = (string) file_get_contents($archivo);
            if (!$this->cifrador->necesitaRecifrado($sobre)) {
                continue;
            }
            $contexto = 'auditoria.lote:' . substr(basename($archivo), strlen('auditoria-'), 10);
            $temporal = $archivo . '.recifrado.tmp';
            file_put_contents($temporal, $this->cifrador->cifrar($this->cifrador->descifrar($sobre, $contexto), $contexto), LOCK_EX);
            @chmod($temporal, 0640);
            rename($temporal, $archivo);
            $hechos['lotes_auditoria']++;
            $usados++;
        }

        return $hechos + ['pendientes' => $this->pendientes()['total']];
    }

    /** @return list<array{tabla:string,id:int,carga:array<string,mixed>,sobre:string}> */
    private function cargasCorreo(): array
    {
        $salida = [];
        $filas = array_merge(
            array_map(static fn (array $f): array => ['tabla' => 'trabajos'] + $f, $this->bd->consultar(
                "SELECT id, carga FROM trabajos WHERE tipo IN ('correo.sistema','correo.enviar') AND estado IN ('pendiente','fallido') ORDER BY id"
            )),
            array_map(static fn (array $f): array => ['tabla' => 'trabajos_fallidos'] + $f, $this->bd->consultar(
                "SELECT id, carga FROM trabajos_fallidos WHERE tipo IN ('correo.sistema','correo.enviar') ORDER BY id"
            )),
        );
        foreach ($filas as $f) {
            $carga = json_decode((string) $f['carga'], true);
            if (is_array($carga) && is_string($carga['datos_cifrados'] ?? null)) {
                $salida[] = ['tabla' => (string) $f['tabla'], 'id' => (int) $f['id'], 'carga' => $carga, 'sobre' => $carga['datos_cifrados']];
            }
        }
        return $salida;
    }

    /** @return list<string> */
    private function lotes(): array
    {
        return is_dir($this->directorioLotes) ? (glob($this->directorioLotes . '/auditoria-????-??-??.jsonl.cifrado') ?: []) : [];
    }
}
