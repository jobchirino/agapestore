<?php

/*
 * Mensajes de validación del framework en español.
 *
 * Solo se cubren las reglas que usa la aplicación. Las claves que falten caen al
 * fallback (en), por eso APP_FALLBACK_LOCALE sigue en inglés.
 */

return [
    'accepted' => 'Debes aceptar :attribute.',
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',

    'current_password' => 'La contraseña no es correcta.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',

    'exists' => 'El valor seleccionado en :attribute no es válido.',

    'file' => 'El campo :attribute debe ser un archivo.',
    'image' => 'El campo :attribute debe ser una imagen.',

    'in' => 'El valor seleccionado en :attribute no es válido.',
    'integer' => 'El campo :attribute debe ser un número entero.',

    'max' => [
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El campo :attribute no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],

    'min' => [
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El campo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],

    'numeric' => 'El campo :attribute debe ser un número.',
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser texto.',

    'uploaded' => 'El campo :attribute no se pudo subir. Revisa el tamaño del archivo e intenta de nuevo.',

    'custom' => [
        'payment_proof' => [
            'max' => 'El comprobante no puede pesar más de 5 MB.',
        ],
    ],

    'attributes' => [
        'address' => 'dirección',
        'category_id' => 'categoría',
        'date' => 'fecha',
        'detal_price' => 'precio detal',
        'email' => 'correo electrónico',
        'initial_payment' => 'inicial',
        'major_price' => 'costo mayor',
        'name' => 'nombre',
        'payment_method' => 'método de pago',
        'payment_proof' => 'comprobante',
        'payment_type' => 'tipo de pago',
        'phone' => 'teléfono',
        'quantity' => 'cantidad',
        'total_stock' => 'stock',
    ],
];
