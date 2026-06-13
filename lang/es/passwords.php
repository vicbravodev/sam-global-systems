<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Líneas de restablecimiento de contraseña
    |--------------------------------------------------------------------------
    */

    'reset' => 'Tu contraseña ha sido restablecida.',
    'sent' => 'Te hemos enviado por correo el enlace para restablecer tu contraseña.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token' => 'El código de restablecimiento de contraseña no es válido.',
    'user' => 'No encontramos ningún usuario con ese correo electrónico.',

    // Mensaje neutro para evitar enumeración de usuarios en /forgot-password:
    // se muestra igual exista o no la cuenta (E4).
    'neutral' => 'Si el correo existe, te enviaremos las instrucciones para restablecer tu contraseña.',

];
