#!/usr/local/bin/php
<?php

if ( count( $argv ) != 2 ) {
    echo '{"error":"$self requires a JSON input object"}';
    exit;
}

$json_input = $argv[1];

$input = json_decode( $json_input );


if ( !$input ) {
    echo '{"error":"invalid JSON"}';
    exit;
}

if ( !isset( $input->searchkey ) ) {
    echo '{"error":"searchkey is missing"}';
    exit;
}

$output = (object)[];

## process inputs here to produce output

### user defines

$MAX_RESULTS       = 25;
$MAX_RESULTS_SHOWN = 10;

### end user defines
    
require '/var/www/html/somoaf/vendor/autoload.php'; ## include Composer's autoloader

### convience functions
function json_exit() {
    global $output;
    echo json_encode( $output );
    exit;
}    

function tcpmessage( $message ) {
    global $input;
    global $output;
    $result = (object)[];
    $msg    = (object)[];
    $msg->_uuid   = $input->_uuid;

    if ( is_string( $message ) ) {
        $msg->_message = json_decode( $message );
    } else if ( is_object( $message ) ) {
        $msg->_message = $message;
    } else if ( is_array( $message ) ) {
        $msg->_message = (object)$message;
    } else {
        $result->error = 'message must be a json string, array or object';
        return $result;
    }

    $msgj = utf8_encode( json_encode( $msg ) );

    # $output->_textarea = "message:\n" . json_encode( json_decode( $msgj ), JSON_PRETTY_PRINT );

    # open socket
    if ( !($socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP ) ) ) {
        $result->error = 'socket create error : ' . socket_strerror( socket_last_error() );
        return $result;
    }
    if ( !socket_connect( $socket, $input->_tcphost, intval( $input->_tcpport ) ) ) {
        $result->error = 'socket connect error : ' . socket_strerror( socket_last_error() );
        return $result;
    }

    # send message

    if ( strlen( $msgj ) != ( $bytes_sent = socket_write( $socket, $msgj, strlen( $msgj ) ) ) ) {
        $result->error = "socket send error bytes sent : $bytes_sent : " . socket_strerror( socket_last_error() );
        return $result;
    }
    socket_close( $socket );
    return "ok";
}

function udpmessage( $message ) {
    global $input;
    global $output;
    $result = (object)[];
    $msg    = (object)[];
    $msg->_uuid   = $input->_uuid;

    if ( is_string( $message ) ) {
        $msg->_message = json_decode( $message );
    } else if ( is_object( $message ) ) {
        $msg->_message = $message;
    } else if ( is_array( $message ) ) {
        $msg->_message = (object)$message;
    } else {
        $result->error = 'message must be a json string, array or object';
        return $result;
    }

    $msgj = utf8_encode( json_encode( $msg ) );

    # $output->_textarea = "udp message:\n" . json_encode( json_decode( $msgj ), JSON_PRETTY_PRINT );

    # open socket
    $socket = socket_create( AF_INET, SOCK_DGRAM, 0 );

    # send message

    socket_sendto( $socket, $msgj, strlen( $msgj ), 0, $input->_udphost, intval( $input->_udpport ) );

    return "ok";
}

