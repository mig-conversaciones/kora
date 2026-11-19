#!/usr/bin/env bash
# Shell de los pasos del trabajo «operacion» de la CI: ejecuta el script del paso y deja su salida en
# /tmp/operacion.log, para que el último paso la publique como anotación si algo falla. Sale con el código del paso.
set -o pipefail
bash --noprofile --norc -eo pipefail "$1" 2>&1 | tee -a /tmp/operacion.log
