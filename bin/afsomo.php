#!/usr/local/bin/php
<?php

## user settings

$papercolors = true;

## end user settings

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

function digitfix( $strval, $digits ) {
    $strnodp = str_replace( ".", "", $strval );
    if ( strlen($strnodp) >= $digits ) {
        return $strval;
    }
    if ( strpos( $strval, "." ) ) {
        return $strval . "0";
    }
    return $strval . ".0";
}

### open db

try {
    $db_mongo = new MongoDB\Client();
} catch ( Exception $e ) {
    $output->errors = "Error connecting to db " . $e->getMessage();
    json_exit();
}

$searchkey = preg_replace( '/^AF-/i', '', $input->searchkey );


### find in db
try {
    $query = [ '_id' => new \MongoDB\BSON\Regex( '^' . $searchkey, 'i' ) ];

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

    if ( count( $ids ) == $MAX_RESULTS ) {
        $multiple_msg = "<i>Note - results are limited to $MAX_RESULTS, more matches may exist.<br>Use a longer search string to refine the results.</i><br>";
    } else {
        $multiple_msg = "";
    }

    $response =
        json_decode(
            tcpquestion(
                [
                 "id" => "q1"
                 ,"title" => "Multiple search results found"
                 ,"icon"  => "noicon.png"
                 ,"text" =>
                 $multiple_msg
                 . "<hr>"
                 ,"grid" => 3
                 ,"timeouttext" => "The time to respond has expired, please search again."
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

$uniprot_name      = preg_replace( '/-F.*$/',      '', $found->_id );
$proc_opt          = preg_replace( '/^.*-F\\d+/',  '', $found->_id );
$af_version        = preg_replace( '/^.*model_v/', '', $found->name );

preg_match( '/^.*-(F\d+)-/', $found->name, $matches );
$af_frame          = $matches[1];
$base_name         = "AF-${uniprot_name}-${af_frame}${proc_opt}-model_v${af_version}";

$output->links     = 
    "<div style='margin-top:0.5rem;margin-bottom:0rem;'>"
    . sprintf( "<a target=_blank href=https://www.uniprot.org/uniprot/%s>UniProt &#x1F517;</a>&nbsp;&nbsp;&nbsp;", $uniprot_name )
    . sprintf( "<a target=_blank href=https://alphafold.ebi.ac.uk/entry/%s>AlphaFold &#x1F517;</a>",               $uniprot_name )
    . "</div>"
    ;
$output->name       = $found->name;
$output->title      = str_replace( 'PREDICTION FOR ', "PREDICTION FOR\n", $found->title );
$output->source     = str_replace( '; ', "\n", $found->source );
# $output->sp         = $found->sp ? $found->sp : "n/a";
$output->proc       = $found->proc;
if ( !$found->proc ) {
    $output->proc = $found->sp ? "Signal peptide $found->sp removed" : "none";
}

$output->res        = $found->res;
$output->afdate     = $found->afdate;
$output->somodate   = $found->somodate;
$output->mw         = sprintf( "%.1f", $found->mw );
$output->psv        = $found->psv;
$output->S          = digitfix( sprintf( "%.3g", $found->S ), 3 );
$output->Dtr        = digitfix( sprintf( "%.3g", $found->Dtr * 1e7 ), 3 );
$output->Rs         = digitfix( sprintf( "%.3g", $found->Rs ), 3 );
$output->Eta        = sprintf( "%s +/- %.2f", digitfix( sprintf( "%.3g", $found->Eta ), 3 ), $found->Eta_sd );
$output->Rg         = digitfix( sprintf( "%.3g", $found->Rg ), 3 );
$output->ExtX       = sprintf( "%.2f", $found->ExtX );
$output->ExtY       = sprintf( "%.2f", $found->ExtY );
$output->ExtZ       = sprintf( "%.2f", $found->ExtZ );
$output->helix      = sprintf( "%.1f", $found->helix );
$output->sheet      = sprintf( "%.1f", $found->sheet );
$output->afmeanconf = sprintf( "%.2f", $found->afmeanconf );
$output->downloads  = 
    "<div style='margin-top:0.5rem;margin-bottom:0rem;'>"
    . sprintf( "<a target=_blank href=data/pdb/%s-somo.pdb>PDB &#x21D3;</a>&nbsp;&nbsp;&nbsp;",           $base_name )
    . sprintf( "<a target=_blank href=data/mmcif/%s-somo.cif>mmCIF &#x21D3;</a>&nbsp;&nbsp;&nbsp;",       $base_name )
    . sprintf( "<a target=_blank href=data/pr/%s-somo-pr.dat>P(r) &#x21D3;</a>&nbsp;&nbsp;&nbsp;",        $base_name )
    . sprintf( "<a target=_blank href=data/cd/%s-somo-sesca-cd.dat>CD &#x21D3;</a>&nbsp;&nbsp;&nbsp;",    $base_name )
    . sprintf( "<a target=_blank href=data/csv/%s-somo.csv>CSV &#x21D3;</a>&nbsp;&nbsp;&nbsp;",           $base_name )
    . sprintf( "<a target=_blank href=data/zip/%s-somo.zip>All zip'd &#x21D3;</a>&nbsp;&nbsp;&nbsp;",     $base_name )
    . sprintf( "<a target=_blank href=data/txz/%s-somo.txz>All txz'd &#x21D3;</a>&nbsp;&nbsp;&nbsp;",     $base_name )
    . "</div>"
    ;

## pdb
$output->struct = sprintf( "data/pdb_tfrev/%s-somo.pdb", $base_name );

## plotly

$prfile = sprintf( "/var/www/html/somoaf/data/pr/%s-somo-pr.dat", $base_name );
if ( file_exists( $prfile ) ) {
    if ( $prfiledata = file_get_contents( $prfile ) ) {
        $plotin = explode( "\n", $prfiledata );
        $plot = json_decode(
            '{
                "data" : [
                    {
                     "x"     : []
                     ,"y"    : []
                     ,"mode" : "lines"
                     ,"line" : {
                         "color"  : "rgb(150,150,222)"
                         ,"width" : 2
                      }
                    }
                 ]
                 ,"layout" : {
                    "title" : "P(r)"
                    ,"font" : {
                        "color"  : "rgb(233,222,222)"
                    }
                    ,"paper_bgcolor": "rgba(0,0,0,0)"
                    ,"plot_bgcolor": "rgba(0,0,0,0)"
                    ,"xaxis" : {
                       "gridcolor" : "rgba(233,222,222,0.5)"
                       ,"title" : {
                       "text" : "Distance [&#8491;]"
                        ,"gridcolor" : "rgb(111,111,111)"
                        ,"font" : {
                            "color"  : "rgb(233,222,222)"
                        }
                     }
                    }
                    ,"yaxis" : {
                       "gridcolor" : "rgba(233,222,222,0.5)"
                       ,"title" : {
                       "text" : "Normalized Frequency"
                       ,"standoff" : 20
                        ,"font" : {
                            "color"  : "rgb(233,222,222)"
                        }
                     }
                    }
                 }
            }'
            );

        ## first two lines are headers
        array_shift( $plotin );
        array_shift( $plotin );

        ## $plot->plotincount = count( $plotin );
        
        foreach ( $plotin as $linein ) {
            $linevals = explode( "\t", $linein );

            if ( count( $linevals ) == 3 ) {
                $plot->data[0]->x[] = floatval($linevals[0]);
                $plot->data[0]->y[] = floatval($linevals[2]);
            }
        }
            
        if ( isset( $papercolors ) && $papercolors ) {
            $plot->data[0]->line->color               = "rgb(50,50,122)";
            $plot->layout->font->color                = "rgb(0,0,0)";
            $plot->layout->xaxis->title->font->color  = "rgb(0,0,0)";
            $plot->layout->yaxis->title->font->color  = "rgb(0,0,0)";
            $plot->layout->xaxis->gridcolor           = "rgb(150,150,150)";
            $plot->layout->yaxis->gridcolor           = "rgb(150,150,150)";
        }

        $output->prplot = $plot;
    }
}
    
$cdfile = sprintf( "/var/www/html/somoaf/data/cd/%s-somo-sesca-cd.dat", $base_name );
if ( file_exists( $cdfile ) ) {
    if ( $cdfiledata = file_get_contents( $cdfile ) ) {
        $plotin = explode( "\n", $cdfiledata );
        $plot = json_decode(
            '{
                "data" : [
                    {
                     "x"     : []
                     ,"y"    : []
                     ,"mode" : "lines"
                     ,"line" : {
                         "color"  : "rgb(150,150,222)"
                         ,"width" : 2
                      }
                    }
                 ]
                 ,"layout" : {
                    "title" : "Circular Dichroism Spectrum"
                    ,"font" : {
                        "color"  : "rgb(233,222,222)"
                    }
                    ,"paper_bgcolor": "rgba(0,0,0,0)"
                    ,"plot_bgcolor": "rgba(0,0,0,0)"
                    ,"xaxis" : {
                       "gridcolor" : "rgba(233,222,222,0.5)"
                       ,"title" : {
                       "text" : "Wavelength [nm]"
                        ,"gridcolor" : "rgb(111,111,111)"
                        ,"font" : {
                            "color"  : "rgb(233,222,222)"
                        }
                     }
                    }
                    ,"yaxis" : {
                       "gridcolor" : "rgba(233,222,222,0.5)"
                       ,"title" : {
                       "text" : "[&#920;] (10<sup>3</sup> deg*cm<sup>2</sup>/dmol)"
                        ,"font" : {
                            "color"  : "rgb(233,222,222)"
                        }
                     }
                    }
                 }
            }'
            );

        ## first two lines are headers
        $plotin = preg_grep( "/^\s*#/", $plotin, PREG_GREP_INVERT );

        foreach ( $plotin as $linein ) {
            $linevals = preg_split( '/\s+/', trim( $linein ) );
            
            if ( count( $linevals ) == 2 ) {
                $plot->data[0]->x[] = floatval($linevals[0]);
                $plot->data[0]->y[] = floatval($linevals[1]);
            }
        }
        ## reverse order
        $plot->data[0]->x = array_reverse( $plot->data[0]->x );
        $plot->data[0]->y = array_reverse( $plot->data[0]->y );

        if ( isset( $papercolors ) && $papercolors ) {
            $plot->data[0]->line->color               = "rgb(50,50,122)";
            $plot->layout->font->color                = "rgb(0,0,0)";
            $plot->layout->xaxis->title->font->color  = "rgb(0,0,0)";
            $plot->layout->yaxis->title->font->color  = "rgb(0,0,0)";
            $plot->layout->xaxis->gridcolor           = "rgb(150,150,150)";
            $plot->layout->yaxis->gridcolor           = "rgb(150,150,150)";
        }

        $output->cdplot = $plot;
    }
}

## log results to textarea

# $output->_textarea .=  "JSON output from executable:\n" . json_encode( $output, JSON_PRETTY_PRINT ) . "\n";
# $output->_textarea .= "JSON input from executable:\n"  . json_encode( $input, JSON_PRETTY_PRINT )  . "\n";

json_exit();