function tcpquestion( $question, $timeout = 300, $buffersize = 65536 ) {
    global $input;
    global $output;

    $result = (object)[];
    $msg    = (object)[];
    $msg->_uuid   = $input->_uuid;
    # genapptest's appconfig has a default timeout
    # $msg->timeout = $timeout;

    if ( is_string( $question ) ) {
        $msg->_question = json_decode( $question );
    } else if ( is_object( $question ) ) {
        $msg->_question = $question;
    } else if ( is_array( $question ) ) {
        $msg->_question = (object)$question;
    } else {
        $result->error = 'question must be a json string, array or object';
        return $result;
    }
        
    $msgj = utf8_encode( json_encode( $msg ) );
    # a newline is also required when sending a question
    $msgj .= "\n";
        
    # $output->_textarea = "question:\n" . json_encode( json_decode( $msgj ), JSON_PRETTY_PRINT ) . "\n";

    # connect
    if ( !($socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP ) ) ) {
        $result->error = 'socket create error : ' . socket_strerror( socket_last_error() );
        return $result;
    }
    if ( !socket_connect( $socket, $input->_tcphost, intval( $input->_tcpport ) ) ) {
        $result->error = 'socket connect error : ' . socket_strerror( socket_last_error() );
        return $result;
    }

    # send question

    if ( strlen( $msgj ) != ( $bytes_sent = socket_write( $socket, $msgj, strlen( $msgj ) ) ) ) {
        $result->error = "socket send error bytes sent = $bytes_sent : " . socket_strerror( socket_last_error() );
        return $result;
    }

    # receive answer

    $data = socket_read( $socket, $buffersize );

    # $output->_textarea .= "question response:\n" . json_encode( json_decode( $data ), JSON_PRETTY_PRINT ) . "\n";

    socket_close( $socket );
    return $data;
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
    $query = [ '_id' => new \MongoDB\BSON\Regex( '^' . $input->searchkey, 'i' ) ];

    # $output->_textarea = "query:\n" . json_encode( $query, JSON_PRETTY_PRINT ) . "\n";

    $foundcursor = $db_mongo->somo->afd->find(
        $query
        ,[
            'limit' => $MAX_RESULTS
            ,'projection' => [
                '_id' => 1
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
    $ids[] = $doc->_id;
}

if ( count( $ids ) == 0 ) {
    $output->_message =
        [
         'text'  => $input->searchkey . ' did not match any records'
         ,'icon' => 'information.png'
        ];
    json_exit();
}

# $output->_textarea .= "output ids:\n" . json_encode( $ids, JSON_PRETTY_PRINT ) . "\n";

if ( count( $ids ) > 1 ) {

    $response =
        json_decode(
            tcpquestion(
                [
                 "id" => "q1"
                 ,"title" => "Multiple search results found"
                 ,"icon"  => "noicon.png"
                 ,"text" =>
                 "There are multiple results in the database<br>"
                 . "(Note - maximum of $MAX_RESULTS matches listed)<br>"
                 . "<hr>"
                 ,"grid" => 3
                 ,"fields" => [
                     [
                      "id" => "lb1"
                      ,"type"       => "listbox"
                      ,"fontfamily" => "monospace"
                      ,"values"     => $ids
                      ,"returns"    => $ids
                      ,"required"   => "true"
                      ,"size"       => count( $ids ) > $MAX_RESULTS_SHOWN ? $MAX_RESULTS_SHOWN : count( $ids )
                      ,"grid"       => [
                          "data"    => [1,3]
                      ]
                     ]
                 ]
                ]
                )
            );


    # $result = tcpmessage( '{"text":"hi there tcp"}' );
    # $result = udpmessage( '{"text":"hi there udp"}' );

    # $output->response = json_encode( $response, JSON_PRETTY_PRINT );

    if (
        isset( $response->_response )
        && isset( $response->_response->button )
        && $response->_response->button == "ok"
        && isset( $response->_response->lb1 )
        && strlen( $response->_response->lb1 )
        ) {
        $output->searchkey = $response->_response->lb1;
    } else {
        $output->_null = "";
        json_exit();
    }
} else {
    ## one entry, set the searchkey to the full _id
    $output->searchkey = $ids[ 0 ];
}

try {
    $found = $db_mongo->somo->afd->findOne( [ "_id" => $output->searchkey ] );
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

if ( !$found ) {
    $output->_message =
        [
         'text'  => $output->searchkey . ' did not match any records'
         ,'icon' => 'information.png'
        ];
    json_exit();
}

# $output->_textarea .= "output:\n" . json_encode( $output, JSON_PRETTY_PRINT ) . "\n";
# $output->_textarea .= "found:\n" . json_encode( $found, JSON_PRETTY_PRINT ) . "\n";

### map outputs

https://www.uniprot.org/uniprot/A0A023GPK8

$uniprot_name      = preg_replace( '/-F.*$/', '', $found->_id );
$output->links     = 
    sprintf( "<a target=_blank href=https://www.uniprot.org/uniprot/%s>UniProt &#x1F517;</a>&nbsp;&nbsp;&nbsp;", $uniprot_name )
    . sprintf( "<a target=_blank href=https://alphafold.ebi.ac.uk/entry/%s>AlphaFold &#x1F517;</a>",             $uniprot_name )
    ;
$output->name      = $found->name;
$output->source    = str_replace( '; ', "\n", $found->source );
$output->sp        = $found->sp ? $found->sp : "n/a";
$output->afdate    = $found->afdate;
$output->mw        = sprintf( "%.1f", $found->mw );
$output->psv       = $found->psv;
$output->S         = sprintf( "%.5g +/- %.3g", $found->S,   $found->S_sd   );
$output->Dtr       = sprintf( "%.5g +/- %.3g", $found->Dtr, $found->Dtr_sd );
$output->Rs        = sprintf( "%.5g +/- %.3g", $found->Rs,  $found->Rs_sd  );
$output->Eta       = sprintf( "%.5g +/- %.3g", $found->Eta, $found->Eta_sd );
$output->Rg        = sprintf( "%.3g", $found->Rg );
$output->ExtX      = sprintf( "%.3g", $found->ExtX );
$output->ExtY      = sprintf( "%.3g", $found->ExtY );
$output->ExtZ      = sprintf( "%.3g", $found->ExtZ );
$output->downloads = 
    sprintf( "<a target=_blank href=pdb/%s_blah.pdb>PDB &#x21D3;</a>&nbsp;&nbsp;&nbsp;",      $found->_id )
    . sprintf( "<a target=_blank href=cif/%s_blah.pdb>CIF &#x21D3;</a>&nbsp;&nbsp;&nbsp;",    $found->_id )
    . sprintf( "<a target=_blank href=pr/%s_blah.dat>P(r) &#x21D3;</a>&nbsp;&nbsp;&nbsp;",    $found->_id )
    . sprintf( "<a target=_blank href=csv/%s.csv>CSV results &#x21D3;</a>&nbsp;&nbsp;&nbsp;", $found->_id )
    . sprintf( "<a target=_blank href=zip/%s.zip>All zip'd &#x21D3;</a>&nbsp;&nbsp;&nbsp;",   $found->_id )
    . sprintf( "<a target=_blank href=txz/%s.zip>All txz'd &#x21D3;</a>&nbsp;&nbsp;&nbsp;",   $found->_id )
    ;

## log results to textarea

# $output->_textarea =  "JSON output from executable:\n" . json_encode( $output, JSON_PRETTY_PRINT ) . "\n";
# $output->_textarea .= "JSON input from executable:\n"  . json_encode( $input, JSON_PRETTY_PRINT )  . "\n";

json_exit();


