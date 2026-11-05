<?php
/**
 * Server Requirements Checker — verify a server meets a JSON profile
 * (PHP version, extensions, php.ini limits, writable paths) before you
 * install WordPress or deploy an app. CI-friendly exit codes.
 *
 * Usage:
 *   php check-requirements.php [--profile=profiles/wordpress.json] [--json]
 *
 * Exit codes: 0 = everything passed, 1 = warnings only, 2 = failures.
 */

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 2 );
}

$options = getopt( '', array( 'profile:', 'json' ) );
$profilePath = isset( $options['profile'] ) ? $options['profile'] : __DIR__ . '/profiles/wordpress.json';
$asJson      = isset( $options['json'] );

if ( ! is_readable( $profilePath ) ) {
    fwrite( STDERR, "Profile not found or not readable: {$profilePath}\n" );
    exit( 2 );
}

$profile = json_decode( file_get_contents( $profilePath ), true );
if ( ! is_array( $profile ) ) {
    fwrite( STDERR, "Profile is not valid JSON: {$profilePath}\n" );
    exit( 2 );
}

$results = array(); // each: ['status' => pass|warn|fail, 'check' => ..., 'detail' => ...]

function add_result( &$results, $status, $check, $detail = '' ) {
    $results[] = array( 'status' => $status, 'check' => $check, 'detail' => $detail );
}

/**
 * Parse a php.ini shorthand byte value ("256M", "1G", "512K", "-1").
 * Returns bytes as int, or -1 for unlimited.
 */
function parse_bytes( $value ) {
    $value = trim( (string) $value );
    if ( '-1' === $value || '' === $value ) {
        return -1;
    }
    $unit = strtolower( substr( $value, -1 ) );
    $num  = (float) $value;
    switch ( $unit ) {
        case 'g':
            $num *= 1024 * 1024 * 1024;
            break;
        case 'm':
            $num *= 1024 * 1024;
            break;
        case 'k':
            $num *= 1024;
            break;
    }
    return (int) $num;
}

function format_bytes( $bytes ) {
    if ( $bytes < 0 ) {
        return 'unlimited';
    }
    if ( $bytes >= 1024 * 1024 * 1024 ) {
        return round( $bytes / ( 1024 * 1024 * 1024 ), 2 ) . 'G';
    }
    if ( $bytes >= 1024 * 1024 ) {
        return round( $bytes / ( 1024 * 1024 ), 2 ) . 'M';
    }
    if ( $bytes >= 1024 ) {
        return round( $bytes / 1024, 2 ) . 'K';
    }
    return $bytes . 'B';
}

/* ---------------- PHP version ---------------- */

if ( ! empty( $profile['php_version'] ) ) {
    $required = $profile['php_version'];
    $current  = PHP_VERSION;
    if ( version_compare( $current, $required, '>=' ) ) {
        add_result( $results, 'pass', "PHP >= {$required}", "running {$current}" );
    } else {
        add_result( $results, 'fail', "PHP >= {$required}", "running {$current}" );
    }
}

/* ---------------- Extensions ---------------- */

foreach ( (array) ( isset( $profile['extensions']['required'] ) ? $profile['extensions']['required'] : array() ) as $ext ) {
    if ( extension_loaded( $ext ) ) {
        add_result( $results, 'pass', "Extension: {$ext}", 'loaded' );
    } else {
        add_result( $results, 'fail', "Extension: {$ext}", 'missing' );
    }
}
foreach ( (array) ( isset( $profile['extensions']['recommended'] ) ? $profile['extensions']['recommended'] : array() ) as $ext ) {
    if ( extension_loaded( $ext ) ) {
        add_result( $results, 'pass', "Extension: {$ext}", 'loaded' );
    } else {
        add_result( $results, 'warn', "Extension: {$ext}", 'missing (recommended)' );
    }
}

/* ---------------- php.ini values ---------------- */

