<?php
// SagipAlert configuration. Edit these values to match your MySQL/MariaDB setup.
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'sagipalert',
    'db_user' => 'root',
    'db_pass' => '',
    'app_name' => 'SagipAlert',
    'timezone' => 'Asia/Manila',
    'max_upload_bytes' => 5 * 1024 * 1024, // 5 MB per file
    'allowed_ext' => ['jpg', 'jpeg', 'png', 'pdf'],
    'barangays' => [
        'Cadulawan', 'Calajoan', 'Camp 7', 'Cuanos', 'Guindaruhan', 'Linao',
        'Manduang', 'Pakigne', 'Poblacion Ward I', 'Poblacion Ward II',
        'Poblacion Ward III', 'Poblacion Ward IV', 'Tubod', 'Tulay',
        'Tunghaan', 'Tungkop', 'Turo', 'Vito', 'Lipata',
    ],
    'incident_types' => ['Fire', 'Vehicular Accident', 'Medical Emergency', 'Flood', 'Landslide', 'Earthquake', 'Crime / Public Safety', 'Other'],
];
