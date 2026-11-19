"""Ganchos de Schemathesis para las pruebas de contrato de KORA (RF-095).

Cada solicitud generada se firma como lo haría el software de ventas (anexo E):

    X-Firma = base64(HMAC-SHA256(secreto, METODO + "\n" + ruta + "\n" + X-Fecha + "\n" + hex(SHA-256(cuerpo))))

El cuerpo se serializa igual que lo envía `requests` con `json=` (json.dumps con sus valores por defecto), así la
huella coincide con los bytes que recibe el simulador. El secreto llega por SIMULADOR_CLAVES («id:secreto»).
"""
import base64
import datetime
import hashlib
import hmac
import json
import os

import schemathesis
from schemathesis.types import NotSet

CLAVE_ID, SECRETO = os.environ["SIMULADOR_CLAVES"].split(",")[0].split(":", 1)
PREFIJO = os.environ.get("KORA_PREFIJO", "/api/v1")


@schemathesis.auth()
class FirmaKora:
    def get(self, case, context):
        return CLAVE_ID

    def set(self, case, data, context):
        cuerpo = b"" if case.body is None or isinstance(case.body, NotSet) else json.dumps(case.body, allow_nan=False).encode("utf-8")
        fecha = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        ruta = PREFIJO.rstrip("/") + case.formatted_path
        canonico = "\n".join([case.method.upper(), ruta, fecha, hashlib.sha256(cuerpo).hexdigest()])
        firma = base64.b64encode(hmac.new(SECRETO.encode("utf-8"), canonico.encode("utf-8"), hashlib.sha256).digest()).decode("ascii")
        case.headers = case.headers or {}
        case.headers.update({"X-Clave-Id": data, "X-Fecha": fecha, "X-Firma": firma})