foreach ( (array) ( isset( $profile['ini'] ) ? $profile['ini'] : array() ) as $key => $rule ) {
    $currentRaw = ini_get( $key );
    if ( false === $currentRaw ) {
        add_result( $results, 'warn', "ini: {$key}", 'unknown ini setting' );
        continue;
    }

    $type     = isset( $rule['type'] ) ? $rule['type'] : 'bytes';
    $severity = isset( $rule['severity'] ) ? $rule['severity'] : 'fail';

    if ( 'bytes' === $type ) {
        $current  = parse_bytes( $currentRaw );
        $required = parse_bytes( $rule['min'] );
        $ok       = ( -1 === $current ) || ( $current >= $required );
        $detail   = 'current ' . format_bytes( $current ) . ', requires >= ' . $rule['min'];
    } elseif ( 'seconds' === $type ) {
        $current  = (int) $currentRaw;
        $required = (int) $rule['min'];
        $ok       = ( 0 === $current ) || ( $current >= $required ); // 0 = unlimited
        $detail   = "current {$current}s, requires >= {$required}s";
    } elseif ( 'number' === $type ) {
        $current  = (int) $currentRaw;
        $required = (int) $rule['min'];
        $ok       = ( $current >= $required );
        $detail   = "current {$current}, requires >= {$required}";
    } else { // 'value': exact string match (on/off, 1/0, timezone, ...)
        $ok     = ( strtolower( trim( $currentRaw ) ) === strtolower( trim( (string) $rule['equals'] ) ) );
        $detail = "current '{$currentRaw}', requires '{$rule['equals']}'";
    }

    if ( $ok ) {
        add_result( $results, 'pass', "ini: {$key}", $detail );
    } else {
        add_result( $results, $severity, "ini: {$key}", $detail );
    }
}

/* ---------------- Writable paths ---------------- */

foreach ( (array) ( isset( $profile['writable_paths'] ) ? $profile['writable_paths'] : array() ) as $path ) {
    if ( is_writable( $path ) ) {
        add_result( $results, 'pass', "Writable: {$path}", 'writable' );
    } elseif ( ! file_exists( $path ) ) {
        add_result( $results, 'fail', "Writable: {$path}", 'path does not exist' );
    } else {
        add_result( $results, 'fail', "Writable: {$path}", 'not writable' );
    }
}

/* ---------------- Functions ---------------- */

foreach ( (array) ( isset( $profile['functions']['required'] ) ? $profile['functions']['required'] : array() ) as $fn ) {
    if ( function_exists( $fn ) ) {
        add_result( $results, 'pass', "Function: {$fn}()", 'available' );
    } else {
        add_result( $results, 'fail', "Function: {$fn}()", 'missing or disabled' );
    }
}

/* ---------------- Report ---------------- */

$counts = array( 'pass' => 0, 'warn' => 0, 'fail' => 0 );
foreach ( $results as $r ) {
    $counts[ $r['status'] ]++;
}

$name = isset( $profile['name'] ) ? $profile['name'] : basename( $profilePath );

if ( $asJson ) {
    echo json_encode( array(
        'profile' => $name,
        'summary' => $counts,
        'checks'  => $results,
    ), JSON_PRETTY_PRINT ) . "\n";
} else {
    echo "Server requirements: {$name}\n";
    echo str_repeat( '=', 60 ) . "\n";
    foreach ( $results as $r ) {
        $tag = strtoupper( $r['status'] );
        printf( "[%s] %s%s\n", $tag, $r['check'], '' !== $r['detail'] ? ' — ' . $r['detail'] : '' );
    }
    echo str_repeat( '-', 60 ) . "\n";
    printf( "%d passed, %d warnings, %d failed\n", $counts['pass'], $counts['warn'], $counts['fail'] );
}

exit( $counts['fail'] > 0 ? 2 : ( $counts['warn'] > 0 ? 1 : 0 ) );
