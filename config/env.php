<?php
// Copiar, editar y subir. También podés setear estas variables como ENV en Render.
return [
'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
'DB_PORT' => getenv('DB_PORT') ?: '3306',
'DB_NAME' => getenv('DB_NAME') ?: 'luna',
'DB_USER' => getenv('DB_USER') ?: 'root',
'DB_PASS' => getenv('DB_PASS') ?: '',


// Mercado Pago (Argentina): token de acceso de PRODUCCIÓN o TEST
'MP_ACCESS_TOKEN' => getenv('MP_ACCESS_TOKEN') ?: '',


// Marca y moneda
'STORE_NAME' => getenv('STORE_NAME') ?: 'LUNA — Tienda de Ropa',
'CURRENCY' => getenv('CURRENCY') ?: 'ARS',


// Transferencia bancaria (mostrado en checkout)
'TRANSFER_ALIAS' => getenv('TRANSFER_ALIAS') ?: 'voleyppp',
'BANK_INFO' => getenv('BANK_INFO') ?: 'Alias: voleyppp · CBU: 0000000000000000000000 · Titular: LUNA',
];