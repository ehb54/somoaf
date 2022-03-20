#!/usr/local/bin/php
<?php

    
require '/var/www/html/somoaf/vendor/autoload.php'; ## include Composer's autoloader

function json_exit() {
    global $output;
    echo json_encode( $output );
    exit;
}    

### open db

try {
    $db_mongo = new MongoDB\Client();
} catch ( Exception $e ) {
    $output->errors = "Error connecting to db " . $e->getMessage();
    json_exit();
}

### find in db
try {
    $foundcursor = $db_mongo->somo->afd->find(
        []
        ,[
            'projection' => [
                '_id' => 1
                ,"proc" => 1
                ,"sp" => 1
            ]
        ]
        );
} catch ( MongoDB\Exception\UnsupportedException $e ) {
    $output->errors = "Error finding " .  $e->getMessage();
    json_exit();
} catch ( MongoDB\Exception\InvalidArgumentException $e ) {
    $output->errors = "Error finding " .  $e->getMessage();
    json_exit();
} catch ( MongoDB\Exception\RuntimeException $e ) {
    $output->errors = "Error finding " .  $e->getMessage();
    json_exit();
}

$ids = [];
foreach( $foundcursor as $doc ) {
    # echo json_encode( $doc );
    if ( strpos( $doc->_id, "-pp" ) !== false ) {
        continue;
    }
    if ( isset( $doc->proc ) ) {
        if ( $doc->proc == "none" ) {
            continue;
        }
        echo "$doc->_id : modified\n";
        continue;
    }
    if ( !isset( $doc->sp ) ) {
        continue;
    }
    echo "$doc->_id : modified\n";
}

