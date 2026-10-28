<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    |
    | Set some default values. It is possible to add all defines that can be set
    | in dompdf_config.inc.php. You can also override the default font
    |
    */
    'show_warnings' => false,   // Throw an Exception on warnings from dompdf

    'public_path' => null, // Override the public path if needed

    /*
     * Dejavu Sans font is missing glyphs for converted entities, turn it off if you need to show € or £.
     */
    'convert_entities' => true,

    'options' => [
        /**
         * The root of your DOMPDF installation
         */
        'font_dir' => storage_path('fonts'),

        /**
         * The directory in which to store fonts
         */
        'font_cache' => storage_path('fonts'),

        /**
         * The directory in which to store temporary files
         */
        'temp_dir' => sys_get_temp_dir(),

        /**
         * Dompdf's "chroot": prevent access to arbitrary system files
         */
        'chroot' => realpath(base_path()),

        /**
         * Protocol whitelist
         */
        'allowed_protocols' => [
            'file://' => ['rules' => []],
            'http://' => ['rules' => []],
            'https://' => ['rules' => []],
            'data://' => ['rules' => []],
        ],

        /**
         * A path to an internal log file
         */
        'log_output_file' => null,

        /**
         * Whether to enable font subsetting or not.
         */
        'enable_font_subsetting' => false,

        /**
         * The PDF rendering backend to use
         */
        'pdf_backend' => 'CPDF',

        /**
         * Media type to use: screen, tty, tv, projection, print, handheld, all
         */
        'default_media_type' => 'screen',

        /**
         * The default paper size: letter, legal, a4, etc.
         */
        'default_paper_size' => 'a4',

        'default_paper_orientation' => 'portrait',

        /**
         * The default font family
         */
        'default_font' => 'sans-serif',

        /**
         * Image DPI setting
         */
        'dpi' => 96,

        /**
         * Enable embedded PHP
         */
        'enable_php' => false,

        /**
         * Enable inline JavaScript
         */
        'enable_javascript' => false,

        /**
         * Enable remote file access (images, css)
         */
        'enable_remote' => true,

        /**
         * DOMPDF's HTML5 parser
         */
        'isHtml5ParserEnabled' => true,

        /**
         * Dompdf isRemoteEnabled
         */
        'isRemoteEnabled' => true,
    ],

];
