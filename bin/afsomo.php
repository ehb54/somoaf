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

require '/var/www/html/somoaf/vendor/autoload.php'; ## include Composer's autoloader

### convience functions
function json_exit() {
    global $output;
    echo json_encode( $output );
    exit;
}    

function tcpquestion( $question, $timeout = 300, $buffersize = 65536 ) {
    global $input;
    global $output;

    $result = (object)[];
    $msg    = (object)[];
    $msg->_uuid   = $input->_uuid;
    $msg->timeout = $timeout;

    if ( is_string( $question ) ) {
        $msg->_question = json_decode( $question );
    } else if ( is_object( $question ) ) {
        $msg->_question = $question;
    } else if ( is_array( $question ) ) {
        $msg->_question = (object)$question; ### json_decode( json_encode( $question ) );
    } else {
        $result->error = 'question must be a json string, array or object';
        return $result;
    }
        
    $msgj = json_encode( $msg );
    # a newline is also required when sending a question
    $msgj .= "\n";
        
    $output->msgjson = $msgj;

    # connect
    $socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
    socket_connect( $socket, $input->_tcphost, $input_tcpport );

    # send question

    socket_send( $socket, $msgj, strlen( $msgj ) );

    # receive answer

    $data = socket_read( $socket, $buffersize, PHP_NORMAL_READ );
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
    $found = $db_mongo->somo->afd->findOne( [ "_id" => $input->searchkey ] );
} catch ( MongoDB\Exception\UnsupportedException $e ) {
    $output->errors = "Error finding " .  $e->getMessage();
    json_exit();
}

if ( !$found ) {
    $output->_textarea = "Not found";
    json_exit();
}

### tcp message test

$response =
    tcpquestion(
        [
         "id" => "q1"
         ,"title" => "are you sure?"
         ,"text" => "<p>header text.</p><hr>"
         ,"fields" => [
             [
              "id" => "l1"
              ,"type" => "label"
              ,"label" => "<center>this is label text</center>"
             ]
             ,[
                 "id" => "t1"
                 ,"type" => "text"
                 ,"label" => "tell me your name:"
             ]
             ,[
                 "id" => "cb1"
                 ,"type" => "checkbox"
                 ,"label" => "are you sure about the speed of light?"
             ]
         ]
        ] );

$output->response = json_encode( $response );

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
    ;

## log results to textarea

# $output->_textarea =  "JSON output from executable:\n" . json_encode( $output, JSON_PRETTY_PRINT ) . "\n";
# $output->_textarea .= "JSON input from executable:\n"  . json_encode( $input, JSON_PRETTY_PRINT )  . "\n";

json_exit();


