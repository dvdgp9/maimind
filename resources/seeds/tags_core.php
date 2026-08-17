<?php

declare(strict_types=1);

/**
 * DOMINIOS VITALES
 *
 * Vocabulario cerrado y pequeño para etiquetar observaciones por área de la vida.
 * Sirve para preguntas del tipo "¿cómo van mis semanas cuando el trabajo aprieta?".
 *
 * Deliberadamente cortos y sin solapamiento. Los temas más finos (un proyecto
 * concreto, una preocupación recurrente) no van aquí: emergen como `theme` a
 * partir de lo que el usuario cuenta.
 */

return [
    ['slug' => 'work',    'name' => 'Trabajo',   'en' => 'Work'],
    ['slug' => 'studies', 'name' => 'Estudios',  'en' => 'Studies'],
    ['slug' => 'partner', 'name' => 'Pareja',    'en' => 'Partner'],
    ['slug' => 'family',  'name' => 'Familia',   'en' => 'Family'],
    ['slug' => 'friends', 'name' => 'Amistades', 'en' => 'Friends'],
    ['slug' => 'health',  'name' => 'Salud',     'en' => 'Health'],
    ['slug' => 'money',   'name' => 'Dinero',    'en' => 'Money'],
    ['slug' => 'home',    'name' => 'Hogar',     'en' => 'Home'],
    ['slug' => 'leisure', 'name' => 'Ocio',      'en' => 'Leisure'],
    ['slug' => 'self',    'name' => 'Uno mismo', 'en' => 'Self'],
];
