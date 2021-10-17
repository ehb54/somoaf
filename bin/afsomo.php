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

require '/var/www/html/somoaf/vendor/autoload.php'; // include Composer's autoloader

### convience functions
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
    $found = $db_mongo->somo->afd->findOne( [ "_id" => $input->searchkey ] );
} catch ( MongoDB\Exception\UnsupportedException $e ) {
    $output->errors = "Error finding " .  $e->getMessage();
    json_exit();
}

if ( !$found ) {
    $output->_textarea = "Not found";
    json_exit();
}

### map outputs

https://www.uniprot.org/uniprot/A0A023GPK8

$uniprot_name      = preg_replace( '/-F.*$/', '', $found->_id );
$output->uniprot   = sprintf( "<a target=_blank href=https://www.uniprot.org/uniprot/%s>go to UniProt &#x1F517;</a>", $uniprot_name );
$output->alphafold = sprintf( "<a target=_blank href=https://alphafold.ebi.ac.uk/entry/%s>go to AlphaFold &#x1F517;</a>", $uniprot_name );
$output->name      = $found->name;
$output->source    = $found->source;
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
$output->alphafold = sprintf( "<a target=_blank href=https://alphafold.ebi.ac.uk/entry/%s>go to AlphaFold &#x1F517;</a>", $uniprot_name );
$output->pdbfile   = sprintf( "<a target=_blank href=pdb/%s_blah.pdb>PDB file &#x21D3;</a>", $found->name );
$output->prfile    = sprintf( "<a target=_blank href=pr/%s_blah.dat>P(r) file &#x21D3;</a>", $found->name );

## log results to textarea

# $output->_textarea =  "JSON output from executable:\n" . json_encode( $output, JSON_PRETTY_PRINT ) . "\n";
# $output->_textarea .= "JSON input from executable:\n"  . json_encode( $input, JSON_PRETTY_PRINT )  . "\n";

json_exit();


